<?php

declare(strict_types=1);

namespace MyInvoice\Service\Sample;

use DateTimeImmutable;
use DateTimeZone;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\Net\PayrollPayoutRuleInput;
use MyInvoice\Service\Payroll\Net\PayrollPayoutRuleService;
use MyInvoice\Service\Payroll\Payment\PayrollPaydayResolver;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use MyInvoice\Service\Payroll\PayrollAbsenceValidator;
use MyInvoice\Service\Payroll\Absence\PayrollWageProrationService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;
use MyInvoice\Service\Payroll\Time\PayrollTimeService;
use PDO;

final class SamplePayrollGenerator
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollPersonCreateService $people,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollPersonStatutoryEvidenceRepository $evidence,
        private readonly PayrollStatutoryAccumulatorRepository $accumulators,
        private readonly PayrollEmployerPolicyRepository $policies,
        private readonly PayrollInstitutionAccountRepository $institutionAccounts,
        private readonly PayrollComponentRepository $components,
        private readonly PayrollInputRepository $inputs,
        private readonly PayrollPayoutRuleService $payoutRules,
        private readonly PayrollTimeService $time,
        private readonly PayrollRunCommandService $runs,
        private readonly PayrollAbsenceRepository $absences,
        private readonly PayrollAbsenceValidator $absenceValidator,
        private readonly PayrollWageProrationService $wageProration,
        private readonly PayrollPaydayResolver $paydays,
    ) {}

    public function generate(int $supplierId, int $userId, DateTimeImmutable $today, bool $advanced, callable $track): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Ukázkové mzdy vyžadují transakci generátoru.');
        }
        $before = SamplePayrollPurger::snapshot($pdo, $supplierId);
        foreach (['payroll_employees', 'payroll_employments', 'payroll_runs', 'payroll_period_ownership'] as $table) {
            if (($before[$table] ?? []) !== []) {
                throw new \DomainException('Ukázkové mzdy vyžadují firmu bez existujících zaměstnanců a mzdových běhů.');
            }
        }
        $originalIdentity = $this->prepareEmployerIdentity($supplierId);
        try {
        $enabled = $pdo->prepare('SELECT payroll_enabled FROM supplier WHERE id = ?');
        $enabled->execute([$supplierId]);
        $track('payroll_restore_enabled', (int) $enabled->fetchColumn() + 1);
        $current = $today->modify('first day of this month')->setTime(0, 0);
        $first = $current->modify('-3 months');
        $start = $first->format('Y-m-d');
        $this->execute('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?', [$supplierId]);
        SamplePayrollModuleState::prepare($pdo, $supplierId, $userId, $start, $track);
        $officeCode = 'SKOLENI';
        $existingOffice = $pdo->prepare('SELECT COUNT(*) FROM payroll_offices WHERE supplier_id = ? AND code = ?');
        for ($suffix = 2; ; $suffix++) {
            $existingOffice->execute([$supplierId, $officeCode]);
            if ((int) $existingOffice->fetchColumn() === 0) break;
            $officeCode = 'SKOLENI-' . $suffix;
        }
        $this->execute('INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active) VALUES (?, ?, "Ukázková mzdová účtárna", "1101234563", 1)', [$supplierId, $officeCode]);
        $officeId = (int) $pdo->lastInsertId();
        $track('payroll_office', $officeId);
        $this->execute('INSERT INTO payroll_office_registration_versions (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference) VALUES (?, ?, ?, "1101234563", "sample:skoleni")', [$supplierId, $officeId, $start]);
        if (($before['payroll_employer_settings'] ?? []) === []) {
            $this->execute('INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code) VALUES (?, ?, "110")', [$supplierId, $officeId]);
        }
        $existingPolicies = $pdo->prepare('SELECT valid_from, valid_to FROM payroll_employer_policies WHERE supplier_id = ? ORDER BY valid_from');
        $existingPolicies->execute([$supplierId]);
        foreach ($this->policyGaps($existingPolicies->fetchAll(PDO::FETCH_ASSOC), $start, $current->format('Y-m-t')) as [$from, $to]) {
            $policy = $this->policies->create($supplierId, [
                'valid_from' => $from, 'valid_to' => $to,
                'payday_day' => 10, 'payday_month_offset' => 1,
                'payday_business_day_rule' => 'previous_business_day',
                'balance_rounding_mode' => 'exact_minor_units',
                'home_office_policy' => 'not_used', 'travel_expense_policy' => 'not_used',
                'leave_entitlement_weeks' => 5, 'automatic_posting_enabled' => false,
                'delivery_channel' => 'disabled', 'delivery_verified_on' => null,
                'source_kind' => 'manual', 'source_reference' => 'sample:skoleni',
            ], $userId);
            $track('payroll_employer_policy', (int) $policy['id']);
        }
        $preparePayments = ($before['payroll_institution_accounts'] ?? []) === [];
        $settings = $pdo->prepare('SELECT social_security_office_code FROM payroll_employer_settings WHERE supplier_id = ?');
        $settings->execute([$supplierId]);
        $socialCode = trim((string) $settings->fetchColumn()) ?: '110';
        foreach ($preparePayments ? [['social_security', $socialCode, 'Ukázková správa sociálního zabezpečení'], ['health_insurer', '111', 'Ukázková zdravotní pojišťovna'], ['tax_office', 'advance_tax', 'Ukázkový finanční úřad'], ['tax_office', 'withholding_tax', 'Ukázkový finanční úřad']] : [] as [$kind, $code, $name]) {
            $account = $this->institutionAccounts->create($supplierId, [
                'institution_type' => $kind, 'institution_code' => $code,
                'institution_name' => $name, 'bank_account' => '1000000005/0100',
                'currency_code' => 'CZK', 'variable_symbol' => '0000001900',
                'specific_symbol' => null, 'constant_symbol' => null,
                'valid_from' => $start, 'valid_to' => null,
                'source_kind' => 'official_document', 'source_reference' => 'sample:skoleni',
                'verified_on' => $start,
            ], $userId);
            $track('payroll_institution_account', (int) $account['id']);
        }
        $this->components->ensureDefaults($supplierId);
        $definitions = $this->components->list($supplierId, $start);
        $byCode = [];
        foreach ($definitions as $definition) {
            $byCode[$definition['code']] = (int) $definition['id'];
        }
        $staff = [];
        foreach ([
            ['Klára', 'Ukázková', 'employment', '40.00', 48700, 480, [1, 2, 3, 4, 5]],
            ['Marek', 'Modelový', 'employment', '20.00', 27300, 240, [1, 2, 3, 4, 5]],
            ['Petra', 'Cvičná', 'dpp', '8.00', 9200, 240, [1, 4]],
            ['Tomáš', 'Názorný', 'dpc', '20.00', 14600, 240, [1, 2, 3, 4, 5]],
        ] as $index => [$given, $surname, $relation, $weekly, $gross, $daily, $weekdays]) {
            $person = $this->people->create($supplierId, [
                'full_name' => "$given $surname", 'first_name' => $given, 'last_name' => $surname,
                'birth_surname' => $surname, 'birth_date' => sprintf('199%d-02-03', $index),
                'relation_type' => $relation, 'planned_start_on' => $start,
                'office_id' => $officeId, 'monthly_gross' => $gross, 'weekly_hours' => $weekly,
                'employment_code' => 'SKOL-' . ($index + 1),
                'jmhz_apz_contribution_status' => 'no', 'jmhz_functional_benefits_status' => 'no',
                'jmhz_temporary_assignment_status' => 'no',
            ], $userId, null, 'sample-generator');
            $employeeId = (int) $person['id'];
            $stmt = $pdo->prepare('SELECT id, row_version FROM payroll_employments WHERE supplier_id = ? AND employee_id = ?');
            $stmt->execute([$supplierId, $employeeId]);
            $employment = $stmt->fetch(PDO::FETCH_ASSOC);
            $employmentId = (int) $employment['id'];
            $track('payroll_person', $employeeId);
            $track('payroll_employment', $employmentId);
            $this->employments->transition($supplierId, $employmentId, 'active', (int) $employment['row_version'], $start, null, $userId, null, 'sample-generator');
            $this->execute('UPDATE payroll_employment_terms SET actual_start_on = ?, work_place = "Praha", regular_workplace = "Praha", jmhz_workplace_municipality_code = "554782", jmhz_workplace_country_code = "CZ" WHERE supplier_id = ? AND employment_id = ?', [$start, $supplierId, $employmentId]);
            $this->execute('UPDATE payroll_employment_checklist_items SET status = "completed", note = "Syntetická registrace pro školení", completed_at = NOW(), completed_by = ? WHERE supplier_id = ? AND employment_id = ?', [$userId, $supplierId, $employmentId]);
            $this->execute('UPDATE payroll_employee_profiles SET profile_status = "ready", payout_method = "cash" WHERE supplier_id = ? AND employee_id = ?', [$supplierId, $employeeId]);
            $this->payoutRules->create($supplierId, $employeeId, PayrollPayoutRuleInput::remainder('cash', null), 'SKOLENI-VYPLATA');
            $this->seedEvidence($supplierId, $employeeId, $first, $current, $userId, $relation !== 'dpp');
            foreach (array_unique([(int) $first->format('Y'), (int) $current->format('Y')]) as $year) {
                $this->seedOpening($supplierId, $employeeId, $year, $userId);
            }
            $pattern = [];
            for ($day = 1; $day <= 7; $day++) $pattern[$day] = in_array($day, $weekdays, true) ? $daily : 0;
            $this->time->createCalendar($supplierId, $employmentId, [
                'name' => 'Pravidelný rozvrh pro školení', 'timezone' => 'Europe/Prague',
                'schedule_type' => 'regular', 'week_pattern' => $pattern,
                'valid_from' => $start, 'valid_to' => null, 'row_version' => 0,
                'month_row_version' => 0, 'days' => [],
            ], $userId);
            $staff[] = compact('employeeId', 'employmentId', 'gross', 'daily', 'weekdays');
        }
        $journalEntryIds = [];
        $generatedRunIds = [];
        for ($offset = 0; $offset < 4; $offset++) {
            $month = $first->modify("+$offset months");
            $period = $month->format('Y-m');
            $periodStart = "$period-01";
            foreach ($staff as $index => $person) {
                $absenceDate = null;
                if ($offset === 1 && $index === 1) {
                    $absenceDay = $month;
                    $holidays = (new CzechHolidayCalendar())->forYear((int) $month->format('Y'));
                    while ((int) $absenceDay->format('N') > 5 || isset($holidays[$absenceDay->format('Y-m-d')])) $absenceDay = $absenceDay->modify('+1 day');
                    $absenceDate = $absenceDay->format('Y-m-d');
                    $absence = $this->absences->create($supplierId, $this->absenceValidator->absence([
                        'employment_id' => $person['employmentId'], 'absence_type' => 'unpaid_leave',
                        'date_from' => $absenceDate, 'date_to' => $absenceDate,
                        'note' => 'Ukázka schváleného neplaceného volna a krácení měsíční mzdy.',
                    ]), $userId);
                    $this->absences->decide($supplierId, (int) $absence['id'], (int) $absence['row_version'], 'approved', $userId);
                }
                $this->seedTime($supplierId, $person, $month, $userId, $offset < 3 ? null : $today, $absenceDate);
                $this->execute('INSERT INTO payroll_enforcement_person_month_evidence (supplier_id, employee_id, period_start, claim_register_evidence_complete, dependants_evidence_complete, spouse_evidence_complete, pension_evidence, updated_by) VALUES (?, ?, ?, 1, 1, 1, "none", ?)', [$supplierId, $person['employeeId'], $periodStart, $userId]);
                $input = [
                    'employee_id' => $person['employeeId'], 'employment_id' => $person['employmentId'],
                    'component_id' => $byCode['MZDA_MESICNI'], 'period_start' => $periodStart,
                    'source_period_start' => null, 'amount_minor' => $person['gross'] * 100,
                    'quantity_milliunits' => null, 'source_kind' => 'manual',
                    'external_id' => "sample:$period:base:$index",
                ];
                if ($absenceDate !== null) {
                    $proration = $this->wageProration->forMonth($supplierId, $person['employmentId'], $period, $person['gross'] * 100);
                    if (!$proration['supported'] || $proration['amount_minor'] === null) throw new \DomainException('Ukázkové krácení mzdy nemá úplné podklady.');
                    $input['amount_minor'] = $proration['amount_minor'];
                    $input['external_id'] = "quick-monthly:sample:$period:base:$index";
                    $input['source_snapshot_json'] = CanonicalJson::encode(['monthly_wage_proration' => $proration['trace']]);
                    $input['source_snapshot_hash'] = hash('sha256', $input['source_snapshot_json'], true);
                }
                $created = $offset < 3 ? $this->inputs->createApproved($supplierId, $input, $userId) : $this->inputs->create($supplierId, $input, $userId);
                $track('payroll_input', (int) $created['id']);
                if ($offset < 3 && $index === $offset % 2) {
                    unset($input['source_snapshot_json'], $input['source_snapshot_hash']);
                    $input['component_id'] = $byCode['ODMENA'];
                    $input['amount_minor'] = (2100 + 700 * $offset) * 100;
                    $input['external_id'] = "sample:$period:bonus:$index";
                    $bonus = $this->inputs->createApproved($supplierId, $input, $userId);
                    $track('payroll_input', (int) $bonus['id']);
                }
            }
            $run = $this->runs->createRun($supplierId, $periodStart, $this->paydays->suggest($supplierId, $periodStart), null, $userId);
            $runId = (int) $run['id'];
            $generatedRunIds[] = $runId;
            $track('payroll_run', $runId);
            if ($offset === 3) continue;
            $calculated = $this->runs->lockAndCalculate($supplierId, $runId, (int) $run['row_version'], "sample-$supplierId-$period-calculate", $userId);
            $issues = $pdo->prepare('SELECT code, message FROM payroll_run_validations WHERE supplier_id = ? AND revision_id = ? AND (severity = "blocker" OR requires_override = 1)');
            $issues->execute([$supplierId, (int) $calculated->revision['id']]);
            $blocking = $issues->fetchAll(PDO::FETCH_ASSOC);
            if ($blocking !== []) throw new \DomainException('Ukázková mzda neprošla kontrolou: ' . json_encode($blocking, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $approved = $this->runs->approve($supplierId, $runId, (int) $calculated->run['row_version'], "sample-$supplierId-$period-approve", $userId);
            if ($advanced) {
                $posted = $this->runs->post($supplierId, $runId, (int) $approved->run['row_version'], "sample-$supplierId-$period-post", $userId);
                if ($preparePayments) $this->runs->preparePayments($supplierId, $runId, (int) $posted->run['row_version'], "sample-$supplierId-$period-payments", $userId);
            }
        }
        $place = implode(',', array_fill(0, count($generatedRunIds), '?'));
        $stmt = $pdo->prepare("SELECT id, journal_entry_id FROM payroll_posting_batches WHERE supplier_id = ? AND run_id IN ($place)");
        $stmt->execute([$supplierId, ...$generatedRunIds]);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($batches as $batch) {
            $track('payroll_posting_batch', (int) $batch['id']);
            if ($batch['journal_entry_id'] !== null) {
                $journalEntryIds[] = (int) $batch['journal_entry_id'];
                $track('journal_entry', (int) $batch['journal_entry_id']);
            }
        }
        SamplePayrollModuleState::trackGenerated($pdo, $supplierId, $track);
        foreach (SamplePayrollPurger::snapshot($pdo, $supplierId) as $table => $ids) {
            foreach (array_diff($ids, $before[$table] ?? []) as $id) {
                $track(SamplePayrollPurger::ownershipType($table), (int) $id);
            }
        }
        return ['payroll_persons' => count($staff), 'payroll_employments' => count($staff), 'payroll_runs' => 4, 'payroll_results' => 12, 'payroll_absences' => 1, 'payroll_posting_batches' => count($batches), 'journal_entry_ids' => $journalEntryIds];
        } finally {
            if ($originalIdentity !== null && $pdo->inTransaction()) {
                $this->execute('UPDATE supplier SET display_name = ?, ic = ? WHERE id = ?', [$originalIdentity['display_name'], $originalIdentity['ic'], $supplierId]);
            }
        }
    }

    private function prepareEmployerIdentity(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, display_name, ic FROM supplier WHERE id = ? FOR UPDATE');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new \OutOfBoundsException('Firma nebyla nalezena.');
        $name = $row['display_name'] !== null && $row['display_name'] !== '' ? $row['display_name'] : $row['company_name'];
        $missingName = trim((string) $name) === '';
        $missingIc = trim((string) $row['ic']) === '';
        if (!$missingName && !$missingIc) return null;
        $displayName = $missingName ? (trim((string) $row['company_name']) ?: 'Ukázkový zaměstnavatel s.r.o.') : $row['display_name'];
        $this->execute('UPDATE supplier SET display_name = ?, ic = ? WHERE id = ?', [$displayName, $missingIc ? '00000019' : $row['ic'], $supplierId]);
        return $row;
    }

    private function policyGaps(array $policies, string $start, string $end): array
    {
        $gaps = [];
        $cursor = $start;
        foreach ($policies as $policy) {
            if ($policy['valid_to'] !== null && $policy['valid_to'] < $cursor) continue;
            if ($policy['valid_from'] > $cursor) {
                $gaps[] = [$cursor, min($end, (new DateTimeImmutable($policy['valid_from']))->modify('-1 day')->format('Y-m-d'))];
            }
            if ($policy['valid_to'] === null) return $gaps;
            $cursor = max($cursor, (new DateTimeImmutable($policy['valid_to']))->modify('+1 day')->format('Y-m-d'));
            if ($cursor > $end) return $gaps;
        }
        if ($cursor <= $end) $gaps[] = [$cursor, $end];
        return $gaps;
    }

    private function seedEvidence(int $supplierId, int $employeeId, DateTimeImmutable $first, DateTimeImmutable $current, int $userId, bool $signed): void
    {
        $interval = ['effective_from' => $first->format('Y-m-d'), 'effective_to' => null];
        $months = [];
        for ($month = $first; $month <= $current; $month = $month->modify('+1 month')) {
            $months[] = ['period_start' => $month->format('Y-m-d'), 'top_up_responsibility' => 'employee', 'top_up_responsibility_evidence_reference' => null, 'selected_top_up_employer_reference' => null, 'selected_top_up_employer_evidence_reference' => null];
        }
        $this->evidence->save($supplierId, $employeeId, ['sections' => [
            'tax_declarations' => [$interval + ['status' => $signed ? 'signed' : 'not-signed', 'evidence_reference' => 'sample:prohlaseni']],
            'tax_residences' => [$interval + ['residence' => 'czech-resident', 'country_code' => 'CZ', 'evidence_reference' => 'sample:rezidence']],
            'tax_credit_claims' => $signed ? [$interval + ['credit_kind' => 'taxpayer', 'evidence_status' => 'verified', 'evidence_reference' => 'sample:sleva']] : [],
            'social_jurisdictions' => [$interval + ['jurisdiction' => 'czech_regime_verified', 'foreign_country_code' => null, 'jurisdiction_evidence_reference' => null, 'a1_status' => 'not_applicable', 'a1_certificate_reference' => null, 'a1_valid_until' => null]],
            'social_discount_claims' => [$interval + ['status' => 'not_claimed', 'evidence_reference' => null]],
            'health_coverages' => [$interval + ['jurisdiction' => 'czech_regime_verified', 'foreign_country_code' => null, 'jurisdiction_evidence_reference' => null, 'insurer_status' => 'verified', 'insurer_code' => '111', 'insurer_evidence_reference' => null]],
            'health_month_evidence' => $months, 'health_minimum_reductions' => [], 'health_other_employer_bases' => [],
        ]], $current->format('Y-m-t'), $userId, null, 'sample-generator');
    }

    private function seedOpening(int $supplierId, int $employeeId, int $year, int $userId): void
    {
        foreach (['social_insurance' => ['assessment_base_minor_units' => 0], 'income_tax' => array_fill_keys(['completed_months', 'advance_base_minor_units', 'withholding_base_minor_units', 'advance_tax_minor_units', 'withholding_tax_minor_units', 'applied_non_refundable_credits_minor_units', 'applied_child_credit_minor_units', 'tax_bonus_minor_units', 'bonus_qualifying_income_minor_units'], 0)] as $kind => $values) {
            $this->accumulators->appendOpeningBalance($supplierId, $employeeId, $year, $kind, $values, 'sample:nastup-bez-predchozich-mezd', ['verified_zero' => true], "sample-opening-$employeeId-$year-$kind", actorUserId: $userId);
        }
    }

    private function seedTime(int $supplierId, array $person, DateTimeImmutable $month, int $userId, ?DateTimeImmutable $through, ?string $absenceDate): void
    {
        $employmentId = $person['employmentId'];
        $period = $month->format('Y-m');
        $item = $this->time->overview($supplierId, $period, false, employmentId: $employmentId)['items'][0];
        $version = (int) ($item['month']['row_version'] ?? 0);
        $holidays = (new CzechHolidayCalendar())->forYear((int) $month->format('Y'));
        for ($day = $month; $day->format('Y-m') === $period && ($through === null || $day <= $through); $day = $day->modify('+1 day')) {
            if (!in_array((int) $day->format('N'), $person['weekdays'], true) || isset($holidays[$day->format('Y-m-d')])) continue;
            $start = new DateTimeImmutable($day->format('Y-m-d') . ' 08:00:00', new DateTimeZone('Europe/Prague'));
            $shift = $this->time->saveShift($supplierId, [
                'employment_id' => $employmentId, 'starts_at' => $start->format('c'),
                'ends_at' => $start->modify('+' . ($person['daily'] + 30) . ' minutes')->format('c'),
                'timezone' => 'Europe/Prague', 'break_minutes' => 30, 'standby_minutes' => 0,
                'publish' => true, 'remote_work' => false, 'supersedes_id' => null, 'calendar_id' => null,
                'row_version' => 0, 'month_row_version' => $version,
            ], $userId);
            $version = (int) $shift['month']['row_version'];
            if ($day->format('Y-m-d') === $absenceDate) continue;
            $entry = $this->time->saveEntry($supplierId, [
                'employment_id' => $employmentId, 'starts_at' => $start->format('c'),
                'ends_at' => $start->modify('+' . ($person['daily'] + 30) . ' minutes')->format('c'),
                'timezone' => 'Europe/Prague', 'category' => 'regular', 'break_minutes' => 30,
                'row_version' => 0, 'month_row_version' => $version, 'supersedes_id' => null,
            ], $userId);
            $version = (int) $entry['month']['row_version'];
        }
        if ($through !== null) return;
        $item = $this->time->overview($supplierId, $period, false, employmentId: $employmentId)['items'][0];
        $preview = $item['jmhz_work_summary']['preview'];
        $summary = $preview['suggestions'];
        $summary['source_snapshot_sha256'] = $preview['source_snapshot_sha256'];
        $summary['confirmation_note'] = 'Syntetický pracovní měsíc pro školení.';
        $this->time->approve($supplierId, $period, ['employment_id' => $employmentId, 'row_version' => $version, 'jmhz_work_summary' => $summary], $userId);
    }

    private function execute(string $sql, array $params): void
    {
        $this->db->pdo()->prepare($sql)->execute($params);
    }
}
