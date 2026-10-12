<?php

namespace Plugin0\Settings;

use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;

/**
 * Podešavanje "Log level": najniži nivo koji LoggerService upisuje u ps_log, kao u demu (src/Settings/Domain/LogLevel.php).
 *
 * Vrednost je Core-ov broj nivoa iz Logger-a: ERROR = 0, WARNING = 1, INFO = 2, DEBUG = 3 (manji broj = ozbiljnije).
 * U formi, u adresi i u logu nivo ima ime (debug, info, warning, error); ova klasa prevodi između imena i broja.
 */
final class LogLevel
{
    /** Core broj => ime, od najopširnijeg ka najozbiljnijem: redosled opcija u formi, kao u demu. */
    private const NAMES = [
        Logger::DEBUG => 'debug',
        Logger::INFO => 'info',
        Logger::WARNING => 'warning',
        Logger::ERROR => 'error',
    ];

    /** @return int[] Core brojevi sva četiri nivoa, redosledom iz NAMES */
    public static function all(): array
    {
        return array_keys(self::NAMES);
    }

    /** @param mixed $level sačuvana vrednost, bilo kog tipa */
    public static function isValid($level): bool
    {
        return is_int($level) && isset(self::NAMES[$level]);
    }

    /** @return int|null Core broj za ime iz forme, null za nepoznato ime */
    public static function fromName(string $name): ?int
    {
        $level = array_search(strtolower($name), self::NAMES, true);

        return $level === false ? null : $level;
    }

    public static function name(int $level): string
    {
        return self::NAMES[$level];
    }

    public static function label(int $level): string
    {
        return ucfirst(self::NAMES[$level]);
    }

    /** Zapis nivoa $entry se upisuje kad podešavanje dozvoljava taj nivo ili ozbiljniji (manji broj). */
    public static function isWritten(int $entry, int $setting): bool
    {
        return $entry <= $setting;
    }

    /** Tekst pored polja u formi, rečima iz dema: "Info, Warning and Error entries are written". */
    public static function effect(int $setting): string
    {
        $written = [];
        foreach (self::all() as $level) {
            if (self::isWritten($level, $setting)) {
                $written[] = self::label($level);
            }
        }
        $last = array_pop($written);

        return $written === []
            ? sprintf('Only %s entries are written', $last)
            : sprintf('%s and %s entries are written', implode(', ', $written), $last);
    }
}
