<?php

namespace Tests\Support;

use Plugin0\BackgroundProcessing\AutoCapture\AutoCaptureLedger;
use Plugin0\Entity\AutoCaptureEntity;

/**
 * The real AutoCaptureLedger rules over an array instead of the plugin0_entity table: only the three storage methods
 * are replaced.
 */
final class InMemoryAutoCaptureLedger extends AutoCaptureLedger
{
    /** @var array<string, AutoCaptureEntity> payment id => entry */
    public array $entries = [];

    private int $nextId = 1;

    protected function load(string $paymentId): ?AutoCaptureEntity
    {
        return isset($this->entries[$paymentId]) ? clone $this->entries[$paymentId] : null;
    }

    protected function store(AutoCaptureEntity $entry): void
    {
        if (!$entry->getId()) {
            $entry->setId($this->nextId++);
        }

        $this->entries[$entry->getPaymentId()] = clone $entry;
    }

    protected function remove(AutoCaptureEntity $entry): void
    {
        unset($this->entries[$entry->getPaymentId()]);
    }

    public function state(string $paymentId): ?string
    {
        return isset($this->entries[$paymentId]) ? $this->entries[$paymentId]->getState() : null;
    }
}
