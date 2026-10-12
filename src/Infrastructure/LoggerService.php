<?php

namespace Plugin0\Infrastructure;

use Plugin0\Settings\LogLevel;
use Plugin0\Settings\SettingsService;
use PrestaShopLogger;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Interfaces\ShopLoggerAdapter;
use WOP\OnlinePayments\Core\Infrastructure\Logger\LogData;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;

/**
 * Core piše svoje poruke ovde; mi ih prosleđujemo u PrestaShop log (ps_log, Advanced Parameters > Logs).
 *
 * Core šalje svaki nivo, i DEBUG, bez filtera (Logger::logMessage zove ShopLoggerAdapter uvek). Šta se od toga
 * upisuje odlučuje podešavanje "Log level" sa Settings stranice: zapis ispod podešenog nivoa se preskače.
 */
class LoggerService implements ShopLoggerAdapter
{
    /** Početak svake poruke u ps_log; LogReader po njemu prepoznaje naše zapise. */
    public const PREFIX = 'WORLDLINE';

    /** Core nivo -> PrestaShop severity (1 info, 2 warning, 3 error, 4 major). */
    private const SEVERITY = [
        Logger::ERROR => 3,
        Logger::WARNING => 2,
        Logger::INFO => 1,
        Logger::DEBUG => 1,
    ];

    private const LEVEL_NAME = [
        Logger::ERROR => 'ERROR',
        Logger::WARNING => 'WARNING',
        Logger::INFO => 'INFO',
        Logger::DEBUG => 'DEBUG',
    ];

    /** Podešeni nivo, keširan po PHP procesu; null = još nije pročitan. SettingsService ga briše posle upisa. */
    private static ?int $minLogLevel = null;

    /** Zaštita od petlje: podešavanje se čita kroz BaseRepository, koji svoje čitanje takođe loguje. */
    private static bool $readingMinLogLevel = false;

    public function logMessage(LogData $data): void
    {
        $level = $data->getLogLevel();
        if (!LogLevel::isWritten($level, $this->minLogLevel())) {
            return;
        }

        $message = sprintf(
            '%s [%s] %s: %s',
            self::PREFIX,
            self::LEVEL_NAME[$level] ?? 'INFO',
            $data->getComponent(),
            $data->getMessage()
        );

        $context = [];
        foreach ($data->getContext() as $item) {
            $context[$item->getName()] = $item->getValue();
        }
        if ($context !== []) {
            $message .= ' | ' . json_encode($context);
        }

        // Šesti argument (allowDuplicate) = true: PrestaShop bi inače preskočio poruku identičnu nekoj koja je već u ps_log,
        // a core poruke moraju sve da stignu u log.
        PrestaShopLogger::addLog($message, self::SEVERITY[$level] ?? 1, null, null, null, true);
    }

    /** SettingsService zove posle upisa podešavanja, da novi nivo važi odmah, u istom procesu. */
    public static function forgetMinLogLevel(): void
    {
        self::$minLogLevel = null;
    }

    private function minLogLevel(): int
    {
        if (self::$minLogLevel !== null) {
            return self::$minLogLevel;
        }

        // Dok se podešavanje čita, BaseRepository loguje baš to čitanje (DEBUG); taj zapis se preskače, ERROR ne.
        if (self::$readingMinLogLevel) {
            return Logger::ERROR;
        }

        self::$readingMinLogLevel = true;
        try {
            self::$minLogLevel = (new SettingsService())->logLevel();
        } finally {
            self::$readingMinLogLevel = false;
        }

        return self::$minLogLevel;
    }
}
