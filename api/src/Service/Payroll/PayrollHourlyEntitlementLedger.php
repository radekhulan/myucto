<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Čtení účinných nároků a vyčerpaných dohod z neměnných schválených revizí běhu. */
final class PayrollHourlyEntitlementLedger
{
    public function __construct(private readonly Connection $db) {}

    /** @param list<int> $employmentIds @return list<int> */
    public function employmentIdsWithOutstanding(int $supplierId, array $employmentIds, string $periodStart, ?string $kind = null, bool $includeCurrentClaims = false): array
    {
        if ($employmentIds === []) return [];
        if ($kind !== null && !in_array($kind, ['holiday', 'overtime'], true)) throw new \InvalidArgumentException('Neznámý druh nároku na volno.');
        $in = implode(',', array_fill(0, count($employmentIds), '?'));
        $kindSql = $kind === null ? '' : ' AND entitlement.kind = ?';
        $claimsSql = $includeCurrentClaims ? " UNION SELECT employment_id FROM payroll_hourly_wage_claims WHERE supplier_id = ? AND employment_id IN ($in) AND work_period_start = ?" : "";
        $stmt = $this->db->pdo()->prepare("WITH ranked AS (
            SELECT entitlement.employment_id, entitlement.id, revision.accounting_period_start,
                   revision.accrued_delta_units, revision.allocated_delta_units, revision.settled_delta_units,
                   ROW_NUMBER() OVER (PARTITION BY entitlement.id, revision.accounting_period_start
                     ORDER BY run_revision.approved_at DESC, run_revision.id DESC, revision.sequence_no DESC) AS position
              FROM payroll_hourly_time_entitlements entitlement
              JOIN payroll_hourly_time_entitlement_revisions revision FORCE INDEX (uq_hourly_time_entitlement_revision) ON revision.supplier_id = entitlement.supplier_id AND revision.entitlement_id = entitlement.id
              JOIN payroll_run_revisions run_revision ON run_revision.supplier_id = revision.supplier_id AND run_revision.idempotency_key_hash = revision.command_key_hash
             WHERE entitlement.supplier_id = ? AND entitlement.employment_id IN ($in) AND entitlement.work_date < ?$kindSql
               AND run_revision.status IN ('approved','superseded')
        ), outstanding AS (
            SELECT employment_id, id, SUM(accrued_delta_units) - SUM(CASE WHEN accounting_period_start = ? THEN 0 ELSE allocated_delta_units + settled_delta_units END) AS available
              FROM ranked WHERE position = 1 GROUP BY employment_id, id
        ) SELECT DISTINCT employment_id FROM outstanding WHERE available > 0$claimsSql ORDER BY employment_id");
        $stmt->execute([$supplierId, ...$employmentIds, $periodStart, ...($kind === null ? [] : [$kind]), $periodStart, ...($includeCurrentClaims ? [$supplierId, ...$employmentIds, $periodStart] : [])]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function hasOutstanding(int $supplierId, int $employmentId, string $periodStart, ?string $kind = null): bool
    {
        return $this->employmentIdsWithOutstanding($supplierId, [$employmentId], $periodStart, $kind) !== [];
    }

    /**
     * @param array<int,array<string,mixed>> $previews úplné výsledky kalkulátoru
     * @param list<int> $employmentIds zamčené vztahy
     */
    public function appendForRevision(int $supplierId, string $periodStart, array $employmentIds, array $previews, string $commandKeyHash, ?int $actorId): void
    {
        if ($employmentIds === []) return;
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction() || strlen($commandKeyHash) !== 32) throw new \LogicException('Ledger volna vyžaduje transakci a otisk příkazu.');
        $in = implode(',', array_fill(0, count($employmentIds), '?'));
        $accruals = [];
        $allocations = [];
        $included = [];
        foreach ($previews as $employmentId => $preview) {
            foreach ($preview['entitlements'] as $event) {
                $kind = (string) ($event['kind'] ?? '');
                if (in_array($kind, ['holiday_time_off_accrual', 'overtime_time_off_accrual'], true)) {
                    $key = (string) ($event['entitlement_key'] ?? '');
                    if ($key === '' || isset($accruals[$employmentId][$key])) throw new \InvalidArgumentException('Nárok na volno nemá jednoznačnou identitu.');
                    $accruals[$employmentId][$key] = $event;
                } elseif (in_array($kind, ['holiday_time_off_allocation', 'overtime_time_off_allocation', 'overtime_premium_settlement'], true)) {
                    $id = (int) ($event['entitlement_id'] ?? 0);
                    if ($id <= 0) throw new \InvalidArgumentException('Čerpání volna nemá platný nárok.');
                    $allocations[$employmentId][$id][] = $event;
                } else {
                    throw new \InvalidArgumentException('Neznámý druh nároku na náhradní volno.');
                }
            }
            foreach ($preview['included_allocations'] as $allocation) {
                $scope = (string) ($allocation['scope_key'] ?? '');
                $units = $allocation['duration_units'] ?? null;
                if ($scope === '' || !is_int($units) || $units < 0) throw new \InvalidArgumentException('Čerpání zahrnuté mzdy nemá platný rozsah.');
                $included[$employmentId][$scope] ??= ['kind' => (string) $allocation['kind'], 'duration_units' => 0, 'allocations' => []];
                $included[$employmentId][$scope]['duration_units'] = \MyInvoice\Service\Payroll\Time\PayrollDuration::add($included[$employmentId][$scope]['duration_units'], $units);
                $included[$employmentId][$scope]['allocations'][] = $allocation;
            }
        }
        $values = [];
        $args = [];
        foreach ($accruals as $employmentId => $events) foreach ($events as $key => $event) {
            $values[] = '(?, ?, ?, ?, ?)';
            array_push($args, $supplierId, $employmentId, $key,
                $event['kind'] === 'holiday_time_off_accrual' ? 'holiday' : 'overtime', $event['work_date']);
        }
        if ($values !== []) {
            $pdo->prepare('INSERT INTO payroll_hourly_time_entitlements (supplier_id, employment_id, entitlement_key, kind, work_date) VALUES ' . implode(',', $values) . ' ON DUPLICATE KEY UPDATE id = id')->execute($args);
        }
        $stmt = $pdo->prepare("SELECT id, employment_id, entitlement_key, kind, work_date FROM payroll_hourly_time_entitlements WHERE supplier_id = ? AND employment_id IN ($in) ORDER BY employment_id, id FOR UPDATE");
        $stmt->execute([$supplierId, ...$employmentIds]);
        $entities = [];
        $byKey = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $entities[$id] = $row;
            $byKey[(int) $row['employment_id']][(string) $row['entitlement_key']] = $id;
        }
        if ($entities !== []) {
            $entityIds = array_keys($entities);
            $entityIn = implode(',', array_fill(0, count($entityIds), '?'));
            $stmt = $pdo->prepare("SELECT entitlement_id, MAX(sequence_no) AS seq FROM payroll_hourly_time_entitlement_revisions WHERE supplier_id = ? AND entitlement_id IN ($entityIn) GROUP BY entitlement_id");
            $stmt->execute([$supplierId, ...$entityIds]);
            $sequences = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $sequences[(int) $row['entitlement_id']] = (int) $row['seq'];
            $stmt = $pdo->prepare("WITH ranked AS (
                SELECT revision.*, ROW_NUMBER() OVER (PARTITION BY revision.entitlement_id, revision.accounting_period_start
                    ORDER BY run_revision.approved_at DESC, run_revision.id DESC, revision.sequence_no DESC) AS position
                  FROM payroll_hourly_time_entitlement_revisions revision
                  JOIN payroll_run_revisions run_revision ON run_revision.supplier_id = revision.supplier_id AND run_revision.idempotency_key_hash = revision.command_key_hash
                 WHERE revision.supplier_id = ? AND revision.entitlement_id IN ($entityIn) AND run_revision.status IN ('approved','superseded')
            ) SELECT * FROM ranked WHERE position = 1");
            $stmt->execute([$supplierId, ...$entityIds]);
            $before = [];
            $priorCurrent = [];
            $latest = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int) $row['entitlement_id'];
                $latest[$id] = max($latest[$id] ?? 0, (int) $row['sequence_no']);
                if ($row['accounting_period_start'] === $periodStart) {
                    $priorCurrent[$id] = $row;
                    continue;
                }
                $before[$id]['accrued'] = ($before[$id]['accrued'] ?? 0) + (int) $row['accrued_delta_units'];
                $before[$id]['allocated'] = ($before[$id]['allocated'] ?? 0) + (int) $row['allocated_delta_units'];
                $before[$id]['settled'] = ($before[$id]['settled'] ?? 0) + (int) $row['settled_delta_units'];
                $before[$id]['due_on'] = $row['due_on'] ?? ($before[$id]['due_on'] ?? null);
            }
            $current = [];
            foreach ($priorCurrent as $id => $row) $current[$id] = ['accrued' => 0, 'allocated' => 0, 'settled' => 0, 'due_on' => $row['due_on'], 'events' => []];
            foreach ($accruals as $employmentId => $events) foreach ($events as $key => $event) {
                $id = $byKey[$employmentId][$key] ?? throw new \LogicException('Nárok se nepodařilo založit.');
                $units = $event['duration_units'] ?? null;
                $allocated = $event['allocated_duration_units'] ?? 0;
                if (!is_int($units) || $units < 0 || !is_int($allocated) || $allocated < 0) throw new \InvalidArgumentException('Nárok na volno nemá platnou délku.');
                $current[$id] ??= ['accrued' => 0, 'allocated' => 0, 'settled' => 0, 'due_on' => null, 'events' => []];
                $current[$id]['accrued'] += $units;
                $current[$id]['allocated'] += $allocated;
                $current[$id]['due_on'] = $event['due_on'] ?? null;
                $current[$id]['events'][] = $event;
            }
            foreach ($allocations as $employmentId => $groups) foreach ($groups as $id => $events) {
                if (!isset($entities[$id]) || (int) $entities[$id]['employment_id'] !== (int) $employmentId) throw new \InvalidArgumentException('Nárok na volno nepatří pracovnímu vztahu.');
                $current[$id] ??= ['accrued' => 0, 'allocated' => 0, 'settled' => 0, 'due_on' => $before[$id]['due_on'] ?? null, 'events' => []];
                foreach ($events as $event) {
                    $expectedKind = $event['kind'] === 'holiday_time_off_allocation' ? 'holiday' : 'overtime';
                    if ($entities[$id]['kind'] !== $expectedKind || ($event['work_date'] ?? null) !== $entities[$id]['work_date']) {
                        throw new PayrollHourlyWageConflictException('Čerpání neodpovídá druhu nebo dni vzniku nároku.');
                    }
                    if ((int) ($event['entitlement_revision'] ?? 0) !== ($latest[$id] ?? 0)) throw new PayrollHourlyWageConflictException('Nárok na volno se mezitím změnil; obnovte podklady.');
                    $units = $event['duration_units'] ?? null;
                    if (!is_int($units) || $units <= 0) throw new \InvalidArgumentException('Čerpání volna nemá platnou délku.');
                    if ($event['kind'] === 'overtime_premium_settlement') $current[$id]['settled'] += $units;
                    else $current[$id]['allocated'] += $units;
                    $current[$id]['events'][] = $event;
                }
            }
            $values = [];
            $args = [];
            foreach ($current as $id => $state) {
                $accrued = ($before[$id]['accrued'] ?? 0) + $state['accrued'];
                $allocated = ($before[$id]['allocated'] ?? 0) + $state['allocated'];
                $settled = ($before[$id]['settled'] ?? 0) + $state['settled'];
                if ($accrued < 0 || $allocated < 0 || $settled < 0 || $allocated + $settled > $accrued) throw new PayrollHourlyWageConflictException('Čerpání nebo vypořádání volna přesahuje aktuální nárok.');
                $snapshot = \MyInvoice\Service\Payroll\Ruleset\CanonicalJson::encode(['events' => $state['events']]);
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push($args, $supplierId, (int) $entities[$id]['employment_id'], $id, ($sequences[$id] ?? 0) + 1,
                    $periodStart, $commandKeyHash, $accrued, $allocated, $settled, $state['accrued'], $state['allocated'], $state['settled'],
                    $state['due_on'] ?? ($before[$id]['due_on'] ?? null), $snapshot, hash('sha256', $snapshot, true), $actorId);
            }
            if ($values !== []) $pdo->prepare('INSERT INTO payroll_hourly_time_entitlement_revisions (supplier_id, employment_id, entitlement_id, sequence_no, accounting_period_start, command_key_hash, accrued_duration_units, allocated_duration_units, settled_duration_units, accrued_delta_units, allocated_delta_units, settled_delta_units, due_on, source_snapshot_json, source_snapshot_hash, created_by) VALUES ' . implode(',', $values))->execute($args);
        }
        $this->appendIncluded($supplierId, $periodStart, $employmentIds, $included, $commandKeyHash, $actorId);
    }

    /**
     * @param list<int> $employmentIds
     * @return array<int,array{entitlements:list<array<string,mixed>>,included_consumption:list<array<string,mixed>>}>
     */
    public function loadMany(int $supplierId, array $employmentIds, string $from, string $through, ?string $excludeAccountingPeriod = null, bool $lock = false): array
    {
        if ($employmentIds === []) return [];
        $ids = array_values(array_unique(array_map('intval', $employmentIds)));
        sort($ids, SORT_NUMERIC);
        $in = implode(',', array_fill(0, count($ids), '?'));
        if ($lock) {
            $stmt = $this->db->pdo()->prepare("SELECT id FROM payroll_employments WHERE supplier_id = ? AND id IN ($in) ORDER BY id FOR UPDATE");
            $stmt->execute([$supplierId, ...$ids]);
            $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        $result = [];
        foreach ($ids as $id) $result[$id] = ['entitlements' => [], 'included_consumption' => []];
        // Bez omezení dolní hranice dne vzniku: nevyčerpané sváteční volno
        // může pocházet z minulého roku a zůstává samostatným nárokem.
        $allocationExpr = $excludeAccountingPeriod === null
            ? 'SUM(allocated_delta_units)'
            : 'SUM(CASE WHEN accounting_period_start = ? THEN 0 ELSE allocated_delta_units END)';
        $settlementExpr = $excludeAccountingPeriod === null
            ? 'SUM(settled_delta_units)'
            : 'SUM(CASE WHEN accounting_period_start = ? THEN 0 ELSE settled_delta_units END)';
        $sql = "WITH ranked AS (
            SELECT entitlement.id, entitlement.employment_id, entitlement.kind, entitlement.work_date,
                   revision.sequence_no AS revision, revision.due_on, revision.accounting_period_start,
                   revision.accrued_delta_units, revision.allocated_delta_units, revision.settled_delta_units,
                   ROW_NUMBER() OVER (PARTITION BY entitlement.id, revision.accounting_period_start ORDER BY run_revision.approved_at DESC, run_revision.id DESC, revision.sequence_no DESC) AS position
              FROM payroll_hourly_time_entitlements entitlement
              JOIN payroll_hourly_time_entitlement_revisions revision FORCE INDEX (uq_hourly_time_entitlement_revision) ON revision.supplier_id = entitlement.supplier_id AND revision.entitlement_id = entitlement.id
              JOIN payroll_run_revisions run_revision ON run_revision.supplier_id = revision.supplier_id AND run_revision.idempotency_key_hash = revision.command_key_hash
             WHERE entitlement.supplier_id = ? AND entitlement.employment_id IN ($in) AND entitlement.work_date <= ?
               AND run_revision.status IN ('approved','superseded')
        ) SELECT id, employment_id, kind, work_date, MAX(revision) AS revision, MAX(due_on) AS due_on,
                 SUM(accrued_delta_units) AS accrued_duration_units, $allocationExpr AS allocated_duration_units,
                 $settlementExpr AS settled_duration_units
            FROM ranked WHERE position = 1 GROUP BY id, employment_id, kind, work_date ORDER BY employment_id, work_date, id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, ...$ids, $through, ...($excludeAccountingPeriod === null ? [] : [$excludeAccountingPeriod, $excludeAccountingPeriod])]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $accrued = (int) $row['accrued_duration_units'];
            $allocated = (int) $row['allocated_duration_units'];
            $settled = (int) $row['settled_duration_units'];
            $result[(int) $row['employment_id']]['entitlements'][] = [
                'id' => (int) $row['id'], 'revision' => (int) $row['revision'], 'kind' => (string) $row['kind'],
                'work_date' => (string) $row['work_date'], 'due_on' => $row['due_on'],
                'accrued_duration_units' => $accrued, 'allocated_duration_units' => $allocated,
                'settled_duration_units' => $settled, 'available_duration_units' => $accrued - $allocated - $settled,
            ];
        }
        $exclude = $excludeAccountingPeriod === null ? '' : ' AND consumption.accounting_period_start <> ?';
        $sql = "WITH ranked AS (
            SELECT consumption.employment_id, consumption.scope_key, consumption.kind, consumption.accounting_period_start,
                   consumption.duration_units,
                   ROW_NUMBER() OVER (PARTITION BY consumption.employment_id, consumption.scope_key, consumption.accounting_period_start
                     ORDER BY run_revision.approved_at DESC, run_revision.id DESC, consumption.id DESC) AS position
              FROM payroll_hourly_included_consumption consumption
              JOIN payroll_run_revisions run_revision ON run_revision.supplier_id = consumption.supplier_id AND run_revision.idempotency_key_hash = consumption.command_key_hash
             WHERE consumption.supplier_id = ? AND consumption.employment_id IN ($in)
               AND consumption.accounting_period_start >= ? AND consumption.accounting_period_start <= ?$exclude
               AND run_revision.status IN ('approved','superseded')
        ) SELECT * FROM ranked WHERE position = 1 ORDER BY employment_id, accounting_period_start, scope_key";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, ...$ids, $from, $through, ...($excludeAccountingPeriod === null ? [] : [$excludeAccountingPeriod])]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employment_id']]['included_consumption'][] = [
                'scope_key' => (string) $row['scope_key'], 'kind' => (string) $row['kind'],
                'accounting_period_start' => (string) $row['accounting_period_start'], 'duration_units' => (int) $row['duration_units'],
            ];
        }
        return $result;
    }

    /** @param list<int> $entitlementIds @return array<int,array<string,mixed>> */
    public function byIds(int $supplierId, int $employmentId, array $entitlementIds): array
    {
        if ($entitlementIds === []) return [];
        $in = implode(',', array_fill(0, count($entitlementIds), '?'));
        $stmt = $this->db->pdo()->prepare("WITH ranked AS (
            SELECT entitlement.id, entitlement.kind, entitlement.work_date, revision.sequence_no AS revision,
                   revision.due_on, revision.accrued_duration_units, revision.allocated_duration_units, revision.settled_duration_units,
                   ROW_NUMBER() OVER (PARTITION BY entitlement.id ORDER BY run_revision.approved_at DESC, run_revision.id DESC, revision.sequence_no DESC) AS position
              FROM payroll_hourly_time_entitlements entitlement
              JOIN payroll_hourly_time_entitlement_revisions revision FORCE INDEX (uq_hourly_time_entitlement_revision) ON revision.supplier_id = entitlement.supplier_id AND revision.entitlement_id = entitlement.id
              JOIN payroll_run_revisions run_revision ON run_revision.supplier_id = revision.supplier_id AND run_revision.idempotency_key_hash = revision.command_key_hash
             WHERE entitlement.supplier_id = ? AND entitlement.employment_id = ? AND entitlement.id IN ($in)
               AND run_revision.status IN ('approved','superseded')
        ) SELECT * FROM ranked WHERE position = 1");
        $stmt->execute([$supplierId, $employmentId, ...$entitlementIds]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $accrued = (int) $row['accrued_duration_units'];
            $allocated = (int) $row['allocated_duration_units'];
            $settled = (int) $row['settled_duration_units'];
            $result[(int) $row['id']] = ['id' => (int) $row['id'], 'revision' => (int) $row['revision'],
                'kind' => (string) $row['kind'], 'work_date' => (string) $row['work_date'], 'due_on' => $row['due_on'],
                'accrued_duration_units' => $accrued, 'allocated_duration_units' => $allocated,
                'settled_duration_units' => $settled, 'available_duration_units' => $accrued - $allocated - $settled];
        }
        return $result;
    }

    /**
     * Přesun doložených přesčasových hodin mezi pracovním a zúčtovacím měsícem.
     * Historii nelze oříznout před LAG: první oprava v dotazovaném čtvrtletí
     * potřebuje poslední platný stav před začátkem čtvrtletí.
     *
     * @param list<int> $employmentIds
     * @return array<int,array{periods:array<string,int>,sources:list<array<string,mixed>>,source_hash:string}>
     */
    public function approvedDurationReallocations(int $supplierId, array $employmentIds, string $from, string $through): array
    {
        if ($employmentIds === []) return [];
        $in = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare("WITH history AS (
            SELECT materialization.id, materialization.employment_id, materialization.accounting_period_start,
                   claim.work_period_start, materialization.full_duration_units,
                   COALESCE(LAG(materialization.full_duration_units) OVER (PARTITION BY materialization.claim_id
                     ORDER BY revision.approved_at, revision.id, materialization.sequence_no), 0) AS previous_duration_units,
                   revision.id AS run_revision_id, LOWER(HEX(materialization.source_snapshot_hash)) AS source_hash
              FROM payroll_hourly_wage_materializations materialization
              JOIN payroll_hourly_wage_claims claim ON claim.supplier_id = materialization.supplier_id AND claim.id = materialization.claim_id
              JOIN payroll_run_revisions revision ON revision.supplier_id = materialization.supplier_id AND revision.idempotency_key_hash = materialization.command_key_hash
             WHERE materialization.supplier_id = ? AND materialization.employment_id IN ($in)
               AND claim.purpose = 'achieved_overtime' AND revision.status IN ('approved','superseded')
        ) SELECT * FROM history WHERE work_period_start <> accounting_period_start
             AND ((work_period_start >= ? AND work_period_start <= ?) OR (accounting_period_start >= ? AND accounting_period_start <= ?))
             ORDER BY employment_id, run_revision_id, id");
        $stmt->execute([$supplierId, ...$employmentIds, $from, $through, $from, $through]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $delta = (int) $row['full_duration_units'] - (int) $row['previous_duration_units'];
            if ($delta === 0) continue;
            $id = (int) $row['employment_id'];
            $work = (string) $row['work_period_start'];
            $accounting = (string) $row['accounting_period_start'];
            $result[$id] ??= ['periods' => [], 'sources' => []];
            if ($work >= $from && $work <= $through) $result[$id]['periods'][$work] = ($result[$id]['periods'][$work] ?? 0) - $delta;
            if ($accounting >= $from && $accounting <= $through) $result[$id]['periods'][$accounting] = ($result[$id]['periods'][$accounting] ?? 0) + $delta;
            $result[$id]['sources'][] = ['materialization_id' => (int) $row['id'], 'run_revision_id' => (int) $row['run_revision_id'],
                'work_period_start' => $work, 'accounting_period_start' => $accounting,
                'duration_delta_units' => $delta, 'source_hash' => (string) $row['source_hash']];
        }
        foreach ($result as &$row) {
            ksort($row['periods']);
            $row['source_hash'] = hash('sha256', \MyInvoice\Service\Payroll\Ruleset\CanonicalJson::encode($row['sources']));
        }
        unset($row);
        return $result;
    }

    /** @param list<int> $employmentIds @param array<int,array<string,array<string,mixed>>> $included */
    private function appendIncluded(int $supplierId, string $periodStart, array $employmentIds, array $included, string $commandKeyHash, ?int $actorId): void
    {
        $in = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT DISTINCT consumption.employment_id, consumption.scope_key, consumption.kind
            FROM payroll_hourly_included_consumption consumption
            JOIN payroll_run_revisions revision ON revision.supplier_id = consumption.supplier_id AND revision.idempotency_key_hash = consumption.command_key_hash
            WHERE consumption.supplier_id = ? AND consumption.employment_id IN ($in) AND consumption.accounting_period_start = ?
              AND revision.status IN ('approved','superseded')");
        $stmt->execute([$supplierId, ...$employmentIds, $periodStart]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['employment_id'];
            $scope = (string) $row['scope_key'];
            $included[$id][$scope] ??= ['kind' => (string) $row['kind'], 'duration_units' => 0, 'allocations' => []];
        }
        $values = [];
        $args = [];
        foreach ($included as $employmentId => $scopes) foreach ($scopes as $scope => $entry) {
            $snapshot = \MyInvoice\Service\Payroll\Ruleset\CanonicalJson::encode(['allocations' => $entry['allocations']]);
            $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push($args, $supplierId, $employmentId, $scope, $entry['kind'], $periodStart, $commandKeyHash,
                $entry['duration_units'], $snapshot, hash('sha256', $snapshot, true), $actorId);
        }
        if ($values !== []) $this->db->pdo()->prepare('INSERT INTO payroll_hourly_included_consumption (supplier_id, employment_id, scope_key, kind, accounting_period_start, command_key_hash, duration_units, source_snapshot_json, source_snapshot_hash, created_by) VALUES ' . implode(',', $values))->execute($args);
    }
}
