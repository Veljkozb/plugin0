<?php
/**
 * Front controller "payment" modula plugin0: stranica na koju kupca vodi opcija plaćanja izabrana na checkout-u
 * (Plugin0::hookPaymentOptions). Adresa: index.php?fc=module&module=plugin0&controller=payment
 * (Link::getModuleLink); metoda stiže kao POST polje "method" (id).
 *
 * Za sada samo potvrđuje izbor: proverava da je metoda i dalje dostupna za korpu (aktivna, valuta korpe, zemlja
 * adrese za fakturu) i prikazuje je. Samo plaćanje, Worldline Hosted Checkout za tip Redirect i Hosted Tokenization
 * za tip iFrame, dolazi u sledećem koraku kroz Core CheckoutAPI; tada ova stranica pravi sesiju i šalje kupca dalje.
 */
class Plugin0PaymentModuleFrontController extends ModuleFrontController
{
    /** @var bool stranica plaćanja ide samo preko HTTPS-a, kao kod ps_wirepayment */
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

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
        foreach (\Plugin0\Checkout\AvailablePaymentMethods::forCart($cart) as $available) {
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

        $presented = \Plugin0\Services::paymentMethods()->present($method);

        $this->context->smarty->assign([
            'method_name' => $presented['name'],
            'method_code' => $presented['code'],
            'type_label' => $presented['type_label'],
            'type_description' => $presented['type_description'],
            'total' => $this->context->getCurrentLocale()->formatPrice(
                $cart->getOrderTotal(true, Cart::BOTH),
                $this->context->currency->iso_code
            ),
            'back_url' => $this->context->link->getPageLink('order', true, null, 'step=3'),
        ]);

        $this->setTemplate('module:plugin0/views/templates/front/payment.tpl');
    }
}
