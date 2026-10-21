<?php

namespace Plugin0\BackgroundProcessing\AutoCapture;

use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\Task;

/**
 * Zadatak u redu "plugin0-auto-capture-scan", na svakih 15 minuta dok je auto capture uključen (AutoCaptureListener):
 * pregleda dospela autorizovana plaćanja i za svako novo zakaže AutoCapturePaymentTask (AutoCaptureScheduler,
 * docs/BACKGROUND.md, 7.1). Sam ne naplaćuje ništa, pa svaka naplata ima svoje pokušaje po Core retry pravilu.
 * Nema stanje, pa mu odgovara Core-ova prazna serijalizacija.
 */
class AutoCaptureScanTask extends Task
{
    public function execute(): void
    {
        AutoCaptureScheduler::create()->scheduleDue();

        $this->reportProgress(100);
    }
}
