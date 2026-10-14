<?php

namespace Logeecom\Settings\Domain;

use Psr\Log\LogLevel as PsrLogLevel;

/**
 * The table in Requirement 4.1: an entry is written when its own level is at or above the configured one.
 *
 * PSR-3 has more levels than 4.1 names: notice sits between info and warning, and critical, alert and
 * emergency above error. They are ranked where PSR-3 puts them, so an Error setting still lets a critical
 * entry through, and an Info setting a notice.
 */
final class Severity
{
    /**
     * Every PSR-3 level, from the most detailed to the most severe.
     *
     * @var list<string>
     */
    private const ORDER = [
        PsrLogLevel::DEBUG,
        PsrLogLevel::INFO,
        PsrLogLevel::NOTICE,
        PsrLogLevel::WARNING,
        PsrLogLevel::ERROR,
        PsrLogLevel::CRITICAL,
        PsrLogLevel::ALERT,
        PsrLogLevel::EMERGENCY,
    ];

    /**
     * Whether an entry at this PSR-3 level is written under this setting.
     *
     * @param string $psrLevel A PSR-3 level name, e.g. warning
     * @param LogLevel $setting The configured lowest level
     *
     * @return bool True when the entry's level is at or above the setting; false for a name PSR-3 does not
     *              define
     */
    public static function isWritten(string $psrLevel, LogLevel $setting): bool
    {
        $rank = array_search($psrLevel, self::ORDER, true);

        return $rank !== false && $rank >= array_search($setting->value, self::ORDER, true);
    }
}
