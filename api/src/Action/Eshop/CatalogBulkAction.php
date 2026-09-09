<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Eshop\CatalogBulkService;
use MyInvoice\Service\Eshop\EshopException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CatalogBulkAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogBulkService $bulk,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        if (!$this->authorized($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_array($body['selection'] ?? null) || !is_array($body['changes'] ?? null)) {
            return Json::error($response, 'validation_failed', 'Výběr a změny musí být objekty.', 400);
        }
        try {
            return Json::ok($response, $this->present($this->bulk->preview(
                $supplierId, $body['selection'], $body['changes'], $this->userId($request),
            )), 202);
        } catch (EshopException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
    }

    public function apply(Request $request, Response $response, array $args): Response
    {
        return $this->derive($request, $response, $args, false);
    }

    public function restore(Request $request, Response $response, array $args): Response
    {
        return $this->derive($request, $response, $args, true);
    }

    private function derive(Request $request, Response $response, array $args, bool $restore): Response
    {
        if (!$this->authorized($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) ($args['id'] ?? 0);
        if ($id < 1) {
            return Json::error($response, 'not_found', 'Hromadná úloha nenalezena.', 404);
        }
        try {
            $job = $restore
                ? $this->bulk->restore($supplierId, $id, $this->userId($request))
                : $this->bulk->apply($supplierId, $id, $this->userId($request));
            return Json::ok($response, $this->present($job), 202);
        } catch (EshopException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }
    }

    private function authorized(Request $request, Response $response, ?Response &$err): bool
    {
        return $this->requirePermission($request, $response, 'eshop.write', AccessLevel::WRITE, $err)
            && $this->requirePermission($request, $response, 'stock.items.write', AccessLevel::WRITE, $err);
    }

    private function present(array $job): array
    {
        unset($job['input']);
        return $job;
    }
}
