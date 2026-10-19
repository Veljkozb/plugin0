<?php

namespace Plugin0\Webhook;

use Plugin0\Entity\WebhookEventEntity;
use Plugin0\Infrastructure\Repository\BaseRepository;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\Operators;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\QueryFilter;
use WOP\OnlinePayments\Core\Infrastructure\ORM\RepositoryRegistry;

/**
 * Evidencija obrađenih webhook događaja (WebhookEventEntity, tip "WebhookEvent" u plugin0_entity): da li je događaj sa
 * datim ID-jem već obrađen, i upis posle uspešne obrade. Jedino mesto koje piše taj tip; čita i piše kroz
 * BaseRepository iz RepositoryRegistry-ja, pa Core mora da bude podignut (Bootstrap::boot) pre poziva.
 *
 * Provera i upis nisu jedna atomična operacija (nema unique ključa nad index kolonama): dve istovremene isporuke
 * istog događaja mogu obe da prođu obradu. To je bezopasno, jer ispravnost čuvaju Core (status se uvek ponovo čita
 * od Worldline-a), ShopOrderService (bez ponovnog ulaska u status) i zaključavanje po transakciji u
 * Infrastructure\StatusUpdateService; ova evidencija štedi Worldline pozive i daje eksplicitan zapis o duplikatu.
 *
 * Svaki upis usput briše redove starije od RETENTION_DAYS, da tabela ne raste neograničeno: Worldline ponavlja
 * isporuku najviše 35 sati, a duplikate šalje u kratkom roku, pa 30 dana pokriva sve sa velikom rezervom.
 */
class ProcessedEvents
{
    public const RETENTION_DAYS = 30;

    public function wasProcessed(string $eventId): bool
    {
        if ($eventId === '') {
            return false;
        }

        $filter = new QueryFilter();
        $filter->where('eventId', Operators::EQUALS, $eventId);

        return $this->repository()->selectOne($filter) !== null;
    }

    public function record(string $eventId, string $eventType, string $paymentId, string $merchantReference): void
    {
        $entry = new WebhookEventEntity();
        $entry->setEventId($eventId);
        $entry->setEventType($eventType);
        $entry->setPaymentId($paymentId);
        $entry->setMerchantReference($merchantReference);
        $entry->setCreatedAt(time());

        $repository = $this->repository();
        $repository->save($entry);

        if ($repository instanceof BaseRepository) {
            $filter = new QueryFilter();
            $filter->where('createdAt', Operators::LESS_THAN, time() - self::RETENTION_DAYS * 86400);
            $repository->deleteWhere($filter);
        }
    }

    /** @return \WOP\OnlinePayments\Core\Infrastructure\ORM\Interfaces\RepositoryInterface */
    private function repository()
    {
        return RepositoryRegistry::getRepository(WebhookEventEntity::CLASS_NAME);
    }
}
