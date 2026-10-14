<?php

namespace Logeecom\PaymentMethods\Domain\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown when the storage failed while a method was being created, changed, toggled or deleted.
 *
 * The service writes the ERROR entry with the cause before it throws this, so the handler renders it as a
 * generic 500 and does not report it: one failure, one entry. The cause travels as the previous exception and
 * its message is not repeated here, because a database message carries SQL and its bindings.
 */
final class PaymentMethodWriteFailedException extends RuntimeException
{
    /**
     * Builds the exception for a write that did not complete.
     *
     * @param string $action What was being done: create, update, toggle or delete
     * @param int|null $id The method's id, or null when it was being created
     * @param Throwable $cause What the storage threw
     *
     * @return self
     */
    public static function during(string $action, ?int $id, Throwable $cause): self
    {
        return new self(
            sprintf(
                'The payment method %s did not complete%s.',
                $action,
                $id === null ? '' : sprintf(' for method %d', $id),
            ),
            0,
            $cause,
        );
    }
}
