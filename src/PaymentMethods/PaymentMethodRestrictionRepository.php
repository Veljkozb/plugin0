<?php

namespace Plugin0\PaymentMethods;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Connection\ActiveConnectionProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Interfaces\ConditionallyDeletes;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Interfaces\RepositoryInterface;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\Operators;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\QueryFilter;
use WOP\OnlinePayments\Core\Infrastructure\ORM\RepositoryRegistry;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;

/**
 * Čita i piše PaymentMethodRestrictionEntity za jednu prodavnicu, u aktivnom okruženju (test ili live), po istom
 * pravilu kao Core-ov PaymentMethodConfigRepository: okruženje daje ActiveConnectionProvider, a bez konekcije nema
 * ni čitanja ni upisa.
 *
 * ActiveConnectionProvider čita konekciju za prodavnicu iz StoreContext-a, pa se svaki poziv izvršava unutar
 * StoreContext::doWithStore($storeId), kao što to AdminAPI radi za Core pozive.
 */
final class PaymentMethodRestrictionRepository
{
    private string $storeId;

    public function __construct(string $storeId)
    {
        $this->storeId = $storeId;
    }

    /**
     * Sva ograničenja prodavnice u aktivnom okruženju.
     *
     * @return array<string, array{countries: string[], currencies: string[]}> po ID-ju proizvoda
     */
    public function all(): array
    {
        return $this->inStore(function (): array {
            $mode = $this->mode();
            if ($mode === null) {
                return [];
            }

            $result = [];
            foreach ($this->repository()->select($this->query($mode)) as $entity) {
                /** @var PaymentMethodRestrictionEntity $entity */
                $result[$entity->getPaymentProductId()] = self::toArray($entity);
            }

            return $result;
        });
    }

    /** @return array{countries: string[], currencies: string[]} prazne liste kad ograničenja nema */
    public function get(string $productId): array
    {
        return $this->inStore(function () use ($productId): array {
            $entity = $this->find($productId);

            return $entity !== null ? self::toArray($entity) : ['countries' => [], 'currencies' => []];
        });
    }

    /**
     * Upisuje ograničenje; dve prazne liste brišu red, jer "bez ograničenja" je isto što i "nema reda".
     *
     * @param string[] $countries
     * @param string[] $currencies
     */
    public function save(string $productId, array $countries, array $currencies): void
    {
        $this->inStore(function () use ($productId, $countries, $currencies): void {
            $mode = $this->mode();
            if ($mode === null) {
                return;
            }

            $existing = $this->find($productId);
            if ($countries === [] && $currencies === []) {
                if ($existing !== null) {
                    $this->repository()->delete($existing);
                }

                return;
            }

            $entity = $existing ?? new PaymentMethodRestrictionEntity();
            $entity->setStoreId($this->storeId);
            $entity->setMode($mode);
            $entity->setPaymentProductId($productId);
            $entity->setCountries($countries);
            $entity->setCurrencies($currencies);

            if ($existing !== null) {
                $this->repository()->update($entity);
            } else {
                $this->repository()->save($entity);
            }
        });
    }

    /**
     * Briše sva ograničenja prodavnice u datom okruženju; zove ga ShopPaymentService pri disconnect-u.
     *
     * RepositoryInterface nema brisanje po uslovu; ima ga ConditionallyDeletes, koji BaseRepository implementira
     * (jedan DELETE upit). Repozitorijum bez njega briše red po red, da disconnect ne zavisi od toga koji je
     * repozitorijum registrovan za entitet.
     */
    public function deleteByMode(string $mode): void
    {
        $this->inStore(function () use ($mode): void {
            $repository = $this->repository();
            if ($repository instanceof ConditionallyDeletes) {
                $repository->deleteWhere($this->query($mode));

                return;
            }

            foreach ($repository->select($this->query($mode)) as $entity) {
                $repository->delete($entity);
            }
        });
    }

    private function find(string $productId): ?PaymentMethodRestrictionEntity
    {
        $mode = $this->mode();
        if ($mode === null) {
            return null;
        }

        /** @var PaymentMethodRestrictionEntity|null $entity */
        $entity = $this->repository()->selectOne(
            $this->query($mode)->where('paymentProductId', Operators::EQUALS, $productId)
        );

        return $entity;
    }

    private function query(string $mode): QueryFilter
    {
        return (new QueryFilter())
            ->where('storeId', Operators::EQUALS, $this->storeId)
            ->where('mode', Operators::EQUALS, $mode);
    }

    /** 'test' ili 'live' za aktivnu konekciju prodavnice; null kad prodavnica nije povezana. */
    private function mode(): ?string
    {
        /** @var ActiveConnectionProvider $provider */
        $provider = ServiceRegister::getService(ActiveConnectionProvider::class);
        $connection = $provider->get();

        return $connection !== null ? (string) $connection->getMode() : null;
    }

    private function repository(): RepositoryInterface
    {
        return RepositoryRegistry::getRepository(PaymentMethodRestrictionEntity::CLASS_NAME);
    }

    /**
     * @return mixed
     */
    private function inStore(callable $callback)
    {
        return StoreContext::doWithStore($this->storeId, $callback);
    }

    /** @return array{countries: string[], currencies: string[]} */
    private static function toArray(PaymentMethodRestrictionEntity $entity): array
    {
        return ['countries' => $entity->getCountries(), 'currencies' => $entity->getCurrencies()];
    }
}
