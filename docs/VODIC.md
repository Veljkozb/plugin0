# Vodič kroz plugin0

Kompletno objašnjenje PrestaShop modula plugin0: šta radi, od kojih delova se sastoji, koji fajl šta radi, koji
interfejsi su ti važni, kako teku podaci od klika do baze i nazad, i kako se sve to proverava.

**Na čemu se dokument zasniva (2026-10-16):**

- Grana `feature/MIDU-1224`, commit `06a7b91` ("Merchant panel on the order page: capture, refund and cancel",
  2026-10-16 11:21), radni direktorijum bez izmena.
- Pročitan je ceo kod plugina: `/home/veljkozbiljic/logeecom/plugin0/plugin0.php`, sve u `/home/veljkozbiljic/logeecom/plugin0/src/`, `/home/veljkozbiljic/logeecom/plugin0/lib/`,
  `/home/veljkozbiljic/logeecom/plugin0/controllers/`, `/home/veljkozbiljic/logeecom/plugin0/views/`, `/home/veljkozbiljic/logeecom/plugin0/config/` i spisak testova u `/home/veljkozbiljic/logeecom/plugin0/tests/`.
- Pročitani su Core i PrestaShop fajlovi koji se u tekstu navode sa brojem linije.
- Baza `prestashop` u kontejneru `logeecom_dev` je pregledana samo čitanjem (SELECT), a Apache access log samo
  pretragom.
- Unit testovi su pokrenuti: **169 testova, 460 provera, svi prolaze** (PHPUnit 11.5.57, PHP 8.2.25 u kontejneru).
- Dopunjeno istog dana: za sve četiri admin stranice i panel na narudžbini šta nam znače, kako smo ih pravili i kako
  su napravljene u kodu, sloj po sloj, sa šablonima (11.1, 11.2, 12.7-12.10, 13.10-13.14, 14.7-14.9, 15.1-15.6,
  20.10-20.11).

**Oznake u tekstu:**

| Oznaka | Značenje |
|---|---|
| **neprovereno** | nisam proverio ni u kodu, ni u bazi, ni radom |
| **default** | podrazumevana vrednost alata ili biblioteke, ne nešto što je plugin postavio |
| **pojednostavljeno** | kod ili slika napisani radi razumevanja; nije doslovno ono što je u fajlu |
| **poređenje** | analogija iz svakodnevnog života; pomaže da se shvati, ali nije tehnički opis |
| **iz beleški** | činjenica iz beleški prethodnih sesija (npr. šta je provereno u browseru); danas nije ponovo proverena |

Ovaj dokument je o samom pluginu. Dva starija dokumenta idu dublje u svoje teme:

- `/home/veljkozbiljic/logeecom/plugin0/docs/CORE.md`: referenca Core biblioteke, klasa po klasa.
- `/home/veljkozbiljic/logeecom/plugin0/docs/OKRUZENJE.md`: Docker, WSL, kontejner, baza, mreža, ngrok.

Zastareli delovi ta dva dokumenta su usklađeni sa kodom 2026-10-16; šta je promenjeno piše u poglavlju 34.

---

## Sadržaj

**Deo I. Velika slika**

1. [Šta je plugin0, u jednoj rečenici i jednoj slici?](#1-šta-je-plugin0-u-jednoj-rečenici-i-jednoj-slici)
2. [Ko sve učestvuje i ko koga poziva?](#2-ko-sve-učestvuje-i-ko-koga-poziva)
3. [Kako je projekat nastajao i gde je šta u git-u?](#3-kako-je-projekat-nastajao-i-gde-je-šta-u-git-u)

**Deo II. Temelji: pojmovi bez kojih ostalo nema smisla**

4. [Kako PrestaShop zna za plugin0 i kada ga poziva?](#4-kako-prestashop-zna-za-plugin0-i-kada-ga-poziva)
5. [Šta znači "podići Core" i zašto se to radi na svakom zahtevu?](#5-šta-znači-podići-core-i-zašto-se-to-radi-na-svakom-zahtevu)
6. [Kako Core traži stvari od plugina? (interfejsi koje plugin0 implementira)](#6-kako-core-traži-stvari-od-plugina-interfejsi-koje-plugin0-implementira)
7. [Kako plugin0 traži stvari od Core-a, i zašto Core ne baca greške?](#7-kako-plugin0-traži-stvari-od-core-a-i-zašto-core-ne-baca-greške)
8. [Kako Core razgovara sa Worldline-om?](#8-kako-core-razgovara-sa-worldline-om)
9. [Gde se čuvaju podaci?](#9-gde-se-čuvaju-podaci)
10. [Kako radi logovanje?](#10-kako-radi-logovanje)

**Deo III. Tokovi, korak po korak**

11. [Šta se desi kad otvoriš jednu admin stranicu plugina?](#11-šta-se-desi-kad-otvoriš-jednu-admin-stranicu-plugina)
12. [Configuration: kako se prodavnica povezuje sa Worldline-om?](#12-configuration-kako-se-prodavnica-povezuje-sa-worldline-om)
13. [Payment methods: kako admin pravi metode plaćanja?](#13-payment-methods-kako-admin-pravi-metode-plaćanja)
14. [Settings: šta znače četiri podešavanja i gde se čuvaju?](#14-settings-šta-znače-četiri-podešavanja-i-gde-se-čuvaju)
15. [Logs: kako stranica čita log?](#15-logs-kako-stranica-čita-log)
16. [Checkout, korak 1: koje metode kupac vidi i zašto baš te?](#16-checkout-korak-1-koje-metode-kupac-vidi-i-zašto-baš-te)
17. [Checkout, korak 2: šta se desi kad kupac klikne PLACE ORDER?](#17-checkout-korak-2-šta-se-desi-kad-kupac-klikne-place-order)
18. [Checkout, korak 3: šta se desi kad se kupac vrati sa Worldline strane?](#18-checkout-korak-3-šta-se-desi-kad-se-kupac-vrati-sa-worldline-strane)
19. [Kada nastaje narudžbina i ko joj menja status?](#19-kada-nastaje-narudžbina-i-ko-joj-menja-status)
20. [Panel na stranici narudžbine: capture, refund, cancel](#20-panel-na-stranici-narudžbine-capture-refund-cancel)
21. [Koja dva problema plugin zaobilazi sopstvenim proxy klasama?](#21-koja-dva-problema-plugin-zaobilazi-sopstvenim-proxy-klasama)

**Deo IV. Referenca**

22. [Katalog svih fajlova plugina](#22-katalog-svih-fajlova-plugina)
23. [Interfejsi koji su ti važni, na jednom mestu](#23-interfejsi-koji-su-ti-važni-na-jednom-mestu)
24. [Core fajlovi koje treba da poznaješ](#24-core-fajlovi-koje-treba-da-poznaješ)
25. [PrestaShop fajlovi i hook-ovi na koje se plugin oslanja](#25-prestashop-fajlovi-i-hook-ovi-na-koje-se-plugin-oslanja)

**Deo V. Provere**

26. [Unit testovi: kako se pokreću i šta dokazuju?](#26-unit-testovi-kako-se-pokreću-i-šta-dokazuju)
27. [Provere iz terminala: grep, git, LF, Core netaknut](#27-provere-iz-terminala-grep-git-lf-core-netaknut)
28. [Provere u bazi](#28-provere-u-bazi)
29. [Provere u logu](#29-provere-u-logu)
30. [Provere u browseru, scenario po scenario](#30-provere-u-browseru-scenario-po-scenario)
31. [Kad izmena "ne radi": keš, ruta, hook, skripta](#31-kad-izmena-ne-radi-keš-ruta-hook-skripta)

**Deo VI. Ograničenja, zamke, nedoslednosti**

32. [Šta plugin0 (još) ne radi?](#32-šta-plugin0-još-ne-radi)
33. [Zamke Core-a i PrestaShop-a koje moraš da znaš](#33-zamke-core-a-i-prestashop-a-koje-moraš-da-znaš)
34. [Nedoslednosti u komentarima i dokumentima](#34-nedoslednosti-u-komentarima-i-dokumentima)

**Deo VII. Za kraj**

35. [Rečnik pojmova](#35-rečnik-pojmova)
36. [Stanje projekta, zaključak i sledeći korak](#36-stanje-projekta-zaključak-i-sledeći-korak)

---

## 0. Kako da čitaš ovaj dokument

**Redosled.** Delovi I i II su obavezni i treba ih čitati redom: bez njih tokovi iz dela III deluju kao gomila
imena klasa. Deo III čitaj tok po tok, kad radiš na tom delu. Deo IV je za traženje ("gde je X?"), deo V za
proveravanje ("kako da znam da radi?"), deo VI pre nego što nešto menjaš.

**Putanje.** Svuda su pune putanje, da ih ne moraš sklapati. Isti fajlovi imaju dve putanje, jer je folder plugina
bind mount u kontejner (OKRUZENJE.md, poglavlje 2):

| Šta | Putanja u WSL-u (ovde menjaš kod) | Ista stvar u kontejneru `logeecom_dev` |
|---|---|---|
| plugin0 | `/home/veljkozbiljic/logeecom/plugin0/` | `/var/www/html/public/prestashop/8-2-4/modules/plugin0/` |
| Core | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/` | `/var/www/html/public/prestashop/8-2-4/modules/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/` |
| Worldline PHP SDK | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/sdk-php/src/OnlinePayments/Sdk/` | `/var/www/html/public/prestashop/8-2-4/modules/plugin0/vendor/wl-online-payments-direct/sdk-php/src/OnlinePayments/Sdk/` |
| PrestaShop 8.2.4 | `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/` | `/var/www/html/public/prestashop/8-2-4/` |
| demo projekat (izvor za `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom`) | `/home/veljkozbiljic/laravel-docker/src/` | nije u ovom kontejneru |

Oblik `putanja:linija` (npr. `/home/veljkozbiljic/logeecom/plugin0/src/Bootstrap.php:135`) znači "taj fajl, ta
linija". U PhpStorm-u: Navigate → File (Ctrl+Shift+N), upiši deo putanje posle `plugin0/` i dodaj `:135`; da li
PhpStorm na tvom Windows-u prihvata i celu WSL putanju je **neprovereno**.

**Jezik.** Prvo kažem stvar običnim rečima, pa tek onda tačno ime (klasa, metoda, fajl, linija, vrednost). Posle
tehničkog pravila sledi rečenica "Prostim rečima:".

---

# Deo I. Velika slika

## 1. Šta je plugin0, u jednoj rečenici i jednoj slici?

### 1.1 U jednoj rečenici

plugin0 je dodatak (modul) za prodavnicu PrestaShop koji kupcu omogućava da plati preko Worldline-a, a trgovcu
da poveže prodavnicu sa svojim Worldline nalogom, sam napravi metode plaćanja, podesi ponašanje plugina, čita
log i sa stranice narudžbine naplati, vrati ili otkaže novac.

Tačnije: to je PrestaShop 8 modul plaćanja, klasa `Plugin0 extends PaymentModule` u
`/home/veljkozbiljic/logeecom/plugin0/plugin0.php:17`, ime `plugin0`, kategorija `payments_gateways`, verzija
`1.0.0`, za PrestaShop od 8.0.0 (`/home/veljkozbiljic/logeecom/plugin0/plugin0.php:21-26`). U dev prodavnici je
instaliran i aktivan (`ps_module`: `id_module = 73`, `active = 1`, `version = 1.0.0`, provereno u bazi 2026-10-16).

To je projekat za učenje: Worldline Online Payments integracija napisana od nule, na Logeecom-ovoj biblioteci
"Core" (`wl-online-payments-direct/plugin-core-base` 1.0.7).

### 1.2 Šta vidi kupac, a šta trgovac

**Kupac** (front office, `https://8-2-4.prestashop.veljko.ngrok.app/`):

1. U koraku plaćanja na checkout-u vidi opcije plaćanja koje plugin nudi, na primer "Viza payment".
2. Izabere opciju i klikne PLACE ORDER.
3. Browser ga odvede na Worldline stranu za plaćanje (sandbox: `payment.preprod.direct.worldline-solutions.com`).
4. Unese karticu, prođe 3-D Secure ako ga banka traži.
5. Worldline ga vrati u prodavnicu, na stranicu plugina "return".
6. Završi na potvrdi narudžbine, ili nazad na koraku plaćanja sa porukom (otkazano, odbijeno, nije potvrđeno),
   ili na stranici "plaćanje se obrađuje".

**Trgovac** (back office, `https://8-2-4.prestashop.veljko.ngrok.app/admin174kob088ranecckhxg/`):

1. **Configuration**: unosi PSPID, API Key, API Secret, Webhook Key i Webhook Secret za Test ili Live okruženje.
   Plugin ih proverava kod Worldline-a pre čuvanja.
2. **Payment methods**: pravi sopstvene metode plaćanja (ime, kod, tip, valute, zemlje, redosled, aktivna).
3. **Settings**: Log level, Default currency, Rows per page, Payment action.
4. **Logs**: čita zapise plugina.
5. Na stranici narudžbine (Sell → Orders → narudžbina): kartica **"Worldline payment"** sa stanjem plaćanja i
   formama za capture, refund i cancel.

### 1.3 Slika

```
 KUPAC (browser)                                     TRGOVAC (browser, PrestaShop admin)
   │ checkout, PLACE ORDER, povratak sa Worldline-a     │ Worldline stranice plugina, panel na narudžbini
   ▼                                                    ▼
+------------------- PrestaShop 8.2.4 (PHP-FPM 8.2, kontejner logeecom_dev) -------------------+
|  hook-ovi, front controller-i, Symfony admin rute                                          |
|        | PrestaShop poziva plugin                                                           |
|        v                                                                                    |
|  +------------------- plugin0 (modules/plugin0) -------------------+   poziva   +---------+ |
|  | plugin0.php, src/ (Plugin0\), controllers/front/,              | ---------> | lib/    | |
|  | views/, config/                                                | <--------- | Logeecom| |
|  +----------+----------------------------------^-------------------+  preko     | (demo   | |
|             | plugin poziva Core              | Core poziva plugin    demo      | pravila)| |
|             | (AdminAPI, CheckoutAPI,         | preko interfejsa      interfejsa+---------+ |
|             |  OrderAPI, Logger, ...)         | (ShopOrderService, StoreService,           |
|             v                                 |  LoggerService, BaseRepository, ...)       |
|  +------------------- Core 1.0.7 (vendor/.../plugin-core-base) ------+                      |
|  | poslovna logika Worldline integracije; ne zna da je ovo PrestaShop |                      |
|  +----------+--------------------------------------------------------+                      |
|             v                                                                               |
|  SDK 6.2.0 (vendor/.../sdk-php): HTTP zahtevi i HMAC potpis                                 |
+-------------+-------------------------------------------------------------------------------+
              | HTTPS                                   MySQL 8.0.40, isti kontejner, baza prestashop:
              v                                         ps_plugin0_entity + PrestaShop tabele
   Worldline Direct API (preprod)
   + Hosted Checkout strana, na koju je kupac preusmeren; posle plaćanja Worldline
     preusmerava kupčev browser nazad na /en/module/plugin0/return
```

### 1.4 Ista slika rečima

1. PrestaShop poziva plugin0: kad gradi korak plaćanja, kad kupac klikne PLACE ORDER, kad se kupac vrati sa
   Worldline-a, kad trgovac otvori stranicu plugina ili stranicu narudžbine.
2. plugin0 poziva Core kad god treba nešto vezano za Worldline: povezivanje, sesija za plaćanje, ishod plaćanja,
   capture, refund, cancel.
3. Core poziva SDK, a SDK šalje HTTPS zahtev Worldline-u.
4. Kad Core-u zatreba nešto iz prodavnice (upiši red u bazu, upiši log, napravi narudžbinu, koji su statusi
   narudžbine), on to ne radi sam: pita plugin preko interfejsa koje je plugin registrovao. Zato strelica ide i
   nazad, od Core-a ka pluginu.
5. Za metode plaćanja i podešavanja plugin koristi poslovna pravila iz demo projekta (`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom`). Ta pravila
   ne znaju ni za PrestaShop ni za Core; do baze i loga stižu preko adaptera iz `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/`.

**Četiri stvari koje se lako pomešaju, a različite su:**

1. **plugin0 i Core su različite stvari.** plugin0 je tvoj kod i smeš da ga menjaš. Core je tuđa biblioteka u
   `/home/veljkozbiljic/logeecom/plugin0/vendor/` i **ne menja se nikad**; greške Core-a se zaobilaze u pluginu (poglavlje 21, 33).
2. **Core i SDK su različite stvari.** Core odlučuje *šta* se šalje Worldline-u i šta odgovor znači. SDK samo
   *prenosi* zahtev (HTTP, potpis, JSON).
3. **`/home/veljkozbiljic/logeecom/plugin0/src/` i `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/` su različite stvari.** `/home/veljkozbiljic/logeecom/plugin0/src/` (namespace `Plugin0\`) je PrestaShop i Core strana.
   `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/` (namespace `Logeecom\`) je poslovni sloj demo projekta, kopiran bez ijedne izmene.
4. **"Payment methods" plugina i Worldline proizvodi su različite stvari.** Worldline proizvod je fiksan ID u
   Core-u (npr. `1` = Visa, `302` = Apple Pay). Metoda plaćanja plugina je zapis koji admin sam pravi; ona se nudi
   kupcu samo ako je njen **Code** jedan od Worldline ID-jeva koje Core podržava (poglavlje 16).

---

## 2. Ko sve učestvuje i ko koga poziva?

| Deo | Gde je (puna putanja) | Šta radi | Da li ga menjamo |
|---|---|---|---|
| Browser kupca i trgovca | Windows | šalje zahteve preko ngrok domena `8-2-4.prestashop.veljko.ngrok.app` | — |
| ngrok + Apache 2 | ngrok agent u WSL-u, Apache u kontejneru `logeecom_dev` | dovode zahtev do PHP-a (OKRUZENJE.md 12) | ne |
| PHP-FPM 8.2 | kontejner `logeecom_dev` | izvršava PHP kod PrestaShop-a i plugina | ne |
| PrestaShop 8.2.4 | `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/` | platforma prodavnice: korpe, narudžbine, kupci, admin, hook-ovi, rute | ne |
| plugin0 | `/home/veljkozbiljic/logeecom/plugin0/` | naš modul: veza PrestaShop ↔ Core, admin stranice, checkout, panel na narudžbini | **da, samo ovo** |
| demo poslovni sloj | `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/` | pravila za metode plaćanja i podešavanja, iz demo projekta | ne (čuva se bajt-identičan demu, da ga demo testovi dokazuju) |
| Core 1.0.7 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/` | poslovna logika Worldline integracije: konekcija, sesije, statusi, capture/refund/cancel, ORM, logger | **nikad** |
| SDK 6.2.0 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/sdk-php/src/OnlinePayments/Sdk/` | zvanični Worldline PHP SDK: HTTP, HMAC potpis, modeli zahteva i odgovora | **nikad** |
| Worldline Direct API | test: `https://payment.preprod.direct.worldline-solutions.com`, live: `https://payment.direct.worldline-solutions.com` (`/home/veljkozbiljic/logeecom/plugin0/config/brand.json:4-5`) | prima zahteve za plaćanje, vodi Hosted Checkout stranu, čuva stanje plaćanja | — |
| Worldline Merchant Portal | test: `https://merchant-portal.preprod.direct.worldline-solutions.com` (`/home/veljkozbiljic/logeecom/plugin0/config/brand.json:7`) | trgovčev pogled na plaćanja kod Worldline-a | — |
| MySQL 8.0.40 | isti kontejner, baza `prestashop` | tabela plugina `ps_plugin0_entity` i PrestaShop tabele (`ps_orders`, `ps_log`, ...) | samo kroz kod plugina |

**Smer poziva, tačno:**

| Ko poziva | Koga | Kako | Primer |
|---|---|---|---|
| PrestaShop | plugin0 | hook metoda klase `Plugin0`, front controller, Symfony kontroler iz `/home/veljkozbiljic/logeecom/plugin0/config/routes.yml` | `Plugin0::hookPaymentOptions()` |
| plugin0 | demo sloj | pravi demo servis i zove mu metodu | `Services::paymentMethods()->list()` |
| demo sloj | plugin0 | preko demo interfejsa koje implementiraju klase iz `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/` | `PaymentMethodRepositoryInterface::all()` → `Plugin0\Adapter\PaymentMethodRepository::all()` |
| plugin0 | Core | fasade `AdminAPI`, `CheckoutAPI`, `OrderAPI`; direktno `Logger`, `RepositoryRegistry`, `ServiceRegister` | `CheckoutAPI::get()->payment('1')->updateOrderStatus(...)` |
| Core | plugin0 | interfejsi registrovani u `ServiceRegister` i `RepositoryRegistry` | `StatusUpdateService` zove `ShopOrderService::createShopOrder()` |
| Core | SDK | proxy klase Core-a (ili plugin0 zamena za dve od njih) | `HostedCheckoutProxy::createSession()` |
| SDK | Worldline | HTTPS sa HMAC potpisom | `POST /v2/{PSPID}/hostedcheckouts` |
| Worldline | browser kupca | HTTP preusmerenje | povratak na `https://8-2-4.prestashop.veljko.ngrok.app/en/module/plugin0/return?RETURNMAC=...&hostedCheckoutId=...` |
| Worldline | plugin0 (webhook) | **ne postoji**: front controller `webhook` nije napravljen | poglavlje 32 |

Prostim rečima: PrestaShop pita plugin, plugin pita Core, Core pita Worldline; kad Core-u nešto treba od
prodavnice, on pita plugin, nikad PrestaShop direktno.

---

## 3. Kako je projekat nastajao i gde je šta u git-u?

### 3.1 Commit po commit

Sve grane su jedan red commit-ova, jedan na drugom (provereno `git log` 2026-10-16):

| Commit | Datum | Grana na kojoj je poslednji | Tiket | Šta je dodato (glavni fajlovi, pune putanje u poglavlju 22) |
|---|---|---|---|---|
| `b9f1d33` | 2026-10-07 | — | MIDU-1215 | `/home/veljkozbiljic/logeecom/plugin0/plugin0.php`, `/home/veljkozbiljic/logeecom/plugin0/src/Bootstrap.php`, `/home/veljkozbiljic/logeecom/plugin0/src/Configuration.php`, `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/CoreInfoController.php`, `/home/veljkozbiljic/logeecom/plugin0/config/routes.yml`, `/home/veljkozbiljic/logeecom/plugin0/config/brand.json`, `/home/veljkozbiljic/logeecom/plugin0/composer.json` |
| `4cd3dea` | 2026-10-07 | — | — | `/home/veljkozbiljic/logeecom/plugin0/README.md` |
| `6838d2c` | 2026-10-09 | `feature/MIDU-1215` | MIDU-1215 | Configuration stranica (`ConfigurationController`, šablon), `BaseRepository`, `LogsRepository`, `LoggerService`, `Encryptor`, `MetadataProvider`, `StoreService`, `Installer` |
| `84f5709` | 2026-10-09 | `main` | — | merge PR #1 (MIDU-1215) u `main` |
| `66d2834` | 2026-10-09 | — | MIDU-1218 | `BaseRepository` loguje čitanja i upise; duplikati u `ps_log` se ne preskaču |
| `43e9e38` | 2026-10-09 | `feature/MIDU-1218` | MIDU-1218 | bočni meni (`/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/layout.html.twig`), Logs stranica (`LogsController`, `LogReader`) |
| `3aff0b0` | 2026-10-12 | — | Settings | prva Settings stranica (fajlovi u `/home/veljkozbiljic/logeecom/plugin0/src/Settings/`, kasnije obrisani) |
| `90a8caf` | 2026-10-14 | — | Payment methods | prva Payment methods stranica nad Core katalogom (`/home/veljkozbiljic/logeecom/plugin0/src/PaymentMethods/`, kasnije obrisano), `LanguageService`, `LogoUrlService`, `ShopPaymentService`, `ShopCountriesAndCurrencies`, `/home/veljkozbiljic/logeecom/plugin0/views/css/admin.css` |
| `7c0a6e3` | 2026-10-14 | — | Settings | izmene podešavanja |
| `76d6d76` | 2026-10-14 | `settings-and-payment_pages` | Settings + Payment methods | **preokret**: demo poslovni sloj u `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/`, adapteri `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/`, `/home/veljkozbiljic/logeecom/plugin0/src/Services.php`, `PaymentMethodEntity`, `PaymentMethodForm`, `CoreLogLevel`, `WorldlineProducts`, `AvailablePaymentMethods`, prvi `/home/veljkozbiljic/logeecom/plugin0/controllers/front/payment.php`, testovi, `/home/veljkozbiljic/logeecom/plugin0/phpunit.xml`; `/home/veljkozbiljic/logeecom/plugin0/src/PaymentMethods/*` i `/home/veljkozbiljic/logeecom/plugin0/src/Settings/*` obrisani |
| `87021ca` | 2026-10-15 | `feature/MIDU-1221` | MIDU-1221 | checkout: `PrestaShopCartProvider`, `ShopOrderService`, pravi `/home/veljkozbiljic/logeecom/plugin0/controllers/front/payment.php`, `/home/veljkozbiljic/logeecom/plugin0/controllers/front/return.php`, `/home/veljkozbiljic/logeecom/plugin0/views/templates/front/return.tpl` |
| `06a7b91` | 2026-10-16 | `feature/MIDU-1224` (tekuća) | MIDU-1224 | panel na narudžbini: `/home/veljkozbiljic/logeecom/plugin0/src/Order/*`, `OrderPaymentController`, `PaymentOperationEntity`, `HostedCheckoutProxy`, `PaymentsProxy`, `PaymentActionSetting`, izmena `StoreService`, testovi u `/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Order`, `/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Checkout`, `/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Settings/PaymentActionSettingTest.php` |

### 3.2 Grane

```
main ── 84f5709 (samo MIDU-1215)
          └─ feature/MIDU-1218 ── 43e9e38
                └─ settings-and-payment_pages ── 76d6d76
                      └─ feature/MIDU-1221 ── 87021ca
                            └─ feature/MIDU-1224 ── 06a7b91   ← ovde si
```

- U `main`-u je samo MIDU-1215. `87021ca` (checkout) **nije** u `main`-u (`git merge-base --is-ancestor` 2026-10-16).
- Svaka grana nosi sve ispod sebe. Ako se spajaju u `main`, redosled je odozdo nagore: 1218, pa
  `settings-and-payment_pages`, pa 1221, pa 1224.

### 3.3 Šta su tiketi tražili (iz beleški)

Tekstovi tiketa nisu u repozitorijumu; ovo je sažetak iz beleški prethodnih sesija, **iz beleški**:

| Tiket | Traži |
|---|---|
| MIDU-1215 | podići Core jednom po pokretanju platforme; implementirati Core `Configuration` (ime integracije, verzija platforme, ime i verzija plugina) i registrovati je u Core `ServiceRegister`; endpoint `GET .../worldline/core-info` koji te podatke čita iz `ServiceRegister`-a, ne iz Symfony DI |
| MIDU-1218 | Core repository interfejs (save, update, delete, select po filteru, count) nad tabelom platforme, sa migracijom; svaki operator filtera podržan ili glasno odbijen; Core logger u log platforme sa nivoom i kontekstom; bočni meni i Logs stranica iz dema (vreme, nivo, poruka, kontekst, filter po nivou, straničenje, najnovije prvo) |
| usmeno | "prenesi i ostale stranice iz demo projekta (Settings i Payment)" |
| MIDU-1221 | checkout kroz Core: sesija, preusmerenje, povratak, status narudžbine; dokumentovati kad nastaje narudžbina; iznos tačan do centa; otkazano/odbijeno nikad plaćeno; povratna adresa idempotentna; izmenjen payment ID nikad ne potvrđuje; status narudžbine se menja na jednom mestu (proverljivo grep-om); bez try/catch oko Core fasada |
| MIDU-1224 | panel "Merchant panel" na stranici narudžbine: capture, refund, cancel; validacija na serveru; log operacija sa ishodom; dozvole; 7 kriterijuma prihvatanja, po beleškama svi pokazani u browseru pre commit-a `06a7b91` |

### 3.4 Najvažnija odluka u istoriji projekta

2026-10-14 je promenjen pravac: **funkcionalnost demo projekta JESTE plugin, a Core je infrastruktura**. Prva
verzija Payment methods stranice je prikazivala Core-ov fiksni katalog Worldline proizvoda; to je proglašeno
pogrešnim putem i zamenjeno metodama koje admin sam pravi, kao u demu (commit `76d6d76`). Zato danas:

- metode plaćanja su demo zapisi (`Logeecom\PaymentMethods`) sačuvani kao Core ORM entitet u
  `ps_plugin0_entity`;
- Core-ov sopstveni modul za metode plaćanja (`AdminAPI::payment()`, `PaymentMethodConfigEntity`) se ne koristi;
  posledica toga je u poglavljima 16 i 33.

Prostim rečima: Core je ovde "motor i instalacije", a demo projekat je "nameštaj i raspored prostorija"
(**poređenje**).

---

# Deo II. Temelji: pojmovi bez kojih ostalo nema smisla

## 4. Kako PrestaShop zna za plugin0 i kada ga poziva?

### 4.1 Kako PHP nalazi klase plugina (autoload)

Običnim rečima: PHP ne zna sam gde je fajl klase `Plugin0\Bootstrap`. Composer pravi "mapu" (autoloader) koja
kaže: sve što počinje sa `Plugin0\` traži u folderu `/home/veljkozbiljic/logeecom/plugin0/src/`, sve što počinje sa `Logeecom\` traži u
`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/`. Kad se ta mapa učita, svaka klasa se nađe sama.

Tačno:

- Mapa je u `/home/veljkozbiljic/logeecom/plugin0/composer.json`: `"Plugin0\\": "src/"`, `"Logeecom\\": "lib/Logeecom/"`,
  a samo za testove `"Tests\\": "tests/"` (`autoload-dev`). Composer od toga pravi
  `/home/veljkozbiljic/logeecom/plugin0/vendor/autoload.php`.
- Plugin učitava tu mapu sam, u prvoj liniji koda: `require_once __DIR__ . '/vendor/autoload.php';`
  (`/home/veljkozbiljic/logeecom/plugin0/plugin0.php:5`).
- Symfony deo PrestaShop-a (admin rute) je učitava i sam: za svaki aktivan modul uključi
  `modules/<modul>/vendor/autoload.php` (`/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/app/AppKernel.php:235`).
- `"config": {"prepend-autoloader": false}` u `/home/veljkozbiljic/logeecom/plugin0/composer.json`: mapa plugina se registruje **posle** PrestaShop-ove.
  Kad obe imaju istu klasu (npr. `PhpParser\ParserFactory`, koju phpunit iz `require-dev` vuče u drugoj verziji),
  pobeđuje PrestaShop-ova. Bez toga je Module Manager pucao ("undefined method create of class
  PhpParser\ParserFactory", OKRUZENJE.md 4.1).
- `/home/veljkozbiljic/logeecom/plugin0/vendor/` je u `/home/veljkozbiljic/logeecom/plugin0/.gitignore`; posle novog `git clone` prvo `composer install`
  (komande u poglavlju 26 i OKRUZENJE.md 4.1).

Prostim rečima: bez `/home/veljkozbiljic/logeecom/plugin0/vendor/autoload.php` PHP ne bi znao ni za jednu klasu plugina, Core-a ni SDK-a.

### 4.2 Instalacija i deinstalacija

`Plugin0::install()` (`/home/veljkozbiljic/logeecom/plugin0/plugin0.php:39-50`) radi redom:

1. `\Plugin0\Bootstrap::boot()` (linija 43): podiže Core ručno, jer hook koji ga inače podiže
   (`actionDispatcherBefore`) u tom trenutku još nije registrovan (komentar na linijama 41-42).
2. `parent::install()` (linija 45): `PaymentModule::install()` i `Module::install()` upisuju modul u `ps_module` i
   redove ograničenja u `ps_module_currency`, `ps_module_country`, `ps_module_group`, `ps_module_carrier`
   (po komentaru u `/home/veljkozbiljic/logeecom/plugin0/plugin0.php:10-12`; tačno koja metoda puni koju tabelu
   nisam proveravao u PrestaShop kodu, **neprovereno**).
3. `\Plugin0\Infrastructure\Installer::createTables()` (linija 46): pravi tabelu `ps_plugin0_entity`
   (`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/Installer.php:14-33`, poglavlje 9).
4. `registerHook('actionDispatcherBefore')`, `registerHook('paymentOptions')`, `registerHook('displayAdminOrderMain')`
   (linije 47-49).

`&&` između koraka znači: prvi korak koji vrati `false` prekida instalaciju.

`Plugin0::uninstall()` (`/home/veljkozbiljic/logeecom/plugin0/plugin0.php:52-58`) podiže Core, zove
`Installer::dropTables()` (`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/Installer.php:35-40`,
`DROP TABLE IF EXISTS ps_plugin0_entity`), pa `parent::uninstall()`.

**Pazi:** Uninstall briše celu tabelu `ps_plugin0_entity`, a time i Worldline konekciju (kredencijale), sva
podešavanja, metode plaćanja, transakcije, log operacija i MonitoringLog. Narudžbine u `ps_orders` ostaju, ali
panel na njima više nema transakciju i neće se prikazati.

Stanje dev prodavnice (provereno u bazi 2026-10-16): modul ima registrovana tri hook-a (`actionDispatcherBefore`,
`paymentOptions`, `displayAdminOrderMain`); ograničenja modula su valute RSD, BZD, INR, MXN, EUR i zemlje FR, US,
RS. Po beleškama, dva od tri hook-a su dodata skriptom bez reinstalacije, jer bi reinstalacija obrisala
konekciju (**iz beleški**).

### 4.3 Sva mesta na kojima PrestaShop "ulazi" u plugin

Običnim rečima: PrestaShop ima "utičnice" (hook-ove) i "adrese" (rute, front controller-e). Plugin se prijavi na
utičnice i donese svoje adrese, i PrestaShop ga zove samo kroz njih (**poređenje**).

| Ulaz | Ko okida (PrestaShop) | Šta se zove u pluginu | Šta plugin radi |
|---|---|---|---|
| hook `actionDispatcherBefore` | front i legacy admin: `Hook::exec('actionDispatcherBefore', ...)` u `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/Dispatcher.php:351`; Symfony admin: `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/PrestaShopBundle/EventListener/ActionDispatcherLegacyHooksSubscriber.php:91` | `Plugin0::hookActionDispatcherBefore()`, `/home/veljkozbiljic/logeecom/plugin0/plugin0.php:75-78` | `Bootstrap::boot()` |
| hook `paymentOptions` | korak plaćanja: `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/checkout/CheckoutPaymentStep.php:83` → `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/checkout/PaymentOptionsFinder.php:54` | `Plugin0::hookPaymentOptions()`, `/home/veljkozbiljic/logeecom/plugin0/plugin0.php:90-111` | vraća po jednu `PaymentOption` za svaku metodu koja važi za korpu (poglavlje 16) |
| hook `displayAdminOrderMain` | stranica narudžbine: `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/PrestaShopBundle/Resources/views/Admin/Sell/Order/Order/view.html.twig:73` | `Plugin0::hookDisplayAdminOrderMain()`, `/home/veljkozbiljic/logeecom/plugin0/plugin0.php:124-166` | crta karticu "Worldline payment" (poglavlje 20) |
| front controller `payment` | POST na `https://8-2-4.prestashop.veljko.ngrok.app/en/module/plugin0/payment` | `Plugin0PaymentModuleFrontController::postProcess()`, `/home/veljkozbiljic/logeecom/plugin0/controllers/front/payment.php:29-84` | pravi Worldline sesiju i preusmerava kupca (poglavlje 17) |
| front controller `return` | GET na `https://8-2-4.prestashop.veljko.ngrok.app/en/module/plugin0/return?RETURNMAC=...&hostedCheckoutId=...` | `Plugin0ReturnModuleFrontController::postProcess()` i `initContent()`, `/home/veljkozbiljic/logeecom/plugin0/controllers/front/return.php:50-132` | ishod plaćanja, narudžbina, poruka kupcu (poglavlje 18) |
| Symfony admin rute | rute iz `/home/veljkozbiljic/logeecom/plugin0/config/routes.yml`, koje učitava `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/PrestaShopBundle/Routing/YamlModuleLoader.php` | kontroleri u `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/` | admin stranice i akcije panela (4.4) |
| dugme Configure u Module Manager-u | Module Manager | `Plugin0::getContent()`, `/home/veljkozbiljic/logeecom/plugin0/plugin0.php:63-68` | preusmerava na rutu `plugin0_configuration` |

Front controller-i postoje zato što su navedeni u `$this->controllers = ['payment', 'return'];`
(`/home/veljkozbiljic/logeecom/plugin0/plugin0.php:32`) i zato što klasa prati PrestaShop-ovo pravilo imena:
`<Modul><Kontroler>ModuleFrontController`, dakle `Plugin0PaymentModuleFrontController` i
`Plugin0ReturnModuleFrontController`. Sa friendly URL-ovima adresa je `/en/module/plugin0/<kontroler>`, bez njih
`index.php?fc=module&module=plugin0&controller=<kontroler>`. Oba oblika pravi `Link::getModuleLink()`.

### 4.4 Admin rute, tačne adrese i zaštita

**Tačna adresa admin rute.** U `/home/veljkozbiljic/logeecom/plugin0/config/routes.yml` putanja je, na primer, `/worldline/settings`. PrestaShop svakoj
ruti modula doda prefiks `/modules`: `$route->setPath('/modules' . $route->getPath());`
(`/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/PrestaShopBundle/Routing/YamlModuleLoader.php:108`),
osim kad ruta ima `_disable_module_prefix: true` (linija 104; plugin0 to nigde nema). Pravi URL je zato:

```
https://8-2-4.prestashop.veljko.ngrok.app/admin174kob088ranecckhxg/index.php/modules/worldline/settings?_token=...
```

Isti oblik je viđen u Apache access logu 2026-10-16:
`POST /admin174kob088ranecckhxg/index.php/modules/worldline/orders/16/refund?_token=...`.

**Sve rute** (`/home/veljkozbiljic/logeecom/plugin0/config/routes.yml`):

| Ime rute | Putanja u routes.yml | Metode | Kontroler::akcija | `_legacy_controller` | Linije |
|---|---|---|---|---|---|
| `api_plugin0_core_info` | `/worldline/core-info` | GET | `Plugin0\Controller\Admin\CoreInfoController::indexAction` | — | 4-8 |
| `plugin0_configuration` | `/worldline/configuration` | GET, POST | `ConfigurationController::indexAction` | `AdminPlugin0Configuration` | 9-14 |
| `plugin0_payment_methods` | `/worldline/payment-methods` | GET | `PaymentMethodsController::listAction` | `AdminPlugin0Configuration` | 17-22 |
| `plugin0_payment_method_create` | `/worldline/payment-methods/create` | GET, POST | `PaymentMethodsController::createAction` | `AdminPlugin0Configuration` | 23-28 |
| `plugin0_payment_method_edit` | `/worldline/payment-methods/{id}/edit` | GET, POST | `PaymentMethodsController::editAction` | `AdminPlugin0Configuration` | 29-36 |
| `plugin0_payment_method_active` | `/worldline/payment-methods/{id}/active` | POST | `PaymentMethodsController::setActiveAction` | `AdminPlugin0Configuration` | 37-44 |
| `plugin0_payment_method_delete` | `/worldline/payment-methods/{id}/delete` | POST | `PaymentMethodsController::deleteAction` | `AdminPlugin0Configuration` | 45-52 |
| `plugin0_settings` | `/worldline/settings` | GET, POST | `SettingsController::indexAction` | `AdminPlugin0Configuration` | 53-58 |
| `plugin0_logs` | `/worldline/logs` | GET | `LogsController::indexAction` | `AdminPlugin0Configuration` | 59-64 |
| `plugin0_order_capture` | `/worldline/orders/{orderId}/capture` | POST | `OrderPaymentController::captureAction` | `AdminOrders` | 68-75 |
| `plugin0_order_refund` | `/worldline/orders/{orderId}/refund` | POST | `OrderPaymentController::refundAction` | `AdminOrders` | 76-83 |
| `plugin0_order_cancel` | `/worldline/orders/{orderId}/cancel` | POST | `OrderPaymentController::cancelAction` | `AdminOrders` | 84-91 |

`{id}` i `{orderId}` moraju biti ceo broj bez vodeće nule (`'[1-9][0-9]{0,17}'`), pa adresa sa slovima uopšte ne
nađe rutu.

**Zaštita admin adresa, sloj po sloj:**

1. **Prijava.** Admin adresu može da otvori samo prijavljen zaposleni
   (`/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/Adapter/Security/Admin.php:90`, `onKernelRequest`).
2. **`_token` (zaštita od CSRF).** PrestaShop svaku admin adresu potpisuje `_token`-om zaposlenog i odbija adresu
   bez njega. Izuzetak su rute čije ime počinje sa `_` ili `api_`:
   `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/PrestaShopBundle/EventListener/TokenizedUrlsListener.php:91`.
   Zato se `api_plugin0_core_info` zove baš tako: može da se otvori običnim GET-om bez tokena (komentar u
   `/home/veljkozbiljic/logeecom/plugin0/config/routes.yml:1-3`). Twig funkcija `path()` token dodaje sama, pa
   forme u šablonima uvek šalju ispravnu adresu.
3. **Dozvola (pravo nad stranicom).** `_legacy_controller` kaže za koju admin "karticu" važe prava. Akcije panela
   imaju anotaciju `@AdminSecurity("is_granted('update', request.get('_legacy_controller'))", ...)`
   (`/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/OrderPaymentController.php:32`, `:40`, `:48`), pa
   zaposleni bez prava Edit na Orders dobija 403 pre ijedne linije plugina. Anotaciju obrađuje
   `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/PrestaShopBundle/EventListener/AccessDeniedListener.php:80`.
   Stranice Configuration, Payment methods, Settings i Logs **nemaju** `@AdminSecurity`, pa ih po kodu može
   otvoriti svaki prijavljen zaposleni; u browseru to nije probano, **neprovereno**.

**Zamka sa anotacijom.** Doctrine čita anotacije iz docblock-a. Kad je u opisu klase stajala reč `@AdminSecurity`
kao obična reč, Doctrine ju je pročitao kao praznu anotaciju i svaki klik na Capture je pucao sa "Unexpected
token end of expression" (2026-10-15). Zato komentar u
`/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/OrderPaymentController.php:22-25` namerno piše ime bez
`@`. Pravilo: u docblock-u nikad ne piši `@NekoIme` kao običan tekst.

Prostim rečima: admin stranica plugina radi samo za prijavljenog zaposlenog i samo sa ispravnim tokenom u adresi;
akcije na narudžbini traže i pravo izmene narudžbina.

---

## 5. Šta znači "podići Core" i zašto se to radi na svakom zahtevu?

### 5.1 Običnim rečima

Core je velika kutija sa alatom, ali pre upotrebe mora da se "složi": mora da nauči koja klasa radi koji posao
(ko piše u bazu, ko piše log, ko pravi narudžbinu, kako se zove brend, na koji Worldline server se ide). To
slaganje se zove **boot** (podizanje). Posle svakog HTTP zahteva PHP zaboravlja sve što je bilo u memoriji
(statičke promenljive, objekte), pa se Core podiže iznova na svakom zahtevu, ali samo jednom po zahtevu.

### 5.2 Tačno: `Plugin0\Bootstrap::boot()`

`/home/veljkozbiljic/logeecom/plugin0/src/Bootstrap.php:65-79`:

```php
public static function boot(): void
{
    if (self::$booted) {          // linija 67: drugi poziv u istom zahtevu ne radi ništa
        return;
    }
    static::bootstrap(             // linije 71-76: Core-ov ulaz
        static function () { return 'worldline'; },   // "koji brend"
        __DIR__ . '/../config/brand.json'               // adrese Worldline servera
    );
    self::$booted = true;          // linija 78
}
```

`static::bootstrap()` je Core metoda
`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/BootstrapComponent.php:169-176`.
Ona radi redom:

1. `WebhookMode::set($webhookMode)` (linija 171). plugin0 ne prosleđuje treći argument, pa važi **default**
   `WebhookMode::MANUAL` (linija 169): Worldline-u se webhook URL ne šalje u zahtevima.
2. `static::init()` (linija 172) →
   `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/BootstrapComponent.php:177-184`:
   1. `parent::init()` (linija 179) → Infrastructure
      `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/BootstrapComponent.php:31-36`:
      `initServices()` (33), `initRepositories()` (34), `initEvents()` (35);
   2. `initControllers()` (180);
   3. `initProxies()` (181);
   4. `initRequestProcessors()` (182, prazna metoda);
   5. `initBackgroundProcesses()` (183).
3. Registruje `ActiveBrandProviderInterface` sa resolverom i putanjom do `/home/veljkozbiljic/logeecom/plugin0/config/brand.json` (linija 173).

Pošto se sve zove sa `static::`, kad je klasa `Plugin0\Bootstrap`, umesto Core-ovih metoda izvršavaju se njene
verzije `initServices()`, `initRepositories()` i `initProxies()`; svaka prvo zove `parent::` (Core verziju), pa
doda svoje.

| Metoda u `/home/veljkozbiljic/logeecom/plugin0/src/Bootstrap.php` | Linije | Šta dodaje |
|---|---|---|
| `initServices()` | 81-126 | deset implementacija Core interfejsa (tabela u poglavlju 6) |
| `initProxies()` | 135-154 | zamena za Core `HostedCheckoutProxy` i omotač oko Core `PaymentsProxy` (poglavlje 21) |
| `initRepositories()` | 161-193 | `BaseRepository` za 17 klasa entiteta, `LogsRepository` za 2 (poglavlje 9) |

### 5.3 `ServiceRegister`: kako Core zna koju klasu da koristi

`ServiceRegister` je jedini "DI kontejner" Core-a: mapa **ključ → funkcija koja pravi objekat**
(`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/ServiceRegister.php`:
`registerService` linija 80, `getService` linija 64).

- Ključ je skoro uvek puno ime interfejsa, npr. `ShopOrderService::class`.
- **Poslednja registracija istog ključa važi.** Tako plugin "pregazi" Core implementaciju.
- Bez omotača `SingleInstance` (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/SingleInstance.php`)
  svaki `getService` pravi novi objekat; sa njim se objekat pravi jednom i pamti do kraja zahteva.
- Ništa se ne pravi pri boot-u. Objekat nastaje tek kad ga neko prvi put zatraži.

Mehanički, **pojednostavljeno**:

```php
// Core, u svom initProxies():
ServiceRegister::registerService(HostedCheckoutProxyInterface::class, fn () => new CoreHostedCheckoutProxy(...));
// plugin0, u svom initProxies(), POSLE parent::initProxies():
ServiceRegister::registerService(HostedCheckoutProxyInterface::class, fn () => new Plugin0HostedCheckoutProxy(...));

ServiceRegister::getService(HostedCheckoutProxyInterface::class); // → Plugin0 verzija: poslednja registracija važi
```

**Zašto zamena proxy-ja mora u `initProxies()`, a ne u `initServices()`.** Core registruje svoj
`HostedCheckoutProxy` u `initProxies()`
(`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/BootstrapComponent.php:417-419`),
a `initProxies()` se izvršava **posle** `initServices()` (redosled iz 5.2). Da je plugin svoju verziju
registrovao u `initServices()`, Core bi je odmah posle toga pregazio svojom:

```php
// POGREŠNO, pojednostavljeno:
initServices():  register(HostedCheckoutProxyInterface, Plugin0 verzija)   // 1. plugin
initProxies():   register(HostedCheckoutProxyInterface, Core verzija)      // 2. Core pregazi plugin
getService(HostedCheckoutProxyInterface)                                   // → Core verzija, zamena ne radi
```

Prostim rečima: Core je spisak "za ovaj posao zovi ovu klasu"; plugin dopisuje svoje stavke, a ko poslednji
upiše stavku, taj važi, pa plugin mora da piše posle Core-a.

### 5.4 Ko sve zove `boot()` i zašto toliko puta

| Mesto | Puna putanja:linija | Zašto ovde |
|---|---|---|
| hook `actionDispatcherBefore` | `/home/veljkozbiljic/logeecom/plugin0/plugin0.php:77` | glavni put: svaki front i admin zahtev |
| `install()` | `/home/veljkozbiljic/logeecom/plugin0/plugin0.php:43` | hook još nije registrovan |
| `uninstall()` | `/home/veljkozbiljic/logeecom/plugin0/plugin0.php:54` | isto, za svaki slučaj |
| `hookPaymentOptions()` | `/home/veljkozbiljic/logeecom/plugin0/plugin0.php:96` | sigurnost: hook ne sme da zavisi od toga da je neko drugi već podigao Core |
| `hookDisplayAdminOrderMain()` | `/home/veljkozbiljic/logeecom/plugin0/plugin0.php:131` | isto |
| svaka akcija admin kontrolera | npr. `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/ConfigurationController.php:28` | isto |
| front controller-i | `/home/veljkozbiljic/logeecom/plugin0/controllers/front/payment.php:31`, `/home/veljkozbiljic/logeecom/plugin0/controllers/front/return.php:52` | isto |

Ponovljen poziv ništa ne košta: `self::$booted` (`/home/veljkozbiljic/logeecom/plugin0/src/Bootstrap.php:59`) ga
završi u prvoj liniji.

**Gde se hook NE okida:** `bin/console` i webservice (komentar `/home/veljkozbiljic/logeecom/plugin0/plugin0.php:71-74`).
Svaka skripta koja koristi plugin mimo browsera mora sama da pozove `\Plugin0\Bootstrap::boot()` (primer skripte u
poglavlju 31.4).

### 5.5 Šta je `/home/veljkozbiljic/logeecom/plugin0/config/brand.json`

`/home/veljkozbiljic/logeecom/plugin0/config/brand.json:1-9`: kod brenda `worldline`, ime `Worldline`, API adrese
za live (`https://payment.direct.worldline-solutions.com`) i test
(`https://payment.preprod.direct.worldline-solutions.com`), adrese Merchant Portal-a za live i test i ime metode
`Worldline`. Čita ga Core `ActiveBrandProvider::getActiveBrand()`
(`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Branding/Brand/ActiveBrandProvider.php:25-30`).
Koja adresa se koristi zavisi od okruženja aktivne konekcije (Test ili Live sa Configuration stranice).

---

## 6. Kako Core traži stvari od plugina? (interfejsi koje plugin0 implementira)

### 6.1 Običnim rečima

Core ne zna da radi u PrestaShop-u. Kad mu nešto zatreba iz prodavnice, on pozove **interfejs**: ugovor koji kaže
"treba mi metoda sa ovim imenom i ovim argumentima", bez ikakvog koda. plugin0 piše klasu koja taj ugovor ispunjava
PrestaShop kodom i registruje je pod imenom interfejsa. Ovo je obrazac "ports and adapters": portovi su interfejsi
u Core-u, adapteri su klase plugina (**pojednostavljeno**, CORE.md 3).

### 6.2 Tabela: Core interfejs → klasa plugina

Sve Core putanje počinju sa
`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/`, ali su ovde
napisane cele, kako si tražio.

| # | Core interfejs (puna putanja:linija) | Klasa plugina (puna putanja) | Registrovano (`/home/veljkozbiljic/logeecom/plugin0/src/Bootstrap.php`) | Ko u Core-u je zove |
|---|---|---|---|---|
| 1 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/Configuration/Configuration.php:12` (apstraktna klasa; nasleđuje `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/Configuration/Configuration.php`) | `/home/veljkozbiljic/logeecom/plugin0/src/Configuration.php` | 85-87 | `ConfigurationManager`, `LoggerConfiguration`, `AsyncProcessStarterService`, `CoreInfoController` plugina |
| 2 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/Serializer/Serializer.php` | Core-ov `JsonSerializer` (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/Serializer/Concrete/JsonSerializer.php`) | 89-91 | red zadataka (plugin0 ga ne koristi) |
| 3 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/Logger/Interfaces/ShopLoggerAdapter.php:10` (metoda `logMessage` u `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/Logger/Interfaces/LoggerAdapter.php:20`) | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/LoggerService.php` | 93-95 | `Logger::logMessage()`, `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/Logger/Logger.php:129` |
| 4 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Integration/Encryption/Encryptor.php:10` | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/Encryptor.php` | 97-99 | `ConnectionConfigRepository` (šifruje API Key, API Secret, Webhook Key, Webhook Secret) |
| 5 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Integration/Metadata/MetadataProviderInterface.php:10` | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/MetadataProvider.php` | 101-103 | `MerchantClientFactory` (podaci o platformi u zaglavlju ka Worldline-u) |
| 6 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Integration/Stores/StoreService.php:13` | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/StoreService.php` | 105-107 | `GeneralSettingsService::getPaymentSettings()` (podrazumevano mapiranje statusa), domenski `StoreService` |
| 7 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Integration/Language/LanguageService.php:11` | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/LanguageService.php` | 110-112 | AdminConfig `PaymentService`, `LanguageController` |
| 8 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Integration/Logo/LogoUrlService.php:10` | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/LogoUrlService.php` | 114-116 | AdminConfig `PaymentService`, `HostedTokenizationService` |
| 9 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Integration/Payment/ShopPaymentService.php:11` | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/ShopPaymentService.php` | 118-120 | AdminConfig `PaymentController::save/enable`, `DisconnectService` |
| 10 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Integration/ShopOrderService.php:12` | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/ShopOrderService.php` | 123-125 | **samo** `StatusUpdateService::updateOrderStatus()` |
| 11 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/Proxies/HostedCheckoutProxyInterface.php:16` | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/HostedCheckoutProxy.php` (zamena Core-ove) | 139-146 (u `initProxies`) | `HostedCheckoutService::createSession()` |
| 12 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/Proxies/PaymentsProxyInterface.php:19` | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/PaymentsProxy.php` (omotač oko Core-ove) | 151-153 (u `initProxies`) | `StatusUpdateService`, `OrderService`, `WebhookLogsService`, `HostedTokenizationService`, plus `PaymentManager::reconcileLog()` plugina |
| 13 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/ORM/Interfaces/RepositoryInterface.php` i `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/ORM/Interfaces/ConditionallyDeletes.php:18` | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/Repository/BaseRepository.php` | 187-189 (`RepositoryRegistry::registerRepository`) | svi Core repozitorijumi u `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/DataAccess/` |
| 14 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Monitoring/Repositories/RepositoryWithAdvancedSearchInterface.php` | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/Repository/LogsRepository.php` | 191-192 | `MonitoringLogRepository`, `WebhookLogRepository` |
| 15 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Checkout/Cart/CartProvider.php:10` | `/home/veljkozbiljic/logeecom/plugin0/src/Checkout/PrestaShopCartProvider.php` | ne registruje se; predaje se u zahtevu (`/home/veljkozbiljic/logeecom/plugin0/controllers/front/payment.php:65`) | `HostedCheckoutService` i transformeri koji iz korpe prave Worldline "order" |

**Šta nije implementirano** (i šta bi se desilo kad bi Core to zatražio):

| Core interfejs | Puna putanja | Posledica |
|---|---|---|
| `VersionService` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Integration/Version/VersionService.php:11` | `AdminAPI::version()` bi vratio grešku; plugin ga ne zove |
| `QueueItemRepository` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/ORM/Interfaces/QueueItemRepository.php` | red zadataka ne radi: Core disconnect (`DisconnectService`), pozadinska provera statusa, auto-capture, čišćenje logova; plugin te puteve izbegava (komentar `/home/veljkozbiljic/logeecom/plugin0/src/Bootstrap.php:157-160`) |
| `DefaultLoggerAdapter` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/Logger/Interfaces/DefaultLoggerAdapter.php` | opcioni; Core ga zove samo ako je `defaultLoggerEnabled` uključen (**default** isključen) |

Mehanički, šta se desi kad registracija fali (**pojednostavljeno**):

```php
// Core, StatusUpdateService se pravi u BootstrapComponent.php:207 i traži ShopOrderService:
ServiceRegister::getService(ShopOrderService::class);
// bez registracije: ServiceNotRegisteredException('Service of type "...ShopOrderService" is not registered.')
// ErrorHandlingAspect je uhvati i fasada vrati grešku "general.unhandled" umesto da obradi povratak kupca
```

### 6.3 Svaka implementacija ukratko

1. **`Plugin0\Configuration`** (`/home/veljkozbiljic/logeecom/plugin0/src/Configuration.php`). Core pita: kako se
   zove platforma (`'PrestaShop'`, linija 37), koja je verzija (`_PS_VERSION_`, linija 42), ime i verzija plugina
   (iz `Module::getInstanceByName('plugin0')`, linije 45-53 i 83-91), adresa za pozadinske procese (`asyncprocess`
   module link, linija 57; taj front controller **ne postoji**, poglavlje 32). Plugin uz to koristi istu klasu za
   svoje ključeve u bazi (`getStoredValue`, `storeValue`, `removeValue`, linije 66-81) i konstante ključeva
   `minLogLevel`, `rowsPerPage`, `defaultCurrency`, `cardAuthorizationMode` (linije 19-31). Linija 33
   (`protected static ?Singleton $instance = null;`) je obavezna: bez nje bi klasa delila statičko polje sa
   drugim Singleton podklasama Core-a, a `getInstance()` bi bacio `RuntimeException('Invalid singleton
   instance.')` (CORE.md 4.2).
2. **`LoggerService`**: Core poruke → PrestaShop log `ps_log` (poglavlje 10).
3. **`Encryptor`** (`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/Encryptor.php:15-30`): PrestaShop
   `PhpEncryption` sa ključem `_NEW_COOKIE_KEY_` (iz `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/app/config/parameters.php` PrestaShop-a). Neuspelo
   dešifrovanje vraća `''` (linija 29). Posledica: kad se ključ promeni (npr. nova instalacija PrestaShop-a sa
   istom bazom), sačuvane tajne postaju prazne.
4. **`MetadataProvider`** (`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/MetadataProvider.php:15-27`):
   `'PrestaShop'`, `_PS_VERSION_`, verzija modula, osnovni URL prodavnice.
5. **`StoreService`** (`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/StoreService.php`): domen, spisak
   prodavnica, podrazumevana prodavnica (`PS_SHOP_DEFAULT`), statusi narudžbine i **podrazumevano mapiranje
   Worldline stanja na PrestaShop statuse** (linije 56-67), detaljno u poglavlju 19.
6. **`LanguageService`** (`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/LanguageService.php:23-51`):
   aktivni jezici prodavnice sa ISO kodom velikim slovima (`EN`, `SR`), jer Core tako ključuje prevode; logo jezika
   je PrestaShop zastavica `img/l/{id_lang}.jpg`.
7. **`LogoUrlService`** (`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/LogoUrlService.php:27-41`):
   `/home/veljkozbiljic/logeecom/plugin0/views/img/payment-methods/{productId}.svg` ili `.png` ako fajl postoji, inače
   `/home/veljkozbiljic/logeecom/plugin0/views/img/payment-method.svg`. Folder
   `/home/veljkozbiljic/logeecom/plugin0/views/img/payment-methods/` je danas prazan (samo `.gitkeep`).
8. **`ShopPaymentService`** (`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/ShopPaymentService.php:22-47`):
   samo upisuje INFO zapis u log. Core ga zove kad sačuva ili uključi svoju konfiguraciju Worldline metode i pri
   disconnect-u; plugin0 metode plaćanja nisu Core metode, pa nema šta da se menja. Registrovan je jer bez njega
   Core ne može ni da konstruiše `PaymentService` i `DisconnectService` (komentar
   `/home/veljkozbiljic/logeecom/plugin0/src/Bootstrap.php:109`).
9. **`ShopOrderService`**: jedino mesto koje pravi narudžbinu i menja joj status (poglavlje 19).
10. **`HostedCheckoutProxy`** i **`PaymentsProxy`**: poglavlje 21.
11. **`BaseRepository`** i **`LogsRepository`**: poglavlje 9.
12. **`PrestaShopCartProvider`**: poglavlje 17.

Prostim rečima: Core je "mozak" koji ne zna gde je; svaki put kad treba nešto da uradi u prodavnici, pozove jednu
od klasa iz tabele 6.2 (četrnaest od petnaest su klase plugina; jedino serializer je Core-ov).

---

## 7. Kako plugin0 traži stvari od Core-a, i zašto Core ne baca greške?

### 7.1 Fasade: tri "šaltera"

Običnim rečima: Core ima nekoliko ulaza za spoljni svet, nalik šalterima (**poređenje**): jedan za admin poslove,
jedan za plaćanje, jedan za operacije nad narudžbinom. plugin0 koristi tri:

| Fasada | Puna putanja | Metode koje plugin0 koristi |
|---|---|---|
| `AdminAPI` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/ApiFacades/AdminConfig/AdminAPI/AdminAPI.php` | `get()` (34), `connection($storeId)` (44), `generalSettings($storeId)` (68) |
| `CheckoutAPI` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/ApiFacades/PaymentProcessor/CheckoutAPI/CheckoutAPI.php` | `get()` (27), `payment($storeId)` (59), `hostedCheckout($storeId)` (68) |
| `OrderAPI` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/ApiFacades/Order/OrderAPI/OrderAPI.php` | `get()` (28), `orders($storeId)` (38), `capture($storeId)` (42), `cancel($storeId)` (46), `refund($storeId)` (50) |

Četvrta fasada, `WebhookAPI`, postoji u Core-u, ali je plugin ne koristi (nema webhook-a).

**Svi pozivi fasada u pluginu:**

| Poziv | Gde u pluginu | Core kontroler koji odgovara |
|---|---|---|
| `AdminAPI::get()->connection($storeId)->getConnectionConfig()` | `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/ConnectedController.php:18`, `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/ConfigurationController.php:254` | `ConnectionAPI\Controller\ConnectionController` |
| `AdminAPI::get()->connection($storeId)->connect(new ConnectionRequest(...))` | `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/ConfigurationController.php:119-121` i `:228-230` | isto |
| `AdminAPI::get()->generalSettings($storeId)->getGeneralSettings()` | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/PaymentActionSetting.php:70` i `:96-97` | `GeneralSettingsAPI\Controller\GeneralSettingsController` |
| `...->generalSettings($storeId)->savePaymentSettings(new PaymentSettingsRequest(...))` | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/PaymentActionSetting.php:112-127` | isto |
| `CheckoutAPI::get()->hostedCheckout($storeId)->createSession(...)` | `/home/veljkozbiljic/logeecom/plugin0/controllers/front/payment.php:63-69` | `CheckoutAPI\Controller\HostedCheckoutController` |
| `CheckoutAPI::get()->payment($storeId)->updateOrderStatus(...)` | `/home/veljkozbiljic/logeecom/plugin0/controllers/front/return.php:68`, `/home/veljkozbiljic/logeecom/plugin0/src/Order/PaymentManager.php:84` i `:170` | `CheckoutAPI\Controller\PaymentController` (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/ApiFacades/CheckoutAPI/Controller/PaymentController.php:45`) |
| `...->payment($storeId)->getPaymentOutcome(...)` | `/home/veljkozbiljic/logeecom/plugin0/controllers/front/return.php:75` i `:85` | isto, linija 40 |
| `...->payment($storeId)->startWaitingForOutcome(...)` | `/home/veljkozbiljic/logeecom/plugin0/controllers/front/return.php:78` | isto, linija 26 |
| `...->payment($storeId)->getPaymentTransaction($cartId)` | `/home/veljkozbiljic/logeecom/plugin0/src/Order/PaymentManager.php:195` | isto, linija 50 |
| `OrderAPI::get()->orders($storeId)->getDetails($merchantReference)` | `/home/veljkozbiljic/logeecom/plugin0/src/Order/PaymentManager.php:94` i `:125` | `OrdersAPI\Controller\OrderController` → `OrderService::getDetails()` |
| `OrderAPI::get()->capture/refund/cancel($storeId)->handle(...)` | `/home/veljkozbiljic/logeecom/plugin0/src/Order/PaymentManager.php:184`, `:186`, `:188` | `CaptureController`, `RefundController`, `CancelController` |

`$storeId` je uvek ID PrestaShop prodavnice kao tekst (`(string) $this->context->shop->id`, u dev prodavnici `'1'`).

### 7.2 Šta fasada radi oko svakog poziva

Svaki poziv metode kroz fasadu prolazi kroz tri omotača ("aspekta"), redom spolja ka unutra
(`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/ApiFacades/PaymentProcessor/CheckoutAPI/CheckoutAPI.php:61`):

1. `ErrorHandlingAspect`: hvata svaku grešku.
2. `TenantContextAspect`: postavlja tenant (u jednotenantskom režimu prazan string).
3. `StoreContextAspect`: postavlja `StoreContext` na `$storeId`, da Core repozitorijumi znaju za koju prodavnicu
   čitaju i pišu.

### 7.3 Zašto Core "ne baca greške"

`ErrorHandlingAspect::applyOn()`
(`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/ApiFacades/Aspects/ErrorHandlingAspect.php:22-35`):

- hvata `BaseTranslatableException` (linija 26): upiše ERROR u log (komponenta `Core`) i vrati
  `TranslatableErrorResponse`;
- hvata **svaki** drugi `Throwable` (linija 29): upiše "Unhandled error occurred." i vrati
  `TranslatableErrorResponse` sa kodom `general.unhandled`;
- nikad ne baca dalje (linija 34 vraća odgovor).

Zato plugin posle svakog poziva fasade proverava `isSuccessful()`. Mehanički, **pojednostavljeno**:

```php
$response = CheckoutAPI::get()->hostedCheckout('1')->createSession($request);
// Worldline je odbio, Core je pukao, konekcije nema: NEMA izuzetka; $response je TranslatableErrorResponse
if (!$response->isSuccessful()) {
    $error = $response->toArray();   // ['statusCode' => 400, 'errorCode' => '...', 'errorMessage' => '...', ...]
    // poruka kupcu; detalji su već u logu (komponenta Core)
}
```

**Posebno: `void` metode.** `startWaitingForOutcome()` ne vraća ništa kad uspe, pa fasada vrati `null`; kad ne
uspe, vrati error odgovor. Zato `/home/veljkozbiljic/logeecom/plugin0/controllers/front/return.php` proverava baš ovako
(`/home/veljkozbiljic/logeecom/plugin0/controllers/front/return.php:78-83`):

```php
$waiting = $payment->startWaitingForOutcome($paymentId, $returnMac);
if ($waiting instanceof Response && !$waiting->isSuccessful()) { /* greška */ }
```

**Zašto u front controller-ima nema `try/catch` oko fasada.** Tiket MIDU-1221 to traži, a nije ni potrebno: fasada
grešku već hvata, loguje i vraća kao odgovor (komentar
`/home/veljkozbiljic/logeecom/plugin0/controllers/front/return.php:21`).

Prostim rečima: Core nikad ne "pukne" prema pluginu; uvek vrati odgovor, a plugin mora da pita "da li je uspelo?".

### 7.4 Kad plugin zaobiđe fasadu, sam postavlja prodavnicu

Na tri mesta plugin zove Core servis direktno, mimo fasade. Tada nema `StoreContextAspect`-a, pa plugin sam
postavlja prodavnicu sa `StoreContext::doWithStore($storeId, $callable)`:

| Mesto | Šta zove | Zašto mimo fasade |
|---|---|---|
| `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/ConfigurationController.php:202-204` | `ConnectionConfigRepositoryInterface::disconnect()` | Core-ov disconnect (`generalSettings()->disconnect()`) traži red zadataka koji ne postoji (poglavlje 12) |
| `/home/veljkozbiljic/logeecom/plugin0/src/Order/PaymentManager.php:212-214` | `PaymentsProxyInterface::getPaymentDetails()` | treba spisak Worldline operacija, koji fasada ne vraća (poglavlje 20) |
| `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/CoreInfoController.php:15` | `ServiceRegister::getService(Configuration::CLASS_NAME)` | tiket MIDU-1215 traži čitanje iz `ServiceRegister`-a; ne treba prodavnica |

Bez `doWithStore` bi `storeId` u Core repozitorijumima bio `''`, pa bi upit tražio redove prodavnice `''` i ne bi
našao ništa (CORE.md, zamka 3).

---

## 8. Kako Core razgovara sa Worldline-om?

### 8.1 Običnim rečima

Za svaki razgovor sa Worldline-om Core prvo napravi "klijenta": uzme sačuvane kredencijale aktivnog okruženja,
izabere adresu servera (test ili live) i podesi potpisivanje. Svaki zahtev se potpiše tajnim ključem (API Secret),
pa Worldline zna da zahtev stvarno šalje vlasnik naloga.

### 8.2 Tačno

`MerchantClientFactory::get()`
(`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/Sdk/MerchantClientFactory.php:34-48`):

1. Uzme aktivnu konekciju (`ActiveConnectionProvider`; ona čita `ConnectionConfig` red iz `ps_plugin0_entity` i
   **pamti ga do kraja zahteva**).
2. Izabere API adresu: live (linija 54) ili test (linija 56) iz `/home/veljkozbiljic/logeecom/plugin0/config/brand.json`.
3. Napravi SDK `V1HmacAuthenticator` (linija 45): svaki zahtev dobija zaglavlje `Authorization: GCS v1HMAC:<apiKey>:<potpis>`.
4. Vrati klijenta za PSPID aktivnih kredencijala (`$client->merchant($pspId)`, linija 48).

### 8.3 Koje Worldline pozive plugin pokreće

| Radnja u pluginu | Worldline poziv (preko Core-a i SDK-a) | Kada |
|---|---|---|
| Save na Configuration stranici | `services()->testConnection()` | provera kredencijala pre čuvanja |
| PLACE ORDER (`/home/veljkozbiljic/logeecom/plugin0/controllers/front/payment.php`) | prvo `GET /v2/{PSPID}/products?countryCode=...&amount=...&currencyCode=...`, pa `POST /v2/{PSPID}/hostedcheckouts` | Core pre sesije pita koje proizvode Worldline nudi za korpu (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/Services/HostedCheckout/HostedCheckoutService.php:54` → `PaymentMethodService::getAvailablePaymentMethods()`, `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/Services/PaymentMethod/PaymentMethodService.php:52`), pa pravi sesiju za Hosted Checkout stranu |
| povratak kupca (`/home/veljkozbiljic/logeecom/plugin0/controllers/front/return.php`), svaki `updateOrderStatus` | `GET /v2/{PSPID}/payments/{paymentId}/details` | čitanje stanja plaćanja |
| panel na narudžbini, `getDetails` | `GET /v2/{PSPID}/payments/{paymentId}/details` | dostupni iznosi za capture/refund/cancel |
| panel, `reconcileLog` | `GET /v2/{PSPID}/payments/{paymentId}/details` | ishod ranijih operacija |
| dugme Capture / Refund / Cancel | `POST /v2/{PSPID}/payments/{paymentId}/capture`, `/refund`, `/cancel` | operacija |

Oblik putanje `/v2/{PSPID}/...` se vidi u `MonitoringLog` redovima u bazi (2026-10-16, npr.
`/v2/logeecom35/payments/10300628270_0`). Sandbox nalog (PSPID) je dakle `logeecom35`; po beleškama ga deli još
neko (plaćanja sa referencom `WC-...` iz WooCommerce plugina, **iz beleški**).

Jedno otvaranje panela na narudžbini zato pošalje najmanje **tri** `GET .../details` zahteva (`updateOrderStatus`,
`reconcileLog`, `getDetails`; poglavlje 20), a `OrderService::getDetails()` uz to pošalje još po jedan
`GET /v2/{PSPID}/payments/{id}` za svaku operaciju u statusu `PENDING_CAPTURE` ili `CAPTURED`, odnosno jedan kad
takvih nema
(`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Order/Services/Order/OrderService.php:40-53`).
U `MonitoringLog`-u od 2026-10-16 11:06:15 (narudžbina 16) to izgleda ovako: `GET .../payments/10300628270_1`,
`_2` i `_3` (operacije naplate) vraćaju **404**, a `GET .../payments/10300628270_0` vraća 200; Core preskoči
operacije za koje dobije 404 i uzme samo plaćanje `_0`. Za panel to nije greška, samo višak zahteva.

### 8.4 MonitoringLog: kad Core snima ceo razgovor

Kad je u Core `LogSettings` uključen `debugMode`, Core svaki zahtev i odgovor upisuje kao red tipa `MonitoringLog`
u `ps_plugin0_entity`
(`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/Sdk/CommunicatorLoggerHelper.php:18-56`;
greške se upisuju uvek). SDK pre upisa sakriva osetljiva polja (CORE.md 6.3).

Stanje dev prodavnice 2026-10-16 (baza): `LogSettings` za prodavnicu 1 i mode `test` ima `"debugMode":true`, a
tabela ima 287 `MonitoringLog` redova. Plugin nema stranicu za ovo podešavanje; po beleškama je uključeno skriptom
2026-10-15 radi istrage (**iz beleški**). Kad istraga nije potrebna, treba ga isključiti (poglavlje 36).

Prostim rečima: Worldline-u se uvek ide preko Core-a i SDK-a, sa potpisom; plugin nikad ne šalje HTTP zahtev
Worldline-u sam.

---

## 9. Gde se čuvaju podaci?

### 9.1 Običnim rečima

Plugin ima **jednu** sopstvenu tabelu, `ps_plugin0_entity`. U nju staju sve vrste zapisa: konekcija, podešavanja,
metode plaćanja, transakcije, log operacija. Svaki red ima kolonu `type` (koja je vrsta zapisa), nekoliko kolona po
kojima se pretražuje (`index_1` do `index_9`) i kolonu `data` u kojoj je ceo zapis kao JSON. Narudžbine, kupci,
korpe i log su u PrestaShop-ovim tabelama.

### 9.2 Tabela `ps_plugin0_entity`

Pravi je `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/Installer.php:14-33`:

| Kolona | Tip | Značenje |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT`, primarni ključ | ID zapisa |
| `type` | `VARCHAR(128) NOT NULL` | vrsta entiteta, npr. `PaymentTransaction` |
| `index_1` … `index_9` | `VARCHAR(255) NULL` | vrednosti po kojima se pretražuje, redom kako ih entitet navodi u `IndexMap`-u |
| `data` | `LONGTEXT NOT NULL` | ceo entitet kao JSON, sa `class_name` |

Ključevi: `(type, index_1)`, `(type, index_2)`, `(type, index_3)`; `utf8mb4_unicode_ci`. Broj indeksnih kolona je
konstanta `BaseRepository::INDEX_COLUMNS = 9`
(`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/Repository/BaseRepository.php:26`), jer najveći Core
entiteti (`QueueItem`, `PaymentTransactionEntity`) imaju po 9 indeksa.

**Kako brojevi izgledaju u indeks kolonama.** Indeks kolone su tekst, a tekst se poredi slovo po slovo. Zato Core
cele brojeve upisuje sa vodećim nulama na 11 mesta (`IndexHelper`, CORE.md 4.5), pa `'00000000009' < '00000000010'`
važi kao i za brojeve. U bazi zato vidiš `statusCode = 00000000009`, `sortOrder = 00000000001`. Logičke vrednosti
su `'1'` i `'0'`.

### 9.3 Koje vrste redova postoje

Broj redova po vrsti u dev bazi 2026-10-16: `Configuration` 2, `ConnectionConfig` 1, `LogSettings` 1,
`MonitoringLog` 287, `PaymentMethod` 2, `PaymentOperation` 12, `PaymentSettingsEntity` 1, `PaymentTransaction` 13.

| `type` | Klasa entiteta (puna putanja) | Indeksi redom (`index_1`, `index_2`, ...) | Šta čuva |
|---|---|---|---|
| `Configuration` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/Configuration/ConfigEntity.php` | name, context | ključ = vrednost; plugin ključevi: `minLogLevel`, `rowsPerPage`, `defaultCurrency`, `cardAuthorizationMode` (danas sačuvani `defaultCurrency = RSD` i `cardAuthorizationMode = PRE_AUTHORIZATION`; ostala dva nisu, pa važe default-i) |
| `ConnectionConfig` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/DataAccess/Connection/ConnectionConfigEntity.php` | storeId | aktivno okruženje i kredencijali za test i live; tajne šifrovane `Encryptor`-om, PSPID nije |
| `PaymentSettingsEntity` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/DataAccess/GeneralSettings/PaymentSettingsConfigEntity.php` | storeId, mode | Core `PaymentSettings`: payment action, automatic capture, broj pokušaja, mapiranje statusa (danas `FINAL_AUTHORIZATION`) |
| `LogSettings` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/DataAccess/GeneralSettings/LogSettingsEntity.php` | storeId, mode | `debugMode`, `logRecordsLifetime` (danas `true`, 14) |
| `PaymentTransaction` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/DataAccess/PaymentTransaction/PaymentTransactionEntity.php` | storeId, transactionId, merchantReference, returnHmac, statusCode, createdAtTimestamp, captureAtTimestamp, updatedAtTimestamp, paymentLinkId | jedno plaćanje: ID korpe, Worldline payment ID, RETURNMAC, poslednji poznat status |
| `PaymentTransactionLock` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/DataAccess/PaymentTransaction/PaymentTransactionLockEntity.php` | storeId, transactionId, merchantReference, lockTimestamp | kratkotrajna Core brava pri pravljenju narudžbine (30 s) |
| `MonitoringLog` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/DataAccess/Monitoring/MonitoringLog.php` | storeId, mode, requestId, orderId, paymentNumber, message, createdAt, expiresAt | zahtev i odgovor ka Worldline-u (8.4) |
| `PaymentMethod` | `/home/veljkozbiljic/logeecom/plugin0/src/Entity/PaymentMethodEntity.php` (plugin) | code, type, isActive, sortOrder | metoda plaćanja iz dema (poglavlje 13) |
| `PaymentOperation` | `/home/veljkozbiljic/logeecom/plugin0/src/Entity/PaymentOperationEntity.php` (plugin) | orderId, createdAt | jedan pokušaj capture/refund/cancel (poglavlje 20) |

Repozitorijum je registrovan i za `Process`, `LogData`, `DisconnectTime`, `PayByLinkSettingsEntity`,
`WebhookSettingsEntity`, `PaymentLinkEntity`, `PaymentMethodConfigEntity`, `ProductTypeEntity`, `TokenEntity` i
`WebhookLog` (`/home/veljkozbiljic/logeecom/plugin0/src/Bootstrap.php:165-192`), ali takvih redova danas nema.

### 9.4 `BaseRepository`: kako Core upit postane SQL

`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/Repository/BaseRepository.php` implementira Core ugovor
(`select`, `selectOne`, `count`, `save`, `update`, `delete`, `deleteWhere`). Jedna instanca radi za jednu klasu
entiteta: `RepositoryRegistry::getRepository($klasa)` je napravi i pozove `setEntityClass()`
(`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/ORM/RepositoryRegistry.php:34`).

Primer: Core traži transakciju po payment ID-ju i RETURNMAC-u
(`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/DataAccess/PaymentTransaction/PaymentTransactionRepository.php:94-110`).
Mehanički, **pojednostavljeno**:

```php
// Core:
$filter = (new QueryFilter())
    ->where('storeId', '=', '1')
    ->where('transactionId', '=', '10300628270')
    ->where('returnHmac', '=', 'c625807e-85e0-4a80-9af9-dbf33991f061');
$repository->selectOne($filter);

// BaseRepository::buildSelectQuery() + buildWhere() + conditionToSql() prave SQL:
// SELECT id, data FROM ps_plugin0_entity
//  WHERE type = 'PaymentTransaction'
//    AND ((index_1 = '1' AND index_2 = '10300628270' AND index_4 = 'c625807e-...'))
//  LIMIT 1
```

Pravila koja `BaseRepository` sprovodi:

| Pravilo | Gde | Zašto |
|---|---|---|
| Filtrira i sortira samo po `id` i po poljima iz `IndexMap`-a; inače `QueryFilterInvalidParamException` ("Field X is not indexed!") | `buildWhere` 196-223, `applyOrderAndLimit` 258-280 | tiket MIDU-1218: "svaki operator podržan ili glasno odbijen" |
| `where()` dodaje u grupu, `orWhere()` otvara novu grupu: `(A AND B) OR (C AND D)` | 196-223 | Core tako zamišlja `orWhere` |
| Vrednost filtera se kastuje isto kao vrednost u koloni (`IndexHelper::castFieldValue`) | `conditionToSql` 225-256 | inače `5` i `'00000000005'` ne bi bili jednaki |
| `IN ()` sa praznom listom postaje `0 = 1`, `NOT IN ()` postaje `1 = 1` | 242-244 | prazna lista u SQL-u je sintaksna greška |
| Prazan string u indeksu ide kao SQL `''`, ne kao `NULL` | `indexValue` 314-325 | PrestaShop `Db::insert(..., true)` bi `''` pretvorio u `NULL`, a Core koristi `''` kao pravu vrednost (kontekst tenanta) |
| `save()` baca `RuntimeException` kad INSERT ne uspe | 88-108 | Core sam ne proverava rezultat |
| `update()` i `delete()` vraćaju `bool` i neuspeh upišu kao ERROR u log | 110-139, 377-388 | Core ignoriše njihov rezultat (CORE.md, zamka 6) |
| Pri čitanju pravi instancu klase iz `class_name` u JSON-u | `rowsToEntities` 327-345 | tako se vraća tačna klasa entiteta |
| Svako čitanje i pisanje upiše DEBUG zapis (komponenta `Repository`), bez kolone `data` | `log` 355-374 | tiket MIDU-1218: "Core radnja proverljiva iz loga"; `data` ne ide u log jer sadrži šifrovane kredencijale |
| Zaštita od beskonačne petlje: `self::$isLogging` | 32, 357-373 | Logger čita podešavanje kroz isti repozitorijum, a to čitanje bi opet logovalo |

`LogsRepository` (`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/Repository/LogsRepository.php`) je
`BaseRepository` plus dve metode koje Core traži za `MonitoringLog` i `WebhookLog`: `getLogs()` (18-26) i
`countLogs()` (28-31), sa pretragom po `orderId` ili `paymentNumber` (33-55).

### 9.5 PrestaShop tabele koje plugin čita ili menja

| Tabela | Šta plugin radi | Gde |
|---|---|---|
| `ps_orders` | čita `id_order` po `id_cart` i `current_state` (bez keša); nove redove pravi `PaymentModule::validateOrder()` | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/ShopOrderService.php:191-214` |
| `ps_order_history` | čita da li je narudžbina već bila u statusu; nove redove pravi `OrderHistory::changeIdOrderState()` + `addWithemail()` | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/ShopOrderService.php:153-156`, `:199-206` |
| `ps_order_payment` | ne piše direktno; PrestaShop upiše uplatu kad narudžbina pređe u status sa `paid = 1` ("Payment accepted", ID 2) | poglavlje 32 (poznato ograničenje) |
| `ps_log` | `LoggerService` piše, `LogReader` čita | poglavlje 10 |
| `ps_currency`, `ps_country` | spisak uključenih valuta i zemalja | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/ShopCountriesAndCurrencies.php` |
| `ps_module_currency`, `ps_module_country` | PrestaShop-ova ograničenja modula (ko sme da plaća) | `/home/veljkozbiljic/logeecom/plugin0/plugin0.php:173-187` |
| `ps_configuration` | ID-jevi statusa `PS_OS_PAYMENT` (2), `PS_OS_ERROR` (8), `PS_OS_BANKWIRE` (10), `PS_OS_CANCELED` (6), `PS_OS_REFUND` (7) (vrednosti iz baze 2026-10-16) | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/StoreService.php:56-67` |

Prostim rečima: sve što je "Worldline stvar" (konekcija, podešavanja, transakcije, metode, log operacija) je u
jednoj tabeli plugina; sve što je "prodavnica" (narudžbine, statusi, uplate, log) ostaje u PrestaShop tabelama.

---

## 10. Kako radi logovanje?

### 10.1 Običnim rečima

I Core i plugin pišu kratke zapise "šta se desilo" (log). Svi ti zapisi završe u PrestaShop-ovoj tabeli `ps_log`,
sa prefiksom `WORLDLINE` na početku poruke, da se razlikuju od PrestaShop-ovih. Stranica Logs u pluginu prikazuje
samo te zapise. Podešavanje "Log level" odlučuje koliko sitni zapisi se uopšte upisuju.

### 10.2 Put jednog zapisa

```
Core kod / plugin kod                       demo servis (PaymentMethods, Settings)
   Logger::logInfo($poruka, $komponenta, $kontekst)        $logger->info(...)   (PSR-3)
            │                                                    │
            │                                     Plugin0\Adapter\CoreLogger::log()
            │                                                    │ prevodi PSR-3 nivo u Core nivo
            ▼                                                    ▼
   Core Logger::logMessage()  ── /home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/Logger/Logger.php:120-130
            │ uvek zove ShopLoggerAdapter (linija 129), bez obzira na nivo
            ▼
   Plugin0\Infrastructure\LoggerService::logMessage()  ── /home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/LoggerService.php:40-66
            │ 1. preskoči zapis ispod podešenog Log level-a (linija 43)
            │ 2. složi tekst "WORLDLINE [NIVO] Komponenta: poruka | {kontekst JSON}" (47-61)
            ▼
   PrestaShopLogger::addLog($tekst, $severity, null, null, null, true)   (linija 65)
            ▼
   tabela ps_log  ──čita──▶  Plugin0\Infrastructure\LogReader  ──▶  stranica Logs
```

### 10.3 Nivoi

Tri sveta nivoa prevodi `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/CoreLogLevel.php`:

| Demo podešavanje (`Logeecom\Settings\Domain\LogLevel`) | PSR-3 nivoi koji mu odgovaraju | Core broj (`Logger::...`) | Oznaka u `ps_log` | PrestaShop severity (`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/LoggerService.php:27-32`) |
|---|---|---|---|---|
| `error` | error, critical, alert, emergency | `ERROR = 0` | `[ERROR]` | 3 |
| `warning` | warning | `WARNING = 1` | `[WARNING]` | 2 |
| `info` | info, notice | `INFO = 2` | `[INFO]` | 1 |
| `debug` | debug | `DEBUG = 3` | `[DEBUG]` | 1 |

Manji Core broj znači ozbiljnije. Zapis se upisuje kad je njegov broj manji ili jednak podešenom
(`CoreLogLevel::isWritten`, `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/CoreLogLevel.php:87-90`).
Mehanički, **pojednostavljeno**:

```php
$podesen = Logger::INFO;            // Log level = Info → 2
isWritten(Logger::DEBUG, $podesen); // 3 <= 2 → false: DEBUG zapis repozitorijuma se NE upisuje
isWritten(Logger::ERROR, $podesen); // 0 <= 2 → true: greška se upisuje
```

**Default.** Log level nije sačuvan u dev bazi (provereno 2026-10-16), pa važi default iz
`SettingsService::defaultLogLevel()`
(`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Service/SettingsService.php:88-91`): Debug kad je
PrestaShop u dev režimu, inače Info. Dev prodavnica ima `_PS_MODE_DEV_ = false`
(`/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/config/defines.inc.php:29`), pa važi **Info**: DEBUG zapisi
(npr. svako čitanje repozitorijuma) se ne upisuju.

**Keš nivoa.** `LoggerService` nivo čita jednom po zahtevu i pamti ga u `self::$minLogLevel` (linije 35, 96-119).
Čitanje ide kroz `BaseRepository`, koji i sam loguje; zato postoji druga zaštita od petlje,
`self::$readingMinLogLevel` (linije 38, 103-115). Kad se podešavanje sačuva, `Adapter\LogLevelApplier` novi nivo
upiše direktno u keš (`LoggerService::useMinLogLevel`, 91-94), da važi odmah u istom zahtevu.

**Duplikati.** Šesti argument `addLog(..., true)` (`allowDuplicate`) je namerno `true`: PrestaShop bi inače preskočio
poruku identičnu nekoj koja već postoji, a svaki Core zapis mora da stigne (commit `66d2834`).

### 10.4 Komponente koje ćeš videti u logu

| Komponenta u poruci | Ko piše | Primer poruke |
|---|---|---|
| `Core` | `ErrorHandlingAspect` i drugi Core kod | `Unhandled error occurred.`, `Payment transaction for payment ID ... not found.` |
| `Repository` | `BaseRepository::log()` | `Read PaymentTransaction: 1 row(s)` (DEBUG) |
| `ShopOrderService` | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/ShopOrderService.php` | `Order created from cart after Worldline payment`, `Order status changed after Worldline payment update` |
| `PaymentManager` | `/home/veljkozbiljic/logeecom/plugin0/src/Order/PaymentManager.php`, `/home/veljkozbiljic/logeecom/plugin0/plugin0.php:152-160` | `Payment operation attempted from the order page`, `Worldline payment panel could not be rendered on the order page` |
| `ShopPaymentService` | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/ShopPaymentService.php` | `Worldline payment method settings saved` |
| `PaymentMethods` | demo `PaymentMethodService` kroz `CoreLogger` | `Payment method created`, `Payment method toggled` |
| `Settings` | demo `SettingsService` kroz `CoreLogger` | `Setting changed`, `Settings restored to defaults` |

U dev bazi je 2026-10-16 bilo 1034 `WORLDLINE` zapisa, od 2026-10-07 do 2026-10-16.

### 10.5 Dva loga, dve namene

1. **`ps_log` (zapisi `WORLDLINE ...`)**: šta su plugin i Core radili; vidi se na stranici Logs plugina i u
   PrestaShop-u pod Advanced Parameters → Logs.
2. **`MonitoringLog` u `ps_plugin0_entity`**: ceo HTTP razgovor sa Worldline-om, samo dok je `debugMode` uključen
   (8.4); nema stranicu u pluginu, gleda se SQL-om (poglavlje 28).

Prostim rečima: kad nešto ne radi, prvo otvori stranicu Logs (ili `ps_log`) i traži `ERROR`; kad ti treba tačan
zahtev ka Worldline-u, pogledaj `MonitoringLog`.

---

# Deo III. Tokovi, korak po korak

Svaki tok ima isti oblik: šta se dešava običnim rečima, pa koraci sa tačnim fajlom i linijom, pa zašto je tako
napravljeno, pa kako se proverava.

Za svaku od četiri admin stranice (Configuration 12, Payment methods 13, Settings 14, Logs 15) poglavlje ima i isti
skup podnaslova: **šta nam stranica znači**, **kako smo je pravili** (commit po commit), **kako je napravljena u kodu,
sloj po sloj** i **šablon i ponašanje u browseru**. Zajednički delovi svih stranica i uputstvo kako se pravi nova
stranica su u 11.1 i 11.2; panel na narudžbini ima isto u 20.10 i 20.11.

## 11. Šta se desi kad otvoriš jednu admin stranicu plugina?

Primer: klik na **Settings** u bočnom meniju plugina.

1. **Browser → ngrok → Apache → PHP-FPM.** Zahtev
   `GET https://8-2-4.prestashop.veljko.ngrok.app/admin174kob088ranecckhxg/index.php/modules/worldline/settings?_token=...`
   stiže do Apache-a u kontejneru, koji `.php` predaje PHP-FPM-u (OKRUZENJE.md 12 i 13).
2. **PrestaShop admin ulaz.** `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/admin174kob088ranecckhxg/index.php` učita konfiguraciju i napravi Symfony kernel.
   Kernel uključi `modules/<modul>/vendor/autoload.php` svakog aktivnog modula
   (`/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/app/AppKernel.php:235`); za plugin0 je to
   `/home/veljkozbiljic/logeecom/plugin0/vendor/autoload.php`, pa PHP zna za klase `Plugin0\...`.
3. **Ruta.** Symfony router nađe rutu `plugin0_settings` (`/home/veljkozbiljic/logeecom/plugin0/config/routes.yml:53-58`;
   putanja `/worldline/settings` sa prefiksom `/modules`). U prod režimu su rute keširane u
   `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/var/cache/prod/` (poglavlje 31).
4. **Zaštita.** Prijava zaposlenog i `_token` (4.4).
5. **Hook `actionDispatcherBefore`.** `ActionDispatcherLegacyHooksSubscriber`
   (`/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/PrestaShopBundle/EventListener/ActionDispatcherLegacyHooksSubscriber.php:91`)
   okine hook → `Plugin0::hookActionDispatcherBefore()` → `Bootstrap::boot()`. Tačan redosled koraka 4 i 5
   unutar Symfony-ja nisam proveravao, **neprovereno**; za plugin je svejedno, jer akcija i sama zove `boot()`.
6. **Akcija kontrolera.** `SettingsController::indexAction()`
   (`/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/SettingsController.php:35`):
   1. `Bootstrap::boot()` (linija 37; ne radi ništa, Core je već podignut);
   2. `isConnected()` (linija 39; poglavlje 12.5): nepovezana prodavnica ide na Configuration;
   3. posao stranice (poglavlje 14).
7. **Twig.** `$this->render('@Modules/plugin0/views/templates/admin/settings.html.twig', [...])`. `@Modules` je
   PrestaShop-ov Twig namespace za folder `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/modules/`. Šablon nasleđuje
   `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/layout.html.twig`, a on
   `@PrestaShop/Admin/layout.html.twig` (izgled admina). Layout:
   - učitava `/home/veljkozbiljic/logeecom/plugin0/views/css/admin.css` preko `asset('../modules/plugin0/views/css/admin.css')` (linije 17-20);
   - crta bočni meni "Worldline" (linije 22-44): Configuration uvek; Payment methods, Settings i Logs samo kad je
     `connected` istina (linije 31-41);
   - stranica ide u blok `plugin0_content` (linija 47).
8. **Odgovor** ide istim putem nazad do browsera.

**Obrazac POST → redirect → GET.** Svaka forma (Save, Disconnect, Restore, izmena metode) radi POST, kontroler
upiše poruku u flash (`addFlash`) i preusmeri na GET iste stranice (npr.
`/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/ConfigurationController.php:37`). Zato osvežavanje
stranice posle čuvanja ne šalje formu ponovo.

Prostim rečima: admin stranica plugina je obična Symfony stranica PrestaShop admina; razlika je samo što njen
kontroler na početku podigne Core i proveri da li je prodavnica povezana.

### 11.1 Od čega je napravljena svaka admin stranica plugina

Običnim rečima: svaka stranica je složena od istih osam delova, kao kuća od temelja, zidova i krova
(**poređenje**). Kad znaš tih osam delova, svaku stranicu čitaš istim redom.

| # | Deo | Gde je | Šta radi |
|---|---|---|---|
| 1 | **ruta** | `/home/veljkozbiljic/logeecom/plugin0/config/routes.yml` | ime rute, putanja (u adresi sa prefiksom `/modules`, 4.4), HTTP metode, `_controller` (klasa::akcija), `_legacy_controller` (čija prava važe), `requirements` za `{id}` |
| 2 | **kontroler** | `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/` | čita zahtev, podiže Core, proverava "povezano", zove tačno jedan servis, vraća stranicu ili preusmerenje; pravila nema |
| 3 | **roditeljska klasa** | PrestaShop `FrameworkBundleAdminController` (`/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/PrestaShopBundle/Controller/Admin/FrameworkBundleAdminController.php`) ili plugin `ConnectedController` (`/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/ConnectedController.php`) | PrestaShop daje `render()`, `redirectToRoute()`, `addFlash()`, `getContext()`; `ConnectedController` dodaje `isConnected()` i `storeId()` |
| 4 | **poslovna pravila** | demo servis (`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/...`) dobijen iz `/home/veljkozbiljic/logeecom/plugin0/src/Services.php`, ili Core fasada, ili klasa plugina | šta sme, šta ne sme, default-i, šta se loguje |
| 5 | **skladište** | adapter (`/home/veljkozbiljic/logeecom/plugin0/src/Adapter/`) → Core ORM → `BaseRepository` → `ps_plugin0_entity`; ili `ps_log`; ili Core repozitorijum konekcije | gde podaci stvarno leže (poglavlje 9) |
| 6 | **šablon** | `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/*.html.twig` | HTML stranice; nasleđuje layout, puni blok `plugin0_content`, po potrebi i blok `javascripts` |
| 7 | **okvir i bočni meni** | `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/layout.html.twig` | izgled PrestaShop admina plus meni "Worldline"; traži promenljive `current_page` i `connected` (komentar u liniji 9) |
| 8 | **stil** | `/home/veljkozbiljic/logeecom/plugin0/views/css/admin.css` | klase `plugin0-*`; učitava ga layout (linije 17-20) |

**Četiri stranice na jedan pogled:**

| Stranica | Rute | Kontroler (roditelj) | Odakle dolaze podaci | Gde se čuva | Šablon | Nastala → velike izmene |
|---|---|---|---|---|---|---|
| Configuration | `plugin0_configuration` (GET, POST) | `ConfigurationController` (`FrameworkBundleAdminController`; mora da radi i bez konekcije) | Core `AdminAPI::connection()` | `ps_plugin0_entity`, tip `ConnectionConfig` | `configuration.html.twig` | `6838d2c` 2026-10-09 → `43e9e38` (meni) |
| Payment methods | `plugin0_payment_methods`, `_create`, `_edit`, `_active`, `_delete` | `PaymentMethodsController` (`ConnectedController`) | demo `PaymentMethodService` (`Services::paymentMethods()`) | `ps_plugin0_entity`, tip `PaymentMethod` | `payment_methods.html.twig`, `payment_method.html.twig` | `90a8caf` 2026-10-14 → `7c0a6e3` → `76d6d76` (demo metode) |
| Settings | `plugin0_settings` (GET, POST) | `SettingsController` (`ConnectedController`) | demo `SettingsService` (`Services::settings()`) + `PaymentActionSetting` (Core `AdminAPI::generalSettings()`) | `ps_plugin0_entity`: tip `Configuration` (ključevi) i `PaymentSettingsEntity` | `settings.html.twig` | `3aff0b0` 2026-10-12 → `7c0a6e3` → `76d6d76` (demo servis) → `06a7b91` (Payment action) |
| Logs | `plugin0_logs` (GET) | `LogsController` (`ConnectedController`) | `LogReader` nad `ps_log`; veličina strane iz `Services::settings()` | ništa ne čuva, samo čita `ps_log` | `logs.html.twig` | `43e9e38` 2026-10-09 → `3aff0b0` → `76d6d76` |

(Ime šablona je u folderu `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/`; ime kontrolera u
`/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/`.)

**Pravila koja važe za svaku stranicu u kodu:**

1. **Svaka akcija počinje sa `Bootstrap::boot()`** (5.4), npr.
   `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/LogsController.php:23`.
2. **Stranice samo za povezanu prodavnicu** odmah posle toga pitaju `isConnected()` i nepovezanu prodavnicu šalju na
   Configuration (npr. `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/LogsController.php:25-27`).
   Configuration to ne radi, jer se na njoj prodavnica tek povezuje.
3. **Svaki `render()` šalje `current_page` i `connected`**, jer ih layout koristi za meni (aktivna stavka i koje
   stavke se vide).
4. **Kontroler nema pravila.** Pravila su u servisu (demo) ili u formi (`/home/veljkozbiljic/logeecom/plugin0/src/Form/PaymentMethodForm.php`);
   kontroler samo prevodi zahtev u poziv i odgovor u stranicu (komentar
   `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/PaymentMethodsController.php:25-26`).
5. **POST → poruka → preusmerenje (PRG).** Uspeh i opšta greška idu kroz `addFlash()` i `redirectToRoute()`.
   PrestaShop poruke prikazuje na vrhu stranice, po vrsti `error`, `success`, `warning`, `info`
   (`/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/PrestaShopBundle/Resources/views/Admin/layout.html.twig:82-96`).
6. **Greška u unosu ne ide u bazu.** Forma se vrati sa unetim vrednostima i porukom ispod polja (niz `errors`):
   Payment methods i Settings. Configuration greške javlja samo porukom na vrhu.
7. **Ulaz se čisti pre upotrebe.** Symfony 4.4 za `x[]=...` vraća niz, pa kontroleri imaju pomoćnu `scalar()`
   (`/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/PaymentMethodsController.php:340-343`,
   `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/SettingsController.php:187-190`); Configuration radi
   `(string)` i `trim()`.
8. **Ko je šta uradio.** Kontroler uzme ID zaposlenog (`$this->getContext()->employee->id`) i preda ga servisu, koji
   ga upiše u log kao `user_id` (Payment methods, Settings).
9. **`_token` u adresi.** `path()` ga dodaje sam; GET forma ga mora nositi u skrivenom polju
   (`/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/payment_methods.html.twig:56`), a meni sa adresama ga
   nosi u samoj adresi (`/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/logs.html.twig:19-22`).
10. **JavaScript je samo udobnost** (potvrde, prekidači, prikaz izabranog okruženja); sva pravila proverava server.
    Skripte su u bloku `javascripts`, posle `{{ parent() }}`.
11. **CSS samo u `/home/veljkozbiljic/logeecom/plugin0/views/css/admin.css`**, nikad u `<style>` bloku Twig-a
    (PhpStorm inspekcija pri commit-u, 22.6).

**Zamka koju treba znati: PrestaShop poruke prikazuje bez escape-ovanja.** U layout-u PrestaShop-a poruka ide kao
`{{ flashMessage|raw }}`
(`/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/PrestaShopBundle/Resources/views/Admin/layout.html.twig:68`
i `:74`). Payment methods u poruku ubacuje ime metode koje je admin uneo, npr. `sprintf('"%s" is now %s.',
$method->name, ...)` (`/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/PaymentMethodsController.php:135`,
i `:162`, `:207-211`). Ime sa HTML-om, npr. `<img src=x onerror=alert(1)>`, bi se izvršilo u browseru zaposlenog
koji posle toga uključi, izmeni ili obriše tu metodu. Mehanički, **pojednostavljeno**:

```php
$this->addFlash('success', sprintf('"%s" is now %s.', $method->name, 'active'));
// layout.html.twig:  {{ flashMessage|raw }}   → HTML iz imena ide u stranicu neizmenjen
```

U šablonima same stranice (`{{ method.name }}`) Twig escape-uje, pa je tamo bezbedno. Zaštita bi bila
`htmlspecialchars()` oko korisničkog teksta pre `addFlash()`. Ovo je nađeno čitanjem koda; u browseru nije
probano, **neprovereno**. Napadač mora već imati pristup admin stranicama plugina.

### 11.2 Uputstvo: kako se pravi nova admin stranica, korak po korak

Primer: nova stranica "Status" koja samo prikazuje nešto i postoji samo dok je prodavnica povezana. Kod ispod je
**šablon za učenje**, ne kod koji postoji u pluginu.

1. **Ruta** u `/home/veljkozbiljic/logeecom/plugin0/config/routes.yml` (ime počinje sa `plugin0_`, putanja sa
   `/worldline/`):

   ```yaml
   plugin0_status:
     path: /worldline/status
     methods: [GET]
     defaults:
       _controller: 'Plugin0\Controller\Admin\StatusController::indexAction'
       _legacy_controller: 'AdminPlugin0Configuration'
   ```

2. **Kontroler** `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/StatusController.php`, po uzoru na
   `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/LogsController.php`:

   ```php
   class StatusController extends ConnectedController
   {
       private const TEMPLATE = '@Modules/plugin0/views/templates/admin/status.html.twig';

       public function indexAction(Request $request): Response
       {
           Bootstrap::boot();                                   // 1. Core
           if (!$this->isConnected()) {                         // 2. samo povezana prodavnica
               return $this->redirectToRoute('plugin0_configuration');
           }
           $data = Services::nesto()->pregled();                // 3. jedan poziv servisa, bez pravila ovde
           return $this->render(self::TEMPLATE, [
               'current_page' => 'status',                      // 4. za meni
               'connected' => true,
               'data' => $data,
           ]);
       }
   }
   ```

3. **Pravila u servis**, ne u kontroler. Ako treba nov servis, napravi ga bez HTTP-a i bez baze (kao demo servisi u
   `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/`), sa interfejsom za skladište, i poveži ga u
   `/home/veljkozbiljic/logeecom/plugin0/src/Services.php` (jedino mesto koje bira implementacije).
4. **Skladište.** Za nove zapise napravi entitet po uzoru na
   `/home/veljkozbiljic/logeecom/plugin0/src/Entity/PaymentOperationEntity.php` (konstanta `TYPE`, `$fields`,
   `getConfig()` sa `IndexMap`, najviše 9 indeksa) i dodaj klasu u spisak u
   `/home/veljkozbiljic/logeecom/plugin0/src/Bootstrap.php:165-185`. Migracija ne treba: svi entiteti dele tabelu
   `ps_plugin0_entity`. Filtrirati se sme samo po indeksiranim poljima (9.4).
5. **Šablon** `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/status.html.twig`:

   ```twig
   {% extends '@Modules/plugin0/views/templates/admin/layout.html.twig' %}

   {% block plugin0_content %}
   <div class="card">
       <h3 class="card-header">Status</h3>
       <div class="card-body">{{ data }}</div>
   </div>
   {% endblock %}
   ```

   Forme šalju na `path('...')` (token ide sam); GET forma nosi `_token` u skrivenom polju; CSS ide u
   `/home/veljkozbiljic/logeecom/plugin0/views/css/admin.css`.
6. **Stavka menija** u `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/layout.html.twig`, unutar
   `{% if connected %}` (linije 31-41), po uzoru na postojeće: `path('plugin0_status')` i
   `current_page == 'status'`.
7. **Log** svake izmene: kroz PSR-3 logger iz `Services::logger('Status')` ili `Logger::logInfo(..., 'Status', ...)`;
   upiši i ID zaposlenog.
8. **Keš.** Posle nove rute i novog šablona obriši Symfony keš kao `www-data` (31.1).
9. **Testovi.** Pravila servisa pokrij unit testom u `/home/veljkozbiljic/logeecom/plugin0/tests/Unit/...` i pokreni
   ih u kontejneru (26.1).
10. **Pre commit-a:** LF provera (27.2); u docblock-u nikad ne piši `@NekoIme` kao tekst (4.4); u Markdown tabelama
    `|` unutar code span-a piši kao `\|`.
11. **Provera u browseru:** adresa
    `https://8-2-4.prestashop.veljko.ngrok.app/admin174kob088ranecckhxg/index.php/modules/worldline/status?_token=...`
    (lakše: klik na stavku menija), pa stranica Logs za zapise.

Prostim rečima: nova stranica = ruta + kontroler koji samo prosleđuje + servis sa pravilima + šablon koji nasleđuje
layout + stavka u meniju + brisanje keša.

---

## 12. Configuration: kako se prodavnica povezuje sa Worldline-om?

### 12.1 Običnim rečima

Admin izabere okruženje (Test ili Live), unese pet podataka koje je dobio od Worldline-a i klikne Save. Plugin te
podatke prvo pokaže Worldline-u ("da li su ispravni?"); tek kad Worldline kaže da jesu, sačuva ih, šifrovane. Sve
ostale stranice plugina postoje tek kad je prodavnica povezana. Disconnect briše kredencijale izabranog okruženja.

| Polje | Šta je | Čuva se |
|---|---|---|
| PSPID | ID trgovačkog naloga kod Worldline-a (npr. `logeecom35`, **iz beleški**) | da, nešifrovano |
| API Key | javni deo ključa za API pozive | da, šifrovano |
| API Secret | tajni deo; njime se potpisuje svaki zahtev | da, šifrovano; nikad se ne vraća u formu |
| Webhook Key | ključ za proveru potpisa webhook poruka | da, šifrovano |
| Webhook Secret | tajna za proveru potpisa webhook poruka | da, šifrovano; nikad se ne vraća u formu |

Webhook Key i Secret se proveravaju samo kao obavezna polja; plugin ih danas ne koristi, jer webhook ne postoji
(poglavlje 32).

### 12.2 Prikaz (GET)

`ConfigurationController::indexAction()`, `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/ConfigurationController.php:26-67`:

1. `storedConfiguration()` (linije 252-257): `AdminAPI::get()->connection($storeId)->getConnectionConfig()`. Core
   vraća `mode` (aktivno okruženje), `sandboxData` i `liveData` sa **dešifrovanim** tajnama (CORE.md, zamka 17).
2. Za oba okruženja (linije 45-58) u šablon idu samo PSPID, API Key, Webhook Key i dve logičke vrednosti
   "secret je sačuvan" (`has_api_secret`, `has_webhook_secret`). Tajne nikad ne idu u HTML.
3. Šablon `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/configuration.html.twig` drži podatke oba
   okruženja u `data-*` atributima opcija menija (linije 28-38); JavaScript (linije 124-171) pri promeni menija
   prepiše polja, značku i dugme Disconnect.
4. Značka (linije 14-20): **Connected** (aktivno okruženje), **Saved, not active** (sačuvano, ali drugo je
   aktivno), **Not connected**.
5. Polje "Webhook URL" (linije 76-87) prikazuje `getModuleLink('plugin0', 'webhook', [], true)` (kontroler, linija 65).
   **Pazi:** front controller `webhook` ne postoji, pa ta adresa danas vraća PrestaShop stranicu "nije nađeno"
   (**neprovereno** u browseru, ali fajl `/home/veljkozbiljic/logeecom/plugin0/controllers/front/webhook.php` ne postoji). Ne unosi je u Merchant
   Portal dok webhook ne bude napravljen.

### 12.3 Čuvanje (POST, Save)

`save()`, linije 69-131:

1. Okruženje mora biti `test` ili `live` (72-76).
2. Prazno polje za secret znači "zadrži sačuvani" (87-93), jer forma secret nikad ne prikazuje.
3. Svih pet polja je obavezno (95-105); poruka nabraja ona koja fale.
4. Kredencijali **drugog** okruženja se prepišu iz sačuvanih (107-117), da prelazak Test ↔ Live ne obriše ništa.
5. `AdminAPI::get()->connection($storeId)->connect(new ConnectionRequest($environment, ...$test, ...$live))` (119-121).
   U Core-u (CORE.md 7.2):
   1. `ConnectionController::connect()` pravi domenski `ConnectionDetails`;
   2. `ConnectionService::connect()` zove `ConnectionProxy::isConnectionValid()` → SDK
      `services()->testConnection()`; rezultat mora biti `'OK'`;
   3. ako nije: greška `connection.apiValidationFailed`, ništa se ne čuva;
   4. ako jeste: `ConnectionConfigRepository::saveConnection()` šifruje četiri tajne plugin `Encryptor`-om i
      upiše red `ConnectionConfig` (index_1 = storeId) kroz `BaseRepository`.
6. Poruka: "Connected to Worldline. Configuration saved." ili "Connection failed, nothing was saved. ..." (123-130).

### 12.4 Disconnect (POST, `action=disconnect`)

`disconnect()`, linije 146-196. Posebna forma u šablonu (`/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/configuration.html.twig:105-108`) šalje samo okruženje;
JavaScript traži potvrdu (165-170). Dva slučaja:

| Slučaj | Metoda | Šta se desi |
|---|---|---|
| izabrano okruženje je **aktivno** | `disconnectActive()`, linije 198-212 | Core `ConnectionConfigRepositoryInterface::disconnect()` unutar `StoreContext::doWithStore()` (linija 204): ako drugo okruženje ima kredencijale, ono postaje aktivno; ako nema, red `ConnectionConfig` se briše |
| izabrano okruženje je sačuvano, ali **nije aktivno** | `removeInactive()`, linije 215-239 | Core nema metodu za to, pa plugin ponovo čuva konekciju aktivnog okruženja bez kredencijala izabranog; Core pritom ponovo proverava aktivne kredencijale kod Worldline-a |

Posle toga kontroler ponovo čita stanje i proverava da li je okruženje stvarno uklonjeno (172-179), jer Core
ignoriše rezultat `update()`/`delete()` i neuspeo upis ne prijavljuje.

**Zašto ne Core-ov disconnect.** Core ima "pravi" disconnect, `AdminAPI::get()->generalSettings($storeId)->disconnect()`
(`DisconnectService`), koji briše i Core podešavanja i u pozadini logove. On traži red zadataka (`QueueItem`
repozitorijum), a plugin ga nema (poglavlje 32). Komentar u kodu
(`/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/ConfigurationController.php:142-144`) kao razlog navodi
i da `ShopPaymentService` nije registrovan; to je zastarelo, jer je registrovan od commit-a `90a8caf`
(`/home/veljkozbiljic/logeecom/plugin0/src/Bootstrap.php:118-120`). Preostali razlog je red zadataka.

Posledica: plugin-ov disconnect briše **samo kredencijale**. `PaymentSettingsEntity`, `LogSettings`, transakcije i
metode plaćanja ostaju u bazi.

### 12.5 "Povezano" i bočni meni

`ConnectedController` (`/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/ConnectedController.php:13-27`) je
roditelj kontrolera Payment methods, Settings i Logs. `isConnected()` (16-21) vraća istinu kad Core vrati konekciju
koja ima ključ `mode`. Svaka akcija tih stranica počinje sa `if (!$this->isConnected()) return redirect('plugin0_configuration')`.
To je PrestaShop verzija demo "connected" middleware-a.

### 12.6 Zašto demo `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Configuration` nije povezan

Demo modul (`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Configuration/Service/ConfigurationService.php`) proverava
kredencijale kroz `CredentialsVerifierInterface`, a u demu tu stoji `FakeProvider`
(`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Configuration/Provider/FakeProvider.php`). U pluginu pravu proveru
radi Core (`testConnection`), pa Configuration stranica ide direktno na Core i demo modul ne koristi. Kod je u
`/home/veljkozbiljic/logeecom/plugin0/lib/` jer je ceo demo `/home/veljkozbiljic/laravel-docker/src/` kopiran bez izmena; testovi `ConfigurationServiceTest` ga i dalje proveravaju.

### 12.7 Šta nam ova stranica znači

- **Bez nje ništa drugo ne radi.** Kredencijali sa ove stranice su jedini način da Core razgovara sa Worldline-om:
  `MerchantClientFactory` ih čita za svaki zahtev (8.2). Bez sačuvane konekcije Core vraća grešku
  `connection.invalidActiveCredentials` (CORE.md 6.3), pa ne rade ni checkout ni panel na narudžbini.
- **Ona otvara ostale stranice.** Payment methods, Settings i Logs postoje samo dok je prodavnica povezana
  (`ConnectedController`, 12.5); i stavke menija se bez konekcije ne vide.
- **Okruženje odlučuje mnogo toga.** Aktivno okruženje (Test ili Live) bira Worldline server (preprod ili live) i
  koja Core podešavanja važe: `PaymentSettingsEntity` i `LogSettings` se čuvaju po prodavnici **i** okruženju (9.3).
- **Webhook Key i Webhook Secret** se čuvaju za budući webhook (32); danas ih ništa ne koristi.

### 12.8 Kako smo je pravili

| Commit | Datum | Šta se promenilo na ovoj stranici |
|---|---|---|
| `b9f1d33` | 2026-10-07 | stranice još nema; postoji samo JSON `core-info` (`/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/CoreInfoController.php`), jer tiket MIDU-1215 traži da se Core `Configuration` čita iz `ServiceRegister`-a |
| `6838d2c` | 2026-10-09 | stranica nastaje (MIDU-1215): stanje po okruženju (Connected / Saved, not active / Not connected), Save koji Core proverava kod Worldline-a, Disconnect po okruženju; za nju je napravljen ceo sloj za čuvanje (`BaseRepository`, `LoggerService`, `Encryptor`, `MetadataProvider`, `StoreService`, `Installer`) |
| `43e9e38` | 2026-10-09 | kontroler šalje `current_page` i `connected`, šablon prelazi na zajednički layout sa bočnim menijem (MIDU-1218) |
| posle toga | — | stranica se nije menjala (`git log` za oba fajla, 2026-10-16) |

Demo projekat ima sopstvenu Configuration stranicu
(`/home/veljkozbiljic/laravel-docker/resources/js/configuration/ConfigurationScreen.jsx`,
`/home/veljkozbiljic/laravel-docker/resources/js/configuration/DisconnectDialog.jsx`,
`/home/veljkozbiljic/laravel-docker/app/Http/Controllers/Admin/ConfigurationController.php`), koja kredencijale
"proverava" kroz `FakeProvider`. Ona nije prenesena: kod nas pravu proveru radi Core (12.6).

### 12.9 Kako je napravljena u kodu, sloj po sloj

```
browser: forma (Save) ili forma Disconnect
   │  POST /admin174kob088ranecckhxg/index.php/modules/worldline/configuration?_token=...
   ▼
ruta plugin0_configuration (routes.yml:9-14)
   ▼
ConfigurationController::indexAction (ConfigurationController.php:26-67)
   roditelj: FrameworkBundleAdminController, a NE ConnectedController (stranica mora da radi i bez konekcije)
   ├─ GET  → storedConfiguration() (252-257) → render šablona (60-66)
   ├─ POST → save() (69-131)        → AdminAPI::connection($storeId)->connect(ConnectionRequest)
   └─ POST → disconnect() (146-196) → disconnectActive() (198-212) ili removeInactive() (215-239)
   ▼
Core: ConnectionController → ConnectionService → ConnectionProxy (SDK testConnection)
      → ConnectionConfigRepository (šifruje/dešifruje kroz plugin Encryptor)
   ▼
plugin BaseRepository → ps_plugin0_entity, red tipa ConnectionConfig (index_1 = storeId)
```

| Sloj | Fajl (puna putanja) | Najvažnije linije |
|---|---|---|
| ruta | `/home/veljkozbiljic/logeecom/plugin0/config/routes.yml` | 9-14 |
| kontroler | `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/ConfigurationController.php` | `indexAction` 26-67, `save` 69-131, `disconnect` 146-196, `disconnectActive` 198-212, `removeInactive` 215-239, `hasCredentials` 246-249, `storedConfiguration` 252-257, `credentialsFor` 260-263 |
| poslovna pravila | Core, ne plugin: provera kod Worldline-a, šifrovanje, prebacivanje okruženja pri disconnect-u (CORE.md 5.3, 7.2) | — |
| šifrovanje | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/Encryptor.php` | 15-30 |
| skladište | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/Repository/BaseRepository.php` | `save` 88-108, `update` 110-130, `delete` 132-139 |
| šablon | `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/configuration.html.twig` | 12.10 |

Ova stranica **nema sopstveni servis** (ni demo, ni plugin): Core već ima sva pravila, pa kontroler priča direktno
sa Core fasadom. Kontroler pri tome pazi na tri stvari koje Core ne radi sam: prazno polje za secret znači "zadrži
sačuvani" (87-93), kredencijali drugog okruženja se prepišu da se ne izgube (107-117), i posle disconnect-a ponovo
čita stanje jer Core neuspeo upis ne prijavljuje (172-179).

**Šta se loguje, a šta ne.** Neuspela provera kredencijala ostaje u logu kao ERROR komponente `Core` (iz
`ErrorHandlingAspect`, 7.3). Uspešan Save i Disconnect **ne ostavljaju INFO zapis**: ni kontroler ni Core kod
konekcije ne zove `Logger` (pretraga 2026-10-16), a DEBUG zapisi `BaseRepository`-ja se na nivou Info ne upisuju.
Ko je i kada povezao ili odvezao prodavnicu se zato iz loga ne vidi. Demo projekat za to ima zapis po svakom
pokušaju (komentar u `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Configuration/Service/ConfigurationService.php`).

### 12.10 Šablon i ponašanje u browseru

`/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/configuration.html.twig`:

| Deo stranice | Linije | Šta radi |
|---|---|---|
| zaglavlje kartice sa značkom stanja | 11-21 | Connected (zeleno), Saved, not active (plavo), Not connected (sivo) |
| meni Environment | 25-43 | Test ili Live; svaka opcija nosi `data-*` podatke svog okruženja (sačuvano, aktivno, PSPID, API Key, Webhook Key, da li ima secret-e); ispod piše koji Worldline server se koristi |
| PSPID, API Key, Webhook Key | 45-53, 62-65 | obična tekstualna polja sa sačuvanim vrednostima |
| API Secret, Webhook Secret | 55-60, 67-72 | polja za lozinku, uvek prazna; kad je secret sačuvan, piše "•••••••• (saved, leave empty to keep)" i polje nije obavezno |
| Webhook URL | 76-87 | samo za čitanje, sa dugmetom Copy (`navigator.clipboard`); adresa vodi na front controller `webhook` koji ne postoji (12.2) |
| dno kartice | 91-99 | Disconnect (vidi se samo kad izabrano okruženje ima sačuvane kredencijale) i Save |
| posebna forma za Disconnect | 105-108 | skrivena polja `action=disconnect` i `environment`; posebna, jer forma u formi nije dozvoljena, a Disconnect ne šalje kredencijale |
| JavaScript | 114-173 | Copy (117-122); `showEnvironment()` pri promeni menija prepiše polja, značku i Disconnect (143-163); potvrda pre Disconnect-a (165-170) |

Prostim rečima: Configuration je ulaz u ceo plugin; ona samo predaje kredencijale Core-u, a Core ih proverava,
šifruje i čuva.

### 12.11 Kako da proveriš

- Browser: Save sa pogrešnim API Secret-om → crvena poruka, ništa sačuvano; sa ispravnim → zelena "Connected...",
  značka Connected, u meniju se pojave Payment methods, Settings, Logs.
- Baza: `SELECT id, index_1 FROM ps_plugin0_entity WHERE type = 'ConnectionConfig';` (danas jedan red, `index_1 = 1`).
- Endpoint `core-info` (MIDU-1215):
  `https://8-2-4.prestashop.veljko.ngrok.app/admin174kob088ranecckhxg/index.php/modules/worldline/core-info` (prijavljen,
  bez tokena) treba da vrati JSON sa `integrationName = PrestaShop`, `integrationVersion`, `pluginName = plugin0`,
  `pluginVersion = 1.0.0` (`/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/CoreInfoController.php:12-23`);
  oblik adrese je izveden iz 4.4, u browseru danas **neprovereno**.

Prostim rečima: Configuration je jedina stranica bez demo pravila; ona samo predaje kredencijale Core-u, a Core ih
proverava kod Worldline-a i čuva šifrovane.

---

## 13. Payment methods: kako admin pravi metode plaćanja?

### 13.1 Običnim rečima

Metoda plaćanja je zapis koji admin sam pravi: kako se zove kupcu ("Viza payment"), koji je njen kod, kog je tipa
(Redirect: kupac ide na tuđu stranu; iFrame: tuđa strana je ugrađena u prodavnicu), u kojim valutama i zemljama
važi, kojim redom se nudi i da li je uključena. Stranica ima listu sa pretragom, filterima i stranama, prekidač
uključeno/isključeno, formu za dodavanje i izmenu i brisanje sa potvrdom. Sve je preneto iz demo projekta.

### 13.2 Slojevi, od klika do baze

```
PaymentMethodsController  (/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/PaymentMethodsController.php)
  │  čita HTTP, zove TAČNO JEDNU metodu servisa, prevodi odgovor u stranicu ili preusmerenje
  ▼
Services::paymentMethods()  (/home/veljkozbiljic/logeecom/plugin0/src/Services.php:25-34)  ← "service provider"
  ▼
Logeecom\PaymentMethods\Service\PaymentMethodService  (/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Service/PaymentMethodService.php)
  │  sva pravila: jedinstven kod, redosled, uključi/isključi ≠ obriši, log svake izmene
  ├─▶ PaymentMethodRepositoryInterface ─▶ Plugin0\Adapter\PaymentMethodRepository ─▶ RepositoryRegistry ─▶ BaseRepository ─▶ ps_plugin0_entity (type PaymentMethod)
  ├─▶ ReferenceDataRepositoryInterface ─▶ Plugin0\Adapter\ReferenceData ─▶ ShopCountriesAndCurrencies ─▶ PrestaShop Currency / Country
  ├─▶ PaymentFlowFactory ─▶ RedirectFlow / IframeFlow (naziv i opis tipa)
  ├─▶ Psr\Log\LoggerInterface ─▶ Plugin0\Adapter\CoreLogger ─▶ Core Logger ─▶ LoggerService ─▶ ps_log
  └─▶ SettingsService (Rows per page, Default currency)
```

Pravilo slojeva (iz komentara `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/PaymentMethodsController.php:25-26`
i `/home/veljkozbiljic/logeecom/plugin0/src/Services.php:15-22`): kontroler nikad ne pravi repozitorijum; traži
servis od `Services`, a servis radi samo kroz interfejse. Servis ne zna ni za HTTP ni za bazu.

### 13.3 Polja i pravila

| Polje | Pravilo | Gde je pravilo |
|---|---|---|
| Name | obavezno, najviše 255 znakova | `/home/veljkozbiljic/logeecom/plugin0/src/Form/PaymentMethodForm.php:100-104` |
| Code | obavezno, najviše 64 znaka, jedinstveno među metodama | `/home/veljkozbiljic/logeecom/plugin0/src/Form/PaymentMethodForm.php:106-112`; servis još jednom pre upisa: `PaymentMethodService::guardCodeIsUnique()`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Service/PaymentMethodService.php:440-445` |
| Type | `redirect` ili `iframe` | `/home/veljkozbiljic/logeecom/plugin0/src/Form/PaymentMethodForm.php:114-118`; enum `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Domain/PaymentMethodType.php:11-15` |
| Currencies | bar jedna, svaka uključena u prodavnici, nijedna dvaput | `/home/veljkozbiljic/logeecom/plugin0/src/Form/PaymentMethodForm.php:120`, `:164-182` |
| Countries | isto, za zemlje | `/home/veljkozbiljic/logeecom/plugin0/src/Form/PaymentMethodForm.php:121`, `:164-182` |
| Sort order | ceo broj od 1 do 1 000 000 | `/home/veljkozbiljic/logeecom/plugin0/src/Form/PaymentMethodForm.php:123-132`; granica `PaymentMethod::MAX_SORT_ORDER`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Domain/PaymentMethod.php:24` |
| Active | da ili ne | `/home/veljkozbiljic/logeecom/plugin0/src/Form/PaymentMethodForm.php:80` |

Dodatno, sam objekat ne dozvoljava metodu bez valute ili bez zemlje: konstruktor `PaymentMethod` baca
`PaymentMethodAppliesNowhereException` (`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Domain/PaymentMethod.php:42-48`).
Pravilo je na objektu, ne samo u formi, pa nijedan put do baze ne može da ga zaobiđe.

**Ponuda za Code.** Forma nudi Worldline proizvode koje Core zna (`WorldlineProducts::names()`,
`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/WorldlineProducts.php:20-28`: ID → engleski naziv iz Core
`PaymentMethodDefaultConfigs`). To je samo predlog: može se upisati bilo koji kod. Ali na checkout-u se nudi samo
metoda čiji je kod Worldline ID koji Core podržava (poglavlje 16).

### 13.4 Lista

`listAction()`, `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/PaymentMethodsController.php:49-89`:

1. `filterFrom()` (259-274) čita samo poznate parametre iz adrese (`search`, `currency`, `country`, `type`,
   `status`, `page`). Nepoznata vrednost znači "bez tog filtera", strana manja od 1 je prva, pretraga se seče na
   64 znaka.
2. `PaymentMethodService::page()` (`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Service/PaymentMethodService.php:161-168`) → `PaymentMethodRepository::page()`
   (`/home/veljkozbiljic/logeecom/plugin0/src/Adapter/PaymentMethodRepository.php:52-79`):
   - **baza** sužava po tipu i statusu, jer su to indeks kolone (55-60);
   - **PHP** radi pretragu (deo imena ili koda, bez obzira na velika i mala slova), filter valute i zemlje,
     redosled "sort order, pa id" i sečenje strane (62-73).
     Core `QueryFilter` ne zna "lista sadrži kod" ni redosled po dve kolone (komentar 29-32);
   - `tableEmpty` razlikuje "nema nijedne metode" od "filter ništa ne nalazi" (76).
3. Veličina strane je **Rows per page** sa Settings stranice (`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Service/PaymentMethodService.php:166`).
4. `presentPage()` (136-150) dodaje uz svaku metodu `type_label` i `type_description` iz `PaymentFlowFactory`
   (`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Domain/Checkout/PaymentFlowFactory.php:26-32`).
5. Strana iza poslednje → preusmerenje na poslednju (kontroler 67-71).
6. Filter je u adresi (`?search=pay&currency=EUR&...`). GET forma filtera zamenjuje ceo query string, pa `_token`
   ide u skriveno polje (kontroler 86-87; šablon
   `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/payment_methods.html.twig:54-56`).
7. Posle svake radnje admin se vraća na isti prikaz liste: forme nose polje `back` sa query string-om liste
   (`backQuery()`, 327-332).

### 13.5 Dodavanje i izmena

`form()`, linije 177-243 (jedna metoda za obe rute):

1. Kod izmene se metoda prvo učita (`$service->get($id)`); nepostojeća → poruka i nazad na listu (184-196).
2. GET: prazna forma (`PaymentMethodForm::empty()`: prvi tip, **Default currency** sa Settings stranice već
   izabrana, sort order 1, aktivna; `/home/veljkozbiljic/logeecom/plugin0/src/Form/PaymentMethodForm.php:37-51`) ili
   forma sa vrednostima metode (`fromMethod()`, 53-64).
3. POST: `PaymentMethodForm::fromSubmitted()` očisti unos (71-82), `validate()` vrati poruku po polju (95-135).
4. Bez grešaka: `create()` ili `update()` servisa, flash poruka, nazad na listu (204-213).
5. `DuplicatePaymentMethodCodeException` (kod zauzet između provere i upisa, npr. u drugoj kartici) → poruka ispod
   polja Code (215-217).
6. Druga greška (`PaymentMethodWriteFailedException`) → forma ostaje sa unetim vrednostima, poruka iznad (218-222).

### 13.6 Redosled i "pravljenje mesta"

Kad admin sačuva metodu sa brojem koji već ima druga metoda, sačuvana metoda dobija taj broj, a ona koja ga je
imala pomeri se za jedan nadole; ako i taj broj neko ima, pomera se i on, dok se ne naiđe na slobodan broj
(`makeRoom()`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Service/PaymentMethodService.php:415-438`).
Primer, **pojednostavljeno**:

```
pre:   A=1, B=2, C=3, D=5
sačuvaj novu metodu X sa brojem 2
posle: A=1, X=2, B=3, C=4, D=5   (B i C pomereni; D nije, jer je 4 bilo slobodno)
```

Upis metode i sva pomeranja su **jedna transakcija**: ili sve uspe, ili ništa
(`PaymentMethodService::write()`, 358-374 → `PaymentMethodRepository::transaction()`,
`/home/veljkozbiljic/logeecom/plugin0/src/Adapter/PaymentMethodRepository.php:149-181`: `START TRANSACTION`,
`COMMIT`, `ROLLBACK` kroz PrestaShop `Db`; ugnježden poziv se pridružuje spoljnom preko `$transactionDepth`).
Sporedan efekat: DEBUG zapisi koje `BaseRepository` upiše u `ps_log` tokom transakcije idu istom konekcijom, pa
nestaju sa `ROLLBACK`-om; ERROR zapis servisa nastaje posle `ROLLBACK`-a i ostaje (komentar 34-37).

### 13.7 Prekidač i brisanje

- **Prekidač** (`setActiveAction()`, 114-144): sopstvena ruta `plugin0_payment_method_active`; `is_active` mora biti
  `'0'` ili `'1'`; servis `setActive()` (`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Service/PaymentMethodService.php:283-301`) menja samo status. Isključena metoda
  ostaje u listi (prigušena) i ne nudi se kupcu.
- **Brisanje** (`deleteAction()`, 147-170): sopstvena ruta `plugin0_payment_method_delete`, tek posle potvrde u
  prozoru (šablon `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/payment_methods.html.twig:165-177`, `:221-235`). Kontroler prvo pročita metodu, da bi poruka
  imala ime, pa je obriše (159-161). Log zapis o brisanju nosi sve podatke metode (`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Service/PaymentMethodService.php:319-328`),
  da bi mogla da se rekonstruiše.

### 13.8 Log i ko je šta uradio

Svaka izmena prima ID zaposlenog (`$this->getContext()->employee->id`, kontroler 246-249) i ostavlja jedan INFO
zapis sa `user_id` (komponenta `PaymentMethods`): "Payment method created / updated / toggled / deleted". Neuspeo
upis ostavlja jedan ERROR zapis i baca `PaymentMethodWriteFailedException` bez SQL poruke
(`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Service/PaymentMethodService.php:358-374`).

### 13.9 Kako se metoda čuva

`/home/veljkozbiljic/logeecom/plugin0/src/Entity/PaymentMethodEntity.php`: tip `PaymentMethod`; indeksi `code`
(`index_1`), `type` (`index_2`), `isActive` (`index_3`), `sortOrder` (`index_4`) (linije 44-53); liste valuta i
zemalja su u JSON koloni `data`, jer Core ORM nema relacije (komentar 13-16). U ključu nema prodavnice ni okruženja:
metode važe za celu PrestaShop instalaciju i disconnect ih ne briše (komentar 21-22).

Pretvaranje entitet ↔ demo objekat: `PaymentMethodRepository::toDomain()` i `toEntity()` (232-257).

**U dev bazi 2026-10-16:**

| id | Name | Code | Type | Valute | Zemlje | Aktivna | Sort |
|---|---|---|---|---|---|---|---|
| 2 | Viza payment | `1` (Visa) | redirect | BZD, MXN, RSD, EUR | FR, RS | da | 1 |
| 4 | Appla Pay | `302` (Apple Pay) | redirect | RSD, BZD, INR, MXN, EUR | FR, RS, US | da | 2 |

"Appla Pay" se na checkout-u nikad ne prikazuje (poglavlje 16, pravilo 4).

### 13.10 Šta nam ova stranica znači

- **To je ponuda kupcu.** Metode sa ove stranice su jedine opcije koje plugin daje u koraku plaćanja (16). Name je
  tekst koji kupac vidi, Sort order je redosled u kom ih vidi.
- **Code je veza sa Worldline-om.** Kod je ID Worldline proizvoda (npr. `1` = Visa); na Worldline strani kupac vidi
  samo taj proizvod, jer `payment.php` šalje `PaymentProductId::parse($method->code)` (17.2). Kod koji Core ne zna
  znači da se metoda nikad ne nudi.
- **Valute i zemlje su ograničenje.** Metoda se nudi samo korpi u nekoj od svojih valuta, sa adresom za fakturu u
  nekoj od svojih zemalja.
- **Prekidač je "privremeno ne nudi", brisanje je "zauvek".** Isključena metoda ostaje sa svim podešavanjima.
- **Type** kaže kako se plaća: Redirect (kupac ide na Worldline stranu) radi; iFrame se čuva, ali se još ne nudi.
- Metode ne zavise od okruženja (Test ili Live) ni od konekcije: važe za celu PrestaShop instalaciju (13.9).

### 13.11 Kako smo je pravili

| Commit | Datum | Šta se promenilo |
|---|---|---|
| `90a8caf` | 2026-10-14 | prva verzija: stranica nad Core-ovim fiksnim katalogom Worldline proizvoda (`AdminAPI::payment()`), bez dodavanja i brisanja, sa sopstvenim ograničenjem zemalja i valuta (`PaymentMethodRestrictionEntity` u fajlovima `/home/veljkozbiljic/logeecom/plugin0/src/PaymentMethods/*`, danas obrisanim) |
| `7c0a6e3` | 2026-10-14 | izmene te prve verzije |
| `76d6d76` | 2026-10-14 | **preokret** (3.4): stranica je prepisana na metode koje admin sam pravi, kao u demu; nastaju `PaymentMethodForm`, `PaymentMethodEntity`, adapteri, `WorldlineProducts`, demo sloj u `lib/Logeecom`; `src/PaymentMethods/*` je obrisan |
| `87021ca` | 2026-10-15 | fajlovi stranice se ne menjaju, ali checkout počinje da koristi metode (`AvailablePaymentMethods`, 16) |
| posle toga | — | fajlovi stranice se nisu menjali (`git log`, 2026-10-16) |

Šta je iz čega u demu preneto (po komentarima u kodu plugina; demo fajlovi postoje na tim putanjama, provereno
2026-10-16):

| Demo projekat | plugin0 |
|---|---|
| `/home/veljkozbiljic/laravel-docker/resources/js/admin/PaymentMethodList.jsx`, `CodeTags.jsx`, `DeleteDialog.jsx` (isti folder) | `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/payment_methods.html.twig` |
| `/home/veljkozbiljic/laravel-docker/resources/js/admin/PaymentMethodForm.jsx`, `MultiSelect.jsx`, `ActiveSwitch.jsx` (isti folder) | `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/payment_method.html.twig` |
| `/home/veljkozbiljic/laravel-docker/app/Http/Controllers/Admin/PaymentMethodController.php` | `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/PaymentMethodsController.php` |
| `/home/veljkozbiljic/laravel-docker/app/Http/Requests/Admin/ListPaymentMethodsRequest.php` | `PaymentMethodsController::filterFrom()` (259-274) |
| `/home/veljkozbiljic/laravel-docker/app/Http/Requests/Admin/AbstractPaymentMethodRequest.php`, `/home/veljkozbiljic/laravel-docker/app/Rules/UniquePaymentMethodCode.php` | `/home/veljkozbiljic/logeecom/plugin0/src/Form/PaymentMethodForm.php` |
| `/home/veljkozbiljic/laravel-docker/app/Repositories/EloquentPaymentMethodRepository.php` | `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/PaymentMethodRepository.php` |
| `/home/veljkozbiljic/laravel-docker/src/PaymentMethods/` | `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/` (bez izmena) |

### 13.12 Kako je napravljena u kodu: svaka radnja, fajl po fajl

Slojevi su nacrtani u 13.2; ovde je za svaku radnju tačan put. Kontroler je
`/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/PaymentMethodsController.php`, servis
`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Service/PaymentMethodService.php`, skladište
`/home/veljkozbiljic/logeecom/plugin0/src/Adapter/PaymentMethodRepository.php`, rute u
`/home/veljkozbiljic/logeecom/plugin0/config/routes.yml`.

| Radnja | Ruta (linije u routes.yml) | Akcija kontrolera | Servis | Skladište | Odgovor |
|---|---|---|---|---|---|
| lista | `plugin0_payment_methods`, GET (17-22) | `listAction` 49-89 | `page()` 161-168, `presentPage()` 136-150 | `page()` 52-79 | `payment_methods.html.twig` |
| nova metoda | `plugin0_payment_method_create`, GET i POST (23-28) | `createAction` 91-100 → `form()` 177-243 | `create()` 210-230 | `add()` 97-108, pa `update()` za pomerene metode | forma ili preusmerenje na listu |
| izmena | `plugin0_payment_method_edit`, GET i POST (29-36) | `editAction` 102-111 → `form()` | `get()` 191-194, `update()` 247-269 | `find()` 81-86, `update()` 110-131 | forma ili preusmerenje |
| prekidač | `plugin0_payment_method_active`, POST (37-44) | `setActiveAction` 114-144 | `setActive()` 283-301 | `update()` | preusmerenje na listu |
| brisanje | `plugin0_payment_method_delete`, POST (45-52) | `deleteAction` 147-170 | `get()`, `delete()` 313-329 | `delete()` 133-147 | preusmerenje na listu |

Pomoćni delovi:

| Šta | Fajl | Uloga |
|---|---|---|
| pravila forme | `/home/veljkozbiljic/logeecom/plugin0/src/Form/PaymentMethodForm.php` | `empty()`, `fromMethod()`, `fromSubmitted()`, `validate()` 95-135, `data()` (13.3, 13.5) |
| izbor servisa | `/home/veljkozbiljic/logeecom/plugin0/src/Services.php:25-34` | servis sa adapterima, loggerom (komponenta `PaymentMethods`) i `SettingsService`-om |
| dozvoljene valute i zemlje | `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/ReferenceData.php` | kodovi koje servis prihvata |
| nazivi valuta i zemalja | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/ShopCountriesAndCurrencies.php` | tekst u filterima i formi (`names()`, kontroler 353-361) |
| ponuda za Code | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/WorldlineProducts.php` | Worldline proizvodi iz Core-a |
| naziv i opis tipa | `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Domain/Checkout/PaymentFlowFactory.php` | "Redirect", "iFrame" i rečenica ispod |
| veličina strane i prva valuta | demo `SettingsService` (Rows per page, Default currency) | 14 |
| entitet | `/home/veljkozbiljic/logeecom/plugin0/src/Entity/PaymentMethodEntity.php` | red tipa `PaymentMethod` (13.9) |

### 13.13 Šablon liste i ponašanje u browseru

`/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/payment_methods.html.twig`:

| Deo stranice | Linije | Šta radi |
|---|---|---|
| makro `codes` | 16-24 | kodovi valuta ili zemalja kao "čipovi": prva tri, ostali iza dugmeta `+N ▼` |
| makro `filter` | 26-36 | jedan padajući filter sa praznom opcijom "All ..." |
| lista se nije učitala | 44-45 | "The payment methods could not be loaded." (kontroler je uhvatio grešku) |
| nema nijedne metode | 46-52 | objašnjenje i dugme Add payment method, umesto tabele |
| GET forma filtera | 55-82 | skriveno polje `_token` (56), pretraga (58-62, najviše 64 znaka), Add payment method sa `back` (63-65), filteri Currency, Country, Type, Status (71-75), Apply i Clear filters (76-80) |
| filter ništa ne nalazi | 84-88 | "No payment methods match these filters" i Clear filters |
| tabela | 90-138 | kolone Order, Name, Code, Type, Currencies, Countries, Active; isključena metoda ima klasu `plugin0-off` (prigušen red, 108); Type prikazuje naziv iz servisa, a opis kao `title` (113) |
| prekidač | 116-127 | posebna POST forma po redu, ka `plugin0_payment_method_active`; skriveno polje nosi **ciljano** stanje (119) i `back` (120); bez JavaScript-a dugme iz `<noscript>` (125) |
| Edit i Delete | 128-133 | Edit je link sa `back`; Delete samo otvara potvrdu (`data-name`, `data-action`) |
| strane | 140-155 | "Showing a–b of n", Previous, "Page x of y", Next; adrese čuvaju filter (Twig `query\|merge`) |
| prozor za potvrdu brisanja | 161-179 | jedna forma za celu listu; JavaScript joj upiše adresu i ime metode |
| JavaScript | 182-246 | prekidač šalje formu čim se promeni (185-191); promena filtera odmah primenjuje filter (192-197); `+N` otvara i zatvara listu kodova, Escape i klik van zatvaraju (198-220); Delete otvara Bootstrap modal sa fokusom na Cancel, a bez jQuery-ja pita `window.confirm` (221-244) |

### 13.14 Šablon forme i ponašanje u browseru

`/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/payment_method.html.twig` (jedan šablon za novu metodu i
za izmenu):

| Deo stranice | Linije | Šta radi |
|---|---|---|
| makro `text` | 16-22 | tekstualno polje sa porukom greške ispod |
| makro `multiselect` | 25-47 | izbor kodova kao čipovi sa `×` i jedan select "Add…" sa samo neizabranim kodovima; opcija `*` = "All ..."; svaki čip nosi skriveno polje `currencies[]` odnosno `countries[]` |
| naslov | 53 | "New payment method" ili ime metode |
| forma | 55-57 | POST na `plugin0_payment_method_create` ili `plugin0_payment_method_edit`; `novalidate`, jer pravila proverava server; skriveno `back` |
| Name | 59 | do 255 znakova |
| Code | 61-80 | slobodan unos (do 64 znaka) i pored njega meni Worldline proizvoda; izbor iz menija upiše ID u polje |
| Type | 82-92 | radio dugme po tipu sa nazivom i opisom iz servisa |
| Currencies, Countries | 94, 96 | makro `multiselect` |
| Sort order | 98-103 | broj od 1 do 1 000 000 |
| Active | 105-112 | prekidač i reč Active / Inactive pored |
| Save i Cancel | 114-118 | Cancel je link nazad na isti prikaz liste |
| JavaScript | 124-222 | Cancel pita "Discard the changes...?" samo kad se nešto promenilo (127-137); meni proizvoda upisuje Code (139-146); reč pored prekidača (148-151); čipovi za valute i zemlje (153-219) |

Prostim rečima: Payment methods je demo stranica u PrestaShop odelu; svaka radnja ide istim putem: ruta, akcija
kontrolera, jedna metoda demo servisa, adapter, tabela plugina, pa šablon ili povratak na listu.

### 13.15 Kako da proveriš

- Unit testovi demo sloja: `/home/veljkozbiljic/logeecom/plugin0/tests/Unit/PaymentMethods/...` (poglavlje 26).
- Browser: napravi metodu sa kodom koji već postoji → poruka "Payment method code '...' is already taken."; sačuvaj
  sa zauzetim brojem → druge metode pomerene; isključi → red prigušen.
- Baza: `SELECT id, index_1 code, index_2 type, index_3 isActive, index_4 sortOrder FROM ps_plugin0_entity WHERE type = 'PaymentMethod';`
- Log: na stranici Logs zapisi komponente `PaymentMethods` sa `user_id`.

Prostim rečima: stranica je demo projekat u PrestaShop odelu; pravila su demo pravila, a čuvanje ide kroz Core-ov
repozitorijum u tabelu plugina.

---

## 14. Settings: šta znače četiri podešavanja i gde se čuvaju?

### 14.1 Običnim rečima

Tri podešavanja su iz demo projekta (Log level, Default currency, Rows per page), a četvrto, Payment action, je
Core-ovo: da li Worldline novac uzme odmah ili ga samo rezerviše, pa ga trgovac kasnije naplati sa stranice
narudžbine.

### 14.2 Sva četiri podešavanja

| Polje | Moguće vrednosti | Default | Gde se čuva | Ko ga čita | Danas u dev bazi |
|---|---|---|---|---|---|
| **Log level** | Debug, Info, Warning, Error | Info (Debug samo kad je `_PS_MODE_DEV_` uključen) | Core `ConfigEntity` ključ `minLogLevel`, kao Core broj 3/2/1/0 | `LoggerService` (koji zapis se upisuje) | nije sačuvano → Info |
| **Default currency** | valute uključene u prodavnici | `EUR` ako je prodavnica ima, inače prazno ("Choose a currency") | `ConfigEntity` ključ `defaultCurrency` | prazna forma nove metode plaćanja (prva izabrana valuta) | `RSD` |
| **Rows per page** | ceo broj 5-100 | 10 | `ConfigEntity` ključ `rowsPerPage` | lista Payment methods i stranica Logs | nije sačuvano → 10 |
| **Payment action** | SALE, FINAL_AUTHORIZATION, PRE_AUTHORIZATION | SALE | **dva mesta**: Core `PaymentSettingsEntity.paymentAction` (SALE ili FINAL_AUTHORIZATION) i plugin `ConfigEntity` ključ `cardAuthorizationMode` (FINAL_AUTHORIZATION ili PRE_AUTHORIZATION) | Core (status narudžbine, automatic capture, zahtev ka Worldline-u) i `HostedCheckoutProxy` | Core `FINAL_AUTHORIZATION` + `cardAuthorizationMode = PRE_AUTHORIZATION` → forma pokazuje PRE_AUTHORIZATION |

Izvori default-a: `SettingsService::DEFAULT_CURRENCY = 'EUR'` i `DEFAULT_ROWS_PER_PAGE = 10`
(`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Service/SettingsService.php:27-29`),
`defaultLogLevel()` (88-91), granice 5 i 100 (`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Domain/Settings.php:17-19`),
Core default SALE
(`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/AdminConfig/Services/GeneralSettings/GeneralSettingsService.php:86`).

**Zašto je Log level baš Core ključ `minLogLevel`.** `SettingsRepository` demo ključ `log_level` prevodi u Core-ov
sopstveni ključ (`/home/veljkozbiljic/logeecom/plugin0/src/Adapter/SettingsRepository.php:87-94`) i upisuje ga
Core metodom `saveMinLogLevel()` (linije 78-82), da Core-ov `getMinLogLevel()` uvek kaže isto što i Settings
stranica (komentar 16-20).

**Sačuvana vrednost koja više ne važi** (npr. valuta koju je prodavnica isključila, ili broj redova van granica)
čita se kao da je nema, pa važi default (`SettingsService::current()`, 55-69). Stranica zato nikad ne puca zbog
starog reda u bazi.

### 14.3 Čuvanje (Save)

`SettingsController::indexAction()`, `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/SettingsController.php:35-91`:

1. Unos se očisti (61-66) i proveri (`validate()`, 101-133). Poruke su iz demo `UpdateSettingsRequest`-a, npr.
   "The rows per page field must be at least 5."; za Payment action: "The payment action must be SALE,
   FINAL_AUTHORIZATION or PRE_AUTHORIZATION." (129).
2. Greška → forma se vrati sa unetim vrednostima i porukom ispod polja (67-70); ništa nije sačuvano.
3. `SettingsService::update(new Settings(...), $employeeId)` (73-80;
   `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Service/SettingsService.php:182-215`):
   - čuva samo vrednosti koje su se promenile;
   - za svaku promenu jedan zapis "Setting changed" sa `user_id`, `from`, `to`; promena Log level-a ide kao
     WARNING, ostale kao INFO (205-209);
   - `writeUnderBothLevels()` (262-273): zapisi o promeni nivoa se upisuju po detaljnijem od starog i novog nivoa,
     pa se novi nivo stavi na snagu (`LogLevelApplier`), da ni prelazak sa Error ni na Error ne prođe bez traga.
4. `PaymentActionSetting::save($storeId, $izbor)` (81; 14.5).
5. "Settings saved." i preusmerenje na GET.

**Pazi: dva upisa nisu jedna transakcija.** Demo podešavanja se sačuvaju prvo (73-80), Payment action posle (81).
Ako drugi upis ne uspe, admin vidi "Settings could not be saved. ...", ali prva tri podešavanja su već sačuvana.

### 14.4 Restore defaults

Posebna forma (`/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/settings.html.twig:94-96`), sa potvrdom (141-148). Kontroler (49-59):
`SettingsService::restoreDefaults()` obriše tri ključa (`SettingsRepository::clear()`,
`/home/veljkozbiljic/logeecom/plugin0/src/Adapter/SettingsRepository.php:53-59`) i upiše jedan zapis sa svim
promenama; zatim `PaymentActionSetting::save($storeId, SALE)`.

### 14.5 Payment action iznutra: zašto dve vrednosti

`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/PaymentActionSetting.php`.

Problem: trgovac bira jedno od tri, a Core u opštim podešavanjima zna samo dva (`PaymentAction::fromState` prihvata
`SALE` i `FINAL_AUTHORIZATION`; `PRE_AUTHORIZATION` Core zna samo kroz konfiguraciju metode `cards`, koju plugin0 ne
koristi; komentar 18-24). Rešenje, dve sačuvane vrednosti:

| Izbor u formi | Core `paymentAction` (`coreAction()`, 46-49) | plugin `cardAuthorizationMode` (`cardMode()`, 52-55) |
|---|---|---|
| SALE | `SALE` | `FINAL_AUTHORIZATION` (nebitno, SALE se ne dira) |
| FINAL_AUTHORIZATION | `FINAL_AUTHORIZATION` | `FINAL_AUTHORIZATION` |
| PRE_AUTHORIZATION | `FINAL_AUTHORIZATION` | `PRE_AUTHORIZATION` |

Nazad, iz dve vrednosti u izbor: `choice()` (58-65); ako Core kaže bilo šta osim `FINAL_AUTHORIZATION`, izbor je
SALE.

`save()` (90-133), redom:

1. Proveri izbor (92-94).
2. Pročita trenutna Core podešavanja (`getGeneralSettings()`, 96-100).
3. Ako je status za "delimično vraćeno" prazan (podešavanja sačuvana pre 2026-10-16), popuni ga sa `PS_OS_PAYMENT`
   (107-110; zašto, u poglavlju 19.4).
4. Sačuva Core `PaymentSettings` sa novom akcijom i **svim ostalim poljima prepisanim** iz trenutnih (112-127):
   automatic capture, broj pokušaja, surcharge, svih sedam statusa, template, slanje korpe, stranica potvrde. Tako
   promena akcije ne menja ništa drugo.
5. Upiše `cardAuthorizationMode` (132).

`cardAuthorizationMode()` (85-88) čita `HostedCheckoutProxy` pri svakom novom plaćanju; bez sačuvane vrednosti važi
`FINAL_AUTHORIZATION`, isto što Core i sam šalje.

**Važno: Core `PaymentSettings` se čuvaju po prodavnici i okruženju** (`PaymentSettingsEntity` ima indekse storeId i
mode), a Core repozitorijum podešavanja bez aktivne konekcije ne čuva ništa (CORE.md 6.4). Zato Settings stranica
postoji samo za povezanu prodavnicu, a prelazak Test ↔ Live znači druga Core podešavanja.

### 14.6 Šta izbor znači za novo plaćanje

| Izbor | Šta Worldline uradi | Worldline status posle plaćanja | Status narudžbine (dev ID-jevi) | Šta panel posle nudi |
|---|---|---|---|---|
| SALE | odmah naplati | 9 (CAPTURED) | "Payment accepted" (2) | samo refund |
| FINAL_AUTHORIZATION | samo rezerviše; capture mora biti pun, u roku od 7 dana | 5 (PENDING_CAPTURE) | "Awaiting bank wire payment" (10) | capture celog iznosa ili cancel |
| PRE_AUTHORIZATION | samo rezerviše; delimični capture i cancel, u roku od 30 dana | 5 | "Awaiting bank wire payment" (10) | delimični capture, cancel ostatka, refund naplaćenog |

Rokovi 7 i 30 dana su iz teksta polja (`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/PaymentActionSetting.php:15-16`,
`/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/settings.html.twig:81`); u Worldline dokumentaciji ih
danas nisam proveravao, **neprovereno**. Izbor važi samo za **nova** plaćanja.

### 14.7 Kako smo je pravili

| Commit | Datum | Šta se promenilo |
|---|---|---|
| `3aff0b0` | 2026-10-12 | prva Settings stranica sa dva polja, Log level i Rows per page, nad sopstvenim klasama plugina (`/home/veljkozbiljic/logeecom/plugin0/src/Settings/{SettingsService,Settings,LogLevel}.php`, danas obrisanim); nastaje `ConnectedController`; Logs dobija veličinu strane iz Rows per page |
| `7c0a6e3` | 2026-10-14 | treće polje, Default currency (prva verzija je novu valutu dodavala i u valute metoda plaćanja, `DefaultCurrencySpreadFailed`) |
| `76d6d76` | 2026-10-14 | sopstvene klase plugina su obrisane, a stranica radi nad demo `Logeecom\Settings` kroz adaptere (`SettingsRepository`, `LogLevelApplier`, `CoreLogger`) i `Services::settings()`; pravila i poruke su demo pravila |
| `06a7b91` | 2026-10-16 | četvrto polje, Payment action (`PaymentActionSetting`); po beleškama je prvo uveden FINAL_AUTHORIZATION (2026-10-15), a pre commit-a i treći izbor PRE_AUTHORIZATION (2026-10-16) (**iz beleški**) |

Šta je iz čega u demu (po komentarima u kodu; demo fajlovi postoje, provereno 2026-10-16):

| Demo projekat | plugin0 |
|---|---|
| `/home/veljkozbiljic/laravel-docker/resources/js/settings/SettingsScreen.jsx`, `/home/veljkozbiljic/laravel-docker/resources/js/settings/RestoreDialog.jsx` | `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/settings.html.twig` |
| `/home/veljkozbiljic/laravel-docker/app/Http/Controllers/Admin/SettingsController.php` | `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/SettingsController.php` |
| `/home/veljkozbiljic/laravel-docker/app/Http/Requests/Admin/UpdateSettingsRequest.php` | `SettingsController::validate()` (101-133) |
| `/home/veljkozbiljic/laravel-docker/app/Repositories/EloquentSettingsRepository.php` | `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/SettingsRepository.php` |
| `/home/veljkozbiljic/laravel-docker/app/Logging/StackLogLevelApplier.php` | `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/LogLevelApplier.php` |
| `/home/veljkozbiljic/laravel-docker/src/Settings/` | `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/` (bez izmena) |
| — (demo nema) | Payment action: `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/PaymentActionSetting.php` |

### 14.8 Kako je napravljena u kodu, sloj po sloj

```
browser: forma Save (action=save) ili forma Restore (action=restore)
   │  POST /admin174kob088ranecckhxg/index.php/modules/worldline/settings?_token=...
   ▼
ruta plugin0_settings (routes.yml:53-58)
   ▼
SettingsController::indexAction (SettingsController.php:35-91)          roditelj: ConnectedController
   ├─ GET          → values($service->current()) (163-178) → renderForm() (139-158)
   ├─ POST save    → validate() (101-133) → SettingsService::update() → PaymentActionSetting::save()
   └─ POST restore → SettingsService::restoreDefaults()               → PaymentActionSetting::save(SALE)
   │
   ├── TRI DEMO POLJA (Log level, Default currency, Rows per page)
   │   Services::settings() (Services.php:36-45) → Logeecom\Settings\Service\SettingsService
   │     ├─▶ SettingsRepositoryInterface ─▶ Adapter\SettingsRepository ─▶ Plugin0\Configuration
   │     │      ─▶ Core ConfigurationManager ─▶ BaseRepository ─▶ ps_plugin0_entity (tip Configuration)
   │     ├─▶ ReferenceDataRepositoryInterface ─▶ Adapter\ReferenceData (dozvoljene valute)
   │     ├─▶ LogLevelApplierInterface ─▶ Adapter\LogLevelApplier ─▶ LoggerService::useMinLogLevel()
   │     └─▶ Psr\Log\LoggerInterface ─▶ Adapter\CoreLogger('Settings') ─▶ Core Logger ─▶ LoggerService ─▶ ps_log
   │
   └── ČETVRTO POLJE (Payment action)
       Infrastructure\PaymentActionSetting (current 68-79, save 90-133)
         ├─▶ AdminAPI::generalSettings($storeId)->getGeneralSettings() / ->savePaymentSettings(...)
         │      ─▶ Core GeneralSettingsService ─▶ BaseRepository ─▶ ps_plugin0_entity (tip PaymentSettingsEntity, po prodavnici i okruženju)
         └─▶ Plugin0\Configuration::storeValue('cardAuthorizationMode') ─▶ ps_plugin0_entity (tip Configuration)
```

| Sloj | Fajl (puna putanja) | Najvažnije linije |
|---|---|---|
| ruta | `/home/veljkozbiljic/logeecom/plugin0/config/routes.yml` | 53-58 |
| kontroler | `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/SettingsController.php` | `indexAction` 35-91, restore 49-59, čuvanje 72-81, `validate` 101-133, `renderForm` 139-158, `values` 163-178 |
| izbor servisa | `/home/veljkozbiljic/logeecom/plugin0/src/Services.php` | `settings()` 36-45, `isDevMode()` 54-57 (od njega zavisi default Log level) |
| pravila (demo) | `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Service/SettingsService.php` | `current` 55-69, `logLevels` 143-166 (tekst efekta), `update` 182-215, `restoreDefaults` 226-250, `writeUnderBothLevels` 262-273 |
| vrednost podešavanja (demo) | `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Domain/Settings.php` | granice 17-19, provera u konstruktoru 29-42, `toPairs` 50-62 |
| ključevi i nivoi (demo) | `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Domain/SettingKey.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Domain/LogLevel.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Domain/Severity.php` | `SettingKey` 10-15, `LogLevel` 12-18, `Severity::isWritten` 41-46 |
| skladište podešavanja | `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/SettingsRepository.php` | `all` 30-41, `put` 46-51, `clear` 53-59, `read` 61-72, `write` 74-85, `coreKey` 87-94 |
| ključevi u bazi | `/home/veljkozbiljic/logeecom/plugin0/src/Configuration.php` | konstante 19-31, `getStoredValue`/`storeValue`/`removeValue` 66-81 |
| nivo odmah na snazi | `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/LogLevelApplier.php` | 17-20 |
| Payment action | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/PaymentActionSetting.php` | izbori 34-38, prevođenje 40-65, `current` 68-79, `cardAuthorizationMode` 85-88, `save` 90-133 |
| šablon | `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/settings.html.twig` | 14.9 |

**Ko posle čita podešavanja:**

| Podešavanje | Ko ga čita | Gde |
|---|---|---|
| Log level | `LoggerService` pri svakom zapisu (keš po zahtevu) | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/LoggerService.php:96-119` |
| Rows per page | lista metoda plaćanja i Logs stranica | `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Service/PaymentMethodService.php:166`, `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/LogsController.php:37` |
| Default currency | prazna forma nove metode plaćanja | `/home/veljkozbiljic/logeecom/plugin0/src/Form/PaymentMethodForm.php:37-51` |
| Payment action (Core deo) | Core pri pravljenju sesije i pri mapiranju statusa | `HostedCheckoutService::getPaymentSettings()`, `StatusUpdateService` (17, 19) |
| Payment action (kartični režim) | `HostedCheckoutProxy` pri svakom novom plaćanju | closure u `/home/veljkozbiljic/logeecom/plugin0/src/Bootstrap.php:142-144` |

**Dve provere iste valute.** Kontroler proverava da je valuta među dozvoljenim (`validate()`, 105-109), a demo
servis isto još jednom pri čuvanju (`SettingsService::update()`, 184-189). Ako se katalog promeni između te dve
provere, servis baci `InvalidArgumentException`, a kontroler je pokaže ispod polja (83-85).

**Šta se loguje, a šta ne.** Svaka promena tri demo polja ostavlja zapis "Setting changed" (komponenta `Settings`,
sa `user_id`, `from`, `to`; promena Log level-a kao WARNING), a Restore jedan zapis "Settings restored to defaults".
Promena **Payment action-a ne ostavlja zapis**: `PaymentActionSetting::save()` ne zove `Logger` (pretraga
2026-10-16), a DEBUG zapisi `BaseRepository`-ja se na nivou Info ne upisuju.

### 14.9 Šablon i ponašanje u browseru

`/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/settings.html.twig`:

| Deo stranice | Linije | Šta radi |
|---|---|---|
| forma Save | 20-21 | POST na `plugin0_settings`, `novalidate` (poruke su uvek sa servera), skriveno `action=save` |
| Log level | 26-38 | četiri izbora; svaka opcija nosi rečenicu efekta (`data-effect`, npr. "Info, Warning and Error entries are written"), koja se prikazuje ispod polja (37) |
| Default currency | 40-58 | valute uključene u prodavnici kao "EUR – Euro"; "Choose a currency" dok nijedna nije izabrana (46-48); "The shop has no currency enabled." kad ih nema (42-44) |
| Rows per page | 60-69 | broj od 5 do 100 |
| Payment action | 71-82 | tri izbora sa rečenicama iz `PaymentActionSetting::CHOICES`; ispod objašnjenje rokova (81) |
| dno kartice | 85-89 | Restore defaults (pripada posebnoj formi) i Save |
| forma Restore | 94-96 | skriveno `action=restore`; ne šalje vrednosti |
| JavaScript | 102-151 | tekst efekta (114-117); Save je onemogućen dok se ništa ne promeni (119-125, kao u demu); potvrda pre Restore, rečima demo RestoreDialog-a (140-148) |

Prostim rečima: Settings je demo stranica sa tri polja plus jedno Core polje; sve četiri vrednosti završe kao redovi u
`ps_plugin0_entity`, a čitaju ih logger, liste, forma metode i Core pri plaćanju.

### 14.10 Kako da proveriš

- Unit testovi: `/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Settings/Service/SettingsServiceTest.php` (demo pravila),
  `/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Settings/PaymentActionSettingTest.php` (podela izbora na dve vrednosti i nazad).
- Baza:
  `SELECT index_1 AS name, data FROM ps_plugin0_entity WHERE type = 'Configuration';` i
  `SELECT data FROM ps_plugin0_entity WHERE type = 'PaymentSettingsEntity';`
- Log: posle Save zapis "Setting changed" (komponenta `Settings`) za svaku promenu.
- Browser: Save je onemogućen dok se ništa ne promeni (JavaScript, `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/settings.html.twig:119-125`); "efekat" pored
  Log level-a se menja sa izborom (114-117).

Prostim rečima: tri podešavanja menjaju kako plugin radi u adminu, a Payment action menja kako Worldline obrađuje
novac kupca; ono se čuva u Core-u i u pluginu, jer Core sam ne zna treći izbor.

---

## 15. Logs: kako stranica čita log?

### 15.1 Šta nam ova stranica znači

- **Dnevnik plugina.** Tu se vidi šta su Core i plugin radili: greške (ERROR), napravljene narudžbine i promene
  statusa (`ShopOrderService`), pokušaji capture/refund/cancel (`PaymentManager`), izmene metoda plaćanja i
  podešavanja sa `user_id` zaposlenog (`PaymentMethods`, `Settings`). Kad nešto ne radi, ovo je prvo mesto gde se
  gleda (29).
- **Koliko je detaljno** odlučuje Log level sa Settings stranice: na default-u Info nema DEBUG zapisa (10.3).
- **Šta ovde nema:** PrestaShop-ovi sopstveni zapisi (nemaju prefiks `WORLDLINE`), ceo razgovor sa Worldline-om
  (`MonitoringLog`, 8.4) i stvari koje se ne loguju (povezivanje na Configuration stranici 12.9, promena Payment
  action-a 14.8).

### 15.2 Kako smo je pravili

| Commit | Datum | Šta se promenilo |
|---|---|---|
| `66d2834` | 2026-10-09 | pre same stranice: `BaseRepository` loguje svako čitanje i upis, a `LoggerService` ne preskače iste poruke (`allowDuplicate = true`) (MIDU-1218: "Core radnja proverljiva iz loga") |
| `43e9e38` | 2026-10-09 | stranica nastaje (MIDU-1218): `LogsController`, `LogReader`, `logs.html.twig`, zajednički layout sa bočnim menijem; fiksno 20 redova po strani; `LoggerService::PREFIX = 'WORLDLINE'` postaje zajednička konstanta pisca i čitača |
| `3aff0b0` | 2026-10-12 | kontroler nasleđuje `ConnectedController`; veličina strane je Rows per page; `LoggerService` počinje da preskače zapise ispod Log level-a |
| `76d6d76` | 2026-10-14 | Rows per page i Log level dolaze iz demo `SettingsService`-a (`Services::settings()`) |
| posle toga | — | fajlovi stranice se nisu menjali (`git log`, 2026-10-16) |

Po komentaru u šablonu, izgled je iz demo
`/home/veljkozbiljic/laravel-docker/resources/js/logs/LogsScreen.jsx`; demo kontroler je
`/home/veljkozbiljic/laravel-docker/app/Http/Controllers/Admin/LogsController.php`. Demo poslovni sloj za logove
nije povezan (15.6).

### 15.3 Kako je napravljena u kodu, sloj po sloj

```
browser: klik na Logs ili izbor nivoa u meniju
   │  GET /admin174kob088ranecckhxg/index.php/modules/worldline/logs?level=error&page=2&_token=...
   ▼
ruta plugin0_logs (routes.yml:59-64)
   ▼
LogsController::indexAction (LogsController.php:21-57)             roditelj: ConnectedController
   ├─ nivo iz adrese (30-33), strana (34)
   ├─ Services::settings()->rowsPerPage() (37) → demo SettingsService → SettingsRepository → ps_plugin0_entity (rowsPerPage)
   └─ (new LogReader())->page($level, $page, $perPage) (39)
         ├─ COUNT(*) i SELECT nad ps_log, uslov message LIKE 'WORLDLINE [NIVO]%' (LogReader.php:26-55)
         └─ parse() (62-94): nivo, komponenta, poruka, kontekst
   ▼
render logs.html.twig: entries, levels, level, pagination (43-56)
```

Pisac i čitač su dve strane istog formata: `LoggerService::logMessage()`
(`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/LoggerService.php:47-61`) piše
`WORLDLINE [NIVO] Komponenta: poruka | {kontekst}`, a `LogReader` taj isti tekst rastavlja nazad (prefiks je
zajednička konstanta `LoggerService::PREFIX`, linija 24). Ako se format pisanja promeni, mora i čitanje.

| Sloj | Fajl (puna putanja) | Najvažnije linije |
|---|---|---|
| ruta | `/home/veljkozbiljic/logeecom/plugin0/config/routes.yml` | 59-64 |
| kontroler | `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/LogsController.php` | 21-57 |
| čitanje loga | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/LogReader.php` | `LEVELS` 17, `page` 26-47, `whereLevel` 49-55, `parse` 62-94 |
| pisanje loga | `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/LoggerService.php` | `PREFIX` 24, `logMessage` 40-66 |
| veličina strane | demo `SettingsService::rowsPerPage()`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Service/SettingsService.php:98-103` | — |
| šablon | `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/logs.html.twig` | 15.4 |

Stranica nema poslovni servis: samo čita PrestaShop tabelu, pa je `LogReader` dovoljan.

Korak po korak, `LogsController::indexAction()`, `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/LogsController.php:21-57`:

1. Nivo iz adrese (`?level=error`) se pretvori u velika slova i mora biti jedan od `DEBUG`, `INFO`, `WARNING`,
   `ERROR` (`LogReader::LEVELS`, `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/LogReader.php:17`); inače
   "svi nivoi" (linije 30-33). Strana manja od 1 je prva (34).
2. Veličina strane je Rows per page (37).
3. `LogReader::page()` (`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/LogReader.php:26-47`) šalje dva upita nad `ps_log`:

   ```sql
   SELECT COUNT(*) FROM ps_log WHERE message LIKE 'WORLDLINE [ERROR]%';
   SELECT id_log, date_add, message FROM ps_log WHERE message LIKE 'WORLDLINE [ERROR]%'
    ORDER BY id_log DESC LIMIT 10 OFFSET 0;
   ```

   (**pojednostavljeno**: `DbQuery` ograničenje piše kao `LIMIT 0, 10`; za "svi nivoi" uslov je
   `LIKE 'WORLDLINE [%'`, linije 49-55).
4. `parse()` (62-94) rastavi tekst `WORLDLINE [NIVO] Komponenta: poruka | {kontekst}` na nivo, komponentu, poruku i
   kontekst (lepo formatiran JSON).
5. Šablon `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/logs.html.twig` prikazuje kolone Time,
   Level, Message (sa komponentom ispred) i Context (sklopivo "Show"). Meni nivoa ima celu adresu kao vrednost
   opcije, jer forma ne bi prenela `_token`.

Stranica samo čita: nema brisanja ni izmene zapisa.

### 15.4 Šablon i ponašanje u browseru

`/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/logs.html.twig`:

| Deo stranice | Linije | Šta radi |
|---|---|---|
| boje nivoa | 9 | ERROR crveno, WARNING žuto, INFO plavo, DEBUG sivo |
| filter za strane | 10 | trenutni nivo (`level`) ide u adrese Previous i Next |
| meni Level | 15-24 | "All levels" i četiri nivoa; vrednost svake opcije je cela adresa sa `_token`-om (19-22) |
| prazno | 26-29 | "No entries have been written yet.", "No entries at this level." ili "Page x is past the last page" |
| tabela | 31-64 | Time u obliku `d M Y, H:i:s` (44), Level kao značka (45), komponenta pa poruka (46-49), Context sklopiv ("Show", lepo formatiran JSON) ili crtica (50-59) |
| strane | 67-84 | "Showing a–b of n", Previous, "Page x of y", Next |
| JavaScript | 89-96 | izbor nivoa odmah otvara tu adresu (92-94) |

### 15.5 Šta stranica ne prikazuje

**Ko je šta uradio.** `PrestaShopLogger::addLog()` upiše ID zaposlenog iz Context-a u `ps_log.id_employee`
kad je zaposleni prijavljen
(`/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/PrestaShopLogger.php:152-157`), ali `LogReader` tu
kolonu ne čita. Ko je šta uradio se zato vidi samo kad poruka sama nosi `user_id` ili `employeeId` u kontekstu
(demo servisi i panel na narudžbini to rade).

### 15.6 Zašto nije demo `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Logs`

Demo `LogService`
(`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Logs/Service/LogService.php`) čita sopstvenu tabelu logova demo
projekta i beleži otvaranje i zatvaranje stranice; plugin piše u `ps_log`, pa ga čita `LogReader`. Po beleškama,
tiket traži kolonu konteksta, koju `LogReader` ima (**iz beleški**).

### 15.7 Kako da proveriš

- Browser: Logs, Level = Error → samo crveni zapisi; Level = All → svi `WORLDLINE` zapisi, najnoviji prvi.
- Promeni Rows per page na Settings stranici (npr. 5) → Logs prikazuje 5 redova po strani; a na samoj Logs stranici
  se pojavi zapis "Setting changed" (komponenta `Settings`).
- Isto iz baze: upiti u 29.2.

Prostim rečima: Logs stranica je pretraga po PrestaShop logu za poruke koje počinju sa `WORLDLINE`.

---

## 16. Checkout, korak 1: koje metode kupac vidi i zašto baš te?

### 16.1 Običnim rečima

Kad kupac stigne do koraka plaćanja, PrestaShop pita svaki modul plaćanja: "šta nudiš za ovu korpu?". plugin0
odgovara spiskom svojih metoda, ali samo onih koje za tu korpu stvarno mogu da prođu: uključene su, odgovaraju
valuti korpe i zemlji adrese za fakturu i mogu da se plate preko Worldline Hosted Checkout strane.

### 16.2 Dva sita, jedno za drugim

**Sito A, PrestaShop-ovo (pre nego što plugin uopšte bude pitan):**

1. Modul mora biti dozvoljen za zemlju adrese za fakturu: `Module::getPaymentModules()` spaja `ps_module_country`
   sa zemljom adrese za fakturu
   (`/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/module/Module.php:1738` i `:1763-1769`). U dev
   prodavnici: FR, US, RS.
2. `Plugin0::hookPaymentOptions()` (`/home/veljkozbiljic/logeecom/plugin0/plugin0.php:92`) odmah vraća prazno ako
   modul nije aktivan, ako nema korpe ili ako `checkCurrency()` ne prođe.
3. `checkCurrency()` (`/home/veljkozbiljic/logeecom/plugin0/plugin0.php:173-187`): valuta korpe mora biti među
   valutama modula (`ps_module_currency`; Payment → Preferences → Currency restrictions). U dev prodavnici: RSD,
   BZD, INR, MXN, EUR.

**Sito B, plugin-ovo:** `AvailablePaymentMethods::for()`
(`/home/veljkozbiljic/logeecom/plugin0/src/Checkout/AvailablePaymentMethods.php:46-59`) uzme sve metode redom po
sort order-u (`PaymentMethodService::list()`,
`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Service/PaymentMethodService.php:173-180`) i
zadrži samo one za koje važi svih šest uslova:

| # | Uslov | Linija | Zašto |
|---|---|---|---|
| 1 | metoda je aktivna | 51 | isključena metoda se ne nudi |
| 2 | tip je Redirect | 52 | tip iFrame (Worldline Hosted Tokenization) još nije povezan |
| 3 | Code je Worldline ID proizvoda koji Core podržava (`PaymentProductId::isSupported()`) | 53 | front controller `payment` od koda pravi `PaymentProductId` za Worldline stranu (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/PaymentMethod/PaymentProductId.php:95`) |
| 4 | proizvod **nije** u `PaymentProductId::SEPARATE_CAPTURE_SUPPORTED` | 54 | za te proizvode Core pada pri pravljenju sesije (objašnjenje ispod) |
| 5 | valuta korpe je u listi valuta metode | 55 | ograničenje metode iz forme |
| 6 | zemlja adrese za fakturu je u listi zemalja metode | 56 | isto; PrestaShop i sam po toj adresi bira module plaćanja |

Valuta je ISO kod valute korpe (`currencyOf()`, 62-65), zemlja je ISO kod zemlje adrese za fakturu
(`countryOf()`, 68-73).

**Uslov 4, mehanički.** Lista `SEPARATE_CAPTURE_SUPPORTED`
(`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/PaymentMethod/PaymentProductId.php:69`)
sadrži `hosted_checkout`, `302` Apple Pay, `5001` Bizum, Cetelem, Cofidis, Cpay, `cards`, `320` Google Pay, Klarna,
MB Way, Oney Bank Card, `840` PayPal, PostFinance Pay, Sofinco, Twint i WeChat Pay. Za njih Core transformer čita
"payment action" iz Core-ove konfiguracije te metode (`PaymentMethodConfigEntity`), koju plugin0 nema, jer koristi
demo metode, ne Core metode. **Pojednostavljeno:**

```php
// /home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/ApiFacades/PaymentProcessor/Proxies/Transformers/CreateHostedCheckoutRequestTransformer.php:76-77
if ($paymentProductId->isSeparateCaptureSupported()) {
    $config = null;                        // plugin0 nema Core konfiguraciju metode 302
    $config->getPaymentAction();           // "Call to a member function getPaymentAction() on null"
}
```

Kupac bi video "The payment could not be started", pa plugin takve metode uopšte ne nudi (komentar
`/home/veljkozbiljic/logeecom/plugin0/src/Checkout/AvailablePaymentMethods.php:22-26`).

### 16.3 Šta plugin vrati PrestaShop-u

Za svaku metodu koja prođe oba sita, jedna `PaymentOption`
(`/home/veljkozbiljic/logeecom/plugin0/plugin0.php:99-108`):

| Svojstvo | Vrednost | Šta znači |
|---|---|---|
| `setModuleName` | `plugin0` | čija je opcija |
| `setCallToActionText` | ime metode, npr. "Viza payment" | tekst pored radio dugmeta |
| `setAction` | `getModuleLink('plugin0', 'payment', [], true)` = `https://8-2-4.prestashop.veljko.ngrok.app/en/module/plugin0/payment` | kuda ide forma posle PLACE ORDER |
| `setInputs` | skriveno polje `method` = ID metode | koju metodu je kupac izabrao |

### 16.4 Šta to znači u dev prodavnici danas

- "Viza payment" (kod `1`) se nudi korpi u EUR, RSD, BZD ili MXN sa adresom za fakturu u FR ili RS.
- "Appla Pay" (kod `302`) se ne nudi nikad (uslov 4).
- **Worldline nije pitan** koje proizvode nudi za tu korpu u trenutku prikaza opcija. Sandbox nalog po beleškama
  nudi proizvode samo za EUR, GBP i CHF (**iz beleški**), pa korpa u RSD vidi "Viza payment", ali pravljenje
  sesije padne (`UNKNOWN_PRODUCT_ID`, **iz beleški**) i kupac dobije "The payment could not be started". Kraj do
  kraja zato danas radi samo sa korpom u EUR.

Mehanički, **pojednostavljeno**:

```php
// korpa: valuta RSD, adresa za fakturu RS
AvailablePaymentMethods::for('RSD', 'RS');
// "Viza payment": aktivna, redirect, '1' podržan, 1 nije separate-capture, RSD u listi, RS u listi → NUDI SE
// "Appla Pay":    aktivna, redirect, '302' podržan, ALI 302 jeste separate-capture                → NE NUDI SE
```

### 16.5 Kako da proveriš

- Browser: korpa u EUR sa adresom u RS → u koraku plaćanja "Viza payment"; promeni adresu za fakturu na zemlju
  koje nema u listi metode → opcija nestane.
- Unit testovi demo dela (`PaymentMethodServiceTest`) pokrivaju redosled; `AvailablePaymentMethods` sam nema unit
  test (zavisi od PrestaShop klasa `Cart`, `Currency`, `Address`).

Prostim rečima: kupac vidi metodu samo ako je uključena, ako odgovara valuti i zemlji i ako je Worldline proizvod
koji ovaj put plaćanja ume da obradi.

---

## 17. Checkout, korak 2: šta se desi kad kupac klikne PLACE ORDER?

### 17.1 Običnim rečima

Plugin još jednom proveri da korpa i metoda važe, pa zamoli Core da kod Worldline-a otvori "sesiju za plaćanje"
za tu korpu. Worldline vrati adresu svoje strane za plaćanje, i plugin na nju preusmeri kupca. Narudžbina se ovde
**ne** pravi.

### 17.2 Koraci

1. Klasična tema pošalje formu izabrane opcije: `POST https://8-2-4.prestashop.veljko.ngrok.app/en/module/plugin0/payment`,
   polje `method` = ID metode (po OKRUZENJE.md 14.7 to radi `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/themes/core.js` klasične teme; kod teme danas nisam
   čitao, **neprovereno**).
2. `Plugin0PaymentModuleFrontController::postProcess()`, `/home/veljkozbiljic/logeecom/plugin0/controllers/front/payment.php:29-84`:
   1. `Bootstrap::boot()` (31).
   2. Provere (33-44): modul aktivan, korpa postoji, ima kupca, adresu dostave i adresu za fakturu, valuta prolazi
      `checkCurrency()`. Inače nazad na prvi korak checkout-a (`index.php?controller=order&step=1`).
   3. Metoda iz forme mora biti među `AvailablePaymentMethods::forCart($cart)` (46-60): kupac je posle prikaza
      opcija mogao da promeni adresu ili valutu, ili je admin u međuvremenu isključio metodu.
   4. Poziv Core-a (63-69):

      ```php
      CheckoutAPI::get()->hostedCheckout((string) $this->context->shop->id)->createSession(
          new HostedCheckoutSessionRequest(
              PrestaShopCartProvider::for($cart),                                      // korpa kao Core Cart (17.3)
              $this->context->link->getModuleLink('plugin0', 'return', [], true),      // kuda Worldline vraća kupca
              PaymentProductId::parse($method->code)                                   // jedini proizvod na Worldline strani
          )
      );
      ```

   5. Neuspeh (71-81): poruka "The payment could not be started. Please try again or choose another payment
      method." i povratak na stranicu narudžbine; detalji greške su već u logu (komponenta `Core`).
   6. Uspeh (83): `Tools::redirect($response->getRedirectUrl())`: kupac ide na Worldline stranu.
3. U Core-u, `HostedCheckoutService::createSession()`
   (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/Services/HostedCheckout/HostedCheckoutService.php:47-63`):
   1. pročita 3-D Secure podešavanja, Core `PaymentSettings` (bez sačuvanih: `new PaymentSettings()`, dakle SALE;
      linije 69-73) i Core metode dostupne za korpu, za šta pita Worldline `GET /products` (linija 54);
   2. zove `HostedCheckoutProxyInterface::createSession()`, a to je **plugin0** `HostedCheckoutProxy`
      (poglavlje 21): Core transformer od korpe napravi SDK zahtev, plugin uskladi režime autorizacije, SDK pošalje
      `POST /v2/{PSPID}/hostedcheckouts`;
   3. Worldline vrati `hostedCheckoutId`, `RETURNMAC` i `redirectUrl`;
   4. Core upiše ID kupca (ako nije gost) i naziv metode u transakciju (55-60) i sačuva je (61).
4. U bazi nastaje red `PaymentTransaction` u `ps_plugin0_entity`: `merchantReference` = ID korpe, payment ID =
   `hostedCheckoutId` + `_0` (`PaymentId::parse()` dodaje `_0`,
   `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Payment/PaymentId.php:40`),
   `returnHmac` = `RETURNMAC`, `statusCode` = 0.

### 17.3 Korpa kao Core `Cart`: šta tačno ide Worldline-u

`/home/veljkozbiljic/logeecom/plugin0/src/Checkout/PrestaShopCartProvider.php` implementira Core `CartProvider`
(jedna metoda, `get(): Cart`). Core od rezultata pravi Worldline "order" (CORE.md 6.3, `OrderTransformer`).

| Deo Core `Cart` | Iz čega u PrestaShop-u | Linije |
|---|---|---|
| `merchantReference` | ID korpe | 77 |
| `total` | `Cart::getOrderTotal(true, Cart::BOTH)` kao `Amount::fromFloat` | 65, 78 |
| `totalInEUR` | isti iznos preračunat u EUR (`Tools::convertPriceFull`); `null` kad prodavnica nema EUR; Core ga koristi za 3-D Secure izuzeća po iznosu | 79, 160-171 |
| kupac | e-mail, telefon (mobilni, inače fiksni sa adrese za fakturu), adresa za fakturu, ID kupca, da li je gost, `locale` jezika korpe | 67-86 |
| stavke | svaki proizvod: ID `idProduct-idAtribut`, ime, referenca, cena bez i sa porezom, količina; plus "Gift wrapping" kad postoji | 104-135 |
| dostava | cena bez i sa porezom i adresa dostave | 88-95 |
| popust | `Cart::getOrderTotal(true, Cart::ONLY_DISCOUNTS)`, kad je veći od nule | 74, 96 |

Adresa (141-154): PrestaShop nema posebno polje za kućni broj, pa `houseNumber` ostaje prazan; `state` je ime
regiona ako ga adresa ima.

**Iznos u najmanjim jedinicama.** Core `Amount` je ceo broj u najmanjim jedinicama valute: 12,34 EUR je `1234`.
`Amount::fromFloat()` množi sa `10^decimale` i zaokružuje
(`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Checkout/Amount.php`).
Broj decimala daje `Currency::getMinorUnits()` (2 za EUR i RSD, 0 za JPY, 3 za BHD).

**Zaokruživanje.** `for()` (56-59) umota provider u Core `RoundingTotalsCartProvider` i `MemoryCachingCartProvider`.
Prvi doda stavku `rounding` (ili poveća popust) kad se zbir stavki, dostave i popusta zbog zaokruživanja po stavci
ne slaže sa ukupnim iznosom; drugi zapamti rezultat, jer Core `get()` zove više puta u istom zahtevu. Primer iz
beleški: korpa 9, 22,944 → stavke 2294 + `rounding` 6 = 2300 RSD (**iz beleški**).

### 17.4 Zašto se narudžbina ne pravi ovde

Detaljno u poglavlju 19. Ukratko: dok Worldline ne potvrdi plaćanje, nema šta da se naručuje; otkazan pokušaj ne
sme da ostavi otkazanu narudžbinu i potrošenu korpu (komentar
`/home/veljkozbiljic/logeecom/plugin0/controllers/front/payment.php:15`).

### 17.5 Kako da proveriš

- Browser: posle PLACE ORDER adresa u browseru je Worldline strana `payment.preprod.direct.worldline-solutions.com`.
- Baza: novi red `PaymentTransaction` sa `statusCode` 0 i `merchantReference` = ID korpe (poglavlje 28).
- Apache access log: `POST /en/module/plugin0/payment ... 302` (redirect na Worldline).
- Kad je `debugMode` uključen: `MonitoringLog` red sa `POST .../hostedcheckouts` i telom zahteva (poglavlje 28).

Prostim rečima: PLACE ORDER kod plugin0 ne pravi narudžbinu; samo otvori plaćanje kod Worldline-a i pošalje kupca
tamo.

---

## 18. Checkout, korak 3: šta se desi kad se kupac vrati sa Worldline strane?

### 18.1 Običnim rečima

Worldline vrati kupca na adresu plugina i u adresu doda dve vrednosti: ID sesije (`hostedCheckoutId`) i tajni
potpis te sesije (`RETURNMAC`). Ta adresa je javna: bilo ko može da je otvori sa izmišljenim vrednostima. Zato plugin
adresi ne veruje ništa osim da po te dve vrednosti nađe svoju transakciju, a šta se stvarno desilo uvek pita
Worldline.

Primer prave adrese (Apache access log 2026-10-16):
`GET /en/module/plugin0/return?RETURNMAC=c625807e-85e0-4a80-9af9-dbf33991f061&hostedCheckoutId=10300628270`.

**Pazi (2026-10-19):** Worldline preprod je u odgovoru na `POST /hostedcheckouts` polje preimenovao u `returnmac`
(malim slovima), a SDK `CreateHostedCheckoutResponse` mapira samo `RETURNMAC`, pa Core sačuva prazan `returnHmac`.
Povratak sa `RETURNMAC=...` u adresi tada ne nalazi transakciju (korpa 18, plaćanje 10300867120, ps_log 1524) i kupac
vidi "nije potvrđeno". Popravljeno istog dana u pluginu: `Infrastructure\HostedCheckoutProxy` odgovor čita kroz
`Infrastructure\Sdk\HostedCheckoutClient` i `Infrastructure\Sdk\CreateHostedCheckoutResponse`, koji prihvata oba oblika.
Plaćanja započeta pre popravke (korpa 18 / narudžbina #18) i dalje imaju prazan `returnHmac`.

### 18.2 Koraci

`Plugin0ReturnModuleFrontController::postProcess()`, `/home/veljkozbiljic/logeecom/plugin0/controllers/front/return.php:50-122`:

1. `Bootstrap::boot()` (52).
2. Zapamti ID prijavljenog kupca (55), jer `validateOrder` kasnije menja kupca u `Context`-u (komentar 54).
3. Pročita parametre kroz `stringParam()` (170-175): samo tekst; niz (npr. `RETURNMAC[]=x`) ili izostavljen
   parametar postaje `''`.
4. **Provera oblika** (57-63): `hostedCheckoutId` mora odgovarati `/^\d+(_\d+)?$/` (linija 35; isti obrazac kao
   privatni `PaymentId::VALID_FORMAT_PATTERN` u
   `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Payment/PaymentId.php:21`,
   pa `PaymentId::parse()` posle ne baca izuzetak), a `RETURNMAC` ne sme biti prazan. Inače: nazad na korak
   plaćanja sa "The payment could not be confirmed...".
5. `PaymentId::parse($hostedCheckoutId)` (65): `10300628270` postaje `10300628270_0`.
6. **`updateOrderStatus($paymentId, $returnMac)`** (68). U Core-u `StatusUpdateService::updateOrderStatus()`
   (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/Services/Payment/StatusUpdateService.php:58-98`):
   1. nađe transakciju po prodavnici, payment ID-ju **i** RETURNMAC-u (60, 99-106); ne nađe →
      `PaymentTransactionNotFoundException` ("Payment transaction for payment ID ... not found.") → error odgovor →
      plugin pokaže "could not be confirmed" (69-73);
   2. pita Worldline `GET /payments/{id}/details` (63), kroz plugin0 `PaymentsProxy` (poglavlje 21);
   3. upiše novi status i naziv metode u transakciju (64-66);
   4. izračuna novi status narudžbine (`getFullStatusMapping`, 67); prazan → kraj (68-70);
   5. ako je status 5 i podešen je automatski capture, upiše kad ga treba uraditi (71-79; plugin0 ostavlja
      **default** "nikad", a Core-ov pozadinski auto-capture ionako traži red zadataka);
   6. ako je nešto vraćeno → `refundShopOrder` (81-87);
   7. ako treba praviti narudžbinu (`shouldTryOrderCreation`, 107-119: plaćen ceo iznos i status 5 ili 9; ili
      plaćanje u obradi kome je isteklo vreme čekanja) → Core brava (`lockOrderCreation`) →
      `ShopOrderService::createShopOrder` → otključavanje (88-92, 134-145);
   8. ako je otkazano ili odbijeno (1, 2, 6) → `cancelShopOrder` (93-96);
   9. inače → `updateStatus` (97).
7. **`getPaymentOutcome($paymentId, $returnMac)`** (75): `isWaiting()` je istina dok je status "u obradi" (kodovi
   0, 4, 46, 50, 51, 52, 55, 91, 92, 99) i nije isteklo vreme čekanja
   (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/BackgroundProcesses/WaitPaymentOutcome.php:21-24`).
   Vreme čekanja je 10 sekundi od povratka kupca (`MAX_PENDING_TRANSACTIONS_WAIT_TIME = 10`,
   `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/Services/Payment/StatusUpdateService.php:38`); dok povratak nije upisan, računa se od nastanka transakcije plus 2 sata
   (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/Services/Payment/StatusUpdateService.php:124-133`).
8. Ako se još čeka: **`startWaitingForOutcome()`** (78) → Core `WaitPaymentOutcomeProcess::startWaiting()`
   (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/BackgroundProcesses/WaitPaymentOutcomeProcess.php:45-54`):
   upiše vreme povratka, spava po 5 sekundi (`SLEEP_INTERVAL = 5`, linija 24) dok čekanje traje, pa još jednom
   `updateOrderStatus`. Plugin zatim ponovo pita za ishod (85). Kupac pritom čeka najviše desetak sekundi.
9. Ishod neuspešan → "could not be confirmed" (88-92).
10. **Otkazano ili odbijeno** (`isCanceledOrRejected()`: 1, 2, 6; 94-112): za status 2 crvena poruka "The payment
    was refused. Your cart is unchanged: you can try again or choose another payment method.", za 1 i 6 žuta "The
    payment was cancelled. Your cart is unchanged: you can choose a payment method again."; korpa ostaje, nazad na
    korak plaćanja. Šta biva sa narudžbinom odlučuje Core (`cancelShopOrder`); plugin samo bira tekst poruke
    (komentar 37-41).
11. **Narudžbina postoji za korpu** (`Order::getIdByCartId`, 114) → `toConfirmation()` (138-152): samo ako je
    prijavljeni kupac iz koraka 2 vlasnik narudžbine, preusmerenje na `order-confirmation` sa `id_cart`, `id_module`,
    `id_order` i `key` (secure key kupca), isto kao posle `ps_wirepayment`; inače na početnu stranu. Tako neko ko
    otvori tuđu, već iskorišćenu povratnu adresu ne vidi tuđu narudžbinu.
12. **Narudžbina još ne postoji** → `stillProcessing = true` (121) → `initContent()` (124-132) prikaže
    `/home/veljkozbiljic/logeecom/plugin0/views/templates/front/return.tpl`: "Payment is being processed ... Your
    order will be created as soon as the payment is confirmed. Please do not pay again for the same cart."

**Zašto plugin prvo zove `updateOrderStatus`, pa tek onda čeka.** Core-ova petlja čekanja (korak 8) gleda samo
lokalni status transakcije, a on ostaje 0 dok neko ne pita Worldline. Bez webhook-a niko ne pita, pa bi kupac uvek
čekao punih ~10 sekundi i tek onda dobio ishod (CORE.md, zamka 26). Kad plugin odmah pita Worldline, plaćeno
plaćanje se vidi odmah, a čeka se samo kad je plaćanje stvarno u obradi.

**Jedan povratak, više obrada istog ishoda.** U koraku 8 Core zove `updateOrderStatus` i unutar `getPaymentOutcome`
(`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/BackgroundProcesses/WaitPaymentOutcomeProcess.php:72-75`) i na kraju čekanja (53). Zato `ShopOrderService` mora da podnese isti
ishod više puta (poglavlje 19.3).

### 18.3 Bezbednost povratne adrese

| Šta neko pokuša | Šta se desi | Zašto |
|---|---|---|
| adresa bez parametara, `hostedCheckoutId` koji nije broj, `RETURNMAC[]=x` | nazad na korak plaćanja, Core se ne zove | provera oblika, korak 4 |
| prazan `RETURNMAC` | isto | Core uslov po `returnHmac` dodaje **samo kad nije `null`** (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/DataAccess/PaymentTransaction/PaymentTransactionRepository.php:101-103`); bez plugin provere transakcija bi se tražila samo po payment ID-ju |
| pravi ID, izmenjen `RETURNMAC` (ili obrnuto) | "Payment transaction ... not found", nazad, ništa se ne menja | Core traži par ID + RETURNMAC |
| tuđa, prava povratna adresa (već plaćena korpa) | Core osveži status (ne menja ništa, idempotentno); kupac ide na početnu, ne na tuđu potvrdu | `toConfirmation()` poredi vlasnika narudžbine |
| izmenjen status u adresi | nema efekta | status se nikad ne čita iz adrese, uvek `GET /payments/{id}/details` |

Po beleškama provereno na sandbox-u 2026-10-14 i u browseru 2026-10-15: pogrešan RETURNMAC i tuđi ID daju "not
found"; adresa bez parametara, sa lažnim RETURNMAC-om, sa `hostedCheckoutId` koji nije broj i sa `RETURNMAC[]`
vraća 302 na `/en/order` (**iz beleški**).

Mehanički, zašto je prazan RETURNMAC opasan, **pojednostavljeno**:

```php
// Core PaymentTransactionRepository::findPaymentTransactionEntity()
$filter->where('transactionId', '=', '10300628270');
if ($returnHmac !== null) {                 // null → ovaj uslov se NE dodaje
    $filter->where('returnHmac', '=', $returnHmac);
}
// bez plugin provere: ko zna samo payment ID, nađe transakciju
```

### 18.4 Ponovljen povratak (idempotentnost)

Ista povratna adresa otvorena drugi put: Core ponovo pita Worldline i zove `ShopOrderService`, ali on ne pravi drugu
narudžbinu, ne dodaje red istorije, uplatu ni belešku (poglavlje 19.3), pa kupac ponovo vidi potvrdu iste narudžbine.
Po beleškama provereno 2026-10-15 (korpa 10, narudžbina 9), **iz beleški**.

### 18.5 Poznat kozmetički nedostatak

Kad je korpa već naručena (npr. izmenjen RETURNMAC za već plaćenu korpu), poruka "The payment could not be
confirmed..." se ne vidi: prazna korpa sa `/en/order` odmah ide na `/en/cart?action=show`; narudžbina i statusi
pritom ostaju netaknuti (**iz beleški**, 2026-10-15).

### 18.6 Kako da proveriš

- Browser, uspeh: Visa `4330264936344675` (test kartica iz Worldline dokumentacije, **iz beleški**), 3-D Secure bez
  dodatnog koraka → potvrda narudžbine.
- Browser, otkazivanje: Cancel na Worldline strani → žuta poruka, korpa ostaje.
- Browser, odbijanje: odbijen 3-D Secure u Worldline simulatoru → crvena poruka.
- Ponovo otvori istu povratnu adresu → ista potvrda, bez nove narudžbine.
- Promeni jedan znak u `RETURNMAC` → nazad, ništa se ne menja.
- Baza: `PaymentTransaction.statusCode` posle povratka (9, 5, 1 ili 2), `ps_orders` za korpu, `ps_order_history`.
- Log: "Order created from cart after Worldline payment" (komponenta `ShopOrderService`).

Prostim rečima: povratak kupca je samo "okidač" da plugin pita Worldline šta se desilo; od odgovora zavisi da li
nastaje narudžbina, poruka o otkazivanju ili stranica "obrađuje se".

---

## 19. Kada nastaje narudžbina i ko joj menja status?

### 19.1 Običnim rečima

Narudžbina nastaje tek kad Worldline potvrdi da je plaćen ceo iznos. Do tada je to samo korpa. Statuse narudžbine
menja samo jedna klasa plugina, i to samo kad je Core zatraži; Core odlučuje koji status, plugin ga samo upiše
pazeći da ništa ne uradi dvaput.

### 19.2 Zašto posle plaćanja, a ne pre

1. **Core je tako napravljen.** `StatusUpdateService` zove `createShopOrder` tek kad je plaćen ceo iznos i status
   5 ili 9 (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/Services/Payment/StatusUpdateService.php:107-119`);
   ugovor `ShopOrderService` kaže da narudžbinu pravi "od pripadajuće korpe ako već ne postoji"
   (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Integration/ShopOrderService.php:23`).
2. **PrestaShop dozvoljava jednu narudžbinu po korpi.** `PaymentModule::validateOrder()` za korpu koja već ima
   narudžbinu zove `die()`
   (`/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/PaymentModule.php:250-256`). Narudžbina pre
   plaćanja bi za svaki otkazan pokušaj ostavila otkazanu narudžbinu, skidala i vraćala zalihe, slala mejlove i
   tražila novu korpu za novi pokušaj.
3. **Cena ove odluke:** ako kupac plati i zatvori browser pre povratka, narudžbina ne nastaje dok neko ne pozove
   `updateOrderStatus`. To bi radili webhook ili Core-ova pozadinska provera, a **nijedno nije povezano**
   (poglavlje 32). Takvo plaćanje danas treba primetiti u Merchant Portal-u.

### 19.3 `ShopOrderService`: jedino mesto koje pravi narudžbinu i menja status

`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/ShopOrderService.php`. Zove ga samo Core, iz
`StatusUpdateService::updateOrderStatus()`. `$newState` je ID PrestaShop statusa koji je Core već izabrao; plugin
status plaćanja ne tumači (komentar 20-28).

| Metoda | Linije | Šta radi |
|---|---|---|
| `createShopOrder()` | 47-61 | pod bravom korpe: ako narudžbina postoji, samo `changeState()`; inače `validateOrder()` |
| `updateStatus()`, `cancelShopOrder()`, `refundShopOrder()` | 63-76 | sve tri → `changeStateOfCartOrder()` (122-132): pod bravom korpe, `changeState()` samo ako narudžbina postoji |
| `validateOrder()` | 86-119 | pravi narudžbinu (detaljno ispod) |
| `changeState()` | 134-163 | menja status, ali samo kad ima smisla (detaljno ispod) |
| `withCartLock()` | 170-184 | MySQL brava po korpi |
| `orderIdForCart()`, `hasBeenInState()`, `currentState()` | 191-214 | čitanja baze bez keša |

**`validateOrder()`, korak po korak:**

1. Učita korpu i modul; korpa mora postojati, modul mora biti `PaymentModule` i aktivan (92-94). Ovo se proverava
   unapred jer PrestaShop-ov `validateOrder()` u tim slučajevima zove `die()` umesto da baci izuzetak
   (`/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/PaymentModule.php:245-261`), a `die()` bi ubio
   ceo zahtev bez traga (CORE.md, zamka 31).
2. Status mora postojati (96-98).
3. `$module->validateOrder(...)` (101-111) sa argumentima:
   - ID korpe i ID statusa;
   - **plaćeni iznos = iznos koji je Worldline naplatio** (`$paymentDetails->getAmount()->getPriceInCurrencyUnits()`);
     kad se ne slaže sa korpom (kupac je u drugom tabu promenio korpu), PrestaShop sam postavi status
     `PS_OS_ERROR` ("Payment error");
   - naziv metode od Worldline-a (npr. "Visa"), inače ime modula;
   - interna beleška `Worldline payment ID: 10300628270_0`;
   - `['transaction_id' => paymentId]`;
   - valuta korpe;
   - `false` za `$dont_touch_amount`: PrestaShop plaćeni iznos zaokruži na preciznost prodavnice
     (`/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/PaymentModule.php:215` i `:1018`);
   - secure key korpe (PrestaShop ga poredi sa korpom, linije 258-261).
4. INFO zapis "Order created from cart after Worldline payment" sa `cartId`, `orderId`, `paymentId`, `orderState`
   (113-118).

**`changeState()` i idempotentnost** (134-163). Status se **ne** menja kad:

1. Core nije dao status (`''` ili 0) ili taj status ne postoji (139);
2. narudžbina je **već nekad bila** u tom statusu (`ps_order_history`, `hasBeenInState()`, 199-206): ponovljen
   poziv ne dodaje red istorije ni mejl, i ne vraća narudžbinu koju je trgovac već pomerio dalje;
3. narudžbina je trenutno u `PS_OS_ERROR` (143-151): to stanje je postavio PrestaShop zbog neslaganja iznosa, i
   plugin ga ne dira automatski (WARNING u logu).

Inače: `OrderHistory::changeIdOrderState($stateId, new Order($orderId), true)` i `addWithemail(true)` (153-156;
PrestaShop pošalje mejl ako ga status traži) i INFO zapis "Order status changed after Worldline payment update".

**Brava po korpi.** Isti ishod stiže više puta i ponekad istovremeno (dva povratka, povratak i panel). Core ima
svoju bravu, ali ona nije atomična: prvo proveri da li brava postoji, pa je tek onda upiše, nad tabelom bez unique
ključa (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/DataAccess/PaymentTransaction/PaymentTransactionRepository.php:111-137`).
Mehanički, **pojednostavljeno**:

```
zahtev A: selectOne(brava)  → nema
zahtev B: selectOne(brava)  → nema          (A još nije upisao)
zahtev A: save(brava)       → "imam bravu"
zahtev B: save(brava)       → "imam bravu"  → obe strane bi napravile narudžbinu
```

Zato plugin sve izmene jedne korpe radi pod MySQL bravom
`SELECT GET_LOCK('plugin0_cart_<idKorpe>_<imeBaze>', 10)` i `RELEASE_LOCK` (170-184): MySQL garantuje da bravu
istog imena drži samo jedna konekcija. Ime sadrži ime baze jer važi za ceo MySQL server (u ovom kontejneru su i
`magento`, `shopware`, `wordpress`) i seče se na 64 znaka. Ako se brava ne dobije za 10 sekundi, baca se izuzetak.

**Čitanja bez keša** (186-214). `Order::getHistory` i `Cart::orderExists` pamte rezultat do kraja zahteva, pa bi
drugi poziv u istom zahtevu (Core ih pravi dva) video stanje pre prvog. Zato tri upita idu direktno u
`ps_orders` i `ps_order_history` sa `Db::getValue(..., false)` (drugi argument isključuje keš).

**Kako da proveriš da je ovo jedino mesto** (zahtev tiketa MIDU-1221):

```bash
grep -rn "validateOrder\|changeIdOrderState\|setCurrentState" /home/veljkozbiljic/logeecom/plugin0/src /home/veljkozbiljic/logeecom/plugin0/controllers /home/veljkozbiljic/logeecom/plugin0/plugin0.php
```

Očekivano: pogoci samo u `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/ShopOrderService.php` (i reč
`validateOrder` u komentarima drugih fajlova).

**Webhook (od 2026-10-19):** isti `StatusUpdateService::updateOrderStatus` zove i Core `WebhookService` kad Worldline
pošalje događaj na `controllers/front/webhook.php`, pa narudžbina nastaje ili menja status i bez kupca i bez admin
panela. Plugin oko toga dodaje: zaključavanje po transakciji (`Infrastructure\StatusUpdateService`), preskakanje već
obrađenog događaja (`Webhook\WebhookService`, evidencija `Entity\WebhookEventEntity`) i prepoznavanje greške
potpisa (`Infrastructure\WebhookTransformer`). Ceo tok i razlozi: `docs/WEBHOOK.md`.

### 19.4 Koji Worldline status daje koji status narudžbine

Mapiranje radi Core (`StatusMappingService`,
`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/OrderStatusMapping/StatusMappingService.php:34-68`),
a ID-jeve PrestaShop statusa daje plugin: dok Core podešavanja nisu sačuvana, iz
`StoreService::getDefaultOrderStatusMapping()` (`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/StoreService.php:56-67`);
posle prvog Save na Settings stranici, iz sačuvanog `PaymentSettingsEntity` (danas: captured 2, error 8, pending
10, authorized 10, cancelled 6, refunded 7, partially refunded 2).

| Worldline statusCode | Core grupa | Ključ u Core podešavanjima | PrestaShop konstanta | ID i naziv u dev prodavnici |
|---|---|---|---|---|
| 9 | naplaćeno (`completed`) | `paymentCapturedStatus` | `PS_OS_PAYMENT` | 2 "Payment accepted" |
| 5 | autorizovano (`authorized`) | `paymentAuthorizedStatus` | `PS_OS_BANKWIRE` | 10 "Awaiting bank wire payment" |
| 1, 2, 6 | otkazano ili odbijeno | `paymentCancelledStatus` | `PS_OS_CANCELED` | 6 "Canceled" |
| 7, 8, 85 | vraćeno | `paymentRefundedStatus` | `PS_OS_REFUND` | 7 "Refunded" |
| 0, 4, 46, 50, 51, 52, 55, 91, 92, 99 | u obradi | `paymentPendingStatus` | `PS_OS_BANKWIRE` | 10 "Awaiting bank wire payment" |
| vraćeno > 0 i manje od naplaćenog | delimično vraćeno | `paymentPartiallyRefundedStatus` | `PS_OS_PAYMENT` (izbor plugina od 2026-10-16) | 2 "Payment accepted" |
| sve ostalo | — | — | — | `''`: narudžbina se ne menja |

`paymentErrorStatus` (`PS_OS_ERROR` = 8) postoji u podešavanjima, ali ga mapiranje ne vraća; "Payment error"
postavlja PrestaShop sam u `validateOrder` kad se iznos ne slaže. Za "autorizovano" i "u obradi" PrestaShop nema
bolji ugrađeni status od "Awaiting bank wire payment"; to je izbor plugina (`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/StoreService.php:61-62`).

**Dve zamke u mapiranju, odvojeno:**

1. **"Delimično vraćeno" ima prednost nad svim ostalim.** `getFullStatusMapping()` čim je vraćeno više od nule, a
   manje od naplaćenog, vrati status za "delimično vraćeno" **pre** mapiranja po kodu (linije 40-46). Kad je taj
   status prazan, `StatusUpdateService` ne menja narudžbinu uopšte (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/Services/Payment/StatusUpdateService.php:68-70`). Do 2026-10-16
   `StoreService` ga je davao prazan, pa je narudžbina 16 ostala "autorizovano" iako je plaćanje bilo 9 (**iz
   beleški**). Rešenje u pluginu: default `PS_OS_PAYMENT` (`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/StoreService.php:65`) i popuna praznog u već
   sačuvanim podešavanjima (`/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/PaymentActionSetting.php:107-110`).
2. **Pun povraćaj Core prepoznaje samo kad je vraćen ceo autorizovani iznos** (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/Services/Payment/StatusUpdateService.php:82`
   poredi vraćeno sa `getAmount()`). Posle delimične naplate (npr. 51,00 od 68,93) i povraćaja svih 51,00 to ne bi
   bilo "pun povraćaj". To rešava plugin0 `PaymentsProxy` (poglavlje 21, problem 2).

### 19.5 Stvaran primer iz dev baze: narudžbina 16

Korpa 17, plaćanje `10300628270` (PRE_AUTHORIZATION), iznos 68,93 EUR. Istorija statusa (`ps_order_history`,
provereno 2026-10-16) i log operacija (`PaymentOperation`, iznosi u centima):

| Vreme | Šta | Rezultat |
|---|---|---|
| 2026-10-16 08:31:05 | povratak kupca: Worldline status 5 | narudžbina napravljena u statusu 10 "Awaiting bank wire payment" |
| 08:32:56 – 08:33:37 | tri pokušaja capture (111,00; 0; 0) | `rejected`: plugin ih zaustavio, Worldline nije pitan |
| 08:33:57, 08:35:13, 09:18:14 | capture 30,00, 11,00, 10,00 | `success`, Worldline operacije `10300628270_1`, `_2`, `_3` |
| 09:21:52 | cancel 17,93 (ostatak) | `success`, `CANCELLED` |
| 10:06:38 | osvežavanje statusa posle zatvorene rezervacije (51,00 naplaćeno + 17,93 otkazano = 68,93) | status 2 "Payment accepted"; PrestaShop upiše uplatu 68,93 |
| 10:18:09 | refund 60,00 | `rejected` (više od naplaćenih 51,00) |
| 10:18:46 | refund 30,00 | `success`, `10300628270_6`; delimično vraćeno → status ostaje 2 |
| 10:43:09 | cancel 10,00 | `rejected` (od rezervacije ništa nije ostalo) |
| 10:46:39 | refund 20,00 | `success`, `10300628270_7` |
| 11:06:13 | osvežavanje statusa: vraćeno ukupno 51,00 = sve naplaćeno | status 7 "Refunded" |

Objašnjenje dva reda "osvežavanje statusa": pre 10:06 je skriptom urađen i probni refund od 1,00 EUR
(`10300628270_5`, **iz beleški**; nije u logu operacija jer nije išao kroz panel). Zato je u 10:06:38 vraćeno 1,00,
a naplaćeno 51,00: `PaymentsProxy` je rezervaciju pročitao kao 9, a Core je izabrao status za "delimično vraćeno"
(= 2). U 11:06:13 je vraćeno 1,00 + 30,00 + 20,00 = 51,00, pa `PaymentsProxy` vrati 8 i Core izabere "Refunded"
(7). Šta je okinulo ta dva osvežavanja (otvaranje panela, radnja ili skripta) baza ne beleži.

Prostim rečima: narudžbina nastaje tek posle potvrđenog plaćanja, a njen status je uvek posledica onoga što
Worldline kaže, prevedeno kroz Core mapiranje i upisano samo iz `ShopOrderService`-a.

---

## 20. Panel na stranici narudžbine: capture, refund, cancel

### 20.1 Običnim rečima

Na stranici svake narudžbine plaćene kroz plugin0 trgovac vidi karticu "Worldline payment": koliko je
rezervisano, naplaćeno, vraćeno i otkazano, i koliko je još dostupno za svaku radnju. Ispod su tri forme: Capture
(naplati rezervisano), Refund (vrati naplaćeno) i Cancel (oslobodi rezervisano a nenaplaćeno), i log svih pokušaja.

| Radnja | Šta znači za kupca | Kad ima smisla |
|---|---|---|
| Capture | novac se stvarno skida sa kartice | plaćanje je samo autorizovano (status 5) |
| Refund | naplaćeni novac se vraća kupcu | nešto je naplaćeno |
| Cancel | rezervacija se poništava, novac se ne skida | rezervisano, a nije naplaćeno |

### 20.2 Prikaz panela

`Plugin0::hookDisplayAdminOrderMain()`, `/home/veljkozbiljic/logeecom/plugin0/plugin0.php:124-166`:

1. Učita narudžbinu; ako je nema ili je nije napravio plugin0 (`Order::module !== 'plugin0'`), vrati `''` (126-129).
2. `Bootstrap::boot()` (131).
3. `PaymentManager::panel($order, true)` (136), `/home/veljkozbiljic/logeecom/plugin0/src/Order/PaymentManager.php:75-105`:
   1. `transaction()` (193-203): `CheckoutAPI::get()->payment($storeId)->getPaymentTransaction((string) $order->id_cart)`;
      poslednja Core transakcija korpe sa payment ID-jem, inače `null` → panel se ne prikazuje;
   2. **osvežavanje statusa** (83-92): `updateOrderStatus($paymentId)` **bez** RETURNMAC-a (admin je pouzdan
      pozivalac): Core pita Worldline i, ako treba, kroz `ShopOrderService` pomeri status narudžbine (npr. kad je
      capture u međuvremenu prešao iz CAPTURE_REQUESTED u CAPTURED). Bez webhook-a niko drugi to ne bi uradio.
      Neuspeh → žuto upozorenje u panelu;
   3. ponovo pročita transakciju (90), da prikaže novi status;
   4. `reconcileLog()` (209-223): unutar `StoreContext::doWithStore()` pita `PaymentsProxyInterface::getPaymentDetails()`
      i redovima loga koji su još `pending` upiše konačan ishod (20.6); greška ovde samo upiše WARNING i ne ruši
      panel;
   5. `OrderAPI::get()->orders($storeId)->getDetails($merchantReference)` (94): Core-ov pregled stanja (20.3);
   6. vrati niz za šablon (96-104).
4. Šablon `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/order_payment_panel.html.twig` se crta preko
   Symfony servisa `twig` (141-150), sa `can_act` = da li zaposleni ima pravo "update" na `AdminOrders`
   (`PageVoter::UPDATE`, 145-148). Forme vidi samo takav zaposleni.
5. Svaka greška (`\Throwable`, 151-165) → ERROR zapis "Worldline payment panel could not be rendered on the order
   page" (komponenta `PaymentManager`) i crvena poruka umesto kartice.

**Zašto `try/catch` baš ovde.** PrestaShop van debug režima guta izuzetak iz hook-a i vrati `''`, pa bi kartica
samo nestala, bez traga. To radi `Hook::callHookOn()`
(`/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/Hook.php:411-439`: `catch (Exception $e)`, u
debug režimu baci dalje, inače vrati `''`). Komentar u `/home/veljkozbiljic/logeecom/plugin0/plugin0.php:133` za to imenuje `Hook::coreCallHook`; tačno
je `Hook::callHookOn`, koji poziva `coreCallHook` unutar svog `try` bloka (poglavlje 34).

### 20.3 Šta Core kaže o stanju plaćanja: `OrderDetails`

`OrderService::getDetails()`
(`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Order/Services/Order/OrderService.php:29-61`):

1. nađe poslednju transakciju po `merchantReference` (ID korpe); status 0 ili bez payment ID-ja → izuzetak "Cannot
   find Worldline transaction" (32-34);
2. `GET /payments/{id}/details` (kroz plugin `PaymentsProxy`);
3. izračuna za svaku radnju `OrderAction(possible, done, pending, available)` (54-61):

| Radnja | `possible` (od Worldline-a) | `done` | `pending` | `available` |
|---|---|---|---|---|
| capture | `isAuthorized` | naplaćeno | zatraženo za naplatu | ukupno − (naplaćeno + zatraženo za naplatu + otkazano), ako `isAuthorized`, inače 0 |
| refund | `isRefundable` | vraćeno | zatraženo za povraćaj | naplaćeno − vraćeno − zatraženo za povraćaj, ako `isRefundable`, inače 0 |
| cancel | `isCancellable` | otkazano | 0 | ukupno − (naplaćeno + zatraženo za naplatu + otkazano), ako `isCancellable`, inače 0 |

`PaymentManager::state()` i `action()` (245-274) od toga prave tekst za tabelu panela (`MinorUnits::formatValue`,
nikad float), a radnja je "moguća" samo kad je `possible` i `available > 0` (268).

### 20.4 Izvršavanje jedne radnje

1. Forma u panelu šalje POST sa poljem `amount` na `plugin0_order_capture|refund|cancel`
   (`/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/order_payment_panel.html.twig:93`), npr.
   `/admin174kob088ranecckhxg/index.php/modules/worldline/orders/16/refund?_token=...`. JavaScript pre slanja traži
   potvrdu (150-159).
2. `OrderPaymentController` (`/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/OrderPaymentController.php`):
   anotacija `@AdminSecurity` (32, 40, 48) pusti samo zaposlenog sa pravom Edit na Orders (inače 403);
   `operate()` (55-83): boot, narudžbina postoji, napravio ju je plugin0, iznos kao tekst.
3. `PaymentManager::execute()` (`/home/veljkozbiljic/logeecom/plugin0/src/Order/PaymentManager.php:112-176`):
   1. transakcija (114-117); nema je → "This order has no Worldline payment.";
   2. valuta narudžbine i `MinorUnits::parse($tekst, $valuta)` (120-121): tekst → ceo broj ili `null` (20.5);
   3. **sveže stanje** od Worldline-a (`getDetails`, 125); neuspeh → red u logu sa ishodom `refused` i poruka "The
      payment state could not be read from Worldline, nothing was sent." (126-131);
   4. **provera** `OperationValidator::validate()` (133); odbijeno → red u logu sa ishodom `rejected`, poruka
      trgovcu, **nijedan POST Worldline-u** (134-138);
   5. **slanje** (140, `send()` 178-190):
      `OrderAPI::get()->capture|refund|cancel($storeId)->handle(new CaptureRequest|RefundRequest|CancelRequest($paymentId, $amount))`;
      Core samo prosledi zahtev SDK-u (`POST /payments/{id}/capture|refund|cancel`), bez ikakve provere i bez
      promene narudžbine (CORE.md, zamka 29);
   6. Worldline odbio → red `refused`, "Worldline refused the capture. ..." (141-146);
   7. Worldline prihvatio → `status` iz odgovora; kad je konačan (`CAPTURED`, `REFUNDED`, `CANCELLED`, 52-56) ishod je
      `success`, inače `pending` (npr. `CAPTURE_REQUESTED`, `REFUND_REQUESTED`); red u logu nosi i Worldline ID
      operacije iz odgovora (npr. `10300628270_1`) (148-167);
   8. **`updateOrderStatus($paymentId)`** (170): jedini put do statusa narudžbine, isti kao na povratku kupca; panel
      sam ne tumači i ne piše status.
4. Poruka (flash) i povratak na stranicu narudžbine (`admin_orders_view`), gde se panel ponovo osveži.

Svaki pokušaj ostavlja i red u logu operacija i INFO zapis "Payment operation attempted from the order page"
(`record()`, 225-238).

### 20.5 `MinorUnits`: iznos iz forme bez float-a

`/home/veljkozbiljic/logeecom/plugin0/src/Order/MinorUnits.php`. Zašto: float ne može tačno da zapiše većinu
decimalnih iznosa. **Pojednostavljeno:**

```php
var_dump(0.1 + 0.2 == 0.3);   // bool(false): 0.30000000000000004
// zato "12.34" ne ide kroz (float), nego se seče kao tekst: "12" i "34" → "1234" → 1234
```

`parse()` (22-46): prihvata samo pozitivan broj sa tačkom ili zarezom i najviše onoliko decimala koliko valuta
ima; dopuni decimale nulama; odbije više od 18 cifara (granica PHP `int`-a).

| Unos (EUR, 2 decimale) | Rezultat |
|---|---|
| `12.34`, `12,34`, ` 12.34 ` | 1234 |
| `12`, `12.`, `12.3` | 1200, 1200, 1230 |
| `1.005` | `null` (tri decimale) |
| `0`, `-5`, `abc`, `1 000`, prazno | `null` |

Za JPY (0 decimala) `1200` daje 1200, a `12.5` daje `null`. `format()` i `formatValue()` (49-68) vraćaju tekst
nazad (`1234` → `12.34 EUR`).

### 20.6 `OperationValidator`: pravila pre slanja

`/home/veljkozbiljic/logeecom/plugin0/src/Order/OperationValidator.php:37-92`. Pravila su na serveru; isključena
dugmad u panelu su samo udobnost (tiket: "validacija na serveru, ne samo isključena dugmad").

| Slučaj | Poruka trgovcu | Linije |
|---|---|---|
| iznos nije ispravan | "Enter a positive amount with at most the number of decimals the currency has." | 39-41 |
| radnja nije moguća ili ništa nije dostupno | "Capture is not possible for this payment in its current state at Worldline." (isto za Refund i Cancellation) | 77-79 |
| iznos veći od dostupnog | "Refund of 60.00 EUR exceeds the refundable amount of 51.00 EUR." | 81-89 |
| cancel kad je sve naplaćeno ili zatraženo za naplatu | "The payment cannot be cancelled: X is already captured or requested for capture. Refund it instead." | 49-55, `fullyCaptured()` 67-73 |
| nepoznata radnja | "Unknown payment operation." | 60 |

Cancel posle delimične naplate je dozvoljen za ostatak (npr. naplaćen jedan od dva artikla); odbija se tek kad od
rezervacije ništa nije ostalo (`fullyCaptured()`: naplaćeno + zatraženo > 0 i `cancel.available` ≤ 0). Po
beleškama to je ublaženo na zahtev 2026-10-16 (**iz beleški**).

### 20.7 Log operacija

Entitet `/home/veljkozbiljic/logeecom/plugin0/src/Entity/PaymentOperationEntity.php` (tip `PaymentOperation`,
indeksi `orderId` i `createdAt`, linije 58-65). Polja: orderId, paymentId, action, amount (najmanje jedinice),
currency, outcome, message, providerStatus, providerOperationId, employeeId, createdAt.

| Ishod | Značenje | Boja u panelu |
|---|---|---|
| `rejected` | plugin zaustavio pre Core-a (`OperationValidator`); Worldline nije pitan | žuta |
| `refused` | Core fasada vratila grešku (Worldline odbio ili poziv nije uspeo) | crvena |
| `pending` | Worldline prihvatio, ali operacija još nije konačna | plava |
| `success` | Worldline izvršio | zelena |

`OperationLog` (`/home/veljkozbiljic/logeecom/plugin0/src/Order/OperationLog.php`): `record()` (21-48) piše red;
`forOrder()` (195-209) čita redove narudžbine, najnoviji prvi; `reconcile()` (79-149) pri otvaranju panela redovima
`pending` daje konačan ishod:

1. red sa sačuvanim Worldline ID-jem operacije se upari sa tom operacijom;
2. stari red bez ID-ja se upari po vrsti i iznosu, **samo** kad je takva operacija tačno jedna i nije vezana za drugi
   red; inače se ne pogađa;
3. operacija `_0` se preskače, jer je to samo plaćanje, nikad operacija panela;
4. ishod po kodu operacije (`outcomeOf()`, 168-193): capture 9 = `success`, 4/91/92/99 = još `pending`; refund
   7/8/85 = `success`, 81/82 = `pending`; cancel 6/61/62 = `success`; sve ostalo = `refused`;
5. redovi `rejected` i `refused` se ne diraju.

Zašto reconcile postoji: Worldline capture i refund skoro uvek prvo prihvati kao `*_REQUESTED`, pa bi red u logu
zauvek ostao "pending", iako je operacija odavno prošla (**iz beleški**: trgovca je to zbunjivalo).

U dev bazi postoji primer starog reda: narudžbina 15, capture 34,46 iz 2026-10-15, `pending` /
`CAPTURE_REQUESTED`, bez ID-ja operacije (red je nastao pre nego što je polje uvedeno).

### 20.8 Šta se dešava sa statusom narudžbine posle radnje

| Radnja | Worldline posle | Status narudžbine |
|---|---|---|
| capture celog iznosa | 9 (posle obrade; prvo često `CAPTURE_REQUESTED`) | 2 "Payment accepted" |
| delimični capture (PRE_AUTHORIZATION) | prvobitna rezervacija `_0` ostaje 5 dok ostatak ne bude naplaćen ili otkazan | ostaje 10, pa 2 kad se rezervacija zatvori (`PaymentsProxy`) |
| cancel cele rezervacije | 6 | 6 "Canceled" |
| refund dela naplaćenog | naplaćeno, sa vraćenim delom | ostaje 2 ("delimično vraćeno" = 2) |
| refund svega naplaćenog | 8 (kroz `PaymentsProxy` i posle delimične naplate) | 7 "Refunded" |

### 20.9 Poznato ograničenje: blok "Payment" u PrestaShop-u

Kad narudžbina pređe u status sa `paid = 1` ("Payment accepted"), PrestaShop sam upiše uplatu za **ceo** iznos
fakture, ne za naplaćeni. Narudžbina 16 u dev bazi ima jednu uplatu od 68,93 (upisanu 2026-10-16 10:06:38, u
trenutku prelaska u status 2), iako je naplaćeno 51,00 (provereno u `ps_order_payment` 2026-10-16). Po beleškama,
odlučeno je da ostane tako (**iz beleški**).

### 20.10 Kako smo ga pravili

Ceo panel je ušao u jedan commit, `06a7b91` (2026-10-16, tiket MIDU-1224, grana `feature/MIDU-1224`). Demo projekat
nema ništa slično; panel je samo plugin-ov. Razvoj je trajao dva dana, a po beleškama ovim redom (**iz beleški**):

| Kada | Šta |
|---|---|
| 2026-10-15 | prva verzija: hook `displayAdminOrderMain`, `PaymentManager`, `OperationValidator`, `MinorUnits`, `OperationLog`, `PaymentOperationEntity`, rute `plugin0_order_*`, polje Payment action (FINAL_AUTHORIZATION) |
| 2026-10-15 | ispravke posle browsera: Twig petlja `for a, b, c, d in` (kartica je nestajala bez traga, 20.2), reč `@AdminSecurity` u opisu klase (Capture je pucao, 4.4); hook od tada greške upisuje u log |
| 2026-10-15 | nađen uzrok "FINAL_AUTHORIZATION daje direct sale" i napravljen `HostedCheckoutProxy` (21.1) |
| 2026-10-16 | treći izbor PRE_AUTHORIZATION (delimični capture); cancel ostatka posle delimičnog capture-a dozvoljen; `PaymentsProxy` za zatvorenu rezervaciju (21.2); "partially refunded" → `PS_OS_PAYMENT` (19.4); `providerOperationId` i usklađivanje loga (20.7) |
| 2026-10-16 | svih 7 kriterijuma prihvatanja pokazano u browseru, pa commit |

| Sloj | Fajl (puna putanja) | Najvažnije linije |
|---|---|---|
| hook (prikaz) | `/home/veljkozbiljic/logeecom/plugin0/plugin0.php` | `hookDisplayAdminOrderMain` 124-166 |
| rute (radnje) | `/home/veljkozbiljic/logeecom/plugin0/config/routes.yml` | 68-91 |
| kontroler radnji | `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/OrderPaymentController.php` | akcije 31-53, `operate` 55-83 |
| logika panela | `/home/veljkozbiljic/logeecom/plugin0/src/Order/PaymentManager.php` | `panel` 75-105, `execute` 112-176 |
| pravila | `/home/veljkozbiljic/logeecom/plugin0/src/Order/OperationValidator.php` | 37-92 |
| iznosi | `/home/veljkozbiljic/logeecom/plugin0/src/Order/MinorUnits.php` | 22-68 |
| log operacija | `/home/veljkozbiljic/logeecom/plugin0/src/Order/OperationLog.php`, `/home/veljkozbiljic/logeecom/plugin0/src/Entity/PaymentOperationEntity.php` | 20.7 |
| šablon | `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/order_payment_panel.html.twig` | 20.11 |

### 20.11 Šablon panela i ponašanje u browseru

`/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/order_payment_panel.html.twig` (crta ga
`hookDisplayAdminOrderMain` preko Symfony servisa `twig`; ne nasleđuje layout plugina, jer je deo PrestaShop-ove
stranice narudžbine):

| Deo kartice | Linije | Šta radi |
|---|---|---|
| zaglavlje "Worldline payment" | 18-21 | kartica u stilu PrestaShop blokova te stranice |
| red o plaćanju | 24-28 | Payment ID, naziv metode, Worldline status code |
| upozorenja | 30-39 | status nije mogao da se osveži; stanje nije moglo da se pročita; greške koje javlja Worldline |
| tabela iznosa | 41-74 | Authorised (ukupno), pa Captured, Refunded, Cancelled sa kolonama Done, Requested in progress i Still available; kad cancel nije moguć jer je sve naplaćeno, piše "0.00 (payment already captured)" (71) |
| tri forme | 76-109 | samo za zaposlenog sa pravom Edit na Orders (`can_act`); svaka forma šalje POST na `plugin0_order_capture`, `plugin0_order_refund` ili `plugin0_order_cancel` (93); polje iznosa unapred popunjeno dostupnim iznosom (96-97); kad radnja nije moguća, polje i dugme su isključeni i piše "Not possible in the current payment state." (103-105) |
| bez prava | 110-112 | "Your profile cannot edit orders, so payment operations are not available." |
| log operacija | 115-146 | najnoviji prvi: vreme, radnja, iznos, ishod kao značka (zeleno success, plavo pending, crveno refused, žuto rejected; 137-138), poruka sa Worldline statusom i ID-jem operacije (140), ID zaposlenog (141) |
| JavaScript | 150-159 | potvrda pre slanja ("Capture this amount at Worldline?"); pravila su na serveru |

Prostim rečima: panel je jedina admin površina plugina van menija "Worldline"; PrestaShop ga ubaci u stranicu
narudžbine, a sve radnje idu kroz tri rute i `PaymentManager`.

### 20.12 Kako da proveriš

- Unit testovi: `/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Order/*` (poglavlje 26).
- Browser: scenariji u poglavlju 30.5.
- Baza: redovi `PaymentOperation` (upit u poglavlju 28).
- Log: "Payment operation attempted from the order page" sa `outcome`, `amount`, `employeeId`.

Prostim rečima: panel je "daljinski upravljač" za Worldline plaćanje: pre svakog pritiska proveri da li je radnja
dozvoljena, pošalje je Worldline-u, zapiše šta se desilo i pusti Core da uskladi status narudžbine.

---

## 21. Koja dva problema plugin zaobilazi sopstvenim proxy klasama?

Ovo su **dva odvojena problema**, oba van plugina, oba zaobiđena u pluginu jer se Core ne sme menjati.

### 21.1 Problem 1: Worldline naplati odmah iako je traženo samo rezervisanje

**Običnim rečima.** Trgovac izabere "samo rezerviši" (FINAL_AUTHORIZATION), a Worldline ipak odmah naplati
(status 9, u Merchant Portal-u "Direct sale (payment)"). Razlog: Core u isti zahtev upiše tri podatka o načinu
plaćanja, a oni se ne slažu.

**Tačno.** Za pojedinačni kartični brend (npr. `1` = Visa) Core
`CreateHostedCheckoutRequestTransformer` upiše:

```json
{
  "cardPaymentMethodSpecificInput":     { "authorizationMode": "FINAL_AUTHORIZATION" },
  "mobilePaymentMethodSpecificInput":   { "authorizationMode": "SALE" },
  "redirectPaymentMethodSpecificInput": { "requiresApproval": false }
}
```

Mobile i redirect deo Core usklađuje sa podešavanjem samo za proizvode iz `SEPARATE_CAPTURE_SUPPORTED`
(`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/ApiFacades/PaymentProcessor/Proxies/Transformers/CreateHostedCheckoutRequestTransformer.php:75-77`
i `:85-87`); Visa tu nije, pa ostaju početne vrednosti `SALE` i `false`. Worldline preprod tada kartično plaćanje
obradi kao direct sale. To je dokazano probama 2026-10-15 (**iz beleški**):

| Proba | Zahtev | Rezultat |
|---|---|---|
| D | isti kao modul (FINAL + mobile SALE + requiresApproval false) | 9 |
| A | bez mobile i redirect dela | 5 |
| E | mobile FINAL_AUTHORIZATION + requiresApproval true | 5 |
| B | kartica PRE_AUTHORIZATION, mobile i dalje SALE | 9 |
| C | bez 3-D Secure | 9 |

Čiji je problem: Core šalje protivrečne vrednosti, a Worldline dokumentacija ne opisuje koja ima prednost.

**Rešenje u pluginu:** `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/HostedCheckoutProxy.php`,
registrovan **umesto** Core-ovog u `/home/veljkozbiljic/logeecom/plugin0/src/Bootstrap.php:139-146`.
`createSession()` (56-84) radi isto što i Core proxy (Core transformer gradi zahtev, Core-ov SDK klijent ga šalje),
samo između ta dva koraka pozove `alignAuthorizationModes()` (96-122):

1. samo za kartični brend (`isCardBrand()`, 98); grupa `cards`, mobilni novčanici i redirect metode se ne diraju;
2. ako je kartični režim FINAL_AUTHORIZATION, a trgovac je izabrao PRE_AUTHORIZATION, kartični režim postaje
   PRE_AUTHORIZATION (108-111), jer Core PRE_AUTHORIZATION ne ume za pojedinačni brend;
3. mobile `authorizationMode` = kartični režim (113-116);
4. redirect `requiresApproval` = istina tačno kad režim nije SALE (118-121).

Kartični režim je merodavan jer u njega Core već uračuna globalnu akciju plaćanja, eksplicitnu akciju i
podešavanje metode (komentar 86-91). Testovi: `/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Checkout/HostedCheckoutProxyTest.php`
(7 testova). Po beleškama, posle ove izmene plaćanje sa FINAL_AUTHORIZATION u browseru je dalo status 5 (**iz
beleški**).

### 21.2 Problem 2: posle delimične naplate Core misli da je plaćanje i dalje samo rezervisano

**Običnim rečima.** Kad trgovac naplati rezervaciju u delovima (30 + 11 + 10 od 68,93) i otkaže ostatak, Worldline
vodi svaki deo kao posebnu operaciju, a prvobitnu rezervaciju ostavi u statusu "autorizovano". Core gleda samo tu
prvobitnu rezervaciju, pa narudžbina ostane "čeka uplatu", a refund bude nemoguć, iako je novac naplaćen.

**Tačno.** Worldline posle delimičnih naplata: rezervacija `_0` ostaje status 5 sa `isRefundable = false`;
naplate su operacije `_1`, `_2`, `_3` sa statusom 9; otkazivanje ostatka je operacija sa statusom 6 (**iz
beleški**, plaćanje 10300628270). Core `PaymentDetailsResponseTransformer` status i zastavice uzima sa vrha
odgovora, dakle sa `_0`
(`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/ApiFacades/PaymentProcessor/Proxies/Transformers/PaymentDetailsResponseTransformer.php:42`),
a iznose sabira iz operacija (149-162). Ishod: `StatusMappingService` vidi 5 → "autorizovano", `OrderService` vidi
`isRefundable = false` → refund 0. Proba je pokazala da Worldline refund na `_0` ipak prihvata (`POST
/payments/10300628270_0/refund` 1,00 EUR → `10300628270_5`, `REFUND_REQUESTED`; **iz beleški**).

**Rešenje u pluginu:** `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/PaymentsProxy.php`, omotač oko
Core `PaymentsProxy` (registrovan u `/home/veljkozbiljic/logeecom/plugin0/src/Bootstrap.php:151-153`). Sve metode
prosleđuje Core-u; samo rezultat `getPaymentDetails()` (50-53) propusti kroz `settleClosedAuthorization()` (84-115):

| Uslov (sve mora važiti) | Linije |
|---|---|
| status je 5 | 87 |
| naplaćeno > 0 | 96 |
| ništa nije u obradi za naplatu | 96 |
| autorizovano − naplaćeno − otkazano ≤ 0 (od rezervacije ništa nije ostalo) | 96 |

Tada:

- ako je vraćeno ≥ naplaćeno → status 8 / `REFUNDED`, `isRefundable = false` → Core: "Refunded";
- inače → status 9 / `CAPTURED`, `isAuthorized = false`, `isCancellable = false`, `isRefundable = true` → Core:
  "Payment accepted", refund do naplaćenog iznosa.

Iznosi, operacije, token i podaci o kartici ostaju netaknuti; u svakom drugom slučaju rezultat je nepromenjen.
Mehanički, **pojednostavljeno**, za narudžbinu 16:

```
autorizovano 6893, naplaćeno 3000 + 1100 + 1000 = 5100, otkazano 1793, u obradi 0
6893 - 5100 - 1793 = 0  → rezervacija zatvorena
vraćeno 0      → status 9 CAPTURED, refund moguć do 5100
vraćeno 5100   → status 8 REFUNDED
```

Testovi: `/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Order/PaymentsProxyTest.php` (9 testova).

### 21.3 Zašto oba proxy-ja moraju u `initProxies()`

Poglavlje 5.3: Core registruje svoje proxy-je u `initProxies()`, posle `initServices()`; plugin mora da ih
registruje posle `parent::initProxies()`, inače Core pregazi plugin. Docblock klase `HostedCheckoutProxy` (linije
23-24) pogrešno kaže `initServices`; kod je ispravan (poglavlje 34).

Prostim rečima: oba proxy-ja su "prevodioci" između Core-a i Worldline-a; prvi ispravlja zahtev pre slanja, drugi
tumači odgovor posle prijema, a Core ostaje netaknut.

---

# Deo IV. Referenca

## 22. Katalog svih fajlova plugina

Svi fajlovi pod git-om, osim `/home/veljkozbiljic/logeecom/plugin0/vendor/` (u `.gitignore`) i `.idea/`. Broj linija je sa grane `feature/MIDU-1224`,
commit `06a7b91`. Kolona "Ko ga koristi" kaže ko taj fajl poziva ili učitava.

### 22.1 Koren, konfiguracija, dokumentacija

| Fajl (puna putanja) | Linija | Šta je | Ko ga koristi |
|---|---|---|---|
| `/home/veljkozbiljic/logeecom/plugin0/plugin0.php` | 188 | klasa modula `Plugin0 extends PaymentModule`: install, uninstall, Configure, četiri hook metode, `checkCurrency()` | PrestaShop |
| `/home/veljkozbiljic/logeecom/plugin0/composer.json` | 23 | zavisnost `wl-online-payments-direct/plugin-core-base` 1.0.7; dev: `phpunit/phpunit` ^11.5, `psr/log` ^1.1; PSR-4 mape; `prepend-autoloader: false` | composer |
| `/home/veljkozbiljic/logeecom/plugin0/composer.lock` | 1980 | tačne verzije i commit-i svih paketa (Core, SDK 6.2.0, `robtimus/multipart` 1.0.1, phpunit...) | composer; dokaz da je Core netaknut (27.4) |
| `/home/veljkozbiljic/logeecom/plugin0/phpunit.xml` | 18 | testsuite `Unit` = `/home/veljkozbiljic/logeecom/plugin0/tests/Unit`, bootstrap `/home/veljkozbiljic/logeecom/plugin0/vendor/autoload.php` | phpunit |
| `/home/veljkozbiljic/logeecom/plugin0/.gitignore` | 3 | tri reda: `.idea/`, `vendor/`, `.phpunit.cache/` (relativno od `/home/veljkozbiljic/logeecom/plugin0/`) | git |
| `/home/veljkozbiljic/logeecom/plugin0/README.md` | 1 | samo naslov | — |
| `/home/veljkozbiljic/logeecom/plugin0/config/routes.yml` | 91 | 12 Symfony admin ruta (4.4) | PrestaShop `YamlModuleLoader` |
| `/home/veljkozbiljic/logeecom/plugin0/config/brand.json` | 9 | Worldline API i Merchant Portal adrese za live i test | Core `ActiveBrandProvider` |
| `/home/veljkozbiljic/logeecom/plugin0/docs/CORE.md` | 948 (posle usklađivanja 2026-10-16) | referenca Core biblioteke | ti |
| `/home/veljkozbiljic/logeecom/plugin0/docs/OKRUZENJE.md` | 1585 (posle usklađivanja 2026-10-16) | Docker/WSL okruženje | ti |
| `/home/veljkozbiljic/logeecom/plugin0/docs/VODIC.md` | — | ovaj dokument | ti |

### 22.2 Front controller-i i front šablon

| Fajl | Linija | Klasa | Uloga | Poglavlje |
|---|---|---|---|---|
| `/home/veljkozbiljic/logeecom/plugin0/controllers/front/payment.php` | 85 | `Plugin0PaymentModuleFrontController` | PLACE ORDER → Core `createSession` → preusmerenje na Worldline | 17 |
| `/home/veljkozbiljic/logeecom/plugin0/controllers/front/return.php` | 176 | `Plugin0ReturnModuleFrontController` | povratak sa Worldline-a → ishod → potvrda, poruka ili "obrađuje se" | 18 |
| `/home/veljkozbiljic/logeecom/plugin0/views/templates/front/return.tpl` | 16 | Smarty šablon | stranica "Payment is being processed" | 18 |

### 22.3 `/home/veljkozbiljic/logeecom/plugin0/src/` (namespace `Plugin0\`)

| Fajl | Linija | Klasa | Uloga | Ko ga koristi |
|---|---|---|---|---|
| `/home/veljkozbiljic/logeecom/plugin0/src/Bootstrap.php` | 193 | `Plugin0\Bootstrap extends BootstrapComponent` | podiže Core; registruje servise, proxy-je, repozitorijume | svi ulazi (5.4) |
| `/home/veljkozbiljic/logeecom/plugin0/src/Configuration.php` | 92 | `Plugin0\Configuration` | Core `Configuration` za PrestaShop + ključevi plugina | Core, `SettingsRepository`, `PaymentActionSetting`, `CoreInfoController` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Services.php` | 58 | `Plugin0\Services` | pravi demo servise sa adapterima ("service provider") | kontroleri, `AvailablePaymentMethods`, `LoggerService` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/PaymentMethodRepository.php` | 258 | `Plugin0\Adapter\PaymentMethodRepository` | demo skladište metoda nad Core ORM-om | `PaymentMethodService` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/ReferenceData.php` | 36 | `Plugin0\Adapter\ReferenceData` | kodovi valuta i zemalja uključenih u prodavnici | `PaymentMethodService`, `SettingsService` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/SettingsRepository.php` | 95 | `Plugin0\Adapter\SettingsRepository` | demo skladište podešavanja nad Core `ConfigEntity` | `SettingsService` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/CoreLogger.php` | 96 | `Plugin0\Adapter\CoreLogger extends AbstractLogger` | PSR-3 logger demo servisa → Core Logger | `Services::logger()` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/LogLevelApplier.php` | 21 | `Plugin0\Adapter\LogLevelApplier` | novi Log level važi odmah | `SettingsService` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Checkout/AvailablePaymentMethods.php` | 74 | `Plugin0\Checkout\AvailablePaymentMethods` | koje metode važe za korpu | `hookPaymentOptions`, `/home/veljkozbiljic/logeecom/plugin0/controllers/front/payment.php` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Checkout/PrestaShopCartProvider.php` | 172 | `Plugin0\Checkout\PrestaShopCartProvider implements CartProvider` | PrestaShop korpa kao Core `Cart` | `/home/veljkozbiljic/logeecom/plugin0/controllers/front/payment.php` → Core |
| `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/CoreInfoController.php` | 24 | `CoreInfoController` | JSON sa podacima iz Core `Configuration` (MIDU-1215) | ruta `api_plugin0_core_info` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/ConnectedController.php` | 27 | `abstract ConnectedController` | zajednički `isConnected()` i `storeId()` | Payment methods, Settings, Logs kontroleri |
| `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/ConfigurationController.php` | 268 | `ConfigurationController` | Configuration stranica: prikaz, Save, Disconnect | ruta `plugin0_configuration` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/PaymentMethodsController.php` | 362 | `PaymentMethodsController` | lista, forma, prekidač, brisanje | rute `plugin0_payment_method*` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/SettingsController.php` | 191 | `SettingsController` | Settings stranica: Save, Restore | ruta `plugin0_settings` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/LogsController.php` | 58 | `LogsController` | Logs stranica | ruta `plugin0_logs` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/OrderPaymentController.php` | 84 | `OrderPaymentController` | capture, refund, cancel iz panela | rute `plugin0_order_*` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Entity/PaymentMethodEntity.php` | 128 | `Plugin0\Entity\PaymentMethodEntity extends Entity` | metoda plaćanja kao red tipa `PaymentMethod` | `PaymentMethodRepository` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Entity/PaymentOperationEntity.php` | 176 | `Plugin0\Entity\PaymentOperationEntity extends Entity` | jedan pokušaj capture/refund/cancel, tip `PaymentOperation` | `OperationLog` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Form/PaymentMethodForm.php` | 211 | `Plugin0\Form\PaymentMethodForm` | vrednosti i pravila forme metode | `PaymentMethodsController` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/Repository/BaseRepository.php` | 435 | `BaseRepository implements ConditionallyDeletes` | Core ORM nad `ps_plugin0_entity` | `RepositoryRegistry` (Core i plugin) |
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/Repository/LogsRepository.php` | 62 | `LogsRepository extends BaseRepository` | `MonitoringLog`, `WebhookLog` sa pretragom | Core |
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/Installer.php` | 41 | `Installer` | `CREATE TABLE` / `DROP TABLE` | `install()`, `uninstall()` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/LoggerService.php` | 120 | `LoggerService implements ShopLoggerAdapter` | Core log → `ps_log`, filter po Log level-u | Core `Logger` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/CoreLogLevel.php` | 91 | `CoreLogLevel` | prevod nivoa: demo ↔ PSR-3 ↔ Core | `LoggerService`, `CoreLogger`, `SettingsRepository`, `LogLevelApplier` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/LogReader.php` | 95 | `LogReader` | čita `WORLDLINE` zapise iz `ps_log` | `LogsController` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/Encryptor.php` | 30 | `Encryptor implements Encryptor` (Core) | šifrovanje tajni (`PhpEncryption`) | Core `ConnectionConfigRepository` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/MetadataProvider.php` | 27 | `MetadataProvider` | podaci o platformi za Worldline zaglavlje | Core `MerchantClientFactory` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/StoreService.php` | 86 | `StoreService` (Core integracioni) | prodavnice, statusi, podrazumevano mapiranje | Core |
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/LanguageService.php` | 52 | `LanguageService` | aktivni jezici | Core |
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/LogoUrlService.php` | 48 | `LogoUrlService` | adrese logotipa metoda | Core |
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/ShopPaymentService.php` | 56 | `ShopPaymentService` | samo log zapis o Core radnjama nad Core metodama | Core |
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/ShopOrderService.php` | 220 | `ShopOrderService` | jedino pravljenje narudžbine i promena statusa | Core `StatusUpdateService` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/HostedCheckoutProxy.php` | 123 | `HostedCheckoutProxy implements HostedCheckoutProxyInterface` | zamena Core proxy-ja (21.1) | Core `HostedCheckoutService` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/PaymentsProxy.php` | 116 | `PaymentsProxy implements PaymentsProxyInterface` | omotač oko Core proxy-ja (21.2) | Core, `PaymentManager` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/PaymentActionSetting.php` | 147 | `PaymentActionSetting` | Payment action: dve sačuvane vrednosti | `SettingsController`, `Bootstrap` (closure za `HostedCheckoutProxy`) |
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/ShopCountriesAndCurrencies.php` | 96 | `ShopCountriesAndCurrencies` | uključene zemlje i valute, keš po zahtevu | `ReferenceData`, kontroleri |
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/WorldlineProducts.php` | 29 | `WorldlineProducts` | Worldline proizvodi iz Core-a, ponuda za Code | `PaymentMethodsController` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Order/PaymentManager.php` | 299 | `PaymentManager` | panel: prikaz i izvršavanje radnji; jedino mesto koje zove `OrderAPI` | `hookDisplayAdminOrderMain`, `OrderPaymentController` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Order/OperationValidator.php` | 93 | `OperationValidator` | pravila capture/refund/cancel | `PaymentManager` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Order/MinorUnits.php` | 69 | `MinorUnits` | tekst ↔ ceo broj u najmanjim jedinicama | `PaymentManager`, `OperationValidator`, `OperationLog` |
| `/home/veljkozbiljic/logeecom/plugin0/src/Order/OperationLog.php` | 210 | `OperationLog` | upis, čitanje i usklađivanje loga operacija | `PaymentManager` |

### 22.4 `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/` (namespace `Logeecom\`, demo poslovni sloj)

Kopija `/home/veljkozbiljic/laravel-docker/src/` bez ijedne izmene: `diff -rq` dva foldera je 2026-10-16 bio prazan.
Ništa ovde ne zna za PrestaShop, Core ni HTTP.

| Folder (puna putanja) | Povezan sa pluginom? | Najvažniji fajlovi |
|---|---|---|
| `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/` | **da** (Payment methods stranica, checkout) | `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Service/PaymentMethodService.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Domain/PaymentMethod.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Domain/PaymentMethodType.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Domain/PaymentMethodFilter.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Domain/PaymentMethodPage.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Domain/Checkout/PaymentFlowFactory.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Domain/Checkout/PaymentFlowInterface.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Domain/Checkout/RedirectFlow.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Domain/Checkout/IframeFlow.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Domain/Exception/`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Repository/PaymentMethodRepositoryInterface.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Repository/ReferenceDataRepositoryInterface.php` (6 izuzetaka u `Domain/Exception/`) |
| `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/` | **da** (Settings stranica, Log level za ceo log, Rows per page, Default currency) | `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Service/SettingsService.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Domain/Settings.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Domain/LogLevel.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Domain/SettingKey.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Domain/Severity.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Logging/LogLevelApplierInterface.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Repository/SettingsRepositoryInterface.php` |
| `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Configuration/` | **ne** (Configuration stranica ide direktno na Core; 12.6) | `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Configuration/Service/ConfigurationService.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Configuration/Provider/FakeProvider.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Configuration/Provider/CredentialsVerifierInterface.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Configuration/Repository/ConfigurationRepositoryInterface.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Configuration/Domain/` |
| `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Logs/` | **ne** (Logs stranica čita `ps_log` kroz `LogReader`; 15) | `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Logs/Service/LogService.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Logs/Repository/LogEntryRepositoryInterface.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Logs/Domain/LogPage.php`, `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Logs/Domain/LogRecord.php` |

### 22.5 `/home/veljkozbiljic/logeecom/plugin0/tests/` (namespace `Tests\`)

| Fajl ili folder | Čiji | Broj pokretanja (2026-10-16) | Šta proverava |
|---|---|---|---|
| `/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Order/` (`MinorUnitsTest`, `OperationLogTest`, `OperationValidatorTest`, `PaymentsProxyTest`) | plugin | 52 | iznosi, usklađivanje loga, pravila radnji, tumačenje zatvorene rezervacije |
| `/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Checkout/HostedCheckoutProxyTest.php` | plugin | 17 | usklađivanje režima autorizacije za kartične brendove |
| `/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Settings/PaymentActionSettingTest.php` | plugin | 9 | podela izbora Payment action na dve vrednosti i nazad |
| `/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Settings/Service/SettingsServiceTest.php` | demo | 23 | pravila podešavanja |
| `/home/veljkozbiljic/logeecom/plugin0/tests/Unit/PaymentMethods/` | demo | 52 | pravila metoda plaćanja, filter, strana, tipovi |
| `/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Configuration/Service/ConfigurationServiceTest.php` | demo | 16 | pravila konfiguracije (nepovezan modul) |
| `/home/veljkozbiljic/logeecom/plugin0/tests/Support/` (7 klasa) | demo | — | in-memory repozitorijumi i pomoćni logger za testove |

Demo testovi i `/home/veljkozbiljic/logeecom/plugin0/tests/Support` su identični demo projektu, osim
`/home/veljkozbiljic/logeecom/plugin0/tests/Support/RecordingLogger.php`, kome je potpis `log()` prilagođen
`psr/log` 1.1 (PrestaShop ga nosi u toj verziji; poređeno sa demo projektom 2026-10-16). "Broj pokretanja" je veći
od broja test metoda jer neki testovi idu kroz data provider.

### 22.6 `/home/veljkozbiljic/logeecom/plugin0/views/`

| Fajl | Linija | Šta prikazuje | Kontroler |
|---|---|---|---|
| `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/layout.html.twig` | 50 | okvir: bočni meni, CSS | nasleđuju ga sve admin stranice plugina |
| `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/configuration.html.twig` | 172 | forma kredencijala, značka, Disconnect, Webhook URL | `ConfigurationController` |
| `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/payment_methods.html.twig` | 246 | lista metoda, filteri, strane, prekidač, potvrda brisanja | `PaymentMethodsController::listAction` |
| `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/payment_method.html.twig` | 222 | forma metode (čipovi valuta i zemalja) | `PaymentMethodsController::form` |
| `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/settings.html.twig` | 151 | četiri podešavanja, Save, Restore | `SettingsController` |
| `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/logs.html.twig` | 96 | tabela loga, filter nivoa, strane | `LogsController` |
| `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/order_payment_panel.html.twig` | 160 | kartica "Worldline payment" | `Plugin0::hookDisplayAdminOrderMain` |
| `/home/veljkozbiljic/logeecom/plugin0/views/templates/front/return.tpl` | 16 | "Payment is being processed" | `/home/veljkozbiljic/logeecom/plugin0/controllers/front/return.php` |
| `/home/veljkozbiljic/logeecom/plugin0/views/css/admin.css` | 47 | stil admin stranica (`plugin0-*` klase) | `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/layout.html.twig` |
| `/home/veljkozbiljic/logeecom/plugin0/views/img/payment-method.svg` | 5 | generička ikonica metode | `LogoUrlService` |
| `/home/veljkozbiljic/logeecom/plugin0/views/img/payment-methods/.gitkeep` | 0 | prazan folder za logotipe `{productId}.svg` ili `.png` | `LogoUrlService` |

CSS je namerno u `/home/veljkozbiljic/logeecom/plugin0/views/css/admin.css`, a ne u `<style>` bloku Twig-a: PhpStorm je pri commit-u prijavljivao
"Selector ... is never used" za CSS u layout-u koji koriste samo podšabloni (**iz beleški**).

---

## 23. Interfejsi koji su ti važni, na jednom mestu

### 23.1 Core interfejsi koje plugin implementira

Pune putanje, ključ registracije i implementacija su u tabeli poglavlja 6.2. Ovde su potpisi metoda, da znaš šta
tačno Core zove:

| Interfejs | Metode (potpis iz Core-a) |
|---|---|
| `ShopOrderService` (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Integration/ShopOrderService.php`) | `createShopOrder(PaymentTransaction, PaymentDetails, string $newState): void` (23), `updateStatus(...)` (32), `cancelShopOrder(...)` (42), `refundShopOrder(...)` (52), sve sa istim argumentima |
| `StoreService` (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Integration/Stores/StoreService.php`) | `getStoreDomain(): string` (20), `getStores(): array` (26), `getDefaultStore(): ?Store` (32), `getStoreById(string): ?Store` (40), `getDefaultOrderStatusMapping(): OrderStatusMapping` (46), `getStoreOrderStatuses(): array` (52) |
| `ShopLoggerAdapter` / `LoggerAdapter` (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/Logger/Interfaces/LoggerAdapter.php`) | `logMessage(LogData $data): void` (20) |
| `Encryptor` (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Integration/Encryption/Encryptor.php`) | `encrypt(string): string` (19), `decrypt(string): string` (27) |
| `MetadataProviderInterface` (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Integration/Metadata/MetadataProviderInterface.php`) | `getMetadata(): Metadata` (12) |
| `LanguageService` (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Integration/Language/LanguageService.php`) | `getEnabledLanguages(): array` (16) |
| `LogoUrlService` (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Integration/Logo/LogoUrlService.php`) | `getHostedCheckoutLogoUrl(): string` (15), `getLogoUrl(string $productId): string` (21) |
| `ShopPaymentService` (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Integration/Payment/ShopPaymentService.php`) | `savePaymentMethod(PaymentMethod): void` (18), `enable(string, bool): void` (25), `deletePaymentMethods(string $mode): void` (31) |
| `HostedCheckoutProxyInterface` (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/Proxies/HostedCheckoutProxyInterface.php`) | `createSession(HostedCheckoutSessionRequest, ThreeDSSettings, PaymentSettings, PaymentMethodCollection, array $supportedPaymentMethods, ?Token = null): PaymentResponse` (18) |
| `PaymentsProxyInterface` (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/Proxies/PaymentsProxyInterface.php`) | `create(...)` (21), `getPaymentDetails(PaymentId): PaymentDetails` (22), `tryToGetPayment(PaymentId): ?Payment` (23), `getPayment(PaymentId): Payment` (24), `getRefunds(PaymentId): array` (25), `getCaptures(PaymentId): array` (26) |
| `RepositoryInterface` (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/ORM/Interfaces/RepositoryInterface.php`) | `getClassName()` (23), `setEntityClass(string)` (29), `select(?QueryFilter): array` (37), `selectOne(?QueryFilter): ?Entity` (45), `save(Entity): int` (53), `update(Entity, ?QueryFilter): bool` (62), `delete(Entity): bool` (70), `count(?QueryFilter): int` (78) |
| `ConditionallyDeletes` (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/ORM/Interfaces/ConditionallyDeletes.php`) | `deleteWhere(?QueryFilter)` (18) |
| `RepositoryWithAdvancedSearchInterface` (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Monitoring/Repositories/RepositoryWithAdvancedSearchInterface.php`) | `getLogs(int $pageNumber, int $pageSize, string $searchTerm, ?DateTime $disconnectTime = null): array` (23), `countLogs(?DateTime $disconnectTime = null, string $searchTerm = ''): ?int` (30) |
| `CartProvider` (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Checkout/Cart/CartProvider.php`) | `get(): Cart` (12) |
| apstraktna `Configuration` (`/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/Configuration/Configuration.php` i `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/Configuration/Configuration.php`) | `getIntegrationName()` (Infrastructure 60), `getAsyncProcessUrl(string $guid)` (Infrastructure 68), `getIntegrationVersion()` (Bootstrap 25), `getPluginName()` (Bootstrap 31), `getPluginVersion()` (Bootstrap 37) |

### 23.2 Demo interfejsi koje implementiraju adapteri plugina

| Demo interfejs (puna putanja:linija) | Metode | Implementacija u pluginu |
|---|---|---|
| `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Repository/PaymentMethodRepositoryInterface.php:19` | `all()` (26), `page(PaymentMethodFilter, int, int): PaymentMethodPage` (38), `find(int): ?PaymentMethod` (47), `findByCode(string): ?PaymentMethod` (56), `add(PaymentMethod): PaymentMethod` (70), `update(PaymentMethod): PaymentMethod` (81), `delete(int): void` (88), `transaction(Closure): mixed` (100) | `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/PaymentMethodRepository.php` |
| `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/PaymentMethods/Repository/ReferenceDataRepositoryInterface.php:12` | `currencies(): array` (17), `countries(): array` (22) | `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/ReferenceData.php` |
| `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Repository/SettingsRepositoryInterface.php:12` | `all(): array` (19), `put(array): void` (26), `clear(): void` (31) | `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/SettingsRepository.php` |
| `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Settings/Logging/LogLevelApplierInterface.php:14` | `apply(LogLevel): void` (19) | `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/LogLevelApplier.php` |
| `Psr\Log\LoggerInterface` (`/home/veljkozbiljic/logeecom/plugin0/vendor/psr/log/Psr/Log/LoggerInterface.php`, psr/log 1.1.4) | `log($level, $message, array $context = [])` + `emergency()` ... `debug()` | `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/CoreLogger.php` (nasleđuje `AbstractLogger`, implementira samo `log()`) |

Demo interfejsi koje plugin **ne** implementira (moduli nisu povezani): `ConfigurationRepositoryInterface`,
`CredentialsVerifierInterface` (`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Configuration/...`) i
`LogEntryRepositoryInterface` (`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom/Logs/Repository/LogEntryRepositoryInterface.php`).
`PaymentFlowInterface` implementiraju demo klase `RedirectFlow` i `IframeFlow`, ne plugin.

### 23.3 Core klase koje plugin poziva ili pravi

| Šta | Puna putanja | Gde u pluginu |
|---|---|---|
| fasade `AdminAPI`, `CheckoutAPI`, `OrderAPI` | 7.1 | 7.1 |
| `ServiceRegister` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/ServiceRegister.php` | `Bootstrap`, `CoreInfoController`, `ConfigurationController`, `PaymentManager` |
| `RepositoryRegistry` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/ORM/RepositoryRegistry.php` | `Bootstrap`, `PaymentMethodRepository`, `OperationLog` |
| `QueryFilter`, `Operators` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/ORM/QueryFilter/` | `BaseRepository`, `LogsRepository`, `PaymentMethodRepository`, `OperationLog` |
| `Entity`, `IndexMap`, `EntityConfiguration`, `IndexHelper` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/ORM/` | dva entiteta plugina, `BaseRepository` |
| `Logger` (statičke `logError/logWarning/logInfo/logDebug`), `LogContextData` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/Logger/` | svuda gde plugin loguje |
| `StoreContext` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Multistore/StoreContext.php` | `ConfigurationController`, `PaymentManager`, `LanguageService`, `LogsRepository` |
| zahtevi `ConnectionRequest`, `PaymentSettingsRequest`, `HostedCheckoutSessionRequest`, `CaptureRequest`, `RefundRequest`, `CancelRequest` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/AdminConfig/ApiFacades/ConnectionAPI/Request/ConnectionRequest.php`, `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/AdminConfig/ApiFacades/GeneralSettingsAPI/Request/PaymentSettingsRequest.php`, `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/HostedCheckout/HostedCheckoutSessionRequest.php`, `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Capture/CaptureRequest.php`, `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Refund/RefundRequest.php`, `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Cancel/CancelRequest.php` | kontroleri i `PaymentManager` |
| vrednosni objekti `PaymentId`, `PaymentProductId`, `StatusCode`, `Amount`, `Currency` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Payment/` i `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/PaymentMethod/PaymentProductId.php`, `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Checkout/` | front controller-i, `AvailablePaymentMethods`, `/home/veljkozbiljic/logeecom/plugin0/src/Order/`, proxy-ji |
| `PaymentAction`, `AuthorizationMode` | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/GeneralSettings/PaymentAction.php`, `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/PaymentMethod/MethodAdditionalData/Cards/AuthorizationMode.php` | `PaymentActionSetting`, `HostedCheckoutProxy` |
| transformeri i SDK zahtev | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/ApiFacades/PaymentProcessor/Proxies/Transformers/`, `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/sdk-php/src/OnlinePayments/Sdk/Domain/CreateHostedCheckoutRequest.php` | `HostedCheckoutProxy` |

---

## 24. Core fajlovi koje treba da poznaješ

Ako čitaš Core, ovo su fajlovi koji objašnjavaju skoro sve što plugin radi. Redosled je redosled učenja.

| # | Fajl (puna putanja) | Zašto je važan |
|---|---|---|
| 1 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/BootstrapComponent.php` | redosled boot-a (169-184) i sve Core registracije; tu vidiš šta plugin može da pregazi |
| 2 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/ServiceRegister.php` | "poslednja registracija važi" |
| 3 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/ApiFacades/Aspects/ErrorHandlingAspect.php` | zašto fasade ne bacaju izuzetke (22-35) |
| 4 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/Services/Payment/StatusUpdateService.php` | srce integracije: od Worldline statusa do poziva `ShopOrderService` (58-145) |
| 5 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/OrderStatusMapping/StatusMappingService.php` | koji status narudžbine za koji Worldline status (34-68) |
| 6 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/BackgroundProcesses/WaitPaymentOutcomeProcess.php` | čekanje na ishod pri povratku kupca (45-77) |
| 7 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/DataAccess/PaymentTransaction/PaymentTransactionRepository.php` | traženje transakcije po ID-ju i RETURNMAC-u (94-110), Core brava (111-149) |
| 8 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/PaymentProcessor/Services/HostedCheckout/HostedCheckoutService.php` | šta se desi pri `createSession` (47-63) |
| 9 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/ApiFacades/PaymentProcessor/Proxies/Transformers/CreateHostedCheckoutRequestTransformer.php` | kako korpa postaje Worldline zahtev; problem iz 21.1 (75-87) |
| 10 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/ApiFacades/PaymentProcessor/Proxies/Transformers/PaymentDetailsResponseTransformer.php` | kako Worldline odgovor postaje `PaymentDetails`; problem iz 21.2 (42, 149-162) |
| 11 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Order/Services/Order/OrderService.php` | dostupni iznosi za panel (29-61) |
| 12 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/AdminConfig/Services/GeneralSettings/GeneralSettingsService.php` | default SALE (79-87) |
| 13 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Payment/StatusCode.php` | grupe Worldline statusa (12-18) |
| 14 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/PaymentMethod/PaymentProductId.php` | Worldline proizvodi, `SUPPORTED_PAYMENT_PRODUCTS` (14), `SEPARATE_CAPTURE_SUPPORTED` (69) |
| 15 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Infrastructure/Logger/Logger.php` | log uvek ide u `ShopLoggerAdapter` (120-130) |
| 16 | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/Sdk/MerchantClientFactory.php` | klijent ka Worldline-u (34-56) |

Za sve ostalo: `/home/veljkozbiljic/logeecom/plugin0/docs/CORE.md`, poglavlja 4-6 i zamke u poglavlju 9.

---

## 25. PrestaShop fajlovi i hook-ovi na koje se plugin oslanja

| Fajl (puna putanja u WSL-u) | Šta je bitno | Gde u vodiču |
|---|---|---|
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/PaymentModule.php` | `validateOrder()` (207-...): `die()` za neaktivan modul, korpu sa narudžbinom, pogrešan secure key (245-261) | 19.3 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/order/OrderHistory.php` | `changeIdOrderState()` (83), `addWithemail()` (446) | 19.3 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/Hook.php` | `callHookOn()` guta izuzetak van debug režima (411-439) | 20.2 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/Dispatcher.php` | `Hook::exec('actionDispatcherBefore')` (351) | 4.3 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/PrestaShopBundle/EventListener/ActionDispatcherLegacyHooksSubscriber.php` | isti hook za Symfony admin (91) | 4.3 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/checkout/CheckoutPaymentStep.php` | korak plaćanja (83) | 16 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/checkout/PaymentOptionsFinder.php` | hook `paymentOptions` (54) | 16 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/module/Module.php` | `getPaymentModules()`: ograničenje po zemlji adrese za fakturu (1738-1769) | 16 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/PrestaShopBundle/Resources/views/Admin/Sell/Order/Order/view.html.twig` | `renderhook('displayAdminOrderMain', ...)` (73) | 20 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/PrestaShopBundle/Routing/YamlModuleLoader.php` | prefiks `/modules` za rute modula (104-108) | 4.4 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/PrestaShopBundle/EventListener/TokenizedUrlsListener.php` | rute `_...` i `api_...` bez `_token` (91) | 4.4 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/Adapter/Security/Admin.php` | prijava zaposlenog (90) | 4.4 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/PrestaShopBundle/EventListener/AccessDeniedListener.php` | obrada `@AdminSecurity` (80) | 4.4 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/PrestaShopBundle/Security/Voter/PageVoter.php` | `UPDATE = 'update'` (41) | 20.2 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/PrestaShopLogger.php` | `addLog()` (141), ID zaposlenog iz Context-a (152-157) | 10, 15 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/app/AppKernel.php` | autoload modula (235) | 4.1 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/config/defines.inc.php` | `_PS_MODE_DEV_ = false` (29) | 10.3 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/Core/Payment/PaymentOption.php` | klasa opcije plaćanja | 16.3 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/controller/ModuleFrontController.php` | roditelj front controller-a | 17, 18 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/src/PrestaShopBundle/Controller/Admin/FrameworkBundleAdminController.php` | roditelj admin kontrolera | 11 |
| `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/PhpEncryption.php` | šifrovanje u `Encryptor`-u | 6.3 |

**Hook-ovi plugina:**

| Hook | Vrsta | Metoda u `/home/veljkozbiljic/logeecom/plugin0/plugin0.php` | Registrovan u `install()` |
|---|---|---|---|
| `actionDispatcherBefore` | action (radnja) | `hookActionDispatcherBefore()` 75-78 | linija 47 |
| `paymentOptions` | payment | `hookPaymentOptions()` 90-111 | linija 48 |
| `displayAdminOrderMain` | display (vraća HTML) | `hookDisplayAdminOrderMain()` 124-166 | linija 49 |

---

# Deo V. Provere

Pravilo za sve provere: **prvo pogledaj, pa zaključi.** Nijedna provera ovde ništa ne menja, osim kad je to
izričito rečeno (keš u 31.1, skripta u 31.4 samo ako je sam napišeš da menja).

Sve komande se kucaju u WSL Ubuntu terminalu. Kontejner `logeecom_dev` mora da radi
(`cd /home/veljkozbiljic/logeecom && docker compose up -d app`, OKRUZENJE.md 4.1), a za browser i ngrok tunel.

## 26. Unit testovi: kako se pokreću i šta dokazuju?

### 26.1 Pokretanje

Testovi se pokreću **u kontejneru**, na PHP-u 8.2.25 na kome radi i prodavnica (WSL ima PHP 8.5.4 i nema
`composer`; kontejner ima `composer` 2.2.9 u `/usr/local/bin/composer`, provereno 2026-10-16):

```bash
docker exec -u 1000:1000 -e HOME=/tmp/plugin0-home -w /var/www/html/public/prestashop/8-2-4/modules/plugin0 logeecom_dev php -d xdebug.mode=off vendor/bin/phpunit
```

- `-u 1000:1000`: radi kao tvoj WSL korisnik, da `.phpunit.cache/` ne pripadne `root`-u.
- `-e HOME=/tmp/plugin0-home`: korisnik 1000 u kontejneru nema svoj home folder.
- `-w .../modules/plugin0`: radni folder je plugin (bind mount tvog foldera).
- `php -d xdebug.mode=off`: Xdebug je uključen u kontejneru; ovako testovi ne čekaju IDE.

Rezultat 2026-10-16: `OK (169 tests, 460 assertions)`, PHPUnit 11.5.57.

Jedan folder ili fajl: dodaj putanju na kraj, npr. `... vendor/bin/phpunit tests/Unit/Order`.

Ako `/home/veljkozbiljic/logeecom/plugin0/vendor/` fali (nov `git clone`), zavisnosti se instaliraju istim putem:

```bash
docker exec -u 1000:1000 -e HOME=/tmp/plugin0-home -w /var/www/html/public/prestashop/8-2-4/modules/plugin0 logeecom_dev composer install
```

### 26.2 Šta dokazuju testovi plugina (78 pokretanja)

**`/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Order/OperationValidatorTest.php`** (pravila panela):

| Test | Dokazuje |
|---|---|
| `testAuthorisedPaymentAllowsPartialCaptureAndCancel` | autorizovano plaćanje dozvoljava delimičan capture i cancel |
| `testCaptureAboveAuthorisedAmountIsRejected` | capture veći od dostupnog se odbija |
| `testRefundAboveCapturedAmountIsRejected` | refund veći od naplaćenog se odbija |
| `testCapturedPaymentCannotBeCancelled` | naplaćeno plaćanje se ne otkazuje |
| `testRemainderOfPartiallyCapturedPaymentCanBeCancelled` | ostatak posle delimične naplate može da se otkaže |
| `testCancelAboveUncapturedRemainderIsRejected` | cancel veći od nenaplaćenog ostatka se odbija |
| `testFullyCapturedDetection` | `fullyCaptured()` |
| `testRefundOfAuthorisedOnlyPaymentIsNotPossible` | refund samo-autorizovanog plaćanja nije moguć |
| `testCaptureOfCapturedPaymentIsNotPossible` | capture već naplaćenog nije moguć |
| `testMissingAmountIsRejectedBeforeAnythingElse` | neispravan iznos se odbija prvi |
| `testUnknownActionIsRejected` | nepoznata radnja |

**`/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Order/MinorUnitsTest.php`**: tekst u najmanje jedinice
(`testParsesTextIntoMinorUnits`), odbijanje svega što nije pozitivan iznos (`testRejectsWhatIsNotAPositiveAmount`),
nazad u tekst (`testFormatsMinorUnitsBackToText`), tamo i nazad bez gubitka (`testParseAndFormatRoundTrip`).

**`/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Order/OperationLogTest.php`** (usklađivanje loga): red sa ID-jem
postaje `success` kad je Worldline naplatio; ostaje `pending` dok je u obradi; postaje `refused` kad je Worldline
odbio; stari red bez ID-ja se upari po vrsti i iznosu samo kad je jednoznačno; dvosmislen iznos se ne pogađa;
operacija vezana za drugi red se ne koristi ponovo; `_0` se nikad ne uparuje; `rejected` i `refused` se ne diraju;
cancel se završava.

**`/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Order/PaymentsProxyTest.php`** (problem 2 iz 21.2): zatvorena
rezervacija se čita kao naplaćena i vraćljiva; sa svim vraćenim kao `REFUNDED`; delimičan refund ostaje
naplaćeno; rezervacija sa ostatkom, naplata u obradi, ništa naplaćeno, drugi statusi i odgovor bez iznosa prolaze
nepromenjeni; omotač pravilo primenjuje na rezultat Core-a.

**`/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Checkout/HostedCheckoutProxyTest.php`** (problem 1 iz 21.1):
kartični brend dobija usklađen mobile i redirect deo; izbor PRE_AUTHORIZATION zamenjuje FINAL_AUTHORIZATION za
kartični brend; kartični režim ostaje kad zamena ne važi; PRE_AUTHORIZATION ne dira druge proizvode; svaki kartični
brend se usklađuje; ostali proizvodi zadržavaju Core vrednosti; delovi koji fale ne ruše usklađivanje.

**`/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Settings/PaymentActionSettingTest.php`**: izbor se deli na Core
akciju i kartični režim; iz dve vrednosti se vraća izbor; SALE pobeđuje šta god kartični režim kaže; bez
kartičnog režima važi FINAL_AUTHORIZATION; nepoznate vrednosti nisu ispravne.

### 26.3 Šta dokazuju demo testovi (91 pokretanje)

Fajlovi u `/home/veljkozbiljic/logeecom/plugin0/tests/Unit/PaymentMethods/`,
`/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Settings/Service/` i
`/home/veljkozbiljic/logeecom/plugin0/tests/Unit/Configuration/` su identični demo projektu. Rade nad in-memory
repozitorijumima iz `/home/veljkozbiljic/logeecom/plugin0/tests/Support/`, ne nad bazom. Dokazuju da su demo pravila
u `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom` netaknuta: jedinstven kod, redosled i "pravljenje mesta", neuspeo upis = jedan ERROR zapis,
default-i podešavanja, log svake izmene, pravila konfiguracije.

### 26.4 Šta unit testovi NE pokrivaju

Sve što zahteva PrestaShop klase, bazu ili Worldline: `ShopOrderService`, `BaseRepository`, `LoggerService`,
`PrestaShopCartProvider`, `AvailablePaymentMethods`, kontroleri, `PaymentManager` (osim čistih delova koje koristi),
hook-ovi, šabloni. To se proverava u bazi, logu i browseru (28-30) ili skriptom u kontejneru (31.4).

---

## 27. Provere iz terminala: grep, git, LF, Core netaknut

### 27.1 Git stanje

```bash
git -C /home/veljkozbiljic/logeecom/plugin0 status --short
```

```bash
git -C /home/veljkozbiljic/logeecom/plugin0 log --oneline -12
```

Ne radi `commit` ni `push` iz terminala za tebe; to radiš sam iz PhpStorm-a (iz beleški, tvoje pravilo).

### 27.2 LF (Linux) krajevi linija

Pravilo iz tvojih uputstava: svi fajlovi su LF. Provera pre commit-a:

```bash
git -C /home/veljkozbiljic/logeecom/plugin0 ls-files --eol | grep -v "w/lf" | grep -v "w/none"
```

Prazan izlaz znači da nijedan praćen fajl nema CRLF. Stanje 2026-10-16: 121 fajl `i/lf w/lf` i jedan prazan
(`/home/veljkozbiljic/logeecom/plugin0/views/img/payment-methods/.gitkeep`, `i/none w/none`). Za nov fajl koji još nije u git-u:

```bash
file /home/veljkozbiljic/logeecom/plugin0/docs/VODIC.md
```

Ako ispis sadrži "with CRLF line terminators", fajl nije LF.

### 27.3 Grep provere pravila

**Status narudžbine se menja samo u `ShopOrderService`** (tiket MIDU-1221):

```bash
grep -rn "validateOrder\|changeIdOrderState\|setCurrentState" /home/veljkozbiljic/logeecom/plugin0/src /home/veljkozbiljic/logeecom/plugin0/controllers /home/veljkozbiljic/logeecom/plugin0/plugin0.php
```

Očekivano: kod samo u `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/ShopOrderService.php` (linije 59, 86,
101, 155); ostali pogoci su komentari u drugim fajlovima (provereno 2026-10-16).

**Nema `try/catch` oko Core fasada u front controller-ima** (tiket MIDU-1221):

```bash
grep -nE "try \{|catch \(" /home/veljkozbiljic/logeecom/plugin0/controllers/front/payment.php /home/veljkozbiljic/logeecom/plugin0/controllers/front/return.php
```

Očekivano: prazan izlaz (provereno 2026-10-16). Šira pretraga `grep -n "try\|catch"` bi našla i komentar
`/home/veljkozbiljic/logeecom/plugin0/controllers/front/return.php:21` i poruke kupcu sa rečima "try again" (`/home/veljkozbiljic/logeecom/plugin0/controllers/front/payment.php:74`, `/home/veljkozbiljic/logeecom/plugin0/controllers/front/return.php:98`), što nije kod.

**`OrderAPI` zove samo `PaymentManager`**:

```bash
grep -rn "OrderAPI::get" /home/veljkozbiljic/logeecom/plugin0/src /home/veljkozbiljic/logeecom/plugin0/controllers /home/veljkozbiljic/logeecom/plugin0/plugin0.php
```

Očekivano: tri pogotka, svi u `/home/veljkozbiljic/logeecom/plugin0/src/Order/PaymentManager.php` (linije 94, 125,
180; provereno 2026-10-16).

**Svi ulazi podižu Core**:

```bash
grep -rn "Bootstrap::boot()" /home/veljkozbiljic/logeecom/plugin0/src /home/veljkozbiljic/logeecom/plugin0/controllers /home/veljkozbiljic/logeecom/plugin0/plugin0.php
```

**`/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom` je i dalje identičan demo projektu**:

```bash
diff -rq /home/veljkozbiljic/laravel-docker/src /home/veljkozbiljic/logeecom/plugin0/lib/Logeecom
```

Prazan izlaz = identično (tako je bilo 2026-10-16).

### 27.4 Core i SDK nisu menjani

Pravilo: Core i SDK u `/home/veljkozbiljic/logeecom/plugin0/vendor/` se nikad ne menjaju. `/home/veljkozbiljic/logeecom/plugin0/vendor/` je u `.gitignore`, pa git izmenu tu ne bi ni
video. Dokaz je poređenje sa originalom tačnog commit-a iz `composer.lock`:

| Paket | Verzija | Commit iz `composer.lock` |
|---|---|---|
| `wl-online-payments-direct/plugin-core-base` | 1.0.7 | `e756094d32902198c2b49ba23f18a5ccc530b438` |
| `wl-online-payments-direct/sdk-php` | 6.2.0 | `342e76315110ed1c8ec2c1e7968eb2cae9e6003f` |

Postupak (traži internet; po beleškama poslednji put urađeno 2026-10-15 i bilo je identično, **iz beleški**):

```bash
mkdir -p /tmp/core-check && cd /tmp/core-check
```

```bash
curl -sL -o core.zip https://codeload.github.com/wl-online-payments-direct/plugin-core-base/zip/e756094d32902198c2b49ba23f18a5ccc530b438
```

```bash
unzip -q core.zip
```

```bash
diff -rq /tmp/core-check/plugin-core-base-e756094d32902198c2b49ba23f18a5ccc530b438/src /home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src
```

Prazan izlaz = Core netaknut. Ime raspakovanog foldera zavisi od GitHub-a; ako se razlikuje, pogledaj ga sa `ls`.
Isto za SDK sa `sdk-php` i njegovim commit-om.

---

## 28. Provere u bazi

Baza je u kontejneru; upit se šalje ovako (upozorenje "Using a password on the command line" je bezopasno):

```bash
docker exec logeecom_dev mysql -uroot -proot prestashop -e "SELECT type, COUNT(*) AS redova FROM ps_plugin0_entity GROUP BY type ORDER BY type;"
```

Ili interaktivno: `docker exec -it logeecom_dev mysql -uroot -proot prestashop`. Iz PhpStorm-a ili DBeaver-a:
`127.0.0.1:3333`, `root` / `root` (OKRUZENJE.md 10.7).

**Ne ispisuj kolonu `data` za `ConnectionConfig`**: sadrži PSPID i šifrovane tajne.

| Šta proveravaš | Upit |
|---|---|
| koliko redova koje vrste | `SELECT type, COUNT(*) FROM ps_plugin0_entity GROUP BY type;` |
| da li je prodavnica povezana | `SELECT id, index_1 AS storeId FROM ps_plugin0_entity WHERE type = 'ConnectionConfig';` |
| sačuvana podešavanja plugina | `SELECT index_1 AS name, JSON_UNQUOTE(JSON_EXTRACT(data, '$.value')) AS value FROM ps_plugin0_entity WHERE type = 'Configuration';` |
| Core payment settings (akcija, statusi) | `SELECT JSON_EXTRACT(data, '$.paymentSettings') FROM ps_plugin0_entity WHERE type = 'PaymentSettingsEntity';` |
| da li je Core debug mode uključen | `SELECT JSON_EXTRACT(data, '$.logSettings.debugMode') FROM ps_plugin0_entity WHERE type = 'LogSettings';` |
| metode plaćanja | `SELECT id, index_1 AS code, index_2 AS type, index_3 AS isActive, CAST(index_4 AS UNSIGNED) AS sortOrder, JSON_UNQUOTE(JSON_EXTRACT(data, '$.name')) AS name FROM ps_plugin0_entity WHERE type = 'PaymentMethod';` |
| poslednje transakcije | `SELECT id, index_2 AS paymentId, index_3 AS cartId, CAST(index_5 AS UNSIGNED) AS statusCode, FROM_UNIXTIME(CAST(index_8 AS UNSIGNED)) AS updatedAt FROM ps_plugin0_entity WHERE type = 'PaymentTransaction' ORDER BY id DESC LIMIT 10;` |
| narudžbine plugina | `SELECT id_order, id_cart, current_state, total_paid, date_add FROM ps_orders WHERE module = 'plugin0' ORDER BY id_order DESC;` |
| istorija statusa jedne narudžbine | `SELECT oh.id_order_state, osl.name, oh.date_add FROM ps_order_history oh JOIN ps_order_state_lang osl ON osl.id_order_state = oh.id_order_state AND osl.id_lang = 1 WHERE oh.id_order = 16 ORDER BY oh.date_add;` |
| uplate jedne narudžbine | `SELECT amount, payment_method, transaction_id, date_add FROM ps_order_payment WHERE order_reference = (SELECT reference FROM ps_orders WHERE id_order = 16);` |
| log operacija panela | `SELECT CAST(index_1 AS UNSIGNED) AS orderId, FROM_UNIXTIME(CAST(index_2 AS UNSIGNED)) AS createdAt, JSON_UNQUOTE(JSON_EXTRACT(data, '$.action')) AS action, JSON_EXTRACT(data, '$.amount') AS amount, JSON_UNQUOTE(JSON_EXTRACT(data, '$.outcome')) AS outcome, JSON_UNQUOTE(JSON_EXTRACT(data, '$.providerStatus')) AS status FROM ps_plugin0_entity WHERE type = 'PaymentOperation' ORDER BY id DESC LIMIT 20;` |
| razgovor sa Worldline-om (samo sa debug mode) | `SELECT id, index_4 AS orderId, index_5 AS paymentNumber, FROM_UNIXTIME(CAST(index_7 AS UNSIGNED)) AS createdAt, JSON_UNQUOTE(JSON_EXTRACT(data, '$.monitoringLog.requestEndpoint')) AS endpoint, JSON_EXTRACT(data, '$.monitoringLog.statusCode') AS http FROM ps_plugin0_entity WHERE type = 'MonitoringLog' ORDER BY id DESC LIMIT 20;` |
| hook-ovi modula | `SELECT h.name FROM ps_hook_module hm JOIN ps_hook h ON h.id_hook = hm.id_hook JOIN ps_module m ON m.id_module = hm.id_module WHERE m.name = 'plugin0';` |
| valute i zemlje modula | `SELECT c.iso_code FROM ps_module_currency mc JOIN ps_currency c ON c.id_currency = mc.id_currency JOIN ps_module m ON m.id_module = mc.id_module WHERE m.name = 'plugin0';` (isto sa `ps_module_country` i `ps_country`) |
| ID-jevi statusa | `SELECT name, value FROM ps_configuration WHERE name IN ('PS_OS_PAYMENT', 'PS_OS_ERROR', 'PS_OS_BANKWIRE', 'PS_OS_CANCELED', 'PS_OS_REFUND');` |

Kolone `index_N` znače različito za svaku vrstu; redosled je u tabeli 9.3. Brojevi su tekst sa vodećim nulama,
zato `CAST(... AS UNSIGNED)`.

---

## 29. Provere u logu

### 29.1 Stranica Logs u pluginu

`https://8-2-4.prestashop.veljko.ngrok.app/admin174kob088ranecckhxg/` → bočni meni Worldline → **Logs**. Filter
Level = Error za greške. Kolona Context sadrži detalje (npr. `orderId`, `paymentId`, `error`, `at` = fajl:linija
greške u panelu).

### 29.2 Isto iz baze

```bash
docker exec logeecom_dev mysql -uroot -proot prestashop -e "SELECT id_log, date_add, LEFT(message, 250) AS message FROM ps_log WHERE message LIKE 'WORLDLINE [ERROR]%' ORDER BY id_log DESC LIMIT 20;"
```

Za sve nivoe zameni uslov sa `LIKE 'WORLDLINE [%'`, za jednu komponentu dodaj npr. `AND message LIKE '%] ShopOrderService:%'`.

### 29.3 Koju poruku tražiš za koju radnju

| Radnja | Očekivan zapis (nivo, komponenta: poruka) |
|---|---|
| narudžbina napravljena na povratku | `INFO ShopOrderService: Order created from cart after Worldline payment` |
| status narudžbine promenjen | `INFO ShopOrderService: Order status changed after Worldline payment update` |
| narudžbina u Payment error, status nije diran | `WARNING ShopOrderService: Order left in Payment error, status not changed automatically` |
| bilo koja radnja u panelu | `INFO PaymentManager: Payment operation attempted from the order page` (sa `outcome`) |
| panel pukao | `ERROR PaymentManager: Worldline payment panel could not be rendered on the order page` |
| usklađivanje loga operacija nije uspelo | `WARNING PaymentManager: Operation log could not be reconciled with Worldline` |
| greška Core-a (npr. pogrešan RETURNMAC) | `ERROR Core: Payment transaction for payment ID ... not found.` ili `ERROR Core: Unhandled error occurred.` |
| metoda napravljena / izmenjena / prekidač / obrisana | `INFO PaymentMethods: Payment method created` (`updated`, `toggled`, `deleted`) |
| podešavanje promenjeno | `INFO Settings: Setting changed` (`WARNING` za Log level) |

Ako zapisa nema: proveri Log level (DEBUG zapisi se ne upisuju na nivou Info) i da li je kod uopšte stigao do te
linije (Apache access log, 29.4).

### 29.4 Ostali logovi u kontejneru

PHP greške (fatal, warning), dva loga:

```bash
docker exec logeecom_dev tail -n 50 /var/log/php8.2-fpm.log
```

```bash
docker exec logeecom_dev tail -n 50 /var/log/apache2/error.log
```

Koji zahtevi ka pluginu su stigli (front adrese `/module/plugin0/...` i admin adrese `/modules/worldline/...`):

```bash
docker exec logeecom_dev sh -c "grep -h 'plugin0\|worldline/' /var/log/apache2/other_vhosts_access.log | tail -20"
```

Symfony log admin strane: `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/var/logs/prod-YYYY-MM-DD.log`
(`YYYY-MM-DD` je datum).

---

## 30. Provere u browseru, scenario po scenario

Pripreme: Docker Desktop, kontejner, ngrok tunel
(`ngrok http 80 --url=https://8-2-4.prestashop.veljko.ngrok.app`), admin nalog. Test kartica koja prolazi:
Visa `4330264936344675` (iz beleški). Za kraj do kraja korpa mora biti u **EUR** (16.4).

### 30.1 Konekcija

1. Configuration, Test, pogrešan API Secret, Save → "Connection failed, nothing was saved."
2. Ispravni kredencijali, Save → "Connected to Worldline. Configuration saved.", značka Connected, u meniju
   Payment methods, Settings, Logs.

### 30.2 Metode plaćanja

1. Add payment method sa kodom koji postoji → poruka ispod Code.
2. Nova metoda sa zauzetim sort order-om → druge metode pomerene nadole.
3. Prekidač → metoda prigušena; na checkout-u nestane.
4. Delete → potvrda → nestane iz liste; u Logs zapis "Payment method deleted".

### 30.3 Podešavanja

1. Rows per page 3 → "must be at least 5"; 20 → lista ima do 20 redova.
2. Payment action = FINAL_AUTHORIZATION, Save → `PaymentSettingsEntity.paymentAction` u bazi je
   `FINAL_AUTHORIZATION`.
3. Restore defaults → Payment action SALE, tri demo ključa obrisana iz baze.

### 30.4 Checkout

| Scenario | Koraci | Očekivano |
|---|---|---|
| uspeh | korpa EUR, adresa RS, "Viza payment", PLACE ORDER, kartica | potvrda narudžbine; status 2 (SALE) ili 10 (autorizacija); u logu "Order created..." |
| ponovljen povratak | ponovo otvori istu povratnu adresu iz istorije browsera | ista potvrda; nema nove narudžbine ni novog reda istorije |
| otkazivanje | Cancel na Worldline strani | žuta poruka, korpa ostaje, narudžbine nema |
| odbijanje | odbijen 3-D Secure u simulatoru | crvena poruka, korpa ostaje |
| lažan povratak | izmeni jedan znak u `RETURNMAC` | nazad, ništa se ne menja; u logu `ERROR Core: Payment transaction ... not found.` |
| valuta koju Worldline ne nudi | korpa u RSD | "The payment could not be started..." |

### 30.5 Panel na narudžbini

Za capture i cancel prvo Payment action = PRE_AUTHORIZATION (ili FINAL_AUTHORIZATION za pun capture) i novo
plaćanje.

| Scenario | Očekivano |
|---|---|
| otvaranje narudžbine plaćene kroz plugin0 | kartica "Worldline payment" između blokova Details i Payment |
| capture 0, "abc", "1.005" | `rejected`: "Enter a positive amount..."; u MonitoringLog-u nema POST-a |
| capture veći od dostupnog | `rejected`: "Capture of ... exceeds the capturable amount of ..." |
| delimičan capture | `success` ili `pending`; posle osvežavanja `success` |
| cancel ostatka | `success`; posle zatvaranja rezervacije narudžbina "Payment accepted" |
| cancel kad je sve naplaćeno | `rejected`: "The payment cannot be cancelled: ... Refund it instead." |
| refund veći od naplaćenog | `rejected` |
| refund svega naplaćenog | narudžbina "Refunded" |
| zaposleni bez prava Edit na Orders (npr. profil Support) | panel bez formi ("Your profile cannot edit orders..."); direktan POST na rutu → 403 |
| zaobilaženje isključenog dugmeta (DevTools: ukloni `disabled`, pošalji) | server odbije (`rejected`) |

Po beleškama, svih 7 kriterijuma prihvatanja tiketa MIDU-1224 je pokazano u browseru pre commit-a `06a7b91`, a u
prodavnici je ostao test profil Support (ID 5) i njegov zaposleni (**iz beleški**; profil sa ID-jem 5 i imenom
Support postoji u bazi 2026-10-16).

---

## 31. Kad izmena "ne radi": keš, ruta, hook, skripta

### 31.1 Symfony keš (rute, Twig, anotacije)

PrestaShop radi u prod režimu, pa Symfony kešira rute, servise, Twig šablone i anotacije u
`/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/var/cache/prod/`. Posle izmene `/home/veljkozbiljic/logeecom/plugin0/config/routes.yml`,
Twig šablona ili `@AdminSecurity` anotacije obriši keš, **kao `www-data`** (inače keš fajlovi pripadnu `root`-u i
PHP-FPM ne može da ih menja):

```bash
docker exec -u www-data logeecom_dev php /var/www/html/public/prestashop/8-2-4/bin/console cache:clear --env=prod --no-warmup
```

Ova komanda menja sadržaj keša (briše ga); prvi sledeći zahtev ga ponovo pravi i traje duže.

### 31.2 Ruta ne postoji (404 na admin adresi)

1. Da li ruta postoji u `/home/veljkozbiljic/logeecom/plugin0/config/routes.yml`?
2. Da li adresa ima prefiks `/modules` (4.4)?
3. Keš (31.1).
4. Da li je modul aktivan (`ps_module.active = 1`)?

### 31.3 Hook se ne izvršava ili kartica nestane

1. Da li je hook registrovan (upit "hook-ovi modula", 28)? Modul instaliran pre dodavanja hook-a ga nema dok se
   ne registruje (reinstalacija briše tabelu, 4.2; u dev prodavnici su hook-ovi zato dodati skriptom).
2. Kartica panela nestane bez poruke → izuzetak je progutao PrestaShop (`Hook::callHookOn`, 20.2); plugin ga za
   panel loguje, pa pogledaj Logs (ERROR, komponenta `PaymentManager`).
3. Panel se ne vidi na narudžbini koja nije plaćena kroz plugin0 ili nema Core transakciju; to je namerno.

### 31.4 Provera servisa skriptom u kontejneru

Za ono što unit testovi ne pokrivaju, a browser je spor: PHP skripta koja podigne PrestaShop i Core. **Šablon**
(ime fajla je primer; skripta sama ništa ne menja dok joj ti ne dopišeš upis):

```php
<?php
// /tmp/plugin0-probe.php u kontejneru (ne u repozitorijumu)
require '/var/www/html/public/prestashop/8-2-4/config/config.inc.php';
require '/var/www/html/public/prestashop/8-2-4/modules/plugin0/vendor/autoload.php';

$context = Context::getContext();
$context->shop = new Shop(1);
$context->language = new Language(1);

\Plugin0\Bootstrap::boot();   // hook actionDispatcherBefore se iz CLI-ja ne okida (5.4)

// primer čitanja: metode koje bi korpa u EUR sa adresom u RS videla
foreach (\Plugin0\Checkout\AvailablePaymentMethods::for('EUR', 'RS') as $method) {
    echo $method->id, ' ', $method->name, ' ', $method->code, PHP_EOL;
}
```

Sačuvaj šablon u WSL-u kao `/home/veljkozbiljic/plugin0-probe.php` (van repozitorijuma, da ne uđe u git), pa ga
kopiraj u kontejner i pokreni kao `www-data`, bez Xdebug-a:

```bash
docker cp /home/veljkozbiljic/plugin0-probe.php logeecom_dev:/tmp/plugin0-probe.php
```

```bash
docker exec -u www-data logeecom_dev php -d xdebug.mode=off /tmp/plugin0-probe.php
```

Šablon je pokrenut 2026-10-16 tačno ovako: ispisao je `2 Viza payment 1` (metoda "Appla Pay" je, kao što treba,
izostala), izlazni kod 0, a u `ps_log` nije nastao nijedan nov red (Log level Info ne upisuje DEBUG zapise
čitanja). Posle provere obriši skriptu iz kontejnera:
`docker exec logeecom_dev rm -f /tmp/plugin0-probe.php`.

---

# Deo VI. Ograničenja, zamke, nedoslednosti

## 32. Šta plugin0 (još) ne radi?

| # | Šta fali | Posledica danas | Šta bi trebalo (Core deo koji to radi) |
|---|---|---|---|
| 1 | **webhook**: urađen 2026-10-19 (`/home/veljkozbiljic/logeecom/plugin0/controllers/front/webhook.php`, `src/Webhook/*`, `src/Infrastructure/WebhookTransformer.php`, `src/Infrastructure/StatusUpdateService.php`, `src/Entity/WebhookEventEntity.php`; ceo opis u `docs/WEBHOOK.md`) | ostaje: prijava adrese u Merchant Portal-u (Developer > Webhooks) i prva prava isporuka od Worldline-a; bez toga promene iz portala i dalje ne stižu same | prijava adrese sa Configuration stranice (12.2) u portal; posle toga provera po `docs/WEBHOOK.md`, sekcija 7 |
| 2 | **red zadataka**: nema repozitorijuma za `QueueItem` ni front controller-a `asyncprocess` (`Plugin0\Configuration::getAsyncProcessUrl()` ga ipak imenuje, `/home/veljkozbiljic/logeecom/plugin0/src/Configuration.php:57`) | ne rade Core pozadinski poslovi: provera statusa kad webhook ne stigne (`TransactionStatusCheckTask`), automatski capture, čišćenje završenih zadataka, brisanje isteklih `MonitoringLog` redova, Core disconnect | `QueueItemRepository` registrovan za `QueueItem` + `asyncprocess` koji zove `AsyncProcessService::runProcess($guid)` (CORE.md 4.9, 7.9) |
| 3 | metode tipa **iFrame** (Worldline Hosted Tokenization) | ne nude se na checkout-u (16.2, uslov 2) | `CheckoutAPI::hostedTokenization()`; pazi, Core metoda se zove `crate`, sa greškom u imenu (CORE.md, zamka 12) |
| 4 | proizvodi sa odvojenim capture-om (Apple Pay, Google Pay, PayPal, grupa `cards`...) | ne nude se (16.2, uslov 4) | Core konfiguracija metode (`PaymentMethodConfigEntity`) za te proizvode |
| 5 | provera dostupnosti kod Worldline-a pri prikazu opcija | metoda može da se prikaže, a da sesija padne (npr. korpa u RSD) | `CheckoutAPI::paymentMethods()->getAvailablePaymentMethods()` (`GET /products`) pre prikaza |
| 6 | `VersionService` | `AdminAPI::version()` ne radi; plugin ga ne zove | implementacija `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/Domain/Integration/Version/VersionService.php` |
| 7 | admin za ostala Core podešavanja | automatski capture, broj pokušaja, mapiranje statusa, šablon strane, debug mode i trajanje logova, Pay by Link, webhook podešavanja nemaju stranicu; menjaju se samo skriptom | stranice nad `AdminAPI::generalSettings()` |
| 8 | pun disconnect | Disconnect briše samo kredencijale; Core podešavanja, transakcije i metode ostaju (12.4) | Core `generalSettings()->disconnect()` posle reda zadataka |
| 9 | iznos uplate posle delimične naplate | PrestaShop blok "Payment" pokazuje ceo iznos fakture (20.9); po beleškama ostavljeno svesno | — |
| 10 | ko je šta uradio na stranici Logs | `ps_log.id_employee` se upisuje, ali se ne prikazuje (15) | kolona u `LogReader` i šablonu |
| 11 | više prodavnica (multistore) | Core podaci se čuvaju po `storeId`, ali metode plaćanja i ključevi plugina (`ConfigEntity`, kontekst `''`) važe za celu instalaciju; rad sa više prodavnica nije proban, **neprovereno** | — |
| 12 | prevodi | poruke su na engleskom; front poruke idu kroz `trans(..., 'Modules.Plugin0.Shop')`, ali prevodi nisu napravljeni (**neprovereno** u Translations stranici) | prevodi za domen `Modules.Plugin0.Shop` |

---

## 33. Zamke Core-a i PrestaShop-a koje moraš da znaš

Svaka zamka: šta je, gde je obrađena u pluginu. Više detalja za Core: `/home/veljkozbiljic/logeecom/plugin0/docs/CORE.md`,
poglavlje 9.

### 33.1 Core

| # | Zamka | Gde je u ovom vodiču |
|---|---|---|
| 1 | fasade ne bacaju izuzetke; uvek proveri `isSuccessful()` | 7.3 |
| 2 | `void` metoda fasade vraća `null` kad uspe | 7.3 |
| 3 | mimo fasade nema `StoreContext`-a; koristi `StoreContext::doWithStore()` | 7.4 |
| 4 | filtrira se samo po indeksiranim poljima; brojevi u indeksima su tekst sa vodećim nulama | 9.2, 9.4 |
| 5 | Core ignoriše rezultat `update()` i `delete()` | 9.4, 12.4 |
| 6 | `ActiveConnectionProvider` pamti konekciju do kraja zahteva: posle čuvanja nove konekcije, servisi koji su je već pročitali u istom zahtevu vide staru | CORE.md, zamka 2 |
| 7 | `returnHmac = null` isključuje proveru RETURNMAC-a | 18.3 |
| 8 | jedan povratak kupca = više `updateOrderStatus` poziva | 18.2, 19.3 |
| 9 | Core brava za pravljenje narudžbine nije atomična | 19.3 |
| 10 | bez webhook-a petlja čekanja uvek traje ~10 s; zato plugin prvo pita Worldline | 18.2 |
| 11 | proizvodi sa odvojenim capture-om ruše `createSession` bez Core konfiguracije metode | 16.2 |
| 12 | `OrderAPI` capture/refund/cancel ništa ne proveravaju i ne diraju narudžbinu | 20.4 |
| 13 | bez sačuvanih `PaymentSettings` Core plaća kao SALE | 14.2 |
| 14 | "delimično vraćeno" ima prednost u mapiranju; prazno = narudžbina se ne menja | 19.4 |
| 15 | za kartični brend Core šalje protivrečne režime autorizacije | 21.1 |
| 16 | Core status i zastavice čita samo sa rezervacije `_0` | 21.2 |
| 17 | pun povraćaj Core poredi sa autorizovanim, ne sa naplaćenim iznosom | 19.4, 21.2 |
| 18 | Core Logger šalje sve nivoe pluginu; filtriranje radi `LoggerService` | 10.3 |
| 19 | tajne zavise od `_NEW_COOKIE_KEY_`; neuspelo dešifrovanje daje `''` | 6.3 |
| 20 | JSON ključ `cratedAt` u `PaymentTransactionEntity` je slovna greška Core-a, ali deo formata podataka: ne "popravljaj" | CORE.md, zamka 21 |
| 21 | Core webhook put ne nosi ID događaja, greške potpisa vraća kao `general.unhandled`, a događaj bez objekta plaćanja obara na `PaymentId::parse('')`; plugin to rešava omotačem i podklasom (`docs/WEBHOOK.md`, 2.2-2.4) | CORE.md, zamka 34 |
| 22 | Core čita Worldline pre zaključavanja korpe, pa povratak kupca i webhook mogu da primene zastareli snimak; plugin zaključava `updateOrderStatus` po transakciji (`Infrastructure\StatusUpdateService`) | `docs/WEBHOOK.md`, 4 |
| 23 | Worldline preprod od 2026-10-19 vraća `returnmac` malim slovima, SDK čita samo `RETURNMAC`, pa povratak kupca završava sa "nije potvrđeno" (korpa 18); popravljeno u pluginu (`Infrastructure\Sdk\*`) | CORE.md, zamka 35; 18.1 |

### 33.2 PrestaShop

| # | Zamka | Gde je u ovom vodiču |
|---|---|---|
| 1 | `validateOrder()` zove `die()` umesto da baci izuzetak | 19.3 |
| 2 | hook izuzetak van debug režima nestane bez traga (`Hook::callHookOn`) | 20.2 |
| 3 | `@NekoIme` u tekstu docblock-a Doctrine čita kao anotaciju | 4.4 |
| 4 | prod keš ruta, Twig-a i anotacija; brisanje samo kao `www-data` | 31.1 |
| 5 | admin rute modula dobijaju prefiks `/modules` | 4.4 |
| 6 | GET forma zamenjuje query string, pa `_token` mora u skriveno polje | 13.4, 15 |
| 7 | `bin/console` i skripte ne okidaju `actionDispatcherBefore`; pozovi `Bootstrap::boot()` sam | 5.4, 31.4 |
| 8 | Uninstall briše `ps_plugin0_entity` sa svim podacima | 4.2 |
| 9 | front controller bez `FrontController::init()` nema Symfony container, pa `validateOrder` puca na `Tools::getContextLocale` posle upisa narudžbine (polu-napravljena narudžbina 17, 2026-10-19); webhook kontroler zato zove `Controller::init()` | `docs/WEBHOOK.md`, 2.6 |
| 9 | `Order::getHistory()` i `Cart::orderExists()` keširaju do kraja zahteva | 19.3 |
| 10 | prelazak u status sa `paid = 1` upiše uplatu za ceo iznos fakture | 20.9 |
| 11 | `prepend-autoloader: false`, inače sukob verzija paketa u Module Manager-u | 4.1 |
| 12 | Symfony 4.4 `ParameterBag::get()` za `x[]=...` vraća niz; zato kontroleri imaju pomoćnu `scalar()` | `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/PaymentMethodsController.php:340-343`, `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/SettingsController.php:187-190`, `/home/veljkozbiljic/logeecom/plugin0/controllers/front/return.php:170-175` |
| 13 | Twig `for a, b, c, d in ...` ne raspakuje niz: dodeli indeks i ceo niz | komentar `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/order_payment_panel.html.twig:77-80` |
| 14 | PhpStorm pri commit-u prijavljuje neizbegnut `\|` u code span-u Markdown tabele i CSS u `<style>` bloku Twig layout-a | 22.6 (**iz beleški**) |
| 15 | PrestaShop admin poruke (flash) prikazuje bez escape-ovanja (`flashMessage` sa filterom `raw`), pa tekst koji je uneo korisnik, npr. ime metode plaćanja, ne sme u `addFlash()` neobrađen | 11.1 |

---

## 34. Nedoslednosti u komentarima i dokumentima

Ništa od ovoga ne menja kako plugin radi; ali ako čitaš komentar ili stari dokument, ovo je tačno stanje.
Dokumenti `OKRUZENJE.md` i `CORE.md` su **usklađeni 2026-10-16** (34.2-34.4); komentari u kodu (34.1) **nisu
menjani**.

### 34.1 Komentari u kodu (nisu menjani)

| Gde | Šta piše | Šta je tačno |
|---|---|---|
| `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/HostedCheckoutProxy.php:23-24` | proxy je registrovan u `Plugin0\Bootstrap::initServices` | registrovan je u `initProxies()` (`/home/veljkozbiljic/logeecom/plugin0/src/Bootstrap.php:139-146`), i mora tamo (5.3) |
| `/home/veljkozbiljic/logeecom/plugin0/plugin0.php:133` | `Hook::coreCallHook` van debug režima guta izuzetak | guta ga `Hook::callHookOn()` (`/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4/classes/Hook.php:411-439`); `coreCallHook()` (1081-1084) samo pozove metodu. Hvata se `Exception`, ne `Error`; plugin sam hvata `\Throwable`, pa je za panel svejedno |
| `/home/veljkozbiljic/logeecom/plugin0/src/Controller/Admin/ConfigurationController.php:142-144` | Core disconnect traži `ShopPaymentService`, "koji plugin još ne registruje" | registrovan je (`/home/veljkozbiljic/logeecom/plugin0/src/Bootstrap.php:118-120`); preostali razlog je red zadataka |
| `/home/veljkozbiljic/logeecom/plugin0/src/Order/OperationLog.php:50-52` | docblock `@return PaymentOperationEntity[] najnoviji prvi` stoji iznad docblock-a metode `reconcileForOrder()` | pripada metodi `forOrder()` (195) |
| `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/settings.html.twig:7-8` | Payment action je SALE ili FINAL_AUTHORIZATION | postoji i treći izbor, PRE_AUTHORIZATION (14.5) |
| `/home/veljkozbiljic/logeecom/plugin0/views/templates/admin/order_payment_panel.html.twig:12-15` | spisak polja reda loga | nedostaje `provider_operation_id`, koje šablon koristi (linija 140) |

### 34.2 `/home/veljkozbiljic/logeecom/plugin0/docs/OKRUZENJE.md` (usklađeno 2026-10-16)

| Poglavlje | Šta je pisalo | Šta piše sada |
|---|---|---|
| 1 i 4.1 | composer je u WSL-u (`/usr/local/bin/composer`, PHP 8.5.4), "u kontejneru nema composera" | WSL ima PHP 8.5.4, ali nema composer; composer je u kontejneru (`/usr/local/bin/composer`, 2.2.9, iz image-a) i pokreće se kao korisnik 1000 (komanda iz 26.1; `--dry-run` je 2026-10-16 javio "Nothing to install, update or remove") |
| 8.3 | composer nije u PATH-u za `root` u kontejneru | ispravka: jeste u kontejneru i u PATH-u |
| 10.4 | u `ps_plugin0_entity` je jedan red; `ps_log` ima 270 `WORLDLINE` redova | brojevi od 2026-10-16 (8 vrsta redova, 1034 `WORLDLINE` reda) i tabele narudžbina |
| 13.1 | primer rute `/worldline/settings`; hook za Symfony rute i preskakanje tokena "po komentaru, neprovereno"; boot "jednom po PHP procesu" | prava adresa sa prefiksom `/modules`; hook i token provereni u kodu PrestaShop-a (linije navedene); boot "jednom po zahtevu" |
| 13.2 | "plugin0 za sada nema front controller-e" | `payment` i `return`, sa adresama; `asyncprocess` i `webhook` ne postoje |
| 13.3 | POST na `/worldline/configuration` | prava adresa sa prefiksom `/modules` |
| 14.2 | stablo fajlova bez proxy klasa i testova plugina | dopunjeno |
| 14.3 | `install()` registruje dva hook-a; nema reda za `hookDisplayAdminOrderMain` | tri hook-a i nov red |
| 14.4 | samo servisi; `PaymentMethodEntity` je jedini entitet plugina | dodat pasus o `initProxies()` (dva proxy-ja); dva entiteta plugina |
| 14.5 | devet ruta; "sve rute imaju `AdminPlugin0Configuration`"; Settings ima tri polja | dvanaest ruta (tri rute panela, `AdminOrders`); prefiks `/modules`; četvrto polje Payment action |
| 14.6 | "Unit testovi (91, iz dema)"; testovi kopirani bez izmena | 169 pokretanja (91 demo + 78 plugin); jedina razlika u `tests/Support/RecordingLogger.php` |
| 14.7 | Core šalje samo `POST /hostedcheckouts` | prvo `GET /products`, pa `POST /hostedcheckouts` kroz plugin `HostedCheckoutProxy` |
| 14.8 | cancel samo dok ništa nije naplaćeno; Payment action ima jedan izbor; delimičan refund → `''`; `Hook::coreCallHook`; "FINAL_AUTHORIZATION ... (otvoreno)"; "Nije provereno: panel u browseru..." | cancel ostatka posle delimičnog capture-a; `reconcileLog` i `providerOperationId`; tri izbora Payment action-a; delimičan refund → `PS_OS_PAYMENT` i `PaymentsProxy` za zatvorenu rezervaciju; `Hook::callHookOn`; uzrok i rešenje (`HostedCheckoutProxy`); provereno u browseru (**iz beleški**) i iz baze; ograničenje bloka "Payment" |
| 16 | jedan red za logove plugina | tačno ko piše, filter Log level-a, nov red za `MonitoringLog` |
| 17, 19 | `composer install` iz WSL-a | komanda u kontejneru; dodata komanda za unit testove |
| 18 | Uninstall briše "i Worldline konekciju" | tačan spisak šta se briše, a šta ostaje |
| 20 | "da li `ActionDispatcherLegacyHooksSubscriber` okida hook" je neprovereno | uklonjeno, provereno u kodu (13.1) |

### 34.3 `/home/veljkozbiljic/logeecom/plugin0/docs/CORE.md` (usklađeno 2026-10-16)

| Poglavlje | Šta je pisalo | Šta piše sada |
|---|---|---|
| 2 | Core dev zavisnosti nisu instalirane "jer composer u plugin0 nema `require-dev`" | composer `require-dev` instalira samo za korenski paket; plugin0 ima sopstveni `require-dev` |
| 6.1 | plugin pregazi `initServices()`/`initRepositories()`; boot "jednom po procesu" | plugin0 pregazi i `initProxies()` (zašto baš tu); boot jednom po zahtevu |
| 7.1 | boot zovu hook, install, uninstall i admin kontroleri; `initServices()` sa šest servisa; `BaseRepository` za 15 klasa | svi ulazi; deset servisa; dva proxy-ja u `initProxies()`; 17 klasa |
| 7.4 | nazivi povratnih parametara "neprovereni" | `hostedCheckoutId` i `RETURNMAC`, viđeni u Apache access logu |
| 7.8 | disconnect "zahteva `ShopPaymentService`" kao da ga nema | registrovan je od commit-a `90a8caf`; preostali razlog je red zadataka; plugin-ov disconnect briše samo kredencijale |
| 8 | naslov kolone "grana `feature/MIDU-1221`"; boot ulazi; `StoreService` daje `''` za partially refunded; repozitorijumi bez entiteta plugina; razlog za `ShopPaymentService` "checkout još ne postoji"; admin strane bez panela i bez `return` | `feature/MIDU-1224`, `06a7b91`; svi ulazi; `PS_OS_PAYMENT`; dva entiteta plugina; tačan razlog; rute panela, prefiks `/modules`, `return` |
| 9, zamka 1 | "Plugin0 `LoggerService` ne filtrira" | filtrira po Log level-u; na default-u Info DEBUG ne stiže u `ps_log` |
| 9, zamka 3 | `StoreContext` ručno samo u `disconnectActive()` | i u `PaymentManager::reconcileLog()` |

### 34.4 Kako je provereno usklađivanje

Posle izmena: oba fajla su LF, bez whitespace grešaka (`git diff --check`), svaka tabela ima isti broj kolona u
svim redovima, a jedini neescape-ovan `|` u code span-u tabele (`CORE.md`, poglavlje 8,
`OrderAPI::capture|refund|cancel`) je sada napisan kao `\|`, da PhpStorm ne prijavi grešku pri commit-u.

---

# Deo VII. Za kraj

## 35. Rečnik pojmova

| Pojam | Običnim rečima | Tačno, u ovom projektu |
|---|---|---|
| **3-D Secure (3DS)** | dodatna provera banke kupca (lozinka, aplikacija) | Worldline je radi na svojoj strani; Core šalje podešavanja u zahtevu |
| **Adapter** | klasa koja "prevodi" jedan svet u drugi | klase u `/home/veljkozbiljic/logeecom/plugin0/src/Adapter/` (demo interfejs → Core/PrestaShop) i u `/home/veljkozbiljic/logeecom/plugin0/src/Infrastructure/` (Core interfejs → PrestaShop) |
| **Aspekt** | omotač oko poziva koji uradi nešto pre i posle | `ErrorHandlingAspect`, `TenantContextAspect`, `StoreContextAspect` oko svake metode fasade (7.2) |
| **Autorizacija (rezervacija)** | iznos je "zamrznut" na kartici, ali još nije skinut | Worldline status 5; posle toga capture ili cancel |
| **Boot** | slaganje Core-a pre upotrebe | `Plugin0\Bootstrap::boot()` (5) |
| **Bind mount** | isti folder vidljiv i u WSL-u i u kontejneru | `/home/veljkozbiljic/logeecom/plugin0` = `/var/www/html/public/prestashop/8-2-4/modules/plugin0` |
| **Cancel** | poništavanje rezervacije; novac se ne skida | `OrderAPI::cancel()`; Worldline status 6 |
| **Capture** | stvarno skidanje rezervisanog novca | `OrderAPI::capture()`; Worldline status 9 (prvo često 91 `CAPTURE_REQUESTED`) |
| **CartProvider** | "daj mi korpu u Core obliku" | `PrestaShopCartProvider` (17.3) |
| **Core** | Logeecom-ova biblioteka sa Worldline logikom | `wl-online-payments-direct/plugin-core-base` 1.0.7; ne menja se |
| **Direct sale** | naplata odmah, bez rezervacije | Worldline status 9 odmah posle plaćanja; Payment action SALE |
| **Entitet** | jedan zapis koji Core zna da sačuva | klasa koja nasleđuje Core `Entity`; jedan red u `ps_plugin0_entity` |
| **Fasada** | "šalter" Core-a | `AdminAPI`, `CheckoutAPI`, `OrderAPI` (7.1) |
| **FINAL_AUTHORIZATION** | rezerviši; naplata mora biti cela | Core `PaymentAction::AUTHORIZE` |
| **Front controller** | stranica modula u prodavnici za kupce | `/home/veljkozbiljic/logeecom/plugin0/controllers/front/payment.php`, `/home/veljkozbiljic/logeecom/plugin0/controllers/front/return.php` |
| **Hook** | "utičnica" u PrestaShop-u na koju se modul prijavi | `actionDispatcherBefore`, `paymentOptions`, `displayAdminOrderMain` |
| **Hosted Checkout** | Worldline-ova strana za plaćanje na koju se kupac preusmeri | `POST /hostedcheckouts`; tip metode Redirect |
| **Hosted Tokenization** | Worldline polje za karticu ugrađeno u stranu prodavnice | tip metode iFrame; nije povezan |
| **hostedCheckoutId** | broj sesije za plaćanje kod Worldline-a | postaje payment ID sa `_0` |
| **Idempotentno** | isti zahtev dvaput = isti rezultat kao jednom | `ShopOrderService` (19.3) |
| **Indeks kolona** | kolona po kojoj se pretražuje | `index_1` ... `index_9` u `ps_plugin0_entity` |
| **`_legacy_controller`** | koja admin "kartica" važi za prava | `AdminPlugin0Configuration` ili `AdminOrders` u `/home/veljkozbiljic/logeecom/plugin0/config/routes.yml` |
| **merchantReference** | trgovčeva oznaka plaćanja | ID PrestaShop korpe |
| **Merchant Portal** | Worldline-ov admin za trgovca | test: `https://merchant-portal.preprod.direct.worldline-solutions.com` |
| **Najmanje jedinice (minor units)** | iznos u centima, kao ceo broj | Core `Amount`; `MinorUnits` (20.5) |
| **MonitoringLog** | snimak zahteva i odgovora ka Worldline-u | redovi u `ps_plugin0_entity` dok je `debugMode` uključen (8.4) |
| **Payment ID** | broj plaćanja kod Worldline-a | `10300628270_0` = plaćanje; `_1`, `_2`... = operacije (naplate, povraćaji) |
| **PRE_AUTHORIZATION** | rezerviši; dozvoljene delimične naplate | plugin ključ `cardAuthorizationMode`, upisuje ga `HostedCheckoutProxy` (14.5, 21.1) |
| **Proxy (Core)** | klasa koja priča sa Worldline-om preko SDK-a | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/ApiFacades/PaymentProcessor/Proxies/` (i `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/ApiFacades/Order/Proxies/`, `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/ApiFacades/AdminConfig/Proxies/`); dve plugin0 zamene (21) |
| **PSPID** | ID trgovačkog naloga kod Worldline-a | `logeecom35` u sandbox-u |
| **API Key / API Secret** | "korisničko ime i lozinka" za API pozive | HMAC potpis svakog zahteva (8.2) |
| **Webhook Key / Secret** | ključ za proveru Worldline poruka prodavnici | čuvaju se, ne koriste se (nema webhook-a) |
| **Refund** | povraćaj naplaćenog novca | `OrderAPI::refund()`; Worldline status 8 (prvo često 81 `REFUND_REQUESTED`) |
| **RETURNMAC** | tajni potpis sesije koji Worldline vrati u adresi | uparuje se sa payment ID-jem pri traženju transakcije (18.3) |
| **Repozitorijum** | klasa koja čita i piše zapise | `BaseRepository` (Core ORM), `PaymentMethodRepository` (demo) |
| **SALE** | naplati odmah | Core `PaymentAction::AUTHORIZE_CAPTURE`, default |
| **Sandbox / preprod / test** | Worldline okruženje za probe, bez pravog novca | `payment.preprod.direct.worldline-solutions.com` |
| **ServiceRegister** | Core-ov spisak "za ovaj posao zovi ovu klasu" | 5.3 |
| **SingleInstance** | "napravi jednom, pa pamti" | omotač u `ServiceRegister`-u |
| **StatusCode** | Worldline broj stanja plaćanja | Core `StatusCode`, grupe u 19.4 |
| **StoreContext / storeId** | za koju prodavnicu Core radi | ID PrestaShop prodavnice kao tekst (`'1'`) |
| **`_token`** | potpis admin adrese protiv lažnih zahteva | PrestaShop CSRF zaštita (4.4) |
| **Transformer (Core)** | prevodi Core objekat u Worldline zahtev i nazad | `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/Bootstrap/ApiFacades/PaymentProcessor/Proxies/Transformers/` |
| **Webhook** | Worldline sam javi prodavnici da se nešto promenilo | ne postoji u pluginu (32) |
| **Worldline proizvod (product ID)** | način plaćanja kod Worldline-a | `1` Visa, `3` Mastercard, `302` Apple Pay... (`PaymentProductId`) |

---

## 36. Stanje projekta, zaključak i sledeći korak

### 36.1 Stanje

| Deo | Stanje | Kako je provereno | Šta nije provereno |
|---|---|---|---|
| Core boot, `Configuration`, `core-info` (MIDU-1215) | gotovo, jedino je ovo u `main`-u | kod; Core se podiže na svakom zahtevu (svi tokovi rade) | `core-info` u browseru danas |
| `BaseRepository`, logger, Logs stranica (MIDU-1218) | gotovo | kod, baza (1034 `WORLDLINE` zapisa) | — |
| Configuration stranica | gotovo; prodavnica povezana (Test) | baza: `ConnectionConfig` red za prodavnicu 1 | Live okruženje nikad |
| Payment methods (demo) | gotovo; 2 metode u bazi | 52 demo testa, baza, probna skripta (31.4) | — |
| Settings (demo + Payment action) | gotovo | 23 demo testa + 9 testova `PaymentActionSetting`, baza | rokovi 7/30 dana u Worldline dokumentaciji |
| Checkout Hosted Checkout (MIDU-1221) | gotovo, samo tip Redirect | browser 2026-10-15 (**iz beleški**), baza, Apache log | plaćanje koje ostane "u obradi" duže od čekanja |
| Panel na narudžbini (MIDU-1224) | gotovo, commit `06a7b91` | 52 testa `Order`, baza (narudžbina 16), browser (**iz beleški**) | — |
| `HostedCheckoutProxy`, `PaymentsProxy` | gotovo | 17 + 9 testova; baza (narudžbina 16) | ponašanje na Live okruženju |
| Unit testovi | 195 pokretanja, 525 provera, svi prolaze | pokrenuti 2026-10-19 | — |
| LF krajevi linija | svi praćeni fajlovi LF | `git ls-files --eol` 2026-10-16 | — |
| `/home/veljkozbiljic/logeecom/plugin0/lib/Logeecom` = demo `/home/veljkozbiljic/laravel-docker/src` | identično | `diff -rq` 2026-10-16 | — |
| Core i SDK netaknuti | po beleškama identični originalu (2026-10-15) | `diff -rq` sa zip-om commit-a (**iz beleški**) | danas nije ponovljeno (traži internet) |
| Webhook (tiket MIDU-1227) | kod gotov 2026-10-19, nije commit-ovan; adresa radi kroz ngrok | 26 unit testova; curl sa pravim HMAC-om (potpis, duplikat, zaključavanje, čišćenje), `docs/WEBHOOK.md` 7 | prijava adrese u Merchant Portal-u i prva prava Worldline isporuka; narudžbina iz webhook-a za svežu korpu (korpa 18 ima polu-napravljenu narudžbinu 17 iz probe pre popravke) |
| Red zadataka i `asyncprocess` | ne postoje | — | — |
| iFrame (Hosted Tokenization) | ne postoji | — | — |
| Core debug mode | **uključen** u dev prodavnici; 287 `MonitoringLog` redova; nema automatskog brisanja | baza 2026-10-16 | — |
| Grane | `main` ima samo MIDU-1215; 1218, `settings-and-payment_pages`, 1221 i 1224 su naslagane i nespojene | `git` 2026-10-16 | — |
| `/home/veljkozbiljic/logeecom/plugin0/docs/VODIC.md` | nov fajl, nije u git-u, nije commit-ovan | — | — |
| poruke posle radnje (flash) sa imenom metode plaćanja | ime ide u poruku bez escape-ovanja, a PrestaShop je prikazuje kao HTML (11.1) | čitanje koda 2026-10-16 | u browseru nije probano |
| zapisi u logu za Configuration i Payment action | ne postoje: povezivanje, odvezivanje i promena Payment action-a ne ostavljaju INFO zapis (12.9, 14.8) | čitanje koda 2026-10-16 | — |
| `/home/veljkozbiljic/logeecom/plugin0/docs/OKRUZENJE.md`, `/home/veljkozbiljic/logeecom/plugin0/docs/CORE.md` | usklađeni sa kodom 2026-10-16, nisu commit-ovani | poglavlje 34 | šest zastarelih komentara u kodu (34.1) nije menjano |

### 36.2 Zaključak

plugin0 je PrestaShop modul koji Worldline plaćanje radi preko Logeecom Core-a: PrestaShop zove plugin, plugin
zove Core, Core zove Worldline, a sve što Core treba od prodavnice dobija preko klasa plugina iz tabele 6.2. Metode plaćanja i podešavanja su demo projekat preslikan bez izmene, a čuvaju se kroz Core u jednoj
tabeli plugina. Narudžbina nastaje tek posle potvrđenog plaćanja i njen status menja samo `ShopOrderService`.
Dve Worldline/Core nezgode (protivrečni režimi autorizacije i čitanje samo rezervacije `_0`) su zaobiđene u
pluginu, bez diranja Core-a. Webhook je dodat 2026-10-19 (`docs/WEBHOOK.md`); najveća preostala praznina je red
zadataka (pozadinska provera statusa i automatski capture).

### 36.3 Sledeći korak

**Konkretno:** otvori ovaj dokument pored prodavnice i prođi poglavlje 30 (provere u browseru) redom, sa
poglavljima 17-20 kao objašnjenjem onoga što vidiš; posle svakog koraka pogledaj stranicu Logs (29.3). Tako ćeš
svaku klasu iz dela III videti kako radi.

Posle toga, po tvom izboru:

1. Isključi Core debug mode kad ti `MonitoringLog` ne treba. Plugin nema stranicu za to; ide skriptom (šablon u
   31.4) sa `\WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI::get()->generalSettings('1')->saveLogSettings(new \WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\GeneralSettingsAPI\Request\LogSettingsRequest(false, 14))`
   (Core metoda `saveLogSettings`, `/home/veljkozbiljic/logeecom/plugin0/vendor/wl-online-payments-direct/plugin-core-base/src/BusinessLogic/AdminConfig/ApiFacades/GeneralSettingsAPI/Controller/GeneralSettingsController.php:69`;
   ovaj poziv danas nisam pokretao, **neprovereno**).
2. Ispravi šest zastarelih komentara u kodu (34.1); dokumenti su već usklađeni (34.2-34.3).
3. Webhook: prijavi adresu u Merchant Portal-u i prođi `docs/WEBHOOK.md`, sekciju 7; posle toga odluči o
   (popravka za `returnmac` je urađena, zamka 33.1/23).
