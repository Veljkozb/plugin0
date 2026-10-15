<?php

namespace Plugin0\Checkout;

use Address as ShopAddress;
use Cart as ShopCart;
use Country as ShopCountry;
use Currency as ShopCurrency;
use Customer as ShopCustomer;
use Language;
use State;
use Tools;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Amount;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Address;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Cart;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\CartProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Customer\ContactDetails;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Customer\Customer;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Customer\PersonalInformation;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\LineItem;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\LineItemCollection;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\MemoryCachingCartProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Product;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\RoundingTotalsCartProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Shipping;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Country;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Currency;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\TaxableAmount;

/**
 * PrestaShop korpa kao Core Cart: sve što Core šalje Worldline-u u POST /hostedcheckouts (Core OrderTransformer
 * pravi od ovoga "order": iznos, merchantReference, kupca sa adresom za fakturu, dostavu sa adresom, popust i
 * stavke ako je u Core podešavanjima uključeno slanje korpe).
 *
 * merchantReference je ID PrestaShop korpe: Core ga upisuje u PaymentTransaction, a ShopOrderService po njemu posle
 * plaćanja nalazi korpu od koje pravi narudžbinu.
 *
 * Iznosi idu kroz Amount::fromFloat, u najmanjim jedinicama valute kao ceo broj (za RSD 2 decimale, po Core tabeli
 * u Currency). Ukupan iznos je Cart::getOrderTotal(true, Cart::BOTH), isti broj koji PaymentModule::validateOrder
 * posle poredi sa naplaćenim iznosom.
 */
final class PrestaShopCartProvider implements CartProvider
{
    private ShopCart $cart;

    public function __construct(ShopCart $cart)
    {
        $this->cart = $cart;
    }

    /**
     * Ono što front controller predaje Core-u. RoundingTotalsCartProvider (Core) dodaje stavku "rounding" ili
     * povećava popust kad zbir stavki, dostave i popusta zbog zaokruživanja po stavci nije jednak ukupnom iznosu;
     * MemoryCachingCartProvider (Core) pamti rezultat, jer Core get() zove više puta u istom zahtevu.
     */
    public static function for(ShopCart $cart): CartProvider
    {
        return new MemoryCachingCartProvider(new RoundingTotalsCartProvider(new self($cart)));
    }

    public function get(): Cart
    {
        $shopCurrency = new ShopCurrency((int) $this->cart->id_currency);
        $currency = Currency::fromIsoCode(strtoupper((string) $shopCurrency->iso_code));
        $total = (float) $this->cart->getOrderTotal(true, ShopCart::BOTH);

        $shopCustomer = new ShopCustomer((int) $this->cart->id_customer);
        $billingAddress = new ShopAddress((int) $this->cart->id_address_invoice);
        $contactDetails = new ContactDetails(
            (string) $shopCustomer->email,
            (string) ($billingAddress->phone_mobile ?: $billingAddress->phone)
        );

        $discount = (float) $this->cart->getOrderTotal(true, ShopCart::ONLY_DISCOUNTS);

        return new Cart(
            (string) $this->cart->id,
            Amount::fromFloat($total, $currency),
            $this->totalInEur($total, $shopCurrency),
            new Customer(
                $contactDetails,
                $this->address($billingAddress),
                (string) $shopCustomer->id,
                (bool) $shopCustomer->is_guest,
                (string) (new Language((int) $this->cart->id_lang))->locale
            ),
            $this->lineItems($currency),
            new Shipping(
                TaxableAmount::fromAmounts(
                    Amount::fromFloat((float) $this->cart->getOrderTotal(false, ShopCart::ONLY_SHIPPING), $currency),
                    Amount::fromFloat((float) $this->cart->getOrderTotal(true, ShopCart::ONLY_SHIPPING), $currency)
                ),
                $this->address(new ShopAddress((int) $this->cart->id_address_delivery)),
                $contactDetails
            ),
            $discount > 0 ? Amount::fromFloat($discount, $currency) : null
        );
    }

    /**
     * Stavke korpe sa jediničnom cenom bez poreza i sa porezom (Cart::getProducts: price i price_wt), plus
     * poklon-pakovanje kao posebna stavka kad ga ima.
     */
    private function lineItems(Currency $currency): LineItemCollection
    {
        $items = new LineItemCollection();
        foreach ($this->cart->getProducts() as $product) {
            $items->add(new LineItem(
                new Product(
                    $product['id_product'] . '-' . $product['id_product_attribute'],
                    (string) $product['name'],
                    (string) $product['reference']
                ),
                TaxableAmount::fromAmounts(
                    Amount::fromFloat((float) $product['price'], $currency),
                    Amount::fromFloat((float) $product['price_wt'], $currency)
                ),
                (int) $product['cart_quantity']
            ));
        }

        $wrapping = (float) $this->cart->getOrderTotal(true, ShopCart::ONLY_WRAPPING);
        if ($wrapping > 0) {
            $items->add(new LineItem(
                new Product('wrapping', 'Gift wrapping'),
                TaxableAmount::fromAmounts(
                    Amount::fromFloat((float) $this->cart->getOrderTotal(false, ShopCart::ONLY_WRAPPING), $currency),
                    Amount::fromFloat($wrapping, $currency)
                ),
                1
            ));
        }

        return $items;
    }

    /**
     * PrestaShop adresa kao Core adresa. PrestaShop nema posebno polje za kućni broj (deo je address1), pa
     * houseNumber ostaje prazan; state je ime regiona, ako ga adresa ima.
     */
    private function address(ShopAddress $address): Address
    {
        return new Address(
            Country::fromIsoCode((string) ShopCountry::getIsoById((int) $address->id_country)),
            (int) $address->id_state > 0 ? (string) State::getNameById((int) $address->id_state) : '',
            (string) $address->city,
            (string) $address->postcode,
            (string) $address->address1,
            '',
            new PersonalInformation((string) $address->firstname, (string) $address->lastname),
            (string) $address->company,
            (string) $address->address2
        );
    }

    /**
     * Ukupan iznos u evrima, koji Core koristi za 3DS izuzeća po iznosu; null kad prodavnica nema EUR (tako
     * Core Cart i traži).
     */
    private function totalInEur(float $total, ShopCurrency $cartCurrency): ?Amount
    {
        $eurId = (int) ShopCurrency::getIdByIsoCode('EUR');
        if ($eurId === 0) {
            return null;
        }

        return Amount::fromFloat(
            (float) Tools::convertPriceFull($total, $cartCurrency, new ShopCurrency($eurId)),
            Currency::fromIsoCode('EUR')
        );
    }
}
