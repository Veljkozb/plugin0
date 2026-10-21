<?php

namespace Plugin0\BackgroundProcessing\AutoCapture;

use RuntimeException;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Amount;

/**
 * Sve što automatska naplata traži od Core-a, PrestaShop-a i reda zadataka, na jednom mestu, da bi AutoCaptureProcessor
 * i AutoCaptureScheduler bili čista logika pokrivena unit testovima. Prava implementacija je CoreAutoCaptureGateway.
 */
interface AutoCaptureGateway
{
    /** Da li je auto capture uključen za prodavnicu (AutoCaptureSetting::isEnabledForStore). */
    public function isEnabled(string $storeId): bool;

    /**
     * Autorizovana plaćanja kojima je prošao Core rok captureAt.
     *
     * @return list<array{storeId: string, paymentId: string, merchantReference: string, captureAt: int}>
     */
    public function dueAuthorizations(int $limit): array;

    /** PrestaShop narudžbina korpe (merchantReference = ID korpe), ili 0 kad je nema. */
    public function orderIdForCart(string $merchantReference): int;

    /** ISO kod valute narudžbine, ili null kad narudžbine nema. */
    public function orderCurrency(int $orderId): ?string;

    /**
     * Sveže stanje baš ovog plaćanja kod Worldline-a.
     *
     * @throws RuntimeException kad stanje ne može da se pročita
     */
    public function captureState(string $storeId, string $paymentId): CaptureState;

    /**
     * Capture iznosa kod Worldline-a.
     *
     * @return array{id: string, status: string} Worldline ID capture operacije i njen status
     *
     * @throws RuntimeException kad Worldline odbije zahtev ili poziv ne uspe
     */
    public function capture(string $storeId, string $paymentId, Amount $amount): array;

    /** Core updateOrderStatus za plaćanje; null kad je uspeo, inače poruka greške. */
    public function refreshOrderStatus(string $storeId, string $paymentId): ?string;

    /** Red u logu operacija narudžbine, koji trgovac vidi u panelu na stranici narudžbine. */
    public function recordOperation(
        int $orderId,
        string $paymentId,
        Amount $amount,
        string $outcome,
        string $message,
        string $providerStatus,
        string $providerOperationId
    ): void;

    /**
     * Izvrši $work pod zaključavanjem transakcije plaćanja (Infrastructure\TransactionLock).
     *
     * @return mixed rezultat $work
     */
    public function withPaymentLock(string $paymentId, callable $work);

    /** Stavi AutoCapturePaymentTask za plaćanje u red; vraća ID QueueItem-a. */
    public function enqueueCapture(string $storeId, string $paymentId, string $merchantReference): int;

    /** Status QueueItem-a (queued, in_progress, completed, failed, aborted), ili null kad ga nema. */
    public function queueItemStatus(int $queueItemId): ?string;
}
