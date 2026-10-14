<?php

namespace Plugin0\Adapter;

use Logeecom\Settings\Domain\LogLevel;
use Logeecom\Settings\Domain\SettingKey;
use Logeecom\Settings\Repository\SettingsRepositoryInterface;
use Plugin0\Configuration;
use Plugin0\Infrastructure\CoreLogLevel;

/**
 * Demo skladište podešavanja (parovi ključ => tekst; u demu app/Repositories/EloquentSettingsRepository.php nad
 * tabelom settings) nad Core ConfigEntity redovima u plugin0_entity, kroz Plugin0\Configuration (getStoredValue,
 * storeValue, removeValue).
 *
 * Ključevi se prevode. Demo "log_level" (ime nivoa: debug, info, warning, error) je Core-ov sopstveni ključ
 * "minLogLevel" (Core broj 3, 2, 1, 0; docs/CORE.md 4.4), da Core-ov getMinLogLevel() uvek kaže isto što i Settings
 * stranica. "rows_per_page" je "rowsPerPage", a "default_currency" je "defaultCurrency", ključevi koje plugin0 već
 * koristi. Sva tri su kontekstno specifični ključevi sa kontekstom '' (jedan tenant): podešavanja važe za celu
 * PrestaShop instalaciju.
 *
 * Vrednost koja nije ispravna (na primer broj nivoa van 0 do 3) ovde važi kao da je nema: servis tada primenjuje
 * default, isto kao za ključ koji nikad nije sačuvan.
 */
final class SettingsRepository implements SettingsRepositoryInterface
{
    /**
     * @return array<string, string> vrednosti po demo ključu; ključ koji nije sačuvan nedostaje
     */
    public function all(): array
    {
        $pairs = [];
        foreach (SettingKey::cases() as $key) {
            $value = $this->read($key);
            if ($value !== null) {
                $pairs[$key->value] = $value;
            }
        }

        return $pairs;
    }

    /**
     * @param array<string, string> $pairs vrednosti po demo ključu
     */
    public function put(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            $this->write(SettingKey::from((string) $key), (string) $value);
        }
    }

    public function clear(): void
    {
        $configuration = Configuration::getInstance();
        foreach (SettingKey::cases() as $key) {
            $configuration->removeValue(self::coreKey($key));
        }
    }

    private function read(SettingKey $key): ?string
    {
        $stored = Configuration::getInstance()->getStoredValue(self::coreKey($key));

        if ($key === SettingKey::LogLevel) {
            $level = CoreLogLevel::toSetting($stored);

            return $level === null ? null : $level->value;
        }

        return is_scalar($stored) ? (string) $stored : null;
    }

    private function write(SettingKey $key, string $value): void
    {
        $configuration = Configuration::getInstance();

        if ($key === SettingKey::LogLevel) {
            $configuration->saveMinLogLevel(CoreLogLevel::fromSetting(LogLevel::from($value)));

            return;
        }

        $configuration->storeValue(self::coreKey($key), $value);
    }

    private static function coreKey(SettingKey $key): string
    {
        return match ($key) {
            SettingKey::LogLevel => Configuration::KEY_MIN_LOG_LEVEL,
            SettingKey::RowsPerPage => Configuration::KEY_ROWS_PER_PAGE,
            SettingKey::DefaultCurrency => Configuration::KEY_DEFAULT_CURRENCY,
        };
    }
}
