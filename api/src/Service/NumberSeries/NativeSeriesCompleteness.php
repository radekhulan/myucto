<?php

declare(strict_types=1);

namespace MyInvoice\Service\NumberSeries;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\NumberSeriesRepository;
use PDO;

/**
 * Úplnost nové (nativní) řady číselníku za rok — read-only, nic neopravuje.
 *
 * Do řady patří doklady s `number_series_id` řady (vystavené z ní), čítač se z čísla
 * vyparsuje vzorem šablony daného období. Rozsah začíná nad ručním začátkem řady
 * (`number_series_counters.floor_number`), stejně jako u řad vydaných dokladů
 * ({@see \MyInvoice\Service\Invoice\InvoiceSeriesCompletenessService}): čísla pod ním
 * v systému nevznikla a nejsou chybějící doklady. Tvar výstupu (`buckets`) je stejný.
 */
final class NativeSeriesCompleteness
{
    private const MAX_LISTED_MISSING = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly NumberSeriesRepository $series,
    ) {}

    /**
     * @param array<string,mixed> $row nativní řada
     * @return list<array{period_key:string, used_count:int, range_from:int, range_to:int,
     *                    missing:list<int>, missing_total:int, missing_truncated:bool, missing_preview:list<string>}>
     */
    public function buckets(int $supplierId, array $row, int $year): array
    {
        $space = NumberSeriesKinds::space((string) $row['doc_kind']);
        if ($space === null || $row['storage'] !== 'native') {
            return [];
        }
        $s = NumberSeriesKinds::SPACES[$space];
        $period = (string) $row['period'];
        $sql = "SELECT {$s['number']} AS num, {$s['date']} AS d FROM {$s['table']}
                 WHERE supplier_id = ? AND number_series_id = ? AND {$s['number']} IS NOT NULL";
        $params = [$supplierId, (int) $row['id']];
        if ($period !== 'none') {
            $sql .= " AND {$s['date']} >= ? AND {$s['date']} < ?";
            $params[] = sprintf('%04d-01-01', $year);
            $params[] = sprintf('%04d-01-01', $year + 1);
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        $used = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $doc) {
            $date = (string) $doc['d'];
            $key = match ($period) {
                'year'  => substr($date, 0, 4),
                'month' => substr($date, 0, 4) . substr($date, 5, 2),
                default => 'ALL',
            };
            [$y, $m] = match ($period) {
                'year'  => [(int) substr($date, 0, 4), null],
                'month' => [(int) substr($date, 0, 4), (int) substr($date, 5, 2)],
                default => [null, null],
            };
            if (preg_match(NumberSeriesTemplate::counterRegex((string) $row['template'], $row['prefix'], $y, $m), (string) $doc['num'], $match) === 1) {
                $used[$key][(int) $match[1]] = true;
            }
        }
        ksort($used);

        $floors = $this->series->floors((int) $row['id']);
        $out = [];
        foreach ($used as $key => $numbers) {
            $max = max(array_keys($numbers));
            $floor = min($floors[$key] ?? 0, min(array_keys($numbers)) - 1);
            $missingTotal = $max - $floor - count($numbers);
            $missing = [];
            for ($n = $floor + 1; $n <= $max && count($missing) < self::MAX_LISTED_MISSING; $n++) {
                if (!isset($numbers[$n])) {
                    $missing[] = $n;
                }
            }
            $date = $key === 'ALL'
                ? new \DateTimeImmutable(sprintf('%04d-01-01', $year))
                : new \DateTimeImmutable(substr((string) $key, 0, 4) . '-' . (strlen((string) $key) === 6 ? substr((string) $key, 4, 2) : '01') . '-01');
            $out[] = [
                'period_key'        => (string) $key,
                'used_count'        => count($numbers),
                'range_from'        => $floor + 1,
                'range_to'          => $max,
                'missing'           => $missing,
                'missing_total'     => $missingTotal,
                'missing_truncated' => $missingTotal > count($missing),
                'missing_preview'   => array_map(
                    static fn (int $n): string => NumberSeriesTemplate::render((string) $row['template'], $row['prefix'], $date, $n),
                    $missing,
                ),
            ];
        }
        return $out;
    }
}
