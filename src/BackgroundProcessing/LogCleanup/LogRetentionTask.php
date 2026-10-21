<?php

namespace Plugin0\BackgroundProcessing\LogCleanup;

use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\Task;

/**
 * Zadatak u redu "plugin0-log-cleanup": briše log zapise starije od podešenog roka (LogCleaner, docs/BACKGROUND.md, 8).
 * U red ga stavlja Core LogCleanupListener jednom dnevno (LogCleanupListener.php:30-34), preko plugin0
 * LogCleanupTaskService-a koji je registrovan umesto Core-ovog.
 *
 * Ime nije LogCleanupTask: Core QueueService::findLatestByType traži po kratkom imenu klase, a Core-ov zadatak se
 * tako zove. Neuspeh se baca dalje, pa ga Core ponavlja (docs/BACKGROUND.md, 2). Nema stanje.
 */
class LogRetentionTask extends Task
{
    public function execute(): void
    {
        LogCleaner::create(function (): void {
            $this->reportAlive();
        })->run(time());

        $this->reportProgress(100);
    }
}
