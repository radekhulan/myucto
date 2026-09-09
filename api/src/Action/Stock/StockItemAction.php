<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Repository\StockLevelRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eshop\Pricing\EffectivePriceResolver;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\StockItemMovementsPdfRenderer;
use MyInvoice\Service\Stock\StockReportXlsxExporter;
use MyInvoice\Service\Stock\StockValuation;
use MyInvoice\Support\Pagination;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Skladové karty (Epic SKLAD).
 *
 *   GET    /api/stock/items/search                 — našeptávač (autocomplete)
 *   GET    /api/stock/items                        — seznam, stránkovaný (filtry: type, active, q, only_below_min)
 *   POST   /api/stock/items                        — nová karta
 *   GET    /api/stock/items/{id}                   — detail
 *   PUT    /api/stock/items/{id}                   — úprava
 *   DELETE /api/stock/items/{id}                   — smazání (jen bez pohybů; jinak deaktivace)
 *   GET    /api/stock/items/{id}/movements         — skladová kniha karty (stránkovaná, s běžnou bilancí)
 *   GET    /api/stock/items/{id}/movements/export  — export skladové karty (?format=pdf|xlsx)
 */
final class StockItemAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly StockItemRepository $items,
        private readonly StockLevelRepository $levels,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly StockItemMovementsPdfRenderer $movementsPdf,
        private readonly StockReportXlsxExporter $xlsx,
        private readonly EffectivePriceResolver $effectivePrice,
    ) {}

    /**
     * Doplní řádkům platnou cenu (akční cena nad standardní cenotvorbou, migrace
     * 1328). Cena se NIKDY nečte přímo ze `sale_price_without_vat` — jediná cesta
     * je {@see EffectivePriceResolver}, aby doklad, seznam i našeptávač quotovaly
     * totéž. Dávkově = konstantní počet dotazů bez ohledu na počet řádků.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function withEffectivePrice(int $supplierId, array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $resolved = $this->effectivePrice->resolveMany($supplierId, $ids);
        foreach ($rows as &$row) {
            $r = $resolved[(int) $row['id']] ?? null;
            $row['effective_price']     = $r['unit_price'] ?? ($row['sale_price_without_vat'] ?? null);
            $row['promo_price']         = ($r !== null && $r['promo_applied']) ? $r['promo']['promo_price'] : null;
            $row['promo_label']         = ($r !== null && $r['promo_applied']) ? $r['promo']['label'] : null;
            $row['promo_qty_available'] = $r['promo_qty_available'] ?? null;
        }
        unset($row);
        return $rows;
    }

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q = $request->getQueryParams();
        $filters = [];
        if (!empty($q['type'])) {
            $filters['type'] = (string) $q['type'];
        }
        if (array_key_exists('active', $q) && $q['active'] !== '') {
            $filters['active'] = (bool) (int) $q['active'];
        }
        if (!empty($q['q'])) {
            $filters['q'] = (string) $q['q'];
        }
        if (!empty($q['only_below_min'])) {
            $filters['only_below_min'] = true;
        }
        foreach (['warehouse_id', 'manufacturer_id', 'category_id', 'vendor_id'] as $field) {
            if (isset($q[$field]) && (int) $q[$field] > 0) {
                $filters[$field] = (int) $q[$field];
            }
        }
        if (!empty($q['tag_ids'])) {
            $tagIds = self::csvIds((string) $q['tag_ids']);
            if ($tagIds === null) {
                return Json::error($response, 'validation_failed', 'Identifikátory štítků musí být kladná celá čísla.', 400);
            }
            $filters['tag_ids'] = $tagIds;
        }
        if (!empty($q['missing'])) {
            $missing = array_values(array_unique(array_filter(array_map(
                static fn (string $value): string => trim($value),
                explode(',', (string) $q['missing']),
            ))));
            if (array_diff($missing, StockItemRepository::MISSING_FIELDS) !== []) {
                return Json::error($response, 'validation_failed', 'Neznámý filtr chybějících údajů.', 400);
            }
            $filters['missing'] = $missing;
        }
        if (!empty($q['availability'])) {
            $availability = (string) $q['availability'];
            if (!in_array($availability, StockItemRepository::AVAILABILITY_FILTERS, true)) {
                return Json::error($response, 'validation_failed', 'Neznámý filtr dostupnosti.', 400);
            }
            $filters['availability'] = $availability;
        }
        foreach (['qty_min', 'qty_max'] as $field) {
            if (isset($q[$field]) && $q[$field] !== '') {
                if (!is_numeric($q[$field])) {
                    return Json::error($response, 'validation_failed', 'Množstevní filtr musí být číslo.', 400);
                }
                $filters[$field] = (string) $q[$field];
            }
        }
        if (!empty($q['attribute_filters'])) {
            try {
                $attributes = json_decode((string) $q['attribute_filters'], true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return Json::error($response, 'validation_failed', 'Filtr atributů nemá platný JSON formát.', 400);
            }
            if (!self::validAttributeFilters($attributes)) {
                return Json::error($response, 'validation_failed', 'Filtr atributů má neplatnou strukturu.', 400);
            }
            $filters['attribute_filters'] = $attributes;
        }
        if (!empty($q['sort'])) {
            $sort = (string) $q['sort'];
            if (!in_array($sort, StockItemRepository::SORT_FIELDS, true)) {
                return Json::error($response, 'validation_failed', 'Neznámý sloupec řazení.', 400);
            }
            $filters['sort'] = $sort;
        }
        if (!empty($q['direction'])) {
            $direction = strtolower((string) $q['direction']);
            if (!in_array($direction, ['asc', 'desc'], true)) {
                return Json::error($response, 'validation_failed', 'Neznámý směr řazení.', 400);
            }
            $filters['direction'] = $direction;
        }

        $p = Pagination::fromQuery($q, 50);
        [$rows, $total] = $this->items->listPaged($supplierId, $filters, $p['per_page'], $p['offset']);
        $rows = $this->withEffectivePrice($supplierId, $rows);
        return Json::ok($response, Pagination::envelope($rows, $total, $p['page'], $p['per_page']));
    }

    /** @return list<int>|null */
    private static function csvIds(string $value): ?array
    {
        $ids = [];
        foreach (explode(',', $value) as $rawId) {
            $rawId = trim($rawId);
            if ($rawId === '' || !ctype_digit($rawId) || (int) $rawId <= 0) {
                return null;
            }
            $ids[] = (int) $rawId;
        }

        return array_values(array_unique($ids));
    }

    private static function validAttributeFilters(mixed $filters): bool
    {
        if (!is_array($filters) || !array_is_list($filters)) {
            return false;
        }
        $allowed = ['attribute_id', 'option_id', 'value_text', 'value_bool', 'value_num_min', 'value_num_max'];
        foreach ($filters as $filter) {
            if (!is_array($filter) || (int) ($filter['attribute_id'] ?? 0) <= 0
                || array_diff(array_keys($filter), $allowed) !== []) {
                return false;
            }
            if (isset($filter['option_id']) && (int) $filter['option_id'] <= 0) {
                return false;
            }
            foreach (['value_num_min', 'value_num_max'] as $field) {
                if (isset($filter[$field]) && !is_numeric($filter[$field])) {
                    return false;
                }
            }
            if (array_key_exists('value_bool', $filter) && !is_bool($filter['value_bool'])) {
                return false;
            }
        }

        return true;
    }

    public function search(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q = $request->getQueryParams();
        $limit = max(1, min(200, (int) ($q['limit'] ?? 50)));
        $rows = $this->items->search($supplierId, (string) ($q['q'] ?? ''), $limit);
        return Json::ok($response, $this->withEffectivePrice($supplierId, $rows));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $item = $this->items->find($supplierId, (int) $args['id']);
        if ($item === null) {
            return Json::error($response, 'not_found', 'Skladová karta nenalezena.', 404);
        }
        return Json::ok($response, $this->withEffectivePrice($supplierId, [$item])[0]);
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
        [$data, $validationErr] = $this->validate($response, $body);
        if ($validationErr !== null) {
            return $validationErr;
        }
        if ($this->items->findBySku($supplierId, $data['sku']) !== null) {
            return Json::error($response, 'sku_taken', 'Skladová karta s tímto SKU už existuje.', 409);
        }
        $id = $this->items->insert($supplierId, $data);
        $this->log($request, 'stock.item_created', $id, ['sku' => $data['sku']]);
        return Json::ok($response, $this->items->find($supplierId, $id), 201);
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
        $existing = $this->items->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Skladová karta nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        [$data, $validationErr] = $this->validate($response, $body, $existing);
        if ($validationErr !== null) {
            return $validationErr;
        }
        $bySku = $this->items->findBySku($supplierId, $data['sku']);
        if ($bySku !== null && (int) $bySku['id'] !== $id) {
            return Json::error($response, 'sku_taken', 'Skladová karta s tímto SKU už existuje.', 409);
        }
        $this->items->update($supplierId, $id, $data);
        $this->log($request, 'stock.item_updated', $id, ['sku' => $data['sku']]);
        return Json::ok($response, $this->items->find($supplierId, $id));
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
        $existing = $this->items->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Skladová karta nenalezena.', 404);
        }
        if ($this->items->hasMovements($supplierId, $id)) {
            return Json::error(
                $response,
                'item_in_use',
                'Skladovou kartu nelze smazat — má skladové pohyby. Deaktivujte ji místo mazání.',
                409,
                ['suggestion' => 'deactivate'],
            );
        }
        $this->items->delete($supplierId, $id);
        $this->log($request, 'stock.item_deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    /** Skladová kniha karty (stránkovaná) s běžnou bilancí (running balance). */
    public function movements(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $itemId = (int) $args['id'];
        if ($this->items->find($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Skladová karta nenalezena.', 404);
        }

        $q = $request->getQueryParams();
        $limit  = max(1, min(500, (int) ($q['limit'] ?? 100)));
        $offset = max(0, (int) ($q['offset'] ?? 0));
        $warehouseId = !empty($q['warehouse_id']) ? (int) $q['warehouse_id'] : null;
        $from = !empty($q['from']) ? (string) $q['from'] : null;
        $to   = !empty($q['to']) ? (string) $q['to'] : null;

        $baseOpts = array_filter([
            'warehouse_id' => $warehouseId,
            'from'         => $from,
            'to'           => $to,
        ], static fn ($v): bool => $v !== null);

        // Počáteční bilance = součet qty_signed VŠECH řádků PŘED touto stránkou
        // (stejné filtry). Počítáno v celočíselných tisícinách (StockValuation),
        // ne floatem — money-safe vzor napříč appkou.
        $openingT = 0;
        if ($offset > 0) {
            $prior = $this->levels->ledgerForItem($supplierId, $itemId, $baseOpts + ['limit' => $offset, 'offset' => 0]);
            foreach ($prior as $p) {
                $openingT += StockValuation::qtyToT((string) $p['qty_signed']);
            }
        }

        $rows = $this->levels->ledgerForItem($supplierId, $itemId, $baseOpts + ['limit' => $limit, 'offset' => $offset]);
        $runningT = $openingT;
        foreach ($rows as &$r) {
            $runningT += StockValuation::qtyToT((string) $r['qty_signed']);
            $r['balance_after'] = StockValuation::tToDecimal($runningT);
        }
        unset($r);

        return Json::ok($response, [
            'items'           => $rows,
            'opening_balance' => StockValuation::tToDecimal($openingT),
            'limit'           => $limit,
            'offset'          => $offset,
        ]);
    }

    /** Export skladové karty (PDF/XLSX) — kompletní historie (batch fetch přes ledgerForItem, repo limit 500/page). */
    public function movementsExport(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $itemId = (int) $args['id'];
        $item = $this->items->find($supplierId, $itemId);
        if ($item === null) {
            return Json::error($response, 'not_found', 'Skladová karta nenalezena.', 404);
        }

        $format = strtolower(trim((string) ($request->getQueryParams()['format'] ?? '')));
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', "format musí být 'pdf' nebo 'xlsx'.", 422);
        }

        $q = $request->getQueryParams();
        $baseOpts = array_filter([
            'warehouse_id' => !empty($q['warehouse_id']) ? (int) $q['warehouse_id'] : null,
            'from'         => !empty($q['from']) ? (string) $q['from'] : null,
            'to'           => !empty($q['to']) ? (string) $q['to'] : null,
        ], static fn ($v): bool => $v !== null);

        $movements = $this->fetchAllMovements($supplierId, $itemId, $baseOpts);

        $out = $format === 'pdf'
            ? [
                'bytes'    => $this->movementsPdf->render(['item' => $item, 'movements' => $movements]),
                'filename' => 'skladova-karta-' . (string) $item['sku'] . '.pdf',
                'mime'     => 'application/pdf',
            ]
            : $this->xlsx->itemMovements($item, $movements);

        $this->log($request, 'stock.item_movements_exported', $itemId, ['format' => $format]);

        // Sanitizace názvu souboru — SKU je uživatelský vstup (bez charset filtru),
        // syrově by umožnilo header/Content-Disposition injection (uvozovka, /, \, CR/LF).
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $out['filename']) ?? 'export';

        $response->getBody()->write($out['bytes']);
        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * Kompletní skladová kniha karty (bez stránkování) — batch přes ledgerForItem
     * po 500 (interní cap repo), s běžnou bilancí od začátku (A6, money-safe tisíciny).
     *
     * @param array<string,mixed> $baseOpts
     * @return array{items:list<array<string,mixed>>, opening_balance:string}
     */
    private function fetchAllMovements(int $supplierId, int $itemId, array $baseOpts): array
    {
        $rows = [];
        $offset = 0;
        $batch = 500;
        for ($i = 0; $i < 100; $i++) { // pojistka proti nekonečné smyčce (max 50 000 řádků)
            $page = $this->levels->ledgerForItem($supplierId, $itemId, $baseOpts + ['limit' => $batch, 'offset' => $offset]);
            if ($page === []) {
                break;
            }
            $rows = array_merge($rows, $page);
            $offset += $batch;
            if (count($page) < $batch) {
                break;
            }
        }

        $runningT = 0;
        foreach ($rows as &$r) {
            $runningT += StockValuation::qtyToT((string) $r['qty_signed']);
            $r['balance_after'] = StockValuation::tToDecimal($runningT);
        }
        unset($r);

        return ['items' => $rows, 'opening_balance' => StockValuation::tToDecimal(0)];
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $existing
     * @return array{0:array<string,mixed>, 1:?Response}
     */
    private function validate(Response $response, array $body, ?array $existing = null): array
    {
        $name = trim((string) ($body['name'] ?? $existing['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            return [[], Json::error($response, 'validation_failed', 'Název karty je povinný (max 255 znaků).', 400)];
        }

        $sku = trim((string) ($body['sku'] ?? ($existing['sku'] ?? '')));
        if ($sku === '') {
            $sku = $this->slugFromName($name);
        }
        if (mb_strlen($sku) > 50) {
            return [[], Json::error($response, 'validation_failed', 'SKU má max 50 znaků.', 400)];
        }

        $itemType = (string) ($body['item_type'] ?? $existing['item_type'] ?? 'goods');
        if (!in_array($itemType, ['material', 'goods', 'product'], true)) {
            return [[], Json::error($response, 'validation_failed', "item_type musí být 'material', 'goods' nebo 'product'.", 400)];
        }

        $unit = trim((string) ($body['unit'] ?? $existing['unit'] ?? 'ks'));
        if ($unit === '') {
            $unit = 'ks';
        }

        $data = [
            'sku'                    => $sku,
            'name'                   => $name,
            'item_type'              => $itemType,
            'unit'                   => $unit,
            'ean'                    => array_key_exists('ean', $body)
                ? $this->nullable($body['ean']) : ($existing['ean'] ?? null),
            'vat_rate_id'            => array_key_exists('vat_rate_id', $body)
                ? (($body['vat_rate_id'] !== null && $body['vat_rate_id'] !== '') ? (int) $body['vat_rate_id'] : null)
                : ($existing['vat_rate_id'] ?? null),
            'sale_price_without_vat' => array_key_exists('sale_price_without_vat', $body)
                ? (($body['sale_price_without_vat'] !== null && $body['sale_price_without_vat'] !== '') ? (string) $body['sale_price_without_vat'] : null)
                : ($existing['sale_price_without_vat'] ?? null),
            'min_qty'                => array_key_exists('min_qty', $body)
                ? (($body['min_qty'] !== null && $body['min_qty'] !== '') ? (string) $body['min_qty'] : null)
                : ($existing['min_qty'] ?? null),
            'is_active'              => array_key_exists('is_active', $body) ? (bool) $body['is_active'] : (bool) ($existing['is_active'] ?? true),
            'note'                   => array_key_exists('note', $body)
                ? (trim((string) $body['note']) !== '' ? trim((string) $body['note']) : null)
                : ($existing['note'] ?? null),
        ];
        return [$data, null];
    }

    private function nullable(mixed $v): ?string
    {
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /** Jednoduchý slug z názvu (bez diakritiky, VELKÁ, jen [A-Z0-9-]) — fallback SKU. */
    private function slugFromName(string $name): string
    {
        $s = \MyInvoice\Support\Slugifier::slug($name, '-', 'upper', 50);
        if ($s === '') {
            $s = 'SKU-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        }
        return $s;
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
