<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockReceiptService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Příjem na sklad z přijaté faktury (Epic SKLAD §5.6) — REST API na cestách
 * pod /api/purchase-invoices (ne /api/stock — vlastní PermissionMiddleware pravidla PF).
 *
 *   GET  /api/purchase-invoices/{id}/stock-receipt   — návrh příjemky (zbývá přijmout, PC, kandidáti nákladů)
 *   POST /api/purchase-invoices/{id}/stock-receipt   — založí DRAFT příjemku
 *   GET  /api/purchase-invoices/{id}/stock-receipts  — přehled existujících příjmů k PF
 */
final class StockReceiptAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly StockReceiptService $service,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function propose(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        try {
            $overrides = [];
            $raw = $request->getQueryParams()['stock_item_ids'] ?? [];
            foreach (is_array($raw) ? $raw : [] as $itemId => $cardId) {
                if ((int) $itemId > 0 && (int) $cardId > 0) {
                    $overrides[(int) $itemId] = (int) $cardId;
                }
            }
            return Json::ok($response, $this->service->proposeForPurchaseInvoice($supplierId, (int) $args['id'], $overrides));
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $piId = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $result = $this->service->createReceipt(
                $supplierId,
                $piId,
                $body,
                $this->userId($request),
                RequestAuthorization::allows($request, 'stock.items.write', AccessLevel::WRITE),
            );
            $this->log($request, 'stock.receipt_created', (int) $result['id'], ['purchase_invoice_id' => $piId]);
            return Json::ok($response, $result, 201);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function list(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        return Json::ok($response, $this->service->receiptsForPurchaseInvoice($supplierId, (int) $args['id']));
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
