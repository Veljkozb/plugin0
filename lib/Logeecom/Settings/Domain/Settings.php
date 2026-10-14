<?php

namespace Logeecom\Settings\Domain;

use InvalidArgumentException;

/**
 * The three settings of Requirement 4.1, as one value. They change how the application behaves; they are not
 * business data.
 *
 * Every property is readonly and the bounds are checked here, so a Settings object that exists is one the
 * application can run on. Where the values come from, and what applies when one is missing, is the service's
 * business.
 */
final class Settings
{
    public const MIN_ROWS_PER_PAGE = 5;

    public const MAX_ROWS_PER_PAGE = 100;

    /**
     * @param string|null $defaultCurrency The currency forms offer first; null when the catalogue has none to
     *                                     offer
     * @param int $rowsPerPage How many rows a list shows on one page, between the two bounds above
     * @param LogLevel $logLevel The lowest level that gets written
     *
     * @throws InvalidArgumentException if the rows per page are outside the bounds
     */
    public function __construct(
        public readonly ?string $defaultCurrency,
        public readonly int $rowsPerPage,
        public readonly LogLevel $logLevel,
    ) {
        if ($rowsPerPage < self::MIN_ROWS_PER_PAGE || $rowsPerPage > self::MAX_ROWS_PER_PAGE) {
            throw new InvalidArgumentException(sprintf(
                'Rows per page must be between %d and %d, %d given.',
                self::MIN_ROWS_PER_PAGE,
                self::MAX_ROWS_PER_PAGE,
                $rowsPerPage,
            ));
        }
    }

    /**
     * The settings as the pairs they are stored as. The currency's key is left out when there is none, so
     * storing it never writes an empty value that would later read as a currency.
     *
     * @return array<string, string> Values keyed by SettingKey value
     */
    public function toPairs(): array
    {
        $pairs = [
            SettingKey::RowsPerPage->value => (string) $this->rowsPerPage,
            SettingKey::LogLevel->value => $this->logLevel->value,
        ];

        if ($this->defaultCurrency !== null) {
            $pairs = [SettingKey::DefaultCurrency->value => $this->defaultCurrency] + $pairs;
        }

        return $pairs;
    }
}
