<?php

namespace Logeecom\Settings\Logging;

use Logeecom\Settings\Domain\LogLevel;

/**
 * Puts a log level in force for the rest of the current request, on every channel.
 *
 * The settings service needs it because the logger of a request is built, at the stored level, before a save
 * of the Log level setting stores a new one. src/ knows nothing of the framework's channels, so the service
 * asks through this interface and app/Logging answers.
 */
interface LogLevelApplierInterface
{
    /**
     * @param LogLevel $level The lowest level written from now on
     */
    public function apply(LogLevel $level): void;
}
