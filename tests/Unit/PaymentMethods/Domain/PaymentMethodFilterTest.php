<?php

namespace Tests\Unit\PaymentMethods\Domain;

use Logeecom\PaymentMethods\Domain\PaymentMethodFilter;
use Logeecom\PaymentMethods\Domain\PaymentMethodType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The query string as the repository reads it: which parameter sets which condition, and which values mean
 * that no condition is applied.
 */
final class PaymentMethodFilterTest extends TestCase
{
    /**
     * @return array<string, array{string|null, bool|null}>
     */
    public static function statuses(): array
    {
        return [
            'active' => ['active', true],
            'inactive' => ['inactive', false],
            'all' => ['all', null],
            'missing' => [null, null],
        ];
    }

    public function test_every_parameter_becomes_its_condition(): void
    {
        $filter = PaymentMethodFilter::fromArray([
            'search' => 'pay',
            'currency' => 'EUR',
            'country' => 'RS',
            'type' => 'iframe',
            'status' => 'active',
            'page' => '2',
        ]);

        self::assertSame('pay', $filter->search);
        self::assertSame('EUR', $filter->currency);
        self::assertSame('RS', $filter->country);
        self::assertSame(PaymentMethodType::IFrame, $filter->type);
        self::assertTrue($filter->isActive);
        self::assertFalse($filter->isEmpty());
    }

    #[DataProvider('statuses')]
    public function test_the_status_maps_to_active_inactive_or_both(?string $status, ?bool $isActive): void
    {
        self::assertSame($isActive, PaymentMethodFilter::fromArray(['status' => $status])->isActive);
    }

    /**
     * No parameters, parameters sent empty, a search of spaces and the status `all` all match every method.
     */
    public function test_missing_and_empty_parameters_apply_no_condition(): void
    {
        self::assertTrue(PaymentMethodFilter::fromArray([])->isEmpty());

        $empty = PaymentMethodFilter::fromArray([
            'search' => '   ',
            'currency' => null,
            'country' => null,
            'type' => null,
            'status' => 'all',
        ]);

        self::assertNull($empty->search);
        self::assertTrue($empty->isEmpty());
    }

    public function test_the_search_term_is_trimmed_and_otherwise_kept_as_typed(): void
    {
        self::assertSame('50% off_', PaymentMethodFilter::fromArray(['search' => ' 50% off_ '])->search);
    }
}
