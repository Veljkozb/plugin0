<?php

namespace Plugin0\Infrastructure;

use PrestaShopLogger;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Interfaces\ShopLoggerAdapter;
use WOP\OnlinePayments\Core\Infrastructure\Logger\LogData;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;

/**
 * Core piše svoje poruke ovde; mi ih prosleđujemo u PrestaShop log (ps_log, Advanced Parameters > Logs).
 */
class LoggerService implements ShopLoggerAdapter
{
    /** Core nivo -> PrestaShop severity (1 info, 2 warning, 3 error, 4 major). */
    private const SEVERITY = [
        Logger::ERROR => 3,
        Logger::WARNING => 2,
        Logger::INFO => 1,
        Logger::DEBUG => 1,
    ];

    private const LEVEL_NAME = [
        Logger::ERROR => 'ERROR',
        Logger::WARNING => 'WARNING',
        Logger::INFO => 'INFO',
        Logger::DEBUG => 'DEBUG',
    ];

    public function logMessage(LogData $data): void
    {
        $level = $data->getLogLevel();

        $message = sprintf(
            'WORLDLINE [%s] %s: %s',
            self::LEVEL_NAME[$level] ?? 'INFO',
            $data->getComponent(),
            $data->getMessage()
        );

        $context = [];
        foreach ($data->getContext() as $item) {
            $context[$item->getName()] = $item->getValue();
        }
        if ($context !== []) {
            $message .= ' | ' . json_encode($context);
        }

        PrestaShopLogger::addLog($message, self::SEVERITY[$level] ?? 1);
    }
}