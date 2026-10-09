<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SmallAssetRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\SmallAsset\SmallAssetService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Karty evidence drobného majetku — REST API (§DM krok 3). RBAC i tenant scoping zrcadlí
 * {@see ExpenseClassificationRuleAction}: čtení readonly+, zápisy účetní|admin
 * (defense-in-depth vedle PermissionMiddleware), vše přes ATTR_CURRENT_ID. Role „client"
 * na tyhle cesty nedosáhne — nemá oprávnění `accounting`, pod které
 * /api/accounting/small-assets spadá fallbackem v RoutePermissionMap.
 *
 * Dostupné v podvojném účetnictví i v daňové evidenci: karta je jen evidence věci
 * (prokázání výdaje a inventura), nic neúčtuje, výdaj nese peněžní deník při úhradě.
 */
final class SmallAssetAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const MAX_PER_PAGE = 100;
    private const STATUSES = ['in_use', 'disposed', 'sold'];

    public function __construct(
        private readonly SmallAssetRepository $cards,
        private readonly SmallAssetService $service,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireAccountingMode($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $status = self::nn($q['status'] ?? null);
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            return Json::error($response, 'validation_failed', 'Neplatný stav karty.', 422);
        }
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($q['per_page'] ?? 50)));
        $yearRaw = self::nn($q['year'] ?? null);
        $result = $this->cards->paginateForTenant($supplierId, [
            'status' => $status,
            'q' => self::nn($q['q'] ?? null),
            'location' => self::nn($q['location'] ?? null),
            'year' => $yearRaw !== null && ctype_digit($yearRaw) ? (int) $yearRaw : null,
        ], $perPage, ($page - 1) * $perPage);

        return Json::ok($response, [
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
            'locations' => $this->cards->locations($supplierId),
            'years' => $this->cards->acquisitionYears($supplierId),
        ]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireAccountingMode($this->db, $supplierId, $response, $err)) return $err;
        $card = $this->cards->find($supplierId, (int) $args['id']);
        if ($card === null) {
            return Json::error($response, 'not_found', 'Karta nenalezena.', 404);
        }
        return Json::ok($response, ['card' => $card]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireAccountingMode($this->db, $supplierId, $response, $err)) return $err;
        try {
            $data = $this->normalizeCard((array) ($request->getParsedBody() ?? []));
            $id = $this->service->create($supplierId, $data, $this->userId($request));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->log($request, 'small_asset.created', $id, ['name' => $data['name'], 'price' => $data['price']]);
        return Json::ok($response, ['card' => $this->cards->find($supplierId, $id)], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireAccountingMode($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) $args['id'];
        $existing = $this->cards->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Karta nenalezena.', 404);
        }
        try {
            $fields = $this->normalizeUpdate($supplierId, $existing, (array) ($request->getParsedBody() ?? []));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->cards->update($supplierId, $id, $fields);
        $this->log($request, 'small_asset.updated', $id, array_keys($fields));
        return Json::ok($response, ['card' => $this->cards->find($supplierId, $id)]);
    }

    /**
     * Vyřazení karty — vlastní endpoint, ne update(). Vyřazení je věcná operace se svými
     * pravidly (nejde vyřadit dvakrát, ne před pořízením) a v audit logu má mít vlastní
     * stopu; schované v generickém patchi by se nedalo dohledat.
     */
    public function dispose(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireAccountingMode($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $disposedAt = $this->assertDate($body['disposed_at'] ?? null, 'disposed_at');
            if ($disposedAt === null) {
                throw $this->err('validation_failed', 'Datum vyřazení je povinné.');
            }
            $this->service->dispose($supplierId, $id, $disposedAt, self::nn($body['disposal_reason'] ?? null));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->log($request, 'small_asset.disposed', $id, ['disposed_at' => $disposedAt]);
        return Json::ok($response, ['card' => $this->cards->find($supplierId, $id)]);
    }

    /**
     * Prodej karty — vlastní endpoint (jako dispose). Prodej drobného majetku je běžná
     * vydaná faktura (výnos 642 + DPH); z karty se NIC neúčtuje (ZC=0), jen se propojí
     * s dokladem prodeje a přejde do stavu 'sold'.
     */
    public function sell(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireAccountingMode($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $saleInvoiceId = self::id($body['sale_invoice_id'] ?? null);
            if ($saleInvoiceId === null) {
                throw $this->err('validation_failed', 'Faktura prodeje je povinná.');
            }
            $soldAt = $this->assertDate($body['sold_at'] ?? null, 'sold_at');
            if ($soldAt === null) {
                throw $this->err('validation_failed', 'Datum prodeje je povinné.');
            }
            $this->service->sell($supplierId, $id, $saleInvoiceId, $soldAt, self::amount($body['sale_price'] ?? null));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->log($request, 'small_asset.sold', $id, ['sale_invoice_id' => $saleInvoiceId, 'sold_at' => $soldAt]);
        return Json::ok($response, ['card' => $this->cards->find($supplierId, $id)]);
    }

    public function restore(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireAccountingMode($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) $args['id'];
        try {
            $this->service->restore($supplierId, $id);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->log($request, 'small_asset.restored', $id, []);
        return Json::ok($response, ['card' => $this->cards->find($supplierId, $id)]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireAccountingMode($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) $args['id'];
        if (!$this->cards->delete($supplierId, $id)) {
            return Json::error($response, 'not_found', 'Karta nenalezena.', 404);
        }
        $this->log($request, 'small_asset.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * Vygeneruje karty ze všech řádků dokladu klasifikovaných jako drobný majetek.
     * Idempotentní — opakované volání nezaloží duplicity (viz SmallAssetService).
     */
    public function generateFromPurchaseInvoice(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireAccountingMode($this->db, $supplierId, $response, $err)) return $err;
        $invoiceId = (int) $args['id'];
        try {
            $result = $this->service->generateFromPurchaseInvoice($supplierId, $invoiceId, $this->userId($request));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->log($request, 'small_asset.generated', $invoiceId, [
            'created' => count($result['created']),
            'skipped' => $result['skipped'],
        ]);
        return Json::ok($response, [
            'purchase_invoice_id' => $invoiceId,
            'created' => count($result['created']),
            'skipped' => $result['skipped'],
            'cards' => array_map(fn (int $id): ?array => $this->cards->find($supplierId, $id), $result['created']),
        ], 201);
    }

    // ── validace / normalizace ──────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function normalizeCard(array $body): array
    {
        $name = self::nn($body['name'] ?? null);
        if ($name === null) {
            throw $this->err('validation_failed', 'Název je povinný.');
        }
        $acquisition = $this->assertDate($body['acquisition_date'] ?? null, 'acquisition_date');
        if ($acquisition === null) {
            throw $this->err('validation_failed', 'Datum pořízení je povinné.');
        }
        $quantity = $this->assertQuantity($body['quantity'] ?? 1);
        $unitPrice = self::amount($body['unit_price'] ?? null) ?? 0.0;
        // Cena za kartu: buď zadaná, nebo dopočtená z ceny za kus × množství. Dopočet
        // je tu proto, aby ruční karta nešla uložit s cenou 0 jen kvůli tomu, že
        // uživatel vyplnil jen cenu za kus.
        $price = self::amount($body['price'] ?? null) ?? round($unitPrice * $quantity, 2);

        return [
            'purchase_invoice_id' => self::id($body['purchase_invoice_id'] ?? null),
            'purchase_invoice_item_id' => self::id($body['purchase_invoice_item_id'] ?? null),
            'cash_document_id' => self::id($body['cash_document_id'] ?? null),
            'document_ref' => self::nn($body['document_ref'] ?? null),
            'name' => $name,
            'inventory_number' => self::nn($body['inventory_number'] ?? null),
            'vendor_client_id' => self::id($body['vendor_client_id'] ?? null),
            'vendor_name' => self::nn($body['vendor_name'] ?? null),
            'acquisition_date' => $acquisition,
            'put_into_use_date' => $this->assertDate($body['put_into_use_date'] ?? null, 'put_into_use_date'),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'price' => $price,
            'location' => self::nn($body['location'] ?? null),
            'responsible_person' => self::nn($body['responsible_person'] ?? null),
            'notes' => self::nn($body['notes'] ?? null),
            // Karta vzniká vždy v užívání. Vyřazení má vlastní endpoint, aby se rovnou
            // založená „vyřazená" karta nedala vyrobit kolem pravidel v dispose().
            'status' => 'in_use',
        ];
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function normalizeUpdate(int $supplierId, array $existing, array $body): array
    {
        $fields = [];
        if (array_key_exists('name', $body)) {
            $name = self::nn($body['name']);
            if ($name === null) {
                throw $this->err('validation_failed', 'Název je povinný.');
            }
            $fields['name'] = $name;
        }
        foreach (['inventory_number', 'vendor_name', 'location', 'responsible_person', 'notes', 'document_ref'] as $key) {
            if (array_key_exists($key, $body)) {
                $fields[$key] = self::nn($body[$key]);
            }
        }
        if (array_key_exists('vendor_client_id', $body)) {
            $vendorId = self::id($body['vendor_client_id']);
            if ($vendorId !== null) {
                $stmt = $this->db->pdo()->prepare('SELECT 1 FROM clients WHERE id = ? AND supplier_id = ?');
                $stmt->execute([$vendorId, $supplierId]);
                if ($stmt->fetchColumn() === false) {
                    throw $this->err('vendor_not_found', 'Dodavatel nenalezen.');
                }
            }
            $fields['vendor_client_id'] = $vendorId;
        }
        if (array_key_exists('acquisition_date', $body)) {
            $acquisition = $this->assertDate($body['acquisition_date'], 'acquisition_date');
            if ($acquisition === null) {
                throw $this->err('validation_failed', 'Datum pořízení je povinné.');
            }
            // Posun data pořízení za datum vyřazení by prošel kolem chk_sma_disposal_after
            // jako 500 z DB místo srozumitelné 422.
            $disposedAt = $existing['disposed_at'] ?? null;
            if ($disposedAt !== null && $acquisition > (string) $disposedAt) {
                throw $this->err('disposal_before_acquisition', 'Datum pořízení nesmí být po datu vyřazení.');
            }
            $fields['acquisition_date'] = $acquisition;
        }
        if (array_key_exists('put_into_use_date', $body)) {
            $fields['put_into_use_date'] = $this->assertDate($body['put_into_use_date'], 'put_into_use_date');
        }
        if (array_key_exists('quantity', $body)) {
            $fields['quantity'] = $this->assertQuantity($body['quantity']);
        }
        if (array_key_exists('unit_price', $body)) {
            $fields['unit_price'] = self::amount($body['unit_price']) ?? 0.0;
        }
        if (array_key_exists('price', $body)) {
            $price = self::amount($body['price']);
            if ($price === null) {
                throw $this->err('validation_failed', 'Cena je povinná.');
            }
            $fields['price'] = $price;
        }
        return $fields;
    }

    private function assertQuantity(mixed $value): float
    {
        $quantity = round((float) $value, 3);
        if ($quantity <= 0) {
            throw $this->err('validation_failed', 'Množství musí být větší než nula.');
        }
        return $quantity;
    }

    private function assertDate(mixed $value, string $field): ?string
    {
        $v = self::nn($value);
        if ($v === null) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            throw $this->err('validation_failed', "{$field} musí být datum (YYYY-MM-DD).");
        }
        return $v;
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    private static function nn(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private static function id(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $id = (int) $v;
        return $id > 0 ? $id : null;
    }

    private static function amount(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        return round((float) $v, 2);
    }

    private function err(string $code, string $message): PostingException
    {
        return new PostingException($code, $message, 422);
    }

    /** @param array<mixed> $payload */
    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log($action, $this->userId($request), 'small_asset', $id, $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }
}
