<?php

namespace Plugin0\Webhook;

/**
 * Beleška za trajanje jednog zahteva: Webhook\WebhookService ovde upiše ID događaja koji je preskočio kao duplikat, a
 * front controller webhook je pročita posle Core poziva da bi u log upisao "duplicate skipped" umesto "processed".
 * Core fasada za preskočen događaj vraća običan uspeh (WebhookResponse), pa kontroler drugačije ne može da zna.
 * Statičko stanje je bezopasno: PHP-FPM obrađuje jedan zahtev po procesu, a take() briše belešku.
 */
final class SkippedDuplicate
{
    private static ?string $eventId = null;

    public static function note(string $eventId): void
    {
        self::$eventId = $eventId;
    }

    /** Vraća zabeležen ID i briše belešku; null kad ništa nije preskočeno. */
    public static function take(): ?string
    {
        $eventId = self::$eventId;
        self::$eventId = null;

        return $eventId;
    }
}
