<?php

namespace Plugin0;

use Plugin0\Infrastructure\Repository\BaseRepository;
use Plugin0\Infrastructure\Repository\LogsRepository;
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
        ];

        foreach ($entities as $entityClass) {
            RepositoryRegistry::registerRepository($entityClass, BaseRepository::getClassName());
        }

        RepositoryRegistry::registerRepository(MonitoringLog::class, LogsRepository::getClassName());
        RepositoryRegistry::registerRepository(WebhookLog::class, LogsRepository::getClassName());
    }
}