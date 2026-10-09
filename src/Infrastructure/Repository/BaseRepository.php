<?php

namespace Plugin0\Infrastructure\Repository;

use Db;
use DbQuery;
use RuntimeException;
use Throwable;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Entity;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Interfaces\ConditionallyDeletes;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\Operators;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\QueryCondition;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\QueryFilter;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Utility\IndexHelper;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;

/**
 * Prevodi core zahteve (save, select, update, delete) u SQL nad tabelom plugin0_entity.
 * Jedna instanca radi za jedan tip entiteta; RepositoryRegistry je pravi i zove setEntityClass().
 */
class BaseRepository implements ConditionallyDeletes
{
    public const THIS_CLASS_NAME = __CLASS__;
    public const TABLE_NAME = 'plugin0_entity';
    public const INDEX_COLUMNS = 9;

    protected string $entityClass;
    private ?array $indexMapping = null;

    /** Zaštita od petlje: core Logger čita svoja podešavanja preko ConfigEntity, a to je opet ovaj repository. */
    private static bool $isLogging = false;

    public static function getClassName(): string
    {
        return static::THIS_CLASS_NAME;
    }

    public function setEntityClass(string $entityClass)
    {
        $this->entityClass = $entityClass;
    }

    // ---- čitanje ----------------------------------------------------------------

    public function select(QueryFilter $filter = null): array
    {
        $query = $this->buildSelectQuery($filter)->select('id, data');
        $this->applyOrderAndLimit($query, $filter);

        $rows = Db::getInstance()->executeS($query);
        $entities = $this->rowsToEntities(is_array($rows) ? $rows : []);

        $this->log(Logger::DEBUG, sprintf('Read %s: %d row(s)', $this->entityType(), count($entities)), [
            'filter' => $this->describeFilter($filter),
            'ids' => array_map(static function (Entity $entity) {
                return $entity->getId();
            }, $entities),
        ]);

        return $entities;
    }

    public function selectOne(QueryFilter $filter = null): ?Entity
    {
        $filter = $filter ?? new QueryFilter();
        $filter->setLimit(1);

        $results = $this->select($filter);

        return $results[0] ?? null;
    }

    public function count(QueryFilter $filter = null): int
    {
        $query = $this->buildSelectQuery($filter)->select('COUNT(*)');
        $count = (int) Db::getInstance()->getValue($query);

        $this->log(Logger::DEBUG, sprintf('Counted %s: %d', $this->entityType(), $count), [
            'filter' => $this->describeFilter($filter),
        ]);

        return $count;
    }

    // ---- pisanje ----------------------------------------------------------------

    public function save(Entity $entity): int
    {
        $record = $this->buildRecord($entity);
        $record['type'] = pSQL($entity->getConfig()->getType());

        if (!Db::getInstance()->insert(static::TABLE_NAME, $record, true)) {
            throw new RuntimeException(sprintf(
                'Entity %s cannot be inserted. Error: %s',
                $entity->getConfig()->getType(),
                Db::getInstance()->getMsgError()
            ));
        }

        $entity->setId((int) Db::getInstance()->Insert_ID());

        $this->log(Logger::DEBUG, sprintf('Inserted %s #%d', $entity->getConfig()->getType(), $entity->getId()), [
            'indexes' => $this->describeIndexes($entity),
        ]);

        return $entity->getId();
    }

    public function update(Entity $entity, QueryFilter $queryFilter = null): bool
    {
        $where = 'id = ' . (int) $entity->getId()
            . " AND type = '" . pSQL($entity->getConfig()->getType()) . "'";

        if ($queryFilter !== null) {
            $condition = $this->buildWhere($queryFilter, $entity);
            if ($condition !== '') {
                $where .= ' AND (' . $condition . ')';
            }
        }

        $updated = Db::getInstance()->update(static::TABLE_NAME, $this->buildRecord($entity), $where, 0, true);

        $this->logWrite($updated, 'Updated', $entity, [
            'indexes' => $this->describeIndexes($entity),
            'filter' => $this->describeFilter($queryFilter),
        ]);

        return $updated;
    }

    public function delete(Entity $entity): bool
    {
        $deleted = Db::getInstance()->delete(static::TABLE_NAME, 'id = ' . (int) $entity->getId());

        $this->logWrite($deleted, 'Deleted', $entity, []);

        return $deleted;
    }

    public function deleteWhere(QueryFilter $queryFilter = null)
    {
        /** @var Entity $entity */
        $entity = new $this->entityClass();
        $where = "type = '" . pSQL($entity->getConfig()->getType()) . "'";

        if ($queryFilter !== null) {
            $condition = $this->buildWhere($queryFilter, $entity);
            if ($condition !== '') {
                $where .= ' AND (' . $condition . ')';
            }
        }

        $limit = $queryFilter !== null ? (int) $queryFilter->getLimit() : 0;
        $deleted = Db::getInstance()->delete(static::TABLE_NAME, $where, $limit);

        $this->log(
            $deleted ? Logger::DEBUG : Logger::ERROR,
            sprintf('%s %s rows by filter', $deleted ? 'Deleted' : 'Failed to delete', $entity->getConfig()->getType()),
            [
                'filter' => $this->describeFilter($queryFilter),
                'affectedRows' => $deleted ? Db::getInstance()->Affected_Rows() : 0,
                'dbError' => $deleted ? '' : Db::getInstance()->getMsgError(),
            ]
        );

        return $deleted;
    }

    // ---- prevođenje QueryFilter -> SQL ------------------------------------------

    protected function buildSelectQuery(?QueryFilter $filter): DbQuery
    {
        /** @var Entity $entity */
        $entity = new $this->entityClass();

        $query = new DbQuery();
        $query->from(static::TABLE_NAME)
            ->where("type = '" . pSQL($entity->getConfig()->getType()) . "'");

        if ($filter !== null) {
            $condition = $this->buildWhere($filter, $entity);
            if ($condition !== '') {
                $query->where($condition);
            }
        }

        return $query;
    }

    /**
     * Izlaz: (C1 AND C2) OR (C3 AND C4). where() dodaje u tekuću grupu, orWhere() otvara novu.
     *
     * @throws QueryFilterInvalidParamException kad se filtrira po polju koje nije index
     */
    protected function buildWhere(QueryFilter $filter, Entity $entity): string
    {
        $fieldIndexMap = IndexHelper::mapFieldsToIndexes($entity);
        $fieldIndexMap['id'] = 0;

        $groups = [];
        $groupIndex = 0;
        foreach ($filter->getConditions() as $condition) {
            if (!empty($groups[$groupIndex]) && $condition->getChainOperator() === 'OR') {
                $groupIndex++;
            }

            if (!array_key_exists($condition->getColumn(), $fieldIndexMap)) {
                throw new QueryFilterInvalidParamException(
                    sprintf('Field %s is not indexed!', $condition->getColumn())
                );
            }

            $groups[$groupIndex][] = $this->conditionToSql($condition, $fieldIndexMap);
        }

        $parts = [];
        foreach ($groups as $group) {
            $parts[] = '(' . implode(' AND ', $group) . ')';
        }

        return implode(' OR ', $parts);
    }

    private function conditionToSql(QueryCondition $condition, array $fieldIndexMap): string
    {
        $column = $condition->getColumn();
        $columnName = $column === 'id' ? 'id' : 'index_' . $fieldIndexMap[$column];
        $operator = $condition->getOperator();

        if (in_array($operator, [Operators::NULL, Operators::NOT_NULL], true)) {
            return $columnName . ' ' . $operator;
        }

        if (in_array($operator, [Operators::IN, Operators::NOT_IN], true)) {
            $values = [];
            foreach ((array) $condition->getValue() as $item) {
                $type = is_int($item) ? 'integer' : (is_float($item) ? 'double' : 'string');
                $values[] = "'" . pSQL((string) IndexHelper::castFieldValue($item, $type), true) . "'";
            }

            if (empty($values)) {
                return $operator === Operators::IN ? '0 = 1' : '1 = 1';
            }

            return $columnName . ' ' . $operator . ' (' . implode(', ', $values) . ')';
        }

        if ($column === 'id') {
            $value = (int) $condition->getValue();
        } else {
            $value = IndexHelper::castFieldValue($condition->getValue(), $condition->getValueType());
        }

        return $columnName . ' ' . $operator . " '" . pSQL((string) $value, true) . "'";
    }

    private function applyOrderAndLimit(DbQuery $query, ?QueryFilter $filter): void
    {
        if ($filter === null) {
            return;
        }

        $orderBy = $filter->getOrderByColumn();
        if ($orderBy) {
            $indexedColumn = $orderBy === 'id' ? 'id' : $this->getIndexColumn($orderBy);
            if ($indexedColumn === null) {
                throw new QueryFilterInvalidParamException(
                    sprintf('Unknown or not indexed OrderBy column %s', $orderBy)
                );
            }

            $query->orderBy($indexedColumn . ' ' . $filter->getOrderDirection());
        }

        $limit = (int) $filter->getLimit();
        if ($limit > 0) {
            $query->limit($limit, (int) $filter->getOffset());
        }
    }

    private function getIndexColumn(string $property): ?string
    {
        if ($this->indexMapping === null) {
            $this->indexMapping = IndexHelper::mapFieldsToIndexes(new $this->entityClass());
        }

        return isset($this->indexMapping[$property]) ? 'index_' . $this->indexMapping[$property] : null;
    }

    // ---- entitet <-> red u tabeli ----------------------------------------------

    protected function buildRecord(Entity $entity): array
    {
        $record = ['data' => pSQL(json_encode($entity->toArray()), true)];

        foreach (IndexHelper::transformFieldsToIndexes($entity) as $index => $value) {
            $record['index_' . $index] = $value !== null ? pSQL((string) $value, true) : null;
        }

        return $record;
    }

    protected function rowsToEntities(array $rows): array
    {
        $entities = [];
        foreach ($rows as $row) {
            $data = json_decode($row['data'], true);
            if (!is_array($data)) {
                continue;
            }

            $class = $data['class_name'] ?? $this->entityClass;
            /** @var Entity $entity */
            $entity = new $class();
            $entity->inflate($data);
            $entity->setId((int) $row['id']);
            $entities[] = $entity;
        }

        return $entities;
    }

    // ---- logovanje -------------------------------------------------------------

    /**
     * Zapis za Core log (završava u ps_log preko LoggerService), da se iz loga vidi šta je core čitao i pisao.
     * Loguje tip, id, indeksirana polja i filter. Kolonu data (ceo entitet, sa šifrovanim kredencijalima) ne loguje.
     *
     * @param array<string, mixed> $context
     */
    private function log(int $level, string $message, array $context): void
    {
        if (self::$isLogging) {
            return;
        }

        self::$isLogging = true;
        try {
            $component = 'Repository';
            if ($level === Logger::ERROR) {
                Logger::logError($message, $component, $context);
            } else {
                Logger::logDebug($message, $component, $context);
            }
        } catch (Throwable $e) {
            // Neuspeo zapis u log ne sme da obori čitanje ili upis koji je već uspeo.
        } finally {
            self::$isLogging = false;
        }
    }

    /** @param array<string, mixed> $context */
    private function logWrite(bool $success, string $action, Entity $entity, array $context): void
    {
        $type = $entity->getConfig()->getType();
        if ($success) {
            $this->log(Logger::DEBUG, sprintf('%s %s #%d', $action, $type, (int) $entity->getId()), $context);

            return;
        }

        $context['dbError'] = Db::getInstance()->getMsgError();
        $this->log(Logger::ERROR, sprintf('%s failed for %s #%d', $action, $type, (int) $entity->getId()), $context);
    }

    private function entityType(): string
    {
        /** @var Entity $entity */
        $entity = new $this->entityClass();

        return $entity->getConfig()->getType();
    }

    /** @return array<string, mixed> ime indeksiranog polja => vrednost */
    private function describeIndexes(Entity $entity): array
    {
        $names = array_flip(IndexHelper::mapFieldsToIndexes($entity));
        $indexes = [];
        foreach (IndexHelper::transformFieldsToIndexes($entity) as $index => $value) {
            $indexes[$names[$index] ?? 'index_' . $index] = $value;
        }

        return $indexes;
    }

    /** @return array<string, mixed> */
    private function describeFilter(?QueryFilter $filter): array
    {
        if ($filter === null) {
            return [];
        }

        $conditions = [];
        foreach ($filter->getConditions() as $condition) {
            $conditions[] = sprintf(
                '%s %s %s %s',
                $condition->getChainOperator(),
                $condition->getColumn(),
                $condition->getOperator(),
                json_encode($condition->getValue())
            );
        }

        return [
            'conditions' => $conditions,
            'orderBy' => $filter->getOrderByColumn() ? $filter->getOrderByColumn() . ' ' . $filter->getOrderDirection() : null,
            'limit' => $filter->getLimit(),
            'offset' => $filter->getOffset(),
        ];
    }
}
