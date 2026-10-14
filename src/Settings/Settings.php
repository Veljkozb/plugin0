<?php

namespace Plugin0\Settings;

use InvalidArgumentException;

/**
 * Tri podešavanja Settings stranice kao jedna vrednost: nivo logovanja, podrazumevana valuta i broj redova po strani.
 * Granice se proveravaju ovde, pa Settings objekat koji postoji uvek nosi vrednosti na kojima plugin može da radi.
 * Odakle vrednosti dolaze i šta važi kad neka nedostaje odlučuje SettingsService.
 *
 * Za valutu se ovde proverava samo oblik (ISO 4217 kod, tri velika slova); da li je prodavnica ima uključenu zna
 * samo PrestaShop, pa to proverava SettingsController pri unosu i SettingsService pri čitanju.
 */
final class Settings
{
    public const MIN_ROWS_PER_PAGE = 5;
    public const MAX_ROWS_PER_PAGE = 100;

    private int $logLevel;
    private string $defaultCurrency;
    private int $rowsPerPage;

    /**
     * @param int $logLevel Core broj nivoa (vidi LogLevel)
     * @param string $defaultCurrency ISO 4217 kod ('EUR')
     * @param int $rowsPerPage koliko redova lista prikazuje na jednoj strani
     *
     * @throws InvalidArgumentException kad nivo nije jedan od četiri, valuta nije troslovni kod ili su redovi van granica
     */
    public function __construct(int $logLevel, string $defaultCurrency, int $rowsPerPage)
    {
        if (!LogLevel::isValid($logLevel)) {
            throw new InvalidArgumentException(sprintf('Log level must be one of the four Core levels, %d given.', $logLevel));
        }

        if (!self::isCurrencyCode($defaultCurrency)) {
            throw new InvalidArgumentException(sprintf('Default currency must be a three-letter ISO 4217 code, "%s" given.', $defaultCurrency));
        }

        if ($rowsPerPage < self::MIN_ROWS_PER_PAGE || $rowsPerPage > self::MAX_ROWS_PER_PAGE) {
            throw new InvalidArgumentException(sprintf(
                'Rows per page must be between %d and %d, %d given.',
                self::MIN_ROWS_PER_PAGE,
                self::MAX_ROWS_PER_PAGE,
                $rowsPerPage
            ));
        }

        $this->logLevel = $logLevel;
        $this->defaultCurrency = $defaultCurrency;
        $this->rowsPerPage = $rowsPerPage;
    }

    /** @param mixed $code */
    public static function isCurrencyCode($code): bool
    {
        return is_string($code) && preg_match('/^[A-Z]{3}$/', $code) === 1;
    }

    public function getLogLevel(): int
    {
        return $this->logLevel;
    }

    public function getDefaultCurrency(): string
    {
        return $this->defaultCurrency;
    }

    public function getRowsPerPage(): int
    {
        return $this->rowsPerPage;
    }

    /** @return array<string, string|int> vrednosti po ključu polja u formi, za poređenje i za log */
    public function toArray(): array
    {
        return [
            'log_level' => LogLevel::name($this->logLevel),
            'default_currency' => $this->defaultCurrency,
            'rows_per_page' => $this->rowsPerPage,
        ];
    }
}
