<?php

namespace Plugin0\PaymentMethods;

use WOP\OnlinePayments\Core\Infrastructure\ORM\Configuration\EntityConfiguration;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Configuration\IndexMap;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Entity;

/**
 * Zemlje i valute na koje admin ograničava jednu metodu plaćanja. Postoji samo u plugin0: Core-ov
 * PaymentMethodRequest nema polje za to, a Worldline ne daje spisak zemalja i valuta po proizvodu, nego za jednu
 * zemlju, valutu i iznos kaže koji proizvodi važe (GET /products, docs/CORE.md 7.3).
 *
 * Ključ je isti kao kod Core-ovog PaymentMethodConfigEntity: prodavnica (storeId), okruženje (mode: test ili live)
 * i proizvod (paymentProductId). Zato ograničenje prati podešavanja metode: posebno je za test i za live, i briše
 * se zajedno sa njima pri disconnect-u (ShopPaymentService::deletePaymentMethods).
 *
 * Prazna lista zemalja znači "sve zemlje prodavnice". Prazna lista valuta znači "Default currency sa Settings
 * stranice": metoda bez sopstvene liste prati to podešavanje (PaymentMethodsService). Red postoji samo dok je bar
 * jedna lista neprazna. Čuva se kao red tipa "PaymentMethodRestriction" u tabeli plugin0_entity (BaseRepository).
 */
class PaymentMethodRestrictionEntity extends Entity
{
    public const CLASS_NAME = __CLASS__;

    protected string $storeId = '';
    protected string $mode = '';
    protected string $paymentProductId = '';
    /** @var string[] ISO 3166-1 alpha-2 kodovi, velikim slovima ('FR', 'RS') */
    protected array $countries = [];
    /** @var string[] ISO 4217 kodovi ('EUR', 'RSD') */
    protected array $currencies = [];

    /** @var string[] polja koja Core serijalizuje u kolonu data (Entity::toArray) i čita iz nje (inflate) */
    protected array $fields = ['id', 'storeId', 'mode', 'paymentProductId', 'countries', 'currencies'];

    public function getConfig(): EntityConfiguration
    {
        $indexMap = new IndexMap();
        $indexMap->addStringIndex('storeId');
        $indexMap->addStringIndex('mode');
        $indexMap->addStringIndex('paymentProductId');

        return new EntityConfiguration($indexMap, 'PaymentMethodRestriction');
    }

    public function getStoreId(): string
    {
        return $this->storeId;
    }

    public function setStoreId(string $storeId): void
    {
        $this->storeId = $storeId;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): void
    {
        $this->mode = $mode;
    }

    public function getPaymentProductId(): string
    {
        return $this->paymentProductId;
    }

    public function setPaymentProductId(string $paymentProductId): void
    {
        $this->paymentProductId = $paymentProductId;
    }

    /** @return string[] */
    public function getCountries(): array
    {
        return $this->countries;
    }

    /** @param string[] $countries */
    public function setCountries(array $countries): void
    {
        $this->countries = $countries;
    }

    /** @return string[] */
    public function getCurrencies(): array
    {
        return $this->currencies;
    }

    /** @param string[] $currencies */
    public function setCurrencies(array $currencies): void
    {
        $this->currencies = $currencies;
    }
}
