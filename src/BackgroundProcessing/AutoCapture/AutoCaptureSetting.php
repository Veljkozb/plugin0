<?php

namespace Plugin0\BackgroundProcessing\AutoCapture;

use Plugin0\Infrastructure\CorePaymentSettings;
use Plugin0\Infrastructure\LoggerService;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\Services\GeneralSettings\Repositories\PaymentSettingsRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\AutomaticCapture;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\PaymentSettings;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;

/**
 * Podešavanje "Auto capture" (uključeno / isključeno) sa Settings stranice. Čuva se u Core PaymentSettings, polje
 * automaticCapture (docs/BACKGROUND.md, 6.1):
 * - isključeno = -1 (Core AutomaticCapture::never(), i Core default kad ništa nije sačuvano);
 * - uključeno = 60, najkraće kašnjenje koje Core prima (AutomaticCapture.php:14-21; ostale vrednosti su 2, 4, 8 h i
 *   1, 2, 5 dana).
 *
 * Šta Core sa tim radi: kad updateOrderStatus vidi autorizovano plaćanje (status 5) dok je Payment action
 * FINAL_AUTHORIZATION i automaticCapture nije -1, transakciji upiše rok captureAt = sada + automaticCapture minuta
 * (StatusUpdateService.php:71-79). Naplatu po tom roku radi plugin0 (AutoCaptureScanTask, AutoCapturePaymentTask), i
 * to samo dok je podešavanje uključeno: zadatak ga proverava pri svakoj naplati (isEnabledForStore).
 */
final class AutoCaptureSetting
{
    /** Core AutomaticCapture::NEVER. */
    public const OFF = -1;

    /** Minuti posle autorizacije: najmanja vrednost koju Core AutomaticCapture prima. */
    public const DELAY_MINUTES = 60;

    private const COMPONENT = 'Settings';

    public static function isOn(int $automaticCapture): bool
    {
        return $automaticCapture !== self::OFF;
    }

    public static function coreValue(bool $enabled): int
    {
        return $enabled ? self::DELAY_MINUTES : self::OFF;
    }

    /** Vrednost na snazi za Settings stranicu (Core fasada; sačuvano ili Core default -1). */
    public static function current(string $storeId): bool
    {
        return self::isOn((int) (CorePaymentSettings::read($storeId)['automaticCapture'] ?? self::OFF));
    }

    /**
     * Upis sa Settings stranice. Kad je stanje već traženo, ništa se ne upisuje: tako ostaje i kašnjenje drugačije od
     * 60 minuta, ako ga je neko postavio mimo ove stranice.
     */
    public static function save(string $storeId, bool $enabled, int $employeeId): void
    {
        $before = (int) (CorePaymentSettings::read($storeId)['automaticCapture'] ?? self::OFF);
        if (self::isOn($before) === $enabled) {
            return;
        }

        CorePaymentSettings::update($storeId, ['automaticCapture' => self::coreValue($enabled)]);

        Logger::logInfo($enabled ? 'Auto capture turned on' : 'Auto capture turned off', self::COMPONENT, LoggerService::context([
            'storeId' => $storeId,
            'automaticCaptureMinutes' => self::coreValue($enabled),
            'previousAutomaticCaptureMinutes' => $before,
            'employeeId' => $employeeId,
        ]));
    }

    /**
     * Da li je auto capture uključen za prodavnicu, za zadatke u pozadini: čita Core repozitorijum podešavanja u
     * store kontekstu, bez fasade. Bez aktivne konekcije ili bez sačuvanih podešavanja Core vraća null, a to je
     * isključeno (Core default never).
     */
    public static function isEnabledForStore(string $storeId): bool
    {
        /** @var PaymentSettings|null $settings */
        $settings = StoreContext::doWithStore($storeId, static function () {
            return ServiceRegister::getService(PaymentSettingsRepositoryInterface::class)->getPaymentSettings();
        });

        return $settings !== null && !$settings->getAutomaticCapture()->equals(AutomaticCapture::never());
    }
}
