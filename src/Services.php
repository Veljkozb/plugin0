<?php

namespace Plugin0;

use Logeecom\PaymentMethods\Domain\Checkout\PaymentFlowFactory;
use Logeecom\PaymentMethods\Service\PaymentMethodService;
use Logeecom\Settings\Service\SettingsService;
use Plugin0\Adapter\CoreLogger;
use Plugin0\Adapter\LogLevelApplier;
use Plugin0\Adapter\PaymentMethodRepository;
use Plugin0\Adapter\ReferenceData;
use Plugin0\Adapter\SettingsRepository;
use Psr\Log\LoggerInterface;

/**
 * Jedino mesto koje odlučuje koja implementacija stoji iza svakog demo interfejsa: ono što u demu rade Laravel
 * service provideri (app/Providers/*ServiceProvider.php). Kontroleri, hook-ovi i LoggerService traže servis odavde
 * i nikad ne prave repozitorijum sami (SDS: kontroler ne instancira repozitorijum nego servis).
 *
 * Servisi nemaju stanje (sve je u bazi), pa se prave na svaki poziv. Core mora da bude podignut (Bootstrap::boot)
 * pre prvog poziva, jer repozitorijumi idu kroz RepositoryRegistry i Plugin0\Configuration.
 */
final class Services
{
    public static function paymentMethods(): PaymentMethodService
    {
        return new PaymentMethodService(
            new PaymentMethodRepository(),
            new ReferenceData(),
            new PaymentFlowFactory(),
            self::logger('PaymentMethods'),
            self::settings()
        );
    }

    public static function settings(): SettingsService
    {
        return new SettingsService(
            new SettingsRepository(),
            new ReferenceData(),
            self::logger('Settings'),
            new LogLevelApplier(),
            self::isDevMode()
        );
    }

    /** PSR-3 logger demo servisa: piše kroz Core Logger i LoggerService u ps_log; $component je ime u poruci. */
    public static function logger(string $component): LoggerInterface
    {
        return new CoreLogger($component);
    }

    /** Demo "APP_ENV=local" je ovde PrestaShop dev režim (_PS_MODE_DEV_ u config/defines.inc.php). */
    public static function isDevMode(): bool
    {
        return defined('_PS_MODE_DEV_') && _PS_MODE_DEV_;
    }
}
