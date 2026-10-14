<?php

namespace Plugin0\PaymentMethods;

use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\PaymentAPI\Request\PaymentMethodRequest;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\Services\Payment\PaymentService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\PaymentAction;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\Cards\AuthorizationMode;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\Cards\FlowType;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\Descriptor;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\Intersolve\PaymentProductId as IntersolveProductId;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\Intersolve\SessionTimeout;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\Sepa\RecurrenceType;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\Sepa\SignatureType;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\ThreeDSSettings\ExemptionType;

/**
 * Forma jedne metode plaćanja: prevodi Core odgovor (PaymentMethodResponse::toArray) u vrednosti polja, uneto iz
 * forme natrag u Core zahtev (PaymentMethodRequest sa 22 parametra), i proverava unos pre nego što ode u Core.
 * Koja polja postoje za koji proizvod kaže PaymentMethodCatalog::sections().
 *
 * Zemlje i valute (countries, currencies) nisu deo Core zahteva: to je ograničenje koje plugin0 čuva sam
 * (PaymentMethodRestrictionRepository), pa ih daje restriction(), a ne toRequest(). Prazna lista = bez ograničenja.
 *
 * Ime i naziv sačuvane kartice su po jeziku prodavnice (kod jezika => tekst). Prvi jezik je podrazumevani jezik
 * prodavnice i on postaje Core-ov default prevod, pa je obavezan. Ostali smeju ostati prazni: Core ih pri čuvanju
 * popuni engleskim default imenom (PaymentService::savePaymentMethod).
 */
final class PaymentMethodForm
{
    public const PAYMENT_ACTIONS = [
        PaymentAction::AUTHORIZE_CAPTURE => 'Authorize and capture (SALE)',
        PaymentAction::AUTHORIZE => 'Authorize only (FINAL_AUTHORIZATION)',
    ];

    /** Kao tip u demo formi: radio dugmad sa opisom šta se dešava kupcu. */
    public const FLOW_TYPES = [
        FlowType::IFRAME => [
            'label' => 'iFrame',
            'description' => 'Card fields are embedded in the checkout page (Hosted Tokenization); the customer never leaves the shop.',
        ],
        FlowType::REDIRECT => [
            'label' => 'Redirect',
            'description' => 'The customer is sent to the Worldline Hosted Checkout page and returns when the payment is done.',
        ],
    ];

    public const AUTHORIZATION_MODES = [
        AuthorizationMode::FINAL_AUTHORIZATION => 'Final authorization',
        AuthorizationMode::PRE_AUTHORIZATION => 'Pre-authorization',
    ];

    public const EXEMPTION_TYPES = [
        ExemptionType::LOW_VALUE => 'Low value',
        ExemptionType::TRANSACTION_RISK_ANALYSIS => 'Transaction risk analysis',
    ];

    public const RECURRENCE_TYPES = [
        RecurrenceType::UNIQUE => 'Unique',
        RecurrenceType::RECURRING => 'Recurring',
    ];

    public const SIGNATURE_TYPES = [
        SignatureType::SMS => 'SMS',
        SignatureType::UNSIGNED => 'Unsigned',
        SignatureType::TICK_BOX => 'Tick box',
    ];

    /** Core defaulti kad podatak nije sačuvan: ThreeDSSettings (30,00 EUR), Intersolve (180 min, proizvod 5700). */
    private const DEFAULT_EXEMPTION_LIMIT = '30.00';
    private const DEFAULT_SESSION_TIMEOUT = '180';
    private const DEFAULT_INTERSOLVE_PRODUCT_ID = '5700';

    /** @var array<string, mixed> vrednosti polja, ključevi kao imena polja u šablonu */
    private array $values;

    /** @param array<string, mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * Polja iz Core odgovora za jednu metodu; za nesačuvan podatak važi Core default.
     *
     * @param array<string, mixed> $method PaymentMethodResponse::toArray()
     * @param array<string, string> $languages kod => naziv, podrazumevani jezik prvi
     * @param array{countries: string[], currencies: string[]} $restriction sačuvano ograničenje metode
     */
    public static function fromCore(array $method, array $languages, array $restriction): self
    {
        $additional = is_array($method['additionalData'] ?? null) ? $method['additionalData'] : [];

        return new self([
            'name' => self::translations((array) ($method['name'] ?? []), $languages),
            'enabled' => (bool) ($method['enabled'] ?? false),
            'template' => (string) ($method['template'] ?? ''),
            'payment_action' => (string) ($method['paymentAction'] ?? ''),
            'vault_titles' => self::translations(
                (array) ($additional['vaultTitleCollection'] ?? []),
                $languages,
                PaymentService::CREDIT_CARD_VAULT_TITLES
            ),
            'flow_type' => (string) ($additional['flowType'] ?? FlowType::IFRAME),
            'authorization_mode' => (string) ($additional['authorizationMode'] ?? AuthorizationMode::FINAL_AUTHORIZATION),
            'enable_group_cards' => (bool) ($additional['enableGroupCards'] ?? true),
            'enable_3ds' => (bool) ($additional['enable3ds'] ?? true),
            'enforce_strong_authentication' => (bool) ($additional['enforceStrongAuthentication'] ?? false),
            'enable_3ds_exemption' => (bool) ($additional['enable3dsExemption'] ?? false),
            'exemption_type' => (string) ($additional['exemptionType'] ?? ExemptionType::LOW_VALUE),
            'exemption_limit' => isset($additional['exemptionLimit'])
                ? number_format((float) $additional['exemptionLimit'], 2, '.', '')
                : self::DEFAULT_EXEMPTION_LIMIT,
            'logo' => (string) ($additional['logo'] ?? ''),
            'instant_payment' => (bool) ($additional['instantPayment'] ?? false),
            'session_timeout' => (string) ($additional['sessionTimeout'] ?? self::DEFAULT_SESSION_TIMEOUT),
            'intersolve_product_id' => (string) ($additional['paymentProductId'] ?? self::DEFAULT_INTERSOLVE_PRODUCT_ID),
            'payment_option' => (string) ($additional['paymentOption'] ?? ''),
            'recurrence_type' => (string) ($additional['recurrenceType'] ?? RecurrenceType::UNIQUE),
            'signature_type' => (string) ($additional['signatureType'] ?? SignatureType::SMS),
            'descriptor' => (string) ($additional['descriptor'] ?? ''),
            'countries' => $restriction['countries'],
            'currencies' => $restriction['currencies'],
        ]);
    }

    /**
     * Polja iz poslate forme, bez ikakve provere: validate() kaže šta ne valja, a šablon vraća uneto.
     *
     * @param array<string, mixed> $input Request::request->all()
     * @param array<string, string> $languages kod => naziv
     */
    public static function fromSubmitted(array $input, array $languages): self
    {
        return new self([
            'name' => self::textsByLanguage($input['name'] ?? [], $languages),
            'enabled' => self::flag($input, 'enabled'),
            'template' => self::text($input, 'template'),
            'payment_action' => self::text($input, 'payment_action'),
            'vault_titles' => self::textsByLanguage($input['vault_titles'] ?? [], $languages),
            'flow_type' => self::text($input, 'flow_type'),
            'authorization_mode' => self::text($input, 'authorization_mode'),
            'enable_group_cards' => self::flag($input, 'enable_group_cards'),
            'enable_3ds' => self::flag($input, 'enable_3ds'),
            'enforce_strong_authentication' => self::flag($input, 'enforce_strong_authentication'),
            'enable_3ds_exemption' => self::flag($input, 'enable_3ds_exemption'),
            'exemption_type' => self::text($input, 'exemption_type'),
            'exemption_limit' => self::text($input, 'exemption_limit'),
            'logo' => self::text($input, 'logo'),
            'instant_payment' => self::flag($input, 'instant_payment'),
            'session_timeout' => self::text($input, 'session_timeout'),
            'intersolve_product_id' => self::text($input, 'intersolve_product_id'),
            'payment_option' => self::text($input, 'payment_option'),
            'recurrence_type' => self::text($input, 'recurrence_type'),
            'signature_type' => self::text($input, 'signature_type'),
            'descriptor' => self::text($input, 'descriptor'),
            'countries' => self::codes($input, 'countries'),
            'currencies' => self::codes($input, 'currencies'),
        ]);
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * Provera unosa za delove forme koji za ovaj proizvod postoje. Vrednosti iz padajućih menija se proveravaju i
     * ovde, iako ih Core proverava takođe: Core-ova poruka ne kaže koje polje, a ova stoji ispod polja.
     *
     * Zemlja ili valuta sme biti samo jedna od ponuđenih: uključene u prodavnici, plus one koje su već sačuvane
     * (kontroler ih daje u $choices), da isključenje u PrestaShop-u ne bi pokvarilo čuvanje ostatka forme.
     *
     * @param array<string, bool> $sections PaymentMethodCatalog::sections()
     * @param array<string, string> $languages kod => naziv, podrazumevani jezik prvi
     * @param array{countries: array<string, mixed>, currencies: array<string, mixed>} $choices ponuđeni kodovi kao ključevi
     *
     * @return array<string, string> poruka po polju; prazno kad je sve ispravno
     */
    public function validate(array $sections, array $languages, array $choices): array
    {
        $errors = [];
        $values = $this->values;
        $defaultCode = (string) array_key_first($languages);
        $defaultLanguage = $languages[$defaultCode] ?? $defaultCode;

        if (($values['name'][$defaultCode] ?? '') === '') {
            $errors['name'] = sprintf('The name in %s is required.', $defaultLanguage);
        }

        if ($sections['payment_action'] && !isset(self::PAYMENT_ACTIONS[$values['payment_action']])) {
            $errors['payment_action'] = 'Choose a payment action.';
        }

        if ($sections['cards']) {
            if (($values['vault_titles'][$defaultCode] ?? '') === '') {
                $errors['vault_titles'] = sprintf('The saved card title in %s is required.', $defaultLanguage);
            }
            if (!isset(self::FLOW_TYPES[$values['flow_type']])) {
                $errors['flow_type'] = 'Choose iFrame or Redirect.';
            }
            if (!isset(self::AUTHORIZATION_MODES[$values['authorization_mode']])) {
                $errors['authorization_mode'] = 'Choose an authorization mode.';
            }
        }

        if ($sections['three_ds']) {
            if (!isset(self::EXEMPTION_TYPES[$values['exemption_type']])) {
                $errors['exemption_type'] = 'Choose an exemption type.';
            }
            // Nula nije dozvoljena: toRequest() bi je poslao kao null, a Core null zamenjuje svojim default-om od
            // 30,00 EUR (ThreeDSSettings konstruktor), pa bi admin upisao 0, a sačuvalo bi se 30.
            $limit = $values['exemption_limit'];
            if ($limit !== '' && (!is_numeric($limit) || (float) $limit <= 0)) {
                $errors['exemption_limit'] = 'The exemption limit must be an amount in EUR greater than 0, or empty for the default of 30.00 EUR.';
            }
        }

        if ($sections['intersolve']) {
            $timeout = $values['session_timeout'];
            if (!ctype_digit($timeout) || (int) $timeout < SessionTimeout::MIN_VALUE || (int) $timeout > SessionTimeout::MAX_VALUE) {
                $errors['session_timeout'] = sprintf(
                    'The session timeout must be a whole number of minutes from %d to %d.',
                    SessionTimeout::MIN_VALUE,
                    SessionTimeout::MAX_VALUE
                );
            }
            $productId = $values['intersolve_product_id'];
            if (!ctype_digit($productId) || !IntersolveProductId::isSupported($productId)) {
                $errors['intersolve_product_id'] = sprintf(
                    'The Intersolve product ID must be a number from %d to %d.',
                    IntersolveProductId::MIN_PRODUCT_ID,
                    IntersolveProductId::MAX_PRODUCT_ID
                );
            }
        }

        if ($sections['sepa']) {
            if (!isset(self::RECURRENCE_TYPES[$values['recurrence_type']])) {
                $errors['recurrence_type'] = 'Choose a recurrence type.';
            }
            if (!isset(self::SIGNATURE_TYPES[$values['signature_type']])) {
                $errors['signature_type'] = 'Choose a signature type.';
            }
        }

        if ($sections['descriptor'] && mb_strlen($values['descriptor']) > Descriptor::MAX_LENGTH) {
            $errors['descriptor'] = sprintf('The descriptor can have at most %d characters.', Descriptor::MAX_LENGTH);
        }

        foreach (['countries' => 'country', 'currencies' => 'currency'] as $field => $noun) {
            $unknown = array_diff($values[$field], array_map('strval', array_keys($choices[$field])));
            if ($unknown !== []) {
                $errors[$field] = sprintf('The %s %s is not one of the choices.', $noun, implode(', ', $unknown));
            }
        }

        return $errors;
    }

    /**
     * Core zahtev iz proverenih vrednosti. Argumenti idu redom konstruktora PaymentMethodRequest-a; polje koje za
     * ovaj proizvod ne postoji ide kao null, isto kao kad ga Core-ov sopstveni admin ne bi poslao.
     *
     * @param array<string, bool> $sections PaymentMethodCatalog::sections()
     */
    public function toRequest(string $productId, array $sections): PaymentMethodRequest
    {
        $values = $this->values;
        $threeDs = $sections['three_ds'];
        $groupCards = $sections['cards'] || $sections['hosted_checkout'];

        return new PaymentMethodRequest(
            $productId,
            self::nonEmpty($values['name']),
            $values['enabled'],
            $values['template'],
            $sections['payment_action'] ? $values['payment_action'] : null,
            $sections['cards'] ? self::nonEmpty($values['vault_titles']) : [],
            $sections['hosted_checkout'] ? $values['logo'] : null,
            $groupCards ? $values['enable_group_cards'] : null,
            $sections['oney'] ? $values['payment_option'] : null,
            $sections['intersolve'] ? (int) $values['session_timeout'] : null,
            $sections['intersolve'] ? $values['intersolve_product_id'] : null,
            $sections['sepa'] ? $values['recurrence_type'] : null,
            $sections['sepa'] ? $values['signature_type'] : null,
            $sections['bank_transfer'] ? $values['instant_payment'] : null,
            $threeDs ? $values['enable_3ds'] : null,
            $threeDs ? $values['enforce_strong_authentication'] : null,
            $threeDs ? $values['enable_3ds_exemption'] : null,
            $threeDs ? $values['exemption_type'] : null,
            // Prazno => null => Core uzima svoj default od 30,00 EUR (PaymentMethodRequest: "$this->amount ?");
            // validate() ne pušta 0, jer bi i 0 tamo postala 30,00.
            $threeDs && $values['exemption_limit'] !== '' ? (float) $values['exemption_limit'] : null,
            $sections['cards'] ? $values['flow_type'] : null,
            $sections['cards'] ? $values['authorization_mode'] : null,
            $sections['descriptor'] ? $values['descriptor'] : null
        );
    }

    /** @return array{countries: string[], currencies: string[]} ograničenje za PaymentMethodsService::saveRestriction() */
    public function restriction(): array
    {
        return ['countries' => $this->values['countries'], 'currencies' => $this->values['currencies']];
    }

    /**
     * Core listu prevoda ([['locale' => 'EN', 'value' => '...'], ...], default prvi) svodi na kod => tekst za jezike
     * prodavnice. Jezik bez prevoda dobija rezervni tekst za svoj kod, inače default prevod.
     *
     * @param array<int, array<string, mixed>> $translations
     * @param array<string, string> $languages
     * @param array<string, string> $fallbacks kod => tekst
     *
     * @return array<string, string>
     */
    private static function translations(array $translations, array $languages, array $fallbacks = []): array
    {
        $byCode = [];
        foreach ($translations as $translation) {
            if (isset($translation['locale'], $translation['value'])) {
                $byCode[strtoupper((string) $translation['locale'])] = (string) $translation['value'];
            }
        }
        $default = (string) ($translations[0]['value'] ?? '');

        $result = [];
        foreach ($languages as $code => $label) {
            $result[$code] = $byCode[$code] ?? $fallbacks[$code] ?? $default;
        }

        return $result;
    }

    /**
     * @param mixed $input vrednost polja name[] ili vault_titles[] iz forme
     * @param array<string, string> $languages
     *
     * @return array<string, string> kod => uneti tekst, za svaki jezik prodavnice
     */
    private static function textsByLanguage($input, array $languages): array
    {
        $result = [];
        foreach ($languages as $code => $label) {
            $result[$code] = is_array($input) && is_scalar($input[$code] ?? null) ? trim((string) $input[$code]) : '';
        }

        return $result;
    }

    /** @param array<string, mixed> $input */
    private static function text(array $input, string $key): string
    {
        return is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
    }

    /**
     * Grupa checkbox-ova (countries[], currencies[]): kodovi velikim slovima, bez ponavljanja, po abecedi, da isti
     * izbor uvek bude ista lista. Nijedan štikliran = polje nije poslato = prazna lista.
     *
     * @param array<string, mixed> $input
     *
     * @return string[]
     */
    private static function codes(array $input, string $key): array
    {
        $codes = [];
        foreach (is_array($input[$key] ?? null) ? $input[$key] : [] as $code) {
            if (is_scalar($code) && trim((string) $code) !== '') {
                $codes[] = strtoupper(trim((string) $code));
            }
        }
        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }

    /**
     * Checkbox: poslat kao "1" kad je štikliran, nije poslat kad nije.
     *
     * @param array<string, mixed> $input
     */
    private static function flag(array $input, string $key): bool
    {
        return isset($input[$key]) && is_scalar($input[$key]) && (string) $input[$key] === '1';
    }

    /**
     * @param array<string, string> $texts kod => tekst
     *
     * @return array<string, string> bez praznih, istim redom (prvi je default jezik, proveren u validate())
     */
    private static function nonEmpty(array $texts): array
    {
        return array_filter($texts, static function (string $text): bool {
            return $text !== '';
        });
    }
}
