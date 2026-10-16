<?php

namespace Plugin0\Order;

use Plugin0\Entity\PaymentOperationEntity;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Amount;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Currency;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentOperation;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\StatusCode;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\Operators;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\QueryFilter;
use WOP\OnlinePayments\Core\Infrastructure\ORM\RepositoryRegistry;

/**
 * Log operacija jedne narudžbine: upis svakog pokušaja (PaymentOperationEntity) i lista najnovijih prvo.
 * Jedino mesto koje piše tip "PaymentOperation"; čita i piše kroz BaseRepository iz RepositoryRegistry-ja, pa
 * Core mora da bude podignut (Bootstrap::boot) pre poziva.
 */
final class OperationLog
{
    public function record(
        int $orderId,
        string $paymentId,
        string $action,
        Amount $amount,
        string $outcome,
        string $message,
        string $providerStatus,
        int $employeeId,
        string $providerOperationId = ''
    ): PaymentOperationEntity {
        $entry = new PaymentOperationEntity();
        $entry->setProviderOperationId($providerOperationId);
        $entry->setOrderId($orderId);
        $entry->setPaymentId($paymentId);
        $entry->setAction($action);
        $entry->setAmount($amount->getValue());
        $entry->setCurrency($amount->getCurrency()->getIsoCode());
        $entry->setOutcome($outcome);
        $entry->setMessage($message);
        $entry->setProviderStatus($providerStatus);
        $entry->setEmployeeId($employeeId);
        $entry->setCreatedAt(time());

        RepositoryRegistry::getRepository(PaymentOperationEntity::CLASS_NAME)->save($entry);

        return $entry;
    }

    /**
     * @return PaymentOperationEntity[] najnoviji prvi
     */
    /**
     * Dopunjava ishod redova koji su još "pending" po stvarnom stanju operacija kod Worldline-a (GET
     * /payments/{id}/details) i upisuje izmenjene redove. Zove se pri otvaranju panela.
     *
     * @param PaymentOperation[] $operations
     */
    public function reconcileForOrder(int $orderId, array $operations): void
    {
        $repository = RepositoryRegistry::getRepository(PaymentOperationEntity::CLASS_NAME);
        foreach (self::reconcile($this->forOrder($orderId), $operations) as $entry) {
            $repository->update($entry);
        }
    }

    /**
     * Čisto pravilo, bez baze. Red koji je "pending" uparuje se sa Worldline operacijom po sačuvanom ID-u operacije;
     * stari red bez ID-a uparuje se po vrsti i iznosu samo kad je takva operacija tačno jedna i još nije vezana za
     * drugi red (`_0` je samo plaćanje, nikad operacija panela, pa se preskače). Uparenom redu se upiše ID, a ishod
     * postane "success" (naplaćeno, vraćeno, otkazano) ili "refused" (Worldline odbio); dok je operacija u obradi,
     * ostaje "pending". Redovi "rejected" i "refused" se ne diraju.
     *
     * @param PaymentOperationEntity[] $entries
     * @param PaymentOperation[] $operations
     *
     * @return PaymentOperationEntity[] izmenjeni redovi
     */
    public static function reconcile(array $entries, array $operations): array
    {
        $byId = [];
        $byActionAndAmount = [];
        foreach ($operations as $operation) {
            $id = (string) $operation->getId();
            if (substr($id, -2) === '_0') {
                continue;
            }
            $byId[$id] = $operation;
            $action = self::actionOf($operation);
            if ($action !== null) {
                $byActionAndAmount[$action][$operation->getAmount()->getValue()][] = $operation;
            }
        }

        $linked = [];
        foreach ($entries as $entry) {
            if ($entry->getProviderOperationId() !== '') {
                $linked[$entry->getProviderOperationId()] = true;
            }
        }

        $changed = [];
        foreach ($entries as $entry) {
            if ($entry->getOutcome() !== PaymentOperationEntity::OUTCOME_PENDING) {
                continue;
            }

            $operation = null;
            if ($entry->getProviderOperationId() !== '') {
                $operation = $byId[$entry->getProviderOperationId()] ?? null;
            } else {
                $candidates = array_values(array_filter(
                    $byActionAndAmount[$entry->getAction()][$entry->getAmount()] ?? [],
                    static function (PaymentOperation $candidate) use ($linked): bool {
                        return !isset($linked[(string) $candidate->getId()]);
                    }
                ));
                if (count($candidates) === 1) {
                    $operation = $candidates[0];
                    $entry->setProviderOperationId((string) $operation->getId());
                    $linked[(string) $operation->getId()] = true;
                    $changed[spl_object_id($entry)] = $entry;
                }
            }

            if ($operation === null) {
                continue;
            }

            $outcome = self::outcomeOf($entry->getAction(), $operation);
            if ($outcome === PaymentOperationEntity::OUTCOME_PENDING) {
                continue;
            }

            $amount = Amount::fromInt($entry->getAmount(), Currency::fromIsoCode($entry->getCurrency()));
            $entry->setOutcome($outcome);
            $entry->setProviderStatus($operation->getStatus());
            $entry->setMessage(sprintf(
                '%s of %s %s (Worldline status %s).',
                ucfirst($entry->getAction()),
                MinorUnits::format($amount),
                $outcome === PaymentOperationEntity::OUTCOME_SUCCESS ? 'completed' : 'refused by Worldline',
                $operation->getStatus()
            ));
            $changed[spl_object_id($entry)] = $entry;
        }

        return array_values($changed);
    }

    /** Vrsta Worldline operacije po njenom statusu; null za ono što nije capture, refund ni cancel. */
    private static function actionOf(PaymentOperation $operation): ?string
    {
        $status = strtoupper($operation->getStatus());
        if (strpos($status, 'CAPTURE') === 0 || $status === 'REJECTED_CAPTURE') {
            return OperationValidator::CAPTURE;
        }
        if (strpos($status, 'REFUND') === 0) {
            return OperationValidator::REFUND;
        }
        if ($status === 'CANCELLED') {
            return OperationValidator::CANCEL;
        }

        return null;
    }

    private static function outcomeOf(string $action, PaymentOperation $operation): string
    {
        $code = $operation->getStatusCode()->getCode();
        switch ($action) {
            case OperationValidator::CAPTURE:
                $done = StatusCode::CAPTURE_STATUS_CODES;
                $inProgress = StatusCode::CAPTURE_REQUESTED_STATUS_CODES;
                break;
            case OperationValidator::REFUND:
                $done = StatusCode::REFUND_STATUS_CODES;
                $inProgress = StatusCode::REFUND_REQUESTED_STATUS_CODES;
                break;
            default:
                $done = StatusCode::CANCEL_STATUS_CODES;
                $inProgress = StatusCode::PENDING_STATUS_CODES;
        }

        if (in_array($code, $done, true)) {
            return PaymentOperationEntity::OUTCOME_SUCCESS;
        }
        if (in_array($code, $inProgress, true)) {
            return PaymentOperationEntity::OUTCOME_PENDING;
        }

        return PaymentOperationEntity::OUTCOME_REFUSED;
    }

    public function forOrder(int $orderId): array
    {
        $filter = new QueryFilter();
        $filter->where('orderId', Operators::EQUALS, $orderId)->orderBy('createdAt', QueryFilter::ORDER_DESC);

        /** @var PaymentOperationEntity[] $entries */
        $entries = RepositoryRegistry::getRepository(PaymentOperationEntity::CLASS_NAME)->select($filter);

        // Isti sekund: noviji red ima veći id, pa i on ide prvi.
        usort($entries, static function (PaymentOperationEntity $a, PaymentOperationEntity $b): int {
            return [$b->getCreatedAt(), $b->getId()] <=> [$a->getCreatedAt(), $a->getId()];
        });

        return $entries;
    }
}
