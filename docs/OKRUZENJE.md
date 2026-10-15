# Razvojno okruzenje plugin0: cela slika

Ovaj dokument opisuje kako radi okruzenje u kome se razvija i pokrece plugin0, od paljenja Docker Desktop-a do
reda u bazi koji upise tvoj kod. Core biblioteka (`wl-online-payments-direct/plugin-core-base` 1.0.7) je opisana
posebno, u [CORE.md](CORE.md). Ovde se pominje samo onoliko koliko treba da se vidi gde se uklapa.

**Stanje je proveravano 2026-10-13** u fajlovima, u kontejneru `logeecom_dev` i u bazi. Sve sto nije provereno
oznaceno je kao **neprovereno**. Podrazumevane vrednosti alata oznacene su kao **default**. Poredjenja koja sluze
samo za lakse razumevanje oznacena su kao **poredjenje**.

---

## Sadrzaj

1. [Velika slika](#1-velika-slika)
2. [Osnovni pojmovi](#2-osnovni-pojmovi)
3. [Fajlovi i folderi okruzenja](#3-fajlovi-i-folderi-okruzenja)
4. [Pokretanje i gasenje, korak po korak](#4-pokretanje-i-gasenje-korak-po-korak)
5. [Sta radi `docker compose up -d app`](#5-sta-radi-docker-compose-up--d-app)
6. [Dockerfile, red po red](#6-dockerfile-red-po-red)
7. [Sta se desava u kontejneru pri startu: `/entrypoint.sh`](#7-sta-se-desava-u-kontejneru-pri-startu-entrypointsh)
8. [Stanje kontejnera posle starta](#8-stanje-kontejnera-posle-starta)
9. [Ostali kontejneri iz compose fajla](#9-ostali-kontejneri-iz-compose-fajla)
10. [Baza podataka](#10-baza-podataka)
11. [Volume: gde je i kako se gleda](#11-volume-gde-je-i-kako-se-gleda)
12. [Mreza: portovi, ngrok, domen, HTTPS](#12-mreza-portovi-ngrok-domen-https)
13. [Put jednog zahteva do plugin0](#13-put-jednog-zahteva-do-plugin0)
14. [plugin0 iznutra](#14-plugin0-iznutra)
15. [Mejlovi](#15-mejlovi)
16. [Logovi: gde sta pise](#16-logovi-gde-sta-pise)
17. [Rad na kodu](#17-rad-na-kodu)
18. [Sta cuva podatke, a sta ih brise](#18-sta-cuva-podatke-a-sta-ih-brise)
19. [Komande na jednom mestu](#19-komande-na-jednom-mestu)
20. [Sta nije provereno](#20-sta-nije-provereno)

---

## 1. Velika slika

Okruzenje ima cetiri nivoa. Svaki nivo radi unutar prethodnog:

```
Windows
├── Docker Desktop (aplikacija na Windows-u)
│
├── WSL distribucija "Ubuntu"            ← ovde je tvoj kod i ovde kucas komande
│   ├── /home/veljkozbiljic/logeecom/    ← docker-compose.yml, Dockerfile, systems/, plugin0/
│   ├── docker (klijent)                 ← salje zahteve Docker Engine-u
│   ├── ngrok (agent)                    ← tunel sa interneta na localhost:80
│   └── composer, php 8.5.4              ← samo za composer install u plugin0
│
└── WSL distribucija "docker-desktop"    ← ovde radi Docker Engine (dockerd)
    ├── image-i (logeecom-app, elasticsearch, ...)
    ├── volume-i (logeecom_db_volume, ...)
    └── kontejner logeecom_dev           ← jedan kontejner, a u njemu vise programa:
        ├── Apache2        (web server, portovi 80 i 443)
        ├── PHP-FPM 8.2    (izvrsava PHP: PrestaShop i plugin0)
        ├── MySQL 8.0.40   (baza, port 3306)
        ├── sshd, Mailcatcher, cron
        └── /var/lib/mysql ← ovde je montiran volume logeecom_db_volume
```

Najvaznije cinjenice:

- **Jedan kontejner, `logeecom_dev`, sadrzi celu prodavnicu**: web server, PHP i bazu. Baza nije poseban
  kontejner.
- **Kod je u WSL-u**, a kontejner ga vidi preko bind mount-ova (poglavlje 2). Izmena fajla u WSL-u odmah vazi u
  kontejneru.
- **Podaci baze su u volume-u**, ne u kontejneru. Kontejner moze da se obrise i ponovo napravi bez gubitka
  podataka.
- **Prodavnica se otvara samo preko ngrok domena** `https://8-2-4.prestashop.veljko.ngrok.app` (poglavlje 12).

---

## 2. Osnovni pojmovi

**Program i proces.** Program je fajl na disku, na primer `/usr/sbin/mysqld`. Proces je program koji se upravo
izvrsava. Svaki proces ima broj **PID**. Proces koji je pokrenuo drugi proces je njegov **roditelj** (PPID).

**Daemon, ili Linux servis.** Proces koji radi u pozadini, bez terminala, i ceka zahteve. MySQL ceka SQL upite,
Apache ceka HTTP zahteve. Daemon se obicno pokrece ovako:
1. Pokretac napravi kopiju sebe u pozadini.
2. Pokretac se zavrsi, a kopija nastavlja da radi.
3. Kopija ostaje bez roditelja, pa je preuzima proces sa PID 1.

Na Ubuntu-u se servis pokrece komandom `service <ime> start`.

**Dva znacenja reci „servis“.** Ovo je cest izvor zabune:
- **Compose servis** je jedna stavka pod `services:` u `docker-compose.yml`. Ona opisuje **jedan kontejner**.
  `app` je compose servis i od njega nastaje kontejner `logeecom_dev`.
- **Linux servis** je daemon unutar kontejnera ili racunara, na primer MySQL server.

Compose servis `app` je dakle jedan kontejner, a u njemu radi sest Linux servisa.

**Port.** Broj od 0 do 65535 na kome program slusa mrezne konekcije. Apache koristi 80 (HTTP) i 443 (HTTPS),
MySQL koristi 3306.

**Unix socket.** Ima istu ulogu kao port, ali je u obliku fajla i radi samo izmedju procesa na istoj masini.
Primer: `/run/php/php8.2-fpm.sock`.

**Image.** Zapakovan fajl sistem koji se ne menja (Ubuntu 22.04 sa instaliranim programima i konfiguracijom),
plus podaci o tome koja se komanda pokrece i koje env varijable vaze. Image se pravi po receptu koji se zove
**Dockerfile**. Image moze da se gradi na drugom image-u (`FROM ...`).

**Kontejner.** Pokrenut image.
- Linux kernel ga izoluje mehanizmom *namespaces*: kontejner vidi samo svoje procese, svoju mrezu i svoj fajl
  sistem.
- Docker dodaje sloj za pisanje iznad image-a. Sve sto se upise van volume-a i bind mount-ova nestaje kad se
  kontejner obrise.
- Kontejner **nije virtuelna masina**. Nema svoj kernel i nema `systemd`, program koji na obicnom Linuxu pri
  paljenju pokrece servise. Zato dockware servise pokrece sopstvenom skriptom (poglavlje 7).

**PID 1.** Prvi proces u kontejneru. **Kontejner radi tacno onoliko dugo koliko radi PID 1.**

**Env varijabla.** Par `IME=vrednost` koji proces nasledjuje od roditelja, na primer `PHP_VERSION=8.2`. Skripte
ih citaju da bi odlucile sta da rade.

**Volume.** Folder kojim upravlja Docker Engine, na primer `logeecom_db_volume`. Montira se u kontejner na
neku putanju. Postoji nezavisno od kontejnera.

**Bind mount.** Folder iz WSL-a koji se vidi unutar kontejnera, na primer `./plugin0` →
`/var/www/html/public/prestashop/8-2-4/modules/plugin0`. Isti fajlovi, dve putanje.

**Docker Engine (`dockerd`).** Server koji zaista pravi image-e, kontejnere, volume-e i mreze. Komanda `docker`
u terminalu je samo **klijent** koji Engine-u salje zahteve.

**Docker Compose.** Deo `docker` klijenta (`docker compose ...`) koji cita `docker-compose.yml` i na osnovu
njega salje Engine-u zahteve za vise kontejnera odjednom.

**WSL distribucija.** Linux sistem koji radi na Windows-u kroz WSL2. Kod tebe rade dve: `Ubuntu` i
`docker-desktop`. Vidi se komandom `wsl.exe -l -v`.

**VirtualHost (Apache).** Blok konfiguracije koji kaze Apache-u kako da odgovara na zahteve: iz kog foldera
da sluzi fajlove i kome da preda `.php` fajlove.

---

## 3. Fajlovi i folderi okruzenja

```
/home/veljkozbiljic/logeecom/
├── docker-compose.yml            ← definise 5 compose servisa (koristi se samo "app")
├── Dockerfile                    ← recept za image logeecom-app
├── systems/                      ← montiran u kontejner kao /var/www/html/public/
│   ├── Dockerfile                ← identican glavnom Dockerfile-u, ne koristi se
│   ├── docker-compose.yml        ← isto kao glavni, ali bez plugin0 mount-a; ne koristi se
│   ├── *:Zone.Identifier         ← oznaka Windows-a (ZoneId=3, Internet) uz ove dve kopije; nema uticaja
│   ├── prestashop/8-2-4/         ← PrestaShop 8.2.4
│   ├── magento/2-4-8/            ← Magento 2.4.8
│   ├── shopware/6-6-10-28/       ← Shopware 6.6.10.28
│   └── wordpress/6-9-1/          ← WordPress 6.9.1
├── integrations/                 ← montiran kao /var/www/workspace/, trenutno prazan
├── prestashop_plugin/            ← poseban git repo (samo README.md); nije montiran u kontejner
└── plugin0/                      ← tvoj modul, git repo; montiran u modules/plugin0
```

**Dve kopije `Dockerfile`-a i `docker-compose.yml`-a.** Po vremenima fajlova (`stat`):

| Fajl | Nastao na disku | Poslednja izmena sadrzaja |
|---|---|---|
| `logeecom/Dockerfile` | 2026-09-30 11:19:37 | 2026-09-30 11:19:37 |
| `logeecom/docker-compose.yml` | 2026-09-30 11:19:37 | 2026-10-06 10:49:55 (dodat plugin0 mount) |
| `logeecom/systems/Dockerfile` | 2026-10-02 10:02:08 | 2026-09-30 11:13:51 |
| `logeecom/systems/docker-compose.yml` | 2026-10-02 10:02:08 | 2026-09-30 11:13:56 |

Kopije u `systems/` su na disk stigle 2026-10-02, kopiranjem sa Windows-a. Fajlovi `:Zone.Identifier` sa
`ZoneId=3` nastaju kad se sa Windows-a u WSL kopira fajl koji je Windows oznacio kao preuzet sa interneta. Koriste
se samo fajlovi iz `logeecom/`; kopije u `systems/` niko ne cita.

Vazni fajlovi PrestaShop-a (putanje u WSL-u, relativno od `systems/prestashop/8-2-4/`):

| Fajl | Sta je |
|---|---|
| `index.php` | ulaz za front office (prodavnicu za kupce) |
| `admin174kob088ranecckhxg/index.php` | ulaz za admin panel; ime foldera je tajno, PrestaShop ga nasumicno pravi pri instalaciji |
| `app/config/parameters.php` | podaci za bazu (`127.0.0.1`, `prestashop`, `root`/`root`, prefiks `ps_`) |
| `config/defines.inc.php` | `_PS_MODE_DEV_` je `false`, dakle prod rezim |
| `var/cache/prod/` | Symfony kes (rute, servisi, Twig) |
| `var/logs/` | logovi PrestaShop-a (`prod-YYYY-MM-DD.log`, `dev-...`) |
| `modules/plugin0/` | bind mount na `/home/veljkozbiljic/logeecom/plugin0` |

---

## 4. Pokretanje i gasenje, korak po korak

### 4.1 Pokretanje

**Korak 1: Windows.** Pokreni **Docker Desktop** i sacekaj da javi da Engine radi. Bez toga komanda `docker`
u Ubuntu-u nema s kim da prica.

**Korak 2: WSL terminal br. 1.** Pokreni kontejner:

```bash
cd /home/veljkozbiljic/logeecom
```

```bash
docker compose up -d app
```

Bitno je da se navede `app`. `docker compose up -d` bez imena pokrece svih pet compose servisa (poglavlje 9).

Sta se tacno desava opisano je u poglavljima 5 i 7. Kontejner je spreman kad
`docker logs logeecom_dev` ispise `WOHOOO, dockware/essentials:latest IS READY`.

**Korak 3: WSL terminal br. 2.** Pokreni ngrok tunel. Ovaj terminal ostaje otvoren dok radis:

```bash
ngrok http 80 --url=https://8-2-4.prestashop.veljko.ngrok.app
```

Zasto je tunel obavezan objasnjeno je u poglavlju 12.

**Korak 4: samo posle novog `git clone`.** Instaliraj zavisnosti, jer je `vendor/` u `.gitignore`:

```bash
cd /home/veljkozbiljic/logeecom/plugin0
```

```bash
composer install
```

Composer pokreces **u WSL-u** (`/usr/local/bin/composer`, PHP 8.5.4). U kontejneru nema composera. Komanda
instalira pakete iz `composer.lock`:
- `wl-online-payments-direct/plugin-core-base` 1.0.7
- `wl-online-payments-direct/sdk-php` 6.2.0
- `robtimus/multipart` 1.0.1

Pravi i `vendor/autoload.php` sa mapiranjima `Plugin0\` → `src/` i `Logeecom\` → `lib/Logeecom/` (demo kod), plus
`Tests\` → `tests/` za unit testove. Dev paketi (`phpunit/phpunit` 11, `psr/log` 1.1) instaliraju se composer-om iz
kontejnera (`docker exec -u 1000:1000 -w .../modules/plugin0 logeecom_dev composer install`), da `vendor/` ostane u
vlasnistvu korisnika iz WSL-a. `composer.json` ima `"config": {"prepend-autoloader": false}` (kao Packlink modul):
autoloader modula se registruje *posle* PrestaShop-ovog, pa klasa koju imaju oba (na primer `PhpParser\ParserFactory`,
koju phpunit vuce u verziji 5, a PrestaShop ima verziju 4 i zove je u Module Manager-u) dolazi iz PrestaShop vendora.
Bez toga Module Manager puca sa "undefined method create of class PhpParser\ParserFactory".

**Korak 5: browser.**
1. Otvori `https://8-2-4.prestashop.veljko.ngrok.app/admin174kob088ranecckhxg/`.
2. Uloguj se kao zaposleni (jedini nalog je `test1@gmail.com`).
3. Idi na **Modules → Module Manager → „Plugin0 Worldline Payments“ → Configure**.
   - Modul je instaliran i aktivan (`ps_module`: `active = 1`, `version = 1.0.0`).
   - Nema stavku u levom meniju PrestaShop admina (u `ps_tab` nema redova za `plugin0`).
   - **Configure** poziva `Plugin0::getContent()`, koji preusmerava na Symfony rutu `plugin0_configuration`.
4. Ostale stranice otvaras iz bocnog menija plugin0: Configuration, Payment methods, Settings, Logs.

Front office (prodavnica za kupce) je na `https://8-2-4.prestashop.veljko.ngrok.app/`.

### 4.2 Gasenje

1. U terminalu br. 2 pritisni `Ctrl+C` da ugasis ngrok.
2. U `/home/veljkozbiljic/logeecom` pokreni:

```bash
docker compose stop app
```

Ne koristi `docker compose down` ako nije potrebno: ona brise kontejner (podaci baze ostaju u volume-u, ali se
kontejner pravi ponovo). `docker compose down -v` brise i volume, a time **sve baze** (poglavlje 18).

### 4.3 Kontejner se ne pali sam

Restart policy kontejnera je `no` (`docker inspect` → `RestartPolicy.Name`). Kad ugasis racunar ili Docker
Desktop, kontejner ostaje ugasen dok ga ne pokrenes korakom 2. Ako se Docker Desktop ili WSL ugasi dok kontejner
radi, kontejner obicno dobije stanje `Exited (255)`; za tvoj slucaj to je **neprovereno**.

Da bi se palio sam, pod `app:` u `docker-compose.yml` bi trebalo dodati `restart: unless-stopped`. Trenutno to
nije podeseno.

---

## 5. Sta radi `docker compose up -d app`

Komanda se kuca u WSL Ubuntu terminalu, u `/home/veljkozbiljic/logeecom`.

1. **Klijent cita compose fajl.** `docker` (koji u Ubuntu dolazi preko Docker Desktop WSL integracije) cita
   `docker-compose.yml` iz trenutnog foldera. **Ime projekta** je ime foldera, `logeecom`. Od njega nastaju
   imena `logeecom_web` (mreza) i `logeecom-app` (image).
2. **Klijent se javlja Engine-u** preko `unix:///var/run/docker.sock` (docker context `default`). Engine radi u
   distribuciji `docker-desktop`.
3. **Mreza.** Ako ne postoji, Engine pravi bridge mrezu `logeecom_web` (iz `networks: web`). Kontejner u njoj
   dobija IP adresu, trenutno `172.20.0.2`. U mrezi radi Docker-ov DNS (`127.0.0.11` iz ugla kontejnera), koji
   prevodi imena compose servisa u IP adrese (zato Magento nalazi OpenSearch pod imenom `opensearch`).
4. **Volume-i.** Engine pravi imenovane volume-e sa dna fajla ako ne postoje: `logeecom_db_volume`,
   `logeecom_elastic_volume`, `logeecom_opensearch_volume`. Svi postoje od 2026-09-30.
5. **Image.** `build: .` kaze da se image pravi, a ne preuzima:
   - `.` je *build context*, relativno od foldera compose fajla: `/home/veljkozbiljic/logeecom`.
   - Fajl `Dockerfile` u tom folderu je **default** compose-a. Drugi fajl bi se izabrao kljucem `dockerfile:`.
   - Ime image-a je **default**: `<projekat>-<servis>` = `logeecom-app`.
   - Ako image vec postoji, compose ga **ne gradi ponovo**, cak ni kad se Dockerfile promeni. Za ponovni build
     treba `docker compose up -d --build app`.
   - Image `logeecom-app` je napravljen 2026-09-30 10:06:44 UTC i ima 6,15 GB.
6. **Kontejner.**
   - Ako `logeecom_dev` postoji sa istom konfiguracijom, samo se pokrece. Compose to proverava preko hash-a u
     labeli `com.docker.compose.config-hash`.
   - Ako se compose konfiguracija promenila, stari kontejner se brise i pravi novi. `logeecom_dev` je poslednji
     put napravljen 2026-10-06 08:50 UTC.
   - Pri pravljenju se primenjuju `ports`, `volumes`, `networks` i `environment` iz compose fajla (poglavlja
     10–12).
7. **Env varijable.** Vrednosti iz compose fajla prepisuju podrazumevane iz image-a:

   | Varijabla | Iz compose fajla | Podrazumevano u image-u |
   |---|---|---|
   | `XDEBUG_ENABLED` | `1` | `0` |
   | `XDEBUG_REMOTE_HOST` | `192.168.1.28` | `host.docker.internal` |
   | `PHP_VERSION` | `8.2` | `8.2` (ista vrednost) |

   Iz image-a dolaze i: `TZ=Europe/Berlin`, `APACHE_DOCROOT=/var/www/html/public`, `RECOVERY_MODE=0`,
   `SSH_USER=not-set`, `MYSQL_USER=not-set`, `NODE_VERSION=18`, `FILEBEAT_ENABLED=0`, `TIDEWAYS_KEY=not-set`,
   `COMPOSER_VERSION=not-set`, `SHOP_DOMAIN=localhost`, `BASH_ENV=/var/www/.bashrc`.
8. **Start.** Engine, preko svojih internih delova `containerd` i `runc`, pravi izolovano okruzenje i u njemu
   pokrece **`/bin/bash /entrypoint.sh` kao PID 1**, kao korisnik `root`, u folderu `/var/www/html`. To su
   `Entrypoint`, `User` i `WorkingDir` iz image-a.
9. **`-d` (detached).** Terminal ti se odmah vraca. Ispis skripte ide u log kontejnera, koji citas sa
   `docker logs logeecom_dev`.

Nista drugo iz compose fajla se ne pokrece, jer `app` nema `depends_on`.

---

## 6. Dockerfile, red po red

Fajl `/home/veljkozbiljic/logeecom/Dockerfile` se izvrsava **samo pri build-u image-a**, ne pri svakom startu
kontejnera. Tvoj Dockerfile ne pominje MySQL, Apache instalaciju ni PHP instalaciju. Sve to dolazi iz osnovnog
image-a.

| Red | Sta radi |
|---|---|
| `FROM dockware/essentials:latest` | Osnova je dockware image (dasistweb GmbH, verzija 1.7.0, Ubuntu 22.04, build 2024-11-12). U njemu vec postoje Apache2, PHP-FPM 5.6–8.3, MySQL 8.0.40, Mailcatcher, sshd, cron, nvm/Node 18, `/entrypoint.sh` i `/var/www/makefile`. Tvoj image nasledjuje sve to. |
| `USER root` | Procesi u kontejneru se pokrecu kao `root`. |
| `RUN echo "IncludeOptional /var/www/html/public/*/*/*.conf" >> /etc/apache2/apache2.conf` | Apache ucitava i `.conf` fajlove iz foldera prodavnica, ako postoje. Trenutno ne postoji nijedan. |
| `RUN a2enmod vhost_alias` | Ukljucuje Apache modul koji omogucava `VirtualDocumentRoot`. |
| `RUN echo "\nxdebug.start_with_request = yes" >> ...` za 8.1, 8.0, 7.4, 7.3, 7.2 | Xdebug se pokrece na svaki zahtev, ali **samo za te verzije PHP-a. Na 8.2 ne utice.** |
| `sed ... xdebug.remote_autostart` za 7.1, 7.0, 5.6 | Isto, za Xdebug 2 na starim verzijama PHP-a. |
| `RUN apt-get update`, `apt-get install php5.6-mcrypt`, `php*-sqlite3` | Dodatne PHP ekstenzije za starije verzije. |
| `wget ... wkhtmltox-0.12.4 ...`, `tar ... -C /usr/local/bin` | Instalira `wkhtmltopdf` (pravljenje PDF-a od HTML-a). |
| `rm -rf /var/lib/apt/lists/* /var/cache/apt/*` | Brise apt kes da image bude manji. |
| `RUN echo '<VirtualHost *:80> ... <VirtualHost *:443> ...' > /etc/apache2/sites-enabled/000-default.conf` | **Prepisuje** Apache vhost dockware-a svojim (poglavlje 12.3). Vhost ima placeholder `php__dockware_php_version__-fpm.sock`, koji se zameni pri startu kontejnera (poglavlje 7, linija 139). |

### Sta je u dockware osnovnom image-u

Dockerfile dockware-a nemas na disku, ali se njegovi koraci vide u istoriji image-a:

```bash
docker image history logeecom-app --no-trunc
```

Koraci vazni za ovo okruzenje:
- `ADD entrypoint.sh /entrypoint.sh` i `ENTRYPOINT ["/bin/bash", "/entrypoint.sh"]`: skripta koja pri startu
  pokrece sve servise.
- `COPY ./assets/scripts/makefile /var/www/makefile`: recept za promenu PHP verzije.
- `COPY ./assets/scripts/bin /var/www/scripts/bin`: skripte `xdebug_enable.sh`, `xdebug_disable.sh`, ...
- `ADD ./config/mysql/my8.cnf /etc/mysql/my.cnf`: konfiguracija MySQL-a.
- `RUN ... debconf mysql-server/root_password password root ... apt-get install -y -q mysql-server-8.0 ...`:
  instalacija MySQL-a sa root lozinkom `root` (poglavlje 10.3).
- `COPY ./config/php/xdebug-3.ini /etc/php/8.2/fpm/conf.d/20-xdebug.ini`: Xdebug konfiguracija.
- `COPY ./config/ssmtp/ssmtp.conf /etc/ssmtp/ssmtp.conf`: slanje mejlova u Mailcatcher.

---

## 7. Sta se desava u kontejneru pri startu: `/entrypoint.sh`

`/entrypoint.sh` ima 289 linija i radi kao PID 1. Tvoj ispis starta citas sa `docker logs logeecom_dev`.

### 7.1 Kako `service X start` radi u kontejneru

Ovo vazi za skoro sve korake ispod:

1. Kontejner nema `systemd`: PID 1 je `bash`, a `/run/systemd/system` ne postoji.
2. Zato `/usr/sbin/service` (shell skripta) pokrece skriptu `/etc/init.d/X start`.
3. Init skripta pokrene pravi program u pozadini i zavrsi se.
4. Program ostaje bez roditelja, pa ga preuzima PID 1. Zato u `ps` svi servisi imaju PPID 1.

`sudo` ispred komandi ovde nista ne menja, jer skripta vec radi kao `root`. Tu je za slucaj da dockware radi
kao korisnik `dockware`.

### 7.2 Skripta, redom

**Linije 1–23: banner.** Logo i podaci o verziji: `Version: 1.7.0`, `Built: Tue Nov 12 15:55:32 UTC 2024`.

**Linija 25: `set -e`.** Ako bilo koja sledeca komanda ne uspe, skripta se prekida. Tada se zavrsava PID 1, a
time i ceo kontejner. Zato pojedine komande imaju `|| true`.

**Linije 27–34: okruzenje skripte.**
- `source /etc/apache2/envvars`: ucitava `APACHE_RUN_USER=www-data`, `APACHE_RUN_GROUP=www-data`,
  `APACHE_PID_FILE=/var/run/apache2/apache2.pid`, `APACHE_LOG_DIR=/var/log/apache2`. Usput radi `unset HOME`.
- `source /var/www/.bashrc`: taj fajl ima jednu liniju, `source /var/www/.nvm/nvm.sh`, koja ucitava **nvm**
  (Node Version Manager).
- `export BASH_ENV=/var/www/.bashrc`: svaki neinteraktivni `bash` posle ovoga ucitava `.bashrc`. Zato i
  `docker exec logeecom_dev bash -c ...` ima nvm.
- `CONTAINER_STARTUP_DIR=$(pwd)`: pamti `/var/www/html`.

**Linija 38: `if [ $RECOVERY_MODE = 0 ]`.** Vrednost je `0`, pa se izvrsava sve do linije 261. Sa `1` nista
se ne bi pokrenulo.

**Linije 42–45: `/var/www/boot_start.sh`.** Mesto za sopstvenu skriptu pri startu. Fajl ne postoji, pa se
preskace.

**Linije 47–49: vremenska zona.** `ln -sf /usr/share/zoneinfo/Europe/Berlin /etc/localtime` i
`dpkg-reconfigure tzdata`. Sat kontejnera pokazuje berlinsko vreme.

**Linije 53–57: Filebeat.** `FILEBEAT_ENABLED=0`, preskace se.

**Linije 60–78: dodatni SSH korisnik.** `SSH_USER=not-set`, preskace se.

**Linija 82: SSH server.** `service ssh restart` pokrece `/etc/init.d/ssh`, a ta skripta pokrece
**`/usr/sbin/sshd`**, server za udaljeno logovanje komandom `ssh`.
- Slusa na portu 22 u kontejneru, odnosno 2222 na hostu.
- `/etc/ssh/sshd_config` dozvoljava samo korisnika `dockware` (`AllowUsers dockware`). Lozinka:
  **neproverena**.
- Za rad ne treba; `docker exec -it logeecom_dev bash` radi isto bez SSH-a.

**Linije 85–95: MySQL.**
1. Brise `/var/run/mysqld/mysqld.sock.lock` ako je ostao od prethodnog rada.
2. `chown -R mysql:mysql /var/lib/mysql /var/run/mysqld`: fajlovi baze (u volume-u) moraju pripadati korisniku
   `mysql`, jer server radi pod tim korisnikom.
3. `service mysql start` pokrece `/etc/init.d/mysql`. Ta skripta u liniji 109 radi
   `su - mysql -s /bin/sh -c "/usr/bin/mysqld_safe > /dev/null 2>&1 &"`.
4. **`mysqld_safe`** je shell skripta „cuvar“: pokrece pravi server i ponovo ga pali ako se srusi.
5. **`/usr/sbin/mysqld`** je MySQL server 8.0.40. Radi sa
   `--datadir=/var/lib/mysql --socket=/var/run/mysqld/mysqld.sock --port=3306`.
6. Init skripta ceka da server proradi tako sto ponavlja `mysqladmin ping` (oko linija 70–86). Za to koristi
   korisnika `debian-sys-maint` iz `/etc/mysql/debian.cnf`.

Detalji baze su u poglavlju 10.

**Linije 97–119: novi MySQL korisnik.** `MYSQL_USER=not-set`, preskace se.

**Linija 123: Mailcatcher.** `mailcatcher --ip=0.0.0.0` pokrece Ruby program
(`/usr/bin/ruby3.0 /usr/local/bin/mailcatcher`) koji glumi mail server: SMTP na portu 1025 prima mejlove, web
na portu 1080 ih prikazuje. Sam se prebacuje u pozadinu. Detalji su u poglavlju 15.

**Linija 127: cron.** `service cron start` pokrece `/etc/init.d/cron`, a ta skripta pokrece
**`/usr/sbin/cron -P`**, daemon koji izvrsava komande po rasporedu.
- Jedini posao je u `/etc/cron.d/php`: u :09 i :39 svakog sata `root` pokrece `/usr/lib/php/sessionclean`, koji
  brise stare PHP session fajlove.
- PrestaShop i plugin0 ovde nemaju cron poslove. Korisnik `www-data` nema crontab.

**Linija 135: placeholder za docroot.** `sed` menja `__dockware_apache_docroot__` u `000-default.conf`. Tvoj
vhost iz Dockerfile-a nema taj placeholder, pa se nista ne menja.

**Linija 139: `cd /var/www && make switch-php version=8.2`.** `make` je program koji izvrsava imenovane
recepte iz fajla, ovde `/var/www/makefile`. Pre recepta racuna dve vrednosti:
- `PHP_VERSION_CURRENT` pita `php` iz komandne linije koja je verzija. Odgovor je bio `8.2`.
- `XDEBUG_STATUS` proverava da li je Xdebug ucitan. Odgovor je bio `1`.

Recept `switch-php` (linije 40–67 makefile-a) radi redom:
1. `service php8.2-fpm start`, pa `service php8.2-fpm stop`, pa ponovo `start`. Init skripta
   `/etc/init.d/php8.2-fpm` preko `start-stop-daemon` pokrece
   `/usr/sbin/php-fpm8.2 --daemonize --fpm-config /etc/php/8.2/fpm/php-fpm.conf`.
2. `export PHP_VERSION=8.2`. Nema efekta: svaka linija recepta radi u svom shell-u, a vrednost je ionako 8.2.
3. `sed` menja `__dockware_php_version__` u `8.2` u `000-default.conf`. **Ovaj korak u tvom vhost-u pravi
   putanju `proxy:unix:/var/run/php/php8.2-fpm.sock`.**
4. Drugi `sed` menja `php8.2-fpm.sock` u `php8.2-fpm.sock`, dakle nista.
5. `service apache2 restart`.
6. `update-alternatives --set php /usr/bin/php8.2`: komanda `php` u terminalu kontejnera od sada je PHP 8.2.
7. Posto je `XDEBUG_STATUS=1`, poziva se `make xdebug-on`, koji pokrece `xdebug_enable.sh`.

**PHP-FPM: sta je i zasto postoji.**
- Apache sam ne izvrsava PHP. **PHP-FPM** (FastCGI Process Manager) je poseban server koji drzi spremne PHP
  procese. Apache mu salje zahtev protokolom FastCGI preko unix socket-a.
- `/var/run` je symlink na `/run`, pa su `/var/run/php/php8.2-fpm.sock` (u Apache-u) i
  `/run/php/php8.2-fpm.sock` (u PHP-FPM-u) isti fajl.
- **Master proces** radi kao `root`, cita `php-fpm.conf` i pravi socket.
- Po `/etc/php/8.2/fpm/pool.d/www.conf` pravi **radne procese** kao `www-data`:
  `listen = /run/php/php8.2-fpm.sock`, `pm = dynamic`, `pm.start_servers = 2`, `pm.min_spare_servers = 1`,
  `pm.max_spare_servers = 3`, `pm.max_children = 5`.
- Zato u `ps` vidis 2 radna procesa. Najvise 5 PHP zahteva moze da se izvrsava istovremeno.
- Log PHP-FPM-a: `/var/log/php8.2-fpm.log` (`error_log` u `php-fpm.conf`).

**Linija 140: `service apache2 stop`.** Gasi Apache koji je `make` upravo restartovao. Ponovo ga pali linija
208.

**Linije 144–157: composer.** `COMPOSER_VERSION=not-set`, preskace se. `export COMPOSER_HOME=/var/www`.

**Linije 160–164: Xdebug.** `XDEBUG_ENABLED=1`, pa se pokrece `/var/www/scripts/bin/xdebug_enable.sh`. To je
drugi put; prvi put ju je pozvao `make`. U logu zato dvaput pise „activating Xdebug...“. Skripta:
1. Preimenuje `20-xdebug.ini_disabled` u `20-xdebug.ini` ako postoji.
2. Upisuje `XDEBUG_REMOTE_HOST` (`192.168.1.28`) umesto `__dockware_host__`.
3. Restartuje PHP-FPM u pozadini.

Rezultat u `/etc/php/8.2/fpm/conf.d/20-xdebug.ini`:
- `zend_extension = "/usr/lib/php/20220829/xdebug_8.2.so"`
- `xdebug.mode = debug`
- `xdebug.client_host = 192.168.1.28`, `xdebug.client_port = 9000`
- `xdebug.idekey = "PHPSTORM"`
- `xdebug.start_with_request` nije postavljen, pa vazi Xdebug **default** `default`. Za `mode = debug` on znaci
  `trigger`: debager se ukljucuje samo kad zahtev nosi `XDEBUG_SESSION` ili `XDEBUG_TRIGGER`.

Da li je `192.168.1.28` i danas IP tvog Windows-a: **neprovereno**. IDE (PhpStorm) mora da slusa na portu 9000.

**Linije 167–174: Tideways.** `TIDEWAYS_KEY=not-set`, preskace se.

**Linije 177–187: Node 18.** `nvm alias default 18`, pa symlink-ovi `/usr/local/bin/node` i
`/usr/local/bin/npm`. PrestaShop i plugin0 ne koriste Node.

**Linije 200–203: ciscenje pre Apache-a.**
- Brise zaostali `/var/run/apache2/apache2.pid`.
- `lsof -t -i tcp:80 | xargs kill || true`: ubija sve sto unutar kontejnera vec drzi port 80. Zahvaljujuci
  `|| true`, greska ovde ne prekida skriptu uprkos `set -e`.

**Linije 207–208: Apache2.**
1. `apache2ctl configtest` proverava sintaksu konfiguracije (u logu: `Syntax OK`). Da ne prodje, `set -e` bi
   ugasio kontejner.
2. `service apache2 restart` pokrece `/etc/init.d/apache2`, koji preko funkcije `do_start` poziva
   `apache2ctl start`.
3. Pokrece se **`/usr/sbin/apache2 -k start`**: master proces kao `root`, radni procesi kao `www-data`.

Apache konfiguraciju cita redom:
1. `/etc/apache2/apache2.conf`
2. `ports.conf`: `Listen 80` i `Listen 443` (443 samo uz `mod_ssl`).
3. `mods-enabled/`. Vazni moduli:
   - `mpm_event`: nacin organizovanja radnih procesa.
   - `proxy`, `proxy_fcgi`: prosledjivanje `.php` zahteva PHP-FPM-u.
   - `vhost_alias`: `VirtualDocumentRoot`.
   - `ssl`: HTTPS sa samopotpisanim `/etc/apache2/ssl/server.crt`.
   - `rewrite`: za `.htaccess` PrestaShop-a (lepi URL-ovi).
   - `http2`, `headers`.
4. `conf-enabled/`: izmedju ostalog `adminer.conf` i `other-vhosts-access-log.conf`.
5. `sites-enabled/000-default.conf`: tvoj vhost.
6. `IncludeOptional /var/www/html/public/*/*/*.conf`: nijedan takav fajl ne postoji.

**Linije 216–223.** `cd /var/www/html`. `/var/www/boot_end.sh` ne postoji, preskace se.

**Linije 231–248: zavrsna poruka.** „WOHOOO ... IS READY“, verzija PHP-a, docroot i URL-ovi. Ispisani URL-ovi
(`http://localhost/adminer.php`, `/mailcatcher`, `/logs`) vaze za originalni dockware vhost. Tvoj Dockerfile ga
je prepisao, pa ti URL-ovi kod tebe najverovatnije ne rade (**neprovereno** u browseru).

**Linija 278: `exec "$@"`.** `"$@"` su argumenti predati kontejneru. Nema ih (`Cmd=null`). Bash-ov `exec` bez
komande ne radi nista, pa skripta nastavlja dalje.

**Linije 282–287: `tail -f /dev/null`.** `DOCKWARE_CI` nije postavljen, pa se izvrsava `tail -f /dev/null`, koji
beskonacno ceka na prazan fajl. **To drzi PID 1 zivim.** Svi servisi su u pozadini, a njihovi pokretaci su se
zavrsili. Da nema ove linije, skripta bi se zavrsila, zavrsio bi se PID 1, i Docker bi ugasio kontejner zajedno
sa svim servisima.

---

## 8. Stanje kontejnera posle starta

### 8.1 Procesi

Iz `docker exec logeecom_dev ps -eo pid,ppid,user,cmd --forest`. PID-ovi su sa starta 2026-10-13 i menjaju se
pri svakom startu.

| PID | Roditelj | Korisnik | Proces |
|---|---|---|---|
| 1 | — | root | `/bin/bash /entrypoint.sh` (ceka u `tail`) |
| 389 | 1 | root | `sshd` |
| 426 | 1 | mysql | `mysqld_safe` |
| 824 | 426 | mysql | `mysqld` |
| 1152 | 1 | root | `mailcatcher` (Ruby) |
| 1159 | 1 | root | `cron -P` |
| 1505 | 1 | root | `php-fpm: master process` |
| 1509, 1511 | 1505 | www-data | `php-fpm: pool www` |
| 1581 | 1 | root | `apache2 -k start` (master) |
| 1583, 1585, 1586 | 1581 | www-data | `apache2 -k start` (radni procesi) |
| 1696 | 1 | root | `tail -f /dev/null` |

### 8.2 Portovi

| U kontejneru | Ko slusa | Na hostu (WSL i Windows) |
|---|---|---|
| 80 | Apache | 80 |
| 443 | Apache | 443 |
| 22 | sshd | 2222 |
| 3306 | mysqld, na `0.0.0.0` | 3333 |
| 33060 | mysqld X Protocol, samo `127.0.0.1` | nije objavljen |
| 1025, 1080 | Mailcatcher | nije objavljen |
| 3390, 8888, 9999, 5173 | **niko** | objavljeni (3336, 8888, 9999, 5173), ali konekcija ne uspeva |

### 8.3 Sta nije u kontejneru

- **Memcached ne radi.** `parameters.php` ima `ps_caching => CacheMemcache`, ali i `ps_cache_enable => false`,
  pa se kes ne koristi.
- **Composer** nije u PATH-u za `root` u kontejneru. Composer se pokrece iz WSL-a.

### 8.4 Mount `/var/run/docker.sock`

Compose montira socket Docker Engine-a u kontejner (`/var/run/docker.sock:/var/run/docker.sock`). Time svaki
proces u kontejneru koji ima pristup tom fajlu dobija punu kontrolu nad Docker Engine-om (moze da pravi i brise
kontejnere). Za plugin0 to ne treba. Za razvojno okruzenje je prihvatljivo, za produkciju ne bi bilo.

---

## 9. Ostali kontejneri iz compose fajla

Svaki compose servis sam odredjuje odakle mu image:

| Compose servis | Kontejner | Kljuc | Odakle je image | Velicina image-a |
|---|---|---|---|---|
| `app` | `logeecom_dev` | `build: .` | **pravi se** iz `/home/veljkozbiljic/logeecom/Dockerfile` | 6,15 GB |
| `elasticsearch` | `logeecom_elastic` | `image: elasticsearch:7.9.3` | preuzima se gotov sa Docker Hub-a | 1,16 GB |
| `opensearch` | `logeecom_opensearch` | `image: opensearchproject/opensearch:2.9.0` | preuzima se gotov | 2,13 GB |
| `chrome` | `logeecom-chrome-1` | `image: selenium/node-chrome:4.17.0-20240123` | preuzima se gotov | 1,79 GB |
| `selenium-hub` | `selenium-hub` | `image: selenium/hub:4.17.0-20240123` | preuzima se gotov | 673 MB |

`build:` vazi samo za servis pod kojim je napisan. Dockerfile pravi samo image `logeecom-app`.

**Zasto ova cetiri kontejnera postoje.** Compose fajl je pisan za vise prodavnica (PrestaShop, Magento,
Shopware, WordPress) i testove, ne samo za plugin0. Kopija `systems/docker-compose.yml` ima `:Zone.Identifier`,
dakle stigla je sa Windows-a kao preuzet fajl (poglavlje 3); verovatno zajednicki sablon, ali odakle je stigao je
**neprovereno**. Sva cetiri
kontejnera su napravljena 2026-09-30 10:06:56 UTC, 12 sekundi posle build-a image-a. To odgovara komandi
`docker compose up -d` bez imena servisa. Poslednji put su radili od 2026-10-06 08:12 do 2026-10-07 05:48.

**Ko ih koristi:**

| Kontejner | Koristi ga | Dokaz |
|---|---|---|
| `logeecom_opensearch` | Magento 2.4.8 | baza `magento`, `core_config_data`: `catalog/search/engine = opensearch`, `opensearch_server_hostname = opensearch`, `opensearch_server_port = 9200` |
| `logeecom_elastic` | niko | Magento koristi OpenSearch, Shopware ima `SHOPWARE_ES_ENABLED=0` |
| `selenium-hub`, `logeecom-chrome-1` | niko u ovom okruzenju | `integrations/` je prazan, nema testova koji ih koriste |

Selenium sluzi za automatske testove u browseru: `selenium-hub` prima komande testova, `chrome` je cvor sa pravim
Chrome-om koji ih izvrsava.

Dok su ugaseni, ne trose CPU ni RAM. Zauzimaju oko 5,8 GB diska (image-i). Elasticsearch i OpenSearch su
podeseni sa po 512 MB Java memorije kad rade.

---

## 10. Baza podataka

### 10.1 Baza je servis u istom kontejneru

MySQL **nije poseban kontejner** i nije definisan u `docker-compose.yml`. To je proces `/usr/sbin/mysqld`
(MySQL 8.0.40, `8.0.40-0ubuntu0.22.04.1`) u kontejneru `logeecom_dev`. Instaliran je u dockware image-u, a
pokrece ga `/entrypoint.sh` (poglavlje 7, linije 85–95).

U compose fajlu postoje samo dva traga MySQL-a:
- `"3333:3306"`: port 3333 hosta vodi na port 3306 kontejnera, na kome slusa `mysqld`. Compose ne zna ko slusa;
  samo objavljuje port.
- `"logeecom_db_volume:/var/lib/mysql"`: volume se montira na folder u kome `mysqld` drzi podatke.

**Poredjenje sa laravel-docker projektom.** U `/home/veljkozbiljic/laravel-docker/compose.yaml` MySQL jeste
poseban compose servis `db:` (`image: mysql:8.4.11`), pa i poseban kontejner `laravel-docker-db-1`. To je
uobicajeni Docker pristup: jedan kontejner, jedan program. Dockware namerno pakuje celo okruzenje u jedan
kontejner. Kontejner `laravel-docker-db-1` nema veze sa plugin0.

### 10.2 Gde se baza nalazi (tri nivoa)

| Nivo | Sta | Gde |
|---|---|---|
| Proces | `mysqld`, radi kao korisnik `mysql` (uid 106) | u kontejneru `logeecom_dev` |
| Folder koji `mysqld` vidi | `/var/lib/mysql/` (382 MB) | u kontejneru |
| Gde su bajtovi stvarno upisani | volume `logeecom_db_volume`, Mountpoint `/var/lib/docker/volumes/logeecom_db_volume/_data` | u Docker Engine-u, na disku distribucije `docker-desktop` |

**Otkud putanja `/var/lib/mysql`.** To je MySQL podesavanje `datadir`:
- Ugradjeno u program: `mysqld --no-defaults --verbose --help` prikazuje `datadir /var/lib/mysql/`. To je
  default Ubuntu build-a MySQL-a.
- U konfiguraciji: `/etc/mysql/mysql.cnf`, linija 20: `datadir = /var/lib/mysql`.
- Po Linux konvenciji (FHS) u `/var/lib` programi drze podatke o svom stanju. To je konvencija, ne tehnicko
  pravilo.

Compose fajl **ne odredjuje** gde MySQL pise. Odredjuje samo sta je ispod te putanje: volume umesto foldera iz
image-a. Da je volume montiran na drugu putanju, MySQL bi i dalje pisao u `/var/lib/mysql`, odnosno u sloj
kontejnera, i podaci bi nestali pri ponovnom pravljenju kontejnera.

### 10.3 Kako je baza nastala, hronoloski

**1. Dockware build, 2024-11-13 (kod dasistweb GmbH).** Jedan `RUN` korak dockware Dockerfile-a:
1. Upisuje u debconf `mysql-server/root_password = root`.
2. `apt-get install -y -q mysql-server-8.0`. Posle instalacije se automatski izvrsava skripta paketa
   `/var/lib/dpkg/info/mysql-server-8.0.postinst`:
   - Linija 173: `mysql_statedir=/var/lib/mysql`.
   - Linija 276: proverava da li je taj folder prazan.
   - Linije 277–291: pravi privremeni SQL fajl sa `ALTER USER 'root'@'localhost' IDENTIFIED BY ...` (lozinka iz
     debconf-a, dakle `root`), `CREATE USER 'debian-sys-maint'@'localhost'` i `SHUTDOWN`.
   - Linija 294: `mysqld --initialize-insecure --user=mysql --init-file=...`. Tada **`mysqld` pravi sistemske
     baze** `mysql`, `sys` i `performance_schema` u `/var/lib/mysql` **unutar image-a**.
3. Dockware zatim pravi `root@'%'` sa lozinkom `root` i `GRANT ALL PRIVILEGES ON *.*`.

Folder `/var/lib/mysql` ne pripada nijednom paketu kao fajl (`dpkg -S /var/lib/mysql` ne nalazi nista). Pravi ga
neka skripta paketa; tacna linija koja radi `mkdir` je **neproverena**.

Sadrzaj `/var/lib/mysql` u image-u (pregledan privremenim kontejnerom bez volume-a): 182 fajla, 192 MB, svi sa
datumom 2024-11-13. Baza `prestashop` tu ne postoji.

**2. Prvo pokretanje, 2026-09-30 10:06:56 UTC.**
1. `docker compose up -d` je napravio **prazan** volume `logeecom_db_volume`.
2. Mount volume-a na `/var/lib/mysql` sakriva folder iz image-a. Da je volume ostao prazan, `mysqld` bi video
   prazan folder, bez korisnika i prava.
3. Zato Docker, kad se **prazan imenovani volume prvi put** montira na folder koji u image-u ima fajlove, prvo
   kopira te fajlove u volume. To je opste pravilo Docker-a.
4. Kopiranje radi Docker pri startu kontejnera, pre `/entrypoint.sh`, dakle pre nego sto `mysqld` radi. To je
   prenos fajlova, ne operacija nad bazom.

Tragovi u volume-u: `auto.cnf`, `ca.pem`, `server-cert.pem`, `debian-5.7.flag` i `buildkitsandbox.pid` imaju
datum 2024-11-13.

**Kopiranje se desilo samo jednom.** Od tada volume nije prazan i Docker vise nista ne kopira. Dokaz: kontejner
je ponovo napravljen 2026-10-06, a baza `prestashop` iz 2026-09-30 je ostala. Kopiranje bi se ponovilo samo sa
novim praznim volume-om (`docker compose down -v`, `docker volume rm logeecom_db_volume` ili promena imena
volume-a u compose fajlu). Tada bi baze prodavnica bile izgubljene. Docker nikad ne spaja sadrzaj image-a sa
postojecim volume-om. Bind mount-ovi (`./plugin0:...`) nikad ne kopiraju nista.

**3. Svaki start kontejnera.** `/entrypoint.sh` radi `chown -R mysql:mysql /var/lib/mysql` i
`service mysql start`.

**4. Instalacija PrestaShop-a, 2026-09-30.** `ps_configuration.PS_INSTALL_VERSION = 8.2.4` ima
`date_add = 2026-09-30 13:35:45`. Instaler je napravio 278 tabela sa prefiksom `ps_` u bazi `prestashop` i upisao
podatke za bazu u `app/config/parameters.php`. Folder `install/` je obrisan, kako PrestaShop trazi posle
instalacije. **Neprovereno:** da li je instaler sam napravio praznu bazu `prestashop` ili je napravljena rucno, i
da li je instalacija isla kroz browser ili CLI.

**5. Ostale prodavnice.** Njihovi instaleri su napravili baze `magento`, `shopware` i `wordpress` (folderi sa
datumima do 2026-10-02).

**6. Instalacija plugin0, 2026-10-07 14:55:22** (`create_time` tabele). `Plugin0::install()` poziva
`Plugin0\Infrastructure\Installer::createTables()`, koja radi `CREATE TABLE IF NOT EXISTS ps_plugin0_entity`.

### 10.4 Sta je u bazi

| Baza | Tabela | Velicina | Ko ju je napravio |
|---|---|---|---|
| `mysql` | 37 | 6,2 MB | MySQL: korisnici, prava, recnik podataka |
| `sys` | 101 | ~0 | MySQL: pogledi za dijagnostiku |
| `performance_schema` | 111 | ~0 | MySQL: merenje performansi, samo u memoriji |
| `information_schema` | 79 | ~0 | MySQL: metapodaci, ne postoji kao fajlovi |
| **`prestashop`** | **278** | 10,4 MB | instaler PrestaShop-a + plugin0 |
| `magento` | 404 | 18,3 MB | instaler Magento-a |
| `shopware` | 230 | 12,6 MB | instaler Shopware-a |
| `wordpress` | 12 | 2,1 MB | instaler WordPress-a |

Podesavanja servera: engine InnoDB (default), `character_set_server = utf8mb4`,
`collation_server = utf8mb4_0900_ai_ci`, `bind_address = 0.0.0.0`, `log_bin = 1`.

**Baza `prestashop`:** tabele `ps_configuration`, `ps_module`, `ps_shop_url`, `ps_employee`, `ps_cart`,
`ps_orders`, `ps_product`, `ps_customer`, `ps_log` i druge. `PS_SHOP_NAME = Test Shop 1`. Domen prodavnice je u
`ps_shop_url.domain` i `ps_configuration.PS_SHOP_DOMAIN`: `8-2-4.prestashop.veljko.ngrok.app`.

**Tabele koje koristi plugin0:**

| Tabela | Cija je | Sta plugin0 tu drzi |
|---|---|---|
| `ps_plugin0_entity` | plugin0 | svi Core entiteti: konekcija, podesavanja (`ConfigEntity`), metode placanja, transakcije, monitoring logovi |
| `ps_log` | PrestaShop | poruke koje `LoggerService` prosledjuje iz Core-a, sa prefiksom `WORLDLINE` (trenutno 270 redova, od 2026-10-07 do 2026-10-13) |

Struktura `ps_plugin0_entity` (pravi je [Installer.php](../src/Infrastructure/Installer.php)):

| Kolona | Tip | Cemu sluzi |
|---|---|---|
| `id` | INT UNSIGNED, AUTO_INCREMENT, PK | ID entiteta |
| `type` | VARCHAR(128) | vrsta Core entiteta, na primer `ConnectionConfig` |
| `index_1` … `index_9` | VARCHAR(255) NULL | vrednosti po kojima Core pretrazuje, na primer `storeId`. Broj kolona je `BaseRepository::INDEX_COLUMNS = 9` |
| `data` | LONGTEXT | ceo entitet kao JSON, sa `class_name` |

Indeksi: `(type, index_1)`, `(type, index_2)`, `(type, index_3)`. Collation: `utf8mb4_unicode_ci`.

Trenutno je u `ps_plugin0_entity` jedan red: `id = 3`, `type = ConnectionConfig`, `index_1 = 1`. Sacuvanih
podesavanja sa Settings stranice nema, pa vaze default-i iz `SettingsService`.

### 10.5 Fajlovi u `/var/lib/mysql`

| Fajl ili folder | Sta je |
|---|---|
| `prestashop/`, `magento/`, `shopware/`, `wordpress/`, `mysql/`, `sys/`, `performance_schema/` | jedan folder po bazi |
| `prestashop/*.ibd` (278 fajlova) | jedan fajl po tabeli: podaci i indeksi. InnoDB „file per table“ (default) |
| `mysql.ibd` | recnik podataka (sve baze, tabele, kolone) i korisnici |
| `ibdata1` | sistemski tablespace InnoDB-a |
| `undo_001`, `undo_002` | undo log: stare verzije redova za rollback i konzistentno citanje |
| `#innodb_redo/` | redo log: zapis izmena za oporavak posle pada |
| `#innodb_temp/`, `ibtmp1` | privremene tabele |
| `#ib_16384_*.dblwr` | doublewrite buffer, zastita od polovicno upisanih stranica |
| `binlog.0000NN`, `binlog.index` | binarni log: svaka izmena podataka redom. Nov fajl pri svakom startu i na 100 MB (`max_binlog_size`); brisu se posle 10 dana (`expire_logs_days = 10`) |
| `ib_buffer_pool` | spisak stranica koje su bile u memoriji, za brze punjenje pri startu |
| `auto.cnf` | jedinstveni UUID servera |
| `*.pem` | SSL sertifikati i RSA kljucevi iz 2024. |

`.ibd` fajlovi su binarni InnoDB format. Ne mogu da se citaju kao tekst; razume ih samo `mysqld`.

### 10.6 Ko pise u bazu

**U `/var/lib/mysql` pise samo `mysqld`.** PrestaShop, plugin0 i instaleri ne diraju fajlove. Oni salju SQL
komande preko konekcije, a `mysqld` ih izvrsava i upisuje u fajlove.

| Sta | Ko je naredio | Ko je upisao | Kada |
|---|---|---|---|
| sistemske baze, `ibdata1`, `auto.cnf`, sertifikati | `mysql-server-8.0.postinst` | `mysqld --initialize-insecure`, u image-u | 2024-11-13 |
| ista kopija u tvom volume-u | Docker (volume je bio prazan) | Docker | 2026-09-30 |
| `prestashop/` | instaler PrestaShop-a (PHP u PHP-FPM-u) | `mysqld` | 2026-09-30 |
| `prestashop/ps_plugin0_entity.ibd` | `Plugin0::install()` → `Installer::createTables()` | `mysqld` | 2026-10-07 |
| `magento/`, `shopware/`, `wordpress/` | njihovi instaleri | `mysqld` | 2026-09-30 – 10-02 |
| svakodnevne izmene | PHP kod PrestaShop-a i plugin0 | `mysqld` | stalno |
| `binlog.*`, `undo_*`, `#innodb_redo/` | niko spolja | `mysqld` | stalno |

Primer jednog upisa je u poglavlju 13.3.

### 10.7 Ko se kako povezuje

| Ko | Kako | Korisnik |
|---|---|---|
| PrestaShop i plugin0 (radni proces PHP-FPM-a) | TCP na `127.0.0.1:3306`; `database_port` je prazan, pa vazi MySQL default 3306 | `root` / `root` |
| `mysql` klijent u kontejneru | unix socket `/var/run/mysqld/mysqld.sock` | `root` / `root` |
| ti sa hosta (DBeaver, PhpStorm Database) | `127.0.0.1:3333` → 3306 u kontejneru | `root` / `root`, radi zbog `root@'%'` |
| `/etc/init.d/mysql` (provera i gasenje) | socket, iz `/etc/mysql/debian.cnf` | `debian-sys-maint` |

`127.0.0.1` u `parameters.php` znaci **sam kontejner** `logeecom_dev`, ne WSL ni Windows. Zato PrestaShop i baza
moraju biti u istom kontejneru da bi ovo podesavanje radilo.

U WSL-u nemas instaliran `mysql` klijent (`which mysql` ne nalazi nista).

Korisnici na serveru: `root@localhost`, `root@%` (oba `mysql_native_password`), `debian-sys-maint@localhost`,
plus interni `mysql.infoschema`, `mysql.session`, `mysql.sys`.

### 10.8 Poredjenje: kako zvanicni `mysql` image pravi bazu

| | logeecom (dockware) | laravel-docker (zvanicni `mysql:8.4.11`) |
|---|---|---|
| MySQL radi u | istom kontejneru kao Apache i PHP | posebnom kontejneru |
| `/var/lib/mysql` u image-u | pun: sistemske baze iz build-a 2024. | prazan; image ga samo proglasava za volume |
| Kako sistemske baze stignu u volume | Docker ih kopira iz image-a, jednom | entrypoint ih pravi (`--initialize-insecure`) |
| Root lozinka | zapecena u image-u (`root`) | iz env varijable `MYSQL_ROOT_PASSWORD` |
| Baza aplikacije | pravi je instaler prodavnice | pravi je entrypoint iz `MYSQL_DATABASE`, tabele prave migracije |
| Sledeci startovi | nista se ne kopira | init se preskace |

Zvanicni entrypoint je `/usr/local/bin/docker-entrypoint.sh`:
1. Linija 237: `if [ -d "$DATADIR/mysql" ]`, da li sistemska baza vec postoji.
2. Ako ne postoji:
   - `mysqld --initialize-insecure` (linija 217).
   - Pokrece privremeni server.
   - Root lozinka iz `MYSQL_ROOT_PASSWORD` (linija 290).
   - `CREATE DATABASE` iz `MYSQL_DATABASE` (linija 312).
   - `CREATE USER` iz `MYSQL_USER` (linija 317).
   - Skripte iz `/docker-entrypoint-initdb.d/`.
   - Gasi privremeni server.
3. `exec "$@"` (linija 413) pokrece `mysqld`, koji postaje glavni proces kontejnera.

U volume-u `laravel-docker_db-data` su folderi `laravel/`, `mysql/`, `performance_schema/` i `sys/`.

---

## 11. Volume: gde je i kako se gleda

Volume `logeecom_db_volume` **fizicki nije u kontejneru**. Nalazi se u skladistu Docker Engine-a. Kontejner ga
samo montira na `/var/lib/mysql`.

**Poredjenje:** kao spoljni disk utaknut u racunar. Disk nije deo racunara, ali ga racunar vidi kao folder.

**Putanja volume-a ne postoji u tvom Ubuntu-u.** `ls /var/lib/docker/volumes` u Ubuntu-u javlja „No such file
or directory“, jer Engine radi u distribuciji `docker-desktop`. Zato do fajlova volume-a dolazis preko nekog
kontejnera koji ga montira. To je uobicajen nacin, ne zaobilaznica.

| Sta hoces da vidis | Kako | Treba li `logeecom_dev` da radi |
|---|---|---|
| podatke (tabele, redove) | `docker exec -it logeecom_dev mysql -uroot -p prestashop` | da |
| podatke, iz grafickog klijenta | DBeaver ili PhpStorm na `127.0.0.1:3333`, `root`/`root` | da |
| fajlove u volume-u | `docker exec logeecom_dev ls -la /var/lib/mysql` | da |
| fajlove u volume-u, bez `logeecom_dev` | privremeni kontejner, primer ispod | ne |
| podatke o volume-u (putanja, datum) | `docker volume inspect logeecom_db_volume` | ne |

Primer privremenog kontejnera koji samo cita volume:

```bash
docker run --rm -v logeecom_db_volume:/podaci:ro --entrypoint ls logeecom-app -la /podaci
```

- `-v logeecom_db_volume:/podaci:ro` montira isti volume na `/podaci`, samo za citanje (`ro`), da ne smeta
  `mysqld`-u koji radi.
- `--rm` brise privremeni kontejner cim se `ls` zavrsi.
- Isti volume se moze montirati u bilo koji kontejner, na bilo koju putanju.

`docker exec -it logeecom_dev mysql ...` ne gleda volume. Pokrece klijent `mysql` u kontejneru, koji se preko
socket-a povezuje na `mysqld`. `mysqld` cita fajlove iz volume-a i vraca tabele i redove.

---

## 12. Mreza: portovi, ngrok, domen, HTTPS

### 12.1 Portovi izmedju hosta i kontejnera

`ports` u compose fajlu (`"HOST:KONTEJNER"`) kaze Docker Desktop-u da prosledjuje konekcije sa porta hosta na
port kontejnera. Prvi broj je uvek host, drugi kontejner. Tabela je u poglavlju 8.2. `localhost:80` u WSL-u
zato stize do Apache-a u kontejneru (provereno sa `curl`), a `127.0.0.1:3333` do MySQL-a (provereno sa `nc`).

### 12.2 Zasto je ngrok obavezan

Dva razloga:

**1. PrestaShop prihvata samo svoj domen.**
- `ps_shop_url.domain` i `ps_configuration.PS_SHOP_DOMAIN` imaju vrednost `8-2-4.prestashop.veljko.ngrok.app`,
  a `PS_SSL_ENABLED = 1`.
- PrestaShop zahteve sa drugim imenom hosta preusmerava na taj domen.

**2. Apache bira folder po imenu hosta** (poglavlje 12.3). Za `http://localhost` nema odgovarajuceg foldera, pa
Apache vraca 404 (provereno).

### 12.3 Kako Apache bira prodavnicu

U tvom vhost-u (iz Dockerfile-a) stoji `VirtualDocumentRoot /var/www/html/public/%2/%1`:
- `%1` je prvi deo imena hosta, `%2` drugi.
- Za `8-2-4.prestashop.veljko.ngrok.app`: `%1 = 8-2-4`, `%2 = prestashop`.
- Document root je onda `/var/www/html/public/prestashop/8-2-4`, a to je
  `/home/veljkozbiljic/logeecom/systems/prestashop/8-2-4` u WSL-u.

Isto pravilo bi radilo za druge prodavnice, na primer `2-4-8.magento.*` → `magento/2-4-8`.

Za `.php` fajlove vazi `SetHandler "proxy:unix:/var/run/php/php8.2-fpm.sock|fcgi://localhost"`: Apache ih salje
PHP-FPM-u.

### 12.4 ngrok

- ngrok agent je u WSL-u: `/snap/bin/ngrok`, verzija 3.39.11.
- Konfiguracija: `~/snap/ngrok/current/.config/ngrok/ngrok.yml`. U njoj su tuneli `site1` do `site6` za domene
  `site*.test`; za ovaj projekat se ne koriste.
- Komanda koju koristis: `ngrok http 80 --url=https://8-2-4.prestashop.veljko.ngrok.app`.

Put zahteva:
1. Browser salje HTTPS zahtev na `8-2-4.prestashop.veljko.ngrok.app`. HTTPS se zavrsava na ngrok serveru.
2. ngrok server salje zahtev kroz tunel ngrok agentu u WSL-u.
3. Agent ga salje kao obican HTTP na `localhost:80`.
4. Docker Desktop ga prosledjuje na port 80 kontejnera.
5. Apache prima zahtev sa originalnim imenom hosta (`Host: 8-2-4.prestashop.veljko.ngrok.app`) i bira folder po
   poglavlju 12.3.

**Kako PrestaShop zna da je zahtev bio HTTPS** iako do Apache-a stize HTTP:
- `Tools::usingSecureMode()` (`classes/Tools.php:392`) proverava, izmedju ostalog,
  `$_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https'` (linija 408).
- ngrok dodaje zaglavlje `X-Forwarded-Proto: https`. To je ponasanje ngrok-a po njegovoj dokumentaciji, u tvom
  okruzenju **neprovereno** posmatranjem zaglavlja.

---

## 13. Put jednog zahteva do plugin0

### 13.1 Admin zahtev (na primer `/worldline/settings`)

1. **Browser → ngrok → Apache.** Kao u poglavlju 12.4.
2. **Apache → PHP-FPM.** Vhost salje `.php` zahtev preko `/var/run/php/php8.2-fpm.sock`. Jedan radni proces
   PHP-FPM-a (`www-data`) preuzima zahtev.
3. **`admin174kob088ranecckhxg/index.php`:**
   - Ucitava `config/config.inc.php` (konfiguracija, baza, kontekst).
   - Pravi Symfony kernel (`new AppKernel(_PS_ENV_, _PS_MODE_DEV_)`) i pokusava da obradi zahtev
     (`$kernel->handle($request)`).
   - Ako Symfony ne nadje rutu (`NotFoundHttpException`), prelazi na stari, legacy admin
     (`Dispatcher::getInstance()->dispatch()`).
4. **Kernel ucitava module.**
   - `app/AppKernel.php` (linije 223–238) za svaki aktivni modul ukljucuje `modules/<modul>/vendor/autoload.php`.
     Tako PHP nalazi klase `Plugin0\...`.
   - `src/PrestaShopBundle/Routing/YamlModuleLoader.php:66` ucitava `modules/<modul>/config/routes.yml`. Tako
     Symfony zna za rute plugin0. U prod rezimu rute su kesirane u `var/cache/prod`.
5. **Hook `actionDispatcherBefore`.** Za Symfony rute ga okida
   `PrestaShopBundle\EventListener\ActionDispatcherLegacyHooksSubscriber` (po komentaru u `plugin0.php`; u kodu
   PrestaShop-a **neprovereno**). Redom:
   - Ucitava se `plugin0.php`, klasa `Plugin0`, koja `require_once` ucitava `vendor/autoload.php`.
   - Poziva se `Plugin0::hookActionDispatcherBefore()`, koji poziva `Plugin0\Bootstrap::boot()`.
   - `boot()` poziva `BootstrapComponent::bootstrap()` iz Core-a sa `config/brand.json` i registruje servise i
     repozitorijume (poglavlje 14.4). Jednom po PHP procesu; svaki sledeci poziv ne radi nista.
6. **Router** nalazi rutu u [config/routes.yml](../config/routes.yml), na primer `plugin0_settings` →
   `Plugin0\Controller\Admin\SettingsController::indexAction`.
7. **Bezbednost.** PrestaShop proverava da li je zaposleni ulogovan
   (`PrestaShop\PrestaShop\Adapter\Security\Admin::onKernelRequest`) i proverava `_token` u URL-u. Ruta
   `api_plugin0_core_info` namerno pocinje sa `api_`, pa `TokenizedUrlsListener` preskace proveru tokena (po
   komentaru u `routes.yml`).
8. **Kontroler** poziva Core i servise plugin0, a Core cita i pise bazu preko `BaseRepository` (poglavlje 13.3).
9. **Twig sablon** iz `views/templates/admin/` renderuje HTML. Sablon nasledjuje
   [layout.html.twig](../views/templates/admin/layout.html.twig), koji nasledjuje
   `@PrestaShop/Admin/layout.html.twig` i dodaje bocni meni plugin0.
10. Odgovor ide nazad: PHP-FPM → Apache → Docker port → ngrok agent → ngrok server → browser.

### 13.2 Front office zahtev

Isto do PHP-FPM-a, ali ulaz je `8-2-4/index.php`. On ucitava `config/config.inc.php` i poziva
`Dispatcher::getInstance()->dispatch()` (`classes/Dispatcher.php`), koji okida isti hook
`actionDispatcherBefore`. plugin0 za sada nema front controller-e. Planirani `asyncprocess`, checkout i webhook
ce ici ovim putem (`Configuration::getAsyncProcessUrl()` vec pravi link na `asyncprocess` preko
`getModuleLink`).

### 13.3 Primer upisa u bazu: cuvanje Worldline konekcije

1. Browser salje POST na `/worldline/configuration`.
2. `ConfigurationController::indexAction` poziva Core, koji kredencijale proverava kod Worldline-a i cuva
   konekciju.
3. Core poziva `Plugin0\Infrastructure\Repository\BaseRepository::save()`, a ona poziva
   `Db::getInstance()->insert('plugin0_entity', ...)`.
4. Klasa `Db` bira drajver u `classes/db/Db.php:296`. Posto je ucitana PHP ekstenzija `pdo_mysql`, koristi
   `DbPDO`.
5. `DbPDO` otvara TCP konekciju na `127.0.0.1:3306` i salje `INSERT INTO ps_plugin0_entity ...`.
6. `mysqld` upisuje:
   - izmenu u redo log (`#innodb_redo/`),
   - red u `prestashop/ps_plugin0_entity.ibd` (InnoDB stranice idu na disk sa zakasnjenjem),
   - zapis u `binlog.0000NN`.
7. Posto je na `/var/lib/mysql` montiran volume, bajtovi zavrsavaju u `logeecom_db_volume`.

PHP i PrestaShop ne znaju za `/var/lib/mysql`. Znaju samo `127.0.0.1:3306`, `root` i `root`.

---

## 14. plugin0 iznutra

### 14.1 Sta je plugin0

PrestaShop 8 modul placanja (`name = plugin0`, `tab = payments_gateways`, `version = 1.0.0`,
`ps_versions_compliancy` od 8.0.0; `Plugin0 extends PaymentModule`). Gradi Worldline Online Payments integraciju od
nule, na Core biblioteci `wl-online-payments-direct/plugin-core-base` 1.0.7. Dva namespace-a: `Plugin0\` → `src/`
(PrestaShop i Core strana) i `Logeecom\` → `lib/Logeecom/` (poslovni sloj demo projekta
`/home/veljkozbiljic/laravel-docker/src`, kopiran bez izmena; vidi 14.6).

### 14.2 Fajlovi

```
plugin0/
├── plugin0.php                     ← glavna klasa modula (Plugin0 extends PaymentModule): install, hook-ovi
├── composer.json, composer.lock    ← zavisnosti i PSR-4 autoload (Plugin0\ → src/, Logeecom\ → lib/Logeecom/)
├── phpunit.xml                     ← demo unit testovi (tests/Unit); pokrecu se u kontejneru (14.6)
├── vendor/                         ← composer install (u .gitignore); dev paketi phpunit i psr/log
├── config/
│   ├── routes.yml                  ← Symfony admin rute
│   └── brand.json                  ← Worldline endpoint-i (live i test), predaje se Core-u
├── controllers/front/payment.php   ← front controller "payment": klik na PLACE ORDER, Worldline sesija, preusmeravanje
├── controllers/front/return.php    ← front controller "return": povratak sa Worldline strane, ishod, narudzbina (14.7)
├── lib/Logeecom/                   ← demo projekat, poslovni sloj bez framework-a, bajt-identican demo src/
│   ├── Configuration/              ← jos nije povezan: Configuration stranica ostaje na Core-u do Worldline koraka
│   ├── Logs/                       ← jos nije povezan: Logs stranica cita ps_log kroz LogReader (tiket trazi kontekst)
│   ├── PaymentMethods/             ← domen, servis, interfejsi repozitorijuma, tipovi Redirect i iFrame
│   └── Settings/                   ← domen, servis, interfejs skladista, LogLevelApplierInterface
├── src/
│   ├── Bootstrap.php               ← podize Core, registruje servise i repozitorijume
│   ├── Configuration.php           ← Core Configuration za PrestaShop (i kljucevi podesavanja)
│   ├── Services.php                ← fabrika demo servisa: koja implementacija stoji iza kog demo interfejsa
│   ├── Adapter/                    ← demo interfejsi nad Core-om i PrestaShop-om (repozitorijumi, logger, applier)
│   ├── Checkout/                   ← AvailablePaymentMethods (koje metode vaze za korpu), PrestaShopCartProvider
│   ├── Controller/Admin/           ← Symfony kontroleri admin stranica
│   ├── Entity/                     ← PaymentMethodEntity: demo metoda kao Core entitet u ps_plugin0_entity
│   ├── Form/                       ← PaymentMethodForm: pravila forme iz demo Form Request-a
│   └── Infrastructure/             ← implementacije Core interfejsa + Installer + BaseRepository + LogReader
├── tests/                          ← demo unit testovi i in-memory repozitorijumi (Tests\ → tests/)
├── views/
│   ├── templates/admin/*.html.twig ← sabloni admin stranica
│   ├── templates/front/return.tpl  ← "placanje se obradjuje" (front controller "return")
│   └── img/                        ← placeholder logo; logotipi metoda u payment-methods/
└── docs/                           ← CORE.md i ovaj dokument
```

### 14.3 `plugin0.php`

| Metoda | Kada je PrestaShop zove | Sta radi |
|---|---|---|
| `__construct()` | kad god ucitava modul | ime, verzija, autor, opis; `currencies = true`, `currencies_mode = 'checkbox'`, `controllers = ['payment', 'return']` (svojstva koja `PaymentModule` trazi) |
| `install()` | dugme Install | `Bootstrap::boot()`, `parent::install()` (`PaymentModule::install`: i redovi u `module_currency`, `module_country`, `module_carrier`), `Installer::createTables()`, `registerHook('actionDispatcherBefore')`, `registerHook('paymentOptions')` |
| `uninstall()` | dugme Uninstall | `Bootstrap::boot()`, `Installer::dropTables()` (**brise `ps_plugin0_entity`**), `parent::uninstall()` |
| `getContent()` | dugme Configure | preusmerava na rutu `plugin0_configuration` |
| `hookActionDispatcherBefore()` | jednom po zahtevu (front, module front controller-i, legacy admin, Symfony admin); ne okida se iz `bin/console` ni webservice-a | `Bootstrap::boot()` |
| `hookPaymentOptions($params)` | korak placanja na checkout-u (`CheckoutPaymentStep` preko `PaymentOptionsFinder`), sa korpom u `$params['cart']` | jedna `PaymentOption` po metodi iz `Checkout\AvailablePaymentMethods::forCart` (aktivna, tip Redirect, Code = Worldline ID proizvoda koji Core podrzava, valuta korpe, zemlja adrese za fakturu); tekst = ime metode, `action` = front controller `payment`, POST polje `method` = id metode |
| `checkCurrency($cart)` | iz `hookPaymentOptions` i iz front controller-a | PrestaShop-ovo ogranicenje valuta modula (`module_currency`), kao u `ps_wirepayment` |

U `install()` se Core podize rucno, jer hook u tom trenutku jos nije registrovan. Modul u dev prodavnici je bio
instaliran dok je jos nasledjivao `Module`; hook `paymentOptions` i redovi ogranicenja (`module_currency`,
`module_country`, `module_carrier`) dodati su 2026-10-14 skriptom u kontejneru, bez reinstalacije (uninstall bi
obrisao `ps_plugin0_entity` i Worldline konekciju u njoj).

### 14.4 `Bootstrap.php`

`Plugin0\Bootstrap extends BootstrapComponent` (Core). `boot()` poziva `bootstrap()` sa brendom `worldline` i
`config/brand.json`.

**Servisi** (`initServices()`, preko `ServiceRegister::registerService`): Core trazi interfejs, a plugin0 daje
implementaciju.

| Core interfejs | Implementacija u plugin0 | Sta radi |
|---|---|---|
| `Core\Bootstrap\Configuration\Configuration` | `Plugin0\Configuration` | ime integracije i verzije, URL za `asyncprocess`, citanje i upis kljuceva |
| `Serializer` | Core `JsonSerializer` | JSON (de)serijalizacija |
| `ShopLoggerAdapter` | `Infrastructure\LoggerService` | Core poruke → `ps_log` |
| `Encryptor` | `Infrastructure\Encryptor` | sifrovanje tajni (API secret) |
| `MetadataProviderInterface` | `Infrastructure\MetadataProvider` | podaci o platformi za zaglavlje `X-GCS-ServerMetaInfo` |
| `StoreService` | `Infrastructure\StoreService` | domen, prodavnice, statusi narudzbina |
| `LanguageService` | `Infrastructure\LanguageService` | aktivni jezici prodavnice |
| `LogoUrlService` | `Infrastructure\LogoUrlService` | URL logotipa metoda (`views/img/payment-methods/{productId}.svg\|png`) |
| `ShopPaymentService` | `Infrastructure\ShopPaymentService` | samo loguje Core radnje nad Worldline podesavanjima metoda (save, enable, disconnect); metode iz dema ne dira |
| `ShopOrderService` | `Infrastructure\ShopOrderService` | pravi PrestaShop narudzbinu posle placanja i menja joj status; jedino mesto koje to radi (14.7) |

**Repozitorijumi** (`initRepositories()`, preko `RepositoryRegistry::registerRepository`):
- `BaseRepository` (tabela `ps_plugin0_entity`) za: `ConfigEntity`, `Process`, `LogData`,
  `ConnectionConfigEntity`, `DisconnectTime`, `LogSettingsEntity`, `PayByLinkSettingsEntity`,
  `PaymentSettingsConfigEntity`, `WebhookSettingsEntity`, `PaymentLinkEntity`, `PaymentMethodConfigEntity`,
  `PaymentTransactionEntity`, `PaymentTransactionLockEntity`, `ProductTypeEntity`, `TokenEntity`, i jedini
  entitet koji nije Core-ov: `Plugin0\Entity\PaymentMethodEntity` (tip `PaymentMethod`, indeksi `code`, `type`,
  `isActive`, `sortOrder`): metoda placanja iz demo projekta, vidi 14.6.
- `LogsRepository` (nasledjuje `BaseRepository`, ista tabela, plus napredna pretraga) za `MonitoringLog` i
  `WebhookLog`.
- `QueueItem` namerno nije registrovan; dolazi sa `QueueItemRepository` u koraku za pozadinske zadatke.

### 14.5 Admin rute i stranice

| Ruta | Putanja | Metode | Kontroler | Sablon |
|---|---|---|---|---|
| `api_plugin0_core_info` | `/worldline/core-info` | GET | `CoreInfoController::indexAction` (JSON) | — |
| `plugin0_configuration` | `/worldline/configuration` | GET, POST | `ConfigurationController::indexAction` | `configuration.html.twig` |
| `plugin0_payment_methods` | `/worldline/payment-methods` | GET | `PaymentMethodsController::listAction` | `payment_methods.html.twig` |
| `plugin0_payment_method_create` | `/worldline/payment-methods/create` | GET, POST | `PaymentMethodsController::createAction` | `payment_method.html.twig` |
| `plugin0_payment_method_edit` | `/worldline/payment-methods/{id}/edit` | GET, POST | `PaymentMethodsController::editAction` | `payment_method.html.twig` |
| `plugin0_payment_method_active` | `/worldline/payment-methods/{id}/active` | POST | `PaymentMethodsController::setActiveAction` | — |
| `plugin0_payment_method_delete` | `/worldline/payment-methods/{id}/delete` | POST | `PaymentMethodsController::deleteAction` | — |
| `plugin0_settings` | `/worldline/settings` | GET, POST | `SettingsController::indexAction` | `settings.html.twig` |
| `plugin0_logs` | `/worldline/logs` | GET | `LogsController::indexAction` | `logs.html.twig` |

- Sve rute osim `api_plugin0_core_info` imaju `_legacy_controller: AdminPlugin0Configuration`.
- `{id}` je ceo broj (`[1-9][0-9]{0,17}`); `create` i `{id}/edit` se ne mesaju jer se putanje razlikuju.
- Settings, Payment methods i Logs nasledjuju `ConnectedController`: postoje samo dok je prodavnica povezana sa
  Worldline-om, inace vode na Configuration.
- Front strana: `index.php?fc=module&module=plugin0&controller=payment` (`controllers/front/payment.php`,
  `Link::getModuleLink('plugin0', 'payment')`), kuda vodi PLACE ORDER, i
  `index.php?fc=module&module=plugin0&controller=return` (`controllers/front/return.php`; sa friendly URL-ovima
  `/en/module/plugin0/return`), kuda Worldline vraca kupca. Oba su opisana u 14.7.

Ukratko sta stranice rade:
- **Configuration**: unos i provera kredencijala po okruzenju (test/live), prikaz stanja i diskonekcija, preko
  Core `AdminAPI::connection()`. Tajne nikad ne idu u sablon. (Jedina stranica koja ne koristi demo kod.)
- **Payment methods**: metode koje admin sam pravi, kao u demu (`Logeecom\PaymentMethods`): lista sa pretragom,
  filterima po valuti, zemlji, tipu (Redirect/iFrame) i statusu, stranicenjem i prekidacem; forma za dodavanje i
  izmenu (Name, Code jedinstven, Type, Currencies, Countries, Sort order, Active); brisanje sa potvrdom. Valute i
  zemlje se biraju medju onima ukljucenim u PrestaShop-u (`Adapter\ReferenceData`). Svaka metoda je jedan red tipa
  `PaymentMethod` u `ps_plugin0_entity`. Aktivne metode cija lista valuta sadrzi valutu korpe, a lista zemalja
  zemlju adrese za fakturu, PrestaShop nudi kupcu na checkout-u (`hookPaymentOptions`).
- **Settings**: Log level, Default currency i Rows per page, pravila iz demo `SettingsService`-a; vrednosti su
  `ConfigEntity` redovi u `ps_plugin0_entity` pod Core kljucem `minLogLevel` (Log level, Core broj nivoa) i
  kljucevima `defaultCurrency` i `rowsPerPage` (`Adapter\SettingsRepository`). Default za Log level je Info, jer je
  `_PS_MODE_DEV_ = false`; default za Default currency je `EUR` ako je prodavnica ima, inace prazno
  ("Choose a currency"), kao u demu; default za Rows per page je 10.
- **Logs**: cita zapise sa prefiksom `WORLDLINE` iz `ps_log`, najnovije prve, sa filterom po nivou i
  stranicenjem (`LogReader`); velicina stranice je Rows per page.

### 14.6 Demo kod u `lib/Logeecom` i adapteri

Demo projekat (`/home/veljkozbiljic/laravel-docker`) ima poslovni sloj u `src/` bez ijedne Laravel klase:
`Logeecom\Configuration`, `Logeecom\Logs`, `Logeecom\PaymentMethods` i `Logeecom\Settings`. Ta cetiri foldera su
kopirana u `lib/Logeecom/` bez izmena (`diff -rq` je prazan), zajedno sa unit testovima (`tests/Unit`, `tests/Support`).
Laravel adaptere iz demo `app/` (Eloquent repozitorijumi, service provideri, Monolog) ovde zamenjuju:

| Demo interfejs | Implementacija u plugin0 | Nad cime |
|---|---|---|
| `PaymentMethodRepositoryInterface` | `Adapter\PaymentMethodRepository` | `Entity\PaymentMethodEntity` kroz Core `RepositoryRegistry` → `BaseRepository` → `ps_plugin0_entity`; tip i status filtrira baza (indeksi), pretragu, valutu, zemlju, redosled i stranicu PHP; transakcija = `START TRANSACTION`/`COMMIT`/`ROLLBACK` kroz `Db` |
| `ReferenceDataRepositoryInterface` | `Adapter\ReferenceData` | kodovi valuta i zemalja ukljucenih u PrestaShop-u (`ShopCountriesAndCurrencies`) |
| `SettingsRepositoryInterface` | `Adapter\SettingsRepository` | Core `ConfigEntity` redovi kroz `Plugin0\Configuration`; `log_level` ↔ Core `minLogLevel` |
| `LogLevelApplierInterface` | `Adapter\LogLevelApplier` | `LoggerService::useMinLogLevel()`: novi nivo vazi odmah, do kraja zahteva |
| `Psr\Log\LoggerInterface` | `Adapter\CoreLogger` | Core `Logger` → `LoggerService` → `ps_log`; komponenta u poruci je ime servisa (`PaymentMethods`, `Settings`) |

`Plugin0\Services` je jedino mesto koje ih spaja (`Services::paymentMethods()`, `Services::settings()`), kao sto su u
demu service provideri. `LoggerService` nivo logovanja cita iz demo `SettingsService::logLevel()`.
`Infrastructure\CoreLogLevel` prevodi imena nivoa iz dema i PSR-3 u Core brojeve i nazad.

Unit testovi (91, iz dema) se pokrecu u kontejneru, na PHP-u 8.2 na kome modul radi:

```
docker exec -u 1000:1000 -e HOME=/tmp/plugin0-home -w /var/www/html/public/prestashop/8-2-4/modules/plugin0 logeecom_dev php -d xdebug.mode=off vendor/bin/phpunit
```

Detalji Core API-ja su u [CORE.md](CORE.md).

### 14.7 Checkout: placanje preko Worldline Hosted Checkout strane

Tiket MIDU-1221. Placanje vodi Core (`CheckoutAPI`); plugin povezuje PrestaShop sa njim i ne tumaci status placanja.

**Tok od klika na PLACE ORDER:**

1. `themes/core.js` klasicne teme salje formu izabrane opcije (`#pay-with-<id>-form form`): POST na front controller
   `payment`, polje `method` = id metode (forma iz `Plugin0::hookPaymentOptions`).
2. `payment` ponovo proverava korpu i metodu (`AvailablePaymentMethods::forCart`), pa zove
   `CheckoutAPI::get()->hostedCheckout($storeId)->createSession(new HostedCheckoutSessionRequest(PrestaShopCartProvider::for($cart), $returnUrl, PaymentProductId::parse($method->code)))`.
   `returnUrl` = `Link::getModuleLink('plugin0', 'return', [], true)`.
3. Core salje Worldline-u `POST /hostedcheckouts` (sandbox: `payment.preprod.direct.worldline-solutions.com`) i
   cuva `PaymentTransaction` u `ps_plugin0_entity` (tip `PaymentTransaction`; merchantReference = ID korpe,
   paymentId = hostedCheckoutId + `_0`, returnHmac = RETURNMAC, status 0).
4. Greska → kupac nazad na korak placanja sa porukom; uspeh → `Tools::redirect($response->getRedirectUrl())`.
5. Worldline vraca kupca na `return` i dodaje query parametre `hostedCheckoutId` i `RETURNMAC`.
6. `return`: bez ispravnog `hostedCheckoutId` (isti obrazac kao Core `PaymentId`) i nepraznog `RETURNMAC`-a
   odmah nazad na korak placanja. Inace `CheckoutAPI::get()->payment($storeId)->updateOrderStatus(...)` (Core odmah
   pita Worldline `GET /payments/{id}/details` i zove `ShopOrderService`), pa `getPaymentOutcome(...)`; ako je
   placanje i dalje u obradi (`isWaiting()`), `startWaitingForOutcome(...)` (Core ceka najvise 10 s od povratka).
7. Kupac ide na: `order-confirmation` (narudzbina postoji i pripada ulogovanom kupcu), korak placanja sa porukom
   "otkazano" (status 1 ili 6) ili "odbijeno" (status 2), korak placanja sa porukom "nije potvrdjeno" (greska ili
   lazni povratak), ili stranicu `return.tpl` "placanje se obradjuje" (status u obradi, narudzbina ne postoji).

**Kada nastaje narudzbina: posle placanja.** Obrazlozenje:
- Core je tako napravljen: `ShopOrderService::createShopOrder` "creates shop order from belonging cart if it doesn't
  already exist", `cancelShopOrder` "(if order is even created)"; `StatusUpdateService` zove `createShopOrder` samo
  kad je placen ceo iznos (`isFullyPaid`) i status 5 ili 9, ili uz ceo iznos kad istekne cekanje na konacan status.
- PrestaShop dozvoljava jednu narudzbinu po korpi (`PaymentModule::validateOrder` odbija korpu sa narudzbinom).
  Narudzbina pre placanja bi za svaki otkazan pokusaj ostavila otkazanu narudzbinu, skidala i vracala zalihe, slala
  mejlove i trazila novu korpu za novi pokusaj. Ovako otkazan ili odbijen pokusaj ne ostavlja nista, a korpa ostaje.
- Cena: ako kupac plati i zatvori browser pre povratka, narudzbina ne nastaje dok neko ne pozove `updateOrderStatus`
  (webhook ili `TransactionStatusCheckTask`; nijedno jos nije povezano).

**Bezbednost povratka:** adresa `return` je javna. Core transakciju trazi po paru payment ID + RETURNMAC
(`PaymentTransactionRepository::get`), a status uvek cita od Worldline-a, nikad iz URL-a. Prazan RETURNMAC plugin
odbija pre Core-a, jer Core uslov po returnHmac dodaje samo kad nije `null`. Provereno na sandbox-u 2026-10-14:
pogresan RETURNMAC i tudji ID sa pravim RETURNMAC-om daju `PaymentProcessor.paymentTransactionNotFound`; adresa bez
parametara, sa laznim RETURNMAC-om, sa `hostedCheckoutId` koji nije broj i sa `RETURNMAC[]` vraca 302 na `/en/order`.

**Statusi na jednom mestu i idempotentnost:** samo `Infrastructure\ShopOrderService` zove
`PaymentModule::validateOrder` i `OrderHistory::changeIdOrderState` (provera:
`grep -rn "validateOrder\|changeIdOrderState\|setCurrentState" src controllers plugin0.php`). Isti ishod stize vise
puta (Core ga i pri jednom povratku obradi dvaput, kupac moze da osvezi stranicu, kasnije i webhook), pa:
- sve izmene jedne korpe idu pod MySQL `GET_LOCK('plugin0_cart_<id>_<baza>')`, jer Core `lockOrderCreation` nad
  `ps_plugin0_entity` nije atomican;
- narudzbina se pravi samo ako `ps_orders` za korpu nema red;
- status se menja samo u status u kome narudzbina jos nije bila (`ps_order_history`), pa ponovljen poziv ne dodaje
  red istorije, mejl ni belesku i ne vraca narudzbinu koju je trgovac vec pomerio dalje;
- interna beleska "Worldline payment ID: ..." nastaje samo pri kreiranju (`validateOrder`, `$message`);
- narudzbinu u stanju `PS_OS_ERROR` plugin ne pomera (to stanje `validateOrder` sam postavlja kad se naplaceni iznos
  ne slaze sa korpom).

**Iznos:** `PrestaShopCartProvider` salje `Cart::getOrderTotal(true, Cart::BOTH)` kao `Amount::fromFloat` (ceo broj
u najmanjim jedinicama valute); Core `RoundingTotalsCartProvider` dodaje stavku `rounding` kad se zbir stavki zbog
zaokruzivanja po redu ne slaze sa ukupnim iznosom (korpa 9: 22,944 → 2294 + rounding 6 = 2300 RSD). `validateOrder`
dobija iznos koji je Worldline naplatio.

**Provereno u browseru 2026-10-15** (sandbox `logeecom35`, valuta EUR, adresa RS, metoda "Viza payment", code 1):
- uspesno placanje (Visa `4330264936344675`, 3-D Secure frictionless): korpa 9 → narudzbina 8 (14,28 EUR), korpa 10
  → narudzbina 9 (22,94 EUR); stanje "Payment accepted", jedan red istorije, jedna uplata sa Worldline payment ID-jem,
  iznos jednak iznosu na Worldline strani do centa;
- ponovno otvaranje istog `return` URL-a (korpa 10): preusmerenje na potvrdu iste narudzbine, bez nove narudzbine,
  reda istorije, uplate, beleske i log zapisa "Order created";
- izmenjen jedan znak u RETURNMAC-u, odnosno u `hostedCheckoutId`: Core vraca "Payment transaction ... not found",
  302 na `/en/order`, nista se ne menja;
- Cancel na Worldline strani (korpa 11): Core status 1, zuta poruka "The payment was cancelled...", korpa ostaje,
  narudzbina ne nastaje;
- odbijen 3-D Secure (Worldline ACS simulator, `transStatus` N/R; strana sa JSON-om postoji samo u sandbox-u, u
  produkciji je to strana banke kupca): Core status 2, crvena poruka "The payment was refused...", korpa ostaje,
  narudzbina ne nastaje.

**Sta nije pokriveno:**
- metode tipa iFrame (Hosted Tokenization) se ne nude na checkout-u;
- webhook i provera u pozadini (narudzbina kad kupac ne dodje do `return`);
- sandbox nalog `logeecom35` nudi proizvode samo u EUR, GBP i CHF (`GET /products` 2026-10-14, iznos 2300: FR/EUR,
  RS/EUR, GB/GBP i CH/CHF po 12 proizvoda, medju njima 1 = Visa, ali ne 302 = Apple Pay; 0 za RSD, BZD, INR, MXN,
  USD, PLN, DKK, SEK, CZK, HUF, CAD, AUD i JPY, i za FR/RSD), pa placanje u valutama dev prodavnice vraca
  `UNKNOWN_PRODUCT_ID`;
- proizvodi iz Core `PaymentProductId::SEPARATE_CAPTURE_SUPPORTED` (npr. 302 Apple Pay, 320 Google Pay, 840 PayPal)
  padaju u `createSession` dok za njih ne postoji Core `PaymentMethodConfigEntity` ("Call to a member function
  getPaymentAction() on null" u `CreateHostedCheckoutRequestTransformer`), pa ih `AvailablePaymentMethods` od
  2026-10-15 ne nudi na checkout-u (demo metoda "Appla Pay", code 302, se zato ne prikazuje);
- kad je korpa vec narucena (npr. izmenjen RETURNMAC za vec placenu korpu), poruka "The payment could not be
  confirmed..." se ne vidi: `/en/order` prazne korpe odmah preusmerava na `/en/cart?action=show` (2026-10-15);
  narudzbina i statusi pritom ostaju netaknuti.

---

## 15. Mejlovi

Nijedan mejl iz ovog okruzenja ne izlazi na internet. Put jednog mejla:

1. PrestaShop salje mejl PHP funkcijom `mail()` (`ps_configuration.PS_MAIL_METHOD = 1`).
2. PHP poziva program iz `sendmail_path = /usr/sbin/ssmtp` (`/etc/php/8.2/fpm/conf.d/01-general.ini`, linija
   19).
3. `ssmtp` salje mejl na `mailhub=localhost:1025` (`/etc/ssmtp/ssmtp.conf`).
4. Mailcatcher ga prima na portu 1025 i cuva u memoriji.

Mailcatcher web (port 1080) nije objavljen na hostu, a tvoj vhost nema proxy za `/mailcatcher`. Zato poruke
trenutno ne mozes da vidis iz Windows browsera. Da bi mogao, u compose fajl bi trebalo dodati port `1080`
(**neprovereno** u praksi).

---

## 16. Logovi: gde sta pise

| Log | Putanja (u kontejneru, osim ako nije drugacije naznaceno) | Ko pise |
|---|---|---|
| ispis starta kontejnera | `docker logs logeecom_dev` | `/entrypoint.sh` |
| Apache greske | `/var/log/apache2/error.log` | Apache (`ErrorLog` u `apache2.conf`) |
| Apache pristupi | `/var/log/apache2/other_vhosts_access.log` | Apache (`other-vhosts-access-log.conf`); `access.log` je prazan |
| PHP-FPM | `/var/log/php8.2-fpm.log` | PHP-FPM master |
| MySQL | `/var/log/mysql/error.log` | `mysqld` |
| PrestaShop (Symfony) | `systems/prestashop/8-2-4/var/logs/prod-YYYY-MM-DD.log` (u WSL-u) | PrestaShop |
| PrestaShop log u bazi | tabela `ps_log` (Advanced Parameters → Logs) | PrestaShop i plugin0 `LoggerService` |
| Core logovi plugin0 | `ps_log`, poruke sa prefiksom `WORLDLINE`; stranica Logs u plugin0 | `LoggerService` |

Primer:

```bash
docker exec logeecom_dev tail -n 50 /var/log/apache2/error.log
```

---

## 17. Rad na kodu

**Izmene su odmah vidljive.** `./plugin0` je bind mount u `modules/plugin0`. Fajl koji izmenis u
`/home/veljkozbiljic/logeecom/plugin0/` kontejner vidi odmah. Restart ne treba.

**Kes.** PrestaShop radi u prod rezimu (`_PS_MODE_DEV_ = false`), pa Symfony kesira rute, servise i Twig u
`var/cache/prod`. Ako se izmena u `routes.yml` ili servisima ne vidi, obrisi kes. Za `.twig` sablone isto
verovatno vazi; to je **neprovereno**.

```bash
docker exec -u www-data logeecom_dev php /var/www/html/public/prestashop/8-2-4/bin/console cache:clear
```

`-u www-data` je obavezno. `docker exec` podrazumevano radi kao `root`, pa bi kes fajlovi pripali `root`-u, a
PHP-FPM koji radi kao `www-data` ne bi mogao da ih menja.

**Composer.** Iz WSL-a, u `/home/veljkozbiljic/logeecom/plugin0`: `composer install` posle kloniranja ili
izmene `composer.lock`.

**Shell u kontejneru:**

```bash
docker exec -it logeecom_dev bash
```

**PHP iz komandne linije u kontejneru** je 8.2 (`update-alternatives`), isto kao PHP-FPM. Skripte koje treba da
koriste PrestaShop moraju da ucitaju `config/config.inc.php` i `modules/plugin0/vendor/autoload.php`.

**Xdebug.** Ukljucen, `mode = debug`, trigger rezim, IDE na `192.168.1.28:9000`, `idekey = PHPSTORM`
(poglavlje 7).

**Line endings.** Svi fajlovi su LF. Pre commit-a proveri `git ls-files --eol`.

---

## 18. Sta cuva podatke, a sta ih brise

| Radnja | Kontejner | Baze (volume) | Kod (`plugin0/`, `systems/`) |
|---|---|---|---|
| `docker compose stop app` / `start` | ostaje | ostaju | ostaje |
| `docker compose up -d --build app` | pravi se novi | ostaju | ostaje |
| `docker compose down` | **brise se** | ostaju | ostaje |
| `docker compose down -v` | **brise se** | **brisu se sve baze** | ostaje |
| `docker volume rm logeecom_db_volume` | — | **brisu se sve baze** | ostaje |
| Uninstall plugin0 u adminu | ostaje | **brise se `ps_plugin0_entity`** (i Worldline konekcija) | ostaje |

Posle brisanja volume-a, pri sledecem startu Docker bi u novi prazan volume kopirao `/var/lib/mysql` iz image-a.
Ostale bi samo sistemske baze; prodavnice ne bi radile.

**Gasenje i MySQL.** Na `docker compose stop` Docker salje SIGTERM procesu PID 1. PID 1 je `bash` bez handlera za
SIGTERM, a kernel takav signal procesu PID 1 ne isporucuje. Docker zato posle 10 sekundi (default) salje SIGKILL,
pa se i `mysqld` ubija naglo. InnoDB pri sledecem startu radi oporavak iz redo loga. Ovo je ocekivano ponasanje po
tome kako Linux tretira PID 1; **neprovereno** testom.

**Backup ne postoji.** Rucni backup baze `prestashop` iz WSL-a (fajl se upisuje u folder iz koga pokrenes
komandu):

```bash
docker exec logeecom_dev mysqldump -uroot -proot --single-transaction prestashop > prestashop-backup.sql
```

Vracanje backup-a:

```bash
docker exec -i logeecom_dev mysql -uroot -proot prestashop < prestashop-backup.sql
```

---

## 19. Komande na jednom mestu

Sve se kucaju u WSL Ubuntu terminalu.

| Sta | Komanda |
|---|---|
| pokretanje kontejnera | `cd /home/veljkozbiljic/logeecom && docker compose up -d app` |
| ngrok tunel | `ngrok http 80 --url=https://8-2-4.prestashop.veljko.ngrok.app` |
| gasenje kontejnera | `cd /home/veljkozbiljic/logeecom && docker compose stop app` |
| stanje kontejnera | `docker ps -a` |
| ispis starta | `docker logs logeecom_dev` |
| procesi u kontejneru | `docker exec logeecom_dev ps -eo pid,ppid,user,cmd --forest` |
| shell u kontejneru | `docker exec -it logeecom_dev bash` |
| MySQL konzola | `docker exec -it logeecom_dev mysql -uroot -proot prestashop` |
| jedan SQL upit | `docker exec logeecom_dev mysql -uroot -proot prestashop -e "select ..."` |
| brisanje Symfony kesa | `docker exec -u www-data logeecom_dev php /var/www/html/public/prestashop/8-2-4/bin/console cache:clear` |
| Apache greske | `docker exec logeecom_dev tail -n 50 /var/log/apache2/error.log` |
| podaci o volume-u | `docker volume inspect logeecom_db_volume` |
| fajlovi volume-a bez `logeecom_dev` | `docker run --rm -v logeecom_db_volume:/podaci:ro --entrypoint ls logeecom-app -la /podaci` |
| istorija image-a (dockware koraci) | `docker image history logeecom-app --no-trunc` |
| ponovni build image-a | `docker compose up -d --build app` |
| backup baze | `docker exec logeecom_dev mysqldump -uroot -proot --single-transaction prestashop > prestashop-backup.sql` |
| zavisnosti plugin0 | `cd /home/veljkozbiljic/logeecom/plugin0 && composer install` |

---

## 20. Sta nije provereno

- Odakle je stigao `docker-compose.yml` (verovatno zajednicki sablon, po `Zone.Identifier` fajlovima uz kopije u
  `systems/`), i ko je i kako napravio kopije 2026-09-30 i 2026-10-02.
- Lozinka korisnika `dockware` za SSH.
- Da li je `192.168.1.28` trenutna IP adresa Windows-a za Xdebug.
- Da li ispisani dockware URL-ovi (`/adminer.php`, `/mailcatcher`, `/logs`) rade sa tvojim vhost-om.
- Da li Twig sabloni zahtevaju brisanje kesa u prod rezimu.
- Kako je baza `prestashop` napravljena pre instalacije (instaler ili rucno) i da li je instalacija isla kroz
  browser ili CLI.
- Tacna linija skripte paketa `mysql-server-8.0` koja pravi folder `/var/lib/mysql`.
- Da li `ActionDispatcherLegacyHooksSubscriber` okida hook za Symfony rute (navedeno po komentaru u
  `plugin0.php`).
- Da li ngrok salje `X-Forwarded-Proto: https` u tvom okruzenju (po dokumentaciji ngrok-a da).
- Ponasanje `docker compose stop` prema `mysqld` (SIGTERM → SIGKILL posle 10 s), po pravilima za PID 1.
- Zasto je kontejner pre 2026-10-13 bio u stanju `Exited (255)`.
