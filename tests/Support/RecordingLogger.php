<?php

namespace Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * A PSR-3 logger that keeps every entry it is given, for a test that has to look at all of them at once.
 *
 * Bound in place of LoggerInterface it receives what the services and the exception handler write, at every
 * level, before any channel decides what to keep.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed $level The PSR-3 level
     * @param string|\Stringable $message The message (untyped: psr/log 1.1, which PrestaShop ships, declares it untyped)
     * @param array<string, mixed> $context The context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
