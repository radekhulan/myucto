<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Upozornění nad tankováním per vozidlo:
 *   - chybějící stav tachometru (bez něj nejde doložit spotřebu ani návaznost),
 *   - nesouvislá řada stavů — tachometr u pozdějšího tankování je NIŽŠÍ než u dřívějšího,
 *     případně nižší než počáteční stav vozidla,
 *   - doklad uplatňuje jiný odpočet DPH, než dovoluje nastavení vozidla
 *     ({@see VehicleVatPolicy::documentMismatch()}).
 *
 * Řada tachometru se vždy posuzuje z CELÉ historie vozidla (ne jen z filtrovaného
 * období), jinak by první tankování v roce nemělo s čím navazovat.
 */
final class FuelingOdometerWarnings
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{cars:list<array<string,mixed>>, totals:array{missing:int, regressions:int, vat_mismatches:int}}
     */
    public function forTenant(int $supplierId, ?int $carId = null, ?int $year = null): array
    {
        $cars = $this->cars($supplierId, $carId);
        if ($cars === []) {
            return ['cars' => [], 'totals' => ['missing' => 0, 'regressions' => 0, 'vat_mismatches' => 0]];
        }
        $series = $this->series($supplierId, array_keys($cars));

        $out = [];
        $totals = ['missing' => 0, 'regressions' => 0, 'vat_mismatches' => 0];
        foreach ($cars as $id => $car) {
            $analysis = self::analyze($series[$id] ?? [], $car['odometer_start'], $car);
            if ($year !== null) {
                $inYear = static fn (array $r): bool => (int) substr((string) $r['date'], 0, 4) === $year;
                $analysis['missing'] = array_values(array_filter($analysis['missing'], $inYear));
                $analysis['regressions'] = array_values(array_filter($analysis['regressions'], $inYear));
                $analysis['vat_mismatches'] = array_values(array_filter($analysis['vat_mismatches'], $inYear));
            }
            if ($analysis['missing'] === [] && $analysis['regressions'] === [] && $analysis['vat_mismatches'] === []) {
                continue;
            }
            $totals['missing'] += count($analysis['missing']);
            $totals['regressions'] += count($analysis['regressions']);
            $totals['vat_mismatches'] += count($analysis['vat_mismatches']);
            $out[] = [
                'car_id'       => $id,
                'registration' => $car['registration'],
                'name'         => $car['name'],
            ] + $analysis;
        }
        return ['cars' => $out, 'totals' => $totals];
    }

    /**
     * Příznaky pro řádky seznamu (`odometer_warning`, `vat_warning`) — řada se bere z celé
     * historie vozidel, která se v seznamu vyskytují.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function annotate(int $supplierId, array $rows): array
    {
        $carIds = [];
        foreach ($rows as $r) {
            if (($r['car_id'] ?? null) !== null) $carIds[(int) $r['car_id']] = true;
        }
        $flags = [];
        if ($carIds !== []) {
            $cars = $this->cars($supplierId, null, array_keys($carIds));
            $series = $this->series($supplierId, array_keys($cars));
            foreach ($cars as $id => $car) {
                $a = self::analyze($series[$id] ?? [], $car['odometer_start'], $car);
                foreach ($a['missing'] as $m) $flags[$m['id']]['odometer'] = 'missing';
                foreach ($a['regressions'] as $m) $flags[$m['id']]['odometer'] = 'regression';
                foreach ($a['vat_mismatches'] as $m) $flags[$m['id']]['vat'] = $m['direction'];
            }
        }
        foreach ($rows as &$r) {
            $id = (int) $r['id'];
            $r['odometer_warning'] = $flags[$id]['odometer'] ?? null;
            $r['vat_warning'] = $flags[$id]['vat'] ?? null;
        }
        unset($r);
        return $rows;
    }

    /**
     * Čistá analýza řady jednoho vozidla (test seam).
     *
     * @param list<array{id:int, date:string, time:?string, odometer:?int, doc_deduction?:?string, doc_percent?:?float}> $rows
     *        seřazené podle data, času a id
     * @param array{vat_deduction_mode?:string, vat_deduction_percent?:float} $car
     * @return array{fuelings:int, missing:list<array{id:int,date:string}>,
     *               regressions:list<array{id:int,date:string,odometer:int,prev_id:?int,prev_date:?string,prev_odometer:int}>,
     *               vat_mismatches:list<array{id:int,date:string,direction:string,doc_percent:float,car_percent:float}>}
     */
    public static function analyze(array $rows, ?int $odometerStart, array $car = []): array
    {
        $missing = [];
        $regressions = [];
        $vat = [];
        $prev = $odometerStart !== null ? ['id' => null, 'date' => null, 'odometer' => $odometerStart] : null;
        $carDeduction = (string) ($car['vat_deduction_mode'] ?? 'full');
        $carPercent = (float) ($car['vat_deduction_percent'] ?? 100);

        foreach ($rows as $r) {
            $odo = $r['odometer'];
            if ($odo === null) {
                $missing[] = ['id' => $r['id'], 'date' => $r['date']];
            } else {
                if ($prev !== null && $odo < $prev['odometer']) {
                    $regressions[] = [
                        'id' => $r['id'], 'date' => $r['date'], 'odometer' => $odo,
                        'prev_id' => $prev['id'], 'prev_date' => $prev['date'], 'prev_odometer' => $prev['odometer'],
                    ];
                }
                // Po regresi pokračuj od vyšší hodnoty — jedna chybná hodnota nesmí shodit
                // všechna další tankování jako „nesouvislá".
                if ($prev === null || $odo >= $prev['odometer']) {
                    $prev = ['id' => $r['id'], 'date' => $r['date'], 'odometer' => $odo];
                }
            }

            $docDeduction = $r['doc_deduction'] ?? null;
            if ($docDeduction !== null) {
                $direction = VehicleVatPolicy::documentMismatch($carDeduction, $carPercent, (string) $docDeduction, (float) ($r['doc_percent'] ?? 100));
                if ($direction !== null) {
                    $vat[] = [
                        'id' => $r['id'], 'date' => $r['date'], 'direction' => $direction,
                        'doc_percent' => (float) (VehicleVatPolicy::effectivePercent((string) $docDeduction, (float) ($r['doc_percent'] ?? 100)) ?? 0),
                        'car_percent' => (float) (VehicleVatPolicy::effectivePercent($carDeduction, $carPercent) ?? 0),
                    ];
                }
            }
        }

        return ['fuelings' => count($rows), 'missing' => $missing, 'regressions' => $regressions, 'vat_mismatches' => $vat];
    }

    /**
     * @param list<int>|null $onlyIds
     * @return array<int, array{registration:string, name:?string, odometer_start:?int, vat_deduction_mode:string, vat_deduction_percent:float}>
     */
    private function cars(int $supplierId, ?int $carId, ?array $onlyIds = null): array
    {
        $where = ['supplier_id = ?'];
        $params = [$supplierId];
        if ($carId !== null) {
            $where[] = 'id = ?';
            $params[] = $carId;
        }
        if ($onlyIds !== null) {
            $where[] = 'id IN (' . implode(',', array_fill(0, count($onlyIds), '?')) . ')';
            array_push($params, ...$onlyIds);
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, registration, name, odometer_start, vat_deduction_mode, vat_deduction_percent
               FROM cars WHERE ' . implode(' AND ', $where) . ' ORDER BY registration'
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = [
                'registration'          => (string) $r['registration'],
                'name'                  => $r['name'] !== null ? (string) $r['name'] : null,
                'odometer_start'        => $r['odometer_start'] !== null ? (int) $r['odometer_start'] : null,
                'vat_deduction_mode'    => (string) $r['vat_deduction_mode'],
                'vat_deduction_percent' => (float) $r['vat_deduction_percent'],
            ];
        }
        return $out;
    }

    /**
     * Řada tankování per vozidlo + odpočet DPH navázaného dokladu.
     *
     * Odpočet se bere jen z dokladu, který DPH skutečně nese (u neplátce nebo dokladu bez
     * daně by výchozí 'full' vyrobil planý nesoulad): přijatá faktura z hlavičky
     * (`vat_deduction`, `vat_deduction_percent`), pokladní doklad z jeho DPH řádků —
     * při rozdílných řádcích rozhoduje nejvyšší uplatněný odpočet.
     *
     * @param list<int> $carIds
     * @return array<int, list<array{id:int, date:string, time:?string, odometer:?int, doc_deduction:?string, doc_percent:?float}>>
     */
    private function series(int $supplierId, array $carIds): array
    {
        if ($carIds === []) return [];
        $in = implode(',', array_fill(0, count($carIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT f.id, f.car_id, f.fueled_date, f.fueled_time, f.odometer,
                    CASE WHEN pi.id IS NOT NULL AND pi.total_vat > 0 THEN pi.vat_deduction END AS pi_deduction,
                    CASE WHEN pi.id IS NOT NULL AND pi.total_vat > 0 THEN pi.vat_deduction_percent END AS pi_percent,
                    (SELECT vl.vat_deduction FROM cash_document_vat_lines vl
                       JOIN cash_documents cd ON cd.id = vl.cash_document_id AND cd.supplier_id = f.supplier_id
                      WHERE vl.cash_document_id = f.source_cash_document_id AND vl.vat_amount > 0
                      ORDER BY CASE vl.vat_deduction WHEN 'full' THEN 0 WHEN 'proportional' THEN 1 WHEN 'reduced' THEN 2 ELSE 3 END,
                               vl.vat_deduction_percent DESC
                      LIMIT 1) AS cash_deduction,
                    (SELECT vl.vat_deduction_percent FROM cash_document_vat_lines vl
                       JOIN cash_documents cd ON cd.id = vl.cash_document_id AND cd.supplier_id = f.supplier_id
                      WHERE vl.cash_document_id = f.source_cash_document_id AND vl.vat_amount > 0
                      ORDER BY CASE vl.vat_deduction WHEN 'full' THEN 0 WHEN 'proportional' THEN 1 WHEN 'reduced' THEN 2 ELSE 3 END,
                               vl.vat_deduction_percent DESC
                      LIMIT 1) AS cash_percent
               FROM fuelings f
          LEFT JOIN purchase_invoices pi ON pi.id = f.source_purchase_invoice_id AND pi.supplier_id = f.supplier_id
              WHERE f.supplier_id = ? AND f.car_id IN ($in)
              ORDER BY f.car_id, f.fueled_date, COALESCE(f.fueled_time, '00:00:00'), f.id"
        );
        $stmt->execute(array_merge([$supplierId], $carIds));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $deduction = $r['pi_deduction'] ?? $r['cash_deduction'] ?? null;
            $percent = $r['pi_deduction'] !== null ? $r['pi_percent'] : $r['cash_percent'];
            $out[(int) $r['car_id']][] = [
                'id'            => (int) $r['id'],
                'date'          => (string) $r['fueled_date'],
                'time'          => $r['fueled_time'] !== null ? (string) $r['fueled_time'] : null,
                'odometer'      => $r['odometer'] !== null ? (int) $r['odometer'] : null,
                'doc_deduction' => $deduction !== null ? (string) $deduction : null,
                'doc_percent'   => $percent !== null ? (float) $percent : null,
            ];
        }
        return $out;
    }
}
