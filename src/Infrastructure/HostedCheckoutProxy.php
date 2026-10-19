<?php

namespace Plugin0\Infrastructure;

use OnlinePayments\Sdk\Domain\CreateHostedCheckoutRequest;
use Plugin0\Infrastructure\Sdk\HostedCheckoutClient as ReturnMacHostedCheckoutClient;
use OnlinePayments\Sdk\Merchant\HostedCheckout\HostedCheckoutClient as SdkHostedCheckoutClient;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers\CreateHostedCheckoutRequestTransformer;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers\CreateHostedCheckoutResponseTransformer;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers\FeedbacksTransformer;
use WOP\OnlinePayments\Core\Bootstrap\Sdk\MerchantClientFactory;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\PaymentAction;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\PaymentSettings;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedCheckout\HostedCheckoutSessionRequest;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\PaymentResponse;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\Token;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Monitoring\ContextLogProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\Cards\AuthorizationMode;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\ThreeDSSettings\ThreeDSSettings;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentMethodCollection;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Proxies\HostedCheckoutProxyInterface;

/**
 * Plugin0 zamena za Core Bootstrap\ApiFacades\PaymentProcessor\Proxies\HostedCheckoutProxy (registrovana u
 * Plugin0\Bootstrap::initServices pod istim ključem HostedCheckoutProxyInterface, što zamenjuje Core registraciju).
 *
 * Zašto: Core za pojedinačni kartični brend (npr. 1 = Visa, van PaymentProductId::SEPARATE_CAPTURE_SUPPORTED) u isti
 * CreateHostedCheckout zahtev stavi cardPaymentMethodSpecificInput.authorizationMode = FINAL_AUTHORIZATION, ali i
 * mobilePaymentMethodSpecificInput.authorizationMode = SALE i redirectPaymentMethodSpecificInput.requiresApproval =
 * false (CreateHostedCheckoutRequestTransformer, početne vrednosti). Worldline (provereno na preprod-u 2026-10-15,
 * plaćanja 10300558801 i 10300566008) tada kartično plaćanje obradi kao direct sale (status 9) umesto kao
 * autorizaciju (status 5). Kad su mobile i redirect deo usklađeni sa kartičnim, plaćanje ostaje u statusu 5.
 *
 * Šta radi: isto što i Core proxy (Core transformer gradi zahtev, Core SDK klijent ga šalje), samo pre slanja za
 * kartične brendove:
 *  1. ako je kartični authorizationMode FINAL_AUTHORIZATION, a trgovac je u Settings izabrao PRE_AUTHORIZATION
 *     (PaymentActionSetting::cardAuthorizationMode), zameni ga sa PRE_AUTHORIZATION; Core to ne ume za pojedinačni
 *     brend, jer PRE_AUTHORIZATION zna samo kroz konfiguraciju metode `cards`;
 *  2. uskladi mobile i redirect deo sa kartičnim authorizationMode-om, onako kako Core sam radi za proizvode sa
 *     odvojenim capture-om (grupa cards, Google Pay, PayPal...).
 * Posle slanja odgovor čita kroz Infrastructure\Sdk\HostedCheckoutClient, koji prihvata i polje returnmac malim slovima
 * (Worldline preprod od 2026-10-19); bez toga Core sačuva prazan returnHmac i povratak kupca ne nalazi transakciju.
 * Core se ne menja.
 */
class HostedCheckoutProxy implements HostedCheckoutProxyInterface
{
    private MerchantClientFactory $clientFactory;

    /** @var callable(): string vraća FINAL_AUTHORIZATION ili PRE_AUTHORIZATION, čita se tek pri slanju */
    private $cardAuthorizationMode;

    /** @param callable(): string $cardAuthorizationMode */
    public function __construct(MerchantClientFactory $clientFactory, callable $cardAuthorizationMode)
    {
        $this->clientFactory = $clientFactory;
        $this->cardAuthorizationMode = $cardAuthorizationMode;
    }

    public function createSession(
        HostedCheckoutSessionRequest $request,
        ThreeDSSettings $cardsSettings,
        PaymentSettings $paymentSettings,
        PaymentMethodCollection $paymentMethodCollection,
        array $supportedPaymentMethods,
        ?Token $token = null
    ): PaymentResponse {
        ContextLogProvider::getInstance()->setCurrentOrder($request->getCartProvider()->get()->getMerchantReference());

        $hostedCheckoutRequest = CreateHostedCheckoutRequestTransformer::transform(
            $request,
            $cardsSettings,
            $paymentSettings,
            $paymentMethodCollection,
            $supportedPaymentMethods,
            $token
        );
        self::alignAuthorizationModes($hostedCheckoutRequest, $request->getPaymentProductId(), ($this->cardAuthorizationMode)());

        $feedbacks = FeedbacksTransformer::transform();
        if ($feedbacks !== null) {
            $hostedCheckoutRequest->setFeedbacks($feedbacks);
        }

        // Plugin0 klijent oko SDK-ovog: odgovor čita i returnmac (mala slova), vidi Infrastructure\Sdk\CreateHostedCheckoutResponse.
        $sdkClient = $this->clientFactory->get()->hostedCheckout();
        $client = $sdkClient instanceof SdkHostedCheckoutClient ? new ReturnMacHostedCheckoutClient($sdkClient) : $sdkClient;

        return CreateHostedCheckoutResponseTransformer::transform($client->createHostedCheckout($hostedCheckoutRequest));
    }

    /**
     * Za kartični brend: ako je kartični režim FINAL_AUTHORIZATION, a $authorizeMode je PRE_AUTHORIZATION, kartični
     * režim postaje PRE_AUTHORIZATION (SALE se nikad ne dira). Zatim mobile authorizationMode = kartični
     * authorizationMode, a redirect requiresApproval = true tačno kad kartični režim nije SALE. Kartični režim je
     * merodavan jer u njega Core već uračuna globalnu akciju plaćanja, eksplicitnu akciju i per-metoda podešavanje
     * (CardPaymentMethodSpecificInputTransformer).
     * Za sve ostale proizvode (grupa cards, mobilne i redirect metode, Intersolve...) zahtev se ne dira.
     *
     * @param string|null $authorizeMode FINAL_AUTHORIZATION ili PRE_AUTHORIZATION; null ili drugo = bez zamene
     */
    public static function alignAuthorizationModes(CreateHostedCheckoutRequest $request, ?PaymentProductId $productId, ?string $authorizeMode = null): void
    {
        if ($productId === null || !$productId->isCardBrand()) {
            return;
        }

        $card = $request->getCardPaymentMethodSpecificInput();
        $mode = $card !== null ? $card->getAuthorizationMode() : null;
        if ($mode === null || $mode === '') {
            return;
        }

        if ($mode === PaymentAction::AUTHORIZE && $authorizeMode === AuthorizationMode::PRE_AUTHORIZATION) {
            $mode = AuthorizationMode::PRE_AUTHORIZATION;
            $card->setAuthorizationMode($mode);
        }

        $mobile = $request->getMobilePaymentMethodSpecificInput();
        if ($mobile !== null) {
            $mobile->setAuthorizationMode($mode);
        }

        $redirect = $request->getRedirectPaymentMethodSpecificInput();
        if ($redirect !== null) {
            $redirect->setRequiresApproval($mode !== PaymentAction::AUTHORIZE_CAPTURE);
        }
    }
}
