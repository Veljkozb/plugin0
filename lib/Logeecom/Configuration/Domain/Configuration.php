<?php

namespace Logeecom\Configuration\Domain;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * The one configuration the application holds: the provider credentials, the environment they belong to and
 * whether they are connected.
 *
 * Every property is readonly. A change is a new object built by one of the with* methods, so what a caller
 * holds can never drift away from what is stored. There is deliberately no toArray(): the secret is in here,
 * and every answer to the browser picks its fields by name instead.
 */
final class Configuration
{
    /**
     * The defaults are the row the migration inserts: Test, not connected, no credentials.
     *
     * @param Environment $environment The environment the credentials belong to
     * @param string|null $pspid The merchant account id at the provider
     * @param string|null $apiKey The API key, stored as it is
     * @param string|null $apiSecret The API secret, decrypted; never sent to the browser
     * @param ConnectionState $state Connected only after the provider accepted the credentials
     * @param DateTimeImmutable|null $lastVerifiedAt When the provider last accepted them
     */
    public function __construct(
        public readonly Environment $environment = Environment::Test,
        public readonly ?string $pspid = null,
        #[SensitiveParameter]
        public readonly ?string $apiKey = null,
        #[SensitiveParameter]
        public readonly ?string $apiSecret = null,
        public readonly ConnectionState $state = ConnectionState::NotConnected,
        public readonly ?DateTimeImmutable $lastVerifiedAt = null,
    ) {
    }

    /**
     * Whether the rest of the admin panel is open.
     *
     * @return bool True only in the Connected state
     */
    public function isConnected(): bool
    {
        return $this->state === ConnectionState::Connected;
    }

    /**
     * Whether a secret is stored. The only thing about the secret the browser is ever told.
     *
     * @return bool True when a secret is stored
     */
    public function hasSecret(): bool
    {
        return $this->apiSecret !== null && $this->apiSecret !== '';
    }

    /**
     * The configuration after the provider accepted these credentials.
     *
     * @param Environment $environment The environment they were verified in
     * @param string $pspid The verified PSPID
     * @param string $apiKey The verified API key
     * @param string $apiSecret The verified secret
     * @param DateTimeImmutable $verifiedAt When the provider accepted them
     *
     * @return self A new instance in the Connected state; this one is unchanged
     */
    public function withVerifiedCredentials(
        Environment $environment,
        string $pspid,
        #[SensitiveParameter]
        string $apiKey,
        #[SensitiveParameter]
        string $apiSecret,
        DateTimeImmutable $verifiedAt,
    ): self {
        return new self(
            environment: $environment,
            pspid: $pspid,
            apiKey: $apiKey,
            apiSecret: $apiSecret,
            state: ConnectionState::Connected,
            lastVerifiedAt: $verifiedAt,
        );
    }

    /**
     * The configuration after Disconnect: not connected, and every entered value kept.
     *
     * @return self A new instance in the NotConnected state; this one is unchanged
     */
    public function withoutConnection(): self
    {
        return new self(
            environment: $this->environment,
            pspid: $this->pspid,
            apiKey: $this->apiKey,
            apiSecret: $this->apiSecret,
            state: ConnectionState::NotConnected,
            lastVerifiedAt: $this->lastVerifiedAt,
        );
    }

    /**
     * The configuration in another environment. Credentials belong to one environment, so the connection
     * drops; the values are kept for the administrator to correct and connect again.
     *
     * @param Environment $environment The environment switched to
     *
     * @return self A new instance in the NotConnected state; this one is unchanged
     */
    public function withEnvironment(Environment $environment): self
    {
        return new self(
            environment: $environment,
            pspid: $this->pspid,
            apiKey: $this->apiKey,
            apiSecret: $this->apiSecret,
            state: ConnectionState::NotConnected,
            lastVerifiedAt: $this->lastVerifiedAt,
        );
    }
}
