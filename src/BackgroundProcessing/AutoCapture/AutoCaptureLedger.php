<?php

namespace Plugin0\BackgroundProcessing\AutoCapture;

use Plugin0\Entity\AutoCaptureEntity;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\Operators;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\QueryFilter;
use WOP\OnlinePayments\Core\Infrastructure\ORM\RepositoryRegistry;

/**
 * Evidencija automatskih naplata (Entity\AutoCaptureEntity, tip "AutoCapture" u plugin0_entity): jedan red po
 * plaćanju, sa stanjem scheduled / captured / not_needed / failed, brojem pokušaja i poslednjom greškom
 * (docs/BACKGROUND.md, 7.2). Jedino mesto koje piše taj tip.
 *
 * Čita i piše kroz BaseRepository iz RepositoryRegistry-ja, pa Core mora da bude podignut (Bootstrap::boot). Unit
 * testovi zamenjuju samo load, store i remove.
 */
class AutoCaptureLedger
{
    public function find(string $paymentId): ?AutoCaptureEntity
    {
        return $this->load($paymentId);
    }

    /** Novo ili ponovno zakazivanje: red prelazi u scheduled, sa nula pokušaja i bez greške. */
    public function schedule(string $storeId, string $paymentId, string $merchantReference, int $orderId): AutoCaptureEntity
    {
        $entry = $this->load($paymentId) ?? new AutoCaptureEntity();
        if (!$entry->getId()) {
            $entry->setCreatedAt(time());
        }

        $entry->setPaymentId($paymentId);
        $entry->setStoreId($storeId);
        $entry->setMerchantReference($merchantReference);
        $entry->setOrderId($orderId);
        $entry->setState(AutoCaptureEntity::STATE_SCHEDULED);
        $entry->setAttempts(0);
        $entry->setLastError('');
        $entry->setQueueItemId(0);
        $this->touchAndStore($entry);

        return $entry;
    }

    public function attachQueueItem(string $paymentId, int $queueItemId): void
    {
        $this->change($paymentId, static function (AutoCaptureEntity $entry) use ($queueItemId): void {
            $entry->setQueueItemId($queueItemId);
        });
    }

    public function startAttempt(string $paymentId, int $attempt): void
    {
        $this->change($paymentId, static function (AutoCaptureEntity $entry) use ($attempt): void {
            $entry->setAttempts($attempt);
        });
    }

    public function attemptFailed(string $paymentId, string $error): void
    {
        $this->change($paymentId, static function (AutoCaptureEntity $entry) use ($error): void {
            $entry->setLastError($error);
        });
    }

    public function captured(string $paymentId, string $providerOperationId): void
    {
        $this->change($paymentId, static function (AutoCaptureEntity $entry) use ($providerOperationId): void {
            $entry->setState(AutoCaptureEntity::STATE_CAPTURED);
            $entry->setProviderOperationId($providerOperationId);
            $entry->setLastError('');
        });
    }

    /** Ništa za naplatu kod Worldline-a. Već upisan captured ostaje: to je ponovljen pokušaj posle naše naplate. */
    public function notNeeded(string $paymentId): void
    {
        $this->change($paymentId, static function (AutoCaptureEntity $entry): void {
            if ($entry->getState() !== AutoCaptureEntity::STATE_CAPTURED) {
                $entry->setState(AutoCaptureEntity::STATE_NOT_NEEDED);
            }
        });
    }

    public function failed(string $paymentId, string $error): void
    {
        $this->change($paymentId, static function (AutoCaptureEntity $entry) use ($error): void {
            $entry->setState(AutoCaptureEntity::STATE_FAILED);
            $entry->setLastError($error);
        });
    }

    /** Briše red, pa plaćanje može ponovo da bude zakazano (npr. kad je auto capture isključen pre naplate). */
    public function forget(string $paymentId): void
    {
        $entry = $this->load($paymentId);
        if ($entry !== null) {
            $this->remove($entry);
        }
    }

    protected function load(string $paymentId): ?AutoCaptureEntity
    {
        $filter = new QueryFilter();
        $filter->where('paymentId', Operators::EQUALS, $paymentId);

        /** @var AutoCaptureEntity|null $entry */
        $entry = RepositoryRegistry::getRepository(AutoCaptureEntity::CLASS_NAME)->selectOne($filter);

        return $entry;
    }

    protected function store(AutoCaptureEntity $entry): void
    {
        $repository = RepositoryRegistry::getRepository(AutoCaptureEntity::CLASS_NAME);
        if ($entry->getId()) {
            $repository->update($entry);

            return;
        }

        $repository->save($entry);
    }

    protected function remove(AutoCaptureEntity $entry): void
    {
        RepositoryRegistry::getRepository(AutoCaptureEntity::CLASS_NAME)->delete($entry);
    }

    private function change(string $paymentId, callable $change): void
    {
        $entry = $this->load($paymentId);
        if ($entry === null) {
            return;
        }

        $change($entry);
        $this->touchAndStore($entry);
    }

    private function touchAndStore(AutoCaptureEntity $entry): void
    {
        $entry->setUpdatedAt(time());
        $this->store($entry);
    }
}
