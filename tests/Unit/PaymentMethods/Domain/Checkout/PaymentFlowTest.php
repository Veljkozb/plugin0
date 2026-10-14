<?php

namespace Tests\Unit\PaymentMethods\Domain\Checkout;

use Logeecom\PaymentMethods\Domain\Checkout\IframeFlow;
use Logeecom\PaymentMethods\Domain\Checkout\RedirectFlow;
use PHPUnit\Framework\TestCase;

/**
 * The two products describe the same thing differently: what happens to the customer who pays this way.
 */
final class PaymentFlowTest extends TestCase
{
    public function test_redirect_says_the_customer_leaves_the_shop(): void
    {
        $flow = new RedirectFlow();

        self::assertSame('Redirect', $flow->label());
        self::assertStringContainsString('sent to the provider', $flow->description());
    }

    public function test_iframe_says_the_customer_stays_on_the_page(): void
    {
        $flow = new IframeFlow();

        self::assertSame('iFrame', $flow->label());
        self::assertStringContainsString('never leaves', $flow->description());
    }
}
