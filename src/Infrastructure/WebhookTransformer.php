<?php

namespace Plugin0\Infrastructure;

use OnlinePayments\Sdk\Communication\InvalidResponseException;
use OnlinePayments\Sdk\Webhooks\ApiVersionMismatchException;
use OnlinePayments\Sdk\Webhooks\SignatureValidationException;
use Plugin0\Webhook\InvalidWebhookSignatureException;
use Plugin0\Webhook\UnprocessableWebhookException;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Connection\ActiveConnectionProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Webhook\Transformers\WebhookTransformerInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Webhook\WebhookData;

/**
 * Plugin0 omotač oko Core Bootstrap\Sdk\WebhookTransformer-a, registrovan u Plugin0\Bootstrap::initServices pod
 * ključem WebhookTransformerInterface (zamenjuje Core registraciju). Proveru potpisa, trgovca i pravljenje
 * WebhookData i dalje radi Core; omotač samo prevodi izuzetke koje Core ne razlikuje.
 *
 * Zašto: SDK greške potpisa (SignatureValidationException: nema zaglavlja, HMAC se ne slaže; njena podklasa
 * SecretKeyNotAvailableException: nepoznat key id) su obični RuntimeException-i, pa ih ErrorHandlingAspect loguje kao
 * "Unhandled error occurred." i vraća errorCode general.unhandled, isti kao za pad baze ili Worldline-a. Front
 * controller webhook po tom kodu mora da odgovori 503 (Worldline šalje ponovo), a za loš potpis je ispravno 200
 * (docs/WEBHOOK.md, 3). Zato se ovde SDK greške umotavaju u plugin izuzetke koji nasleđuju BaseTranslatableException,
 * pa Core vraća njihov errorCode:
 * - Webhook\InvalidWebhookSignatureException (plugin0.webhook.invalidSignature) za greške potpisa i za prodavnicu bez
 *   aktivne konekcije (Core bi na null konekciji pao sa TypeError; bez tajne se ništa ne može proveriti);
 * - Webhook\UnprocessableWebhookException (plugin0.webhook.unprocessable) za ispravno potpisano telo koje SDK ne može
 *   da pročita: apiVersion nije v1 (ApiVersionMismatchException) ili telo nije JSON objekat događaja
 *   (InvalidResponseException iz ResponseFactory, npr. niz ili neispravan JSON); ponovno slanje ne pomaže.
 * Sve ostalo (WebhookMerchantMismatchException je već translatable; neutralan WebhookData za događaj bez plaćanja)
 * prolazi nepromenjeno.
 */
class WebhookTransformer implements WebhookTransformerInterface
{
    private WebhookTransformerInterface $inner;
    private ActiveConnectionProvider $connections;

    public function __construct(WebhookTransformerInterface $inner, ActiveConnectionProvider $connections)
    {
        $this->inner = $inner;
        $this->connections = $connections;
    }

    public function transform(string $webhookBody, array $requestHeaders): WebhookData
    {
        if ($this->connections->get() === null) {
            throw new InvalidWebhookSignatureException('the shop has no active Worldline connection');
        }

        try {
            return $this->inner->transform($webhookBody, $requestHeaders);
        } catch (SignatureValidationException $e) {
            throw new InvalidWebhookSignatureException($e->getMessage(), $e);
        } catch (ApiVersionMismatchException $e) {
            throw new UnprocessableWebhookException(
                sprintf('event apiVersion "%s" is not supported, expected "%s"', (string) $e->getEventApiVersion(), (string) $e->getSdkApiVersion()),
                $e
            );
        } catch (InvalidResponseException $e) {
            throw new UnprocessableWebhookException('body is not a webhook event object: ' . $e->getMessage(), $e);
        }
    }
}
