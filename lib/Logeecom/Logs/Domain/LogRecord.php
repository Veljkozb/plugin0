<?php

namespace Logeecom\Logs\Domain;

use DateTimeImmutable;

/**
 * One stored entry as the Logs screen shows it (Requirement 5.1): when, how severe, who, and what. The context
 * of the entry is left out on purpose - it is for whoever reads the table, not for the screen.
 */
final class LogRecord
{
    /**
     * @param int $id The entry's id
     * @param DateTimeImmutable $createdAt When it was written, in UTC
     * @param string $level The PSR-3 level name, e.g. warning
     * @param string|null $userEmail The e-mail of the user it names; null when it names none, e.g. a failed
     *                               sign-in
     * @param string $message The message as it was written
     */
    public function __construct(
        public readonly int $id,
        public readonly DateTimeImmutable $createdAt,
        public readonly string $level,
        public readonly ?string $userEmail,
        public readonly string $message,
    ) {
    }
}
