<?php

namespace Logeecom\PaymentMethods\Repository;

use Closure;
use Logeecom\PaymentMethods\Domain\Exception\PaymentMethodAlreadyExistsException;
use Logeecom\PaymentMethods\Domain\Exception\PaymentMethodNotFoundException;
use Logeecom\PaymentMethods\Domain\PaymentMethod;
use Logeecom\PaymentMethods\Domain\PaymentMethodFilter;
use Logeecom\PaymentMethods\Domain\PaymentMethodPage;

/**
 * The only way into the stored payment methods.
 *
 * Everything above it - the service, the controller, the screens - depends on this interface and never on
 * where the data actually is, which is what makes moving from the array in the code to a database a change
 * to one binding.
 */
interface PaymentMethodRepositoryInterface
{
    /**
     * Every stored method, in no promised order.
     *
     * @return list<PaymentMethod>
     */
    public function all(): array;

    /**
     * One page of the methods that match the filter, ordered by sort order and then by id, so the same data
     * always pages the same way. The order never comes from input.
     *
     * @param PaymentMethodFilter $filter The conditions every method on the page meets
     * @param int $page The page number, from 1; a page past the last one has no items
     * @param int $perPage How many methods a full page holds
     *
     * @return PaymentMethodPage The page, the number of matches and whether the table is empty
     */
    public function page(PaymentMethodFilter $filter, int $page, int $perPage): PaymentMethodPage;

    /**
     * Finds one method by its id.
     *
     * @param int $id The id to look up
     *
     * @return PaymentMethod|null The method, or null when nothing is stored under that id
     */
    public function find(int $id): ?PaymentMethod;

    /**
     * Finds one method by its code.
     *
     * @param string $code The code to look up
     *
     * @return PaymentMethod|null The method, or null when no method carries that code
     */
    public function findByCode(string $code): ?PaymentMethod;

    /**
     * Stores a method that does not exist yet and returns it with the id the repository assigned.
     *
     * The method must not carry an id: adding and changing are separate intentions, and one method that
     * guessed between them would turn a mistaken id into a silent overwrite.
     *
     * @param PaymentMethod $method The method to store, without an id
     *
     * @return PaymentMethod The stored method, carrying the id the repository assigned
     *
     * @throws PaymentMethodAlreadyExistsException if the method carries an id
     */
    public function add(PaymentMethod $method): PaymentMethod;

    /**
     * Replaces the stored method with the same id.
     *
     * @param PaymentMethod $method The method to store in place of the one with the same id
     *
     * @return PaymentMethod The stored method
     *
     * @throws PaymentMethodNotFoundException if the method has no id, or no method is stored under it
     */
    public function update(PaymentMethod $method): PaymentMethod;

    /**
     * Removes the method with this id, and does nothing when there is none.
     *
     * @param int $id The id to remove
     */
    public function delete(int $id): void;

    /**
     * Runs several writes as one: either all of them are stored or none is. A save that moves other methods out
     * of its way is such a write. The writes inside may run transactions of their own; they nest.
     *
     * @template T
     *
     * @param Closure(): T $writes The writes
     *
     * @return T Whatever the writes returned
     */
    public function transaction(Closure $writes): mixed;
}
