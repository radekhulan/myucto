<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentLifecycleSql;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerSettingsRepository;
use MyInvoice\Repository\Payroll\PayrollEnforcementRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseSource;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthEvidence;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\RiskySavings\PayrollRiskySavingsPolicy;
use MyInvoice\Service\Payroll\RiskySavings\PayrollRiskySavingsRules;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookUnavailableException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookValueException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojClaimDeadlinePolicy;
use MyInvoice\Service\Payroll\Time\Overtime\PayrollOvertimeLimitService;
use PDO;

final class PayrollRunSnapshotBuilder
{
    private readonly PayrollRunSnapshotBatchLoader $batch;
    private readonly PayrollRiskySavingsPolicy $riskySavingsPolicy;

    /**
     * `$rulesets` je POVINNÝ. Jako volitelný parametr s defaultem ho PHP-DI
     * nevyplňovalo a snapshot běhu si tiše bral výchozí sadu z kódu, takže
     * manifest rulesetů neodpovídal tomu, čím se běh doopravdy počítal.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRulesetProvider $rulesets,
        private readonly ?EnforcementCaseSource $enforcement = null,
        private readonly ?PayrollPersonStatutoryEvidenceRepository $statutoryEvidence = null,
        private readonly ?PayrollStatutoryPeriodResolver $periods = null,
        private readonly ?PayrollStatutoryAccumulatorRepository $statutoryAccumulators = null,
        private readonly ?PayrollEmployerSettingsRepository $employerSettings = null,
        private readonly ?PayrollEmployerPolicyRepository $employerPolicies = null,
        private readonly ?JmhzExternalCodebookCatalog $jmhzExternalCodebooks = null,
        private readonly ?PayrollOvertimeLimitService $overtimeLimits = null,
    ) {
        // Loader je čistý SQL pomocník nad týmž spojením — vědomě se nedává do
        // konstruktoru, aby nepřibyl další volitelný parametr, který by PHP-DI
        // nevyplnilo a který by se pak tiše nahradil defaultem.
        $this->batch = new PayrollRunSnapshotBatchLoader($db);
        $this->riskySavingsPolicy = new PayrollRiskySavingsPolicy();
    }

    public function build(
        int $supplierId,
        string $periodStart,
        string $paymentDate,
        ?int $officeId = null,
    ): PayrollRunInputSnapshot {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma mzdového běhu není platná.');
        }
        $period = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if ($period === false
            || $period->format('Y-m-d') !== $periodStart
            || $period->format('d') !== '01'
        ) {
            throw new \InvalidArgumentException(
                'Mzdové období musí být první den měsíce ve formátu YYYY-MM-DD.',
            );
        }
        if ($officeId !== null && $officeId <= 0) {
            throw new \InvalidArgumentException('Mzdová účtárna není platná.');
        }
        $payment = \DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
        if ($payment === false
            || $payment->format('Y-m-d') !== $paymentDate
            || $payment < $period
        ) {
            throw new \InvalidArgumentException(
                'Datum výplaty mzdového běhu není platné.',
            );
        }
        $periodEnd = $period->modify('last day of this month')->format('Y-m-d');
        $statutoryPeriod = ($this->periods ?? new PayrollStatutoryPeriodResolver())
            ->resolve($periodStart, $paymentDate);
        $manifest = $this->rulesets->canonicalManifest();
        $riskySavingsRules = PayrollRiskySavingsRules::fromProvider(
            $this->rulesets,
            $periodStart,
        );
        $manifestJson = CanonicalJson::encode(['rulesets' => $manifest]);
        $employerPolicy = $this->employerPolicySnapshot(
            $supplierId,
            $periodStart,
        );
        $employer = $this->employerSnapshot($supplierId);

        $employments = $this->employmentRows(
            $supplierId,
            $periodStart,
            $periodEnd,
            $officeId,
        );
        $validations = [];
        if ($employments === []) {
            $validations[] = new PayrollRunValidation(
                'blocker',
                'run_without_employments',
                'run',
                null,
                'Pro období nebyl nalezen žádný zpracovatelný pracovní vztah.',
                '/payroll/employees',
            );
        }

        // Zmrazená množina ID — od tohohle bodu se podklady načítají MNOŽINOVĚ.
        // Dřív tady běžel dotaz na osobu a na vztah, takže běh nad ~300 osobami
        // vygeneroval tisíce round-tripů uvnitř jedné transakce a nedoběhl.
        $employmentIds = [];
        $employeeIds = [];
        foreach ($employments as $row) {
            $employmentIds[] = (int) $row['employment_id'];
            $employeeIds[(int) $row['employee_id']] = true;
        }
        $employeeIds = array_keys($employeeIds);

        // Limity přesčasové práce podle § 93 zákoníku práce. Nálezy se přidávají
        // k VALIDACÍM, ne do `$data` — kanonický snapshot a tím i `input_hash`
        // proto zůstávají beze změny a přepočet starší revize dá bit po bitu
        // tentýž vstup jako předtím.
        foreach ($this->overtimeValidations(
            $supplierId,
            $employments,
            $periodStart,
            $periodEnd,
        ) as $validation) {
            $validations[] = $validation;
        }

        $timeMonthRows = $this->batch->timeMonths(
            $supplierId,
            $employmentIds,
            $periodStart,
        );
        $draftInputCounts = $this->batch->draftInputCounts(
            $supplierId,
            $employmentIds,
            $periodStart,
        );
        $inputRows = $this->batch->inputs($supplierId, $employmentIds, $periodStart);
        $dimensionRows = $this->batch->employmentDimensions(
            $supplierId,
            $employmentIds,
            $periodStart,
        );
        $absenceRows = $this->batch->absences(
            $supplierId,
            $employmentIds,
            $periodStart,
            $periodEnd,
        );
        $discountIntentRows = $this->batch->discountIntents(
            $supplierId,
            $employmentIds,
            $periodStart,
            $periodEnd,
        );
        $enforcementEvidence = $this->enforcement === null
            ? []
            : $this->enforcementEvidence(
                $supplierId,
                $employeeIds,
                $period->format('Y-m'),
                $paymentDate,
            );
        $statutoryEvidence = $this->statutoryEvidence?->snapshotMany(
            $supplierId,
            $employeeIds,
            $statutoryPeriod->taxCalculationDate,
        ) ?? [];
        $accumulatorStates = $this->statutoryAccumulatorStates(
            $supplierId,
            $employeeIds,
            (int) $period->format('Y'),
            $periodStart,
        );
        $deductionAgreementRows = $this->batch->deductionAgreements(
            $supplierId,
            $employeeIds,
            $periodEnd,
        );
        $payoutRuleRows = $this->batch->payoutRules($supplierId, $employeeIds);
        $payoutAccountRows = $this->batch->payoutAccounts(
            $supplierId,
            $employeeIds,
            $paymentDate,
        );

        /** @var array<int,array{employee:array<string,mixed>,employments:list<array<string,mixed>>}> $people */
        $people = [];
        /** @var array<int,array<string,mixed>|null> $officeRegistrations */
        $officeRegistrations = [];
        foreach ($employments as $row) {
            $employmentId = (int) $row['employment_id'];
            $employeeId = (int) $row['employee_id'];
            if ($row['term_id'] === null) {
                $validations[] = new PayrollRunValidation(
                    'blocker',
                    'missing_effective_employment_term',
                    'employment',
                    $employmentId,
                    'Pracovní vztah nemá pro mzdové období účinné smluvní podmínky.',
                    "/payroll/employees/{$employeeId}",
                );
            }
            // Mzdová účtárna je u vztahu POVINNÁ. Variabilní symbol
            // zaměstnavatele pro sociální pojistné vychází výhradně
            // z `payroll_offices`, takže odvod za vztah bez účtárny není čím
            // vykázat, a běh zúžený na účtárnu by takový vztah navíc tiše
            // vynechal. Zápisová cesta ji od migrace
            // 1503_payroll_employment_office_backfill doplňuje z výchozí
            // účtárny zaměstnavatele; tohle je pojistka pro data, která
            // vznikla dřív nebo mimo ni — musí se ozvat tady, dokud se dá
            // vztah opravit, ne až kontrolními součty při schvalování.
            if ($row['office_id'] === null) {
                $validations[] = new PayrollRunValidation(
                    'blocker',
                    'employment_without_office',
                    'employment',
                    $employmentId,
                    'Pracovní vztah nemá mzdovou účtárnu. Bez ní nelze vykázat'
                    . ' odvod sociálního pojistného.',
                    "/payroll/employees/{$employeeId}",
                );
            }
            $officeId = $row['office_id'] === null ? null : (int) $row['office_id'];
            if ($officeId !== null && !array_key_exists($officeId, $officeRegistrations)) {
                $officeRegistrations[$officeId] = $this->officeRegistration(
                    $supplierId,
                    $officeId,
                    $periodStart,
                );
            }
            $officeRegistration = $officeId === null ? null : $officeRegistrations[$officeId];
            if ($row['office_id'] !== null && $officeRegistration === null) {
                $validations[] = new PayrollRunValidation(
                    'blocker',
                    'office_registration_history_missing',
                    'office',
                    (int) $row['office_id'],
                    'Mzdová účtárna nemá pro období doloženou účinnou registraci ČSSZ.',
                    '/payroll/settings',
                );
            }
            $timeMonth = isset($timeMonthRows[$employmentId])
                ? $this->timeMonth($timeMonthRows[$employmentId])
                : null;
            if ($timeMonth !== null && $timeMonth['status'] !== 'approved') {
                $validations[] = new PayrollRunValidation(
                    'blocker',
                    'time_month_not_approved',
                    'employment',
                    $employmentId,
                    'Docházka pracovního vztahu není schválena.',
                    '/payroll/time',
                );
            }
            $draftCount = $draftInputCounts[$employmentId] ?? 0;
            if ($draftCount > 0) {
                $validations[] = new PayrollRunValidation(
                    'blocker',
                    'draft_inputs_present',
                    'employment',
                    $employmentId,
                    sprintf(
                        '%s: pracovní vztah obsahuje neschválené mzdové vstupy.',
                        (string) $row['full_name'],
                    ),
                    '/payroll/components',
                );
            }
            $inputs = $this->inputs($inputRows[$employmentId] ?? []);
            $riskySavingsEvidence = $this->riskySavingsEvidence($row);
            if ($riskySavingsEvidence !== null) {
                foreach ($this->riskySavingsPolicy->issues(
                    $riskySavingsEvidence,
                    $periodStart,
                ) as $issue) {
                    $validations[] = new PayrollRunValidation(
                        'blocker',
                        $issue,
                        'employment',
                        $employmentId,
                        $this->riskySavingsValidationMessage($issue),
                        '/payroll/components',
                    );
                }
                foreach ($this->riskySavingsPolicy->warnings(
                    $riskySavingsEvidence,
                    $periodStart,
                ) as $warning) {
                    $validations[] = new PayrollRunValidation(
                        'warning',
                        $warning,
                        'employment',
                        $employmentId,
                        $this->riskySavingsValidationMessage($warning),
                        '/payroll/components',
                    );
                }
            }
            if ($inputs === []) {
                // JEDINÉ místo v modulu, které si žádá ruční override. Vztah bez
                // složky je většinou chyba zadání, ale legitimní důvody existují
                // (celý měsíc neplaceného volna, spící dohoda), takže se to nedá
                // rozhodnout automaticky — musí to odklepnout člověk. Do MZ-01-W07
                // to ale byla past: workflow na nevyřešeném overridu zastavilo
                // `approve` a cesta, jak override udělit, neexistovala. Vede k němu
                // {@see \MyInvoice\Service\Payroll\Run\PayrollRunValidationOverrideService}.
                $validations[] = new PayrollRunValidation(
                    'warning',
                    'employment_without_inputs',
                    'employment',
                    $employmentId,
                    sprintf(
                        '%s: pracovní vztah nemá v období žádnou schválenou mzdovou složku.',
                        (string) $row['full_name'],
                    ),
                    '/payroll/components',
                    true,
                );
            }
            $absences = $this->absences($absenceRows[$employmentId] ?? []);
            foreach ($this->discountValidations(
                $row,
                $discountIntentRows[$employmentId] ?? null,
                $employmentId,
                $employeeId,
                $periodStart,
            ) as $validation) {
                $validations[] = $validation;
            }

            $people[$employeeId] ??= [
                'employee' => [
                    'id' => $employeeId,
                    'full_name' => (string) $row['full_name'],
                    'profile_status' => (string) $row['profile_status'],
                    'is_active' => (bool) $row['employee_active'],
                ],
                'enforcement_evidence' => $this->enforcement === null
                    ? null
                    : $enforcementEvidence[$employeeId],
                'statutory_evidence' => $this->statutoryEvidence === null
                    ? null
                    : ($statutoryEvidence[$employeeId] ?? null),
                'statutory_accumulators' => $accumulatorStates[$employeeId],
                'deduction_agreements' => $this->deductionAgreements(
                    $deductionAgreementRows[$employeeId] ?? [],
                ),
                'payout_rules' => $this->payoutRules(
                    $payoutRuleRows[$employeeId] ?? [],
                ),
                'payout_accounts' => $this->payoutAccounts(
                    $payoutAccountRows[$employeeId] ?? [],
                ),
                'employments' => [],
            ];
            $termSnapshot = null;
            if ($row['term_id'] !== null) {
                $jmhzValidationCodebook = $this->jmhzCodebookValidationForPeriod(
                    $row,
                    $periodStart,
                    $periodEnd,
                );
                $termSnapshot = [
                    'id' => (int) $row['term_id'],
                    'row_version' => (int) $row['term_row_version'],
                    'effective_from' => (string) $row['effective_from'],
                    'effective_to' => $row['effective_to'],
                    'weekly_hours' => $row['weekly_hours'] === null
                        ? null
                        : (string) $row['weekly_hours'],
                    'workload_basis_points' => (int) $row['workload_basis_points'],
                    'activity_code' => $row['activity_code'],
                    'jmhz_relationship_detail_code' =>
                        $row['jmhz_relationship_detail_code'],
                    'work_place' => $row['work_place'],
                    'jmhz_workplace_municipality_code' =>
                        $row['jmhz_workplace_municipality_code'],
                    'jmhz_workplace_country_code' =>
                        $row['jmhz_workplace_country_code'],
                    'jmhz_external_codebook_overlay_key' =>
                        $row['jmhz_external_codebook_overlay_key'],
                    'jmhz_external_codebook_manifest_sha256' =>
                        $row['jmhz_external_codebook_manifest_sha256'],
                    'jmhz_external_codebooks_verified_for_period' => $jmhzValidationCodebook !== null,
                    'jmhz_validation_external_codebook_overlay_key' =>
                        $jmhzValidationCodebook['overlay_key'] ?? null,
                    'jmhz_validation_external_codebook_manifest_sha256' =>
                        $jmhzValidationCodebook['manifest_sha256'] ?? null,
                    'jmhz_apz_contribution_status' =>
                        (string) $row['jmhz_apz_contribution_status'],
                    'jmhz_apz_instrument_code' => $row['jmhz_apz_instrument_code'],
                    'jmhz_functional_benefits_status' =>
                        (string) $row['jmhz_functional_benefits_status'],
                    'jmhz_temporary_assignment_status' =>
                        (string) $row['jmhz_temporary_assignment_status'],
                    'jmhz_orchard_discount_eligible' =>
                        (bool) $row['jmhz_orchard_discount_eligible'],
                    'jmhz_specific_legal_fact_applies' =>
                        (bool) $row['jmhz_specific_legal_fact_applies'],
                    'jmhz_ozp_employment_support_applies' =>
                        (bool) $row['jmhz_ozp_employment_support_applies'],
                    'jmhz_deep_mining_work_applies' =>
                        (bool) $row['jmhz_deep_mining_work_applies'],
                    'social_insurance_participation' =>
                        (string) $row['social_insurance_participation'],
                    'health_insurance_participation' =>
                        (string) $row['health_insurance_participation'],
                    'tax_regime' => (string) $row['tax_regime'],
                    'other_withholding_eligibility' =>
                        (string) $row['other_withholding_eligibility'],
                    'tax_declaration_signed' =>
                        (bool) $row['tax_declaration_signed'],
                    'risky_work' => (bool) $row['risky_work'],
                    'social_employer_rate_category' =>
                        (string) $row['social_employer_rate_category'],
                    'social_employer_rate_category_evidence' =>
                        $row['social_employer_rate_category_evidence'],
                    'social_part_time_discount_reason' =>
                        (string) $row['social_part_time_discount_reason'],
                    'social_part_time_discount_evidence' =>
                        $row['social_part_time_discount_evidence'],
                    'social_part_time_discount_notified_on' =>
                        $row['social_part_time_discount_notified_on'],
                    // Doložený záměr OZUSPOJ. Ručně opsané datum výš zůstává
                    // jen jako zmrazená historie starších revizí — nárok podle
                    // § 7a odst. 5 se od téhle migrace posuzuje z evidence
                    // záměrů, protože ta jediná ví, KDY bylo oznámení doručeno
                    // a NA JAKÉ OBDOBÍ platí.
                    'social_part_time_discount_intent' =>
                        $discountIntentRows[$employmentId] ?? null,
                    'foreign_legislation_country_code' =>
                        $row['foreign_legislation_country_code'],
                    'a1_certificate_until' => $row['a1_certificate_until'],
                ];
            }
            $people[$employeeId]['employments'][] = [
                'employment' => [
                    'id' => $employmentId,
                    'employee_id' => $employeeId,
                    'office_id' => $row['office_id'] === null
                        ? null
                        : (int) $row['office_id'],
                    'office_registration' => $officeRegistration,
                    'code' => (string) $row['employment_code'],
                    'relation_type' => (string) $row['relation_type'],
                    'status' => (string) $row['employment_status'],
                    'start_date' => $row['start_date'],
                    'actual_start_date' => $row['actual_start_date'],
                    'end_date' => $row['end_date'],
                    'monthly_gross_minor' => $row['monthly_gross_minor'] === null
                        ? null
                        : (int) $row['monthly_gross_minor'],
                    'is_primary' => $row['term_is_primary'] === null
                        ? null
                        : (bool) $row['term_is_primary'],
                ],
                'term' => $termSnapshot,
                'ordinary_evidence_profile' => $row['term_id'] === null
                    ? null
                    : [
                        'source_term_id' => (int) $row['term_id'],
                        'source_term_row_version' => (int) $row['term_row_version'],
                        'orchard_discount_eligible' =>
                            (bool) $row['jmhz_orchard_discount_eligible'],
                        'specific_legal_fact_applies' =>
                            (bool) $row['jmhz_specific_legal_fact_applies'],
                        'ozp_employment_support_applies' =>
                            (bool) $row['jmhz_ozp_employment_support_applies'],
                        'deep_mining_work_applies' =>
                            (bool) $row['jmhz_deep_mining_work_applies'],
                    ],
                'average_earning' => $this->averageEarningSnapshot($row),
                'time_month' => $timeMonth,
                'absences' => $absences,
                'inputs' => $inputs,
                'risky_savings_evidence' => $riskySavingsEvidence,
                'dimensions' => $this->dimensions($dimensionRows[$employmentId] ?? []),
            ];
        }
        ksort($people, SORT_NUMERIC);
        foreach ($people as &$person) {
            usort(
                $person['employments'],
                static fn (array $left, array $right): int =>
                    (int) $left['employment']['id']
                    <=> (int) $right['employment']['id'],
            );
        }
        unset($person);

        $data = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $supplierId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'payment_date' => $paymentDate,
            'statutory_period' => $statutoryPeriod->toSnapshot(),
            'risky_savings_ruleset' => $riskySavingsRules->toSnapshot(),
            'employer_policy' => $employerPolicy,
            'employer' => $employer,
            'office_id' => $officeId,
            'ruleset_manifest' => $manifest,
            'people' => array_values($people),
        ];
        $json = CanonicalJson::encode($data);

        return new PayrollRunInputSnapshot(
            $data,
            $json,
            hash('sha256', $json),
            hash('sha256', $manifestJson),
            $validations,
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function riskySavingsEvidence(array $row): ?array
    {
        if ($row['risky_savings_id'] === null) {
            return null;
        }
        return [
            'id' => (int) $row['risky_savings_id'],
            'period_start' => (string) $row['risky_savings_period_start'],
            'revision_no' => (int) $row['risky_savings_revision_no'],
            'risk_factor' => (string) $row['risky_savings_risk_factor'],
            'work_category' => (int) $row['risky_savings_work_category'],
            'qualifying_shift_eighths' =>
                (int) $row['risky_savings_qualifying_shift_eighths'],
            'right_claimed_on' =>
                (string) $row['risky_savings_right_claimed_on'],
            'employee_informed_on' =>
                $row['risky_savings_employee_informed_on'],
            'pension_company' =>
                (string) $row['risky_savings_pension_company'],
            'institution_account_id' =>
                $row['risky_savings_institution_account_id'] === null
                    ? null
                    : (int) $row['risky_savings_institution_account_id'],
            'institution_account_row_version' =>
                $row['risky_savings_institution_account_row_version'] === null
                    ? null
                    : (int) $row['risky_savings_institution_account_row_version'],
            'institution_account_hash' =>
                $row['risky_savings_institution_account_hash'],
            'institution_account_masked' =>
                $row['risky_savings_institution_account_masked'],
            'current_institution_account_row_version' =>
                $row['risky_savings_current_account_row_version'] === null
                    ? null
                    : (int) $row['risky_savings_current_account_row_version'],
            'current_institution_account_hash' =>
                $row['risky_savings_current_account_hash'],
            'product_reference' =>
                (string) $row['risky_savings_product_reference'],
            'variable_symbol' => $row['risky_savings_variable_symbol'],
            'specific_symbol' => $row['risky_savings_specific_symbol'],
            'payment_message' => $row['risky_savings_payment_message'],
            'evidence_reference' => $row['risky_savings_evidence_reference'],
            'status' => (string) $row['risky_savings_status'],
            'row_version' => (int) $row['risky_savings_row_version'],
            'approved_at' => $row['risky_savings_approved_at'],
            'approved_by' => $row['risky_savings_approved_by'] === null
                ? null
                : (int) $row['risky_savings_approved_by'],
        ];
    }

    private function riskySavingsValidationMessage(string $issue): string
    {
        return match ($issue) {
            'risky_savings_evidence_not_approved' =>
                'Evidence rozhodných směn pro povinné spoření není schválena.',
            'risky_savings_shift_eighths_invalid' =>
                'Rozsah rozhodných směn pro povinné spoření není platný.',
            'risky_savings_claim_date_invalid' =>
                'Chybí platný den, kdy zaměstnanec uplatnil právo na příspěvek.',
            'risky_savings_claim_not_effective_for_period' =>
                'Právo na příspěvek bylo uplatněno až v tomto období; nárok vzniká nejdříve následující měsíc.',
            'risky_savings_risk_factor_invalid' =>
                'Chybí zákonný rizikový faktor 3. kategorie pro povinné spoření.',
            'risky_savings_work_category_invalid' =>
                'Povinné spoření lze vypočítat jen pro doloženou práci 3. kategorie.',
            'risky_savings_pension_company_missing' =>
                'Chybí penzijní společnost pro povinné spoření.',
            'risky_savings_product_reference_missing' =>
                'Chybí identifikace produktu povinného spoření.',
            'risky_savings_payment_target_invalid' =>
                'Chybí platný ověřený účet penzijní společnosti pro povinné spoření.',
            'risky_savings_payment_target_changed' =>
                'Ověřený účet penzijní společnosti se po schválení podkladu změnil. Zkontrolujte účet a schvalte novou revizi evidence.',
            'risky_savings_employee_not_informed' =>
                'Není evidováno splnění informační povinnosti vůči zaměstnanci podle § 5 zákona č. 324/2025 Sb.',
            default => 'Podklady povinného spoření vyžadují kontrolu.',
        };
    }

    /**
     * Varování k § 93. Bez nakonfigurované služby se nic nepřidává — kontrola je
     * tím pádem přídavek, který nemůže rozbít běh, který ji nemá zapnutou.
     *
     * @param list<array<string,mixed>> $employments
     * @return list<PayrollRunValidation>
     */
    private function overtimeValidations(
        int $supplierId,
        array $employments,
        string $periodStart,
        string $periodEnd,
    ): array {
        if ($this->overtimeLimits === null || $employments === []) {
            return [];
        }
        $employmentIds = [];
        $starts = [];
        foreach ($employments as $row) {
            $employmentId = (int) $row['employment_id'];
            $employmentIds[] = $employmentId;
            $start = $row['actual_start_date'] ?? $row['start_date'] ?? null;
            $starts[$employmentId] = is_string($start) ? $start : null;
        }

        $validations = [];
        foreach ($this->overtimeLimits->assessMany(
            $supplierId,
            $employmentIds,
            $periodStart,
            $periodEnd,
            $starts,
        ) as $assessment) {
            foreach ($this->overtimeLimits->validations($assessment) as $validation) {
                $validations[] = $validation;
            }
        }

        return $validations;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,string|null>|null
     */
    private function jmhzCodebookValidationForPeriod(
        array $row,
        string $periodStart,
        string $periodEnd,
    ): ?array
    {
        $code = $row['jmhz_workplace_municipality_code'];
        $country = $row['jmhz_workplace_country_code'];
        $name = $row['work_place'];
        $overlayKey = $row['jmhz_external_codebook_overlay_key'];
        $manifestHash = $row['jmhz_external_codebook_manifest_sha256'];
        if (!is_string($code) || !is_string($country) || !is_string($name)
            || !is_string($overlayKey)
            || !is_string($manifestHash)
            || $this->jmhzExternalCodebooks === null
            || !$this->jmhzExternalCodebooks->hasLoadableIdentity($overlayKey, $manifestHash)
        ) {
            return null;
        }
        try {
            $startProvenance = $this->jmhzExternalCodebooks->provenanceForDate($periodStart);
            $endProvenance = $this->jmhzExternalCodebooks->provenanceForDate($periodEnd);
            if ($startProvenance['overlay_key'] !== $endProvenance['overlay_key']
                || $startProvenance['manifest_sha256'] !== $endProvenance['manifest_sha256']
            ) {
                return null;
            }
            $this->jmhzExternalCodebooks->requireMunicipality($code, $name, $periodStart);
            $this->jmhzExternalCodebooks->requireCountry($country, $periodStart);
            $this->jmhzExternalCodebooks->requireMunicipality($code, $name, $periodEnd);
            $this->jmhzExternalCodebooks->requireCountry($country, $periodEnd);
            return $endProvenance;
        } catch (JmhzCodebookUnavailableException|JmhzCodebookValueException) {
            return null;
        }
    }

    /**
     * @return array{
     *   id:int,
     *   row_version:int,
     *   automatic_posting_enabled:bool
     * }
     */
    private function employerPolicySnapshot(
        int $supplierId,
        string $periodStart,
    ): array {
        $policy = ($this->employerPolicies
            ?? new PayrollEmployerPolicyRepository(
                $this->db,
                new PayrollEmployerPolicyDeletionRepository($this->db, new ActivityLogger($this->db)),
            ))
            ->findEffective($supplierId, $periodStart);
        if ($policy === null) {
            throw new \DomainException(
                'Pro mzdové období chybí účinná zaměstnavatelská politika.',
            );
        }
        $id = $policy['id'] ?? null;
        $rowVersion = $policy['row_version'] ?? null;
        $automaticPosting = $policy['automatic_posting_enabled'] ?? null;
        if (!is_int($id)
            || $id <= 0
            || !is_int($rowVersion)
            || $rowVersion <= 0
            || !is_bool($automaticPosting)
        ) {
            throw new \UnexpectedValueException(
                'Účinná zaměstnavatelská politika nemá platná data pro mzdový snapshot.',
            );
        }

        return [
            'id' => $id,
            'row_version' => $rowVersion,
            'automatic_posting_enabled' => $automaticPosting,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function employmentRows(
        int $supplierId,
        string $periodStart,
        string $periodEnd,
        ?int $officeId,
    ): array {
        $officeSql = $officeId === null ? '1 = 1' : 'employment.office_id = ?';
        $stmt = $this->db->pdo()->prepare(
            'WITH effective_employment AS (
                    SELECT employment.*,
                           ' . PayrollEmploymentLifecycleSql::effectiveStatusAtPlaceholder() . '
                               AS effective_status
                      FROM payroll_employments employment
                     WHERE employment.supplier_id = ?
                 )
             SELECT employment.id AS employment_id,
                    employment.employee_id,
                    employment.office_id,
                    employment.code AS employment_code,
                    employment.relation_type,
                    employment.effective_status AS employment_status,
                    employment.start_date,
                    employment.actual_start_date,
                    employment.end_date,
                    employment.monthly_gross_minor,
                    employee.full_name,
                    employee.is_active AS employee_active,
                    profile.profile_status,
                    term.id AS term_id,
                    term.row_version AS term_row_version,
                    term.effective_from,
                    term.effective_to,
                    term.weekly_hours,
                    term.workload_basis_points,
                    term.activity_code,
                    term.jmhz_relationship_detail_code,
                    term.work_place,
                    term.jmhz_workplace_municipality_code,
                    term.jmhz_workplace_country_code,
                    term.jmhz_external_codebook_overlay_key,
                    term.jmhz_external_codebook_manifest_sha256,
                    term.jmhz_apz_contribution_status,
                    term.jmhz_apz_instrument_code,
                    term.jmhz_functional_benefits_status,
                    term.jmhz_temporary_assignment_status,
                    term.jmhz_orchard_discount_eligible,
                    term.jmhz_specific_legal_fact_applies,
                    term.jmhz_ozp_employment_support_applies,
                    term.jmhz_deep_mining_work_applies,
                    term.social_insurance_participation,
                    term.health_insurance_participation,
                    term.tax_regime,
                    term.other_withholding_eligibility,
                    term.tax_declaration_signed,
                    term.is_primary AS term_is_primary,
                    term.risky_work,
                    term.social_employer_rate_category,
                    term.social_employer_rate_category_evidence,
                    term.social_part_time_discount_reason,
                    term.social_part_time_discount_evidence,
                    term.social_part_time_discount_notified_on,
                    term.foreign_legislation_country_code,
                    term.a1_certificate_until,
                    average.id AS average_earning_id,
                    average.row_version AS average_earning_row_version,
                    average.applicable_year AS average_earning_year,
                    average.applicable_quarter AS average_earning_quarter,
                    average.revision_no AS average_earning_revision_no,
                    average.source_kind AS average_earning_source_kind,
                    average.average_hourly_minor,
                    average.support_status AS average_earning_support_status,
                    average.status AS average_earning_status,
                    average.ruleset_id AS average_earning_ruleset_id,
                    average.ruleset_hash AS average_earning_ruleset_hash,
                    HEX(average.input_hash) AS average_earning_input_hash,
                    risky_savings.id AS risky_savings_id,
                    risky_savings.period_start AS risky_savings_period_start,
                    risky_savings.revision_no AS risky_savings_revision_no,
                    risky_savings.risk_factor AS risky_savings_risk_factor,
                    risky_savings.work_category AS risky_savings_work_category,
                    risky_savings.qualifying_shift_eighths
                        AS risky_savings_qualifying_shift_eighths,
                    risky_savings.right_claimed_on
                        AS risky_savings_right_claimed_on,
                    risky_savings.employee_informed_on
                        AS risky_savings_employee_informed_on,
                    risky_savings.pension_company
                        AS risky_savings_pension_company,
                    risky_savings.institution_account_id
                        AS risky_savings_institution_account_id,
                    risky_savings.institution_account_row_version
                        AS risky_savings_institution_account_row_version,
                    risky_savings.institution_account_hash
                        AS risky_savings_institution_account_hash,
                    risky_savings.institution_account_masked
                        AS risky_savings_institution_account_masked,
                    risky_savings_account.row_version
                        AS risky_savings_current_account_row_version,
                    LOWER(HEX(risky_savings_account.bank_account_hash))
                        AS risky_savings_current_account_hash,
                    risky_savings.product_reference
                        AS risky_savings_product_reference,
                    risky_savings.variable_symbol
                        AS risky_savings_variable_symbol,
                    risky_savings.specific_symbol
                        AS risky_savings_specific_symbol,
                    risky_savings.payment_message
                        AS risky_savings_payment_message,
                    risky_savings.evidence_reference
                        AS risky_savings_evidence_reference,
                    risky_savings.status AS risky_savings_status,
                    risky_savings.row_version AS risky_savings_row_version,
                    risky_savings.approved_at AS risky_savings_approved_at,
                    risky_savings.approved_by AS risky_savings_approved_by
               FROM effective_employment employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
               JOIN payroll_employee_profiles profile
                 ON profile.supplier_id = employment.supplier_id
                AND profile.employee_id = employment.employee_id
          LEFT JOIN payroll_employment_terms term
                 ON term.supplier_id = employment.supplier_id
                AND term.employment_id = employment.id
                AND term.effective_from <= LEAST(
                    ?,
                    COALESCE(employment.end_date, ?)
                )
                AND (
                    term.effective_to IS NULL
                    OR term.effective_to >= LEAST(
                        ?,
                        COALESCE(employment.end_date, ?)
                    )
                )
                AND term.id = (
                    SELECT selected.id
                      FROM payroll_employment_terms selected
                     WHERE selected.supplier_id = employment.supplier_id
                       AND selected.employment_id = employment.id
                       AND selected.effective_from <= LEAST(
                           ?,
                           COALESCE(employment.end_date, ?)
                       )
                       AND (
                           selected.effective_to IS NULL
                           OR selected.effective_to >= LEAST(
                               ?,
                               COALESCE(employment.end_date, ?)
                           )
                       )
                     ORDER BY selected.effective_from DESC, selected.id DESC
                     LIMIT 1
                )
          LEFT JOIN payroll_average_earning_snapshots average
                 ON average.supplier_id = employment.supplier_id
                AND average.employment_id = employment.id
                AND average.applicable_year = YEAR(?)
                AND average.applicable_quarter = QUARTER(?)
                AND average.status = "approved"
                AND average.id = (
                    SELECT selected_average.id
                      FROM payroll_average_earning_snapshots selected_average
                     WHERE selected_average.supplier_id = employment.supplier_id
                       AND selected_average.employment_id = employment.id
                       AND selected_average.applicable_year = YEAR(?)
                       AND selected_average.applicable_quarter = QUARTER(?)
                       AND selected_average.status = "approved"
                     ORDER BY selected_average.revision_no DESC,
                              selected_average.id DESC
                     LIMIT 1
                )
          LEFT JOIN payroll_risky_savings_evidence risky_savings
                 ON risky_savings.supplier_id = employment.supplier_id
                AND risky_savings.employment_id = employment.id
                AND risky_savings.period_start = ?
                AND risky_savings.revision_no = (
                    SELECT MAX(selected_risky_savings.revision_no)
                      FROM payroll_risky_savings_evidence selected_risky_savings
                     WHERE selected_risky_savings.supplier_id =
                           risky_savings.supplier_id
                       AND selected_risky_savings.employment_id =
                           risky_savings.employment_id
                       AND selected_risky_savings.period_start =
                           risky_savings.period_start
                )
          LEFT JOIN payroll_institution_accounts risky_savings_account
                 ON risky_savings_account.supplier_id =
                    risky_savings.supplier_id
                AND risky_savings_account.id =
                    risky_savings.institution_account_id
              WHERE employment.effective_status IS NOT NULL
                AND employment.effective_status NOT IN ("archived", "no_show")
                AND COALESCE(
                    employment.actual_start_date,
                    employment.start_date,
                    "1900-01-01"
                ) <= ?
                AND (
                    employment.end_date IS NULL
                    OR employment.end_date >= ?
                    OR EXISTS (
                        SELECT 1
                          FROM payroll_inputs post_termination_input
                         WHERE post_termination_input.supplier_id =
                               employment.supplier_id
                           AND post_termination_input.employment_id =
                               employment.id
                           AND post_termination_input.period_start = ?
                           AND post_termination_input.status <> "cancelled"
                    )
                )
                AND ' . $officeSql . '
              ORDER BY employment.employee_id, employment.id'
        );
        $stmt->execute([
            $periodEnd,
            $supplierId,
            $periodEnd,
            $periodEnd,
            $periodEnd,
            $periodEnd,
            $periodEnd,
            $periodEnd,
            $periodEnd,
            $periodEnd,
            $periodStart,
            $periodStart,
            $periodStart,
            $periodStart,
            $periodStart,
            $periodEnd,
            $periodStart,
            $periodStart,
            ...($officeId === null ? [] : [$officeId]),
        ]);
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    private function officeRegistration(int $supplierId, int $officeId, string $onDate): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT version.id, version.effective_from, version.social_security_variable_symbol,
                    version.source_reference, office.code, office.name
               FROM payroll_office_registration_versions version
               JOIN payroll_offices office
                 ON office.supplier_id = version.supplier_id AND office.id = version.office_id
              WHERE version.supplier_id = ? AND version.office_id = ?
                AND version.effective_from <= ?
              ORDER BY version.effective_from DESC, version.id DESC LIMIT 1',
        );
        $statement->execute([$supplierId, $officeId, $onDate]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        $data = [
            'id' => (int) $row['id'],
            'effective_from' => (string) $row['effective_from'],
            'social_security_variable_symbol' => (string) $row['social_security_variable_symbol'],
            'source_reference' => (string) $row['source_reference'],
            'office_code' => (string) $row['code'],
            'office_name' => (string) $row['name'],
        ];
        $data['sha256'] = hash('sha256', CanonicalJson::encode($data));
        return $data;
    }

    /**
     * Nálezy ke slevě zaměstnavatele podle § 7a. Jdou do VALIDACÍ, ne do
     * `$data` — kanonický snapshot a tím i `input_hash` proto zůstávají beze
     * změny a přepočet starší revize dá bit po bitu tentýž vstup jako předtím.
     *
     * Dvě věci, o kterých se uživatel jinak nedozví:
     *
     * 1. **Chybějící přijatý záměr.** Sleva se bez něj podle § 7a odst. 5
     *    neuplatní. Ve výsledku běhu je to jen „nedoložený nárok"; odsud se
     *    dozví, KTERÉ podání chybí.
     * 2. **Přechodné pravidlo pro 01–03/2026.** Kontroly 164, 290 a 333 vážou
     *    slevu za tahle období na 30. 6. 2026 a všechny tři jsou v evaluátoru
     *    `NotEvaluable`, protože potřebují datum přijetí podání od ČSSZ.
     *    Uživatel tedy z kontrol nedostane varování žádné.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $intent
     * @return list<PayrollRunValidation>
     */
    private function discountValidations(
        array $row,
        ?array $intent,
        int $employmentId,
        int $employeeId,
        string $periodStart,
    ): array {
        $reason = $row['social_part_time_discount_reason'] ?? 'none';
        if (!is_string($reason) || $reason === 'none') {
            return [];
        }
        $validations = [];
        if ($intent === null) {
            $validations[] = new PayrollRunValidation(
                'warning',
                'part_time_discount_intent_missing',
                'employment',
                $employmentId,
                'Sleva na pojistném za kratší úvazek je zadaná, ale za období není doložený přijatý záměr (OZUSPOJ). Podle § 7a odst. 5 se bez něj sleva neuplatní.',
                '/payroll/submissions',
                true,
            );
        }
        if ((new OzuspojClaimDeadlinePolicy())->isTransitionalQ12026($periodStart)) {
            $validations[] = new PayrollRunValidation(
                'warning',
                'part_time_discount_transitional_window',
                'employment',
                $employmentId,
                'Slevu na pojistném za leden až březen 2026 bylo nutné vykázat v měsíčním hlášení do 30. 6. 2026. Po tomhle dni ji ČSSZ neuzná (kontrola 333) a odečtené pojistné se stane dluhem podle § 7c odst. 3.',
                "/payroll/employees/{$employeeId}",
                true,
            );
        }

        return $validations;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function averageEarningSnapshot(array $row): ?array
    {
        if ($row['average_earning_id'] === null) {
            return null;
        }

        return [
            'id' => (int) $row['average_earning_id'],
            'row_version' => (int) $row['average_earning_row_version'],
            'applicable_year' => (int) $row['average_earning_year'],
            'applicable_quarter' => (int) $row['average_earning_quarter'],
            'revision_no' => (int) $row['average_earning_revision_no'],
            'source_kind' => (string) $row['average_earning_source_kind'],
            'average_hourly_minor' => (int) $row['average_hourly_minor'],
            'support_status' => (string) $row['average_earning_support_status'],
            'status' => (string) $row['average_earning_status'],
            'ruleset_id' => (string) $row['average_earning_ruleset_id'],
            'ruleset_hash' => strtolower((string) $row['average_earning_ruleset_hash']),
            'input_hash' => strtolower((string) $row['average_earning_input_hash']),
        ];
    }

    /**
     * Exekuční evidence celé zmrazené množiny osob v kanonickém tvaru.
     *
     * @param list<int> $employeeIds
     * @return array<int,array<string,mixed>>
     */
    private function enforcementEvidence(
        int $supplierId,
        array $employeeIds,
        string $period,
        string $paymentDate,
    ): array {
        $source = $this->enforcement;
        if ($source === null) {
            return [];
        }
        if ($source instanceof PayrollEnforcementRepository) {
            return array_map(
                static fn (EnforcementPersonMonthEvidence $evidence): array =>
                    $evidence->toCanonicalArray(),
                $source->evidenceForMany(
                    $supplierId,
                    $employeeIds,
                    $period,
                    $paymentDate,
                ),
            );
        }

        // Cizí implementace rozhraní (testovací dvojníci) dávkové rozhraní nemají.
        $result = [];
        foreach ($employeeIds as $employeeId) {
            $result[$employeeId] = $source->evidenceFor(
                $supplierId,
                $employeeId,
                $period,
                $paymentDate,
            )->toCanonicalArray();
        }

        return $result;
    }

    /**
     * Zákonné roční kumulace celé zmrazené množiny osob.
     *
     * Osoba bez doloženého opening balance chybí ve výsledku dávkového dotazu —
     * a dostane tady tutéž hlášku `unverified`, jakou dřív vyrobila zachycená
     * PayrollStatutoryAccumulatorUnavailableException.
     *
     * @param list<int> $employeeIds
     * @return array<int,array<string,mixed>>
     */
    private function statutoryAccumulatorStates(
        int $supplierId,
        array $employeeIds,
        int $year,
        string $periodStart,
    ): array {
        $unverified = [
            'status' => 'unverified',
            'issue_code' => 'annual_accumulator_missing',
            'state' => null,
        ];
        $states = [];
        foreach (['social_insurance', 'income_tax'] as $calculationKind) {
            $states[$calculationKind] = $this->statutoryAccumulators?->statesBeforePeriod(
                $supplierId,
                $employeeIds,
                $year,
                $periodStart,
                $calculationKind,
            ) ?? [];
        }

        $result = [];
        foreach ($employeeIds as $employeeId) {
            $snapshot = [
                'schema_version' => 'payroll-person-statutory-accumulators.v1',
            ];
            foreach ($states as $calculationKind => $byEmployee) {
                $state = $byEmployee[$employeeId] ?? null;
                $snapshot[$calculationKind] = $state === null
                    ? $unverified
                    : [
                        'status' => 'verified',
                        'issue_code' => null,
                        'state' => $state,
                    ];
            }
            $result[$employeeId] = $snapshot;
        }

        return $result;
    }

    /**
     * @return array{
     *   name:string,
     *   identification_number:string,
     *   accounting_accounts:array<string,string>
     * }
     */
    private function employerSnapshot(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COALESCE(NULLIF(display_name, ""), company_name) AS name, ic
               FROM supplier
              WHERE id = ?'
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || !is_string($row['name'] ?? null)
            || trim($row['name']) === ''
            || !is_string($row['ic'] ?? null)
            || trim($row['ic']) === ''
        ) {
            throw new \DomainException(
                'Firma nemá úplnou identitu zaměstnavatele pro výplatní pásky.',
            );
        }
        $settings = ($this->employerSettings
            ?? new PayrollEmployerSettingsRepository($this->db))
            ->get($supplierId);
        $accounts = $settings['accounts'] ?? null;
        if (!is_array($accounts) || array_is_list($accounts)) {
            throw new \DomainException(
                'Firma nemá úplné účetní předkontace pro výplatní pásky.',
            );
        }
        $accountSnapshot = [];
        foreach ($accounts as $key => $account) {
            if (!is_string($key)
                || !is_string($account)
                || preg_match('/^[0-9]{3}[.A-Z0-9]{0,13}$/D', $account) !== 1
            ) {
                throw new \DomainException(
                    'Firma nemá platné účetní předkontace pro výplatní pásky.',
                );
            }
            $accountSnapshot[$key] = $account;
        }
        ksort($accountSnapshot, SORT_STRING);

        return [
            'name' => trim($row['name']),
            'identification_number' => trim($row['ic']),
            'accounting_accounts' => $accountSnapshot,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function timeMonth(array $row): array
    {
        $summary = null;
        if ($row['summary_id'] !== null) {
            if (!hash_equals(
                (string) $row['stored_spec_manifest_sha256'],
                (string) $row['spec_manifest_sha256'],
            )) {
                throw new \DomainException('Pracovní souhrn JMHZ odkazuje na jiný balík specifikace.');
            }
            $provenance = json_decode(
                (string) $row['provenance_json'],
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            if (!is_array($provenance)) {
                throw new \DomainException('Provenance pracovního souhrnu JMHZ je neplatná.');
            }
            $values = [
                'standard_fund_millihours' => (int) $row['standard_fund_millihours'],
                'agreed_fund_millihours' => (int) $row['agreed_fund_millihours'],
                'weekly_work_centihours' => (int) $row['weekly_work_centihours'],
                'evidence_days' => (int) $row['evidence_days'],
                'worked_millihours' => (int) $row['worked_millihours'],
            ];
            $derivationVersion = (string) $row['derivation_version'];
            $conditionalBlocksConfirmed = (int) $row['conditional_blocks_confirmed'] === 1;
            $interactions = null;
            if ($derivationVersion === 'jmhz-work-month.v2') {
                if (!$conditionalBlocksConfirmed
                    || !in_array($row['unworked_hours_occurred'], [0, 1, '0', '1'], true)
                    || !in_array($row['work_obstacles_occurred'], [0, 1, '0', '1'], true)
                ) {
                    throw new \DomainException(
                        'Podmíněné bloky pracovního souhrnu JMHZ nejsou potvrzené.',
                    );
                }
                foreach ([
                    'unworked_total_millihours',
                    'unworked_paid_millihours',
                    'dpn_without_employer_compensation_millihours',
                    'dpn_with_employer_compensation_millihours',
                    'vacation_millihours',
                    'care_millihours',
                    'employee_obstacle_paid_millihours',
                    'employer_obstacle_millihours',
                ] as $field) {
                    $values[$field] = $row[$field] === null ? null : (int) $row[$field];
                }
                $interactions = [
                    'IN07' => (int) $row['unworked_hours_occurred'] === 1,
                    'IN08' => (int) $row['work_obstacles_occurred'] === 1,
                ];
            } elseif ($derivationVersion !== 'jmhz-work-month-core.v1') {
                throw new \DomainException('Verze pracovního souhrnu JMHZ není podporovaná.');
            }
            $sourceJson = (string) $row['source_snapshot_json'];
            $sourceHash = (string) $row['source_snapshot_sha256'];
            if (!hash_equals($sourceHash, hash('sha256', $sourceJson))) {
                throw new \DomainException('Zdrojový hash pracovního souhrnu JMHZ nesouhlasí.');
            }
            $summaryPayload = [
                'derivation_version' => $derivationVersion,
                'specification' => [
                    'package_key' => (string) $row['spec_package_key'],
                    'spec_manifest_sha256' => (string) $row['spec_manifest_sha256'],
                    'scenario_catalog_key' => (string) $row['scenario_catalog_key'],
                    'scenario_manifest_sha256' => (string) $row['scenario_manifest_sha256'],
                ],
                'source_snapshot_sha256' => $sourceHash,
            ];
            if ($derivationVersion === 'jmhz-work-month.v2') {
                $summaryPayload['specification']['control_catalog_key'] =
                    (string) $row['control_catalog_key'];
                $summaryPayload['specification']['control_manifest_sha256'] =
                    (string) $row['control_manifest_sha256'];
                $summaryPayload['conditional_blocks_confirmed'] = true;
                $summaryPayload['interactions'] = $interactions;
            }
            $summaryPayload['values'] = $values;
            $summaryPayload['provenance'] = $provenance;
            $summaryPayload['confirmation_note'] = (string) $row['confirmation_note'];
            $summaryHash = (string) $row['summary_sha256'];
            if (!hash_equals(
                $summaryHash,
                hash('sha256', CanonicalJson::encode($summaryPayload)),
            )) {
                throw new \DomainException('Obsahový hash pracovního souhrnu JMHZ nesouhlasí.');
            }
            $summary = $summaryPayload + [
                'id' => (int) $row['summary_id'],
                'time_month_revision_no' => (int) $row['revision_no'],
                'source_snapshot_json' => $sourceJson,
                'summary_sha256' => $summaryHash,
            ];
        }
        return [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'revision_no' => (int) $row['revision_no'],
            'row_version' => (int) $row['row_version'],
            'approved_at' => $row['approved_at'],
            'jmhz_work_summary_status' => $summary === null
                ? 'unverified'
                : ((string) $summary['derivation_version'] === 'jmhz-work-month.v2'
                    ? 'frozen_work_summary'
                    : 'frozen_core'),
            'jmhz_work_summary' => $summary,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function inputs(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $component = json_decode(
                (string) $row['component_snapshot_json'],
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            if (!is_array($component)) {
                throw new \UnexpectedValueException('Snapshot mzdové složky není objekt.');
            }
            $entry = [
                'id' => (int) $row['id'],
                'amount_minor' => (int) $row['amount_minor'],
                'quantity_milliunits' => $row['quantity_milliunits'] === null
                    ? null
                    : (int) $row['quantity_milliunits'],
                'source_kind' => (string) $row['source_kind'],
                'source_period_start' => $row['source_period_start'],
                'component_snapshot_hash' =>
                    strtolower((string) $row['component_snapshot_hash']),
                'component' => $component,
            ];
            // Rozpad zákonného koše osvobození (§ 6 odst. 9 ZDP) zmrazený při
            // schválení vstupu. Revize spočtené před migrací 1480 klíče nemají —
            // čtou se proto jako „vstup do koše nepatří" a přepočítají se stejně
            // jako dřív.
            if (($row['benefit_basket'] ?? null) !== null) {
                $entry['benefit_basket'] = (string) $row['benefit_basket'];
                $entry['benefit_exempt_minor'] = (int) $row['benefit_exempt_minor'];
                $entry['benefit_taxable_minor'] = (int) $row['benefit_taxable_minor'];
            }
            $result[] = $entry;
        }
        return $result;
    }

    /**
     * Dimenze pracovního vztahu zmrazené do snapshotu.
     *
     * Nesou `default_account_code`, tedy nákladový účet hrubé mzdy pro dané
     * středisko/zakázku/činnost. Uživatel ho v číselníku dimenzí nastavoval už od
     * migrace 1307, ale zaúčtování ho nikdy nečetlo — nastavení bylo tiše k ničemu.
     *
     * Do snapshotu patří proto, že zaúčtování běží nad zmrazenými daty: kdyby se
     * dimenze dohledávaly až při účtování, přeúčtování starší revize by použilo
     * dnešní přiřazení střediska a vyrobilo jiné zaúčtování než původní. Starší
     * revize klíč `dimensions` nemají vůbec a
     * {@see \MyInvoice\Service\Payroll\Posting\PayrollPostingLineBuilder} to čte jako
     * „žádná dimenze“ — účtují se tedy dál přesně tak, jak se účtovaly.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function dimensions(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $account = $row['default_account_code'];
            $result[] = [
                'type' => (string) $row['dimension_type'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'default_account_code' => $account === null ? null : (string) $account,
            ];
        }
        return $result;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function absences(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'absence_type' => (string) $row['absence_type'],
                'date_from' => (string) $row['date_from'],
                'date_to' => (string) $row['date_to'],
                'partial_first_minutes' => $row['partial_first_minutes'] === null
                    ? null
                    : (int) $row['partial_first_minutes'],
                'partial_last_minutes' => $row['partial_last_minutes'] === null
                    ? null
                    : (int) $row['partial_last_minutes'],
                'timezone_name' => (string) $row['timezone_name'],
                'compensation_policy' => (string) $row['compensation_policy'],
                'average_snapshot_id' => $row['average_snapshot_id'] === null
                    ? null
                    : (int) $row['average_snapshot_id'],
                'decided_at' => $row['decided_at'],
            ],
            $rows,
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function deductionAgreements(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'agreement_reference' => (string) $row['agreement_reference'],
                'title' => (string) $row['title'],
                'deduction_kind' => (string) $row['deduction_kind'],
                'priority_no' => (int) $row['priority_no'],
                'requested_minor' => (int) $row['requested_minor'],
                'total_limit_minor' => $row['total_limit_minor'] === null
                    ? null
                    : (int) $row['total_limit_minor'],
                'withheld_total_minor' => (int) $row['withheld_total_minor'],
                'valid_from' => (string) $row['valid_from'],
                'valid_to' => $row['valid_to'],
                'row_version' => (int) $row['row_version'],
            ],
            $rows,
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function payoutRules(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'allocation_reference' => (string) $row['allocation_reference'],
                'destination_kind' => (string) $row['destination_kind'],
                'destination_reference' => $row['destination_reference'],
                'allocation_kind' => (string) $row['allocation_kind'],
                'amount_minor' => $row['amount_minor'] === null
                    ? null
                    : (int) $row['amount_minor'],
                'basis_points' => $row['basis_points'] === null
                    ? null
                    : (int) $row['basis_points'],
                'priority_no' => (int) $row['priority_no'],
                'row_version' => (int) $row['row_version'],
            ],
            $rows,
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function payoutAccounts(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'bank_account_hash' => (string) $row['bank_account_hash'],
                'bank_account_masked' => (string) $row['bank_account_masked'],
                'allocation_basis_points' =>
                    (int) $row['allocation_basis_points'],
                'effective_from' => (string) $row['effective_from'],
                'effective_to' => $row['effective_to'],
                'row_version' => (int) $row['row_version'],
                'verification_source' => $row['verification_source'],
                'verified_on' => $row['verified_on'],
                'verified_by' => $row['verified_by'] === null
                    ? null
                    : (int) $row['verified_by'],
            ],
            $rows,
        );
    }
}
