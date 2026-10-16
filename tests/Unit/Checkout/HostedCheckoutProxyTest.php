<?php

namespace Tests\Unit\Checkout;

use OnlinePayments\Sdk\Domain\CardPaymentMethodSpecificInput;
use OnlinePayments\Sdk\Domain\CreateHostedCheckoutRequest;
use OnlinePayments\Sdk\Domain\MobilePaymentMethodSpecificInput;
use OnlinePayments\Sdk\Domain\RedirectPaymentMethodSpecificInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin0\Infrastructure\HostedCheckoutProxy;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;

/**
 * For a single card brand the mobile and redirect parts of the Hosted Checkout request must carry the same
 * authorization mode as the card part, otherwise Worldline processes the card payment as a direct sale
 * (verified on preprod 2026-10-15). Other products are left exactly as Core built them.
 */
final class HostedCheckoutProxyTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function cardModes(): array
    {
        return [
            'final authorization' => ['FINAL_AUTHORIZATION', 'FINAL_AUTHORIZATION', true],
            'pre authorization' => ['PRE_AUTHORIZATION', 'PRE_AUTHORIZATION', true],
            'sale' => ['SALE', 'SALE', false],
        ];
    }

    #[DataProvider('cardModes')]
    public function testCardBrandAlignsMobileAndRedirectWithCardMode(string $cardMode, string $expectedMobile, bool $expectedApproval): void
    {
        $request = self::coreShapedRequest($cardMode);

        HostedCheckoutProxy::alignAuthorizationModes($request, PaymentProductId::parse('1'));

        self::assertSame($cardMode, $request->getCardPaymentMethodSpecificInput()->getAuthorizationMode());
        self::assertSame($expectedMobile, $request->getMobilePaymentMethodSpecificInput()->getAuthorizationMode());
        self::assertSame($expectedApproval, $request->getRedirectPaymentMethodSpecificInput()->getRequiresApproval());
    }

    public function testPreAuthorizationChoiceReplacesFinalAuthorizationForCardBrand(): void
    {
        $request = self::coreShapedRequest('FINAL_AUTHORIZATION');

        HostedCheckoutProxy::alignAuthorizationModes($request, PaymentProductId::parse('1'), 'PRE_AUTHORIZATION');

        self::assertSame('PRE_AUTHORIZATION', $request->getCardPaymentMethodSpecificInput()->getAuthorizationMode());
        self::assertSame('PRE_AUTHORIZATION', $request->getMobilePaymentMethodSpecificInput()->getAuthorizationMode());
        self::assertTrue($request->getRedirectPaymentMethodSpecificInput()->getRequiresApproval());
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function noReplacement(): array
    {
        return [
            'sale stays sale even with pre-authorization chosen' => ['SALE', 'PRE_AUTHORIZATION'],
            'final stays final when final chosen' => ['FINAL_AUTHORIZATION', 'FINAL_AUTHORIZATION'],
            'final stays final without a choice' => ['FINAL_AUTHORIZATION', null],
            'unknown choice is ignored' => ['FINAL_AUTHORIZATION', 'SOMETHING_ELSE'],
        ];
    }

    #[DataProvider('noReplacement')]
    public function testCardModeIsKeptWhenNoReplacementApplies(string $cardMode, ?string $choice): void
    {
        $request = self::coreShapedRequest($cardMode);

        HostedCheckoutProxy::alignAuthorizationModes($request, PaymentProductId::parse('1'), $choice);

        self::assertSame($cardMode, $request->getCardPaymentMethodSpecificInput()->getAuthorizationMode());
        self::assertSame($cardMode, $request->getMobilePaymentMethodSpecificInput()->getAuthorizationMode());
    }

    public function testPreAuthorizationChoiceDoesNotTouchOtherProducts(): void
    {
        $request = self::coreShapedRequest('FINAL_AUTHORIZATION');

        HostedCheckoutProxy::alignAuthorizationModes($request, PaymentProductId::cards(), 'PRE_AUTHORIZATION');

        self::assertSame('FINAL_AUTHORIZATION', $request->getCardPaymentMethodSpecificInput()->getAuthorizationMode());
        self::assertSame('SALE', $request->getMobilePaymentMethodSpecificInput()->getAuthorizationMode());
    }

    public function testEveryCardBrandIsAligned(): void
    {
        foreach (PaymentProductId::getAllCardBrands() as $brand) {
            $request = self::coreShapedRequest('FINAL_AUTHORIZATION');
            HostedCheckoutProxy::alignAuthorizationModes($request, $brand);
            self::assertSame('FINAL_AUTHORIZATION', $request->getMobilePaymentMethodSpecificInput()->getAuthorizationMode(), (string) $brand);
            self::assertTrue($request->getRedirectPaymentMethodSpecificInput()->getRequiresApproval(), (string) $brand);
        }
    }

    /**
     * @return array<string, array{?PaymentProductId}>
     */
    public static function untouchedProducts(): array
    {
        return [
            'no product (hosted checkout page)' => [null],
            'cards group' => [PaymentProductId::cards()],
            'google pay' => [PaymentProductId::googlePay()],
            'apple pay' => [PaymentProductId::applePay()],
            'paypal' => [PaymentProductId::paypal()],
            'intersolve' => [PaymentProductId::intersolve()],
        ];
    }

    #[DataProvider('untouchedProducts')]
    public function testOtherProductsKeepCoreValues(?PaymentProductId $productId): void
    {
        $request = self::coreShapedRequest('FINAL_AUTHORIZATION');

        HostedCheckoutProxy::alignAuthorizationModes($request, $productId);

        self::assertSame('SALE', $request->getMobilePaymentMethodSpecificInput()->getAuthorizationMode());
        self::assertFalse($request->getRedirectPaymentMethodSpecificInput()->getRequiresApproval());
    }

    public function testMissingPartsDoNotBreakAlignment(): void
    {
        $request = new CreateHostedCheckoutRequest();
        HostedCheckoutProxy::alignAuthorizationModes($request, PaymentProductId::parse('1'));
        self::assertNull($request->getMobilePaymentMethodSpecificInput());

        $card = new CardPaymentMethodSpecificInput();
        $request->setCardPaymentMethodSpecificInput($card);
        $mobile = new MobilePaymentMethodSpecificInput();
        $mobile->setAuthorizationMode('SALE');
        $request->setMobilePaymentMethodSpecificInput($mobile);
        HostedCheckoutProxy::alignAuthorizationModes($request, PaymentProductId::parse('1'));
        self::assertSame('SALE', $mobile->getAuthorizationMode(), 'no card mode: nothing to align with');
    }

    /** The request exactly as Core's CreateHostedCheckoutRequestTransformer builds it for a single card brand. */
    private static function coreShapedRequest(string $cardMode): CreateHostedCheckoutRequest
    {
        $card = new CardPaymentMethodSpecificInput();
        $card->setAuthorizationMode($cardMode);
        $card->setPaymentProductId('1');

        $mobile = new MobilePaymentMethodSpecificInput();
        $mobile->setAuthorizationMode('SALE');

        $redirect = new RedirectPaymentMethodSpecificInput();
        $redirect->setRequiresApproval(false);

        $request = new CreateHostedCheckoutRequest();
        $request->setCardPaymentMethodSpecificInput($card);
        $request->setMobilePaymentMethodSpecificInput($mobile);
        $request->setRedirectPaymentMethodSpecificInput($redirect);

        return $request;
    }
}
