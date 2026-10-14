<?php

namespace Logeecom\PaymentMethods\Domain\Checkout;

use Logeecom\PaymentMethods\Domain\PaymentMethodType;

/**
 * The Factory Method for payment flows. This is the only class that names RedirectFlow and IframeFlow;
 * everything else asks for a PaymentFlowInterface and gets whichever one the method's type calls for, decided
 * at run time from data that came out of the form.
 *
 * A third type is one new class implementing PaymentFlowInterface, one case in PaymentMethodType and one arm
 * in the match below. Nothing in the service, the controller or the screens changes: they read the label and
 * the description off whatever this factory hands back. Leaving the arm out is not a silent bug: match throws
 * UnhandledMatchError the first time that type is asked for.
 */
final class PaymentFlowFactory
{
    /**
     * The flow that belongs to this type.
     *
     * @param PaymentMethodType $type The type taken from the payment method
     *
     * @return PaymentFlowInterface The one flow that knows what this type means
     */
    public function for(PaymentMethodType $type): PaymentFlowInterface
    {
        return match ($type) {
            PaymentMethodType::Redirect => new RedirectFlow(),
            PaymentMethodType::IFrame => new IframeFlow(),
        };
    }
}
