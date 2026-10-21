<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once __DIR__ . '/vendor/autoload.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

/**
 * Modul plaćanja (PaymentModule, ne Module): PrestaShop ga pri checkout-u pita za opcije plaćanja preko hook-a
 * paymentOptions i vodi za koje valute, zemlje, grupe kupaca i prevoznike važi (tabele module_currency,
 * module_country, module_group i module_carrier, koje PaymentModule::install i Module::install pune).
 *
 * Opcije plaćanja su metode iz demo projekta (Logeecom\PaymentMethods), koje admin pravi na stranici Payment
 * methods; koje od njih važe za korpu kaže Plugin0\Checkout\AvailablePaymentMethods.
 */
class Plugin0 extends PaymentModule
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
        // PaymentModule::install upisuje za koje valute modul važi; "checkbox" = admin bira više valuta odjednom
        // (Payment > Preferences > Currency restrictions).
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->controllers = ['payment', 'return', 'webhook', 'asyncprocess'];

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
            && $this->registerHook('actionDispatcherBefore')
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayAdminOrderMain');
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

    /**
     * Korak plaćanja na checkout-u: PrestaShop (CheckoutPaymentStep preko PaymentOptionsFinder) zove svaki modul
     * registrovan na paymentOptions sa korpom u $params['cart'] i prikazuje vraćene opcije. Jedna opcija po metodi
     * iz plugina koja važi za korpu: tekst je ime metode, a izbor vodi (POST, polje "method" = id) na front
     * controller "payment".
     *
     * @param array{cart: Cart} $params
     *
     * @return PaymentOption[]
     */
    public function hookPaymentOptions(array $params): array
    {
        if (!$this->active || !isset($params['cart']) || !$this->checkCurrency($params['cart'])) {
            return [];
        }

        \Plugin0\Bootstrap::boot();

        $options = [];
        foreach (\Plugin0\Checkout\AvailablePaymentMethods::forCart($params['cart']) as $method) {
            $option = new PaymentOption();
            $option->setModuleName($this->name)
                ->setCallToActionText($method->name)
                ->setAction($this->context->link->getModuleLink($this->name, 'payment', [], true))
                ->setInputs([
                    ['type' => 'hidden', 'name' => 'method', 'value' => (string) $method->id],
                ]);
            $options[] = $option;
        }

        return $options;
    }

    /**
     * Panel "Worldline payment" na admin stranici narudžbine (Sell > Orders > narudžbina): PrestaShop-ov Symfony
     * šablon Admin/Sell/Order/Order/view.html.twig zove hook displayAdminOrderMain sa $params['id_order'] između
     * bloka Details i bloka Payment. Samo za narudžbine koje je napravio ovaj modul (Order::module).
     *
     * Pri svakom otvaranju panel prvo osveži status narudžbine od Worldline-a (PaymentManager::panel sa true), pa
     * prikaže stanje plaćanja, forme za capture / refund / cancel i log pokušaja. Forme vidi samo zaposleni čiji
     * profil sme da menja narudžbine (pravo "update" na AdminOrders, isto koje traži i kontroler akcija).
     *
     * @param array{id_order: int|string} $params
     */
    public function hookDisplayAdminOrderMain(array $params): string
    {
        $order = new Order((int) ($params['id_order'] ?? 0));
        if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        \Plugin0\Bootstrap::boot();

        // Hook::coreCallHook van debug režima guta svaki Exception i vraća '' (kartica tada samo nestane), pa se
        // greška ovde upisuje u log (Worldline > Logs) i trgovac vidi da panel nije mogao da se učita.
        try {
            $panel = (new \Plugin0\Order\PaymentManager((string) $this->context->shop->id))->panel($order, true);
            if ($panel === null) {
                return '';
            }

            $container = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();

            return $container->get('twig')->render('@Modules/plugin0/views/templates/admin/order_payment_panel.html.twig', [
                'order_id' => (int) $order->id,
                'can_act' => $container->get('security.authorization_checker')->isGranted(
                    \PrestaShopBundle\Security\Voter\PageVoter::UPDATE,
                    'AdminOrders'
                ),
                'panel' => $panel,
            ]);
        } catch (\Throwable $e) {
            \WOP\OnlinePayments\Core\Infrastructure\Logger\Logger::logError(
                'Worldline payment panel could not be rendered on the order page',
                'PaymentManager',
                \Plugin0\Infrastructure\LoggerService::context([
                    'orderId' => (int) $order->id,
                    'error' => get_class($e) . ': ' . $e->getMessage(),
                    'at' => $e->getFile() . ':' . $e->getLine(),
                ])
            );

            return '<div class="card mt-2"><div class="card-body"><div class="alert alert-danger mb-0">'
                . 'The Worldline payment panel could not be loaded. Details are in Worldline &gt; Logs.'
                . '</div></div></div>';
        }
    }

    /**
     * PrestaShop-ovo ograničenje valuta modula (Payment > Preferences > Currency restrictions, tabela
     * module_currency), isto kao u ps_wirepayment: valuta korpe mora biti među valutama za koje je modul uključen.
     * Sopstveno ograničenje svake metode (njena lista valuta) proverava se posle, u AvailablePaymentMethods.
     */
    public function checkCurrency(Cart $cart): bool
    {
        $cartCurrency = new Currency((int) $cart->id_currency);
        $moduleCurrencies = $this->getCurrency((int) $cart->id_currency);

        if (is_array($moduleCurrencies)) {
            foreach ($moduleCurrencies as $moduleCurrency) {
                if ((int) $cartCurrency->id === (int) $moduleCurrency['id_currency']) {
                    return true;
                }
            }
        }

        return false;
    }
}
