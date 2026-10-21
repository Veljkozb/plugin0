<?php

namespace Plugin0\Infrastructure;

use RuntimeException;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\GeneralSettingsAPI\Request\PaymentSettingsRequest;

/**
 * Čitanje i upis Core PaymentSettings aktivne konekcije preko Core fasade (AdminAPI::generalSettings), za sva plugin
 * podešavanja koja tamo žive: Payment action (Infrastructure\PaymentActionSetting) i Auto capture
 * (BackgroundProcessing\AutoCapture\AutoCaptureSetting).
 *
 * Core čuva PaymentSettings kao jednu celinu od četrnaest polja (PaymentSettingsRequest), pa svaka izmena jednog
 * polja ide kao čitaj-izmeni-upiši: pročitaju se vrednosti na snazi (sačuvane ili Core defaulti,
 * GeneralSettingsService::getPaymentSettings), promene se samo tražena polja i cela celina se upiše nazad.
 */
final class CorePaymentSettings
{
    /**
     * Vrednosti na snazi, u obliku GeneralSettingsResponse::paymentSettingsToArray (paymentAction, automaticCapture,
     * numberOfPaymentAttempts, ...).
     *
     * @return array<string, mixed>
     */
    public static function read(string $storeId): array
    {
        $response = AdminAPI::get()->generalSettings($storeId)->getGeneralSettings();
        if (!$response->isSuccessful()) {
            throw new RuntimeException(self::errorMessage($response->toArray()));
        }

        return (array) ($response->toArray()['paymentSettings'] ?? []);
    }

    /**
     * Upiše vrednosti na snazi sa traženim izmenama. Dozvoljeni ključevi: paymentAction (string) i automaticCapture
     * (int, minuti ili -1).
     *
     * @param array{paymentAction?: string, automaticCapture?: int} $changes
     */
    public static function update(string $storeId, array $changes): void
    {
        $s = array_merge(self::read($storeId), $changes);

        // Status za "delimično vraćeno" prazan je u Core podešavanjima sačuvanim pre 2026-10-16 (StoreService ga tada
        // nije davao); bez njega Core posle delimičnog refunda ne menja narudžbinu. Prazno se popunjava kao i u
        // StoreService::getDefaultOrderStatusMapping: isti status kao za naplaćeno (PrestaShop "Payment accepted").
        $partiallyRefunded = (string) ($s['paymentPartiallyRefundedStatus'] ?? '');
        if ($partiallyRefunded === '') {
            $partiallyRefunded = (string) \Configuration::get('PS_OS_PAYMENT');
        }

        $saved = AdminAPI::get()->generalSettings($storeId)->savePaymentSettings(new PaymentSettingsRequest(
            (string) $s['paymentAction'],
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
            throw new RuntimeException(self::errorMessage($saved->toArray()));
        }
    }

    /** @param array<string, mixed> $error toArray() Core error odgovora */
    public static function errorMessage(array $error): string
    {
        return (string) ($error['errorMessage'] ?? $error['errorCode'] ?? 'Unknown Core error.');
    }
}
