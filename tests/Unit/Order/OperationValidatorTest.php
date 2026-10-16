<?php

namespace Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use Plugin0\Order\MinorUnits;
use Plugin0\Order\OperationValidator;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Amount;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Currency;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Order\OrderAction;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Order\OrderDetails;

/**
 * The rules that stop an operation before any request reaches Worldline (ticket: refunds above the captured
 * amount, captures above the authorised amount, cancelling a captured payment, zero or negative amounts).
 * The payment state is a Core OrderDetails built by hand, as OrderService::getDetails would build it.
 */
final class OperationValidatorTest extends TestCase
{
    private OperationValidator $validator;

    private Currency $eur;

    protected function setUp(): void
    {
        $this->validator = new OperationValidator();
        $this->eur = Currency::fromIsoCode('EUR');
    }

    public function testAuthorisedPaymentAllowsPartialCaptureAndCancel(): void
    {
        $details = $this->authorised(2294);

        self::assertNull($this->validator->validate('capture', $this->amount(1000), $details));
        self::assertNull($this->validator->validate('capture', $this->amount(2294), $details));
        self::assertNull($this->validator->validate('cancel', $this->amount(2294), $details));
    }

    public function testCaptureAboveAuthorisedAmountIsRejected(): void
    {
        $message = $this->validator->validate('capture', $this->amount(2295), $this->authorised(2294));

        self::assertSame('Capture of 22.95 EUR exceeds the capturable amount of 22.94 EUR.', $message);
    }

    public function testRefundAboveCapturedAmountIsRejected(): void
    {
        $details = $this->captured(2294, 444);

        self::assertNull($this->validator->validate('refund', $this->amount(1850), $details));
        self::assertSame(
            'Refund of 18.51 EUR exceeds the refundable amount of 18.50 EUR.',
            $this->validator->validate('refund', $this->amount(1851), $details)
        );
    }

    public function testCapturedPaymentCannotBeCancelled(): void
    {
        $message = $this->validator->validate('cancel', $this->amount(100), $this->captured(2294, 0));

        self::assertSame(
            'The payment cannot be cancelled: 22.94 EUR is already captured or requested for capture. Refund it instead.',
            $message
        );
    }

    public function testRemainderOfPartiallyCapturedPaymentCanBeCancelled(): void
    {
        $details = $this->partiallyCaptured(2294, 1000);

        // One of two articles shipped and captured: the uncaptured rest is released.
        self::assertNull($this->validator->validate('cancel', $this->amount(1294), $details));
        self::assertNull($this->validator->validate('cancel', $this->amount(500), $details));
        // The rest can still be captured instead.
        self::assertNull($this->validator->validate('capture', $this->amount(1294), $details));
    }

    public function testCancelAboveUncapturedRemainderIsRejected(): void
    {
        $message = $this->validator->validate('cancel', $this->amount(1295), $this->partiallyCaptured(2294, 1000));

        self::assertSame('Cancellation of 12.95 EUR exceeds the cancellable amount of 12.94 EUR.', $message);
    }

    public function testFullyCapturedDetection(): void
    {
        self::assertTrue(OperationValidator::fullyCaptured($this->captured(2294, 0)));
        self::assertFalse(OperationValidator::fullyCaptured($this->partiallyCaptured(2294, 1000)));
        self::assertFalse(OperationValidator::fullyCaptured($this->authorised(2294)));
    }

    public function testRefundOfAuthorisedOnlyPaymentIsNotPossible(): void
    {
        self::assertSame(
            'Refund is not possible for this payment in its current state at Worldline.',
            $this->validator->validate('refund', $this->amount(100), $this->authorised(2294))
        );
    }

    public function testCaptureOfCapturedPaymentIsNotPossible(): void
    {
        self::assertSame(
            'Capture is not possible for this payment in its current state at Worldline.',
            $this->validator->validate('capture', $this->amount(100), $this->captured(2294, 0))
        );
    }

    public function testMissingAmountIsRejectedBeforeAnythingElse(): void
    {
        $details = $this->authorised(2294);

        foreach (['capture', 'refund', 'cancel'] as $action) {
            self::assertSame(
                'Enter a positive amount with at most the number of decimals the currency has.',
                $this->validator->validate($action, MinorUnits::parse('0', $this->eur), $details),
                $action
            );
            self::assertNotNull($this->validator->validate($action, MinorUnits::parse('-1', $this->eur), $details));
        }
    }

    public function testUnknownActionIsRejected(): void
    {
        self::assertSame('Unknown payment operation.', $this->validator->validate('void', $this->amount(1), $this->authorised(100)));
    }

    private function amount(int $value): Amount
    {
        return Amount::fromInt($value, $this->eur);
    }

    /** Status 5 at Worldline: nothing captured yet, capture and cancel possible, refund not. */
    private function authorised(int $total): OrderDetails
    {
        return new OrderDetails(
            $this->amount($total),
            [],
            new OrderAction(true, $this->amount(0), $this->amount(0), $this->amount($total)),
            new OrderAction(false, $this->amount(0), $this->amount(0), $this->amount(0)),
            new OrderAction(true, $this->amount(0), $this->amount(0), $this->amount($total)),
            []
        );
    }

    /** Status 9 at Worldline, as order 9 in the dev shop: only a refund of what is left is possible. */
    private function captured(int $total, int $refunded): OrderDetails
    {
        return new OrderDetails(
            $this->amount($total),
            [],
            new OrderAction(false, $this->amount($total), $this->amount(0), $this->amount(0)),
            new OrderAction(true, $this->amount($refunded), $this->amount(0), $this->amount($total - $refunded)),
            new OrderAction(false, $this->amount(0), $this->amount(0), $this->amount(0)),
            []
        );
    }

    private function partiallyCaptured(int $total, int $captured): OrderDetails
    {
        return new OrderDetails(
            $this->amount($total),
            [],
            new OrderAction(true, $this->amount($captured), $this->amount(0), $this->amount($total - $captured)),
            new OrderAction(true, $this->amount(0), $this->amount(0), $this->amount($captured)),
            new OrderAction(true, $this->amount(0), $this->amount(0), $this->amount($total - $captured)),
            []
        );
    }
}
