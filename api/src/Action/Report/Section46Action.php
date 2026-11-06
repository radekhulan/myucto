<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Tax\BadDebt\Section46Service;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * § 46 až § 46g ZDPH — oprava základu daně u nedobytné pohledávky (věřitel).
 *
 *   GET  /api/reports/s46/candidates?as_of=2026-06-30   — READ-ONLY pracovní seznam pohledávek
 *   POST /api/reports/s46/correction { invoice_id, legal_ground, delivered_on, … }
 *                                                       — zaevidování opravy k dokladu
 *   GET  /api/reports/s46/restorations?year&month       — READ-ONLY náhled obnov po úhradě
 *   POST /api/reports/s46/restorations { year, month }  — zaevidování obnov (§ 46e)
 *
 * Na rozdíl od § 74b se oprava NEODVOZUJE: je právem věřitele a váže se na právní
 * skutečnost, kterou systém nevidí (insolvence, exekuce, smrt, likvidace) a na doručení
 * opravného daňového dokladu. Seznam kandidátů je proto jen pracovní pomůcka, ne nárok.
 * Obnova po úhradě už automatická je — plyne výhradně z úhrad, které systém eviduje.
 *
 * ⚠️ Citlivý daňový výstup — pomůcka. Před podáním ověřit s účetní/poradcem.
 */
final class Section46Action
{
    public function __construct(
        private readonly Section46Service $service,
    ) {}

    /** GET pracovní seznam pohledávek po splatnosti — účetní|admin (reports READ). */
    public function candidates(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $asOf = trim((string) ($q['as_of'] ?? date('Y-m-d')));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1) {
            return Json::error($response, 'validation_failed', 'Neplatné datum as_of (očekává se Y-m-d).', 400);
        }
        try {
            $rows = $this->service->previewCandidates($supplierId, $asOf);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'build_failed', $e->getMessage(), 500);
        }

        return Json::ok($response, ['as_of' => $asOf, 'rows' => $rows]);
    }

    /** POST zaevidování opravy — mění daňovou evidenci, proto reports.finalize WRITE. */
    public function correction(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.finalize', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0) ?: null;

        $body = (array) ($request->getParsedBody() ?? []);
        $invoiceId = (int) ($body['invoice_id'] ?? 0);
        $ground = trim((string) ($body['legal_ground'] ?? ''));
        $deliveredOn = trim((string) ($body['delivered_on'] ?? ''));

        if ($invoiceId <= 0) {
            return Json::error($response, 'validation_failed', 'Chybí invoice_id.', 400);
        }
        if (!in_array($ground, Section46Service::LEGAL_GROUNDS, true)) {
            return Json::error($response, 'validation_failed',
                'legal_ground musí být jedna z: ' . implode(', ', Section46Service::LEGAL_GROUNDS) . '.', 400);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveredOn) !== 1) {
            return Json::error($response, 'validation_failed',
                'delivered_on (datum doručení opravného dokladu, § 46f) je povinné ve tvaru Y-m-d.', 400);
        }

        try {
            $result = $this->service->registerCorrection(
                $supplierId,
                $invoiceId,
                $ground,
                $deliveredOn,
                self::nullableString($body['corrective_doc_number'] ?? null, 60),
                self::nullableString($body['note'] ?? null, 255),
                $userId,
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            // Nesplněná podmínka § 46 je chyba vstupu, ne selhání serveru.
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'build_failed', $e->getMessage(), 500);
        }

        return Json::ok($response, $result);
    }

    /** GET náhled obnov po úhradě (§ 46e) — READ-ONLY. */
    public function restorationsPreview(Request $request, Response $response): Response
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
            $result = $this->service->previewRestorations($supplierId, $year, $month);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'build_failed', $e->getMessage(), 500);
        }

        return Json::ok($response, $result);
    }

    /** POST zaevidování obnov za období. */
    public function restorationsRecord(Request $request, Response $response): Response
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
            $result = $this->service->recordRestorations($supplierId, $year, $month, $userId);
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

    private static function nullableString(mixed $value, int $maxLen): ?string
    {
        $s = trim((string) ($value ?? ''));

        return $s === '' ? null : mb_substr($s, 0, $maxLen);
    }
}
