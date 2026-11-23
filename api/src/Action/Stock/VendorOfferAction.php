<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Repository\StockItemVendorRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eshop\Pricing\PriceRecomputeDispatcher;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Invoice\UnitPricePrecision;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * „U dodavatele" — nabídky dodavatelů nad `stock_item_vendors` (Epic SKLAD, fáze 3).
 *
 *   GET    /api/stock/vendor-offers        — seznam napříč kartami {items,total,limit,offset}
 *   POST   /api/stock/vendor-offers        — nová nabídka (201)
 *   PATCH  /api/stock/vendor-offers/{id}   — částečná úprava (MCP-friendly)
 *   DELETE /api/stock/vendor-offers/{id}   — smazání
 *
 * Proti `PUT /api/eshop/products/{id}/vendors` (replace-all celé sady) je tohle
 * pohled „řádek = nabídka": edituje se jedna dvojice karta×dodavatel, aniž by
 * volající musel poslat i všechny ostatní. Zapisuje se do TÉŽE tabulky, takže
 * obě cesty vidí stejná data.
 *
 * KAŽDÝ zápis (i POST a DELETE, nejen PATCH) volá
 * `PriceRecomputeDispatcher::recomputeItem()` stejně jako
 * `ProductVendorAction::put()` — cenotvorba s `pricing_base=manual` bere nákupní
 * cenu preferovaného dodavatele, takže bez přepočtu by se prodejní ceny rozešly
 * podle toho, KUDY se zápis provedl.
 *
 * Karta bez jediného skladového pohybu je plnohodnotná (rozhodnutí #12 plánu):
 * nabídku lze navázat hned po založení karty, `stock_levels` řádek existovat
 * nemusí a `on_hand` se vrací jako 0, ne jako chybějící údaj.
 */
final class VendorOfferAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    private const AVAILABILITY_STATES = ['in_stock', 'on_order', 'unavailable', 'unknown'];
    private const DATA_SOURCES = ['manual', 'import', 'feed'];

    public function __construct(
        private readonly Connection $db,
        private readonly StockItemRepository $items,
        private readonly StockItemVendorRepository $vendors,
        private readonly PriceRecomputeDispatcher $dispatcher,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q = $request->getQueryParams();
        $limit  = max(1, min(500, (int) ($q['limit'] ?? 100)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        $state = isset($q['availability_state']) ? trim((string) $q['availability_state']) : '';
        if ($state !== '' && !in_array($state, self::AVAILABILITY_STATES, true)) {
            return Json::error($response, 'validation_failed', 'Neplatná dostupnost u dodavatele.', 422);
        }

        $result = $this->vendors->listOffers($supplierId, [
            'stock_item_id'      => isset($q['stock_item_id']) ? (int) $q['stock_item_id'] : null,
            'client_id'          => isset($q['client_id']) ? (int) $q['client_id'] : null,
            'q'                  => isset($q['q']) ? (string) $q['q'] : null,
            'availability_state' => $state !== '' ? $state : null,
            'active'             => array_key_exists('active', $q) && $q['active'] !== ''
                ? (bool) filter_var($q['active'], FILTER_VALIDATE_BOOLEAN)
                : null,
            'preferred'          => !empty($q['preferred']),
            'limit'              => $limit,
            'offset'             => $offset,
        ]);

        return Json::ok($response, [
            'items'  => $result['items'],
            'total'  => $result['total'],
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $offer = $this->vendors->findOffer($supplierId, (int) $args['id']);
        if ($offer === null) {
            return Json::error($response, 'not_found', 'Nabídka dodavatele nenalezena.', 404);
        }
        return Json::ok($response, $offer);
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

        $itemId = (int) ($body['stock_item_id'] ?? 0);
        if ($itemId <= 0 || $this->items->find($supplierId, $itemId) === null) {
            return Json::error($response, 'validation_failed', 'Karta zboží nenalezena.', 422, ['field' => 'stock_item_id']);
        }
        $clientId = (int) ($body['client_id'] ?? 0);
        if ($clientId <= 0 || $this->vendors->filterOwnedVendors($supplierId, [$clientId]) === []) {
            return Json::error($response, 'vendor_invalid', 'Zvolený dodavatel neexistuje nebo není označen jako dodavatel.', 422, ['client_id' => $clientId]);
        }
        if ($this->vendors->findByItemAndClient($supplierId, $itemId, $clientId) !== null) {
            return Json::error($response, 'vendor_offer_exists', 'Tento dodavatel už u karty nabídku má — upravte ji.', 409);
        }

        [$changes, $validationErr] = $this->parse($response, $body, null);
        if ($validationErr !== null) {
            return $validationErr;
        }

        $data = $changes + ['client_id' => $clientId];
        $preferred = (bool) ($data['is_preferred'] ?? false);

        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $id = $this->vendors->add($supplierId, $itemId, $data);
            if ($preferred) {
                $this->vendors->clearPreferredForItem($supplierId, $itemId, $id);
            }
            if ($owns) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->dispatcher->recomputeItem($supplierId, $itemId);
        $this->log($request, 'stock.vendor_offer_created', $id, ['stock_item_id' => $itemId, 'client_id' => $clientId]);
        return Json::ok($response, $this->vendors->findOffer($supplierId, $id) ?? [], 201);
    }

    public function patch(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        $existing = $this->vendors->findOffer($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Nabídka dodavatele nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);

        [$changes, $validationErr] = $this->parse($response, $body, $existing);
        if ($validationErr !== null) {
            return $validationErr;
        }
        if ($changes === []) {
            return Json::ok($response, $existing);
        }

        $itemId = (int) $existing['stock_item_id'];
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $this->vendors->updateOffer($supplierId, $id, $changes);
            if (!empty($changes['is_preferred'])) {
                $this->vendors->clearPreferredForItem($supplierId, $itemId, $id);
            }
            if ($owns) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Stejný háček jako ProductVendorAction::put() — jinak by se cenotvorba
        // rozešla podle toho, kterou cestou se nákupní cena zapsala.
        $this->dispatcher->recomputeItem($supplierId, $itemId);
        $this->log($request, 'stock.vendor_offer_updated', $id, ['fields' => array_keys($changes)]);
        return Json::ok($response, $this->vendors->findOffer($supplierId, $id) ?? []);
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
        $existing = $this->vendors->findOffer($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Nabídka dodavatele nenalezena.', 404);
        }
        $itemId = (int) $existing['stock_item_id'];
        $this->vendors->deleteOffer($supplierId, $id);
        $this->dispatcher->recomputeItem($supplierId, $itemId);
        $this->log($request, 'stock.vendor_offer_deleted', $id, ['stock_item_id' => $itemId]);
        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * Validace + normalizace předaných polí. Vrací JEN klíče, které v těle byly —
     * PATCH tak rozliší „neměň" od „vynuluj".
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $existing
     * @return array{0:array<string,mixed>, 1:?Response}
     */
    private function parse(Response $response, array $body, ?array $existing): array
    {
        $out = [];
        $fail = static fn (string $message, string $field): array => [
            [], Json::error($response, 'validation_failed', $message, 422, ['field' => $field]),
        ];

        if (array_key_exists('vendor_sku', $body)) {
            $v = $this->strOrNull($body['vendor_sku']);
            if ($v !== null && mb_strlen($v) > 80) {
                return $fail('Kód u dodavatele je delší než 80 znaků.', 'vendor_sku');
            }
            $out['vendor_sku'] = $v;
        }
        if (array_key_exists('purchase_price', $body)) {
            $v = $this->decOrNull($body['purchase_price'], 10);
            if ($v === false) {
                return $fail('Neplatná nákupní cena.', 'purchase_price');
            }
            if (!UnitPricePrecision::isWithinPrecision($v)) {
                return $fail('Nákupní cena smí mít nejvýše šest desetinných míst.', 'purchase_price');
            }
            $out['purchase_price'] = $v;
        }
        if (array_key_exists('currency_code', $body)) {
            $v = strtoupper(trim((string) $body['currency_code']));
            if (!preg_match('/^[A-Z]{3}$/', $v)) {
                return $fail('Kód měny musí mít 3 písmena (ISO 4217).', 'currency_code');
            }
            $out['currency_code'] = $v;
        }
        if (array_key_exists('delivery_days', $body)) {
            $raw = $body['delivery_days'];
            if ($raw === null || $raw === '') {
                $out['delivery_days'] = null;
            } else {
                if (!is_numeric($raw) || (int) $raw < 0 || (int) $raw > 65535) {
                    return $fail('Dodací lhůta musí být celé číslo 0–65535.', 'delivery_days');
                }
                $out['delivery_days'] = (int) $raw;
            }
        }
        if (array_key_exists('stock_qty', $body)) {
            $v = $this->decOrNull($body['stock_qty'], 11);
            if ($v === false) {
                return $fail('Neplatné množství u dodavatele.', 'stock_qty');
            }
            $out['stock_qty'] = $v;
            // Razítko je informativní (rozhodnutí #7): nic podle něj neplatí ani
            // neplatí přestává, jen se dá dohledat, kdy množství naposled přišlo.
            $previous = $existing['stock_qty'] ?? null;
            if ($v !== null && (string) $previous !== (string) $v) {
                $out['stock_qty_updated_at'] = date('Y-m-d H:i:s');
            }
        }
        if (array_key_exists('stock_qty_updated_at', $body)) {
            $raw = $body['stock_qty_updated_at'];
            if ($raw === null || $raw === '') {
                $out['stock_qty_updated_at'] = null;
            } else {
                $ts = strtotime((string) $raw);
                if ($ts === false) {
                    return $fail('Neplatné datum aktualizace skladovosti.', 'stock_qty_updated_at');
                }
                $out['stock_qty_updated_at'] = date('Y-m-d H:i:s', $ts);
            }
        }
        if (array_key_exists('availability_state', $body)) {
            $v = trim((string) $body['availability_state']);
            if (!in_array($v, self::AVAILABILITY_STATES, true)) {
                return $fail('Neplatná dostupnost u dodavatele.', 'availability_state');
            }
            $out['availability_state'] = $v;
        }
        foreach (['min_order_qty', 'package_qty'] as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $v = $this->decOrNull($body[$field], 11);
            if ($v === false) {
                return $fail('Neplatné množství.', $field);
            }
            if ($v !== null && (float) $v <= 0) {
                return $fail('Množství musí být větší než nula.', $field);
            }
            $out[$field] = $v;
        }
        if (array_key_exists('price_valid_to', $body)) {
            $raw = $body['price_valid_to'];
            if ($raw === null || $raw === '') {
                $out['price_valid_to'] = null;
            } else {
                $s = trim((string) $raw);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) || strtotime($s) === false) {
                    return $fail('Platnost ceny musí být datum ve tvaru RRRR-MM-DD.', 'price_valid_to');
                }
                $out['price_valid_to'] = $s;
            }
        }
        if (array_key_exists('data_source', $body)) {
            $v = trim((string) $body['data_source']);
            if (!in_array($v, self::DATA_SOURCES, true)) {
                return $fail('Neplatný zdroj dat nabídky.', 'data_source');
            }
            $out['data_source'] = $v;
        }
        if (array_key_exists('is_active', $body)) {
            $out['is_active'] = (bool) $body['is_active'];
        }
        if (array_key_exists('is_preferred', $body)) {
            $out['is_preferred'] = (bool) $body['is_preferred'];
        }
        if (array_key_exists('note', $body)) {
            $v = $this->strOrNull($body['note']);
            if ($v !== null && mb_strlen($v) > 255) {
                return $fail('Poznámka je delší než 255 znaků.', 'note');
            }
            $out['note'] = $v;
        }

        return [$out, null];
    }

    /**
     * Desetinné číslo jako string (money/qty-safe, žádný float). `false` = chyba,
     * `null` = prázdná hodnota. `$maxIntDigits` chrání před přetečením DECIMAL,
     * které by jinak v strict mode skončilo PDOException → 500.
     */
    private function decOrNull(mixed $v, int $maxIntDigits): string|false|null
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string) $v);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            return false;
        }
        if ((float) $s < 0) {
            return false;
        }
        if (strlen(explode('.', ltrim($s, '-'), 2)[0]) > $maxIntDigits) {
            return false;
        }
        return $s;
    }

    private function strOrNull(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'stock_item_vendor',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
