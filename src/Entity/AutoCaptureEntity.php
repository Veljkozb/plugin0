<?php

namespace Plugin0\Entity;

use WOP\OnlinePayments\Core\Infrastructure\ORM\Configuration\EntityConfiguration;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Configuration\IndexMap;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Entity;

/**
 * Evidencija automatske naplate jednog plaćanja (tiket za pozadinsku obradu, Part 4; docs/BACKGROUND.md, 7.2). Red u
 * tabeli plugin0_entity tipa "AutoCapture", kroz BaseRepository kao i ostali entiteti; Core nema takav zapis. Piše ga
 * samo BackgroundProcessing\AutoCapture\AutoCaptureLedger.
 *
 * Zašto postoji: Core posle capture-a ne menja lokalnu transakciju (ostaje status 5 sa prošlim rokom captureAt), pa
 * je isti upit bira i u sledećem krugu. Ovaj red kaže da je plaćanje već zakazano ili rešeno, i zato se ne zakazuje
 * ponovo; stanje FAILED znači da su pokušaji potrošeni i da naplatu radi trgovac.
 *
 * Stanja (state):
 * - scheduled: zadatak AutoCapturePaymentTask je u redu ili radi;
 * - captured: Worldline je prihvatio capture (CAPTURE_REQUESTED ili CAPTURED);
 * - not_needed: kod Worldline-a nije ostalo ništa za naplatu (već naplaćeno ili otkazano mimo automatske naplate);
 * - failed: svi pokušaji su potrošeni, poslednja greška je u lastError.
 * Vremena su unix timestamp. Indeksi: paymentId (traženje po plaćanju), state, updatedAt.
 */
class AutoCaptureEntity extends Entity
{
    public const CLASS_NAME = __CLASS__;

    /** Vrednost kolone type u plugin0_entity. */
    public const TYPE = 'AutoCapture';

    public const STATE_SCHEDULED = 'scheduled';
    public const STATE_CAPTURED = 'captured';
    public const STATE_NOT_NEEDED = 'not_needed';
    public const STATE_FAILED = 'failed';

    /** ID plaćanja iz Core transakcije, npr. 10301114880_0. */
    protected string $paymentId = '';
    protected string $storeId = '';
    /** merchantReference transakcije = ID PrestaShop korpe. */
    protected string $merchantReference = '';
    /** PrestaShop narudžbina korpe; 0 kad je nema. */
    protected int $orderId = 0;
    protected string $state = '';
    /** Broj započetih pokušaja (1 = prvi). */
    protected int $attempts = 0;
    protected string $lastError = '';
    /** ID QueueItem-a poslednjeg zakazanog zadatka AutoCapturePaymentTask. */
    protected int $queueItemId = 0;
    /** Worldline ID capture operacije iz odgovora, npr. 10301114880_1. */
    protected string $providerOperationId = '';
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    /** @var string[] polja koja Core serijalizuje u kolonu data (Entity::toArray) i čita iz nje (inflate) */
    protected array $fields = [
        'id', 'paymentId', 'storeId', 'merchantReference', 'orderId', 'state', 'attempts', 'lastError', 'queueItemId',
        'providerOperationId', 'createdAt', 'updatedAt',
    ];

    public function getConfig(): EntityConfiguration
    {
        $indexMap = new IndexMap();
        $indexMap->addStringIndex('paymentId');
        $indexMap->addStringIndex('state');
        $indexMap->addIntegerIndex('updatedAt');

        return new EntityConfiguration($indexMap, self::TYPE);
    }

    public function getPaymentId(): string
    {
        return $this->paymentId;
    }

    public function setPaymentId(string $paymentId): void
    {
        $this->paymentId = $paymentId;
    }

    public function getStoreId(): string
    {
        return $this->storeId;
    }

    public function setStoreId(string $storeId): void
    {
        $this->storeId = $storeId;
    }

    public function getMerchantReference(): string
    {
        return $this->merchantReference;
    }

    public function setMerchantReference(string $merchantReference): void
    {
        $this->merchantReference = $merchantReference;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function setOrderId(int $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): void
    {
        $this->state = $state;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): void
    {
        $this->attempts = $attempts;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function setLastError(string $lastError): void
    {
        $this->lastError = $lastError;
    }

    public function getQueueItemId(): int
    {
        return $this->queueItemId;
    }

    public function setQueueItemId(int $queueItemId): void
    {
        $this->queueItemId = $queueItemId;
    }

    public function getProviderOperationId(): string
    {
        return $this->providerOperationId;
    }

    public function setProviderOperationId(string $providerOperationId): void
    {
        $this->providerOperationId = $providerOperationId;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function setCreatedAt(int $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getUpdatedAt(): int
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(int $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
