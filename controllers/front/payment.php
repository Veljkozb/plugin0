<?php
/**
 * Front controller "payment" modula plugin0: ovde stiže klik na PLACE ORDER. Klasična tema (themes/core.js) šalje
 * formu izabrane opcije plaćanja (Plugin0::hookPaymentOptions): POST na
 * index.php?fc=module&module=plugin0&controller=payment, polje "method" = id metode.
 *
 * Redosled:
 * 1. proveri da je metoda i dalje dostupna za korpu (AvailablePaymentMethods: aktivna, Redirect, Code poznat
 *    Core-u, valuta korpe, zemlja adrese za fakturu), jer je kupac posle prikaza izbora mogao da promeni korpu;
 * 2. Core pravi Worldline Hosted Checkout sesiju (POST /hostedcheckouts) od korpe (PrestaShopCartProvider), sa
 *    Code-om metode kao jedinim proizvodom na Worldline strani, i čuva PaymentTransaction (ID korpe,
 *    hostedCheckoutId, RETURNMAC) u ps_plugin0_entity;
 * 3. kupac ide na Worldline stranu; posle plaćanja Worldline ga vraća na front controller "return".
 *
 * Narudžbina ovde ne nastaje: pravi je ShopOrderService tek kad Core na povratku utvrdi da je plaćeno.
 */

use Plugin0\Checkout\AvailablePaymentMethods;
use Plugin0\Checkout\PrestaShopCartProvider;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\CheckoutAPI\CheckoutAPI;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedCheckout\HostedCheckoutSessionRequest;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;

class Plugin0PaymentModuleFrontController extends ModuleFrontController
{
    /** @var bool stranica plaćanja ide samo preko HTTPS-a, kao kod ps_wirepayment */
    public $ssl = true;

    public function postProcess()
    {
        \Plugin0\Bootstrap::boot();

        $cart = $this->context->cart;
        if (!$this->module->active
            || !$cart instanceof Cart
            || (int) $cart->id_customer === 0
            || (int) $cart->id_address_delivery === 0
            || (int) $cart->id_address_invoice === 0
            || !$this->module->checkCurrency($cart)
        ) {
            Tools::redirect('index.php?controller=order&step=1');

            return;
        }

        $id = (int) Tools::getValue('method');
        $method = null;
        foreach (AvailablePaymentMethods::forCart($cart) as $available) {
            if ($available->id === $id) {
                $method = $available;
                break;
            }
        }

        if ($method === null) {
            // Metoda je u međuvremenu isključena ili je kupac promenio adresu ili valutu: nazad na izbor plaćanja.
            Tools::redirect('index.php?controller=order&step=1');

            return;
        }

        // Code je proveren u AvailablePaymentMethods (PaymentProductId::isSupported), pa parse ovde ne baca izuzetak.
        $response = CheckoutAPI::get()->hostedCheckout((string) $this->context->shop->id)->createSession(
            new HostedCheckoutSessionRequest(
                PrestaShopCartProvider::for($cart),
                $this->context->link->getModuleLink($this->module->name, 'return', [], true),
                PaymentProductId::parse($method->code)
            )
        );

        if (!$response->isSuccessful()) {
            // Fasada ne baca izuzetak: grešku je ErrorHandlingAspect već upisao u log i vratio kao odgovor.
            $this->errors[] = $this->trans(
                'The payment could not be started. Please try again or choose another payment method.',
                [],
                'Modules.Plugin0.Shop'
            );
            $this->redirectWithNotifications($this->context->link->getPageLink('order', true));

            return;
        }

        Tools::redirect($response->getRedirectUrl());
    }
}
