<?php

namespace Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use Plugin0\Webhook\ProcessedEvents;
use Plugin0\Webhook\SkippedDuplicate;
use Plugin0\Webhook\WebhookService;
use RuntimeException;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentId;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentTransaction;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\Repositories\PaymentTransactionRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Webhook\WebhookData;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Services\Payment\StatusUpdateService;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Services\PaymentLinks\PaymentLinkTransactionService;

/**
 * Idempotency layer 3 (docs/WEBHOOK.md, 5.3): an event id already recorded is skipped before the Core touches
 * Worldline or the order; a new event is recorded only after the Core processed it without error, so a delivery that
 * failed (503) is processed again when Worldline retries.
 */
final class WebhookServiceTest extends TestCase
{
    private const EVENT_ID = '11111111-2222-4333-8444-555555555555';
    private const PAYMENT_ID = '9999999999_0';

    protected function setUp(): void
    {
        SkippedDuplicate::take();
    }

    public function testAlreadyProcessedEventIsSkippedWithoutTouchingTheOrder(): void
    {
        $events = $this->events([self::EVENT_ID]);
        $statusUpdate = $this->createMock(StatusUpdateService::class);
        $statusUpdate->expects(self::never())->method('updateOrderStatus');

        $this->service($events, $this->transaction(), $statusUpdate)->process($this->webhook(self::EVENT_ID));

        self::assertSame(self::EVENT_ID, SkippedDuplicate::take());
        self::assertSame([], $events->recorded);
    }

    public function testNewEventIsProcessedByTheCoreAndThenRecorded(): void
    {
        $events = $this->events([]);
        $statusUpdate = $this->createMock(StatusUpdateService::class);
        $statusUpdate->expects(self::once())->method('updateOrderStatus')->with(self::callback(
            static function (PaymentId $paymentId): bool {
                return (string) $paymentId === self::PAYMENT_ID;
            }
        ));

        $this->service($events, $this->transaction(), $statusUpdate)->process($this->webhook(self::EVENT_ID));

        self::assertNull(SkippedDuplicate::take());
        self::assertSame([[self::EVENT_ID, 'payment.captured', self::PAYMENT_ID, '15']], $events->recorded);
    }

    public function testEventForUnknownPaymentIsRecordedSoThatRetriesAreSkipped(): void
    {
        $events = $this->events([]);
        $statusUpdate = $this->createMock(StatusUpdateService::class);
        $statusUpdate->expects(self::never())->method('updateOrderStatus');

        $this->service($events, null, $statusUpdate)->process($this->webhook(self::EVENT_ID));

        self::assertSame([self::EVENT_ID], array_column($events->recorded, 0));
    }

    public function testFailedProcessingIsNotRecorded(): void
    {
        $events = $this->events([]);
        $statusUpdate = $this->createMock(StatusUpdateService::class);
        $statusUpdate->method('updateOrderStatus')->willThrowException(new RuntimeException('Worldline unreachable'));
        $service = $this->service($events, $this->transaction(), $statusUpdate);

        try {
            $service->process($this->webhook(self::EVENT_ID));
            self::fail('the Core failure must propagate so that the controller answers 503');
        } catch (RuntimeException $e) {
            self::assertSame('Worldline unreachable', $e->getMessage());
        }

        self::assertSame([], $events->recorded, 'a failed delivery must stay unrecorded for the retry');
        self::assertNull(SkippedDuplicate::take());
    }

    public function testEventWithoutIdIsNeitherCheckedNorRecorded(): void
    {
        $events = $this->events([]);
        $statusUpdate = $this->createMock(StatusUpdateService::class);
        $statusUpdate->expects(self::once())->method('updateOrderStatus');

        $this->service($events, $this->transaction(), $statusUpdate)->process($this->webhook(''));

        self::assertSame([], $events->checked);
        self::assertSame([], $events->recorded);
    }

    /**
     * @param string[] $known
     *
     * @return ProcessedEvents&object{checked: string[], recorded: array<int, array{string, string, string, string}>}
     */
    private function events(array $known): ProcessedEvents
    {
        return new class($known) extends ProcessedEvents {
            /** @var string[] */
            public array $checked = [];
            /** @var array<int, array{string, string, string, string}> */
            public array $recorded = [];
            /** @var string[] */
            private array $known;

            public function __construct(array $known)
            {
                $this->known = $known;
            }

            public function wasProcessed(string $eventId): bool
            {
                $this->checked[] = $eventId;

                return in_array($eventId, $this->known, true);
            }

            public function record(string $eventId, string $eventType, string $paymentId, string $merchantReference): void
            {
                $this->recorded[] = [$eventId, $eventType, $paymentId, $merchantReference];
            }
        };
    }

    private function service(ProcessedEvents $events, ?PaymentTransaction $transaction, StatusUpdateService $statusUpdate): WebhookService
    {
        $transactions = $this->createStub(PaymentTransactionRepositoryInterface::class);
        $transactions->method('get')->willReturn($transaction);

        return new WebhookService($statusUpdate, $transactions, $this->createStub(PaymentLinkTransactionService::class), $events);
    }

    private function transaction(): PaymentTransaction
    {
        return new PaymentTransaction('15', PaymentId::parse(self::PAYMENT_ID));
    }

    private function webhook(string $eventId): WebhookData
    {
        $body = ['apiVersion' => 'v1', 'type' => 'payment.captured', 'merchantId' => 'logeecom35', 'payment' => ['id' => self::PAYMENT_ID]];
        if ($eventId !== '') {
            $body['id'] = $eventId;
        }

        return new WebhookData(self::PAYMENT_ID, '15', 'payment.captured', '2026-10-19T12:00:00+02:00', 'COMPLETED', 9, json_encode($body));
    }
}
