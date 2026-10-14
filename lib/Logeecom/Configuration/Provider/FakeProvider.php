<?php

namespace Logeecom\Configuration\Provider;

use Logeecom\Configuration\Domain\Environment;
use SensitiveParameter;

/**
 * A stand-in for the payment provider until the real one is chosen (the spec's first open question).
 *
 * It never leaves the container and answers by one rule, so both outcomes can be tried by hand and in tests:
 * a PSPID starting with "invalid" is rejected, anything else is accepted. The environment, the key and the
 * secret are not looked at.
 */
final class FakeProvider implements CredentialsVerifierInterface
{
    /**
     * The prefix that makes the fake reject a PSPID.
     */
    public const REJECTED_PREFIX = 'invalid';

    /**
     * @param Environment $environment The environment the credentials were issued for
     * @param string $pspid The merchant account id at the provider
     * @param string $apiKey The API key
     * @param string $apiSecret The API secret
     *
     * @return bool False for a PSPID starting with "invalid", true for anything else
     */
    public function verify(
        Environment $environment,
        string $pspid,
        #[SensitiveParameter]
        string $apiKey,
        #[SensitiveParameter]
        string $apiSecret,
    ): bool {
        return !str_starts_with($pspid, self::REJECTED_PREFIX);
    }
}
