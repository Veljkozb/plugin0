<?php

namespace Logeecom\PaymentMethods\Domain\Checkout;

/**
 * The redirect type: the customer is sent to the provider's page and comes back when it is done.
 */
final class RedirectFlow implements PaymentFlowInterface
{
    /**
     * The name the administrator reads.
     *
     * @return string
     */
    public function label(): string
    {
        return 'Redirect';
    }

    /**
     * What happens to the customer.
     *
     * @return string
     */
    public function description(): string
    {
        return 'The customer is sent to the provider\'s page and returns to the shop when the payment is done.';
    }
}
