<?php

namespace Plugin0\Checkout;

use Address;
use Cart;
use Country;
use Currency;
use Logeecom\PaymentMethods\Domain\PaymentMethod;
use Plugin0\Services;

/**
 * Koje metode plaćanja iz dema prodavnica nudi jednoj korpi: aktivne, sa valutom korpe u svojoj listi valuta i sa
 * zemljom adrese za fakturu u svojoj listi zemalja, u redosledu sort order (PaymentMethodService::list).
 *
 * Zemlja je ona sa adrese za fakturu (Cart::id_address_invoice), jer PrestaShop po njoj odlučuje koji moduli
 * plaćanja uopšte važe (Module::getPaymentModules, tabela module_country); valuta je valuta korpe
 * (Cart::id_currency). hookPaymentOptions zove ovo pri prikazu izbora, a front controller "payment" još jednom pri
 * potvrdi, jer je kupac u međuvremenu mogao da promeni adresu ili valutu.
 */
final class AvailablePaymentMethods
{
    /**
     * @return list<PaymentMethod>
     */
    public static function forCart(Cart $cart): array
    {
        return self::for(self::currencyOf($cart), self::countryOf($cart));
    }

    /**
     * @return list<PaymentMethod>
     */
    public static function for(string $currencyIso, string $countryIso): array
    {
        return array_values(array_filter(
            Services::paymentMethods()->list(),
            static function (PaymentMethod $method) use ($currencyIso, $countryIso): bool {
                return $method->isActive
                    && in_array($currencyIso, $method->currencies, true)
                    && in_array($countryIso, $method->countries, true);
            }
        ));
    }

    /** ISO 4217 kod valute korpe. */
    public static function currencyOf(Cart $cart): string
    {
        return strtoupper((string) (new Currency((int) $cart->id_currency))->iso_code);
    }

    /** ISO 3166-1 alpha-2 kod zemlje adrese za fakturu; prazno kad korpa još nema adresu. */
    public static function countryOf(Cart $cart): string
    {
        $address = new Address((int) $cart->id_address_invoice);

        return strtoupper((string) Country::getIsoById((int) $address->id_country));
    }
}
