<?php

namespace Logeecom\PaymentMethods\Domain;

/**
 * What the list is narrowed to: a search term and up to four filters, all optional. Every condition that is
 * set narrows the result further (AND); none replaces another.
 *
 * It carries values that validation has already accepted. Whether a currency or a country exists is not
 * checked here: that is the Form Request's job, before this object is built.
 */
final class PaymentMethodFilter
{
    /**
     * @param string|null $search Part of a name or a code, matched case-insensitively and taken literally
     * @param string|null $currency An ISO 4217 code the method must be assigned
     * @param string|null $country An ISO 3166-1 alpha-2 code the method must be assigned
     * @param PaymentMethodType|null $type The type the method must have
     * @param bool|null $isActive true for active methods only, false for inactive only, null for both
     */
    public function __construct(
        public readonly ?string $search = null,
        public readonly ?string $currency = null,
        public readonly ?string $country = null,
        public readonly ?PaymentMethodType $type = null,
        public readonly ?bool $isActive = null,
    ) {
    }

    /**
     * Builds the filter from the validated query string. A missing parameter, an empty search and the status
     * `all` each mean that condition is not applied.
     *
     * @param array<string, mixed> $query The validated parameters: search, currency, country, type, status
     *
     * @return self
     */
    public static function fromArray(array $query): self
    {
        $search = trim((string) ($query['search'] ?? ''));

        return new self(
            search: $search === '' ? null : $search,
            currency: $query['currency'] ?? null,
            country: $query['country'] ?? null,
            type: isset($query['type']) ? PaymentMethodType::from($query['type']) : null,
            isActive: match ($query['status'] ?? 'all') {
                'active' => true,
                'inactive' => false,
                default => null,
            },
        );
    }

    /**
     * Whether no condition is set, so the filter matches every stored method.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->search === null
            && $this->currency === null
            && $this->country === null
            && $this->type === null
            && $this->isActive === null;
    }
}
