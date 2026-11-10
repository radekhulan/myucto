<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\StockDocumentRepository;
use MyInvoice\Repository\StockTrackingRepository;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use MyInvoice\Service\Oss\OssClientContext;
use MyInvoice\Service\Oss\OssItemPlanner;
use MyInvoice\Service\Vat\VatStatusService;
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
        private readonly StockTrackingRepository $trackingUnits,
        private readonly OssItemPlanner $ossPlanner,
        private readonly VatStatusService $vatStatus,
    ) {}

    /** Kód v `_warnings`: sériová čísla nebo šarže řádku už nejsou na skladě příjemky. */
    public const WARNING_TRACKING_UNAVAILABLE = 'receipt_tracking_unavailable';
    /** Kód v `_warnings`: zahraniční odběratel mimo OSS a bez přenesení daně nese tuzemskou sazbu. */
    public const WARNING_FOREIGN_DOMESTIC_VAT = 'receipt_foreign_client_domestic_vat';

    /**
     * Zamkne příjemku a připraví tělo pro založení faktury. Volat v otevřené transakci:
     * zámek brání dvěma souběžným fakturám ze stejných řádků.
     *
     * @param array<string,mixed> $body {client_id, price_level_id?, lines?: list<{line_id, manual_price?}>, allow_duplicate?}
     * @return array{invoice: array<string,mixed>, line_ids: list<int>, line_nos: list<int>, duplicate_line_nos: list<int>, warnings: array<string,array<string,mixed>>}
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

        $defaults = $this->defaults->resolve(['client_id' => $clientId], forNewInvoice: true);
        $currencyId = (int) $defaults['currency_id'];
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

        $vatStatus = $this->vatStatus->statusAt($supplierId, $today);
        $isVatPayer = $vatStatus['is_vat_payer'];
        $exemptRateId = $isVatPayer ? null : VatStatusService::exemptRateIdAt($this->db->pdo(), $today);
        $client = $this->ossPlanner->clientContext($clientId);
        // Stejně jako editor faktury: plátce podle karty odběratele, identifikovaná osoba u firmy
        // z jiného státu EU (doložka na dokladu a souhrnné hlášení), čistý neplátce nikdy.
        $identifiedReverseCharge = !$isVatPayer && $vatStatus['is_identified'] && $this->isEuBusinessAbroad($supplierId, $client);
        $reverseCharge = $identifiedReverseCharge || ($isVatPayer && !empty($defaults['reverse_charge']));
        $foreignDomestic = !$reverseCharge && $this->isCrossBorderOutsideOss($supplierId, $client);
        $cardRates = $this->cardVatRates($supplierId, array_column($lines, 'stock_item_id'));
        $ratePercents = $this->invoices->vatRateMap();
        $taken = $this->heldByOtherDrafts($supplierId, $documentId);
        $items = [];
        $ossNotes = [];
        $foreignDomesticLines = [];
        $unavailable = [];
        foreach ($lines as $index => $line) {
            if ($exemptRateId !== null) {
                $tax = ['vat_rate_id' => $exemptRateId];
            } else {
                $cardRate = $cardRates[$line['stock_item_id']] ?? null;
                if ($cardRate === null) {
                    throw new StockException('stock_item_vat_missing', 'Karta ' . $line['sku'] . ' nemá sazbu DPH, doplňte ji na kartě.', 422, ['line_id' => $line['id']]);
                }
                $plan = $this->ossPlanner->planIssuedItem($supplierId, $client, (float) ($ratePercents[$cardRate] ?? 0.0), (string) $line['unit'], $today, $reverseCharge);
                if ($plan->isRejected()) {
                    throw new StockException('invoice_tax_review_required', $plan->errorMessage() ?? 'Daňové zařazení vyžaduje kontrolu.', 422, ['line_id' => $line['id']]);
                }
                $tax = $plan->itemColumns();
                foreach ($plan->warnings() as $message) {
                    $ossNotes[] = ['item' => $index + 1, 'manual_review' => $plan->needsManualReview(), 'message' => $message];
                }
                if ($foreignDomestic && !$tax['oss_applicable'] && $tax['vat_rate_snapshot'] > 0) {
                    $foreignDomesticLines[] = ['line_id' => $line['id'], 'line_no' => $line['line_no'], 'sku' => $line['sku']];
                }
            }
            [$identities, $missing] = $this->availableIdentities($supplierId, $line, (int) $doc['warehouse_id'], $taken);
            if ($missing !== []) {
                $unavailable[] = ['line_id' => $line['id'], 'line_no' => $line['line_no'], 'sku' => $line['sku'], 'units' => $missing];
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
                'stock_item_id' => $line['stock_item_id'],
                'warehouse_id' => (int) $doc['warehouse_id'],
                'order_index' => $index,
                'tracking_allocations' => $identities,
            ] + $tax;
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
                'reverse_charge' => $reverseCharge,
                'vat_classification_code' => $identifiedReverseCharge ? '22' : null,
                'items' => $items,
            ],
            'line_ids' => array_map(static fn (array $l): int => (int) $l['id'], $lines),
            'line_nos' => $lineNos,
            // Řádky, u kterých volající vědomě přebil upozornění na existující fakturu.
            'duplicate_line_nos' => array_values(array_unique(array_merge([], ...array_column($clashes, 'line_nos')))),
            'warnings' => array_filter([
                self::WARNING_TRACKING_UNAVAILABLE => $unavailable === [] ? null : ['lines' => $unavailable],
                self::WARNING_FOREIGN_DOMESTIC_VAT => $foreignDomesticLines === [] ? null : ['lines' => $foreignDomesticLines],
                'oss_derived_notes' => $ossNotes === [] ? null : ['items' => $ossNotes],
            ]),
        ];
    }

    /**
     * Chyba z {@see \MyInvoice\Action\Invoice\CreateInvoiceAction} mluví o pořadí řádku faktury.
     * Volající zná jen řádky příjemky, proto se pořadí přeloží na jejich `line_id`.
     *
     * @param array<string,mixed> $error
     * @param list<int> $lineIds id řádku příjemky podle pořadí na faktuře
     * @return array<string,mixed>
     */
    public static function errorWithLineIds(array $error, array $lineIds): array
    {
        $translate = static function (array $row) use ($lineIds): array {
            if (is_numeric($row['index'] ?? null) && isset($lineIds[(int) $row['index']])) {
                $row['line_id'] = $lineIds[(int) $row['index']];
                unset($row['index']);
            }
            return $row;
        };
        $error = $translate($error);
        if (is_array($error['items'] ?? null)) {
            $error['items'] = array_map(static fn (mixed $row): mixed => is_array($row) ? $translate($row) : $row, $error['items']);
        }
        if (is_array($error['fields'] ?? null)) {
            $fields = [];
            foreach ($error['fields'] as $field => $messages) {
                $matched = preg_match('/^items\.(\d+)(.*)$/D', (string) $field, $m) === 1 && isset($lineIds[(int) $m[1]]);
                $fields[$matched ? 'lines.' . $lineIds[(int) $m[1]] . $m[2] : $field] = $messages;
            }
            $error['fields'] = $fields;
        }
        return $error;
    }

    private function isEuBusinessAbroad(int $supplierId, OssClientContext $client): bool
    {
        $country = OssClientContext::iso2OrNull($client->countryIso2);
        return $country !== null && $country !== $this->ossPlanner->domesticCountry($supplierId) && $client->isEu && $client->hasVatId();
    }

    /** Odběratel z ciziny, u kterého plnění do OSS nepatří: firma s DIČ v EU nebo kdokoli mimo EU. */
    private function isCrossBorderOutsideOss(int $supplierId, OssClientContext $client): bool
    {
        $country = OssClientContext::iso2OrNull($client->countryIso2);
        return $country !== null
            && $country !== $this->ossPlanner->domesticCountry($supplierId)
            && (!$client->isEu || $client->hasVatId());
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
     * Sériová čísla a šarže řádku příjemky ve tvaru výběru na faktuře, jen v množství, které
     * je pořád na skladě příjemky. Bez umístění: výdej ho dohledá podle aktuální zásoby kusu
     * ({@see InvoiceItemTracking}).
     *
     * @param array<string,mixed> $line
     * @param array<string,int> $taken množství v tisícinách, které už drží jiné koncepty a dřívější řádky
     * @return array{0: list<array<string,mixed>>, 1: list<array<string,mixed>>} výběr a nedostupné kusy
     */
    private function availableIdentities(int $supplierId, array $line, int $warehouseId, array &$taken): array
    {
        if ($line['tracking_mode'] === 'none') {
            return [[], []];
        }
        $itemId = (int) $line['stock_item_id'];
        // Převzatý doklad nese kusy jen v alokacích, ne ve vstupu řádku.
        $allocations = $line['tracking_allocations'] ?: $this->tracking->canonicalAllocationsForLine($supplierId, (int) $line['id'], 'in');
        $identities = [];
        $missing = [];
        foreach ($allocations as $allocation) {
            $key = self::trackingKey($allocation);
            $requestedT = StockValuation::qtyToT((string) $allocation['quantity']);
            $stockT = array_sum(array_map(
                static fn (array $row): int => StockValuation::qtyToT($row['quantity']),
                $this->trackingUnits->stockByKeys($supplierId, $itemId, $warehouseId, [$key]),
            ));
            $heldKey = $itemId . '|' . mb_strtolower($key);
            $availableT = max(0, $stockT - ($taken[$heldKey] ?? 0));
            $usedT = min($requestedT, $availableT);
            $taken[$heldKey] = ($taken[$heldKey] ?? 0) + $usedT;
            $identity = [
                'serial_number' => $allocation['serial_number'] ?? null,
                'lot_code' => $allocation['lot_code'] ?? null,
                'expires_on' => $allocation['expires_on'] ?? null,
            ];
            if ($usedT > 0) {
                $identities[] = ['quantity' => StockValuation::tToDecimal($usedT)] + $identity;
            }
            if ($usedT < $requestedT) {
                $missing[] = ['requested' => StockValuation::tToDecimal($requestedT), 'available' => StockValuation::tToDecimal($usedT)] + $identity;
            }
        }
        return [$identities, $missing];
    }

    /**
     * Kusy vybrané na jiných konceptech z téže příjemky. Sklad se hýbe až vystavením, takže
     * bez toho by `allow_duplicate` nabídl stejné sériové číslo podruhé.
     *
     * @return array<string,int> karta|klíč kusu => množství v tisícinách
     */
    private function heldByOtherDrafts(int $supplierId, int $documentId): array
    {
        $held = [];
        foreach ($this->documents->trackingOfDraftInvoicesFromReceipt($supplierId, $documentId) as $row) {
            foreach (InvoiceItemTracking::decode($row['tracking_json']) as $allocation) {
                $key = $row['stock_item_id'] . '|' . mb_strtolower(self::trackingKey($allocation));
                $held[$key] = ($held[$key] ?? 0) + StockValuation::qtyToT((string) $allocation['quantity']);
            }
        }
        return $held;
    }

    /** @param array<string,mixed> $allocation */
    private static function trackingKey(array $allocation): string
    {
        return ($allocation['serial_number'] ?? null) !== null
            ? 'serial:' . $allocation['serial_number']
            : 'lot:' . $allocation['lot_code'] . '|' . ($allocation['expires_on'] ?? '');
    }
}
