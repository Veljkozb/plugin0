<?php

namespace Plugin0\Infrastructure;

use Db;
use DbQuery;

/**
 * Čita zapise koje je LoggerService upisao u PrestaShop log (tabela ps_log), za Logs stranicu.
 * Samo čita: ništa ne menja i ne briše.
 *
 * Format poruke je onaj iz LoggerService::logMessage(): "WORLDLINE [NIVO] Komponenta: poruka | {kontekst kao JSON}".
 */
class LogReader
{
    /** Nivoi koje LoggerService piše, isti redosled kao u core Logger-u: od najopširnijeg do najozbiljnijeg. */
    public const LEVELS = ['DEBUG', 'INFO', 'WARNING', 'ERROR'];

    /**
     * Jedna stranica zapisa, najnoviji prvi.
     *
     * @param string|null $level jedan od LEVELS, ili null za sve nivoe
     *
     * @return array{entries: array<int, array<string, mixed>>, total: int}
     */
    public function page(?string $level, int $page, int $perPage): array
    {
        $where = $this->whereLevel($level);

        $total = (int) Db::getInstance()->getValue(
            (new DbQuery())->select('COUNT(*)')->from('log')->where($where)
        );

        $rows = Db::getInstance()->executeS(
            (new DbQuery())
                ->select('id_log, date_add, message')
                ->from('log')
                ->where($where)
                ->orderBy('id_log DESC')
                ->limit($perPage, ($page - 1) * $perPage)
        );

        return [
            'entries' => array_map([$this, 'parse'], is_array($rows) ? $rows : []),
            'total' => $total,
        ];
    }

    private function whereLevel(?string $level): string
    {
        // Nivo je već proveren protiv LEVELS u kontroleru; pSQL je ovde druga linija zaštite.
        $pattern = LoggerService::PREFIX . ' [' . ($level === null ? '%' : $level . ']%');

        return "message LIKE '" . pSQL($pattern) . "'";
    }

    /**
     * @param array<string, string> $row
     *
     * @return array<string, mixed>
     */
    private function parse(array $row): array
    {
        $text = (string) $row['message'];
        $level = '';
        $component = '';
        $message = $text;
        $context = null;

        if (preg_match('/^' . preg_quote(LoggerService::PREFIX, '/') . ' \[([A-Z]+)] ([^:]*): (.*)$/s', $text, $match)) {
            $level = $match[1];
            $component = $match[2];
            $message = $match[3];
        }

        // Kontekst je JSON posle prvog " | {". Ako se ne može dekodirati, cela poruka ostaje kao tekst.
        $separator = strpos($message, ' | {');
        if ($separator !== false) {
            $decoded = json_decode(substr($message, $separator + 3), true);
            if (is_array($decoded)) {
                $context = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $message = substr($message, 0, $separator);
            }
        }

        return [
            'id' => (int) $row['id_log'],
            'time' => (string) $row['date_add'],
            'level' => $level,
            'component' => $component,
            'message' => $message,
            'context' => $context,
        ];
    }
}
