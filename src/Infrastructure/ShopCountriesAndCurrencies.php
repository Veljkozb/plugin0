<?php

namespace Plugin0\Infrastructure;

use Context;
use Country;
use Currency;

/**
 * Zemlje i valute koje je prodavnica uključila u PrestaShop adminu: International > Locations > Countries
 * (kolona Enabled) i International > Localization > Currencies. Samo među njima admin bira ograničenje metode
 * plaćanja, jer kupac drugu zemlju ne može da izabere u adresi, a korpa ne može biti u drugoj valuti.
 *
 * Obe liste su za tekuću prodavnicu iz Context-a (PrestaShop ih filtrira kroz Shop::addSqlAssociation).
 *
 * Svaka lista je jedan upit u bazu i pamti se za ceo zahtev: kontroler je pita više puta po zahtevu (provera
 * filtera, ponuda u formi, povratna adresa), a prodavnica i jezik admina se unutar zahteva ne menjaju.
 */
final class ShopCountriesAndCurrencies
{
    /** @var array<string, string>|null */
    private static ?array $countries = null;

    /** @var array<string, string>|null */
    private static ?array $currencies = null;

    /**
     * Uključene zemlje: ISO kod (country.iso_code, ISO 3166-1 alpha-2) => naziv na jeziku admina, po nazivu.
     *
     * @return array<string, string>
     */
    public static function countries(): array
    {
        if (self::$countries !== null) {
            return self::$countries;
        }

        $idLang = (int) Context::getContext()->language->id;
        $countries = [];
        foreach (Country::getCountries($idLang, true, false, false) as $country) {
            $code = strtoupper((string) $country['iso_code']);
            $countries[$code] = (string) ($country['name'] ?? $code);
        }

        return self::$countries = $countries;
    }

    /**
     * Uključene valute koje nisu obrisane: ISO kod (currency.iso_code, ISO 4217) => naziv, po kodu.
     *
     * @return array<string, string>
     */
    public static function currencies(): array
    {
        if (self::$currencies !== null) {
            return self::$currencies;
        }

        $currencies = [];
        foreach (Currency::getCurrencies(false, true, true) as $currency) {
            $code = strtoupper((string) $currency['iso_code']);
            $currencies[$code] = (string) ($currency['name'] ?? $code);
        }
        ksort($currencies);

        return self::$currencies = $currencies;
    }
}
