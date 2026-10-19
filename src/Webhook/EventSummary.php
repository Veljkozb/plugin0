<?php

namespace Plugin0\Webhook;

/**
 * Sažetak webhook tela samo za log: ID događaja, tip, ID plaćanja i merchantReference (ID korpe). Telo je do Core-ove
 * provere potpisa nepouzdano, pa se iz ovoga ništa ne odlučuje; služi da svaki zapis u logu ima čime da se pronađe
 * (tiket: "log every received event ... with enough context (order/transaction reference, event ID)").
 *
 * Core-ov WebhookData ne nosi ID događaja (UUID sa vrha JSON-a), samo ID plaćanja; zato se ovde čita iz sirovog tela.
 * Neispravan JSON ili polja koja nedostaju daju prazne vrednosti, nikad grešku. Vrednosti su skraćene na 100 znakova.
 */
final class EventSummary
{
    private const MAX_LENGTH = 100;
    private const MAX_JSON_DEPTH = 16;

    /** Objekti u kojima Worldline šalje ID i reference, redom kojim se traže. */
    private const EVENT_OBJECTS = ['payment', 'refund', 'payout', 'token'];

    public string $eventId = '';
    public string $type = '';
    public string $paymentId = '';
    public string $merchantReference = '';

    public static function fromBody(string $body): self
    {
        $summary = new self();
        $data = json_decode($body, true, self::MAX_JSON_DEPTH);
        if (!is_array($data)) {
            return $summary;
        }

        $summary->eventId = self::text($data['id'] ?? null);
        // Plaćanja nose "type", payment link događaji "eventType" (isto razlikuje i Core WebhookTransformer).
        $summary->type = self::text($data['type'] ?? ($data['eventType'] ?? null));

        foreach (self::EVENT_OBJECTS as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                continue;
            }
            $object = $data[$key];
            $summary->paymentId = self::text($object['id'] ?? null);
            $output = $object['paymentOutput'] ?? ($object['refundOutput'] ?? null);
            if (is_array($output)) {
                $summary->merchantReference = self::text($output['references']['merchantReference'] ?? null);
            }
            break;
        }

        return $summary;
    }

    /** @return array<string, string> parovi za LoggerService::context */
    public function toContext(): array
    {
        return [
            'eventId' => $this->eventId,
            'type' => $this->type,
            'paymentId' => $this->paymentId,
            'merchantReference' => $this->merchantReference,
        ];
    }

    /** @param mixed $value */
    private static function text($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return substr((string) $value, 0, self::MAX_LENGTH);
    }
}
