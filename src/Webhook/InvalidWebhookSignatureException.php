<?php

namespace Plugin0\Webhook;

use Throwable;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Translations\Exceptions\BaseTranslatableException;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Translations\Model\TranslatableLabel;

/**
 * Webhook čiji potpis nije mogao da se proveri: nema zaglavlja X-GCS-Signature ili X-GCS-KeyId, key id nije onaj
 * iz Configuration strane, HMAC se ne slaže, ili prodavnica nema aktivnu Core konekciju. Baca ga
 * Infrastructure\WebhookTransformer umesto SDK RuntimeException-a (SignatureValidationException,
 * SecretKeyNotAvailableException), da bi ErrorHandlingAspect vratio errorCode ResponsePolicy::ERROR_INVALID_SIGNATURE
 * po kome kontroler zna da odgovori 200 i upiše upozorenje (docs/WEBHOOK.md, 2.2 i 3.4).
 */
class InvalidWebhookSignatureException extends BaseTranslatableException
{
    public function __construct(string $reason, ?Throwable $previous = null)
    {
        parent::__construct(
            new TranslatableLabel('Webhook signature could not be verified: ' . $reason, ResponsePolicy::ERROR_INVALID_SIGNATURE),
            $previous
        );
    }
}
