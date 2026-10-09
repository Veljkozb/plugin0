<?php

namespace Plugin0\Infrastructure\Repository;

use DateTime;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Monitoring\Repositories\RepositoryWithAdvancedSearchInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\Operators;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\QueryFilter;

/**
 * Za MonitoringLog i WebhookLog: core njihovim repozitorijumima traži i straničenje sa pretragom.
 */
class LogsRepository extends BaseRepository implements RepositoryWithAdvancedSearchInterface
{
    public const THIS_CLASS_NAME = __CLASS__;

    public function getLogs(int $pageNumber, int $pageSize, string $searchTerm, ?DateTime $disconnectTime = null): array
    {
        $filter = $this->buildLogsFilter($searchTerm, $disconnectTime);
        $filter->orderBy('id', QueryFilter::ORDER_DESC)
            ->setLimit($pageSize)
            ->setOffset(max(0, $pageNumber - 1) * $pageSize);

        return $this->select($filter);
    }

    public function countLogs(?DateTime $disconnectTime = null, string $searchTerm = ''): ?int
    {
        return $this->count($this->buildLogsFilter($searchTerm, $disconnectTime));
    }

    private function buildLogsFilter(string $searchTerm, ?DateTime $disconnectTime): QueryFilter
    {
        $storeId = StoreContext::getInstance()->getStoreId();
        $filter = new QueryFilter();

        if ($searchTerm === '') {
            $filter->where('storeId', Operators::EQUALS, $storeId);
            $this->addDisconnectCondition($filter, $disconnectTime);

            return $filter;
        }

        // (storeId AND orderId LIKE) OR (storeId AND paymentNumber LIKE)
        $filter->where('storeId', Operators::EQUALS, $storeId)
            ->where('orderId', Operators::LIKE, '%' . $searchTerm . '%');
        $this->addDisconnectCondition($filter, $disconnectTime);

        $filter->orWhere('storeId', Operators::EQUALS, $storeId)
            ->where('paymentNumber', Operators::LIKE, '%' . $searchTerm . '%');
        $this->addDisconnectCondition($filter, $disconnectTime);

        return $filter;
    }

    private function addDisconnectCondition(QueryFilter $filter, ?DateTime $disconnectTime): void
    {
        if ($disconnectTime !== null) {
            $filter->where('createdAt', Operators::GREATER_THAN, $disconnectTime->getTimestamp());
        }
    }
}