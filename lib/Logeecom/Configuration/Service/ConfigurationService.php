<?php

namespace Logeecom\Configuration\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Logeecom\Configuration\Domain\Configuration;
use Logeecom\Configuration\Domain\Environment;
use Logeecom\Configuration\Domain\Exception\CredentialsRejectedException;
use Logeecom\Configuration\Provider\CredentialsVerifierInterface;
use Logeecom\Configuration\Repository\ConfigurationRepositoryInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * The rules of Requirements 2.1 and 2.2: credentials are saved only after the provider accepted them, a
 * rejection leaves the working configuration as it was, disconnecting keeps every value, changing the
 * environment drops the connection, and every verification attempt leaves one log entry without the key or the
 * secret. Nothing here knows about HTTP or about where the configuration is stored.
 *
 * Every write takes the id of the administrator who asked for it and names it in its entry (Requirement 5.1):
 * the two verification entries, a disconnect and an environment change.
 */
final class ConfigurationService
{
    /**
     * @param ConfigurationRepositoryInterface $repository Where the one configuration is stored
     * @param CredentialsVerifierInterface $verifier Who decides whether credentials are valid
     * @param LoggerInterface $logger Where every attempt and every change is recorded (Requirement 5.1)
     */
    public function __construct(
        private readonly ConfigurationRepositoryInterface $repository,
        private readonly CredentialsVerifierInterface $verifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The stored configuration, as the screen shows it.
     *
     * @return Configuration What is stored now
     */
    public function current(): Configuration
    {
        return $this->repository->get();
    }

    /**
     * Whether the rest of the admin panel is open. The only question the connected middleware asks.
     *
     * @return bool True in the Connected state
     */
    public function isConnected(): bool
    {
        return $this->repository->get()->isConnected();
    }

    /**
     * Connect and Save and re-verify alike: verify the credentials with the provider, then save them.
     *
     * One log entry is written as soon as the provider has answered, whatever it answered, so an attempt is
     * recorded even if saving fails afterwards. Only an accepted attempt saves anything.
     *
     * The key and the secret are #[SensitiveParameter]: PHP writes a function's arguments into an exception's
     * stack trace, and the framework writes that trace into the log whenever it reports an exception.
     *
     * @param Environment $environment The environment the credentials are for
     * @param string $pspid The PSPID as entered
     * @param string $apiKey The API key as entered
     * @param string|null $apiSecret The secret as entered; null or empty keeps the stored one
     * @param int $userId Who made the attempt, the signed-in administrator's id; the entry's user
     *
     * @return Configuration The saved configuration, in the Connected state
     *
     * @throws CredentialsRejectedException if the provider rejects the credentials; nothing is saved
     * @throws InvalidArgumentException if no secret is given and none is stored; nothing is saved or logged
     */
    public function connect(
        Environment $environment,
        string $pspid,
        #[SensitiveParameter]
        string $apiKey,
        #[SensitiveParameter]
        ?string $apiSecret,
        int $userId,
    ): Configuration {
        $current = $this->repository->get();
        $secret = $this->secretToVerify($apiSecret, $current);

        $accepted = $this->verifier->verify($environment, $pspid, $apiKey, $secret);

        $this->logAttempt($accepted, $environment, $pspid, $userId);

        if (!$accepted) {
            throw CredentialsRejectedException::byProvider();
        }

        $connected = $current->withVerifiedCredentials(
            $environment,
            $pspid,
            $apiKey,
            $secret,
            new DateTimeImmutable(),
        );
        $this->repository->save($connected);

        return $connected;
    }

    /**
     * Disconnect: the state becomes Not connected and every entered value is kept.
     *
     * @param int $userId The signed-in administrator's id; the entry's user
     *
     * @return Configuration The saved configuration, in the NotConnected state
     */
    public function disconnect(int $userId): Configuration
    {
        $disconnected = $this->repository->get()->withoutConnection();
        $this->repository->save($disconnected);

        $this->logger->info('Disconnected from the provider', [
            'user_id' => $userId,
            'pspid' => $disconnected->pspid,
            'environment' => $disconnected->environment->value,
        ]);

        return $disconnected;
    }

    /**
     * Choosing the other environment: saved at once and the connection dropped, the credentials kept. Choosing
     * the environment that is already stored changes nothing, so it cannot disconnect by accident.
     *
     * @param Environment $environment The environment chosen
     * @param int $userId The signed-in administrator's id; the entry's user
     *
     * @return Configuration The stored configuration after the change
     */
    public function changeEnvironment(Environment $environment, int $userId): Configuration
    {
        $current = $this->repository->get();

        if ($current->environment === $environment) {
            return $current;
        }

        $changed = $current->withEnvironment($environment);
        $this->repository->save($changed);

        $this->logger->info(sprintf('Environment changed to %s', $environment->value), [
            'user_id' => $userId,
            'from' => $current->environment->value,
            'to' => $environment->value,
            'was_connected' => $current->isConnected(),
        ]);

        return $changed;
    }

    /**
     * The secret the provider is asked about: the one entered, or the stored one when the field was left empty.
     *
     * @param string|null $entered The secret as entered
     * @param Configuration $current The stored configuration
     *
     * @return string The secret to verify
     *
     * @throws InvalidArgumentException if none is entered and none is stored
     */
    private function secretToVerify(#[SensitiveParameter] ?string $entered, Configuration $current): string
    {
        if ($entered !== null && $entered !== '') {
            return $entered;
        }

        if (!$current->hasSecret()) {
            throw new InvalidArgumentException('An API secret is required: none was entered and none is stored.');
        }

        return $current->apiSecret;
    }

    /**
     * One entry per attempt, in Requirement 5.1's shape: the level, the message, and the user in the context.
     * The API key and the secret are never passed to the logger.
     *
     * @param bool $accepted What the provider answered
     * @param Environment $environment The environment of the attempt
     * @param string $pspid The PSPID of the attempt
     * @param int $userId The signed-in administrator's id
     */
    private function logAttempt(bool $accepted, Environment $environment, string $pspid, int $userId): void
    {
        $context = [
            'user_id' => $userId,
            'pspid' => $pspid,
            'environment' => $environment->value,
        ];

        if ($accepted) {
            $this->logger->info(
                sprintf('Connection verified for PSPID %s (%s)', $pspid, $environment->value),
                $context,
            );

            return;
        }

        $this->logger->error(
            sprintf('Connection rejected for PSPID %s (%s)', $pspid, $environment->value),
            $context,
        );
    }
}
