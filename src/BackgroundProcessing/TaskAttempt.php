<?php

namespace Plugin0\BackgroundProcessing;

use WOP\OnlinePayments\Core\Infrastructure\Configuration\Configuration;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\QueueService;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\Task;

/**
 * Broj pokušaja zadatka iz reda, za log (docs/BACKGROUND.md, 2.2). Core zadatku ne kaže koji je ovo pokušaj, ali
 * zadatak zna ID svog QueueItem-a (Task::getExecutionId), a QueueItem broji neuspehe (retries): ovaj pokušaj je
 * retries + 1. Najviše pokušaja je maxTaskExecutionRetries + 1 (Core default 5 + 1 = 6), jer QueueService::fail
 * obara zadatak tek kad je retries veći od maksimuma (QueueService.php:223).
 */
final class TaskAttempt
{
    public static function current(Task $task): int
    {
        $id = (int) $task->getExecutionId();
        if ($id <= 0) {
            return 1;
        }

        /** @var QueueService $queue */
        $queue = ServiceRegister::getService(QueueService::CLASS_NAME);
        $item = $queue->find($id);

        return $item !== null ? $item->getRetries() + 1 : 1;
    }

    public static function max(): int
    {
        /** @var Configuration $configuration */
        $configuration = ServiceRegister::getService(Configuration::CLASS_NAME);
        $retries = $configuration->getMaxTaskExecutionRetries();

        return ($retries !== null ? $retries : QueueService::MAX_RETRIES) + 1;
    }
}
