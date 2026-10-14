<?php

namespace Plugin0\Infrastructure;

use Context;
use Language as PrestaShopLanguage;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Language\LanguageService as LanguageServiceInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Language\Language;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;

/**
 * Jezici prodavnice za Core: PaymentService::savePaymentMethod odavde uzima jezike za koje dopunjava prevode imena
 * metode, a LanguageController ih izlaže adminu.
 *
 * Kod jezika je ISO 639-1 velikim slovima ('EN', 'SR'), jer Core tako ključuje prevode: default ime metode je pod 'EN'
 * (PaymentMethodDefaultConfigs), nazivi sačuvane kartice pod 'EN', 'DE', 'FR', 'ES', 'IT' (PaymentService).
 * Logo jezika je zastavica iz PrestaShop-a (img/l/{id_lang}.jpg), kao apsolutna adresa sa domenom prodavnice,
 * isto kao logotipi metoda iz LogoUrlService-a (_PS_IMG_ je samo putanja od korena sajta, '/img/').
 */
class LanguageService implements LanguageServiceInterface
{
    /** @return Language[] */
    public function getEnabledLanguages(): array
    {
        $baseLink = Context::getContext()->link->getBaseLink();
        $languages = [];
        foreach (self::prestaShopLanguages() as $language) {
            $languages[] = new Language(self::code($language), $baseLink . 'img/l/' . (int) $language['id_lang'] . '.jpg');
        }

        return $languages;
    }

    /**
     * Aktivni jezici prodavnice iz StoreContext-a (Core ga postavlja pre poziva), inače tekuće prodavnice admina.
     *
     * @return array<int, array<string, mixed>> redovi PrestaShop tabele lang (id_lang, name, iso_code, ...)
     */
    public static function prestaShopLanguages(): array
    {
        $storeId = StoreContext::getInstance()->getStoreId();
        $idShop = $storeId !== '' ? (int) $storeId : (int) Context::getContext()->shop->id;

        return PrestaShopLanguage::getLanguages(true, $idShop);
    }

    /** @param array<string, mixed> $language red PrestaShop tabele lang */
    public static function code(array $language): string
    {
        return strtoupper((string) $language['iso_code']);
    }
}
