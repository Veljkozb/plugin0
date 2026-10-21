<?php

namespace Plugin0\Infrastructure;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentId;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Services\Payment\StatusUpdateService as CoreStatusUpdateService;

/**
 * Plugin0 podklasa Core StatusUpdateService-a, registrovana u Plugin0\Bootstrap::initServices pod Core ključem
 * (zamenjuje Core registraciju; konstruktor i zavisnosti su Core-ovi). Jedina izmena: updateOrderStatus radi pod
 * MySQL imenovanim zaključavanjem po ID-ju transakcije.
 *
 * Zašto (docs/WEBHOOK.md, 4): Core u updateOrderStatus prvo čita plaćanje od Worldline-a (GET /payments/{id}/details,
 * StatusUpdateService.php:63), pa tek onda zove ShopOrderService, koji zaključava korpu. Povratak kupca (return.php),
 * webhook i admin panel (PaymentManager) stižu u različitim PHP-FPM procesima, pa dva procesa mogu da pročitaju
 * Worldline u različitim trenucima i primene u obrnutom redu: zastareli snimak "autorizovano" (status 10) primenjen
 * posle "naplaćeno" (status 2) vraća narudžbinu unazad, uz mejl kupcu; hasBeenInState to ne sprečava, jer narudžbina u
 * tom statusu nikad nije bila. Zaključavanje korpe serijalizuje samo upise, ne i čitanja.
 *
 * Šta zaključavanje radi: GET_LOCK('plugin0_tx_<transactionId>_<baza>', 10) obuhvata i čitanje i primenu, pa ko
 * stigne drugi čeka, zatim čita sveže stanje i primenjuje njega (tiket: "whichever arrives second must see the current
 * state"). Ključ je ID transakcije, deo pre donje crte (PaymentId::getTransactionId), pa 10300628270_0 sa povratne
 * strane i 10300628270_1 iz webhook-a o naplati dele isto zaključavanje. Zaključavanje korpe u ShopOrderService ostaje
 * unutrašnje i uzima se uvek posle ovog, pa nema uzajamnog čekanja. Petlja čekanja na povratnoj strani
 * (WaitPaymentOutcomeProcess::startWaiting) je van updateOrderStatus, pa se zaključavanje drži samo za jedno čitanje i
 * jednu primenu. MySQL od 5.7.5 (ovde 8.0) dozvoljava više imenovanih zaključavanja po konekciji; PrestaShop koristi
 * jednu konekciju po zahtevu (Db::getInstance).
 *
 * Kad zaključavanje ne uspe za 10 s, baca se izuzetak: ErrorHandlingAspect ga pretvori u error odgovor, webhook
 * kontroler odgovori 503 (Worldline šalje ponovo), a povratna strana prikaže poruku da plaćanje nije potvrđeno.
 */
class StatusUpdateService extends CoreStatusUpdateService
{
    public function updateOrderStatus(PaymentId $paymentId, ?string $returnHmac = null): void
    {
        // Isto zaključavanje drži i automatska naplata (TransactionLock, BackgroundProcessing\AutoCapture).
        TransactionLock::run($paymentId->getTransactionId(), function () use ($paymentId, $returnHmac): void {
            parent::updateOrderStatus($paymentId, $returnHmac);
        });
    }
}
