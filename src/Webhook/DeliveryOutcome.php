<?php

namespace Plugin0\Webhook;

/**
 * Ishod jedne webhook isporuke sa stanovišta kontrolera: šta se desilo i koji HTTP status ide Worldline-u.
 * Pravila su u ResponsePolicy; ovde je samo vrednost (docs/WEBHOOK.md, tabela 3.4).
 */
final class DeliveryOutcome
{
    /** Core je obradio događaj: 200. */
    public const PROCESSED = 'processed';
    /** Plugin je događaj preskočio kao već obrađen (Webhook\WebhookService): 200, bez promene. */
    public const DUPLICATE = 'duplicate';
    /** Potpis ili trgovac nisu prošli Core proveru: 200, ništa nije obrađeno. */
    public const REJECTED = 'rejected';
    /** Događaj bez objekta plaćanja ili povraćaja (npr. token.*): 200, nema šta da se obradi. */
    public const IGNORED = 'ignored';
    /** Potpis je prošao, ali SDK ne može da pročita telo (apiVersion nije v1, nije JSON objekat): 200, greška u logu. */
    public const UNPROCESSABLE = 'unprocessable';
    /** Potpis je prošao, a obrada pala (baza, Worldline, zaključavanje): 503, da Worldline pošalje ponovo. */
    public const FAILED = 'failed';

    private const HTTP_STATUS = [
        self::PROCESSED => 200,
        self::DUPLICATE => 200,
        self::REJECTED => 200,
        self::IGNORED => 200,
        self::UNPROCESSABLE => 200,
        self::FAILED => 503,
    ];

    private string $kind;
    private string $errorCode;
    private string $errorMessage;

    private function __construct(string $kind, string $errorCode, string $errorMessage)
    {
        $this->kind = $kind;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
    }

    public static function processed(): self
    {
        return new self(self::PROCESSED, '', '');
    }

    public static function duplicate(): self
    {
        return new self(self::DUPLICATE, '', '');
    }

    public static function rejected(string $errorCode, string $errorMessage): self
    {
        return new self(self::REJECTED, $errorCode, $errorMessage);
    }

    public static function ignored(string $errorCode, string $errorMessage): self
    {
        return new self(self::IGNORED, $errorCode, $errorMessage);
    }

    public static function unprocessable(string $errorCode, string $errorMessage): self
    {
        return new self(self::UNPROCESSABLE, $errorCode, $errorMessage);
    }

    public static function failed(string $errorCode, string $errorMessage): self
    {
        return new self(self::FAILED, $errorCode, $errorMessage);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUS[$this->kind];
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function errorMessage(): string
    {
        return $this->errorMessage;
    }
}
