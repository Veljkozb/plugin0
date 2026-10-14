<?php

namespace Plugin0\PaymentMethods;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentMethodDefaultConfigs;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;

/**
 * Reči i pravila Worldline kataloga za admin stranicu: nazivi grupa i načina integracije (Core ih drži kao ključeve
 * u PaymentMethodDefaultConfigs) i koji delovi forme važe za koji proizvod. Grananje po proizvodu je isto kao u
 * Core-ovom PaymentMethodRequest::transformToDomainModel(), pa forma nikad ne traži polje koje Core ne bi primio.
 */
final class PaymentMethodCatalog
{
    /** Grupa (ključ iz Core-a) => naziv u listi i u filteru. */
    public const GROUPS = [
        PaymentMethodDefaultConfigs::CARDS => 'Cards',
        PaymentMethodDefaultConfigs::HOSTED => 'Hosted Checkout',
        PaymentMethodDefaultConfigs::MOBILE => 'Mobile',
        PaymentMethodDefaultConfigs::REAL_TIME_BANKING => 'Real-time banking',
        PaymentMethodDefaultConfigs::GIFT_CARDS => 'Gift cards',
        PaymentMethodDefaultConfigs::PREPAID => 'Prepaid',
        PaymentMethodDefaultConfigs::INSTALMENT => 'Instalment',
        PaymentMethodDefaultConfigs::E_WALLET => 'E-wallet',
        PaymentMethodDefaultConfigs::POSTPAID => 'Postpaid',
        PaymentMethodDefaultConfigs::DIRECT_DEBIT => 'Direct debit',
    ];

    /** Način integracije (ključ iz Core-a) => naziv u listi. */
    public const INTEGRATION_TYPES = [
        PaymentMethodDefaultConfigs::HOSTED_PAGE => 'Hosted page',
        PaymentMethodDefaultConfigs::REDIRECT => 'Redirect',
        PaymentMethodDefaultConfigs::TOKENIZATION => 'Tokenization',
    ];

    /** Filter statusa u listi; prazno = sve. */
    public const STATUSES = [
        'enabled' => 'Enabled',
        'disabled' => 'Disabled',
    ];

    private const ONEY = [
        PaymentProductId::ONEY_3X,
        PaymentProductId::ONEY_4X,
        PaymentProductId::ONEY_FINANCEMENT_LONG,
        PaymentProductId::ONEY_BRANDED_GIFT_CARD,
        PaymentProductId::ONEY_BANK_CARD,
    ];

    private const DESCRIPTOR = [PaymentProductId::SOFINCO, PaymentProductId::LINXO];

    public static function groupLabel(string $group): string
    {
        return self::GROUPS[$group] ?? ucfirst($group);
    }

    /**
     * @param string[] $types ključevi iz Core-a
     *
     * @return string[] nazivi, istim redom
     */
    public static function integrationLabels(array $types): array
    {
        return array_map(static function ($type): string {
            return self::INTEGRATION_TYPES[(string) $type] ?? ucfirst((string) $type);
        }, $types);
    }

    /**
     * Koji delovi forme postoje za proizvod.
     *
     * @return array<string, bool> payment_action, cards, hosted_checkout, three_ds, bank_transfer, intersolve, oney, sepa, descriptor
     */
    public static function sections(string $productId): array
    {
        $cards = $productId === PaymentProductId::CARDS;
        $hostedCheckout = $productId === PaymentProductId::HOSTED_CHECKOUT;
        $googlePay = $productId === PaymentProductId::GOOGLE_PAY;

        return [
            // Autorizacija ili odmah naplata se bira samo za proizvode koji podržavaju odvojeni capture.
            'payment_action' => in_array($productId, PaymentProductId::SEPARATE_CAPTURE_SUPPORTED, true),
            'cards' => $cards,
            'hosted_checkout' => $hostedCheckout,
            'three_ds' => $cards || $hostedCheckout || $googlePay,
            'bank_transfer' => $productId === PaymentProductId::BANK_TRANSFER,
            'intersolve' => $productId === PaymentProductId::INTERSOLVE,
            'oney' => in_array($productId, self::ONEY, true),
            'sepa' => $productId === PaymentProductId::SEPA_DIRECT_DEBIT,
            'descriptor' => in_array($productId, self::DESCRIPTOR, true),
        ];
    }
}
