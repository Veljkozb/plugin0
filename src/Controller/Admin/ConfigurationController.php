<?php

namespace Plugin0\Controller\Admin;

use Plugin0\Bootstrap;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\ConnectionAPI\Request\ConnectionRequest;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Connection\Repositories\ConnectionConfigRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;

/**
 * Configuration stranica modula: GET prikazuje formu, POST proverava kredencijale kod Worldline-a preko core-a,
 * a POST sa action=disconnect uklanja kredencijale okruženja izabranog u formi.
 * Stranica prikazuje stanje za svako okruženje posebno: aktivno, sačuvano ali neaktivno, ili nepovezano.
 * Secreti nikad ne idu u template, samo informacija da li su sačuvani.
 */
class ConfigurationController extends FrameworkBundleAdminController
{
    private const TEMPLATE = '@Modules/plugin0/views/templates/admin/configuration.html.twig';

    public function indexAction(Request $request): Response
    {
        Bootstrap::boot();

        if ($request->isMethod('POST')) {
            if ($request->request->get('action') === 'disconnect') {
                $this->disconnect((string) $request->request->get('environment', ''));
            } else {
                $this->save($request);
            }

            return $this->redirectToRoute('plugin0_configuration');
        }

        $stored = $this->storedConfiguration();
        $activeEnvironment = $stored['mode'] ?? null;

        // Podaci za oba okruženja, da šablon pri promeni padajućeg menija pokaže stanje baš tog okruženja.
        // Secreti ne idu u šablon, samo informacija da li su sačuvani.
        $environments = [];
        foreach (['test', 'live'] as $environment) {
            $credentials = $this->credentialsFor($stored, $environment);
            $environments[$environment] = [
                // Aktivno okruženje uvek ima kredencijale u core-u, i kad se ne mogu dešifrovati (prazan apiKey).
                'saved' => $environment === $activeEnvironment || $this->hasCredentials($credentials),
                'active' => $environment === $activeEnvironment,
                'pspid' => (string) ($credentials['pspid'] ?? ''),
                'api_key' => (string) ($credentials['apiKey'] ?? ''),
                'webhook_key' => (string) ($credentials['webhooksKey'] ?? ''),
                'has_api_secret' => !empty($credentials['apiSecret']),
                'has_webhook_secret' => !empty($credentials['webhooksSecret']),
            ];
        }

        return $this->render(self::TEMPLATE, [
            'environment' => $activeEnvironment ?? 'test',
            'environments' => $environments,
            'webhook_url' => $this->getContext()->link->getModuleLink('plugin0', 'webhook', [], true),
        ]);
    }

    private function save(Request $request): void
    {
        $environment = (string) $request->request->get('environment', '');
        if (!in_array($environment, ['test', 'live'], true)) {
            $this->addFlash('error', 'Environment must be Test or Live.');

            return;
        }

        $stored = $this->storedConfiguration();
        $storedCredentials = $this->credentialsFor($stored, $environment);

        $pspid = trim((string) $request->request->get('pspid', ''));
        $apiKey = trim((string) $request->request->get('api_key', ''));
        $apiSecret = trim((string) $request->request->get('api_secret', ''));
        $webhookKey = trim((string) $request->request->get('webhook_key', ''));
        $webhookSecret = trim((string) $request->request->get('webhook_secret', ''));

        // Prazno polje za secret znači "zadrži sačuvani", jer formu nikad ne punimo secretom.
        if ($apiSecret === '') {
            $apiSecret = (string) ($storedCredentials['apiSecret'] ?? '');
        }
        if ($webhookSecret === '') {
            $webhookSecret = (string) ($storedCredentials['webhooksSecret'] ?? '');
        }

        $missing = [];
        foreach (['PSPID' => $pspid, 'API Key' => $apiKey, 'API Secret' => $apiSecret, 'Webhook Key' => $webhookKey, 'Webhook Secret' => $webhookSecret] as $label => $value) {
            if ($value === '') {
                $missing[] = $label;
            }
        }
        if ($missing !== []) {
            $this->addFlash('error', 'Required: ' . implode(', ', $missing) . '.');

            return;
        }

        // Kredencijali drugog okruženja ostaju kakvi su sačuvani, da promena Test/Live ne obriše ništa.
        $other = $this->credentialsFor($stored, $environment === 'test' ? 'live' : 'test');
        $submitted = [$pspid, $apiKey, $apiSecret, $webhookKey, $webhookSecret];
        $kept = [
            $other['pspid'] ?? null,
            $other['apiKey'] ?? null,
            $other['apiSecret'] ?? null,
            $other['webhooksKey'] ?? null,
            $other['webhooksSecret'] ?? null,
        ];
        [$test, $live] = $environment === 'test' ? [$submitted, $kept] : [$kept, $submitted];

        $response = AdminAPI::get()->connection($this->storeId())->connect(
            new ConnectionRequest($environment, ...$test, ...$live)
        );

        if ($response->isSuccessful()) {
            $this->addFlash('success', 'Connected to Worldline. Configuration saved.');

            return;
        }

        $error = $response->toArray();
        $this->addFlash('error', 'Connection failed, nothing was saved. ' . ($error['errorMessage'] ?? 'Unknown error.'));
    }

    /**
     * Uklanja kredencijale okruženja koje je admin izabrao u formi i potvrdio.
     *
     * - Izabrano okruženje je aktivno: ConnectionConfigRepository::disconnect(). Ako drugo okruženje ima
     *   sačuvane kredencijale, ono postaje aktivno; ako nema, red ConnectionConfig se briše.
     * - Izabrano okruženje je sačuvano, ali nije aktivno: core nema metodu za to, pa ponovo čuvamo konekciju
     *   aktivnog okruženja bez kredencijala izabranog. Core pritom ponovo proverava aktivne kredencijale
     *   kod Worldline-a, kao na Save.
     *
     * Namerno ne zove AdminAPI::get()->generalSettings(...)->disconnect(). Taj core tok (DisconnectService)
     * traži ShopPaymentService, koji plugin još ne registruje, i red zadataka (QueueItem), koji još nije
     * podešen. Kad se to doda, ova metoda treba da pređe na taj poziv.
     */
    private function disconnect(string $environment): void
    {
        if (!in_array($environment, ['test', 'live'], true)) {
            $this->addFlash('error', 'Environment must be Test or Live.');

            return;
        }

        $stored = $this->storedConfiguration();
        $activeBefore = $stored['mode'] ?? null;

        // Važi i za staru stranicu u drugom tabu i za dupli klik: već uklonjeno okruženje se ne dira ponovo.
        $isSaved = $environment === $activeBefore || $this->hasCredentials($this->credentialsFor($stored, $environment));
        if ($activeBefore === null || !$isSaved) {
            $this->addFlash('warning', sprintf('%s is not connected, nothing was removed.', ucfirst($environment)));

            return;
        }

        $removed = $environment === $activeBefore
            ? $this->disconnectActive()
            : $this->removeInactive($stored, (string) $activeBefore);
        if (!$removed) {
            return;
        }

        $after = $this->storedConfiguration();
        $activeAfter = $after['mode'] ?? null;
        if ($activeAfter === $environment || $this->hasCredentials($this->credentialsFor($after, $environment))) {
            // Core ignoriše bool iz update()/delete(), pa neuspeo upis u bazu ne baca izuzetak.
            $this->addFlash('error', sprintf('Disconnect failed, %s credentials are still saved.', ucfirst($environment)));

            return;
        }

        if ($activeAfter === null) {
            $this->addFlash('success', sprintf('Disconnected from Worldline. %s credentials were removed.', ucfirst($environment)));
        } elseif ($activeAfter !== $activeBefore) {
            $this->addFlash('success', sprintf(
                '%s credentials were removed. %s credentials are still saved and now active.',
                ucfirst($environment),
                ucfirst((string) $activeAfter)
            ));
        } else {
            $this->addFlash('success', sprintf(
                '%s credentials were removed. %s stays active.',
                ucfirst($environment),
                ucfirst((string) $activeAfter)
            ));
        }
    }

    private function disconnectActive(): bool
    {
        try {
            /** @var ConnectionConfigRepositoryInterface $repository */
            $repository = ServiceRegister::getService(ConnectionConfigRepositoryInterface::class);
            // Repository čita prodavnicu iz StoreContext; AdminAPI to radi preko StoreContextAspect, mi ručno.
            StoreContext::doWithStore($this->storeId(), [$repository, 'disconnect']);
        } catch (Throwable $e) {
            $this->addFlash('error', 'Disconnect failed, nothing was removed. ' . $e->getMessage());

            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $stored */
    private function removeInactive(array $stored, string $activeEnvironment): bool
    {
        $active = $this->credentialsFor($stored, $activeEnvironment);
        $kept = [
            $active['pspid'] ?? null,
            $active['apiKey'] ?? null,
            $active['apiSecret'] ?? null,
            $active['webhooksKey'] ?? null,
            $active['webhooksSecret'] ?? null,
        ];
        $none = [null, null, null, null, null];
        [$test, $live] = $activeEnvironment === 'test' ? [$kept, $none] : [$none, $kept];

        $response = AdminAPI::get()->connection($this->storeId())->connect(
            new ConnectionRequest($activeEnvironment, ...$test, ...$live)
        );
        if (!$response->isSuccessful()) {
            $error = $response->toArray();
            $this->addFlash('error', 'Disconnect failed, nothing was removed. ' . ($error['errorMessage'] ?? 'Unknown error.'));

            return false;
        }

        return true;
    }

    /**
     * Isto pravilo kao ConnectionRequest::transformToDomainModel(): okruženje ima kredencijale ako ima API key.
     *
     * @param array<string, string|null> $credentials
     */
    private function hasCredentials(array $credentials): bool
    {
        return !empty($credentials['apiKey']);
    }

    /** @return array<string, mixed> Core odgovor: mode, sandboxData, liveData; samo webhookMode ako nema konekcije. */
    private function storedConfiguration(): array
    {
        $response = AdminAPI::get()->connection($this->storeId())->getConnectionConfig();

        return $response->isSuccessful() ? $response->toArray() : [];
    }

    /** @return array<string, string|null> */
    private function credentialsFor(array $stored, string $environment): array
    {
        return $stored[$environment === 'test' ? 'sandboxData' : 'liveData'] ?? [];
    }

    private function storeId(): string
    {
        return (string) $this->getContext()->shop->id;
    }
}