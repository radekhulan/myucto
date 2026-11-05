<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Zabraňuje schválení dříve vytvořených náhrad s nedoloženým hodinovým minimem. */
final class PayrollHourlyAverageInputGuard
{
    private const CODES = ['NAHRADA_MZDY_DOVOLENA', 'NAHRADA_MZDY_PREKAZKY_ZAMESTNANEC',
        'NAHRADA_MZDY_PREKAZKY_ZAMESTNAVATEL', 'NAHRADA_MZDY_DPN', 'ODMENA_POHOTOVOST'];

    public function __construct(private readonly Connection $db) {}

    /** @param array<int,list<array<string,mixed>>> $inputRows @param array<int,array<string,mixed>> $contexts @return array{invalid:array<int,list<int>>,source:array<int,array<int,string>>} */
    public function invalidInputs(int $supplierId, string $periodStart, array $inputRows, array $contexts): array
    {
        $candidates = [];
        $reversalCandidates = [];
        $standbyInputs = [];
        foreach ($inputRows as $employmentId => $rows) {
            $floors = array_values(array_filter($contexts[$employmentId]['minimum_floor_segments'] ?? [], static fn(array $floor): bool => ($floor['remuneration_type'] ?? null) === 'hourly'));
            foreach ($rows as $row) {
                $component = json_decode((string) ($row['component_snapshot_json'] ?? ''), true);
                $code = is_array($component) ? ($component['code'] ?? '') : '';
                if ($floors !== [] && $code === 'ODMENA_POHOTOVOST' && ($row['source_kind'] ?? null) === 'time'
                    && str_starts_with((string) ($row['external_id'] ?? ''), 'standby:')) {
                    $standbyInputs[(int) $employmentId][(int) $row['id']] = (int) $row['amount_minor'];
                }
                if ((int) $row['amount_minor'] < 0 && ($row['source_kind'] ?? null) === 'correction' && in_array($code, self::CODES, true)) {
                    $reversalCandidates[(int) $row['id']] = ['employment_id' => (int) $employmentId, 'code' => $code, 'amount' => (int) $row['amount_minor']];
                }
                if ((int) $row['amount_minor'] <= 0) continue;
                $autoTermination = ($row['source_kind'] ?? null) === 'manual'
                    && in_array($code, ['ODSTUPNE', 'NAHRADA_271CA'], true)
                    && in_array($row['external_id'] ?? null, ["termination:severance:{$employmentId}", "termination:work-injury-271ca:{$employmentId}"], true);
                if ($autoTermination || ($floors !== [] && in_array($row['source_kind'] ?? null, ['absence', 'time', 'correction'], true) && in_array($code, self::CODES, true))) {
                    $candidates[(int) $row['id']] = ['employment_id' => (int) $employmentId, 'code' => $code, 'floors' => $floors, 'termination' => $autoTermination, 'amount' => (int) $row['amount_minor']];
                }
            }
        }
        if ($candidates === []) return ['invalid' => [], 'source' => []];
        $standbyIds = [];
        foreach ($standbyInputs as $inputs) array_push($standbyIds, ...array_keys($inputs));
        $ids = array_values(array_unique([...array_keys($candidates), ...array_keys($reversalCandidates), ...$standbyIds]));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT input.id, input.source_snapshot_json, HEX(input.source_snapshot_hash) AS source_hash,
                standby.source_snapshot_json AS standby_json, HEX(standby.source_snapshot_hash) AS standby_hash,
                termination.source_snapshot_json AS termination_json, HEX(termination.source_snapshot_hash) AS termination_hash,
                employment.end_date, input.amount_minor,
                sickness.sickness_event_id, sickness.reverses_materialization_id, sickness.source_snapshot_json AS sickness_json, HEX(sickness.source_snapshot_hash) AS sickness_hash, original_sickness.input_id AS sickness_reverses_input_id,
                segment.local_date, segment.hourly_average_minor, segment.trace
            FROM payroll_inputs input
            JOIN payroll_employments employment ON employment.supplier_id = input.supplier_id AND employment.id = input.employment_id
            LEFT JOIN payroll_termination_floor_proofs termination ON termination.supplier_id = input.supplier_id AND termination.input_id = input.id
            LEFT JOIN payroll_standby_floor_proofs standby ON standby.supplier_id = input.supplier_id AND standby.input_id = input.id
            LEFT JOIN payroll_sickness_input_materializations sickness ON sickness.supplier_id = input.supplier_id AND sickness.input_id = input.id
            LEFT JOIN payroll_sickness_input_materializations original_sickness ON original_sickness.supplier_id = sickness.supplier_id AND original_sickness.id = sickness.reverses_materialization_id
            LEFT JOIN payroll_sickness_compensation_segments segment FORCE INDEX (idx_payroll_sickness_segment_event)
              ON segment.supplier_id = sickness.supplier_id AND segment.sickness_event_id = sickness.sickness_event_id
              AND segment.local_date >= ? AND segment.local_date < ?
            WHERE input.supplier_id = ? AND input.id IN ($in) ORDER BY input.id, segment.local_date";
        $next = (new \DateTimeImmutable($periodStart))->modify('+1 month')->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$periodStart, $next, $supplierId, ...$ids]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[(int) $row['id']][] = $row;
        $terminationDates = [];
        foreach ($candidates as $id => $candidate) {
            $date = $rows[$id][0]['end_date'] ?? null;
            if ($candidate['termination'] && is_string($date)) $terminationDates[$candidate['employment_id']] = $date;
        }
        if ($terminationDates !== []) {
            $dates = array_values($terminationDates);
            sort($dates);
            $terminationFloors = (new PayrollHourlyAverageFloorResolver($this->db))->forMany($supplierId, array_keys($terminationDates), $dates[0], $dates[count($dates) - 1]);
            foreach ($candidates as $id => &$candidate) {
                if (!$candidate['termination']) continue;
                $date = $terminationDates[$candidate['employment_id']] ?? '';
                $candidate['floors'] = array_values(array_filter($terminationFloors[$candidate['employment_id']] ?? [], static fn(array $floor): bool => $floor['date_from'] <= $date && $floor['date_to'] >= $date));
                // Běžný měsíční nárok ponechává původní snapshot beze změny.
                if ($candidate['floors'] === []) unset($candidates[$id]);
            }
            unset($candidate);
        }
        $settledStandby = [];
        foreach ($standbyInputs as $employmentId => $amounts) {
            $latestId = max(array_keys($amounts));
            $latest = $rows[$latestId][0] ?? null;
            if (!is_array($latest) || !is_string($latest['standby_json'] ?? null)) continue;
            $proof = json_decode($latest['standby_json'], true);
            $oneCandidate = null;
            foreach ($candidates as $candidate) if ($candidate['employment_id'] === $employmentId && $candidate['code'] === 'ODMENA_POHOTOVOST') { $oneCandidate = $candidate; break; }
            if ($oneCandidate === null || !is_array($proof) || !is_int($proof['amount_minor'] ?? null)
                || $proof['amount_minor'] !== array_sum($amounts) || !$this->isSufficient('ODMENA_POHOTOVOST', $oneCandidate['floors'], [$latest], [])) continue;
            $settledStandby[$employmentId] = ['amounts' => $amounts, 'latest' => $rows[$latestId]];
        }
        $reversed = [];
        foreach ($reversalCandidates as $reversalId => $reversal) {
            $row = $rows[$reversalId][0] ?? null;
            if (!is_array($row)) continue;
            $sickness = $reversal['code'] === 'NAHRADA_MZDY_DPN';
            $json = $sickness ? $row['sickness_json'] : $row['source_snapshot_json'];
            $hash = $sickness ? $row['sickness_hash'] : $row['source_hash'];
            if (!is_string($json) || !is_string($hash) || !hash_equals(strtolower($hash), hash('sha256', $json))) continue;
            $trace = json_decode($json, true);
            if (!is_array($trace) || !is_int($trace['reverses_input_id'] ?? null)) continue;
            $originalId = $trace['reverses_input_id'];
            $original = $candidates[$originalId] ?? null;
            if (!is_array($original) || $original['employment_id'] !== $reversal['employment_id'] || $original['code'] !== $reversal['code']
                || $reversal['amount'] !== -$original['amount'] || ($trace['amount_minor'] ?? null) !== $reversal['amount']) continue;
            if ($sickness && (int) ($row['sickness_reverses_input_id'] ?? 0) !== $originalId) continue;
            $reversed[$originalId] = $rows[$reversalId];
        }
        $absenceIds = [];
        foreach ($candidates as $id => $candidate) {
            if (in_array($candidate['code'], ['NAHRADA_MZDY_DPN', 'ODMENA_POHOTOVOST'], true)) continue;
            $snapshot = json_decode((string) ($rows[$id][0]['source_snapshot_json'] ?? ''), true);
            if (is_array($snapshot) && is_int($snapshot['absence_id'] ?? null) && $snapshot['absence_id'] > 0) $absenceIds[$snapshot['absence_id']] = true;
        }
        $absences = [];
        if ($absenceIds !== []) {
            $absenceIn = implode(',', array_fill(0, count($absenceIds), '?'));
            $stmt = $this->db->pdo()->prepare("SELECT id, date_from, date_to FROM payroll_absences WHERE supplier_id = ? AND id IN ($absenceIn)");
            $stmt->execute([$supplierId, ...array_keys($absenceIds)]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $absence) $absences[(int) $absence['id']] = $absence;
        }
        $invalid = [];
        $source = [];
        foreach ($candidates as $id => $candidate) {
            $items = $rows[$id] ?? [];
            $source[$candidate['employment_id']][$id] = hash('sha256', \MyInvoice\Service\Payroll\Ruleset\CanonicalJson::encode(['rows' => $items, 'floors' => $candidate['floors'], 'reversal' => $reversed[$id] ?? null, 'standby' => $candidate['code'] === 'ODMENA_POHOTOVOST' ? ($settledStandby[$candidate['employment_id']] ?? null) : null]));
            if (!isset($reversed[$id]) && !($candidate['code'] === 'ODMENA_POHOTOVOST' && isset($settledStandby[$candidate['employment_id']])) && ($items === [] || !$this->isSufficient($candidate['code'], $candidate['floors'], $items, $absences))) $invalid[$candidate['employment_id']][] = $id;
        }
        return ['invalid' => $invalid, 'source' => $source];
    }

    /** @param list<array<string,mixed>> $floors @param list<array<string,mixed>> $rows */
    private function isSufficient(string $code, array $floors, array $rows, array $absences): bool
    {
        $first = $rows[0];
        if (in_array($code, ['ODSTUPNE', 'NAHRADA_271CA'], true)) {
            $json = $first['termination_json']; $hash = $first['termination_hash'];
            if (!is_string($json) || !is_string($hash) || !hash_equals(strtolower($hash), hash('sha256', $json))) return false;
            $proof = json_decode($json, true);
            $rate = is_array($proof) ? ($proof['hourly_average_floor']['average_hourly_minor'] ?? null) : null;
            return is_int($rate) && is_string($first['end_date']) && ($proof['end_date'] ?? null) === $first['end_date']
                && (int) ($proof['amount_minor'] ?? -1) === (int) $first['amount_minor'] && $this->meetsFloor($floors, $first['end_date'], $rate);
        }
        if ($code === 'NAHRADA_MZDY_DPN') {
            if ($first['sickness_event_id'] === null) return false;
            $found = false;
            foreach ($rows as $row) {
                if ($row['local_date'] === null) continue;
                $found = true;
                if (!$this->meetsFloor($floors, (string) $row['local_date'], (int) $row['hourly_average_minor'])) return false;
            }
            return $found;
        }
        $standby = $code === 'ODMENA_POHOTOVOST';
        $json = $standby ? $first['standby_json'] : $first['source_snapshot_json'];
        $hash = $standby ? $first['standby_hash'] : $first['source_hash'];
        if (!is_string($json) || !is_string($hash) || !hash_equals(strtolower($hash), hash('sha256', $json))) return false;
        $proof = json_decode($json, true);
        if (!is_array($proof)) return false;
        $segments = $proof['hourly_average_segments'] ?? null;
        $rates = $proof['hourly_average_floor'] ?? null;
        if (is_array($segments) && is_array($rates)) {
            $byDate = [];
            foreach ($rates as $rate) if (is_array($rate) && is_string($rate['application_date'] ?? null)) $byDate[$rate['application_date']] = $rate['average_hourly_minor'] ?? null;
            foreach ($segments as $segment) {
                $date = (string) ($segment['local_date'] ?? '');
                $hourly = array_any($floors, static fn(array $floor): bool => $floor['date_from'] <= $date && $floor['date_to'] >= $date);
                if ($hourly && (!is_int($byDate[$date] ?? null) || !$this->meetsFloor($floors, $date, $byDate[$date]))) return false;
            }
            return $segments !== [];
        }
        if ($standby) return false;
        $absence = $absences[$proof['absence_id'] ?? 0] ?? null;
        if (is_array($absence)) {
            $floors = array_values(array_filter($floors, static fn(array $floor): bool =>
                $floor['date_from'] <= $absence['date_to'] && $floor['date_to'] >= $absence['date_from']));
            if ($floors === []) return true;
        } elseif (is_string($proof['end_date'] ?? null)) {
            $day = $proof['end_date'];
            $floors = array_values(array_filter($floors, static fn(array $floor): bool => $floor['date_from'] <= $day && $floor['date_to'] >= $day));
            if ($floors === []) return true;
        }
        $average = $proof['average_hourly_minor'] ?? null;
        if (!is_int($average) || $average <= 0) return false;
        foreach ($floors as $floor) if (!is_int($floor['minimum_hourly_minor'] ?? null) || $average < $floor['minimum_hourly_minor']) return false;
        return true;
    }

    private function meetsFloor(array $floors, string $date, int $average): bool
    {
        try { return PayrollHourlyAverageFloorResolver::apply($floors, $date, $average)['hourly_minor'] === $average; }
        catch (\InvalidArgumentException) { return false; }
    }
}
