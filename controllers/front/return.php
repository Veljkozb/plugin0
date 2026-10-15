<?php
/**
 * Front controller "return" modula plugin0: ovde Worldline vraća kupca sa Hosted Checkout strane (returnUrl iz
 * front controller-a "payment") i dodaje query parametre hostedCheckoutId i RETURNMAC.
 *
 * Adresa je javna i svako može da je pozove sa bilo kojim vrednostima, zato:
 * - bez ispravnog hostedCheckoutId i nepraznog RETURNMAC-a ne zove Core;
 * - Core transakciju traži po paru payment ID + RETURNMAC (PaymentTransactionRepository::get), pa izmenjen ID
 *   ili tuđi RETURNMAC daju "transakcija nije nađena", a ne potvrdu;
 * - status plaćanja Core uvek čita od Worldline-a (GET /payments/{id}/details), nikad iz URL-a.
 *
 * Redosled:
 * 1. payment()->updateOrderStatus: Core odmah pita Worldline i preko ShopOrderService pravi narudžbinu ako je
 *    plaćeno (bez webhook-a bi startWaitingForOutcome uvek prvo čekao oko 10 s);
 * 2. payment()->getPaymentOutcome; ako je plaćanje i dalje u obradi (isWaiting), startWaitingForOutcome: Core čeka
 *    najviše 10 s od povratka, pa ponovo pita Worldline;
 * 3. kupac ide na potvrdu narudžbine (narudžbina postoji), nazad na korak plaćanja sa porukom (otkazano, odbijeno,
 *    nije potvrđeno; korpa ostaje) ili na stranicu "plaćanje se obrađuje" (narudžbina još ne postoji).
 *
 * Ponovljen poziv iste adrese ne pravi drugu narudžbinu ni novu promenu statusa: ShopOrderService je idempotentan.
 * Fasade ne bacaju izuzetke (ErrorHandlingAspect grešku upiše u log i vrati kao odgovor), pa ovde nema try/catch.
 */

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\CheckoutAPI\CheckoutAPI;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\ApiFacades\Response\Response;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentId;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\StatusCode;

class Plugin0ReturnModuleFrontController extends ModuleFrontController
{
    /**
     * Isti obrazac kao privatni PaymentId::VALID_FORMAT_PATTERN u Core-u: sa njim PaymentId::parse ne baca
     * izuzetak.
     */
    private const PAYMENT_ID_PATTERN = '/^\d+(_\d+)?$/';

    /**
     * Worldline statusCode 2 = odbijeno (REJECTED); 1 i 6 su otkazano (značenje kodova je iz Worldline
     * dokumentacije, Core ih ne razdvaja: StatusCode::isCanceledOrRejected = 1, 2, 6). Koristi se samo za tekst
     * poruke kupcu; šta se dešava sa narudžbinom odlučuje Core (cancelShopOrder).
     */
    private const REFUSED_STATUS_CODE = 2;

    /** @var bool stranica povratka ide samo preko HTTPS-a, kao stranica plaćanja */
    public $ssl = true;

    /** @var bool plaćanje je i posle čekanja u obradi, a narudžbina ne postoji: prikazuje se return.tpl */
    private bool $stillProcessing = false;

    public function postProcess()
    {
        \Plugin0\Bootstrap::boot();

        // Pre Core poziva: validateOrder (preko ShopOrderService) Context-u postavlja kupca iz korpe.
        $customerId = (int) $this->context->customer->id;

        $hostedCheckoutId = $this->stringParam('hostedCheckoutId');
        $returnMac = $this->stringParam('RETURNMAC');
        if (preg_match(self::PAYMENT_ID_PATTERN, $hostedCheckoutId) !== 1 || $returnMac === '') {
            $this->backToPayment($this->notConfirmedMessage());

            return;
        }

        $paymentId = PaymentId::parse($hostedCheckoutId);
        $payment = CheckoutAPI::get()->payment((string) $this->context->shop->id);

        $update = $payment->updateOrderStatus($paymentId, $returnMac);
        if (!$update->isSuccessful()) {
            $this->backToPayment($this->notConfirmedMessage());

            return;
        }

        $outcome = $payment->getPaymentOutcome($paymentId, $returnMac);
        if ($outcome->isSuccessful() && $outcome->isWaiting()) {
            // startWaitingForOutcome je void: kroz fasadu daje null kad uspe, a ErrorResponse kad ne uspe.
            $waiting = $payment->startWaitingForOutcome($paymentId, $returnMac);
            if ($waiting instanceof Response && !$waiting->isSuccessful()) {
                $this->backToPayment($this->notConfirmedMessage());

                return;
            }

            $outcome = $payment->getPaymentOutcome($paymentId, $returnMac);
        }

        if (!$outcome->isSuccessful()) {
            $this->backToPayment($this->notConfirmedMessage());

            return;
        }

        $transaction = $outcome->getPaymentTransaction();
        if ($transaction->getStatusCode()->isCanceledOrRejected()) {
            if ($transaction->getStatusCode()->equals(StatusCode::parse(self::REFUSED_STATUS_CODE))) {
                $this->errors[] = $this->trans(
                    'The payment was refused. Your cart is unchanged: you can try again or choose another payment method.',
                    [],
                    'Modules.Plugin0.Shop'
                );
            } else {
                $this->warning[] = $this->trans(
                    'The payment was cancelled. Your cart is unchanged: you can choose a payment method again.',
                    [],
                    'Modules.Plugin0.Shop'
                );
            }
            $this->redirectWithNotifications($this->context->link->getPageLink('order', true));

            return;
        }

        $orderId = (int) Order::getIdByCartId((int) $transaction->getMerchantReference());
        if ($orderId > 0) {
            $this->toConfirmation(new Order($orderId), $customerId);

            return;
        }

        $this->stillProcessing = true;
    }

    public function initContent()
    {
        parent::initContent();

        if ($this->stillProcessing) {
            $this->context->smarty->assign(['home_url' => $this->context->link->getPageLink('index', true)]);
            $this->setTemplate('module:plugin0/views/templates/front/return.tpl');
        }
    }

    /**
     * Potvrda narudžbine, isto kao posle ps_wirepayment: order-confirmation proverava id_cart, id_module, id_order i
     * secure key kupca. Ko vrati tuđu (već iskorišćenu) povratnu adresu ne vidi tuđu narudžbinu, nego početnu.
     */
    private function toConfirmation(Order $order, int $customerId): void
    {
        if ($customerId === 0 || (int) $order->id_customer !== $customerId) {
            Tools::redirect($this->context->link->getPageLink('index', true));

            return;
        }

        Tools::redirect($this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $order->id_cart,
            'id_module' => (int) $this->module->id,
            'id_order' => (int) $order->id,
            'key' => (new Customer($customerId))->secure_key,
        ]));
    }

    private function backToPayment(string $message): void
    {
        $this->errors[] = $message;
        $this->redirectWithNotifications($this->context->link->getPageLink('order', true));
    }

    private function notConfirmedMessage(): string
    {
        return $this->trans(
            'The payment could not be confirmed. If the amount was charged, please contact us before paying again.',
            [],
            'Modules.Plugin0.Shop'
        );
    }

    /** Query parametar kao string; niz (npr. RETURNMAC[]=x) ili izostavljen parametar daju ''. */
    private function stringParam(string $name): string
    {
        $value = Tools::getValue($name, '');

        return is_string($value) ? trim($value) : '';
    }
}
