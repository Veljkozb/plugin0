<?php
/**
 * Front controller "asyncprocess" modula plugin0: ulaz za Core-ove asinhrone procese (docs/BACKGROUND.md, 1.3 i 4.3).
 * Core adresu dobija od Plugin0\Configuration::getAsyncProcessUrl($guid) i šalje na nju zahtev svaki put kad pokreće
 * task runner ili jedan zadatak iz reda (AsyncProcessStarterService::start, AsyncProcessStarterService.php:77,
 * HttpClient čeka odgovor najviše 1 s). Ovaj kontroler zove AsyncProcessService::runProcess($guid): Core učita
 * sačuvani Process sa tim guid-om, izvrši ga i obriše (AsyncProcessStarterService.php:88-101).
 *
 * Redom:
 * 1. Controller::init(), ne FrontController::init(): Symfony container i trenutni locale, koje traže zadaci što prave
 *    ili menjaju narudžbine (PaymentModule::validateOrder), bez kolačića, korpe, geolokacije i stranice održavanja.
 *    Isti razlog i isti poziv kao u controllers/front/webhook.php (vidi njegov opis);
 * 2. ignore_user_abort(true) i set_time_limit(0): posao se nastavlja i kad pozivalac prekine vezu, i ne staje na
 *    max_execution_time;
 * 3. odgovor 200 odmah, pa fastcgi_finish_request(): pozivalac ne čeka posao, a PHP-FPM radnik ostaje zauzet samo
 *    dok posao traje;
 * 4. runProcess($guid). Nepoznat ili već izvršen guid ne radi ništa (AsyncProcessStarterService.php:93-97), pa adresi
 *    ne treba token: guid je nasumičan, jednokratan i zna ga samo server.
 *
 * Core u runProcess hvata Exception i upisuje ga u log; PHP Error (npr. TypeError) prolazi, pa ga ovde hvata
 * \Throwable i upisuje u Worldline > Logs. Zadatak koji je tako pao ostaje in_progress dok ga runner posle 300 s bez
 * znaka života ne vrati u red ili obori (docs/BACKGROUND.md, 2.2).
 */

use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\Interfaces\AsyncProcessService;

class Plugin0AsyncprocessModuleFrontController extends ModuleFrontController
{
    private const COMPONENT = 'AsyncProcess';

    public function init()
    {
        $this->initShopContext();
        \Plugin0\Bootstrap::boot();

        ignore_user_abort(true);
        set_time_limit(0);

        $guid = (string) Tools::getValue('guid', '');
        $this->answerAndDetach();

        try {
            if ($guid !== '') {
                ServiceRegister::getService(AsyncProcessService::CLASS_NAME)->runProcess($guid);
            }
        } catch (\Throwable $e) {
            Logger::logError('Async process stopped with an error', self::COMPONENT, \Plugin0\Infrastructure\LoggerService::context([
                'guid' => $guid,
                'error' => get_class($e) . ': ' . $e->getMessage(),
                'at' => $e->getFile() . ':' . $e->getLine(),
            ]));
        }

        exit;
    }

    /** Isto kao controllers/front/webhook.php: Controller::init bez FrontController dela. */
    protected function initShopContext(): void
    {
        \Controller::init();
    }

    /**
     * Pošalje kratak odgovor 200 i zatvori zahtev prema pozivaocu; skripta zatim radi dalje bez otvorene veze.
     * Pod PHP-FPM-om to radi fastcgi_finish_request(); bez njega (npr. mod_php) ostaje samo flush, pa pozivalac
     * dobije odgovor, ali veza ostaje otvorena dok posao ne završi ili dok je pozivalac sam ne prekine.
     */
    private function answerAndDetach(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Length: 2');
        header('Connection: close');
        echo 'OK';

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();

            return;
        }

        flush();
    }
}
