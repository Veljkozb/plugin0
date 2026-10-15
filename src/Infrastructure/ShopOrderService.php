<?php

namespace Plugin0\Infrastructure;

use Cart;
use Configuration;
use Db;
use Module;
use Order;
use OrderHistory;
use OrderState;
use PaymentModule;
use RuntimeException;
use Validate;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\ShopOrderService as ShopOrderServiceInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentDetails;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentTransaction;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;

/**
 * Jedino mesto u pluginu koje pravi PrestaShop narudžbinu i menja joj status (PaymentModule::validateOrder i
 * OrderHistory::changeIdOrderState se ne zovu nigde drugde).
 *
 * Zove ga samo Core, iz StatusUpdateService::updateOrderStatus, posle čitanja plaćanja sa Worldline-a
 * (GET /payments/{id}/details): createShopOrder kad je plaćen ceo iznos, cancelShopOrder kad je plaćanje otkazano ili
 * odbijeno, refundShopOrder posle povraćaja, updateStatus inače. $newState je ID PrestaShop statusa narudžbine koji
 * je Core već izabrao (StatusMappingService, podrazumevano iz StoreService::getDefaultOrderStatusMapping); ovde se
 * status plaćanja ne tumači.
 *
 * Narudžbina nastaje tek posle plaćanja (merchantReference transakcije = ID korpe): otkazan ili odbijen pokušaj
 * ne ostavlja narudžbinu, a korpa ostaje kupcu za novi pokušaj.
 *
 * Svaka metoda je idempotentna, jer isti ishod stiže više puta: Core ga i pri jednom povratku kupca obradi dvaput
 * (WaitPaymentOutcomeProcess), kupac može da osveži stranicu povratka, a kasnije stižu i webhook-ovi.
 * - Sve izmene jedne korpe idu pod MySQL GET_LOCK; Core-ovo zaključavanje (lockOrderCreation) nad
 *   ps_plugin0_entity nije atomično (prvo proveri, pa upiše, bez unique ključa).
 * - Narudžbina se pravi samo ako za korpu još ne postoji (ps_orders.id_cart).
 * - Status se menja samo u status u kome narudžbina još nije bila (ps_order_history), pa ponovljen poziv ne
 *   dodaje red istorije, mejl ni belešku, i ne vraća narudžbinu koju je trgovac već pomerio dalje.
 * - Interna beleška sa Worldline ID-jem plaćanja nastaje samo pri kreiranju (validateOrder, $message).
 */
class ShopOrderService implements ShopOrderServiceInterface
{
    private const COMPONENT = 'ShopOrderService';
    private const LOCK_TIMEOUT_SECONDS = 10;

    public function createShopOrder(PaymentTransaction $paymentTransaction, PaymentDetails $paymentDetails, string $newState): void
    {
        $cartId = (int) $paymentTransaction->getMerchantReference();

        $this->withCartLock($cartId, function () use ($cartId, $paymentTransaction, $paymentDetails, $newState): void {
            $orderId = $this->orderIdForCart($cartId);
            if ($orderId > 0) {
                $this->changeState($orderId, $newState, $paymentTransaction);

                return;
            }

            $this->validateOrder($cartId, $paymentTransaction, $paymentDetails, $newState);
        });
    }

    public function updateStatus(PaymentTransaction $paymentTransaction, PaymentDetails $paymentDetails, string $newState): void
    {
        $this->changeStateOfCartOrder($paymentTransaction, $newState);
    }

    public function cancelShopOrder(PaymentTransaction $paymentTransaction, PaymentDetails $paymentDetails, string $newState): void
    {
        $this->changeStateOfCartOrder($paymentTransaction, $newState);
    }

    public function refundShopOrder(PaymentTransaction $paymentTransaction, PaymentDetails $paymentDetails, string $newState): void
    {
        $this->changeStateOfCartOrder($paymentTransaction, $newState);
    }

    /**
     * Pravi narudžbinu od korpe. validateOrder za "narudžbina za korpu već postoji", "secure key se ne slaže" i
     * "modul nije aktivan" zove die() umesto da baci izuzetak, pa se ti uslovi proveravaju pre poziva.
     *
     * Kao plaćeni iznos ide iznos koji je Worldline naplatio: kad se ne slaže sa korpom (kupac je, na primer,
     * u drugom tabu promenio korpu posle otvaranja Worldline strane), validateOrder sam postavlja PS_OS_ERROR
     * ("Payment error").
     */
    private function validateOrder(int $cartId, PaymentTransaction $paymentTransaction, PaymentDetails $paymentDetails, string $newState): void
    {
        $cart = new Cart($cartId);
        $module = Module::getInstanceByName('plugin0');
        $stateId = (int) $newState;

        if (!Validate::isLoadedObject($cart) || !$module instanceof PaymentModule || !$module->active) {
            throw new RuntimeException(sprintf('Cannot create the order for cart %d: cart or payment module not available.', $cartId));
        }

        if (!$this->stateExists($stateId)) {
            throw new RuntimeException(sprintf('Cannot create the order for cart %d: order status "%s" does not exist.', $cartId, $newState));
        }

        $paymentId = (string) $paymentTransaction->getPaymentId();
        $module->validateOrder(
            $cartId,
            $stateId,
            (float) $paymentDetails->getAmount()->getPriceInCurrencyUnits(),
            (string) ($paymentTransaction->getPaymentMethod() ?: $module->displayName),
            sprintf('Worldline payment ID: %s', $paymentId),
            ['transaction_id' => $paymentId],
            (int) $cart->id_currency,
            false,
            $cart->secure_key
        );

        Logger::logInfo('Order created from cart after Worldline payment', self::COMPONENT, LoggerService::context([
            'cartId' => $cartId,
            'orderId' => (int) $module->currentOrder,
            'paymentId' => $paymentId,
            'orderState' => $stateId,
        ]));
    }

    /** Za updateStatus, cancelShopOrder i refundShopOrder: bez narudžbine (plaćanje nije prošlo) nema šta da se menja. */
    private function changeStateOfCartOrder(PaymentTransaction $paymentTransaction, string $newState): void
    {
        $cartId = (int) $paymentTransaction->getMerchantReference();

        $this->withCartLock($cartId, function () use ($cartId, $paymentTransaction, $newState): void {
            $orderId = $this->orderIdForCart($cartId);
            if ($orderId > 0) {
                $this->changeState($orderId, $newState, $paymentTransaction);
            }
        });
    }

    private function changeState(int $orderId, string $newState, PaymentTransaction $paymentTransaction): void
    {
        $stateId = (int) $newState;

        // Core za neke ishode nema mapirano stanje (npr. delimičan povraćaj je '' u StoreService mapiranju).
        if ($stateId <= 0 || !$this->stateExists($stateId) || $this->hasBeenInState($orderId, $stateId)) {
            return;
        }

        if ($this->currentState($orderId) === (int) Configuration::get('PS_OS_ERROR')) {
            Logger::logWarning('Order left in Payment error, status not changed automatically', self::COMPONENT, LoggerService::context([
                'orderId' => $orderId,
                'paymentId' => (string) $paymentTransaction->getPaymentId(),
                'requestedState' => $stateId,
            ]));

            return;
        }

        $history = new OrderHistory();
        $history->id_order = $orderId;
        $history->changeIdOrderState($stateId, new Order($orderId), true);
        $history->addWithemail(true);

        Logger::logInfo('Order status changed after Worldline payment update', self::COMPONENT, LoggerService::context([
            'orderId' => $orderId,
            'paymentId' => (string) $paymentTransaction->getPaymentId(),
            'orderState' => $stateId,
        ]));
    }

    /**
     * Izvršava $action dok drži MySQL zaključavanje za korpu, da dva istovremena zahteva (povratak kupca i
     * webhook, ili dva povratka) ne naprave dve narudžbine ni dva ista reda istorije. Ime važi za ceo MySQL server,
     * pa sadrži i ime baze; MySQL ga ograničava na 64 znaka.
     */
    private function withCartLock(int $cartId, callable $action): void
    {
        $db = Db::getInstance();
        $lockName = pSQL(substr('plugin0_cart_' . $cartId . '_' . _DB_NAME_, 0, 64));

        if ((int) $db->getValue("SELECT GET_LOCK('" . $lockName . "', " . self::LOCK_TIMEOUT_SECONDS . ')', false) !== 1) {
            throw new RuntimeException(sprintf('Order lock for cart %d not acquired within %d s.', $cartId, self::LOCK_TIMEOUT_SECONDS));
        }

        try {
            $action();
        } finally {
            $db->getValue("SELECT RELEASE_LOCK('" . $lockName . "')", false);
        }
    }

    /*
     * Tri upita ispod čitaju bazu direktno i bez Db keša: Order::getHistory i Cart::orderExists pamte rezultat do
     * kraja zahteva, pa bi drugi poziv u istom zahtevu (Core ih pravi dva) video stanje pre prvog.
     */

    private function orderIdForCart(int $cartId): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_cart` = ' . $cartId,
            false
        );
    }

    private function hasBeenInState(int $orderId, int $stateId): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'order_history`'
            . ' WHERE `id_order` = ' . $orderId . ' AND `id_order_state` = ' . $stateId,
            false
        );
    }

    private function currentState(int $orderId): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT `current_state` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_order` = ' . $orderId,
            false
        );
    }

    private function stateExists(int $stateId): bool
    {
        return $stateId > 0 && Validate::isLoadedObject(new OrderState($stateId));
    }
}
