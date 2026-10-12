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

    /**
     * Core-ov sopstveni ključ najnižeg nivoa logovanja (getMinLogLevel/saveMinLogLevel, docs/CORE.md 4.4).
     * Plugin0 ga koristi kao podešavanje "Log level" sa Settings stranice; vrednost je Core broj nivoa.
     */
    public const KEY_MIN_LOG_LEVEL = 'minLogLevel';

    /** Ključ podešavanja "Rows per page" sa Settings stranice; postoji samo u plugin0. */
    public const KEY_ROWS_PER_PAGE = 'rowsPerPage';

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

    /**
     * Sirova sačuvana vrednost ključa, ili null kad red ne postoji. Default ne daje ovde nego SettingsService,
     * da bi se razlikovalo "nije sačuvano" od "sačuvan je baš default".
     *
     * @return mixed
     */
    public function getStoredValue(string $name)
    {
        return $this->getConfigValue($name, null);
    }

    /** @param mixed $value */
    public function storeValue(string $name, $value): void
    {
        $this->saveConfigValue($name, $value);
    }

    /** Briše red ključa, pa od tada važi default. Isto pravilo o kontekstu kao kod čitanja i upisa. */
    public function removeValue(string $name): void
    {
        $this->getConfigurationManager()->deleteConfigEntity($name, $this->isContextSpecific($name));
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
