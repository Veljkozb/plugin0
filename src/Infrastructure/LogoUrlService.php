<?php

namespace Plugin0\Infrastructure;

use Context;
use Plugin0\Configuration;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Logo\LogoUrlService as LogoUrlServiceInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;

/**
 * Adrese logotipa metoda plaćanja za Core: PaymentService upisuje logo u konfiguraciju Hosted Checkout metode,
 * a HostedTokenizationService daje logo brenda uz sačuvanu karticu kupca.
 *
 * Plugin još nema logotipe brendova. Konvencija: fajl views/img/payment-methods/{productId}.svg ili .png ako postoji,
 * inače generička ikonica views/img/payment-method.svg. Dovoljno je ubaciti fajl, kod se ne menja.
 */
class LogoUrlService implements LogoUrlServiceInterface
{
    private const FOLDER = 'views/img/payment-methods/';
    private const PLACEHOLDER = 'views/img/payment-method.svg';

    public function getHostedCheckoutLogoUrl(): string
    {
        return $this->getLogoUrl(PaymentProductId::HOSTED_CHECKOUT);
    }

    public function getLogoUrl(string $productId): string
    {
        // ID proizvoda je deo putanje fajla: samo slova, cifre i donja crta ('cards', 'hosted_checkout', '5408').
        // preg_replace vraća null kad PCRE ne uspe (na primer neispravan UTF-8); to je isto što i prazan ID.
        $productId = (string) preg_replace('/[^a-z0-9_]/i', '', $productId);

        foreach (['svg', 'png'] as $extension) {
            $file = self::FOLDER . $productId . '.' . $extension;
            if ($productId !== '' && is_file(_PS_MODULE_DIR_ . Configuration::MODULE_NAME . '/' . $file)) {
                return $this->url($file);
            }
        }

        return $this->url(self::PLACEHOLDER);
    }

    /** Apsolutna adresa sa domenom prodavnice, da važi i van admin stranica. */
    private function url(string $file): string
    {
        return Context::getContext()->link->getBaseLink() . 'modules/' . Configuration::MODULE_NAME . '/' . $file;
    }
}
