<?php

namespace Tests\Support;

use Logeecom\Settings\Domain\LogLevel;
use Logeecom\Settings\Logging\LogLevelApplierInterface;

/**
 * A log level applier with no channels behind it, for unit tests of the settings service. Their spied logger
 * keeps every entry at every level, so there is nothing for a level to apply to; LevelChangeLogTest checks
 * the real one through the stack.
 */
final class NullLogLevelApplier implements LogLevelApplierInterface
{
    /**
     * @param LogLevel $level Ignored
     */
    public function apply(LogLevel $level): void
    {
    }
}
