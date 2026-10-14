<?php

namespace Plugin0\Controller\Admin;

use Logeecom\PaymentMethods\Domain\Exception\DuplicatePaymentMethodCodeException;
use Logeecom\PaymentMethods\Domain\Exception\PaymentMethodNotFoundException;
use Logeecom\PaymentMethods\Domain\PaymentMethod;
use Logeecom\PaymentMethods\Domain\PaymentMethodType;
use Logeecom\PaymentMethods\Service\PaymentMethodService;
use Plugin0\Bootstrap;
use Plugin0\Form\PaymentMethodForm;
use Plugin0\Infrastructure\ShopCountriesAndCurrencies;
use Plugin0\Infrastructure\WorldlineProducts;
use Plugin0\Services;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Payment methods stranica iz dema (resources/js/admin/PaymentMethodList.jsx, PaymentMethodForm.jsx,
 * DeleteDialog.jsx i app/Http/Controllers/Admin/PaymentMethodController.php): lista sa pretragom, filterima po
 * valuti, zemlji, tipu i statusu, straničenjem i prekidačem; forma za dodavanje i izmenu; brisanje sa potvrdom.
 * Sadržaj su metode koje admin sam pravi (Logeecom\PaymentMethods), ne Worldline katalog.
 *
 * Jedini sloj koji zna za HTTP: čita zahtev, zove tačno jednu metodu PaymentMethodService-a i prevodi odgovor u
 * stranicu ili preusmerenje. Pravila su u servisu i u PaymentMethodForm; ovde nema nijednog.
 *
 * Prikaz liste je u adresi (?search=pay&currency=EUR&country=RS&type=redirect&status=active&page=2), pa se može
 * osvežiti i podeliti. Vrednost koja ne znači ništa (nepoznata valuta, zemlja, tip ili status) važi kao "bez tog
 * filtera", strana manja od 1 kao prva, a strana iza poslednje vodi na poslednju, kao u demu. Posle svake radnje
 * admin se vraća na isti prikaz liste: forme ga nose u polju "back" (demo: location.state.from).
 *
 * Svaki upis nosi ID zaposlenog iz PrestaShop admina, pa zapis u logu kaže ko je šta uradio (Requirement 5.1).
 * Kao u demu, stranica postoji samo dok je prodavnica povezana; inače vodi na Configuration.
 */
class PaymentMethodsController extends ConnectedController
{
    private const LIST_TEMPLATE = '@Modules/plugin0/views/templates/admin/payment_methods.html.twig';
    private const FORM_TEMPLATE = '@Modules/plugin0/views/templates/admin/payment_method.html.twig';

    /** Najduža pretraga: najduži kod ima 64 znaka, pa duži pojam ne može ništa da nađe (demo ListPaymentMethodsRequest). */
    private const MAX_SEARCH_LENGTH = 64;

    /** Filter statusa, kao demo STATUSES; prazno = sve. */
    private const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

    private const FILTER_KEYS = ['search', 'currency', 'country', 'type', 'status'];

    public function listAction(Request $request): Response
    {
        Bootstrap::boot();

        if (!$this->isConnected()) {
            return $this->redirectToRoute('plugin0_configuration');
        }

        $service = Services::paymentMethods();
        $filter = $this->filterFrom($request->query->all(), $service);

        try {
            $page = $service->presentPage($service->page($this->serviceQuery($filter)));
        } catch (Throwable $e) {
            $this->addFlash('error', 'Payment methods could not be loaded. ' . $e->getMessage());
            $page = null;
        }

        // Brisanje može da isprazni poslednju stranu, a obeležena adresa da imenuje stranu koje više nema: lista
        // tada prelazi na poslednju, kao u demu.
        if ($page !== null && $page['data'] === [] && $page['meta']['total'] > 0) {
            return $this->redirectToRoute('plugin0_payment_methods', $this->urlQuery($filter, $page['meta']['last_page']));
        }

        $query = $this->urlQuery($filter);

        return $this->render(self::LIST_TEMPLATE, [
            'current_page' => 'payment_methods',
            'connected' => true,
            'filter' => $filter,
            'query' => $query,
            'back' => http_build_query($query),
            'currencies' => $this->names(ShopCountriesAndCurrencies::currencies(), $service->allowedCurrencies()),
            'countries' => $this->names(ShopCountriesAndCurrencies::countries(), $service->allowedCountries()),
            'types' => $service->types(),
            'statuses' => self::STATUSES,
            'page' => $page,
            // GET forma filtera zamenjuje ceo query string, pa _token tekuće adrese mora u skriveno polje.
            'token' => (string) $request->query->get('_token', ''),
        ]);
    }

    public function createAction(Request $request): Response
    {
        Bootstrap::boot();

        if (!$this->isConnected()) {
            return $this->redirectToRoute('plugin0_configuration');
        }

        return $this->form($request, null);
    }

    public function editAction(Request $request, int $id): Response
    {
        Bootstrap::boot();

        if (!$this->isConnected()) {
            return $this->redirectToRoute('plugin0_configuration');
        }

        return $this->form($request, $id);
    }

    /** Prekidač u listi: sopstvena ruta i sopstvena metoda servisa, nikad isti put kao brisanje (demo setActive). */
    public function setActiveAction(Request $request, int $id): Response
    {
        Bootstrap::boot();

        if (!$this->isConnected()) {
            return $this->redirectToRoute('plugin0_configuration');
        }

        $service = Services::paymentMethods();
        $back = $this->backQuery($request->request->get('back'), $service);

        // Demo: is_active obavezan boolean, inače 422 i ništa se ne menja.
        $value = self::scalar($request->request->get('is_active'));
        if (!in_array($value, ['0', '1'], true)) {
            $this->addFlash('error', 'The is active field is required.');

            return $this->redirectToRoute('plugin0_payment_methods', $back);
        }

        try {
            $method = $service->setActive($id, $value === '1', $this->employeeId());
            $this->addFlash('success', sprintf('"%s" is now %s.', $method->name, $method->isActive ? 'active' : 'inactive'));
        } catch (PaymentMethodNotFoundException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (Throwable $e) {
            // PaymentMethodWriteFailedException (ERROR zapis je servis već upisao) ili bilo šta drugo.
            $this->addFlash('error', 'Payment method could not be switched. ' . $e->getMessage());
        }

        return $this->redirectToRoute('plugin0_payment_methods', $back);
    }

    /** Brisanje, tek posle potvrde na listi (requirement 3.3); poruka imenuje metodu, kao demo destroy(). */
    public function deleteAction(Request $request, int $id): Response
    {
        Bootstrap::boot();

        if (!$this->isConnected()) {
            return $this->redirectToRoute('plugin0_configuration');
        }

        $service = Services::paymentMethods();
        $back = $this->backQuery($request->request->get('back'), $service);

        try {
            // Prvo čitanje, pa brisanje: posle delete() nema šta da se pita za ime.
            $method = $service->get($id);
            $service->delete($id, $this->employeeId());
            $this->addFlash('success', sprintf('Payment method "%s" was deleted.', $method->name));
        } catch (PaymentMethodNotFoundException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (Throwable $e) {
            $this->addFlash('error', 'Payment method could not be deleted. ' . $e->getMessage());
        }

        return $this->redirectToRoute('plugin0_payment_methods', $back);
    }

    /**
     * Jedna forma za obe adrese, kao demo PaymentMethodForm.jsx: sa id-jem menja tu metodu, bez njega pravi novu.
     * GET prikazuje formu; POST proverava pravila i čuva, pa vodi nazad na listu; neispravan unos vraća formu sa
     * svim unetim vrednostima i porukom ispod svakog polja koje nije prošlo.
     */
    private function form(Request $request, ?int $id): Response
    {
        $service = Services::paymentMethods();
        $isPost = $request->isMethod('POST');
        $back = $this->backQuery($isPost ? $request->request->get('back') : $request->query->get('back'), $service);

        $method = null;
        if ($id !== null) {
            try {
                $method = $service->get($id);
            } catch (PaymentMethodNotFoundException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('plugin0_payment_methods', $back);
            } catch (Throwable $e) {
                $this->addFlash('error', 'Payment method could not be loaded. ' . $e->getMessage());

                return $this->redirectToRoute('plugin0_payment_methods', $back);
            }
        }

        $errors = [];
        if ($isPost) {
            $form = PaymentMethodForm::fromSubmitted($request->request->all());
            try {
                $errors = $form->validate($service, $id);
                if ($errors === []) {
                    $saved = $id === null
                        ? $service->create($form->data(), $this->employeeId())
                        : $service->update($id, $form->data(), $this->employeeId());
                    $this->addFlash('success', sprintf(
                        'Payment method "%s" was %s.',
                        $saved->name,
                        $id === null ? 'created' : 'saved'
                    ));

                    return $this->redirectToRoute('plugin0_payment_methods', $back);
                }
            } catch (DuplicatePaymentMethodCodeException $e) {
                // Kod je zauzet između provere i upisa (druga kartica): poruka ispod polja, kao da provera nije prošla.
                $errors['code'] = $e->getMessage();
            } catch (Throwable $e) {
                // PaymentMethodWriteFailedException (ERROR zapis je servis već upisao) ili bilo šta drugo: forma
                // ostaje sa unetim vrednostima, poruka iznad nje.
                $this->addFlash('error', 'Payment method could not be saved. ' . $e->getMessage());
            }
        } else {
            $form = $method === null ? PaymentMethodForm::empty($service) : PaymentMethodForm::fromMethod($method);
        }

        return $this->render(self::FORM_TEMPLATE, [
            'current_page' => 'payment_methods',
            'connected' => true,
            'id' => $id,
            'title' => $method === null ? 'New payment method' : $method->name,
            'form' => $form->values(),
            'errors' => $errors,
            'types' => $service->types(),
            'currencies' => $this->names(ShopCountriesAndCurrencies::currencies(), $service->allowedCurrencies()),
            'countries' => $this->names(ShopCountriesAndCurrencies::countries(), $service->allowedCountries()),
            'max_sort_order' => PaymentMethod::MAX_SORT_ORDER,
            // Ponuda za Code: Worldline proizvodi iz Core-a (ID => naziv); kod se moze i rucno upisati.
            'worldline_products' => WorldlineProducts::names(),
            'back' => http_build_query($back),
            'back_query' => $back,
        ]);
    }

    /** ID zaposlenog prijavljenog u PrestaShop admin; svaka ruta ovog kontrolera je iza prijave, pa ga uvek ima. */
    private function employeeId(): int
    {
        return (int) $this->getContext()->employee->id;
    }

    /**
     * Samo poznati parametri liste, očišćeni; sve ostalo iz adrese se ignoriše. Demo ih proverava u
     * ListPaymentMethodsRequest i nepoznatu vrednost odbija sa 422; ovde nepoznata vrednost znači "bez tog filtera".
     *
     * @param array<string, mixed> $input
     *
     * @return array{search: string, currency: string, country: string, type: string, status: string, page: int}
     */
    private function filterFrom(array $input, PaymentMethodService $service): array
    {
        $currency = self::scalar($input['currency'] ?? null);
        $country = self::scalar($input['country'] ?? null);
        $type = self::scalar($input['type'] ?? null);
        $status = self::scalar($input['status'] ?? null);

        return [
            'search' => mb_substr(trim(self::scalar($input['search'] ?? null)), 0, self::MAX_SEARCH_LENGTH),
            'currency' => in_array($currency, $service->allowedCurrencies(), true) ? $currency : '',
            'country' => in_array($country, $service->allowedCountries(), true) ? $country : '',
            'type' => PaymentMethodType::tryFrom($type) !== null ? $type : '',
            'status' => isset(self::STATUSES[$status]) ? $status : '',
            'page' => max(1, (int) self::scalar($input['page'] ?? null)),
        ];
    }

    /**
     * Parametri za PaymentMethodService::page(): samo postavljeni, onako kako ih demo Form Request prosleđuje.
     *
     * @param array{search: string, currency: string, country: string, type: string, status: string, page: int} $filter
     *
     * @return array<string, string|int>
     */
    private function serviceQuery(array $filter): array
    {
        $query = ['page' => $filter['page']];
        foreach (self::FILTER_KEYS as $key) {
            if ($filter[$key] !== '') {
                $query[$key] = $filter[$key];
            }
        }

        return $query;
    }

    /**
     * Filter kao parametri adrese: samo postavljene vrednosti, da adrese ostanu kratke; strana samo kad nije prva.
     *
     * @param array{search: string, currency: string, country: string, type: string, status: string, page: int} $filter
     * @param int|null $page strana umesto one iz filtera
     *
     * @return array<string, string|int>
     */
    private function urlQuery(array $filter, ?int $page = null): array
    {
        $query = [];
        foreach (self::FILTER_KEYS as $key) {
            if ($filter[$key] !== '') {
                $query[$key] = $filter[$key];
            }
        }

        $page = $page ?? $filter['page'];
        if ($page > 1) {
            $query['page'] = $page;
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
    private function backQuery($back, PaymentMethodService $service): array
    {
        parse_str(self::scalar($back), $params);

        return $this->urlQuery($this->filterFrom($params, $service));
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
     * Nazivi uz kodove koje servis dozvoljava (ponuda u filterima i u formi): kod => naziv iz PrestaShop-a.
     *
     * @param array<string, string> $shop kod => naziv, uključene u prodavnici
     * @param string[] $allowed kodovi koje servis prihvata, po kodu
     *
     * @return array<string, string>
     */
    private function names(array $shop, array $allowed): array
    {
        $names = [];
        foreach ($allowed as $code) {
            $names[$code] = $shop[$code] ?? $code;
        }

        return $names;
    }
}
