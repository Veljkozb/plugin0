<?php

namespace Logeecom\PaymentMethods\Domain\Exception;

use InvalidArgumentException;

/**
 * Thrown when a payment method would be built with no currency or no country.
 *
 * Requirement 3.2: "At least one currency and at least one country are required. A method that applies
 * nowhere cannot be saved." The rule lives on the object rather than on the form or on a repository, so no
 * path into storage can produce such a method - not a direct call, not a seeder, not a test.
 */
final class PaymentMethodAppliesNowhereException extends InvalidArgumentException
{
    /**
     * Builds the exception for a method assigned no currency.
     *
     * @param string $code The code of the method being built
     *
     * @return self
     */
    public static function withoutCurrencies(string $code): self
    {
        return new self(sprintf('Payment method "%s" must be assigned at least one currency.', $code));
    }

    /**
     * Builds the exception for a method assigned no country.
     *
     * @param string $code The code of the method being built
     *
     * @return self
     */
    public static function withoutCountries(string $code): self
    {
        return new self(sprintf('Payment method "%s" must be assigned at least one country.', $code));
    }
}
