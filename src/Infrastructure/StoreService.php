<?php

namespace Plugin0\Infrastructure;

use Configuration;
use Context;
use OrderState;
use Shop;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Stores\StoreService as StoreServiceInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\OrderStatusMapping\Models\OrderStatusMapping;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Stores\Models\Store;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Stores\Models\StoreOrderStatus;

/**
 * Core pita "koji shopovi postoje i koje statuse narudžbine imaju"; odgovor daje PrestaShop.
 */
class StoreService implements StoreServiceInterface
{
    public function getStoreDomain(): string
    {
        return Context::getContext()->shop->domain;
    }

    /** @return Store[] */
    public function getStores(): array
    {
        $stores = [];
        foreach (Shop::getShops(false) as $shop) {
            $stores[] = $this->toStore((int) $shop['id_shop']);
        }

        return $stores;
    }

    public function getDefaultStore(): ?Store
    {
        return $this->toStore((int) Configuration::get('PS_SHOP_DEFAULT'));
    }

    public function getStoreById(string $id): ?Store
    {
        $shop = new Shop((int) $id);

        return $shop->id ? $this->toStore((int) $shop->id) : null;
    }

    /**
     * Podrazumevano mapiranje Worldline stanja na PrestaShop statuse narudžbine (ID-jevi iz ps_configuration).
     * Redosled argumenata: captured, error, pending, authorized, cancelled, refunded, partially refunded.
     */
    public function getDefaultOrderStatusMapping(): OrderStatusMapping
    {
        return new OrderStatusMapping(
            (string) Configuration::get('PS_OS_PAYMENT'),
            (string) Configuration::get('PS_OS_ERROR'),
            (string) Configuration::get('PS_OS_BANKWIRE'),
            (string) Configuration::get('PS_OS_BANKWIRE'),
            (string) Configuration::get('PS_OS_CANCELED'),
            (string) Configuration::get('PS_OS_REFUND'),
            ''
        );
    }

    /** @return StoreOrderStatus[] */
    public function getStoreOrderStatuses(): array
    {
        $statuses = [];
        foreach (OrderState::getOrderStates((int) Context::getContext()->language->id) as $state) {
            $statuses[] = new StoreOrderStatus((string) $state['id_order_state'], (string) $state['name']);
        }

        return $statuses;
    }

    private function toStore(int $shopId): Store
    {
        $shop = new Shop($shopId);
        $maintenance = !(bool) Configuration::get('PS_SHOP_ENABLE', null, null, $shopId);

        return new Store((string) $shop->id, (string) $shop->name, $maintenance);
    }
}