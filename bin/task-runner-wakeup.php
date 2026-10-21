<?php
/**
 * Cron ulaz koji budi Core task runner (docs/BACKGROUND.md, 4.1 i 5.2). Pokreće se iz komandne linije svakog minuta,
 * kao korisnik web servera, npr. red u crontab-u korisnika www-data:
 *
 *   * * * * * php /putanja/do/prodavnice/modules/plugin0/bin/task-runner-wakeup.php > /dev/null 2>&1
 *
 * Zove TaskRunnerWakeup::wakeup() (TaskRunnerWakeupService.php:51-75): kad je runner živ (javio se u poslednjih
 * 15 s), ne radi ništa; kad nije, upiše novi runner i pošalje asinhroni zahtev na controllers/front/asyncprocess.php,
 * odakle se Core-ov lanac dalje nastavlja sam. Cron tako samo ponovo pokreće prekinut lanac: posle restarta servera ili
 * PHP-FPM-a, ili kad se jedan asinhroni zahtev izgubi. Bez njega periodični zadaci (auto capture, čišćenje logova)
 * posle prvog prekida nikad više ne krenu, jer ih u red stavlja samo runner koji radi (docs/BACKGROUND.md, 1.6).
 *
 * Prodavnicu podiže config/config.inc.php: u komandnoj liniji postavlja podrazumevanu prodavnicu, jezik i Link, iz
 * kojeg Plugin0\Configuration::getAsyncProcessUrl pravi adresu. Ispisuje jedan red (vreme i šta je uradio), koji cron
 * obično baca.
 *
 * Fajl je u direktorijumu modula, pa je dostupan i preko weba; web zahtev odmah dobija 404 i ništa se ne učitava.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 3) . '/config/config.inc.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!Module::isEnabled('plugin0')) {
    echo date('c'), ' plugin0 is not installed or not enabled, nothing to wake up.', PHP_EOL;
    exit(0);
}

\Plugin0\Bootstrap::boot();

/** @var \WOP\OnlinePayments\Core\Infrastructure\TaskExecution\Interfaces\TaskRunnerStatusStorage $statusStorage */
$statusStorage = \WOP\OnlinePayments\Core\Infrastructure\ServiceRegister::getService(
    \WOP\OnlinePayments\Core\Infrastructure\TaskExecution\Interfaces\TaskRunnerStatusStorage::CLASS_NAME
);
$before = $statusStorage->getStatus();
$wasAlive = $before->getGuid() !== '' && !$before->isExpired();

\WOP\OnlinePayments\Core\Infrastructure\ServiceRegister::getService(
    \WOP\OnlinePayments\Core\Infrastructure\TaskExecution\Interfaces\TaskRunnerWakeup::CLASS_NAME
)->wakeup();

if ($wasAlive) {
    echo date('c'), ' task runner already active (', $before->getGuid(), '), nothing to do.', PHP_EOL;
    exit(0);
}

$after = $statusStorage->getStatus();
echo date('c'), ' task runner woken up (', $after->getGuid() !== '' ? $after->getGuid() : 'no active runner recorded, see Worldline > Logs', ').', PHP_EOL;
