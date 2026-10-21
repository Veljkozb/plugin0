<?php

namespace Plugin0\BackgroundProcessing\AutoCapture;

use Currency as ShopCurrency;
use Order;
use Plugin0\Infrastructure\CorePaymentSettings;
use Plugin0\Infrastructure\TransactionLock;
use Plugin0\Order\OperationLog;
use Plugin0\Order\OperationValidator;
use RuntimeException;
use Validate;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\Order\OrderAPI\OrderAPI;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\CheckoutAPI\CheckoutAPI;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\PaymentTransaction\AuthorizedTransactionsRepository;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\PaymentTransaction\PaymentTransactionEntity;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Capture\CaptureRequest;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Amount;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentDetails;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentId;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Proxies\PaymentsProxyInterface;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\QueueService;

/**
 * AutoCaptureGateway nad Core-om i PrestaShop-om (docs/BACKGROUND.md, 7):
 * - dospela plaćanja: Core AuthorizedTransactionsRepository::get (lokalni status 5, captureAt prošao,
 *   AuthorizedTransactionsRepository.php:28-36);
 * - stanje plaćanja: Core PaymentsProxyInterface::getPaymentDetails za baš taj ID plaćanja, u store kontekstu. Ne
 *   OrderAPI::orders()->getDetails, jer on čita poslednju transakciju korpe (OrderService.php:29-31), a korpa može da
 *   ima više plaćanja (npr. korpa 18 u dev prodavnici);
 * - capture: Core OrderAPI::capture()->handle, sa izvorom 'order.autocapture' kao u Core AutoCaptureCheckTask;
 *   fasada grešku vraća kao odgovor, pa se ovde pretvara u izuzetak, da bi Core retry radio;
 * - osvežavanje narudžbine: Core CheckoutAPI::payment()->updateOrderStatus (jedini put do statusa narudžbine, preko
 *   Plugin0\Infrastructure\ShopOrderService);
 * - panel narudžbine: Order\OperationLog (isti log kao ručni capture), sa employeeId 0;
 * - red zadataka: Core QueueService, red CAPTURE_QUEUE (jedan zadatak odjednom).
 */
final class CoreAutoCaptureGateway implements AutoCaptureGateway
{
    /** Red za AutoCapturePaymentTask: naplate idu jedna po jedna. */
    public const CAPTURE_QUEUE = 'plugin0-auto-capture';

    /** Izvor zahteva u Core monitoring logu, isti kao kod Core AutoCaptureCheckTask. */
    private const ORIGIN = 'order.autocapture';

    public function isEnabled(string $storeId): bool
    {
        return AutoCaptureSetting::isEnabledForStore($storeId);
    }

    public function dueAuthorizations(int $limit): array
    {
        /** @var AuthorizedTransactionsRepository $repository */
        $repository = ServiceRegister::getService(AuthorizedTransactionsRepository::class);

        $due = [];
        /** @var PaymentTransactionEntity $entity */
        foreach ($repository->get($limit) as $entity) {
            $transaction = $entity->getPaymentTransaction();
            if ($transaction->getPaymentId() === null) {
                continue;
            }

            $due[] = [
                'storeId' => $entity->getStoreId(),
                'paymentId' => (string) $transaction->getPaymentId(),
                'merchantReference' => $transaction->getMerchantReference(),
                'captureAt' => $entity->getCaptureAtTimestamp(),
            ];
        }

        return $due;
    }

    public function orderIdForCart(string $merchantReference): int
    {
        if (preg_match('/^\d+$/', $merchantReference) !== 1) {
            return 0;
        }

        return (int) Order::getIdByCartId((int) $merchantReference);
    }

    public function orderCurrency(int $orderId): ?string
    {
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return null;
        }

        $currency = new ShopCurrency((int) $order->id_currency);

        return Validate::isLoadedObject($currency) ? (string) $currency->iso_code : null;
    }

    public function captureState(string $storeId, string $paymentId): CaptureState
    {
        try {
            /** @var PaymentDetails $details */
            $details = StoreContext::doWithStore($storeId, static function () use ($paymentId) {
                return ServiceRegister::getService(PaymentsProxyInterface::class)->getPaymentDetails(PaymentId::parse($paymentId));
            });
        } catch (\Throwable $e) {
            throw new RuntimeException('The payment state could not be read from Worldline. ' . $e->getMessage(), 0, $e);
        }

        $total = $details->getAmount();
        $currency = $total->getCurrency();
        $amounts = $details->getAmounts();
        $zero = Amount::fromInt(0, $currency);
        $captured = $amounts !== null && $amounts->getCapturedAmount() !== null ? $amounts->getCapturedAmount() : $zero;
        $requested = $amounts !== null && $amounts->getCaptureRequestedAmount() !== null ? $amounts->getCaptureRequestedAmount() : $zero;
        $cancelled = $amounts !== null && $amounts->getCancelledAmount() !== null ? $amounts->getCancelledAmount() : $zero;

        // Ista formula kao Core OrderService::getDetails (OrderService.php:47-49).
        $authorized = $details->getStatusOutput() !== null && $details->getStatusOutput()->isAuthorized() === true;
        $left = $total->getValue() - $captured->getValue() - $requested->getValue() - $cancelled->getValue();

        return new CaptureState(
            Amount::fromInt($authorized && $left > 0 ? $left : 0, $currency),
            $captured,
            $requested,
            $details->getStatusCode()->getCode()
        );
    }

    public function capture(string $storeId, string $paymentId, Amount $amount): array
    {
        StoreContext::getInstance()->setOrigin(self::ORIGIN);
        $response = OrderAPI::get()->capture($storeId)->handle(new CaptureRequest(PaymentId::parse($paymentId), $amount));
        if (!$response->isSuccessful()) {
            throw new RuntimeException('Worldline refused the capture. ' . CorePaymentSettings::errorMessage($response->toArray()));
        }

        $data = $response->toArray();

        return ['id' => (string) ($data['id'] ?? ''), 'status' => (string) ($data['status'] ?? '')];
    }

    public function refreshOrderStatus(string $storeId, string $paymentId): ?string
    {
        $response = CheckoutAPI::get()->payment($storeId)->updateOrderStatus(PaymentId::parse($paymentId));

        return $response->isSuccessful() ? null : CorePaymentSettings::errorMessage($response->toArray());
    }

    public function recordOperation(
        int $orderId,
        string $paymentId,
        Amount $amount,
        string $outcome,
        string $message,
        string $providerStatus,
        string $providerOperationId
    ): void {
        if ($orderId <= 0) {
            return;
        }

        (new OperationLog())->record($orderId, $paymentId, OperationValidator::CAPTURE, $amount, $outcome, $message, $providerStatus, 0, $providerOperationId);
    }

    public function withPaymentLock(string $paymentId, callable $work)
    {
        return TransactionLock::run(PaymentId::parse($paymentId)->getTransactionId(), $work);
    }

    public function enqueueCapture(string $storeId, string $paymentId, string $merchantReference): int
    {
        $item = self::queue()->enqueue(self::CAPTURE_QUEUE, new AutoCapturePaymentTask($storeId, $paymentId, $merchantReference));

        return (int) $item->getId();
    }

    public function queueItemStatus(int $queueItemId): ?string
    {
        $item = self::queue()->find($queueItemId);

        return $item !== null ? $item->getStatus() : null;
    }

    private static function queue(): QueueService
    {
        return ServiceRegister::getService(QueueService::CLASS_NAME);
    }
}
