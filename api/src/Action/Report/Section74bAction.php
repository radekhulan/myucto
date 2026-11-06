<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Tax\BadDebt\Section74bService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * § 74b ZDPH — korekce odpočtu u neuhrazených závazků dlužníka (audit §2.5).
 *
 *   GET  /api/reports/s74b/preview?year=2026&month=5  — READ-ONLY dry-run náhled korekcí
 *   POST /api/reports/s74b/record  { year, month }    — VĚDOMÉ zaevidování období do ledgeru
 *
 * Náhled nic nezapisuje ani neúčtuje. Zaevidování zapíše pohyby korekce + auditní stopu
 * a teprve poté se korekce promítne do DPHDP3 (ř. 34 + ř. 40/41) a KH (B.2, zdph_44='P').
 *
 * ⚠️ Citlivý daňový výstup — pomůcka. Před podáním ověřit s účetní/poradcem.
 */
final class Section74bAction
{
    public function __construct(
        private readonly Section74bService $service,
    ) {}

    /** GET náhled (dry-run) — účetní|admin (reports READ). */
    public function preview(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        [$year, $month, $err] = $this->period($request);
        if ($err !== null) {
            return Json::error($response, 'validation_failed', $err, 400);
        }
        try {
            $result = $this->service->previewAging($supplierId, $year, $month);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'build_failed', $e->getMessage(), 500);
        }
        return Json::ok($response, $result);
    }

    /** POST zaevidování období — mění účetní evidenci, proto reports.finalize WRITE. */
    public function record(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.finalize', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0) ?: null;

        $body = (array) ($request->getParsedBody() ?? []);
        $year  = (int) ($body['year']  ?? 0);
        $month = (int) ($body['month'] ?? 0);
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }
        try {
            $result = $this->service->recordAging($supplierId, $year, $month, $userId);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'build_failed', $e->getMessage(), 500);
        }
        return Json::ok($response, $result);
    }

    /** @return array{0:int,1:int,2:?string} [year, month, error|null] */
    private function period(Request $request): array
    {
        $q = $request->getQueryParams();
        $year  = (int) ($q['year']  ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return [0, 0, 'Neplatný rok/měsíc.'];
        }
        return [$year, $month, null];
    }
}
