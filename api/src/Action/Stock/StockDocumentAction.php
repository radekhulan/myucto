<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockDocumentRepository;
use MyInvoice\Repository\WarehouseRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\StockDocumentPdfRenderer;
use MyInvoice\Service\Stock\StockDocumentService;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockReceiptService;
use MyInvoice\Service\Stock\StockValuation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Skladové doklady PRI/VYD/PRE — REST API (Epic SKLAD, vzor CashDocumentAction).
 *
 *   GET    /api/stock/documents             — stránkovaný seznam (filtry)
 *   POST   /api/stock/documents             — nový draft
 *   GET    /api/stock/documents/{id}        — detail vč. řádků
 *   PUT    /api/stock/documents/{id}        — úprava draftu
 *   DELETE /api/stock/documents/{id}        — smazání draftu
 *   POST   /api/stock/documents/{id}/post   — zaúčtování draftu
 *   POST   /api/stock/documents/{id}/reverse— storno (protidoklad v původní ceně)
 *   GET    /api/stock/documents/{id}/pdf    — tisk dokladu (PDF)
 */
final class StockDocumentAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    private const MAX_PER_PAGE = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly StockDocumentService $service,
        private readonly StockDocumentRepository $docs,
        private readonly WarehouseRepository $warehouses,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly StockDocumentPdfRenderer $pdfRenderer,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q = $request->getQueryParams();
        $filters = [];
        foreach (['doc_type', 'status', 'origin', 'q', 'from', 'to'] as $key) {
            if (isset($q[$key]) && $q[$key] !== '') {
                $filters[$key] = (string) $q[$key];
            }
        }
        if (!empty($q['warehouse_id'])) {
            $filters['warehouse_id'] = (int) $q['warehouse_id'];
        }
        $limit  = max(1, min(self::MAX_PER_PAGE, (int) ($q['limit'] ?? 100)));
        $offset = max(0, (int) ($q['offset'] ?? 0));
        $filters['limit']  = $limit;
        $filters['offset'] = $offset;

        $rows = $this->docs->list($supplierId, $filters);
        $total = $rows !== [] ? (int) $rows[0]['total_rows'] : 0;
        foreach ($rows as &$r) {
            unset($r['total_rows']);
        }
        unset($r);

        return Json::ok($response, ['items' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $doc = $this->docs->findWithLines($supplierId, (int) $args['id']);
        if ($doc === null) {
            return Json::error($response, 'not_found', 'Skladový doklad nenalezen.', 404);
        }
        if ($doc['doc_type'] === 'receipt') {
            $doc['sales_invoices'] = $this->docs->salesInvoicesOfReceipt(
                $supplierId,
                (int) $args['id'],
                RequestAuthorization::allows($request, 'invoices', AccessLevel::READ),
            );
        }
        return Json::ok($response, $doc);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $this->guardCardWriteOptions($request, $supplierId, $body);
            // Ruční zadání: hlídá desetinná místa množství podle jednotky karty (2036).
            $result = $this->service->create($supplierId, $body, $this->userId($request), checkUnitDecimals: true);
            $this->log($request, 'stock.document_created', (int) $result['id'], ['doc_type' => $result['doc_type'] ?? null]);
            return Json::ok($response, $result, 201);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $this->guardCardWriteOptions($request, $supplierId, $body, $id);
            $result = $this->service->updateDraft($supplierId, $id, $body, $this->userId($request), checkUnitDecimals: true);
            $this->log($request, 'stock.document_updated', $id, []);
            return Json::ok($response, $result);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        try {
            $this->service->deleteDraft($supplierId, $id, $this->userId($request));
            $this->log($request, 'stock.document_deleted', $id, []);
            return Json::ok($response, ['deleted' => true]);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function post(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        try {
            $result = $this->service->post(
                $supplierId,
                $id,
                $this->userId($request),
                RequestAuthorization::allows($request, 'stock.items.write', AccessLevel::WRITE),
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'),
            );
            $this->log($request, 'stock.document_posted', $id, ['doc_number' => $result['doc_number'] ?? null]);
            return Json::ok($response, $result);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    /**
     * Volby zapisující na kartu smí nastavit jen ten, kdo smí upravovat karty. Bez práva lze u konceptu
     * jen ponechat tu, kterou řádek už má se stejným řádkem faktury a stejnou kartou, nebo ji zrušit.
     *
     * @param array<string,mixed> $body
     */
    private function guardCardWriteOptions(Request $request, int $supplierId, array $body, ?int $draftId = null): void
    {
        if (RequestAuthorization::allows($request, 'stock.items.write', AccessLevel::WRITE)) {
            return;
        }
        $lines = is_array($body['lines'] ?? null) ? $body['lines'] : [];
        $key = static fn (array $l): string => (int) ($l['purchase_invoice_item_id'] ?? 0) . '/' . (int) ($l['stock_item_id'] ?? 0);
        $current = null;
        foreach (StockDocumentService::CARD_WRITE_OPTIONS as $option) {
            $chosen = array_filter($lines, static fn (mixed $l): bool => is_array($l) && ($l[$option] ?? false) === true);
            if ($chosen === []) {
                continue;
            }
            $current ??= $draftId === null ? [] : ($this->docs->findWithLines($supplierId, $draftId)['lines'] ?? []);
            $kept = array_map($key, array_filter($current, static fn (array $l): bool => $l[$option] === true));
            foreach ($chosen as $line) {
                if (!in_array($key($line), $kept, true)) {
                    throw new StockException('card_write_forbidden', StockReceiptService::CARD_WRITE_FORBIDDEN, 403);
                }
            }
        }
    }

    public function reverse(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $result = $this->service->reverse($supplierId, $id, [
                'reason' => (string) ($body['reason'] ?? ''),
            ], $this->userId($request));
            $this->log($request, 'stock.document_reversed', $id, [
                'reversal_id' => $result['reversal']['id'] ?? null,
            ]);
            return Json::ok($response, $result);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    /** PDF tisk skladového dokladu (PRI/VYD/PRE). */
    public function pdf(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        $doc = $this->docs->findWithLines($supplierId, $id);
        if ($doc === null) {
            return Json::error($response, 'not_found', 'Skladový doklad nenalezen.', 404);
        }

        $warehouse   = $this->warehouses->find($supplierId, (int) $doc['warehouse_id']);
        $warehouseTo = $doc['warehouse_to_id'] !== null
            ? $this->warehouses->find($supplierId, (int) $doc['warehouse_to_id'])
            : null;

        $stmt = $this->db->pdo()->prepare(
            'SELECT company_name, street, city, zip, ic, dic FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $totalValueC = 0;
        foreach ($doc['lines'] ?? [] as $line) {
            $totalValueC += StockValuation::valueToC((string) ($line['value_total'] ?? '0'));
        }

        $data = [
            'document'     => $doc,
            'warehouse'    => $warehouse,
            'warehouse_to' => $warehouseTo,
            'supplier'     => $supplier,
            'total_value'  => StockValuation::cToDecimal($totalValueC),
        ];

        try {
            $bytes = $this->pdfRenderer->render($data);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }

        $prefixes = ['receipt' => 'PRI', 'issue' => 'VYD', 'transfer' => 'PRE'];
        $prefix   = $prefixes[(string) ($doc['doc_type'] ?? '')] ?? 'SKL';
        $filename = $prefix . '-' . (string) ($doc['doc_number'] ?? $id) . '.pdf';
        $filename = str_replace(['/', '\\', ' '], '-', $filename);

        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** Výdejky/vratky k FV (Epic SKLAD §7.3 Detail FV) — GET /api/invoices/{id}/stock-documents. */
    public function forInvoice(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        return Json::ok($response, $this->docs->listByInvoice($supplierId, (int) $args['id']));
    }

    /** Příjemky, ze kterých faktura vznikla — GET /api/invoices/{id}/source-receipts. */
    public function sourceReceipts(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        return Json::ok($response, $this->docs->receiptsOfSalesInvoice($supplierId, (int) $args['id']));
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'stock_document',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }

    private function mapStockError(Response $response, \Throwable $e): Response
    {
        if ($e instanceof StockException) {
            return Json::error($response, 'stock.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus, ['items' => $e->details]);
        }
        throw $e;
    }
}
