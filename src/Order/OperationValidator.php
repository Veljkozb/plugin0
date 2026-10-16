<?php

namespace Plugin0\Order;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Amount;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Order\OrderAction;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Order\OrderDetails;

/**
 * Pravila koja zaustavljaju capture, refund ili cancel pre nego što zahtev ode Worldline-u (tiket: validacija na
 * serveru, ne samo isključena dugmad). Core ih nema: CaptureService, RefundService i CancelService samo prosleđuju
 * zahtev proxy-ju.
 *
 * Stanje plaćanja je Core OrderDetails (OrderAPI::orders()->getDetails), sveže pročitano od Worldline-a. Za
 * svaku akciju Core daje OrderAction: possible (Worldline statusOutput isAuthorized / isRefundable /
 * isCancellable), done (već urađeno), pending (zatraženo, još nije prošlo) i available (koliko još može).
 *
 * Pravila, redom:
 * - iznos: pozitivan broj sa decimalama valute (MinorUnits::parse); nula, negativan i tekst su odbijeni;
 * - capture: moguć po Worldline-u, iznos do capture.available (= autorizovano − naplaćeno − zatraženo − otkazano);
 * - refund: moguć, iznos do refund.available (= naplaćeno − vraćeno − zatraženo za povraćaj);
 * - cancel: oslobađa samo deo koji nije naplaćen niti zatražen za naplatu, iznos do cancel.available
 *   (= autorizovano − naplaćeno − zatraženo − otkazano). Posle delimičnog capture-a ostatak se može otkazati (npr.
 *   naplaćen jedan od dva artikla). Kad je sve naplaćeno ili zatraženo za naplatu, cancel je odbijen porukom da se
 *   radi refund (tiket AC4: naplaćeno plaćanje se ne otkazuje).
 *
 * Vraća poruku za trgovca ili null kad je sve u redu. Čista PHP klasa, pokrivena unit testovima.
 */
final class OperationValidator
{
    public const CAPTURE = 'capture';
    public const REFUND = 'refund';
    public const CANCEL = 'cancel';

    public const ACTIONS = [self::CAPTURE, self::REFUND, self::CANCEL];

    public function validate(string $action, ?Amount $amount, OrderDetails $details): ?string
    {
        if ($amount === null) {
            return 'Enter a positive amount with at most the number of decimals the currency has.';
        }

        switch ($action) {
            case self::CAPTURE:
                return $this->check($details->getCapture(), $amount, 'Capture', 'capturable');
            case self::REFUND:
                return $this->check($details->getRefund(), $amount, 'Refund', 'refundable');
            case self::CANCEL:
                $capture = $details->getCapture();
                if (self::fullyCaptured($details)) {
                    return sprintf(
                        'The payment cannot be cancelled: %s is already captured or requested for capture. Refund it instead.',
                        MinorUnits::format($capture->getDone()->plus($capture->getPending()))
                    );
                }

                return $this->check($details->getCancel(), $amount, 'Cancellation', 'cancellable');
        }

        return 'Unknown payment operation.';
    }

    /**
     * Ništa više nije rezervisano za otkazivanje, a nešto je naplaćeno ili zatraženo za naplatu: to je naplaćeno
     * plaćanje, koje se vraća refund-om, ne cancel-om. Koristi ga i panel (PaymentManager) za prikaz.
     */
    public static function fullyCaptured(OrderDetails $details): bool
    {
        $capture = $details->getCapture();
        $capturedOrRequested = $capture->getDone()->getValue() + $capture->getPending()->getValue();

        return $capturedOrRequested > 0 && $details->getCancel()->getAvailable()->getValue() <= 0;
    }

    private function check(OrderAction $state, Amount $amount, string $label, string $availableLabel): ?string
    {
        if (!$state->isPossible() || $state->getAvailable()->getValue() <= 0) {
            return sprintf('%s is not possible for this payment in its current state at Worldline.', $label);
        }

        if ($amount->getValue() > $state->getAvailable()->getValue()) {
            return sprintf(
                '%s of %s exceeds the %s amount of %s.',
                $label,
                MinorUnits::format($amount),
                $availableLabel,
                MinorUnits::format($state->getAvailable())
            );
        }

        return null;
    }
}
