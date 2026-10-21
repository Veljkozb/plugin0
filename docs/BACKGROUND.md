# Background processing: the Core task runner, auto capture and log cleanup

Ticket: "Background processing: Core task runner, auto capture and log cleanup", branch `feature/MIDU-1230`.
Sections 1-4 are the written part the ticket asks for before the code (Part 1): how the Core runner and queue work,
the retry policy, why background work must not be a loop inside a request, and what PrestaShop has to provide.
Sections 5-8 describe what plugin0 builds on top of it (Parts 2-5) and how it was verified.

It describes the Core as it is (`wl-online-payments-direct/plugin-core-base` 1.0.7 in `vendor/`, never modified).
All paths are relative to the module root `modules/plugin0/` and are written out in full, for example
`vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/TaskExecution/QueueService.php:208`.
Numbers about the server (PHP-FPM pool, timeouts) were read in the dev container `logeecom_dev` (Apache + PHP-FPM
8.2, PrestaShop 8.2.4) and are labelled as such.

## 1. The runner and the queue in the Core

### 1.1 The parts

| Part | What it is | Where |
|---|---|---|
| Task | One unit of work. A subclass implements `execute()`; it reports progress with `reportProgress()` (100 % finishes it) and "still alive" with `reportAlive()`. Its state is serialized with `toArray()`/`fromArray()`. | `vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/TaskExecution/Task.php:56`, `:105`, `:122` |
| QueueItem | The stored wrapper around one task: status, queue name, serialized task, retries, failure description, timestamps, priority. An ORM entity of type `QueueItem`. | `vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/TaskExecution/QueueItem.php:34-54` (statuses), `:649-654` (indexes) |
| QueueService | The only API that changes queue items: `enqueue`, `start`, `finish`, `requeue`, `fail`, `abort`, `keepAlive`, plus finders. | `vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/TaskExecution/QueueService.php` |
| TaskRunnerWakeupService | Starts a runner when none is alive. | `vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/TaskExecution/TaskRunnerWakeupService.php:51-75` |
| AsyncProcessStarterService + Process | Runs a piece of work in a separate HTTP request to the shop itself: stores a `Process` (guid + serialized runnable), then calls the shop's async URL with that guid. | `vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/TaskExecution/AsyncProcessStarterService.php:77`, `:88`, `:130-137` |
| TaskRunnerStarter, TaskRunner | One runner life cycle: start the oldest queued items, sleep, wake the next runner, fire `TickEvent`. | `vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/TaskExecution/TaskRunnerStarter.php:108-146`, `vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/TaskExecution/TaskRunner.php:104-124` |
| QueueItemStarter | Runs one queue item inside its own async request. | `vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/TaskExecution/QueueItemStarter.php:90-108` |
| RunnerStatusStorage, TaskRunnerStatus | The "who is the active runner" record: guid + alive-since timestamp, expired after 15 s. | `vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/TaskExecution/TaskRunnerStatus.php:17`, `:87-91` |

### 1.2 How a task gets into the queue

`QueueService::enqueue($queueName, $task, $context = '', $priority = NORMAL)`
(`vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/TaskExecution/QueueService.php:116-125`):

1. wraps the task in a new `QueueItem` with status `queued` and the current time as `queueTime`;
2. saves it through the queue repository (`QueueItemRepository::saveWithCondition`);
3. fires `QueueItemEnqueuedEvent`;
4. calls `TaskRunnerWakeup::wakeup()`, so a runner starts if none is alive.

`QueueService::create()` does the same with status `created`, for sub-tasks that an orchestrator queues later.

### 1.3 Who picks it up

Nothing in the Core loops. Every step below is its own HTTP request from the server to the shop's async URL:

1. `TaskRunnerWakeupService::wakeup()` reads the runner status. If a runner is active and not expired (alive within
   the last 15 s), it does nothing. Otherwise it writes a new guid G1 as the active runner and calls
   `AsyncProcessStarterService::start(new TaskRunnerStarter(G1))`.
2. `AsyncProcessStarterService::start()` stores a `Process` row (guid P1 + the serialized `TaskRunnerStarter`) and
   sends a fire-and-forget request to `Configuration::getAsyncProcessUrl(P1)`
   (`vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/TaskExecution/AsyncProcessStarterService.php:133`).
   The default HTTP client waits at most 1000 ms for an answer and treats a timeout as success
   (`vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/Http/CurlHttpClient.php:19`, `:121-134`, `:239-246`).
3. The integration's endpoint receives the request and calls `AsyncProcessService::runProcess(P1)`
   (`vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/TaskExecution/AsyncProcessStarterService.php:88-101`):
   load the `Process`, run it, delete it. An unknown guid does nothing.
4. `TaskRunnerStarter::run()` checks that G1 is still the active, non-expired runner and calls `TaskRunner::run()`.
5. `TaskRunner::run()` (`vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/TaskExecution/TaskRunner.php:104-124`):
   - `failOrRequeueExpiredTasks()` (`:131`): items `in_progress` without a sign of life for `maxTaskInactivityPeriod`
     (300 s) are requeued when their progress grew, otherwise failed (section 2.2);
   - `startOldestQueuedItems()` (`:186-228`): free slots = `maxStartedTasksLimit` (64) minus running items; for each
     oldest queued item it creates a `QueueItemStarter(itemId)` and starts them in batches, each as a new async request;
   - `wakeup()` (`:229-239`): sleeps `taskRunnerWakeupDelay` (5 s, `:33`), writing "alive" every second, then clears
     the runner status and calls `TaskRunnerWakeup::wakeup()`, which starts runner G2 with a new async request.
6. Back in `TaskRunnerStarter::doRun()` the Core fires `TickEvent` (`TaskRunnerStarter.php:143`): the periodic
   listeners of section 1.6 run here.
7. Each `QueueItemStarter` request (`vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/TaskExecution/QueueItemStarter.php:90-108`)
   loads its item, skips it unless it is still `queued`, sets the configuration context from the item, and calls
   `QueueService::start()`, which runs `Task::execute()`.

So the chain keeps itself alive: as long as every self-request arrives, a new runner starts about every 5 s.

### 1.4 How the status changes

| From | To | Method | When |
|---|---|---|---|
| (new) | `queued` | `enqueue` (`QueueService.php:116`) | code puts a task in the queue |
| `queued` | `in_progress` | `start` (`:144-157`) | a `QueueItemStarter` picked it; saved only if the row is still `queued` with the same `lastUpdateTimestamp` |
| `in_progress` | `completed` | `finish` (`:167-176`) | the task reported 100 % (`updateProgress`, `:289-290`) |
| `in_progress` | `queued` | `fail` (`:208-242`) | the task threw and attempts are left (section 2) |
| `in_progress` | `failed` | `fail` | the task threw and no attempts are left, or `fail(..., force: true)` |
| `in_progress` | `queued` | `requeue` (`:185-196`) | the runner found it inactive but its progress had grown |
| `in_progress` | `aborted` | `abort` (`:253-275`) | the task threw `AbortTaskExecutionException` (no retry) |

Every transition fires a `QueueItem*Event` and a `QueueStatusChangedEvent`.

### 1.5 Where the status is stored

- Queue items: in the integration's entity table (`ps_plugin0_entity` in plugin0), one row per item with
  `type = 'QueueItem'`. The nine indexed fields go to `index_1` .. `index_9` in this order: `status`, `taskType`
  (the task's short class name), `queueName`, `context`, `queueTime`, `lastExecutionProgress`, `lastUpdateTimestamp`,
  `priority`, `parentId`. The whole item, including the serialized task, is JSON in `data`.
- Every write goes through `QueueItemRepository::saveWithCondition($item, $additionalWhere)`
  (`vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/ORM/Interfaces/QueueItemRepository.php`):
  the update must succeed only if the row still has the expected `status` / `lastUpdateTimestamp` /
  `lastExecutionProgress`; otherwise it throws `QueueItemSaveException`. That optimistic lock is what stops two
  requests from starting or finishing the same item twice (`QueueService.php:410-424`).
- The active runner (guid + alive-since) and the "halted" flag: Core configuration keys `taskRunnerStatus` and
  `isTaskRunnerHalted`, rows of type `Configuration` in the same table.
- Pending async calls: rows of type `Process` (guid + serialized runnable), deleted after they ran.

### 1.6 Periodic work: `TickEvent`

The Core has no scheduler of its own. After every runner life cycle it fires `TickEvent`, and four listeners
decide whether their periodic task is due (`vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/BootstrapComponent.php:460-481`):

| Listener | Task it queues | How often |
|---|---|---|
| `TransactionStatusCheckListener` | `TransactionStatusCheckTask`: refreshes pending payments (status codes in `StatusCode::PENDING_STATUS_CODES`) of the last 24 h | 15 min |
| `AutoCaptureCheckListener` | `AutoCaptureCheckTask`: captures authorized payments whose `captureAt` has passed | 15 min |
| `TaskCleanupListener` | `TaskCleanupTask`: deletes completed queue items, and failed/aborted ones older than 14 days | daily |
| `LogCleanupListener` | `LogCleanupTask`: deletes expired monitoring and webhook logs | daily |

Consequence: periodic tasks exist only while runners run. With no runner, no `TickEvent` is fired, so no periodic
task is ever queued, and nothing calls `enqueue()` that would start a runner. Something outside the Core has to wake
the runner up (section 4.1).

## 2. Retry policy

### 2.1 What happens when a task fails

A task fails by throwing. `QueueItemStarter::run()` catches the exception
(`vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/TaskExecution/QueueItemStarter.php:106-108`) and calls
`QueueService::fail($item, $message)` (`vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/TaskExecution/QueueService.php:208-242`):

1. `retries` is increased by one;
2. `"Attempt N: <message>"` is appended to the item's `failureDescription`, so the item keeps the history of every attempt;
3. if `retries > maxTaskExecutionRetries`, the item becomes `failed`, gets a `failTime`, and `Task::onFail()` is called
   (`:223-227`); if it has a parent, the parent is failed too;
4. otherwise the item goes straight back to `queued`.

### 2.2 How many times it is retried

- `maxTaskExecutionRetries` defaults to `QueueService::MAX_RETRIES = 5` (`QueueService.php:46`, `:514-524`; Core
  configuration key `maxTaskExecutionRetries`). The test is `retries > 5`, so a task runs at most **6 times**: the
  first attempt and 5 retries.
- There is **no pause between attempts**. The requeued item is picked up by the next runner cycle, about 5 s later.
  `QueueItem` has an `earliestStartTime` field (`QueueItem.php:150`), but nothing that selects work reads it.
- A queue (one `queueName`) runs one item at a time: `findOldestQueuedItems` returns only the oldest queued item per
  queue and skips queues that have an item `in_progress`. A failing item that is requeued is again the oldest one, so
  it is retried before the items queued after it.
- A crash (PHP fatal error, killed worker, server restart) leaves the item `in_progress`. The next runner sees no
  sign of life for 300 s (`Task::MAX_INACTIVITY_PERIOD`, `Task.php:28`) and requeues it if its progress grew, or
  fails it as one attempt (`TaskRunner.php:169`, `:172`). So a crash costs an attempt instead of losing the work.

### 2.3 Why there has to be a maximum

Some failures never go away by themselves: revoked API credentials, a payment that no longer exists, a bug that
throws on the same data every time. Without a maximum such an item would be retried forever:

- it would block its queue, because the failing item is always the oldest one and runs first;
- every attempt would take a PHP-FPM worker and, for payment tasks, a Worldline API call;
- it would write the same error to the log every few seconds, hiding everything else.

A maximum turns "broken forever" into one final state that someone can see and act on.

### 2.4 A task that has used up its attempts

- It stays in the table with status `failed`, `failTime` and the full `failureDescription` (one line per attempt).
- `Task::onFail()` is called once (`QueueService.php:227`); the default implementation does nothing
  (`Task.php:204`), so a task that must be visible has to override it.
- Nothing restarts it. `TaskCleanupTask` deletes it after 14 days
  (`vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/Maintenance/TaskCleanupTask.php:45-49`).
- An aborted task (`AbortTaskExecutionException`) is not retried at all.

### 2.5 What this means for a task

- **It must throw on failure.** The Core API facades (`OrderAPI`, `CheckoutAPI`, `AdminAPI`) never throw: their
  `ErrorHandlingAspect` catches the exception, writes one ERROR log line and returns an error response
  (`vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/ApiFacades/Aspects/ErrorHandlingAspect.php:26-32`).
  A task that calls a facade and ignores `isSuccessful()` reports success, and the retry policy never applies.
- **It must be safe to run again.** A retry repeats the whole `execute()`. If the first attempt did its work and then
  failed (for example the answer timed out after Worldline already processed the request), the retry must notice
  that and not do the work again.
- **It must report progress or "alive" when it runs long**, or the runner treats it as dead after 300 s.

## 3. Why background work must not be a loop inside a request

A loop inside the request that the merchant or customer is waiting for has four problems the queue does not have:

1. **Request timeouts.** PHP stops a script after `max_execution_time` (30 s in the dev container's
   `/etc/php/8.2/fpm/php.ini`; on Linux it counts CPU time of the script, not time spent waiting for the network).
   Apache, a proxy (ngrok in the dev setup) or the browser give up after their own timeouts and show an error while
   the loop may still run, or may not.
2. **A blocked PHP worker.** Each request holds one PHP-FPM worker until it ends. The dev pool has
   `pm.max_children = 5`; one long loop takes a fifth of the capacity, and a few of them make customers and Worldline
   webhooks wait or fail.
3. **The work stops when the user leaves.** When the browser is closed, the connection is dropped; PHP notices at
   its next output and ends the script, unless `ignore_user_abort` is set. Half of a batch is done, half is not.
4. **No resume after a crash.** The loop's position lives only in the process memory. After a fatal error, a
   killed worker or a restart, nobody knows which items were processed. The queue stores status, progress and the
   number of attempts per item in the database, and the runner's inactivity check (section 2.2) picks up an item
   whose process died.

A queue item runs in its own short request that no user waits for, can be retried, and survives restarts.

## 4. What the integration must provide on PrestaShop

### 4.1 The trigger that wakes the runner up

PrestaShop has no scheduler and no message queue in its core (unlike Shopware's scheduled tasks and message
queue). The options are a system cron (calling a CLI script or a URL), the "Cron tasks manager" module (itself
driven by a cron or a remote service), or "pseudo-cron" on shop visits (no visits at night means no work).

The Core's own chain (section 1.3) keeps running while every self-request arrives. It stops when one does not: the
server was restarted, PHP-FPM was reloaded, the self-request failed. After that only an `enqueue()` would start it
again, and the periodic tasks are queued only by a running runner (section 1.6). So the chain needs a guaranteed
trigger: a system cron entry that calls `TaskRunnerWakeup::wakeup()` every minute. `wakeup()` does nothing when a
runner is alive, so the cron entry only restarts a broken chain.

### 4.2 The storage the queue uses

- A repository for `QueueItem` that implements `QueueItemRepository` (`findOldestQueuedItems`, `saveWithCondition`,
  `batchStatusUpdate`, plus the normal repository methods and `deleteWhere`), registered with
  `RepositoryRegistry::registerRepository(QueueItem::class, ...)`. The Core ships no implementation.
- Repositories for `Process` (async calls) and for the Core configuration entity (runner status).

### 4.3 The HTTP endpoint

- `Configuration::getAsyncProcessUrl($guid)` must return a URL of the shop that the server itself can reach.
- The controller behind it must call `AsyncProcessService::runProcess($guid)`. It should answer at once (the caller
  waits at most 1 s), keep running after the caller hangs up (`ignore_user_abort`), not stop at the PHP time limit,
  skip what a storefront page needs (cookie, cart, maintenance page, geolocation), and still build the parts of the
  shop context that tasks use (for example `PaymentModule::validateOrder` needs the Symfony container and the locale,
  see `docs/WEBHOOK.md`).

### 4.4 Services the Core expects

The same services every Core call needs (the `Configuration` subclass, `Serializer`, `ShopLoggerAdapter`,
`ShopOrderService`, `StoreService`, ...), and an `HttpClient`, which the Core registers by default
(`CurlHttpClient`, `vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/BootstrapComponent.php:54-56`).
The tasks themselves use whatever their work needs; the payment tasks need the order and payment services that the
checkout already uses.

### 4.5 What happens if one of them is missing

| Missing | What happens |
|---|---|
| `QueueItemRepository` | `RepositoryRegistry::getQueueItemRepository()` throws, so `enqueue()` fails and no task enters the queue. |
| The async endpoint (or the URL is not reachable from the server) | The self-request gets 404 or times out. The Core logs "Async process failed" for an error status and nothing for a timeout (`CurlHttpClient.php:121-134`); no runner and no task ever runs. |
| The trigger | The chain runs until the first missed self-request and then stays stopped; periodic tasks are never queued again. |
| Shop context in the endpoint | Tasks that create or change orders fail halfway (`validateOrder` without container and locale), see `docs/WEBHOOK.md`. |
| `Process` or configuration repository | The runner cannot store its status or its async calls; `wakeup()` logs at DEBUG and gives up. |

## 5. What plugin0 builds: the runner on PrestaShop (Part 2)

| Piece | File | Role |
|---|---|---|
| Queue storage | `src/Infrastructure/Repository/QueueItemRepository.php` | Core `QueueItemRepository` over `ps_plugin0_entity`, registered in `src/Bootstrap.php` `initRepositories()` |
| Async endpoint | `controllers/front/asyncprocess.php` (and `'asyncprocess'` in `plugin0.php` `$this->controllers`) | Answers 200 at once and runs `AsyncProcessService::runProcess($guid)` |
| Trigger | `bin/task-runner-wakeup.php` + a cron entry | Calls `TaskRunnerWakeup::wakeup()` every minute |
| Auto capture | `src/BackgroundProcessing/AutoCapture/*`, `src/Entity/AutoCaptureEntity.php` | Section 7 |
| Log cleanup | `src/BackgroundProcessing/LogCleanup/*` | Section 8 |
| Settings | `src/Controller/Admin/SettingsController.php`, `views/templates/admin/settings.html.twig` | Section 6 |

The Core is not modified. Where its behaviour does not fit the ticket, plugin0 registers its own class under the
Core's `ServiceRegister` key after the Core's registration (the last registration wins), as it already does for
`StatusUpdateService` and the webhook classes.

### 5.1 Queue storage

`QueueItemRepository extends BaseRepository` and adds the three queue methods:

- `findOldestQueuedItems($priority, $limit)`: one SQL query: per `queueName` the lowest `id` with status `queued` and
  the given priority (`MIN(id) ... GROUP BY`), minus the queues that have an item `in_progress` (`NOT EXISTS`, not
  `NOT IN`, which returns nothing when the list contains NULL), oldest first.
- `saveWithCondition($item, $additionalWhere)`: `UPDATE ... WHERE id AND type AND <conditions>`; a missing `id`
  inserts. MySQL reports only changed rows, so 0 affected rows means either "the condition failed" or "the row
  already had exactly these values" (for example `keepAlive` twice in one second). The repository re-checks the same
  condition with `SELECT COUNT(*)` and throws `QueueItemSaveException` only when the condition does not hold. It must
  not compare the row content instead: two processes that start the same item in the same second write identical
  content, and the second one has to get the exception (section 9.3 shows the check).
- `batchStatusUpdate($ids, $status)`: loads the items and saves each with the new status (status is both an index
  column and part of `data`).

`BaseRepository::log()` became `protected` so the subclass logs the same way.

### 5.2 The async endpoint

`controllers/front/asyncprocess.php`, class `Plugin0AsyncprocessModuleFrontController`, URL
`/<lang>/module/plugin0/asyncprocess?guid=...` (built by `src/Configuration.php:55-58`):

1. `\Controller::init()` instead of `FrontController::init()`: the Symfony container and the locale that order
   code needs, without cookie, cart, geolocation and maintenance page (same reasoning as `controllers/front/webhook.php`);
2. `ignore_user_abort(true)`, `set_time_limit(0)`;
3. answers `200 OK` with `Content-Length: 2` and calls `fastcgi_finish_request()` (PHP-FPM), so the caller's 1 s
   wait ends at once and the work continues without an open connection;
4. `AsyncProcessService::runProcess($guid)`; a PHP `Error` that the Core does not catch is logged as
   "Async process stopped with an error".

There is no token: a guid is random, used once and known only to the server; an unknown guid does nothing.

### 5.3 The trigger

`bin/task-runner-wakeup.php` loads PrestaShop (`config/config.inc.php` sets the default shop, language and `Link`
on the command line), boots the Core and calls `TaskRunnerWakeup::wakeup()`. It prints one line ("already active"
or "woken up") and answers 404 if it is requested over the web. Cron entry, as the web server user, every minute:

```
* * * * * php /path/to/shop/modules/plugin0/bin/task-runner-wakeup.php > /dev/null 2>&1
```

In the dev container `logeecom_dev` the entry is `/etc/cron.d/plugin0-task-runner` (user `www-data`,
`php -d xdebug.mode=off`, output appended to `/tmp/plugin0-task-runner-wakeup.log`). The cron daemon already runs
there: the dockware entrypoint starts it on every container start (`/entrypoint.sh:126-127`, `service cron start`).
The file lives in the container's file system, so it survives a restart but not a re-created container.

### 5.4 What starts running once the runner works

The Core's own periodic tasks (section 1.6) start as well:

- `TransactionStatusCheckTask` every 15 min refreshes pending payments of the last 24 h, the Core's fallback when a
  webhook does not arrive (in the dev shop on 2026-10-20 there were none);
- `TaskCleanupTask` daily deletes completed queue items, and failed/aborted ones older than 14 days;
- the Core's auto capture and log cleanup tasks are no longer queued: plugin0 replaces their listener and service
  (sections 7 and 8).

In the dev setup the shop's own URL is the ngrok domain, so every self-request (one per runner cycle, about every
6 s, plus one per task) goes out through ngrok and back. It works (section 9.1), but it is ngrok traffic.

## 6. Settings (Part 3)

### 6.1 The two fields

| Field | Values | Stored in | Default |
|---|---|---|---|
| Auto capture | on / off | Core `PaymentSettings.automaticCapture`: off = -1 (`AutomaticCapture::never()`), on = 60 | off (Core default -1, `GeneralSettingsService.php:86`) |
| Log retention | 1, 2, 7, 14 days | Core `LogSettings.logRecordsLifetime` (Core accepts 1-14, `LogRecordsLifetime.php:28`) | 14 (Core default, `GeneralSettingsService.php:105`) |

- Classes: `src/BackgroundProcessing/AutoCapture/AutoCaptureSetting.php`,
  `src/BackgroundProcessing/LogCleanup/LogRetentionSetting.php`. Both write through the Core facade
  (`AdminAPI::generalSettings()`), read-modify-write. Payment settings go through `src/Infrastructure/CorePaymentSettings.php`,
  which `src/Infrastructure/PaymentActionSetting.php` now uses as well; the log settings keep `debugMode` as it is.
- Restore defaults sets auto capture off and retention 14 days. Every change is logged ("Auto capture turned on/off",
  "Log retention changed", with the employee id); saving an unchanged value writes and logs nothing.
- The background tasks read the same values through the Core repositories in the store context
  (`isEnabledForStore`, `daysForStore`); without an active connection the Core returns nothing and the defaults apply.

### 6.2 What "on" means in the Core

The Core decides when a payment is due, not plugin0: when `updateOrderStatus` sees an authorized payment (status 5)
while the payment action is `FINAL_AUTHORIZATION` and `automaticCapture` is not -1, it stores
`captureAt = now + automaticCapture minutes` on the transaction, once (`StatusUpdateService.php:71-79`). 60 minutes
is the shortest value the Core accepts (`AutomaticCapture.php:14-21`). So:

- "on" captures about 60-75 minutes after the authorization (60 plus the 15-minute scan interval);
- it applies to payments whose status is updated while it is on (return page, webhook, order page refresh), also
  to an older authorized payment whose status is refreshed then;
- with "Authorize and capture (SALE)" nothing is ever due, the payment is captured at once by Worldline;
- turning it off stops every capture that has not run yet (each attempt checks the setting); a payment that already
  has a `captureAt` is captured if the setting is turned on again.

## 7. Auto capture (Part 4)

### 7.1 Scheduling

- `AutoCaptureListener` replaces the Core `AutoCaptureCheckListener` (`src/Bootstrap.php`
  `initBackgroundProcesses()`). On `TickEvent`, at most every 15 minutes and only while auto capture is on in at
  least one store, it queues `AutoCaptureScanTask` (queue `plugin0-auto-capture-scan`).
- `AutoCaptureScanTask` runs `AutoCaptureScheduler::scheduleDue()`: the due payments come from the Core
  `AuthorizedTransactionsRepository::get(100)` (local status 5, `captureAt` passed). Skipped: stores where the
  setting is off, and payments that already have a ledger entry. For each new one it writes the ledger entry
  (`scheduled`) and queues one `AutoCapturePaymentTask` (queue `plugin0-auto-capture`, so captures run one at a
  time). A `scheduled` entry whose queue item is gone (deleted, or crashed past `onFail`) is scheduled again.

### 7.2 The ledger

`src/Entity/AutoCaptureEntity.php` (type `AutoCapture`), written only by
`src/BackgroundProcessing/AutoCapture/AutoCaptureLedger.php`: one row per payment with state `scheduled`,
`captured`, `not_needed` or `failed`, the attempt count, the last error, the queue item id and the Worldline capture
id. It exists because the Core does not change the local transaction after a capture: without it the same payment
would be selected again every 15 minutes.

### 7.3 One attempt

`AutoCapturePaymentTask::execute()` runs `AutoCaptureProcessor::process()` with the attempt number
(`src/BackgroundProcessing/TaskAttempt.php`: the queue item's `retries + 1`, maximum `maxTaskExecutionRetries + 1` = 6):

1. log "Auto capture attempt started" with order id, cart id, payment id, store, attempt and maximum;
2. setting off: nothing is sent, the ledger entry is removed, log "skipped";
3. under the per-transaction lock (`src/Infrastructure/TransactionLock.php`, the same MySQL `GET_LOCK` as the status
   update, re-entrant in one connection) it reads the current state of **this** payment from Worldline
   (`PaymentsProxyInterface::getPaymentDetails`, not `OrderAPI::orders()->getDetails`, which reads the cart's latest
   transaction, `OrderService.php:29-31`) and computes the capturable amount with the Core's formula;
4. nothing left to capture: no capture is sent, the order status is refreshed, ledger `not_needed` (an existing
   `captured` stays), log "not needed";
5. otherwise a capture of the whole remaining amount (`OrderAPI::capture()`); ledger `captured`; a row in the order
   page's payment panel (outcome pending or success, employee "Automatic"); log "succeeded" with the Worldline
   status and capture id; then the order status is refreshed (`CheckoutAPI::payment()->updateOrderStatus`);
6. any failure (state not readable, capture refused, refresh failed) is written to the ledger and logged with the
   attempt number (WARNING while attempts are left, ERROR on the last one) and rethrown, so the Core retries.

After the last failed attempt the Core calls `onFail()`, and `AutoCaptureProcessor::giveUp()` sets the ledger to
`failed`, adds a row "Automatic capture failed after N attempts: ... Capture the payment from this page." to the
order's payment panel and logs an ERROR. Failed payments are not scheduled again; the merchant captures them by hand.
If a capture succeeded and only the order refresh kept failing, the payment is not marked failed.

### 7.4 The acceptance criteria

| Criterion | How |
|---|---|
| On: authorized payments captured in a background task; off: nothing captured | 7.1-7.3; the setting is checked by the listener and by every attempt |
| Running again does not capture twice, also after a retry following a timeout | Step 3-4: every attempt reads the current state first and sends nothing when nothing is capturable; the ledger stops new schedules; captures run one at a time under the transaction lock |
| A failed capture is retried by the retry policy; after the last attempt logged and visible; nothing fails silently | Step 6 throws (the Core facade alone would not), Core `QueueService::fail` retries up to 6 times, `onFail` -> `giveUp` |
| Every attempt logged with order and transaction reference | Steps 1, 2, 4, 5, 6 and `giveUp`, all with `orderId`, `cartId`, `paymentId`, `attempt` |

### 7.5 Why the Core task was replaced

The Core `AutoCaptureCheckTask` (`vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/ApiFacades/PaymentProcessor/BackgroundProcesses/AutoCaptureCheckTask.php:27-34`)
captures every local status-5 transaction whose `captureAt` has passed: it does not check the setting at run time,
ignores the facade's error response (so the queue item is `completed` and never retried), never refreshes the local
status (so the next run selects the same payment again), and logs nothing per attempt.

## 8. Log cleanup (Part 5)

- `LogCleanupTaskService` replaces the Core service under `LogCleanupTaskServiceInterface` (`src/Bootstrap.php`
  `initServices()`). The schedule stays the Core's: `LogCleanupListener` asks on every `TickEvent` and queues a run
  when the last one was queued more than a day ago (`LogCleanupListener.php:30-38`). What is queued is plugin0's
  `LogRetentionTask` (queue `plugin0-log-cleanup`); it is not called `LogCleanupTask` because the Core finds tasks by
  their short class name.
- `LogCleaner::run()` reads the retention in force at the start of every run and deletes everything older than
  `now - days * 86400`, in batches of 1000, calling `reportAlive()` after each batch:
  1. `ps_log` rows of the plugin only (`message LIKE 'WORLDLINE [%'`, the prefix of `src/Infrastructure/LoggerService.php:24`)
     by `date_add`; rows of PrestaShop and other modules are never touched;
  2. Core `MonitoringLog` rows (requests to Worldline and answers) by `createdAt`;
  3. Core `WebhookLog` rows (received webhooks) by `createdAt`.
- Each run logs "Log cleanup finished: N entries older than D days deleted" with the cutoff and the count per place;
  an error is logged as "Log cleanup failed" with what was deleted so far and rethrown (Core retry).
- Why not the Core task: it deletes only `MonitoringLog` and `WebhookLog`, by an `expiresAt` that is computed when
  the row is written (`WebhookLogRepository.php:57-59`, `MonitoringLogRepository.php:72`), so a changed retention would
  apply only to new rows; it never touches `ps_log`, and it does not log what it deleted.
- Not logs and not deleted here: orders, transactions, queue items (the Core `TaskCleanupTask` handles those), and
  the webhook event records of `src/Webhook/ProcessedEvents.php` (own 30-day purge).

## 9. Verified, assumed, still open

All runs on 2026-10-20 in the dev container, times in shop time (UTC+2).

### 9.1 Runner and trigger

- First start from the command line (14:05:51), then the chain kept itself alive (a new runner guid about every
  6 s). The Core's periodic tasks ran through the new storage: `TaskCleanupTask` (#716), `TransactionStatusCheckTask`
  (#724), `AutoCaptureCheckTask` (#725) and `LogCleanupTask` (#726) all `completed` (the last two before the
  replacements were registered; nothing was due, nothing was deleted).
- Cron: the runner was halted, the chain stopped (status expired), the halt flag was cleared without a wakeup; the
  cron run at 14:27:02 logged "task runner woken up" and the chain continued.

### 9.2 Log cleanup

- First real run (#854): "0 entries older than 14 days deleted" (the oldest plugin line, 2026-10-07 15:23:54, was 12.9 days old).
- Synthetic rows 20 and 13.5 days old in all three places, plus one non-Worldline `ps_log` row 20 days old:
  run with 14 days (#908) deleted 3 (one per place); then only the Core setting was changed to 13 days and the next
  run (#912) deleted the other 3; the non-Worldline row and all real rows (oldest 2026-10-07 15:23:54) stayed. The
  setting was set back to 14 (debugMode kept) and the synthetic row removed.

### 9.3 Queue and retry policy

- `Tests\Support\AlwaysFailingProbeTask` (test-only, autoload-dev) through the real queue: attempts 1-6 about 6 s
  apart, `failureDescription` "Attempt 1: ..." to "Attempt 6: ...", status `failed` with `retries=6`, then `onFail`.
- `saveWithCondition`: a write with a stale condition is rejected; an identical write with a matching condition is
  accepted; two copies of one item started in the same second with identical content: the first is saved, the second
  gets `QueueItemSaveException`. (The first version compared row content and let the second one through; this check
  found it.)
- `TransactionLock` nested twice in one connection: the inner call gets the lock at once, the lock is free afterwards.

### 9.4 Auto capture without capturing money

- Payment states read through the gateway: cart 13 / order 12 captured 57.41 EUR, capturable 0; carts 19 and 20
  authorized, capturable 10.80 and 42.00 EUR; no transaction had a `captureAt`, the setting is off.
- The processor with the setting forced on and the capture forbidden, on the captured payment of cart 13: "not
  needed", no capture sent, ledger `not_needed`, logs with order 12 and attempt 1 of 6 (ledger row removed again).
- The same on a payment that does not exist (`9999999999_0`): attempts 1-5 "failed, it will be retried" (WARNING),
  attempt 6 "no attempts left" (ERROR), then "failed after the last attempt" (ERROR), ledger `failed`.
- Unit tests: `tests/Unit/BackgroundProcessing/*` (capture, already captured, retry after a timeout, refresh failure,
  give up, scheduling rules, settings mapping, cutoff), 227 tests in total, all green.

### 9.5 Still open

- An automatic capture end to end, with the capture request actually sent to Worldline: a new payment with
  "Authorize only", auto capture on, then 60-75 minutes. Everything runs against the Worldline test environment
  (preprod, connection mode `test`, only test credentials stored), so no real money moves; what is real is that
  the payment changes state at Worldline (5 -> 91/9) and can then only be refunded.
  Payment tests are done by the team with the Worldline test card, not from this environment, and no `captureAt` is
  moved to speed it up. While auto capture is on, older authorized orders also get a `captureAt` as soon as their
  status is refreshed (order page opened, webhook); in the dev shop on 2026-10-20 those were orders 19 and 20.
- The Settings page and the "Automatic" rows of the order panel were not opened in a browser (no admin login here);
  both Twig templates pass `bin/console lint:twig`.
- The Worldline preprod answers carried `ratelimit-limit: 100` per 60 s for the merchant, with 1 left at the time;
  the account `logeecom35` is shared, the cause was not checked.
- Production: the cron entry has to be set up per shop (section 5.3); without it the runner restarts only when
  something calls `enqueue()`.
