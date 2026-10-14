<?php

namespace Logeecom\PaymentMethods\Repository;

/**
 * The currency and country codes a payment method is allowed to be assigned.
 *
 * Read only on purpose: this is a catalogue, not application data. It is the fixed set of options the form
 * offers and the set validation checks a submitted code against, so it must not change while the application
 * runs and must not depend on which payment methods happen to exist at the moment.
 */
interface ReferenceDataRepositoryInterface
{
    /**
     * @return list<string> ISO 4217 codes, e.g. EUR
     */
    public function currencies(): array;

    /**
     * @return list<string> ISO 3166-1 alpha-2 codes, e.g. RS
     */
    public function countries(): array;
}
