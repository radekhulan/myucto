<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\StockDocumentRepository;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use PDO;

/**
 * Podklad pro koncept vydané faktury z příjemky: zboží koupené na zakázku se rovnou
 * přefakturuje odběrateli. Vlastní cenová logika tu žádná není, ceny dává
 * {@see StockItemQuoteService} a doklad zakládá stejná cesta jako POST /api/invoices.
 */
final class ReceiptInvoiceService
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockDocumentRepository $documents,
        private readonly StockItemQuoteService $quotes,
        private readonly InvoiceDefaults $defaults,
        private readonly InvoiceRepository $invoices,
        private readonly TrackingAllocationService $tracking,
    ) {}

    /**
     * Zamkne příjemku a připraví tělo pro založení faktury. Volat v otevřené transakci:
     * zámek brání dvěma souběžným fakturám ze stejných řádků.
     *
     * @param array<string,mixed> $body {client_id, price_level_id?, lines?: list<{line_id, manual_price?}>, allow_duplicate?}
     * @return array{invoice: array<string,mixed>, line_nos: list<int>, duplicate_line_nos: list<int>}
     * @throws StockException
     */
    public function prepare(int $supplierId, int $documentId, array $body, bool $canReadInvoices): array
    {
        $doc = $this->documents->lockForPost($supplierId, $documentId);
        if ($doc === null) {
            throw new StockException('not_found', 'Skladový doklad nenalezen.', 404);
        }
        if ($doc['doc_type'] !== 'receipt') {
            throw new StockException('not_a_receipt', 'Fakturu lze vytvořit jen z příjemky.', 409);
        }
        if ($doc['status'] === 'reversed') {
            throw new StockException('receipt_reversed', 'Stornovaná příjemka už zboží nenese.', 409);
        }
        // Jen zaúčtovaná: vazba drží čísla řádků a úprava konceptu po fakturaci by je posunula.
        if ($doc['status'] !== 'posted') {
            throw new StockException('receipt_not_posted', 'Fakturu lze vytvořit jen ze zaúčtované příjemky.', 409);
        }
        $clientId = is_numeric($body['client_id'] ?? null) ? (int) $body['client_id'] : 0;
        if ($clientId <= 0) {
            throw new StockException('validation_failed', 'Vyberte odběratele.', 422, ['field' => 'client_id']);
        }
        // Před výchozími hodnotami dokladu: ty čtou kartu odběratele.
        $owned = $this->db->pdo()->prepare('SELECT 1 FROM clients WHERE supplier_id = ? AND id = ?');
        $owned->execute([$supplierId, $clientId]);
        if ($owned->fetchColumn() === false) {
            throw new StockException('invalid_client', 'Odběratel nepatří této firmě.', 422, ['client_id' => $clientId]);
        }

        [$lines, $manual] = $this->selectedLines($supplierId, $documentId, $body['lines'] ?? null);
        $lineNos = array_map(static fn (array $l): int => (int) $l['line_no'], $lines);
        $clashes = $this->clashes($supplierId, $documentId, $lineNos, $canReadInvoices);
        if ($clashes !== [] && !filter_var($body['allow_duplicate'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new StockException('receipt_already_invoiced', 'Z vybraných řádků příjemky už faktura vznikla.', 409, $clashes);
        }

        $currencyId = (int) $this->defaults->resolve(['client_id' => $clientId], forNewInvoice: true)['currency_id'];
        $today = date('Y-m-d');
        $quoted = $this->quotes->quote($supplierId, [
            'client_id' => $clientId,
            'price_level_id' => $body['price_level_id'] ?? null,
            'currency' => $this->invoices->currencyCode($currencyId),
            'date' => $today,
            'lines' => array_map(static fn (array $l): array => [
                'key' => (string) $l['id'], 'stock_item_id' => $l['stock_item_id'], 'unit' => null, 'quantity' => (string) $l['qty'],
            ], $lines),
        ])['lines'];

        $vatRates = $this->cardVatRates($supplierId, array_column($lines, 'stock_item_id'));
        $items = [];
        foreach ($lines as $index => $line) {
            if (!isset($vatRates[$line['stock_item_id']])) {
                throw new StockException('stock_item_vat_missing', 'Karta ' . $line['sku'] . ' nemá sazbu DPH, doplňte ji na kartě.', 422, ['line_id' => $line['id']]);
            }
            $price = $quoted[$index]['unit_price'];
            // Karta bez ceny v měně dokladu dopadne stejně jako vědomý příznak: cenu zadá člověk.
            $needsPrice = isset($manual[$line['id']]) || $price === null;
            $items[] = [
                'description' => (string) $line['name'],
                'quantity' => (string) $line['qty'],
                'unit' => (string) $line['unit'],
                'unit_price_without_vat' => $needsPrice ? '0' : $price,
                'list_unit_price' => $needsPrice ? null : $quoted[$index]['list_unit_price'],
                'manual_price' => $needsPrice,
                'vat_rate_id' => $vatRates[$line['stock_item_id']],
                'stock_item_id' => $line['stock_item_id'],
                'warehouse_id' => (int) $doc['warehouse_id'],
                'order_index' => $index,
                'tracking_allocations' => $this->identities($supplierId, $line),
            ];
        }

        return [
            'invoice' => [
                'client_id' => $clientId,
                'invoice_type' => 'invoice',
                'issue_date' => $today,
                'tax_date' => $today,
                'currency_id' => $currencyId,
                'price_level_id' => $body['price_level_id'] ?? null,
                // Nacenění vrací cenu vždy bez DPH, proto doklad v tomhle režimu i u firmy,
                // která jinak zadává ceny s DPH: částky sedí a přepnout to jde v editoru.
                'prices_include_vat' => false,
                'items' => $items,
            ],
            'line_nos' => $lineNos,
            // Řádky, u kterých volající vědomě přebil upozornění na existující fakturu.
            'duplicate_line_nos' => array_values(array_unique(array_merge([], ...array_column($clashes, 'line_nos')))),
        ];
    }

    /**
     * @return array{0: list<array<string,mixed>>, 1: array<int,true>} vybrané řádky a id řádků s příznakem „cenu zadá člověk"
     * @throws StockException
     */
    private function selectedLines(int $supplierId, int $documentId, mixed $selection): array
    {
        $lines = $this->documents->lines($supplierId, $documentId);
        if ($selection === null) {
            if ($lines === []) {
                throw new StockException('validation_failed', 'Příjemka nemá žádné řádky.', 422, ['field' => 'lines']);
            }
            return [$lines, []];
        }
        if (!is_array($selection) || !array_is_list($selection) || $selection === []) {
            throw new StockException('validation_failed', 'lines musí být neprázdný seznam řádků příjemky.', 422, ['field' => 'lines']);
        }
        $wanted = [];
        $manual = [];
        foreach ($selection as $index => $row) {
            $lineId = is_array($row) && is_numeric($row['line_id'] ?? null) ? (int) $row['line_id'] : 0;
            if ($lineId <= 0) {
                throw new StockException('validation_failed', 'Řádek výběru musí mít line_id.', 422, ['index' => $index]);
            }
            $wanted[$lineId] = true;
            if (filter_var($row['manual_price'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $manual[$lineId] = true;
            }
        }
        $picked = array_values(array_filter($lines, static fn (array $l): bool => isset($wanted[$l['id']])));
        if (count($picked) !== count($wanted)) {
            throw new StockException('invalid_line', 'Vybraný řádek na příjemce není.', 422, ['field' => 'lines']);
        }
        return [$picked, $manual];
    }

    /**
     * Faktury, které už vybrané řádky pokrývají. Volající může upozornění vědomě přebít
     * (`allow_duplicate`), stornovaná faktura se nepočítá. Dobropis řádky neuvolní.
     *
     * @param list<int> $lineNos
     * @return list<array<string,mixed>>
     */
    private function clashes(int $supplierId, int $documentId, array $lineNos, bool $canReadInvoices): array
    {
        $clashes = [];
        foreach ($this->documents->salesInvoicesOfReceipt($supplierId, $documentId, $canReadInvoices) as $invoice) {
            $shared = array_values(array_intersect($invoice['line_nos'], $lineNos));
            if (($invoice['status'] ?? null) !== 'cancelled' && $shared !== []) {
                $clashes[] = ['line_nos' => $shared] + $invoice;
            }
        }
        return $clashes;
    }

    /**
     * @param list<int> $itemIds
     * @return array<int,int> karta => sazba DPH
     */
    private function cardVatRates(int $supplierId, array $itemIds): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, vat_rate_id FROM stock_items
              WHERE supplier_id = ? AND vat_rate_id IS NOT NULL AND id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')'
        );
        $stmt->execute(array_merge([$supplierId], $itemIds));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: []);
    }

    /**
     * Sériová čísla a šarže řádku příjemky ve tvaru výběru na faktuře. Bez umístění: výdej
     * ho dohledá podle aktuální zásoby kusu ({@see InvoiceItemTracking}).
     *
     * @param array<string,mixed> $line
     * @return list<array<string,mixed>>
     */
    private function identities(int $supplierId, array $line): array
    {
        if ($line['tracking_mode'] === 'none') {
            return [];
        }
        // Převzatý doklad nese kusy jen v alokacích, ne ve vstupu řádku.
        $allocations = $line['tracking_allocations'] ?: $this->tracking->canonicalAllocationsForLine($supplierId, (int) $line['id'], 'in');
        return array_map(static fn (array $a): array => [
            'quantity' => (string) $a['quantity'],
            'serial_number' => $a['serial_number'] ?? null,
            'lot_code' => $a['lot_code'] ?? null,
            'expires_on' => $a['expires_on'] ?? null,
        ], $allocations);
    }
}
