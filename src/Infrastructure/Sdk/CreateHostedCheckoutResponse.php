<?php

namespace Plugin0\Infrastructure\Sdk;

use OnlinePayments\Sdk\Domain\CreateHostedCheckoutResponse as SdkCreateHostedCheckoutResponse;

/**
 * Odgovor na POST /v2/{merchantId}/hostedcheckouts koji pored polja RETURNMAC čita i returnmac (mala slova).
 *
 * Zašto: Worldline preprod od 2026-10-19 vraća returnmac malim slovima (2026-10-16 je bilo RETURNMAC). SDK
 * CreateHostedCheckoutResponse::fromObject prepisuje samo RETURNMAC, pa Core (CreateHostedCheckoutResponseTransformer)
 * sačuva prazan returnHmac i povratak kupca ne nalazi transakciju ("Payment transaction ... not found", korpa 18,
 * plaćanje 10300867120). URL povratka i dalje nosi RETURNMAC=... sa istom vrednošću. Kad su oba polja prisutna, važi
 * RETURNMAC, kao do sada.
 */
class CreateHostedCheckoutResponse extends SdkCreateHostedCheckoutResponse
{
    public function fromObject($object)
    {
        parent::fromObject($object);

        if (($this->RETURNMAC === null || $this->RETURNMAC === '') && is_object($object) && property_exists($object, 'returnmac')) {
            $this->RETURNMAC = $object->returnmac;
        }

        return $this;
    }
}
