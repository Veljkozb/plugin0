<?php

namespace Plugin0\Adapter;

use Closure;
use Db;
use Logeecom\PaymentMethods\Domain\Exception\PaymentMethodAlreadyExistsException;
use Logeecom\PaymentMethods\Domain\Exception\PaymentMethodNotFoundException;
use Logeecom\PaymentMethods\Domain\PaymentMethod;
use Logeecom\PaymentMethods\Domain\PaymentMethodFilter;
use Logeecom\PaymentMethods\Domain\PaymentMethodPage;
use Logeecom\PaymentMethods\Domain\PaymentMethodType;
use Logeecom\PaymentMethods\Repository\PaymentMethodRepositoryInterface;
use Plugin0\Entity\PaymentMethodEntity;
use RuntimeException;
use Throwable;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Entity;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Interfaces\RepositoryInterface;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\Operators;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\QueryFilter;
use WOP\OnlinePayments\Core\Infrastructure\ORM\RepositoryRegistry;

/**
 * Demo interfejs skladišta metoda plaćanja (u demu app/Repositories/EloquentPaymentMethodRepository.php) nad Core
 * ORM-om: svaka metoda je PaymentMethodEntity u tabeli plugin0_entity, a čita je i piše BaseRepository kroz
 * RepositoryRegistry, isto kao Core-ove entitete. Ništa iznad ovoga ne vidi ni Core ni PrestaShop: svaka metoda
 * prima i vraća demo domen objekat PaymentMethod.
 *
 * Šta radi baza, a šta PHP. Core QueryFilter zna jednakost, LIKE, IN i jedno ORDER BY po jednoj indeksiranoj
 * koloni; ne zna "lista sadrži kod" ni redosled po dve kolone. Zato iz baze dolaze redovi suženi po tipu i statusu
 * (indeksi), a pretraga po imenu ili kodu, filter po valuti i zemlji, redosled "sort order pa id" i sečenje
 * stranice rade u PHP-u, nad desetinama redova koliko katalog metoda ima.
 *
 * Transakcija: PrestaShop Db nema metode za transakcije, pa ide START TRANSACTION, COMMIT i ROLLBACK kroz
 * Db::getInstance(), istu konekciju koju koristi BaseRepository. Ugnježdeni poziv se samo pridružuje spoljnom.
 * DEBUG zapisi koje BaseRepository za vreme transakcije upiše u ps_log (ista konekcija) nestaju sa ROLLBACK-om;
 * ERROR zapis servisa nastaje posle ROLLBACK-a i ostaje.
 */
final class PaymentMethodRepository implements PaymentMethodRepositoryInterface
{
    /** Dubina ugnježdenih transaction() poziva; samo spoljni (dubina 1) otvara i zatvara transakciju u bazi. */
    private static int $transactionDepth = 0;

    /**
     * @return list<PaymentMethod>
     */
    public function all(): array
    {
        return array_map([$this, 'toDomain'], $this->entities(new QueryFilter()));
    }

    public function page(PaymentMethodFilter $filter, int $page, int $perPage): PaymentMethodPage
    {
        $query = new QueryFilter();
        if ($filter->type !== null) {
            $query->where('type', Operators::EQUALS, $filter->type->value);
        }
        if ($filter->isActive !== null) {
            $query->where('isActive', Operators::EQUALS, $filter->isActive);
        }

        $matches = array_values(array_filter(
            array_map([$this, 'toDomain'], $this->entities($query)),
            static function (PaymentMethod $method) use ($filter): bool {
                return self::matches($method, $filter);
            }
        ));
        usort($matches, static function (PaymentMethod $a, PaymentMethod $b): int {
            return [$a->sortOrder, $a->id] <=> [$b->sortOrder, $b->id];
        });

        $total = count($matches);
        $items = array_slice($matches, ($page - 1) * $perPage, $perPage);

        // Da je tabela prazna zna se bez gledanja samo kad ništa nije filtrirano; inače se pogleda.
        $tableEmpty = $total === 0 && ($filter->isEmpty() || $this->repository()->count() === 0);

        return new PaymentMethodPage($items, $total, $page, $perPage, $tableEmpty);
    }

    public function find(int $id): ?PaymentMethod
    {
        $entity = $this->entity($id);

        return $entity === null ? null : $this->toDomain($entity);
    }

    public function findByCode(string $code): ?PaymentMethod
    {
        $entity = $this->repository()->selectOne(
            (new QueryFilter())->where('code', Operators::EQUALS, $code)
        );

        return $entity instanceof PaymentMethodEntity ? $this->toDomain($entity) : null;
    }

    public function add(PaymentMethod $method): PaymentMethod
    {
        if ($method->id !== null) {
            throw PaymentMethodAlreadyExistsException::withId($method->id);
        }

        $entity = $this->toEntity($method, new PaymentMethodEntity());
        // BaseRepository::save baca RuntimeException kad INSERT ne prođe.
        $this->repository()->save($entity);

        return $this->toDomain($entity);
    }

    public function update(PaymentMethod $method): PaymentMethod
    {
        if ($method->id === null) {
            throw PaymentMethodNotFoundException::withoutId();
        }

        $entity = $this->entity($method->id);
        if ($entity === null) {
            throw PaymentMethodNotFoundException::withId($method->id);
        }

        $this->toEntity($method, $entity);
        if (!$this->repository()->update($entity)) {
            throw new RuntimeException(sprintf(
                'Payment method %d could not be updated. %s',
                $method->id,
                Db::getInstance()->getMsgError()
            ));
        }

        return $this->toDomain($entity);
    }

    public function delete(int $id): void
    {
        $entity = $this->entity($id);
        if ($entity === null) {
            return;
        }

        if (!$this->repository()->delete($entity)) {
            throw new RuntimeException(sprintf(
                'Payment method %d could not be deleted. %s',
                $id,
                Db::getInstance()->getMsgError()
            ));
        }
    }

    public function transaction(Closure $writes): mixed
    {
        if (self::$transactionDepth > 0) {
            ++self::$transactionDepth;
            try {
                return $writes();
            } finally {
                --self::$transactionDepth;
            }
        }

        $db = Db::getInstance();
        if (!$db->execute('START TRANSACTION')) {
            throw new RuntimeException('The transaction could not be started. ' . $db->getMsgError());
        }

        self::$transactionDepth = 1;
        try {
            $result = $writes();
        } catch (Throwable $e) {
            self::$transactionDepth = 0;
            $db->execute('ROLLBACK');

            throw $e;
        }
        self::$transactionDepth = 0;

        if (!$db->execute('COMMIT')) {
            throw new RuntimeException('The transaction could not be committed. ' . $db->getMsgError());
        }

        return $result;
    }

    /**
     * @return list<PaymentMethodEntity>
     */
    private function entities(QueryFilter $filter): array
    {
        return array_values(array_filter(
            $this->repository()->select($filter),
            static function (Entity $entity): bool {
                return $entity instanceof PaymentMethodEntity;
            }
        ));
    }

    private function entity(int $id): ?PaymentMethodEntity
    {
        $entity = $this->repository()->selectOne(
            (new QueryFilter())->where('id', Operators::EQUALS, $id)
        );

        return $entity instanceof PaymentMethodEntity ? $entity : null;
    }

    private function repository(): RepositoryInterface
    {
        return RepositoryRegistry::getRepository(PaymentMethodEntity::CLASS_NAME);
    }

    /** Uslovi koje baza ne zna: pretraga (deo imena ili koda, bez obzira na velika i mala slova, doslovno) i liste. */
    private static function matches(PaymentMethod $method, PaymentMethodFilter $filter): bool
    {
        if ($filter->search !== null
            && mb_stripos($method->name, $filter->search) === false
            && mb_stripos($method->code, $filter->search) === false
        ) {
            return false;
        }

        if ($filter->currency !== null && !in_array($filter->currency, $method->currencies, true)) {
            return false;
        }

        if ($filter->country !== null && !in_array($filter->country, $method->countries, true)) {
            return false;
        }

        return true;
    }

    /** Red iz tabele kao demo domen objekat; prazna lista valuta ili zemalja ovde baca, kao i u demu, jer takav red ne sme da postoji. */
    private function toDomain(PaymentMethodEntity $entity): PaymentMethod
    {
        return new PaymentMethod(
            id: $entity->getId(),
            name: $entity->getName(),
            code: $entity->getCode(),
            type: PaymentMethodType::from($entity->getType()),
            currencies: $entity->getCurrencies(),
            countries: $entity->getCountries(),
            isActive: $entity->isActive(),
            sortOrder: $entity->getSortOrder(),
        );
    }

    private function toEntity(PaymentMethod $method, PaymentMethodEntity $entity): PaymentMethodEntity
    {
        $entity->setName($method->name);
        $entity->setCode($method->code);
        $entity->setType($method->type->value);
        $entity->setCurrencies($method->currencies);
        $entity->setCountries($method->countries);
        $entity->setIsActive($method->isActive);
        $entity->setSortOrder($method->sortOrder);

        return $entity;
    }
}
