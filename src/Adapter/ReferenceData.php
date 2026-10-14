<?php

namespace Plugin0\Adapter;

use Logeecom\PaymentMethods\Repository\ReferenceDataRepositoryInterface;
use Plugin0\Infrastructure\ShopCountriesAndCurrencies;

/**
 * Demo katalog valuta i zemalja (u demu app/Repositories/EloquentReferenceData.php nad tabelama koje pune seederi)
 * nad PrestaShop-om: kodovi valuta i zemalja koje je prodavnica uključila (ShopCountriesAndCurrencies), po kodu.
 * Samo čitanje: ovo je ponuda forme i skup uz koji se proverava unos, ne podaci aplikacije.
 */
final class ReferenceData implements ReferenceDataRepositoryInterface
{
    /**
     * @return list<string> ISO 4217 kodovi, npr. EUR
     */
    public function currencies(): array
    {
        $codes = array_map('strval', array_keys(ShopCountriesAndCurrencies::currencies()));
        sort($codes);

        return $codes;
    }

    /**
     * @return list<string> ISO 3166-1 alpha-2 kodovi, npr. RS
     */
    public function countries(): array
    {
        $codes = array_map('strval', array_keys(ShopCountriesAndCurrencies::countries()));
        sort($codes);

        return $codes;
    }
}
