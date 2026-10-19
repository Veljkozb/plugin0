<?php
/**
 * Front controller "webhook" modula plugin0: ovde Worldline šalje POST obaveštenja o promeni stanja plaćanja
 * (Merchant Portal > Developer > Webhooks, adresa sa Configuration strane). Adresa je javna, bez prijave i bez
 * PrestaShop tokena: front controller modula token ne proverava (FrontController::checkAccess vraća true).
 * Ceo tok, odluke i razlozi: docs/WEBHOOK.md.
 *
 * Kontroler radi samo tri stvari:
 * 1. sirovo telo (php://input, bajt za bajt, jer je potpis HMAC nad telom) i zaglavlja prosledi Core-u:
 *    WebhookAPI::get()->webhooks($storeId)->process($body, $headers). Potpis (X-GCS-KeyId, X-GCS-Signature),
 *    trgovca, Core webhook log i narudžbinu radi Core: WebhooksController -> WebhookTransformer ->
 *    WebhookLogsService -> WebhookService -> StatusUpdateService -> Plugin0\Infrastructure\ShopOrderService;
 * 2. Core odgovor prevede u HTTP status (Webhook\ResponsePolicy): 200 za obrađeno, za odbijen potpis ili trgovca,
 *    za tip događaja koji se ne obrađuje i za telo koje SDK ne može da pročita; 503 samo kad je potpis prošao, a
 *    naša obrada pala, da Worldline pošalje
 *    ponovo (5 puta: posle 10 min, 1 h, 2 h, 8 h, 24 h). Telo odgovora je prazno: Worldline gleda samo status;
 * 3. svaku isporuku upiše u ps_log (Worldline > Logs) sa ID-jem događaja, tipom, ID-jem plaćanja, korpom
 *    (merchantReference) i brojem ponavljanja (zaglavlje retry-count); odbijen potpis i pad obrade sa razlogom,
 *    preskočen duplikat (Webhook\WebhookService javlja preko SkippedDuplicate) kao "duplicate skipped".
 *
 * Odgovara u init(), umesto parent::init(): FrontController::init radi kolačić, korpu, geolokaciju i stranicu
 * održavanja, a za Worldline poziv ništa od toga ne treba; stranica ograničene zemlje ili održavanja bi vratila HTML
 * umesto statusa i događaj bi propao. Od osnovne inicijalizacije ipak treba deo koji radi Controller::init (ne
 * FrontController::init): Symfony container i trenutni locale u Context-u, jer PaymentModule::validateOrder zove
 * Tools::getContextLocale (PaymentModule.php:447) za cene u mejlu, a ContainerFinder container traži baš na
 * kontroleru iz Context-a; bez toga validateOrder puca sa ContainerNotFoundException posle upisa narudžbine i
 * ostavlja je polu-napravljenu (provereno 2026-10-19, narudžbina 17). Zato se zove Controller::init() direktno,
 * preskačući FrontController::init. Ostalo Context već ima: shop, jezik i link iz config/config.inc.php, a
 * validateOrder sam postavlja korpu, kupca, jezik, shop i valutu iz korpe (PaymentModule.php:224-233), pa narudžbina
 * može da nastane i iz zahteva bez kupčevog kolačića.
 *
 * Fasada ne baca izuzetke (ErrorHandlingAspect grešku upiše u log i vrati kao odgovor), pa ovde nema try/catch.
 */

use Plugin0\Infrastructure\LoggerService;
use Plugin0\Webhook\DeliveryOutcome;
use Plugin0\Webhook\EventSummary;
use Plugin0\Webhook\ResponsePolicy;
use Plugin0\Webhook\SkippedDuplicate;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\WebhookAPI\WebhookAPI;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;

class Plugin0WebhookModuleFrontController extends ModuleFrontController
{
    private const COMPONENT = 'Webhook';

    /** Worldline zaglavlja koja idu u log; vrednosti su tuđe, pa se skraćuju. */
    private const HEADER_KEY_ID = 'X-GCS-KeyId';
    private const HEADER_RETRY_COUNT = 'retry-count';
    private const MAX_LOGGED_LENGTH = 300;

    public function init()
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            header('Allow: POST');
            $this->respond(405);
        }

        $this->initShopContext();
        \Plugin0\Bootstrap::boot();

        $body = (string) file_get_contents('php://input');
        $headers = $this->requestHeaders();
        $summary = EventSummary::fromBody($body);

        $response = WebhookAPI::get()->webhooks((string) $this->context->shop->id)->process($body, $headers);
        $outcome = ResponsePolicy::forResponse($response, SkippedDuplicate::take() !== null);

        $this->log($outcome, $summary, $headers, strlen($body));
        $this->respond($outcome->httpStatus());
    }

    /**
     * Osnovna inicijalizacija bez FrontController dela: Controller::init pravi Symfony container (buildContainer) i
     * postavlja Context::currentLocale, što PaymentModule::validateOrder traži preko Tools::getContextLocale. Poziv
     * ide direktno na Controller (pretka FrontController-a), pa se kolačić, korpa, geolokacija i stranica
     * održavanja iz FrontController::init ne izvršavaju. Protected da bi probna skripta mogla da ga pozove.
     */
    protected function initShopContext(): void
    {
        \Controller::init();
    }

    /**
     * @param array<string, string> $headers
     */
    private function log(DeliveryOutcome $outcome, EventSummary $summary, array $headers, int $bodyLength): void
    {
        $context = $summary->toContext() + [
            'retryCount' => $this->header($headers, self::HEADER_RETRY_COUNT),
            'httpStatus' => $outcome->httpStatus(),
        ];

        switch ($outcome->kind()) {
            case DeliveryOutcome::PROCESSED:
                Logger::logInfo('Webhook processed', self::COMPONENT, LoggerService::context($context));

                return;
            case DeliveryOutcome::DUPLICATE:
                Logger::logInfo('Webhook duplicate skipped: event already processed', self::COMPONENT, LoggerService::context($context));

                return;
            case DeliveryOutcome::IGNORED:
                Logger::logInfo('Webhook ignored: event type not handled', self::COMPONENT, LoggerService::context($context + [
                    'errorCode' => $outcome->errorCode(),
                ]));

                return;
            case DeliveryOutcome::REJECTED:
                Logger::logWarning('Webhook rejected: signature or merchant check failed', self::COMPONENT, LoggerService::context($context + [
                    'keyId' => $this->header($headers, self::HEADER_KEY_ID),
                    'remoteAddress' => (string) Tools::getRemoteAddr(),
                    'bodyLength' => $bodyLength,
                    'errorCode' => $outcome->errorCode(),
                    'errorMessage' => substr($outcome->errorMessage(), 0, self::MAX_LOGGED_LENGTH),
                ]));

                return;
            case DeliveryOutcome::UNPROCESSABLE:
                Logger::logError('Webhook cannot be processed, answered 200 because a retry would not help', self::COMPONENT, LoggerService::context($context + [
                    'bodyLength' => $bodyLength,
                    'errorCode' => $outcome->errorCode(),
                    'errorMessage' => substr($outcome->errorMessage(), 0, self::MAX_LOGGED_LENGTH),
                ]));

                return;
            default:
                Logger::logError('Webhook processing failed, answered 503 so that Worldline retries', self::COMPONENT, LoggerService::context($context + [
                    'errorCode' => $outcome->errorCode(),
                    'errorMessage' => substr($outcome->errorMessage(), 0, self::MAX_LOGGED_LENGTH),
                ]));
        }
    }

    /**
     * Zaglavlja onako kako su stigla, imena kako ih je pošiljalac poslao: SDK SignatureValidator ih poredi bez
     * obzira na velika i mala slova. getallheaders() postoji i pod PHP-FPM-om i pod Apache modulom; rezerva gradi
     * ista zaglavlja iz $_SERVER (HTTP_X_GCS_SIGNATURE -> X-Gcs-Signature).
     *
     * @return array<string, string>
     */
    private function requestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return $headers;
            }
        }

        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (strpos($name, 'HTTP_') === 0 && is_string($value)) {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     */
    private function header(array $headers, string $name): string
    {
        foreach ($headers as $headerName => $value) {
            if (strcasecmp((string) $headerName, $name) === 0 && is_string($value)) {
                return substr($value, 0, self::MAX_LOGGED_LENGTH);
            }
        }

        return '';
    }

    /** Samo HTTP status, bez tela; ajaxDie prekida zahtev pre parent::init(), postProcess i prikaza teme. */
    private function respond(int $status): void
    {
        http_response_code($status);
        $this->ajaxDie('');
    }
}
