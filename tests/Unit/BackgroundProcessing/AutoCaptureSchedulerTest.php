<?php

namespace Tests\Unit\BackgroundProcessing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin0\BackgroundProcessing\AutoCapture\AutoCaptureScheduler;
use Plugin0\Entity\AutoCaptureEntity;
use RuntimeException;
use Tests\Support\FakeAutoCaptureGateway;
use Tests\Support\InMemoryAutoCaptureLedger;
use Tests\Support\RecordingLogger;

/**
 * Scheduling: one capture task per due authorization while auto capture is on in its store, never a second one for a
 * payment that is scheduled, captured, not needed or finally failed, and a new one when the scheduled task is gone.
 */
final class AutoCaptureSchedulerTest extends TestCase
{
    private FakeAutoCaptureGateway $gateway;
    private InMemoryAutoCaptureLedger $ledger;
    private RecordingLogger $logger;
    private AutoCaptureScheduler $scheduler;

    protected function setUp(): void
    {
        $this->gateway = new FakeAutoCaptureGateway();
        $this->gateway->due = [
            ['storeId' => '1', 'paymentId' => '10301114880_0', 'merchantReference' => '19', 'captureAt' => 1792490000],
        ];
        $this->ledger = new InMemoryAutoCaptureLedger();
        $this->logger = new RecordingLogger();
        $this->scheduler = new AutoCaptureScheduler($this->gateway, $this->ledger, $this->logger);
    }

    public function testDueAuthorizationIsScheduledOnce(): void
    {
        self::assertSame(1, $this->scheduler->scheduleDue());
        self::assertSame(0, $this->scheduler->scheduleDue());

        self::assertCount(1, $this->gateway->enqueued);
        self::assertSame(
            ['storeId' => '1', 'paymentId' => '10301114880_0', 'merchantReference' => '19'],
            $this->gateway->enqueued[0]
        );
        $entry = $this->ledger->find('10301114880_0');
        self::assertSame(AutoCaptureEntity::STATE_SCHEDULED, $entry->getState());
        self::assertSame(100, $entry->getQueueItemId());
        self::assertSame(42, $entry->getOrderId());
        self::assertSame('Auto capture scheduled', $this->logger->records[0]['message']);
        self::assertSame('10301114880_0', $this->logger->records[0]['context']['paymentId']);
    }

    public function testNothingIsScheduledInAStoreWhereAutoCaptureIsOff(): void
    {
        $this->gateway->enabled['1'] = false;

        self::assertSame(0, $this->scheduler->scheduleDue());
        self::assertSame([], $this->gateway->enqueued);
        self::assertNull($this->ledger->find('10301114880_0'));
    }

    /** @return array<string, array{string}> */
    public static function settledStates(): array
    {
        return [
            'captured' => [AutoCaptureEntity::STATE_CAPTURED],
            'not needed' => [AutoCaptureEntity::STATE_NOT_NEEDED],
            'finally failed' => [AutoCaptureEntity::STATE_FAILED],
        ];
    }

    #[DataProvider('settledStates')]
    public function testSettledPaymentIsNotScheduledAgain(string $state): void
    {
        $this->scheduler->scheduleDue();
        $entry = $this->ledger->find('10301114880_0');
        $entry->setState($state);
        $this->ledger->entries['10301114880_0'] = $entry;

        self::assertSame(0, $this->scheduler->scheduleDue());
        self::assertCount(1, $this->gateway->enqueued);
    }

    public function testScheduledPaymentWhoseTaskIsGoneIsScheduledAgain(): void
    {
        $this->scheduler->scheduleDue();
        $this->gateway->queueStatuses[100] = 'completed';

        self::assertSame(1, $this->scheduler->scheduleDue());

        self::assertCount(2, $this->gateway->enqueued);
        self::assertSame(101, $this->ledger->find('10301114880_0')->getQueueItemId());
        self::assertContains(
            'Auto capture scheduled again: the previous task is no longer in the queue',
            array_column($this->logger->records, 'message')
        );
    }

    public function testRunningTaskIsNotScheduledAgain(): void
    {
        $this->scheduler->scheduleDue();
        $this->gateway->queueStatuses[100] = 'in_progress';

        self::assertSame(0, $this->scheduler->scheduleDue());
    }

    public function testFailedEnqueueLeavesNoScheduleBehind(): void
    {
        $this->gateway->enqueueError = new RuntimeException('Unable to update the task.');

        try {
            $this->scheduler->scheduleDue();
            self::fail('The enqueue error must be rethrown.');
        } catch (RuntimeException $e) {
        }

        self::assertNull($this->ledger->find('10301114880_0'));
    }
}
