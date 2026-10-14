<?php

namespace Plugin0\Infrastructure;

use InvalidArgumentException;
use Logeecom\Settings\Domain\LogLevel;
use Psr\Log\LogLevel as PsrLogLevel;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;

/**
 * Prevodi nivoe logovanja između tri sveta: demo podešavanje "Log level" (Logeecom\Settings\Domain\LogLevel: debug,
 * info, warning, error), PSR-3 nivoi kojima demo servisi pišu (osam imena) i Core brojevi koje Core Logger i
 * LoggerService koriste (Logger::DEBUG = 3, INFO = 2, WARNING = 1, ERROR = 0; manji broj = ozbiljnije).
 *
 * PSR-3 ima više nivoa nego Core: notice ide kao INFO, a critical, alert i emergency kao ERROR, po istom rangiranju
 * kao demo Severity (notice između info i warning; critical, alert i emergency iznad error).
 */
final class CoreLogLevel
{
    /** Ime iz demo podešavanja => Core broj. */
    private const BY_NAME = [
        'debug' => Logger::DEBUG,
        'info' => Logger::INFO,
        'warning' => Logger::WARNING,
        'error' => Logger::ERROR,
    ];

    /** PSR-3 nivo koji Core nema => Core broj koji mu odgovara po ozbiljnosti. */
    private const PSR_EXTRA = [
        PsrLogLevel::NOTICE => Logger::INFO,
        PsrLogLevel::CRITICAL => Logger::ERROR,
        PsrLogLevel::ALERT => Logger::ERROR,
        PsrLogLevel::EMERGENCY => Logger::ERROR,
    ];

    /** Core broj => oznaka u poruci u ps_log ("WORLDLINE [INFO] ..."), koju LogReader čita nazad. */
    private const TOKEN = [
        Logger::DEBUG => 'DEBUG',
        Logger::INFO => 'INFO',
        Logger::WARNING => 'WARNING',
        Logger::ERROR => 'ERROR',
    ];

    public static function fromSetting(LogLevel $level): int
    {
        return self::BY_NAME[$level->value];
    }

    /**
     * @param mixed $coreLevel sačuvana vrednost, bilo kog tipa
     *
     * @return LogLevel|null null kad vrednost nije jedan od četiri Core broja
     */
    public static function toSetting($coreLevel): ?LogLevel
    {
        if (!is_int($coreLevel)) {
            return null;
        }

        $name = array_search($coreLevel, self::BY_NAME, true);

        return $name === false ? null : LogLevel::from($name);
    }

    /**
     * @throws InvalidArgumentException za ime koje PSR-3 ne definiše, kako PSR-3 traži od loggera
     */
    public static function fromPsr(string $psrLevel): int
    {
        $psrLevel = strtolower($psrLevel);
        if (isset(self::BY_NAME[$psrLevel])) {
            return self::BY_NAME[$psrLevel];
        }
        if (isset(self::PSR_EXTRA[$psrLevel])) {
            return self::PSR_EXTRA[$psrLevel];
        }

        throw new InvalidArgumentException(sprintf('"%s" is not a PSR-3 log level.', $psrLevel));
    }

    public static function token(int $coreLevel): string
    {
        return self::TOKEN[$coreLevel] ?? 'INFO';
    }

    /** Zapis Core nivoa $entry se upisuje kad podešeni nivo dozvoljava taj nivo ili ozbiljniji (manji broj). */
    public static function isWritten(int $entry, int $setting): bool
    {
        return $entry <= $setting;
    }
}
