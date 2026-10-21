# Worldline Core (plugin-core-base 1.0.7) – sveobuhvatan opis za pisanje integracije

Ovaj dokument opisuje biblioteku `wl-online-payments-direct/plugin-core-base` verzije 1.0.7 (u daljem tekstu: Core),
onako kako je instalirana u `vendor/wl-online-payments-direct/plugin-core-base/`. Sve tvrdnje su pročitane iz koda te
verzije; kad nešto nije provereno u kodu, to je izričito označeno kao *neprovereno*. Kad se navodi podrazumevana
vrednost, označena je kao *default*. Putanje fajlova su relativne u odnosu na `vendor/wl-online-payments-direct/plugin-core-base/src/`
osim kad je drugačije rečeno.

Sadržaj

1. Šta je Core i šta radi
2. Paketi, biblioteke i PHP ekstenzije
3. Arhitektura: četiri sloja i smer zavisnosti
4. Infrastructure sloj
5. BusinessLogic sloj
6. Bootstrap sloj
7. Tokovi korak po korak
8. Šta plugin mora da obezbedi i stanje plugin0
9. Zamke i detalji koje treba znati
10. Mapa foldera Core-a

---

## 1. Šta je Core i šta radi

Core je platformski nezavisna PHP biblioteka (PHP >= 7.4) koja sadrži celu poslovnu logiku Worldline Online Payments
integracije: povezivanje sa Worldline nalogom, podešavanja, metode plaćanja, kreiranje plaćanja (Hosted Checkout Page,
Hosted Tokenization Page, Pay by Link), praćenje ishoda plaćanja, webhook-ove, capture/cancel/refund, logove i
pozadinske zadatke. Core ne zna ništa o PrestaShop-u, Magentu ili bilo kojoj platformi. Sve što zavisi od platforme
(baza, logovanje, šifrovanje, narudžbine, prodavnice, jezici, URL-ovi) Core traži kroz interfejse koje plugin
implementira i registruje.

Core nema sopstveni DI kontejner, ORM biblioteku, PSR logger ni HTTP biblioteku. Sve to ima u sopstvenom,
minimalističkom obliku (vidi poglavlje 4). Ka Worldline API-ju Core ne ide svojim HTTP klijentom, nego preko
zvaničnog Worldline PHP SDK-a (vidi 2 i 6.3).

Ukupno: 490 PHP fajlova u `src/`.

---

## 2. Paketi, biblioteke i PHP ekstenzije

Iz `composer.lock` projekta plugin0 (instalirano u `vendor/`):

| Paket | Verzija | Namespace | Čemu služi |
|---|---|---|---|
| `wl-online-payments-direct/plugin-core-base` | 1.0.7 | `WOP\OnlinePayments\Core\` → `src/` | Core, opisan u ovom dokumentu. Licenca: proprietary. |
| `wl-online-payments-direct/sdk-php` | 6.2.0 | `OnlinePayments\Sdk\` → `src/OnlinePayments/Sdk` i `lib/OnlinePayments/Sdk` | Zvanični Worldline PHP SDK: HTTP komunikacija sa Worldline API-jem (`Communicator`, `DefaultConnection` sa `curl_multi_*`), HMAC autentifikacija (`V1HmacAuthenticator`), 273 Domain DTO klase (request/response modeli), validacija potpisa webhook-a (`Webhooks\WebhooksHelper`, `SignatureValidator`), logovanje i obfuskacija osetljivih polja. 369 PHP fajlova. |
| `robtimus/multipart` | 1.0.1 | `Robtimus\Multipart\` | Streaming multipart tela zahteva. Zavisnost SDK-a (za endpoint-e sa upload-om fajlova). Core ga ne koristi direktno. |

Zahtevi Core-a iz njegovog `composer.json`: `php >= 7.4`, `ext-json`, `ext-ctype`, `wl-online-payments-direct/sdk-php: 6.2.0`.
Zahtevi SDK-a: `ext-curl`, `ext-json`, `ext-mbstring`.

PHP funkcije koje Core direktno koristi, a zavise od ekstenzija ili okruženja:

- `curl_*` u `Infrastructure/Http/CurlHttpClient.php` (poziva sam sebe, vidi 4.8).
- `pfsockopen`, `fwrite` u `Infrastructure/Http/AsyncSocketHttpClient.php`.
- `json_encode`/`json_decode` u ORM-u, serializeru, MetricsProvidingCommunicator-u.
- `sleep`/`usleep` u `TimeProvider` i `AsyncSocketHttpClient`.

Dev zavisnosti Core-a (phpunit 8.5, phpstan 1.10, php-cs-fixer, php-parallel-lint, symfony/console) nisu instalirane
u plugin0: composer `require-dev` instalira samo za korenski paket (ovde plugin0), nikad za zavisnosti. plugin0 ima
sopstveni `require-dev` (`phpunit/phpunit` ^11.5, `psr/log` ^1.1) za svoje unit testove (ranije je ovde pogrešno pisalo
da plugin0 nema `require-dev`; usklađeno 2026-10-16). Core ima i `autoload-dev` namespace `WOP\OnlinePayments\Core\Tests\` → `tests/`,
ali folder `tests/` nije uključen u distribuirani paket.

Plugin0 sam (PrestaShop modul) koristi još: PrestaShop klase (`Module`, `Db`, `DbQuery`, `PrestaShopLogger`,
`PhpEncryption`, `Shop`, `OrderState`, `Configuration`, `Context`), Symfony kontrolere (`FrameworkBundleAdminController`)
i Twig šablone.

---

## 3. Arhitektura: četiri sloja i smer zavisnosti

```
src/
├── Infrastructure/   tehnički temelj: ServiceRegister, Configuration, ORM, Logger, Serializer, Http, TaskExecution
├── BusinessLogic/    poslovna logika nezavisna od platforme i od SDK-a
│   ├── Domain/       modeli, vrednosni objekti, interfejsi repozitorijuma i interfejsi integracije
│   ├── AdminConfig/  admin tokovi: konekcija, podešavanja, metode plaćanja, logovi, prodavnice...
│   ├── PaymentProcessor/  checkout tokovi, webhook-ovi, payment link, praćenje ishoda plaćanja
│   └── Order/        capture, cancel, refund, detalji narudžbine
├── Bootstrap/        "lepak": registracija svih servisa, API fasade, SDK proxy-ji, entiteti i repozitorijumi
└── Branding/         brend (Worldline ili white label): endpoint-i, nazivi
```

Smer zavisnosti: `Bootstrap` → `BusinessLogic` → `Infrastructure`. `Branding` koriste `Bootstrap` i `BusinessLogic`.
`BusinessLogic` ne zna za SDK: sve što ide ka Worldline-u ide kroz interfejse `*ProxyInterface`, a implementacije
tih interfejsa (koje koriste SDK) žive u `Bootstrap/ApiFacades/*/Proxies/`. Isto važi za bazu: `BusinessLogic`
definiše `*RepositoryInterface`, `Bootstrap/DataAccess` daje implementacije nad Core ORM-om, a plugin daje
implementaciju Core ORM interfejsa `RepositoryInterface` nad konkretnom bazom.

Pojednostavljeno (ovo je pojednostavljenje): to su "ports and adapters". Portovi su interfejsi u `BusinessLogic`,
adapteri ka Worldline-u su u `Bootstrap`, adapteri ka platformi su u pluginu.

Putanja jednog poziva iz plugina (primer: čuvanje kredencijala):

1. Plugin kontroler zove `AdminAPI::get()->connection($storeId)->connect(new ConnectionRequest(...))`.
2. `AdminAPI` (Bootstrap) umotava poziv u aspekte: `ErrorHandlingAspect` → `TenantContextAspect` → `StoreContextAspect`.
3. Aspekt uzima `ConnectionController` iz `ServiceRegister` i poziva `connect()`.
4. `ConnectionController` (BusinessLogic/AdminConfig) pretvara request u domenski `ConnectionDetails` i zove `ConnectionService::connect()`.
5. `ConnectionService` zove `ConnectionProxyInterface::isConnectionValid()`; implementacija `Bootstrap/ApiFacades/AdminConfig/Proxies/ConnectionProxy` preko `MerchantClientFactory` i SDK-a zove Worldline `services()->testConnection()`.
6. Ako je validno, `ConnectionService` zove `ConnectionConfigRepositoryInterface::saveConnection()`; implementacija `Bootstrap/DataAccess/Connection/ConnectionConfigRepository` šifruje tajne preko plugin `Encryptor`-a i čuva `ConnectionConfigEntity` kroz `RepositoryRegistry::getRepository(ConnectionConfigEntity::class)`, što je plugin klasa `Plugin0\Infrastructure\Repository\BaseRepository` koja piše u MySQL tabelu `plugin0_entity`.
7. Kontroler vraća `ConnectionResponse`; aspekt bi svaki izuzetak pretvorio u `TranslatableErrorResponse`.

---

## 4. Infrastructure sloj

### 4.1 ServiceRegister (`Infrastructure/ServiceRegister.php`)

Statički registar servisa; jedina "DI" mehanika u Core-u.

- `ServiceRegister::registerService(string $type, callable $delegate)`: pamti callable pod ključem `$type` (po konvenciji FQCN interfejsa ili klase, često kroz konstantu `CLASS_NAME`). Ponovna registracija istog ključa **zamenjuje** prethodnu, što je zvaničan način da plugin pregazi Core implementaciju.
- `ServiceRegister::getService(string $type)`: poziva callable i vraća rezultat. Ako callable nije umotan u `SingleInstance` (4.2), **svaki poziv pravi novu instancu**.
- Nepostojeći ključ baca `Infrastructure\Exceptions\ServiceNotRegisteredException` (poruka `Service of type "X" is not registered.`).
- Delegat koji nije callable baca `InvalidArgumentException`.

### 4.2 Singleton i SingleInstance

- `Infrastructure/Singleton.php`: apstraktna bazna klasa sa `getInstance()`/`resetInstance()`. **Svaka podklasa mora da deklariše sopstveno `protected static ?Singleton $instance = null;`**, inače dele isto statičko polje i `getInstance()` baca `RuntimeException('Invalid singleton instance.')`. Plugin0 `Configuration` to radi.
- `Bootstrap/SingleInstance.php`: invokable omotač oko callable-a koji rezultat kešira po PHP procesu (`__invoke` pravi instancu samo prvi put). Bootstrap njime registruje većinu servisa.

Koji servisi nisu `SingleInstance` (nova instanca pri svakom `getService`): `HttpClient` (uvek `new CurlHttpClient()`), `QueueService`, `TaskRunnerWakeupService`, `TaskRunner`, `RunnerStatusStorage`, `TaskRunnerManager`, `ConnectionService`, `PaymentService`, `StoreService` (domenski), `GeneralSettingsService`, `DisconnectService`, `WebhookTransformerInterface`. Ostali iz Bootstrap-a jesu `SingleInstance` (vidi tabelu u 6.1).

### 4.3 BootstrapComponent (Infrastructure)

`Infrastructure/BootstrapComponent.php` ima `init()` koji redom zove `initServices()`, `initRepositories()` (prazno), `initEvents()` (prazno). `initServices()` registruje:

| Ključ (`CLASS_NAME`) | Implementacija | Nova instanca po pozivu? |
|---|---|---|
| `Infrastructure\Configuration\ConfigurationManager` | `Bootstrap\Configuration\ConfigurationManager::getInstance()` (podklasa svesna tenanta) | ne (singleton) |
| `Infrastructure\Utility\TimeProvider` | `TimeProvider::getInstance()` | ne |
| `Infrastructure\Utility\GuidProvider` | `GuidProvider::getInstance()` | ne |
| `Infrastructure\Utility\Events\EventBus` | `EventBus::getInstance()` (registrovan dvaput, bezopasno) | ne |
| `Infrastructure\Http\HttpClient` | `new CurlHttpClient()` | da |
| `TaskExecution\Interfaces\AsyncProcessService` | `AsyncProcessStarterService::getInstance()` | ne |
| `TaskExecution\QueueService` | `new QueueService()` | da |
| `TaskExecution\Interfaces\TaskRunnerWakeup` | `new TaskRunnerWakeupService()` | da |
| `TaskExecution\TaskRunner` | `new TaskRunner()` | da |
| `TaskExecution\Interfaces\TaskRunnerStatusStorage` | `new RunnerStatusStorage()` | da |
| `TaskExecution\Interfaces\TaskRunnerManager` | `new TaskRunnerManager()` | da |
| `TaskExecution\Events\QueueItemStateTransitionEventBus` | `QueueItemStateTransitionEventBus::getInstance()` | ne |

Napomena o ključevima: `CLASS_NAME` je definisan kao `__CLASS__` u Infrastructure klasama. Bootstrap podklase
(`Bootstrap\Configuration\Configuration`, `Bootstrap\Configuration\ConfigurationManager`) ne redefinišu `CLASS_NAME`,
pa se i preko njih dobija Infrastructure FQCN. Zato plugin0 registruje `CoreConfiguration::CLASS_NAME` (Bootstrap alias),
a Core svuda traži `Configuration::CLASS_NAME` (Infrastructure): to je isti string.

### 4.4 Configuration, ConfigurationManager, ConfigEntity

**`Infrastructure/Configuration/Configuration.php`** (apstraktna, `extends Singleton`). Plugin mora da je nasledi
(preko `Bootstrap/Configuration/Configuration.php`) i registruje pod `Configuration::CLASS_NAME`.

Apstraktne metode koje plugin implementira:

| Metoda | Nivo | Čemu služi |
|---|---|---|
| `getIntegrationName(): string` | Infrastructure | ime platforme, ide u `LogData::integration` (plugin0: `'PrestaShop'`) |
| `getAsyncProcessUrl(string $guid): string` | Infrastructure | URL endpoint-a plugina koji pokreće pozadinski proces (vidi 4.9). Core ga poziva HTTP-om. `getAutoConfigurationUrl()` vraća `getAsyncProcessUrl('auto-configure')`. |
| `getIntegrationVersion(): string` | Bootstrap | verzija platforme |
| `getPluginName(): string` | Bootstrap | ime plugina |
| `getPluginVersion(): string` | Bootstrap | verzija plugina |

Metoda sa default implementacijom koju plugin može da pregazi: `getWebhookUrl(): string` vraća `''`. Koristi se samo u
automatskom webhook režimu (`WebhookMode::AUTOMATIC`, vidi 5.1 i 6.3) da bi se URL webhook endpoint-a prodavnice slao
Worldline-u u `feedbacks` objektu zahteva.

Sve ostale metode su get/set parovi nad konfiguracionim vrednostima koje se čuvaju u bazi kao `ConfigEntity` redovi
(preko `ConfigurationManager`). Tabela vrednosti, ključeva i default-a (default = vrednost koju getter vraća kad red ne postoji;
"sistemski default" = vrednost koju potrošač koristi kad getter vrati `null`):

| Ključ (`name`) | Getter / setter | Default | Ko koristi |
|---|---|---|---|
| `minLogLevel` | `getMinLogLevel` / `saveMinLogLevel` | `2` (`MIN_LOG_LEVEL`, nivo INFO) | `LoggerConfiguration`, samo za *default logger*; u plugin0 i `LoggerService` (podešavanje Log level sa Settings stranice, default Debug kad je `_PS_MODE_DEV_` uključen, inače Info) |
| `defaultLoggerEnabled` | `isDefaultLoggerEnabled` / `setDefaultLoggerEnabled` | `false` | `Logger` |
| `debugModeEnabled` | `isDebugModeEnabled` / `setDebugModeEnabled` | `false` | nije korišćeno u Core-u 1.0.7 (debug mode plaćanja ide kroz `LogSettings`, vidi 5.1) |
| `syncRequestTimeout` | `getSyncRequestTimeout` / `setSyncRequestTimeout` | `null` → `CurlHttpClient::DEFAULT_REQUEST_TIMEOUT` = 900000 ms | `CurlHttpClient` |
| `asyncRequestTimeout` | `getAsyncRequestTimeout` / `setAsyncRequestTimeout` | `null` → 1000 ms (`CurlHttpClient`) odnosno 5 s (`AsyncSocketHttpClient`) | HTTP klijenti za async start |
| `taskRunnerMaxAliveTime` | `getTaskRunnerMaxAliveTime` / `setTaskRunnerMaxAliveTime` | `null` → `TaskRunnerStatus::MAX_ALIVE_TIME` = 15 s | `TaskRunnerStatus::isExpired` |
| `maxTaskExecutionRetries` | `getMaxTaskExecutionRetries` / `setMaxTaskExecutionRetries` | `null` → `QueueService::MAX_RETRIES` = 5 | `QueueService::fail` |
| `maxTaskInactivityPeriod` | `getMaxTaskInactivityPeriod` / `setMaxTaskInactivityPeriod` | `null` → `Task::MAX_INACTIVITY_PERIOD` = 300 s | `TaskRunner` (isteklo izvršavanje) |
| `taskRunnerWakeupDelay` | `getTaskRunnerWakeupDelay` / `setTaskRunnerWakeupDelay` | `null` → `TaskRunner::WAKEUP_DELAY` = 5 s | `TaskRunner::wakeup` |
| `maxStartedTasksLimit` | `getMaxStartedTasksLimit` / `setMaxStartedTasksLimit` | `64` | `TaskRunner::startOldestQueuedItems` |
| `asyncStarterBatchSize` | `getAsyncStarterBatchSize` / `setAsyncStarterBatchSize` | `8` | `TaskRunner` → `AsyncBatchStarter` |
| `asyncProcessCallHttpMethod` | `getAsyncProcessCallHttpMethod` / `setAsyncProcessCallHttpMethod` | `'POST'` | `AsyncProcessStarterService`, `AutoConfiguration` |
| `taskRunnerStatus` | `getTaskRunnerStatus` / `setTaskRunnerStatus(guid, timestamp)` | `[]` | `RunnerStatusStorage`, `TaskRunner::keepAlive` |
| `isTaskRunnerHalted` | `isTaskRunnerHalted` / `setTaskRunnerHalted` | `false` | `TaskRunner::run`, `TaskRunnerManager` |
| `httpConfigurationOptions` | `getHttpConfigurationOptions(domain)` / `setHttpConfigurationOptions(domain, Options[])` | `'[]'` (JSON po domenu) | `HttpClient::getAdditionalOptions`, auto-konfiguracija |
| `autoConfigurationState` | `getAutoConfigurationState` / `setAutoConfigurationState` | `''` | `AutoConfiguration` |
| `autoTestMode` | `isAutoTestMode` / `setAutoTestMode` | `false` | `AutoTestService` |

Kontekst konfiguracionih vrednosti: `Configuration::$globalConfigValues` nabraja ključeve koji su globalni
(`taskRunnerStatus`, `isTaskRunnerHalted`, `maxTaskInactivityPeriod`, `maxTaskExecutionRetries`, `maxStartedTasksLimit`,
`taskRunnerMaxAliveTime`, `taskRunnerWakeupDelay`, `asyncStarterBatchSize`, `asyncRequestTimeout`, `syncRequestTimeout`,
`asyncRequestWithProgress`). Svi ostali ključevi su "kontekstno specifični": čuvaju se sa `context` = tekući kontekst
`ConfigurationManager`-a.

**`Infrastructure/Configuration/ConfigurationManager.php`** (`extends Singleton`): `getConfigValue(name, default, isContextSpecific)`,
`saveConfigValue(name, value, isContextSpecific)` (insert ili update), `deleteConfigEntity(name, isContextSpecific)`,
`setContext()/getContext()`. Repozitorijum uzima preko `RepositoryRegistry::getRepository(ConfigEntity::getClassName())`.
**`Bootstrap/Configuration/ConfigurationManager.php`** pregazi `getContext()` tako da vraća `TenantContext::getInstance()->getTenantId()`
(u jednotenantskom režimu to je prazan string).

**`Infrastructure/Configuration/ConfigEntity.php`**: entitet tipa `'Configuration'`, polja `id, name, value, context`,
string indeksi `name` i `context`. Plugin mora da registruje repozitorijum za njega (plugin0 to radi).

### 4.5 ORM: Entity, EntityConfiguration, IndexMap, RepositoryRegistry, QueryFilter

Core ORM je mali sloj "entitet ↔ niz ↔ skladište". Core ne zna šta je skladište; plugin daje klasu koja implementira
`Infrastructure\ORM\Interfaces\RepositoryInterface`.

**Entitet** (`Infrastructure/ORM/Entity.php`, `extends Data\DataTransferObject`):

- `protected array $fields = ['id']` – lista imena polja koja `inflate(array)`/`toArray()` kopiraju generički. Podklase dopunjuju listu ili pregaze `inflate`/`toArray` (većina Bootstrap entiteta to radi ručno).
- `toArray()` uvek dodaje `'class_name' => static::getClassName()`. Pri čitanju iz baze plugin repozitorijum pravi instancu te klase i zove `inflate($data)`.
- `getConfig(): EntityConfiguration` (apstraktna): vraća `new EntityConfiguration(IndexMap, string $type)`. `$type` je kratak naziv tipa (npr. `'Configuration'`, `'QueueItem'`, `'PaymentTransaction'`) koji plugin čuva u koloni `type`.
- `getIndexValue(string $indexKey)`: vrednost indeksiranog polja; traži `get<Key>()`, pa `is<Key>()`, pa svojstvo `$key`; inače `InvalidArgumentException`. Zato indeks može da bude i izračunata vrednost (npr. `QueueItem` indeks `taskType` → `getTaskType()`).

**`Infrastructure/ORM/Configuration/`**: `IndexMap` (`addStringIndex`, `addIntegerIndex`, `addDoubleIndex`, `addBooleanIndex`, `addDateTimeIndex`; redosled dodavanja = redni broj indeksa 1, 2, 3...), `IndexColumn` (tipovi `string`, `integer`, `double`, `boolean`, `dateTime`), `EntityConfiguration`.

**`Infrastructure/ORM/Utility/IndexHelper.php`**: `mapFieldsToIndexes(entity)` daje `['storeId' => 1, 'mode' => 2, ...]`;
`transformFieldsToIndexes(entity)` daje `[1 => 'vrednost', ...]` već kastovano za upis u string kolone:
`integer` → `sprintf('%011d')` (nula-popunjeno, da leksičko poređenje radi kao numeričko), `double` → `sprintf('%017.5f')`,
`boolean` → `'1'`/`'0'`, `dateTime` → `(string) $dateTime->getTimestamp()`, `string` → kako jeste, `null` → `null`.
Plugin repozitorijum mora isto kastovanje da primeni i na vrednosti iz `QueryFilter`-a (plugin0 `BaseRepository::conditionToSql` to radi preko `IndexHelper::castFieldValue`).

**`Infrastructure/ORM/Interfaces/`**:

- `RepositoryInterface`: `getClassName()` (statička), `setEntityClass(string)`, `select(?QueryFilter): Entity[]`, `selectOne(?QueryFilter): ?Entity`, `save(Entity): int` (mora da postavi id na entitet i vrati ga), `update(Entity, ?QueryFilter): bool`, `delete(Entity): bool`, `count(?QueryFilter): int`.
- `ConditionallyDeletes extends RepositoryInterface`: `deleteWhere(?QueryFilter)`. Traže ga `TokensRepository` i `ProductTypeRepository` (Bootstrap) i svi `deleteByMode`/`deleteExpired` pozivi u settings i log repozitorijumima.
- `MassInsert extends RepositoryInterface`: `massInsert(Entity[])`. Core 1.0.7 ga nigde ne poziva.
- `QueueItemRepository extends RepositoryInterface, ConditionallyDeletes`: obavezan za red zadataka. Dodatne metode: `findOldestQueuedItems(int $priority, int $limit = 10): QueueItem[]` (po redu `queueName` vrati samo najstariji `queued` item, i to samo za redove koji nemaju `in_progress` item, samo za dati prioritet), `saveWithCondition(QueueItem, array $additionalWhere = []): int` (update mora da uspe samo ako su u bazi još uvek vrednosti iz `$additionalWhere`, inače baca `QueueItemSaveException`; to je optimističko zaključavanje), `batchStatusUpdate(array $ids, string $status)`.

**`Infrastructure/ORM/RepositoryRegistry.php`**: `registerRepository(string $entityClass, string $repositoryClass)` (proverava `is_subclass_of($repositoryClass, RepositoryInterface::CLASS_NAME)`, inače `RepositoryClassException`), `getRepository(string $entityClass)` (pravi instancu repozitorijuma jednom po klasi entiteta, zove `setEntityClass($entityClass)`, kešira), `isRegistered()`, `getQueueItemRepository()` (dodatno proverava `instanceof QueueItemRepository`, inače `RepositoryClassException`). Nepoznata klasa entiteta → `RepositoryNotRegisteredException`.

**`Infrastructure/ORM/QueryFilter/`**:

- `QueryFilter`: `where(column, operator, value)` (AND), `orWhere(column, operator, value)` (OR; u plugin0 `BaseRepository::buildWhere` grupe se grade kao `(c1 AND c2) OR (c3 AND c4)`), `orderBy(column, 'ASC'|'DESC')`, `setLimit`, `setOffset`, getteri. Validacija: operator mora biti iz `Operators::$AVAILABLE_OPERATORS`, i dozvoljen za PHP tip vrednosti po `Operators::$TYPE_OPERATORS`.
- `Operators`: `=`, `!=`, `>`, `>=`, `<`, `<=`, `LIKE` (samo string), `IN`/`NOT IN` (samo array), `IS NULL`/`IS NOT NULL` (samo `null` vrednost). Tip `dateTime` se prepoznaje kad je vrednost `DateTime`.
- `QueryCondition`: `chainOperator` (`AND`/`OR`), `column`, `operator`, `value`, `valueType` (`gettype`, ili `'dateTime'`).

Pravilo koje Core podrazumeva, a plugin0 sprovodi: filtrirati i sortirati sme samo po `id` i po poljima iz `IndexMap`-a; ostalo baca `QueryFilterInvalidParamException`.

**`Infrastructure/ORM/IntermediateObject.php` i `Utility/EntityTranslator.php`**: pomoćne klase za implementacije koje red iz baze (index1..6 + data) prevode u entitet; plugin0 ih ne koristi (radi direktno `json_decode` + `inflate`).

Izuzeci: `EntityClassException`, `QueryFilterInvalidParamException`, `RepositoryClassException`, `RepositoryNotRegisteredException` (svi `extends Infrastructure\Exceptions\BaseException extends \Exception`).

**Kako plugin0 implementira skladište** (`src/Infrastructure/Repository/BaseRepository.php`, `Installer.php`): jedna MySQL tabela
`<prefix>plugin0_entity` sa kolonama `id INT UNSIGNED AUTO_INCREMENT`, `type VARCHAR(128)`, `index_1` ... `index_9 VARCHAR(255) NULL`,
`data LONGTEXT` (ceo entitet kao JSON iz `toArray()`), ključevi `(type, index_1)`, `(type, index_2)`, `(type, index_3)`.
Devet indeksnih kolona je minimum jer `QueueItem` i `PaymentTransactionEntity` imaju po 9 indeksa (vidi 6.4).
Jedna instanca `BaseRepository` radi za jednu klasu entiteta (`setEntityClass`). Svako čitanje i pisanje loguje u Core `Logger`
(komponenta `Repository`) sa zaštitom od rekurzije (`$isLogging`), jer `Logger` sam čita `ConfigEntity` kroz isti repozitorijum.
`LogsRepository extends BaseRepository implements RepositoryWithAdvancedSearchInterface` dodaje `getLogs()` i `countLogs()` koje
Core traži za `MonitoringLog` i `WebhookLog`.

### 4.6 Logger

**`Infrastructure/Logger/Logger.php`** (`extends Singleton`). Statičke metode `logError`, `logWarning`, `logInfo`, `logDebug`
sa potpisom `(string $message, string $component = 'Core', array $context = [])`. Nivoi: `ERROR = 0`, `WARNING = 1`,
`INFO = 2`, `DEBUG = 3` (manji broj = ozbiljnije).

`logMessage()` pravi `LogData` (integracija iz `LoggerConfiguration::getIntegrationName()`, nivo, timestamp u milisekundama,
komponenta, poruka, kontekst) i zatim:

1. ako je *default logger* uključen (`Configuration::isDefaultLoggerEnabled()`) **i** nivo `<= getMinLogLevel()`, zove `DefaultLoggerAdapter::logMessage()` (servis pod ključem `DefaultLoggerAdapter::CLASS_NAME`; Core ne daje implementaciju, plugin je registruje samo ako uključi default logger);
2. **uvek, bez obzira na nivo**, zove `ShopLoggerAdapter::logMessage()`.

Dakle `minLogLevel` ne filtrira ono što stiže u plugin logger. Ako plugin želi da ne upisuje DEBUG poruke, mora sam da filtrira u svom `ShopLoggerAdapter`-u.

`Logger` instancu pravi u konstruktoru uzimajući `ShopLoggerAdapter` i `TimeProvider` iz `ServiceRegister`-a; zato `ShopLoggerAdapter` mora biti registrovan **pre** prvog logovanja. Plugin0 ga registruje u `Bootstrap::initServices()`.

- `Interfaces/LoggerAdapter`: `logMessage(LogData $data): void`.
- `Interfaces/ShopLoggerAdapter extends LoggerAdapter` (ključ `ShopLoggerAdapter::CLASS_NAME`): obavezna plugin implementacija (plugin0: `Plugin0\Infrastructure\LoggerService` → `PrestaShopLogger::addLog`, tabela `ps_log`).
- `Interfaces/DefaultLoggerAdapter` (ključ `DefaultLoggerAdapter::CLASS_NAME`): marker interfejs bez metoda, opciono.
- `LogData` (entitet tipa `'LogData'`, polja `id, integration, logLevel, timestamp, component, message` + `context`; indeksi `integration`, `logLevel`, `timestamp`, `component`): koristi se kao DTO ka adapteru i kao entitet za `AutoTestLogger`. Plugin0 registruje repozitorijum i za njega.
- `LogContextData`: par `name`/`value`; `Logger` prihvata i obične `['ključ' => vrednost]` nizove i pretvara ih.
- `LoggerConfiguration` (`extends Singleton`): kešira `isDefaultLoggerEnabled`, `minLogLevel`, `integrationName` čitajući ih iz `Configuration`; greške pri čitanju guta i vraća default (`false`, `DEBUG`, `'unknown'`).

### 4.7 Serializer

`Infrastructure/Serializer/Serializer.php` (apstraktna): statičke `Serializer::serialize($data): string` i `Serializer::unserialize(string)`
traže instancu pod ključem `Serializer::CLASS_NAME` i zovu `doSerialize`/`doUnserialize`. Core **ne registruje** serializer;
plugin bira:

- `Concrete/JsonSerializer`: za objekte sa `toArray()` upisuje `toArray()` + `class_name`, pri čitanju zove `$class::fromArray($array)`. Za `stdClass` dodaje `className`. Nizovi i skalari idu direktno kroz `json_encode`.
- `Concrete/NativeSerializer`: PHP `serialize`/`unserialize`.

Plugin0 registruje `JsonSerializer`. Posledica: svaki `Task` i `Runnable` koji plugin napiše mora ispravno da implementira
`Interfaces/Serializable` (`fromArray(array): Serializable`, `toArray(): array`), jer se tako čuva u `QueueItem::serializedTask`
i `Process::runner`.

### 4.8 Http (`Infrastructure/Http/`)

Bitno za razumevanje: ovaj HTTP klijent se **ne koristi** za Worldline API. Koristi se za pozive koje Core upućuje
**samom shop-u** (URL iz `Configuration::getAsyncProcessUrl()`): pokretanje pozadinskih procesa i auto-konfiguraciju.
Komunikacija sa Worldline-om ide isključivo kroz SDK (6.3).

- `HttpClient` (apstraktna): `request(method, url, headers, body): HttpResponse` (sinhrono), `requestAsync(method, url, headers, body): void` (fire-and-forget), `autoConfigure(method, url)` (isprobava kombinacije opcija i čuva uspešnu kombinaciju po domenu u `httpConfigurationOptions`). Konstante HTTP metoda i statusa (401, 403, 404, 409).
- `CurlHttpClient extends HttpClient`: podrazumevana registrovana implementacija. Opcije: `CURLOPT_FOLLOWLOCATION` (isključeno ako postoji `open_basedir`), `CURLOPT_MAXREDIRS = 10`, `CURLOPT_IPRESOLVE = V4`, **`CURLOPT_SSL_VERIFYPEER` i `CURLOPT_SSL_VERIFYHOST` su `false`** (`SSL_STRICT_MODE = false`), fiksni User-Agent string, `CURLOPT_TIMEOUT_MS` = `syncRequestTimeout` ili 900000 ms. Async zahtev: `CURLOPT_FRESH_CONNECT`, `CURLOPT_TIMEOUT_MS` = `asyncRequestTimeout` ili 1000 ms; istek vremena (status 0) se smatra uspehom. Kombinacije za auto-konfiguraciju: `SWITCH_PROTOCOL` (http↔https), `CURLOPT_FOLLOWLOCATION = false`, `CURLOPT_IPRESOLVE = V6`.
- `AsyncSocketHttpClient extends CurlHttpClient`: async zahtev šalje ručno sastavljen HTTP/1.1 zahtev preko `pfsockopen` (`tcp://` ili `tls://`, port iz URL-a ili 443/80), `usleep(300000)` posle `fwrite`, pa zatvara. Timeout `asyncRequestTimeout` ili 5 s. Brže od curl-a u multi-tenant okruženjima. Plugin ga može registrovati pod `HttpClient::CLASS_NAME` umesto `CurlHttpClient`.
- `LoggingHttpClient extends HttpClient`: dekorator koji loguje zahtev i odgovor na DEBUG nivou.
- `HttpResponse`: `getStatus()`, `getBody()`, `decodeBodyToArray()`, `getHeaders()`, `isSuccessful()` (2xx).
- `DTO/Options`: par `name`/`value` za curl opcije.
- `AutoConfiguration`: stanja `not-started`, `started`, `succeeded`, `failed`; `start()` postavlja metodu na POST, proba `autoConfigure(POST)`, pa `GET` (i pamti GET ako uspe).
- Izuzeci: `HttpBaseException` i podklase `HttpCommunicationException` (nema odgovora), `HttpRequestException`, `HttpAuthenticationException`, `HttpBatchSizeTooBigException`, `HttpUnhandledException`.

### 4.9 TaskExecution: red zadataka i task runner

Ovo je najsloženiji deo Infrastructure sloja i jedini koji zahteva od plugina (a) poseban repozitorijum i (b) HTTP endpoint.

**Pojmovi**

- `Task` (`TaskExecution/Task.php`, apstraktna, `extends Utility\Events\EventEmitter implements Serializable`): jedinica posla. Plugin/Core nasleđuju i implementiraju `execute()`. Pomoćne metode: `reportProgress(float|int $percent)` (emituje `TaskProgressEvent`; 100 završava zadatak), `reportAlive(bool $force = false)` (emituje `AliveAnnouncedTaskEvent`, najviše jednom u `ALIVE_SIGNAL_FREQUENCY` = 2 s), `getPriority()` (default `Priority::NORMAL`), `getMaxInactivityPeriod()` (konfiguracija ili 300 s), `canBeReconfigured()`/`reconfigure()`, `onFail()`/`onAbort()` (kuke), `getExecutionId()/setExecutionId()`. `Task::getClassName()` vraća **kratko ime klase** (poslednji segment namespace-a); to je vrednost indeksa `taskType`. Default serijalizacija je prazna (`[]`, `new static()`): zadatak sa stanjem mora da pregazi `toArray`/`fromArray`/`serialize`/`unserialize`.
- `Interfaces/Runnable extends Serializable`: `run()`. Implementacije: `TaskRunnerStarter`, `QueueItemStarter`, `AsyncBatchStarter`, `Bootstrap...WaitPaymentOutcomeProcessRunner`.
- `Interfaces/Priority`: `LOW = 1`, `NORMAL = 100`, `HIGH = 1000`.
- `QueueItem` (entitet tipa `'QueueItem'`): omot oko zadatka u redu. Statusi: `created`, `queued`, `in_progress`, `completed`, `failed`, `aborted`. Polja: `id, parentId, status, context, serializedTask, queueName, lastExecutionProgressBasePoints, progressBasePoints, retries, failureDescription, createTime, startTime, finishTime, failTime, earliestStartTime, queueTime, lastUpdateTime, priority`. Napredak je u "base points" (0..10000, 1 bp = 0,01 %). Indeksi (redom): `status`(string), `taskType`(string), `queueName`(string), `context`(string), `queueTime`(dateTime), `lastExecutionProgress`(int), `lastUpdateTimestamp`(int), `priority`(int), `parentId`(int). `getTask()` deserijalizuje zadatak lenjo (`QueueItemDeserializationException` pri grešci) i kači mu listenere `OnReportProgress` i `OnReportAlive`.
- `Process` (entitet tipa `'Process'`): par `guid` (string indeks) + serijalizovani `Runnable`. Čuva ono što treba izvršiti u asinhronom HTTP pozivu.
- `QueueService`: API za rad sa redom. `enqueue(queueName, Task, context = '', priority = NORMAL): QueueItem` (status `queued`, `queueTime`, čuva, emituje `QueueItemEnqueuedEvent`, zove `TaskRunnerWakeup::wakeup()`), `create(...)` (status `created`, za podzadatke orkestratora), `start(QueueItem)` (`queued` → `in_progress` sa uslovom, pa `Task::execute()`), `finish`, `requeue`, `fail(item, description, force = false)` (povećava `retries`; posle `maxTaskExecutionRetries` (default 5) status `failed` i `Task::onFail()`; ako ima roditelja, roditelj se takođe failuje), `abort`, `updateProgress`, `keepAlive`, `find(id)`, `findLatestByType(taskType, context = '')`, `findRunningItems()`, `findOldestQueuedItems(limit)` (po prioritetima od HIGH ka LOW), `batchStatusUpdate`, `validateExecutionRequirements` (prazna kuka). Svako čuvanje ide kroz `QueueItemRepository::saveWithCondition` sa `additionalWhere` nad `status`/`lastUpdateTimestamp`/`lastExecutionProgress` (optimističko zaključavanje); neuspeh → `QueueStorageUnavailableException`. Promene stanja emituju `BeforeQueueStatusChangeEvent`/`QueueStatusChangedEvent` na `EventBus` i `QueueItem*Event` na `QueueItemStateTransitionEventBus`.
- `TaskRunner`: jedan "životni ciklus" runnera, uvek u zasebnom HTTP zahtevu. `run()`: `keepAlive()` (upisuje `taskRunnerStatus` = `{guid, timestamp}` najviše na 2 s) → ako je `isTaskRunnerHalted` prekida → ako je ovaj runner još aktivan (`guid` jednak i nije istekao): `failOrRequeueExpiredTasks()` (zadaci `in_progress` bez `lastUpdateTime` u poslednjih `maxInactivityPeriod` s: ako je napredak porastao → `requeue`, inače `reconfigureTask()` + `fail`) i `startOldestQueuedItems()` (broj slobodnih slotova = `maxStartedTasksLimit` − broj `in_progress`; za svaki najstariji queued item pravi `QueueItemStarter` i dodaje u `AsyncBatchStarter` veličine `asyncStarterBatchSize`; `run()` batch-a pokreće svaki starter kao zaseban async HTTP poziv) → `wakeup()` (spava `taskRunnerWakeupDelay` + proračunato vreme batch-a, svake sekunde `keepAlive`, zatim upisuje prazan status i zove `TaskRunnerWakeup::wakeup()` koji pokreće **novi** runner).
- `TaskRunnerStarter implements Runnable`: nosi `guid`; `run()` proverava da je taj guid aktivan i neistekao, zove `TaskRunner::run()`, zatim emituje `TaskEvents\TickEvent` na `EventBus` (na to su zakačeni periodični listeneri, vidi 6.5) i zove `wakeup()` (no-op ako već postoji aktivan runner).
- `TaskRunnerWakeupService implements TaskRunnerWakeup`: `wakeup()`: ako postoji aktivan i neistekao runner, ne radi ništa; inače generiše guid (`GuidProvider::generateGuid()` = `uniqid(getmypid() . '_', true)`), upisuje status i zove `AsyncProcessService::start(new TaskRunnerStarter($guid))`. Sve greške loguje na DEBUG i guta.
- `RunnerStatusStorage implements TaskRunnerStatusStorage`: čita/piše `taskRunnerStatus` iz `Configuration`; `setStatus` odbija promenu ako je upisan drugi, neprazan guid (`TaskRunnerStatusChangeException`).
- `TaskRunnerStatus`: `guid`, `aliveSinceTimestamp`, `isExpired()` (`aliveSince + taskRunnerMaxAliveTime (15 s) < now`).
- `AsyncProcessStarterService implements AsyncProcessService` (`extends Singleton`): `start(Runnable)`: generiše guid, čuva `Process` (guid + runner), zatim `HttpClient::requestAsync(asyncProcessCallHttpMethod, Configuration::getAsyncProcessUrl(guid))`. `runProcess(string $guid)`: nalazi `Process` po guid-u, zove `runner->run()`, briše `Process`; greške loguje kao ERROR. **Plugin endpoint koji prima async poziv mora da pozove `ServiceRegister::getService(AsyncProcessService::CLASS_NAME)->runProcess($guid)`.**
- `AsyncBatchStarter implements Runnable`: rekurzivno grupiše runnere u podbatcheve (kapacitet `batchSize` runnera + `batchSize` podbatcheva), svaki podbatch pokreće kao zaseban async proces. `getWaitTime()` procenjuje koliko runner treba da sačeka.
- `QueueItemStarter implements Runnable`: po `queueItemId` učitava item, mora biti `queued`, postavlja `ConfigurationManager::setContext(item->getContext())`, zove `QueueService::validateExecutionRequirements` i `start`. Izuzeci: `AbortTaskExecutionException` → `abort`; ostali, ako je item `in_progress` → `fail`.
- `TaskRunnerManager`: `halt()`/`resume()` (postavlja `isTaskRunnerHalted`; `resume` i budi runner).
- `CompositeTask` (sinhrono izvršavanje više podzadataka u jednom `execute()`, sa udelima u napretku) i `Composite\Orchestrator` + `Composite\ExecutionDetails` (asinhroni podzadaci: `createSubJob(Task, weight)` pravi `QueueItem` sa `parentId`, `getSubTask()` apstraktna, napredak roditelja = težinski prosek). `OnReportProgress`/`OnReportAlive` propagiraju napredak i "živ sam" na roditelja.
- Events: `Events/` (`QueueItemCreatedEvent`, `QueueItemEnqueuedEvent`, `QueueItemStartedEvent`, `QueueItemFinishedEvent`, `QueueItemFailedEvent`, `QueueItemRequeuedEvent`, `QueueItemAbortedEvent` na `QueueItemStateTransitionEventBus`; `BeforeQueueStatusChangeEvent`, `QueueStatusChangedEvent` na `EventBus`), `TaskEvents/` (`TaskProgressEvent`, `AliveAnnouncedTaskEvent`, `TickEvent`).
- Izuzeci (`Exceptions/`): `AbortTaskExecutionException`, `ExecutionRequirementsNotMetException`, `ProcessStarterSaveException`, `ProcessStorageGetException`, `QueueItemDeserializationException`, `QueueItemSaveException`, `QueueStorageUnavailableException`, `SyncTaskFailedException`, `TaskRunnerRunException`, `TaskRunnerStatusChangeException`, `TaskRunnerStatusStorageUnavailableException`.

**Kompletna sekvenca izvršavanja jednog zadatka** (jednotenantski, plugin0 konfiguracija):

1. Kod zove `QueueService::enqueue('ime-reda', new NekiTask())`. `QueueItem` se upisuje sa statusom `queued`.
2. `enqueue` zove `TaskRunnerWakeupService::wakeup()`. Ako nema aktivnog runnera: generiše guid G1, upisuje `taskRunnerStatus = {G1, now}`, pravi `Process P1` sa `TaskRunnerStarter(G1)` i šalje async HTTP zahtev na `getAsyncProcessUrl(P1.guid)`.
3. Plugin endpoint (u plugin0 `controllers/front/asyncprocess.php`, od 2026-10-20, MIDU-1230) prima zahtev, bootuje Core, odmah odgovori 200 pa zove `AsyncProcessService::runProcess(guid)`.
4. `runProcess` učitava `Process P1`, zove `TaskRunnerStarter::run()` → `TaskRunner::run()` sa guid G1.
5. `TaskRunner` nalazi najstarije `queued` iteme, za svaki pravi `QueueItemStarter(itemId)`, pakuje u `AsyncBatchStarter` i pokreće: za svaki starter novi `Process` + novi async HTTP zahtev na isti endpoint.
6. Svaki od tih zahteva kroz `runProcess` dolazi do `QueueItemStarter::run()` → `QueueService::start(item)` → `Task::execute()`. Zadatak zove `reportProgress`/`reportAlive`; na 100 % `QueueService::finish`. Izuzetak → `fail` (ponavljanje do 5 puta) ili `abort`.
7. Runner iz koraka 4 posle pokretanja spava (`wakeup delay`), upisuje prazan status, pa `wakeup()` pokreće runner G2 novim async zahtevom. `TaskRunnerStarter` zatim emituje `TickEvent`.
8. Ciklus se nastavlja dokle god se runner budi. Ako prestane (npr. server ne prihvata self-pozive), sledeći `enqueue()` iz bilo kog zahteva ponovo pokreće ciklus. Ako aktivan runner "umre", novi može da krene tek kad status istekne (15 s).

Šta Core *ne* radi, a plugin endpoint mora (neprovereno u Core-u, preporuka iz prirode `requestAsync` sa timeout-om 1 s): endpoint treba da nastavi izvršavanje i kad klijent prekine vezu (`ignore_user_abort(true)`, dovoljno dug `max_execution_time`, odgovor poslat pre dugog posla).

### 4.10 AutoTest (`Infrastructure/AutoTest/`)

Dijagnostika "da li red zadataka radi". `AutoTestService::startAutoTest()` prebacuje `ShopLoggerAdapter` na `AutoTestLogger`
(upisuje `LogData` entitete u bazu), briše stare, enqueue-uje `AutoTestTask` u red `'Auto-test'`; `getAutoTestTaskStatus(id)`
vraća `AutoTestStatus` (status, finished, error, logovi); ako je item `queued` duže od 30 s → `'timeout'`. `stopAutoTestMode(callable)`
vraća originalni logger. Plugin ne mora da koristi.

### 4.11 Utility, Data, Events

- `Utility/TimeProvider` (singleton): `getCurrentLocalTime()`, `getDateTime(int ts)`, `getMillisecondsTimestamp()`, `getMicroTimestamp()`, `sleep(int)`, `serializeDate`/`deserializeDateString` (format `DATE_ATOM`).
- `Utility/GuidProvider` (singleton): `generateGuid()`.
- `Utility/Events/Event` (apstraktna), `EventEmitter` (`when(eventClass, callable)`, zaštićeni `fire`), `EventBus` (singleton, javni `fire`).
- `Utility/Php`: `classUsesRecursive`, `traitUsesRecursive` (nije korišćeno u Core-u 1.0.7).
- `Data/DataTransferObject` (apstraktna: `fromArray` baca `RuntimeException` ako nije implementirano, `fromBatch`, `toBatchArray`, `toArray` apstraktna), `Data/Transformer` (`transform`, `batchTransform`, `trim`).
- `Exceptions/BaseException`, `ServiceNotRegisteredException`, `StorageNotAccessibleException`.

---

## 5. BusinessLogic sloj

### 5.1 Domain – modeli i vrednosni objekti (`BusinessLogic/Domain/`)

Svi domenski objekti su obični PHP objekti sa validacijom u konstruktoru ili statičkoj fabrici. Greške su podklase
`Translations\Exceptions\BaseTranslatableException` koje nose `TranslatableLabel(message, code, params)`; aspekt ih pretvara
u `TranslatableErrorResponse` sa poljima `statusCode`, `errorCode` (npr. `connection.invalidActiveCredentials`), `errorMessage`, `errorParameters`.

**Checkout (iznosi i korpa)**

- `Checkout/Amount`: iznos u **najmanjim jedinicama valute kao int** (`fromInt`), ili `fromFloat` (množi sa `10^minorUnits` i zaokružuje). `plus`/`minus` (baca `CurrencyMismatchException` ako valute nisu iste), `multiply(int)`, `getPriceInCurrencyUnits()`, `toArray()`/`fromArray()` (`['value' => int, 'currency' => 'EUR']`).
- `Checkout/Currency`: `fromIsoCode('EUR')` (tačno 3 slova, inače `InvalidCurrencyCode`), `getMinorUnits()` (tabela izuzetaka: 0 za JPY, ISK...; 3 za BHD, KWD...; 4 za CLF, UYW; default 2), `getDefault()` = EUR.
- `Checkout/TaxRate` (0 ≤ rate < 100, inače `InvalidTaxRate`), `Checkout/TaxableAmount` (`amountExclTax`, `amountInclTax`, `taxRate`; fabrike `fromAmounts`, `fromAmountExclTaxAndTaxRate`, `fromAmountInclTaxAndTaxRate`, `fromAmountExclTax`, `fromAmountInclTax`; `getTaxAmount`, `plus`, `minus`, `multiply`).
- `Checkout/Country`: `fromIsoCode('RS')` (upper-case, dva slova se ne validiraju).
- `Checkout/Cart/Cart`: `merchantReference` (ID narudžbine/korpe u shopu), `total: Amount`, `totalInEUR: ?Amount` (treba za 3DS exemption logiku), `customer`, `lineItems`, `shipping`, `discount`, `descriptor` (soft descriptor).
- `Cart/Customer/Customer`: `contactDetails` (email, phone), `billingAddress`, `merchantCustomerId`, `isGuest`, `locale` (default `en_GB`; `getFormattedLocale()` svodi na jednu od `SUPPORTED_LOCALES`), `device` (`Device`: acceptHeader, userAgent, ipAddress, colorDepth, screen..., timezoneOffset, javaEnabled; za 3DS).
- `Cart/Address` (country, state, city, zip, street, houseNumber, personalInformation, companyName, additionalInfo), `Cart/Customer/PersonalInformation` (firstName, lastName, gender, title), `Cart/Customer/ContactDetails`.
- `Cart/LineItem` (product, unitPrice: TaxableAmount, quantity, discount, opcioni `lineTotal`), `Cart/Product` (id, name, upcCode, type, unit), `Cart/LineItemCollection` (`getTotal`, `getQuantitySum`, `getCount`, `isEmpty`), `Cart/Shipping` (cost: TaxableAmount, address, contactDetails).
- `Cart/CartProvider` (interfejs sa `get(): Cart`): **plugin implementira** i prosleđuje u request objekte. `MemoryCachingCartProvider` kešira rezultat u memoriji. `RoundingTotalsCartProvider` upoređuje `Cart::total` sa zbirom stavki − popust + dostava i razliku dodaje kao stavku `rounding` (ako je total veći) ili kao dodatni popust (ako je manji). Request objekti (`HostedCheckoutSessionRequest`, `PaymentRequest`, `PaymentLinkRequest`) automatski umotavaju prosleđeni provider u `MemoryCachingCartProvider(new RoundingTotalsCartProvider($cart))`.
- `Checkout/SurchargeRequest` (amount, token) / `SurchargeResponse` (netAmount, surchargeAmount, totalAmount), `Checkout/DataBag`.

**Connection**

- `Connection/ConnectionMode`: `live`/`test` (`parse`, `live()`, `test()`, `equals`).
- `Connection/Credentials`: `pspId`, `apiKey`, `apiSecret`, `webhookKey`, `webhookSecret`.
- `Connection/ConnectionDetails`: `mode` + `liveCredentials` + `testCredentials`; konstruktor baca `InvalidConnectionDetailsException` ako za aktivni mode nema kredencijala. `getActiveCredentials()`.
- `Connection/ActiveConnectionProvider`: `get(): ?ConnectionDetails` iz `ConnectionConfigRepositoryInterface`, **kešira rezultat u polju** za ceo život instance (a instanca je `SingleInstance`, dakle ceo PHP proces).
- `Connection/Repositories/ConnectionConfigRepositoryInterface`: `saveConnection`, `getConnection`, `getOldestConnection`, `getOldestConnectedStore`, `disconnect`.

**GeneralSettings**

- `PaymentAction`: `AUTHORIZE = 'FINAL_AUTHORIZATION'`, `AUTHORIZE_CAPTURE = 'SALE'`; `fromState`, `authorize()`, `authorizeCapture()`.
- `AutomaticCapture`: dozvoljene vrednosti u minutima `-1` (never), `60`, `120`, `240`, `480`, `1440`, `2880`, `7200`.
- `PaymentAttemptsNumber`: 0..10. `LogRecordsLifetime`: 1..14 dana. `PayByLinkExpirationTime`: 0..180 dana.
- `PaymentSettings`: `paymentAction` (default SALE), `automaticCapture` (default never), `paymentAttemptsNumber` (default 10), `applySurcharge`, statusi narudžbine za mapiranje (`paymentCapturedStatus`, `paymentErrorStatus`, `paymentPendingStatus`, `paymentAuthorizedStatus`, `paymentCancelledStatus`, `paymentRefundedStatus`, `paymentPartiallyRefundedStatus`), `template` (variant Hosted Checkout strane), `sendShoppingCart` (default true), `displayConfirmationPage` (default true).
- `LogSettings` (`debugMode`, `logRecordsLifetime`), `PayByLinkSettings` (`enable`, `title`, `expirationTime` default 7 dana), `WebhookSettings` (`additionalWebhookUrls`; `isValidWebhookUrl` zahteva https i hostname sa tačkom), `GeneralSettingsResponse` (sve zajedno).
- Izuzeci: `InvalidActionTypeException`, `InvalidAutomaticCaptureValueException`, `InvalidExemptionTypeException`, `InvalidLogRecordsLifetimeException`, `InvalidPayByLinkExpirationTimeException`, `InvalidPaymentAttemptsNumberException`, `InvalidWebhookUrlException`, `WebhookSettingsNotSupportedException`.

**Payment**

- `Payment/PaymentId`: validira format `^\d+(_\d+)?$`. Bez `_` i bez prefiksa `90000` dodaje `_0` (npr. `4365991440` → `4365991440_0`). `getTransactionId()`: pre `_`, ili za format `900000xxxxxxxxxx000` (stari) i `90000xxxxxxxxxxx000` (novi) seče prefiks i 3 cifre sufiksa. `getOldTransactionId()` je fallback sa 6-znakovnim prefiksom za stare upise.
- `Payment/StatusCode`: int kod Worldline statusa. Grupe: `PENDING_STATUS_CODES = [0,4,46,50,51,52,55,91,92,99]`, `CANCELLATION_STATUS_CODES = [1,2,6]`, `REFUND_STATUS_CODES = [7,8,85]`, `REFUND_REQUESTED = [81,82]`, `CAPTURE = [9]`, `CAPTURE_REQUESTED = [4,91,92,99]`, `CANCEL = [6,61,62]`. Fabrike `incomplete()` = 0, `authorized()` = 5, `completed()` = 9. Metode `isPending`, `isCanceledOrRejected`, `isRefunded`.
- `Payment/PaymentTransaction`: lokalni zapis plaćanja: `merchantReference`, `paymentId`, `returnHmac` (RETURNMAC iz Worldline odgovora), `statusCode` (default incomplete), `customerId`, `createdAt`, `updatedAt`, `returnedAt` (kad se kupac vratio u shop), `paymentMethod` (naziv), `captureAt` (zakazani auto-capture), `paymentLinkId`. `createFromPaymentLink()`.
- `Payment/PaymentDetails`: rezultat `GET /payments/{id}/details`: `statusCode`, `amount`, `tokenId`, `amounts: PaymentAmounts` (refunded, refundRequested, captured, captureRequested, cancelled), `fullyPaid`, `paymentMethod` (naziv), `statusOutput` (`StatusOutput`: authorized/cancellable/refundable/errors), `paymentSpecificOutput` (`PaymentSpecificOutput`: productId, fraudResult, 3DS liability, exemption, surchargeAmount, bin, cardNumber), `status` (string), `operations: PaymentOperation[]`.
- `Payment/Payment` (rezultat `GET /payments/{id}`): statusCode, amount, tokenId, status, productId, paymentMethodName. `PaymentCapture`, `PaymentRefund`, `StatusError`.
- `Payment/Repositories/PaymentTransactionRepositoryInterface`: `save`, `updatePaymentId`, `get(paymentId, returnHmac = null)`, `getByPaymentLinkId`, `getByMerchantReference`, `lockOrderCreation`, `unlockOrderCreation`. `PaymentSettingsRepositoryInterface` (domenski): `getPaymentSettings`.
- Izuzeci: `InvalidPaymentIdException`, `PaymentTransactionNotFoundException`.

**PaymentMethod**

- `PaymentProductId`: string ID Worldline proizvoda ili pseudo-ID. Pseudo: `cards` (`'cards'`), `hosted_checkout`. Pravi: `1` Visa, `2` Amex, `3` Mastercard, `117` Maestro, `125` JCB, `128` Discover, `130` Carte Bancaire, `132` Diners, `56` UPI, `302` Apple Pay, `320` Google Pay, `771` SEPA Direct Debit, `809` iDEAL, `840` PayPal, `3012` Bancontact, `3103` CADO, `3112` Illicado, `3116` Spirit of Cadeau, `3124` Przelewy24, `3203` PostFinance Pay, `3204` BLIK, `3301`-`3306` Klarna varijante, `5001` Bizum, `5003` Linxo, `5100` Cpay, `5111` Oney 3x, `5112` Oney 4x, `5125` Oney Financement Long, `5127` Oney Bank Card, `5129` Cofidis, `5131` Sofinco, `5133` Cetelem, `5402` Mealvouchers, `5403` Chèque-Vacances Connect, `5404` WeChat Pay, `5405` Alipay+, `5406` EPS, `5407` Twint, `5408` Bank Transfer, `5500` Multibanco, `5600` Oney gift card, `5601` Cadhoc, `5700` Intersolve, `5908` MB Way. Metode: `parse` (samo podržani), `isSupported`, `equals(string)`, `isCardBrand`, `isCardType` (brendovi + cards + Intersolve + Cpay + Bancontact), `isMobileType` (Apple/Google Pay), `isRedirectType` (sve ostalo), `isSeparateCaptureSupported` (lista `SEPARATE_CAPTURE_SUPPORTED`), `getForHostedCheckoutPage(list)` (izbacuje `cards`, `hosted_checkout`, `5402`), `getAllCardBrands()`, imenovane fabrike (`cards()`, `googlePay()`, ...).
- `PaymentMethod`: `productId`, `name: TranslationCollection`, `enabled`, `template`, `additionalData: ?PaymentMethodAdditionalData`, `paymentAction: ?PaymentAction` (per-metoda override).
- `PaymentMethodCollection`: mapa po productId: `add`, `remove(ids)`, `get`, `has`, `intersect`, `union`, `isCardsGroupingEnabled`, `isCardsTokenizationEnabled` (cards sa `FlowType::iframe`), `isEmpty`, `toArray`.
- `PaymentMethodDefaultConfigs::PAYMENT_METHOD_CONFIGS`: za svaki productId podrazumevano ime (EN), `paymentGroup` (cards, hosted, mobile, realTimeBanking, giftCards, prepaid, instalment, eWallet, postpaid, directDebit) i `integrationTypes` (`hosted`, `redirect`, `tokenization`). `getName(productId, brand)` ubacuje ime brenda u `'Bank Transfer by %s'` i `'Hosted Checkout (Redirect To %s)'`.
- `MethodAdditionalData/`: `CreditCard` (vaultTitles, `ThreeDSSettings`, `FlowType` iframe/redirect, `enableGroupCards`, `AuthorizationMode` FINAL_AUTHORIZATION/PRE_AUTHORIZATION), `HostedCheckout` (logo, enableGroupCards, ThreeDSSettings), `GooglePay` (ThreeDSSettings), `BankTransfer` (instantPayment), `Sepa` (`RecurrenceType` unique/recurring, `SignatureType` SMS/UNSIGNED/TICK_BOX), `Intersolve` (`SessionTimeout` 1..1440 min, `Intersolve\PaymentProductId` 5700..5799), `Oney` (paymentOption), `Descriptor` (max 15 znakova; Sofinco, Linxo), `ThreeDSSettings/ThreeDSSettings` (enable3ds default true, enforceStrongAuthentication, enable3dsExemption, `ExemptionType` low-value / transaction-risk-analysis, exemptionLimit default 30,00 EUR), `ThreeDSSettings/ExemptionType`.
- `ThreeDSSettingsService::getThreeDSSettings(productId)`: za kartične tipove uzima iz konfiguracije `cards`, za Google Pay i hosted checkout iz njihove konfiguracije, inače `null`.
- `PaymentProductService::getSupportedPaymentMethods(bool $withCards = true)`.
- `PaymentMethodResponse` (DTO za admin listu), `Repositories/PaymentConfigRepositoryInterface` (`getPaymentMethods`, `getPaymentMethod`, `savePaymentMethod`, `deleteByMode`).
- Izuzeci: `InvalidAuthorizationModeException`, `InvalidFlowTypeException`, `InvalidPaymentProductIdException`, `InvalidRecurrenceTypeException`, `InvalidSessionTimeoutException`, `InvalidSignatureTypeException`.

**Tokovi plaćanja: request/response objekti**

- `HostedCheckout/HostedCheckoutSessionRequest(CartProvider, returnUrl, ?PaymentProductId, ?tokenId)`.
- `HostedTokenization/PaymentRequest(hostedTokenizationId, CartProvider, returnUrl, ?tokenId)`, `HostedTokenization/HostedTokenization` (url, invalidTokens), `HostedTokenization/PaymentResponse` (paymentTransaction, redirectUrl), `HostedTokenization/Token` (customerId, tokenId, productId, cardNumber, expiryDate), `HostedTokenization/TokenResponse` (za prikaz sačuvanih kartica), `Repositories/TokensRepositoryInterface` (`get`, `save`, `getForCustomer`, `delete`).
- `PaymentLinks/PaymentLinkRequest(CartProvider, returnUrl, ?expiresAt)`, `PaymentLink` (paymentLinkId, merchantReference, paymentId, expiresAt, redirectionUrl, status), `PaymentLinkResponse`, `Repositories/PaymentLinkRepositoryInterface`.
- `Capture/CaptureRequest(PaymentId, ?Amount, ?merchantReference)` i `CaptureResponse(id, statusCode, status)`; `Cancel/CancelRequest(PaymentId, Amount, ?merchantReference)` / `CancelResponse`; `Refund/RefundRequest(PaymentId, Amount, ?merchantReference)` / `RefundResponse(id, statusCode, status, amount)`.
- `Order/OrderDetails` (amount, payments: `OrderPayment[]`, capture/refund/cancel: `OrderAction` (possible, done, pending, available), errors).

**Ostalo u Domain-u**

- `OrderStatusMapping/Models/OrderStatusMapping` (ID-jevi statusa shopa za captured, error, pending, authorized, cancelled, refunded, partially refunded) i `OrderStatusMapping/StatusMappingService`: `getStatusMapping(StatusCode)` (9 → captured; 5 → authorized; 1,2,6 → cancelled; 7,8,85 → refunded; pending kodovi → pending; inače `''`), `getFullStatusMapping(PaymentDetails)` (ako je delimično refundirano, vraća partially refunded status).
- `Monitoring/MonitoringLog` (zapis API zahteva/odgovora: requestId, orderId, paymentNumber, logLevel, message, createdAt, requestMethod, requestEndpoint, requestBody, statusCode, responseBody, transactionLink, orderLink), `Monitoring/WebhookLog` (orderId, paymentNumber, paymentMethod, status, type, createdAt, statusCode, webhookBody, transactionLink, orderLink), `Monitoring/WebhookStatuses` (mapa Worldline kategorija → COMPLETED/PAID/AWAITING/IN_PROGRESS/FAIL), `Monitoring/ContextLogProvider` (singleton sa `currentOrder` i `paymentNumber`, proxy-ji ga pune pre SDK poziva da bi monitoring log znao narudžbinu), `Monitoring/Repositories/*` (`MonitoringLogRepositoryInterface`, `WebhookLogRepositoryInterface`, `RepositoryWithAdvancedSearchInterface`).
- `Multistore/StoreContext` (singleton: `storeId`, `origin`; `doWithStore(storeId, callable, params)` postavlja i vraća prethodni), `Multistore/TenantContext` (isto za `tenantId`), `Multistore/TenantRegistryInterface` (`isMultiTenant`, `getTenantBatch(offset, size)`).
- `Translations/Model/TranslatableLabel`, `Translation(localeCode, message)`, `TranslationCollection` (default + mapa po lokalu; `fromArray([['locale' => 'EN', 'value' => '...'], ...])`).
- `Webhook/WebhookData` (id, merchantReference, type, created, statusCategory, statusCode, webhookBody), `Webhook/WebhookMode` (statička klasa: `MANUAL` default, `AUTOMATIC`; `set()` zove samo bootstrap), `Webhook/Transformers/WebhookTransformerInterface`, `Webhook/Exceptions/WebhookMerchantMismatchException`.
- `Stores/Models/Store(storeId, storeName, maintenanceMode)`, `Stores/Models/StoreOrderStatus(statusId, statusName)`, `Stores/StoreService` (domenski; omotava integracioni `StoreService` i baca `FailedToRetrieveStoresException`/`FailedToRetrieveOrderStatusesException`; `getCurrentStore()` = prva povezana prodavnica ili default).
- `Language/Language(code, logo)`, `Language/LanguageCode` (`fromIso('en_GB'|'en-GB'|'en')` sa mapom jezik → zemlja).
- `ProductTypes/ProductType` (`FoodAndDrink`, `HomeAndGarden`, `GiftAndFlowers`; za Mealvouchers).
- `Version/VersionInfo(installed, latest)`, `Time/TimeProviderInterface`, `LogCleanup/LogCleanupListener` + `LogCleanupTaskServiceInterface`, `Disconnect/DisconnectTaskEnqueuerInterface`, `ApiFacades/Request/Request` (apstraktna `transformToDomainModel()`), `ApiFacades/Response/Response` (apstraktna: `isSuccessful()` default true, `getStatusCode()` default 200, `toArray()`).

### 5.2 Domain/Integration – interfejsi koje plugin implementira (`BusinessLogic/Domain/Integration/`)

Ovo su "portovi" ka platformi. Core **ne registruje** nijednu od ovih implementacija; ako plugin ne registruje, prvi
`getService` baca `ServiceNotRegisteredException`.

| Interfejs (ključ u `ServiceRegister` = FQCN) | Metode | Ko ga u Core-u koristi |
|---|---|---|
| `Encryption\Encryptor` | `encrypt(string): string`, `decrypt(string): string` | `ConnectionConfigRepository` (apiKey, apiSecret, webhookKey, webhookSecret; pspId se ne šifruje) |
| `Metadata\MetadataProviderInterface` | `getMetadata(): Metadata` (platformName, platformVersion, platformVariant, pluginVersion, storeUrl) | `MerchantClientFactory` → `MetricsProvidingCommunicator` (header `platforminfo`) |
| `Stores\StoreService` | `getStoreDomain()`, `getStores(): Store[]`, `getDefaultStore()`, `getStoreById(string)`, `getDefaultOrderStatusMapping(): OrderStatusMapping`, `getStoreOrderStatuses(): StoreOrderStatus[]` | `Domain\Stores\StoreService`, `GeneralSettingsService::getPaymentSettings()` (default mapiranje) |
| `Language\LanguageService` | `getEnabledLanguages(): Language[]` | `LanguageController`, `PaymentService::savePaymentMethod` (dopunjava prevode imena) |
| `Logo\LogoUrlService` | `getHostedCheckoutLogoUrl(): string`, `getLogoUrl(string $productId): string` | `PaymentService` (default logo za hosted checkout), `HostedTokenizationService::getTokens` (logo sačuvane kartice) |
| `Payment\ShopPaymentService` | `savePaymentMethod(PaymentMethod)`, `enable(productId, bool)`, `deletePaymentMethods(string $mode)` | `AdminConfig PaymentController::save/enable` (posle čuvanja u Core), `DisconnectService::disconnectIntegration` |
| `ShopOrderService` | `createShopOrder(PaymentTransaction, PaymentDetails, string $newState)`, `updateStatus(...)`, `cancelShopOrder(...)`, `refundShopOrder(...)` | `StatusUpdateService` (jedini pozivalac; ovo je srce integracije narudžbina) |
| `Version\VersionService` | `getVersionInfo(): VersionInfo` | `VersionController` |

Pored njih, iz Infrastructure sloja plugin obavezno daje: `Configuration` podklasu (4.4), `ShopLoggerAdapter` (4.6),
`Serializer` (4.7), i repozitorijume za sve entitete (6.4) uključujući `QueueItemRepository` za `QueueItem`.
Opciono može da pregazi: `HttpClient` (4.8), `TenantRegistryInterface` (default `DefaultTenantRegistry`: jedan tenant sa praznim ID-jem),
`WebhookSettingsRepositoryInterface` (Core daje `WebhookSettingsRepository`), `MonitoringLogRepository`/`WebhookLogRepository`
metode `getOrderUrl()` (default `''`) i `getOrderIdByMerchantReference()` (default vraća istu referencu) nasleđivanjem i
ponovnom registracijom, `DefaultLoggerAdapter`.

### 5.3 AdminConfig (`BusinessLogic/AdminConfig/`)

Kontroleri koje `AdminAPI` izlaže (svi se dobijaju iz `ServiceRegister`-a kao `SingleInstance`):

| Kontroler | Metode | Request → Response (`toArray()`) |
|---|---|---|
| `ConnectionAPI\Controller\ConnectionController` | `connect(ConnectionRequest)`, `getConnectionConfig()` | `ConnectionRequest(mode, testPspid, testApiKey, testApiSecret, testWebhooksKey, testWebhooksSecret, livePspid..., liveWebhooksSecret)`: kredencijali okruženja se prave samo ako je `apiKey` tog okruženja neprazan. `ConnectionResponse` → `[]`. `ConnectionConfigResponse` → `['webhookMode' => ...]` kad nema konekcije; inače i `mode`, `sandboxData` i `liveData` (pspid, apiKey, apiSecret, webhooksKey, webhooksSecret, **dešifrovano**). |
| `GeneralSettingsAPI\Controller\GeneralSettingsController` | `getGeneralSettings()`, `savePaymentSettings(PaymentSettingsRequest)`, `saveLogSettings(LogSettingsRequest)`, `savePayByLinkSettings(PayByLinkSettingsRequest)`, `saveWebhookSettings(WebhookSettingsRequest)`, `disconnect()` | `GeneralSettingsResponse` → `accountSettings`, `paymentSettings`, `logSettings` (`debugMode`, `logDays`), `payByLinkSettings`, `webhookMode`, `webhookSettings`. Save* → `SaveSettingsResponse` `[]`. `disconnect()` → `DisconnectResponse` `[]`. |
| `IntegrationAPI\Controller\IntegrationController` | `getState()` | `StateResponse` → `['state' => 'connection'\|'payments']` (`payments` ako je konekcija sačuvana **i** validna kod Worldline-a: `ConnectionService::isLoggedIn` zove `testConnection`). |
| `LanguageAPI\Controller\LanguageController` | `getLanguages()` | `LanguageResponse` → `['EN' => ['code' => 'en', 'logo' => url], ...]`. |
| `MonitoringAPI\Controller\LogsController` | `getMonitoringLogs(page = 1, size = 10, search = '')`, `downloadMonitoringLogs()`, `getWebhookLogs(...)`, `downloadWebhookLogs()` | `MonitoringLogsResponse` → `monitoringLogs[]`, `nextPageAvailable`, `beginning`, `end`, `numberOfItems`; `WebhookLogsResponse` isto sa `webhookLogs[]`; Download* → ceo niz logova. |
| `PaymentAPI\Controller\PaymentController` | `list()`, `enable(productId, bool)`, `save(PaymentMethodRequest)`, `getPaymentMethod(productId)` | `PaymentMethodsResponse` → lista `[paymentProductId, name{locale,value}, paymentGroup, integrationTypes, enabled]`; `PaymentMethodResponse` → `paymentProductId, name[], enabled, template, additionalData{...po tipu}, paymentAction`. `save` i `enable` **zovu i `ShopPaymentService`**. `PaymentMethodRequest` ima 22 parametra konstruktora (productId, name[], enabled, template, paymentAction, vaultTitles, logo, enableGroupCards, paymentOption, sessionTimeout, intersolveProductId, recurrenceType, signatureType, instantPayment, enable3ds, enforceStrongAuthentication, enable3dsExemption, exemptionType, amount, flowType, authorizationMode, descriptor). |
| `ProductTypesAPI\Controller\ProductTypesController` | `list(productId)`, `save(productId, ProductType)`, `delete(productId)` | `ProductTypesListResponse` → `availableProductTypes[]`, `selectedProductType`. |
| `StoreAPI\Controller\StoreController` | `getStores()`, `getCurrentStore()`, `getStoreOrderStatuses()` | `StoresResponse`, `StoreResponse` (`storeId, storeName, maintenanceMode`; fallback store `'failBack'`), `StoreOrderStatusesResponse` (`value`, `label`). |
| `VersionsAPI\Controller\VersionController` | `getVersionInfo()` | `VersionInfoResponse` → `installed`, `latest`. |

Servisi (`AdminConfig/Services/`):

- `Connection\ConnectionService`: `connect(ConnectionDetails)` (validira kod Worldline-a kroz `ConnectionProxyInterface::isConnectionValid`, inače `InvalidConnectionDetailsException` sa kodom `connection.apiValidationFailed`; zatim `saveConnection`), `isLoggedIn()`, `getConnectionConfig()`.
- `Disconnect\DisconnectService::disconnect()`: `ShopPaymentService::deletePaymentMethods(mode)`, brisanje payment/log/paymentMethod/payByLink podešavanja za aktivni mode, `ConnectionConfigRepository::disconnect()` (prebacuje na drugo okruženje ako ima kredencijale, inače briše red), zatim `DisconnectTaskEnqueuerInterface::enqueueDisconnectTask(mode)` (pozadinsko brisanje logova; traži red zadataka).
- `GeneralSettings\GeneralSettingsService`: get/save za svaka podešavanja; `getPaymentSettings()` bez sačuvanih vraća default sa mapiranjem iz `StoreService::getDefaultOrderStatusMapping()`; `getLogSettings()` default `(debugMode false, 14 dana)`; `saveWebhookSettings` u MANUAL režimu baca `WebhookSettingsNotSupportedException` ako su date URL-ovi.
- `Monitoring\MonitoringLogsService`, `Monitoring\WebhookLogsService` (`logWebhook(WebhookData)` pravi `WebhookLog`; naziv metode plaćanja dohvata od Worldline-a samo ako lokalno postoji `PaymentTransaction` za taj paymentId).
- `Payment\PaymentService`: `getPaymentMethods()` (unija sačuvanih i default konfiguracija svih podržanih bez pojedinačnih kartičnih brendova), `savePaymentMethod` (dopunjava logo i prevode), `getPaymentMethod`, `enablePaymentMethod`. Default `CREDIT_CARD_VAULT_TITLES` za EN/DE/FR/ES/IT.
- `ProductTypes\ProductTypeService`.
- Interfejsi repozitorijuma za podešavanja: `GeneralSettings/Repositories/{Log,PayByLink,Payment,Webhook}SettingsRepositoryInterface`, `Disconnect/Repositories/DisconnectRepositoryInterface`, `ProductTypes/Repositories/ProductTypeRepositoryInterface`, `Connection/Proxies/ConnectionProxyInterface`.

### 5.4 PaymentProcessor (`BusinessLogic/PaymentProcessor/`)

Kontroleri (`CheckoutAPI`, `WebhooksAPI`, `AdminAPI`):

| Kontroler | Metode | Beleške |
|---|---|---|
| `CheckoutAPI\Controller\PaymentMethodsController` | `getAvailablePaymentMethods(CartProvider): PaymentMethodsResponse` | Vraća presek uključenih metoda i onih koje Worldline nudi za zemlju/iznos/valutu korpe (`GET /products`), plus `ValidTokensResponse` sa sačuvanim karticama kupca (ako nije gost). |
| `CheckoutAPI\Controller\HostedCheckoutController` | `createSession(HostedCheckoutSessionRequest): HostedCheckoutSessionResponse` | `getRedirectUrl()`, `getReturnHmac()`. Čuva `PaymentTransaction`. |
| `CheckoutAPI\Controller\HostedTokenizationController` | `crate(CartProvider, ?PaymentProductId)` (**tako se zove, sa greškom u imenu**), `pay(PaymentRequest): PayResponse`, `getTokens(customerId): TokensResponse`, `deleteToken(customerId, tokenId)` | `PayResponse::isRedirectRequired()`, `getRedirectUrl()`, `getPaymentTransaction()`. |
| `CheckoutAPI\Controller\PaymentController` | `startWaitingForOutcome(?PaymentId, ?returnHmac, ?merchantReference)`, `startWaitingForOutcomeInBackground(...)`, `getPaymentOutcome(...): PaymentOutcomeResponse` (`isWaiting()`, `getPaymentTransaction()`), `updateOrderStatus(PaymentId, ?returnHmac)`, `getPaymentTransaction(merchantReference)` | Landing/return strana i pozadinsko praćenje. |
| `WebhooksAPI\Controller\WebhooksController` | `process(string $body, array $headers): WebhookResponse` | transform (validacija potpisa + merchantId) → log → `WebhookService::process`. |
| `AdminAPI\Controller\PaymentLinksController` | `create(PaymentLinkRequest)`, `get(merchantReference)` | `PaymentLinkResponse::getRedirectUrl()`. |

Servisi:

- `Services\PaymentMethod\PaymentMethodService::getAvailablePaymentMethods(CartProvider)`: `enabled ∩ availableFromWorldline`, izbacuje pojedinačne kartične brendove, dodaje `cards` ako je uključen (i pojedinačne brendove ako grupisanje kartica nije uključeno), dodaje `hosted_checkout` ako je uključen, izbacuje Mealvouchers ako kupac nema email ili nijedan proizvod nema dodeljen `ProductType`. `getCardsPaymentAction()`, `getCardsTemplate()`, `calculateSurcharge()`.
- `Services\HostedCheckout\HostedCheckoutService::createSession`: za Mealvouchers zamenjuje korpu sa `MealvouchersCartProvider` (spaja sve stavke u jednu), učitava token ako je dat, zove proxy, upisuje `customerId` (ako nije gost) i naziv metode u transakciju, čuva `PaymentTransaction`.
- `Services\HostedTokenization\HostedTokenizationService`: `create` (URL za iframe), `getValidTokens` (pravi hosted tokenization sesiju sa sačuvanim tokenima da bi Worldline rekao koji su nevažeći, pa ih briše), `pay` (`POST /payments` sa `hostedTokenizationId`; ako nema `redirectUrl` (nema 3DS redirect), odmah `WaitPaymentOutcomeProcess::startInBackground` → asinhroni HTTP proces), `getTokens`, `deleteToken`.
- `Services\Payment\StatusUpdateService::updateOrderStatus(PaymentId, ?returnHmac)`: **centralna logika statusa**. Koraci: nađi `PaymentTransaction` (inače `PaymentTransactionNotFoundException`) → `GET /payments/{id}/details` → upiši statusCode i paymentMethod u transakciju → `newState = StatusMappingService::getFullStatusMapping()`; ako prazno, kraj → ako je status 5 (authorized) i podešeno `FINAL_AUTHORIZATION` + automatic capture ≠ never i `captureAt` nije postavljen: `captureAt = now + N minuta` → ako je refundirano (status ili `refundedAmount > 0`): pun refund → status mapiran za kod 8; `ShopOrderService::refundShopOrder` → inače ako treba kreirati narudžbinu (`fullyPaid` i status 5 ili 9, ili ne-pending i isteklo čekanje): `lockOrderCreation` (zapis `PaymentTransactionLockEntity`, važi 30 s) → `ShopOrderService::createShopOrder` → `unlock`; zatim `saveToken` (ako kupac nije gost i Worldline vratio token, dohvata `GET /tokens/{id}` i čuva) → inače ako je status cancelled/rejected: `cancelShopOrder` → inače `updateStatus`. `getPaymentOutcome(transaction)` vraća `WaitPaymentOutcome` (`isWaiting()` = status pending i nije isteklo `MAX_PENDING_TRANSACTIONS_WAIT_TIME` = 10 s od `returnedAt`, ili od `createdAt + 2 h` ako se kupac nije vratio).
- `BackgroundProcesses\WaitPaymentOutcomeProcess`: `startWaiting(paymentId, returnHmac)` (sinhrona petlja: `sleep(5)` dok je `isWaiting()`, zatim `updateOrderStatus`), `startWaitingForPaymentLink(merchantReference)`, `startInBackground(...)` (resetuje `returnedAt` i pokreće `WaitPaymentOutcomeProcessRunner` kao async proces), `getPaymentOutcome(...)` (ako nije waiting, a i dalje pending, usput zove `updateOrderStatus`), `updateOrderStatus`.
- `Services\Webhooks\WebhookService::process(WebhookData)`: za `paymentlink.paid` ažurira paymentId transakcije pronađene po `paymentLinkId` i radi update; inače ako postoji lokalna transakcija za paymentId → `updateOrderStatus`; za `payment.created` dodatno pokriva otkazane payment linkove (transakcija po merchantReference bez paymentId).
- `Services\PaymentLinks\PaymentLinksService` (`create` čuva `PaymentLink` i `PaymentTransaction` bez paymentId; `get` vraća `null` ako je link istekao) i `PaymentLinkTransactionService::updatePaymentId` (dohvata link od Worldline-a da bi saznao paymentId).
- Proxy interfejsi (`Proxies/`): `HostedCheckoutProxyInterface::createSession`, `HostedTokenizationProxyInterface::create/getToken/deleteToken`, `PaymentLinksProxyInterface::create/getById`, `PaymentMethodProxyInterface::getAvailablePaymentMethods`, `PaymentsProxyInterface::create/getPaymentDetails/tryToGetPayment/getPayment/getRefunds/getCaptures`, `SurchargeProxyInterface::calculateSurcharge`.
- Repozitorijum interfejsi (`Repositories/`): `PaymentMethodConfigRepositoryInterface` (`getEnabled`, `getPaymentMethod`), `ProductTypeRepositoryInterface` (`getProductTypesMap(CartProvider)`).

### 5.5 Order (`BusinessLogic/Order/`)

- `OrdersAPI\Controller\OrderController::getDetails(merchantReference): OrderDetailsResponse` → `OrderService::getDetails`: po merchantReference nalazi poslednju transakciju (mora imati paymentId i status ≠ 0), `GET /payments/{id}/details`, iz operacija sa statusom `CAPTURED`/`PENDING_CAPTURE` gradi `OrderPayment[]` (po jedan po proizvodu), računa dostupne iznose: capturable = total − (captured + captureRequested + cancelled) ako je `isAuthorized`; refundable = captured − refunded − refundRequested ako je `isRefundable`; cancellable kao capturable ako je `isCancellable`. `toArray()` daje `amounts`, `payments[]`, `errors[]`, `capture{possible, amount, pending, available}`, `cancel{...}`, `refund{...}`.
- `CaptureAPI\Controller\CaptureController::handle(CaptureRequest)`, `CancelAPI\Controller\CancelController::handle(CancelRequest)`, `RefundAPI\Controller\RefundController::handle(RefundRequest)`: prosleđuju servisu koji prosleđuje proxy-ju (`POST /payments/{id}/capture|cancel|refund`). Odgovori `CreateCaptureResponse`/`CreateCancelResponse` (`id`, `status`, `statusCode`), `CreateRefundResponse` (+ `amount`).
- Proxy interfejsi: `Proxies/CaptureProxyInterface`, `CancelProxyInterface`, `RefundProxyInterface`.

Napomena: ovi pozivi **ne** ažuriraju narudžbinu u shopu. Promena statusa u shopu dolazi naknadno kroz webhook
(`WebhookService` → `StatusUpdateService`) ili kroz periodičnu proveru (6.5).

---

## 6. Bootstrap sloj

### 6.1 `Bootstrap/BootstrapComponent.php` i kompletna tabela registracija

Ulazna tačka: `BootstrapComponent::bootstrap(callable $activeBrandResolver, string $brandConfigFile, string $webhookMode = WebhookMode::MANUAL)`.
Redosled:

1. `WebhookMode::set($webhookMode)`.
2. `static::init()` → `parent::init()` (Infrastructure: `initServices`, `initRepositories`, `initEvents`, pri čemu Bootstrap pregazi sve tri) → `initControllers()` → `initProxies()` → `initRequestProcessors()` (prazno, kuka za plugin) → `initBackgroundProcesses()`.
3. Registracija `ActiveBrandProviderInterface` → `new ActiveBrandProvider($activeBrandResolver, $brandConfigFile)`.

Plugin nasleđuje `BootstrapComponent`, pregazi `initServices()`/`initRepositories()` (uz `parent::` poziv) i zove `static::bootstrap(...)`
(plugin0: `Plugin0\Bootstrap::boot()`, sa resolverom koji vraća `'worldline'` i putanjom `config/brand.json`,
podrazumevani MANUAL webhook režim). plugin0 pregazi i `initProxies()`: posle `parent::initProxies()` registruje svoje
`HostedCheckoutProxyInterface` i `PaymentsProxyInterface` (zamke 32 i 33); u `initServices()` bi ih Core pregazio,
jer se `initProxies()` izvršava posle. `boot()` radi jednom po zahtevu: statički `$booted` važi do kraja zahteva, jer
PHP posle svakog zahteva zaboravlja statičke promenljive i kad isti PHP-FPM radni proces prima sledeći zahtev
(ranije je ovde pisalo "jednom po procesu"; za CLI skriptu je to isto, za PHP-FPM nije).

Tabela svega što Bootstrap registruje (uz Infrastructure iz 4.3). Ključ = FQCN interfejsa/klase iz prve kolone; "SI" = `SingleInstance`.

Servisi (`initServices`):

| Ključ | Implementacija | SI |
|---|---|---|
| `Domain\Time\TimeProviderInterface` | `Bootstrap\Time\TimeProvider(Infrastructure TimeProvider)` | da |
| `PaymentProcessor\Services\PaymentMethod\PaymentMethodService` | sa `PaymentMethodConfigRepositoryInterface`, `ProductTypeRepositoryInterface` (PaymentProcessor), `PaymentMethodProxyInterface`, `SurchargeProxyInterface` | da |
| `...HostedTokenization\HostedTokenizationService` | 10 zavisnosti (proxy-ji, repozitorijumi, `ThreeDSSettingsService`, `WaitPaymentOutcomeProcess`, `LogoUrlService`, brand, `PaymentMethodService`) | da |
| `...HostedCheckout\HostedCheckoutService` | 8 zavisnosti | da |
| `PaymentProcessor\BackgroundProcesses\WaitPaymentOutcomeProcess` | 6 zavisnosti | da |
| `...Payment\StatusUpdateService` | 8 zavisnosti, uključujući `ShopOrderService` i `StatusMappingService` | da |
| `Domain\Connection\ActiveConnectionProvider` | sa `ConnectionConfigRepositoryInterface` | da |
| `Bootstrap\Sdk\MerchantClientFactory` | sa `ActiveConnectionProvider`, `ActiveBrandProviderInterface`, `MetadataProviderInterface` | da |
| `AdminConfig\Services\Connection\ConnectionService` | sa `ConnectionConfigRepositoryInterface`, `ConnectionProxyInterface` | **ne** |
| `AdminConfig\Services\Payment\PaymentService` | sa `PaymentConfigRepositoryInterface`, `LogoUrlService`, brand, `PaymentProductService`, `GeneralSettingsService`, `LanguageService` | **ne** |
| `Domain\Stores\StoreService` | sa integracionim `StoreService` i `ConnectionConfigRepositoryInterface` | **ne** |
| `AdminConfig\Services\GeneralSettings\GeneralSettingsService` | repozitorijumi podešavanja + integracioni `StoreService`; `WebhookSettingsRepositoryInterface` samo u AUTOMATIC režimu | **ne** |
| `AdminConfig\Services\Disconnect\DisconnectService` | `ShopPaymentService`, repozitorijumi, `DisconnectTaskEnqueuerInterface` | **ne** |
| `AdminConfig\Services\ProductTypes\ProductTypeService` | `ProductTypeRepositoryInterface` (AdminConfig) | da |
| `PaymentProcessor\Services\Webhooks\WebhookService` | `StatusUpdateService`, `PaymentTransactionRepositoryInterface`, `PaymentLinkTransactionService` | da |
| `Domain\OrderStatusMapping\StatusMappingService` | `GeneralSettingsService` | da |
| `AdminConfig\Services\Monitoring\MonitoringLogsService` | `MonitoringLogRepositoryInterface`, `DisconnectRepositoryInterface` | da |
| `AdminConfig\Services\Monitoring\WebhookLogsService` | 5 zavisnosti | da |
| `...PaymentLinks\PaymentLinksService` | 8 zavisnosti | da |
| `Domain\Disconnect\DisconnectTaskEnqueuerInterface` | `Bootstrap\Disconnect\DisconnectTaskEnqueuer(DisconnectRepositoryInterface, QueueService)` | da |
| `Domain\Webhook\Transformers\WebhookTransformerInterface` | `Bootstrap\Sdk\WebhookTransformer(ActiveConnectionProvider)` | **ne** |
| `Order\Services\Order\OrderService` | `PaymentTransactionRepositoryInterface`, `PaymentsProxyInterface` | da |
| `Order\Services\Capture\CaptureService`, `Cancel\CancelService`, `Refund\RefundService` | odgovarajući proxy | da |
| `Domain\LogCleanup\LogCleanupTaskServiceInterface` | `Bootstrap\LogCleanup\LogCleanupTaskService` | da |
| `Domain\Multistore\TenantRegistryInterface` | `Bootstrap\Multistore\DefaultTenantRegistry` | da |
| `Domain\PaymentMethod\PaymentProductService` | `new PaymentProductService()` | da |
| `Domain\PaymentMethod\ThreeDSSettingsService` | `PaymentConfigRepositoryInterface` | da |
| `...PaymentLinks\PaymentLinkTransactionService` | `PaymentLinksProxyInterface`, `PaymentLinkRepositoryInterface`, `PaymentTransactionRepositoryInterface` | da |

Repozitorijumi (`initRepositories`), svi SI; svaki uzima Core ORM repozitorijum preko `RepositoryRegistry::getRepository(<Entity>::class)`:

| Ključ (interfejs) | Implementacija (`Bootstrap\DataAccess\...`) | Entitet | Dodatne zavisnosti |
|---|---|---|---|
| `Domain\Payment\Repositories\PaymentTransactionRepositoryInterface` | `PaymentTransaction\PaymentTransactionRepository` | `PaymentTransactionEntity` + `PaymentTransactionLockEntity` | `StoreContext`, `TimeProviderInterface` |
| `PaymentTransaction\PendingTransactionsRepository` (klasa) | ista | `PaymentTransactionEntity` | `TimeProviderInterface` |
| `PaymentTransaction\AuthorizedTransactionsRepository` (klasa) | ista | `PaymentTransactionEntity` | `TimeProviderInterface` |
| `Domain\HostedTokenization\Repositories\TokensRepositoryInterface` | `Tokens\TokensRepository` | `TokenEntity` (repozitorijum mora biti `ConditionallyDeletes`) | `StoreContext` |
| `PaymentProcessor\Repositories\PaymentMethodConfigRepositoryInterface` i `Domain\PaymentMethod\Repositories\PaymentConfigRepositoryInterface` | `PaymentMethod\PaymentMethodConfigRepository` (dve registracije, dve instance) | `PaymentMethodConfigEntity` | `StoreContext`, `ActiveConnectionProvider` |
| `Domain\Connection\Repositories\ConnectionConfigRepositoryInterface` | `Connection\ConnectionConfigRepository` | `ConnectionConfigEntity` | `StoreContext`, `Encryptor` |
| `AdminConfig...PaymentSettingsRepositoryInterface` (implementira i domenski) | `GeneralSettings\PaymentSettingsRepository` | `PaymentSettingsConfigEntity` | `StoreContext`, `ActiveConnectionProvider` |
| `AdminConfig...LogSettingsRepositoryInterface` | `GeneralSettings\LogSettingsRepository` | `LogSettingsEntity` | isto |
| `AdminConfig...DisconnectRepositoryInterface` | `Disconnect\DisconnectRepository` | `DisconnectTime` | `StoreContext` |
| `AdminConfig...PayByLinkSettingsRepositoryInterface` | `GeneralSettings\PayByLinkSettingsRepository` | `PayByLinkSettingsEntity` | `StoreContext`, `ActiveConnectionProvider` |
| `AdminConfig...WebhookSettingsRepositoryInterface` | `GeneralSettings\WebhookSettingsRepository` | `WebhookSettingsEntity` | `StoreContext` |
| `Domain\PaymentLinks\Repositories\PaymentLinkRepositoryInterface` | `PaymentLink\PaymentLinkRepository` | `PaymentLinkEntity` | `StoreContext` |
| `AdminConfig...ProductTypeRepositoryInterface` i `PaymentProcessor...ProductTypeRepositoryInterface` | `ProductTypes\ProductTypeRepository` (jedna registracija pod AdminConfig ključem; isti objekat implementira oba) | `ProductTypeEntity` (`ConditionallyDeletes`) | – |
| `Maintenance\TaskCleanupRepository` (klasa) | ista | `QueueItem` (mora biti `QueueItemRepository`) | – |
| `Domain\Monitoring\Repositories\MonitoringLogRepositoryInterface` | `Monitoring\MonitoringLogRepository` | `MonitoringLog` (repozitorijum mora biti `RepositoryWithAdvancedSearchInterface`) | `StoreContext`, `ActiveConnectionProvider`, brand, `LogSettingsRepositoryInterface` |
| `Domain\Monitoring\Repositories\WebhookLogRepositoryInterface` | `Monitoring\WebhookLogRepository` | `WebhookLog` (isto) | `StoreContext`, `ActiveConnectionProvider`, `LogSettingsRepositoryInterface` |

Napomena: `ProductTypeRepositoryInterface` iz `PaymentProcessor\Repositories` nije posebno registrovan; `PaymentMethodService` i
`HostedCheckoutService` ga traže pod tim ključem. Pošto je `use ... AdminConfig\Services\ProductTypes\Repositories\ProductTypeRepositoryInterface`
u `BootstrapComponent`-u, a `PaymentMethodService` se konstruiše sa `ServiceRegister::getService(ProductTypeRepositoryInterface::class)` u istom
fajlu, ključ je AdminConfig interfejs i registracija pokriva oba korišćenja u Bootstrap-u.

Kontroleri (`initControllers`, svi SI): `PaymentMethodsController`, `HostedTokenizationController`, `HostedCheckoutController`,
`PaymentController` (CheckoutAPI), `ConnectionController`, `VersionController`, `StoreController`, `IntegrationController`,
`PaymentController` (AdminConfig), `LanguageController`, `GeneralSettingsController`, `ProductTypesController`, `WebhooksController`,
`LogsController`, `PaymentLinksController`, `OrderController`, `CaptureController`, `CancelController`, `RefundController`.

Proxy-ji (`initProxies`, svi SI, svi dobijaju `MerchantClientFactory`): `PaymentMethodProxyInterface` → `PaymentMethodProxy`,
`PaymentsProxyInterface` → `PaymentsProxy`, `HostedCheckoutProxyInterface` → `HostedCheckoutProxy`, `HostedTokenizationProxyInterface` →
`HostedTokenizationProxy`, `ConnectionProxyInterface` → `ConnectionProxy`, `PaymentLinksProxyInterface` → `PaymentLinksProxy`,
`SurchargeProxyInterface` → `SurchargeProxy`, `CaptureProxyInterface` → `CaptureProxy`, `CancelProxyInterface` → `CancelProxy`,
`RefundProxyInterface` → `RefundProxy`.

Pozadinski procesi (`initBackgroundProcesses`, SI): `WaitPaymentOutcomeProcessStarterInterface` → `WaitPaymentOutcomeProcessStarter(AsyncProcessService, StoreContext, TenantContext)`,
`TransactionStatusCheckListener`, `AutoCaptureCheckListener` (oba sa `QueueService`, `TimeProviderInterface`, `TenantRegistryInterface`).

Eventi (`initEvents`): na `EventBus` za `TickEvent` zakačena četiri listenera: `TransactionStatusCheckListener::handle`,
`AutoCaptureCheckListener::handle`, `(new TaskCleanupListener())->handle()`, `(new LogCleanupListener(LogCleanupTaskServiceInterface))->handle()`.

### 6.2 Aspekti i API fasade

**Aspekti** (`Bootstrap/Aspect/`, `Bootstrap/ApiFacades/Aspects/`):

- `Aspect` interfejs: `applyOn($callee, array $params = [])`.
- `Aspects::run(Aspect)->andRun(Aspect)->...->beforeEachMethodOfService(FQCN)` ili `->beforeEachMethodOfInstance($obj)`: vraća `Aspects` objekat čiji `__call($method, $args)` primenjuje aspekte oko poziva metode na servisu iz `ServiceRegister`-a (ili na datoj instanci). `CompositeAspect` ih ulančava tako da je **prvi dodat spolja**: za `run(ErrorHandling)->andRun(Tenant)->andRun(Store)` redosled izvršavanja je `ErrorHandling { Tenant { Store { metoda } } }`.
- `ErrorHandlingAspect`: hvata `BaseTranslatableException` (loguje ERROR, vraća `TranslatableErrorResponse::fromError`) i svaki drugi `Throwable` (loguje `'Unhandled error occurred.'`, umotava u `BaseTranslatableUnhandledException` sa kodom `general.unhandled`). **Nikad ne baca dalje**; pozivalac mora da proveri `isSuccessful()`.
- `TenantContextAspect($tenantId)` → `TenantContext::doWithTenant`, `StoreContextAspect($storeId)` → `StoreContext::doWithStore`.
- `Response/ErrorResponse` (`successful = false`, `statusCode` = kod izuzetka ili 400; `__call` vraća `$this` da ulančani pozivi ne pucaju) i `Response/TranslatableErrorResponse` (`toArray()` → `statusCode`, `errorCode`, `errorMessage`, `errorParameters`).

**Fasade** (sve imaju privatni konstruktor, statički `get()`, `forTenant(string)` i metode koje primaju `$storeId`):

| Fasada | `get()` postavlja `StoreContext` origin | Metode → kontroler |
|---|---|---|
| `AdminConfig\AdminAPI\AdminAPI` | `'config'` | `connection($storeId)`, `version()` (bez store), `integration($storeId)`, `store($storeId)`, `payment($storeId)`, `language($storeId)`, `generalSettings($storeId)`, `productTypes()` (bez store), `monitoringLogs($storeId)`, `paymentLinks($storeId)` |
| `PaymentProcessor\CheckoutAPI\CheckoutAPI` | ne postavlja (kontroleri postavljaju: `checkoutLoad`, `checkoutHtp`, `checkoutHtpStored`/`checkoutHtpNew`, `checkoutPre`/`checkoutHcp`, `storedCards`, `landing`) | `paymentMethods($storeId)`, `hostedTokenization($storeId)`, `payment($storeId)`, `hostedCheckout($storeId)` |
| `Order\OrderAPI\OrderAPI` | `'order'` | `orders($storeId)`, `capture($storeId)`, `cancel($storeId)`, `refund($storeId)` |
| `PaymentProcessor\WebhookAPI\WebhookAPI` | `'hooks'` | `webhooks($storeId)` |

Origin ide u `platforminfo` header ka Worldline-u i briše se posle svakog SDK zahteva. `setOrigin` ne menja već postavljen origin.

Primer: `CheckoutAPI::get()->forTenant('')->hostedCheckout('1')->createSession($request)`.

### 6.3 Sdk: `MerchantClientFactory` i komunikacija sa Worldline-om (`Bootstrap/Sdk/`)

`MerchantClientFactory::get(?ConnectionDetails $connection = null): MerchantClientInterface`:

1. Ako konekcija nije data, uzima `ActiveConnectionProvider::get()`; ako je `null`, baca `InvalidConnectionDetailsException` (`connection.invalidActiveCredentials`).
2. Pravi `DbLogConnection(new CommunicatorLoggerHelper())` (SDK `DefaultConnection` sa Core helperom) i `enableLogging(new ApiLogger())`.
3. `CommunicatorConfiguration(apiKey, apiSecret, endpoint, 'Logeecom')`; endpoint = `BrandConfig::getLiveApiEndpoint()` za live, `getTestApiEndpoint()` za test.
4. `V1HmacAuthenticator(config)`: SDK potpisuje svaki zahtev header-om `Authorization: GCS v1HMAC:<apiKey>:<base64(HMAC-SHA256(apiSecret, metod + Content-Type + Date + X-GCS headeri + putanja))>` (šta tačno ulazi u potpis radi SDK `prepareDataForSignature`, ovde nije prepisano).
5. `MetricsProvidingCommunicator(config, authenticator, connection, brand, metadataProvider, StoreContext)`: SDK `Communicator` koji dodaje header `platforminfo` = base64 JSON sa SDK verzijom, `SERVER_SOFTWARE`, imenom i verzijom platforme, verzijom plugina, imenom brenda, originom i ID-jem/URL-om prodavnice (u izvoru postoji ključ `'sdk"'` sa viškom navodnika, tako je u kodu). SDK sam dodaje `Date`, `X-GCS-ServerMetaInfo` (iz SDK `MetadataProvider`, `PHPServerSDK/v6.2.0`) i `Authorization`.
6. `new Client($communicator)`, pa `->merchant($pspId)` → `MerchantClient` sa resursima: `hostedCheckout()`, `hostedTokenization()`, `payments()`, `captures()`, `refunds()`, `complete()`, `productGroups()`, `products()`, `services()`, `webhooks()`, `sessions()`, `tokens()`, `payouts()`, `mandates()`, `privacyPolicy()`, `paymentLinks()`.

Koje SDK pozive Core koristi (preko proxy-ja u `Bootstrap/ApiFacades/*/Proxies/`):

| Core proxy | SDK poziv |
|---|---|
| `ConnectionProxy::isConnectionValid` | `services()->testConnection()->getResult() === 'OK'` |
| `PaymentMethodProxy::getAvailablePaymentMethods` | `products()->getPaymentProducts(countryCode, amount, currencyCode)` → `GetPaymentProductsResponseTransformer` (samo podržani ID-jevi) |
| `SurchargeProxy::calculateSurcharge` | `services()->surchargeCalculation(amount, cardSource.token)` |
| `HostedCheckoutProxy::createSession` | `hostedCheckout()->createHostedCheckout(CreateHostedCheckoutRequest)` → `PaymentResponse(PaymentTransaction(merchantReference, hostedCheckoutId kao PaymentId, RETURNMAC), redirectUrl)` |
| `HostedTokenizationProxy::create/getToken/deleteToken` | `hostedTokenization()->createHostedTokenization(...)`, `tokens()->getToken(id)` (vraća `Token` samo za trajne kartične tokene), `tokens()->deleteToken(id)` |
| `PaymentsProxy::create` | `payments()->createPayment(CreatePaymentRequest)` → `PaymentResponse` (paymentId, RETURNMAC i redirectURL iz `merchantAction.redirectData` ako treba 3DS) |
| `PaymentsProxy::getPaymentDetails/getPayment/getRefunds/getCaptures` | `payments()->getPaymentDetails(id)`, `payments()->getPayment(id)`, `refunds()->getRefunds(id)`, `captures()->getCaptures(id)` |
| `PaymentLinksProxy::create/getById` | `paymentLinks()->createPaymentLink(...)`, `paymentLinks()->getPaymentLinkById(id)` |
| `CaptureProxy`/`CancelProxy`/`RefundProxy` | `payments()->capturePayment(id, CapturePaymentRequest)`, `cancelPayment(id, CancelPaymentRequest)`, `refundPayment(id, RefundRequest)` |

Transformeri (`Proxies/Transformers/`) pretvaraju domenske objekte u SDK Domain objekte i nazad. Najvažniji:

- `OrderTransformer`: `Cart` → SDK `Order` (amountOfMoney, references.merchantReference + descriptor, discount, shipping sa adresom i troškom, customer sa contactDetails/locale/merchantCustomerId/personalInformation/device/billingAddress, shoppingCart stavke ako je `sendShoppingCart`).
- `CardPaymentMethodSpecificInputTransformer`: token, `threeDSecure` (returnUrl, `skipAuthentication = !enable3ds`, Maestro uvek 3DS, exemption i challengeIndicator po `ThreeDSSettings` i iznosu u EUR, `paymentProduct130SpecificInput` za Carte Bancaire), `authorizationMode` (globalni `PaymentSettings::paymentAction`, pa eksplicitni `paymentAction`, pa per-metoda; kreditne kartice sa `FINAL_AUTHORIZATION` koriste `AuthorizationMode` iz konfiguracije; Mealvouchers, Chèque-Vacances, Intersolve uvek `SALE`), `paymentProductId` za pojedinačne brendove i Intersolve.
- `CreateHostedCheckoutRequestTransformer`: `hostedCheckoutSpecificInput` (returnUrl, locale, allowedNumberOfPaymentAttempts, showResultPage, variant/template, `paymentProductFilters.restrictTo` na jedan proizvod ili grupu `cards`, grupisanje kartica, Intersolve sessionTimeout), `order` (+ `surchargeSpecificInput mode on-behalf-of` ako je `applySurcharge`), card/mobile/redirect/SEPA specifični inputi (SEPA mandat `UNIQUE`, jezik iz lokala, Bank Transfer instant, Oney paymentOption, Mealvouchers/CVCo `completeRemainingPaymentAmount`), `requiresApproval` po payment action za metode sa odvojenim capture-om.
- `CreatePaymentRequestTransformer` (Hosted Tokenization: `hostedTokenizationId` ili token, order, card input), `CreatePaymentLinkRequestTransformer` (expirationDate = dati datum ili now + N dana, uvek 23:59:59 UTC), `FeedbacksTransformer` (samo AUTOMATIC režim: `feedbacks.webhooksUrls` = `Configuration::getWebhookUrl()` + dodatni URL-ovi), `GooglePaySpecificRequestTransformer`, `PaymentDetailsResponseTransformer` (računa `PaymentAmounts` iz operacija po grupama status kodova; `isFullyPaid` = amount + surcharge == acquiredAmount; naziv metode iz `PaymentMethodDefaultConfigs`, za wallet dodaje brend mreže), `PaymentResponseTransformer`, `PaymentCaptureResponseTransformer`, `PaymentRefundResponseTransformer`, `TokenResponseTransformer`, `GetPaymentProductsParamsTransformer`, `GetPaymentProductsResponseTransformer`, `CalculateSurcharge*Transformer`, `Create{Capture,Cancel,Refund}{Request,Response}Transformer`.
- Izuzetak `Proxies/Exceptions/InvalidApiResponseException` (`paymentProcessor.proxy.InvalidApiResponse`) kad odgovor nema obavezna polja.

Logovanje API komunikacije:

- `ApiLogger implements OnlinePayments\Sdk\Logging\CommunicatorLogger`: `log()` → `Logger::logInfo`, `logException()` → `Logger::logError`. SDK sam obfuskira osetljiva polja (`HttpObfuscator`).
- `CommunicatorLoggerHelper extends SDK CommunicatorLoggerHelper`: `logRequest`/`logResponse` **samo ako je `LogSettings::debugMode` uključen** upisuju `MonitoringLog` (nivo `DEBUG`) kroz `MonitoringLogRepositoryInterface`; `logException` upisuje `MonitoringLog` nivoa `ERROR` **uvek**. `transactionLink` = `BrandConfig` merchant portal URL + `/transactions/online/` + paymentNumber.
- `DbLogConnection extends SDK DefaultConnection`: samo ubrizgava gornji helper.
- `WebhookTransformer implements WebhookTransformerInterface`: `transform(body, headers)`: (1) uzima `webhookKey`/`webhookSecret` aktivne konekcije, pravi `InMemorySecretKeyStore` i `WebhooksHelper::unmarshal` (SDK proverava header `X-GCS-KeyId` i `X-GCS-Signature` = base64 HMAC-SHA256 tela sa secret-om; greška → `SignatureValidationException`, nepodudaranje API verzije → `ApiVersionMismatchException`); (2) proverava da `merchantId` iz tela odgovara `pspId` aktivne konekcije (`WebhookMerchantMismatchException`); (3) gradi `WebhookData`: za `paymentlink.paid` iz `paymentLink` objekta, inače iz `payment` ili `refund` objekta (id, merchantReference, type iz `eventType` ili `type`, created, statusCategory, statusCode); bez payment/refund objekta vraća neutralan `WebhookData` sa praznim id-jem.

### 6.4 DataAccess: entiteti i repozitorijumi (`Bootstrap/DataAccess/`)

Svaki entitet nasleđuje Core `Entity`, ima `CLASS_NAME`, `getConfig()` sa tipom i indeksima, ručne `inflate`/`toArray`.
Plugin mora da registruje repozitorijum za **svaki** od ovih entiteta plus `ConfigEntity`, `Process`, `LogData`, `QueueItem`.

| Entitet (klasa) | `type` u bazi | Indeksi redom (index_1, index_2, ...) | Čuva |
|---|---|---|---|
| `Infrastructure\Configuration\ConfigEntity` | `Configuration` | name(s), context(s) | konfiguracione vrednosti (4.4) |
| `Infrastructure\TaskExecution\Process` | `Process` | guid(s) | serijalizovani `Runnable` za async poziv |
| `Infrastructure\TaskExecution\QueueItem` | `QueueItem` | status(s), taskType(s), queueName(s), context(s), queueTime(dt), lastExecutionProgress(i), lastUpdateTimestamp(i), priority(i), parentId(i) | red zadataka; **repozitorijum mora implementirati `QueueItemRepository`** |
| `Infrastructure\Logger\LogData` | `LogData` | integration(s), logLevel(i), timestamp(i), component(s) | logovi auto-testa |
| `Connection\ConnectionConfigEntity` | `ConnectionConfig` | storeId(s) | `ConnectionDetails` (tajne šifrovane) |
| `Disconnect\DisconnectTime` | `DisconnectTime` | storeId(s) | vreme poslednjeg disconnect-a (logovi stariji od toga se ne prikazuju) |
| `GeneralSettings\LogSettingsEntity` | `LogSettings` | storeId(s), mode(s) | `LogSettings` |
| `GeneralSettings\PayByLinkSettingsEntity` | `PayByLinkSettings` | storeId(s), mode(s) | `PayByLinkSettings` |
| `GeneralSettings\PaymentSettingsConfigEntity` | `PaymentSettingsEntity` | storeId(s), mode(s) | `PaymentSettings` |
| `GeneralSettings\WebhookSettingsEntity` | `WebhookSettings` | storeId(s) | `WebhookSettings` (bez mode) |
| `Monitoring\MonitoringLog` | `MonitoringLog` | storeId(s), mode(s), requestId(s), orderId(s), paymentNumber(s), message(s), createdAt(i), expiresAt(i) | API zahtev/odgovor; repozitorijum mora biti `RepositoryWithAdvancedSearchInterface` |
| `Monitoring\WebhookLog` | `WebhookLog` | storeId(s), mode(s), orderId(s), paymentNumber(s), createdAt(i), expiresAt(i) | primljeni webhook; isto |
| `PaymentLink\PaymentLinkEntity` | `PaymentLinkEntity` | storeId(s), paymentLinkId(s), merchantReference(s) | `PaymentLink` |
| `PaymentMethod\PaymentMethodConfigEntity` | `PaymentMethodConfig` | storeId(s), mode(s), enabled(b), paymentProductId(s) | `PaymentMethod` sa `additionalData` po tipu |
| `PaymentTransaction\PaymentTransactionEntity` | `PaymentTransaction` | storeId(s), transactionId(s), merchantReference(s), returnHmac(s), statusCode(i), createdAtTimestamp(i), captureAtTimestamp(i), updatedAtTimestamp(i), paymentLinkId(s) | `PaymentTransaction` (u JSON-u ključ `cratedAt`, tako je u kodu) |
| `PaymentTransaction\PaymentTransactionLockEntity` | `PaymentTransactionLock` | storeId(s), transactionId(s), merchantReference(s), lockTimestamp(i) | brava za kreiranje narudžbine (30 s) |
| `ProductTypes\ProductTypeEntity` | `ProductTypeEntity` | productId(s) | `ProductType` proizvoda; repozitorijum mora biti `ConditionallyDeletes` |
| `Tokens\TokenEntity` | `TokenEntity` | storeId(s), customerId(s), tokenId(s) | sačuvana kartica; `ConditionallyDeletes` |

Ponašanje repozitorijuma koje treba znati:

- Svi "settings" repozitorijumi (`Payment`, `Log`, `PayByLink`, `PaymentMethodConfig`) filtriraju po `storeId` **i** `mode` aktivne konekcije; ako nema aktivne konekcije, `get*` vraća `null`/prazno, a `save*` tiho ne radi ništa. `WebhookSettingsRepository` filtrira samo po `storeId`.
- `ConnectionConfigRepository`: `saveConnection` šifruje 4 tajne po okruženju; `getConnection` dešifruje; `getOldestConnection`/`getOldestConnectedStore` = prvi red bez filtera; `disconnect` prebacuje na drugo okruženje ako ono ima kredencijale, inače briše red.
- `PaymentTransactionRepository`: `save` upisuje ili ažurira po paymentId (transactionId) ili paymentLinkId, postavlja `updatedAt`/`createdAt`; `get` pokušava i `getOldTransactionId()`; `getByMerchantReference` vraća najnoviju; `lockOrderCreation` pravi `PaymentTransactionLockEntity` ako ne postoji nov (< 30 s), inače vraća `false`.
- `PendingTransactionsRepository::get(limit = 10)`: `statusCode IN PENDING_STATUS_CODES`, `createdAt >= now − 1 dan`, `paymentLinkId = ''`, po `updatedAt DESC`. `AuthorizedTransactionsRepository::get(limit = 10)`: `statusCode = 5`, `captureAt != 0`, `captureAt <= now`.
- `MonitoringLogRepository::saveMonitoringLog`: ako postoji log sa istim `requestId`, odgovor se spaja u isti red (zahtev + odgovor = jedan zapis); `expiresAt` = `createdAt + logRecordsLifetime` (default 14 dana). `getOrderUrl()` i `getOrderIdByMerchantReference()` su kuke koje plugin može pregaziti nasleđivanjem i ponovnom registracijom.
- `TaskCleanupRepository`: `getCompletedCount`, `getFailedCount` (failed + aborted), `deleteCompletedTasks(limit)`, `deleteFailedTasks(beforeDate, limit)`.

### 6.5 Pozadinski procesi (listeneri i taskovi)

Svi rade kroz red zadataka iz 4.9, dakle tek kad plugin obezbedi `QueueItemRepository` i async endpoint. U plugin0 rade od 2026-10-20
(MIDU-1230, docs/BACKGROUND.md): `TransactionStatusCheckListener` i `TaskCleanupListener` su Core-ovi, a `AutoCaptureCheckListener` i
`LogCleanupTaskServiceInterface` su zamenjeni plugin klasama `BackgroundProcessing\AutoCapture\AutoCaptureListener` i
`BackgroundProcessing\LogCleanup\LogCleanupTaskService` (razlozi u zamkama 36 i 37).

| Komponenta | Okidač | Šta radi |
|---|---|---|
| `ApiFacades\PaymentProcessor\BackgroundProcesses\WaitPaymentOutcomeProcessStarter` + `WaitPaymentOutcomeProcessRunner` | `WaitPaymentOutcomeProcess::startInBackground` (Hosted Tokenization bez redirect-a, ili `PaymentController::startWaitingForOutcomeInBackground`) | zaseban async HTTP proces koji sinhrono čeka ishod (`startWaitingForOutcome`) i ažurira narudžbinu; **ne koristi red zadataka**, samo `AsyncProcessService` i `Process` entitet |
| `TransactionStatusCheckListener` → `TransactionStatusCheckTask` (ili `...OrchestratorTask` u multi-tenant) | `TickEvent`, ako poslednji takav task nije enqueue-ovan u poslednjih 15 min | za svaku pending transakciju iz poslednja 24 h zove `CheckoutAPI::get()->payment($storeId)->updateOrderStatus(...)` (origin `fallback`); ovo je rezerva kad webhook ne stigne; red `transaction_status_check` |
| `AutoCaptureCheckListener` → `AutoCaptureCheckTask` | `TickEvent`, 15 min | za svaku autorizovanu transakciju sa dospelim `captureAt` zove `OrderAPI::get()->capture($storeId)->handle(new CaptureRequest(paymentId))` (origin `order.autocapture`); red `auto_capture_check` |
| `Maintenance\TaskCleanupListener` → `TaskCleanupTask` | `TickEvent`, jednom dnevno, prioritet LOW | briše `completed` queue iteme i `failed`/`aborted` starije od 14 dana, po 5000 |
| `Domain\LogCleanup\LogCleanupListener` → `LogCleanup\Tasks\LogCleanupTask` | `TickEvent`, jednom dnevno | briše istekle `MonitoringLog` i `WebhookLog` (po `expiresAt`), po tenantu |
| `Disconnect\DisconnectTaskEnqueuer` → `Disconnect\Tasks\DisconnectTask` | `DisconnectService::deleteAllData` | upisuje `DisconnectTime`, u pozadini briše sve monitoring i webhook logove za mode, pa briše `DisconnectTime`; red `disconnect-integration` |
| `TaskExecution\TenantAwareTask` | bazna klasa | `execute()` je `final` i umotava `doExecute()` u `TenantContext::doWithTenant($tenantId)`; serijalizuje `tenantId` |
| `Multistore\DefaultTenantRegistry` | – | `isMultiTenant() = false`, jedan tenant `''` |
| `Time\TimeProvider` | – | `TimeProviderInterface` nad Infrastructure `TimeProvider` |

### 6.6 Branding (`Branding/Brand/`)

- `BrandConfig(code, name, liveApiEndpoint, testApiEndpoint, liveUrl, testUrl, paymentMethodName)`.
- `ActiveBrandProvider(callable $resolver, string $file)`: `getActiveBrand()` zove resolver (rezultat se ne koristi za izbor fajla u 1.0.7, samo u poruci greške), čita JSON fajl i pravi `BrandConfig`; ako fajl ne postoji baca `InvalidArgumentException`. `getApiUrl()` = `liveUrl` ili `testUrl` po modu aktivne konekcije (merchant portal, ne API), `getTransactionUrl()` = `getApiUrl() . '/transactions/online/'`.
- Plugin0 `config/brand.json`: code `worldline`, name `Worldline`, liveApiEndpoint `https://payment.direct.worldline-solutions.com`, testApiEndpoint `https://payment.preprod.direct.worldline-solutions.com`, liveUrl/testUrl merchant portal, paymentMethodName `Worldline`.

---

## 7. Tokovi korak po korak

### 7.1 Boot (plugin0)

1. PrestaShop učita `plugin0.php`; `require_once vendor/autoload.php`.
2. Hook `actionDispatcherBefore` (ili `install()`, `uninstall()`, hook-ovi `paymentOptions` i `displayAdminOrderMain`, admin kontroleri i front kontroleri `payment` i `return`) zove `Plugin0\Bootstrap::boot()`.
3. `boot()` (jednom po zahtevu, vidi 6.1) zove `BootstrapComponent::bootstrap(resolver 'worldline', config/brand.json)`.
4. Core registruje sve iz 4.3 i 6.1; plugin `initServices()` dodaje `Configuration`, `JsonSerializer`, `LoggerService` (ShopLoggerAdapter), `Encryptor`, `MetadataProvider`, `StoreService`, `LanguageService`, `LogoUrlService`, `ShopPaymentService`, `ShopOrderService`; plugin `initProxies()` posle Core-ovih registracija zamenjuje `HostedCheckoutProxyInterface` (`Plugin0\Infrastructure\HostedCheckoutProxy`, zamka 32) i `PaymentsProxyInterface` (omotač `Plugin0\Infrastructure\PaymentsProxy`, zamka 33); plugin `initRepositories()` registruje `BaseRepository` za 17 klasa entiteta (15 Core-ovih i dva plugin-ova: `Plugin0\Entity\PaymentMethodEntity`, `Plugin0\Entity\PaymentOperationEntity`) i `LogsRepository` za `MonitoringLog` i `WebhookLog`.
5. Ništa se ne instancira dok se ne zatraži (`SingleInstance` je lenj).

### 7.2 Povezivanje (kredencijali)

1. Admin forma → `ConfigurationController::save()` (plugin0) pravi `ConnectionRequest(mode, test..., live...)`.
2. `AdminAPI::get()->connection($storeId)->connect($request)`.
3. `ConnectionController::connect` → `ConnectionRequest::transformToDomainModel()` → `ConnectionDetails` (validira da aktivni mode ima kredencijale).
4. `ConnectionService::connect` → `ConnectionProxy::isConnectionValid` → `MerchantClientFactory::get($details)` → SDK `services()->testConnection()`.
5. Ako nije `'OK'`: `InvalidConnectionDetailsException('connection.apiValidationFailed')` → aspekt vraća `TranslatableErrorResponse`.
6. Ako jeste: `ConnectionConfigRepository::saveConnection` šifruje tajne i upisuje `ConnectionConfigEntity` (`storeId` iz `StoreContext`).
7. Plugin dobija `ConnectionResponse` (`isSuccessful() === true`).

### 7.3 Checkout: lista metoda plaćanja

1. Plugin implementira `CartProvider` koji iz PrestaShop korpe pravi `Cart` (iznosi kao `Amount::fromFloat`, kupac, adresa, stavke).
2. `CheckoutAPI::get()->paymentMethods($storeId)->getAvailablePaymentMethods($cartProvider)`.
3. `PaymentMethodService` čita uključene metode iz `PaymentMethodConfigRepository` (za aktivni mode), zove Worldline `GET /products?countryCode&amount&currencyCode`, pravi presek i primenjuje pravila za kartice/hosted checkout/mealvouchers.
4. `HostedTokenizationService::getValidTokens` za registrovane kupce sa sačuvanim karticama pravi hosted tokenization sesiju i vraća važeće tokene.
5. Plugin iz `PaymentMethodsResponse::getPaymentMethods()` gradi payment opcije; nazive uzima iz `PaymentMethod::getName()->getTranslationMessage($locale)`.

### 7.4 Plaćanje preko Hosted Checkout Page (redirect)

1. Kupac bira metodu; plugin pravi `HostedCheckoutSessionRequest($cartProvider, $returnUrl, PaymentProductId::parse($id) ili null za generičku stranu, $tokenId)`.
2. `CheckoutAPI::get()->hostedCheckout($storeId)->createSession($request)`.
3. `HostedCheckoutService::createSession` → `HostedCheckoutProxy::createSession` → `POST /hostedcheckouts` → odgovor `hostedCheckoutId`, `RETURNMAC`, `redirectUrl`.
4. Core čuva `PaymentTransaction(merchantReference, paymentId = hostedCheckoutId, returnHmac, status 0, customerId, paymentMethod)`.
5. Plugin preusmerava kupca na `$response->getRedirectUrl()`.
6. Kupac se vraća na `returnUrl`; Worldline dodaje query parametre sa ID-jem hosted checkout sesije i RETURNMAC-om (nazivi parametara su stvar Worldline API-ja i nisu u Core kodu; u plugin0 su to `hostedCheckoutId` i `RETURNMAC`, videno u Apache access logu, npr. `GET /en/module/plugin0/return?RETURNMAC=...&hostedCheckoutId=10300628270` 2026-10-16). Plugin zove `CheckoutAPI::get()->payment($storeId)->startWaitingForOutcome(PaymentId::parse($hostedCheckoutId), $returnMac)` (sinhrono, čeka do 10 s posle povratka) ili `getPaymentOutcome(...)` u petlji sa AJAX-om.
7. `StatusUpdateService::updateOrderStatus`: `GET /payments/{id}/details`, mapiranje statusa, `ShopOrderService::createShopOrder(...)` (plugin pravi PrestaShop narudžbinu, npr. `validateOrder`), čuvanje tokena.
8. Plugin čita `getPaymentOutcome()->getPaymentTransaction()->getStatusCode()` i prikazuje potvrdu ili grešku.

### 7.5 Plaćanje preko Hosted Tokenization Page (iframe kartice)

1. Plugin zove `CheckoutAPI::get()->hostedTokenization($storeId)->crate($cartProvider, PaymentProductId::cards())` i ugrađuje `getHostedTokenization()->getUrl()` u iframe (Worldline JS SDK na frontu; nije deo Core-a).
2. Front JS vraća `hostedTokenizationId`; plugin zove `->pay(new PaymentRequest($hostedTokenizationId, $cartProvider, $returnUrl, $tokenId))`.
3. `HostedTokenizationService::pay` → `POST /payments`. Ako odgovor ima `merchantAction.redirectData` (3DS), `PayResponse::isRedirectRequired()` je `true` i plugin preusmerava; inače Core odmah pokreće `WaitPaymentOutcomeProcess::startInBackground` (async HTTP proces) koji čeka ishod i kreira narudžbinu.
4. Posle povratka (ili odmah ako nema redirect-a) plugin kao u 7.4 koracima 6 do 8.

### 7.6 Webhook

1. Worldline šalje POST na webhook URL plugina (ručno registrovan u Worldline back office-u u MANUAL režimu).
2. Plugin kontroler zove `WebhookAPI::get()->webhooks($storeId)->process($rawBody, $headers)`.
3. `WebhookTransformer` validira potpis (`X-GCS-KeyId`, `X-GCS-Signature`) i `merchantId`.
4. `WebhookLogsService::logWebhook` upisuje `WebhookLog`.
5. `WebhookService::process` → `StatusUpdateService::updateOrderStatus` ako lokalno postoji transakcija → `ShopOrderService::updateStatus/cancelShopOrder/refundShopOrder/createShopOrder`.
6. Plugin vraća HTTP status po `errorCode`-u odgovora (plugin0 `Webhook\ResponsePolicy`, docs/WEBHOOK.md 3.4): 200 za uspeh, odbijen potpis ili trgovca (`plugin0.webhook.invalidSignature`, `webhook.merchantIdMismatch`), događaj bez objekta plaćanja (`payment.invalidPaymentIdFormat`) i nečitljivo telo (`plugin0.webhook.unprocessable`); 503 samo kad je potpis prošao, a obrada pala, jer Worldline tada ponavlja isporuku (5 puta: 10 min, 1 h, 2 h, 8 h, 24 h; zaglavlje `retry-count`).
7. plugin0 oko Core-a registruje tri klase (`Bootstrap::initServices`, posle `parent::initServices()`): `Infrastructure\WebhookTransformer` (SDK greške potpisa u `BaseTranslatableException` sa plugin kodom), `Webhook\WebhookService` (preskače već obrađen ID događaja iz evidencije `Entity\WebhookEventEntity`, beleži tek posle uspeha) i `Infrastructure\StatusUpdateService` (`updateOrderStatus` pod MySQL `GET_LOCK` po ID-ju transakcije, da povratak kupca i webhook ne primene zastareli snimak jedan preko drugog). Front controller `webhook` zove `Controller::init()` umesto `FrontController::init()`: Symfony container i locale moraju postojati zbog `Tools::getContextLocale` u `validateOrder`, a kolačić, korpa, geolokacija i stranica održavanja ne smeju da vrate HTML umesto statusa.

### 7.7 Capture, cancel, refund iz admina

1. `OrderAPI::get()->orders($storeId)->getDetails($merchantReference)` daje iznose i da li je akcija moguća.
2. `OrderAPI::get()->capture($storeId)->handle(new CaptureRequest($paymentId, $amount))`, `->cancel(...)->handle(new CancelRequest($paymentId, $amount))`, `->refund(...)->handle(new RefundRequest($paymentId, $amount))`.
3. Status narudžbine u shopu se menja kad stigne webhook ili kad `TransactionStatusCheckTask` (samo za pending) ili plugin eksplicitno pozove `CheckoutAPI::get()->payment($storeId)->updateOrderStatus($paymentId)`.

### 7.8 Disconnect

`AdminAPI::get()->generalSettings($storeId)->disconnect()` → `DisconnectService::disconnect` (vidi 5.3). Zahteva registrovan
`ShopPaymentService` (plugin0 ga registruje od commit-a `90a8caf`) i radni red zadataka (plugin0 ga nema). Plugin0 zato
umesto toga zove `ConnectionConfigRepositoryInterface::disconnect()` direktno unutar `StoreContext::doWithStore`
(komentar u `ConfigurationController::disconnect()` objašnjava zašto; deo komentara koji kaže da `ShopPaymentService`
nije registrovan je zastareo, preostali razlog je red zadataka). Posledica: plugin-ov disconnect briše samo
kredencijale; Core podešavanja, transakcije i metode plaćanja ostaju u bazi.

### 7.9 Red zadataka

Vidi sekvencu u 4.9. Minimalno što plugin dodaje: repozitorijum koji implementira `QueueItemRepository` registrovan za `QueueItem::class`,
front kontroler na URL-u koji vraća `Configuration::getAsyncProcessUrl($guid)` i koji zove `AsyncProcessService::runProcess($guid)`,
i po potrebi cron/wakeup poziv `TaskRunnerWakeup::wakeup()` (npr. iz hook-a ili cron-a) da runner krene i bez `enqueue`.

Plugin0 od 2026-10-20 (MIDU-1230): `Infrastructure\Repository\QueueItemRepository`, `controllers/front/asyncprocess.php` i
`bin/task-runner-wakeup.php` koji cron zove svakog minuta (u kontejneru `logeecom_dev`: `/etc/cron.d/plugin0-task-runner`).
Ceo opis, provere i otvorena pitanja: docs/BACKGROUND.md.

---

## 8. Šta plugin mora da obezbedi i stanje plugin0

| Obaveza | Core ključ / mehanizam | plugin0 (grana `feature/MIDU-1224`, commit `06a7b91`; usklađeno 2026-10-16) |
|---|---|---|
| Bootstrap podklasa i poziv `bootstrap()` na svakom ulazu | `Bootstrap\BootstrapComponent` | **ima**: `Plugin0\Bootstrap::boot()` iz `actionDispatcherBefore`, `install`, `uninstall`, hook-ova `paymentOptions` i `displayAdminOrderMain`, admin kontrolera i front kontrolera `payment` i `return` |
| `Configuration` podklasa | `Configuration::CLASS_NAME` | **ima**: `Plugin0\Configuration` (`getAsyncProcessUrl` → module link `asyncprocess`) |
| `ShopLoggerAdapter` | `ShopLoggerAdapter::CLASS_NAME` | **ima**: `LoggerService` → `ps_log` |
| `Serializer` | `Serializer::CLASS_NAME` | **ima**: `JsonSerializer` |
| `Encryptor` | `Domain\Integration\Encryption\Encryptor` | **ima**: `PhpEncryption(_NEW_COOKIE_KEY_)` |
| `MetadataProviderInterface` | FQCN | **ima** |
| Integracioni `StoreService` | `Domain\Integration\Stores\StoreService` | **ima** (mapiranje: captured `PS_OS_PAYMENT`, error `PS_OS_ERROR`, pending i authorized `PS_OS_BANKWIRE`, cancelled `PS_OS_CANCELED`, refunded `PS_OS_REFUND`, partially refunded `PS_OS_PAYMENT`; do 2026-10-16 partially refunded je bio `''`, vidi zamku 33) |
| Repozitorijumi za entitete | `RepositoryRegistry::registerRepository` | **ima** za `ConfigEntity`, `Process`, `LogData`, `ConnectionConfigEntity`, `DisconnectTime`, `LogSettingsEntity`, `PayByLinkSettingsEntity`, `PaymentSettingsConfigEntity`, `WebhookSettingsEntity`, `PaymentLinkEntity`, `PaymentMethodConfigEntity`, `PaymentTransactionEntity`, `PaymentTransactionLockEntity`, `ProductTypeEntity`, `TokenEntity` i plugin-ove `Plugin0\Entity\PaymentMethodEntity`, `Plugin0\Entity\PaymentOperationEntity`, `Plugin0\Entity\WebhookEventEntity` i `Plugin0\Entity\AutoCaptureEntity` (`BaseRepository`), `MonitoringLog`, `WebhookLog` (`LogsRepository`), `QueueItem` (`QueueItemRepository`) |
| Repozitorijum za `QueueItem` (`QueueItemRepository`) | `RepositoryRegistry::getQueueItemRepository` | **ima** od 2026-10-20: `Infrastructure\Repository\QueueItemRepository` (docs/BACKGROUND.md 5.1) |
| Async endpoint (`runProcess`) | `Configuration::getAsyncProcessUrl` | **ima** od 2026-10-20: `controllers/front/asyncprocess.php` (docs/BACKGROUND.md 5.2) |
| Okidač runnera | `TaskRunnerWakeup::wakeup()` | **ima** od 2026-10-20: `bin/task-runner-wakeup.php` iz cron-a svakog minuta (docs/BACKGROUND.md 5.3) |
| Auto capture i čišćenje logova | `AutoCaptureCheckListener`, `LogCleanupTaskServiceInterface` | **zamenjeno** plugin klasama `BackgroundProcessing\AutoCapture\*` i `BackgroundProcessing\LogCleanup\*`, podešavanja Auto capture i Log retention na Settings strani (docs/BACKGROUND.md 6-8) |
| Webhook endpoint | `WebhookAPI` | **ima** od 2026-10-19: `controllers/front/webhook.php` (docs/WEBHOOK.md) |
| `ShopOrderService` | FQCN | **ima**: `Plugin0\Infrastructure\ShopOrderService`; pravi narudžbinu posle plaćanja (`validateOrder`) i menja joj status, idempotentno, pod MySQL `GET_LOCK` po korpi (vidi OKRUZENJE.md 14.7) |
| `ShopPaymentService` | FQCN | **ima**: `Plugin0\Infrastructure\ShopPaymentService`, samo upisuje zapis u log: metode plaćanja plugina su demo metode (`Logeecom\PaymentMethods`), ne Core konfiguracije metoda, pa PrestaShop strana nema šta da menja kad Core sačuva, uključi ili obriše svoju konfiguraciju; registrovan je jer bez njega Core ne može da napravi `PaymentService` ni `DisconnectService` |
| `LanguageService`, `LogoUrlService` | FQCN | **ima**: `LanguageService` daje aktivne jezike prodavnice sa ISO kodom velikim slovima (`'EN'`, `'SR'`, kako Core ključuje prevode); `LogoUrlService` daje `views/img/payment-methods/{productId}.svg` ili `.png` ako fajl postoji, inače `views/img/payment-method.svg` |
| `VersionService` | FQCN | **nema**; samo `AdminAPI::version()` |
| `CartProvider` implementacija | interfejs | **ima**: `Plugin0\Checkout\PrestaShopCartProvider` (merchantReference = ID korpe), umotan u Core `RoundingTotalsCartProvider` i `MemoryCachingCartProvider` |
| Checkout front kontroleri, return/landing, admin akcije na narudžbini | PrestaShop strana | **ima** za Hosted Checkout (tip Redirect): `controllers/front/payment.php` (`createSession` + redirect; Core `HostedCheckoutProxyInterface` zamenjen plugin klasom `Infrastructure\HostedCheckoutProxy`, zamka 32; `PaymentsProxyInterface` omotačem `Infrastructure\PaymentsProxy`, zamka 33), `controllers/front/return.php` (`updateOrderStatus`, `getPaymentOutcome`, po potrebi `startWaitingForOutcome`); **ima** admin akcije capture/refund/cancel: hook `displayAdminOrderMain` → `Order\PaymentManager` (`OrderAPI::orders()->getDetails`, `OperationValidator` pre `OrderAPI::capture\|refund\|cancel`, log `PaymentOperationEntity` sa Worldline ID-jem operacije iz odgovora, pa `CheckoutAPI::payment()->updateOrderStatus`; pri otvaranju panela `OperationLog::reconcileForOrder` redovima koji su još `pending` daje konačan ishod po operacijama iz `GET /payments/{id}/details`, jer Worldline capture i refund uvek prvo prihvati kao `*_REQUESTED`), rute `plugin0_order_*` (OKRUZENJE.md 14.8); Hosted Tokenization (iFrame) **nema** |
| `brand.json` | `ActiveBrandProvider` | **ima** |
| Tabela u bazi | `Installer::createTables` | **ima**: `plugin0_entity` sa 9 indeksnih kolona |
| Admin strane | PrestaShop Symfony rute `config/routes.yml` | **ima**: Configuration (`/worldline/configuration`, nad `AdminAPI::connection()`), Payment methods (`/worldline/payment-methods`, `.../create`, `.../{id}/edit`, `.../{id}/active`, `.../{id}/delete`; metode iz demo projekta, `Logeecom\PaymentMethods` nad `Plugin0\Entity\PaymentMethodEntity` u `ps_plugin0_entity`, ne Core `AdminAPI::payment()`), Settings (`/worldline/settings`; demo `Logeecom\Settings` nad `ConfigEntity` ključevima `minLogLevel`, `defaultCurrency` i `rowsPerPage`, plus Payment action: Core `PaymentSettings` i plugin ključ `cardAuthorizationMode`, plus od 2026-10-20 Auto capture (Core `PaymentSettings.automaticCapture`) i Log retention (Core `LogSettings`)), Logs (`/worldline/logs`, čita `ps_log`), `api_plugin0_core_info`, akcije panela na narudžbini (`/worldline/orders/{orderId}/capture`, `.../refund`, `.../cancel`); prava adresa admin rute ima prefiks `/modules` (PrestaShop `YamlModuleLoader`); front: `controllers/front/payment.php`, `controllers/front/return.php` i hook `paymentOptions` (vidi OKRUZENJE.md 14) |

Redosled implementacije koji sledi iz zavisnosti: (1) `QueueItemRepository` + async endpoint (jer i Hosted Tokenization bez
3DS i disconnect i svi periodični taskovi zavise od toga), (2) `ShopOrderService` + `CartProvider` + checkout/return kontroleri
(urađeno za Hosted Checkout u MIDU-1221, pre koraka 1, jer Hosted Checkout red zadataka ne traži),
(3) webhook endpoint, (4) `VersionService` i Core general settings (payment, log, pay by link, webhook) u adminu; tri servisa
i stranice Payment methods i Settings iz tog koraka već postoje (Settings od 2026-10-15 čuva i Core `paymentAction`),
(5) admin akcije capture/cancel/refund (urađeno 2026-10-15, pre koraka 1 i 3, jer `OrderAPI` red zadataka ne traži).

---

## 9. Zamke i detalji koje treba znati

1. **Logger šalje sve nivoe u `ShopLoggerAdapter`.** `minLogLevel` važi samo za default logger. Plugin0 `LoggerService` zato filtrira sam, po podešavanju Log level sa Settings stranice (`CoreLogLevel::isWritten`, `LoggerService::logMessage`); na default-u Info (dev prodavnica ima `_PS_MODE_DEV_ = false`) DEBUG zapisi, npr. svako čitanje repozitorijuma, ne stižu u `ps_log`. (Ranije je ovde pisalo da `LoggerService` ne filtrira; usklađeno 2026-10-16.)
2. **`ActiveConnectionProvider` kešira konekciju za ceo proces.** Posle `saveConnection` u istom zahtevu, servisi koji su već dobili instancu vide staru konekciju. Isto važi za `ConnectionConfigRepository` koji je `SingleInstance`, ali on ne kešira, pa plugin0 čita svež `getConnectionConfig()` posle čuvanja bez problema; problem je samo za komponente koje drže `ActiveConnectionProvider`.
3. **`StoreContext` mora biti postavljen** kad se zovu repozitorijumi direktno (van API fasada), inače `storeId` je `''`. Koristiti `StoreContext::doWithStore($storeId, fn)`. Plugin0 to radi u `ConfigurationController::disconnectActive()` i `Order\PaymentManager::reconcileLog()`.
4. **Filtriranje samo po indeksima.** `QueryFilter` po polju koje nije u `IndexMap`-u (ni `id`) mora da baci `QueryFilterInvalidParamException` u plugin repozitorijumu. `LIKE` samo za string, `IN` samo za array, `IS NULL` samo sa `null`.
5. **Kastovanje indeksa** (`IndexHelper::castFieldValue`) mora isto da se primeni i na vrednosti filtera, inače poređenja brojeva kao stringova ne rade (npr. `captureAtTimestamp <= now`).
6. **Core ignoriše povratne vrednosti `update()`/`delete()`** u svojim repozitorijumima; neuspeo upis ne baca izuzetak. Plugin0 `BaseRepository::save` baca `RuntimeException` pri neuspelom insertu, `update`/`delete` samo loguju.
7. **`QueueItemRepository::saveWithCondition`** mora da baci `QueueItemSaveException` kad uslov nije ispunjen; na tome počiva zaštita od dvostrukog startovanja zadatka.
8. **`Task::getClassName()` je kratko ime** (bez namespace-a). Dva zadatka iz različitih namespace-a sa istim kratkim imenom dele `taskType`.
9. **`CurlHttpClient` ne proverava SSL sertifikat** (`SSL_STRICT_MODE = false`). Koristi se samo za self-pozive, ali treba znati.
10. **`Amount` je int u najmanjim jedinicama.** `Amount::fromFloat(12.34, EUR)` = 1234. Valute sa 0 ili 3 decimale su u tabeli u `Currency`.
11. **`PaymentId` normalizacija**: `'4365991440'` postaje `'4365991440_0'`; hostedCheckoutId iz Hosted Checkout odgovora se takođe parsira kao `PaymentId`, a `transactionId` indeks se pravi iz `getTransactionId()`.
12. **`HostedTokenizationController::crate`** je ime metode (greška u Core-u); poziv `create` kroz fasadu bi pao u `ErrorHandlingAspect` kao nepostojeća metoda i vratio error response.
13. **`WebhookMode` se postavlja jednom** u `bootstrap()`; `FeedbacksTransformer` šalje webhook URL-ove Worldline-u samo u `AUTOMATIC` režimu, i tada `Configuration::getWebhookUrl()` mora da vrati pravi URL.
14. **`ConfigEntity::context` = `tenantId`**, prazan string u jednotenantskom režimu; globalni ključevi (lista u 4.4) nemaju kontekst.
15. **`class_name` u JSON-u** entiteta: plugin repozitorijum pravi instancu te klase, ne nužno registrovane (`rowsToEntities` u plugin0 koristi `class_name` iz podataka).
16. **Error response je uvek "uspešan" PHP poziv.** Posle `AdminAPI`/`CheckoutAPI`/`OrderAPI`/`WebhookAPI` poziva obavezno `isSuccessful()`; `toArray()` error odgovora ima `errorCode`/`errorMessage`.
17. **`ConnectionConfigResponse::toArray()` vraća dešifrovane tajne** (`apiSecret`, `webhooksSecret`). Plugin0 ih ne šalje u šablon.
18. **`DisconnectService` i `PaymentController` (AdminConfig) traže `ShopPaymentService`** već pri konstrukciji (unutar `SingleInstance` closure-a, dakle pri prvom `getService`). Bez registracije svaki poziv kroz `AdminAPI::get()->payment($storeId)->...` i `generalSettings($storeId)->disconnect()` završava u `ErrorHandlingAspect`-u kao `ServiceNotRegisteredException` i vraća error response sa kodom `general.unhandled`.
19. **`MetricsProvidingCommunicator` resetuje `StoreContext` origin** posle svakog zahteva, pa origin pokriva samo prvi SDK poziv u zahtevu.
20. **`TaskRunner` zauzima HTTP radnika oko `wakeupDelay` (5 s) + batch vreme** po ciklusu, neprekidno. Na serveru sa malo PHP-FPM procesa to treba uzeti u obzir (zapažanje iz koda; dimenzionisanje je neprovereno).
21. **`PaymentTransactionEntity` JSON ključ `cratedAt`** (slovna greška) je deo formata podataka; ne "popravljati" u plugin repozitorijumu.
22. **`LoggerConfiguration` kešira `integrationName`**; `Configuration::getIntegrationName()` mora da radi i kad plugin još nije instaliran do kraja (plugin0 vraća konstantu).
23. **`returnHmac = null` isključuje proveru.** `PaymentTransactionRepository::findPaymentTransactionEntity` dodaje uslov po `returnHmac` samo kad nije `null`; sa `null` transakciju nalazi samo po payment ID-ju. Povratna strana zato mora sama da odbije prazan ili izostavljen RETURNMAC pre poziva Core-a.
24. **Jedan povratak, dva `updateOrderStatus`.** `WaitPaymentOutcomeProcess::startWaiting` ga zove u `getPaymentOutcome` (kad čekanje istekne, a status je i dalje pending) i još jednom na kraju; `ShopOrderService::createShopOrder` mora da bude idempotentan i bez ponovljenih zahteva.
25. **`lockOrderCreation` nije atomičan** nad tabelom bez unique ključa (prvo `selectOne`, pa `save`); dva istovremena zahteva mogu oba da dobiju "lock". Plugin0 zato drži MySQL `GET_LOCK` po korpi u `ShopOrderService`.
26. **Bez webhook-a `startWaitingForOutcome` uvek čeka ~10 s.** Petlja čita samo lokalni status transakcije (0 dok ga niko ne ažurira) i tek kad čekanje istekne pita Worldline; zato plugin0 na povratku prvo zove `updateOrderStatus`.
27. **Proizvodi iz `PaymentProductId::SEPARATE_CAPTURE_SUPPORTED` traže Core `PaymentMethodConfigEntity`.** `CreateHostedCheckoutRequestTransformer` za njih zove `$config->getPaymentAction()` bez provere na `null`; bez uključene Core metode `createSession` vraća `general.unhandled` ("Call to a member function getPaymentAction() on null"), provereno za 302 (Apple Pay) 2026-10-14. Kartični brendovi (npr. 1 = Visa) tu granu ne prolaze.
28. **`void` metode fasade** (npr. `PaymentController::startWaitingForOutcome`) kroz `ErrorHandlingAspect` vraćaju `null` kad uspeju, a `TranslatableErrorResponse` kad ne uspeju; proverava se `instanceof Response && !isSuccessful()`.
29. **`OrderAPI` capture/refund/cancel ne proveravaju ništa i ne diraju narudžbinu.** `CaptureService`, `RefundService` i `CancelService` su jedan red: `return $this->proxy->create($request)`. Iznos veći od dostupnog, nula ili cancel naplaćenog plaćanja odlaze Worldline-u kakvi jesu; status narudžbine se menja tek kad neko pozove `CheckoutAPI::payment()->updateOrderStatus` (plugin0 to radi odmah posle uspešne operacije i pri otvaranju panela). Dostupne iznose daje `OrderAPI::orders()->getDetails` (`OrderDetails`), svaki poziv je novi `GET /payments/{id}/details`.
30. **Bez sačuvanih `PaymentSettings` Core plaća kao SALE.** `GeneralSettingsService::getPaymentSettings` vraća `PaymentAction::authorizeCapture()` kad red `PaymentSettingsConfigEntity` ne postoji; plaćanje odmah dobija status 9 i može samo refund. Za capture/cancel se mora sačuvati `FINAL_AUTHORIZATION` (`AdminAPI::generalSettings()->savePaymentSettings`, plugin0 Settings strana). `getPaymentSettings` vraća `null` i kad nema aktivne konekcije.
31. **`PaymentModule::validateOrder` zove `die()`**, ne baca izuzetak, kad za korpu već postoji narudžbina, kad se `secure_key` ne slaže i kad modul nije aktivan (PrestaShop 8.2.4, `classes/PaymentModule.php`); te uslove `ShopOrderService` proverava pre poziva. `validateOrder` i menja `Context` (cart, customer, language, currency, shop).
32. **Za pojedinačni kartični brend Core šalje protivrečne režime, a Worldline ih tumači kao SALE.** `CreateHostedCheckoutRequestTransformer` usklađuje `mobilePaymentMethodSpecificInput.authorizationMode` i `redirectPaymentMethodSpecificInput.requiresApproval` sa akcijom plaćanja samo za proizvode iz `PaymentProductId::SEPARATE_CAPTURE_SUPPORTED` (grupa `cards`, Apple/Google Pay, PayPal...); za brend kao `1` (Visa) ostaju početne vrednosti `SALE` i `false` uz kartični `FINAL_AUTHORIZATION`. Worldline preprod tada kartično plaćanje obradi kao direct sale (status 9, "Direct sale (payment)" u Merchant Portal-u), provereno 2026-10-15 probama (identičan zahtev 9; bez mobile/redirect dela ili sa usklađenim vrednostima 5; `PRE_AUTHORIZATION` uz mobile `SALE` i dalje 9; bez 3DS i dalje 9). Plugin0 zato u `Bootstrap::initProxies` (ne u `initServices`, jer `BootstrapComponent::init` zove `initProxies` posle `initServices` i Core bi pregazio raniju registraciju) registruje `Infrastructure\HostedCheckoutProxy` pod `HostedCheckoutProxyInterface`: isti tok kao Core proxy, plus `alignAuthorizationModes` za `isCardBrand()` proizvode. Test: `tests/Unit/Checkout/HostedCheckoutProxyTest.php`. Isti proxy daje i treći izbor Settings strane, `PRE_AUTHORIZATION` (delimični capture i cancel, 30 dana): Core `PaymentAction::fromState` zna samo SALE i FINAL_AUTHORIZATION, pa `Infrastructure\PaymentActionSetting` u Core `PaymentSettings` upiše FINAL_AUTHORIZATION, a izabrani način rezervacije kartice čuva pod plugin ključem `Configuration::KEY_CARD_AUTHORIZATION_MODE`; proxy ga upiše u `cardPaymentMethodSpecificInput.authorizationMode` kad je kartični režim FINAL_AUTHORIZATION (SALE se ne dira). Test: `tests/Unit/Settings/PaymentActionSettingTest.php`.
34. **Core webhook put ne nosi ID događaja i ne razlikuje grešku potpisa.** `WebhookData` ima ID plaćanja, referencu, tip i sirovo telo, ali ne i `id` sa vrha JSON-a; SDK `SignatureValidationException`, `SecretKeyNotAvailableException`, `ApiVersionMismatchException` i `InvalidResponseException` su obični `RuntimeException`-i, pa `ErrorHandlingAspect` za njih vraća `general.unhandled` kao i za pad baze. Događaj bez objekta `payment`/`refund` (token.*, payout.*) daje prazan ID i `WebhookLogsService::logWebhook:53` pada na `PaymentId::parse('')`. Core u `updateOrderStatus` čita Worldline (`StatusUpdateService.php:63`) pre nego što integracija zaključa korpu, pa dva procesa mogu da primene snimke u obrnutom redu. Worldline na `GET /payments/{id}_1/details` (ID operacije) vraća celo plaćanje u trenutnom stanju (provereno 2026-10-19), a na `GET /payments/{id}_1` bez `/details` 404, što Core u `resolvePaymentMethodName` i očekuje. Rešenja u plugin0: docs/WEBHOOK.md.
35. **Worldline preprod od 2026-10-19 vraća polje `returnmac` malim slovima** u odgovoru na `POST /hostedcheckouts` (16. 10. je bilo `RETURNMAC`). SDK `CreateHostedCheckoutResponse` mapira samo `RETURNMAC`, pa Core sačuva prazan `returnHmac`, a povratak kupca (URL i dalje nosi `RETURNMAC=...`) završi sa `PaymentTransactionNotFoundException` i porukom "nije potvrđeno" (korpa 18, plaćanje 10300867120). Popravljeno u plugin0 2026-10-19: `Infrastructure\HostedCheckoutProxy` odgovor čita kroz `Infrastructure\Sdk\HostedCheckoutClient` u `Infrastructure\Sdk\CreateHostedCheckoutResponse`, koji prihvata i `returnmac` (`RETURNMAC` ima prednost); test `tests/Unit/Checkout/CreateHostedCheckoutResponseTest.php`, probna sesija 10301130070 dobila RETURNMAC od 36 znakova.
33. **Posle delimičnih naplata Core gleda samo prvobitnu rezervaciju `_0`.** Worldline naplate vodi kao posebne operacije `_1`, `_2`... (status 9, `isRefundable=true`), otkazivanje ostatka kao operaciju sa statusom 6, a `_0` ostaje u statusu 5 sa `isRefundable=false` i kad od autorizovanog iznosa ništa nije ostalo (provereno 2026-10-16, plaćanje 10300628270: 30 + 11 + 10 naplaćeno, 17.93 otkazano od 68.93). `PaymentDetailsResponseTransformer` status i zastavice uzima sa `_0`, pa `StatusMappingService` narudžbinu drži na "autorizovano", a `OrderService::getDetails` refund svodi na 0, iako Worldline refund na `_0` prihvata (proba: `POST /payments/10300628270_0/refund` 1,00 EUR → `10300628270_5`, `REFUND_REQUESTED`). Plugin0 zato u `Bootstrap::initProxies` registruje `Infrastructure\PaymentsProxy`, omotač oko Core `PaymentsProxy`, koji posle `getPaymentDetails` zatvorenu rezervaciju (status 5, nešto naplaćeno, ništa u obradi, autorizovano − naplaćeno − otkazano <= 0) tumači kao status 9 / `CAPTURED` sa `isRefundable=true`, a kad je vraćeno >= naplaćeno kao 8 / `REFUNDED`; Core puni refund inače prepoznaje samo kad je vraćen ceo autorizovani iznos. Test: `tests/Unit/Order/PaymentsProxyTest.php`. PrestaShop pri prelasku u "Payment accepted" i dalje upiše uplatu za ceo iznos fakture (`OrderHistory::changeIdOrderState`, status sa `paid=1`), ne za naplaćeni; to ovaj omotač ne rešava. Povezana zamka: `StatusMappingService::getFullStatusMapping` čim je nešto vraćeno, a manje od naplaćenog, vraća `paymentPartiallyRefundedStatus`, i to **pre** mapiranja po statusu; kad je taj status prazan (tako ga je `StoreService` davao do 2026-10-16), `StatusUpdateService` ne menja narudžbinu uopšte, pa narudžbina sa delimičnim refundom ostaje u zatečenom statusu (viđeno: narudžbina 16 ostala na "autorizovano" iako je plaćanje 9). Plugin0 zato "partially refunded" podrazumevano mapira na isti status kao "captured" (`StoreService::getDefaultOrderStatusMapping`, a `PaymentActionSetting::save` popunjava prazno u već sačuvanim podešavanjima).

36. **Core auto capture ne ispunjava pravila "isključeno ne naplaćuje", "bez dvostruke naplate" i "neuspeh se ponavlja".** `StatusUpdateService` upiše `captureAt = sada + automaticCapture minuta` samo za FINAL_AUTHORIZATION i samo jednom (`StatusUpdateService.php:71-79`; najmanje 60 min, `AutomaticCapture.php:14-21`). `AutoCaptureCheckTask` (`AutoCaptureCheckTask.php:27-34`) zatim naplati svaku lokalnu transakciju sa statusom 5 i prošlim `captureAt`: ne gleda podešavanje u trenutku naplate, ignoriše error odgovor fasade (zadatak ostaje `completed`, nema ponavljanja), ne osvežava lokalni status (sledeći krug ponovo bira isto plaćanje) i ne loguje pokušaje. Plugin0 ga zamenjuje (docs/BACKGROUND.md 7).
37. **Core čišćenje logova računa rok pri upisu.** `expiresAt = createdAt + logRecordsLifetime` se upiše kad zapis nastane (`WebhookLogRepository.php:57-59`, `MonitoringLogRepository.php:72`), a `LogCleanupTask` briše samo `expiresAt < sada`; promena podešavanja važi samo za nove zapise. Briše samo `MonitoringLog` i `WebhookLog`, ne `ps_log`, i ne loguje broj obrisanih. Plugin0 ga zamenjuje (docs/BACKGROUND.md 8).
38. **Retry: najviše 6 izvršavanja, bez pauze.** `QueueService::fail` obara zadatak kad je `retries > maxTaskExecutionRetries` (default 5), dakle posle šestog neuspeha; između pokušaja nema čekanja, jer polje `earliestStartTime` (`QueueItem.php:150`) ništa ne čita. Fasade ne bacaju izuzetke (zamka 16), pa zadatak mora sam da baci da bi se ponavljao. Provereno kroz pravi red 2026-10-20 (docs/BACKGROUND.md 9.3).
39. **`saveWithCondition` i MySQL "affected rows".** MySQL ne broji red koji je već imao iste vrednosti, pa 0 izmenjenih redova znači ili "uslov nije prošao" ili "isti sadržaj". Razlikuje ih samo ponovna provera istog uslova; poređenje sadržaja reda je pogrešno, jer dva procesa koja u istoj sekundi pokrenu isti zadatak upišu isti sadržaj (prva verzija plugin0 je tako grešila, provera trke je to pokazala).
40. **`OrderAPI::orders()->getDetails($merchantReference)` čita poslednju transakciju korpe** (`OrderService.php:29-31`). Za korpu sa više plaćanja (npr. korpa 18 u dev prodavnici) to nije nužno plaćanje o kom je reč; automatska naplata zato stanje čita po ID-ju plaćanja (`PaymentsProxyInterface::getPaymentDetails`) istom formulom kao `OrderService.php:47-49`.
41. **Periodični zadaci postoje samo dok runner radi.** U red ih stavljaju listeneri na `TickEvent`, a `TickEvent` okida samo runner; kad se lanac samopoziva prekine (restart, izgubljen zahtev), ništa ga ne pokreće osim novog `enqueue()` ili spoljnog okidača (cron, docs/BACKGROUND.md 4.1 i 5.3).

---

## 10. Mapa foldera Core-a

```
src/Infrastructure/
  ServiceRegister.php, Singleton.php, BootstrapComponent.php
  AutoTest/            AutoTestService, AutoTestTask, AutoTestLogger, AutoTestStatus
  Configuration/       Configuration (apstraktna), ConfigurationManager, ConfigEntity
  Data/                DataTransferObject, Transformer
  Exceptions/          BaseException, ServiceNotRegisteredException, StorageNotAccessibleException
  Http/                HttpClient, CurlHttpClient, AsyncSocketHttpClient, LoggingHttpClient, HttpResponse, AutoConfiguration, DTO/Options, Exceptions/*
  Logger/              Logger, LogData, LogContextData, LoggerConfiguration, Interfaces/{LoggerAdapter,ShopLoggerAdapter,DefaultLoggerAdapter}
  ORM/                 Entity, RepositoryRegistry, IntermediateObject, Configuration/{EntityConfiguration,IndexMap,IndexColumn},
                       Interfaces/{RepositoryInterface,ConditionallyDeletes,MassInsert,QueueItemRepository}, QueryFilter/{QueryFilter,QueryCondition,Operators},
                       Utility/{IndexHelper,EntityTranslator}, Exceptions/*
  Serializer/          Serializer, Concrete/{JsonSerializer,NativeSerializer}, Interfaces/Serializable
  TaskExecution/       Task, QueueItem, QueueService, QueueItemStarter, TaskRunner, TaskRunnerStarter, TaskRunnerWakeupService, TaskRunnerManager,
                       TaskRunnerStatus, RunnerStatusStorage, AsyncProcessStarterService, AsyncBatchStarter, Process, CompositeTask,
                       Composite/{Orchestrator,ExecutionDetails}, Events/*, TaskEvents/{TaskProgressEvent,AliveAnnouncedTaskEvent,TickEvent,Listeners/*},
                       Interfaces/{AsyncProcessService,Priority,Runnable,TaskRunnerManager,TaskRunnerStatusStorage,TaskRunnerWakeup}, Exceptions/*
  Utility/             TimeProvider, GuidProvider, Php, Events/{Event,EventEmitter,EventBus}

src/BusinessLogic/
  Domain/              ApiFacades/{Request,Response}, Cancel, Capture, Refund, Checkout (Amount, Currency, TaxableAmount, TaxRate, Country, Cart/*, Surcharge*),
                       Connection, Disconnect, GeneralSettings, HostedCheckout, HostedTokenization, Integration/* (interfejsi za plugin),
                       Language, LogCleanup, Monitoring, Multistore, Order, OrderStatusMapping, Payment, PaymentLinks, PaymentMethod, ProductTypes,
                       Stores, Time, Translations, Version, Webhook
  AdminConfig/         ApiFacades/{Connection,GeneralSettings,Integration,Language,Monitoring,Payment,ProductTypes,Store,Versions}API/{Controller,Request,Response}
                       Services/{Connection,Disconnect,GeneralSettings,Monitoring,Payment,ProductTypes}
  PaymentProcessor/    ApiFacades/{AdminAPI(PaymentLinks),CheckoutAPI,WebhooksAPI}/{Controller,Response}, BackgroundProcesses/WaitPaymentOutcome*,
                       Proxies/*Interface, Repositories/*Interface, Services/{HostedCheckout,HostedTokenization,Payment,PaymentLinks,PaymentMethod,Webhooks}
  Order/               ApiFacades/{Cancel,Capture,Orders,Refund}API, Proxies/*Interface, Services/{Cancel,Capture,Order,Refund}

src/Bootstrap/
  BootstrapComponent.php, SingleInstance.php
  Aspect/              Aspect, Aspects, CompositeAspect
  ApiFacades/          Aspects/{ErrorHandling,StoreContext,TenantContext}Aspect, Response/{ErrorResponse,TranslatableErrorResponse},
                       AdminConfig/{AdminAPI/AdminAPI, Proxies/ConnectionProxy}, Order/{OrderAPI/OrderAPI, Proxies/*, Proxies/Transformers/*},
                       PaymentProcessor/{CheckoutAPI/CheckoutAPI, WebhookAPI/WebhookAPI, BackgroundProcesses/*, Proxies/*, Proxies/Transformers/*}
  Configuration/       Configuration (apstraktna, Bootstrap nivo), ConfigurationManager (tenant kontekst)
  DataAccess/          Connection, Disconnect, GeneralSettings, Maintenance, Monitoring, PaymentLink, PaymentMethod, PaymentTransaction, ProductTypes, Tokens
  Disconnect/          DisconnectTaskEnqueuer, Tasks/DisconnectTask
  LogCleanup/          LogCleanupTaskService, Tasks/LogCleanupTask
  Maintenance/         TaskCleanupListener, TaskCleanupTask
  Multistore/          DefaultTenantRegistry
  Sdk/                 MerchantClientFactory, MetricsProvidingCommunicator, DbLogConnection, CommunicatorLoggerHelper, ApiLogger, WebhookTransformer
  TaskExecution/       TenantAwareTask
  Time/                TimeProvider

src/Branding/Brand/    BrandConfig, ActiveBrandProvider, ActiveBrandProviderInterface
```
