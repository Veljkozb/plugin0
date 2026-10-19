<?php

namespace Plugin0\Webhook;

use Throwable;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Translations\Exceptions\BaseTranslatableException;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Translations\Model\TranslatableLabel;

/**
 * Webhook sa ispravnim potpisom koji SDK ipak ne može da pročita: apiVersion različit od v1
 * (ApiVersionMismatchException) ili telo koje nije JSON objekat događaja (InvalidResponseException, npr. niz ili
 * neispravan JSON). Ponovno slanje istih bajtova ne može da pomogne, zato kontroler odgovara 200 i upisuje grešku
 * (ResponsePolicy::ERROR_UNPROCESSABLE), umesto 503 koji bi izazvao pet ponavljanja bez svrhe.
 */
class UnprocessableWebhookException extends BaseTranslatableException
{
    public function __construct(string $reason, ?Throwable $previous = null)
    {
        parent::__construct(
            new TranslatableLabel('Webhook cannot be processed: ' . $reason, ResponsePolicy::ERROR_UNPROCESSABLE),
            $previous
        );
    }
}
