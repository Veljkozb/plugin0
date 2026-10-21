<?php

namespace Plugin0\BackgroundProcessing\AutoCapture;

use Plugin0\Adapter\CoreLogger;
use Plugin0\Entity\AutoCaptureEntity;
use Plugin0\Entity\PaymentOperationEntity;
use Plugin0\Order\MinorUnits;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Amount;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Currency;

/**
 * Jedan pokušaj automatske naplate jednog plaćanja (tiket za pozadinsku obradu, Part 4; docs/BACKGROUND.md, 7.3).
 * Zove ga AutoCapturePaymentTask::execute iz reda zadataka; posle poslednjeg neuspelog pokušaja Core zove onFail, a
 * zadatak giveUp.
 *
 * Pokušaj, redom:
 * 1. log "attempt started" sa brojem pokušaja, narudžbinom, korpom i plaćanjem; broj pokušaja ide u evidenciju;
 * 2. auto capture isključen: ništa se ne šalje, red iz evidencije se briše (plaćanje može ponovo da bude zakazano
 *    ako se podešavanje opet uključi), log "skipped";
 * 3. pod zaključavanjem transakcije (TransactionLock) sveže stanje baš ovog plaćanja kod Worldline-a. Kad nema šta
 *    da se naplati (već naplaćeno, zatraženo ili otkazano, ili ponovljen pokušaj posle naplate čiji je odgovor
 *    istekao), capture se NE šalje: samo se osveži narudžbina i upiše not_needed. To čini ponovno pokretanje
 *    bezbednim: naplaćeno plaćanje ostaje nepromenjeno;
 * 4. inače capture celog preostalog iznosa; evidencija captured, red u panelu narudžbine (employee 0 = automatski),
 *    log "succeeded", pa osvežavanje narudžbine (Core updateOrderStatus);
 * 5. svaka greška (stanje se ne čita, Worldline odbije, osvežavanje ne uspe) se upiše u evidenciju i log sa brojem
 *    pokušaja i baci dalje: Core QueueService::fail tada vraća zadatak u red ili ga, posle poslednjeg pokušaja,
 *    obara (docs/BACKGROUND.md, 2).
 */
final class AutoCaptureProcessor
{
    public const CAPTURED = 'captured';
    public const NOT_NEEDED = 'not_needed';
    public const SKIPPED = 'skipped';

    /** Worldline status capture operacije posle koga je ona gotova; CAPTURE_REQUESTED znači da je još u obradi. */
    private const FINAL_CAPTURE_STATUS = 'CAPTURED';

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

    /**
     * @return string CAPTURED, NOT_NEEDED ili SKIPPED
     *
     * @throws Throwable kad pokušaj nije uspeo; Core ga ponavlja po retry pravilu
     */
    public function process(string $storeId, string $paymentId, string $merchantReference, int $attempt, int $maxAttempts): string
    {
        $context = [
            'orderId' => $this->gateway->orderIdForCart($merchantReference),
            'cartId' => $merchantReference,
            'paymentId' => $paymentId,
            'storeId' => $storeId,
            'attempt' => $attempt,
            'maxAttempts' => $maxAttempts,
        ];

        $this->logger->info('Auto capture attempt started', $context);
        $this->ledger->startAttempt($paymentId, $attempt);

        try {
            if (!$this->gateway->isEnabled($storeId)) {
                $this->ledger->forget($paymentId);
                $this->logger->info('Auto capture skipped: auto capture is turned off, nothing was sent to Worldline', $context);

                return self::SKIPPED;
            }

            return $this->gateway->withPaymentLock($paymentId, function () use ($storeId, $paymentId, $context): string {
                return $this->captureIfNeeded($storeId, $paymentId, $context);
            });
        } catch (Throwable $e) {
            $this->ledger->attemptFailed($paymentId, $e->getMessage());

            $failure = $context + ['error' => get_class($e) . ': ' . $e->getMessage()];
            if ($attempt < $maxAttempts) {
                $this->logger->warning('Auto capture attempt failed, it will be retried', $failure);
            } else {
                $this->logger->error('Auto capture attempt failed, no attempts left', $failure);
            }

            throw $e;
        }
    }

    /**
     * Posle poslednjeg neuspelog pokušaja (AutoCapturePaymentTask::onFail): evidencija failed, red u panelu
     * narudžbine i ERROR u logu, pa trgovac vidi da plaćanje mora sam da naplati. Ako je capture u nekom pokušaju
     * prošao, a pao je samo osvežavanje narudžbine, plaćanje se ne obeležava kao neuspelo.
     */
    public function giveUp(string $storeId, string $paymentId, string $merchantReference): void
    {
        $entry = $this->ledger->find($paymentId);
        $attempts = $entry !== null ? $entry->getAttempts() : 0;
        $lastError = $entry !== null && $entry->getLastError() !== '' ? $entry->getLastError() : 'unknown error';
        $orderId = $this->gateway->orderIdForCart($merchantReference);
        $context = [
            'orderId' => $orderId,
            'cartId' => $merchantReference,
            'paymentId' => $paymentId,
            'storeId' => $storeId,
            'attempts' => $attempts,
            'error' => $lastError,
        ];

        if ($entry !== null && $entry->getState() === AutoCaptureEntity::STATE_CAPTURED) {
            $this->logger->error(
                'Auto capture: the payment is captured, but the order status could not be refreshed; opening the order page refreshes it',
                $context
            );

            return;
        }

        $this->ledger->failed($paymentId, $lastError);

        $currency = $orderId > 0 ? $this->gateway->orderCurrency($orderId) : null;
        if ($currency !== null) {
            $this->gateway->recordOperation(
                $orderId,
                $paymentId,
                Amount::fromInt(0, Currency::fromIsoCode($currency)),
                PaymentOperationEntity::OUTCOME_REFUSED,
                sprintf('Automatic capture failed after %d attempts: %s Capture the payment from this page.', $attempts, rtrim($lastError, '.') . '.'),
                '',
                ''
            );
        }

        $this->logger->error('Auto capture failed after the last attempt, the payment is left for the merchant to capture', $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function captureIfNeeded(string $storeId, string $paymentId, array $context): string
    {
        $state = $this->gateway->captureState($storeId, $paymentId);

        if (!$state->canCapture()) {
            $this->ledger->notNeeded($paymentId);
            $refreshError = $this->gateway->refreshOrderStatus($storeId, $paymentId);
            $this->logger->info('Auto capture not needed: nothing is left to capture at Worldline, no capture was sent', $context + [
                'statusCode' => $state->getStatusCode(),
                'captured' => MinorUnits::format($state->getCaptured()),
                'captureRequested' => MinorUnits::format($state->getCaptureRequested()),
            ]);

            if ($refreshError !== null) {
                throw new RuntimeException('The order status could not be refreshed from Worldline. ' . $refreshError);
            }

            return self::NOT_NEEDED;
        }

        $amount = $state->getAvailable();
        $result = $this->gateway->capture($storeId, $paymentId, $amount);
        $final = $result['status'] === self::FINAL_CAPTURE_STATUS;

        $this->ledger->captured($paymentId, $result['id']);
        $this->gateway->recordOperation(
            (int) $context['orderId'],
            $paymentId,
            $amount,
            $final ? PaymentOperationEntity::OUTCOME_SUCCESS : PaymentOperationEntity::OUTCOME_PENDING,
            sprintf(
                'Automatic capture of %s %s (Worldline status %s).',
                MinorUnits::format($amount),
                $final ? 'completed' : 'accepted and being processed',
                $result['status']
            ),
            $result['status'],
            $result['id']
        );
        $this->logger->info('Auto capture succeeded', $context + [
            'amount' => MinorUnits::format($amount),
            'providerStatus' => $result['status'],
            'providerOperationId' => $result['id'],
        ]);

        $refreshError = $this->gateway->refreshOrderStatus($storeId, $paymentId);
        if ($refreshError !== null) {
            throw new RuntimeException('The payment is captured, but the order status could not be refreshed from Worldline. ' . $refreshError);
        }

        return self::CAPTURED;
    }
}
