<?php

namespace Plugin0;

use Plugin0\Infrastructure\Repository\BaseRepository;
use Plugin0\Infrastructure\Repository\LogsRepository;
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
     * Svaki core entitet dobija klasu koja ga čita i piše u PrestaShop bazi.
     * QueueItem namerno nije ovde: on traži QueueItemRepository (posebne metode za red zadataka)
     * i dolazi u koraku za webhook-e i pozadinske zadatke.
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
            // Entiteti koji nisu Core-ovi: metoda plaćanja iz demo projekta (Entity\PaymentMethodEntity) i log
            // capture/refund/cancel pokušaja sa admin stranice narudžbine (Entity\PaymentOperationEntity).
            PaymentMethodEntity::class,
            PaymentOperationEntity::class,
        ];

        foreach ($entities as $entityClass) {
            RepositoryRegistry::registerRepository($entityClass, BaseRepository::getClassName());
        }

        RepositoryRegistry::registerRepository(MonitoringLog::class, LogsRepository::getClassName());
        RepositoryRegistry::registerRepository(WebhookLog::class, LogsRepository::getClassName());
    }
}