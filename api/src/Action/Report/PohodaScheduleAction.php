<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Export\ScheduledPohodaExportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Plánovaný export do Pohody (práva viz RoutePermissionMap: čtení a spuštění utilities.export,
 * uložení nastavení settings.company.write).
 *
 *   GET  /api/reports/pohoda-schedule       → nastavení, historie po měsících, poslední selhání
 *   PUT  /api/reports/pohoda-schedule       → uložení nastavení
 *   POST /api/reports/pohoda-schedule/run   → spustit teď (month=YYYY-MM uzavřeného měsíce, confirm_repeat=true při opakování)
 */
final class PohodaScheduleAction
{
    public function __construct(private readonly ScheduledPohodaExportService $service) {}

    public function get(Request $request, Response $response): Response
    {
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
        if ($result['status'] === 'exists') {
            return Json::error($response, 'already_exported', 'Tento měsíc už byl exportován. Potvrď opakování.', 409);
        }
        if ($result['status'] === 'busy') {
            return Json::error($response, 'busy', 'Export tohoto měsíce právě běží.', 409);
        }
        return Json::ok($response, $result);
    }
}
