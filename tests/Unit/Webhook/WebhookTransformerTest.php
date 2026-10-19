<?php

namespace Tests\Unit\Webhook;

use OnlinePayments\Sdk\Communication\ConnectionResponse;
use OnlinePayments\Sdk\Communication\InvalidResponseException;
use OnlinePayments\Sdk\Webhooks\ApiVersionMismatchException;
use OnlinePayments\Sdk\Webhooks\SecretKeyNotAvailableException;
use OnlinePayments\Sdk\Webhooks\SignatureValidationException;
use PHPUnit\Framework\TestCase;
use Plugin0\Infrastructure\WebhookTransformer;
use Plugin0\Webhook\InvalidWebhookSignatureException;
use Plugin0\Webhook\ResponsePolicy;
use Plugin0\Webhook\UnprocessableWebhookException;
use Throwable;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Connection\ActiveConnectionProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Connection\ConnectionDetails;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Translations\Model\TranslatableLabel;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Webhook\Exceptions\WebhookMerchantMismatchException;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Webhook\Transformers\WebhookTransformerInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Webhook\WebhookData;

/**
 * The decorator leaves verification to the Core transformer and only translates the SDK RuntimeExceptions into
 * plugin exceptions whose error code the controller can act on (docs/WEBHOOK.md, 2.2).
 */
final class WebhookTransformerTest extends TestCase
{
    private const BODY = '{"apiVersion":"v1","id":"e1"}';
    private const HEADERS = ['X-GCS-KeyId' => 'k', 'X-GCS-Signature' => 's'];

    public function testVerifiedEventPassesThroughUntouched(): void
    {
        $data = new WebhookData('10300628270_0', '17', 'payment.captured', '2026-10-19T12:00:00+02:00', 'COMPLETED', 9, self::BODY);
        $inner = $this->inner(null, $data);

        $result = (new WebhookTransformer($inner, $this->provider(true)))->transform(self::BODY, self::HEADERS);

        self::assertSame($data, $result);
        self::assertSame([[self::BODY, self::HEADERS]], $inner->calls);
    }

    public function testSignatureFailureBecomesPluginExceptionWithSignatureCode(): void
    {
        $sdk = new SignatureValidationException("failed to validate signature 's'");
        $transformer = new WebhookTransformer($this->inner($sdk), $this->provider(true));

        try {
            $transformer->transform(self::BODY, self::HEADERS);
            self::fail('expected InvalidWebhookSignatureException');
        } catch (InvalidWebhookSignatureException $e) {
            self::assertSame(ResponsePolicy::ERROR_INVALID_SIGNATURE, $e->getTranslatableLabel()->getCode());
            self::assertStringContainsString("failed to validate signature 's'", $e->getMessage());
            self::assertSame($sdk, $e->getPrevious());
        }
    }

    public function testUnknownKeyIdBecomesPluginExceptionWithSignatureCode(): void
    {
        $transformer = new WebhookTransformer(
            $this->inner(new SecretKeyNotAvailableException('NOPE', 'could not find secret key for key id NOPE')),
            $this->provider(true)
        );

        $this->expectException(InvalidWebhookSignatureException::class);
        $this->expectExceptionMessage('could not find secret key for key id NOPE');

        $transformer->transform(self::BODY, self::HEADERS);
    }

    public function testShopWithoutActiveConnectionIsRejectedBeforeTheCoreRuns(): void
    {
        $inner = $this->inner(null, null);
        $transformer = new WebhookTransformer($inner, $this->provider(false));

        try {
            $transformer->transform(self::BODY, self::HEADERS);
            self::fail('expected InvalidWebhookSignatureException');
        } catch (InvalidWebhookSignatureException $e) {
            self::assertSame(ResponsePolicy::ERROR_INVALID_SIGNATURE, $e->getTranslatableLabel()->getCode());
        }

        self::assertSame([], $inner->calls, 'the Core transformer must not be asked to verify without credentials');
    }

    public function testApiVersionMismatchIsUnprocessable(): void
    {
        $transformer = new WebhookTransformer($this->inner(new ApiVersionMismatchException('v0', 'v1')), $this->provider(true));

        try {
            $transformer->transform(self::BODY, self::HEADERS);
            self::fail('expected UnprocessableWebhookException');
        } catch (UnprocessableWebhookException $e) {
            self::assertSame(ResponsePolicy::ERROR_UNPROCESSABLE, $e->getTranslatableLabel()->getCode());
            self::assertStringContainsString('"v0"', $e->getMessage());
            self::assertStringContainsString('"v1"', $e->getMessage());
        }
    }

    public function testBodyThatIsNotAnEventObjectIsUnprocessable(): void
    {
        $sdk = new InvalidResponseException(new ConnectionResponse(200, [], '[]'), 'Expected object, got array');
        $transformer = new WebhookTransformer($this->inner($sdk), $this->provider(true));

        try {
            $transformer->transform('[]', self::HEADERS);
            self::fail('expected UnprocessableWebhookException');
        } catch (UnprocessableWebhookException $e) {
            self::assertSame(ResponsePolicy::ERROR_UNPROCESSABLE, $e->getTranslatableLabel()->getCode());
            self::assertStringContainsString('Expected object, got array', $e->getMessage());
            self::assertSame($sdk, $e->getPrevious());
        }
    }

    public function testCoreTranslatableExceptionsPassThroughUnchanged(): void
    {
        $core = new WebhookMerchantMismatchException(new TranslatableLabel('mismatch', 'webhook.merchantIdMismatch'));
        $transformer = new WebhookTransformer($this->inner($core), $this->provider(true));

        try {
            $transformer->transform(self::BODY, self::HEADERS);
            self::fail('expected the Core exception');
        } catch (WebhookMerchantMismatchException $e) {
            self::assertSame($core, $e);
        }
    }

    /**
     * @return WebhookTransformerInterface&object{calls: array<int, array{string, array<string, string>}>}
     */
    private function inner(?Throwable $throws, ?WebhookData $returns = null): WebhookTransformerInterface
    {
        return new class($throws, $returns) implements WebhookTransformerInterface {
            /** @var array<int, array{string, array<string, string>}> */
            public array $calls = [];
            private ?Throwable $throws;
            private ?WebhookData $returns;

            public function __construct(?Throwable $throws, ?WebhookData $returns)
            {
                $this->throws = $throws;
                $this->returns = $returns;
            }

            public function transform(string $webhookBody, array $requestHeaders): WebhookData
            {
                $this->calls[] = [$webhookBody, $requestHeaders];
                if ($this->throws !== null) {
                    throw $this->throws;
                }

                return $this->returns ?? new WebhookData('', '', '', '', 'CREATED', 0, $webhookBody);
            }
        };
    }

    private function provider(bool $connected): ActiveConnectionProvider
    {
        $provider = $this->createStub(ActiveConnectionProvider::class);
        $provider->method('get')->willReturn($connected ? $this->createStub(ConnectionDetails::class) : null);

        return $provider;
    }
}
