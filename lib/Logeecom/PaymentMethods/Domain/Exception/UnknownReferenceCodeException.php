<?php

namespace Logeecom\PaymentMethods\Domain\Exception;

use RuntimeException;

/**
 * Thrown when a payment method is assigned a currency or a country code the catalogue does not hold.
 *
 * Validation rejects such a code before it ever reaches storage, but a repository must not depend on that:
 * silently keeping the codes it recognises would store a method assigned to nothing, which is exactly the
 * record the domain forbids.
 */
final class UnknownReferenceCodeException extends RuntimeException
{
    /**
     * Builds the exception for currency codes that are not in the catalogue.
     *
     * @param list<string> $codes The codes that were not found
     *
     * @return self
     */
    public static function forCurrencies(array $codes): self
    {
        return new self(sprintf('Unknown currency code(s): %s.', implode(', ', $codes)));
    }

    /**
     * Builds the exception for country codes that are not in the catalogue.
     *
     * @param list<string> $codes The codes that were not found
     *
     * @return self
     */
    public static function forCountries(array $codes): self
    {
        return new self(sprintf('Unknown country code(s): %s.', implode(', ', $codes)));
    }
}
