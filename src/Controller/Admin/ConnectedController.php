<?php

namespace Plugin0\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;

/**
 * Zajednički deo stranica koje postoje samo dok je prodavnica povezana sa Worldline-om: Settings, Logs,
 * kasnije Payment methods. U demu su te stranice iza "connected" middleware-a; ovde svaki kontroler na početku
 * pita isConnected() i nepovezanu prodavnicu vodi na Configuration, gde ona postaje povezana.
 */
abstract class ConnectedController extends FrameworkBundleAdminController
{
    /** Povezano = Core ima sačuvanu konekciju za ovu prodavnicu; odgovor tada nosi "mode". */
    protected function isConnected(): bool
    {
        $response = AdminAPI::get()->connection($this->storeId())->getConnectionConfig();

        return $response->isSuccessful() && isset($response->toArray()['mode']);
    }

    protected function storeId(): string
    {
        return (string) $this->getContext()->shop->id;
    }
}
