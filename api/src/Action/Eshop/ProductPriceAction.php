<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Pricing\PriceRecomputeDispatcher;
use MyInvoice\Service\Eshop\Pricing\PriceWriteService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Cenotvorba karty — ceny per měna + přepočet (Epic ESHOP).
 *
 *   GET /api/eshop/products/{id}/prices
 *   PUT /api/eshop/products/{id}/prices              — bulk definice + přepočet
 *   POST /api/eshop/products/{id}/prices/recompute   — vynucený přepočet
 */
final class ProductPriceAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly StockItemRepository $items,
        private readonly StockItemPriceRepository $prices,
        private readonly PriceRecomputeDispatcher $dispatcher,
        private readonly PriceWriteService $writer,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $itemId = (int) $args['id'];
        if ($this->items->find($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Karta zboží nenalezena.', 404);
        }
        return Json::ok($response, $this->prices->listForItem($supplierId, $itemId));
    }

    public function put(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'eshop.write', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $itemId = (int) $args['id'];
        if ($this->items->find($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Karta zboží nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        if (!isset($body['prices']) || !is_array($body['prices']) || !array_is_list($body['prices'])) {
            return Json::error($response, 'validation_failed', 'Pole prices musí být seznam cen.', 400);
        }
        $rows = $body['prices'];

        try {
            $result = array_key_exists('row_version', $body)
                ? $this->writer->saveVersioned(
                    $supplierId,
                    $itemId,
                    (int) $body['row_version'],
                    $rows,
                    $request->getMethod() === 'PUT',
                )
                : $this->writer->save($supplierId, $itemId, $rows, $request->getMethod() === 'PUT');
        } catch (EshopException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        $this->log($request, 'eshop.prices_updated', $itemId, ['currencies' => array_column($rows, 'currency_code')]);
        return Json::ok($response, $result);
    }

    public function recompute(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'eshop.write', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $itemId = (int) $args['id'];
        if ($this->items->find($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Karta zboží nenalezena.', 404);
        }
        $result = $this->dispatcher->recomputeItem($supplierId, $itemId);
        $this->log($request, 'eshop.prices_recomputed', $itemId, []);
        return Json::ok($response, $result);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'eshop.write', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $itemId = (int) $args['id'];
        if ($this->items->find($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Karta zboží nenalezena.', 404);
        }
        $result = $this->writer->delete($supplierId, $itemId, (string) $args['currency']);
        $this->log($request, 'eshop.price_deleted', $itemId, ['currency' => strtoupper((string) $args['currency'])]);
        return Json::ok($response, $result);
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'stock_item',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
