<?php

namespace Plugin0\Adapter;

use BackedEnum;
use DateTimeInterface;
use Plugin0\Infrastructure\CoreLogLevel;
use Psr\Log\AbstractLogger;
use Throwable;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;

/**
 * PSR-3 logger koji demo servisi dobijaju (u demu Laravel LogManager): svaki zapis ide u Core Logger, odatle u
 * LoggerService (Core-ov ShopLoggerAdapter) i u PrestaShop log ps_log, kao "WORLDLINE [NIVO] Komponenta: poruka |
 * {kontekst}". Komponenta je ime servisa koji piše (PaymentMethods, Settings), da se na Logs stranici vidi ko je šta
 * zapisao. Nivo odlučuje LoggerService po podešavanju "Log level".
 *
 * Kontekst se svodi na ono što json_encode može da zapiše: izuzetak postaje klasa i poruka (bez steka, jer stek nosi
 * argumente funkcija), datum ISO 8601, enum svoja vrednost, objekat svoj tekst ili ime klase.
 */
final class CoreLogger extends AbstractLogger
{
    private string $component;

    public function __construct(string $component)
    {
        $this->component = $component;
    }

    /**
     * @param mixed $level PSR-3 ime nivoa
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $coreLevel = CoreLogLevel::fromPsr((string) $level);
        $message = (string) $message;
        $context = self::normalize($context);

        switch ($coreLevel) {
            case Logger::ERROR:
                Logger::logError($message, $this->component, $context);
                break;
            case Logger::WARNING:
                Logger::logWarning($message, $this->component, $context);
                break;
            case Logger::DEBUG:
                Logger::logDebug($message, $this->component, $context);
                break;
            default:
                Logger::logInfo($message, $this->component, $context);
        }
    }

    /**
     * @param array<mixed> $context
     *
     * @return array<mixed>
     */
    private static function normalize(array $context): array
    {
        $normalized = [];
        foreach ($context as $key => $value) {
            $normalized[$key] = self::normalizeValue($value);
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function normalizeValue($value)
    {
        if ($value instanceof Throwable) {
            return ['class' => get_class($value), 'message' => $value->getMessage()];
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if (is_array($value)) {
            return self::normalize($value);
        }
        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : get_class($value);
        }

        return $value;
    }
}
