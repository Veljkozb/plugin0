<?php

namespace Plugin0\BackgroundProcessing\LogCleanup;

use Db;
use Plugin0\Adapter\CoreLogger;
use Plugin0\Infrastructure\LoggerService;
use Psr\Log\LoggerInterface;
use Throwable;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\Monitoring\MonitoringLog;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\Monitoring\WebhookLog;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Stores\StoreService;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\Operators;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\QueryFilter;
use WOP\OnlinePayments\Core\Infrastructure\ORM\RepositoryRegistry;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;

/**
 * Jedno pokretanje čišćenja logova (tiket za pozadinsku obradu, Part 5; docs/BACKGROUND.md, 8). Zove ga
 * LogRetentionTask.
 *
 * Granica se računa na početku svakog pokretanja iz podešavanja na snazi (LogRetentionSetting::daysForStore, Core
 * LogSettings podrazumevane prodavnice): granica = sada − N dana. Zato promena podešavanja važi već od sledećeg
 * pokretanja. Core-ovo čišćenje to ne može, jer rok (expiresAt) upisuje svakom zapisu pri nastanku
 * (WebhookLogRepository.php:57-59, MonitoringLogRepository.php:72).
 *
 * Briše zapise starije od granice na tri mesta, svako u paketima od BATCH_SIZE redova:
 * 1. ps_log: samo redovi plugina, oni koji počinju sa "WORLDLINE [" (LoggerService::PREFIX), po koloni date_add;
 *    zapisi samog PrestaShop-a i drugih modula se ne diraju. To su redovi Worldline > Logs strane;
 * 2. Core MonitoringLog (zahtevi ka Worldline-u i odgovori, plugin0_entity), po createdAt;
 * 3. Core WebhookLog (primljeni webhook-ovi, plugin0_entity), po createdAt.
 *
 * Svako pokretanje upiše jedan INFO red sa brojem obrisanih po mestu i ukupno; greška se upiše kao ERROR sa onim što
 * je do tada obrisano i baci dalje, pa Core zadatak ponavlja (docs/BACKGROUND.md, 2). Jedan dan = 86 400 s; vreme u
 * ps_log je lokalno vreme prodavnice (PHP date), pa se granica za ps_log piše istom funkcijom.
 */
final class LogCleaner
{
    public const BATCH_SIZE = 1000;

    private const DAY_SECONDS = 86400;

    private LoggerInterface $logger;

    /** @var callable(): void poziva se posle svakog paketa (Task::reportAlive), da runner ne proglasi zadatak mrtvim */
    private $alive;

    public function __construct(LoggerInterface $logger, ?callable $alive = null)
    {
        $this->logger = $logger;
        $this->alive = $alive ?? static function (): void {
        };
    }

    public static function create(?callable $alive = null): self
    {
        return new self(new CoreLogger('LogCleanup'), $alive);
    }

    public static function cutoff(int $now, int $retentionDays): int
    {
        return $now - $retentionDays * self::DAY_SECONDS;
    }

    /**
     * Uslov za plugin0 redove u ps_log starije od granice; $cutoffDate je u obliku kolone date_add (Y-m-d H:i:s).
     * Obe vrednosti su naše (konstanta i date()), a datum se ipak proverava po obliku, pa uslov ne treba escape.
     * "[" nije džoker znak u MySQL LIKE (džokeri su samo % i _).
     */
    public static function psLogCondition(string $cutoffDate): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cutoffDate) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid cutoff date "%s".', $cutoffDate));
        }

        return "message LIKE '" . LoggerService::PREFIX . " [%' AND date_add < '" . $cutoffDate . "'";
    }

    /** @return array{retentionDays: int, cutoff: string, psLog: int, monitoringLog: int, webhookLog: int, total: int} */
    public function run(int $now): array
    {
        $days = LogRetentionSetting::daysForStore($this->defaultStoreId());
        $cutoff = self::cutoff($now, $days);
        $result = [
            'retentionDays' => $days,
            'cutoff' => date('Y-m-d H:i:s', $cutoff),
            'psLog' => 0,
            'monitoringLog' => 0,
            'webhookLog' => 0,
            'total' => 0,
        ];

        try {
            $result['psLog'] = $this->deletePsLog($result['cutoff']);
            $result['monitoringLog'] = $this->deleteEntities(MonitoringLog::class, $cutoff);
            $result['webhookLog'] = $this->deleteEntities(WebhookLog::class, $cutoff);
        } catch (Throwable $e) {
            $result['total'] = $result['psLog'] + $result['monitoringLog'] + $result['webhookLog'];
            $this->logger->error('Log cleanup failed', $result + ['error' => get_class($e) . ': ' . $e->getMessage()]);

            throw $e;
        }

        $result['total'] = $result['psLog'] + $result['monitoringLog'] + $result['webhookLog'];
        $this->logger->info(sprintf('Log cleanup finished: %d entries older than %d days deleted', $result['total'], $days), $result);

        return $result;
    }

    private function deletePsLog(string $cutoffDate): int
    {
        $db = Db::getInstance();
        $deleted = 0;

        do {
            if (!$db->delete('log', self::psLogCondition($cutoffDate), self::BATCH_SIZE)) {
                throw new \RuntimeException('ps_log rows could not be deleted. ' . $db->getMsgError());
            }

            // Broj odmah posle DELETE-a, pre ijednog drugog upita na istoj konekciji.
            $batch = (int) $db->Affected_Rows();
            $deleted += $batch;
            ($this->alive)();
        } while ($batch === self::BATCH_SIZE);

        return $deleted;
    }

    /** Core log entitet u plugin0_entity, po indeksu createdAt (unix timestamp). */
    private function deleteEntities(string $entityClass, int $cutoff): int
    {
        $repository = RepositoryRegistry::getRepository($entityClass);
        $filter = new QueryFilter();
        $filter->where('createdAt', Operators::LESS_THAN, $cutoff);

        // Broj se uzima pre brisanja: BaseRepository::deleteWhere posle DELETE-a piše u log, pa broj izmenjenih redova
        // na konekciji više ne pripada brisanju.
        $total = $repository->count($filter);
        $remaining = $total;
        while ($remaining > 0) {
            $batch = new QueryFilter();
            $batch->where('createdAt', Operators::LESS_THAN, $cutoff);
            $batch->setLimit(self::BATCH_SIZE);
            if (!$repository->deleteWhere($batch)) {
                throw new \RuntimeException(sprintf('%s rows could not be deleted. %s', $entityClass, Db::getInstance()->getMsgError()));
            }

            ($this->alive)();
            $before = $remaining;
            $remaining = $repository->count($filter);
            if ($remaining >= $before) {
                throw new \RuntimeException(sprintf('%s rows are not getting deleted (%d left).', $entityClass, $remaining));
            }
        }

        return $total;
    }

    private function defaultStoreId(): string
    {
        /** @var StoreService $stores */
        $stores = ServiceRegister::getService(StoreService::class);
        $store = $stores->getDefaultStore();

        return $store !== null ? $store->getStoreId() : (string) \Configuration::get('PS_SHOP_DEFAULT');
    }
}
