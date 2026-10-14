<?php

namespace Logeecom\PaymentMethods\Domain\Exception;

use RuntimeException;

/**
 * Thrown when a payment method is saved under a code another method already holds.
 *
 * The code identifies the method to the outside world, so two methods sharing one would make the pair
 * indistinguishable everywhere the code is used instead of the id.
 */
final class DuplicatePaymentMethodCodeException extends RuntimeException
{
    /**
     * Builds the exception for a code that is already taken.
     *
     * @param string $code The code that was refused
     *
     * @return self
     */
    public static function code(string $code): self
    {
        return new self(sprintf('Payment method code "%s" is already taken.', $code));
    }
}
