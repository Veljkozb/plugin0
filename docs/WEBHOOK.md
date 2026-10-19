# Webhook handling (Part 1): how a Worldline webhook is received, verified and applied

Ticket: "Webhook handling: receive, verify and process payment status updates", branch `feature/MIDU-1227`.
This is the written part the ticket asks for before the code: how the Core processes a webhook, why an invalid
signature still gets a 200, what the race with the return page is, and why idempotency is a requirement.
It describes the Core as it is (`wl-online-payments-direct/plugin-core-base` 1.0.7, `sdk-php` 6.2.0, both in
`vendor/`, never modified) and the plugin design that Part 2 implements. Paths below are relative to the module root
`modules/plugin0/`; Core paths are relative to `vendor/wl-online-payments-direct/plugin-core-base/src/`, SDK paths to
`vendor/wl-online-payments-direct/sdk-php/lib/OnlinePayments/Sdk/`.

## 1. The path of one event

| # | Who | What happens | Where |
|---|-----|--------------|-------|
| 1 | Worldline | One HTTP POST per event: a JSON object in the body, headers `X-GCS-KeyId`, `X-GCS-Signature`, `retry-count` | Merchant Portal > Developer > Webhooks, endpoint URL |
| 2 | PrestaShop | Routes `/en/module/plugin0/webhook` to the module front controller | `controllers/front/webhook.php` (added by this ticket) |
| 3 | plugin0 controller | Reads the raw body and the headers and hands them to the Core unchanged | `WebhookAPI::get()->webhooks($storeId)->process($body, $headers)` |
| 4 | Core | Verifies the signature and the merchant, writes a webhook log row, finds the local transaction, re-reads the payment from Worldline | `WebhooksController::process`, `WebhooksController.php:40-46` |
| 5 | Core -> plugin0 | Calls the integration's order service with the fresh payment state | `src/Infrastructure/ShopOrderService.php` |
| 6 | plugin0 -> PrestaShop | Creates the order or changes its status | `PaymentModule::validateOrder`, `OrderHistory::changeIdOrderState` |
| 7 | plugin0 controller | Answers Worldline with an HTTP status | section 3 |

The Core processes the event synchronously inside the HTTP request; there is no queue in this path
(`WebhookAPI.php:22-35` only adds the error, tenant and store aspects around `WebhooksController`).

## 2. How the Core processes a webhook

### 2.1 Receiving

`WebhooksController::process(string $webhookBody, array $requestHeaders)` does exactly three things, in this order
(`WebhooksController.php:42-45`):

1. `$this->transformer->transform($webhookBody, $requestHeaders)`: verification and parsing (2.2, 2.3);
2. `$this->webhookLogsService->logWebhook($webhook)`: the Core webhook log (2.4);
3. `$this->webhookService->process($webhook)`: the order update (2.5).

If any step throws, `ErrorHandlingAspect::applyOn` (`Bootstrap/ApiFacades/Aspects/ErrorHandlingAspect.php:22-35`)
logs the error and returns a `TranslatableErrorResponse` instead of throwing. The controller therefore never sees an
exception, only `isSuccessful()` and `toArray()` with `errorCode`, `errorMessage` and `statusCode`
(`TranslatableErrorResponse.php:27-30`).

The body must reach the Core byte for byte as Worldline sent it, because the signature is computed over the raw body
(2.2). The controller reads it with `file_get_contents('php://input')` and never re-encodes it. One POST carries one
event as a JSON object: the SDK maps the body into a single `WebhooksEvent` and rejects a JSON array
(`Domain/DataObject.php:48-54`, "Expected object, got array"). The Worldline documentation page wraps its examples in
`[ ]`; the SDK's own test uses a single object (`sdk-php/tests/.../WebhooksHelperTest.php:23-55`).

### 2.2 Signature verification

`Bootstrap\Sdk\WebhookTransformer::validate` (`Bootstrap/Sdk/WebhookTransformer.php:64-73`):

1. takes the webhook key and the webhook secret of the active connection (the Test or Live credentials saved on the
   Configuration page, decrypted by the plugin's `Encryptor`);
2. builds the SDK `InMemorySecretKeyStore([webhookKey => webhookSecret])` and `WebhooksHelper`;
3. calls `WebhooksHelper::unmarshal($webhookBody, $requestHeaders)` (`Webhooks/WebhooksHelper.php:48-59`).

Inside the SDK, `SignatureValidator::validateBody` (`Webhooks/SignatureValidator.php:35-47`):

- reads the headers `X-GCS-Signature` and `X-GCS-KeyId`; header names are matched case-insensitively
  (`getHeaderValue`, lines 51-59); a missing header throws `SignatureValidationException("could not find header ...")`;
- asks the key store for the secret of that key id; an unknown key id throws `SecretKeyNotAvailableException`
  (`Webhooks/InMemorySecretKeyStore.php:29-35`), so the `X-GCS-KeyId` Worldline sends must equal the webhook key
  saved in the plugin;
- computes `base64_encode(hash_hmac('sha256', $body, $secretKey, true))` and compares it to the header with
  `hash_equals`; a mismatch throws `SignatureValidationException("failed to validate signature ...")`;
- after the signature, `unmarshal` parses the JSON and rejects an `apiVersion` other than `v1`
  (`ApiVersionMismatchException`, lines 61-66).

All three SDK exceptions are plain `RuntimeException`s, not Core translatable exceptions. `ErrorHandlingAspect`
therefore logs them as "Unhandled error occurred." and returns `errorCode` `general.unhandled`
(`BaseTranslatableUnhandledException.php:17-20`), the same code as for a database or Worldline failure. The plugin
therefore wraps the Core transformer: `src/Infrastructure/WebhookTransformer.php` implements
`WebhookTransformerInterface`, delegates `transform()` to the Core class and translates only the exceptions
(registered in `src/Bootstrap.php::initServices` after `parent::initServices()`, which overwrites the Core
registration, `BootstrapComponent.php:251-253`):

- `SignatureValidationException`, including its subclass `SecretKeyNotAvailableException` (unknown key id), and a shop
  without an active connection (`ActiveConnectionProvider::get()` returns null; the Core class would fail with a
  `TypeError`) become `Plugin0\Webhook\InvalidWebhookSignatureException`, `errorCode` `plugin0.webhook.invalidSignature`;
- `ApiVersionMismatchException` and the SDK `InvalidResponseException` (a correctly signed body that is not a JSON
  event object: a JSON array, truncated JSON) become `Plugin0\Webhook\UnprocessableWebhookException`, `errorCode`
  `plugin0.webhook.unprocessable`;
- everything else passes through unchanged (`WebhookMerchantMismatchException` is already translatable).

Both plugin exceptions extend `BaseTranslatableException`, so `ErrorHandlingAspect` logs their message and returns
their code, and the controller can tell a signature failure apart from any other error (3.4).

### 2.3 Merchant check and transformation

After the signature, `validateMerchantId` (`WebhookTransformer.php:81-89`) requires the body's `merchantId` to equal the
PSPID of the active connection (lower-cased, `hash_equals`); otherwise `WebhookMerchantMismatchException`
(`errorCode` `webhook.merchantIdMismatch`). A correctly signed event for another merchant is never processed.

`doTransform` (`WebhookTransformer.php:35-63`) then builds `WebhookData(id, merchantReference, type, created,
statusCategory, statusCode, webhookBody)`:

- `id` is `payment.id` or `refund.id` from the body (for example `10300628270_0`; capture and refund events carry
  operation ids such as `10300628270_1`);
- `merchantReference` is the reference the plugin sent when the session was created: the PrestaShop cart id
  (`src/Checkout/PrestaShopCartProvider.php`);
- `type` is the event type (`payment.created`, `payment.captured`, `payment.refunded`, `refund.refund_requested`, ...);
- an event without a `payment` or `refund` object (token or payout events) becomes a "neutral" `WebhookData` with an
  empty id (lines 55-60).

`WebhookData` does not carry the top-level event `id` (the UUID of the delivery). It is only available inside the raw
body, which `WebhookData::getWebhookBody()` returns; the plugin reads it from there (section 5).

### 2.4 Webhook log

`WebhookLogsService::logWebhook` (`BusinessLogic/AdminConfig/Services/Monitoring/WebhookLogsService.php:51-56`) parses
the id with `PaymentId::parse` and stores a `WebhookLog` row (type `WebhookLog` in `ps_plugin0_entity`) with the
merchant reference, payment number, payment method name, mapped status, event type, creation time, status code and the
full body. Two details matter:

- for a payment the plugin knows, it asks Worldline for the payment method name (`tryToGetPayment`, lines 70-84), one
  outbound `GET /payments/{id}` per logged event; unknown ids never trigger a call;
- `PaymentId::parse('')` throws `InvalidPaymentIdException` (`Domain/Payment/PaymentId.php:34-38`), so the "neutral"
  event of 2.3 ends here with `errorCode` `payment.invalidPaymentIdFormat`. The plugin treats that code as "event type
  not handled" and still answers 200 (section 3).

### 2.5 Handing the verified event to the integration

`WebhookService::process` (`BusinessLogic/PaymentProcessor/Services/Webhooks/WebhookService.php:28-55`):

1. `PaymentId::parse($webhook->getId())`;
2. `paymentlink.paid` events take a separate path (not used by plugin0, which has no Pay by Link);
3. `$this->paymentTransactionRepository->get($paymentId)`: the local `PaymentTransaction` created at checkout, looked up
   by the transaction id, the part before `_` (`PaymentTransactionRepository.php:83-110`); an operation id such as
   `..._1` finds the same transaction;
4. if it exists: `$this->statusUpdateService->updateOrderStatus($paymentId)`; if not, nothing happens (the event is
   logged in 2.4 and answered with success).

`StatusUpdateService::updateOrderStatus` (`BusinessLogic/PaymentProcessor/Services/Payment/StatusUpdateService.php:58-98`)
is the single place where the integration is called, for webhooks and for the return page alike:

1. loads the transaction, line 60;
2. **re-reads the payment from Worldline**, `GET /payments/{id}/details`, line 63; the status that came in the webhook
   body is never used for the order;
3. saves the fresh status code and payment method on the local transaction, lines 64-66;
4. maps the Worldline state to a PrestaShop order status, line 67 (`StatusMappingService`; defaults from
   `src/Infrastructure/StoreService.php:57-67`: captured 2 "Payment accepted", pending and authorized 10 "Awaiting bank
   wire payment", cancelled 6, refunded 7, error 8);
5. chooses one integration method: `refundShopOrder` when anything was refunded (lines 81-87), `createShopOrder` when
   the payment is fully paid and authorized or completed (lines 88-92, through `createShopOrder` with the Core
   creation lock, lines 134-145), `cancelShopOrder` when cancelled or rejected (lines 93-96), otherwise `updateStatus`
   (line 97).

### 2.6 What the integration provides

`src/Infrastructure/ShopOrderService.php` implements the Core interface
`BusinessLogic\Domain\Integration\ShopOrderService` (registered in `src/Bootstrap.php:123-125`) and is the only class in
the plugin that calls `PaymentModule::validateOrder` and `OrderHistory::changeIdOrderState`:

- `createShopOrder` (lines 47-61): under a MySQL `GET_LOCK` per cart, creates the order if the cart has none, otherwise
  changes the status of the existing one;
- `updateStatus`, `cancelShopOrder`, `refundShopOrder` (lines 63-76): change the status of the cart's order if it exists;
  without an order (a cancelled or refused payment) nothing happens;
- `changeState` (lines 134-163) never re-enters a status the order already had (`ps_order_history`, lines 199-206),
  leaves an order in "Payment error" alone, and sends the status e-mail once per transition.

The webhook path adds no new integration method: it drives the same four methods, but from a request without the
customer's cookie. That works because `PaymentModule::validateOrder` sets the cart, customer, language, shop and
currency from the cart itself (`classes/PaymentModule.php:224-233`), and `OrderHistory::sendEmail` uses the order's
language.

One thing the request must still provide: `validateOrder` formats prices for the confirmation e-mail through
`Tools::getContextLocale` (`classes/PaymentModule.php:447`), which needs the Symfony container that `Controller::init`
builds (`classes/controller/Controller.php:204-210`) and that `ContainerFinder` looks up on the context's controller.
The webhook controller does not run `FrontController::init` (cookie, cart, geolocation and maintenance pages must not
answer a webhook), so it calls `Controller::init()` itself. Without that, `validateOrder` threw
`ContainerNotFoundException` after inserting the order and left it half-created (order 17 for cart 18, 2026-10-19).

### 2.7 Facts that shape the design

- Verification happens inside the Core, before anything is logged or applied; the plugin never computes an HMAC.
- The event's own status is never applied; the Core re-reads Worldline (2.5). A late or repeated event therefore applies
  the current state, not the state it describes.
- The Core calls `GET /payments/{id}/details` with the id exactly as it came, including an operation suffix
  (`WebhookService.php:37`, Core `PaymentsProxy.php:48-52` uses `(string) $paymentId`). Worldline answers that request
  for an operation id with the whole payment's current state: in the sandbox, `GET /payments/10300628270_1/details`
  returned HTTP 200 with `id` `10300628270_7`, status `REFUNDED`, the payment amount and the merchant reference
  (section 7), so a capture or refund event updates the order from the same data as a payment event. No id
  normalisation is needed.
- Errors never leave the facade as exceptions; the controller decides the HTTP status from `errorCode`.
- The processed-event id is not carried by `WebhookData`; the plugin takes it from the raw body.

## 3. Why an invalid signature still gets a 200 response

### 3.1 Worldline's delivery contract

From the Worldline Direct webhooks documentation (references in section 8):

- the endpoint must "respond to the POST action with a 2XX status code for all events delivered";
- when Worldline gets no 2xx, it retries five times, 10 minutes, 1 hour, 2 hours, 8 hours and 24 hours after the
  previous attempt; every delivery carries the header `retry-count` (0 for the first attempt);
- "Receiving duplicate webhook events is a feature of our reliable delivery architecture"; "Assume every webhook event
  might be repeated"; duplicates "have identical values for both properties payment.id and type";
- Worldline recommends answering 2xx right away and keeping the business logic decoupled from the delivery.

The Direct documentation does not describe disabling an endpoint after repeated failures; the sister platform Worldline
Connect documents a temporary blacklist for endpoints that fail too often. For Direct this remains unverified, so the
design does not rely on it either way.

### 3.2 What a non-2xx answer to a bad signature would cause

A webhook whose signature does not verify is either a forgery, a replay after a key rotation, or a delivery signed with
a key the shop does not have (shared PSPID, regenerated keys). In all three cases re-sending the same bytes cannot make
them verify. Answering 401 or 403 would make Worldline deliver the identical message five more times over 35 hours,
each attempt rejected again, and would count against the endpoint's reliability for no benefit. After a key rotation it
would turn every in-flight legitimate event into a 35-hour stream of failures.

### 3.3 What a different status code would tell an attacker

Nothing useful is gained by the sender from a 200, because the shop processes nothing: the Core throws before the log
and before the order update (2.1). A 401 or 403, on the other hand, confirms that the endpoint exists, that it verifies
signatures, and that this particular signature failed. The SDK messages even differ between "could not find header",
"could not find secret key for key id X" and "failed to validate signature" (`SignatureValidator.php:37-45`), which
would let a sender probe whether a key id is known. The plugin therefore answers an identical empty 200 for every
rejected event and keeps the reason only in the shop's own log (section 6).

### 3.4 Response policy

| Core result (`errorCode`) | HTTP | Log line (ps_log, component `Webhook`) |
|---|---|---|
| success | 200 | info: received and processed; event id, type, payment id, cart id, `retry-count` |
| `plugin0.webhook.invalidSignature` (`src/Infrastructure/WebhookTransformer.php`) or `webhook.merchantIdMismatch` | 200 | warning: rejected; key id header, remote address, SDK message |
| `payment.invalidPaymentIdFormat` (no payment or refund object in the event) | 200 | info: event type not handled |
| `plugin0.webhook.unprocessable` (valid signature, but `apiVersion` is not `v1` or the body is not a JSON event object) | 200 | error: cannot be processed, a retry would not help; `errorCode`, `errorMessage`, body length |
| duplicate event (skipped inside the plugin's `WebhookService`, the Core returns success, the controller reads `SkippedDuplicate`) | 200 | info: duplicate skipped; event id, type, payment id, cart id |
| any other error: database, Worldline unreachable, lock not acquired | 503 | error: processing failed; `errorCode`, `errorMessage` |

### 3.5 Why 503, and only after a valid signature

A 503 is the one case where a retry can help: the event was genuine, but the shop could not apply it (database down,
Worldline details call failed, lock timeout). plugin0 has no task queue and does not run the Core's
`TransactionStatusCheckTask`, so Worldline's five retries are the only automatic second chance; a 200 here would mean
the order is never updated unless a customer returns or a merchant opens the order page. The 503 is sent only when the
signature and merchant check have passed, so an unauthenticated sender can never trigger it.

## 4. The race with the return page

### 4.1 Two callers, one service

Both the customer's return and the webhook end in `StatusUpdateService::updateOrderStatus`, in two different PHP-FPM
processes, in any order or at the same moment:

- return page `controllers/front/return.php`: `CheckoutAPI::get()->payment()->updateOrderStatus` (line 68), then
  `getPaymentOutcome` (line 75), `startWaitingForOutcome` while the payment is pending (line 78) and `getPaymentOutcome`
  again (line 85). Each of these can call `updateOrderStatus` (`WaitPaymentOutcomeProcess.php:53, 74, 80`), so one return
  request calls it between one and several times;
- webhook: `WebhookService::process` (`WebhookService.php:37`) calls it once per event.

Each call reads Worldline (`StatusUpdateService.php:63`) and then calls `ShopOrderService`.

### 4.2 What already protects the order (from MIDU-1221)

- `ShopOrderService::withCartLock` (lines 170-184): every change for one cart runs under `GET_LOCK('plugin0_cart_<id>_<db>', 10)`;
- one order per cart: `orderIdForCart` (lines 191-197) is checked under the lock before `validateOrder`; without that
  check PrestaShop would either create a second order (two processes passing `Cart::OrderExists()` before either
  inserts, `PaymentModule.php:250-256`) or end the second process with `die()` and HTTP 200, losing the webhook silently;
- `hasBeenInState` (lines 199-206): a status the order already had is never set again, so no second history row, no
  second e-mail, no second note;
- the Core's own `lockOrderCreation` (`PaymentTransactionRepository.php:111-137`) is a check-then-insert on
  `ps_plugin0_entity` without a unique key, with a 30-second expiry; it narrows the window but is not atomic.

### 4.3 The remaining gap: a stale snapshot applied second

The Worldline read happens before the cart lock, so two processes can read at different moments and apply in the
opposite order. Simplified timeline, A = return page, B = webhook `payment.created` or `payment.pending_capture`,
Worldline moves the payment from "authorized" (status code 5) to "captured" (9) between the two reads:

| t | Process B (webhook) | Process A (return page) |
|---|---|---|
| t1 | reads Worldline: status 5, maps to 10 "Awaiting bank wire payment" | |
| t2 | | reads Worldline: status 9, maps to 2 "Payment accepted" |
| t3 | | `GET_LOCK` cart, no order yet, `validateOrder` creates the order in state 2, `RELEASE_LOCK` |
| t4 | `GET_LOCK` cart, order exists, `changeState(10)`; `hasBeenInState(10)` is false, the order never had it | |
| t5 | the order moves from "Payment accepted" back to "Awaiting bank wire payment", with an e-mail | |

The same shape appears with a refund webhook and a late return, or with two webhooks for consecutive states. In plain
words: the lock serialises the writes, but not the reads, so the second writer can write older information.

### 4.4 The same-moment case

When both read the same state (both see 9), both try to create the order. The Core creation lock may let both through
(4.2); the cart lock then serialises them: the second finds the order (`orderIdForCart > 0`), calls `changeState(2)`,
`hasBeenInState(2)` is true, nothing happens. This case is already safe.

### 4.5 The fix: one lock around read and apply

The plugin subclass `src/Infrastructure/StatusUpdateService.php` of the Core `StatusUpdateService` is registered in
`src/Bootstrap.php::initServices` after `parent::initServices()`, with the same eight dependencies as the Core
registration (`BootstrapComponent.php:206-207`); `ServiceRegister::register` overwrites the previous delegate
(`Infrastructure/ServiceRegister.php:98`), and every Core caller (`WebhookService`, `WaitPaymentOutcomeProcess`, the
order panel through `CheckoutAPI`) resolves the service lazily from the register (`BootstrapComponent.php:204, 234`).
The override wraps `parent::updateOrderStatus()` in `GET_LOCK('plugin0_tx_<transactionId>_<database>', 10)` and
releases it in a `finally` block:

- the lock covers the Worldline read and the `ShopOrderService` call together, so whichever process arrives second waits,
  then reads the current state and applies that: the ticket's "whichever arrives second must see the current state";
- the transaction id (the part before `_`) is the key, so `10300628270_0` from the return page and `10300628270_1` from a
  capture webhook share the lock; the cart lock inside `ShopOrderService` stays as the inner lock, always taken after
  the transaction lock, so there is no deadlock;
- the return page's wait loop (`WaitPaymentOutcomeProcess.php:48-52`) runs outside `updateOrderStatus`, so the lock is
  held only for one read and one apply, typically one to two seconds;
- a lock that cannot be acquired within 10 seconds throws, the Core turns it into an error response, the controller
  answers 503 and Worldline retries (3.5); on the return page the customer sees the "could not be confirmed" message;
- several named locks per connection and re-acquiring a held lock need MySQL 5.7.5 or MariaDB 10.0.2; the dev shop runs
  MySQL 8.0.40, and PrestaShop uses one connection per request (`Db::getInstance()`).

## 5. Why idempotency is a requirement

### 5.1 The delivery guarantee

Worldline delivers at least once: retries after a missing 2xx, plus duplicates that are "a feature of our reliable
delivery architecture" (3.1). The same event can reach the shop twice within seconds, or 24 hours later.

### 5.2 What processing an event twice would do without protection

- `OrderHistory::changeIdOrderState` + `addWithemail` (`classes/order/OrderHistory.php:83, 446`) would add a second
  history row and send the status e-mail to the customer a second time;
- a repeated `payment.created` applied after `payment.captured` would set an older status on a newer order (4.3);
- two `createShopOrder` calls could create two orders for one cart, or kill the second process with `die()` (4.2);
- the Worldline details call and the method-name call would be repeated for every delivery.

### 5.3 Three layers that make it a no-op

1. **The Core never applies the event's status.** `updateOrderStatus` re-reads Worldline (2.5), so a repeated or late
   event applies the state the payment is in now. A duplicate `payment.captured` reads "captured" again.
2. **`ShopOrderService` refuses repeated transitions.** One order per cart, no re-entry into a status the order already
   had, no second note (2.6, 4.2). "A transition the order has already gone through" results in no change.
3. **The plugin skips exact duplicates before the Core touches the order.** The entity
   `src/Entity/WebhookEventEntity.php` (type `WebhookEvent` in `ps_plugin0_entity`, indexed by event id, payment id and
   time) and the plugin subclass `src/Webhook/WebhookService.php` of the Core `WebhookService` (registered under the
   Core key in `src/Bootstrap.php::initServices`). Its `process()` reads the top-level `id` from
   `WebhookData::getWebhookBody()`; if `src/Webhook/ProcessedEvents.php` already holds that id, it notes the skip in
   `src/Webhook/SkippedDuplicate.php` and returns without calling `parent::process()`, so neither the Worldline details
   call nor `ShopOrderService` run; otherwise it calls `parent::process()` and records the id only after it succeeded.
   The Core returns success either way; the controller reads the note and logs "duplicate skipped" with the event id,
   type, payment id and cart id, and answers 200. The skip happens after `logWebhook` (2.4), so the Core webhook log
   still gets one row per delivery (and, for a known payment, its payment-method lookup). Each record also deletes
   entries older than 30 days; the check-then-insert is not atomic, which is acceptable because correctness rests on
   layers 1 and 2 and on the transaction lock (4.5), not on this table.

Recording after success, not before, is what keeps retries useful: a delivery that failed with 503 is not recorded,
so the retry is processed again.

### 5.4 The deduplication key: the event id, not (type, payment.id)

Worldline says duplicates share `payment.id` and `type`, but does not say whether a retry keeps the top-level `id`.
Keying on `(type, payment.id)` would be the broader filter, but it is the dangerous direction: skipping an event that
was in fact new would leave the order stale until the next event or a merchant visit, while processing a logical
duplicate costs only two Worldline calls and ends in layers 1 and 2 as a no-op. The plugin therefore skips only exact
duplicates (same event `id`) and lets the Core's re-read handle the rest. Events without a top-level `id` are neither
checked nor recorded.

## 6. What is logged, and where

| Event | Where | Content |
|---|---|---|
| every verified delivery | Core `WebhookLog` row in `ps_plugin0_entity` (2.4) | merchant reference, payment number, type, status, full body, created at |
| every delivery, accepted or not | `ps_log` line from the controller, prefix `WORLDLINE`, component `Webhook` | event id, type, payment id, cart id, `retry-count`, outcome, HTTP status answered |
| signature or merchant failure | `ps_log` warning (controller) + Core error line from `ErrorHandlingAspect` | key id header, remote address, SDK message |
| duplicate skipped | `ps_log` info (controller, after the note from the plugin `WebhookService`) | event id, type, payment id, cart id, `retry-count` |
| unprocessable body with a valid signature | `ps_log` error (controller) + Core error line | `errorCode`, `errorMessage`, body length; answered 200 |
| processing failed | `ps_log` error (controller) + Core error line | `errorCode`, `errorMessage` |
| order created or status changed | `ps_log` info (`ShopOrderService`, existing) | order id, payment id, state |

The plugin's Logs page (Worldline > Logs) reads `ps_log`, so the controller lines are visible there; the Core
`WebhookLog` rows are read with the DB checks described in `docs/VODIC.md`.

## 7. Verified, assumed, still open

Verified by reading the code and the Worldline documentation (2026-10-19):

- the Core verification order, the HMAC formula, the header matching and the exception types (2.2);
- the merchant check, the `WebhookData` content and the missing event id (2.3);
- the re-read of Worldline and the single integration entry point (2.5);
- the retry schedule, the `retry-count` header and the duplicate statement (3.1);
- the read-before-lock gap in the return and webhook paths (4.3);
- the public path through the ngrok tunnel (`https://8-2-4.prestashop.veljko.ngrok.app/en/module/plugin0/webhook`):
  GET 405, signed events 200, the Merchant Portal test message shape answered 200 with the real merchant id
  (processed, unknown payment) and with the documentation's placeholder merchant id (rejected, merchant mismatch);
- the Symfony container inside the webhook request after the `Controller::init()` fix: an HTTP probe through the web
  server built `FrontContainer` and formatted a price with the context locale;
- a refund requested through the Core `OrderAPI` on a paid order (order 14, 10.80 EUR) followed by signed
  `payment.refunded` deliveries: the first was processed with 200 and the order correctly stayed in "Payment accepted"
  while Worldline still reported `REFUND_REQUESTED` (status 81), because the Core applies the re-read state, not the
  event's; the second, sent after Worldline reported `REFUNDED` (status 8), moved the order to "Refunded" (history
  2, 7) without the merchant opening the order page;
- the controller and the decorator answer as in 3.4, checked with curl in the dev container: GET 405; missing
  headers, wrong signature and unknown key id 200 with a warning line; valid signature with `apiVersion` `v0`, with a
  JSON array body and with truncated JSON 200 with an error line; valid signature for an unknown payment 200 with an
  info line and a Core `WebhookLog` row;
- two deliveries for the same paid order sent at the same moment in the dev container: both answered 200, the order
  stayed in "Payment accepted" with one history row, and the Core monitoring log shows the two
  `GET /payments/{id}/details` calls one after the other, the second starting after the first had finished (consistent
  with the transaction lock; the log has one-second resolution, so this is evidence, not a proof);
- `GET /payments/10300628270_1/details` (a capture operation id) answered 200 with the whole payment's current state
  (2.7), so capture and refund events need no id normalisation;
- the transaction lock itself, in the dev container: while another MySQL session held
  `plugin0_tx_10300564983_prestashop` for 6 seconds, a signed delivery for that payment waited and then answered 200
  after 5.5 seconds; while the lock was held for 15 seconds, the delivery gave up after the 10-second timeout with 503
  and the log line "Payment status lock for transaction 10300564983 not acquired within 10 s.", and the event was
  not recorded, so Worldline's retry would process it;
- the 30-day retention of recorded events: a `WebhookEvent` row dated 40 days back was deleted by the next recorded
  delivery;
- unit tests in `tests/Unit/Webhook/` cover the body summary, the response policy (all five outcomes), the transformer
  decorator (signature, unknown key id, no connection, API version, unreadable body, pass-through) and the
  deduplicating `WebhookService` (skip, record after success, record for unknown payments, no record on failure, no
  check without an event id);
- PrestaShop module front controllers have no CSRF token check (`FrontController::checkAccess` returns true,
  `classes/controller/FrontController.php:237-240`), SSL and canonical redirects do not apply to POST
  (lines 855-858 and 876-878), maintenance mode answers 503 (lines 805-817), which makes Worldline retry.

Assumed, to be confirmed in the sandbox:

- one POST = one JSON object (2.1): the SDK requires it; a real delivery will be inspected in the Core webhook log; if
  Worldline ever sent an array, the shop would answer 200 with an error line "Expected object, got array" (3.4);
- the Merchant Portal test message (`payment.test`, payment id `9999_9`, placeholder merchant id in the documentation)
  is answered with 200 either way: merchant mismatch or an unknown payment both end without an order change;
- the webhook key pair: the Merchant Portal keeps one active pair per PSPID and "generating a new pair revokes the
  existing one immediately"; with the shared sandbox PSPID `logeecom35`, a regenerated pair on either side breaks
  verification on the other until the Configuration page is updated.

Still open after this ticket's work:

- the endpoint is registered in the Merchant Portal; real deliveries (`Direct API/2.0`) arrived and were processed,
  including the `SendTestWebhook` test message (`payment.test`);
- order creation by a real Worldline webhook was observed after the container fix: order #18 (payment 10301086130) was
  created by `payment.created`, a 10.00 EUR capture from the Merchant Portal updated the panel, and capturing the rest
  moved the order to "Payment accepted" without anyone opening it; the half-created order 17 was deleted by the user;
- since 2026-10-19 the preprod Hosted Checkout response names the return HMAC field `returnmac` (lowercase); the SDK
  maps only `RETURNMAC`, the Core stores an empty `returnHmac`, and the customer's return fails with "payment
  transaction not found" (cart 18). Fixed outside this ticket's scope on the same day: `src/Infrastructure/Sdk/*` reads
  both spellings; payments started before the fix keep an empty `returnHmac`, and for them the webhook creates the
  order (order #18 was created by `payment.created` at 14:40:31, two seconds before the failed return).

Not documented by Worldline Direct: endpoint disabling after failures, the response timeout, event ordering.

## 8. References

- Worldline Direct, API developer guide, Webhooks:
  https://docs.direct.worldline-solutions.com/en/integration/api-developer-guide/webhooks
- Worldline Connect, Webhooks (sister platform, blacklist statement):
  https://docs.connect.worldline-solutions.com/documentation/webhooks/
- Core reference in this repository: `docs/CORE.md` section 7.6; plugin guide `docs/VODIC.md` chapters 18, 19 and 32.
