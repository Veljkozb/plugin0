<?php

namespace Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use Plugin0\Infrastructure\PaymentsProxy;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Amount;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Currency;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentAmounts;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentDetails;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentId;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\StatusCode;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\StatusOutput;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Proxies\PaymentsProxyInterface;

/**
 * After partial captures Worldline keeps the original authorisation in status 5 while the captures are separate
 * operations. Once nothing of the authorised amount is left (captured + cancelled = total) the payment must read as
 * captured and refundable, and as refunded once everything captured has been refunded. Anything else passes through.
 */
final class PaymentsProxyTest extends TestCase
{
    private Currency $eur;

    protected function setUp(): void
    {
        $this->eur = Currency::fromIsoCode('EUR');
    }

    public function testClosedAuthorizationReadsAsCapturedAndRefundable(): void
    {
        // Payment 10300628270 on 2026-10-16: 30 + 11 + 10 captured, 17.93 cancelled, nothing left of 68.93.
        $settled = PaymentsProxy::settleClosedAuthorization($this->authorised(6893, 5100, 0, 1793, 0));

        self::assertSame(9, $settled->getStatusCode()->getCode());
        self::assertSame('CAPTURED', $settled->getStatus());
        self::assertFalse($settled->getStatusOutput()->isAuthorized());
        self::assertFalse($settled->getStatusOutput()->isCancellable());
        self::assertTrue($settled->getStatusOutput()->isRefundable());
        self::assertSame(5100, $settled->getAmounts()->getCapturedAmount()->getValue(), 'amounts untouched');
        self::assertSame(6893, $settled->getAmount()->getValue());
        self::assertSame('tok', $settled->getTokenId());
        self::assertSame('Visa', $settled->getPaymentMethod());
    }

    public function testClosedAuthorizationWithEverythingRefundedReadsAsRefunded(): void
    {
        $settled = PaymentsProxy::settleClosedAuthorization($this->authorised(6893, 5100, 0, 1793, 5100));

        self::assertSame(8, $settled->getStatusCode()->getCode());
        self::assertSame('REFUNDED', $settled->getStatus());
        self::assertFalse($settled->getStatusOutput()->isRefundable());
    }

    public function testPartialRefundKeepsCaptured(): void
    {
        $settled = PaymentsProxy::settleClosedAuthorization($this->authorised(6893, 5100, 0, 1793, 100));

        self::assertSame(9, $settled->getStatusCode()->getCode());
        self::assertTrue($settled->getStatusOutput()->isRefundable());
    }

    public function testRemainderStillReservedPassesThrough(): void
    {
        $details = $this->authorised(6893, 4100, 0, 0, 0);

        self::assertSame($details, PaymentsProxy::settleClosedAuthorization($details));
    }

    public function testCaptureStillInProgressPassesThrough(): void
    {
        $details = $this->authorised(6893, 4100, 1000, 1793, 0);

        self::assertSame($details, PaymentsProxy::settleClosedAuthorization($details));
    }

    public function testNothingCapturedPassesThrough(): void
    {
        self::assertSame(
            $details = $this->authorised(6893, 0, 0, 6893, 0),
            PaymentsProxy::settleClosedAuthorization($details),
            'fully cancelled: Worldline sets the status itself'
        );
        self::assertSame($details = $this->authorised(6893, 0, 0, 0, 0), PaymentsProxy::settleClosedAuthorization($details));
    }

    public function testOtherStatusesPassThrough(): void
    {
        foreach ([9, 91, 2, 6, 8, 0] as $code) {
            $details = new PaymentDetails(
                StatusCode::parse($code),
                $this->amount(6893),
                null,
                new PaymentAmounts($this->amount(0), $this->amount(0), $this->amount(6893), $this->amount(0), $this->amount(0)),
                true,
                'Visa',
                new StatusOutput(false, false, true),
                null,
                'X',
                []
            );
            self::assertSame($details, PaymentsProxy::settleClosedAuthorization($details), (string) $code);
        }
    }

    public function testMissingAmountsPassThrough(): void
    {
        $details = new PaymentDetails(StatusCode::authorized(), $this->amount(6893), null);

        self::assertSame($details, PaymentsProxy::settleClosedAuthorization($details));
    }

    public function testDecoratorAppliesTheRuleToCoreResult(): void
    {
        $inner = $this->createMock(PaymentsProxyInterface::class);
        $inner->method('getPaymentDetails')->willReturn($this->authorised(6893, 5100, 0, 1793, 0));

        $settled = (new PaymentsProxy($inner))->getPaymentDetails(PaymentId::parse('10300628270'));

        self::assertSame(9, $settled->getStatusCode()->getCode());
    }

    private function amount(int $value): Amount
    {
        return Amount::fromInt($value, $this->eur);
    }

    /** Status 5 at Worldline with the given operation sums, exactly as Core's transformer builds it. */
    private function authorised(int $total, int $captured, int $captureRequested, int $cancelled, int $refunded): PaymentDetails
    {
        return new PaymentDetails(
            StatusCode::authorized(),
            $this->amount($total),
            'tok',
            new PaymentAmounts($this->amount($refunded), $this->amount(0), $this->amount($captured), $this->amount($captureRequested), $this->amount($cancelled)),
            false,
            'Visa',
            new StatusOutput(true, true, false),
            null,
            'PENDING_CAPTURE',
            []
        );
    }
}
