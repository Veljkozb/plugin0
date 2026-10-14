<?php

namespace Logeecom\PaymentMethods\Domain;

use InvalidArgumentException;

/**
 * One page of the filtered list: the methods on it, how many match in all, and where the page sits.
 *
 * It also says whether the whole table is empty, which is not the same as nothing matching: the screen
 * explains an empty catalogue and offers to add a method, while a filter that matches nothing offers to
 * clear the filters. A page past the last one is a valid page with no items, not an error.
 */
final class PaymentMethodPage
{
    /**
     * @param list<PaymentMethod> $items The methods on this page, in list order
     * @param int $total How many methods match the filter across all pages
     * @param int $page The page number, from 1
     * @param int $perPage How many methods a full page holds
     * @param bool $tableEmpty Whether no method is stored at all, whatever the filter
     *
     * @throws InvalidArgumentException if the page, the page size or the total is out of range
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly bool $tableEmpty,
    ) {
        if ($page < 1 || $perPage < 1 || $total < 0) {
            throw new InvalidArgumentException(sprintf(
                'A page needs a page and a page size of at least 1 and a total of at least 0, got %d, %d and %d.',
                $page,
                $perPage,
                $total,
            ));
        }
    }

    /**
     * The position of the first method on this page among all that match, from 1.
     *
     * @return int|null null when the page has no items
     */
    public function from(): ?int
    {
        return $this->items === [] ? null : ($this->page - 1) * $this->perPage + 1;
    }

    /**
     * The position of the last method on this page among all that match.
     *
     * @return int|null null when the page has no items
     */
    public function to(): ?int
    {
        return $this->items === [] ? null : ($this->page - 1) * $this->perPage + count($this->items);
    }

    /**
     * The number of the last page that has items; 1 when nothing matches, so there is always a first page.
     *
     * @return int
     */
    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
