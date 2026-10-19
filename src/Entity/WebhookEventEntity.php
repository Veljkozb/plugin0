<?php

namespace Plugin0\Entity;

use WOP\OnlinePayments\Core\Infrastructure\ORM\Configuration\EntityConfiguration;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Configuration\IndexMap;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Entity;

/**
 * Jedan uspešno obrađen Worldline webhook događaj (tiket "Webhook handling": obrada mora biti idempotentna, a
 * preskočen duplikat zabeležen). Red u tabeli plugin0_entity tipa "WebhookEvent", kroz BaseRepository kao i ostali
 * entiteti; Core nema takav zapis, jer njegov WebhookData ne nosi ID događaja (UUID sa vrha JSON-a), samo ID plaćanja.
 *
 * Piše ga Webhook\ProcessedEvents tek pošto Core WebhookService::process prođe bez greške; isporuka koja je pala
 * (503) nije zabeležena, pa Worldline-ovo ponovno slanje prolazi obradu ponovo. Red ne služi kao dokaz da je
 * narudžbina promenjena: duplikat sa istim ID-jem događaja se samo preskače, a stanje narudžbine čuvaju Core
 * (ponovno čitanje Worldline-a) i ShopOrderService (bez ponovnog ulaska u status). Vreme je unix timestamp.
 * Indeksi: eventId (provera duplikata), paymentId (provere u bazi), createdAt (čišćenje starih redova).
 */
class WebhookEventEntity extends Entity
{
    public const CLASS_NAME = __CLASS__;

    /** Vrednost kolone type u plugin0_entity. */
    public const TYPE = 'WebhookEvent';

    /** UUID sa vrha webhook JSON-a ("id"). */
    protected string $eventId = '';
    /** Worldline tip događaja, npr. payment.captured. */
    protected string $eventType = '';
    /** ID plaćanja ili operacije iz događaja, npr. 10300628270_1. */
    protected string $paymentId = '';
    /** merchantReference iz događaja = ID PrestaShop korpe. */
    protected string $merchantReference = '';
    protected int $createdAt = 0;

    /** @var string[] polja koja Core serijalizuje u kolonu data (Entity::toArray) i čita iz nje (inflate) */
    protected array $fields = ['id', 'eventId', 'eventType', 'paymentId', 'merchantReference', 'createdAt'];

    public function getConfig(): EntityConfiguration
    {
        $indexMap = new IndexMap();
        $indexMap->addStringIndex('eventId');
        $indexMap->addStringIndex('paymentId');
        $indexMap->addIntegerIndex('createdAt');

        return new EntityConfiguration($indexMap, self::TYPE);
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function setEventId(string $eventId): void
    {
        $this->eventId = $eventId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): void
    {
        $this->eventType = $eventType;
    }

    public function getPaymentId(): string
    {
        return $this->paymentId;
    }

    public function setPaymentId(string $paymentId): void
    {
        $this->paymentId = $paymentId;
    }

    public function getMerchantReference(): string
    {
        return $this->merchantReference;
    }

    public function setMerchantReference(string $merchantReference): void
    {
        $this->merchantReference = $merchantReference;
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
