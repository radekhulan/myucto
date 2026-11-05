<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

/** Kanonický podklad odpracované doby, nezávislý na potvrzení výkazu JMHZ. */
final class PayrollWorkedTimeEvidence
{
    public function __construct(private readonly Connection $db) {}

    /** @param list<int> $employmentIds @return array<int,array<string,mixed>> */
    public function loadMany(int $supplierId, array $employmentIds, string $periodStart, bool $lock = false): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($employmentIds), '?'));
        $suffix = $lock ? ' FOR UPDATE' : '';
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, employment_id, period_start, status, revision_no, row_version, work_source
             FROM payroll_time_months WHERE supplier_id = ? AND employment_id IN ($in) AND period_start = ?" . $suffix,
        );
        $stmt->execute([$supplierId, ...$employmentIds, $periodStart]);
        $months = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($months === []) {
            return [];
        }
        $sourceIds = [];
        foreach ($months as $month) {
            $sourceIds[$month['work_source']][] = (int) $month['employment_id'];
        }
        // Počet dotazů závisí jen na použitých druzích zdroje, ne na počtu vztahů.
        $manual = $this->summaryRows($supplierId, $sourceIds['manual_summary'] ?? [], $periodStart, 'manual', $lock);
        $imports = $this->summaryRows($supplierId, $sourceIds['import_summary'] ?? [], $periodStart, 'import', $lock);
        $manualIds = array_column($manual, 'id');
        $segments = [];
        if ($manualIds !== []) {
            $ids = implode(',', array_fill(0, count($manualIds), '?'));
            $stmt = $this->db->pdo()->prepare("SELECT summary_id, sequence_no, date_from, date_to, worked_duration_units, overtime_duration_units, worked_days FROM payroll_time_month_manual_segments WHERE supplier_id = ? AND summary_id IN ($ids) ORDER BY summary_id, sequence_no" . $suffix);
            $stmt->execute([$supplierId, ...$manualIds]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int) $row['summary_id'];
                unset($row['summary_id']);
                foreach (['sequence_no', 'worked_duration_units', 'overtime_duration_units', 'worked_days'] as $key) {
                    $row[$key] = $row[$key] === null ? null : (int) $row[$key];
                }
                $segments[$id][] = $row;
            }
        }
        $entries = [];
        $entryIds = $sourceIds['entries'] ?? [];
        if ($entryIds !== []) {
            $in = implode(',', array_fill(0, count($entryIds), '?'));
            $period = new \DateTimeImmutable($periodStart, new \DateTimeZone('UTC'));
            $stmt = $this->db->pdo()->prepare("SELECT id, employment_id, starts_at_utc, ends_at_utc, timezone_name, break_minutes, category, status FROM payroll_time_entries WHERE supplier_id = ? AND employment_id IN ($in) AND starts_at_utc >= ? AND starts_at_utc < ? AND status <> 'superseded' ORDER BY employment_id, starts_at_utc, id" . $suffix);
            $stmt->execute([$supplierId, ...$entryIds, $period->modify('-1 day')->format('Y-m-d H:i:s'), $period->modify('first day of next month')->modify('+1 day')->format('Y-m-d H:i:s')]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
                $local = (new \DateTimeImmutable($entry['starts_at_utc'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($entry['timezone_name']));
                if ($local->format('Y-m') !== substr($periodStart, 0, 7)) {
                    continue;
                }
                $employmentId = (int) $entry['employment_id'];
                unset($entry['employment_id']);
                $entry['id'] = (int) $entry['id'];
                $entry['break_minutes'] = (int) $entry['break_minutes'];
                $entries[$employmentId][] = $entry;
            }
        }
        $result = [];
        foreach ($months as $month) {
            $id = (int) $month['employment_id'];
            $source = [
                'schema_version' => 'payroll-worked-time.v1',
                'duration_units_per_hour' => PayrollDuration::UNITS_PER_HOUR,
                'time_month_id' => (int) $month['id'],
                'revision_no' => (int) $month['revision_no'],
                'row_version' => (int) $month['row_version'],
                'status' => $month['status'],
                'work_source' => $month['work_source'],
                'period_start' => $periodStart,
            ];
            if ($month['work_source'] === 'manual_summary') {
                $summary = $manual[$id] ?? null;
                if ($summary !== null) {
                    $summary['segments'] = $segments[$summary['id']] ?? [];
                }
                $source['manual_summary'] = $summary;
            } elseif ($month['work_source'] === 'import_summary') {
                $source['import_summary'] = $imports[$id] ?? null;
            } elseif ($month['work_source'] === 'entries') {
                $source['time_entries'] = $entries[$id] ?? [];
            }
            $source['source_sha256'] = hash('sha256', CanonicalJson::encode($source));
            $result[$id] = $source;
        }
        return $result;
    }

    /** @param list<int> $employmentIds @return array<int,array<string,mixed>> */
    private function summaryRows(int $supplierId, array $employmentIds, string $periodStart, string $kind, bool $lock): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($employmentIds), '?'));
        // Název tabulky je interní výčet; žádná část nepochází z požadavku.
        $table = $kind === 'manual' ? 'payroll_time_month_manual_summaries' : 'payroll_time_month_import_summaries';
        $columns = $kind === 'manual' ? 'summary.reason' : 'summary.attendance_import_id, summary.values_json, summary.sources_json, summary.worked_days';
        $stmt = $this->db->pdo()->prepare("SELECT summary.id, summary.employment_id, summary.time_month_id, summary.time_month_revision_no, summary.content_sha256, $columns FROM payroll_time_months month_row JOIN $table summary ON summary.supplier_id = month_row.supplier_id AND summary.time_month_id = month_row.id AND summary.time_month_revision_no = month_row.revision_no WHERE month_row.supplier_id = ? AND month_row.employment_id IN ($in) AND month_row.period_start = ?" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, ...$employmentIds, $periodStart]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['employment_id'];
            unset($row['employment_id']);
            foreach (['id', 'time_month_id', 'time_month_revision_no', 'attendance_import_id', 'worked_days'] as $key) {
                if (array_key_exists($key, $row)) {
                    $row[$key] = $row[$key] === null ? null : (int) $row[$key];
                }
            }
            foreach (['values', 'sources'] as $key) {
                if (isset($row[$key . '_json'])) {
                    $row[$key] = json_decode($row[$key . '_json'], true, flags: JSON_THROW_ON_ERROR);
                    unset($row[$key . '_json']);
                }
            }
            $result[$id] = $row;
        }
        return $result;
    }
}
