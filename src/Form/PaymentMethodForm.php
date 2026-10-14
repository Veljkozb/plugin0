<?php

namespace Plugin0\Form;

use Logeecom\PaymentMethods\Domain\PaymentMethod;
use Logeecom\PaymentMethods\Domain\PaymentMethodType;
use Logeecom\PaymentMethods\Service\PaymentMethodService;

/**
 * Forma jedne metode plaćanja (dodavanje i izmena): vrednosti polja kakve forma prikazuje i pravila koja se
 * proveravaju pre nego što išta stigne do servisa. Pravila i poruke su iz demo Form Request-a
 * (app/Http/Requests/Admin/AbstractPaymentMethodRequest.php i app/Rules/UniquePaymentMethodCode.php): ime i kod
 * obavezni i ograničene dužine, kod jedinstven, tip jedan od dva, bar jedna valuta i bar jedna zemlja, svaka iz
 * kataloga i samo jednom, sort order ceo broj od 1 do PaymentMethod::MAX_SORT_ORDER, aktivna da ili ne.
 *
 * Ovo je prezentacioni sloj: ništa ovde ne zna gde se metode čuvaju; da li je kod slobodan pita se servis. Polje
 * koje forma ne šalje (na primer id) se ne gleda: id izmene je u adresi, a nove metode id dodeljuje skladište.
 */
final class PaymentMethodForm
{
    public const MAX_NAME_LENGTH = 255;
    public const MAX_CODE_LENGTH = 64;

    /** @var array{name: string, code: string, type: string, currencies: string[], countries: string[], sort_order: string, is_active: bool} */
    private array $values;

    /** @param array{name: string, code: string, type: string, currencies: string[], countries: string[], sort_order: string, is_active: bool} $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * Prazna forma kao demo EMPTY: prvi tip koji servis nudi, Default currency sa Settings stranice već izabrana
     * (nijedna kad podešavanje nema vrednost), sort order 1 (nova metoda ide na vrh), aktivna.
     */
    public static function empty(PaymentMethodService $service): self
    {
        $types = $service->types();
        $defaultCurrency = $service->defaultCurrency();

        return new self([
            'name' => '',
            'code' => '',
            'type' => $types[0]['value'] ?? '',
            'currencies' => $defaultCurrency !== null ? [$defaultCurrency] : [],
            'countries' => [],
            'sort_order' => '1',
            'is_active' => true,
        ]);
    }

    public static function fromMethod(PaymentMethod $method): self
    {
        return new self([
            'name' => $method->name,
            'code' => $method->code,
            'type' => $method->type->value,
            'currencies' => $method->currencies,
            'countries' => $method->countries,
            'sort_order' => (string) $method->sortOrder,
            'is_active' => $method->isActive,
        ]);
    }

    /**
     * Iz POST-a, očišćeno: tekst bez belina na krajevima, liste samo od nepraznih skalara, prekidač "1" ili ništa.
     *
     * @param array<string, mixed> $post
     */
    public static function fromSubmitted(array $post): self
    {
        return new self([
            'name' => self::text($post['name'] ?? null),
            'code' => self::text($post['code'] ?? null),
            'type' => self::text($post['type'] ?? null),
            'currencies' => self::codes($post['currencies'] ?? null),
            'countries' => self::codes($post['countries'] ?? null),
            'sort_order' => self::text($post['sort_order'] ?? null),
            'is_active' => self::text($post['is_active'] ?? null) === '1',
        ]);
    }

    /** @return array{name: string, code: string, type: string, currencies: string[], countries: string[], sort_order: string, is_active: bool} */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * @param int|null $editedId metoda koja se menja i sme da zadrži svoj kod; null pri dodavanju
     *
     * @return array<string, string> poruka po polju; prazno kad su sva pravila prošla
     */
    public function validate(PaymentMethodService $service, ?int $editedId): array
    {
        $errors = [];
        $values = $this->values;

        if ($values['name'] === '') {
            $errors['name'] = 'The name field is required.';
        } elseif (mb_strlen($values['name']) > self::MAX_NAME_LENGTH) {
            $errors['name'] = sprintf('The name field must not be greater than %d characters.', self::MAX_NAME_LENGTH);
        }

        if ($values['code'] === '') {
            $errors['code'] = 'The code field is required.';
        } elseif (mb_strlen($values['code']) > self::MAX_CODE_LENGTH) {
            $errors['code'] = sprintf('The code field must not be greater than %d characters.', self::MAX_CODE_LENGTH);
        } elseif (!$service->isCodeUnique($values['code'], $editedId)) {
            $errors['code'] = sprintf("Payment method code '%s' is already taken.", $values['code']);
        }

        if ($values['type'] === '') {
            $errors['type'] = 'The type field is required.';
        } elseif (PaymentMethodType::tryFrom($values['type']) === null) {
            $errors['type'] = 'The selected type is invalid.';
        }

        $errors += self::listErrors('currencies', 'currency', $values['currencies'], $service->allowedCurrencies());
        $errors += self::listErrors('countries', 'country', $values['countries'], $service->allowedCountries());

        $sortOrder = $values['sort_order'];
        if ($sortOrder === '') {
            $errors['sort_order'] = 'The sort order field is required.';
        } elseif (preg_match('/^-?\d+$/', $sortOrder) !== 1) {
            $errors['sort_order'] = 'The sort order field must be an integer.';
        } elseif ((int) $sortOrder < 1) {
            $errors['sort_order'] = 'The sort order must be 1 or higher.';
        } elseif ((int) $sortOrder > PaymentMethod::MAX_SORT_ORDER) {
            $errors['sort_order'] = sprintf('The sort order must be %d or lower.', PaymentMethod::MAX_SORT_ORDER);
        }

        return $errors;
    }

    /**
     * Red kakav servis prima (PaymentMethod::fromArray): bez id-ja, sort order kao broj. Zove se tek kad validate()
     * ne vrati nijednu poruku.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [
            'name' => $this->values['name'],
            'code' => $this->values['code'],
            'type' => $this->values['type'],
            'currencies' => $this->values['currencies'],
            'countries' => $this->values['countries'],
            'sort_order' => (int) $this->values['sort_order'],
            'is_active' => $this->values['is_active'],
        ];
    }

    /**
     * Pravila liste kodova iz dema: bar jedan, svaki iz kataloga, nijedan dva puta; prva prekršena daje poruku.
     *
     * @param string[] $codes
     * @param string[] $allowed
     *
     * @return array<string, string>
     */
    private static function listErrors(string $field, string $word, array $codes, array $allowed): array
    {
        if ($codes === []) {
            return [$field => sprintf('Select at least one %s.', $word)];
        }

        foreach ($codes as $code) {
            if (!in_array($code, $allowed, true)) {
                return [$field => sprintf('The %s %s is not one of the allowed codes.', $word, $code)];
            }
        }

        $duplicates = array_diff_assoc($codes, array_unique($codes));
        if ($duplicates !== []) {
            return [$field => sprintf('The %s %s is selected more than once.', $word, reset($duplicates))];
        }

        return [];
    }

    /** @param mixed $value */
    private static function text($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param mixed $value
     *
     * @return string[]
     */
    private static function codes($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $codes = [];
        foreach ($value as $item) {
            $code = self::text($item);
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
