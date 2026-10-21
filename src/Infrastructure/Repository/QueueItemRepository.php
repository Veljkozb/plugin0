<?php

namespace Plugin0\Infrastructure\Repository;

use Db;
use DbQuery;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Interfaces\QueueItemRepository as QueueItemRepositoryInterface;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\Operators;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\QueryFilter;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Utility\IndexHelper;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\Exceptions\QueueItemSaveException;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\QueueItem;

/**
 * Skladište reda zadataka: Core QueueItemRepository nad istom tabelom plugin0_entity, tip "QueueItem". Core ga
 * uzima preko RepositoryRegistry::getQueueItemRepository (registracija u Plugin0\Bootstrap::initRepositories) i kroz
 * njega upisuje svaku promenu stanja zadatka (QueueService::save, QueueService.php:410-424). Core ne daje
 * implementaciju; ceo mehanizam je u docs/BACKGROUND.md, 1.
 *
 * Pored običnih metoda iz BaseRepository-ja, tri metode reda:
 * - findOldestQueuedItems: po imenu reda samo najstariji zadatak u statusu queued, date prioritete, i samo za redove
 *   koji trenutno nemaju zadatak in_progress. Zato jedan red izvršava jedan zadatak odjednom;
 * - saveWithCondition: update prolazi samo ako red u bazi još ima vrednosti iz $additionalWhere (status,
 *   lastUpdateTimestamp, lastExecutionProgress), inače QueueItemSaveException. Na tome Core gradi zaštitu od
 *   dvostrukog pokretanja i završavanja istog zadatka (optimističko zaključavanje);
 * - batchStatusUpdate: isti status za više zadataka.
 *
 * Indeksi QueueItem-a (QueueItem.php:649-654) su, redom, kolone index_1 .. index_9: status, taskType, queueName,
 * context, queueTime, lastExecutionProgress, lastUpdateTimestamp, priority, parentId.
 */
class QueueItemRepository extends BaseRepository implements QueueItemRepositoryInterface
{
    public const THIS_CLASS_NAME = __CLASS__;

    public function findOldestQueuedItems(int $priority, int $limit = 10): array
    {
        $prototype = new QueueItem();
        $columns = IndexHelper::mapFieldsToIndexes($prototype);
        $status = 'index_' . $columns['status'];
        $queueName = 'index_' . $columns['queueName'];
        $priorityColumn = 'index_' . $columns['priority'];
        $table = '`' . bqSQL(_DB_PREFIX_ . static::TABLE_NAME) . '`';
        $type = "'" . pSQL($prototype->getConfig()->getType()) . "'";

        // Najstariji (najmanji id) queued zadatak po redu, pa izbaci redove u kojima nešto već radi. NOT EXISTS, a ne
        // NOT IN: NOT IN nad listom sa NULL vrednošću ne vraća ništa.
        $sql = 'SELECT item.id, item.data FROM ' . $table . ' item'
            . ' INNER JOIN (SELECT MIN(id) AS id FROM ' . $table
            . ' WHERE type = ' . $type
            . ' AND ' . $status . " = '" . pSQL(QueueItem::QUEUED) . "'"
            . ' AND ' . $priorityColumn . " = '" . pSQL((string) IndexHelper::castFieldValue($priority, 'integer')) . "'"
            . ' GROUP BY ' . $queueName . ') oldest ON oldest.id = item.id'
            . ' WHERE NOT EXISTS (SELECT 1 FROM ' . $table . ' running'
            . ' WHERE running.type = ' . $type
            . ' AND running.' . $status . " = '" . pSQL(QueueItem::IN_PROGRESS) . "'"
            . ' AND running.' . $queueName . ' = item.' . $queueName . ')'
            . ' ORDER BY item.id ASC'
            . ' LIMIT ' . max(0, $limit);

        $rows = Db::getInstance()->executeS($sql, true, false);
        $items = $this->rowsToEntities(is_array($rows) ? $rows : []);

        $this->log(Logger::DEBUG, sprintf('Read QueueItem: %d oldest queued item(s)', count($items)), [
            'priority' => $priority,
            'limit' => $limit,
            'ids' => array_map(static function (QueueItem $item) {
                return $item->getId();
            }, $items),
        ]);

        return $items;
    }

    public function saveWithCondition(QueueItem $queueItem, array $additionalWhere = []): int
    {
        if (!$queueItem->getId()) {
            return $this->save($queueItem);
        }

        $filter = new QueryFilter();
        foreach ($additionalWhere as $field => $value) {
            if ($value === null) {
                $filter->where($field, Operators::NULL);
            } else {
                $filter->where($field, Operators::EQUALS, $value);
            }
        }

        $where = 'id = ' . (int) $queueItem->getId()
            . " AND type = '" . pSQL($queueItem->getConfig()->getType()) . "'";
        $condition = $this->buildWhere($filter, $queueItem);
        if ($condition !== '') {
            $where .= ' AND (' . $condition . ')';
        }

        $db = Db::getInstance();
        if (!$db->update(static::TABLE_NAME, $this->buildRecord($queueItem), $where, 0, true)) {
            throw new QueueItemSaveException(sprintf(
                'Queue item %d cannot be saved. Error: %s',
                (int) $queueItem->getId(),
                $db->getMsgError()
            ));
        }

        // MySQL u broj izmenjenih redova ne računa red koji je već imao iste vrednosti. Nula zato znači ili da uslov
        // nije prošao (drugi proces je u međuvremenu promenio zadatak), ili da je uslov prošao, a red već imao tačno
        // ove vrednosti (npr. keepAlive dvaput u istoj sekundi). Razlikuje ih ponovna provera istog uslova. Ne sme se
        // porediti sadržaj reda: dva procesa koja u istoj sekundi pokrenu isti zadatak upišu isti sadržaj, a drugi
        // mora da dobije grešku.
        if ((int) $db->Affected_Rows() === 0 && !$this->conditionHolds($where)) {
            $this->log(Logger::DEBUG, sprintf('QueueItem #%d not saved: the save condition is not met', (int) $queueItem->getId()), [
                'condition' => $additionalWhere,
                'status' => $queueItem->getStatus(),
            ]);

            throw new QueueItemSaveException(sprintf(
                'Queue item %d was changed by another process; the save condition is not met.',
                (int) $queueItem->getId()
            ));
        }

        $this->log(Logger::DEBUG, sprintf('Updated QueueItem #%d', (int) $queueItem->getId()), [
            'status' => $queueItem->getStatus(),
            'queueName' => $queueItem->getQueueName(),
            'retries' => $queueItem->getRetries(),
        ]);

        return (int) $queueItem->getId();
    }

    public function batchStatusUpdate(array $ids, string $status)
    {
        if ($ids === []) {
            return;
        }

        $filter = new QueryFilter();
        $filter->where('id', Operators::IN, array_values(array_map('intval', $ids)));

        /** @var QueueItem $item */
        foreach ($this->select($filter) as $item) {
            $item->setStatus($status);
            $this->update($item);
        }
    }

    /** Da li red i dalje ispunjava uslov upisa (id, tip i $additionalWhere), pročitano bez keša. */
    private function conditionHolds(string $where): bool
    {
        $query = (new DbQuery())
            ->select('COUNT(*)')
            ->from(static::TABLE_NAME)
            ->where($where);

        return (int) Db::getInstance()->getValue($query, false) === 1;
    }
}
