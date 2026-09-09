<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockTakeService;
use MyInvoice\Service\Pdf\StockTakePdfRenderer;
use MyInvoice\Repository\WarehouseRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Inventury (Epic SKLAD §7.2 TakeWizard) — REST API.
 *
 *   GET    /api/stock/takes             — seznam (filtry warehouse_id, status)
 *   POST   /api/stock/takes             — nová inventura (draft)
 *   GET    /api/stock/takes/{id}        — detail vč. řádků
 *   PUT    /api/stock/takes/{id}        — zadání skutečných počtů (jen counting)
 *   POST   /api/stock/takes/{id}/start  — draft → counting (snapshot očekávaných stavů)
 *   POST   /api/stock/takes/{id}/close  — counting → closed (rozdílové doklady)
 */
final class StockTakeAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    private function requireWrite(Request $request, Response $response, ?Response &$err): bool
    {
        return $this->requirePermission($request, $response, 'stock.take', \MyInvoice\Security\AccessLevel::WRITE, $err);
    }

    public function __construct(
        private readonly Connection $db,
        private readonly StockTakeService $service,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly StockTakePdfRenderer $pdfRenderer,
        private readonly WarehouseRepository $warehouses,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q = $request->getQueryParams();
        $filters = [];
        if (!empty($q['warehouse_id'])) {
            $filters['warehouse_id'] = (int) $q['warehouse_id'];
        }
        if (!empty($q['status'])) {
            $filters['status'] = (string) $q['status'];
        }
        return Json::ok($response, $this->service->list($supplierId, $filters));
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
            $result = $this->service->create($supplierId, $body, $this->userId($request));
            $this->log($request, 'stock.take_created', (int) $result['id'], ['warehouse_id' => $result['warehouse_id'] ?? null]);
            return Json::ok($response, $result, 201);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        try {
            return Json::ok($response, $this->service->get($supplierId, (int) $args['id']));
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    /** Zadání skutečných počtů (jen fáze counting). */
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
            $result = $this->service->updateCounts($supplierId, $id, $body, $this->userId($request));
            $this->log($request, 'stock.take_counts_updated', $id, []);
            return Json::ok($response, $result);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function start(Request $request, Response $response, array $args): Response
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
            $result = $this->service->prepare($supplierId, $id, $this->userId($request));
            $this->log($request, 'stock.take_started', $id, []);
            return Json::ok($response, $result, 202);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function preparation(Request $request, Response $response, array $args): Response
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
            $result = $args['operation'] === 'retry-preparation'
                ? $this->service->retryPreparation($supplierId, $id)
                : $this->service->cancelPreparation($supplierId, $id);
            return Json::ok($response, $result);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function close(Request $request, Response $response, array $args): Response
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
            $result = $this->service->close($supplierId, $id, $this->userId($request));
            $this->log($request, 'stock.take_closed', $id, [
                'receipt_document_id' => $result['receipt_document']['id'] ?? null,
                'issue_document_id'   => $result['issue_document']['id'] ?? null,
            ]);
            return Json::ok($response, $result);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function pdf(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        try {
            $take = $this->service->get($supplierId, (int) $args['id']);
            $warehouse = $this->warehouses->find($supplierId, (int) $take['warehouse_id']);
            $stmt = $this->db->pdo()->prepare('SELECT company_name, ic FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $labels = [
                'physical_count' => 'Fyzické přepočítání',
                'measurement' => 'Měření',
                'weighing' => 'Vážení',
                'other' => 'Jiný způsob',
            ];
            $bytes = $this->pdfRenderer->render([
                'take' => $take,
                'warehouse' => $warehouse ?? [],
                'supplier' => $stmt->fetch(\PDO::FETCH_ASSOC) ?: [],
                'counting_method_label' => $labels[(string) $take['counting_method']] ?? (string) $take['counting_method'],
            ]);
            $response->getBody()->write($bytes);
            return $response
                ->withHeader('Content-Type', 'application/pdf')
                ->withHeader('Content-Disposition', 'inline; filename="inventura-' . (int) $take['id'] . '.pdf"')
                ->withHeader('Content-Length', (string) strlen($bytes))
                ->withHeader('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'stock_take',
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
