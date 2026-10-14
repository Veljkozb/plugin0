<?php

namespace Plugin0\Infrastructure;

use Context;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Payment\ShopPaymentService as ShopPaymentServiceInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentMethod;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;

/**
 * Šta PrestaShop strana radi kad Core sačuva, uključi ili obriše svoja podešavanja Worldline metode plaćanja. Core
 * zove ove metode posle sopstvenog upisa (AdminConfig PaymentController::save/enable) i pri disconnect-u
 * (DisconnectService).
 *
 * Plugin0 tu nema šta da upiše: metode plaćanja koje admin pravi (Logeecom\PaymentMethods) nisu vezane za
 * Worldline katalog ni za konekciju, pa ih disconnect ne dira. Ostaje zapis u log, kao trag radnje.
 */
class ShopPaymentService implements ShopPaymentServiceInterface
{
    private const COMPONENT = 'ShopPaymentService';

    public function savePaymentMethod(PaymentMethod $paymentMethod): void
    {
        Logger::logInfo('Worldline payment method settings saved', self::COMPONENT, LoggerService::context([
            'productId' => $paymentMethod->getProductId()->getId(),
            'name' => $paymentMethod->getName()->getDefaultTranslation()->getMessage(),
            'enabled' => $paymentMethod->isEnabled(),
            'employeeId' => $this->employeeId(),
        ]));
    }

    public function enable(string $paymentProductId, bool $enabled): void
    {
        Logger::logInfo($enabled ? 'Worldline payment method enabled' : 'Worldline payment method disabled', self::COMPONENT, LoggerService::context([
            'productId' => $paymentProductId,
            'employeeId' => $this->employeeId(),
        ]));
    }

    /** Core je zove pri disconnect-u, unutar StoreContext-a prodavnice koja se odvezuje. */
    public function deletePaymentMethods(string $mode): void
    {
        Logger::logInfo('Worldline payment method settings deleted', self::COMPONENT, LoggerService::context([
            'mode' => $mode,
            'employeeId' => $this->employeeId(),
        ]));
    }

    /** ID zaposlenog iz admina; null van admina, na primer u pozadinskom zadatku. */
    private function employeeId(): ?int
    {
        $employee = Context::getContext()->employee ?? null;

        return $employee !== null && (int) ($employee->id ?? 0) > 0 ? (int) $employee->id : null;
    }
}
