<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\ProductRelationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ProductRelationAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(private readonly Connection $db, private readonly ProductRelationService $relations) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, fn (int $supplierId): array => $this->relations->get($supplierId, (int) $args['id']));
    }

    public function put(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'eshop.write', AccessLevel::WRITE, $err)
            || !$this->requirePermission($request, $response, 'stock.items.write', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return Json::error($response, 'validation_failed', 'Požadavek musí obsahovat objekt JSON.', 400);
        }
        return $this->run($request, $response, fn (int $supplierId): array => $this->relations->replace($supplierId, (int) $args['id'], $body));
    }

    private function run(Request $request, Response $response, callable $callback): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        try {
            return Json::ok($response, $callback($supplierId));
        } catch (EshopException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
    }
}
