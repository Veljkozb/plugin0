<?php

namespace Plugin0\Checkout;

use Address;
use Cart;
use Country;
use Currency;
use Logeecom\PaymentMethods\Domain\PaymentMethod;
use Logeecom\PaymentMethods\Domain\PaymentMethodType;
use Plugin0\Services;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;

/**
 * Koje metode plaćanja iz dema prodavnica nudi jednoj korpi: aktivne, sa valutom korpe u svojoj listi valuta i sa
 * zemljom adrese za fakturu u svojoj listi zemalja, u redosledu sort order (PaymentMethodService::list).
 *
 * Plaćanje ide preko Worldline Hosted Checkout strane, pa se nude samo metode koje taj put mogu da prođu:
 * - tip Redirect; tip iFrame (Hosted Tokenization) još nije povezan, pa se iFrame metode ne nude;
 * - Code je Worldline ID proizvoda koji Core podržava (PaymentProductId::isSupported, npr. 1 = Visa,
 *   302 = Apple Pay), jer front controller "payment" od njega pravi PaymentProductId za Worldline stranu;
 * - proizvod nije u PaymentProductId::SEPARATE_CAPTURE_SUPPORTED (npr. 302 = Apple Pay, 320 = Google Pay, 840 =
 *   PayPal): za njih Core 1.0.7 u CreateHostedCheckoutRequestTransformer::transform() čita payment action iz
 *   svoje konfiguracije metode (PaymentMethodConfigEntity, admin Core-a) bez provere na null; plugin0 te redove
 *   nema (metode su demo metode), pa createSession vraća grešku "Call to a member function getPaymentAction() on
 *   null" i kupac vidi "The payment could not be started". Dok se konfiguracija ne poveže, te metode se ne nude.
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
                    && $method->type === PaymentMethodType::Redirect
                    && PaymentProductId::isSupported($method->code)
                    && !PaymentProductId::parse($method->code)->isSeparateCaptureSupported()
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
