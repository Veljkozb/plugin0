<?php

namespace Logeecom\Configuration\Provider;

use Logeecom\Configuration\Domain\Environment;
use SensitiveParameter;

/**
 * Asks the payment provider whether a set of credentials is valid.
 *
 * The service depends on this interface and never on a provider, so the real one replaces FakeProvider with a
 * change to one binding in ConfigurationServiceProvider and to nothing else. The key and the secret are marked
 * #[SensitiveParameter] so no stack trace carries them; the attribute is not inherited, so an implementation
 * marks them again.
 */
interface CredentialsVerifierInterface
{
    /**
     * Whether the provider accepts these credentials in this environment.
     *
     * @param Environment $environment The environment the credentials were issued for
     * @param string $pspid The merchant account id at the provider
     * @param string $apiKey The API key
     * @param string $apiSecret The API secret
     *
     * @return bool True when the provider accepts them
     */
    public function verify(
        Environment $environment,
        string $pspid,
        #[SensitiveParameter]
        string $apiKey,
        #[SensitiveParameter]
        string $apiSecret,
    ): bool;
}
