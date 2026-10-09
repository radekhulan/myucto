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
use MyInvoice\Service\Payroll\Employment\PayrollRelationType;
use MyInvoice\Service\Payroll\PayrollAccountingDefaults;
use MyInvoice\Service\Payroll\PayrollPredecessorObligationScope;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\HealthInsurance\PayrollExpectedHealthParticipation;
use MyInvoice\Service\Payroll\SocialInsurance\PayrollExpectedParticipation;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPartTimeDiscountAgeCondition;
use MyInvoice\Service\Payroll\RiskySavings\PayrollRiskySavingsPolicy;
use MyInvoice\Service\Payroll\RiskySavings\PayrollRiskySavingsRules;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookUnavailableException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookValueException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojClaimDeadlinePolicy;
use MyInvoice\Service\Payroll\Time\Overtime\PayrollOvertimeLimitService;
use MyInvoice\Service\Payroll\Time\PayrollAgreementAnnualHours;
use MyInvoice\Service\Payroll\Time\PayrollAgreementWeeklyAverage;
use MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder;
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
                '/payroll/people',
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
        // Roční limit dohod o provedení práce (§ 75 ZP) včetně hodin převzatých
        // z předchozího programu. Varování, ne závora — a jako u přesčasů se
        // do kanonického snapshotu nepíše.
        foreach ((new PayrollAgreementAnnualHours($this->db))->validations(
            $supplierId,
            $employments,
            $periodStart,
        ) as $validation) {
            $validations[] = $validation;
        }
        // Průměrný týdenní rozsah DPČ (§ 76 odst. 2 ZP), stejně mimo snapshot.
        foreach ((new PayrollAgreementWeeklyAverage($this->db))->validations(
            $supplierId,
            $employments,
            $periodStart,
        ) as $validation) {
            $validations[] = $validation;
        }

        $personRegistrationGaps = $this->personRegistrationGaps(
            $supplierId,
            $employmentIds,
        );

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
        $pendingRecurringCounts = $this->batch->pendingRecurringCounts(
            $supplierId,
            $employmentIds,
            $periodStart,
            $periodEnd,
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
        $discountIntentRecords = $this->batch->discountIntentRecords(
            $supplierId,
            $employmentIds,
            $periodStart,
            $periodEnd,
        );
        $discountIntentRows = array_map(
            PayrollRunSnapshotBatchLoader::snapshotDiscountIntent(...),
            $discountIntentRecords,
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
        $deferredIncomes = $this->deferredIncomes($supplierId, $periodStart);
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
                    "/payroll/people?person={$employeeId}&employment={$employmentId}&panel=employment_terms",
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
                    "/payroll/people?person={$employeeId}&employment={$employmentId}&panel=employment_terms&field=office_id",
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
                    '/payroll/settings?tab=employer#payroll-employer-registration',
                );
            }
            // Registrace ÚČTÁRNY (výše) není registrace OSOBY. Doteď se
            // kontrolovala jen ta první, takže mzda zaměstnankyně, za kterou
            // nikdo nepodal přihlášku, spočítala pojistné, zaúčtovala ho
            // i zaplatila — a aplikace mlčela až do kontroly z ČSSZ.
            foreach ($this->personRegistrationValidations(
                $row,
                $employmentId,
                $employeeId,
                $personRegistrationGaps[$employmentId] ?? null,
                $periodEnd,
            ) as $validation) {
                $validations[] = $validation;
            }
            $timeMonth = isset($timeMonthRows[$employmentId])
                ? $this->timeMonth($timeMonthRows[$employmentId])
                : null;
            /*
             * CHYBĚJÍCÍ pracovní měsíc blokuje stejně jako neschválený.
             *
             * Why: kontrola dřív hlídala jen řádek, který existuje a není
             * schválený. Vztah, u kterého se docházka vůbec nezaložila, prošel
             * tiše — a protože `lock_inputs` zmrazí snímek, dostal se do něj
             * `time_month: null`. Účetní pak měsíc schválila, jenže hlášení
             * JMHZ čte zmrazený snímek a dál tvrdilo „pracovní doba za měsíc
             * není schválená". Slepá ulička: údaj byl doplněný, hláška lhala
             * a cesta ven (znovu otevřít běh a založit novou revizi) nikde
             * nestála.
             *
             * Pracovní souhrn je pro JMHZ povinný — u scénáře 3 (jednatel)
             * atributy 10259/10260/10261. Je to `warning`, ne `blocker`:
             * varování se ukáže PŘED zamknutím, takže účetní ví, co si zmrazí,
             * ale vztah, u kterého se docházka opravdu nevede (dohoda bez
             * evidence), tím běh nezastaví.
             */
            $deferredIncome = $deferredIncomes[$employmentId] ?? null;
            // Odložený příjem se vyplácí po skončení vztahu, kdy žádná pracovní
            // doba neexistuje; formulář JMHZ pro něj průběh zaměstnání nemá.
            if ($deferredIncome !== null) {
                // Bez kontroly pracovní doby, viz výše.
            } elseif ($timeMonth === null) {
                $validations[] = new PayrollRunValidation(
                    'warning',
                    'time_month_missing',
                    'employment',
                    $employmentId,
                    // Jméno vpředu (Q15-11): 187 stejných vět bez osoby nešlo
                    // v kontrolách běhu přiřadit. Varování schválení nezastaví,
                    // text proto neříká „nejdřív", jen co to znamená pro JMHZ.
                    sprintf(
                        '%s: pracovní vztah nemá za období založenou a schválenou pracovní dobu. '
                        . 'Běh jde schválit i tak, ale zamknutím vstupů se stav zmrazí '
                        . 'a měsíční hlášení ČSSZ ji neuvidí — schvalte ji před přípravou hlášení.',
                        (string) $row['full_name'],
                    ),
                    "/payroll/time?employment={$employmentId}&period=" . substr($periodStart, 0, 7),
                );
            } elseif ($timeMonth['status'] !== 'approved') {
                $validations[] = new PayrollRunValidation(
                    'blocker',
                    'time_month_not_approved',
                    'employment',
                    $employmentId,
                    sprintf(
                        '%s: pracovní vztah nemá schválenou docházku. Zamknutím vstupů se '
                        . 'stav zmrazí a měsíční hlášení ČSSZ by ji už nevidělo.',
                        (string) $row['full_name'],
                    ),
                    "/payroll/time?employment={$employmentId}&period=" . substr($periodStart, 0, 7),
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
                    // Rovnou na koncepty měsíce: nefiltrovaný seznam měl stovky
                    // řádků a koncepty v něm musel uživatel hledat po stránkách.
                    '/payroll/components?tab=inputs&status=draft&period=' . substr($periodStart, 0, 7),
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
            $absences = $this->absences($absenceRows[$employmentId] ?? []);
            if ($inputs === []) {
                // Vztah bez složky je většinou chyba zadání (zapomenutá mzda).
                // Výjimku (override) tu NEnabízíme: když měsíc bez vstupu
                // nevysvětluje schválená nepřítomnost, zastaví vztah zákonný
                // výpočet (`payroll_component_missing`) podle téhož pravidla
                // {@see PayrollRunStatutoryInputAssembler::monthWithoutInputsExplained()}
                // a schválená výjimka by se po přepočtu vrátila jako blokátor.
                // Dřív ji šlo „schválit u všech", běh přesto neprošel a výjimka
                // jen klamala. Náprava je vstup, ne podpis — proto proklik.
                $pendingRecurring = $pendingRecurringCounts[$employmentId] ?? 0;
                $validations[] = new PayrollRunValidation(
                    'warning',
                    'employment_without_inputs',
                    'employment',
                    $employmentId,
                    $pendingRecurring > 0
                        // Pravidelná složka (typicky převzatá měsíční mzda) se do
                        // běhu dostane až jako schválený vstup. Výjimka tu nic
                        // nevyřeší — výpočet by vztah stejně zastavil — takže
                        // proklik vede rovnou na hromadné vytvoření vstupů.
                        ? sprintf(
                            '%s: pravidelná mzdová složka za období ještě nemá vytvořený a schválený vstup. '
                            . 'V Rychlém zadání mezd použijte „Vytvořit a schválit vstupy z pravidelných složek" '
                            . 'a pak obnovte podklady běhu.',
                            (string) $row['full_name'],
                        )
                        // Trvající vztah bez příjmu (typicky dohoda, na které
                        // se v měsíci nepracovalo) se hlásí nulovým formulářem
                        // JMHZ; vznikne z výslovně zadané nuly, ne z mlčení.
                        : sprintf(
                            '%s: pracovní vztah nemá v období žádnou schválenou mzdovou složku. '
                            . 'Pokud vztah trvá a příjem v měsíci opravdu nevznikl, zadejte u základní '
                            . 'složky 0 Kč — za vztah se podá nulové hlášení JMHZ.',
                            (string) $row['full_name'],
                        ),
                    $pendingRecurring > 0
                        ? '/payroll/quick-inputs?period=' . substr($periodStart, 0, 7)
                        : '/payroll/quick-inputs?period=' . substr($periodStart, 0, 7)
                            . '&employment=' . $employmentId,
                    false,
                );
            }
            foreach ($this->discountValidations(
                $row,
                $discountIntentRows[$employmentId] ?? null,
                $employmentId,
                $employeeId,
                $periodStart,
                $discountIntentRecords[$employmentId] ?? null,
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
                    // Uživatel dočasného přidělení (JMHZ 10252, 10492–10494).
                    'jmhz_assignment_user_kind' => $row['jmhz_assignment_user_kind'] ?? null,
                    'jmhz_assignment_user_ico' => $row['jmhz_assignment_user_ico'] ?? null,
                    'jmhz_assignment_user_country_code' =>
                        $row['jmhz_assignment_user_country_code'] ?? null,
                    'jmhz_assignment_user_foreign_id' =>
                        $row['jmhz_assignment_user_foreign_id'] ?? null,
                    'jmhz_assignment_user_name' => $row['jmhz_assignment_user_name'] ?? null,
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
                    // `other_withholding_eligibility` (migrace 1403) do nových
                    // revizí nepatří: srážku § 6 odst. 4 ZDP určuje výpočet
                    // z druhu vztahu a úhrnu příjmů. Starší revize klíč nesou dál.
                    'tax_declaration_signed' => $this->taxDeclarationSigned(
                        $statutoryEvidence[$employeeId] ?? null,
                        $row,
                    ),
                    'risky_work' => (bool) $row['risky_work'],
                    'social_employer_rate_category' =>
                        (string) $row['social_employer_rate_category'],
                    'social_employer_rate_category_evidence' =>
                        $row['social_employer_rate_category_evidence'],
                    // Kategorizace rizika JMHZ 10274 u písm. b) (6/7).
                    'jmhz_risk_categorization_code' =>
                        $row['jmhz_risk_categorization_code'] ?? null,
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
                    // Výsledek věkové podmínky důvodu slevy (§ 7a odst. 1
                    // písm. a, d, g). Do snímku jde jen odvozený výrok, ne
                    // datum narození - snímek se ukládá nešifrovaně.
                    'social_part_time_discount_age_condition' =>
                        $this->discountAgeCondition($row, $periodStart, $periodEnd),
                    // Zaměstnavatel na chráněném trhu práce (REGZEL ID 10211):
                    // zaměstnanci s postižením u něj sleva nenáleží (§ 7a odst. 3
                    // písm. d). Bez profilu REGZEL se bere „ne" jako jinde v mzdách.
                    'employer_protected_labor_market' =>
                        (int) ($row['employer_protected_labor_market'] ?? 0) === 1,
                    'foreign_legislation_country_code' =>
                        $row['foreign_legislation_country_code'],
                    'a1_certificate_until' => $row['a1_certificate_until'],
                ];
                // Člen družstva nebo SVJ (§ 5 písm. a) body 4 a 5 zákona
                // č. 48/1997 Sb.) je zaměstnancem pro ZP jen v měsíci se
                // započitatelným příjmem. Klíč jde do snímku jen u takového
                // vztahu, ostatním zůstává snímek i otisk vstupu beze změny.
                if ((int) ($row['health_association_member'] ?? 0) === 1) {
                    $termSnapshot['health_association_member'] = true;
                }
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
                'dimensions' => $this->dimensions($dimensionRows[$employmentId] ?? [], $employmentId),
                // Potvrzený odložený příjem (JMHZ 10548) za tento měsíc; jen
                // tam, kde ho účetní potvrdila, ostatní vstup se nemění.
                ...($deferredIncome === null ? [] : ['deferred_income' => $deferredIncome]),
                // Jiný příjem povinného v době poskytování odstupného (§ 299
                // odst. 4 věta druhá o. s. ř.) zadaný v kartě Skončení vztahu;
                // jen tam, kde je vyplněný, ostatní vstup se nemění.
                ...(($row['severance_other_income_from'] ?? null) === null ? [] : [
                    'severance_garnishment' => [
                        'other_income_from' => (string) $row['severance_other_income_from'],
                        'other_payer_applies_protected_amount' =>
                            (bool) $row['severance_other_payer_applies_protected_amount'],
                    ],
                ]),
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

        foreach ($this->receivedEnforcementValidations(
            $supplierId,
            array_keys($people),
            $paymentDate,
        ) as $validation) {
            $validations[] = $validation;
        }

        // Nepodepsané prohlášení poplatníka je rozhodnutý stav, ne mezera —
        // assembler ho proto neblokuje. Má ale daňový dopad (bez slevy na
        // poplatníka a zvýhodnění, u DPP a zaměstnání malého rozsahu pod
        // rozhodnou částkou srážka) a hromadné doplnění ho umí zapsat na jedno
        // kliknutí. Jedno souhrnné varování za firmu, ne řádek na osobu:
        // u 200 lidí by jednotlivá varování přehlušila skutečné problémy.
        // Validace do `$data` nevstupují, takže `input_hash` se nemění.
        //
        // Jednatel a společník (osoba jen se vztahy orgánu společnosti) se
        // nepočítají: prohlášení u nich obvykle vědomě nepodepisují, protože
        // ho uplatňují u jiného plátce, a záloha bez slevy je běžný stav, ne
        // přehlédnutí. Daňový dopad je stejný jako u kohokoliv jiného (včetně
        // srážky pod rozhodnou částkou), mění se jen to, co běh hlásí.
        // Osoba, která má vedle funkce i jiný vztah, se počítá dál.
        // Id osob nese souhrn kvůli trvalému skrytí po osobách.
        $unsignedDeclarations = [];
        foreach ($people as $personId => $person) {
            if (self::onlyCompanyBodyRelations($person['employments'])) {
                continue;
            }
            $declaration = $statutoryEvidence[$personId]['income_tax']['declaration'] ?? null;
            if (is_array($declaration) && ($declaration['status'] ?? null) === 'not-signed') {
                $unsignedDeclarations[] = (int) $personId;
            }
        }
        if ($unsignedDeclarations !== []) {
            $validations[] = new PayrollRunValidation(
                'warning',
                'tax_declaration_not_signed_summary',
                'run',
                null,
                self::unsignedDeclarationsMessage(count($unsignedDeclarations)),
                '/payroll/people',
                subjectIds: $unsignedDeclarations,
            );
        }

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
     * Věta souhrnného varování o nepodepsaných prohlášeních. Veřejná, protože
     * po skrytí části osob ji se sníženým počtem skládá i
     * {@see PayrollWarningSuppressionSet}.
     */
    public static function unsignedDeclarationsMessage(int $count): string
    {
        return sprintf(
            'Počet osob s nepodepsaným prohlášením poplatníka k dani: %d. Záloha'
            . ' se jim počítá bez slevy na poplatníka a bez daňového zvýhodnění;'
            . ' u dohody o provedení práce a zaměstnání malého rozsahu s příjmem'
            . ' pod rozhodnou částkou se daň sráží zvláštní sazbou (§ 6 odst. 4 ZDP).'
            . ' Ověřte, že to odpovídá skutečnosti.',
            $count,
        );
    }

    /**
     * Aktuální stav podkladů, které snímek zmrazuje — bez stavby celého snímku.
     *
     * Pro detekci zastaralé otevřené revize ({@see PayrollRunSourceDriftDetector}).
     * Musí jít TÝMIŽ dotazy a TOUŽ normalizací jako `build()`; vlastní výklad
     * by hlásil změnu tam, kde by nový snímek vyšel stejně, nebo naopak mlčel.
     * Množina vztahů jde přes `employmentRows()`, takže nový vztah v období
     * (nástup, vstup po skončení) se pozná stejně, jako by ho vzal nový snímek.
     *
     * @param list<int> $employmentIds vztahy zmrazeného snímku
     * @param list<int> $employeeIds osoby zmrazeného snímku
     * @return array{
     *   eligible_employment_ids:list<int>,
     *   inputs:array<int,list<array<string,mixed>>>,
     *   absences:array<int,list<array<string,mixed>>>,
     *   statutory_evidence:array<int,mixed>|null
     * }
     */
    public function currentSources(
        int $supplierId,
        string $periodStart,
        string $paymentDate,
        ?int $officeId,
        array $employmentIds,
        array $employeeIds,
    ): array {
        $period = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if ($supplierId <= 0 || $period === false || $period->format('Y-m-d') !== $periodStart) {
            throw new \InvalidArgumentException('Období mzdového běhu není platné.');
        }
        $periodEnd = $period->modify('last day of this month')->format('Y-m-d');

        $eligible = [];
        $eligibleEmployees = [];
        foreach ($this->employmentRows($supplierId, $periodStart, $periodEnd, $officeId) as $row) {
            $eligible[] = (int) $row['employment_id'];
            $eligibleEmployees[] = (int) $row['employee_id'];
        }
        $allEmployments = array_values(array_unique([...$employmentIds, ...$eligible]));
        $inputRows = $this->batch->inputs($supplierId, $allEmployments, $periodStart);
        $absenceRows = $this->batch->absences(
            $supplierId,
            $allEmployments,
            $periodStart,
            $periodEnd,
        );
        $inputs = [];
        $absences = [];
        foreach ($allEmployments as $employmentId) {
            $inputs[$employmentId] = $this->inputs($inputRows[$employmentId] ?? []);
            $absences[$employmentId] = $this->absences($absenceRows[$employmentId] ?? []);
        }

        $statutoryEvidence = null;
        if ($this->statutoryEvidence !== null) {
            $statutoryPeriod = ($this->periods ?? new PayrollStatutoryPeriodResolver())
                ->resolve($periodStart, $paymentDate);
            $statutoryEvidence = $this->statutoryEvidence->snapshotMany(
                $supplierId,
                array_values(array_unique([...$employeeIds, ...$eligibleEmployees])),
                $statutoryPeriod->taxCalculationDate,
            );
        }

        return [
            'eligible_employment_ids' => $eligible,
            'inputs' => $inputs,
            'absences' => $absences,
            'statutory_evidence' => $statutoryEvidence,
        ];
    }

    /** @param list<array<string,mixed>> $employments */
    private static function onlyCompanyBodyRelations(array $employments): bool
    {
        if ($employments === []) {
            return false;
        }
        foreach ($employments as $employment) {
            $type = PayrollRelationType::tryFrom((string) ($employment['employment']['relation_type'] ?? ''));
            if ($type === null || !$type->isCompanyBody()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Prohlášení k dani do snímku vztahu.
     *
     * Zdrojem je ZÁKONNÁ EVIDENCE OSOBY, ne sloupec smluvních podmínek.
     * Prohlášení se podepisuje (i odvolává) kdykoliv v průběhu vztahu, kdežto
     * `payroll_employment_terms` je verze smlouvy — dokud se hodnota brala
     * odtud, znamenal každý pozdější podpis rozpor mezi oběma místy a mzdový
     * běh na něj spadl (`tax_declaration_term_conflict`), aniž by ho kdo mohl
     * odstranit jinak než novou verzí smluvních podmínek.
     *
     * Sloupec zůstává záložním zdrojem jen tam, kde evidence není k dispozici
     * (volitelná závislost, osoba bez evidence). Chybějící evidenci ohlásí
     * assembler vlastním blokátorem `tax_declaration_evidence_missing`.
     *
     * @param array<string,mixed> $row
     */
    private function taxDeclarationSigned(mixed $evidence, array $row): bool
    {
        if (is_array($evidence)) {
            $incomeTax = $evidence['income_tax'] ?? null;
            $declaration = is_array($incomeTax) ? ($incomeTax['declaration'] ?? null) : null;
            $status = is_array($declaration) ? ($declaration['status'] ?? null) : null;
            if (is_string($status)) {
                return $status === 'signed';
            }
        }

        return (bool) $row['tax_declaration_signed'];
    }

    /**
     * Potvrzení odloženého příjmu (JMHZ scénář 8) za měsíc po vztazích.
     *
     * Bez sondy na existenci tabulky: tu zakládá migrace 1917 a snapshot běhu
     * nesmí stát víc dotazů podle toho, jestli je schéma v cache
     * (PayrollRunSnapshotBatchLoadTest).
     *
     * @return array<int,array{deferred_type:string,note:?string,row_version:int}>
     */
    private function deferredIncomes(int $supplierId, string $periodStart): array
    {
        return (new \MyInvoice\Repository\Payroll\PayrollDeferredIncomeRepository($this->db))
            ->forPeriod($supplierId, $periodStart);
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
     * Ověření obce a státu proti číselníku účinnému pro VYKAZOVANÉ OBDOBÍ.
     *
     * Provenience uložená u podmínek vztahu říká, proti čemu se hodnota ověřovala
     * v den, kdy ji někdo zadal — což je jiná otázka než ta, na kterou odpovídá
     * měsíční hlášení. Vztah vzniklý dřív, než ČSSZ začala číselníky vydávat,
     * žádnou provenienci nemá a mít nemůže; kdyby byla podmínkou, nešel by
     * takový člověk vykázat NIKDY, i když jeho obec v číselníku pro vykazovaný
     * měsíc bez problému je. Zaměstnavatel s dlouholetým zaměstnancem by tak
     * hlášení nesestavil vůbec.
     *
     * Uloženou provenienci proto bereme jako doklad navíc: když u podmínek je,
     * musí být načtitelná (poškozený nebo neznámý otisk je chyba evidence).
     * Když není, rozhoduje jen to, jestli obec a stát platí v číselníku
     * účinném pro celé vykazované období — a to se ověřuje tak jako tak níže.
     *
     * @param array<string,mixed> $row
     * @return array<string,string|null>|null
     */
    private function jmhzCodebookValidationForPeriod(
        array $row,
        string $periodStart,
        string $periodEnd,
    ): ?array
    {
        if ($this->jmhzExternalCodebooks === null) {
            return null;
        }
        $text = static fn (mixed $value): ?string => is_string($value) ? $value : null;

        // Pravidlo je jedno pro běh i hromadné doplnění pracoviště — viz
        // JmhzExternalCodebookCatalog::workplaceProvenanceForPeriod().
        return $this->jmhzExternalCodebooks->workplaceProvenanceForPeriod(
            $text($row['jmhz_workplace_municipality_code']),
            $text($row['work_place']),
            $text($row['jmhz_workplace_country_code']),
            $text($row['jmhz_external_codebook_overlay_key']),
            $text($row['jmhz_external_codebook_manifest_sha256']),
            $periodStart,
            $periodEnd,
        );
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
            /*
             * Hláška musí jmenovat období, jinak účetní neví, který měsíc
             * vlastně vadí — a hlavně musí říct, CO S TÍM. Typická příčina je
             * mzdová politika založená s účinností „dnes": pak platí až od
             * dneška a všechny dřívější měsíce zůstanou bez ní, přestože
             * pravidla tehdy samozřejmě platila taky.
             */
            throw new \DomainException(sprintf(
                'Za období %s není účinná žádná zaměstnavatelská mzdová '
                    . 'politika%s. Otevřete Mzdy → Nastavení zaměstnavatele → '
                    . 'Mzdové politiky a založte politiku s účinností od %s '
                    . 'nebo dřívější (u té nejstarší patří datum, odkdy firma '
                    . 'vede mzdy, ne dnešek).',
                self::formatPeriod($periodStart),
                $this->describeEarliestPolicy($supplierId, $periodStart),
                $periodStart,
            ));
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

    private static function formatPeriod(string $periodStart): string
    {
        $period = \DateTimeImmutable::createFromFormat('Y-m-d', $periodStart);

        return $period === false ? $periodStart : $period->format('m/Y');
    }

    /**
     * Dovětek, který účetní řekne, PROČ politika nesedí: skoro vždy proto, že
     * ta nejstarší uložená začíná až po hledaném období — typicky dneškem.
     */
    private function describeEarliestPolicy(
        int $supplierId,
        string $periodStart,
    ): string {
        try {
            $statement = $this->db->pdo()->prepare(
                'SELECT MIN(valid_from) FROM payroll_employer_policies
                  WHERE supplier_id = ?',
            );
            $statement->execute([$supplierId]);
            $earliest = $statement->fetchColumn();
        } catch (\PDOException) {
            return '';
        }
        if (!is_string($earliest) || $earliest === '') {
            return ' (zatím není uložená žádná)';
        }
        if ($earliest <= $periodStart) {
            return '';
        }

        return sprintf(' (nejstarší uložená platí až od %s)', $earliest);
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
                    COALESCE(term.monthly_gross_minor, employment.monthly_gross_minor)
                      AS monthly_gross_minor,
                    employee.full_name,
                    employee.birth_date AS employee_birth_date,
                    (SELECT regzel_profile.protected_labor_market
                       FROM payroll_regzel_employer_profiles regzel_profile
                      WHERE regzel_profile.supplier_id = employment.supplier_id)
                      AS employer_protected_labor_market,
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
                    term.jmhz_assignment_user_kind,
                    term.jmhz_assignment_user_ico,
                    term.jmhz_assignment_user_country_code,
                    term.jmhz_assignment_user_foreign_id,
                    term.jmhz_assignment_user_name,
                    term.jmhz_orchard_discount_eligible,
                    term.jmhz_specific_legal_fact_applies,
                    term.jmhz_ozp_employment_support_applies,
                    term.jmhz_deep_mining_work_applies,
                    term.social_insurance_participation,
                    term.health_insurance_participation,
                    term.health_association_member,
                    term.tax_regime,
                    term.tax_declaration_signed,
                    term.is_primary AS term_is_primary,
                    term.risky_work,
                    term.social_employer_rate_category,
                    term.social_employer_rate_category_evidence,
                    term.jmhz_risk_categorization_code,
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
                    risky_savings.approved_by AS risky_savings_approved_by,
                    termination.other_income_from AS severance_other_income_from,
                    termination.other_payer_applies_protected_amount
                        AS severance_other_payer_applies_protected_amount
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
          LEFT JOIN payroll_employment_terminations termination
                 ON termination.supplier_id = employment.supplier_id
                AND termination.employment_id = employment.id
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

    /**
     * Chybějící registrace OSOBY u ČSSZ a u zdravotní pojišťovny.
     *
     * Mezera se pozná ze DVOU nezávislých pramenů a hlásí se, jen když mlčí
     * oba: položka nástupního checklistu je pořád „nevyřízeno" **a** k vztahu
     * neexistuje evidovaná povinnost podání. Přihláška se totiž mohla podat
     * mimo aplikaci — pak ji obsluha odklikne v checklistu a varování zmizí.
     * Položku z doby před začátkem vedení mezd v MyÚčtu vyřídil předchozí
     * program ({@see PayrollPredecessorObligationScope}) a nehlásí se.
     *
     * Vztah, který položku checklistu vůbec nemá (převzatá legacy projekce,
     * data z doby před životním cyklem), se ZÁMĚRNĚ nehlásí. O takovém vztahu
     * aplikace neví, jestli přihláška existuje, a varovat „nevím" u každého
     * převzatého zaměstnance je přesně ten planý poplach, kvůli kterému si
     * účetní na hlášku zvykne a přestane ji číst.
     *
     * @param list<int> $employmentIds
     * @return array<int,array{social:bool,health:bool}>
     */
    private function personRegistrationGaps(
        int $supplierId,
        array $employmentIds,
    ): array {
        if ($employmentIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $statement = $this->db->pdo()->prepare(
            'SELECT employment.id AS employment_id,
                    EXISTS (
                      SELECT 1 FROM payroll_employment_checklist_items item
                       WHERE item.supplier_id = employment.supplier_id
                         AND item.employment_id = employment.id
                         AND item.phase = \'onboarding\'
                         AND item.item_key = \'social_jmhz_registration\'
                         AND item.status = \'pending\'
                         AND NOT ' . PayrollPredecessorObligationScope::sql('item') . '
                    ) AS social_pending,
                    EXISTS (
                      SELECT 1 FROM payroll_employment_checklist_items item
                       WHERE item.supplier_id = employment.supplier_id
                         AND item.employment_id = employment.id
                         AND item.phase = \'onboarding\'
                         AND item.item_key = \'health_insurance_registration\'
                         AND item.status = \'pending\'
                         AND NOT ' . PayrollPredecessorObligationScope::sql('item') . '
                    ) AS health_pending,
                    EXISTS (
                      SELECT 1 FROM payroll_obligations obligation
                       WHERE obligation.supplier_id = employment.supplier_id
                         AND obligation.source_event_type
                               = \'payroll_employment_registration\'
                         AND obligation.source_event_reference
                               = CONCAT(\'payroll_employment:\', employment.id)
                         AND obligation.status <> \'cancelled\'
                    ) AS social_submitted,
                    EXISTS (
                      SELECT 1 FROM payroll_obligations obligation
                       WHERE obligation.supplier_id = employment.supplier_id
                         AND obligation.source_event_type
                               = \'payroll_health_notification\'
                         AND obligation.source_event_reference LIKE CONCAT(
                               \'payroll_health_notification:\',
                               employment.id,
                               \':employment_start:%\'
                             )
                         AND obligation.status <> \'cancelled\'
                    ) AS health_submitted
               FROM payroll_employments employment
              WHERE employment.supplier_id = ?
                AND employment.is_legacy_projection = 0
                AND employment.id IN (' . $placeholders . ')'
        );
        $statement->execute([$supplierId, ...$employmentIds]);
        $gaps = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $gaps[(int) $row['employment_id']] = [
                'social' => (bool) $row['social_pending']
                    && !(bool) $row['social_submitted'],
                'health' => (bool) $row['health_pending']
                    && !(bool) $row['health_submitted'],
            ];
        }

        return $gaps;
    }

    /**
     * Varování o chybějící přihlášce osoby — vědomě VAROVÁNÍ s overridem,
     * ne blokátor. Přihláška se mohla podat mimo aplikaci a mzda se musí dát
     * spočítat i tak; jen ne mlčky.
     *
     * Koho se to týká, rozhoduje ÚČAST NA POJIŠTĚNÍ, ne druh vztahu sám:
     * `included` se hlásí vždy, `excluded` a `foreign` (cizinec pod cizí
     * legislativou s A1) nikdy. `automatic` znamená „rozhodne výpočet podle
     * prahu příjmu" — u DPP se proto mlčí, protože DPP pod rozhodným
     * příjmem se u ČSSZ nehlásí a hlásit ji „pro jistotu" by bylo varování
     * u každé brigády; DPČ a další vztahy malého rozsahu se sjednaným příjmem
     * nad rozhodným příjmem výpočet pojistí vždy, takže se hlásí. Pravidlo drží
     * {@see PayrollExpectedParticipation}. Zdravotní pojištění má vlastní
     * pravidlo účasti ({@see PayrollExpectedHealthParticipation}) — totéž, ze
     * kterého vzniká oznámení pojišťovně: zaměstnání malého rozsahu a jednatel
     * s odměnou pod rozhodným příjmem jsou zaměstnanci pro ZP, i když pro ČSSZ ne.
     *
     * @param array<string,mixed> $row
     * @param array{social:bool,health:bool}|null $gap
     * @return list<PayrollRunValidation>
     */
    private function personRegistrationValidations(
        array $row,
        int $employmentId,
        int $employeeId,
        ?array $gap,
        string $periodEnd,
    ): array {
        if ($gap === null || (!$gap['social'] && !$gap['health'])) {
            return [];
        }
        if (in_array(
            (string) $row['employment_status'],
            ['no_show', 'archived'],
            true,
        )) {
            return [];
        }
        // Vztah, který v období ještě nezačal, přihlášku dlužit nemůže.
        $startedOn = $row['actual_start_date'] ?? $row['start_date'];
        if ($startedOn === null || (string) $startedOn > $periodEnd) {
            return [];
        }
        $relationType = (string) $row['relation_type'];
        $validations = [];
        if ($gap['social'] && $this->participatesInLevy(
            $row['social_insurance_participation'],
            $relationType,
            $row,
            $periodEnd,
        )) {
            $validations[] = new PayrollRunValidation(
                'warning',
                'employment_social_registration_missing',
                'employment',
                $employmentId,
                sprintf(
                    '%s: k pracovnímu vztahu chybí přihláška u ČSSZ '
                    . '(nemocenské pojištění). Podejte ji v Mzdy → Podání → '
                    . 'Registrace zaměstnance, nebo — byla-li podána mimo '
                    . 'aplikaci — odškrtněte v checklistu vztahu položku '
                    . '„Registrace ČSSZ / JMHZ".',
                    (string) $row['full_name'],
                ),
                "/payroll/people?person={$employeeId}&employment={$employmentId}&panel=employment_checklist&field=social_jmhz_registration",
                true,
            );
        }
        if ($gap['health'] && $this->participatesInHealthInsurance(
            $row['health_insurance_participation'],
            $relationType,
            $row,
            $periodEnd,
        )) {
            $validations[] = new PayrollRunValidation(
                'warning',
                'employment_health_registration_missing',
                'employment',
                $employmentId,
                sprintf(
                    '%s: k pracovnímu vztahu chybí oznámení nástupu zdravotní '
                    . 'pojišťovně. Podejte je v Mzdy → Podání → Zdravotní '
                    . 'pojišťovny, nebo — bylo-li podáno mimo aplikaci — '
                    . 'odškrtněte v checklistu vztahu položku „Registrace '
                    . 'zdravotní pojišťovny".',
                    (string) $row['full_name'],
                ),
                "/payroll/people?person={$employeeId}&employment={$employmentId}&panel=employment_checklist&field=health_insurance_registration",
                true,
            );
        }

        return $validations;
    }

    /**
     * Stejné pravidlo jako oznámení nástupu zdravotní pojišťovně:
     * {@see PayrollExpectedHealthParticipation}. Výsledek běhu se tu ještě
     * nezná, takže DPP mlčí a DPČ se hlásí jen podle sjednané odměny.
     *
     * @param array<string,mixed> $row
     */
    private function participatesInHealthInsurance(
        mixed $participation,
        string $relationType,
        array $row,
        string $periodEnd,
    ): bool {
        return PayrollExpectedHealthParticipation::expected(
            is_string($participation) ? $participation : null,
            $relationType,
            ($row['monthly_gross_minor'] ?? null) === null ? null : (int) $row['monthly_gross_minor'],
            PayrollExpectedHealthParticipation::dpcThreshold($this->rulesets, $periodEnd),
            associationMember: (int) ($row['health_association_member'] ?? 0) === 1,
            activityCode: is_string($row['activity_code'] ?? null) ? $row['activity_code'] : null,
        );
    }

    /**
     * Účast na sociálním pojištění: {@see PayrollExpectedParticipation}.
     *
     * @param array<string,mixed> $row
     */
    private function participatesInLevy(
        mixed $participation,
        string $relationType,
        array $row,
        string $periodEnd,
    ): bool {
        return PayrollExpectedParticipation::expected(
            is_string($participation) ? $participation : null,
            $relationType,
            ($row['monthly_gross_minor'] ?? null) === null ? null : (int) $row['monthly_gross_minor'],
            PayrollExpectedParticipation::smallScaleThreshold($this->rulesets, $periodEnd),
        );
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
     * Věková podmínka důvodu slevy (§ 7a odst. 1 písm. a, d, g) pro tenhle
     * vztah a měsíc - viz {@see SocialPartTimeDiscountAgeCondition}.
     *
     * @param array<string,mixed> $row
     */
    private function discountAgeCondition(
        array $row,
        string $periodStart,
        string $periodEnd,
    ): string {
        $from = $row['actual_start_date'] ?? $row['start_date'] ?? null;
        $to = $row['end_date'] ?? null;
        $birthDate = $row['employee_birth_date'] ?? null;

        return SocialPartTimeDiscountAgeCondition::forMonth(
            $row['social_part_time_discount_reason'] ?? null,
            is_string($birthDate) && $birthDate !== '' ? $birthDate : null,
            $periodStart,
            $periodEnd,
            is_string($from) && $from !== '' ? $from : null,
            is_string($to) && $to !== '' ? $to : null,
        );
    }

    /**
     * Nálezy ke slevě zaměstnavatele podle § 7a. Jdou do VALIDACÍ, ne do
     * `$data` — kanonický snapshot a tím i `input_hash` proto zůstávají beze
     * změny a přepočet starší revize dá bit po bitu tentýž vstup jako předtím.
     *
     * Tři věci, o kterých se uživatel jinak nedozví:
     *
     * 1. **Chybějící přijatý záměr.** Sleva se bez něj podle § 7a odst. 5
     *    neuplatní. Ve výsledku běhu je to jen „nedoložený nárok"; odsud se
     *    dozví, KTERÉ podání chybí.
     * 2. **Přechodné pravidlo pro 01–03/2026.** Kontroly 164, 290 a 333 vážou
     *    slevu za tahle období na 30. 6. 2026 a všechny tři jsou v evaluátoru
     *    `NotEvaluable`, protože potřebují datum přijetí podání od ČSSZ.
     *    Uživatel tedy z kontrol nedostane varování žádné.
     *
     * 3. **Neevidované poučení zaměstnance.** § 23d odst. 2 ukládá zaměstnavateli
     *    informovat zaměstnance písemně PŘED prvním uplatněním slevy; porušení
     *    je přestupek (§ 25d odst. 1 písm. j). Datum poučení je u záměru
     *    nepovinné, takže bez tohoto upozornění by chybějící poučení nikdo
     *    nezaznamenal. U záměru převzatého z předchozího programu se nehlásí:
     *    poučení tehdy evidoval ten program a MyÚčto o něm nemá co vědět.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $intent
     * @param array<string,mixed>|null $information `intent_from`,
     *     `employee_informed_on` a `predecessor_source` téhož záměru
     * @return list<PayrollRunValidation>
     */
    private function discountValidations(
        array $row,
        ?array $intent,
        int $employmentId,
        int $employeeId,
        string $periodStart,
        ?array $information = null,
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
        if ($intent !== null && $information !== null
            && ($information['predecessor_source'] ?? null) === null
        ) {
            $informedOn = $information['employee_informed_on'] ?? null;
            $intentFrom = (string) ($information['intent_from'] ?? '');
            $firstMonthEnd = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $intentFrom) === 1
                ? date('Y-m-t', (int) strtotime($intentFrom))
                : null;
            $missing = !is_string($informedOn) || $informedOn === '';
            if ($firstMonthEnd !== null
                && ($missing || (string) $informedOn > $firstMonthEnd)
            ) {
                $validations[] = new PayrollRunValidation(
                    'warning',
                    'part_time_discount_employee_not_informed',
                    'employment',
                    $employmentId,
                    $missing
                        ? 'U záměru uplatňovat slevu na pojistném není zapsané písemné poučení zaměstnance. Zaměstnavatel ho musí informovat před prvním uplatněním slevy (§ 23d odst. 2 zákona č. 589/1992 Sb.), jinak se dopouští přestupku (§ 25d odst. 1 písm. j).'
                        : 'Zaměstnanec byl o uplatňování slevy na pojistném poučen až po prvním měsíci jejího uplatnění. Zaměstnavatel ho musí informovat písemně před prvním uplatněním slevy (§ 23d odst. 2 zákona č. 589/1992 Sb.).',
                    '/payroll/submissions',
                    false,
                );
            }
        }
        $ageCondition = $this->discountAgeCondition(
            $row,
            $periodStart,
            date('Y-m-t', (int) strtotime($periodStart)),
        );
        if ($ageCondition === SocialPartTimeDiscountAgeCondition::NOT_MET
            || $ageCondition === SocialPartTimeDiscountAgeCondition::UNKNOWN
        ) {
            $validations[] = new PayrollRunValidation(
                'warning',
                'part_time_discount_age_condition',
                'employment',
                $employmentId,
                $ageCondition === SocialPartTimeDiscountAgeCondition::UNKNOWN
                    ? 'Důvod slevy na pojistném má věkovou hranici (§ 7a odst. 1 písm. a, d nebo g), ale zaměstnanec nemá vyplněné datum narození, takže ji nejde ověřit. Sleva se proto neuplatnila.'
                    : 'Zaměstnanec za tenhle měsíc nesplňuje věkovou podmínku zvoleného důvodu slevy na pojistném (§ 7a odst. 1 písm. a, d nebo g; podle § 7b odst. 4 musí platit po celou dobu zaměstnání v měsíci). Sleva se neuplatnila.',
                "/payroll/people?person={$employeeId}&employment={$employmentId}&panel=employment_terms&field=social_part_time_discount_reason",
                false,
            );
        }
        if ((new OzuspojClaimDeadlinePolicy())->isTransitionalQ12026($periodStart)) {
            $validations[] = new PayrollRunValidation(
                'warning',
                'part_time_discount_transitional_window',
                'employment',
                $employmentId,
                'Slevu na pojistném za leden až březen 2026 bylo nutné vykázat v měsíčním hlášení do 30. 6. 2026. Po tomhle dni ji ČSSZ neuzná (kontrola 333) a odečtené pojistné se stane dluhem podle § 7c odst. 3.',
                "/payroll/people?person={$employeeId}&employment={$employmentId}&panel=employment_terms&field=social_part_time_discount_reason",
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
     * Doručený, ale nepřevedený exekuční případ zastaví schválení běhu.
     *
     * Srážet se má ode dne doručení plátci mzdy (§ 282 odst. 3 o. s. ř.), jenže
     * případ ve stavu `received` do výpočtu nevstupuje — evidence pohledávek
     * bere jen případy, které účetní převedla dál. Bez tohohle varování se
     * zaměstnanci vyplatila celá mzda a nikdo se to nedozvěděl.
     *
     * Je to varování s POVINNÝM potvrzením, ne blokátor: příkaz může přijít
     * den před výplatou, kdy podklady k ověření ještě nejsou, a běh se musí dát
     * vědomě dokončit s tím, že se srážka dorovná příští měsíc. Tiše už ale ne.
     * Do `$data` nevstupuje, takže `input_hash` se nemění.
     *
     * @param list<int> $employeeIds
     * @return list<PayrollRunValidation>
     */
    private function receivedEnforcementValidations(
        int $supplierId,
        array $employeeIds,
        string $paymentDate,
    ): array {
        if (!$this->enforcement instanceof PayrollEnforcementRepository || $employeeIds === []) {
            return [];
        }
        $validations = [];
        foreach ($this->enforcement->receivedCaseIdsForMany(
            $supplierId,
            $employeeIds,
            $paymentDate,
        ) as $employeeId => $caseIds) {
            foreach ($caseIds as $caseId) {
                $validations[] = new PayrollRunValidation(
                    'warning',
                    'enforcement_case_received',
                    'employee',
                    $employeeId,
                    'Exekuční případ je zaevidovaný jako doručený, ale ještě se'
                    . ' nesráží. Srážet se má ode dne doručení plátci mzdy (§ 282'
                    . ' odst. 3 o. s. ř.) — doplňte a ověřte podklady a převeďte'
                    . ' případ do srážení, jinak se v tomto běhu nesrazí nic.',
                    "/payroll/enforcement?person={$employeeId}&case={$caseId}",
                    true,
                );
            }
        }

        return $validations;
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
        /*
         * Zdravotní pojištění je ve snímku, ale NENÍ podmínkou výpočtu: nemá
         * roční strop ani roční slevu, takže z jeho kumulace do měsíce nic
         * nevstupuje. Assembler ho proto nečte a osoba bez openingu ZP na něm
         * běh neshodí — jinak by chybějící počáteční stav ZP zablokoval každou
         * firmu, která mzdy vedla už před jeho zavedením.
         */
        $kinds = ['social_insurance', 'health_insurance', 'income_tax'];
        // Všechny druhy kumulace jednou dávkou: druh se liší jedinou hodnotou ve
        // WHERE, takže volání po jednom platilo tytéž tři dotazy znovu.
        $states = $this->statutoryAccumulators?->statesBeforePeriodByKind(
            $supplierId,
            $employeeIds,
            $year,
            $periodStart,
            $kinds,
        ) ?? array_fill_keys($kinds, []);

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
            // Nenastavená nepovinná předkontace se do snapshotu nezmrazí —
            // zaúčtování ji pak nevidí a účtuje přesně jako dřív (viz
            // PayrollAccountingDefaults::NULLABLE_ACCOUNTS a SNAPSHOT_GATED_ACCOUNTS).
            if (is_string($key) && $account === ''
                && PayrollAccountingDefaults::isNullable($key)
            ) {
                continue;
            }
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
            /*
             * v3 nese navíc hodiny nepřítomností bez atributu hlášení, v4 k nim
             * ještě rozpad odpracované doby na dny a přesčas. Verze se
             * NESLUČUJÍ: obsahový otisk se počítá z kanonického JSONu hodnot,
             * takže dřív zmrazený v2 souhrn musí i dál vydat přesně tentýž
             * výčet klíčů, jaký měl při schválení.
             */
            $conditionalVersions = PayrollJmhzWorkMonthSummaryBuilder::CONDITIONAL_VERSIONS;
            if (in_array(
                $derivationVersion,
                PayrollJmhzWorkMonthSummaryBuilder::VERSIONS_WITH_WORKED_BREAKDOWN,
                true,
            )) {
                // Dny jsou u v4 a v5 vždy vyplněné (CHECK), u v6 ze souhrnu
                // importu smí být NEUVEDENÉ; přesčas smí chybět u všech.
                $values['worked_days'] = $row['worked_days'] === null ? null : (int) $row['worked_days'];
                $values['overtime_millihours'] = $row['overtime_millihours'] === null
                    ? null
                    : (int) $row['overtime_millihours'];
            }
            if (in_array($derivationVersion, $conditionalVersions, true)) {
                if (!$conditionalBlocksConfirmed
                    || !in_array($row['unworked_hours_occurred'], [0, 1, '0', '1'], true)
                    || !in_array($row['work_obstacles_occurred'], [0, 1, '0', '1'], true)
                ) {
                    throw new \DomainException(
                        'Podmíněné bloky pracovního souhrnu JMHZ nejsou potvrzené.',
                    );
                }
                $conditionalFields = [
                    'unworked_total_millihours',
                    'unworked_paid_millihours',
                    'dpn_without_employer_compensation_millihours',
                    'dpn_with_employer_compensation_millihours',
                    'vacation_millihours',
                    'care_millihours',
                    'employee_obstacle_paid_millihours',
                    'employer_obstacle_millihours',
                ];
                if (in_array(
                    $derivationVersion,
                    PayrollJmhzWorkMonthSummaryBuilder::VERSIONS_WITH_LOCAL_EVIDENCE,
                    true,
                )) {
                    $conditionalFields = array_merge(
                        $conditionalFields,
                        PayrollJmhzWorkMonthSummaryBuilder::localEvidenceFields(),
                    );
                }
                if (in_array(
                    $derivationVersion,
                    PayrollJmhzWorkMonthSummaryBuilder::VERSIONS_WITH_COMPENSATORY_TIME_OFF,
                    true,
                )) {
                    $conditionalFields = array_merge(
                        $conditionalFields,
                        PayrollJmhzWorkMonthSummaryBuilder::compensatoryTimeOffFields(),
                    );
                }
                if (in_array(
                    $derivationVersion,
                    PayrollJmhzWorkMonthSummaryBuilder::VERSIONS_WITH_HOLIDAYS,
                    true,
                )) {
                    $conditionalFields = array_merge(
                        $conditionalFields,
                        PayrollJmhzWorkMonthSummaryBuilder::holidayFields(),
                    );
                }
                if (in_array(
                    $derivationVersion,
                    PayrollJmhzWorkMonthSummaryBuilder::VERSIONS_WITH_UNPAID_HOLIDAYS,
                    true,
                )) {
                    $conditionalFields = array_merge(
                        $conditionalFields,
                        PayrollJmhzWorkMonthSummaryBuilder::unpaidHolidayFields(),
                    );
                }
                foreach ($conditionalFields as $field) {
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
            if (in_array($derivationVersion, $conditionalVersions, true)) {
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
                : (in_array(
                    (string) $summary['derivation_version'],
                    PayrollJmhzWorkMonthSummaryBuilder::CONDITIONAL_VERSIONS,
                    true,
                )
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
    private function dimensions(array $rows, int $employmentId = 0): array
    {
        $result = [];
        /** @var array<string,array{count:int,total:int}> $shares typ → součet podílů */
        $shares = [];
        foreach ($rows as $row) {
            $account = $row['default_account_code'];
            $dimension = [
                'type' => (string) $row['dimension_type'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'default_account_code' => $account === null ? null : (string) $account,
            ];
            // Podíl a vazba na firemní dimenzi vstupují jen tam, kde jsou
            // nastavené. Vztah se 100 % a bez vazby tak má otisk shodný
            // s dosavadním snapshotem a zaúčtuje se bajtově stejně.
            $shareBp = self::shareBasisPoints($row['share_percent'] ?? null);
            if ($shareBp !== 10_000) {
                $dimension['share_bp'] = $shareBp;
            }
            $typeId = $row['company_dimension_type_id'] ?? null;
            $valueId = $row['company_dimension_value_id'] ?? null;
            if ($typeId !== null && $valueId !== null) {
                $dimension['dimension_type_id'] = (int) $typeId;
                $dimension['dimension_value_id'] = (int) $valueId;
            }
            $shares[$dimension['type']] ??= ['count' => 0, 'total' => 0];
            $shares[$dimension['type']]['count']++;
            $shares[$dimension['type']]['total'] += $shareBp;
            $result[] = $dimension;
        }
        // Rozpad, ze kterého k začátku období vypadla část (deaktivovaná nebo
        // neúčinná dimenze), se nesmí zmrazit: účetní můstek by ho odmítl až po
        // schválení běhu. Zastaví se tady, kdy se dá ještě opravit.
        foreach ($shares as $type => $share) {
            if ($share['total'] !== 10_000) {
                throw new \DomainException(sprintf(
                    'Procentní rozpad mzdových dimenzí typu %s u pracovního vztahu %d dává k začátku '
                    . 'období %s %% místo 100 %%. Upravte rozpad na kartě pracovního vztahu.',
                    $type,
                    $employmentId,
                    number_format($share['total'] / 100, 2, ',', ' '),
                ));
            }
        }
        return $result;
    }

    /** Podíl v setinách procenta (10 000 = 100 %); chybějící sloupec = 100 %. */
    private static function shareBasisPoints(mixed $share): int
    {
        if ($share === null) {
            return 10_000;
        }
        $text = is_string($share) ? $share : (string) $share;
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $text, $match) !== 1) {
            throw new \UnexpectedValueException("Podíl dimenze {$text} není platné procento.");
        }
        $bp = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '0', 2, '0');
        if ($bp <= 0 || $bp > 10_000) {
            throw new \UnexpectedValueException("Podíl dimenze {$text} je mimo rozsah 0–100 %.");
        }

        return $bp;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function absences(array $rows): array
    {
        return array_map(
            /*
             * Dny porodu nese jen PPM, protože jen z nich evidenční list
             * odvozuje předporodní část (10359). U ostatních druhů by klíče
             * byly vždy null a jen by přepsaly otisk každého zmrazeného vstupu.
             */
            static fn (array $row): array => [
                ...(($row['absence_type'] ?? null) === 'ppm' ? [
                    'expected_childbirth_date' => $row['expected_childbirth_date'] ?? null,
                    'childbirth_date' => $row['childbirth_date'] ?? null,
                ] : []),
                /*
                 * Nemoc nese okno náhrady mzdy a potvrzení nároku na nemocenské
                 * ze schváleného výpočtu náhrady; bez výpočtu klíče chybí
                 * a vyloučené dny § 18 odst. 7 se nerozhodnou. Ošetřování nese
                 * příznak osamělého zaměstnance (podpůrčí doba 16 dnů). Jen
                 * u těchto druhů, stejně jako dny porodu, ať se nemění otisk
                 * ostatních.
                 */
                ...(in_array($row['absence_type'] ?? null, ['dpn', 'quarantine'], true)
                    && ($row['compensation_window_from'] ?? null) !== null ? [
                        'compensation_window_from' => (string) $row['compensation_window_from'],
                        'compensation_window_to' => (string) $row['compensation_window_to'],
                        'insurance_eligibility_confirmed' => (int) $row['insurance_eligibility_confirmed'] === 1,
                    ] : []),
                ...(($row['absence_type'] ?? null) === 'ocr' ? [
                    'lone_carer' => (int) ($row['lone_carer'] ?? 0) === 1,
                ] : []),
                /*
                 * Placená překážka nese druh a sazbu náhrady: z nich běh pozná,
                 * že vyměřovací základ snížila překážka na straně zaměstnavatele,
                 * a doplatek do minima ZP pak hradí zaměstnavatel (§ 3 odst. 10
                 * zákona č. 592/1992 Sb.). Jen u překážky s druhem, ať se nemění
                 * otisk ostatních ani starších překážek.
                 */
                ...(($row['obstacle_kind'] ?? null) !== null ? [
                    'obstacle_kind' => (string) $row['obstacle_kind'],
                    'compensation_rate_basis_points' => (int) $row['compensation_rate_basis_points'],
                ] : []),
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
                // Den doručení dohody plátci mzdy (§ 2045 odst. 2 OZ). Ve
                // zmrazeném snímku je proto, že z něj plyne POŘADÍ dohody vůči
                // exekucím podle § 280 odst. 5 o. s. ř. — bez něj by se běh
                // nedal přepočítat na tentýž rozvrh. `null` = legacy dohoda
                // zaevidovaná dřív, než se datum ukládalo.
                'delivered_on' => $row['delivered_on'] ?? null,
                'row_version' => (int) $row['row_version'],
                // Srážka ze zákona (§ 147 odst. 1 písm. c) až e) ZP), ne dohoda.
                // Jen tam, kde to platí — snímky dohod zůstávají bajtově stejné.
                ...((($row['legal_basis'] ?? 'agreement') === 'agreement')
                    ? []
                    : ['legal_basis' => (string) $row['legal_basis']]),
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
