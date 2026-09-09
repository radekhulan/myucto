<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Eshop\CatalogReadService;
use MyInvoice\Service\Eshop\CatalogFilter;
use MyInvoice\Service\Eshop\EshopException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CatalogReadAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(private readonly Connection $db, private readonly CatalogReadService $catalog, private readonly StockItemRepository $items) {}

    public function facets(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'eshop', AccessLevel::READ, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        try {
            $query = $request->getQueryParams();
            $limit = filter_var($query['limit'] ?? 100, FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > 500) {
                throw new \InvalidArgumentException('Limit fasety musí být 1 až 500.');
            }
            return Json::ok($response, $this->items->facets($supplierId, CatalogFilter::normalize($query), $limit));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
    }

    public function products(Request $request, Response $response): Response
    {
        return $this->read($request, $response, false);
    }

    public function prices(Request $request, Response $response): Response
    {
        return $this->read($request, $response, true);
    }

    private function read(Request $request, Response $response, bool $prices): Response
    {
        if (!$this->requirePermission($request, $response, 'eshop', AccessLevel::READ, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return Json::error($response, 'validation_failed', 'Požadavek musí obsahovat objekt JSON.', 400);
        }
        try {
            $token = (array) $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN, []);
            $allowCosts = RequestAuthorization::allows($request, 'stock.items.write', AccessLevel::WRITE)
                && (!RequestAuthorization::isBearerAuth($request) || ($token['scope'] ?? '') === 'read_write');
            $result = $prices ? $this->catalog->prices($supplierId, $body)
                : $this->catalog->products($supplierId, $body, $allowCosts);
            return Json::ok($response, $result);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (EshopException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }
}
