<?php

namespace Plugin0\BackgroundProcessing\AutoCapture;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\BackgroundProcesses\AutoCaptureCheckListener;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Stores\StoreService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\TenantRegistryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Time\TimeProviderInterface;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\QueueService;

/**
 * Zamena Core AutoCaptureCheckListener-a (docs/BACKGROUND.md, 7). Core posle svakog kruga runnera okida TickEvent i
 * listener tada uzima iz ServiceRegister-a (BootstrapComponent.php:470-473); Plugin0\Bootstrap::initBackgroundProcesses
 * pod istim ključem registruje ovu klasu, pa Core-ov AutoCaptureCheckTask više ne ide u red.
 *
 * Zašto zamena: Core zadatak naplati svako lokalno autorizovano plaćanje sa prošlim rokom captureAt, ne gleda da li
 * je auto capture i dalje uključen, ignoriše grešku fasade (nema ponavljanja) i ne osvežava lokalni status, pa isto
 * plaćanje šalje na naplatu i u sledećem krugu (AutoCaptureCheckTask.php:27-34).
 *
 * Kao i Core: najviše jedan pregled na 15 minuta, mereno vremenom kad je poslednji pregled stavljen u red. Za razliku
 * od Core-a, pregled ide u red samo dok je auto capture uključen bar u jednoj prodavnici.
 */
class AutoCaptureListener extends AutoCaptureCheckListener
{
    public const SCAN_QUEUE = 'plugin0-auto-capture-scan';

    private const INTERVAL = 'PT15M';

    private QueueService $queue;
    private TimeProviderInterface $time;

    public function __construct(QueueService $queueService, TimeProviderInterface $timeProvider, TenantRegistryInterface $tenantRegistry)
    {
        parent::__construct($queueService, $timeProvider, $tenantRegistry);

        $this->queue = $queueService;
        $this->time = $timeProvider;
    }

    protected function canHandle(): bool
    {
        $latest = $this->queue->findLatestByType(AutoCaptureScanTask::getClassName());
        $intervalStart = $this->time->getCurrentLocalTime()->sub(new \DateInterval(self::INTERVAL))->getTimestamp();
        if ($latest !== null && $latest->getQueueTimestamp() >= $intervalStart) {
            return false;
        }

        return $this->isEnabledInAnyStore();
    }

    protected function doHandle(): void
    {
        $this->queue->enqueue(self::SCAN_QUEUE, new AutoCaptureScanTask());
    }

    private function isEnabledInAnyStore(): bool
    {
        /** @var StoreService $stores */
        $stores = ServiceRegister::getService(StoreService::class);
        foreach ($stores->getStores() as $store) {
            if (AutoCaptureSetting::isEnabledForStore($store->getStoreId())) {
                return true;
            }
        }

        return false;
    }
}
