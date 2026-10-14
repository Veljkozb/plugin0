<?php

namespace Tests\Unit\Configuration\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Logeecom\Configuration\Domain\Configuration;
use Logeecom\Configuration\Domain\ConnectionState;
use Logeecom\Configuration\Domain\Environment;
use Logeecom\Configuration\Domain\Exception\CredentialsRejectedException;
use Logeecom\Configuration\Provider\CredentialsVerifierInterface;
use Logeecom\Configuration\Provider\FakeProvider;
use Logeecom\Configuration\Repository\ConfigurationRepositoryInterface;
use Logeecom\Configuration\Service\ConfigurationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use RuntimeException;
use Tests\Support\InMemoryConfigurationRepository;
use Throwable;

/**
 * The rules of Requirements 2.1 and 2.2 without HTTP or a database: FakeProvider decides, an in-memory
 * repository stores, and a spy logger records. The verifier is FakeProvider behind a thin recorder, so a test
 * can see which secret the provider was actually asked about.
 */
final class ConfigurationServiceTest extends TestCase
{
    /** The administrator every attempt and change here is made by. */
    private const USER = 7;

    private const KEY = 'a1b2c3d4e5f6';

    private const SECRET = 'stored-secret-value';

    private InMemoryConfigurationRepository $repository;

    private AbstractLogger $logger;

    private CredentialsVerifierInterface $verifier;

    public function test_accepted_credentials_are_saved_and_connect(): void
    {
        $before = new DateTimeImmutable();

        $returned = $this->service()->connect(Environment::Test, 'demo-merchant', self::KEY, self::SECRET, self::USER);

        $stored = $this->repository->get();
        self::assertSame($returned, $stored);
        self::assertSame(ConnectionState::Connected, $stored->state);
        self::assertSame(Environment::Test, $stored->environment);
        self::assertSame('demo-merchant', $stored->pspid);
        self::assertSame(self::KEY, $stored->apiKey);
        self::assertSame(self::SECRET, $stored->apiSecret);
        self::assertNotNull($stored->lastVerifiedAt);
        self::assertGreaterThanOrEqual($before, $stored->lastVerifiedAt);
        self::assertLessThanOrEqual(new DateTimeImmutable(), $stored->lastVerifiedAt);
    }

    /**
     * Save and re-verify with everything changed: what was entered is what is verified and what is stored, and
     * the time of the last verification moves forward.
     */
    public function test_an_accepted_attempt_saves_what_was_entered_over_what_was_stored(): void
    {
        $stored = $this->connectedConfiguration(Environment::Test);
        $this->repository->save($stored);

        $this->service()->connect(Environment::Live, 'live-merchant', 'live-key', 'live-secret', self::USER);

        self::assertSame(Environment::Live, $this->verifier->calls[0]['environment']);
        self::assertSame('live-merchant', $this->verifier->calls[0]['pspid']);
        self::assertSame('live-key', $this->verifier->calls[0]['apiKey']);
        self::assertSame('live-secret', $this->verifier->calls[0]['apiSecret']);

        $saved = $this->repository->get();
        self::assertSame(ConnectionState::Connected, $saved->state);
        self::assertSame(Environment::Live, $saved->environment);
        self::assertSame('live-merchant', $saved->pspid);
        self::assertSame('live-key', $saved->apiKey);
        self::assertSame('live-secret', $saved->apiSecret);
        self::assertGreaterThan($stored->lastVerifiedAt, $saved->lastVerifiedAt);
    }

    public function test_an_accepted_attempt_writes_one_info_entry(): void
    {
        $this->service()->connect(Environment::Test, 'demo-merchant', self::KEY, self::SECRET, self::USER);

        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::INFO, $this->logger->records[0]['level']);
        self::assertSame('Connection verified for PSPID demo-merchant (test)', $this->logger->records[0]['message']);
        self::assertSame(self::USER, $this->logger->records[0]['context']['user_id']);
        $this->assertNoCredentialInTheLog();
    }

    public function test_rejected_credentials_throw_the_message_the_screen_shows(): void
    {
        $this->expectException(CredentialsRejectedException::class);
        $this->expectExceptionMessage('The credentials were rejected. Nothing has been saved.');

        $this->service()->connect(Environment::Test, 'invalid-merchant', self::KEY, self::SECRET, self::USER);
    }

    /**
     * Requirement 2.2, point 5: a Save and re-verify that does not validate keeps the previously working
     * configuration - here a connected one in Live, which must come out of the attempt exactly as it went in.
     */
    public function test_a_rejected_attempt_leaves_the_working_configuration_as_it_was(): void
    {
        $working = $this->connectedConfiguration(Environment::Live);
        $this->repository->save($working);

        try {
            $this->service()->connect(Environment::Test, 'invalid-merchant', 'other-key', 'other', self::USER);
            self::fail('The attempt should have been rejected.');
        } catch (CredentialsRejectedException) {
        }

        self::assertSame($working, $this->repository->get());
    }

    public function test_a_rejected_attempt_writes_one_error_entry(): void
    {
        try {
            $this->service()->connect(Environment::Live, 'invalid-merchant', self::KEY, self::SECRET, self::USER);
        } catch (CredentialsRejectedException) {
        }

        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::ERROR, $this->logger->records[0]['level']);
        self::assertSame('Connection rejected for PSPID invalid-merchant (live)', $this->logger->records[0]['message']);
        self::assertSame(self::USER, $this->logger->records[0]['context']['user_id']);
        $this->assertNoCredentialInTheLog();
    }

    public function test_each_attempt_writes_exactly_one_entry(): void
    {
        $service = $this->service();

        $service->connect(Environment::Test, 'demo-merchant', self::KEY, self::SECRET, self::USER);
        try {
            $service->connect(Environment::Test, 'invalid-merchant', self::KEY, null, self::USER);
        } catch (CredentialsRejectedException) {
        }
        $service->connect(Environment::Test, 'demo-merchant', self::KEY, null, self::USER);

        self::assertSame(
            [LogLevel::INFO, LogLevel::ERROR, LogLevel::INFO],
            array_column($this->logger->records, 'level'),
        );
    }

    /**
     * The secret field comes back empty when it was left alone: the stored secret is the one verified and kept.
     */
    public function test_a_missing_secret_verifies_and_keeps_the_stored_one(): void
    {
        $this->repository->save($this->connectedConfiguration(Environment::Test));

        $this->service()->connect(Environment::Test, 'demo-merchant', 'new-key', null, self::USER);

        self::assertSame(self::SECRET, $this->verifier->calls[0]['apiSecret']);
        self::assertSame(self::SECRET, $this->repository->get()->apiSecret);
        self::assertSame('new-key', $this->repository->get()->apiKey);
    }

    public function test_an_empty_secret_counts_as_missing(): void
    {
        $this->repository->save($this->connectedConfiguration(Environment::Test));

        $this->service()->connect(Environment::Test, 'demo-merchant', self::KEY, '', self::USER);

        self::assertSame(self::SECRET, $this->verifier->calls[0]['apiSecret']);
        self::assertSame(self::SECRET, $this->repository->get()->apiSecret);
    }

    public function test_an_entered_secret_replaces_the_stored_one(): void
    {
        $this->repository->save($this->connectedConfiguration(Environment::Test));

        $this->service()->connect(Environment::Test, 'demo-merchant', self::KEY, 'new-secret', self::USER);

        self::assertSame('new-secret', $this->verifier->calls[0]['apiSecret']);
        self::assertSame('new-secret', $this->repository->get()->apiSecret);
    }

    public function test_no_secret_entered_and_none_stored_is_refused_before_the_provider_is_asked(): void
    {
        $initial = $this->repository->get();

        try {
            $this->service()->connect(Environment::Test, 'demo-merchant', self::KEY, null, self::USER);
            self::fail('A connection without any secret should have been refused.');
        } catch (InvalidArgumentException) {
        }

        self::assertSame([], $this->verifier->calls);
        self::assertSame([], $this->logger->records);
        self::assertSame($initial, $this->repository->get());
    }

    /**
     * PHP writes a function's arguments into an exception's stack trace, and the framework writes that trace into
     * the log when it reports the exception. Whatever is thrown out of connect() - a rejection, a missing secret,
     * a failing save - the trace must carry neither credential. Strings are cut at 15 characters in a trace, so
     * the secret is looked for by its first 13.
     */
    public function test_no_stack_trace_out_of_connect_carries_the_key_or_the_secret(): void
    {
        $failingStorage = new class () implements ConfigurationRepositoryInterface {
            public function get(): Configuration
            {
                return new Configuration();
            }

            public function save(Configuration $configuration): void
            {
                throw new RuntimeException('The database is gone.');
            }
        };
        $attempts = [
            'rejection' => fn () => $this->service()
                ->connect(Environment::Test, 'invalid-merchant', self::KEY, self::SECRET, self::USER),
            'missing secret' => fn () => $this->service()
                ->connect(Environment::Test, 'demo-merchant', self::KEY, null, self::USER),
            'failing save' => fn () => (new ConfigurationService($failingStorage, $this->verifier, $this->logger))
                ->connect(Environment::Test, 'demo-merchant', self::KEY, self::SECRET, self::USER),
        ];

        foreach ($attempts as $name => $attempt) {
            try {
                $attempt();
                self::fail(sprintf('The %s should have thrown.', $name));
            } catch (Throwable $e) {
                self::assertStringContainsString('->connect(', $e->getTraceAsString(), $name);
                self::assertStringNotContainsString(self::KEY, $e->getTraceAsString(), $name);
                self::assertStringNotContainsString(substr(self::SECRET, 0, 13), $e->getTraceAsString(), $name);
            }
        }
    }

    public function test_disconnect_drops_the_connection_and_keeps_every_value(): void
    {
        $connected = $this->connectedConfiguration(Environment::Live);
        $this->repository->save($connected);

        $returned = $this->service()->disconnect(self::USER);

        $stored = $this->repository->get();
        self::assertSame($returned, $stored);
        self::assertSame(ConnectionState::NotConnected, $stored->state);
        self::assertSame(Environment::Live, $stored->environment);
        self::assertSame($connected->pspid, $stored->pspid);
        self::assertSame($connected->apiKey, $stored->apiKey);
        self::assertSame($connected->apiSecret, $stored->apiSecret);
        self::assertSame($connected->lastVerifiedAt, $stored->lastVerifiedAt);

        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::INFO, $this->logger->records[0]['level']);
        self::assertSame('Disconnected from the provider', $this->logger->records[0]['message']);
        self::assertSame(
            ['user_id' => self::USER, 'pspid' => 'demo-merchant', 'environment' => 'live'],
            $this->logger->records[0]['context'],
        );
        $this->assertNoCredentialInTheLog();
    }

    public function test_choosing_the_other_environment_drops_the_connection_and_keeps_the_credentials(): void
    {
        $connected = $this->connectedConfiguration(Environment::Test);
        $this->repository->save($connected);

        $returned = $this->service()->changeEnvironment(Environment::Live, self::USER);

        $stored = $this->repository->get();
        self::assertSame($returned, $stored);
        self::assertSame(Environment::Live, $stored->environment);
        self::assertSame(ConnectionState::NotConnected, $stored->state);
        self::assertSame($connected->pspid, $stored->pspid);
        self::assertSame($connected->apiKey, $stored->apiKey);
        self::assertSame($connected->apiSecret, $stored->apiSecret);

        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::INFO, $this->logger->records[0]['level']);
        self::assertSame('Environment changed to live', $this->logger->records[0]['message']);
        self::assertSame(
            ['user_id' => self::USER, 'from' => 'test', 'to' => 'live', 'was_connected' => true],
            $this->logger->records[0]['context'],
        );
        $this->assertNoCredentialInTheLog();
    }

    public function test_choosing_the_environment_already_stored_changes_nothing(): void
    {
        $connected = $this->connectedConfiguration(Environment::Test);
        $this->repository->save($connected);

        $returned = $this->service()->changeEnvironment(Environment::Test, self::USER);

        self::assertSame($connected, $returned);
        self::assertSame($connected, $this->repository->get());
        self::assertTrue($this->service()->isConnected());
        self::assertSame([], $this->logger->records);
    }

    public function test_current_and_is_connected_read_what_is_stored(): void
    {
        $service = $this->service();
        self::assertFalse($service->isConnected());
        self::assertSame($this->repository->get(), $service->current());

        $this->repository->save($this->connectedConfiguration(Environment::Test));

        self::assertTrue($service->isConnected());
        self::assertSame($this->repository->get(), $service->current());
    }

    protected function setUp(): void
    {
        $this->repository = new InMemoryConfigurationRepository();

        $this->logger = new class () extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $this->verifier = new class () implements CredentialsVerifierInterface {
            /** @var list<array{environment: Environment, pspid: string, apiKey: string, apiSecret: string}> */
            public array $calls = [];

            public function verify(Environment $environment, string $pspid, string $apiKey, string $apiSecret): bool
            {
                $this->calls[] = compact('environment', 'pspid', 'apiKey', 'apiSecret');

                return (new FakeProvider())->verify($environment, $pspid, $apiKey, $apiSecret);
            }
        };
    }

    private function service(): ConfigurationService
    {
        return new ConfigurationService($this->repository, $this->verifier, $this->logger);
    }

    private function connectedConfiguration(Environment $environment): Configuration
    {
        return (new Configuration())->withVerifiedCredentials(
            $environment,
            'demo-merchant',
            self::KEY,
            self::SECRET,
            new DateTimeImmutable('2026-09-13 10:42:00'),
        );
    }

    /**
     * Neither credential appears anywhere in any entry, message or context.
     */
    private function assertNoCredentialInTheLog(): void
    {
        $written = json_encode($this->logger->records, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(self::KEY, $written);
        self::assertStringNotContainsString(self::SECRET, $written);
    }
}
