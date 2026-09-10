<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Cash;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\Deletion\CashDocumentDeletionGuard;
use MyInvoice\Repository\Deletion\ForeignKeyDeletionGuard;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashException;
use MyInvoice\Service\Accounting\Cash\CashRulePresets;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\CashDocumentPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Pokladní doklady PPD/VPD — REST API (mini-epic POKLADNA #14).
 *
 *   GET    /api/accounting/cash-documents             — stránkovaný seznam (filtry)
 *   POST   /api/accounting/cash-documents             — nový doklad (default post:true)
 *   GET    /api/accounting/cash-documents/unpaid      — našeptávač nezaplacených FV/PF
 *   GET    /api/accounting/cash-documents/rule-presets— nabídka kontací pro purpose=other
 *   GET    /api/accounting/cash-documents/{id}        — detail
 *   PUT    /api/accounting/cash-documents/{id}        — úprava draftu
 *   DELETE /api/accounting/cash-documents/{id}        — smazání draftu
 *   POST   /api/accounting/cash-documents/{id}/post   — zaúčtování draftu
 *   POST   /api/accounting/cash-documents/{id}/reverse— storno (povinný reason)
 *   GET    /api/accounting/cash-documents/{id}/pdf    — tisk dokladu (PDF stage)
 */
final class CashDocumentAction
{
    use AccountingActionSupport;

    private const MAX_PER_PAGE = 200;

    public function __construct(
        private readonly CashDocumentService $service,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly CashDocumentPdfRenderer $pdfRenderer,
        private readonly CashRulePresets $rulePresets,
        // Registr cizích vazeb, které smazání blokují — bez něj končila
        // `payroll_payment_matches.cash_document_id` (RESTRICT) jako HTTP 500.
        private readonly CashDocumentDeletionGuard $deletionGuard,
        private readonly \MyInvoice\Service\Logbook\CashFuelingService $cashFuelings,
        private readonly \MyInvoice\Service\Document\AttachmentCheck\AttachmentCheckService $attachmentChecks,
    ) {}

    /**
     * Zaúčtovaná účtenka za PHM → tankování v knize jízd (evidenční vrstva, nic neúčtuje).
     * Selhání nesmí shodit pokladní doklad — ten už je zaúčtovaný.
     */
    private function autoFueling(int $supplierId, int $id, ?int $userId): void
    {
        try {
            $this->cashFuelings->autoFromCashDocument($supplierId, $id, $userId);
        } catch (\Throwable) {
        }
    }

    /**
     * GET /api/accounting/cash-documents/rule-presets?doc_type=in|out
     * Nabídka „co to je" pro purpose=other — FE pošle zvolený `rule_key` (ne protiúčet),
     * takže si doklad zachová vazbu na kontaci včetně případného per-tenant override.
     */
    public function rulePresets(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $docType = $request->getQueryParams()['doc_type'] ?? null;
        if ($docType !== 'in' && $docType !== 'out') {
            $docType = null;
        }
        return Json::ok($response, ['items' => $this->rulePresets->listForOther($supplierId, $docType)]);
    }

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $q = $request->getQueryParams();

        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($q['per_page'] ?? 50)));

        $filters = [];
        foreach (['register_id', 'doc_type', 'purpose', 'status', 'from', 'to', 'q'] as $key) {
            if (isset($q[$key]) && $q[$key] !== '') {
                $filters[$key] = $key === 'register_id' ? (int) $q[$key] : (string) $q[$key];
            }
        }
        return Json::ok($response, $this->service->listDocuments($supplierId, $filters, $page, $perPage));
    }

    public function unpaid(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $q = $request->getQueryParams();
        try {
            $result = $this->service->searchUnpaid(
                $supplierId,
                (string) ($q['kind'] ?? ''),
                (string) ($q['q'] ?? ''),
                (int) ($q['limit'] ?? 20),
                // Vratka úhrady: nabídnout faktury, na kterých už úhrada visí.
                ((string) ($q['refundable'] ?? '')) === '1',
            );
            return Json::ok($response, $result);
        } catch (\Throwable $e) {
            return $this->mapCashError($response, $e);
        }
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        try {
            return Json::ok($response, $this->service->get($supplierId, (int) $args['id']));
        } catch (\Throwable $e) {
            return $this->mapCashError($response, $e);
        }
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireCashDocumentWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $result = $this->service->create($supplierId, $body, $this->userId($request));
            $this->log($request, $result['status'] === 'posted' ? 'cash.document_posted' : 'cash.document_created',
                (int) $result['id'], ['doc_number' => $result['doc_number']]);
            if ($result['status'] === 'posted') {
                $this->autoFueling($supplierId, (int) $result['id'], $this->userId($request));
            }
            return Json::ok($response, $result, 201);
        } catch (\Throwable $e) {
            return $this->mapCashError($response, $e);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireCashDocumentWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        $id = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $this->service->updateDraft($supplierId, $id, $body);
            $this->log($request, 'cash.document_updated', $id, []);
            try {
                $this->attachmentChecks->recheckEntity($supplierId, 'cash_document', $id);
            } catch (\Throwable) {
            }
            return Json::ok($response, $this->service->get($supplierId, $id));
        } catch (\Throwable $e) {
            return $this->mapCashError($response, $e);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireCashDocumentWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        $id = (int) $args['id'];
        // ?force=1 → tvrdé smazání dokladu i s účetními zápisy (jinak jen draft).
        $force = ((string) (($request->getQueryParams()['force'] ?? '')) === '1');
        // H-4: tvrdé smazání maže i deníkové zápisy (vč. protizápisu) a v číselné řadě
        // po sobě nechá trvalou díru (§ 11 ZoÚ) — na běžné zápisové právo to patřit nemůže.
        // Vyžaduje proto samostatné `cash.close`, obdobně jako ruční deník `accounting.journal.post`.
        if ($force && !$this->requireCashClose($request, $response, $err)) {
            return $err;
        }

        // Kontrola PŘEDEM — obě větve, protože rozhodovat musí existence vazby,
        // ne stav dokladu. Scopovaná na tenanta, aby cizí id skončilo na 404
        // z ověření níž, ne na 409 (to by prozradilo, že doklad existuje).
        $conflict = $this->deletionGuard->conflict($supplierId, $id);
        if ($conflict !== null) {
            return Json::error($response, 'cash.error.has_dependencies', $conflict->message, 409,
                $conflict->toErrorExtra());
        }

        try {
            $payload = ['deleted' => true, 'warnings' => []];
            if ($force) {
                $result = $this->service->deleteDocument($supplierId, $id);
                $this->log($request, 'cash.document_deleted', $id, [
                    'hard'              => true,
                    'deleted_entry_ids' => $result['deleted_entry_ids'],
                    'doc_number'        => $result['doc_number'],
                ]);
                // Číslo řady se nevrací → v PPD/VPD zůstane díra. Uživatel to musí vidět.
                // Draft ale číslo nikdy nedostal (`doc_number === null`), takže po něm
                // žádná díra nezůstane — varovat u něj by protiřečilo hlášce
                // `cash.delete.draft_warning`, kterou u téhož dokladu ukazuje UI.
                if ($result['doc_number'] !== null) {
                    $payload['warnings'][] = 'cash.warning.series_gap';
                }
                $payload['doc_number'] = $result['doc_number'];
                $payload['deleted_entry_ids'] = $result['deleted_entry_ids'];
            } else {
                $this->service->deleteDraft($supplierId, $id);
                $this->log($request, 'cash.document_deleted', $id, []);
            }
            return Json::ok($response, $payload);
        } catch (\PDOException $e) {
            // Druhá půlka pojistky: vazba vznikla mezi kontrolou a mazáním, nebo
            // ukazuje z tabulky, která v registru chybí. `mapCashError()` by
            // netypovanou výjimku přehodil dál a uživatel by dostal HTTP 500.
            if (!ForeignKeyDeletionGuard::isForeignKeyViolation($e)) {
                throw $e;
            }
            return Json::error($response, 'cash.error.has_dependencies',
                CashDocumentDeletionGuard::raceMessage(), 409);
        } catch (\Throwable $e) {
            return $this->mapCashError($response, $e);
        }
    }

    public function post(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireCashDocumentWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        $id = (int) $args['id'];
        try {
            $result = $this->service->post($supplierId, $id, $this->userId($request));
            $this->log($request, 'cash.document_posted', $id, ['doc_number' => $result['doc_number']]);
            $this->autoFueling($supplierId, $id, $this->userId($request));
            return Json::ok($response, $result);
        } catch (\Throwable $e) {
            return $this->mapCashError($response, $e);
        }
    }

    public function reverse(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireCashDocumentWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        $id = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $result = $this->service->reverse($supplierId, $id, [
                'reason'     => (string) ($body['reason'] ?? ''),
                'entry_date' => $body['entry_date'] ?? null,
            ], $this->userId($request));
            $this->log($request, 'cash.document_reversed', $id, ['reversal_entry_id' => $result['reversal_entry_id']]);
            return Json::ok($response, $result);
        } catch (\Throwable $e) {
            return $this->mapCashError($response, $e);
        }
    }

    /** PDF tisk pokladního dokladu (§5.5) — náležitosti §11 ZoÚ, jen posted/reversed. */
    public function pdf(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $id = (int) $args['id'];
        try {
            $data = $this->service->pdfData($supplierId, $id);
            $bytes = $this->pdfRenderer->render($data);
            $isIn = ($data['document']['doc_type'] ?? 'in') === 'in';
            $filename = ($isIn ? 'PPD' : 'VPD') . '-' . (string) ($data['document']['doc_number'] ?? $id) . '.pdf';
            $filename = str_replace(['/', '\\', ' '], '-', $filename);

            $response->getBody()->write($bytes);
            return $response
                ->withHeader('Content-Type', 'application/pdf')
                ->withHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
                ->withHeader('Content-Length', (string) strlen($bytes))
                ->withHeader('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return $this->mapCashError($response, $e);
        }
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'cash_document',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }

}
