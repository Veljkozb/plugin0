<?php

namespace Plugin0\PaymentMethods;

use Configuration as PrestaShopConfiguration;
use Plugin0\Infrastructure\LanguageService;
use Plugin0\Infrastructure\LoggerService;
use Plugin0\Settings\SettingsService;
use Throwable;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\PaymentAPI\Request\PaymentMethodRequest;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\ApiFacades\Response\Response;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;

/**
 * Payment methods stranica nad Core-om: lista Worldline proizvoda (AdminAPI::payment()->list()), jedna metoda,
 * uključivanje i čuvanje. Pretraga, filteri i straničenje su ovde, u memoriji: Core vraća ceo katalog odjednom,
 * bez sopstvenog straničenja, a katalog ima pedesetak proizvoda.
 *
 * Uz to i zemlje i valute u kojima se metoda nudi: njih Core nema, pa ih plugin0 čuva sam
 * (PaymentMethodRestrictionRepository). Prazna lista zemalja znači "sve zemlje prodavnice". Lista valuta nikad nije
 * prazna: metoda bez sopstvene liste nudi se u Default currency sa Settings stranice (SettingsService), a metoda
 * sa listom u onome što je admin izabrao, u šta SettingsService dodaje svaku novu Default currency.
 *
 * Greške Core-a stižu kao TranslatableErrorResponse (ErrorHandlingAspect ih nikad ne baca dalje); ovde postaju
 * poruka za flash, ili null kad je sve prošlo.
 */
final class PaymentMethodsService
{
    /** Najduža pretraga; duže od najdužeg imena u katalogu ionako ništa ne bi našlo. */
    public const MAX_SEARCH_LENGTH = 64;

    /** Komponenta zapisa u logu (kolona Message na Logs stranici počinje sa "PaymentMethods:"). */
    private const COMPONENT = 'PaymentMethods';

    private string $storeId;

    private ?string $defaultCurrency = null;

    public function __construct(string $storeId)
    {
        $this->storeId = $storeId;
    }

    /**
     * Ceo katalog kao redovi liste, po imenu. Core daje uniju sačuvanih konfiguracija i default konfiguracija svih
     * podržanih proizvoda bez pojedinačnih kartičnih brendova (oni idu kroz "cards").
     *
     * @return array{methods: array<int, array<string, mixed>>, error: string|null}
     */
    public function all(): array
    {
        $response = AdminAPI::get()->payment($this->storeId)->list();
        if (!$response->isSuccessful()) {
            return ['methods' => [], 'error' => $this->errorMessage($response)];
        }

        // Ograničenja čita plugin0 sam, van Core-ovog ErrorHandlingAspect-a, pa se greška baze hvata ovde.
        try {
            $restrictions = $this->restrictions()->all();
        } catch (Throwable $e) {
            return ['methods' => [], 'error' => $e->getMessage()];
        }

        $methods = [];
        foreach ($response->toArray() as $method) {
            $productId = (string) $method['paymentProductId'];
            $restriction = $this->withDefaultCurrency($restrictions[$productId] ?? ['countries' => [], 'currencies' => []]);
            $methods[] = [
                'product_id' => $productId,
                'name' => (string) ($method['name']['value'] ?? ''),
                'group' => (string) $method['paymentGroup'],
                'group_label' => PaymentMethodCatalog::groupLabel((string) $method['paymentGroup']),
                'integration_types' => PaymentMethodCatalog::integrationLabels((array) $method['integrationTypes']),
                'enabled' => (bool) $method['enabled'],
                'countries' => $restriction['countries'],
                'currencies' => $restriction['currencies'],
            ];
        }
        usort($methods, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });

        return ['methods' => $methods, 'error' => null];
    }

    /**
     * Jedna strana liste, sužena pretragom i filterima; svaki postavljen uslov dodatno sužava, kao u demu.
     * Strana iza poslednje vraća praznu listu uz ukupan broj, pa šablon može to i da kaže.
     *
     * Filter zemlje ili valute pokazuje metode koje u njoj mogu da se ponude: zemlja bez ograničenja ili u listi,
     * valuta u listi (lista valuta uvek postoji, vidi all()).
     *
     * @param array{search: string, group: string, country: string, currency: string, status: string, page: int} $filter
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, last_page: int, from: int|null, to: int|null, error: string|null}
     */
    public function page(array $filter, int $perPage): array
    {
        $all = $this->all();
        $matching = array_values(array_filter($all['methods'], static function (array $method) use ($filter): bool {
            if ($filter['search'] !== ''
                && stripos($method['name'], $filter['search']) === false
                && stripos($method['product_id'], $filter['search']) === false) {
                return false;
            }
            if ($filter['group'] !== '' && $method['group'] !== $filter['group']) {
                return false;
            }
            if ($filter['country'] !== '' && !self::allows($method['countries'], $filter['country'])) {
                return false;
            }
            if ($filter['currency'] !== '' && !self::allows($method['currencies'], $filter['currency'])) {
                return false;
            }
            if ($filter['status'] === 'enabled' && !$method['enabled']) {
                return false;
            }
            if ($filter['status'] === 'disabled' && $method['enabled']) {
                return false;
            }

            return true;
        }));

        $total = count($matching);
        $page = $filter['page'];
        $items = array_slice($matching, ($page - 1) * $perPage, $perPage);
        $count = count($items);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'from' => $count > 0 ? ($page - 1) * $perPage + 1 : null,
            'to' => $count > 0 ? ($page - 1) * $perPage + $count : null,
            'error' => $all['error'],
        ];
    }

    /** Da li je proizvod u listi: Core podržava i pojedinačne kartične brendove, ali ih u listi nema. */
    public static function isListed(string $productId): bool
    {
        return PaymentProductId::isSupported($productId) && !in_array($productId, PaymentProductId::CARD_BRANDS, true);
    }

    /**
     * Jedna metoda: sačuvana konfiguracija, ili Core default kad još nije čuvana.
     *
     * @return array<string, mixed>|null PaymentMethodResponse::toArray(); null kad Core vrati grešku
     */
    public function get(string $productId): ?array
    {
        $response = AdminAPI::get()->payment($this->storeId)->getPaymentMethod($productId);

        return $response->isSuccessful() ? $response->toArray() : null;
    }

    /** @return string|null poruka greške, null kad je prošlo */
    public function enable(string $productId, bool $enabled): ?string
    {
        $response = AdminAPI::get()->payment($this->storeId)->enable($productId, $enabled);

        return $response->isSuccessful() ? null : $this->errorMessage($response);
    }

    /**
     * Zemlje i valute metode za formu: sačuvana lista zemalja (prazna = sve) i valute u kojima se metoda nudi,
     * što je za metodu bez sopstvene liste Default currency. Tako forma metode koja nikad nije podešavana počinje
     * sa Default currency već izabranom, kao forma nove metode u demu.
     *
     * @return array{countries: string[], currencies: string[]}
     */
    public function restriction(string $productId): array
    {
        return $this->withDefaultCurrency($this->restrictions()->get($productId));
    }

    /**
     * Upisuje zemlje i valute metode, i kad su se promenile ostavlja zapis u logu sa starim i novim listama.
     * Poredi se sa sačuvanim redom, ne sa onim što forma prikazuje: metoda koja je pratila Default currency i sad
     * dobija istu tu valutu kao sopstvenu listu jeste promena, i ona se upisuje.
     *
     * @param string[] $countries
     * @param string[] $currencies
     *
     * @return string|null poruka greške, null kad je prošlo
     */
    public function saveRestriction(string $productId, array $countries, array $currencies): ?string
    {
        try {
            $before = $this->restrictions()->get($productId);
            $after = ['countries' => $countries, 'currencies' => $currencies];
            if ($before === $after) {
                return null;
            }

            $this->restrictions()->save($productId, $countries, $currencies);
            Logger::logInfo('Payment method countries and currencies saved', self::COMPONENT, LoggerService::context([
                'productId' => $productId,
                'countries' => ['old' => $before['countries'], 'new' => $countries],
                'currencies' => ['old' => $before['currencies'], 'new' => $currencies],
            ]));
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** @return string|null poruka greške, null kad je prošlo */
    public function save(PaymentMethodRequest $request): ?string
    {
        $response = AdminAPI::get()->payment($this->storeId)->save($request);

        return $response->isSuccessful() ? null : $this->errorMessage($response);
    }

    /**
     * Aktivni jezici prodavnice: kod => naziv, podrazumevani jezik prodavnice (PS_LANG_DEFAULT) prvi, jer on daje
     * Core-ov default prevod. Kodovi su isti koje LanguageService daje Core-u.
     *
     * @return array<string, string>
     */
    public function languages(): array
    {
        $defaultId = (int) PrestaShopConfiguration::get('PS_LANG_DEFAULT');
        $languages = [];
        foreach (LanguageService::prestaShopLanguages() as $language) {
            $entry = [LanguageService::code($language) => (string) $language['name']];
            $languages = (int) $language['id_lang'] === $defaultId ? $entry + $languages : $languages + $entry;
        }

        return $languages;
    }

    /** @param string[] $codes ograničenje metode; prazno = bez ograničenja */
    private static function allows(array $codes, string $code): bool
    {
        return $codes === [] || in_array($code, $codes, true);
    }

    /**
     * Sačuvano ograničenje kakvo važi: prazna lista valuta postaje Default currency sa Settings stranice.
     *
     * @param array{countries: string[], currencies: string[]} $restriction
     *
     * @return array{countries: string[], currencies: string[]}
     */
    private function withDefaultCurrency(array $restriction): array
    {
        if ($restriction['currencies'] === []) {
            $restriction['currencies'] = [$this->defaultCurrency()];
        }

        return $restriction;
    }

    /** Jednom po objektu: ista vrednost za svaku metodu u listi. */
    private function defaultCurrency(): string
    {
        if ($this->defaultCurrency === null) {
            $this->defaultCurrency = (new SettingsService())->defaultCurrency();
        }

        return $this->defaultCurrency;
    }

    private function restrictions(): PaymentMethodRestrictionRepository
    {
        return new PaymentMethodRestrictionRepository($this->storeId);
    }

    private function errorMessage(Response $response): string
    {
        $error = $response->toArray();

        return (string) ($error['errorMessage'] ?? 'Unknown error.');
    }
}
