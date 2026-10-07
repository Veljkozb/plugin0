<?php

namespace Plugin0;

use WOP\OnlinePayments\Core\Bootstrap\BootstrapComponent;
use WOP\OnlinePayments\Core\Bootstrap\Configuration\Configuration as CoreConfiguration;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;

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
    }
}
