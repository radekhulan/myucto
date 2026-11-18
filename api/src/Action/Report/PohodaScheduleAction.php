<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Export\ScheduledPohodaExportFeature;
use MyInvoice\Service\Export\ScheduledPohodaExportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Plánovaný export do Pohody (práva viz RoutePermissionMap: čtení a spuštění utilities.export,
 * uložení nastavení settings.company.write).
 *
 *   GET  /api/reports/pohoda-schedule       → nastavení, historie po měsících, poslední selhání
 *   PUT  /api/reports/pohoda-schedule       → uložení nastavení
 *   POST /api/reports/pohoda-schedule/run   → spustit teď (month=YYYY-MM uzavřeného měsíce, confirm_repeat=true při opakování;
 *                                            409 already_exported a 429 too_soon nesou `last`: kdy a komu se posílalo)
 *
 * Bez zapnuté volby instalace (ScheduledPohodaExportFeature) vrací všechny tři 404 scheduled_pohoda_export_disabled.
 */
final class PohodaScheduleAction
{
    public function __construct(
        private readonly ScheduledPohodaExportService $service,
        private readonly ScheduledPohodaExportFeature $feature,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        if (!$this->feature->isEnabled()) {
            return self::disabled($response);
        }
        $sid = SupplierGuard::currentId($request);
        $config = $this->service->config($sid);
        $history = $this->service->history($sid);
        // Příjemce a texty chyb ukazujeme jen tomu, kdo smí číst nastavení firmy.
        if (!RequestAuthorization::allows($request, 'settings.company', AccessLevel::READ)) {
            $config['recipient_email'] = '';
            foreach ($history['runs'] as &$r) {
                $r['recipient_email'] = '';
                $r['error'] = null;
            }
            unset($r);
            if ($history['problem'] !== null) {
                $history['problem']['error'] = null;
            }
        }
        return Json::ok($response, ['config' => $config] + $history);
    }

    public function save(Request $request, Response $response): Response
    {
        if (!$this->feature->isEnabled()) {
            return self::disabled($response);
        }
        $sid = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        try {
            $this->service->saveConfig($sid, (array) ($request->getParsedBody() ?? []), isset($user['id']) ? (int) $user['id'] : null);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        return Json::ok($response, ['config' => $this->service->config($sid)]);
    }

    public function run(Request $request, Response $response): Response
    {
        if (!$this->feature->isEnabled()) {
            return self::disabled($response);
        }
        $sid = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $month = (string) ($body['month'] ?? '');
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return Json::error($response, 'validation_failed', 'Měsíc musí být ve tvaru RRRR-MM.', 422);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        try {
            $result = $this->service->run($sid, $month, 'manual', ($body['confirm_repeat'] ?? false) === true, isset($user['id']) ? (int) $user['id'] : null);
        } catch (\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        if (isset($result['last']) && !RequestAuthorization::allows($request, 'settings.company', AccessLevel::READ)) {
            $result['last']['recipient_email'] = '';
        }
        if ($result['status'] === 'exists') {
            return Json::error($response, 'already_exported', 'Tento měsíc už byl exportován. Potvrď opakování.', 409, ['last' => $result['last']]);
        }
        if ($result['status'] === 'too_soon') {
            return Json::error($response, 'too_soon', sprintf('Tento měsíc se odeslal před chvílí. Znovu ho lze poslat za %d minut od posledního odeslání.', ScheduledPohodaExportService::REPEAT_COOLDOWN_MINUTES), 429, ['last' => $result['last']]);
        }
        if ($result['status'] === 'busy') {
            return Json::error($response, 'busy', 'Export této firmy právě běží.', 409);
        }
        return Json::ok($response, $result);
    }

    /** Volba instalace je vypnutá: funkce navenek neexistuje, uložená nastavení firem zůstávají. */
    private static function disabled(Response $response): Response
    {
        return Json::error($response, 'scheduled_pohoda_export_disabled', 'Automatický export do Pohody není na této instalaci zapnutý.', 404);
    }
}
