<?php

namespace Plugin0\Settings;

use Plugin0\Configuration;
use Plugin0\Infrastructure\LoggerService;
use Plugin0\Infrastructure\ShopCountriesAndCurrencies;
use Plugin0\PaymentMethods\PaymentMethodRestrictionRepository;
use Throwable;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;

/**
 * Pravila Settings stranice, prenesena iz dema (src/Settings/Service/SettingsService.php): svako podešavanje
 * ima default, red koji nedostaje ili je pokvaren nikad ne obara plugin nego važi default, Restore defaults
 * vraća sve na default, a svaka promena ostavlja zapis u logu.
 *
 * Vrednosti se čuvaju kao Core ConfigEntity redovi u tabeli plugin0_entity, kroz Plugin0\Configuration:
 * ključ "minLogLevel" (Core-ov sopstveni ključ, vidi docs/CORE.md 4.4), pa ključevi "defaultCurrency" i
 * "rowsPerPage" (samo plugin0). Sva tri su kontekstno specifični ključevi, a kontekst je ID tenanta, koji je u
 * plugin0 uvek prazan string: podešavanja važe za celu PrestaShop instalaciju, ne po prodavnici.
 *
 * Default currency je veza Settings i Payment methods stranice: svaka metoda plaćanja se nudi u njoj. Metoda bez
 * sopstvene liste valuta nudi se samo u njoj (PaymentMethodsService), a kad se podešavanje promeni, nova valuta se
 * dodaje u listu svake metode koja listu ima (spreadDefaultCurrency); stara ostaje u tim listama.
 *
 * Defaulti žive ovde i nigde drugde. Liste pitaju rowsPerPage() i nemaju sopstveni broj.
 */
final class SettingsService
{
    public const DEFAULT_ROWS_PER_PAGE = 10;

    /** Komponenta zapisa u logu (kolona Message na Logs stranici počinje sa "Settings:"). */
    private const COMPONENT = 'Settings';

    /** Podešavanja na snazi: sačuvana vrednost koja je i dalje ispravna, inače default. */
    public function current(): Settings
    {
        return new Settings($this->logLevel(), $this->defaultCurrency(), $this->rowsPerPage());
    }

    public function defaults(): Settings
    {
        return new Settings($this->defaultLogLevel(), $this->shopDefaultCurrency(), self::DEFAULT_ROWS_PER_PAGE);
    }

    /**
     * Default kao u demu: Debug dok je prodavnica u dev režimu (_PS_MODE_DEV_ u config/defines.inc.php), Info inače.
     * Core-ov sopstveni default za isti ključ je uvek Info (Configuration::MIN_LOG_LEVEL = 2), ali Core tu vrednost
     * koristi samo za svoj default logger, koji plugin0 ne uključuje.
     */
    public function defaultLogLevel(): int
    {
        return defined('_PS_MODE_DEV_') && _PS_MODE_DEV_ ? Logger::DEBUG : Logger::INFO;
    }

    /** Najniži nivo koji se upisuje. LoggerService ga pita jednom po procesu, pre prvog zapisa. */
    public function logLevel(): int
    {
        $stored = $this->stored(Configuration::KEY_MIN_LOG_LEVEL);

        return LogLevel::isValid($stored) ? $stored : $this->defaultLogLevel();
    }

    /**
     * Default podešavanja "Default currency": podrazumevana valuta prodavnice u PrestaShop-u. Demo tu nema default
     * (polje prazno dok admin ne izabere), ali ovde svaka metoda plaćanja mora da ima valutu i pre prve posete
     * Settings stranici, pa default mora da postoji.
     */
    public function shopDefaultCurrency(): string
    {
        return ShopCountriesAndCurrencies::defaultCurrency();
    }

    /**
     * Valuta u kojoj se nudi svaka metoda plaćanja. Sačuvana vrednost važi dok je prodavnica ima uključenu;
     * valuta koju je admin posle isključio u PrestaShop-u ne može biti ponuđena, pa tada važi default.
     */
    public function defaultCurrency(): string
    {
        $stored = $this->stored(Configuration::KEY_DEFAULT_CURRENCY);

        return $this->isKnownCurrency($stored) ? $stored : $this->shopDefaultCurrency();
    }

    /**
     * Valute koje Default currency sme da bude: uključene u prodavnici, kod => naziv, po kodu.
     *
     * @return array<string, string>
     */
    public function currencies(): array
    {
        return ShopCountriesAndCurrencies::currencies();
    }

    /** Veličina stranice svake liste: Logs i Payment methods. */
    public function rowsPerPage(): int
    {
        $stored = $this->stored(Configuration::KEY_ROWS_PER_PAGE);

        return $this->isValidRows($stored) ? (int) $stored : self::DEFAULT_ROWS_PER_PAGE;
    }

    /**
     * Opcije padajućeg menija nivoa, demo redosledom, svaka sa tekstom efekta.
     *
     * @return array<int, array{value: string, label: string, effect: string}>
     */
    public function logLevels(): array
    {
        return array_map(static function (int $level): array {
            return [
                'value' => LogLevel::name($level),
                'label' => LogLevel::label($level),
                'effect' => LogLevel::effect($level),
            ];
        }, LogLevel::all());
    }

    /**
     * Upisuje vrednosti koje se razlikuju od onih na snazi i za svaku ostavlja po jedan zapis.
     * Promena nivoa logovanja se piše na WARNING, sve ostalo na INFO: nivo odlučuje šta se od tada upisuje,
     * pa njegovo spuštanje ne sme da prođe nezapaženo.
     *
     * Kad se promenila Default currency, posle upisa se nova valuta dodaje u liste metoda plaćanja.
     *
     * @return Settings podešavanja na snazi posle upisa, ponovo pročitana iz baze
     *
     * @throws DefaultCurrencySpreadFailed podešavanja su upisana, ali valuta nije dodata metodama
     */
    public function save(Settings $settings): Settings
    {
        $before = $this->current();
        $changes = $this->changes($before, $settings);
        if ($changes === []) {
            return $before;
        }

        $this->writeUnderBothLevels(
            $before,
            $settings,
            function () use ($changes): void {
                foreach ($changes as $key => $change) {
                    $this->log(
                        $key === 'log_level' ? Logger::WARNING : Logger::INFO,
                        'Setting changed',
                        ['key' => $key] + $change
                    );
                }
            },
            static function () use ($before, $settings): void {
                $configuration = Configuration::getInstance();
                if ($settings->getLogLevel() !== $before->getLogLevel()) {
                    $configuration->saveMinLogLevel($settings->getLogLevel());
                }
                if ($settings->getDefaultCurrency() !== $before->getDefaultCurrency()) {
                    $configuration->storeValue(Configuration::KEY_DEFAULT_CURRENCY, $settings->getDefaultCurrency());
                }
                if ($settings->getRowsPerPage() !== $before->getRowsPerPage()) {
                    $configuration->storeValue(Configuration::KEY_ROWS_PER_PAGE, $settings->getRowsPerPage());
                }
            }
        );

        if (isset($changes['default_currency'])) {
            $this->spreadDefaultCurrency($settings->getDefaultCurrency());
        }

        return $this->current();
    }

    /**
     * Vraća sva podešavanja na default tako što briše sačuvane redove, i ostavlja jedan zapis sa onim što se
     * time promenilo. Kad je sve već bilo na defaultu, ne piše ništa. Kad se time promenila Default currency,
     * podrazumevana valuta prodavnice se dodaje u liste metoda plaćanja, isto kao pri čuvanju.
     *
     * @return Settings podešavanja na snazi posle brisanja, ponovo pročitana iz baze
     *
     * @throws DefaultCurrencySpreadFailed redovi su obrisani, ali valuta nije dodata metodama
     */
    public function restoreDefaults(): Settings
    {
        $before = $this->current();
        $after = $this->defaults();
        $changes = $this->changes($before, $after);

        $this->writeUnderBothLevels(
            $before,
            $after,
            function () use ($changes): void {
                if ($changes === []) {
                    return;
                }
                $this->log(
                    isset($changes['log_level']) ? Logger::WARNING : Logger::INFO,
                    'Settings restored to defaults',
                    ['changes' => $changes]
                );
            },
            static function (): void {
                $configuration = Configuration::getInstance();
                $configuration->removeValue(Configuration::KEY_MIN_LOG_LEVEL);
                $configuration->removeValue(Configuration::KEY_DEFAULT_CURRENCY);
                $configuration->removeValue(Configuration::KEY_ROWS_PER_PAGE);
            }
        );

        if (isset($changes['default_currency'])) {
            $this->spreadDefaultCurrency($after->getDefaultCurrency());
        }

        return $this->current();
    }

    /**
     * Dodaje valutu u listu valuta svake metode plaćanja koja listu ima, u svim prodavnicama i oba okruženja
     * (test i live), jer podešavanje važi za celu instalaciju. Metode bez liste se ne diraju: one ionako prate
     * Default currency. Zove se tek pošto je podešavanje upisano i novi nivo logovanja na snazi.
     *
     * @throws DefaultCurrencySpreadFailed
     */
    private function spreadDefaultCurrency(string $currency): void
    {
        try {
            $count = PaymentMethodRestrictionRepository::addCurrencyEverywhere($currency);
        } catch (Throwable $e) {
            $this->log(Logger::WARNING, 'Default currency could not be added to payment methods', [
                'currency' => $currency,
                'error' => $e->getMessage(),
            ]);

            throw new DefaultCurrencySpreadFailed(sprintf(
                'The default currency %s was saved, but it could not be added to the currencies of the payment methods. %s',
                $currency,
                $e->getMessage()
            ), 0, $e);
        }

        if ($count > 0) {
            $this->log(Logger::INFO, 'Default currency added to payment methods', [
                'currency' => $currency,
                'methods' => $count,
            ]);
        }
    }

    /**
     * Zapis o promeni mora da prođe i kad nivo ide ka strožem i kad ide ka opširnijem. Zato se piše pod
     * opširnijim od dva nivoa: ako je stari opširniji, prvo zapis pa upis; inače prvo upis pa zapis.
     * LoggerService kešira nivo po procesu, pa se keš posle upisa briše da bi novi nivo odmah važio.
     */
    private function writeUnderBothLevels(Settings $before, Settings $after, callable $log, callable $write): void
    {
        $logFirst = $before->getLogLevel() > $after->getLogLevel();

        if ($logFirst) {
            $log();
        }

        $write();
        LoggerService::forgetMinLogLevel();

        if (!$logFirst) {
            $log();
        }
    }

    /**
     * Sirova sačuvana vrednost ključa. Red koji se ne može pročitati važi kao da ga nema: default umesto greške.
     *
     * @return mixed null kad reda nema
     */
    private function stored(string $name)
    {
        try {
            return Configuration::getInstance()->getStoredValue($name);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @param mixed $rows sačuvana vrednost, bilo kog tipa */
    private function isValidRows($rows): bool
    {
        if (!is_int($rows) && !(is_string($rows) && ctype_digit($rows))) {
            return false;
        }

        return (int) $rows >= Settings::MIN_ROWS_PER_PAGE && (int) $rows <= Settings::MAX_ROWS_PER_PAGE;
    }

    /** @param mixed $code sačuvana vrednost, bilo kog tipa; poznata je kad je uključena u prodavnici */
    private function isKnownCurrency($code): bool
    {
        return Settings::isCurrencyCode($code) && isset($this->currencies()[$code]);
    }

    /**
     * Šta se razlikuje između dva skupa podešavanja, ključ po ključ. Nijedna vrednost nije tajna, pa zapis nosi
     * i staru i novu.
     *
     * @return array<string, array{from: string|int, to: string|int}> samo promenjeni ključevi
     */
    private function changes(Settings $before, Settings $after): array
    {
        $changes = [];
        $to = $after->toArray();
        foreach ($before->toArray() as $key => $from) {
            if ($from !== $to[$key]) {
                $changes[$key] = ['from' => $from, 'to' => $to[$key]];
            }
        }

        return $changes;
    }

    /** @param array<string, mixed> $context */
    private function log(int $level, string $message, array $context): void
    {
        if ($level === Logger::WARNING) {
            Logger::logWarning($message, self::COMPONENT, $context);

            return;
        }

        Logger::logInfo($message, self::COMPONENT, $context);
    }
}
