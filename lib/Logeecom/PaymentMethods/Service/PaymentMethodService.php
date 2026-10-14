<?php

namespace Logeecom\PaymentMethods\Service;

use Closure;
use Logeecom\PaymentMethods\Domain\Checkout\PaymentFlowFactory;
use Logeecom\PaymentMethods\Domain\Exception\DuplicatePaymentMethodCodeException;
use Logeecom\PaymentMethods\Domain\Exception\PaymentMethodAlreadyExistsException;
use Logeecom\PaymentMethods\Domain\Exception\PaymentMethodNotFoundException;
use Logeecom\PaymentMethods\Domain\Exception\PaymentMethodWriteFailedException;
use Logeecom\PaymentMethods\Domain\PaymentMethod;
use Logeecom\PaymentMethods\Domain\PaymentMethodFilter;
use Logeecom\PaymentMethods\Domain\PaymentMethodPage;
use Logeecom\PaymentMethods\Domain\PaymentMethodType;
use Logeecom\PaymentMethods\Repository\PaymentMethodRepositoryInterface;
use Logeecom\PaymentMethods\Repository\ReferenceDataRepositoryInterface;
use Logeecom\Settings\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The business rules for payment methods: the order they are offered in, the uniqueness of a code, the
 * separation between switching a method off and deleting it, and what each type means — resolved through the
 * factory, so the screens never decide that for themselves. Nothing here knows about HTTP or about where the
 * methods are stored.
 *
 * Every write takes the id of the administrator who asked for it and leaves one INFO entry naming the user, the
 * method and what changed (Requirement 5.1). A write the storage could not complete stores nothing, leaves one
 * ERROR entry instead and is rethrown as PaymentMethodWriteFailedException.
 */
final class PaymentMethodService
{
    /**
     * @param PaymentMethodRepositoryInterface $repository Where the methods are stored
     * @param ReferenceDataRepositoryInterface $referenceData The allowed currency and country codes
     * @param PaymentFlowFactory $flows Which class of behaviour each type stands for
     * @param LoggerInterface $logger Where every write, completed or failed, is recorded
     * @param SettingsService $settings The page size of the list (Rows per page, Requirement 4.1)
     */
    public function __construct(
        private readonly PaymentMethodRepositoryInterface $repository,
        private readonly ReferenceDataRepositoryInterface $referenceData,
        private readonly PaymentFlowFactory $flows,
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * The codes a payment method may be assigned. They come from the catalogue and not from the methods
     * that happen to exist, so deleting the only method that uses SEK does not remove SEK from the offer.
     *
     * @return list<string>
     */
    public function allowedCurrencies(): array
    {
        return $this->referenceData->currencies();
    }

    /**
     * @return list<string>
     */
    public function allowedCountries(): array
    {
        return $this->referenceData->countries();
    }

    /**
     * The currency the create form selects before the administrator picks one: the Default currency setting
     * (Requirement 4.1).
     *
     * @return string|null null when the catalogue holds no currency to default to
     */
    public function defaultCurrency(): ?string
    {
        return $this->settings->defaultCurrency();
    }

    /**
     * The types a method can have, each with what it means, in the order the enum declares them. The form
     * draws its radio buttons from this list, so a third type reaches the screen with no change to it.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    public function types(): array
    {
        return array_map(
            fn (PaymentMethodType $type): array => [
                'value' => $type->value,
                'label' => $this->flows->for($type)->label(),
                'description' => $this->flows->for($type)->description(),
            ],
            PaymentMethodType::cases(),
        );
    }

    /**
     * The method as the screens read it: its own fields, plus what its type means. The label and the
     * description come from the flow the factory picks for the type, so the frontend prints them and never
     * maps a type value to a word of its own.
     *
     * @param PaymentMethod $method The method to present
     *
     * @return array<string, mixed>
     */
    public function present(PaymentMethod $method): array
    {
        $flow = $this->flows->for($method->type);

        return $method->toArray() + [
            'type_label' => $flow->label(),
            'type_description' => $flow->description(),
        ];
    }

    /**
     * present() over a whole list, in the order it was given.
     *
     * @param list<PaymentMethod> $methods The methods to present
     *
     * @return list<array<string, mixed>>
     */
    public function presentAll(array $methods): array
    {
        return array_map(fn (PaymentMethod $method): array => $this->present($method), $methods);
    }

    /**
     * A page as the list screen reads it: the methods under `data`, presented one by one, and under `meta`
     * where the page sits and whether the table is empty. `from` and `to` are null on a page with no items.
     *
     * @param PaymentMethodPage $page The page to present
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int|bool|null>}
     */
    public function presentPage(PaymentMethodPage $page): array
    {
        return [
            'data' => $this->presentAll($page->items),
            'meta' => [
                'page' => $page->page,
                'per_page' => $page->perPage,
                'total' => $page->total,
                'from' => $page->from(),
                'to' => $page->to(),
                'last_page' => $page->lastPage(),
                'table_empty' => $page->tableEmpty,
            ],
        ];
    }

    /**
     * One page of the list, narrowed by the search and the filters. The page size is the Rows per page
     * setting, read on every call, so a change on the Settings screen applies to the next request.
     *
     * @param array<string, mixed> $query The validated query string: search, currency, country, type, status
     *                                    and page, each optional
     *
     * @return PaymentMethodPage
     */
    public function page(array $query): PaymentMethodPage
    {
        return $this->repository->page(
            PaymentMethodFilter::fromArray($query),
            (int) ($query['page'] ?? 1),
            $this->settings->rowsPerPage(),
        );
    }

    /**
     * @return list<PaymentMethod> in the order methods are presented to a customer
     */
    public function list(): array
    {
        $methods = $this->repository->all();

        usort($methods, static fn (PaymentMethod $a, PaymentMethod $b): int => $a->sortOrder <=> $b->sortOrder);

        return $methods;
    }

    /**
     * One method by its id.
     *
     * @param int $id The id to look up
     *
     * @return PaymentMethod
     *
     * @throws PaymentMethodNotFoundException if nothing is stored under that id
     */
    public function get(int $id): PaymentMethod
    {
        return $this->repository->find($id) ?? throw PaymentMethodNotFoundException::withId($id);
    }

    /**
     * The row must not carry an id; the repository assigns one. An id that slips in used to be dropped here
     * without a word, which meant a caller could ask to write method 3 and be told it had succeeded while a
     * different method was created.
     *
     * @param array<string, mixed> $data a row without an id
     * @param int $userId The administrator who asked for it; the entry's user
     *
     * @return PaymentMethod The stored method
     *
     * @throws PaymentMethodAlreadyExistsException if the row carries an id
     * @throws DuplicatePaymentMethodCodeException if another method holds the code
     * @throws PaymentMethodWriteFailedException if the storage failed; the ERROR entry is already written
     */
    public function create(array $data, int $userId): PaymentMethod
    {
        $method = PaymentMethod::fromArray($data);

        $this->guardCodeIsUnique($method->code, null);

        [$created, $moved] = $this->write('create', null, $userId, function () use ($method): array {
            $created = $this->repository->add($method);

            return [$created, $this->makeRoom($created)];
        });

        $this->logger->info('Payment method created', [
            'user_id' => $userId,
            'id' => $created->id,
            'code' => $created->code,
            'moved' => $moved,
        ]);

        return $created;
    }

    /**
     * Replaces the stored method with what the form holds. The entry names the fields whose value changed
     * and nothing of the values themselves; the stored method is read first so that there is something to
     * compare the saved one with.
     *
     * @param int $id The method's id
     * @param array<string, mixed> $data The row to store in its place
     * @param int $userId The administrator who asked for it; the entry's user
     *
     * @return PaymentMethod The stored method
     *
     * @throws PaymentMethodNotFoundException if nothing is stored under that id
     * @throws DuplicatePaymentMethodCodeException if another method holds the code
     * @throws PaymentMethodWriteFailedException if the storage failed; the ERROR entry is already written
     */
    public function update(int $id, array $data, int $userId): PaymentMethod
    {
        $stored = $this->get($id);

        $method = PaymentMethod::fromArray(array_merge($data, ['id' => $id]));

        $this->guardCodeIsUnique($method->code, $id);

        [$updated, $moved] = $this->write('update', $id, $userId, function () use ($method): array {
            $updated = $this->repository->update($method);

            return [$updated, $this->makeRoom($updated)];
        });

        $this->logger->info('Payment method updated', [
            'user_id' => $userId,
            'id' => $id,
            'changed' => $this->changedFields($stored, $updated),
            'moved' => $moved,
        ]);

        return $updated;
    }

    /**
     * Switching a method off never removes it; see delete() for the other path.
     *
     * @param int $id The method's id
     * @param bool $isActive Whether the method is offered to customers from now on
     * @param int $userId The administrator who asked for it; the entry's user
     *
     * @return PaymentMethod The stored method
     *
     * @throws PaymentMethodNotFoundException if nothing is stored under that id
     * @throws PaymentMethodWriteFailedException if the storage failed; the ERROR entry is already written
     */
    public function setActive(int $id, bool $isActive, int $userId): PaymentMethod
    {
        $stored = $this->get($id);

        $updated = $this->write(
            'toggle',
            $id,
            $userId,
            fn (): PaymentMethod => $this->repository->update($stored->withActive($isActive)),
        );

        $this->logger->info('Payment method toggled', [
            'user_id' => $userId,
            'id' => $id,
            'is_active' => ['from' => $stored->isActive, 'to' => $isActive],
        ]);

        return $updated;
    }

    /**
     * Removes the method and, with it, its currency and country assignments. The entry carries enough to
     * rebuild what disappeared.
     *
     * @param int $id The method's id
     * @param int $userId The administrator who asked for it; the entry's user
     *
     * @throws PaymentMethodNotFoundException if nothing is stored under that id
     * @throws PaymentMethodWriteFailedException if the storage failed; the ERROR entry is already written
     */
    public function delete(int $id, int $userId): void
    {
        $method = $this->get($id);

        $this->write('delete', $id, $userId, fn () => $this->repository->delete($id));

        $this->logger->info('Payment method deleted', [
            'user_id' => $userId,
            'id' => $method->id,
            'name' => $method->name,
            'code' => $method->code,
            'type' => $method->type->value,
            'sort_order' => $method->sortOrder,
            'currencies' => $method->currencies,
            'countries' => $method->countries,
        ]);
    }

    /**
     * @param int|null $exceptId the method being edited, which may keep its own code
     */
    public function isCodeUnique(string $code, ?int $exceptId = null): bool
    {
        $existing = $this->repository->findByCode($code);

        return $existing === null || $existing->id === $exceptId;
    }

    /**
     * Runs one write against the storage, as one transaction: a save and the methods makeRoom() moves out of
     * its way are stored together or not at all. A domain exception passes through as it is: it says the
     * caller asked for something that cannot be done, and the handler already knows how to answer it. Anything
     * else means the storage failed and nothing of the write is stored: one ERROR entry with the action, the
     * record, the user and the cause, and a domain exception in its place so that no layer above sees a
     * database message.
     *
     * @param string $action What is being done: create, update, toggle or delete
     * @param int|null $id The method's id, or null when it is being created
     * @param int $userId The administrator who asked for it
     * @param Closure(): mixed $write The write itself
     *
     * @return mixed Whatever the write returned
     *
     * @throws PaymentMethodWriteFailedException if the write threw anything but a domain exception
     */
    private function write(string $action, ?int $id, int $userId, Closure $write): mixed
    {
        try {
            return $this->repository->transaction($write);
        } catch (PaymentMethodAlreadyExistsException | PaymentMethodNotFoundException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error(sprintf('Payment method %s failed', $action), [
                'action' => $action,
                'id' => $id,
                'user_id' => $userId,
                'exception' => $e,
            ]);

            throw PaymentMethodWriteFailedException::during($action, $id, $e);
        }
    }

    /**
     * The names of the fields whose value differs between the two, for the edit entry. The two assignment
     * lists are compared as sets: the storage returns them in its own order.
     *
     * @param PaymentMethod $before The method as it was stored
     * @param PaymentMethod $after The method as it is stored now
     *
     * @return list<string>
     */
    private function changedFields(PaymentMethod $before, PaymentMethod $after): array
    {
        $changed = [];

        foreach ($before->toArray() as $field => $old) {
            $new = $after->toArray()[$field];

            if (is_array($old) && is_array($new)) {
                sort($old);
                sort($new);
            }

            if ($old !== $new) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * The form lets a method ask for a number another method already holds. The saved method keeps the
     * number it asked for, and the method that held it steps down by one; if that lands on the next
     * method's number, that one steps down too, for as long as the numbers keep meeting. The first free
     * number ends it, and nothing above the saved method is touched.
     *
     * @param PaymentMethod $saved The method that was just stored
     *
     * @return list<int> The ids of the methods that were moved, in the order they moved
     */
    private function makeRoom(PaymentMethod $saved): array
    {
        $taken = $saved->sortOrder;
        $moved = [];

        $below = array_filter(
            $this->list(),
            static fn (PaymentMethod $method): bool => $method->id !== $saved->id && $method->sortOrder >= $taken,
        );

        foreach ($below as $method) {
            if ($method->sortOrder > $taken) {
                // Its number is free, and the list is sorted, so every method after it is higher still.
                break;
            }

            $taken++;

            $this->repository->update($method->withSortOrder($taken));
            $moved[] = $method->id;
        }

        return $moved;
    }

    private function guardCodeIsUnique(string $code, ?int $exceptId): void
    {
        if (! $this->isCodeUnique($code, $exceptId)) {
            throw DuplicatePaymentMethodCodeException::code($code);
        }
    }
}
