<?php

namespace Plugin0\Infrastructure;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\PaymentAction;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\PaymentSettings;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\PaymentRequest;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\PaymentResponse;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\Token;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\Payment;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentDetails;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentId;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\StatusCode;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\StatusOutput;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\ThreeDSSettings\ThreeDSSettings;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Proxies\PaymentsProxyInterface;

/**
 * Plugin0 omotač oko Core Bootstrap\ApiFacades\PaymentProcessor\Proxies\PaymentsProxy, registrovan u
 * Plugin0\Bootstrap::initProxies pod ključem PaymentsProxyInterface (zamenjuje Core registraciju). Sve prosleđuje
 * Core proxy-ju; jedino rezultat getPaymentDetails (GET /payments/{id}/details) propusti kroz settleClosedAuthorization.
 *
 * Zašto: posle delimičnih naplata Worldline plaćanje vodi kao više operacija: prvobitna rezervacija `_0` ostaje u
 * statusu 5 (autorizovano, isRefundable=false), a svaka naplata `_1`, `_2`... je posebna operacija sa statusom 9
 * (isRefundable=true); otkazivanje ostatka je operacija sa statusom 6. Core status plaćanja i zastavice
 * isAuthorized / isCancellable / isRefundable uzima samo sa `_0` (PaymentDetailsResponseTransformer), iznose sabira iz
 * operacija. Zato narudžbina posle "naplaćeno 51.00 + otkazano 17.93 od 68.93" ostaje u statusu za autorizovano, a
 * refund je u OrderService::getDetails sveden na 0, iako Worldline refund na `_0` prihvata (provereno 2026-10-16,
 * plaćanje 10300628270, refund 10300628270_5). Core se ne menja.
 */
class PaymentsProxy implements PaymentsProxyInterface
{
    private PaymentsProxyInterface $inner;

    public function __construct(PaymentsProxyInterface $inner)
    {
        $this->inner = $inner;
    }

    public function create(
        PaymentRequest $request,
        ThreeDSSettings $cardsSettings,
        PaymentSettings $paymentSettings,
        ?Token $token = null,
        ?PaymentAction $paymentAction = null
    ): PaymentResponse {
        return $this->inner->create($request, $cardsSettings, $paymentSettings, $token, $paymentAction);
    }

    public function getPaymentDetails(PaymentId $paymentId): PaymentDetails
    {
        return self::settleClosedAuthorization($this->inner->getPaymentDetails($paymentId));
    }

    public function tryToGetPayment(PaymentId $paymentId): ?Payment
    {
        return $this->inner->tryToGetPayment($paymentId);
    }

    public function getPayment(PaymentId $paymentId): Payment
    {
        return $this->inner->getPayment($paymentId);
    }

    public function getRefunds(PaymentId $paymentId): array
    {
        return $this->inner->getRefunds($paymentId);
    }

    public function getCaptures(PaymentId $paymentId): array
    {
        return $this->inner->getCaptures($paymentId);
    }

    /**
     * Rezervacija je zatvorena kad je status još 5 (autorizovano), nešto je naplaćeno, nijedna naplata nije više u
     * obradi, i od autorizovanog iznosa ništa nije ostalo (autorizovano − naplaćeno − otkazano <= 0). Tada plaćanje
     * dobija status 9 / CAPTURED, isAuthorized=false, isCancellable=false, isRefundable=true, pa Core narudžbinu
     * mapira na "naplaćeno" i dozvoli refund do naplaćenog iznosa. Ako je uz to sve naplaćeno već i vraćeno (vraćeno
     * >= naplaćeno), status je 8 / REFUNDED, isRefundable=false, pa Core narudžbinu mapira na "vraćeno"; bez toga bi
     * Core puni refund prepoznao samo kad je vraćen ceo autorizovani iznos (StatusUpdateService).
     * Iznosi, operacije, token i podaci o kartici ostaju netaknuti. U svakom drugom slučaju vraća ulaz nepromenjen.
     */
    public static function settleClosedAuthorization(PaymentDetails $details): PaymentDetails
    {
        $amounts = $details->getAmounts();
        if ($amounts === null || !$details->getStatusCode()->equals(StatusCode::authorized())) {
            return $details;
        }

        $captured = $amounts->getCapturedAmount() ? $amounts->getCapturedAmount()->getValue() : 0;
        $captureRequested = $amounts->getCaptureRequestedAmount() ? $amounts->getCaptureRequestedAmount()->getValue() : 0;
        $cancelled = $amounts->getCancelledAmount() ? $amounts->getCancelledAmount()->getValue() : 0;
        $refunded = $amounts->getRefundedAmount() ? $amounts->getRefundedAmount()->getValue() : 0;

        if ($captured <= 0 || $captureRequested > 0 || $details->getAmount()->getValue() - $captured - $cancelled > 0) {
            return $details;
        }

        $errors = $details->getStatusOutput() ? $details->getStatusOutput()->getErrors() : [];
        $fullyRefunded = $refunded >= $captured;

        return new PaymentDetails(
            $fullyRefunded ? StatusCode::parse(8) : StatusCode::completed(),
            $details->getAmount(),
            $details->getTokenId(),
            $amounts,
            $details->isFullyPaid(),
            $details->getPaymentMethod(),
            new StatusOutput(false, false, !$fullyRefunded, $errors),
            $details->getPaymentSpecificOutput(),
            $fullyRefunded ? 'REFUNDED' : 'CAPTURED',
            $details->getOperations()
        );
    }
}
