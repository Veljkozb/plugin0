<?php

namespace Logeecom\PaymentMethods\Domain\Checkout;

/**
 * The product of PaymentFlowFactory: what one type of payment method means. The service works against this
 * interface only and never learns which class it was handed, and the screens print whatever it answers, so
 * a new type is described here and nowhere in the frontend.
 *
 * Starting a payment is not part of this ticket — checkout is Phase 2 — so the interface says only what a
 * type is, not what it does at checkout. That method joins it when there is a caller for it.
 */
interface PaymentFlowInterface
{
    /**
     * The name the administrator reads in the list and next to the radio button.
     *
     * @return string
     */
    public function label(): string;

    /**
     * One sentence on what happens to the customer who pays with a method of this type.
     *
     * @return string
     */
    public function description(): string;
}
