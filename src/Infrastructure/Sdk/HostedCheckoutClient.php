<?php

namespace Plugin0\Infrastructure\Sdk;

use OnlinePayments\Sdk\CallContext;
use OnlinePayments\Sdk\Communication\ErrorResponseException;
use OnlinePayments\Sdk\Communication\ResponseClassMap;
use OnlinePayments\Sdk\Domain\CreateHostedCheckoutRequest;
use OnlinePayments\Sdk\ExceptionFactory;
use OnlinePayments\Sdk\Merchant\HostedCheckout\HostedCheckoutClient as SdkHostedCheckoutClient;

/**
 * SDK HostedCheckoutClient koji odgovor na createHostedCheckout čita u Plugin0 CreateHostedCheckoutResponse (i polje
 * returnmac). Pravi se oko SDK klijenta koji vraća MerchantClient::hostedCheckout(): taj klijent mu je roditelj (isti
 * Communicator, autentikacija i Core logovanje), a kontekst (merchantId za URL) se preuzima od njega. Sve ostale metode
 * ostaju SDK-ove. Kod je isti kao SDK createHostedCheckout (SDK 6.2.0), razlikuje se samo ime klase uspešnog odgovora;
 * pri ažuriranju SDK-a uporedi sa SDK metodom.
 */
class HostedCheckoutClient extends SdkHostedCheckoutClient
{
    public function __construct(SdkHostedCheckoutClient $sdkClient)
    {
        parent::__construct($sdkClient, $sdkClient->context);
    }

    public function createHostedCheckout(CreateHostedCheckoutRequest $body, ?CallContext $callContext = null)
    {
        $responseClassMap = new ResponseClassMap();
        $responseClassMap->defaultSuccessResponseClassName = CreateHostedCheckoutResponse::class;
        $responseClassMap->defaultErrorResponseClassName = '\OnlinePayments\Sdk\Domain\ErrorResponse';
        try {
            return $this->getCommunicator()->post(
                $responseClassMap,
                $this->instantiateUri('/v2/{merchantId}/hostedcheckouts'),
                $this->getClientMetaInfo(),
                $body,
                null,
                $callContext
            );
        } catch (ErrorResponseException $e) {
            // SDK getResponseExceptionFactory() je private; SDK ga pravi isto: new ExceptionFactory().
            throw (new ExceptionFactory())->createException(
                $e->getHttpStatusCode(),
                $e->getErrorResponse(),
                $callContext
            );
        }
    }
}
