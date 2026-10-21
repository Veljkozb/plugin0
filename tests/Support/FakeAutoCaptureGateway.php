<?php

namespace Tests\Support;

use Plugin0\BackgroundProcessing\AutoCapture\AutoCaptureGateway;
use Plugin0\BackgroundProcessing\AutoCapture\CaptureState;
use RuntimeException;
use Throwable;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Amount;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Currency;

/**
 * An AutoCaptureGateway that keeps the Worldline side of one payment in memory (authorized, captured, requested
 * amounts in minor units) and records every call, so a test can see what would have been sent.
 *
 * A capture that fails with $captureReachedWorldline = true models a timeout: Worldline processed the capture,
 * but the answer never arrived.
 */
final class FakeAutoCaptureGateway implements AutoCaptureGateway
{
    /** @var array<string, bool> store id => auto capture on */
    public array $enabled = ['1' => true];

    /** @var list<array{storeId: string, paymentId: string, merchantReference: string, captureAt: int}> */
    public array $due = [];

    public int $orderId = 42;
    public ?string $orderCurrency = 'EUR';

    public int $authorized = 2294;
    public int $captured = 0;
    public int $captureRequested = 0;
    public bool $isAuthorized = true;
    public int $statusCode = 5;

    public ?Throwable $stateError = null;
    public ?Throwable $captureError = null;
    public bool $captureReachedWorldline = false;
    public string $captureStatus = 'CAPTURE_REQUESTED';
    public ?string $refreshError = null;
    public ?Throwable $enqueueError = null;

    /** @var array<int, string> queue item id => status */
    public array $queueStatuses = [];

    /** @var list<array{paymentId: string, amount: int}> */
    public array $captureCalls = [];
    /** @var list<string> */
    public array $refreshCalls = [];
    /** @var list<array{orderId: int, amount: int, outcome: string, message: string, status: string, operationId: string}> */
    public array $operations = [];
    /** @var list<array{storeId: string, paymentId: string, merchantReference: string}> */
    public array $enqueued = [];
    public int $lockCalls = 0;

    private int $nextQueueItemId = 100;

    public function isEnabled(string $storeId): bool
    {
        return $this->enabled[$storeId] ?? false;
    }

    public function dueAuthorizations(int $limit): array
    {
        return array_slice($this->due, 0, $limit);
    }

    public function orderIdForCart(string $merchantReference): int
    {
        return $this->orderId;
    }

    public function orderCurrency(int $orderId): ?string
    {
        return $this->orderCurrency;
    }

    public function captureState(string $storeId, string $paymentId): CaptureState
    {
        if ($this->stateError !== null) {
            throw $this->stateError;
        }

        $left = $this->authorized - $this->captured - $this->captureRequested;

        return new CaptureState(
            $this->amount($this->isAuthorized && $left > 0 ? $left : 0),
            $this->amount($this->captured),
            $this->amount($this->captureRequested),
            $this->statusCode
        );
    }

    public function capture(string $storeId, string $paymentId, Amount $amount): array
    {
        $this->captureCalls[] = ['paymentId' => $paymentId, 'amount' => $amount->getValue()];

        if ($this->captureError === null || $this->captureReachedWorldline) {
            $this->captureRequested += $amount->getValue();
            $this->statusCode = 91;
        }

        if ($this->captureError !== null) {
            throw $this->captureError;
        }

        return ['id' => strstr($paymentId, '_', true) . '_1', 'status' => $this->captureStatus];
    }

    public function refreshOrderStatus(string $storeId, string $paymentId): ?string
    {
        $this->refreshCalls[] = $paymentId;

        return $this->refreshError;
    }

    public function recordOperation(
        int $orderId,
        string $paymentId,
        Amount $amount,
        string $outcome,
        string $message,
        string $providerStatus,
        string $providerOperationId
    ): void {
        $this->operations[] = [
            'orderId' => $orderId,
            'amount' => $amount->getValue(),
            'outcome' => $outcome,
            'message' => $message,
            'status' => $providerStatus,
            'operationId' => $providerOperationId,
        ];
    }

    public function withPaymentLock(string $paymentId, callable $work)
    {
        $this->lockCalls++;

        return $work();
    }

    public function enqueueCapture(string $storeId, string $paymentId, string $merchantReference): int
    {
        if ($this->enqueueError !== null) {
            throw $this->enqueueError;
        }

        $this->enqueued[] = ['storeId' => $storeId, 'paymentId' => $paymentId, 'merchantReference' => $merchantReference];
        $id = $this->nextQueueItemId++;
        $this->queueStatuses[$id] = 'queued';

        return $id;
    }

    public function queueItemStatus(int $queueItemId): ?string
    {
        return $this->queueStatuses[$queueItemId] ?? null;
    }

    public function failCaptureWith(string $message, bool $reachedWorldline = false): void
    {
        $this->captureError = new RuntimeException($message);
        $this->captureReachedWorldline = $reachedWorldline;
    }

    private function amount(int $value): Amount
    {
        return Amount::fromInt($value, Currency::fromIsoCode('EUR'));
    }
}
