<?php

namespace Plugin0\Controller\Admin;

use Plugin0\Bootstrap;
use Plugin0\Settings\LogLevel;
use Plugin0\Settings\Settings;
use Plugin0\Settings\SettingsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Settings stranica, prenesena iz dema (resources/js/settings/SettingsScreen.jsx): Log level i Rows per page,
 * sa Save i Restore defaults. GET prikazuje formu sa vrednostima na snazi; POST čuva (action=save) ili vraća
 * na default (action=restore), pa vodi nazad na GET. Pogrešan unos ne ide u bazu: forma se vraća sa porukom
 * ispod polja i sa onim što je uneto.
 *
 * Pravila (defaulti, granice, šta se loguje) su u SettingsService; kontroler samo čita zahtev i prevodi odgovor.
 * Kao u demu, stranica postoji samo dok je prodavnica povezana; inače vodi na Configuration.
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

        $service = new SettingsService();

        if (!$request->isMethod('POST')) {
            return $this->renderForm($service, $service->current()->toArray(), []);
        }

        if ($request->request->get('action') === 'restore') {
            $this->restore($service);

            return $this->redirectToRoute('plugin0_settings');
        }

        $submitted = [
            'log_level' => trim((string) $request->request->get('log_level', '')),
            'rows_per_page' => trim((string) $request->request->get('rows_per_page', '')),
        ];
        $errors = $this->validate($submitted);
        if ($errors !== []) {
            return $this->renderForm($service, $submitted, $errors);
        }

        $this->save($service, new Settings(LogLevel::fromName($submitted['log_level']), (int) $submitted['rows_per_page']));

        return $this->redirectToRoute('plugin0_settings');
    }

    /**
     * @param array<string, string> $submitted
     *
     * @return array<string, string> poruka po polju; prazno kad je sve ispravno
     */
    private function validate(array $submitted): array
    {
        $errors = [];

        if (LogLevel::fromName($submitted['log_level']) === null) {
            $errors['log_level'] = 'The log level must be Debug, Info, Warning or Error.';
        }

        $rows = $submitted['rows_per_page'];
        if ($rows === '') {
            $errors['rows_per_page'] = 'The rows per page field is required.';
        } elseif (!ctype_digit($rows)) {
            $errors['rows_per_page'] = 'The rows per page must be a whole number.';
        } elseif ((int) $rows < Settings::MIN_ROWS_PER_PAGE || (int) $rows > Settings::MAX_ROWS_PER_PAGE) {
            $errors['rows_per_page'] = sprintf(
                'The rows per page must be between %d and %d.',
                Settings::MIN_ROWS_PER_PAGE,
                Settings::MAX_ROWS_PER_PAGE
            );
        }

        return $errors;
    }

    private function save(SettingsService $service, Settings $settings): void
    {
        try {
            $saved = $service->save($settings);
        } catch (Throwable $e) {
            $this->addFlash('error', 'Settings could not be saved. ' . $e->getMessage());

            return;
        }

        // Core ignoriše bool iz update()/delete() repozitorijuma, pa se uspeh proverava ponovnim čitanjem.
        if ($saved->toArray() !== $settings->toArray()) {
            $this->addFlash('error', 'Settings could not be saved: the stored values differ from the submitted ones.');

            return;
        }

        $this->addFlash('success', 'Settings saved.');
    }

    private function restore(SettingsService $service): void
    {
        try {
            $restored = $service->restoreDefaults();
        } catch (Throwable $e) {
            $this->addFlash('error', 'Settings could not be restored. ' . $e->getMessage());

            return;
        }

        if ($restored->toArray() !== $service->defaults()->toArray()) {
            $this->addFlash('error', 'Settings could not be restored: a stored value is still there.');

            return;
        }

        $this->addFlash('success', 'Every setting is back at its default.');
    }

    /**
     * @param array<string, string|int> $form vrednosti polja: log_level (ime nivoa) i rows_per_page
     * @param array<string, string> $errors poruka po polju
     */
    private function renderForm(SettingsService $service, array $form, array $errors): Response
    {
        return $this->render(self::TEMPLATE, [
            'current_page' => 'settings',
            'connected' => true,
            'form' => $form,
            'errors' => $errors,
            'levels' => $service->logLevels(),
            'defaults' => $service->defaults()->toArray(),
            'min_rows' => Settings::MIN_ROWS_PER_PAGE,
            'max_rows' => Settings::MAX_ROWS_PER_PAGE,
        ]);
    }
}
