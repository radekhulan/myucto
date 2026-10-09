<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Tax\Vat\Section43Service;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * § 43 ZDPH — oprava výše daně v jiných případech (per doklad).
 *
 *   GET    /api/reports/s43?year=2026[&month=3]  — evidované opravy za období plnění
 *   POST   /api/reports/s43 { source_type, source_id, period_year, period_month, rate_kind,
 *                             base_delta, vat_delta, delivered_on, reason, … }
 *   DELETE /api/reports/s43/{id}
 *
 * Oprava patří ZPĚTNĚ do období PŮVODNÍHO plnění (na rozdíl od § 42, který jde do období
 * doručení opravného dokladu) a podává se v dodatečném přiznání za to období.
 *
 * ⚠️ Citlivý daňový výstup — pomůcka. Před podáním ověřit s účetní/poradcem.
 */
final class Section43Action
{
    public function __construct(
        private readonly Section43Service $service,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $q = $request->getQueryParams();
        $year = (int) ($q['year'] ?? 0);
        if ($year < 2000 || $year > 2100) {
            return Json::error($response, 'validation_failed', 'Neplatný rok.', 400);
        }
        $month = isset($q['month']) && $q['month'] !== '' ? (int) $q['month'] : null;
        if ($month !== null && ($month < 1 || $month > 12)) {
            return Json::error($response, 'validation_failed', 'Neplatný měsíc.', 400);
        }

        return Json::ok($response, [
            'year'  => $year,
            'month' => $month,
            'rows'  => $this->service->corrections(SupplierGuard::currentId($request), $year, $month),
        ]);
    }

    /** POST zaevidování opravy — mění daňovou evidenci, proto reports.finalize WRITE. */
    public function create(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.finalize', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0) ?: null;

        $body = (array) ($request->getParsedBody() ?? []);
        $deliveredOn = trim((string) ($body['delivered_on'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveredOn) !== 1) {
            return Json::error($response, 'validation_failed', 'delivered_on musí být Y-m-d.', 400);
        }

        try {
            $id = $this->service->register(
                $supplierId,
                (string) ($body['source_type'] ?? 'invoice'),
                (int) ($body['source_id'] ?? 0),
                (int) ($body['period_year'] ?? 0),
                (int) ($body['period_month'] ?? 0),
                (string) ($body['rate_kind'] ?? 'basic'),
                (float) ($body['base_delta'] ?? 0),
                (float) ($body['vat_delta'] ?? 0),
                $deliveredOn,
                (string) ($body['reason'] ?? ''),
                isset($body['corrective_doc_number']) && $body['corrective_doc_number'] !== ''
                    ? (string) $body['corrective_doc_number']
                    : null,
                $userId,
            );
        } catch (\InvalidArgumentException $e) {
            $code = match ($e->getCode()) {
                Section43Service::ERR_TAX_INCREASE   => 's43_tax_increase',
                Section43Service::ERR_REVERSE_CHARGE => 's43_reverse_charge',
                default                              => 'validation_failed',
            };
            return Json::error($response, $code, $e->getMessage(), 422);
        }

        return Json::ok($response, ['id' => $id]);
    }

    public function delete(Request $request, Response $response, array $args = []): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.finalize', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'validation_failed', 'Neplatné id.', 400);
        }

        $this->service->delete(SupplierGuard::currentId($request), $id);

        return Json::ok($response, ['deleted' => $id]);
    }
}
