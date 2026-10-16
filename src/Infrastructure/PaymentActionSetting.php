<?php

namespace Plugin0\Infrastructure;

use Plugin0\Configuration;
use RuntimeException;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\GeneralSettingsAPI\Request\PaymentSettingsRequest;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\PaymentAction;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\Cards\AuthorizationMode;

/**
 * Podešavanje "Payment action" sa Settings stranice. Tri izbora za trgovca:
 *  - SALE: Worldline naplati odmah (Core PaymentAction::authorizeCapture);
 *  - FINAL_AUTHORIZATION: samo rezerviše, capture mora biti pun, u roku od 7 dana;
 *  - PRE_AUTHORIZATION: samo rezerviše, dozvoljeni su delimični capture i cancel, u roku od 30 dana.
 *
 * Core u opštim PaymentSettings zna samo SALE i FINAL_AUTHORIZATION (PaymentAction::fromState odbija sve drugo), a
 * PRE_AUTHORIZATION poznaje samo kroz konfiguraciju metode `cards`, koju plugin0 ne koristi. Zato se čuvaju dve stvari:
 *  1. Core PaymentSettings::paymentAction = SALE ili FINAL_AUTHORIZATION (za oba "authorize" izbora), od čega zavise
 *     Core status narudžbine, automatic capture i requiresApproval;
 *  2. plugin ključ Configuration::KEY_CARD_AUTHORIZATION_MODE = FINAL_AUTHORIZATION ili PRE_AUTHORIZATION, koji
 *     Infrastructure\HostedCheckoutProxy upiše u cardPaymentMethodSpecificInput.authorizationMode kad je Core akcija
 *     FINAL_AUTHORIZATION.
 * Core bez sačuvanih podešavanja podrazumeva SALE (GeneralSettingsService::getPaymentSettings).
 */
final class PaymentActionSetting
{
    public const SALE = PaymentAction::AUTHORIZE_CAPTURE;
    public const AUTHORIZE = PaymentAction::AUTHORIZE;
    public const PRE_AUTHORIZE = AuthorizationMode::PRE_AUTHORIZATION;

    /** @var array<string, string> vrednost => tekst u formi */
    public const CHOICES = [
        self::SALE => 'Authorize and capture (SALE): the amount is charged immediately',
        self::AUTHORIZE => 'Authorize only, final authorization (FINAL_AUTHORIZATION): capture in full or cancel later from the order page',
        self::PRE_AUTHORIZE => 'Authorize only, pre-authorization (PRE_AUTHORIZATION): partial captures and cancellations from the order page',
    ];

    public static function isValid(string $value): bool
    {
        return array_key_exists($value, self::CHOICES);
    }

    /** Core PaymentAction koji odgovara izboru: oba "authorize" izbora su za Core FINAL_AUTHORIZATION. */
    public static function coreAction(string $choice): string
    {
        return $choice === self::SALE ? self::SALE : self::AUTHORIZE;
    }

    /** Način rezervacije kartice koji odgovara izboru; za SALE nije primenjiv i vraća FINAL_AUTHORIZATION kao neutralan. */
    public static function cardMode(string $choice): string
    {
        return $choice === self::PRE_AUTHORIZE ? self::PRE_AUTHORIZE : self::AUTHORIZE;
    }

    /** Izbor sa forme iz dve sačuvane vrednosti. */
    public static function choice(string $coreAction, ?string $cardMode): string
    {
        if ($coreAction !== self::AUTHORIZE) {
            return self::SALE;
        }

        return $cardMode === self::PRE_AUTHORIZE ? self::PRE_AUTHORIZE : self::AUTHORIZE;
    }

    /** Vrednost na snazi: sačuvana ili Core default (SALE). */
    public static function current(string $storeId): string
    {
        $response = AdminAPI::get()->generalSettings($storeId)->getGeneralSettings();
        if (!$response->isSuccessful()) {
            throw new RuntimeException(self::error($response->toArray()));
        }

        return self::choice(
            (string) ($response->toArray()['paymentSettings']['paymentAction'] ?? self::SALE),
            self::storedCardMode()
        );
    }

    /**
     * Način rezervacije kartice za novi Hosted Checkout zahtev; zove ga HostedCheckoutProxy unutar Core store
     * konteksta. Bez sačuvane vrednosti važi FINAL_AUTHORIZATION, isto što Core sam šalje.
     */
    public static function cardAuthorizationMode(): string
    {
        return self::storedCardMode() ?? self::AUTHORIZE;
    }

    public static function save(string $storeId, string $paymentAction): void
    {
        if (!self::isValid($paymentAction)) {
            throw new RuntimeException(sprintf('Unknown payment action "%s".', $paymentAction));
        }

        $general = AdminAPI::get()->generalSettings($storeId);
        $response = $general->getGeneralSettings();
        if (!$response->isSuccessful()) {
            throw new RuntimeException(self::error($response->toArray()));
        }

        $s = $response->toArray()['paymentSettings'];

        // Status za "delimično vraćeno" prazan je u Core podešavanjima sačuvanim pre 2026-10-16 (StoreService ga tada
        // nije davao); bez njega Core posle delimičnog refunda ne menja narudžbinu. Prazno se popunjava kao i u
        // StoreService::getDefaultOrderStatusMapping: isti status kao za naplaćeno (PrestaShop "Payment accepted").
        $partiallyRefunded = (string) ($s['paymentPartiallyRefundedStatus'] ?? '');
        if ($partiallyRefunded === '') {
            $partiallyRefunded = (string) \Configuration::get('PS_OS_PAYMENT');
        }

        $saved = $general->savePaymentSettings(new PaymentSettingsRequest(
            self::coreAction($paymentAction),
            (int) $s['automaticCapture'],
            (int) $s['numberOfPaymentAttempts'],
            (bool) $s['applySurcharge'],
            (string) $s['paymentCapturedStatus'],
            (string) $s['paymentErrorStatus'],
            (string) $s['paymentPendingStatus'],
            (string) $s['paymentAuthorizedStatus'],
            (string) $s['paymentCancelledStatus'],
            (string) $s['paymentRefundedStatus'],
            (string) $s['template'],
            $partiallyRefunded,
            (bool) ($s['sendShoppingCart'] ?? true),
            (bool) ($s['displayConfirmationPage'] ?? true)
        ));
        if (!$saved->isSuccessful()) {
            throw new RuntimeException(self::error($saved->toArray()));
        }

        Configuration::getInstance()->storeValue(Configuration::KEY_CARD_AUTHORIZATION_MODE, self::cardMode($paymentAction));
    }

    private static function storedCardMode(): ?string
    {
        $stored = Configuration::getInstance()->getStoredValue(Configuration::KEY_CARD_AUTHORIZATION_MODE);

        return $stored === self::PRE_AUTHORIZE || $stored === self::AUTHORIZE ? $stored : null;
    }

    /** @param array<string, mixed> $error */
    private static function error(array $error): string
    {
        return (string) ($error['errorMessage'] ?? $error['errorCode'] ?? 'Unknown Core error.');
    }
}
