<?php

namespace Plugin0\Controller\Admin;

use Plugin0\Bootstrap;
use Plugin0\Infrastructure\LogReader;
use Plugin0\Settings\SettingsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs stranica: zapisi koje je LoggerService upisao u ps_log, najnoviji prvi, sa filterom po nivou i straničenjem.
 * Filter i stranica su u URL-u (?level=error&page=2), pa se prikaz može osvežiti i podeliti.
 *
 * Kao u demo projektu, stranica postoji samo dok je prodavnica povezana; inače vodi na Configuration.
 */
class LogsController extends ConnectedController
{
    private const TEMPLATE = '@Modules/plugin0/views/templates/admin/logs.html.twig';

    public function indexAction(Request $request): Response
    {
        Bootstrap::boot();

        if (!$this->isConnected()) {
            return $this->redirectToRoute('plugin0_configuration');
        }

        // Nepoznat nivo u adresi znači "svi nivoi", a stranica manja od 1 znači prva.
        $level = strtoupper((string) $request->query->get('level', ''));
        if (!in_array($level, LogReader::LEVELS, true)) {
            $level = null;
        }
        $page = max(1, (int) $request->query->get('page', 1));

        // Veličina stranice je podešavanje "Rows per page" sa Settings stranice, kao u demu.
        $perPage = (new SettingsService())->rowsPerPage();

        $result = (new LogReader())->page($level, $page, $perPage);
        $total = $result['total'];
        $count = count($result['entries']);

        return $this->render(self::TEMPLATE, [
            'current_page' => 'logs',
            'connected' => true,
            'levels' => LogReader::LEVELS,
            'level' => $level,
            'entries' => $result['entries'],
            'pagination' => [
                'page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'total' => $total,
                'from' => $count > 0 ? ($page - 1) * $perPage + 1 : null,
                'to' => $count > 0 ? ($page - 1) * $perPage + $count : null,
            ],
        ]);
    }
}
