<?php

namespace Plugin0\Controller\Admin;

use InvalidArgumentException;
use Logeecom\Settings\Domain\LogLevel;
use Logeecom\Settings\Domain\Settings;
use Logeecom\Settings\Service\SettingsService;
use Plugin0\Bootstrap;
use Plugin0\Infrastructure\ShopCountriesAndCurrencies;
use Plugin0\Services;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Settings stranica iz dema (resources/js/settings/SettingsScreen.jsx, RestoreDialog.jsx i
 * app/Http/Controllers/Admin/SettingsController.php): Log level sa tekstom efekta, Default currency i Rows per
 * page, sa Save i Restore defaults. GET prikazuje formu sa vrednostima na snazi; POST čuva (action=save) ili vraća
 * na default (action=restore), pa vodi nazad na GET. Neispravan unos ne ide u bazu: forma se vraća sa porukom ispod
 * polja i sa onim što je uneto; pravila i poruke su iz demo UpdateSettingsRequest-a.
 *
 * Pravila (defaulti, granice, šta se loguje) su u demo SettingsService-u; kontroler samo čita zahtev i prevodi
 * odgovor. Kao u demu, stranica postoji samo dok je prodavnica povezana; inače vodi na Configuration.
 */
class SettingsController extends ConnectedController
{
    private const TEMPLATE = '@Modules/plugin0/views/templates/admin/settings.html.twig';

    public function indexAction(Request $request): Response
    {
        Bootstrap::boot();

        if (!$this->isConnected()) {
            return $this->redirectToRoute('plugin0_configuration');
        }

        $service = Services::settings();

        if (!$request->isMethod('POST')) {
            return $this->renderForm($service, $this->values($service->current()), []);
        }

        if ($request->request->get('action') === 'restore') {
            try {
                $service->restoreDefaults($this->employeeId());
                $this->addFlash('success', 'Every setting is back at its default.');
            } catch (Throwable $e) {
                $this->addFlash('error', 'Settings could not be restored. ' . $e->getMessage());
            }

            return $this->redirectToRoute('plugin0_settings');
        }

        $submitted = [
            'log_level' => trim(self::scalar($request->request->get('log_level'))),
            'default_currency' => trim(self::scalar($request->request->get('default_currency'))),
            'rows_per_page' => trim(self::scalar($request->request->get('rows_per_page'))),
        ];
        $errors = $this->validate($service, $submitted);
        if ($errors !== []) {
            return $this->renderForm($service, $submitted, $errors);
        }

        try {
            $service->update(
                new Settings(
                    $submitted['default_currency'],
                    (int) $submitted['rows_per_page'],
                    LogLevel::from($submitted['log_level'])
                ),
                $this->employeeId()
            );
            $this->addFlash('success', 'Settings saved.');
        } catch (InvalidArgumentException $e) {
            // Servis je odbio valutu (katalog se promenio između prikaza i čuvanja): poruka ispod polja, ništa nije upisano.
            return $this->renderForm($service, $submitted, ['default_currency' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->addFlash('error', 'Settings could not be saved. ' . $e->getMessage());
        }

        return $this->redirectToRoute('plugin0_settings');
    }

    /**
     * Pravila demo UpdateSettingsRequest-a: sva tri polja obavezna, valuta iz kataloga, redovi ceo broj u granicama,
     * nivo jedan od četiri.
     *
     * @param array{log_level: string, default_currency: string, rows_per_page: string} $submitted
     *
     * @return array<string, string> poruka po polju; prazno kad je sve ispravno
     */
    private function validate(SettingsService $service, array $submitted): array
    {
        $errors = [];

        if ($submitted['default_currency'] === '') {
            $errors['default_currency'] = 'The default currency field is required.';
        } elseif (!in_array($submitted['default_currency'], $service->allowedCurrencies(), true)) {
            $errors['default_currency'] = sprintf('The currency %s is not one the application knows.', $submitted['default_currency']);
        }

        $rows = $submitted['rows_per_page'];
        if ($rows === '') {
            $errors['rows_per_page'] = 'The rows per page field is required.';
        } elseif (preg_match('/^-?\d+$/', $rows) !== 1) {
            $errors['rows_per_page'] = 'The rows per page field must be an integer.';
        } elseif ((int) $rows < Settings::MIN_ROWS_PER_PAGE) {
            $errors['rows_per_page'] = sprintf('The rows per page field must be at least %d.', Settings::MIN_ROWS_PER_PAGE);
        } elseif ((int) $rows > Settings::MAX_ROWS_PER_PAGE) {
            $errors['rows_per_page'] = sprintf('The rows per page field must not be greater than %d.', Settings::MAX_ROWS_PER_PAGE);
        }

        if ($submitted['log_level'] === '') {
            $errors['log_level'] = 'The log level field is required.';
        } elseif (LogLevel::tryFrom($submitted['log_level']) === null) {
            $errors['log_level'] = 'The log level must be Debug, Info, Warning or Error.';
        }

        return $errors;
    }

    /**
     * @param array{log_level: string, default_currency: string, rows_per_page: string|int} $form vrednosti polja
     * @param array<string, string> $errors poruka po polju
     */
    private function renderForm(SettingsService $service, array $form, array $errors): Response
    {
        $shopCurrencies = ShopCountriesAndCurrencies::currencies();
        $currencies = [];
        foreach ($service->allowedCurrencies() as $code) {
            $currencies[$code] = $shopCurrencies[$code] ?? $code;
        }

        return $this->render(self::TEMPLATE, [
            'current_page' => 'settings',
            'connected' => true,
            'form' => $form,
            'errors' => $errors,
            'levels' => $service->logLevels(),
            'currencies' => $currencies,
            'min_rows' => Settings::MIN_ROWS_PER_PAGE,
            'max_rows' => Settings::MAX_ROWS_PER_PAGE,
        ]);
    }

    /**
     * @return array{log_level: string, default_currency: string, rows_per_page: int}
     */
    private function values(Settings $settings): array
    {
        return [
            'log_level' => $settings->logLevel->value,
            'default_currency' => $settings->defaultCurrency ?? '',
            'rows_per_page' => $settings->rowsPerPage,
        ];
    }

    /** ID zaposlenog prijavljenog u PrestaShop admin; svaka ruta ovog kontrolera je iza prijave, pa ga uvek ima. */
    private function employeeId(): int
    {
        return (int) $this->getContext()->employee->id;
    }

    /** @param mixed $value */
    private static function scalar($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
