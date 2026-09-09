<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Service\Eshop\CatalogFilter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class StockItemNeighborsAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly StockItemRepository $items,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $query = $request->getParsedBody() ?? [];
        if (!is_array($query)) {
            return Json::error($response, 'validation', 'Filtry musí být objekt.', 422);
        }
        try {
            if (array_diff(array_keys($query), ['filters', 'ids', 'excluded_ids']) !== []) {
                throw new \InvalidArgumentException('Neznámé pole výběru.');
            }
            $ids = array_key_exists('ids', $query) ? $this->ids($query['ids']) : null;
            $excludedIds = array_key_exists('excluded_ids', $query) ? $this->ids($query['excluded_ids']) : [];
            if (isset($query['filters']) && !is_array($query['filters'])) {
                throw new \InvalidArgumentException('Filtry musí být objekt.');
            }
            $filters = CatalogFilter::normalize($query['filters'] ?? []);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation', $e->getMessage(), 422);
        }
        return Json::ok($response, $this->items->neighbors($supplierId, (int) $args['id'], $filters, $ids, $excludedIds));
    }

    private function ids(mixed $value): array
    {
        $values = is_string($value) ? ($value === '' ? [] : explode(',', $value)) : $value;
        if (!is_array($values) || !array_is_list($values) || count($values) > 30000) {
            throw new \InvalidArgumentException('Výběr musí obsahovat nejvýše 30000 identifikátorů.');
        }
        $ids = [];
        foreach ($values as $id) {
            if ((!is_string($id) && !is_int($id)) || !ctype_digit((string) $id)
                || ($parsed = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false) {
                throw new \InvalidArgumentException('Výběr musí obsahovat kladné celočíselné identifikátory.');
            }
            $ids[] = $parsed;
        }
        return array_values(array_unique($ids));
    }
}
