<?php

namespace Tests\Support;

use Closure;
use Logeecom\PaymentMethods\Domain\PaymentMethod;
use Logeecom\PaymentMethods\Domain\PaymentMethodFilter;
use Logeecom\PaymentMethods\Domain\PaymentMethodPage;
use Logeecom\PaymentMethods\Repository\PaymentMethodRepositoryInterface;
use Throwable;

/**
 * A repository whose storage is broken: every read is answered by the repository it wraps, and every write
 * throws what it was given. It stands in for a database that failed in the middle of a write, for the tests
 * of the ERROR entry and the 500 the service and the handler answer with.
 */
final class FailingPaymentMethodRepository implements PaymentMethodRepositoryInterface
{
    /**
     * @param PaymentMethodRepositoryInterface $inner Answers the reads
     * @param Throwable $failure What every write throws
     */
    public function __construct(
        private readonly PaymentMethodRepositoryInterface $inner,
        private readonly Throwable $failure,
    ) {
    }

    /**
     * @return list<PaymentMethod>
     */
    public function all(): array
    {
        return $this->inner->all();
    }

    /**
     * @param PaymentMethodFilter $filter The conditions every method on the page meets
     * @param int $page The page number, from 1
     * @param int $perPage How many methods a full page holds
     *
     * @return PaymentMethodPage
     */
    public function page(PaymentMethodFilter $filter, int $page, int $perPage): PaymentMethodPage
    {
        return $this->inner->page($filter, $page, $perPage);
    }

    /**
     * @param int $id The id to look up
     *
     * @return PaymentMethod|null The method, or null when nothing is stored under that id
     */
    public function find(int $id): ?PaymentMethod
    {
        return $this->inner->find($id);
    }

    /**
     * @param string $code The code to look up
     *
     * @return PaymentMethod|null The method, or null when no method carries that code
     */
    public function findByCode(string $code): ?PaymentMethod
    {
        return $this->inner->findByCode($code);
    }

    /**
     * @param PaymentMethod $method Ignored
     *
     * @return PaymentMethod Never returns
     *
     * @throws Throwable Always
     */
    public function add(PaymentMethod $method): PaymentMethod
    {
        throw $this->failure;
    }

    /**
     * @param PaymentMethod $method Ignored
     *
     * @return PaymentMethod Never returns
     *
     * @throws Throwable Always
     */
    public function update(PaymentMethod $method): PaymentMethod
    {
        throw $this->failure;
    }

    /**
     * @param int $id Ignored
     *
     * @throws Throwable Always
     */
    public function delete(int $id): void
    {
        throw $this->failure;
    }

    /**
     * Runs the writes, each of which throws.
     *
     * @template T
     *
     * @param Closure(): T $writes The writes
     *
     * @return T Never returns
     */
    public function transaction(Closure $writes): mixed
    {
        return $writes();
    }
}
