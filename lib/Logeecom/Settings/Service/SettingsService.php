<?php

namespace Logeecom\Settings\Service;

use Closure;
use InvalidArgumentException;
use Logeecom\PaymentMethods\Repository\ReferenceDataRepositoryInterface;
use Logeecom\Settings\Domain\LogLevel;
use Logeecom\Settings\Domain\SettingKey;
use Logeecom\Settings\Domain\Settings;
use Logeecom\Settings\Domain\Severity;
use Logeecom\Settings\Logging\LogLevelApplierInterface;
use Logeecom\Settings\Repository\SettingsRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel as PsrLogLevel;

/**
 * The rules of Requirement 4.1: every setting has a default, a missing or broken row never breaks the
 * application because the default applies, only a currency the application knows may be the default one,
 * and Restore defaults returns every setting to its default. Every change leaves a log entry (Requirement 5.1).
 *
 * The defaults live here and nowhere else. This is the "one place" the ticket asks the page size to be fixed
 * in: the lists ask rowsPerPage() and never hold a number of their own.
 */
final class SettingsService
{
    public const DEFAULT_CURRENCY = 'EUR';

    public const DEFAULT_ROWS_PER_PAGE = 10;

    /**
     * @param SettingsRepositoryInterface $repository Where the pairs are stored
     * @param ReferenceDataRepositoryInterface $referenceData The currencies the application knows
     * @param LoggerInterface $logger Where every change is recorded
     * @param LogLevelApplierInterface $levels Puts a saved log level in force for the rest of the request
     * @param bool $isLocal Whether the application runs with APP_ENV=local, which decides the default log level
     */
    public function __construct(
        private readonly SettingsRepositoryInterface $repository,
        private readonly ReferenceDataRepositoryInterface $referenceData,
        private readonly LoggerInterface $logger,
        private readonly LogLevelApplierInterface $levels,
        private readonly bool $isLocal,
    ) {
    }

    /**
     * The settings in force: each stored value that is still valid, and the default for every other one.
     *
     * A stored currency the catalogue no longer has, rows outside the bounds or a level that does not exist
     * read as missing, so they fall back to the default instead of failing.
     *
     * @return Settings The settings the application runs on now
     */
    public function current(): Settings
    {
        $stored = $this->repository->all();
        $currencies = $this->referenceData->currencies();
        $defaults = $this->defaultsFor($currencies);

        $currency = $stored[SettingKey::DefaultCurrency->value] ?? null;
        $rows = $stored[SettingKey::RowsPerPage->value] ?? '';

        return new Settings(
            in_array($currency, $currencies, true) ? $currency : $defaults->defaultCurrency,
            $this->isValidRows($rows) ? (int) $rows : $defaults->rowsPerPage,
            LogLevel::tryFrom($stored[SettingKey::LogLevel->value] ?? '') ?? $defaults->logLevel,
        );
    }

    /**
     * Every setting at its default.
     *
     * @return Settings What Restore defaults returns to
     */
    public function defaults(): Settings
    {
        return $this->defaultsFor($this->referenceData->currencies());
    }

    /**
     * The default log level: the ticket's "debug on in local only" as the default of the setting that
     * Requirement 4.1 puts in charge. It needs no database, so it is what applies when the settings cannot be
     * read at all.
     *
     * @return LogLevel Debug in the local environment, Info everywhere else
     */
    public function defaultLogLevel(): LogLevel
    {
        return $this->isLocal ? LogLevel::Debug : LogLevel::Info;
    }

    /**
     * The page size of every list. Like logLevel(), it reads only the stored pairs.
     *
     * @return int How many rows a list shows on one page
     */
    public function rowsPerPage(): int
    {
        $rows = $this->repository->all()[SettingKey::RowsPerPage->value] ?? '';

        return $this->isValidRows($rows) ? (int) $rows : self::DEFAULT_ROWS_PER_PAGE;
    }

    /**
     * The lowest level that gets written. It reads the stored pairs and nothing else - not the currency
     * catalogue - because the logger asks it once per request, before the first entry is written.
     *
     * @return LogLevel The lowest level that gets written
     */
    public function logLevel(): LogLevel
    {
        $stored = $this->repository->all()[SettingKey::LogLevel->value] ?? '';

        return LogLevel::tryFrom($stored) ?? $this->defaultLogLevel();
    }

    /**
     * @return string|null The currency forms offer first, or null when the catalogue has none
     */
    public function defaultCurrency(): ?string
    {
        return $this->current()->defaultCurrency;
    }

    /**
     * The currencies the default may be chosen from: the application's catalogue (Requirement 4.1).
     *
     * @return list<string>
     */
    public function allowedCurrencies(): array
    {
        return $this->referenceData->currencies();
    }

    /**
     * The four log levels in 4.1's order, each with the effect the screen states next to the field, in the
     * words of the 4.1 table: `Info, Warning and Error entries are written`. The levels a choice lets through
     * come from Severity, the same table as code, so the text cannot say one thing while the rule does another.
     *
     * @return list<array{value: string, label: string, effect: string}>
     */
    public function logLevels(): array
    {
        return array_map(
            static function (LogLevel $setting): array {
                $written = array_map(
                    static fn (LogLevel $level): string => ucfirst($level->value),
                    array_values(array_filter(
                        LogLevel::cases(),
                        static fn (LogLevel $level): bool => Severity::isWritten($level->value, $setting),
                    )),
                );
                $last = array_pop($written);

                return [
                    'value' => $setting->value,
                    'label' => ucfirst($setting->value),
                    'effect' => $written === []
                        ? sprintf('Only %s entries are written', $last)
                        : sprintf('%s and %s entries are written', implode(', ', $written), $last),
                ];
            },
            LogLevel::cases(),
        );
    }

    /**
     * Saves the values that differ from the ones in force, and writes one entry for each of them.
     *
     * A change of the log level is written at WARNING and every other change at INFO: the log level decides
     * what is written from then on, so lowering it is the one change that must not go unnoticed. The entries
     * of a save that changes it are judged by the more detailed of the two levels (writeUnderBothLevels()).
     *
     * @param Settings $settings The settings as the administrator saved them
     * @param int $actorId The signed-in administrator's id
     *
     * @return Settings The settings in force after saving
     *
     * @throws InvalidArgumentException if the currency is not one the application knows; nothing is saved
     */
    public function update(Settings $settings, int $actorId): Settings
    {
        if (! in_array($settings->defaultCurrency, $this->referenceData->currencies(), true)) {
            throw new InvalidArgumentException(sprintf(
                'The default currency must be one the application knows, "%s" given.',
                $settings->defaultCurrency ?? '',
            ));
        }

        $before = $this->current();
        $changes = $this->changes($before, $settings);

        if ($changes === []) {
            return $before;
        }

        $this->repository->put(array_intersect_key($settings->toPairs(), $changes));

        $this->writeUnderBothLevels(
            $before->logLevel,
            $settings->logLevel,
            function () use ($changes, $actorId): void {
                foreach ($changes as $key => $change) {
                    $this->logger->log(
                        $key === SettingKey::LogLevel->value ? PsrLogLevel::WARNING : PsrLogLevel::INFO,
                        'Setting changed',
                        ['user_id' => $actorId, 'key' => $key] + $change,
                    );
                }
            },
        );

        return $this->current();
    }

    /**
     * Returns every setting to its default by removing what is stored, and writes one entry naming what that
     * changed. A setting already at its default is not listed, and nothing at all is written when every one
     * already was.
     *
     * @param int $actorId The signed-in administrator's id
     *
     * @return Settings The defaults, now in force
     */
    public function restoreDefaults(int $actorId): Settings
    {
        $before = $this->current();

        $this->repository->clear();

        $after = $this->current();
        $changes = $this->changes($before, $after);

        if ($changes !== []) {
            $this->writeUnderBothLevels(
                $before->logLevel,
                $after->logLevel,
                function () use ($changes, $actorId): void {
                    $this->logger->log(
                        isset($changes[SettingKey::LogLevel->value]) ? PsrLogLevel::WARNING : PsrLogLevel::INFO,
                        'Settings restored to defaults',
                        ['user_id' => $actorId, 'changes' => $changes],
                    );
                },
            );
        }

        return $after;
    }

    /**
     * Writes the entries of a save under the more detailed of the level before it and the level after it, then
     * leaves the new level in force. The request's logger was built at the old level before the new one was
     * stored: judged by the old level alone, a change away from Error left no entry at all, and judged by the
     * new level alone, a change to Error would leave none either.
     *
     * @param LogLevel $before The log level in force before the save
     * @param LogLevel $after The log level the save leaves in force
     * @param Closure(): void $write Writes the entries of the save
     */
    private function writeUnderBothLevels(LogLevel $before, LogLevel $after, Closure $write): void
    {
        if ($before === $after) {
            $write();

            return;
        }

        $this->levels->apply(Severity::isWritten($before->value, $after) ? $after : $before);
        $write();
        $this->levels->apply($after);
    }

    /**
     * @param list<string> $currencies The currencies the application knows
     *
     * @return Settings Every setting at its default, given that catalogue
     */
    private function defaultsFor(array $currencies): Settings
    {
        return new Settings(
            in_array(self::DEFAULT_CURRENCY, $currencies, true) ? self::DEFAULT_CURRENCY : null,
            self::DEFAULT_ROWS_PER_PAGE,
            $this->defaultLogLevel(),
        );
    }

    /**
     * @param string $rows A stored value
     *
     * @return bool Whether it is a whole number within the bounds
     */
    private function isValidRows(string $rows): bool
    {
        return ctype_digit($rows)
            && (int) $rows >= Settings::MIN_ROWS_PER_PAGE
            && (int) $rows <= Settings::MAX_ROWS_PER_PAGE;
    }

    /**
     * What differs between two sets of settings, key by key. None of the values is a secret, so the entry can
     * carry them as they are.
     *
     * @param Settings $before The settings before
     * @param Settings $after The settings after
     *
     * @return array<string, array{from: string|null, to: string|null}> The changed keys only
     */
    private function changes(Settings $before, Settings $after): array
    {
        $from = $this->valuesOf($before);
        $to = $this->valuesOf($after);
        $changes = [];

        foreach ($from as $key => $value) {
            if ($value !== $to[$key]) {
                $changes[$key] = ['from' => $value, 'to' => $to[$key]];
            }
        }

        return $changes;
    }

    /**
     * @param Settings $settings The settings to flatten
     *
     * @return array<string, string|null> Every key, with null for a currency that is not set
     */
    private function valuesOf(Settings $settings): array
    {
        return [
            SettingKey::DefaultCurrency->value => $settings->defaultCurrency,
            SettingKey::RowsPerPage->value => (string) $settings->rowsPerPage,
            SettingKey::LogLevel->value => $settings->logLevel->value,
        ];
    }
}
