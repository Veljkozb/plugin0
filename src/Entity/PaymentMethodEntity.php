<?php

namespace Plugin0\Entity;

use WOP\OnlinePayments\Core\Infrastructure\ORM\Configuration\EntityConfiguration;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Configuration\IndexMap;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Entity;

/**
 * Jedna metoda plaćanja iz demo projekta (Logeecom\PaymentMethods\Domain\PaymentMethod) kao red u tabeli
 * plugin0_entity: Core ORM entitet tipa "PaymentMethod", koji BaseRepository čita i piše isto kao Core-ove entitete.
 *
 * Polja su ona iz demo tabele payment_methods i njene dve pivot tabele (OBPHP1 Data model): name, code, type
 * (redirect ili iframe), is_active, sort_order, pa liste valuta i zemalja. Liste ovde nisu posebne tabele nego deo
 * JSON kolone data, jer Core ORM nema relacije; filtriranje po valuti i zemlji radi Adapter\PaymentMethodRepository
 * u PHP-u.
 *
 * Indeksi (kolone index_1 do index_4): code (findByCode i provera jedinstvenosti), type i isActive (filteri liste)
 * i sortOrder (Core ga upisuje sa vodećim nulama, pa poređenje teksta daje numerički redosled).
 *
 * U ključu nema ni prodavnice ni okruženja: metode važe za celu PrestaShop instalaciju, kao podešavanja, jer demo
 * projekat ima jednu aplikaciju; nisu vezane ni za Worldline konekciju, pa ih disconnect ne briše.
 */
class PaymentMethodEntity extends Entity
{
    public const CLASS_NAME = __CLASS__;

    /** Vrednost kolone type u plugin0_entity. */
    public const TYPE = 'PaymentMethod';

    protected string $name = '';
    protected string $code = '';
    protected string $type = '';
    /** @var string[] ISO 4217 kodovi ('EUR', 'RSD') */
    protected array $currencies = [];
    /** @var string[] ISO 3166-1 alpha-2 kodovi ('FR', 'RS') */
    protected array $countries = [];
    protected bool $isActive = false;
    protected int $sortOrder = 0;

    /** @var string[] polja koja Core serijalizuje u kolonu data (Entity::toArray) i čita iz nje (inflate) */
    protected array $fields = ['id', 'name', 'code', 'type', 'currencies', 'countries', 'isActive', 'sortOrder'];

    public function getConfig(): EntityConfiguration
    {
        $indexMap = new IndexMap();
        $indexMap->addStringIndex('code');
        $indexMap->addStringIndex('type');
        $indexMap->addBooleanIndex('isActive');
        $indexMap->addIntegerIndex('sortOrder');

        return new EntityConfiguration($indexMap, self::TYPE);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    /** @return string[] */
    public function getCurrencies(): array
    {
        return $this->currencies;
    }

    /** @param string[] $currencies */
    public function setCurrencies(array $currencies): void
    {
        $this->currencies = array_values($currencies);
    }

    /** @return string[] */
    public function getCountries(): array
    {
        return $this->countries;
    }

    /** @param string[] $countries */
    public function setCountries(array $countries): void
    {
        $this->countries = array_values($countries);
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }
}
