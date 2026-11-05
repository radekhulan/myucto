<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBatchLoader;
use PDO;

/** Zápis schváleného nároku a jeho neměnné stopy do transakce zamykající běh. */
final class PayrollHourlyWageMaterializer
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRunSnapshotBuilder $snapshots,
        private readonly PayrollComponentRepository $components,
        private readonly PayrollInputRepository $inputs,
    ) {}

    /** @return array{written:int,blocked:int} */
    public function materialize(int $supplierId, int $runId, string $periodStart, ?int $officeId, int $actorId, string $commandKeyHash): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) throw new \LogicException('Hodinová mzda musí vznikat v transakci mzdového běhu.');
        if (strlen($commandKeyHash) !== 32) throw new \InvalidArgumentException('Otisk mzdového příkazu není platný.');
        $end = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $employments = $this->snapshots->employmentRows($supplierId, $periodStart, $end, $officeId);
        if ($employments === []) return ['written' => 0, 'blocked' => 0];
        $allIds = array_map(static fn (array $row): int => (int) $row['employment_id'], $employments);
        $hourly = (new PayrollRunSnapshotBatchLoader($this->db))->hourlyRemunerationTerms($supplierId, $allIds, $periodStart, $end);
        $outstanding = (new PayrollHourlyEntitlementLedger($this->db))->employmentIdsWithOutstanding($supplierId, $allIds, $periodStart, includeCurrentClaims: true);
        $selected = array_fill_keys(array_merge(array_keys($hourly), $outstanding), true);
        if ($selected === []) return ['written' => 0, 'blocked' => 0];
        $employments = array_values(array_filter($employments, static fn (array $row): bool => isset($selected[(int) $row['employment_id']])));
        $ids = array_keys($selected);
        sort($ids, SORT_NUMERIC);
        $in = implode(',', array_fill(0, count($ids), '?'));
        // Všichni zapisovatelé stejného nároku musí zabírat vztahy ve stejném pořadí.
        $locks = $pdo->prepare("SELECT id FROM payroll_employments WHERE supplier_id = ? AND id IN ($in) ORDER BY id FOR UPDATE");
        $locks->execute([$supplierId, ...$ids]);
        $locks->fetchAll(PDO::FETCH_COLUMN);
        // Zámek vlastního schváleného běhu je při opravě přípustný; vstupy
        // držené jiným během nebo bez doloženého vlastníka nelze změnit.
        $locked = $pdo->prepare("SELECT input.employment_id FROM payroll_inputs input FORCE INDEX (uq_payroll_input_external)
            WHERE input.supplier_id = ? AND input.period_start = ? AND input.status = 'locked'
              AND input.employment_id IN ($in)
              AND (NOT EXISTS (SELECT 1 FROM payroll_run_revisions own
                    JOIN payroll_run_employments frozen ON frozen.supplier_id = own.supplier_id AND frozen.revision_id = own.id
                    WHERE own.supplier_id = input.supplier_id AND own.run_id = ? AND frozen.employment_id = input.employment_id AND frozen.period_start = input.period_start)
                OR EXISTS (SELECT 1 FROM payroll_runs other FORCE INDEX (uq_payroll_run_period)
                    JOIN payroll_run_revisions held ON held.supplier_id = other.supplier_id AND held.run_id = other.id
                    JOIN payroll_run_employments frozen ON frozen.supplier_id = held.supplier_id AND frozen.revision_id = held.id
                    WHERE other.supplier_id = input.supplier_id AND other.period_start = input.period_start AND other.id <> ?
                      AND frozen.employment_id = input.employment_id AND frozen.period_start = input.period_start
                      AND (held.status = 'approved' OR (other.status <> 'cancelled' AND held.status IN ('snapshot','calculated','reviewed')))))
            LIMIT 1 FOR UPDATE");
        $locked->execute([$supplierId, $periodStart, ...$ids, $runId, $runId]);
        if ($locked->fetchColumn() !== false) throw new PayrollHourlyWageConflictException('Mzdové vstupy vztahu jsou uzamčené jiným během.');
        $previews = (new PayrollHourlyWagePreview($this->db))->forMany($supplierId, $periodStart, $employments, true);
        $ready = array_filter($previews, static fn (array $row): bool => $row['complete']);
        $blocked = count($previews) - count($ready);
        if ($ready === []) return ['written' => 0, 'blocked' => $blocked];
        $readyIds = array_keys($ready);
        $readyIn = implode(',', array_fill(0, count($readyIds), '?'));
        // Při opravě hodinového měsíce na měsíční se musí předchozí nároky,
        // které settlement-only kalkulátor již netvoří, explicitně vyrovnat.
        $previousClaims = $pdo->prepare("SELECT employment_id, purpose FROM payroll_hourly_wage_claims
            WHERE supplier_id = ? AND employment_id IN ($readyIn) AND work_period_start = ? ORDER BY employment_id, purpose FOR UPDATE");
        $previousClaims->execute([$supplierId, ...$readyIds, $periodStart]);
        $codes = ['ordinary_base' => 'MZDA_HODINOVA_ZAKLAD', 'achieved_overtime' => 'MZDA_DOSAZENA_PRESCAS',
            'holiday_nonworked_compensation' => 'NAHRADA_MZDY_SVATEK', 'holiday_time_off_compensation' => 'NAHRADA_MZDY_VOLNO_ZA_SVATEK'];
        foreach (\MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind::all() as $kind) $codes['premium_' . $kind->value] = $kind->componentCode();
        foreach ($previousClaims->fetchAll(PDO::FETCH_ASSOC) as $previousClaim) {
            $id = (int) $previousClaim['employment_id'];
            if (!$ready[$id]['settlement_only']) continue;
            $purpose = (string) $previousClaim['purpose'];
            if (in_array($purpose, array_column($ready[$id]['claims'], 'purpose'), true)) continue;
            $code = $codes[$purpose] ?? throw new \LogicException('Neznámý dřívější účel hodinového nároku.');
            $ready[$id]['claims'][] = ['purpose' => $purpose, 'component_code' => $code, 'ready' => true,
                'duration_units' => 0, 'worked_duration_units' => 0, 'raw_numerator_minor' => 0,
                'raw_denominator' => 1, 'analytical_minor' => 0, 'payable_minor' => 0, 'segments' => []];
        }

        $normalIds = array_keys(array_filter($ready, static fn (array $row): bool => !$row['settlement_only']));
        if ($normalIds !== []) {
            $normalIn = implode(',', array_fill(0, count($normalIds), '?'));
            $reservedIn = implode(',', array_fill(0, count(PayrollHourlyWageComponents::RESERVED), '?'));
            $conflicts = $pdo->prepare("SELECT input.employment_id, component.code FROM payroll_inputs input
                JOIN payroll_component_definitions component ON component.supplier_id = input.supplier_id AND component.id = input.component_id
                WHERE input.supplier_id = ? AND input.employment_id IN ($normalIn) AND input.period_start = ?
                  AND input.status <> 'cancelled' AND component.component_kind IN ('base_wage','hourly_wage')
                  AND component.code NOT IN ($reservedIn) ORDER BY input.employment_id, input.id LIMIT 1 FOR UPDATE");
            $conflicts->execute([$supplierId, ...$normalIds, $periodStart, ...PayrollHourlyWageComponents::RESERVED]);
            $conflict = $conflicts->fetch(PDO::FETCH_ASSOC);
            if (is_array($conflict)) {
                throw new PayrollHourlyWageConflictException(sprintf('Hodinová mzda vztahu %d koliduje s existující částkou složky %s. Ponechte jediný podklad mzdy.', $conflict['employment_id'], $conflict['code']));
            }
        }
        $claimPairs = [];
        $claimPairArgs = [];
        foreach ($ready as $employmentId => $preview) {
            foreach ($preview['claims'] as $claim) {
                $claimPairs[] = '(?, ?)';
                array_push($claimPairArgs, $employmentId, $claim['component_code']);
            }
        }
        if ($claimPairs !== []) {
            $pairsSql = implode(',', $claimPairs);
            $conflicts = $pdo->prepare("SELECT input.employment_id, component.code FROM payroll_inputs input
                JOIN payroll_component_definitions component ON component.supplier_id = input.supplier_id AND component.id = input.component_id
                WHERE input.supplier_id = ? AND input.employment_id IN ($readyIn) AND input.period_start = ?
                  AND input.status <> 'cancelled' AND (input.employment_id, component.code) IN ($pairsSql)
                  AND NOT EXISTS (SELECT 1 FROM payroll_hourly_wage_materializations owned
                      WHERE owned.supplier_id = input.supplier_id AND owned.input_id = input.id)
                ORDER BY input.employment_id, input.id LIMIT 1 FOR UPDATE");
            $conflicts->execute([$supplierId, ...$readyIds, $periodStart, ...$claimPairArgs]);
            $conflict = $conflicts->fetch(PDO::FETCH_ASSOC);
            if (is_array($conflict)) {
                throw new PayrollHourlyWageConflictException(sprintf('Hodinový nárok vztahu %d koliduje s existující složkou %s. Ponechte jediný podklad.', $conflict['employment_id'], $conflict['code']));
            }
        }
        $premiumValues = [];
        $premiumArgs = [];
        $premiumPairs = [];
        $premiumPairArgs = [];
        foreach ($ready as $employmentId => $preview) {
            foreach ($preview['claims'] as $claim) {
                if (!str_starts_with((string) $claim['purpose'], 'premium_')) continue;
                $kind = substr((string) $claim['purpose'], strlen('premium_'));
                if (\MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind::tryFrom($kind) === null) throw new \LogicException('Neznámý druh hodinového příplatku.');
                $premiumValues[] = '(?, ?, ?, ?, "time", ?)';
                $premiumPairs[] = '(?, ?)';
                array_push($premiumPairArgs, $employmentId, $kind);
                array_push($premiumArgs, $supplierId, $employmentId, $periodStart, $kind, $actorId);
            }
        }
        if ($premiumValues !== []) {
            $pdo->prepare('INSERT INTO payroll_surcharge_period_claims (supplier_id, employment_id, period_start, surcharge_kind, claim_source, created_by) VALUES ' . implode(',', $premiumValues) . ' ON DUPLICATE KEY UPDATE id = id')->execute($premiumArgs);
            $premiumPairSql = implode(',', $premiumPairs);
            $stmt = $pdo->prepare("SELECT employment_id, surcharge_kind FROM payroll_surcharge_period_claims WHERE supplier_id = ? AND employment_id IN ($readyIn) AND period_start = ? AND (employment_id, surcharge_kind) IN ($premiumPairSql) AND claim_source <> 'time' LIMIT 1 FOR UPDATE");
            $stmt->execute([$supplierId, ...$readyIds, $periodStart, ...$premiumPairArgs]);
            if ($stmt->fetch(PDO::FETCH_ASSOC) !== false) throw new PayrollHourlyWageConflictException('Zákonný příplatek za toto období již používá ruční zdroj.');
        }
        $claimValues = [];
        $claimArgs = [];
        foreach ($ready as $employmentId => $preview) {
            foreach ($preview['claims'] as $claim) {
                $claimValues[] = '(?, ?, ?, ?, "hourly_calculation", ?)';
                array_push($claimArgs, $supplierId, $employmentId, $periodStart, $claim['purpose'], $actorId);
            }
        }
        if ($claimValues === []) {
            (new PayrollHourlyEntitlementLedger($this->db))->appendForRevision($supplierId, $periodStart, $readyIds, $ready, $commandKeyHash, $actorId);
            return ['written' => 0, 'blocked' => $blocked];
        }
        $pdo->prepare('INSERT INTO payroll_hourly_wage_claims (supplier_id, employment_id, work_period_start, purpose, claim_source, created_by) VALUES ' . implode(',', $claimValues) . ' ON DUPLICATE KEY UPDATE id = id')->execute($claimArgs);
        $claimRows = $pdo->prepare("SELECT id, employment_id, purpose, claim_source FROM payroll_hourly_wage_claims WHERE supplier_id = ? AND employment_id IN ($readyIn) AND work_period_start = ? ORDER BY employment_id, purpose FOR UPDATE");
        $claimRows->execute([$supplierId, ...$readyIds, $periodStart]);
        $claims = [];
        $claimIds = [];
        foreach ($claimRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['claim_source'] !== 'hourly_calculation') throw new PayrollHourlyWageConflictException('Hodinový nárok již používá jiný zdroj mzdy.');
            $claims[(int) $row['employment_id']][(string) $row['purpose']] = (int) $row['id'];
            $claimIds[] = (int) $row['id'];
        }
        $claimIn = implode(',', array_fill(0, count($claimIds), '?'));
        $ledger = $pdo->prepare("SELECT materialization.claim_id, MAX(materialization.sequence_no) AS seq,
            SUM(CASE WHEN materialization.input_id IS NULL OR input.status <> 'cancelled' THEN materialization.delta_amount_minor ELSE 0 END) AS amount,
            SUM(CASE WHEN materialization.input_id IS NULL OR input.status <> 'cancelled' THEN materialization.duration_delta_units ELSE 0 END) AS duration,
            SUM(CASE WHEN materialization.input_id IS NOT NULL AND input.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
            FROM payroll_hourly_wage_materializations materialization
            LEFT JOIN payroll_inputs input ON input.supplier_id = materialization.supplier_id AND input.id = materialization.input_id
            WHERE materialization.supplier_id = ? AND materialization.claim_id IN ($claimIn)
            GROUP BY materialization.claim_id");
        $ledger->execute([$supplierId, ...$claimIds]);
        $previous = [];
        foreach ($ledger->fetchAll(PDO::FETCH_ASSOC) as $row) $previous[(int) $row['claim_id']] = $row;
        $employees = [];
        foreach ($employments as $employment) $employees[(int) $employment['employment_id']] = (int) $employment['employee_id'];
        $newInputs = [];
        $pending = [];
        foreach ($ready as $employmentId => $preview) {
            foreach ($preview['claims'] as $claim) {
                if (($claim['ready'] ?? false) !== true || !is_int($claim['payable_minor'] ?? null)) {
                    throw new \LogicException('Úplný výpočet obsahuje nevyhodnocený nárok.');
                }
                $claimId = $claims[$employmentId][$claim['purpose']] ?? throw new \LogicException('Chybí zajištěný nárok hodinové mzdy.');
                $old = $previous[$claimId] ?? ['seq' => 0, 'amount' => 0, 'duration' => 0, 'cancelled' => 0];
                if ((int) $old['cancelled'] > 0) throw new \InvalidArgumentException('Zrušený vstup hodinové mzdy vyžaduje kontrolu účetní před dalším výpočtem.');
                $full = (int) $claim['payable_minor'];
                if (!is_int($claim['worked_duration_units'] ?? null)) {
                    throw new \LogicException('Nárok neobsahuje doloženou odpracovanou dobu pro průměrný výdělek.');
                }
                $duration = $claim['worked_duration_units'];
                $delta = $full - (int) $old['amount'];
                $durationDelta = $duration - (int) $old['duration'];
                // Každá nová revize má vlastní úplný stav i tehdy, když se
                // nárok nezměnil; předchozí revize mohla být opuštěna.
                $sequence = (int) $old['seq'] + 1;
                $external = sprintf('hourly-wage:%d:%d', $claimId, $sequence);
                $snapshot = CanonicalJson::encode($preview['_source_snapshot'] + ['claim' => $claim]);
                if ($delta !== 0) $newInputs[] = ['employee_id' => $employees[$employmentId], 'employment_id' => $employmentId,
                    'component_code' => $claim['component_code'], 'source_period_start' => null, 'amount_minor' => $delta,
                    'external_id' => $external, 'source_snapshot_json' => $snapshot];
                $pending[] = ['claim_id' => $claimId, 'employment_id' => $employmentId, 'sequence' => $sequence,
                    'external_id' => $delta === 0 ? null : $external, 'full' => $full, 'delta' => $delta,
                    'duration' => $duration, 'duration_delta' => $durationDelta, 'hash' => $preview['evidence_hash'], 'snapshot' => $snapshot];
            }
        }
        if ($pending === []) {
            (new PayrollHourlyEntitlementLedger($this->db))->appendForRevision($supplierId, $periodStart, $readyIds, $ready, $commandKeyHash, $actorId);
            return ['written' => 0, 'blocked' => $blocked];
        }
        if ($newInputs !== []) $this->components->ensureDefaults($supplierId);
        $inputIds = $this->inputs->createApprovedHourlyBatch($supplierId, $periodStart, $newInputs, $actorId);
        $values = [];
        $args = [];
        foreach ($pending as $item) {
            $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push($args, $supplierId, $item['claim_id'], $item['employment_id'], $item['sequence'], $periodStart, $runId,
                $item['external_id'] === null ? null : ($inputIds[$item['external_id']] ?? throw new \LogicException('Chybí vytvořený mzdový vstup.')),
                $item['full'], $item['delta'], hex2bin($item['hash']), $item['snapshot'], hash('sha256', $item['snapshot'], true), $actorId,
                $item['duration'], $item['duration_delta'], $commandKeyHash);
        }
        $pdo->prepare('INSERT INTO payroll_hourly_wage_materializations (supplier_id, claim_id, employment_id, sequence_no, accounting_period_start, run_id, input_id, full_amount_minor, delta_amount_minor, evidence_hash, source_snapshot_json, source_snapshot_hash, created_by, full_duration_units, duration_delta_units, command_key_hash) VALUES ' . implode(',', $values))->execute($args);
        (new PayrollHourlyEntitlementLedger($this->db))->appendForRevision($supplierId, $periodStart, $readyIds, $ready, $commandKeyHash, $actorId);
        return ['written' => count($pending), 'blocked' => $blocked];
    }
}
