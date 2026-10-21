<?php

namespace Plugin0;

use Plugin0\Infrastructure\Repository\BaseRepository;
use Plugin0\Infrastructure\Repository\LogsRepository;
use Plugin0\Infrastructure\Repository\QueueItemRepository;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\QueueItem;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\QueueService;
use Plugin0\BackgroundProcessing\AutoCapture\AutoCaptureListener;
use Plugin0\BackgroundProcessing\LogCleanup\LogCleanupTaskService;
use Plugin0\Entity\AutoCaptureEntity;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\BackgroundProcesses\AutoCaptureCheckListener;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\LogCleanup\LogCleanupTaskServiceInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\TenantRegistryInterface;
use Plugin0\Entity\PaymentMethodEntity;
use Plugin0\Entity\PaymentOperationEntity;
use WOP\OnlinePayments\Core\Bootstrap\BootstrapComponent;
use WOP\OnlinePayments\Core\Bootstrap\Configuration\Configuration as CoreConfiguration;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\Connection\ConnectionConfigEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\Disconnect\DisconnectTime;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\GeneralSettings\LogSettingsEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\GeneralSettings\PayByLinkSettingsEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\GeneralSettings\PaymentSettingsConfigEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\GeneralSettings\WebhookSettingsEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\Monitoring\MonitoringLog;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\Monitoring\WebhookLog;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\PaymentLink\PaymentLinkEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\PaymentMethod\PaymentMethodConfigEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\PaymentTransaction\PaymentTransactionEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\PaymentTransaction\PaymentTransactionLockEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\ProductTypes\ProductTypeEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\Tokens\TokenEntity;
use WOP\OnlinePayments\Core\Infrastructure\Configuration\ConfigEntity;
use WOP\OnlinePayments\Core\Infrastructure\Logger\LogData;
use WOP\OnlinePayments\Core\Infrastructure\ORM\RepositoryRegistry;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\Process;
use Plugin0\Infrastructure\Encryptor;
use Plugin0\Infrastructure\LoggerService;
use Plugin0\Infrastructure\MetadataProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Encryption\Encryptor as EncryptorInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Metadata\MetadataProviderInterface;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Interfaces\ShopLoggerAdapter;
use WOP\OnlinePayments\Core\Infrastructure\Serializer\Concrete\JsonSerializer;
use WOP\OnlinePayments\Core\Infrastructure\Serializer\Serializer;
use Plugin0\Infrastructure\StoreService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Stores\StoreService as StoreServiceInterface;
use Plugin0\Infrastructure\LanguageService;
use Plugin0\Infrastructure\LogoUrlService;
use Plugin0\Infrastructure\ShopPaymentService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Language\LanguageService as LanguageServiceInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Logo\LogoUrlService as LogoUrlServiceInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Payment\ShopPaymentService as ShopPaymentServiceInterface;
use Plugin0\Infrastructure\ShopOrderService;
use Plugin0\Infrastructure\HostedCheckoutProxy;
use Plugin0\Infrastructure\PaymentActionSetting;
use Plugin0\Infrastructure\PaymentsProxy;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\PaymentsProxy as CorePaymentsProxy;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Proxies\PaymentsProxyInterface;
use WOP\OnlinePayments\Core\Bootstrap\SingleInstance;
use WOP\OnlinePayments\Core\Bootstrap\Sdk\MerchantClientFactory;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Proxies\HostedCheckoutProxyInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\ShopOrderService as ShopOrderServiceInterface;
use Plugin0\Infrastructure\WebhookTransformer;
use WOP\OnlinePayments\Core\Bootstrap\Sdk\WebhookTransformer as CoreWebhookTransformer;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Connection\ActiveConnectionProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Webhook\Transformers\WebhookTransformerInterface;
use Plugin0\Entity\WebhookEventEntity;
use Plugin0\Webhook\ProcessedEvents;
use Plugin0\Webhook\WebhookService;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Services\Webhooks\WebhookService as CoreWebhookService;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Services\Payment\StatusUpdateService as CoreStatusUpdateService;
use Plugin0\Infrastructure\StatusUpdateService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\Repositories\TokensRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Proxies\HostedTokenizationProxyInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Time\TimeProviderInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\OrderStatusMapping\StatusMappingService;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\Services\GeneralSettings\Repositories\PaymentSettingsRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\Repositories\PaymentTransactionRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Services\PaymentLinks\PaymentLinkTransactionService;

class Bootstrap extends BootstrapComponent
{
    private static bool $booted = false;

    /**
     * Boots the core once per PHP process. Safe to call from every entry point
     * (hook, controller, installer, upgrade script): every call after the first is a no-op.
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        static::bootstrap(
            static function () {
                return 'worldline';
            },
            __DIR__ . '/../config/brand.json'
        );

        self::$booted = true;
    }

    protected static function initServices(): void
    {
        parent::initServices();

        ServiceRegister::registerService(CoreConfiguration::CLASS_NAME, static function () {
            return Configuration::getInstance();
        });

        ServiceRegister::registerService(Serializer::CLASS_NAME, static function () {
            return new JsonSerializer();
        });

        ServiceRegister::registerService(ShopLoggerAdapter::CLASS_NAME, static function () {
            return new LoggerService();
        });

        ServiceRegister::registerService(EncryptorInterface::class, static function () {
            return new Encryptor();
        });

        ServiceRegister::registerService(MetadataProviderInterface::class, static function () {
            return new MetadataProvider();
        });

        ServiceRegister::registerService(StoreServiceInterface::class, static function () {
            return new StoreService();
        });

        // Tri servisa bez kojih Core ne može da napravi PaymentService (admin lista metoda) ni DisconnectService.
        ServiceRegister::registerService(LanguageServiceInterface::class, static function () {
            return new LanguageService();
        });

        ServiceRegister::registerService(LogoUrlServiceInterface::class, static function () {
            return new LogoUrlService();
        });

        ServiceRegister::registerService(ShopPaymentServiceInterface::class, static function () {
            return new ShopPaymentService();
        });

        // Bez njega Core ne može da napravi StatusUpdateService, pa ni da obradi povratak kupca sa Worldline strane.
        ServiceRegister::registerService(ShopOrderServiceInterface::class, static function () {
            return new ShopOrderService();
        });

        // Omotač oko Core WebhookTransformer-a (Core ga registruje u parent::initServices, bez SingleInstance; ovde se
        // ista registracija prepisuje, poslednja važi). SDK greške potpisa i nečitljivog tela pretvara u plugin
        // izuzetke sa errorCode-om po kome front controller webhook zna da odgovori 200. Vidi Infrastructure\WebhookTransformer.
        ServiceRegister::registerService(WebhookTransformerInterface::class, static function () {
            return new WebhookTransformer(
                new CoreWebhookTransformer(ServiceRegister::getService(ActiveConnectionProvider::class)),
                ServiceRegister::getService(ActiveConnectionProvider::class)
            );
        });

        // Podklasa Core WebhookService-a (Core ga registruje u parent::initServices kao SingleInstance; ovde se
        // prepisuje pod istim ključem): već obrađen događaj (Webhook\ProcessedEvents, tip WebhookEvent) preskače bez
        // Worldline poziva, a nov beleži tek posle uspešne obrade. Vidi Webhook\WebhookService.
        ServiceRegister::registerService(CoreWebhookService::class, new SingleInstance(static function () {
            return new WebhookService(
                ServiceRegister::getService(CoreStatusUpdateService::class),
                ServiceRegister::getService(PaymentTransactionRepositoryInterface::class),
                ServiceRegister::getService(PaymentLinkTransactionService::class),
                new ProcessedEvents()
            );
        }));

        // Podklasa Core StatusUpdateService-a (Core ga registruje u parent::initServices kao SingleInstance; ovde se
        // prepisuje pod istim ključem, sa istih osam zavisnosti): updateOrderStatus, čitanje Worldline-a i promena
        // narudžbine zajedno, drži pod MySQL GET_LOCK po ID-ju transakcije, da povratak kupca, webhook i admin panel ne
        // primene zastareli snimak jedan preko drugog. Vidi Infrastructure\StatusUpdateService.
        ServiceRegister::registerService(CoreStatusUpdateService::class, new SingleInstance(static function () {
            return new StatusUpdateService(
                ServiceRegister::getService(PaymentTransactionRepositoryInterface::class),
                ServiceRegister::getService(PaymentsProxyInterface::class),
                ServiceRegister::getService(TokensRepositoryInterface::class),
                ServiceRegister::getService(HostedTokenizationProxyInterface::class),
                ServiceRegister::getService(TimeProviderInterface::class),
                ServiceRegister::getService(ShopOrderServiceInterface::class),
                ServiceRegister::getService(StatusMappingService::class),
                ServiceRegister::getService(PaymentSettingsRepositoryInterface::class)
            );
        }));

        // Zamena Core LogCleanupTaskService-a (Core ga registruje u parent::initServices, SingleInstance): Core-ov
        // dnevni LogCleanupListener sada stavlja u red plugin0 LogRetentionTask, koji briše po podešavanju na snazi,
        // i plugin0 redove u ps_log (docs/BACKGROUND.md, 8). Vidi BackgroundProcessing\LogCleanup.
        ServiceRegister::registerService(LogCleanupTaskServiceInterface::class, new SingleInstance(static function () {
            return new LogCleanupTaskService();
        }));
    }

    /**
     * Zamena Core AutoCaptureCheckListener-a. Core ga registruje u parent::initBackgroundProcesses i uzima iz
     * ServiceRegister-a tek kad okine TickEvent (BootstrapComponent.php:470-473), pa važi ova registracija: na 15 minuta
     * plugin0 pregled dospelih plaćanja (AutoCaptureScanTask) umesto Core AutoCaptureCheckTask-a, i samo dok je auto
     * capture uključen (docs/BACKGROUND.md, 7). Vidi BackgroundProcessing\AutoCapture.
     */
    protected static function initBackgroundProcesses(): void
    {
        parent::initBackgroundProcesses();

        ServiceRegister::registerService(AutoCaptureCheckListener::class, new SingleInstance(static function () {
            return new AutoCaptureListener(
                ServiceRegister::getService(QueueService::class),
                ServiceRegister::getService(TimeProviderInterface::class),
                ServiceRegister::getService(TenantRegistryInterface::class)
            );
        }));
    }

    /**
     * Zamena Core HostedCheckoutProxy-ja. Mora u initProxies, ne u initServices: BootstrapComponent::init zove
     * parent::init (initServices) pa tek onda initProxies, gde Core registruje svoj proxy pod istim ključem; ponovna
     * registracija posle parent::initProxies zamenjuje Core implementaciju (ServiceRegister, poslednja važi).
     * Za kartične brendove usklađuje mobile/redirect deo zahteva sa kartičnim authorizationMode-om, inače Worldline
     * plaćanje obradi kao direct sale. Vidi Infrastructure\HostedCheckoutProxy.
     */
    protected static function initProxies(): void
    {
        parent::initProxies();

        ServiceRegister::registerService(HostedCheckoutProxyInterface::class, new SingleInstance(static function () {
            return new HostedCheckoutProxy(
                ServiceRegister::getService(MerchantClientFactory::class),
                static function (): string {
                    return PaymentActionSetting::cardAuthorizationMode();
                }
            );
        }));

        // Omotač oko Core PaymentsProxy-ja: posle delimičnih naplata Worldline prvobitnu rezervaciju drži u statusu 5,
        // a Core status i "refund moguć" čita samo sa nje; omotač zatvorenu rezervaciju tumači kao naplaćeno
        // (ili vraćeno). Vidi Infrastructure\PaymentsProxy.
        ServiceRegister::registerService(PaymentsProxyInterface::class, new SingleInstance(static function () {
            return new PaymentsProxy(new CorePaymentsProxy(ServiceRegister::getService(MerchantClientFactory::class)));
        }));
    }

    /**
     * Svaki core entitet dobija klasu koja ga čita i piše u PrestaShop bazi. QueueItem dobija posebnu klasu
     * (Infrastructure\Repository\QueueItemRepository), jer Core za red zadataka traži i metode za izbor sledećeg
     * zadatka i uslovni upis (docs/BACKGROUND.md, 1.5 i 4.2).
     */
    protected static function initRepositories(): void
    {
        parent::initRepositories();

        $entities = [
            ConfigEntity::class,
            Process::class,
            LogData::class,
            ConnectionConfigEntity::class,
            DisconnectTime::class,
            LogSettingsEntity::class,
            PayByLinkSettingsEntity::class,
            PaymentSettingsConfigEntity::class,
            WebhookSettingsEntity::class,
            PaymentLinkEntity::class,
            PaymentMethodConfigEntity::class,
            PaymentTransactionEntity::class,
            PaymentTransactionLockEntity::class,
            ProductTypeEntity::class,
            TokenEntity::class,
            // Entiteti koji nisu Core-ovi: metoda plaćanja iz demo projekta (Entity\PaymentMethodEntity), log
            // capture/refund/cancel pokušaja sa admin stranice narudžbine (Entity\PaymentOperationEntity), evidencija
            // obrađenih webhook događaja (Entity\WebhookEventEntity) i evidencija automatskih naplata
            // (Entity\AutoCaptureEntity).
            PaymentMethodEntity::class,
            PaymentOperationEntity::class,
            WebhookEventEntity::class,
            AutoCaptureEntity::class,
        ];

        foreach ($entities as $entityClass) {
            RepositoryRegistry::registerRepository($entityClass, BaseRepository::getClassName());
        }

        RepositoryRegistry::registerRepository(MonitoringLog::class, LogsRepository::getClassName());
        RepositoryRegistry::registerRepository(WebhookLog::class, LogsRepository::getClassName());
        RepositoryRegistry::registerRepository(QueueItem::class, QueueItemRepository::getClassName());
    }
}