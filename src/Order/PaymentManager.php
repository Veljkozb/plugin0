<?php

namespace Plugin0\Order;

use Currency as ShopCurrency;
use Order;
use Plugin0\Entity\PaymentOperationEntity;
use Plugin0\Infrastructure\LoggerService;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\Order\OrderAPI\OrderAPI;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\CheckoutAPI\CheckoutAPI;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\ApiFacades\Response\Response;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Cancel\CancelRequest;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Capture\CaptureRequest;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Amount;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Currency;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Proxies\PaymentsProxyInterface;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Order\OrderAction;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Order\OrderDetails;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentTransaction;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Refund\RefundRequest;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;

/**
 * Panel za upravljanje plaćanjem na admin stranici narudžbine (tiket "Merchant panel"): stanje plaćanja kod
 * Worldline-a, capture / refund / cancel i log pokušaja. Jedino mesto u pluginu koje zove Core OrderAPI.
 *
 * Šta radi ko:
 * - Core OrderAPI::orders()->getDetails(merchantReference) čita plaćanje od Worldline-a (GET /payments/{id}/details)
 *   i za svaku akciju kaže da li je moguća i koliko je još dostupno (OrderDetails / OrderAction);
 * - Core OrderAPI::capture|refund|cancel()->handle(...) samo prosleđuje zahtev Worldline-u (POST
 *   /payments/{id}/capture|refund|cancel), bez ikakve provere i bez promene narudžbine;
 * - zato plugin pre poziva proverava iznos i stanje (OperationValidator), svaki pokušaj upisuje u log operacija
 *   (OperationLog), a posle uspeha zove Core CheckoutAPI::payment()->updateOrderStatus(paymentId): Core ponovo
 *   pročita plaćanje i jedino kroz Plugin0\Infrastructure\ShopOrderService menja status narudžbine. Ovde se status
 *   narudžbine ne tumači i ne menja (tiket: statusi ostaju na jednom mestu).
 *
 * Isti updateOrderStatus se zove i pri otvaranju panela, da stanje narudžbine stigne operaciju koja je u
 * međuvremenu prošla (npr. CAPTURE_REQUESTED → CAPTURED); bez webhook-a niko drugi to ne bi uradio.
 *
 * Neuspeo ili odbijen pokušaj ne dira narudžbinu: za njega se ne zove updateOrderStatus, samo se upiše u log.
 *
 * merchantReference transakcije = ID korpe (PrestaShopCartProvider), a narudžbina pamti korpu (Order::id_cart).
 * Fasade ne bacaju izuzetke (ErrorHandlingAspect grešku upiše u log i vrati kao odgovor), pa ovde nema try/catch.
 */
final class PaymentManager
{
    private const COMPONENT = 'PaymentManager';

    /** Worldline status operacije posle koga je ona gotova; sve drugo (npr. CAPTURE_REQUESTED) je još u obradi. */
    private const FINAL_STATUSES = [
        OperationValidator::CAPTURE => 'CAPTURED',
        OperationValidator::REFUND => 'REFUNDED',
        OperationValidator::CANCEL => 'CANCELLED',
    ];

    private string $storeId;
    private OperationValidator $validator;
    private OperationLog $log;

    public function __construct(string $storeId)
    {
        $this->storeId = $storeId;
        $this->validator = new OperationValidator();
        $this->log = new OperationLog();
    }

    /**
     * Podaci za prikaz panela. Null kad narudžbina nema Worldline transakciju sa ID-jem plaćanja (nije plaćena
     * kroz plugin0, ili plaćanje nije ni počelo).
     *
     * @return array<string, mixed>|null
     */
    public function panel(Order $order, bool $refreshStatus): ?array
    {
        $transaction = $this->transaction($order);
        if ($transaction === null) {
            return null;
        }

        $statusWarning = null;
        if ($refreshStatus) {
            $update = CheckoutAPI::get()->payment($this->storeId)->updateOrderStatus($transaction->getPaymentId());
            if (!$update->isSuccessful()) {
                $statusWarning = 'The order status could not be refreshed from Worldline. ' . self::errorMessage($update);
            }

            // updateOrderStatus je upisao novi statusCode u transakciju; bez ponovnog čitanja panel bi pokazao stari.
            $transaction = $this->transaction($order) ?? $transaction;
            $this->reconcileLog((int) $order->id, $transaction);
        }

        $details = OrderAPI::get()->orders($this->storeId)->getDetails($transaction->getMerchantReference());

        return [
            'payment_id' => (string) $transaction->getPaymentId(),
            'payment_method' => (string) $transaction->getPaymentMethod(),
            'status_code' => $transaction->getStatusCode()->getCode(),
            'status_warning' => $statusWarning,
            'details_error' => $details->isSuccessful() ? null : self::errorMessage($details),
            'state' => $details->isSuccessful() ? $this->state($details->getOrderDetails()) : null,
            'log' => array_map([$this, 'logRow'], $this->log->forOrder((int) $order->id)),
        ];
    }

    /**
     * Jedna operacija, od provere do loga. Vraća poruku za trgovca i da li je operacija prošla.
     *
     * @return array{ok: bool, message: string}
     */
    public function execute(string $action, Order $order, string $amountText, int $employeeId): array
    {
        $transaction = $this->transaction($order);
        if ($transaction === null) {
            return ['ok' => false, 'message' => 'This order has no Worldline payment.'];
        }

        $paymentId = $transaction->getPaymentId();
        $currency = Currency::fromIsoCode((new ShopCurrency((int) $order->id_currency))->iso_code);
        $amount = MinorUnits::parse($amountText, $currency);
        $orderId = (int) $order->id;

        // Sveže stanje od Worldline-a: provera iznosa nad starim stanjem ne bi vredela.
        $details = OrderAPI::get()->orders($this->storeId)->getDetails($transaction->getMerchantReference());
        if (!$details->isSuccessful()) {
            $message = 'The payment state could not be read from Worldline, nothing was sent. ' . self::errorMessage($details);
            $this->record($orderId, $paymentId, $action, $amount ?? Amount::fromInt(0, $currency), PaymentOperationEntity::OUTCOME_REFUSED, $message, '', $employeeId);

            return ['ok' => false, 'message' => $message];
        }

        $rejection = $this->validator->validate($action, $amount, $details->getOrderDetails());
        if ($rejection !== null) {
            $this->record($orderId, $paymentId, $action, $amount ?? Amount::fromInt(0, $currency), PaymentOperationEntity::OUTCOME_REJECTED, $rejection, '', $employeeId);

            return ['ok' => false, 'message' => $rejection];
        }

        $response = $this->send($action, $paymentId, $amount);
        if (!$response->isSuccessful()) {
            $message = sprintf('Worldline refused the %s. %s', $action, self::errorMessage($response));
            $this->record($orderId, $paymentId, $action, $amount, PaymentOperationEntity::OUTCOME_REFUSED, $message, '', $employeeId);

            return ['ok' => false, 'message' => $message];
        }

        $providerStatus = (string) ($response->toArray()['status'] ?? '');
        $final = $providerStatus === self::FINAL_STATUSES[$action];
        $message = sprintf(
            '%s of %s %s (Worldline status %s).',
            ucfirst($action),
            MinorUnits::format($amount),
            $final ? 'completed' : 'accepted and being processed',
            $providerStatus
        );
        $this->record(
            $orderId,
            $paymentId,
            $action,
            $amount,
            $final ? PaymentOperationEntity::OUTCOME_SUCCESS : PaymentOperationEntity::OUTCOME_PENDING,
            $message,
            $providerStatus,
            $employeeId,
            (string) ($response->toArray()['id'] ?? '')
        );

        // Jedini put do statusa narudžbine: Core ponovo čita plaćanje i zove ShopOrderService.
        $update = CheckoutAPI::get()->payment($this->storeId)->updateOrderStatus($paymentId);
        if (!$update->isSuccessful()) {
            $message .= ' The order status could not be refreshed from Worldline: ' . self::errorMessage($update);
        }

        return ['ok' => true, 'message' => $message];
    }

    private function send(string $action, $paymentId, Amount $amount): Response
    {
        $api = OrderAPI::get();

        switch ($action) {
            case OperationValidator::CAPTURE:
                return $api->capture($this->storeId)->handle(new CaptureRequest($paymentId, $amount));
            case OperationValidator::REFUND:
                return $api->refund($this->storeId)->handle(new RefundRequest($paymentId, $amount));
            default:
                return $api->cancel($this->storeId)->handle(new CancelRequest($paymentId, $amount));
        }
    }

    /** Poslednja Core transakcija korpe sa ID-jem plaćanja, ili null. */
    private function transaction(Order $order): ?PaymentTransaction
    {
        $response = CheckoutAPI::get()->payment($this->storeId)->getPaymentTransaction((string) (int) $order->id_cart);
        if (!$response->isSuccessful()) {
            return null;
        }

        $transaction = $response->getPaymentTransaction();

        return $transaction !== null && $transaction->getPaymentId() !== null ? $transaction : null;
    }

    /**
     * Redovi istorije koji su još "pending" dobijaju konačan ishod po stvarnom stanju operacija kod Worldline-a.
     * Greška ovde ne sme da sruši panel: istorija je pomoćna, stanje plaćanja je u tabeli iznad.
     */
    private function reconcileLog(int $orderId, PaymentTransaction $transaction): void
    {
        try {
            $details = StoreContext::doWithStore($this->storeId, static function () use ($transaction) {
                return ServiceRegister::getService(PaymentsProxyInterface::class)->getPaymentDetails($transaction->getPaymentId());
            });
            $this->log->reconcileForOrder($orderId, $details->getOperations());
        } catch (\Throwable $e) {
            Logger::logWarning('Operation log could not be reconciled with Worldline', self::COMPONENT, LoggerService::context([
                'orderId' => $orderId,
                'paymentId' => (string) $transaction->getPaymentId(),
                'error' => get_class($e) . ': ' . $e->getMessage(),
            ]));
        }
    }

    private function record(int $orderId, $paymentId, string $action, Amount $amount, string $outcome, string $message, string $providerStatus, int $employeeId, string $providerOperationId = ''): void
    {
        $this->log->record($orderId, (string) $paymentId, $action, $amount, $outcome, $message, $providerStatus, $employeeId, $providerOperationId);

        Logger::logInfo('Payment operation attempted from the order page', self::COMPONENT, LoggerService::context([
            'orderId' => $orderId,
            'paymentId' => (string) $paymentId,
            'action' => $action,
            'amount' => $amount->getValue(),
            'currency' => $amount->getCurrency()->getIsoCode(),
            'outcome' => $outcome,
            'employeeId' => $employeeId,
        ]));
    }

    /**
     * Iznosi za prikaz, svi kao tekst iz celog broja (MinorUnits), nikad float.
     *
     * @return array<string, mixed>
     */
    private function state(OrderDetails $details): array
    {
        $currency = $details->getAmount()->getCurrency();
        $cancelBlocked = OperationValidator::fullyCaptured($details);

        return [
            'currency' => $currency->getIsoCode(),
            'total' => MinorUnits::formatValue($details->getAmount()->getValue(), $currency),
            'capture' => $this->action($details->getCapture(), $currency),
            'refund' => $this->action($details->getRefund(), $currency),
            'cancel' => $this->action($details->getCancel(), $currency) + ['blocked' => $cancelBlocked],
            'errors' => array_map(static function ($error): string {
                return $error->getId() . ': ' . $error->getErrorCode();
            }, $details->getErrors()),
        ];
    }

    /**
     * @return array{possible: bool, done: string, pending: string, available: string, available_value: int}
     */
    private function action(OrderAction $action, Currency $currency): array
    {
        return [
            'possible' => $action->isPossible() && $action->getAvailable()->getValue() > 0,
            'done' => MinorUnits::formatValue($action->getDone()->getValue(), $currency),
            'pending' => MinorUnits::formatValue($action->getPending()->getValue(), $currency),
            'available' => MinorUnits::formatValue($action->getAvailable()->getValue(), $currency),
            'available_value' => $action->getAvailable()->getValue(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function logRow(PaymentOperationEntity $entry): array
    {
        return [
            'time' => date('Y-m-d H:i:s', $entry->getCreatedAt()),
            'action' => $entry->getAction(),
            'amount' => MinorUnits::formatValue($entry->getAmount(), Currency::fromIsoCode($entry->getCurrency())) . ' ' . $entry->getCurrency(),
            'outcome' => $entry->getOutcome(),
            'message' => $entry->getMessage(),
            'provider_status' => $entry->getProviderStatus(),
            'provider_operation_id' => $entry->getProviderOperationId(),
            'employee_id' => $entry->getEmployeeId(),
        ];
    }

    private static function errorMessage(Response $response): string
    {
        $data = $response->toArray();

        return (string) ($data['errorMessage'] ?? $data['errorCode'] ?? 'Unknown error.');
    }
}
