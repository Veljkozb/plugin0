<?php

namespace Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use Plugin0\Entity\PaymentOperationEntity;
use Plugin0\Order\OperationLog;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Amount;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Currency;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentId;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentOperation;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\StatusCode;

/**
 * Log rows record what Worldline answered at click time ("pending" for captures and refunds). When the panel is
 * opened later, pending rows get their final outcome from the operations Worldline reports for the payment.
 */
final class OperationLogTest extends TestCase
{
    public function testPendingRowLinkedByIdBecomesSuccessWhenWorldlineCaptured(): void
    {
        $row = $this->row('capture', 3000, PaymentOperationEntity::OUTCOME_PENDING, '10300628270_1');

        $changed = OperationLog::reconcile([$row], [$this->operation('10300628270_1', 3000, 9, 'CAPTURED')]);

        self::assertSame([$row], $changed);
        self::assertSame('success', $row->getOutcome());
        self::assertSame('CAPTURED', $row->getProviderStatus());
        self::assertSame('Capture of 30.00 EUR completed (Worldline status CAPTURED).', $row->getMessage());
    }

    public function testPendingRowStaysPendingWhileWorldlineStillProcesses(): void
    {
        $row = $this->row('refund', 3000, PaymentOperationEntity::OUTCOME_PENDING, '10300628270_6');

        $changed = OperationLog::reconcile([$row], [$this->operation('10300628270_6', 3000, 81, 'REFUND_REQUESTED')]);

        self::assertSame([], $changed);
        self::assertSame('pending', $row->getOutcome());
    }

    public function testRefusedByWorldlineBecomesRefused(): void
    {
        $row = $this->row('capture', 1000, PaymentOperationEntity::OUTCOME_PENDING, '10300628270_3');

        OperationLog::reconcile([$row], [$this->operation('10300628270_3', 1000, 93, 'REJECTED_CAPTURE')]);

        self::assertSame('refused', $row->getOutcome());
        self::assertStringContainsString('refused by Worldline', $row->getMessage());
    }

    public function testOldRowWithoutIdIsLinkedByActionAndAmountWhenUnambiguous(): void
    {
        // Order 16 on 2026-10-16: three captures of different amounts recorded before ids were stored.
        $rows = [
            $this->row('capture', 3000, PaymentOperationEntity::OUTCOME_PENDING, ''),
            $this->row('capture', 1100, PaymentOperationEntity::OUTCOME_PENDING, ''),
            $this->row('cancel', 1793, PaymentOperationEntity::OUTCOME_SUCCESS, ''),
        ];
        $operations = [
            $this->operation('10300628270_0', 6893, 5, 'PENDING_CAPTURE'),
            $this->operation('10300628270_1', 3000, 9, 'CAPTURED'),
            $this->operation('10300628270_2', 1100, 9, 'CAPTURED'),
            $this->operation('10300628270_4', 1793, 6, 'CANCELLED'),
        ];

        $changed = OperationLog::reconcile($rows, $operations);

        self::assertCount(2, $changed);
        self::assertSame('10300628270_1', $rows[0]->getProviderOperationId());
        self::assertSame('success', $rows[0]->getOutcome());
        self::assertSame('10300628270_2', $rows[1]->getProviderOperationId());
        self::assertSame('', $rows[2]->getProviderOperationId(), 'already final rows are not touched');
    }

    public function testAmbiguousAmountIsNotGuessed(): void
    {
        $rows = [
            $this->row('capture', 1000, PaymentOperationEntity::OUTCOME_PENDING, ''),
            $this->row('capture', 1000, PaymentOperationEntity::OUTCOME_PENDING, ''),
        ];
        $operations = [
            $this->operation('1_1', 1000, 9, 'CAPTURED'),
            $this->operation('1_2', 1000, 9, 'CAPTURED'),
        ];

        self::assertSame([], OperationLog::reconcile($rows, $operations));
        self::assertSame('pending', $rows[0]->getOutcome());
    }

    public function testOperationAlreadyLinkedToAnotherRowIsNotReused(): void
    {
        $rows = [
            $this->row('capture', 1000, PaymentOperationEntity::OUTCOME_SUCCESS, '1_1'),
            $this->row('capture', 1000, PaymentOperationEntity::OUTCOME_PENDING, ''),
        ];

        self::assertSame([], OperationLog::reconcile($rows, [$this->operation('1_1', 1000, 9, 'CAPTURED')]));
    }

    public function testPaymentItselfIsNeverMatchedAsAnOperation(): void
    {
        // Full capture: the payment _0 reads CAPTURED with the full amount; only _1 is the capture operation.
        $row = $this->row('capture', 3446, PaymentOperationEntity::OUTCOME_PENDING, '');
        $operations = [
            $this->operation('10300578891_0', 3446, 9, 'CAPTURED'),
            $this->operation('10300578891_1', 3446, 9, 'CAPTURED'),
        ];

        OperationLog::reconcile([$row], $operations);

        self::assertSame('10300578891_1', $row->getProviderOperationId());
        self::assertSame('success', $row->getOutcome());
    }

    public function testRejectedAndRefusedRowsAreLeftAlone(): void
    {
        $rows = [
            $this->row('refund', 6000, PaymentOperationEntity::OUTCOME_REJECTED, ''),
            $this->row('capture', 500, PaymentOperationEntity::OUTCOME_REFUSED, '1_9'),
        ];

        self::assertSame([], OperationLog::reconcile($rows, [$this->operation('1_9', 500, 9, 'CAPTURED')]));
        self::assertSame('refused', $rows[1]->getOutcome());
    }

    public function testCancelOperationCompletes(): void
    {
        $row = $this->row('cancel', 1793, PaymentOperationEntity::OUTCOME_PENDING, '10300628270_4');

        OperationLog::reconcile([$row], [$this->operation('10300628270_4', 1793, 6, 'CANCELLED')]);

        self::assertSame('success', $row->getOutcome());
    }

    private function row(string $action, int $amount, string $outcome, string $operationId): PaymentOperationEntity
    {
        $entry = new PaymentOperationEntity();
        $entry->setOrderId(16);
        $entry->setPaymentId('10300628270_0');
        $entry->setAction($action);
        $entry->setAmount($amount);
        $entry->setCurrency('EUR');
        $entry->setOutcome($outcome);
        $entry->setMessage('as recorded at click time');
        $entry->setProviderStatus($outcome === PaymentOperationEntity::OUTCOME_PENDING ? 'REQUESTED' : '');
        $entry->setProviderOperationId($operationId);
        $entry->setEmployeeId(1);
        $entry->setCreatedAt(1792135000);

        return $entry;
    }

    private function operation(string $id, int $amount, int $statusCode, string $status): PaymentOperation
    {
        return new PaymentOperation(
            PaymentId::parse($id),
            Amount::fromInt($amount, Currency::fromIsoCode('EUR')),
            StatusCode::parse($statusCode),
            $status
        );
    }
}
