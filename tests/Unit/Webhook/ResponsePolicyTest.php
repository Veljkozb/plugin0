<?php

namespace Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use Plugin0\Webhook\DeliveryOutcome;
use Plugin0\Webhook\InvalidWebhookSignatureException;
use Plugin0\Webhook\ResponsePolicy;
use Plugin0\Webhook\UnprocessableWebhookException;
use RuntimeException;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\Response\TranslatableErrorResponse;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\Exceptions\InvalidPaymentIdException;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentId;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Translations\Exceptions\BaseTranslatableException;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Translations\Exceptions\BaseTranslatableUnhandledException;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Translations\Model\TranslatableLabel;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Webhook\Exceptions\WebhookMerchantMismatchException;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\ApiFacades\WebhooksAPI\Response\WebhookResponse;

/**
 * The HTTP status Worldline gets is decided from the Core facade response alone (docs/WEBHOOK.md, 3.4): 200 for
 * everything a retry cannot fix, 503 only when the signature passed and our own processing failed.
 */
final class ResponsePolicyTest extends TestCase
{
    public function testSuccessIsProcessedWith200(): void
    {
        $outcome = ResponsePolicy::forResponse(new WebhookResponse());

        self::assertSame(DeliveryOutcome::PROCESSED, $outcome->kind());
        self::assertSame(200, $outcome->httpStatus());
        self::assertSame('', $outcome->errorCode());
    }

    public function testSuccessWithSkippedDuplicateIsDuplicateWith200(): void
    {
        $outcome = ResponsePolicy::forResponse(new WebhookResponse(), true);

        self::assertSame(DeliveryOutcome::DUPLICATE, $outcome->kind());
        self::assertSame(200, $outcome->httpStatus());
    }

    public function testInvalidSignatureIsRejectedWith200(): void
    {
        $response = TranslatableErrorResponse::fromError(new InvalidWebhookSignatureException("failed to validate signature 'abc='"));

        $outcome = ResponsePolicy::forResponse($response);

        self::assertSame(DeliveryOutcome::REJECTED, $outcome->kind());
        self::assertSame(200, $outcome->httpStatus());
        self::assertSame(ResponsePolicy::ERROR_INVALID_SIGNATURE, $outcome->errorCode());
        self::assertStringContainsString("failed to validate signature 'abc='", $outcome->errorMessage());
    }

    public function testMerchantMismatchIsRejectedWith200(): void
    {
        // Same label code as Core Bootstrap\Sdk\WebhookTransformer::validateMerchantId.
        $response = TranslatableErrorResponse::fromError(new WebhookMerchantMismatchException(
            new TranslatableLabel('Webhook merchant id does not match the configured merchant.', 'webhook.merchantIdMismatch')
        ));

        $outcome = ResponsePolicy::forResponse($response);

        self::assertSame(DeliveryOutcome::REJECTED, $outcome->kind());
        self::assertSame(200, $outcome->httpStatus());
    }

    public function testEventWithoutPaymentIdIsIgnoredWith200(): void
    {
        // The real Core exception: WebhookLogsService::logWebhook calls PaymentId::parse('') for token/payout events.
        try {
            PaymentId::parse('');
            self::fail('PaymentId::parse must reject an empty id');
        } catch (InvalidPaymentIdException $e) {
            $response = TranslatableErrorResponse::fromError($e);
        }

        $outcome = ResponsePolicy::forResponse($response);

        self::assertSame(DeliveryOutcome::IGNORED, $outcome->kind());
        self::assertSame(200, $outcome->httpStatus());
        self::assertSame(ResponsePolicy::CORE_ERROR_INVALID_PAYMENT_ID, $outcome->errorCode());
    }

    public function testUnreadableBodyIsUnprocessableWith200(): void
    {
        $response = TranslatableErrorResponse::fromError(new UnprocessableWebhookException('Expected object, got array'));

        $outcome = ResponsePolicy::forResponse($response);

        self::assertSame(DeliveryOutcome::UNPROCESSABLE, $outcome->kind());
        self::assertSame(200, $outcome->httpStatus());
        self::assertSame(ResponsePolicy::ERROR_UNPROCESSABLE, $outcome->errorCode());
    }

    public function testAnyOtherErrorIsFailedWith503(): void
    {
        // What ErrorHandlingAspect produces for a database or Worldline failure after a valid signature.
        $response = TranslatableErrorResponse::fromError(new BaseTranslatableUnhandledException(new RuntimeException('db down')));

        $outcome = ResponsePolicy::forResponse($response);

        self::assertSame(DeliveryOutcome::FAILED, $outcome->kind());
        self::assertSame(503, $outcome->httpStatus());
        self::assertSame('general.unhandled', $outcome->errorCode());
        self::assertStringContainsString('db down', $outcome->errorMessage());
    }

    public function testUnknownTranslatableCodeIsFailedWith503(): void
    {
        $response = TranslatableErrorResponse::fromError(new BaseTranslatableException(new TranslatableLabel('x', 'some.other.code')));

        self::assertSame(503, ResponsePolicy::forResponse($response)->httpStatus());
    }
}
