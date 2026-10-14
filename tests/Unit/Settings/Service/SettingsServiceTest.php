<?php

namespace Tests\Unit\Settings\Service;

use InvalidArgumentException;
use Logeecom\PaymentMethods\Repository\ReferenceDataRepositoryInterface;
use Logeecom\Settings\Domain\LogLevel;
use Logeecom\Settings\Domain\Settings;
use Logeecom\Settings\Domain\Severity;
use Logeecom\Settings\Service\SettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel as PsrLogLevel;
use Tests\Support\InMemoryReferenceData;
use Tests\Support\InMemorySettingsRepository;
use Tests\Support\NullLogLevelApplier;

/**
 * The rules of Requirement 4.1 without HTTP or a database: an in-memory repository stores the pairs, the
 * catalogue is the fixed test one, and a spy logger records every entry.
 */
final class SettingsServiceTest extends TestCase
{
    private const ACTOR = 7;

    private InMemorySettingsRepository $repository;

    private AbstractLogger $logger;

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function brokenRows(): array
    {
        return [
            'unknown currency' => [['default_currency' => 'XXX']],
            'rows below the bound' => [['rows_per_page' => '4']],
            'rows above the bound' => [['rows_per_page' => '101']],
            'rows not a number' => [['rows_per_page' => '10abc']],
            'negative rows' => [['rows_per_page' => '-10']],
            'unknown level' => [['log_level' => 'verbose']],
            'level in capitals' => [['log_level' => 'Info']],
        ];
    }

    /**
     * @return array<string, array{LogLevel, list<string>}>
     */
    public static function levelTable(): array
    {
        $severe = [PsrLogLevel::CRITICAL, PsrLogLevel::ALERT, PsrLogLevel::EMERGENCY];

        return [
            'Debug' => [LogLevel::Debug, ['debug', 'info', 'notice', 'warning', 'error', ...$severe]],
            'Info' => [LogLevel::Info, ['info', 'notice', 'warning', 'error', ...$severe]],
            'Warning' => [LogLevel::Warning, ['warning', 'error', ...$severe]],
            'Error' => [LogLevel::Error, ['error', ...$severe]],
        ];
    }

    public function test_an_empty_store_gives_every_default(): void
    {
        $settings = $this->service(isLocal: false)->current();

        self::assertSame('EUR', $settings->defaultCurrency);
        self::assertSame(10, $settings->rowsPerPage);
        self::assertSame(LogLevel::Info, $settings->logLevel);
    }

    public function test_the_default_log_level_is_debug_only_in_the_local_environment(): void
    {
        self::assertSame(LogLevel::Debug, $this->service(isLocal: true)->logLevel());
        self::assertSame(LogLevel::Info, $this->service(isLocal: false)->logLevel());
    }

    public function test_the_default_currency_is_none_when_the_catalogue_has_no_eur(): void
    {
        $catalogue = new class () implements ReferenceDataRepositoryInterface {
            public function currencies(): array
            {
                return ['RSD', 'USD'];
            }

            public function countries(): array
            {
                return ['RS'];
            }
        };

        $service = new SettingsService($this->repository, $catalogue, $this->logger, new NullLogLevelApplier(), false);

        self::assertNull($service->defaultCurrency());
    }

    public function test_stored_values_are_read_over_the_defaults(): void
    {
        $this->repository->put(['default_currency' => 'RSD', 'rows_per_page' => '25', 'log_level' => 'warning']);

        $settings = $this->service()->current();

        self::assertSame('RSD', $settings->defaultCurrency);
        self::assertSame(25, $settings->rowsPerPage);
        self::assertSame(LogLevel::Warning, $settings->logLevel);
    }

    /**
     * "A missing row must never break the application: the default applies." A row that holds something the
     * application cannot use is treated the same way as a missing one.
     *
     * @param array<string, string> $stored
     */
    #[DataProvider('brokenRows')]
    public function test_a_broken_row_reads_as_its_default(array $stored): void
    {
        $this->repository->put($stored);

        $settings = $this->service(isLocal: false)->current();

        self::assertSame('EUR', $settings->defaultCurrency);
        self::assertSame(10, $settings->rowsPerPage);
        self::assertSame(LogLevel::Info, $settings->logLevel);
    }

    public function test_an_update_saves_only_what_changed_and_writes_one_entry_per_change(): void
    {
        $this->service(isLocal: false)->update(new Settings('RSD', 10, LogLevel::Info), self::ACTOR);

        self::assertSame(['default_currency' => 'RSD'], $this->repository->all());
        self::assertCount(1, $this->logger->records);
        self::assertSame(PsrLogLevel::INFO, $this->logger->records[0]['level']);
        self::assertSame('Setting changed', $this->logger->records[0]['message']);
        self::assertSame(
            ['user_id' => self::ACTOR, 'key' => 'default_currency', 'from' => 'EUR', 'to' => 'RSD'],
            $this->logger->records[0]['context'],
        );
    }

    public function test_a_change_of_the_log_level_is_written_at_warning(): void
    {
        $this->service(isLocal: false)->update(new Settings('EUR', 20, LogLevel::Error), self::ACTOR);

        $byKey = array_column(
            array_map(fn (array $record): array => [$record['context']['key'], $record['level']], $this->logger->records),
            1,
            0,
        );

        self::assertSame(['rows_per_page' => PsrLogLevel::INFO, 'log_level' => PsrLogLevel::WARNING], $byKey);
    }

    public function test_an_update_that_changes_nothing_writes_nothing(): void
    {
        $settings = $this->service(isLocal: false)->update(new Settings('EUR', 10, LogLevel::Info), self::ACTOR);

        self::assertSame([], $this->repository->all());
        self::assertSame([], $this->logger->records);
        self::assertSame(10, $settings->rowsPerPage);
    }

    public function test_an_unknown_currency_is_refused_and_nothing_is_saved(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->service()->update(new Settings('XXX', 10, LogLevel::Info), self::ACTOR);
        } finally {
            self::assertSame([], $this->repository->all());
            self::assertSame([], $this->logger->records);
        }
    }

    public function test_rows_outside_the_bounds_cannot_even_be_built(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Settings('EUR', 101, LogLevel::Info);
    }

    public function test_restoring_the_defaults_empties_the_store_and_names_what_it_changed(): void
    {
        $this->repository->put(['default_currency' => 'RSD', 'rows_per_page' => '25']);

        $settings = $this->service(isLocal: false)->restoreDefaults(self::ACTOR);

        self::assertSame([], $this->repository->all());
        self::assertSame('EUR', $settings->defaultCurrency);
        self::assertSame(10, $settings->rowsPerPage);
        self::assertCount(1, $this->logger->records);
        self::assertSame(PsrLogLevel::INFO, $this->logger->records[0]['level']);
        self::assertSame('Settings restored to defaults', $this->logger->records[0]['message']);
        self::assertSame(
            [
                'user_id' => self::ACTOR,
                'changes' => [
                    'default_currency' => ['from' => 'RSD', 'to' => 'EUR'],
                    'rows_per_page' => ['from' => '25', 'to' => '10'],
                ],
            ],
            $this->logger->records[0]['context'],
        );
    }

    public function test_restoring_the_defaults_is_a_warning_when_it_changes_the_log_level(): void
    {
        $this->repository->put(['log_level' => 'error']);

        $this->service(isLocal: false)->restoreDefaults(self::ACTOR);

        self::assertSame(PsrLogLevel::WARNING, $this->logger->records[0]['level']);
    }

    public function test_restoring_when_everything_is_already_default_writes_nothing(): void
    {
        $this->service()->restoreDefaults(self::ACTOR);

        self::assertSame([], $this->logger->records);
    }

    /**
     * The table in Requirement 4.1, one row per setting, plus the PSR-3 levels 4.1 does not name.
     *
     * @param list<string> $written
     */
    #[DataProvider('levelTable')]
    public function test_the_log_level_writes_what_the_requirement_table_says(LogLevel $setting, array $written): void
    {
        $all = [
            PsrLogLevel::DEBUG,
            PsrLogLevel::INFO,
            PsrLogLevel::NOTICE,
            PsrLogLevel::WARNING,
            PsrLogLevel::ERROR,
            PsrLogLevel::CRITICAL,
            PsrLogLevel::ALERT,
            PsrLogLevel::EMERGENCY,
        ];

        self::assertSame(
            $written,
            array_values(array_filter($all, fn (string $level): bool => Severity::isWritten($level, $setting))),
        );
        self::assertFalse(Severity::isWritten('verbose', $setting));
    }

    protected function setUp(): void
    {
        $this->repository = new InMemorySettingsRepository();

        $this->logger = new class () extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    private function service(bool $isLocal = false): SettingsService
    {
        return new SettingsService(
            $this->repository,
            new InMemoryReferenceData(),
            $this->logger,
            new NullLogLevelApplier(),
            $isLocal,
        );
    }
}
