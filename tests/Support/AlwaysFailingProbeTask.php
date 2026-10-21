<?php

namespace Tests\Support;

use Plugin0\BackgroundProcessing\TaskAttempt;
use RuntimeException;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\Task;

/**
 * Integration probe for the Core retry policy (docs/BACKGROUND.md, 9.3): a queue task that always throws and logs
 * its attempt number, and logs once more when the Core calls onFail after the last attempt.
 *
 * It is put into the queue only by hand, from a script in the dev container. It lives under tests/ and is loaded
 * through the autoload-dev mapping, so a production install (composer install --no-dev) does not have it.
 */
final class AlwaysFailingProbeTask extends Task
{
    public function execute(): void
    {
        Logger::logWarning(
            sprintf('Retry probe: attempt %d of %d failed on purpose', TaskAttempt::current($this), TaskAttempt::max()),
            'RetryProbe'
        );

        throw new RuntimeException('Retry probe failure');
    }

    public function onFail(): void
    {
        Logger::logError('Retry probe: no attempts left, the Core called onFail', 'RetryProbe');
    }
}
