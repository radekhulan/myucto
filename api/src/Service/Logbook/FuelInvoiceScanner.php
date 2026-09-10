<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use MyInvoice\Repository\CarRepository;
use MyInvoice\Repository\FuelingRepository;
use MyInvoice\Repository\FuelScanRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Bank\Card\CardNumberMask;
use MyInvoice\Service\Logbook\Fuel\FuelStatementParserRegistry;
use MyInvoice\Service\Logbook\Fuel\FuelTransactionEnricher;

/**
 * Orchestrátor vytěžení tankování z přijatých faktur od benzínek.
 *
 * Vybere parser z registry, vytvoří fuelings (idempotentně přes dedup_hash), zapíše
 * marker do logbook_fuel_scans (parse jen jednou). Backfill projede historii.
 */
final class FuelInvoiceScanner
{
    /** parser name → fuelings.source ENUM */
    private const SOURCE_MAP = ['axigon' => 'axigon', 'axigon_ai' => 'axigon_ai', 'summary' => 'invoice'];

    public function __construct(
        private readonly PurchaseInvoiceRepository $invoices,
        private readonly FuelingRepository $fuelings,
        private readonly FuelScanRepository $scans,
        private readonly FuelStatementParserRegistry $registry,
        private readonly PurchaseInvoicePdfReader $pdfReader,
        private readonly CarRepository $cars,
        private readonly FuelTransactionEnricher $enricher,
        private readonly FuelExpenseReclassifier $reclassifier,
        private readonly VehicleResolver $vehicles,
    ) {}

    /**
     * Vytěží jednu fakturu. $carId = explicitní auto (jinak default auto / bez přiřazení).
     *
     * @return array{ok:bool, invoice_id:int, created:int, duplicates:int, updated?:int, fuel_rows:int,
     *               parser:string, status:string, skipped?:bool, error?:string}
     */
    public function scanInvoice(int $supplierId, int $invoiceId, ?int $carId, ?int $userId, bool $force = false): array
    {
        $base = ['ok' => false, 'invoice_id' => $invoiceId, 'created' => 0, 'duplicates' => 0,
                 'fuel_rows' => 0, 'parser' => 'none', 'status' => 'failed'];

        $invoice = $this->invoices->find($invoiceId, $supplierId);
        if ($invoice === null) {
            return $base + ['error' => 'Faktura nenalezena.'];
        }
        if (!$force && $this->scans->isScanned($supplierId, $invoiceId)) {
            return ['ok' => true, 'invoice_id' => $invoiceId, 'created' => 0, 'duplicates' => 0,
                    'updated' => 0, 'fuel_rows' => 0, 'parser' => 'none', 'status' => 'skipped', 'skipped' => true];
        }

        $pdfBytes = $this->pdfReader->read($invoice);
        $result = $this->registry->parse($invoice, $pdfBytes);
        // Doplnění chybějících litrů z položek faktury + fallback data DUZP.
        $result['transactions'] = $this->enricher->enrich($result['transactions'], $invoice);

        // Cílové auto: explicitní → SPZ z řádku výpisu → karta dokladu → výchozí/jediné → null.
        $cardLast4 = CardNumberMask::isValidLast4(isset($invoice['card_last4']) ? (string) $invoice['card_last4'] : null)
            ? (string) $invoice['card_last4'] : null;

        $vendorId = (int) ($invoice['vendor_id'] ?? 0) ?: null;
        $source = self::SOURCE_MAP[$result['parser']] ?? 'invoice';

        // Tankování založené ze skenu účtenky (FuelingFromExtraction) má jiný otisk než
        // řádky parseru. Dá-li parser jediné tankování, doplní se to ze skenu — jinak by
        // jedna účtenka byla v knize dvakrát. Výpis s víc tankováními se nehádá.
        $scanHash = FuelingDocumentRef::purchaseInvoice($invoiceId)->dedupHash($supplierId);
        $fromScan = array_values(array_filter(
            $this->fuelings->findByDocumentLink($supplierId, 'source_purchase_invoice_id', $invoiceId),
            static fn (array $r): bool => $r['dedup_hash'] === $scanHash,
        ));
        $singleFuelRow = count(array_filter($result['transactions'], static fn (array $t): bool => !empty($t['is_fuel']))) === 1;

        $created = 0; $dupes = 0; $updated = 0; $fuelRows = 0; $ordinal = 0;
        foreach ($result['transactions'] as $t) {
            $ordinal++;
            if (empty($t['is_fuel'])) continue; // jen pohonné hmoty se stanou tankováním
            $fuelRows++;
            $vehicle = $this->vehicles->resolve($supplierId, [
                'car_id'     => $carId,
                'plate'      => $t['plate'] ?? null,
                'text'       => $t['raw_text'] ?? null,
                'card_last4' => $cardLast4,
                'date'       => (string) ($t['fueled_date'] ?? ''),
            ]);
            $data = [
                'car_id'                     => $vehicle['car_id'],
                'car_assigned_by'            => $vehicle['method'],
                'card_last4'                 => $cardLast4,
                'fueled_date'                => $t['fueled_date'],
                'fueled_time'                => $t['fueled_time'] ?? null,
                'fuel_type'                  => $t['fuel_type'] ?? null,
                'quantity'                   => $t['quantity'] ?? null,
                'unit'                       => $t['unit'] ?? 'l',
                'unit_price'                 => $t['unit_price'] ?? null,
                'amount_without_vat'         => $t['amount_without_vat'] ?? null,
                'amount_vat'                 => $t['amount_vat'] ?? null,
                'amount_with_vat'            => $t['amount_with_vat'] ?? 0,
                'currency'                   => $t['currency'] ?? 'CZK',
                'station'                    => $t['station'] ?? null,
                'vendor_id'                  => $vendorId,
                'source'                     => $source,
                'source_purchase_invoice_id' => $invoiceId,
                'source_item_id'             => $t['source_item_id'] ?? null,
                'receipt_number'             => $t['receipt_number'] ?? null,
                'raw_text'                   => $t['raw_text'] ?? null,
                'dedup_hash'                 => $this->dedupHash($supplierId, $invoiceId, $t, $ordinal),
            ];
            if ($fromScan !== [] && $singleFuelRow) {
                $this->fuelings->fillMissing($fromScan[0]['id'], $supplierId, $data) ? $updated++ : $dupes++;
                continue;
            }
            $r = $this->fuelings->insertScanned($supplierId, $data, $userId);
            if ($r > 0) $created++;
            elseif ($r < 0) $updated++;
            else $dupes++;
        }

        $status = $result['status'] === 'failed' ? 'failed' : ($result['parser'] === 'summary' ? 'summary' : 'parsed');
        $this->scans->recordScan($supplierId, $invoiceId, $result['parser'], $fuelRows, $status);

        // Rozpoznané palivo se musí promítnout i do ÚČETNICTVÍ, jinak jedna část systému
        // vede řádek jako tankování a druhá ho účtuje na 518. Položky vyhodnocené jako PHM
        // se proto překlasifikují natvrdo (force) a doklad se přeúčtuje — PostingService
        // přepíše existující zápis in-place (žádné storno). Selhání nesmí shodit vytěžení.
        $reclassified = [];
        if ($fuelRows > 0) {
            try {
                $reclassified = $this->reclassifier->reclassifyFuelAndRepost($supplierId, $invoiceId, $userId);
            } catch (\Throwable $e) {
                $reclassified = ['error' => $e->getMessage()];
            }
        }

        return ['ok' => true, 'invoice_id' => $invoiceId, 'created' => $created, 'duplicates' => $dupes,
                'updated' => $updated, 'fuel_rows' => $fuelRows, 'parser' => $result['parser'], 'status' => $status,
                'reclassified' => $reclassified];
    }

    /**
     * Zpětné dávkové vytěžení historie — projede faktury od benzínek, které ještě
     * nebyly vytěženy (logbook_fuel_scans). Parse jen jednou.
     *
     * Po vytěžení nových faktur projede do zbylého limitu i už vytěžené faktury, kterým
     * chybí litry, a doplní je z položek (každou nejvýše jednou — liters_attempted).
     *
     * @return array{ok:bool, processed:int, created:int, duplicates:int, updated:int, remaining:int, results:list<array<string,mixed>>}
     */
    public function backfill(int $supplierId, ?int $userId, int $limit = 25): array
    {
        $ids = $this->scans->unscannedInvoiceIds($supplierId, $limit + 1);
        $hasMoreUnscanned = false;
        if (count($ids) > $limit) {
            $ids = array_slice($ids, 0, $limit);
            $hasMoreUnscanned = true;
        }

        $processed = 0; $created = 0; $dupes = 0; $updated = 0; $results = [];
        foreach ($ids as $invoiceId) {
            $r = $this->scanInvoice($supplierId, $invoiceId, null, $userId, false);
            $processed++;
            $created += (int) $r['created'];
            $dupes += (int) $r['duplicates'];
            $updated += (int) ($r['updated'] ?? 0);
            $results[] = $r;
        }

        // Zbylý limit využij na doplnění litrů u dříve vytěžených faktur (force re-scan).
        $slots = $limit - count($ids);
        if ($slots > 0) {
            foreach ($this->scans->incompleteInvoiceIds($supplierId, $slots) as $invoiceId) {
                $r = $this->scanInvoice($supplierId, $invoiceId, null, $userId, true);
                $this->scans->markLitersAttempted($supplierId, $invoiceId); // pokus proběhl — neopakovat
                $processed++;
                $created += (int) $r['created'];
                $dupes += (int) $r['duplicates'];
                $updated += (int) ($r['updated'] ?? 0);
                $results[] = $r;
            }
        }

        $remaining = ($hasMoreUnscanned ? count($this->scans->unscannedInvoiceIds($supplierId, 1000)) : 0)
                   + count($this->scans->incompleteInvoiceIds($supplierId, 1000));

        return ['ok' => true, 'processed' => $processed, 'created' => $created,
                'duplicates' => $dupes, 'updated' => $updated, 'remaining' => $remaining, 'results' => $results];
    }

    private function dedupHash(int $supplierId, int $invoiceId, array $t, int $ordinal): string
    {
        return hash('sha256', implode('|', [
            $supplierId, $invoiceId, $ordinal,
            (string) ($t['receipt_number'] ?? ''),
            (string) ($t['fueled_date'] ?? ''),
            (string) ($t['fueled_time'] ?? ''),
            (string) ($t['fuel_type'] ?? ''),
            number_format((float) ($t['amount_with_vat'] ?? 0), 2, '.', ''),
        ]));
    }
}
