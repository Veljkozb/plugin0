<?php

namespace Logeecom\PaymentMethods\Domain\Exception;

use RuntimeException;

/**
 * Thrown when a method that is being added already carries an id.
 *
 * Ids belong to the repository, not to the caller. Accepting one would mean a create silently overwriting
 * whatever is stored under that id, or claiming an id the repository never handed out.
 */
final class PaymentMethodAlreadyExistsException extends RuntimeException
{
    /**
     * Builds the exception for a method that arrived carrying an id.
     *
     * @param int $id The id the caller supplied
     *
     * @return self
     */
    public static function withId(int $id): self
    {
        return new self(
            sprintf(
                'A new payment method must not carry an id; %d was supplied and would overwrite '
                . 'whatever is stored under it.',
                $id
            )
        );
    }
}
