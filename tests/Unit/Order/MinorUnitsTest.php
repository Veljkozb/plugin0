<?php

namespace Tests\Unit\Order;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin0\Order\MinorUnits;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Amount;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Currency;

/**
 * Amounts typed by the merchant become integers in minor units without a float in between, and only when they
 * are positive and fit the currency's decimals.
 */
final class MinorUnitsTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int}>
     */
    public static function parsable(): array
    {
        return [
            'two decimals' => ['12.34', 'EUR', 1234],
            'one decimal' => ['12.3', 'EUR', 1230],
            'no decimals' => ['12', 'EUR', 1200],
            'trailing dot' => ['12.', 'EUR', 1200],
            'comma' => ['0,05', 'EUR', 5],
            'spaces around' => [' 7.00 ', 'EUR', 700],
            'zero-decimal currency' => ['1200', 'JPY', 1200],
            'three-decimal currency' => ['1.005', 'BHD', 1005],
            'float trap 0.1+0.2' => ['0.30', 'EUR', 30],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejected(): array
    {
        return [
            'empty' => ['', 'EUR'],
            'zero' => ['0', 'EUR'],
            'zero with decimals' => ['0.00', 'EUR'],
            'negative' => ['-5', 'EUR'],
            'plus sign' => ['+5', 'EUR'],
            'letters' => ['12abc', 'EUR'],
            'too many decimals' => ['12.345', 'EUR'],
            'decimals in zero-decimal currency' => ['12.5', 'JPY'],
            'thousands separator' => ['1 000', 'EUR'],
            'two dots' => ['1.2.3', 'EUR'],
            'exponent' => ['1e3', 'EUR'],
            'overflow' => ['99999999999999999999', 'EUR'],
        ];
    }

    #[DataProvider('parsable')]
    public function testParsesTextIntoMinorUnits(string $text, string $currency, int $expected): void
    {
        $amount = MinorUnits::parse($text, Currency::fromIsoCode($currency));

        self::assertNotNull($amount);
        self::assertSame($expected, $amount->getValue());
        self::assertSame($currency, $amount->getCurrency()->getIsoCode());
    }

    #[DataProvider('rejected')]
    public function testRejectsWhatIsNotAPositiveAmount(string $text, string $currency): void
    {
        self::assertNull(MinorUnits::parse($text, Currency::fromIsoCode($currency)));
    }

    public function testFormatsMinorUnitsBackToText(): void
    {
        $eur = Currency::fromIsoCode('EUR');

        self::assertSame('12.34 EUR', MinorUnits::format(Amount::fromInt(1234, $eur)));
        self::assertSame('0.05', MinorUnits::formatValue(5, $eur));
        self::assertSame('0.00', MinorUnits::formatValue(0, $eur));
        self::assertSame('-1.50', MinorUnits::formatValue(-150, $eur));
        self::assertSame('1200', MinorUnits::formatValue(1200, Currency::fromIsoCode('JPY')));
        self::assertSame('1.005', MinorUnits::formatValue(1005, Currency::fromIsoCode('BHD')));
    }

    public function testParseAndFormatRoundTrip(): void
    {
        $eur = Currency::fromIsoCode('EUR');

        self::assertSame('18.50', MinorUnits::formatValue(MinorUnits::parse('18.5', $eur)->getValue(), $eur));
    }
}
