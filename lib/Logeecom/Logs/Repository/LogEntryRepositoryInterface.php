<?php

namespace Logeecom\Logs\Repository;

use Logeecom\Logs\Domain\LogPage;

/**
 * The stored entries, read only. There is no way to change or remove an entry through this interface, which is
 * what keeps the Logs screen from being one (Requirement 5.1, ASVS 7.3.3); removing old entries is
 * `model:prune`'s job alone.
 */
interface LogEntryRepositoryInterface
{
    /**
     * One page of the entries, newest first, with the e-mail of the user each one names.
     *
     * @param string|null $level Only entries of this PSR-3 level; null for every level
     * @param int $page The page number, from 1; a page past the last one has no items
     * @param int $perPage How many entries a full page holds
     *
     * @return LogPage
     */
    public function page(?string $level, int $page, int $perPage): LogPage;
}
