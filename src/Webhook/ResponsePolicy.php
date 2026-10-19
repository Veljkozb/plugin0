<?php

namespace Plugin0\Webhook;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\ApiFacades\Response\Response;

/**
 * Prevodi odgovor Core fasade WebhookAPI u ishod isporuke (DeliveryOutcome), po errorCode-u iz
 * TranslatableErrorResponse::toArray(). Fasada ne baca izuzetke: ErrorHandlingAspect svaki izuzetak pretvori u
 * error odgovor, pa je errorCode jedino po čemu kontroler razlikuje slučajeve (docs/WEBHOOK.md, 2.1 i 3.4).
 *
 * - uspeh -> PROCESSED (200), ili DUPLICATE (200) kad je Webhook\WebhookService preskočio već obrađen događaj
 *   (Core i tada vraća običan uspeh, pa to kontroler javi preko SkippedDuplicate);
 * - plugin0.webhook.invalidSignature (Infrastructure\WebhookTransformer, umotava SDK greške potpisa) i Core
 *   webhook.merchantIdMismatch -> REJECTED (200): ponovno slanje istih bajtova ne može da prođe, a 401/403 bi
 *   pošiljaocu reklo da endpoint proverava potpis i da baš ovaj nije prošao;
 * - Core payment.invalidPaymentIdFormat -> IGNORED (200): događaj bez objekta payment/refund (token.*, payout.*)
 *   Core-u daje prazan ID i WebhookLogsService::logWebhook pada na PaymentId::parse(''); nema šta da se obradi;
 * - plugin0.webhook.unprocessable (isti dekorator, umotava ApiVersionMismatchException i InvalidResponseException)
 *   -> UNPROCESSABLE (200 uz grešku u logu): potpis je prošao, ali SDK ne može da pročita telo (apiVersion nije v1,
 *   telo nije JSON objekat); ponovno slanje istih bajtova ne pomaže;
 * - sve ostalo -> FAILED (503): potpis je prošao, naša obrada nije; Worldline šalje ponovo (10 min, 1 h, 2 h, 8 h,
 *   24 h), što je jedini mehanizam nadoknade jer plugin0 nema red zadataka ni TransactionStatusCheckTask.
 */
final class ResponsePolicy
{
    /** Kod plugin izuzetka za greške potpisa iz SDK-a (Webhook\InvalidWebhookSignatureException). */
    public const ERROR_INVALID_SIGNATURE = 'plugin0.webhook.invalidSignature';

    /** Kod plugin izuzetka za telo koje SDK ne može da pročita (Webhook\UnprocessableWebhookException). */
    public const ERROR_UNPROCESSABLE = 'plugin0.webhook.unprocessable';

    /** Core: WebhookMerchantMismatchException, Bootstrap\Sdk\WebhookTransformer::validateMerchantId. */
    public const CORE_ERROR_MERCHANT_MISMATCH = 'webhook.merchantIdMismatch';

    /** Core: InvalidPaymentIdException iz PaymentId::parse. */
    public const CORE_ERROR_INVALID_PAYMENT_ID = 'payment.invalidPaymentIdFormat';

    public static function forResponse(Response $response, bool $duplicateSkipped = false): DeliveryOutcome
    {
        if ($response->isSuccessful()) {
            return $duplicateSkipped ? DeliveryOutcome::duplicate() : DeliveryOutcome::processed();
        }

        $error = $response->toArray();
        $code = is_scalar($error['errorCode'] ?? null) ? (string) $error['errorCode'] : '';
        $message = is_scalar($error['errorMessage'] ?? null) ? (string) $error['errorMessage'] : '';

        switch ($code) {
            case self::ERROR_INVALID_SIGNATURE:
            case self::CORE_ERROR_MERCHANT_MISMATCH:
                return DeliveryOutcome::rejected($code, $message);
            case self::CORE_ERROR_INVALID_PAYMENT_ID:
                return DeliveryOutcome::ignored($code, $message);
            case self::ERROR_UNPROCESSABLE:
                return DeliveryOutcome::unprocessable($code, $message);
            default:
                return DeliveryOutcome::failed($code, $message);
        }
    }
}
