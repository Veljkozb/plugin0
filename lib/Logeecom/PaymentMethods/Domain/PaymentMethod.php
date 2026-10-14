<?php

namespace Logeecom\PaymentMethods\Domain;

use Logeecom\PaymentMethods\Domain\Exception\PaymentMethodAppliesNowhereException;

/**
 * One payment method as the shop offers it: what it is called, how it behaves, where and in what currency it
 * may be used, and where it sits in the list.
 *
 * Every property is readonly. A change is a new object built by one of the with* methods, so a method held
 * by a caller can never drift away from what is stored.
 *
 * The constructor refuses a method that applies nowhere (Requirement 3.2), which makes the record the domain
 * forbids impossible to represent rather than merely rejected on the way in: validation, the repositories and
 * the seeders all build this object, and none of them can get around it.
 */
final class PaymentMethod
{
    /**
     * The highest sort order the form accepts (ASVS 5.4.3). Far above any real catalogue, and far enough below the
     * INT column's 2147483647 that makeRoom() stepping methods down by one can never run past it.
     */
    public const MAX_SORT_ORDER = 1000000;

    /**
     * @param list<string> $currencies ISO 4217 codes, e.g. ['EUR', 'RSD']; at least one
     * @param list<string> $countries ISO 3166-1 alpha-2 codes, e.g. ['RS', 'DE']; at least one
     *
     * @throws PaymentMethodAppliesNowhereException if either list is empty
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $code,
        public readonly PaymentMethodType $type,
        public readonly array $currencies,
        public readonly array $countries,
        public readonly bool $isActive = false,
        public readonly int $sortOrder = 0,
    ) {
        if ($currencies === []) {
            throw PaymentMethodAppliesNowhereException::withoutCurrencies($code);
        }

        if ($countries === []) {
            throw PaymentMethodAppliesNowhereException::withoutCountries($code);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: $row['id'] ?? null,
            name: $row['name'],
            code: $row['code'],
            type: PaymentMethodType::from($row['type']),
            currencies: array_values($row['currencies'] ?? []),
            countries: array_values($row['countries'] ?? []),
            isActive: $row['is_active'] ?? false,
            sortOrder: $row['sort_order'] ?? 0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type->value,
            'currencies' => $this->currencies,
            'countries' => $this->countries,
            'is_active' => $this->isActive,
            'sort_order' => $this->sortOrder,
        ];
    }

    /**
     * The same method with the id the repository assigned.
     *
     * @param int $id The assigned id
     *
     * @return self A new instance; this one is unchanged
     */
    public function withId(int $id): self
    {
        return new self(
            id: $id,
            name: $this->name,
            code: $this->code,
            type: $this->type,
            currencies: $this->currencies,
            countries: $this->countries,
            isActive: $this->isActive,
            sortOrder: $this->sortOrder,
        );
    }

    /**
     * The same method switched on or off.
     *
     * @param bool $isActive Whether the method is offered to customers
     *
     * @return self A new instance; this one is unchanged
     */
    public function withActive(bool $isActive): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            code: $this->code,
            type: $this->type,
            currencies: $this->currencies,
            countries: $this->countries,
            isActive: $isActive,
            sortOrder: $this->sortOrder,
        );
    }

    /**
     * The same method at another place in the list.
     *
     * @param int $sortOrder The new sort order
     *
     * @return self A new instance; this one is unchanged
     */
    public function withSortOrder(int $sortOrder): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            code: $this->code,
            type: $this->type,
            currencies: $this->currencies,
            countries: $this->countries,
            isActive: $this->isActive,
            sortOrder: $sortOrder,
        );
    }
}
