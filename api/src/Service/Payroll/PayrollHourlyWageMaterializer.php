<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBatchLoader;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind;
use PDO;

/**
 * Zápis schváleného nároku a jeho neměnné stopy do transakce zamykající běh.
 *
 * Kolize jednoho vztahu nesmí shodit příkaz celé firmy: vztah se vyřadí
 * a vrátí se jako blokace snímku, ostatní vztahy se zpracují normálně.
 */
final class PayrollHourlyWageMaterializer
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRunSnapshotBuilder $snapshots,
        private readonly PayrollComponentRepository $components,
        private readonly PayrollInputRepository $inputs,
    ) {}

    /** @return array{written:int,blocked:int,blockers:array<int,list<array{code:string,message:string,remediation:string}>>} */
    public function materialize(int $supplierId, int $runId, string $periodStart, ?int $officeId, int $actorId, string $commandKeyHash): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) throw new \LogicException('Hodinová mzda musí vznikat v transakci mzdového běhu.');
        if (strlen($commandKeyHash) !== 32) throw new \InvalidArgumentException('Otisk mzdového příkazu není platný.');
        $end = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $blockers = [];
        $incomplete = 0;
        $finish = static function (int $written) use (&$incomplete, &$blockers): array {
            return ['written' => $written, 'blocked' => $incomplete + count($blockers), 'blockers' => $blockers];
        };
        if (!$this->hasHourlyScope($supplierId, $periodStart, $end)) return $finish(0);
        $employments = $this->snapshots->employmentRows($supplierId, $periodStart, $end, $officeId);
        if ($employments === []) return $finish(0);
        $allIds = array_map(static fn (array $row): int => (int) $row['employment_id'], $employments);
        $hourly = (new PayrollRunSnapshotBatchLoader($this->db))->hourlyRemunerationTerms($supplierId, $allIds, $periodStart, $end);
        $outstanding = (new PayrollHourlyEntitlementLedger($this->db))->employmentIdsWithOutstanding($supplierId, $allIds, $periodStart, includeCurrentClaims: true);
        $selected = array_fill_keys(array_merge(array_keys($hourly), $outstanding), true);
        if ($selected === []) return $finish(0);
        $employments = array_values(array_filter($employments, static fn (array $row): bool => isset($selected[(int) $row['employment_id']])));
        $ids = array_keys($selected);
        sort($ids, SORT_NUMERIC);
        $in = implode(',', array_fill(0, count($ids), '?'));
        // Všichni zapisovatelé stejného nároku musí zabírat vztahy ve stejném pořadí.
        $locks = $pdo->prepare("SELECT id FROM payroll_employments WHERE supplier_id = ? AND id IN ($in) ORDER BY id FOR UPDATE");
        $locks->execute([$supplierId, ...$ids]);
        $locks->fetchAll(PDO::FETCH_COLUMN);
        $inputsLink = '/payroll/quick-inputs?period=' . substr($periodStart, 0, 7) . '&employment=';
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
            FOR UPDATE");
        $locked->execute([$supplierId, $periodStart, ...$ids, $runId, $runId]);
        foreach (array_unique(array_map('intval', $locked->fetchAll(PDO::FETCH_COLUMN))) as $id) {
            $blockers[$id][] = ['code' => 'hourly_inputs_locked_by_other_run', 'remediation' => '/payroll/runs?period=' . substr($periodStart, 0, 7),
                'message' => 'Mzdové vstupy vztahu drží jiný mzdový běh stejného období. Dokončete nebo zrušte druhý běh a potom podklady tohoto běhu obnovte.'];
        }
        $employments = array_values(array_filter($employments, static fn (array $row): bool => !isset($blockers[(int) $row['employment_id']])));
        $previews = $employments === [] ? [] : (new PayrollHourlyWagePreview($this->db))->forMany($supplierId, $periodStart, $employments, true);
        $ready = array_filter($previews, static fn (array $row): bool => $row['complete']);
        $incomplete = count($previews) - count($ready);
        if ($ready === []) return $finish(0);
        $codes = ['ordinary_base' => 'MZDA_HODINOVA_ZAKLAD', 'achieved_overtime' => 'MZDA_DOSAZENA_PRESCAS', 'minimum_wage_top_up' => 'DOPLATEK_MINIMALNI_MZDA',
            'holiday_nonworked_compensation' => 'NAHRADA_MZDY_SVATEK', 'holiday_time_off_compensation' => 'NAHRADA_MZDY_VOLNO_ZA_SVATEK'];
        foreach (PayrollSurchargeKind::all() as $kind) $codes['premium_' . $kind->value] = $kind->componentCode();
        $readyIds = array_keys($ready);
        $readyIn = implode(',', array_fill(0, count($readyIds), '?'));
        $existingClaims = $pdo->prepare("SELECT id, employment_id, purpose, claim_source FROM payroll_hourly_wage_claims
            WHERE supplier_id = ? AND employment_id IN ($readyIn) AND work_period_start = ? ORDER BY employment_id, purpose FOR UPDATE");
        $existingClaims->execute([$supplierId, ...$readyIds, $periodStart]);
        $claimIds = [];
        $existingIds = [];
        foreach ($existingClaims->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['employment_id'];
            $purpose = (string) $row['purpose'];
            if ($row['claim_source'] !== 'hourly_calculation') {
                $blockers[$id][] = ['code' => 'hourly_claim_foreign_source', 'remediation' => $inputsLink . $id,
                    'message' => sprintf('Nárok %s za toto období už vznikl z jiného zdroje mzdy než z hodinového výpočtu. Ponechte jediný podklad.', $codes[$purpose] ?? $purpose)];
                continue;
            }
            $claimIds[$id][$purpose] = (int) $row['id'];
            $existingIds[] = (int) $row['id'];
            // Při opravě hodinového měsíce na měsíční se musí předchozí nároky,
            // které settlement-only kalkulátor již netvoří, explicitně vyrovnat.
            if (!$ready[$id]['settlement_only'] || in_array($purpose, array_column($ready[$id]['claims'], 'purpose'), true)) continue;
            $code = $codes[$purpose] ?? throw new \LogicException('Neznámý dřívější účel hodinového nároku.');
            $ready[$id]['claims'][] = ['purpose' => $purpose, 'component_code' => $code, 'ready' => true,
                'duration_units' => 0, 'worked_duration_units' => 0, 'raw_numerator_minor' => 0,
                'raw_denominator' => 1, 'analytical_minor' => 0, 'payable_minor' => 0, 'segments' => []];
        }
        $history = $this->history($supplierId, $existingIds);
        // Plán každého nároku vůči poslednímu ZÚČTOVANÉMU stavu: neschválená revize
        // svůj dřívější rozdíl nahrazuje, aby páska neukazovala nezúčtované částky.
        $plans = [];
        foreach ($ready as $employmentId => $preview) {
            if (isset($blockers[$employmentId])) continue;
            foreach ($preview['claims'] as $claim) {
                if (($claim['ready'] ?? false) !== true || !is_int($claim['payable_minor'] ?? null)) {
                    throw new \LogicException('Úplný výpočet obsahuje nevyhodnocený nárok.');
                }
                if (!is_int($claim['worked_duration_units'] ?? null)) {
                    throw new \LogicException('Nárok neobsahuje doloženou odpracovanou dobu pro průměrný výdělek.');
                }
                $claimId = $claimIds[$employmentId][$claim['purpose']] ?? null;
                $state = $claimId === null ? null : ($history[$claimId] ?? null);
                if ($state !== null && !$state['consistent']) {
                    $blockers[$employmentId][] = ['code' => 'hourly_settled_input_cancelled', 'remediation' => $inputsLink . $employmentId,
                        'message' => sprintf('Vstup složky %s ze schválené revize byl zrušen a evidence hodinového nároku s ním nesouhlasí. Zkontrolujte storno v mzdových vstupech; do nápravy nelze hodinovou mzdu vztahu znovu vypočítat.', $claim['component_code'])];
                    continue 2;
                }
                $full = (int) $claim['payable_minor'];
                $baseline = $state['baseline_amount'] ?? 0;
                $plans[$employmentId][] = ['claim' => $claim, 'claim_id' => $claimId, 'sequence' => ($state['sequence'] ?? 0) + 1,
                    'full' => $full, 'delta' => $full - $baseline, 'duration' => $claim['worked_duration_units'],
                    'duration_delta' => $claim['worked_duration_units'] - ($state['baseline_duration'] ?? 0),
                    'replaced' => $state['unsettled_inputs'] ?? []];
            }
        }
        // Vypořádání dřívějších nároků se netýká práce tohoto měsíce; nulový nárok
        // bez dřívějšího zápisu proto nekoliduje s měsíčními podklady.
        $relevant = static fn (int $employmentId, array $plan): bool => !$ready[$employmentId]['settlement_only']
            || $plan['full'] !== 0 || $plan['delta'] !== 0 || $plan['replaced'] !== [];
        // Náhled ověřuje složku jen u nenulového nároku, vstup ale vzniká i z
        // opravného rozdílu k nule. Neplatná složka blokuje jen dotčený vztah.
        $componentProblems = null;
        foreach ($plans as $employmentId => $items) {
            foreach ($items as $plan) {
                if ($plan['delta'] === 0) continue;
                $code = (string) $plan['claim']['component_code'];
                $componentProblems ??= PayrollHourlyWageComponents::definitionProblems($pdo, $supplierId, $periodStart);
                if (!isset($componentProblems[$code])) continue;
                $blockers[$employmentId][] = ['code' => 'hourly_component_definition_invalid', 'remediation' => '/payroll/components',
                    'message' => sprintf('Mzdová složka %s je ve firmě vedená jako vlastní nebo nemá v měsíci jednoznačnou verzi s klasifikací hodinové mzdy. Upravte nebo ukončete vlastní složku v číselníku.', $code)];
                break;
            }
        }

        $normalIds = array_keys(array_filter($plans, static fn (int $id): bool => !$ready[$id]['settlement_only'], ARRAY_FILTER_USE_KEY));
        if ($normalIds !== []) {
            $normalIn = implode(',', array_fill(0, count($normalIds), '?'));
            $reservedIn = implode(',', array_fill(0, count(PayrollHourlyWageComponents::RESERVED), '?'));
            $conflicts = $pdo->prepare("SELECT input.employment_id, component.code FROM payroll_inputs input
                JOIN payroll_component_definitions component ON component.supplier_id = input.supplier_id AND component.id = input.component_id
                WHERE input.supplier_id = ? AND input.employment_id IN ($normalIn) AND input.period_start = ?
                  AND input.status <> 'cancelled' AND component.component_kind IN ('base_wage','hourly_wage')
                  AND component.code NOT IN ($reservedIn) ORDER BY input.employment_id, input.id FOR UPDATE");
            $conflicts->execute([$supplierId, ...$normalIds, $periodStart, ...PayrollHourlyWageComponents::RESERVED]);
            foreach ($conflicts->fetchAll(PDO::FETCH_ASSOC) as $conflict) {
                $id = (int) $conflict['employment_id'];
                if (isset($blockers[$id])) continue;
                $blockers[$id][] = ['code' => 'hourly_wage_base_conflict', 'remediation' => $inputsLink . $id,
                    'message' => sprintf('Hodinová mzda koliduje s existující částkou složky %s. Ponechte jediný podklad mzdy: zrušte ruční vstup, nebo upravte podmínky vztahu.', $conflict['code'])];
            }
        }
        $claimPairs = [];
        $claimPairArgs = [];
        foreach ($plans as $employmentId => $items) {
            if (isset($blockers[$employmentId])) continue;
            foreach ($items as $plan) {
                if (!$relevant($employmentId, $plan)) continue;
                $claimPairs[] = '(?, ?)';
                array_push($claimPairArgs, $employmentId, $plan['claim']['component_code']);
            }
        }
        if ($claimPairs !== []) {
            $pairsSql = implode(',', $claimPairs);
            $pairIds = array_values(array_unique(array_filter($claimPairArgs, 'is_int')));
            $pairIn = implode(',', array_fill(0, count($pairIds), '?'));
            $conflicts = $pdo->prepare("SELECT input.employment_id, component.code,
                    EXISTS (SELECT 1 FROM payroll_surcharge_input_materializations attendance
                        WHERE attendance.supplier_id = input.supplier_id AND attendance.input_id = input.id) AS attendance_owned,
                    (input.source_period_start IS NULL OR input.source_period_start = input.period_start) AS current_work
                FROM payroll_inputs input
                JOIN payroll_component_definitions component ON component.supplier_id = input.supplier_id AND component.id = input.component_id
                WHERE input.supplier_id = ? AND input.employment_id IN ($pairIn) AND input.period_start = ?
                  AND input.status <> 'cancelled' AND (input.employment_id, component.code) IN ($pairsSql)
                  AND NOT EXISTS (SELECT 1 FROM payroll_hourly_wage_materializations owned
                      WHERE owned.supplier_id = input.supplier_id AND owned.input_id = input.id)
                ORDER BY input.employment_id, input.id FOR UPDATE");
            $conflicts->execute([$supplierId, ...$pairIds, $periodStart, ...$claimPairArgs]);
            foreach ($conflicts->fetchAll(PDO::FETCH_ASSOC) as $conflict) {
                $id = (int) $conflict['employment_id'];
                // Příplatek z docházky nebo ruční vstup měsíčního vztahu za práci
                // tohoto měsíce vypořádání dřívějšího nároku nezdvojuje; doplatek
                // za dřívější období by ale mohl platit tentýž nárok.
                if (isset($blockers[$id]) || ($ready[$id]['settlement_only']
                    && ((int) $conflict['attendance_owned'] === 1 || (int) $conflict['current_work'] === 1))) continue;
                $blockers[$id][] = ['code' => 'hourly_claim_component_conflict', 'remediation' => $inputsLink . $id,
                    'message' => sprintf('Hodinový nárok koliduje s existující složkou %s. Ponechte jediný podklad: zrušte ruční vstup této složky, hodinový výpočet ji doplní sám.', $conflict['code'])];
            }
        }
        $premiumPairs = [];
        $premiumPairArgs = [];
        foreach ($plans as $employmentId => $items) {
            // Vypořádání dřívějšího nároku nezabírá příplatek práce tohoto měsíce.
            if (isset($blockers[$employmentId]) || $ready[$employmentId]['settlement_only']) continue;
            foreach ($items as $plan) {
                if (!str_starts_with((string) $plan['claim']['purpose'], 'premium_')) continue;
                $kind = substr((string) $plan['claim']['purpose'], strlen('premium_'));
                if (PayrollSurchargeKind::tryFrom($kind) === null) throw new \LogicException('Neznámý druh hodinového příplatku.');
                $premiumPairs[] = '(?, ?)';
                array_push($premiumPairArgs, $employmentId, $kind);
            }
        }
        if ($premiumPairs !== []) {
            $premiumPairSql = implode(',', $premiumPairs);
            $premiumIds = array_values(array_unique(array_filter($premiumPairArgs, 'is_int')));
            $premiumIn = implode(',', array_fill(0, count($premiumIds), '?'));
            $stmt = $pdo->prepare("SELECT employment_id, surcharge_kind, claim_source FROM payroll_surcharge_period_claims WHERE supplier_id = ? AND employment_id IN ($premiumIn) AND period_start = ? AND (employment_id, surcharge_kind) IN ($premiumPairSql) ORDER BY employment_id, surcharge_kind FOR UPDATE");
            $stmt->execute([$supplierId, ...$premiumIds, $periodStart, ...$premiumPairArgs]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int) $row['employment_id'];
                if (isset($blockers[$id]) || $row['claim_source'] === 'time') continue;
                $kind = PayrollSurchargeKind::from((string) $row['surcharge_kind']);
                $blockers[$id][] = ['code' => 'hourly_premium_manual_source_conflict', 'remediation' => $inputsLink . $id,
                    'message' => sprintf('%s (%s) je za toto období zadán ručně v rychlém měsíčním vstupu. Ponechte jen jeden podklad: ruční příplatek odeberte, hodinový výpočet ho doloží z evidence.', $kind->label(), $kind->section())];
            }
        }
        $plans = array_filter($plans, static fn (int $id): bool => !isset($blockers[$id]), ARRAY_FILTER_USE_KEY);
        $readyIds = array_keys(array_filter($ready, static fn (int $id): bool => !isset($blockers[$id]), ARRAY_FILTER_USE_KEY));
        $ready = array_intersect_key($ready, array_flip($readyIds));
        if ($readyIds === []) return $finish(0);
        $premiumValues = [];
        $premiumArgs = [];
        $claimValues = [];
        $claimArgs = [];
        foreach ($plans as $employmentId => $items) {
            foreach ($items as $plan) {
                $purpose = (string) $plan['claim']['purpose'];
                if (!$ready[$employmentId]['settlement_only'] && str_starts_with($purpose, 'premium_')) {
                    $premiumValues[] = '(?, ?, ?, ?, "time", ?)';
                    array_push($premiumArgs, $supplierId, $employmentId, $periodStart, substr($purpose, strlen('premium_')), $actorId);
                }
                if ($plan['claim_id'] !== null) continue;
                $claimValues[] = '(?, ?, ?, ?, "hourly_calculation", ?)';
                array_push($claimArgs, $supplierId, $employmentId, $periodStart, $purpose, $actorId);
            }
        }
        if ($premiumValues !== []) {
            $pdo->prepare('INSERT INTO payroll_surcharge_period_claims (supplier_id, employment_id, period_start, surcharge_kind, claim_source, created_by) VALUES ' . implode(',', $premiumValues) . ' ON DUPLICATE KEY UPDATE id = id')->execute($premiumArgs);
        }
        if ($plans === []) {
            (new PayrollHourlyEntitlementLedger($this->db))->appendForRevision($supplierId, $periodStart, $readyIds, $ready, $commandKeyHash, $actorId);
            return $finish(0);
        }
        if ($claimValues !== []) {
            $pdo->prepare('INSERT INTO payroll_hourly_wage_claims (supplier_id, employment_id, work_period_start, purpose, claim_source, created_by) VALUES ' . implode(',', $claimValues) . ' ON DUPLICATE KEY UPDATE id = id')->execute($claimArgs);
            $planIds = array_keys($plans);
            $planIn = implode(',', array_fill(0, count($planIds), '?'));
            $claimRows = $pdo->prepare("SELECT id, employment_id, purpose, claim_source FROM payroll_hourly_wage_claims WHERE supplier_id = ? AND employment_id IN ($planIn) AND work_period_start = ? ORDER BY employment_id, purpose FOR UPDATE");
            $claimRows->execute([$supplierId, ...$planIds, $periodStart]);
            foreach ($claimRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['claim_source'] !== 'hourly_calculation') throw new \LogicException('Zajištěný hodinový nárok změnil zdroj během příkazu.');
                $claimIds[(int) $row['employment_id']][(string) $row['purpose']] = (int) $row['id'];
            }
        }
        $employees = [];
        foreach ($employments as $employment) $employees[(int) $employment['employment_id']] = (int) $employment['employee_id'];
        $newInputs = [];
        $pending = [];
        $replaced = [];
        foreach ($plans as $employmentId => $items) {
            $preview = $ready[$employmentId];
            foreach ($items as $plan) {
                $claim = $plan['claim'];
                $claimId = $claimIds[$employmentId][$claim['purpose']] ?? throw new \LogicException('Chybí zajištěný nárok hodinové mzdy.');
                $external = sprintf('hourly-wage:%d:%d', $claimId, $plan['sequence']);
                $snapshot = CanonicalJson::encode($preview['_source_snapshot'] + ['claim' => $claim]);
                array_push($replaced, ...$plan['replaced']);
                if ($plan['delta'] !== 0) $newInputs[] = ['employee_id' => $employees[$employmentId], 'employment_id' => $employmentId,
                    'component_code' => $claim['component_code'], 'source_period_start' => null, 'amount_minor' => $plan['delta'],
                    'external_id' => $external, 'source_snapshot_json' => $snapshot];
                $pending[] = ['claim_id' => $claimId, 'employment_id' => $employmentId, 'sequence' => $plan['sequence'],
                    'external_id' => $plan['delta'] === 0 ? null : $external, 'full' => $plan['full'], 'delta' => $plan['delta'],
                    'duration' => $plan['duration'], 'duration_delta' => $plan['duration_delta'], 'hash' => $preview['evidence_hash'], 'snapshot' => $snapshot];
            }
        }
        if ($replaced !== []) {
            // Nezúčtovaný rozdíl opuštěné revize nahrazuje nový rozdíl proti zúčtovanému stavu.
            $replacedIn = implode(',', array_fill(0, count($replaced), '?'));
            $pdo->prepare("UPDATE payroll_inputs SET status = 'cancelled', row_version = row_version + 1
                WHERE supplier_id = ? AND id IN ($replacedIn) AND status IN ('approved','locked')")->execute([$supplierId, ...$replaced]);
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
        return $finish(count($pending));
    }

    /** Levný předstupeň: bez hodinových podmínek a dřívějších nároků není co zapisovat. */
    private function hasHourlyScope(int $supplierId, string $periodStart, string $periodEnd): bool
    {
        $stmt = $this->db->pdo()->prepare("SELECT EXISTS (SELECT 1 FROM payroll_employment_terms
                    WHERE supplier_id = ? AND remuneration_type = 'hourly' AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?))
                OR EXISTS (SELECT 1 FROM payroll_hourly_wage_claims WHERE supplier_id = ? AND work_period_start = ?)
                OR EXISTS (SELECT 1 FROM payroll_hourly_time_entitlements WHERE supplier_id = ? AND work_date < ?)");
        $stmt->execute([$supplierId, $periodEnd, $periodStart, $supplierId, $periodStart, $supplierId, $periodStart]);
        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * Stav nároků vůči zúčtovaným revizím. Zúčtovaný je zápis příkazu, jehož
     * revize byla schválena (i později nahrazena opravou).
     *
     * @param list<int> $claimIds
     * @return array<int,array{sequence:int,baseline_amount:int,baseline_duration:int,consistent:bool,unsettled_inputs:list<int>}>
     */
    private function history(int $supplierId, array $claimIds): array
    {
        if ($claimIds === []) return [];
        $in = implode(',', array_fill(0, count($claimIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT materialization.claim_id, materialization.sequence_no, materialization.input_id,
                materialization.full_amount_minor, materialization.full_duration_units, materialization.delta_amount_minor,
                materialization.duration_delta_units, input.status AS input_status,
                EXISTS (SELECT 1 FROM payroll_run_revisions revision
                    WHERE revision.supplier_id = materialization.supplier_id AND revision.idempotency_key_hash = materialization.command_key_hash
                      AND revision.status IN ('approved','superseded')) AS settled
            FROM payroll_hourly_wage_materializations materialization
            LEFT JOIN payroll_inputs input ON input.supplier_id = materialization.supplier_id AND input.id = materialization.input_id
            WHERE materialization.supplier_id = ? AND materialization.claim_id IN ($in)
            ORDER BY materialization.claim_id, materialization.sequence_no");
        $stmt->execute([$supplierId, ...$claimIds]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[(int) $row['claim_id']][] = $row;
        $result = [];
        foreach ($rows as $claimId => $items) {
            $baseline = null;
            foreach ($items as $row) if ((int) $row['settled'] === 1) $baseline = $row;
            $settledSequence = $baseline === null ? 0 : (int) $baseline['sequence_no'];
            $settledAmount = 0;
            $settledDuration = 0;
            $unsettled = [];
            foreach ($items as $row) {
                $active = $row['input_id'] === null || $row['input_status'] !== 'cancelled';
                if ((int) $row['sequence_no'] <= $settledSequence) {
                    if ($active) {
                        $settledAmount += (int) $row['delta_amount_minor'];
                        $settledDuration += (int) $row['duration_delta_units'];
                    }
                } elseif ($row['input_id'] !== null && $active) {
                    $unsettled[] = (int) $row['input_id'];
                }
            }
            $result[$claimId] = ['sequence' => (int) end($items)['sequence_no'],
                'baseline_amount' => $baseline === null ? 0 : (int) $baseline['full_amount_minor'],
                'baseline_duration' => $baseline === null ? 0 : (int) $baseline['full_duration_units'],
                'consistent' => $baseline === null || ($settledAmount === (int) $baseline['full_amount_minor'] && $settledDuration === (int) $baseline['full_duration_units']),
                'unsettled_inputs' => $unsettled];
        }
        return $result;
    }
}
