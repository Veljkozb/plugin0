<?php

namespace Logeecom\PaymentMethods\Domain\Exception;

use RuntimeException;

/**
 * Thrown when a payment method is asked for, updated or deleted and no method is stored under that id.
 */
final class PaymentMethodNotFoundException extends RuntimeException
{
    /**
     * Builds the exception for an id nothing is stored under.
     *
     * @param int $id The id that was looked up
     *
     * @return self
     */
    public static function withId(int $id): self
    {
        return new self(sprintf('Payment method %d does not exist.', $id));
    }

    /**
     * Builds the exception for a method that has no id at all and therefore cannot be updated.
     *
     * @return self
     */
    public static function withoutId(): self
    {
        return new self('A payment method without an id cannot be updated.');
    }
}
