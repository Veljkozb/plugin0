<?php

namespace Plugin0\Controller\Admin;

use Plugin0\Bootstrap;
use Plugin0\Infrastructure\ShopCountriesAndCurrencies;
use Plugin0\PaymentMethods\PaymentMethodCatalog;
use Plugin0\PaymentMethods\PaymentMethodForm;
use Plugin0\PaymentMethods\PaymentMethodsService;
use Plugin0\Settings\SettingsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\Descriptor;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\Intersolve\PaymentProductId as IntersolveProductId;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\Intersolve\SessionTimeout;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentMethodDefaultConfigs;

/**
 * Payment methods stranica: izgled i ponašanje iz dema (resources/js/admin/PaymentMethodList.jsx i
 * PaymentMethodForm.jsx), sadržaj iz Core-a: Worldline proizvodi koje admin uključuje, isključuje i podešava.
 * Nema dodavanja ni brisanja, jer katalog daje Worldline.
 *
 * Lista: pretraga, filteri po grupi, zemlji, valuti i statusu i stranica su u adresi
 * (?search=pay&group=cards&country=FR&currency=EUR&status=enabled&page=2),
 * pa se prikaz može osvežiti i podeliti. Posle uključivanja, čuvanja ili odustajanja admin se vraća na isti prikaz
 * liste: forme ga nose u polju "back" (demo: location.state.from).
 *
 * Zemlje i valute metode (ograničenje koje plugin0 čuva sam, Core ga nema) biraju se među onima koje je prodavnica
 * uključila u PrestaShop-u (ShopCountriesAndCurrencies). Prazan izbor znači "bez ograničenja".
 *
 * Kao u demu, stranica postoji samo dok je prodavnica povezana; inače vodi na Configuration.
 */
class PaymentMethodsController extends ConnectedController
{
    private const LIST_TEMPLATE = '@Modules/plugin0/views/templates/admin/payment_methods.html.twig';
    private const FORM_TEMPLATE = '@Modules/plugin0/views/templates/admin/payment_method.html.twig';

    public function listAction(Request $request): Response
    {
        Bootstrap::boot();

        if (!$this->isConnected()) {
            return $this->redirectToRoute('plugin0_configuration');
        }

        $filter = $this->filterFrom($request->query->all());
        $page = (new PaymentMethodsService($this->storeId()))->page($filter, (new SettingsService())->rowsPerPage());
        if ($page['error'] !== null) {
            $this->addFlash('error', 'Payment methods could not be loaded. ' . $page['error']);
        }

        $query = $this->query($filter);

        return $this->render(self::LIST_TEMPLATE, [
            'current_page' => 'payment_methods',
            'connected' => true,
            'filter' => $filter,
            'query' => $query,
            'back' => http_build_query($query),
            'groups' => PaymentMethodCatalog::GROUPS,
            'countries' => ShopCountriesAndCurrencies::countries(),
            'currencies' => ShopCountriesAndCurrencies::currencies(),
            'statuses' => PaymentMethodCatalog::STATUSES,
            'page' => $page,
            // GET forma filtera zamenjuje ceo query string, pa _token tekuće adrese mora u skriveno polje.
            'token' => (string) $request->query->get('_token', ''),
        ]);
    }

    /** Prekidač u listi: poseban poziv ka Core-u (PaymentController::enable), kao poseban endpoint u demu. */
    public function enableAction(Request $request): Response
    {
        Bootstrap::boot();

        if (!$this->isConnected()) {
            return $this->redirectToRoute('plugin0_configuration');
        }

        $back = $this->backQuery($request->request->get('back'));
        $productId = self::scalar($request->request->get('product_id'));
        $enabled = self::scalar($request->request->get('enabled')) === '1';
        $service = new PaymentMethodsService($this->storeId());

        $method = PaymentMethodsService::isListed($productId) ? $service->get($productId) : null;
        if ($method === null) {
            $this->addFlash('error', 'Unknown payment method.');

            return $this->redirectToRoute('plugin0_payment_methods', $back);
        }

        $name = $this->defaultName($method);
        $state = $enabled ? 'enabled' : 'disabled';
        $error = $service->enable($productId, $enabled);
        if ($error !== null) {
            $this->addFlash('error', sprintf('"%s" could not be %s. %s', $name, $state, $error));

            return $this->redirectToRoute('plugin0_payment_methods', $back);
        }

        // Core ignoriše bool iz update() repozitorijuma, pa se uspeh proverava ponovnim čitanjem.
        $stored = $service->get($productId);
        if ($stored === null || $stored['enabled'] !== $enabled) {
            $this->addFlash('error', sprintf('"%s" could not be %s: the stored state did not change.', $name, $state));
        } else {
            $this->addFlash('success', sprintf('"%s" is now %s.', $name, $state));
        }

        return $this->redirectToRoute('plugin0_payment_methods', $back);
    }

    public function editAction(Request $request, string $productId): Response
    {
        Bootstrap::boot();

        if (!$this->isConnected()) {
            return $this->redirectToRoute('plugin0_configuration');
        }

        $isPost = $request->isMethod('POST');
        $back = $this->backQuery($isPost ? $request->request->get('back') : $request->query->get('back'));
        $service = new PaymentMethodsService($this->storeId());

        $method = PaymentMethodsService::isListed($productId) ? $service->get($productId) : null;
        if ($method === null) {
            $this->addFlash('error', 'Unknown payment method.');

            return $this->redirectToRoute('plugin0_payment_methods', $back);
        }

        // Jezici i ograničenje su čitanja van Core-a (PrestaShop i sopstveni repozitorijum), bez Core-ovog hvatanja
        // grešaka; greška baze ovde postaje poruka i povratak na listu, kao na Settings stranici.
        try {
            $languages = $service->languages();
            $restriction = $service->restriction($productId);
        } catch (Throwable $e) {
            $this->addFlash('error', 'Payment method could not be loaded. ' . $e->getMessage());

            return $this->redirectToRoute('plugin0_payment_methods', $back);
        }
        $sections = PaymentMethodCatalog::sections($productId);
        $choices = [
            'countries' => $this->choices(ShopCountriesAndCurrencies::countries(), $restriction['countries']),
            'currencies' => $this->choices(ShopCountriesAndCurrencies::currencies(), $restriction['currencies']),
        ];
        $errors = [];

        if ($isPost) {
            $form = PaymentMethodForm::fromSubmitted($request->request->all(), $languages);
            $errors = $form->validate($sections, $languages, $choices);
            if ($errors === []) {
                // Prvo Core, pa ograničenje: kad Core odbije metodu, ne menja se ni ograničenje.
                $error = $service->save($form->toRequest($productId, $sections));
                if ($error === null) {
                    $chosen = $form->restriction();
                    $error = $service->saveRestriction($productId, $chosen['countries'], $chosen['currencies']);
                    // Ime na podrazumevanom jeziku; Core default ime kad prodavnica nema nijedan aktivan jezik.
                    $name = $form->values()['name'][(string) array_key_first($languages)] ?? $this->defaultName($method);
                    if ($error === null) {
                        $this->addFlash('success', sprintf('Payment method "%s" was saved.', $name));

                        return $this->redirectToRoute('plugin0_payment_methods', $back);
                    }
                    $this->addFlash('error', sprintf('Payment method "%s" was saved, but its countries and currencies could not be saved. %s', $name, $error));
                } else {
                    $this->addFlash('error', 'Payment method could not be saved. ' . $error);
                }
            }
        } else {
            $form = PaymentMethodForm::fromCore($method, $languages, $restriction);
        }

        return $this->render(self::FORM_TEMPLATE, [
            'current_page' => 'payment_methods',
            'connected' => true,
            'product_id' => $productId,
            'title' => $this->defaultName($method),
            'group_label' => PaymentMethodCatalog::groupLabel(PaymentMethodDefaultConfigs::getPaymentGroup($productId)),
            'integration_types' => PaymentMethodCatalog::integrationLabels(PaymentMethodDefaultConfigs::getIntegrationTypes($productId)),
            'sections' => $sections,
            'languages' => $languages,
            'form' => $form->values(),
            'errors' => $errors,
            'choices' => $choices,
            'options' => [
                'payment_actions' => PaymentMethodForm::PAYMENT_ACTIONS,
                'flow_types' => PaymentMethodForm::FLOW_TYPES,
                'authorization_modes' => PaymentMethodForm::AUTHORIZATION_MODES,
                'exemption_types' => PaymentMethodForm::EXEMPTION_TYPES,
                'recurrence_types' => PaymentMethodForm::RECURRENCE_TYPES,
                'signature_types' => PaymentMethodForm::SIGNATURE_TYPES,
            ],
            'limits' => [
                'session_timeout_min' => SessionTimeout::MIN_VALUE,
                'session_timeout_max' => SessionTimeout::MAX_VALUE,
                'intersolve_product_id_min' => IntersolveProductId::MIN_PRODUCT_ID,
                'intersolve_product_id_max' => IntersolveProductId::MAX_PRODUCT_ID,
                'descriptor_max' => Descriptor::MAX_LENGTH,
            ],
            'back' => http_build_query($back),
            'back_query' => $back,
        ]);
    }

    /**
     * Samo poznati parametri liste, očišćeni; sve ostalo iz adrese se ignoriše. Nepoznata grupa, zemlja, valuta
     * ili status znači "bez tog filtera", strana manja od 1 znači prva. Zemlja i valuta su poznate kad su
     * uključene u prodavnici.
     *
     * @param array<string, mixed> $input
     *
     * @return array{search: string, group: string, country: string, currency: string, status: string, page: int}
     */
    private function filterFrom(array $input): array
    {
        $search = is_scalar($input['search'] ?? null) ? trim((string) $input['search']) : '';
        $group = is_scalar($input['group'] ?? null) ? (string) $input['group'] : '';
        $country = is_scalar($input['country'] ?? null) ? strtoupper((string) $input['country']) : '';
        $currency = is_scalar($input['currency'] ?? null) ? strtoupper((string) $input['currency']) : '';
        $status = is_scalar($input['status'] ?? null) ? (string) $input['status'] : '';
        $page = is_scalar($input['page'] ?? null) ? (int) $input['page'] : 1;

        return [
            'search' => mb_substr($search, 0, PaymentMethodsService::MAX_SEARCH_LENGTH),
            'group' => isset(PaymentMethodCatalog::GROUPS[$group]) ? $group : '',
            'country' => isset(ShopCountriesAndCurrencies::countries()[$country]) ? $country : '',
            'currency' => isset(ShopCountriesAndCurrencies::currencies()[$currency]) ? $currency : '',
            'status' => isset(PaymentMethodCatalog::STATUSES[$status]) ? $status : '',
            'page' => max(1, $page),
        ];
    }

    /**
     * Filter kao parametri adrese: samo postavljene vrednosti, da adrese ostanu kratke.
     *
     * @param array{search: string, group: string, country: string, currency: string, status: string, page: int} $filter
     *
     * @return array<string, string|int>
     */
    private function query(array $filter): array
    {
        $query = [];
        foreach (['search', 'group', 'country', 'currency', 'status'] as $key) {
            if ($filter[$key] !== '') {
                $query[$key] = $filter[$key];
            }
        }
        if ($filter['page'] > 1) {
            $query['page'] = $filter['page'];
        }

        return $query;
    }

    /**
     * Prikaz liste na koji se admin vraća, iz polja "back" (query string liste), očišćen isto kao sama lista.
     *
     * @param mixed $back vrednost polja iz zahteva; sve što nije skalar važi kao prazno
     *
     * @return array<string, string|int>
     */
    private function backQuery($back): array
    {
        parse_str(self::scalar($back), $params);

        return $this->query($this->filterFrom($params));
    }

    /**
     * Vrednost polja zahteva kao tekst. Symfony 4.4 (PrestaShop 8) za "back[]=x" iz ParameterBag::get vraća niz,
     * a (string) niza je upozorenje "Array to string conversion"; niz ovde važi kao prazno polje.
     *
     * @param mixed $value
     */
    private static function scalar($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Ponuda za izbor u formi: zemlje ili valute uključene u prodavnici, pa one koje su sačuvane za metodu a u
     * međuvremenu isključene u PrestaShop-u. Te ostaju vidljive i označene, da ne nestanu iz izbora bez traga.
     *
     * @param array<string, string> $shop kod => naziv, uključene u prodavnici
     * @param string[] $saved kodovi sačuvani za metodu
     *
     * @return array<string, array{name: string, in_shop: bool}>
     */
    private function choices(array $shop, array $saved): array
    {
        $choices = [];
        foreach ($shop as $code => $name) {
            $choices[$code] = ['name' => $name, 'in_shop' => true];
        }
        foreach ($saved as $code) {
            if (!isset($choices[$code])) {
                $choices[$code] = ['name' => $code, 'in_shop' => false];
            }
        }

        return $choices;
    }

    /** @param array<string, mixed> $method PaymentMethodResponse::toArray(); default prevod je prvi u listi name */
    private function defaultName(array $method): string
    {
        return (string) ($method['name'][0]['value'] ?? $method['paymentProductId'] ?? '');
    }
}
