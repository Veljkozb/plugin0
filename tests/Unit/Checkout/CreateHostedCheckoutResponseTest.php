<?php

namespace Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use Plugin0\Infrastructure\Sdk\CreateHostedCheckoutResponse;

/**
 * Worldline preprod returned the return MAC as RETURNMAC until 2026-10-16 and as returnmac from 2026-10-19. Both must
 * end up in getRETURNMAC(), otherwise the Core stores an empty returnHmac and the customer's return finds nothing.
 */
final class CreateHostedCheckoutResponseTest extends TestCase
{
    public function testUppercaseFieldIsReadAsBefore(): void
    {
        $response = (new CreateHostedCheckoutResponse())->fromJson('{"RETURNMAC":"c625807e","hostedCheckoutId":"10300628270","merchantReference":"17"}');

        self::assertSame('c625807e', $response->getRETURNMAC());
        self::assertSame('10300628270', $response->getHostedCheckoutId());
    }

    public function testLowercaseFieldIsReadToo(): void
    {
        $response = (new CreateHostedCheckoutResponse())->fromJson('{"returnmac":"7a1f0c2e","hostedCheckoutId":"10301086130","merchantReference":"18"}');

        self::assertSame('7a1f0c2e', $response->getRETURNMAC());
        self::assertSame('18', $response->getMerchantReference());
    }

    public function testUppercaseWinsWhenBothArePresent(): void
    {
        $response = (new CreateHostedCheckoutResponse())->fromJson('{"RETURNMAC":"upper","returnmac":"lower","hostedCheckoutId":"1"}');

        self::assertSame('upper', $response->getRETURNMAC());
    }

    public function testMissingFieldStaysNull(): void
    {
        $response = (new CreateHostedCheckoutResponse())->fromJson('{"hostedCheckoutId":"1"}');

        self::assertNull($response->getRETURNMAC());
    }
}
