<?php

namespace Tests\Unit\PaymentMethods\Domain;

use InvalidArgumentException;
use Logeecom\PaymentMethods\Domain\PaymentMethod;
use Logeecom\PaymentMethods\Domain\PaymentMethodPage;
use Logeecom\PaymentMethods\Domain\PaymentMethodType;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic behind "Showing 11–20 of 25": where a page starts and ends among all matches, and how many
 * pages there are.
 */
final class PaymentMethodPageTest extends TestCase
{
    public function test_a_middle_page_runs_from_its_first_to_its_last_position(): void
    {
        $page = new PaymentMethodPage($this->methods(10), 25, 2, 10, false);

        self::assertSame(11, $page->from());
        self::assertSame(20, $page->to());
        self::assertSame(3, $page->lastPage());
    }

    public function test_the_last_page_ends_at_the_total(): void
    {
        $page = new PaymentMethodPage($this->methods(5), 25, 3, 10, false);

        self::assertSame(21, $page->from());
        self::assertSame(25, $page->to());
    }

    /**
     * A page past the last one, and a filter that matches nothing, have no positions; there is still a
     * first page to go back to.
     */
    public function test_a_page_without_items_has_no_positions_and_one_page(): void
    {
        $past = new PaymentMethodPage([], 25, 7, 10, false);
        $none = new PaymentMethodPage([], 0, 1, 10, true);

        self::assertNull($past->from());
        self::assertNull($past->to());
        self::assertSame(3, $past->lastPage());
        self::assertSame(1, $none->lastPage());
    }

    public function test_a_page_below_one_cannot_be_built(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PaymentMethodPage([], 0, 0, 10, true);
    }

    public function test_a_page_size_below_one_cannot_be_built(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PaymentMethodPage([], 0, 1, 0, true);
    }

    /**
     * @return list<PaymentMethod>
     */
    private function methods(int $count): array
    {
        return array_map(
            static fn (int $id): PaymentMethod => new PaymentMethod(
                id: $id,
                name: sprintf('Method %d', $id),
                code: sprintf('method_%d', $id),
                type: PaymentMethodType::Redirect,
                currencies: ['EUR'],
                countries: ['RS'],
            ),
            range(1, $count),
        );
    }
}
