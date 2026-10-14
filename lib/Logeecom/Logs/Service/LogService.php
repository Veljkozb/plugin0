<?php

namespace Logeecom\Logs\Service;

use Logeecom\Logs\Domain\LogPage;
use Logeecom\Logs\Domain\LogRecord;
use Logeecom\Logs\Repository\LogEntryRepositoryInterface;
use Logeecom\Settings\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * The Logs screen (Requirement 5.1): the stored entries, paged by the Rows per page setting and narrowed by
 * level, and the two entries that record who looked at them (ASVS 8.3.5).
 *
 * Opening the screen and leaving it each write one INFO entry, and the two share a view id, so the time spent
 * reading the logs can be told from the entries alone. Paging and filtering write nothing: they are the same
 * visit (*decided by Veljko*).
 */
final class LogService
{
    /**
     * The levels the screen filters by: the four of Requirement 4.1, and critical, which the application
     * writes when the database is unreachable.
     *
     * @var list<string>
     */
    public const LEVELS = ['debug', 'info', 'warning', 'error', 'critical'];

    /**
     * @param LogEntryRepositoryInterface $repository Where the entries are read
     * @param SettingsService $settings The page size (Rows per page, Requirement 4.1)
     * @param LoggerInterface $logger Where opening and leaving the screen are recorded
     */
    public function __construct(
        private readonly LogEntryRepositoryInterface $repository,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * One page of the entries, newest first.
     *
     * @param array<string, mixed> $query The validated query string: level and page, both optional
     *
     * @return LogPage
     */
    public function page(array $query): LogPage
    {
        return $this->repository->page(
            $query['level'] ?? null,
            (int) ($query['page'] ?? 1),
            $this->settings->rowsPerPage(),
        );
    }

    /**
     * A page as the screen reads it: the entries under `data`, the position under `meta`. The time is ISO 8601
     * in UTC, and the screen shows it in the browser's own timezone.
     *
     * @param LogPage $page The page to present
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int|null>}
     */
    public function present(LogPage $page): array
    {
        return [
            'data' => array_map(static fn (LogRecord $record): array => [
                'id' => $record->id,
                'created_at' => $record->createdAt->format('Y-m-d\TH:i:s.up'),
                'level' => $record->level,
                'user' => $record->userEmail,
                'message' => $record->message,
            ], $page->items),
            'meta' => [
                'page' => $page->page,
                'per_page' => $page->perPage,
                'total' => $page->total,
                'from' => $page->from(),
                'to' => $page->to(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    /**
     * The screen was opened: one INFO entry naming the user and the view id the screen will close with.
     *
     * @param int $userId The signed-in administrator's id
     * @param string $viewId A new v4 UUID for this visit
     */
    public function opened(int $userId, string $viewId): void
    {
        $this->logger->info('Logs screen opened', ['user_id' => $userId, 'view_id' => $viewId]);
    }

    /**
     * The screen was left: one INFO entry with the view id it was opened with.
     *
     * @param int $userId The signed-in administrator's id
     * @param string $viewId The view id the screen was opened with
     */
    public function left(int $userId, string $viewId): void
    {
        $this->logger->info('Logs screen left', ['user_id' => $userId, 'view_id' => $viewId]);
    }
}
