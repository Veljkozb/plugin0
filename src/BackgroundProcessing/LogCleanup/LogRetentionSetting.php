<?php

namespace Plugin0\BackgroundProcessing\LogCleanup;

use Plugin0\Infrastructure\CorePaymentSettings;
use Plugin0\Infrastructure\LoggerService;
use RuntimeException;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\GeneralSettingsAPI\Request\LogSettingsRequest;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\Services\GeneralSettings\Repositories\LogSettingsRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\LogSettings;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;

/**
 * Podešavanje "Log retention" sa Settings stranice: koliko dana se čuvaju log zapisi pre nego što ih
 * LogRetentionTask obriše (docs/BACKGROUND.md, 6.1 i 8). Čuva se u Core LogSettings, polje logRecordsLifetime (Core
 * prima 1 do 14 dana, LogRecordsLifetime.php:28; stranica nudi 1, 2, 7 i 14). Bez sačuvanog podešavanja važi Core
 * default 14 (GeneralSettingsService.php:105).
 *
 * LogSettings ima i drugo polje, debugMode (Core beleži svaki zahtev ka Worldline-u i odgovor u MonitoringLog); upis
 * ga uvek prenosi nepromenjenog.
 */
final class LogRetentionSetting
{
    /** @var int[] broj dana koje Settings stranica nudi */
    public const CHOICES = [1, 2, 7, 14];

    public const DEFAULT_DAYS = 14;

    private const COMPONENT = 'Settings';

    public static function isValid(int $days): bool
    {
        return in_array($days, self::CHOICES, true);
    }

    /**
     * Izbori za formu: ponuđeni brojevi dana i, ako je sačuvana neka druga vrednost (Core prima 1-14), i ona, da forma
     * pokaže ono što stvarno važi.
     *
     * @return int[]
     */
    public static function choicesWith(int $current): array
    {
        $choices = self::CHOICES;
        if (!in_array($current, $choices, true)) {
            $choices[] = $current;
            sort($choices);
        }

        return $choices;
    }

    /** Broj dana na snazi za Settings stranicu (Core fasada; sačuvano ili Core default 14). */
    public static function current(string $storeId): int
    {
        return (int) (self::readLogSettings($storeId)['logDays'] ?? self::DEFAULT_DAYS);
    }

    public static function save(string $storeId, int $days, int $employeeId): void
    {
        if (!self::isValid($days)) {
            throw new RuntimeException(sprintf('Log retention must be one of %s days.', implode(', ', self::CHOICES)));
        }

        $logSettings = self::readLogSettings($storeId);
        $before = (int) ($logSettings['logDays'] ?? self::DEFAULT_DAYS);
        if ($before === $days) {
            return;
        }

        $saved = AdminAPI::get()->generalSettings($storeId)->saveLogSettings(
            new LogSettingsRequest((bool) ($logSettings['debugMode'] ?? false), $days)
        );
        if (!$saved->isSuccessful()) {
            throw new RuntimeException(CorePaymentSettings::errorMessage($saved->toArray()));
        }

        Logger::logInfo('Log retention changed', self::COMPONENT, LoggerService::context([
            'storeId' => $storeId,
            'previousDays' => $before,
            'days' => $days,
            'employeeId' => $employeeId,
        ]));
    }

    /**
     * Broj dana za zadatak čišćenja: čita Core repozitorijum u store kontekstu, bez fasade. Bez aktivne konekcije ili
     * bez sačuvanih podešavanja Core vraća null (LogSettingsRepository.php:37-46), pa važi default 14.
     */
    public static function daysForStore(string $storeId): int
    {
        /** @var LogSettings|null $settings */
        $settings = StoreContext::doWithStore($storeId, static function () {
            return ServiceRegister::getService(LogSettingsRepositoryInterface::class)->getLogSettings();
        });

        return $settings !== null ? $settings->getLogRecordsLifetime()->getDays() : self::DEFAULT_DAYS;
    }

    /** @return array<string, mixed> debugMode i logDays, oblik GeneralSettingsResponse::logSettingsToArray */
    private static function readLogSettings(string $storeId): array
    {
        $response = AdminAPI::get()->generalSettings($storeId)->getGeneralSettings();
        if (!$response->isSuccessful()) {
            throw new RuntimeException(CorePaymentSettings::errorMessage($response->toArray()));
        }

        return (array) ($response->toArray()['logSettings'] ?? []);
    }
}
