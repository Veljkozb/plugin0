<?php

namespace Plugin0\BackgroundProcessing\AutoCapture;

use Plugin0\Adapter\CoreLogger;
use Plugin0\Entity\AutoCaptureEntity;
use Psr\Log\LoggerInterface;
use Throwable;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\QueueItem;

/**
 * Zakazivanje automatskih naplata (docs/BACKGROUND.md, 7.1): za svako autorizovano plaćanje kome je prošao Core rok
 * captureAt, a koje još nije zakazano ni rešeno, jedan AutoCapturePaymentTask u redu. Zove ga AutoCaptureScanTask.
 *
 * Plaćanje se preskače kad:
 * - je auto capture isključen za njegovu prodavnicu;
 * - već ima red u evidenciji (AutoCaptureLedger): zakazano i još u redu, naplaćeno, nepotrebno ili konačno neuspelo.
 *   Konačno neuspelo se ne zakazuje ponovo; naplatu tada radi trgovac sa stranice narudžbine.
 * Zakazano, a zadatak više nije u redu ni u radu (obrisan, ili srušen mimo onFail), zakazuje se ponovo.
 *
 * Redosled upisa: prvo evidencija (scheduled), pa red zadataka, pa ID zadatka u evidenciju. Zadatak tako uvek nađe
 * svoj red u evidenciji, i kad krene pre nego što se zakazivanje završi.
 */
final class AutoCaptureScheduler
{
    /** Koliko dospelih plaćanja jedan krug pregleda (Core AuthorizedTransactionsRepository, najnoviji rok prvi). */
    public const BATCH_SIZE = 100;

    private AutoCaptureGateway $gateway;
    private AutoCaptureLedger $ledger;
    private LoggerInterface $logger;

    public function __construct(AutoCaptureGateway $gateway, AutoCaptureLedger $ledger, LoggerInterface $logger)
    {
        $this->gateway = $gateway;
        $this->ledger = $ledger;
        $this->logger = $logger;
    }

    public static function create(): self
    {
        return new self(new CoreAutoCaptureGateway(), new AutoCaptureLedger(), new CoreLogger('AutoCapture'));
    }

    /** @return int broj novo zakazanih naplata */
    public function scheduleDue(): int
    {
        $due = $this->gateway->dueAuthorizations(self::BATCH_SIZE);
        $scheduled = 0;
        $enabled = [];

        foreach ($due as $payment) {
            $storeId = $payment['storeId'];
            if (!array_key_exists($storeId, $enabled)) {
                $enabled[$storeId] = $this->gateway->isEnabled($storeId);
            }
            if (!$enabled[$storeId]) {
                continue;
            }

            $entry = $this->ledger->find($payment['paymentId']);
            if ($entry !== null && !$this->isOrphan($entry)) {
                continue;
            }

            $this->schedule($payment, $entry !== null);
            $scheduled++;
        }

        $this->logger->debug('Auto capture scan finished', ['due' => count($due), 'scheduled' => $scheduled]);

        return $scheduled;
    }

    /**
     * @param array{storeId: string, paymentId: string, merchantReference: string, captureAt: int} $payment
     */
    private function schedule(array $payment, bool $again): void
    {
        $orderId = $this->gateway->orderIdForCart($payment['merchantReference']);
        $this->ledger->schedule($payment['storeId'], $payment['paymentId'], $payment['merchantReference'], $orderId);

        try {
            $queueItemId = $this->gateway->enqueueCapture($payment['storeId'], $payment['paymentId'], $payment['merchantReference']);
        } catch (Throwable $e) {
            $this->ledger->forget($payment['paymentId']);

            throw $e;
        }

        $this->ledger->attachQueueItem($payment['paymentId'], $queueItemId);

        $this->logger->info($again ? 'Auto capture scheduled again: the previous task is no longer in the queue' : 'Auto capture scheduled', [
            'orderId' => $orderId,
            'cartId' => $payment['merchantReference'],
            'paymentId' => $payment['paymentId'],
            'storeId' => $payment['storeId'],
            'captureAt' => date('Y-m-d H:i:s', $payment['captureAt']),
            'queueItemId' => $queueItemId,
        ]);
    }

    /** Zakazano, a zadatak nije ni u redu ni u radu. */
    private function isOrphan(AutoCaptureEntity $entry): bool
    {
        if ($entry->getState() !== AutoCaptureEntity::STATE_SCHEDULED) {
            return false;
        }

        $status = $entry->getQueueItemId() > 0 ? $this->gateway->queueItemStatus($entry->getQueueItemId()) : null;

        return !in_array($status, [QueueItem::QUEUED, QueueItem::IN_PROGRESS], true);
    }
}
