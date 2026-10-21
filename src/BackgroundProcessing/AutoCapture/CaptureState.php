<?php

namespace Plugin0\BackgroundProcessing\AutoCapture;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Amount;

/**
 * Stanje jednog plaćanja kod Worldline-a koje automatska naplata gleda pre capture-a: koliko još može da se naplati,
 * koliko je već naplaćeno i koliko je zatraženo za naplatu. Pravi ga AutoCaptureGateway iz Core PaymentDetails
 * (GET /payments/{id}/details) istom formulom kao Core OrderService::getDetails (OrderService.php:47-49):
 * available = autorizovano − naplaćeno − zatraženo za naplatu − otkazano, a 0 kad Worldline kaže da plaćanje nije
 * autorizovano (statusOutput.isAuthorized = false).
 */
final class CaptureState
{
    private Amount $available;
    private Amount $captured;
    private Amount $captureRequested;
    private int $statusCode;

    public function __construct(Amount $available, Amount $captured, Amount $captureRequested, int $statusCode)
    {
        $this->available = $available;
        $this->captured = $captured;
        $this->captureRequested = $captureRequested;
        $this->statusCode = $statusCode;
    }

    public function canCapture(): bool
    {
        return $this->available->getValue() > 0;
    }

    public function getAvailable(): Amount
    {
        return $this->available;
    }

    public function getCaptured(): Amount
    {
        return $this->captured;
    }

    public function getCaptureRequested(): Amount
    {
        return $this->captureRequested;
    }

    /** Worldline statusCode plaćanja (5 = autorizovano, 91 = zatražena naplata, 9 = naplaćeno, ...). */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
