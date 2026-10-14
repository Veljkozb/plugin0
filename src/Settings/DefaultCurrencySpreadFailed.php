<?php

namespace Plugin0\Settings;

use RuntimeException;

/**
 * Podešavanje "Default currency" je sačuvano, ali nova valuta nije dodata u valute metoda plaćanja
 * (SettingsService::spreadDefaultCurrency). Posebna klasa, da kontroler ne javi "nije sačuvano" za nešto što jeste.
 */
final class DefaultCurrencySpreadFailed extends RuntimeException
{
}
