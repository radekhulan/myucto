<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Eshop\Pricing\CatalogPriceJobService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CatalogJobAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    private function requireWrite(Request $request, Response $response, ?Response &$err): bool
    {
        return $this->requirePermission($request, $response, 'eshop.write', \MyInvoice\Security\AccessLevel::WRITE, $err);
    }

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly CatalogPriceJobService $prices,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q = $request->getQueryParams();
        return Json::ok($response, array_map($this->present(...), $this->jobs->history($supplierId, (int) ($q['before_id'] ?? PHP_INT_MAX), (int) ($q['limit'] ?? 50))));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $job = $this->jobs->find($supplierId, (int) $args['id']);
        return $job === null ? Json::error($response, 'not_found', 'Úloha nenalezena.', 404) : Json::ok($response, $this->present($job));
    }

    public function change(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        $job = $this->jobs->find($supplierId, $id);
        if ($job === null) {
            return Json::error($response, 'not_found', 'Úloha nenalezena.', 404);
        }
        if ($job['kind'] === 'stock_valuation'
            && !$this->requirePermission($request, $response, 'stock', \MyInvoice\Security\AccessLevel::WRITE, $err)) {
            return $err;
        }
        if (!empty($job['input']['stock_take_id'])) {
            return Json::error($response, 'stock_take_preparation', 'Přípravu inventury ovládejte v detailu inventury.', 409);
        }
        $changed = $args['operation'] === 'retry' ? $this->jobs->retry($supplierId, $id) : $this->jobs->cancel($supplierId, $id);
        if (!$changed) {
            return Json::error($response, 'job_state_conflict', 'Aktuální stav úlohy tuto operaci nepovoluje.', 409);
        }
        return Json::ok($response, $this->present($this->jobs->find($supplierId, $id)));
    }

    public function prices(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $ids = $body['item_ids'] ?? null;
        if ($ids !== null && (!is_array($ids) || !array_is_list($ids) || count($ids) > 30000)) {
            return Json::error($response, 'validation_failed', 'item_ids musí být seznam nejvýše 30000 ID.', 400);
        }
        if ($ids !== null) {
            foreach ($ids as $id) {
                if (!is_int($id) || $id <= 0) {
                    return Json::error($response, 'validation_failed', 'Neplatné ID karty.', 400);
                }
            }
        }
        $id = $this->prices->enqueue($supplierId, $ids);
        if ($id === 0) {
            return Json::error($response, 'no_prices', 'Výběr neobsahuje žádné ceny k přepočtu.', 422);
        }
        return Json::ok($response, $this->present($this->jobs->find($supplierId, $id)), 202);
    }

    private function present(array $job): array
    {
        $job['stock_take_id'] = $job['input']['stock_take_id'] ?? null;
        unset($job['input']);
        return $job;
    }
}
