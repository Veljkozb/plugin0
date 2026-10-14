<?php

namespace Tests\Unit\PaymentMethods\Service;

use Logeecom\PaymentMethods\Domain\Checkout\PaymentFlowFactory;
use Logeecom\PaymentMethods\Domain\Exception\DuplicatePaymentMethodCodeException;
use Logeecom\PaymentMethods\Domain\Exception\PaymentMethodAlreadyExistsException;
use Logeecom\PaymentMethods\Domain\Exception\PaymentMethodNotFoundException;
use Logeecom\PaymentMethods\Domain\Exception\PaymentMethodWriteFailedException;
use Logeecom\PaymentMethods\Domain\PaymentMethod;
use Logeecom\PaymentMethods\Domain\PaymentMethodType;
use Logeecom\PaymentMethods\Repository\PaymentMethodRepositoryInterface;
use Logeecom\PaymentMethods\Service\PaymentMethodService;
use Logeecom\Settings\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Support\FailingPaymentMethodRepository;
use Tests\Support\InMemoryPaymentMethodRepository;
use Tests\Support\InMemoryReferenceData;
use Tests\Support\InMemorySettingsRepository;
use Tests\Support\NullLogLevelApplier;

/**
 * The business rules, with an in-memory repository standing in for storage: ordering, the uniqueness of a
 * code, the sort order rule, deactivation kept apart from deletion, and the entry each write leaves.
 */
final class PaymentMethodServiceTest extends TestCase
{
    /** The administrator every write here is done by. */
    private const USER = 7;

    private PaymentMethodRepositoryInterface $repository;

    private AbstractLogger $logger;

    /** Where the Rows per page setting the list pages by is stored. */
    private InMemorySettingsRepository $settings;

    public function test_list_is_sorted_by_sort_order(): void
    {
        $codes = array_map(static fn ($method) => $method->code, $this->service()->list());

        self::assertSame(['klarna', 'visa', 'paypal'], $codes);
    }

    public function test_get_throws_when_the_method_does_not_exist(): void
    {
        $this->expectException(PaymentMethodNotFoundException::class);

        $this->service()->get(999);
    }

    public function test_create_assigns_an_id_and_stores_the_method(): void
    {
        $created = $this->service()->create($this->row(null, 'apple_pay', 40), self::USER);

        self::assertSame(4, $created->id);
        self::assertCount(4, $this->service()->list());
    }

    /**
     * The scenario this guards: two methods are stored under ids 3 and 4, and a create arrives claiming id 3.
     * It must not land on the stored method, and it must not be silently turned into a different one either.
     */
    public function test_create_refuses_a_row_that_chooses_its_own_id(): void
    {
        $this->expectException(PaymentMethodAlreadyExistsException::class);

        $this->service()->create($this->row(1, 'apple_pay', 40), self::USER);
    }

    public function test_a_refused_create_leaves_the_stored_method_untouched(): void
    {
        $service = $this->service();

        try {
            $service->create($this->row(1, 'apple_pay', 40), self::USER);
        } catch (PaymentMethodAlreadyExistsException) {
        }

        self::assertSame('paypal', $service->get(1)->code);
        self::assertCount(3, $service->list());
    }

    public function test_create_rejects_a_code_that_is_already_taken(): void
    {
        $this->expectException(DuplicatePaymentMethodCodeException::class);

        $this->service()->create($this->row(null, 'paypal', 40), self::USER);
    }

    public function test_update_keeps_the_id_from_the_route_and_ignores_the_one_in_the_payload(): void
    {
        $updated = $this->service()->update(1, array_merge($this->row(2, 'paypal', 99), ['name' => 'PayPal Express']), self::USER);

        self::assertSame(1, $updated->id);
        self::assertSame('PayPal Express', $updated->name);
        self::assertSame(99, $updated->sortOrder);
        self::assertCount(3, $this->service()->list());
    }

    public function test_update_allows_a_method_to_keep_its_own_code(): void
    {
        $updated = $this->service()->update(1, $this->row(null, 'paypal', 15), self::USER);

        self::assertSame('paypal', $updated->code);
        self::assertSame(15, $updated->sortOrder);
    }

    public function test_update_rejects_a_code_taken_by_another_method(): void
    {
        $this->expectException(DuplicatePaymentMethodCodeException::class);

        $this->service()->update(1, $this->row(null, 'klarna', 30), self::USER);
    }

    /**
     * Criterion 19, the seed shape 10, 20, 30: a new method at 10 keeps 10, and klarna, which held it,
     * steps down by one.
     */
    public function test_create_on_a_taken_sort_order_pushes_the_holder_down_by_one(): void
    {
        $created = $this->service()->create($this->row(null, 'apple_pay', 10), self::USER);

        self::assertSame(10, $created->sortOrder);
        self::assertSame(['apple_pay', 'klarna', 'visa', 'paypal'], $this->codes($this->service()->list()));
        self::assertSame([10, 11, 20, 30], $this->sortOrders($this->service()->list()));
    }

    public function test_create_on_the_last_sort_order_pushes_the_last_method_down(): void
    {
        $this->service()->create($this->row(null, 'apple_pay', 30), self::USER);

        self::assertSame(['klarna', 'visa', 'apple_pay', 'paypal'], $this->codes($this->service()->list()));
        self::assertSame([10, 20, 30, 31], $this->sortOrders($this->service()->list()));
    }

    /**
     * 10, 11, 30: a new method at 10 pushes klarna to 11, which is visa's number, so visa steps down to 12.
     * Paypal's 30 is free, so the cascade stops there instead of renumbering the whole list.
     */
    public function test_create_on_a_taken_sort_order_cascades_while_the_numbers_keep_meeting(): void
    {
        $this->service()->update(3, $this->row(null, 'visa', 11), self::USER);

        $created = $this->service()->create($this->row(null, 'apple_pay', 10), self::USER);

        self::assertSame(10, $created->sortOrder);
        self::assertSame(['apple_pay', 'klarna', 'visa', 'paypal'], $this->codes($this->service()->list()));
        self::assertSame([10, 11, 12, 30], $this->sortOrders($this->service()->list()));
    }

    public function test_update_onto_another_methods_sort_order_pushes_that_method_down(): void
    {
        $updated = $this->service()->update(1, $this->row(null, 'paypal', 10), self::USER);

        self::assertSame(10, $updated->sortOrder);
        self::assertSame(['paypal', 'klarna', 'visa'], $this->codes($this->service()->list()));
        self::assertSame([10, 11, 20], $this->sortOrders($this->service()->list()));
    }

    public function test_update_that_keeps_its_own_sort_order_moves_nobody(): void
    {
        $this->service()->update(1, array_merge($this->row(null, 'paypal', 30), ['name' => 'PayPal Express']), self::USER);

        self::assertSame(['klarna', 'visa', 'paypal'], $this->codes($this->service()->list()));
        self::assertSame([10, 20, 30], $this->sortOrders($this->service()->list()));
    }

    public function test_set_active_switches_both_ways_and_never_removes_the_method(): void
    {
        $service = $this->service();

        self::assertFalse($service->setActive(1, false, self::USER)->isActive);
        self::assertTrue($service->setActive(1, true, self::USER)->isActive);
        self::assertCount(3, $service->list());
    }

    /**
     * Criterion 19, the delete entry: enough to rebuild what disappeared, and who asked for it.
     */
    public function test_delete_removes_the_method_and_writes_a_log_entry(): void
    {
        $service = $this->service();

        $service->delete(1, self::USER);

        self::assertCount(2, $service->list());
        self::assertSame([[LogLevel::INFO, 'Payment method deleted']], $this->entries());
        self::assertSame([
            'user_id' => self::USER,
            'id' => 1,
            'name' => 'Paypal',
            'code' => 'paypal',
            'type' => 'redirect',
            'sort_order' => 30,
            'currencies' => ['EUR'],
            'countries' => ['RS'],
        ], $this->logger->records[0]['context']);
    }

    /**
     * Criterion 19, the create entry: the user, the new id, and the methods makeRoom() moved. Apple Pay takes
     * 10, so klarna moves to 11 and visa, which held 11, to 12; paypal at 30 is not touched.
     */
    public function test_create_writes_one_entry_naming_the_user_the_id_and_the_moved_methods(): void
    {
        $this->service()->update(3, $this->row(null, 'visa', 11), self::USER);
        $this->logger->records = [];

        $this->service()->create($this->row(null, 'apple_pay', 10), self::USER);

        self::assertSame([[LogLevel::INFO, 'Payment method created']], $this->entries());
        self::assertSame(
            ['user_id' => self::USER, 'id' => 4, 'code' => 'apple_pay', 'moved' => [2, 3]],
            $this->logger->records[0]['context'],
        );
    }

    /**
     * Criterion 19, the edit entry: the names of the fields whose value changed, and none of the values. The
     * countries are given in another order and count as unchanged.
     */
    public function test_update_writes_one_entry_naming_only_the_changed_fields(): void
    {
        $this->repository->update(PaymentMethod::fromArray(array_merge($this->row(1, 'paypal', 30), [
            'countries' => ['RS', 'DE'],
        ])));

        $this->service()->update(1, array_merge($this->row(null, 'paypal', 30), [
            'name' => 'PayPal Express',
            'countries' => ['DE', 'RS'],
            'currencies' => ['EUR', 'USD'],
        ]), self::USER);

        self::assertSame([[LogLevel::INFO, 'Payment method updated']], $this->entries());
        self::assertSame(
            ['user_id' => self::USER, 'id' => 1, 'changed' => ['name', 'currencies'], 'moved' => []],
            $this->logger->records[0]['context'],
        );
        self::assertStringNotContainsString('PayPal Express', json_encode($this->logger->records));
    }

    /**
     * Criterion 19, the toggle entry: the old and the new state.
     */
    public function test_set_active_writes_one_entry_with_the_old_and_the_new_state(): void
    {
        $this->service()->setActive(1, false, self::USER);

        self::assertSame([[LogLevel::INFO, 'Payment method toggled']], $this->entries());
        self::assertSame(
            ['user_id' => self::USER, 'id' => 1, 'is_active' => ['from' => true, 'to' => false]],
            $this->logger->records[0]['context'],
        );
    }

    /**
     * Criterion 22: the storage throws in the middle of a write. One ERROR entry with the action, the record,
     * the user and the cause, no INFO entry, and a domain exception that carries the cause as previous and
     * none of its message.
     */
    public function test_a_failed_write_writes_one_error_entry_and_throws_a_domain_exception(): void
    {
        $cause = new RuntimeException('SQLSTATE[HY000]: General error: 1 no such table');
        $this->repository = new FailingPaymentMethodRepository($this->repository, $cause);

        try {
            $this->service()->update(1, $this->row(null, 'paypal', 30), self::USER);
            self::fail('The write should have failed.');
        } catch (PaymentMethodWriteFailedException $e) {
            self::assertSame($cause, $e->getPrevious());
            self::assertStringNotContainsString('SQLSTATE', $e->getMessage());
        }

        self::assertSame([[LogLevel::ERROR, 'Payment method update failed']], $this->entries());
        self::assertSame(
            ['action' => 'update', 'id' => 1, 'user_id' => self::USER, 'exception' => $cause],
            $this->logger->records[0]['context'],
        );
    }

    /**
     * The same for a create, where there is no id yet, and for a delete.
     */
    public function test_a_failed_create_and_a_failed_delete_are_logged_with_their_action(): void
    {
        $this->repository = new FailingPaymentMethodRepository($this->repository, new RuntimeException('boom'));

        foreach (['create' => fn () => $this->service()->create($this->row(null, 'apple_pay', 40), self::USER),
            'delete' => fn () => $this->service()->delete(1, self::USER)] as $action => $write) {
            try {
                $write();
                self::fail(sprintf('The %s should have failed.', $action));
            } catch (PaymentMethodWriteFailedException) {
            }
        }

        self::assertSame(
            [[LogLevel::ERROR, 'Payment method create failed'], [LogLevel::ERROR, 'Payment method delete failed']],
            $this->entries(),
        );
        self::assertNull($this->logger->records[0]['context']['id']);
        self::assertSame(1, $this->logger->records[1]['context']['id']);
    }

    public function test_delete_throws_when_the_method_does_not_exist(): void
    {
        $this->expectException(PaymentMethodNotFoundException::class);

        $this->service()->delete(999, self::USER);
    }

    public function test_is_code_unique_ignores_the_method_being_edited(): void
    {
        $service = $this->service();

        self::assertFalse($service->isCodeUnique('paypal'));
        self::assertTrue($service->isCodeUnique('paypal', 1));
        self::assertTrue($service->isCodeUnique('apple_pay'));
    }

    /**
     * The words for a type come out of the factory and travel with the method, so the screens print them
     * instead of mapping the value to a label of their own.
     */
    public function test_present_adds_what_the_type_means_to_the_methods_own_fields(): void
    {
        $service = $this->service();

        $presented = $service->present($service->get(1));

        self::assertSame('paypal', $presented['code']);
        self::assertSame('redirect', $presented['type']);
        self::assertSame('Redirect', $presented['type_label']);
        self::assertNotSame('', $presented['type_description']);
    }

    /**
     * The list the form draws its radio buttons from: one entry per enum case, in the enum's order, each
     * with the label the factory's flow gives it. A third case shows up here with no change to the form.
     */
    public function test_types_lists_every_enum_case_with_its_label_and_description(): void
    {
        $types = $this->service()->types();

        self::assertSame(
            array_map(static fn (PaymentMethodType $type): string => $type->value, PaymentMethodType::cases()),
            array_column($types, 'value'),
        );
        self::assertSame(['Redirect', 'iFrame'], array_column($types, 'label'));

        foreach ($types as $type) {
            self::assertSame(['value', 'label', 'description'], array_keys($type));
            self::assertNotSame('', $type['description']);
        }
    }

    /**
     * The page size is the Rows per page setting and nothing else: seven methods at 5 per page are a full
     * first page and a second page of two, in sort order and then by id where two numbers meet. Mastercard is
     * stored before visa, so only the id puts visa first.
     */
    public function test_page_takes_its_size_from_the_rows_per_page_setting(): void
    {
        $this->repository = new InMemoryPaymentMethodRepository([
            $this->row(1, 'paypal', 30),
            $this->row(2, 'klarna', 10),
            $this->row(4, 'mastercard', 20),
            $this->row(3, 'visa', 20),
            $this->row(5, 'ideal', 40),
            $this->row(6, 'sofort', 50),
            $this->row(7, 'giropay', 60),
        ]);
        $this->settings->put(['rows_per_page' => '5']);

        $first = $this->service()->page([]);
        $second = $this->service()->page(['page' => '2']);

        self::assertSame(['klarna', 'visa', 'mastercard', 'paypal', 'ideal'], $this->codes($first->items));
        self::assertSame(['sofort', 'giropay'], $this->codes($second->items));
        self::assertSame([7, 5, 2], [$second->total, $second->perPage, $second->page]);
        self::assertFalse($second->tableEmpty);
    }

    public function test_page_applies_every_filter_together(): void
    {
        $this->repository = new InMemoryPaymentMethodRepository([
            ['type' => 'iframe', 'is_active' => false] + $this->row(1, 'paypal', 30),
            ['currencies' => ['RSD']] + $this->row(2, 'klarna', 10),
            $this->row(3, 'visa', 20),
            ['countries' => ['DE']] + $this->row(4, 'ideal', 40),
        ]);

        $page = $this->service()->page([
            'currency' => 'EUR',
            'country' => 'RS',
            'type' => 'redirect',
            'status' => 'active',
        ]);

        self::assertSame(['visa'], $this->codes($page->items));
        self::assertSame(['paypal'], $this->codes($this->service()->page(['search' => 'PAY'])->items));
        self::assertSame(['paypal'], $this->codes($this->service()->page(['status' => 'inactive'])->items));
    }

    public function test_page_tells_an_empty_table_from_a_filter_that_matches_nothing(): void
    {
        $filtered = $this->service()->page(['search' => 'nothing like this']);

        self::assertSame([], $filtered->items);
        self::assertFalse($filtered->tableEmpty);

        $this->repository = new InMemoryPaymentMethodRepository();

        self::assertTrue($this->service()->page([])->tableEmpty);
    }

    public function test_present_page_puts_the_methods_under_data_and_the_position_under_meta(): void
    {
        $presented = $this->service()->presentPage($this->service()->page([]));

        self::assertSame(['klarna', 'visa', 'paypal'], array_column($presented['data'], 'code'));
        self::assertSame('Redirect', $presented['data'][0]['type_label']);
        self::assertSame([
            'page' => 1,
            'per_page' => 10,
            'total' => 3,
            'from' => 1,
            'to' => 3,
            'last_page' => 1,
            'table_empty' => false,
        ], $presented['meta']);
    }

    protected function setUp(): void
    {
        $this->repository = new InMemoryPaymentMethodRepository([
            $this->row(1, 'paypal', 30),
            $this->row(2, 'klarna', 10),
            $this->row(3, 'visa', 20),
        ]);

        $this->logger = new class () extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $this->settings = new InMemorySettingsRepository();
    }

    private function service(): PaymentMethodService
    {
        return new PaymentMethodService(
            $this->repository,
            new InMemoryReferenceData(),
            new PaymentFlowFactory(),
            $this->logger,
            new SettingsService(
                $this->settings,
                new InMemoryReferenceData(),
                new NullLogger(),
                new NullLogLevelApplier(),
                false,
            ),
        );
    }

    /**
     * The level and the message of every entry written so far, in order.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function entries(): array
    {
        return array_map(
            static fn (array $record): array => [$record['level'], $record['message']],
            $this->logger->records,
        );
    }

    /**
     * @param list<PaymentMethod> $methods
     * @return list<string>
     */
    private function codes(array $methods): array
    {
        return array_map(static fn ($method) => $method->code, $methods);
    }

    /**
     * @param list<PaymentMethod> $methods
     * @return list<int>
     */
    private function sortOrders(array $methods): array
    {
        return array_map(static fn ($method) => $method->sortOrder, $methods);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(?int $id, string $code, int $sortOrder): array
    {
        return [
            'id' => $id,
            'name' => ucfirst($code),
            'code' => $code,
            'type' => 'redirect',
            'currencies' => ['EUR'],
            'countries' => ['RS'],
            'is_active' => true,
            'sort_order' => $sortOrder,
        ];
    }
}
