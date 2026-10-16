<?php

namespace Plugin0\Order;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Amount;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Currency;

/**
 * Iznos iz forme (tekst "12.34") u Core Amount (ceo broj u najmanjim jedinicama valute: 1234 za EUR) i nazad,
 * bez float-a u sredini: tekst se seče na ceo deo i decimale, pa se decimale dopune nulama do broja decimala
 * valute (Currency::getMinorUnits: 2 za EUR, 0 za JPY, 3 za BHD). Tiket traži da se novac ne računa kao float.
 *
 * Prihvata se samo pozitivan iznos sa najviše onoliko decimala koliko valuta ima; zarez važi kao decimalni
 * znak, razmaci oko teksta se brišu. Sve drugo (prazno, slova, negativno, nula, više decimala, hiljade sa
 * razmakom) daje null, a poruku o tome sastavlja OperationValidator.
 */
final class MinorUnits
{
    /** Ceo deo, pa po želji tačka ili zarez i decimale; vodeći znak nije dozvoljen. */
    private const PATTERN = '/^(\d+)(?:[.,](\d*))?$/';

    public static function parse(string $text, Currency $currency): ?Amount
    {
        if (preg_match(self::PATTERN, trim($text), $parts) !== 1) {
            return null;
        }

        $decimals = $currency->getMinorUnits();
        $fraction = $parts[2] ?? '';
        if (strlen($fraction) > $decimals) {
            return null;
        }

        $digits = $parts[1] . str_pad($fraction, $decimals, '0');
        // Granica PHP int-a (PHP_INT_MAX ima 19 cifara): duži tekst bi se tiho prelio.
        if (strlen($digits) > 18) {
            return null;
        }

        $value = (int) $digits;
        if ($value <= 0) {
            return null;
        }

        return Amount::fromInt($value, $currency);
    }

    /** "12.34 EUR" za 1234; "1200 JPY" za 1200; uvek tačka kao decimalni znak, uvek sve decimale valute. */
    public static function format(Amount $amount): string
    {
        return self::formatValue($amount->getValue(), $amount->getCurrency()) . ' ' . $amount->getCurrency()->getIsoCode();
    }

    /** Samo broj, za polje forme: "12.34". */
    public static function formatValue(int $value, Currency $currency): string
    {
        $decimals = $currency->getMinorUnits();
        $sign = $value < 0 ? '-' : '';
        $digits = (string) abs($value);

        if ($decimals === 0) {
            return $sign . $digits;
        }

        $digits = str_pad($digits, $decimals + 1, '0', STR_PAD_LEFT);

        return $sign . substr($digits, 0, -$decimals) . '.' . substr($digits, -$decimals);
    }
}
