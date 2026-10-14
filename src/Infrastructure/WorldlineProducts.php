<?php

namespace Plugin0\Infrastructure;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentMethodDefaultConfigs;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;

/**
 * Worldline proizvodi koje Core podržava, kao ponuda za polje Code na formi metode plaćanja: ID proizvoda => naziv
 * (`840` => `Paypal`, `1` => `Visa`, `cards` => `Credit Card`). Lista je fiksna u Core-u
 * (PaymentProductId::SUPPORTED_PAYMENT_PRODUCTS, nazivi iz PaymentMethodDefaultConfigs); Worldline se ne pita.
 *
 * Samo ponuda: admin može da upiše i kod koga nema u listi, a Name ostaje njegov.
 */
final class WorldlineProducts
{
    /**
     * @return array<string, string> ID proizvoda => engleski naziv iz Core-a, redosledom Core liste
     */
    public static function names(): array
    {
        $names = [];
        foreach (PaymentProductId::SUPPORTED_PAYMENT_PRODUCTS as $productId) {
            $names[$productId] = (string) (PaymentMethodDefaultConfigs::PAYMENT_METHOD_CONFIGS[$productId]['name']['translation'] ?? $productId);
        }

        return $names;
    }
}
