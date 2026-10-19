<?php

namespace Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use Plugin0\Webhook\EventSummary;

/**
 * The summary exists only to give log lines something to search by (event id, type, payment id, cart id). It must
 * never fail on a body it does not understand, because the body is untrusted until the Core verified the signature.
 */
final class EventSummaryTest extends TestCase
{
    public function testPaymentEventFieldsAreRead(): void
    {
        $summary = EventSummary::fromBody(json_encode([
            'id' => '34b8a607-1fce-4003-b3ae-a4d29e92b232',
            'type' => 'payment.captured',
            'payment' => ['id' => '10300628270_1', 'paymentOutput' => ['references' => ['merchantReference' => '17']]],
        ]));

        self::assertSame([
            'eventId' => '34b8a607-1fce-4003-b3ae-a4d29e92b232',
            'type' => 'payment.captured',
            'paymentId' => '10300628270_1',
            'merchantReference' => '17',
        ], $summary->toContext());
    }

    public function testRefundEventUsesTheRefundObject(): void
    {
        $summary = EventSummary::fromBody(json_encode([
            'id' => 'e-refund',
            'type' => 'refund.refund_requested',
            'refund' => ['id' => '10300628270_5', 'refundOutput' => ['references' => ['merchantReference' => '17']]],
        ]));

        self::assertSame('10300628270_5', $summary->paymentId);
        self::assertSame('17', $summary->merchantReference);
    }

    public function testPaymentLinkEventUsesEventType(): void
    {
        $summary = EventSummary::fromBody(json_encode(['id' => 'e-link', 'eventType' => 'paymentlink.paid', 'paymentLink' => []]));

        self::assertSame('paymentlink.paid', $summary->type);
        self::assertSame('', $summary->paymentId);
        self::assertSame('', $summary->merchantReference);
    }

    public function testInvalidJsonGivesAnEmptySummary(): void
    {
        $summary = EventSummary::fromBody('{"id":');

        self::assertSame(['eventId' => '', 'type' => '', 'paymentId' => '', 'merchantReference' => ''], $summary->toContext());
    }

    public function testJsonArrayGivesAnEmptySummary(): void
    {
        $summary = EventSummary::fromBody('[{"id":"e1","type":"payment.created"}]');

        self::assertSame('', $summary->eventId);
        self::assertSame('', $summary->type);
    }

    public function testNonScalarValuesAreIgnoredAndLongValuesAreCut(): void
    {
        $summary = EventSummary::fromBody(json_encode([
            'id' => ['not', 'a', 'string'],
            'type' => str_repeat('a', 150),
            'payment' => ['id' => 12345],
        ]));

        self::assertSame('', $summary->eventId);
        self::assertSame(100, strlen($summary->type));
        self::assertSame('12345', $summary->paymentId);
    }
}
