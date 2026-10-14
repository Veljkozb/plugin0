<?php

namespace Tests\Support;

use Logeecom\PaymentMethods\Repository\ReferenceDataRepositoryInterface;

/**
 * The currency and country catalogues as two fixed arrays, for tests that must not touch a database.
 *
 * It holds the same codes the seeders write, so a test that passes against it passes against the real
 * catalogue too. The application itself reads the catalogues from the database; this exists only so a unit
 * test of the service does not depend on a seeded database.
 */
final class InMemoryReferenceData implements ReferenceDataRepositoryInterface
{
    /**
     * @var list<string>
     */
    private const CURRENCIES = [
        'AUD', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HUF', 'JPY', 'NOK', 'PLN', 'RSD', 'SEK', 'USD',
    ];

    /**
     * @var list<string>
     */
    private const COUNTRIES = [
        'AT', 'BE', 'CH', 'CZ', 'DE', 'DK', 'ES', 'FR', 'GB', 'HU', 'IT', 'NL', 'NO', 'PL', 'RS', 'SE', 'US',
    ];

    /**
     * @return list<string>
     */
    public function currencies(): array
    {
        return self::CURRENCIES;
    }

    /**
     * @return list<string>
     */
    public function countries(): array
    {
        return self::COUNTRIES;
    }
}
