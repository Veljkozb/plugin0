<?php

namespace Plugin0\Infrastructure;

use Db;
use RuntimeException;

/**
 * MySQL imenovano zaključavanje po ID-ju Worldline transakcije (deo ID-ja plaćanja pre donje crte,
 * PaymentId::getTransactionId): GET_LOCK('plugin0_tx_<transactionId>_<baza>', 10). Koriste ga
 * Infrastructure\StatusUpdateService (čitanje Worldline-a i promena narudžbine, docs/WEBHOOK.md 4.5) i
 * BackgroundProcessing\AutoCapture\AutoCaptureProcessor (provera stanja i capture, docs/BACKGROUND.md 7.3), pa
 * webhook, povratak kupca, panel narudžbine i automatska naplata istog plaćanja ne rade u isto vreme.
 *
 * Ime važi za ceo MySQL server, pa sadrži i ime baze; MySQL ga ograničava na 64 znaka. Od MySQL 5.7.5 ista
 * konekcija može isto ime da zaključa više puta i mora toliko puta da ga otključa, pa automatska naplata, koja drži
 * zaključavanje, sme da pozove updateOrderStatus, koji ga uzima još jednom. PrestaShop koristi jednu konekciju po
 * zahtevu (Db::getInstance).
 */
final class TransactionLock
{
    public const DEFAULT_TIMEOUT_SECONDS = 10;

    /**
     * Izvrši $work pod zaključavanjem i vrati njegov rezultat; otključava i kad $work baci izuzetak.
     *
     * @throws RuntimeException kad zaključavanje nije dobijeno za $timeoutSeconds
     *
     * @return mixed
     */
    public static function run(string $transactionId, callable $work, int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS)
    {
        $db = Db::getInstance();
        $lockName = self::name($transactionId);

        if ((int) $db->getValue("SELECT GET_LOCK('" . $lockName . "', " . $timeoutSeconds . ')', false) !== 1) {
            throw new RuntimeException(sprintf(
                'Payment status lock for transaction %s not acquired within %d s.',
                $transactionId,
                $timeoutSeconds
            ));
        }

        try {
            return $work();
        } finally {
            $db->getValue("SELECT RELEASE_LOCK('" . $lockName . "')", false);
        }
    }

    public static function name(string $transactionId): string
    {
        return pSQL(substr('plugin0_tx_' . $transactionId . '_' . _DB_NAME_, 0, 64));
    }
}
