<?php

namespace Tests\Unit\BackgroundProcessing;

use PHPUnit\Framework\TestCase;
use Plugin0\BackgroundProcessing\AutoCapture\AutoCaptureProcessor;
use Plugin0\Entity\AutoCaptureEntity;
use Plugin0\Entity\PaymentOperationEntity;
use RuntimeException;
use Tests\Support\FakeAutoCaptureGateway;
use Tests\Support\InMemoryAutoCaptureLedger;
use Tests\Support\RecordingLogger;

/**
 * One automatic capture attempt (ticket Part 4): nothing is captured while the setting is off, an already captured
 * payment is never captured again (also when a retry follows a timeout), a failure is logged with its attempt
 * number and rethrown for the Core retry policy, and the last failure is shown to the merchant.
 */
final class AutoCaptureProcessorTest extends TestCase
{
    private const STORE = '1';
    private const PAYMENT = '10301114880_0';
    private const CART = '19';

    private FakeAutoCaptureGateway $gateway;
    private InMemoryAutoCaptureLedger $ledger;
    private RecordingLogger $logger;
    private AutoCaptureProcessor $processor;

    protected function setUp(): void
    {
        $this->gateway = new FakeAutoCaptureGateway();
        $this->ledger = new InMemoryAutoCaptureLedger();
        $this->logger = new RecordingLogger();
        $this->processor = new AutoCaptureProcessor($this->gateway, $this->ledger, $this->logger);
        $this->ledger->schedule(self::STORE, self::PAYMENT, self::CART, 42);
    }

    public function testCapturesTheWholeRemainingAmountAndShowsItOnTheOrder(): void
    {
        $result = $this->processor->process(self::STORE, self::PAYMENT, self::CART, 1, 6);

        self::assertSame(AutoCaptureProcessor::CAPTURED, $result);
        self::assertSame([['paymentId' => self::PAYMENT, 'amount' => 2294]], $this->gateway->captureCalls);
        self::assertSame(AutoCaptureEntity::STATE_CAPTURED, $this->ledger->state(self::PAYMENT));
        self::assertSame('10301114880_1', $this->ledger->find(self::PAYMENT)->getProviderOperationId());
        self::assertSame([self::PAYMENT], $this->gateway->refreshCalls);
        self::assertSame(1, $this->gateway->lockCalls);

        self::assertCount(1, $this->gateway->operations);
        self::assertSame(42, $this->gateway->operations[0]['orderId']);
        self::assertSame(PaymentOperationEntity::OUTCOME_PENDING, $this->gateway->operations[0]['outcome']);
        self::assertSame(
            'Automatic capture of 22.94 EUR accepted and being processed (Worldline status CAPTURE_REQUESTED).',
            $this->gateway->operations[0]['message']
        );

        self::assertSame(['Auto capture attempt started', 'Auto capture succeeded'], $this->messages());
        self::assertSame(
            ['orderId' => 42, 'cartId' => self::CART, 'paymentId' => self::PAYMENT, 'storeId' => self::STORE, 'attempt' => 1, 'maxAttempts' => 6],
            $this->logger->records[0]['context']
        );
        self::assertSame('10301114880_1', $this->logger->records[1]['context']['providerOperationId']);
    }

    public function testCapturedStatusIsRecordedAsSuccess(): void
    {
        $this->gateway->captureStatus = 'CAPTURED';

        $this->processor->process(self::STORE, self::PAYMENT, self::CART, 1, 6);

        self::assertSame(PaymentOperationEntity::OUTCOME_SUCCESS, $this->gateway->operations[0]['outcome']);
    }

    public function testNothingIsSentWhileAutoCaptureIsOff(): void
    {
        $this->gateway->enabled[self::STORE] = false;

        $result = $this->processor->process(self::STORE, self::PAYMENT, self::CART, 1, 6);

        self::assertSame(AutoCaptureProcessor::SKIPPED, $result);
        self::assertSame([], $this->gateway->captureCalls);
        self::assertSame(0, $this->gateway->lockCalls);
        // The schedule is dropped, so the payment can be scheduled again if the setting is turned back on.
        self::assertNull($this->ledger->find(self::PAYMENT));
        self::assertSame('Auto capture skipped: auto capture is turned off, nothing was sent to Worldline', $this->messages()[1]);
    }

    public function testAlreadyCapturedPaymentIsLeftUnchanged(): void
    {
        $this->gateway->captured = 2294;
        $this->gateway->statusCode = 9;

        $result = $this->processor->process(self::STORE, self::PAYMENT, self::CART, 1, 6);

        self::assertSame(AutoCaptureProcessor::NOT_NEEDED, $result);
        self::assertSame([], $this->gateway->captureCalls);
        self::assertSame([], $this->gateway->operations);
        self::assertSame([self::PAYMENT], $this->gateway->refreshCalls);
        self::assertSame(AutoCaptureEntity::STATE_NOT_NEEDED, $this->ledger->state(self::PAYMENT));
    }

    public function testRetryAfterATimeoutDoesNotCaptureTwice(): void
    {
        // Attempt 1: Worldline processes the capture, but the answer times out.
        $this->gateway->failCaptureWith('Request timed out', true);
        try {
            $this->processor->process(self::STORE, self::PAYMENT, self::CART, 1, 6);
            self::fail('The failed attempt must be rethrown for the Core retry policy.');
        } catch (RuntimeException $e) {
            self::assertSame('Request timed out', $e->getMessage());
        }

        // Attempt 2 (the Core retry): the fresh state shows the capture, so nothing is sent again.
        $this->gateway->captureError = null;
        $result = $this->processor->process(self::STORE, self::PAYMENT, self::CART, 2, 6);

        self::assertSame(AutoCaptureProcessor::NOT_NEEDED, $result);
        self::assertCount(1, $this->gateway->captureCalls);
        self::assertSame(2294, $this->gateway->captureRequested);
        self::assertSame(2, $this->ledger->find(self::PAYMENT)->getAttempts());
    }

    public function testFailedAttemptIsLoggedWithItsNumberAndRethrown(): void
    {
        $this->gateway->failCaptureWith('Worldline refused the capture. PAYMENT_NOT_FOUND');

        try {
            $this->processor->process(self::STORE, self::PAYMENT, self::CART, 3, 6);
            self::fail('The failed attempt must be rethrown.');
        } catch (RuntimeException $e) {
        }

        $failure = $this->logger->records[1];
        self::assertSame('warning', $failure['level']);
        self::assertSame('Auto capture attempt failed, it will be retried', $failure['message']);
        self::assertSame(3, $failure['context']['attempt']);
        self::assertSame(self::PAYMENT, $failure['context']['paymentId']);
        self::assertSame(42, $failure['context']['orderId']);
        self::assertStringContainsString('PAYMENT_NOT_FOUND', $failure['context']['error']);
        self::assertSame('Worldline refused the capture. PAYMENT_NOT_FOUND', $this->ledger->find(self::PAYMENT)->getLastError());
        self::assertSame(AutoCaptureEntity::STATE_SCHEDULED, $this->ledger->state(self::PAYMENT));
    }

    public function testLastFailedAttemptIsLoggedAsError(): void
    {
        $this->gateway->stateError = new RuntimeException('The payment state could not be read from Worldline. 503');

        try {
            $this->processor->process(self::STORE, self::PAYMENT, self::CART, 6, 6);
            self::fail('The failed attempt must be rethrown.');
        } catch (RuntimeException $e) {
        }

        self::assertSame('error', $this->logger->records[1]['level']);
        self::assertSame('Auto capture attempt failed, no attempts left', $this->logger->records[1]['message']);
        self::assertSame([], $this->gateway->captureCalls);
    }

    public function testRefreshFailureAfterTheCaptureIsRetriedWithoutASecondCapture(): void
    {
        $this->gateway->refreshError = 'Payment status lock not acquired';
        try {
            $this->processor->process(self::STORE, self::PAYMENT, self::CART, 1, 6);
            self::fail('A failed order refresh must be retried.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('the order status could not be refreshed', $e->getMessage());
        }

        $this->gateway->refreshError = null;
        $result = $this->processor->process(self::STORE, self::PAYMENT, self::CART, 2, 6);

        self::assertSame(AutoCaptureProcessor::NOT_NEEDED, $result);
        self::assertCount(1, $this->gateway->captureCalls);
        // The ledger keeps "captured": the retry only refreshed the order.
        self::assertSame(AutoCaptureEntity::STATE_CAPTURED, $this->ledger->state(self::PAYMENT));
    }

    public function testGiveUpMarksThePaymentFailedAndShowsItToTheMerchant(): void
    {
        $this->gateway->failCaptureWith('Worldline refused the capture. ACTION_NOT_ALLOWED_ON_TARGET');
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            try {
                $this->processor->process(self::STORE, self::PAYMENT, self::CART, $attempt, 6);
            } catch (RuntimeException $e) {
            }
        }

        $this->processor->giveUp(self::STORE, self::PAYMENT, self::CART);

        self::assertSame(AutoCaptureEntity::STATE_FAILED, $this->ledger->state(self::PAYMENT));
        self::assertSame(6, $this->ledger->find(self::PAYMENT)->getAttempts());
        $row = end($this->gateway->operations);
        self::assertSame(PaymentOperationEntity::OUTCOME_REFUSED, $row['outcome']);
        self::assertSame(0, $row['amount']);
        self::assertSame(
            'Automatic capture failed after 6 attempts: Worldline refused the capture. ACTION_NOT_ALLOWED_ON_TARGET. Capture the payment from this page.',
            $row['message']
        );
        $last = end($this->logger->records);
        self::assertSame('error', $last['level']);
        self::assertSame('Auto capture failed after the last attempt, the payment is left for the merchant to capture', $last['message']);
        self::assertSame(6, $last['context']['attempts']);
    }

    public function testGiveUpAfterASuccessfulCaptureDoesNotMarkThePaymentFailed(): void
    {
        $this->gateway->refreshError = 'Worldline did not answer';
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            try {
                $this->processor->process(self::STORE, self::PAYMENT, self::CART, $attempt, 6);
            } catch (RuntimeException $e) {
            }
        }

        $this->processor->giveUp(self::STORE, self::PAYMENT, self::CART);

        self::assertCount(1, $this->gateway->captureCalls);
        self::assertSame(AutoCaptureEntity::STATE_CAPTURED, $this->ledger->state(self::PAYMENT));
        self::assertCount(1, $this->gateway->operations);
        self::assertStringContainsString('the order status could not be refreshed', end($this->logger->records)['message']);
    }

    /** @return list<string> */
    private function messages(): array
    {
        return array_map(static function (array $record): string {
            return $record['message'];
        }, $this->logger->records);
    }
}
