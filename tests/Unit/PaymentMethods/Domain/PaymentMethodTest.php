<?php

namespace Tests\Unit\PaymentMethods\Domain;

use Logeecom\PaymentMethods\Domain\Exception\PaymentMethodAppliesNowhereException;
use Logeecom\PaymentMethods\Domain\PaymentMethod;
use Logeecom\PaymentMethods\Domain\PaymentMethodType;
use PHPUnit\Framework\TestCase;

/**
 * The domain object: the array round trip, that every with* method answers with a new instance and leaves
 * the old one alone, and that a method which applies nowhere cannot be built at all.
 */
final class PaymentMethodTest extends TestCase
{
    public function test_from_array_and_to_array_round_trip(): void
    {
        $data = [
            'id' => 1,
            'name' => 'Visa',
            'code' => 'visa',
            'type' => 'redirect',
            'currencies' => ['EUR', 'RSD'],
            'countries' => ['RS', 'DE'],
            'is_active' => true,
            'sort_order' => 10,
        ];

        $paymentMethod = PaymentMethod::fromArray($data);

        self::assertSame($data, $paymentMethod->toArray());
    }

    public function test_with_active_does_not_mutate_original(): void
    {
        $paymentMethod = new PaymentMethod(
            id: 1,
            name: 'Visa',
            code: 'visa',
            type: PaymentMethodType::Redirect,
            currencies: ['EUR'],
            countries: ['RS'],
            isActive: true,
            sortOrder: 10,
        );

        $inactivePaymentMethod = $paymentMethod->withActive(false);

        self::assertTrue($paymentMethod->isActive);
        self::assertFalse($inactivePaymentMethod->isActive);
    }

    public function test_with_id_does_not_mutate_original(): void
    {
        $paymentMethod = new PaymentMethod(
            id: null,
            name: 'Visa',
            code: 'visa',
            type: PaymentMethodType::Redirect,
            currencies: ['EUR'],
            countries: ['RS'],
        );

        $paymentMethodWithId = $paymentMethod->withId(5);

        self::assertNull($paymentMethod->id);
        self::assertSame(5, $paymentMethodWithId->id);
    }

    /**
     * Requirement 3.2: a method that applies nowhere cannot be saved. The object refuses to exist, so no
     * caller - a repository, a seeder, a test - can produce one by taking a shortcut around validation.
     */
    public function test_a_method_without_a_currency_cannot_be_built(): void
    {
        $this->expectException(PaymentMethodAppliesNowhereException::class);
        $this->expectExceptionMessage('Payment method "visa" must be assigned at least one currency.');

        new PaymentMethod(
            id: null,
            name: 'Visa',
            code: 'visa',
            type: PaymentMethodType::Redirect,
            currencies: [],
            countries: ['RS'],
        );
    }

    public function test_a_method_without_a_country_cannot_be_built(): void
    {
        $this->expectException(PaymentMethodAppliesNowhereException::class);
        $this->expectExceptionMessage('Payment method "visa" must be assigned at least one country.');

        new PaymentMethod(
            id: null,
            name: 'Visa',
            code: 'visa',
            type: PaymentMethodType::Redirect,
            currencies: ['EUR'],
            countries: [],
        );
    }

    /**
     * The same rule reached through fromArray(), which is the way the service builds a method from a
     * submitted form: a row with no assignments is refused by the constructor behind it.
     */
    public function test_from_array_refuses_a_row_that_applies_nowhere(): void
    {
        $this->expectException(PaymentMethodAppliesNowhereException::class);

        PaymentMethod::fromArray([
            'name' => 'Visa',
            'code' => 'visa',
            'type' => 'redirect',
            'currencies' => [],
            'countries' => [],
        ]);
    }
}
