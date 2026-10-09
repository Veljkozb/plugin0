<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once __DIR__ . '/vendor/autoload.php';

class Plugin0 extends Module
{
    public function __construct()
    {
        $this->name = 'plugin0';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Veljko Zbiljic';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();
        $this->displayName = $this->l('Plugin0 Worldline Payments');
        $this->description = $this->l('Learning project: Worldline Online Payments module built from scratch');
    }

    public function install()
    {
        // The actionDispatcherBefore hook is not registered yet while install() runs,
        // so the core has to be booted explicitly for any installer code that needs it.
        \Plugin0\Bootstrap::boot();

        return parent::install()
            && \Plugin0\Infrastructure\Installer::createTables()
            && $this->registerHook('actionDispatcherBefore');
    }

    public function uninstall()
    {
        \Plugin0\Bootstrap::boot();

        return \Plugin0\Infrastructure\Installer::dropTables()
            && parent::uninstall();
    }

    /**
     * Dugme "Configure" u Module Manager-u: PrestaShop zove ovu metodu, mi vodimo na Symfony stranicu.
     */
    public function getContent()
    {
        \Tools::redirectAdmin(
            \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance()->get('router')->generate('plugin0_configuration')
        );
    }

    /**
     * Fired once per request by classes/Dispatcher.php (front office, module front controllers,
     * legacy admin) and by PrestaShopBundle\EventListener\ActionDispatcherLegacyHooksSubscriber
     * (Symfony admin routes). Not fired by bin/console or the webservice.
     */
    public function hookActionDispatcherBefore(array $params)
    {
        \Plugin0\Bootstrap::boot();
    }
}
