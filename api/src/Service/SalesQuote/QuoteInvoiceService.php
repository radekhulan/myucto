<?php

declare(strict_types=1);

namespace MyInvoice\Service\SalesQuote;

use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Codebook\UnitPrecision;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use MyInvoice\Service\Invoice\InvoiceMath;
use MyInvoice\Service\Invoice\UnitPricePrecision;
use MyInvoice\Service\NumberSeries\NumberSeriesKinds;
use MyInvoice\Service\NumberSeries\NumberSeriesSelection;
use MyInvoice\Service\Oss\OssItemPlanner;
use MyInvoice\Service\Validation\InvoiceValidation;
use PDO;

final class QuoteInvoiceService
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceCalculator $calculator,
        private readonly InvoiceDefaults $defaults,
        private readonly TenantReferenceGuard $tenantRefs,
        private readonly OssItemPlanner $vatPlanner,
        private readonly UnitPrecision $unitPrecision,
        private readonly DocumentLockService $documentLocks,
        private readonly NumberSeriesSelection $numberSeries,
    ) {}

    public function convert(int $supplierId, int $userId, int $quoteId, array $body): int
    {
        $type = $body['type'] ?? 'invoice';
        if (!in_array($type, ['invoice', 'proforma'], true)) {
            throw new QuoteException('invalid_invoice_type', 'Zvolte vydanou nebo zálohovou fakturu.');
        }

        $key = $body['idempotency_key'] ?? null;
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9._-]{1,100}$/D', $key)) {
            throw new QuoteException('invalid_idempotency_key', 'Převod vyžaduje platný identifikátor požadavku.');
        }

        $selection = $this->normalizeSelection($body['items'] ?? null);
        $percent = $this->advanceValue($body['advance_percent'] ?? null, 100.0, 4);
        $amount = $this->advanceValue($body['advance_amount'] ?? null, 9999999999.99, 2);
        if (($percent !== null && $amount !== null) || ($type !== 'proforma' && ($percent !== null || $amount !== null))) {
            throw new QuoteException('invalid_advance_amount', 'U zálohové faktury zadejte částku nebo procento.');
        }
        $requestHash = hash('sha256', json_encode([$type, $selection, $percent, $amount], JSON_THROW_ON_ERROR));

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $quote = self::acceptedQuote($pdo, $supplierId, $quoteId);
            $existing = $pdo->prepare(
                'SELECT invoice_id, request_hash FROM sales_quote_documents
                  WHERE supplier_id = ? AND quote_id = ? AND idempotency_key = ?',
            );
            $existing->execute([$supplierId, $quoteId, $key]);
            $previous = $existing->fetch(PDO::FETCH_ASSOC);
            if ($previous !== false) {
                if (!hash_equals((string) $previous['request_hash'], $requestHash)) {
                    throw new QuoteException('conversion_conflict', 'Tento identifikátor již patří jinému převodu nabídky.', 409);
                }
                $pdo->commit();
                return (int) $previous['invoice_id'];
            }
            if ($type === 'invoice' && $this->db->hasTable('sales_quote_orders')) {
                self::assertNoPendingOrder($pdo, $supplierId, $quoteId);
            }

            $accepted = self::acceptedItems($quote);
            $remaining = $this->summary($supplierId, $quoteId, $quote)['quantities'];
            $items = $this->invoiceItems($accepted, $remaining, $selection);
            $originByOrder = array_column($items, 'sales_quote_item_key', 'order_index');
            $depositOnly = $percent !== null || $amount !== null;
            if ($depositOnly) {
                $originByOrder = [];
                $items = $this->advanceItems($items, $quote, $percent, $amount);
            }

            $payload = $this->invoicePayload($supplierId, $quote, $type, $items);
            $invoiceId = $this->invoices->createDraft($payload, $userId);
            $this->invoices->replaceItems($invoiceId, $payload['items']);
            QuoteInvoiceOrigin::writeKeys($pdo, $invoiceId, $originByOrder);
            $seriesKind = NumberSeriesKinds::issuedKind($type);
            $this->numberSeries->apply('invoices', $supplierId, $invoiceId, $payload, $seriesKind, 0, $userId, isNew: true);
            $this->calculator->recompute($invoiceId);

            $insert = $pdo->prepare(
                'INSERT INTO sales_quote_documents (supplier_id, quote_id, version_id, invoice_id, idempotency_key, request_hash)
                 VALUES (?, ?, ?, ?, ?, ?)',
            );
            $insert->execute([$supplierId, $quoteId, $quote['current_version_id'], $invoiceId, $key, $requestHash]);
            $pdo->commit();
            return $invoiceId;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \InvalidArgumentException) {
                throw new QuoteException('invalid_invoice_draft', $e->getMessage());
            }
            throw $e;
        }
    }

    public function summary(int $supplierId, int $quoteId, array $quote): array
    {
        $rows = self::documentRows($this->db->pdo(), $supplierId, [$quoteId]);
        return $this->summarize($quote, $rows);
    }

    public function listSummaries(int $supplierId, array $quotes): array
    {
        if ($quotes === []) {
            return [];
        }
        $rowsByQuote = [];
        foreach (self::documentRows($this->db->pdo(), $supplierId, array_column($quotes, 'id')) as $row) {
            $rowsByQuote[$row['quote_id']][] = $row;
        }
        $summaries = [];
        foreach ($quotes as $quote) {
            $id = (int) $quote['id'];
            $summaries[$id] = $this->summarize($quote, $rowsByQuote[$id] ?? []);
        }
        return $summaries;
    }

    private function summarize(array $quote, array $rows): array
    {
        $items = self::acceptedItems($quote);
        $usage = self::usage($rows, $items);
        $documents = [];
        $billing = ['billed' => 0.0, 'draft' => 0.0, 'remaining' => 0.0, 'advance_requested' => 0.0, 'advance_paid' => 0.0];
        $pricesIncludeVat = self::pricesIncludeVat($quote);
        $basis = $pricesIncludeVat ? 'total_with_vat' : 'total_without_vat';
        foreach ($rows as $row) {
            $invoiceId = (int) $row['invoice_id'];
            $type = (string) $row['invoice_type'];
            $status = (string) $row['status'];
            if (!isset($documents[$invoiceId])) {
                $paid = (float) $row['paid_total'];
                if ($paid === 0.0 && $status === 'paid') {
                    $paid = (float) $row['document_total'];
                }
                $documents[$invoiceId] = [
                    'invoice_id' => $invoiceId,
                    'type' => $type,
                    'status' => $status,
                    'number' => $row['varsymbol'],
                    'amount' => (float) $row['document_total'],
                    'paid_total' => $paid,
                ];
                if ($type === 'proforma' && !in_array($status, ['draft', 'cancelled'], true)) {
                    $billing['advance_requested'] += (float) $row['document_total'];
                    $billing['advance_paid'] += $paid;
                }
            }
            if (!isset($items[(string) $row['sales_quote_item_key']]) || $status === 'cancelled') {
                continue;
            }
            if ($type === 'invoice') {
                $billing[$status === 'draft' ? 'draft' : 'billed'] += (float) $row[$basis];
            } elseif ($type === 'credit_note' && $status !== 'draft') {
                $billing['billed'] += (float) $row[$basis];
            }
        }

        $quantities = [];
        $remainingRows = [];
        foreach ($items as $key => $item) {
            $time = !empty($item['duration_minutes']);
            $billed = max(0.0, $usage[$key]['billed']);
            $agreed = $time ? (float) $item['duration_minutes'] : (float) $item['quantity'];
            $unallocated = max(0.0, $agreed - $billed - $usage[$key]['draft']);
            $remaining = round($time ? $unallocated / 60 : $unallocated, 3);
            $quantities[$key] = [
                'billed_quantity' => round($time ? $billed / 60 : $billed, 3),
                'draft_quantity' => round($time ? $usage[$key]['draft'] / 60 : $usage[$key]['draft'], 3),
                'remaining_quantity' => $remaining,
            ];
            if ($remaining > 0) {
                $remainingRows[] = $this->line($item, $remaining, count($remainingRows));
            }
        }
        $remainingTotals = InvoiceMath::compute($remainingRows, pricesIncludeVat: $pricesIncludeVat)['totals'];
        $billing['remaining'] = $remainingTotals[$pricesIncludeVat ? 'with_vat' : 'without_vat'];

        return [
            'documents' => array_values($documents),
            'billing' => array_map(static fn(float $value): float => round($value, 2), $billing),
            'quantities' => $quantities,
        ];
    }

    public static function assertFinalCanUseQuoteItems(PDO $pdo, int $supplierId, int $proformaId, array $items, bool $orderLinksAvailable): void
    {
        $check = $pdo->prepare(
            'SELECT quote_id FROM sales_quote_documents WHERE supplier_id = ? AND invoice_id = ? FOR UPDATE',
        );
        $check->execute([$supplierId, $proformaId]);
        $quoteId = $check->fetchColumn();
        if ($quoteId === false) {
            return;
        }
        if (array_filter(array_column($items, 'sales_quote_item_key')) === []) {
            throw new QuoteException('quote_deposit_requires_offer', 'Částkovou zálohu vyúčtujte na faktuře vytvořené z položek nabídky.', 409);
        }

        $quote = self::acceptedQuote($pdo, $supplierId, (int) $quoteId);
        if ($orderLinksAvailable) {
            self::assertNoPendingOrder($pdo, $supplierId, (int) $quoteId);
        }
        self::assertAvailableQuantities(
            $pdo,
            $supplierId,
            $quote,
            $items,
            'Část rozsahu zálohy již byla použita v jiné faktuře. Vytvořte fakturu ze zbývajících položek nabídky a zálohu odečtěte v editoru.',
        );
    }

    public static function assertUncancelCanRestoreQuoteItems(PDO $pdo, int $supplierId, int $invoiceId, bool $orderLinksAvailable): void
    {
        $query = $pdo->prepare(
            "SELECT qd.quote_id
               FROM invoices i
               JOIN sales_quote_documents qd ON qd.supplier_id = i.supplier_id
                   AND qd.invoice_id IN (i.id, i.parent_invoice_id)
              WHERE i.supplier_id = ? AND i.id = ? AND i.invoice_type = 'invoice'
              ORDER BY qd.invoice_id = i.id DESC
              LIMIT 1",
        );
        $query->execute([$supplierId, $invoiceId]);
        $quoteId = $query->fetchColumn();
        if ($quoteId === false) {
            return;
        }

        $quote = self::acceptedQuote($pdo, $supplierId, (int) $quoteId);
        $invoice = $pdo->prepare('SELECT status FROM invoices WHERE supplier_id = ? AND id = ? FOR UPDATE');
        $invoice->execute([$supplierId, $invoiceId]);
        if ($invoice->fetchColumn() !== 'cancelled') {
            throw new QuoteException('race_condition', 'Faktura byla mezitím změněna.', 409);
        }
        $items = QuoteInvoiceOrigin::rows($pdo, $invoiceId, lock: true);
        if ($items === []) {
            return;
        }
        if ($orderLinksAvailable) {
            self::assertNoPendingOrder($pdo, $supplierId, (int) $quoteId);
        }
        self::assertAvailableQuantities(
            $pdo,
            $supplierId,
            $quote,
            $items,
            'Rozsah nabídky mezitím převzala jiná faktura. Před zrušením storna upravte navazující fakturaci.',
        );
    }

    private static function assertAvailableQuantities(PDO $pdo, int $supplierId, array $quote, array $items, string $message): void
    {
        $accepted = self::acceptedItems($quote);
        $usage = self::usage(self::documentRows($pdo, $supplierId, [(int) $quote['id']], lock: true), $accepted);
        foreach ($items as $item) {
            $key = $item['sales_quote_item_key'] ?? null;
            if ($key === null) {
                continue;
            }
            $source = $accepted[$key] ?? null;
            $time = !empty($source['duration_minutes']);
            $quantity = $time ? (float) ($item['duration_minutes'] ?? ((float) $item['quantity'] * 60)) : (float) $item['quantity'];
            $available = (float) ($source[$time ? 'duration_minutes' : 'quantity'] ?? 0) - max(0.0, $usage[$key]['billed'] ?? 0) - ($usage[$key]['draft'] ?? 0);
            if ($source === null || round($quantity, 3) > round($available, 3)) {
                throw new QuoteException('quote_quantity_unavailable', $message, 409);
            }
            $usage[$key]['draft'] += $quantity;
        }
    }

    public static function assertNoPendingOrder(PDO $pdo, int $supplierId, int $quoteId): void
    {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM sales_quote_orders qo
              JOIN sales_orders o ON o.id = qo.order_id AND o.supplier_id = qo.supplier_id
             WHERE qo.supplier_id = ? AND qo.quote_id = ? AND o.commercial_status <> 'cancelled'
               AND NOT EXISTS (
                   SELECT 1 FROM sales_order_invoice_links l WHERE l.order_id = o.id AND l.supplier_id = qo.supplier_id
               )
             LIMIT 1",
        );
        $stmt->execute([$supplierId, $quoteId]);
        if ($stmt->fetchColumn() !== false) {
            throw new QuoteException('quote_has_pending_order', 'Nabídka už je v prodejní objednávce. Vytvořte fakturu z této objednávky.', 409);
        }
    }

    private static function acceptedQuote(PDO $pdo, int $supplierId, int $quoteId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, client_id, project_id, currency_id, quote_number, status, current_version_id, accepted_section_ids
               FROM sales_quotes WHERE supplier_id = ? AND id = ? FOR UPDATE',
        );
        $stmt->execute([$supplierId, $quoteId]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($header === false) {
            throw new QuoteException('quote_not_found', 'Nabídka nebyla nalezena.', 404);
        }
        if ($header['status'] !== 'accepted') {
            throw new QuoteException('quote_not_accepted', 'Převést lze pouze přijatou nabídku.', 409);
        }

        $version = $pdo->prepare(
            'SELECT data_json FROM sales_quote_versions WHERE supplier_id = ? AND quote_id = ? AND id = ? FOR UPDATE',
        );
        $version->execute([$supplierId, $quoteId, $header['current_version_id']]);
        $data = $version->fetchColumn();
        if ($data === false) {
            throw new QuoteException('quote_version_not_found', 'Přijatá verze nabídky nebyla nalezena.', 409);
        }
        $header['accepted_section_ids'] = json_decode((string) ($header['accepted_section_ids'] ?? '[]'), true, flags: JSON_THROW_ON_ERROR);
        return $header + json_decode((string) $data, true, flags: JSON_THROW_ON_ERROR);
    }

    private static function acceptedItems(array $quote): array
    {
        $selected = array_fill_keys($quote['accepted_section_ids'] ?? [], true);
        $items = [];
        foreach ($quote['sections'] ?? [] as $section) {
            if ($section['kind'] !== 'required' && ($section['kind'] !== 'optional' || !isset($selected[$section['id']]))) {
                continue;
            }
            foreach ($section['items'] as $item) {
                $items[$item['id']] = $item;
            }
        }
        return $items;
    }

    private static function documentRows(PDO $pdo, int $supplierId, array $quoteIds, bool $lock = false): array
    {
        $placeholders = implode(', ', array_fill(0, count($quoteIds), '?'));
        $stmt = $pdo->prepare(
            "WITH source_documents AS (
                SELECT qd.quote_id, i.id, i.supplier_id
                  FROM sales_quote_documents qd
                  JOIN invoices i ON i.id = qd.invoice_id AND i.supplier_id = qd.supplier_id
                 WHERE qd.supplier_id = ? AND qd.quote_id IN ({$placeholders})
            ), final_documents AS (
                SELECT s.quote_id, i.id, i.supplier_id
                  FROM source_documents s
                  JOIN invoices i ON i.parent_invoice_id = s.id AND i.supplier_id = s.supplier_id
                 WHERE i.invoice_type = 'invoice'
            ), billed_documents AS (
                SELECT quote_id, id, supplier_id FROM source_documents
                UNION
                SELECT quote_id, id, supplier_id FROM final_documents
            ), quote_documents AS (
                SELECT quote_id, id, supplier_id FROM billed_documents
                UNION
                SELECT d.quote_id, i.id, i.supplier_id
                  FROM billed_documents d
                  JOIN invoices i ON i.parent_invoice_id = d.id AND i.supplier_id = d.supplier_id
                 WHERE i.invoice_type = 'credit_note'
            )
            SELECT d.quote_id, i.id AS invoice_id, i.invoice_type, i.status, i.varsymbol, i.total_with_vat AS document_total, i.paid_total,
                   ii.sales_quote_item_key, ii.quantity, ii.duration_minutes, ii.total_without_vat, ii.total_with_vat,
                   EXISTS (
                        SELECT 1 FROM invoice_items original
                         WHERE original.invoice_id = i.parent_invoice_id
                           AND original.sales_quote_item_key = ii.sales_quote_item_key
                           AND original.unit_price_without_vat = ii.unit_price_without_vat
                   ) AS restores_quantity
              FROM quote_documents d
              JOIN invoices i ON i.id = d.id AND i.supplier_id = d.supplier_id
         LEFT JOIN invoice_items ii ON ii.invoice_id = d.id AND ii.sales_quote_item_key IS NOT NULL
             ORDER BY i.id DESC, ii.id" . ($lock ? ' FOR UPDATE' : ''),
        );
        $stmt->execute([$supplierId, ...$quoteIds]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function usage(array $rows, array $items): array
    {
        $usage = array_fill_keys(array_keys($items), ['billed' => 0.0, 'draft' => 0.0]);
        foreach ($rows as $row) {
            $key = (string) $row['sales_quote_item_key'];
            if (!isset($usage[$key]) || $row['status'] === 'cancelled') {
                continue;
            }
            $quantity = !empty($items[$key]['duration_minutes'])
                ? (float) ($row['duration_minutes'] ?? round((float) $row['quantity'] * 60))
                : (float) $row['quantity'];
            if ($row['invoice_type'] === 'invoice') {
                $usage[$key][$row['status'] === 'draft' ? 'draft' : 'billed'] += $quantity;
            } elseif ($row['invoice_type'] === 'credit_note' && $row['status'] !== 'draft' && !empty($row['restores_quantity'])) {
                $usage[$key]['billed'] += $quantity;
            }
        }
        return $usage;
    }

    private function normalizeSelection(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > 500) {
            throw new QuoteException('invalid_invoice_items', 'Vyberte položky nabídky k převodu.');
        }
        $selection = [];
        foreach ($raw as $row) {
            $key = is_array($row) ? ($row['id'] ?? null) : null;
            $quantity = is_array($row) ? ($row['quantity'] ?? null) : null;
            if (!is_string($key) || !preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD', $key)
                || isset($selection[strtolower($key)]) || !is_numeric($quantity) || !is_finite((float) $quantity)
                || (float) $quantity <= 0 || (float) $quantity > 9999999.999 || abs(round((float) $quantity, 3) - (float) $quantity) > 0.0000001) {
                throw new QuoteException('invalid_invoice_items', 'Každá položka se vybírá jednou s kladným množstvím na nejvýše tři desetinná místa.');
            }
            $selection[strtolower($key)] = round((float) $quantity, 3);
        }
        ksort($selection);
        return $selection;
    }

    private function advanceValue(mixed $value, float $max, int $decimals): ?float
    {
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0 || (float) $value > $max
            || abs(round((float) $value, $decimals) - (float) $value) > 0.0000001) {
            throw new QuoteException('invalid_advance_amount', 'Zadejte platnou kladnou částku nebo procento zálohy.');
        }
        return round((float) $value, $decimals);
    }

    private function invoiceItems(array $accepted, array $remaining, ?array $selection): array
    {
        if ($selection !== null && array_diff_key($selection, $accepted) !== []) {
            throw new QuoteException('item_not_accepted', 'Výběr obsahuje položku mimo přijatý rozsah nabídky.', 409);
        }
        $items = [];
        foreach ($accepted as $key => $item) {
            $available = $remaining[$key]['remaining_quantity'];
            $quantity = $selection === null ? $available : ($selection[$key] ?? 0.0);
            if ($quantity > $available) {
                throw new QuoteException('quote_quantity_unavailable', 'Zvolené množství už není k dispozici pro převod. Obnovte nabídku.', 409);
            }
            if ($quantity > 0) {
                $items[] = $this->line($item, $quantity, count($items));
            }
        }
        if ($items === []) {
            throw new QuoteException('no_items_to_convert', 'Nabídka neobsahuje položky k převodu.', 409);
        }
        return $items;
    }

    private function line(array $item, float $quantity, int $order): array
    {
        $minutes = empty($item['duration_minutes']) ? null : (int) round($quantity * 60);
        if ($minutes !== null && ($minutes <= 0 || abs(round($minutes / 60, 3) - $quantity) > 0.000001)) {
            throw new QuoteException('invalid_duration', 'Část časové položky musí odpovídat celému počtu minut.');
        }

        $listPrice = (float) $item['unit_price'];
        $effectivePrice = UnitPricePrecision::normalize($listPrice * (1 - (float) ($item['discount_percent'] ?? 0) / 100));
        return [
            'description' => (string) $item['description'],
            'quantity' => $quantity,
            'duration_minutes' => $minutes,
            'unit' => (string) $item['unit'],
            'unit_price_without_vat' => $effectivePrice,
            'list_unit_price' => $listPrice,
            'vat_rate' => (float) $item['vat_rate'],
            'vat_rate_snapshot' => (float) $item['vat_rate'],
            'stock_item_id' => $item['stock_item_id'] ?? null,
            'warehouse_id' => $item['warehouse_id'] ?? null,
            'sales_quote_item_key' => $item['id'],
            'order_index' => $order,
        ];
    }

    private static function pricesIncludeVat(array $quote): bool
    {
        return (bool) ($quote['prices_include_vat'] ?? $quote['header']['prices_include_vat'] ?? false);
    }

    private function advanceItems(array $items, array $quote, ?float $percent, ?float $amount): array
    {
        $gross = self::pricesIncludeVat($quote);
        $computed = InvoiceMath::compute($items, pricesIncludeVat: $gross);
        $total = (int) round($computed['totals'][$gross ? 'with_vat' : 'without_vat'] * 100);
        $target = $amount === null ? (int) round($total * (float) $percent / 100) : (int) round($amount * 100);
        if ($target <= 0 || $target > $total) {
            throw new QuoteException('invalid_advance_amount', 'Záloha musí být kladná a nejvýše ve výši vybrané části nabídky.');
        }

        $en = ($quote['language'] ?? $quote['header']['language'] ?? 'cs') === 'en';
        $rows = [];
        $cumulative = 0;
        $allocated = 0;
        foreach ($items as $index => $item) {
            $cents = (int) round($computed['items'][$index][$gross ? 'with' : 'base'] * 100);
            $cumulative += $cents;
            $subtotal = (int) round($target * ($cumulative / $total));
            $advance = $subtotal - $allocated;
            $allocated = $subtotal;
            if ($advance > 0) {
                $rows[] = [
                    'description' => ($en ? 'Advance: ' : 'Záloha: ') . $item['description'],
                    'quantity' => 1,
                    'unit' => $item['unit'],
                    'unit_price_without_vat' => $advance / 100,
                    'vat_rate' => $item['vat_rate'],
                    'order_index' => count($rows),
                ];
            }
        }
        return $rows;
    }

    private function invoicePayload(int $supplierId, array $quote, string $type, array $items): array
    {
        $language = $quote['language'] ?? $quote['header']['language'] ?? 'cs';
        $ref = (string) $quote['quote_number'];
        $payload = [
            'invoice_type' => $type,
            'client_id' => (int) $quote['client_id'],
            'project_id' => $quote['project_id'] ?? null,
            'currency_id' => (int) $quote['currency_id'],
            'prices_include_vat' => self::pricesIncludeVat($quote),
            'language' => $language,
            'supplier_order_number' => $ref,
            'note_below_items' => ($language === 'en' ? 'Quote: ' : 'Nabídka: ') . $ref,
        ];
        $badRefs = $this->tenantRefs->violations($supplierId, $payload, ['client_id', 'project_id', 'currency_id']);
        if ($badRefs !== []) {
            throw new QuoteException('invalid_reference', TenantReferenceGuard::message($badRefs));
        }
        $payload = $this->defaults->resolve($payload, forNewInvoice: true);
        $date = DocumentLockService::invoiceRefDate($payload) ?? $payload['issue_date'];
        $lock = $this->documentLocks->forDate($supplierId, $date);
        if ($lock->inClosedPeriod || $lock->inClosingPeriod || $lock->dateLocked) {
            throw new QuoteException('period_closed', 'Faktura spadá do uzamčeného účetního období.', 409);
        }

        try {
            $payload['items'] = $this->vatPlanner->planIssuedItems(
                $supplierId,
                $payload['client_id'],
                $date,
                (bool) $payload['reverse_charge'],
                $items,
            );
        } catch (\RuntimeException $e) {
            if (TransactionFailure::aborted($e)) {
                throw $e;
            }
            throw new QuoteException('invoice_vat_review', $e->getMessage());
        }
        if ($this->unitPrecision->check($payload['items']) !== []) {
            throw new QuoteException('invalid_quantity_precision', UnitPrecision::ERROR_MESSAGE);
        }
        $errors = InvoiceValidation::invoice(
            $payload,
            $this->invoices->vatRateMap(),
            $this->invoices->vatRateCountryMap(),
            $this->vatPlanner->domesticCountry($supplierId),
        );
        if ($errors !== []) {
            throw new QuoteException('invalid_invoice_draft', implode(' ', array_merge(...array_values($errors))));
        }

        return $payload;
    }
}
