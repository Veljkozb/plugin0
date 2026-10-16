<?php

namespace Plugin0\Entity;

use WOP\OnlinePayments\Core\Infrastructure\ORM\Configuration\EntityConfiguration;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Configuration\IndexMap;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Entity;

/**
 * Jedan pokušaj capture, refund ili cancel operacije sa admin stranice narudžbine (tiket "Merchant panel": log
 * operacija sa akcijom, vremenom, iznosom i ishodom, uključujući odbijene i neuspele pokušaje). Red u tabeli
 * plugin0_entity tipa "PaymentOperation", kroz BaseRepository kao i ostali entiteti; Core nema takav zapis.
 *
 * Ishod (outcome) je jedan od:
 * - rejected: plugin je zaustavio operaciju pre Core-a (OperationValidator); Worldline nije ni pitan;
 * - refused: Core fasada je vratila grešku (Worldline je odbio zahtev ili poziv nije uspeo);
 * - pending: Worldline je prihvatio zahtev, ali status operacije još nije konačan (npr. CAPTURE_REQUESTED);
 * - success: Worldline je izvršio operaciju.
 *
 * Iznos je ceo broj u najmanjim jedinicama valute (amount + currency), vreme je unix timestamp (createdAt).
 * Indeksi: orderId (lista za jednu narudžbinu) i createdAt (najnoviji prvi).
 */
class PaymentOperationEntity extends Entity
{
    public const CLASS_NAME = __CLASS__;

    /** Vrednost kolone type u plugin0_entity. */
    public const TYPE = 'PaymentOperation';

    public const OUTCOME_REJECTED = 'rejected';
    public const OUTCOME_REFUSED = 'refused';
    public const OUTCOME_PENDING = 'pending';
    public const OUTCOME_SUCCESS = 'success';

    protected int $orderId = 0;
    protected string $paymentId = '';
    /** capture, refund ili cancel (OperationValidator::ACTIONS) */
    protected string $action = '';
    protected int $amount = 0;
    protected string $currency = '';
    protected string $outcome = '';
    /** Poruka trgovcu: razlog odbijanja, greška Core-a ili status koji je Worldline vratio. */
    protected string $message = '';
    /** Worldline status operacije (npr. CAPTURED, REFUND_REQUESTED), prazno kad zahtev nije ni poslat. */
    protected string $providerStatus = '';

    /** Worldline ID operacije iz odgovora na capture/refund/cancel (npr. 10300628270_1); '' za odbijene i stare redove. */
    protected string $providerOperationId = '';
    protected int $employeeId = 0;
    protected int $createdAt = 0;

    /** @var string[] polja koja Core serijalizuje u kolonu data (Entity::toArray) i čita iz nje (inflate) */
    protected array $fields = [
        'id', 'orderId', 'paymentId', 'action', 'amount', 'currency', 'outcome', 'message', 'providerStatus', 'providerOperationId',
        'employeeId', 'createdAt',
    ];

    public function getConfig(): EntityConfiguration
    {
        $indexMap = new IndexMap();
        $indexMap->addIntegerIndex('orderId');
        $indexMap->addIntegerIndex('createdAt');

        return new EntityConfiguration($indexMap, self::TYPE);
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function setOrderId(int $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getPaymentId(): string
    {
        return $this->paymentId;
    }

    public function setPaymentId(string $paymentId): void
    {
        $this->paymentId = $paymentId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): void
    {
        $this->action = $action;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): void
    {
        $this->amount = $amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = $currency;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function setOutcome(string $outcome): void
    {
        $this->outcome = $outcome;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;
    }

    public function getProviderStatus(): string
    {
        return $this->providerStatus;
    }

    public function setProviderStatus(string $providerStatus): void
    {
        $this->providerStatus = $providerStatus;
    }

    public function getProviderOperationId(): string
    {
        return $this->providerOperationId;
    }

    public function setProviderOperationId(string $providerOperationId): void
    {
        $this->providerOperationId = $providerOperationId;
    }

    public function getEmployeeId(): int
    {
        return $this->employeeId;
    }

    public function setEmployeeId(int $employeeId): void
    {
        $this->employeeId = $employeeId;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function setCreatedAt(int $createdAt): void
    {
        $this->createdAt = $createdAt;
    }
}
