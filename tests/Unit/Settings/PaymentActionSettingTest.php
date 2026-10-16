<?php

namespace Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin0\Infrastructure\PaymentActionSetting;

/**
 * The three form choices map onto two stored values: the Core payment action (SALE or FINAL_AUTHORIZATION) and the
 * plugin card authorization mode (FINAL_AUTHORIZATION or PRE_AUTHORIZATION), and back.
 */
final class PaymentActionSettingTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function choices(): array
    {
        return [
            'sale' => [PaymentActionSetting::SALE, 'SALE', 'FINAL_AUTHORIZATION'],
            'final authorization' => [PaymentActionSetting::AUTHORIZE, 'FINAL_AUTHORIZATION', 'FINAL_AUTHORIZATION'],
            'pre-authorization' => [PaymentActionSetting::PRE_AUTHORIZE, 'FINAL_AUTHORIZATION', 'PRE_AUTHORIZATION'],
        ];
    }

    #[DataProvider('choices')]
    public function testChoiceSplitsIntoCoreActionAndCardMode(string $choice, string $coreAction, string $cardMode): void
    {
        self::assertTrue(PaymentActionSetting::isValid($choice));
        self::assertSame($coreAction, PaymentActionSetting::coreAction($choice));
        self::assertSame($cardMode, PaymentActionSetting::cardMode($choice));
    }

    #[DataProvider('choices')]
    public function testStoredValuesRebuildTheChoice(string $choice, string $coreAction, string $cardMode): void
    {
        self::assertSame($choice, PaymentActionSetting::choice($coreAction, $cardMode));
    }

    public function testSaleWinsWhateverTheCardModeSays(): void
    {
        self::assertSame(PaymentActionSetting::SALE, PaymentActionSetting::choice('SALE', 'PRE_AUTHORIZATION'));
        self::assertSame(PaymentActionSetting::SALE, PaymentActionSetting::choice('SALE', null));
    }

    public function testMissingCardModeMeansFinalAuthorization(): void
    {
        self::assertSame(PaymentActionSetting::AUTHORIZE, PaymentActionSetting::choice('FINAL_AUTHORIZATION', null));
    }

    public function testUnknownValuesAreInvalid(): void
    {
        self::assertFalse(PaymentActionSetting::isValid('AUTHORIZE'));
        self::assertFalse(PaymentActionSetting::isValid(''));
    }
}
