<?php

namespace Logeecom\Logs\Domain;

use InvalidArgumentException;

/**
 * One page of the stored entries, newest first: the entries on it, how many match in all, and where the page
 * sits. A page past the last one is a valid page with no items.
 */
final class LogPage
{
    /**
     * @param list<LogRecord> $items The entries on this page, newest first
     * @param int $total How many entries match the level filter across all pages
     * @param int $page The page number, from 1
     * @param int $perPage How many entries a full page holds
     *
     * @throws InvalidArgumentException if the page, the page size or the total is out of range
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
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
     * @return int|null The position of the first entry on this page, from 1; null when the page has none
     */
    public function from(): ?int
    {
        return $this->items === [] ? null : ($this->page - 1) * $this->perPage + 1;
    }

    /**
     * @return int|null The position of the last entry on this page; null when the page has none
     */
    public function to(): ?int
    {
        return $this->items === [] ? null : ($this->page - 1) * $this->perPage + count($this->items);
    }

    /**
     * @return int The last page that has entries; 1 when nothing matches
     */
    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
