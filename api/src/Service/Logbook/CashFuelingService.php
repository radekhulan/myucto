<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CarRepository;
use MyInvoice\Repository\CashDocumentRepository;
use MyInvoice\Repository\FuelingRepository;
use MyInvoice\Service\Bank\Card\CardNumberMask;
use MyInvoice\Service\Logbook\Fuel\FuelKeywords;
use MyInvoice\Service\Logbook\Fuel\FuelReceiptTextParser;
use PDO;

/**
 * Tankování z pokladního dokladu (VPD) — protějšek {@see FuelInvoiceScanner} pro účtenky
 * placené hotově.
 *
 * Který doklad je tankování: výdajový, zaúčtovaný (ne stornovaný) doklad na nákup nebo
 * „jiný výdaj", který NENÍ úhradou přijaté faktury (ta má tankování z faktury — jinak by
 * se jedno tankování evidovalo dvakrát), a jehož partner je čerpací stanice
 * (`clients.is_fuel_station` podle IČO) nebo popis zní na pohonné hmoty
 * ({@see FuelKeywords::isFuelForAccounting()} — tentýž seznam slov, podle kterého
 * účetnictví klasifikuje náklad na PHM, aby se kniha jízd a účetnictví nerozešly).
 *
 * Z popisu se vytáhnou litry, cena za litr, druh paliva, SPZ a tachometr
 * ({@see FuelReceiptTextParser}), vozidlo určí {@see VehicleResolver}.
 * Jeden doklad = jedno tankování, idempotentně přes `dedup_hash` — opakované vytěžení
 * jen doplní chybějící údaje.
 *
 * Tankování je evidenční vrstva: nic neúčtuje, náklad a DPH nese pokladní doklad.
 */
final class CashFuelingService
{
    /** Strop vrácených kandidátů i velikost stránky, po které se doklady čtou. */
    private const SCAN_LIMIT = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly CashDocumentRepository $documents,
        private readonly FuelingRepository $fuelings,
        private readonly CarRepository $cars,
        private readonly VehicleResolver $vehicles,
    ) {}

    /**
     * Pokladní doklady, které vypadají jako tankování, od nejnovějších.
     *
     * Filtry „jen nevytěžené" a „tento doklad" jdou do SQL a doklady se čtou po
     * stránkách, dokud se nenaplní strop výsledku. Jinak by strop v SQL uřízl starší
     * doklady dřív, než PHP vyřadí vytěžené a netankovací (mytí, známka…) — backfill
     * by po 500 zpracovaných dokladech hlásil nulu a zpětně datovaný doklad by se
     * automaticky nevytěžil nikdy.
     *
     * @param array{year?:int, only_unscanned?:bool, id?:int, limit?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function candidates(int $supplierId, array $filters = []): array
    {
        $where = [
            'cd.supplier_id = ?', "cd.doc_type = 'out'", "cd.status = 'posted'",
            'cd.purchase_invoice_id IS NULL', "cd.purpose IN ('purchase','other')",
        ];
        $params = [$supplierId];
        if (!empty($filters['year'])) {
            $y = (int) $filters['year'];
            $where[] = 'cd.issue_date >= ? AND cd.issue_date < ?';
            $params[] = sprintf('%04d-01-01', $y);
            $params[] = sprintf('%04d-01-01', $y + 1);
        }
        if (!empty($filters['id'])) {
            $where[] = 'cd.id = ?';
            $params[] = (int) $filters['id'];
        }
        if (!empty($filters['only_unscanned'])) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM fuelings fx
                                     WHERE fx.supplier_id = cd.supplier_id AND fx.source_cash_document_id = cd.id)';
        }
        $limit = max(1, (int) ($filters['limit'] ?? self::SCAN_LIMIT));

        $out = [];
        $cursor = null;
        do {
            $pageWhere = $where;
            $pageParams = $params;
            if ($cursor !== null) {
                $pageWhere[] = '(cd.issue_date < ? OR (cd.issue_date = ? AND cd.id < ?))';
                array_push($pageParams, $cursor[0], $cursor[0], $cursor[1]);
            }
            $pageParams[] = FuelKeywords::SQL_REGEXP;
            $stmt = $this->db->pdo()->prepare(
                'SELECT * FROM (
                    SELECT cd.id, cd.doc_number, cd.issue_date, cd.tax_date, cd.partner_name, cd.partner_ic,
                           cd.description, cd.total_amount, cd.currency_code, cd.amount_foreign,
                           (SELECT MIN(cl.id) FROM clients cl
                             WHERE cl.supplier_id = cd.supplier_id AND cl.is_fuel_station = 1
                               AND cd.partner_ic IS NOT NULL AND cd.partner_ic <> \'\' AND cl.ic = cd.partner_ic) AS station_client_id,
                           (SELECT COUNT(*) FROM fuelings f
                             WHERE f.supplier_id = cd.supplier_id AND f.source_cash_document_id = cd.id) AS fuelings_count
                      FROM cash_documents cd
                     WHERE ' . implode(' AND ', $pageWhere) . '
                 ) x
                 WHERE x.station_client_id IS NOT NULL OR LOWER(x.description) REGEXP ?
                 ORDER BY x.issue_date DESC, x.id DESC
                 LIMIT ' . self::SCAN_LIMIT
            );
            $stmt->execute($pageParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $cursor = [(string) $r['issue_date'], (int) $r['id']];
                $candidate = self::candidateRow($r);
                if ($candidate !== null) {
                    $out[] = $candidate;
                    if (count($out) >= $limit) {
                        return $out;
                    }
                }
            }
        } while (count($rows) === self::SCAN_LIMIT);
        return $out;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>|null null = netankovací doklad
     */
    private static function candidateRow(array $r): ?array
    {
        $isStation = $r['station_client_id'] !== null;
        if (!self::looksLikeFuel((string) $r['description'], $isStation)) {
            return null;
        }
        $scanned = (int) $r['fuelings_count'] > 0;
        return [
            'id'              => (int) $r['id'],
            'doc_number'      => $r['doc_number'] !== null ? (string) $r['doc_number'] : null,
            'issue_date'      => (string) $r['issue_date'],
            'partner_name'    => $r['partner_name'] !== null ? (string) $r['partner_name'] : null,
            'description'     => (string) $r['description'],
            'total_amount'    => (float) $r['total_amount'],
            'currency'        => (string) $r['currency_code'],
            'is_fuel_station' => $isStation,
            'fuelings_count'  => (int) $r['fuelings_count'],
            'scanned'         => $scanned,
        ];
    }

    /** Popis / partner dokladu odpovídá tankování (nebo nabíjení)? */
    public static function looksLikeFuel(string $description, bool $isFuelStation): bool
    {
        if (FuelKeywords::isNonFuelService($description)) {
            // Mytí, dálniční známka, parkování… u benzínky nejsou tankování.
            return false;
        }
        return $isFuelStation || FuelKeywords::isFuelForAccounting($description);
    }

    /**
     * Vytěží pokladní doklad do tankování (idempotentně).
     *
     * @return array{ok:bool, cash_document_id:int, created:int, updated:int, duplicates:int,
     *               fueling_id:?int, car_id:?int, car_method:string, reassigned?:int, error?:string}
     */
    public function scan(int $supplierId, int $cashDocumentId, ?int $carId, ?int $userId): array
    {
        $base = ['ok' => false, 'cash_document_id' => $cashDocumentId, 'created' => 0, 'updated' => 0,
                 'duplicates' => 0, 'fueling_id' => null, 'car_id' => null, 'car_method' => 'none'];

        $doc = $this->documents->find($supplierId, $cashDocumentId);
        if ($doc === null) {
            return $base + ['error' => 'Pokladní doklad nenalezen.'];
        }
        if ($doc['doc_type'] !== 'out' || $doc['status'] === 'reversed') {
            return $base + ['error' => 'Tankování lze vytěžit jen z platného výdajového dokladu.'];
        }
        if ($doc['purchase_invoice_id'] !== null) {
            return $base + ['error' => 'Doklad je úhradou přijaté faktury — tankování se vytěží z faktury.'];
        }

        $description = (string) $doc['description'];
        $parsed = FuelReceiptTextParser::parse($description);
        // Pokladní doklad nemá pole pro kartu — koncovka se bere jen z maskovaného čísla
        // v popisu („**** 1234"), nikdy z holých čísel.
        $cardLast4 = CardNumberMask::last4FromText($description);
        $vehicle = $this->vehicles->resolve($supplierId, [
            'car_id'     => $carId,
            'plate'      => $parsed['plate'],
            'text'       => $description,
            'card_last4' => $cardLast4,
            'date'       => (string) $doc['issue_date'],
        ]);

        [$amount, $currency] = self::amountOf($doc);
        $net = null;
        $vat = null;
        $lines = $this->documents->vatLinesFor($cashDocumentId);
        if ($lines !== [] && $currency === 'CZK') {
            $net = round(array_sum(array_map(static fn (array $l): float => (float) $l['base_amount'], $lines)), 2);
            $vat = round(array_sum(array_map(static fn (array $l): float => (float) $l['vat_amount'], $lines)), 2);
        }

        $quantity = $parsed['quantity'];
        $unitPrice = $parsed['unit_price'];
        if ($quantity !== null && $quantity > 0 && $unitPrice === null) {
            $unitPrice = round($amount / $quantity, 4);
        } elseif ($unitPrice !== null && $unitPrice > 0 && $quantity === null) {
            $quantity = round($amount / $unitPrice, 3);
        }

        $data = [
            'car_id'                  => $vehicle['car_id'],
            'car_assigned_by'         => $vehicle['method'],
            'card_last4'              => $cardLast4,
            'fueled_date'             => (string) ($doc['tax_date'] ?? $doc['issue_date']),
            'fuel_type'               => $parsed['fuel_type'],
            'quantity'                => $quantity,
            'unit'                    => $parsed['unit'],
            'unit_price'              => $unitPrice,
            'amount_without_vat'      => $net,
            'amount_vat'              => $vat,
            'amount_with_vat'         => $amount,
            'currency'                => $currency,
            'odometer'                => $parsed['odometer'],
            'station'                 => $doc['partner_name'] ?? null,
            'vendor_id'               => $this->stationClientId($supplierId, (string) ($doc['partner_ic'] ?? '')),
            'source'                  => 'cash',
            'source_cash_document_id' => $cashDocumentId,
            'receipt_number'          => $doc['doc_number'] ?? null,
            'raw_text'                => $description,
            'dedup_hash'              => FuelingDocumentRef::cashDocument($cashDocumentId)->dedupHash($supplierId),
        ];

        $r = $this->fuelings->insertScanned($supplierId, $data, $userId);
        $result = array_merge($base, [
            'ok'         => true,
            'created'    => $r > 0 ? 1 : 0,
            'updated'    => $r < 0 ? 1 : 0,
            'duplicates' => $r === 0 ? 1 : 0,
            'fueling_id' => $r > 0 ? $r : null,
            'car_id'     => $vehicle['car_id'],
            'car_method' => $vehicle['method'],
        ]);
        // Explicitně zvolené vozidlo přepíše i dříve vytěžené tankování (jako u faktur).
        if ($carId !== null && $vehicle['method'] === 'explicit') {
            $result['reassigned'] = $this->fuelings->reassignByCashDocument($supplierId, $cashDocumentId, $carId);
        }
        return $result;
    }

    /**
     * Automatické tankování po zaúčtování pokladního dokladu. Jen když firma vede knihu
     * jízd (má aspoň jedno vozidlo), doklad vypadá jako tankování a ještě vytěžený není.
     *
     * @return array<string,mixed>|null výsledek scan(), nebo null když se nic nedělo
     */
    public function autoFromCashDocument(int $supplierId, int $cashDocumentId, ?int $userId): ?array
    {
        if ($this->cars->countActive($supplierId) === 0) {
            return null;
        }
        $c = $this->candidates($supplierId, ['id' => $cashDocumentId, 'limit' => 1])[0] ?? null;
        if ($c === null || $c['scanned']) {
            return null;
        }
        return $this->scan($supplierId, $cashDocumentId, null, $userId);
    }

    /**
     * Zpětné vytěžení dosud nezpracovaných pokladních dokladů.
     *
     * @return array{ok:bool, processed:int, created:int, updated:int, remaining:int}
     */
    public function backfill(int $supplierId, ?int $userId, int $limit = 25): array
    {
        $pending = $this->candidates($supplierId, ['only_unscanned' => true]);
        $batch = array_slice($pending, 0, max(1, $limit));
        $created = 0;
        $updated = 0;
        foreach ($batch as $c) {
            $r = $this->scan($supplierId, (int) $c['id'], null, $userId);
            $created += (int) $r['created'];
            $updated += (int) $r['updated'];
        }
        return ['ok' => true, 'processed' => count($batch), 'created' => $created, 'updated' => $updated,
                'remaining' => max(0, count($pending) - count($batch))];
    }

    /** @return array{0:float, 1:string} částka a měna tankování */
    private static function amountOf(array $doc): array
    {
        $currency = strtoupper((string) ($doc['currency_code'] ?? 'CZK'));
        if ($currency !== 'CZK' && isset($doc['amount_foreign']) && (float) $doc['amount_foreign'] > 0) {
            return [round((float) $doc['amount_foreign'], 2), $currency];
        }
        return [round((float) $doc['total_amount'], 2), 'CZK'];
    }

    private function stationClientId(int $supplierId, string $ic): ?int
    {
        $ic = trim($ic);
        if ($ic === '') return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT MIN(id) FROM clients WHERE supplier_id = ? AND ic = ? AND is_fuel_station = 1'
        );
        $stmt->execute([$supplierId, $ic]);
        $id = $stmt->fetchColumn();
        return $id !== false && $id !== null ? (int) $id : null;
    }
}
