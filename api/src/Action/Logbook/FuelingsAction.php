<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Http\Json;
use MyInvoice\Http\ReferenceId;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\CarRepository;
use MyInvoice\Repository\FuelingRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Logbook\FuelingLinkCandidates;
use MyInvoice\Service\Logbook\FuelingOdometerEstimator;
use MyInvoice\Service\Logbook\FuelingOdometerWarnings;
use MyInvoice\Support\Pagination;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Tankování:
 *   GET    /api/logbook/fuelings      — list (?car_id=&source=&vendor_id=&year=&month=&date_from=&date_to=&unassigned=1&page=&per_page=)
 *   GET    /api/logbook/fuelings/{id}
 *   POST   /api/logbook/fuelings      — ruční záznam
 *   PUT    /api/logbook/fuelings/{id}
 *   DELETE /api/logbook/fuelings/{id}
 *   GET    /api/logbook/fuelings/warnings        — chybějící / nesouvislý tachometr, nesoulad odpočtu DPH
 *   GET    /api/logbook/fuelings/link-candidates — doklady k navázání (?type=&date=&amount=&q=)
 */
final class FuelingsAction
{
    /** Vazby na doklad, kterým bylo tankování zaplaceno (migrace 1801). */
    private const LINK_COLUMNS = ['source_purchase_invoice_id', 'source_cash_document_id', 'source_bank_transaction_id', 'source_journal_entry_id'];

    public function __construct(
        private readonly FuelingRepository $repo,
        private readonly CarRepository $cars,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly FuelingOdometerEstimator $odometer,
        private readonly TenantReferenceGuard $tenantRefs,
        private readonly \MyInvoice\Service\Stock\StockReferenceGuard $stockRefs,
        private readonly FuelingOdometerWarnings $warnings,
        private readonly FuelingLinkCandidates $linkCandidates,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $filters = array_intersect_key($q, array_flip(['car_id', 'source', 'vendor_id', 'year', 'month', 'date_from', 'date_to', 'unassigned']));
        $p = Pagination::fromQuery($q, 50);
        [$rows, $total] = $this->repo->listPaged($supplierId, $filters, $p['per_page'], $p['offset']);
        $rows = $this->odometer->annotate($supplierId, $rows);
        $rows = $this->warnings->annotate($supplierId, $rows);
        $carId = !empty($filters['car_id']) ? (int) $filters['car_id'] : null;
        $years = $this->repo->distinctYears($supplierId, $carId);
        return Json::ok($response, array_merge(
            Pagination::envelope($rows, $total, $p['page'], $p['per_page']),
            ['years' => $years]
        ));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $f = $this->repo->find((int) ($args['id'] ?? 0), $supplierId);
        if ($f === null) return Json::error($response, 'not_found', 'Tankování nenalezeno.', 404);
        $rows = $this->warnings->annotate($supplierId, $this->odometer->annotate($supplierId, [$f]));
        return Json::ok($response, $rows[0]);
    }

    public function warnings(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $carId = !empty($q['car_id']) ? (int) $q['car_id'] : null;
        $year = !empty($q['year']) ? (int) $q['year'] : null;
        return Json::ok($response, $this->warnings->forTenant($supplierId, $carId, $year));
    }

    public function linkCandidates(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $amount = isset($q['amount']) && is_numeric($q['amount']) ? (float) $q['amount'] : null;
        return Json::ok($response, $this->linkCandidates->find(
            $supplierId,
            (string) ($q['type'] ?? ''),
            (string) ($q['date'] ?? ''),
            $amount,
            (string) ($q['q'] ?? ''),
        ));
    }

    public function create(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $err = $this->validate($supplierId, $body);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        if ($ref = $this->tenantRefError($supplierId, $body)) {
            return Json::error($response, 'invalid_reference', $ref, 400);
        }
        $body['source'] = 'manual';
        $id = $this->repo->create($supplierId, $body, $this->userId($request));
        $this->log($request, 'fueling.created', $id, $body);
        return Json::ok($response, $this->repo->find($id, $supplierId), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Tankování nenalezeno.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $err = $this->validate($supplierId, $body);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        if ($ref = $this->tenantRefError($supplierId, $body)) {
            return Json::error($response, 'invalid_reference', $ref, 400);
        }
        $this->repo->update($id, $supplierId, $body);
        // Vazby na doklad mění jen klíče, které klient poslal — ruční úprava údajů
        // tankování (bez vazeb v těle) provenienci nepřepisuje.
        $this->repo->setLinks($id, $supplierId, array_intersect_key($body, array_flip(self::LINK_COLUMNS)));
        $this->log($request, 'fueling.updated', $id, $body);
        return Json::ok($response, $this->repo->find($id, $supplierId));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Tankování nenalezeno.', 404);
        }
        $this->repo->delete($id, $supplierId);
        $this->log($request, 'fueling.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    private function validate(int $supplierId, array $body): ?string
    {
        $date = trim((string) ($body['fueled_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return 'Neplatné datum tankování (YYYY-MM-DD).';
        if (!isset($body['amount_with_vat']) || (float) $body['amount_with_vat'] <= 0) {
            return 'Celková částka musí být kladná.';
        }
        $carId = $this->intOrNull($body['car_id'] ?? null);
        if ($carId !== null && $this->cars->find($carId, $supplierId) === null) {
            return 'Auto neexistuje.';
        }
        return null;
    }

    /**
     * BOLA guard (security report 2026-08, R2 #6 / sweep F1) — car_id se ve validate()
     * kontroluje odjakživa, vendor_id a source_purchase_invoice_id se zapisovaly
     * nevázané a FuelingRepository::find() je čte přes JOIN bez tenant predikátu.
     *
     * Vazby na pokladní doklad a účetní zápis jdou guardem; bankovní pohyb `supplier_id`
     * nemá, jeho vlastníka určuje výpis (FuelingRepository::bankTransactionBelongs()).
     * Obojí navíc drží trigger z migrace 1801.
     */
    private function tenantRefError(int $supplierId, array $body): ?string
    {
        $badRefs = $this->tenantRefs->violations(
            $supplierId,
            $body,
            ['vendor_id', 'source_purchase_invoice_id', 'source_cash_document_id', 'source_journal_entry_id'],
        );

        $itemRefs = $this->stockRefs->violations(
            $supplierId, ['purchase_invoice_item_id' => [$body['source_item_id'] ?? null]],
        );
        if ($itemRefs !== []) {
            $badRefs[] = 'source_item_id';
        }

        try {
            $bankTx = ReferenceId::optional($body['source_bank_transaction_id'] ?? null);
            if ($bankTx !== null && !$this->repo->bankTransactionBelongs($supplierId, $bankTx)) {
                $badRefs[] = 'source_bank_transaction_id';
            }
        } catch (\InvalidArgumentException) {
            $badRefs[] = 'source_bank_transaction_id';
        }
        return $badRefs !== [] ? TenantReferenceGuard::message($badRefs) : null;
    }

    private function intOrNull(mixed $v): ?int
    {
        return ($v === null || $v === '') ? null : (int) $v;
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0) ?: null;
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $this->userId($request), 'fueling', $id, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
