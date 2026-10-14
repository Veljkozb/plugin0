<?php

namespace Logeecom\Configuration\Domain\Exception;

use RuntimeException;

/**
 * Thrown when the provider rejects the credentials of a Connect or a Save and re-verify. Nothing has been saved
 * by the time it is thrown.
 *
 * The message is the line the screen shows under Connection failed (Requirement 2.2), so the HTTP layer can pass
 * it on as it is.
 */
final class CredentialsRejectedException extends RuntimeException
{
    /**
     * The line the screen shows under Connection failed.
     */
    public const MESSAGE = 'The credentials were rejected. Nothing has been saved.';

    /**
     * Builds the exception for credentials the provider did not accept.
     *
     * @return self
     */
    public static function byProvider(): self
    {
        return new self(self::MESSAGE);
    }
}
