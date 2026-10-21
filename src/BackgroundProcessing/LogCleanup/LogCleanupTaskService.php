<?php

namespace Plugin0\BackgroundProcessing\LogCleanup;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\LogCleanup\LogCleanupTaskServiceInterface;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\QueueService;

/**
 * Zamena Core LogCleanupTaskService-a (registracija u Plugin0\Bootstrap::initServices, pod Core ključem
 * LogCleanupTaskServiceInterface; Core ga registruje u BootstrapComponent.php:266). Raspored ostaje Core-ov: posle
 * svakog kruga runnera Core LogCleanupListener pita kad je čišćenje poslednji put stavljeno u red i, ako je to bilo
 * pre više od jednog dana, traži novo (LogCleanupListener.php:30-38). Menja se samo šta ide u red: plugin0
 * LogRetentionTask umesto Core LogCleanupTask-a, jer Core briše po roku upisanom pri nastanku zapisa i ne dira ps_log
 * (docs/BACKGROUND.md, 8).
 */
class LogCleanupTaskService implements LogCleanupTaskServiceInterface
{
    public const QUEUE = 'plugin0-log-cleanup';

    public function findLatestExecutionTimestamp(): int
    {
        $task = $this->queue()->findLatestByType(LogRetentionTask::getClassName());

        return $task !== null ? (int) $task->getQueueTimestamp() : 0;
    }

    public function enqueueLogCleanupTask(): void
    {
        $this->queue()->enqueue(self::QUEUE, new LogRetentionTask());
    }

    private function queue(): QueueService
    {
        return ServiceRegister::getService(QueueService::CLASS_NAME);
    }
}
