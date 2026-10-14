<?php

namespace Plugin0\Infrastructure;

use Context;
use Plugin0\PaymentMethods\PaymentMethodRestrictionRepository;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Payment\ShopPaymentService as ShopPaymentServiceInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentMethod;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;

/**
 * Šta PrestaShop strana radi kad admin sačuva, uključi ili obriše metodu plaćanja. Core zove ove metode posle
 * sopstvenog upisa (AdminConfig PaymentController::save/enable) i pri disconnect-u (DisconnectService).
 *
 * Jedino sopstveno stanje metoda u plugin0 su zemlje i valute (PaymentMethodRestrictionEntity), pa ih
 * deletePaymentMethods briše zajedno sa Core-ovim podešavanjima metoda. Ostalo je zapis u log, kao trag admin radnje
 * (demo je svaku radnju nad metodom logovao na INFO sa korisnikom).
 */
class ShopPaymentService implements ShopPaymentServiceInterface
{
    private const COMPONENT = 'ShopPaymentService';

    public function savePaymentMethod(PaymentMethod $paymentMethod): void
    {
        Logger::logInfo('Payment method saved', self::COMPONENT, LoggerService::context([
            'productId' => $paymentMethod->getProductId()->getId(),
            'name' => $paymentMethod->getName()->getDefaultTranslation()->getMessage(),
            'enabled' => $paymentMethod->isEnabled(),
            'employeeId' => $this->employeeId(),
        ]));
    }

    public function enable(string $paymentProductId, bool $enabled): void
    {
        Logger::logInfo($enabled ? 'Payment method enabled' : 'Payment method disabled', self::COMPONENT, LoggerService::context([
            'productId' => $paymentProductId,
            'employeeId' => $this->employeeId(),
        ]));
    }

    /** Core je zove pri disconnect-u, unutar StoreContext-a prodavnice koja se odvezuje. */
    public function deletePaymentMethods(string $mode): void
    {
        (new PaymentMethodRestrictionRepository(StoreContext::getInstance()->getStoreId()))->deleteByMode($mode);

        Logger::logInfo('Payment methods deleted', self::COMPONENT, LoggerService::context([
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
