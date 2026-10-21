<?php

namespace Plugin0\BackgroundProcessing\AutoCapture;

use Plugin0\BackgroundProcessing\TaskAttempt;
use Plugin0\Infrastructure\LoggerService;
use Throwable;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Core\Infrastructure\Serializer\Interfaces\Serializable;
use WOP\OnlinePayments\Core\Infrastructure\Serializer\Serializer;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\Task;

/**
 * Zadatak u redu "plugin0-auto-capture": automatska naplata jednog plaćanja (docs/BACKGROUND.md, 7.3). Zakazuje ga
 * AutoCaptureScheduler; posao radi AutoCaptureProcessor::process.
 *
 * Neuspeh se baca dalje, pa Core QueueService::fail zadatak vraća u red do maksimuma pokušaja (Core default 6); posle
 * poslednjeg Core zove onFail, a ovde AutoCaptureProcessor::giveUp obeleži plaćanje kao neuspelo i pokaže ga trgovcu.
 * onFail Core zove i kada runner obori zadatak čiji je proces pao (300 s bez znaka života).
 *
 * Stanje zadatka (prodavnica, plaćanje, korpa) se čuva u QueueItem-u kroz toArray / fromArray (JsonSerializer).
 */
class AutoCapturePaymentTask extends Task
{
    private string $storeId;
    private string $paymentId;
    private string $merchantReference;

    public function __construct(string $storeId = '', string $paymentId = '', string $merchantReference = '')
    {
        $this->storeId = $storeId;
        $this->paymentId = $paymentId;
        $this->merchantReference = $merchantReference;
    }

    public function execute(): void
    {
        AutoCaptureProcessor::create()->process(
            $this->storeId,
            $this->paymentId,
            $this->merchantReference,
            TaskAttempt::current($this),
            TaskAttempt::max()
        );

        $this->reportProgress(100);
    }

    public function onFail(): void
    {
        // Core zove onFail usred QueueService::fail; izuzetak odavde bi prekinuo upis statusa failed, pa se ovde guta.
        try {
            AutoCaptureProcessor::create()->giveUp($this->storeId, $this->paymentId, $this->merchantReference);
        } catch (Throwable $e) {
            Logger::logError('Auto capture: the final failure could not be recorded', 'AutoCapture', LoggerService::context([
                'paymentId' => $this->paymentId,
                'cartId' => $this->merchantReference,
                'error' => get_class($e) . ': ' . $e->getMessage(),
            ]));
        }
    }

    public function getStoreId(): string
    {
        return $this->storeId;
    }

    public function getPaymentId(): string
    {
        return $this->paymentId;
    }

    public function getMerchantReference(): string
    {
        return $this->merchantReference;
    }

    public function toArray(): array
    {
        return [
            'storeId' => $this->storeId,
            'paymentId' => $this->paymentId,
            'merchantReference' => $this->merchantReference,
        ];
    }

    public static function fromArray(array $array): Serializable
    {
        return new static(
            (string) ($array['storeId'] ?? ''),
            (string) ($array['paymentId'] ?? ''),
            (string) ($array['merchantReference'] ?? '')
        );
    }

    public function serialize(): string
    {
        return Serializer::serialize($this->toArray());
    }

    public function unserialize(string $serialized): void
    {
        $data = Serializer::unserialize($serialized);
        $this->storeId = (string) ($data['storeId'] ?? '');
        $this->paymentId = (string) ($data['paymentId'] ?? '');
        $this->merchantReference = (string) ($data['merchantReference'] ?? '');
    }
}
