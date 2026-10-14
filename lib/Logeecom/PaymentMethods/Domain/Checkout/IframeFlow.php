<?php

namespace Logeecom\PaymentMethods\Domain\Checkout;

/**
 * The iframe type: the provider's page is embedded in the shop's own page and the customer never leaves it.
 */
final class IframeFlow implements PaymentFlowInterface
{
    /**
     * The name the administrator reads.
     *
     * @return string
     */
    public function label(): string
    {
        return 'iFrame';
    }

    /**
     * What happens to the customer.
     *
     * @return string
     */
    public function description(): string
    {
        return 'The provider\'s page is embedded in the shop\'s own page; the customer never leaves it.';
    }
}
