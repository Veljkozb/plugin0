<?php

namespace Logeecom\PaymentMethods\Domain;

/**
 * How a payment with this method is carried out. The value is what the form sends and what is stored.
 *
 * The set is closed by how the application behaves, not by configuration: a new case needs a flow class to
 * go with it, which is why the enum itself is the validation rule.
 */
enum PaymentMethodType: string
{
    case Redirect = 'redirect';
    case IFrame = 'iframe';
}
