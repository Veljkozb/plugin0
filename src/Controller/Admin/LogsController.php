<?php

namespace Plugin0\Controller\Admin;

use Plugin0\Bootstrap;
use Plugin0\Infrastructure\LogReader;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;

/**
 * Logs stranica: zapisi koje je LoggerService upisao u ps_log, najnoviji prvi, sa filterom po nivou i straničenjem.
 * Filter i stranica su u URL-u (?level=error&page=2), pa se prikaz može osvežiti i podeliti.
 *
 * Kao u demo projektu, stranica postoji samo dok je prodavnica povezana; inače vodi na Configuration.
 */
class LogsController extends FrameworkBundleAdminController
{
    private const TEMPLATE = '@Modules/plugin0/views/templates/admin/logs.html.twig';

    /** Demo uzima veličinu stranice iz podešavanja "Rows per page"; plugin0 još nema stranicu Settings. */
    private const PER_PAGE = 20;

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

        $result = (new LogReader())->page($level, $page, self::PER_PAGE);
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
                'last_page' => max(1, (int) ceil($total / self::PER_PAGE)),
                'total' => $total,
                'from' => $count > 0 ? ($page - 1) * self::PER_PAGE + 1 : null,
                'to' => $count > 0 ? ($page - 1) * self::PER_PAGE + $count : null,
            ],
        ]);
    }

    private function isConnected(): bool
    {
        $response = AdminAPI::get()->connection((string) $this->getContext()->shop->id)->getConnectionConfig();

        return $response->isSuccessful() && isset($response->toArray()['mode']);
    }
}
