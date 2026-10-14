<?php

namespace Tests\Support;

use Closure;
use Logeecom\PaymentMethods\Domain\Exception\PaymentMethodAlreadyExistsException;
use Logeecom\PaymentMethods\Domain\Exception\PaymentMethodNotFoundException;
use Logeecom\PaymentMethods\Domain\PaymentMethod;
use Logeecom\PaymentMethods\Domain\PaymentMethodFilter;
use Logeecom\PaymentMethods\Domain\PaymentMethodPage;
use Logeecom\PaymentMethods\Repository\PaymentMethodRepositoryInterface;
use Throwable;

/**
 * The payment methods in a plain array, for tests that are about the service and not about storage.
 *
 * It lives in tests/ and not in the application: the application runs on one implementation only, the
 * Eloquent one. A unit test of the service needs something that fulfils the interface without a database,
 * and this is that something - it behaves exactly as the interface promises, nothing more.
 *
 * Rows are stored as arrays rather than objects on purpose: a caller that holds a PaymentMethod then cannot
 * change what is stored without storing it back, which is how the database behaves too.
 */
final class InMemoryPaymentMethodRepository implements PaymentMethodRepositoryInterface
{
    /** @var list<array<string, mixed>> */
    private array $rows;

    /**
     * @param list<array<string, mixed>> $rows The methods this repository starts with
     */
    public function __construct(array $rows = [])
    {
        $this->rows = array_values($rows);
    }

    /**
     * @return list<PaymentMethod>
     */
    public function all(): array
    {
        return array_map(
            static fn (array $row): PaymentMethod => PaymentMethod::fromArray($row),
            $this->rows
        );
    }

    /**
     * The same answer the Eloquent repository gives, worked out over the array: the search is a literal,
     * case-insensitive substring of the name or the code, and every other condition is an exact match.
     *
     * @param PaymentMethodFilter $filter The conditions every method on the page meets
     * @param int $page The page number, from 1
     * @param int $perPage How many methods a full page holds
     *
     * @return PaymentMethodPage
     */
    public function page(PaymentMethodFilter $filter, int $page, int $perPage): PaymentMethodPage
    {
        $matches = array_values(array_filter(
            $this->all(),
            static fn (PaymentMethod $method): bool => self::matches($method, $filter),
        ));

        usort(
            $matches,
            static fn (PaymentMethod $a, PaymentMethod $b): int => [$a->sortOrder, $a->id] <=> [$b->sortOrder, $b->id],
        );

        return new PaymentMethodPage(
            array_slice($matches, ($page - 1) * $perPage, $perPage),
            count($matches),
            $page,
            $perPage,
            $this->rows === [],
        );
    }

    /**
     * @param int $id The id to look up
     *
     * @return PaymentMethod|null The method, or null when nothing is stored under that id
     */
    public function find(int $id): ?PaymentMethod
    {
        foreach ($this->rows as $row) {
            if ($row['id'] === $id) {
                return PaymentMethod::fromArray($row);
            }
        }

        return null;
    }

    /**
     * @param string $code The code to look up
     *
     * @return PaymentMethod|null The method, or null when no method carries that code
     */
    public function findByCode(string $code): ?PaymentMethod
    {
        foreach ($this->rows as $row) {
            if ($row['code'] === $code) {
                return PaymentMethod::fromArray($row);
            }
        }

        return null;
    }

    /**
     * @param PaymentMethod $method The method to store, without an id
     *
     * @return PaymentMethod The stored method, carrying the id this repository assigned
     *
     * @throws PaymentMethodAlreadyExistsException if the method carries an id
     */
    public function add(PaymentMethod $method): PaymentMethod
    {
        if ($method->id !== null) {
            throw PaymentMethodAlreadyExistsException::withId($method->id);
        }

        $added = $method->withId($this->nextId());

        $this->rows[] = $added->toArray();

        return $added;
    }

    /**
     * @param PaymentMethod $method The method to store in place of the one with the same id
     *
     * @return PaymentMethod The stored method
     *
     * @throws PaymentMethodNotFoundException if the method has no id, or no method is stored under it
     */
    public function update(PaymentMethod $method): PaymentMethod
    {
        if ($method->id === null) {
            throw PaymentMethodNotFoundException::withoutId();
        }

        foreach ($this->rows as $index => $row) {
            if ($row['id'] === $method->id) {
                $this->rows[$index] = $method->toArray();

                return $method;
            }
        }

        throw PaymentMethodNotFoundException::withId($method->id);
    }

    /**
     * @param int $id The id to remove
     */
    public function delete(int $id): void
    {
        $this->rows = array_values(
            array_filter($this->rows, static fn (array $row): bool => $row['id'] !== $id)
        );
    }

    /**
     * Keeps a copy of the rows and puts it back when the writes throw, the way a rolled-back transaction leaves
     * the tables.
     *
     * @template T
     *
     * @param Closure(): T $writes The writes
     *
     * @return T Whatever the writes returned
     */
    public function transaction(Closure $writes): mixed
    {
        $rows = $this->rows;

        try {
            return $writes();
        } catch (Throwable $e) {
            $this->rows = $rows;

            throw $e;
        }
    }

    /**
     * Whether the method meets every condition the filter sets.
     *
     * @param PaymentMethod $method The method to check
     * @param PaymentMethodFilter $filter The conditions
     *
     * @return bool
     */
    private static function matches(PaymentMethod $method, PaymentMethodFilter $filter): bool
    {
        $search = $filter->search;

        return ($search === null
                || mb_stripos($method->name, $search) !== false
                || mb_stripos($method->code, $search) !== false)
            && ($filter->currency === null || in_array($filter->currency, $method->currencies, true))
            && ($filter->country === null || in_array($filter->country, $method->countries, true))
            && ($filter->type === null || $method->type === $filter->type)
            && ($filter->isActive === null || $method->isActive === $filter->isActive);
    }

    /**
     * The id the next added method gets: one above the highest stored, or 1 when nothing is stored.
     *
     * @return int The next free id
     */
    private function nextId(): int
    {
        $ids = array_column($this->rows, 'id');

        return $ids === [] ? 1 : max($ids) + 1;
    }
}
