<?php

namespace Plugin0\Webhook;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\Repositories\PaymentTransactionRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Webhook\WebhookData;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Services\Payment\StatusUpdateService;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Services\PaymentLinks\PaymentLinkTransactionService;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Services\Webhooks\WebhookService as CoreWebhookService;

/**
 * Plugin0 podklasa Core WebhookService-a, registrovana u Plugin0\Bootstrap::initServices pod Core ključem (zamenjuje
 * Core registraciju). Core je zove iz WebhooksController::process tek pošto je WebhookTransformer proverio potpis i
 * trgovca i pošto je WebhookLogsService upisao Core webhook log, pa svaka isporuka ostaje zabeležena kod Core-a.
 *
 * Šta dodaje (docs/WEBHOOK.md, 5.3 i 5.4):
 * - događaj čiji je ID (UUID "id" sa vrha tela) već u evidenciji ProcessedEvents se preskače: ne zove se
 *   StatusUpdateService, pa ni Worldline (GET /payments/{id}/details), ni ShopOrderService; preskok se zabeleži u
 *   SkippedDuplicate da ga front controller upiše u log, a Core-u se vraća običan uspeh (kontroler odgovara 200);
 * - inače parent::process (Core: lokalna transakcija -> StatusUpdateService::updateOrderStatus), pa tek posle
 *   uspešne obrade upis u evidenciju; obrada koja baci izuzetak (503 ka Worldline-u) ništa ne upiše, da bi ponovljena
 *   isporuka prošla obradu ponovo.
 * Ključ je samo ID događaja, ne par tip + payment.id: preskočiti nešto novo bi ostavilo narudžbinu zastarelu, a
 * obraditi logički duplikat košta samo Worldline poziv i završava bez promene (Core ponovo čita stanje,
 * ShopOrderService ne ulazi ponovo u status). Događaj bez ID-ja se ne proverava i ne beleži.
 */
class WebhookService extends CoreWebhookService
{
    private ProcessedEvents $processedEvents;

    public function __construct(
        StatusUpdateService $statusUpdateService,
        PaymentTransactionRepositoryInterface $paymentTransactionRepository,
        PaymentLinkTransactionService $paymentLinkTransactionService,
        ProcessedEvents $processedEvents
    ) {
        parent::__construct($statusUpdateService, $paymentTransactionRepository, $paymentLinkTransactionService);
        $this->processedEvents = $processedEvents;
    }

    public function process(WebhookData $webhook): void
    {
        $eventId = EventSummary::fromBody($webhook->getWebhookBody())->eventId;

        if ($eventId !== '' && $this->processedEvents->wasProcessed($eventId)) {
            SkippedDuplicate::note($eventId);

            return;
        }

        parent::process($webhook);

        if ($eventId !== '') {
            $this->processedEvents->record($eventId, $webhook->getType(), $webhook->getId(), $webhook->getMerchantReference());
        }
    }
}
