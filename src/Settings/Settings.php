<?php

namespace Plugin0\Settings;

use InvalidArgumentException;

/**
 * Dva podešavanja Settings stranice kao jedna vrednost: nivo logovanja i broj redova po strani.
 * Granice se proveravaju ovde, pa Settings objekat koji postoji uvek nosi vrednosti na kojima plugin može da radi.
 * Odakle vrednosti dolaze i šta važi kad neka nedostaje odlučuje SettingsService.
 *
 * Demo ima i "Default currency"; u plugin0 je nema, jer ništa u pluginu ne bi čitalo tu vrednost.
 */
final class Settings
{
    public const MIN_ROWS_PER_PAGE = 5;
    public const MAX_ROWS_PER_PAGE = 100;

    private int $logLevel;
    private int $rowsPerPage;

    /**
     * @param int $logLevel Core broj nivoa (vidi LogLevel)
     * @param int $rowsPerPage koliko redova lista prikazuje na jednoj strani
     *
     * @throws InvalidArgumentException kad nivo nije jedan od četiri ili su redovi van granica
     */
    public function __construct(int $logLevel, int $rowsPerPage)
    {
        if (!LogLevel::isValid($logLevel)) {
            throw new InvalidArgumentException(sprintf('Log level must be one of the four Core levels, %d given.', $logLevel));
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
        $this->rowsPerPage = $rowsPerPage;
    }

    public function getLogLevel(): int
    {
        return $this->logLevel;
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
            'rows_per_page' => $this->rowsPerPage,
        ];
    }
}
