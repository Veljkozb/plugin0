<?php

namespace Plugin0;

use Context;
use Module;
use RuntimeException;
use WOP\OnlinePayments\Core\Bootstrap\Configuration\Configuration as CoreConfiguration;
use WOP\OnlinePayments\Core\Infrastructure\Singleton;

class Configuration extends CoreConfiguration
{
    public const MODULE_NAME = 'plugin0';

    protected static ?Singleton $instance = null;

    public function getIntegrationName(): string
    {
        return 'PrestaShop';
    }

    public function getIntegrationVersion(): string
    {
        return _PS_VERSION_;
    }

    public function getPluginName(): string
    {
        return $this->getModule()->name;
    }

    public function getPluginVersion(): string
    {
        return $this->getModule()->version;
    }

    public function getAsyncProcessUrl(string $guid): string
    {
        return Context::getContext()->link->getModuleLink(self::MODULE_NAME, 'asyncprocess', ['guid' => $guid], true);
    }

    private function getModule(): Module
    {
        $module = Module::getInstanceByName(self::MODULE_NAME);
        if (!$module instanceof Module) {
            throw new RuntimeException(sprintf('Module "%s" is not installed.', self::MODULE_NAME));
        }

        return $module;
    }
}
