<?php

namespace Tests\Unit\PaymentMethods\Domain\Checkout;

use Logeecom\PaymentMethods\Domain\Checkout\IframeFlow;
use Logeecom\PaymentMethods\Domain\Checkout\PaymentFlowFactory;
use Logeecom\PaymentMethods\Domain\Checkout\PaymentFlowInterface;
use Logeecom\PaymentMethods\Domain\Checkout\RedirectFlow;
use Logeecom\PaymentMethods\Domain\PaymentMethodType;
use PHPUnit\Framework\TestCase;

/**
 * The Factory Method itself: every type gets its own flow class, and a type without an arm fails here
 * rather than on the first screen that asks what the type means.
 */
final class PaymentFlowFactoryTest extends TestCase
{
    public function test_redirect_gets_the_redirect_flow(): void
    {
        $flow = $this->factory()->for(PaymentMethodType::Redirect);

        self::assertInstanceOf(RedirectFlow::class, $flow);
    }

    public function test_iframe_gets_the_iframe_flow(): void
    {
        $flow = $this->factory()->for(PaymentMethodType::IFrame);

        self::assertInstanceOf(IframeFlow::class, $flow);
    }

    /**
     * The guard for the third type: whoever adds a case to the enum and forgets the factory arm fails here
     * and not on the first screen. Every flow also has to read differently, or two radio buttons would carry
     * the same word.
     */
    public function test_every_type_has_its_own_flow_class_with_its_own_label(): void
    {
        $factory = $this->factory();
        $classes = [];
        $labels = [];

        foreach (PaymentMethodType::cases() as $type) {
            $flow = $factory->for($type);

            self::assertInstanceOf(PaymentFlowInterface::class, $flow);
            self::assertNotSame('', $flow->label());
            self::assertNotSame('', $flow->description());
            $classes[] = $flow::class;
            $labels[] = $flow->label();
        }

        self::assertCount(count(PaymentMethodType::cases()), array_unique($classes));
        self::assertCount(count(PaymentMethodType::cases()), array_unique($labels));
    }

    private function factory(): PaymentFlowFactory
    {
        return new PaymentFlowFactory();
    }
}
