<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Eshop\CatalogJobAccessPolicy;
use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Service\Eshop\Pricing\CatalogPriceJobService;
use MyInvoice\Security\RequestAuthorization;
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
        private readonly CatalogJobItemRepository $items,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q = $request->getQueryParams();
        return Json::ok($response, array_map($this->present(...), $this->jobs->history($supplierId, (int) ($q['before_id'] ?? PHP_INT_MAX), (int) ($q['limit'] ?? 50), CatalogJobAccessPolicy::readableKinds($request))));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $job = $this->jobs->find($supplierId, (int) $args['id']);
        return $job === null || !CatalogJobAccessPolicy::allows($request, $job['kind'])
            ? Json::error($response, 'not_found', 'Úloha nenalezena.', 404) : Json::ok($response, $this->present($job));
    }

    public function items(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $job = $this->jobs->find($supplierId, (int) $args['id']);
        if ($job === null || !CatalogJobAccessPolicy::allows($request, $job['kind'], audit: true)) {
            return Json::error($response, 'not_found', 'Úloha nenalezena.', 404);
        }
        $q = $request->getQueryParams();
        foreach (['page' => 1, 'limit' => 50] as $key => $default) {
            if (isset($q[$key]) && (!is_scalar($q[$key]) || !ctype_digit((string) $q[$key]))) {
                return Json::error($response, 'validation_failed', 'Neplatné stránkování reportu.', 400);
            }
        }
        if (isset($q['status']) && !is_string($q['status'])) {
            return Json::error($response, 'validation_failed', 'Neplatný stav položky.', 400);
        }
        try {
            $page = $this->items->page($supplierId, $job['id'], (int) ($q['page'] ?? 1), (int) ($q['limit'] ?? 50), $q['status'] ?? null);
            if (str_starts_with($job['kind'], 'catalog_import_')) {
                foreach ($page['items'] as &$item) {
                    if (!is_array($item['input']['media'] ?? null)) {
                        continue;
                    }
                    $item['input']['media'] = array_map(static fn (array $media): array => [
                        'index' => (int) ($media['index'] ?? 0),
                        'url_hash' => (string) ($media['url_hash'] ?? ''),
                    ], array_is_list($item['input']['media']) ? $item['input']['media'] : [$item['input']['media']]);
                }
                unset($item);
            }
            return Json::ok($response, $page);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
    }

    public function change(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
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
        if (!CatalogJobAccessPolicy::allows($request, $job['kind'], write: true)) {
            return Json::error($response, 'forbidden', 'K této operaci nemáte oprávnění.', 403);
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
        if (in_array($job['kind'], ['price_matrix_preview', 'catalog_import_stage', 'stock_opening_import_stage'], true)) {
            $applyKind = match ($job['kind']) {
                'price_matrix_preview' => 'price_matrix_apply',
                'catalog_import_stage' => 'catalog_import_apply',
                default => 'stock_opening_import_apply',
            };
            $stmt = $this->db->pdo()->prepare('SELECT id FROM catalog_jobs WHERE supplier_id = ? AND kind = ?
                AND JSON_UNQUOTE(JSON_EXTRACT(input_json, "$.source_job_id")) = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$job['supplier_id'], $applyKind, (string) $job['id']]);
            $applyId = $stmt->fetchColumn();
            $job['apply_job_id'] = $applyId === false ? null : (int) $applyId;
        }
        unset($job['input']);
        return $job;
    }
}
