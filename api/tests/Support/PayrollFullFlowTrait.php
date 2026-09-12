<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Action\Payroll\PayrollAbsenceAction;
use MyInvoice\Action\Payroll\PayrollJmhzPreparationAction;
use MyInvoice\Action\Payroll\PayrollJmhzXmlDryRunAction;
use MyInvoice\Action\Payroll\PayrollTimeAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Service\Payroll\Posting\PayrollApprovedRevisionPostingService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCalculationPipeline;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandResult;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunWorkflow;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;
use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Znovupoužitelný syntetický mzdový řez nad izolovanou firmou v transakci.
 *
 * Firma se zapnutými mzdami a jednou účetní, účtárna ČSSZ, výstupy pojistného,
 * zaměstnanci s úplnou zákonnou evidencí, mzdové složky, schválené vstupy,
 * absence a docházka jdou přes produkční akce a služby z kontejneru, stejně
 * jako mzdový běh a měsíční hlášení JMHZ až po testovací dry-run. Transakci
 * vrací {@see self::tearDownPayrollFullFlow()}, takže v databázi nic nezůstane.
 *
 * Třída testu volá v `setUp()` {@see self::bootPayrollFullFlow()} a
 * v `tearDown()` {@see self::tearDownPayrollFullFlow()}.
 */
trait PayrollFullFlowTrait
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ContainerInterface $container;
    private PayrollRunCommandService $runs;
    private PayrollAbsenceAction $absences;
    private PayrollTimeAction $time;
    private int $supplierId;
    /** @var list<int> */
    private array $actors = [];
    /** @var array<int,true> */
    private array $calendarEmployments = [];

    private function bootPayrollFullFlow(): void
    {
        $this->container = Bootstrap::buildContainer();
        $db = $this->container->get(Connection::class);
        $absences = $this->container->get(PayrollAbsenceAction::class);
        $time = $this->container->get(PayrollTimeAction::class);
        if (!$db instanceof Connection
            || !$absences instanceof PayrollAbsenceAction
            || !$time instanceof PayrollTimeAction
        ) {
            throw new \RuntimeException('Služby syntetického mzdového toku nejsou dostupné.');
        }
        $this->db = $db;
        $this->absences = $absences;
        $this->time = $time;
        foreach ([
            'payroll_runs',
            'payroll_run_revisions',
            'payroll_inputs',
            'payroll_absences',
            'payroll_statutory_results',
        ] as $table) {
            if (!$db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }

        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            "UPDATE supplier
                SET payroll_enabled = 1,
                    accounting_mode = 'double_entry',
                    company_name = 'Syntetický zaměstnavatel',
                    display_name = 'Syntetický zaměstnavatel',
                    ic = '00000019',
                    street = 'Zkušební',
                    street_number_pop = '12',
                    zip = '110 00',
                    city = 'Praha 1',
                    phone = '+420111222333'
              WHERE id = ?",
        )->execute([$this->supplierId]);

        $this->actors = [$this->createActor('accountant')];
        $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->actors[0]]);
        $policy = $this->container->get(PayrollEmployerPolicyRepository::class);
        if (!$policy instanceof PayrollEmployerPolicyRepository) {
            throw new \RuntimeException('Politika zaměstnavatele není dostupná.');
        }
        $policy->create($this->supplierId, $this->employerPolicy(), $this->actors[0]);

        $runRepository = $this->container->get(PayrollRunRepository::class);
        if (!$runRepository instanceof PayrollRunRepository) {
            throw new \RuntimeException('Repository mzdového běhu není dostupné.');
        }
        $posting = $this->container->get(PayrollApprovedRevisionPostingService::class);
        if (!$posting instanceof PayrollApprovedRevisionPostingService) {
            throw new \RuntimeException('Služba zaúčtování schválené revize není dostupná.');
        }
        $this->runs = new PayrollRunCommandService(
            $db,
            $runRepository,
            $this->container->get(PayrollRunSnapshotBuilder::class),
            $this->container->get(PayrollRunCalculationPipeline::class),
            $this->container->get(PayrollRunWorkflow::class),
            $this->container->get(PayrollPeriodOwnershipService::class),
            $posting,
        );
    }

    private function tearDownPayrollFullFlow(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /** @return array<string,mixed> */
    private function healthResultSnapshot(int $revisionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT person.result_snapshot_json
               FROM payroll_statutory_person_results person
               JOIN payroll_statutory_results result
                 ON result.supplier_id = person.supplier_id
                AND result.id = person.statutory_result_id
              WHERE result.supplier_id = ? AND result.revision_id = ?
                AND result.calculation_kind = "health_insurance"'
        );
        $stmt->execute([$this->supplierId, $revisionId]);
        $snapshot = json_decode((string) $stmt->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($snapshot);
        return $snapshot;
    }

    private function createOffice(
        string $code = 'FLOW',
        string $name = 'Syntetická účtárna',
        string $variableSymbol = '1234567890',
    ): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, ?, ?, ?, 1)',
        )->execute([$this->supplierId, $code, $name, $variableSymbol]);
        $officeId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from,
                 social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2026-01-01", ?, "synthetic:full-flow")',
        )->execute([$this->supplierId, $officeId, $variableSymbol]);
        return $officeId;
    }

    private function configureSocialInsuranceOutput(int $officeId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "P")',
        )->execute([$this->supplierId, $officeId]);
        $accounts = $this->container->get(PayrollInstitutionAccountRepository::class);
        if (!$accounts instanceof PayrollInstitutionAccountRepository) {
            throw new \RuntimeException('Evidence účtů institucí není dostupná.');
        }
        $accounts->create($this->supplierId, [
            'institution_type' => 'social_security',
            'institution_code' => 'P',
            'institution_name' => 'Syntetická správa sociálního zabezpečení',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => null,
            'specific_symbol' => null,
            'constant_symbol' => '7618',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:full-flow-cssz-account',
            'verified_on' => '2026-06-15',
        ], $this->actors[0]);
    }

    private function configureHealthInsuranceOutput(): void
    {
        $accounts = $this->container->get(PayrollInstitutionAccountRepository::class);
        if (!$accounts instanceof PayrollInstitutionAccountRepository) {
            throw new \RuntimeException('Evidence účtů institucí není dostupná.');
        }
        $accounts->create($this->supplierId, [
            'institution_type' => 'health_insurer',
            'institution_code' => '111',
            'institution_name' => 'Syntetická zdravotní pojišťovna',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => '0000001900',
            'specific_symbol' => null,
            'constant_symbol' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:full-flow-health-account',
            'verified_on' => '2026-06-15',
        ], $this->actors[0]);
    }

    /**
     * Ověřené účty finančního úřadu pro zálohovou i srážkovou daň — bez nich
     * materializér daňových závazků odmítne vytvořit platební cíl.
     */
    private function configureIncomeTaxOutput(): void
    {
        $accounts = $this->container->get(PayrollInstitutionAccountRepository::class);
        if (!$accounts instanceof PayrollInstitutionAccountRepository) {
            throw new \RuntimeException('Evidence účtů institucí není dostupná.');
        }
        foreach ([
            'advance_tax' => ['1001', '1148'],
            'withholding_tax' => ['7720', '1148'],
        ] as $kind => [$specificSymbol, $constantSymbol]) {
            $accounts->create($this->supplierId, [
                'institution_type' => 'tax_office',
                'institution_code' => $kind,
                'institution_name' => 'Syntetický finanční úřad',
                'bank_account' => '1000000005/0100',
                'currency_code' => 'CZK',
                'variable_symbol' => '0000001900',
                'specific_symbol' => $specificSymbol,
                'constant_symbol' => $constantSymbol,
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'source_kind' => 'official_document',
                'source_reference' => "synthetic:full-flow-tax-account:{$kind}",
                'verified_on' => '2026-06-15',
            ], $this->actors[0]);
        }
    }

    /**
     * Zaměstnanec s pracovním vztahem a úplnou zákonnou evidencí.
     *
     * S `$existingEmployeeId` se osoba nezakládá znovu a vztah vznikne jako
     * další, neprimární vztah téže osoby (souběh).
     *
     * @return array{employee_id:int,employment_id:int,name:string}
     */
    private function createEmployment(
        int $officeId,
        string $name,
        int $sequence,
        string $employmentType,
        string $relationType,
        int $weeklyHours,
        int $workloadBasisPoints,
        bool $taxDeclarationSigned = true,
        string $periodStart = '2026-06-01',
        bool $withHealthMonthEvidence = true,
        string $taxpayerType = 'employee',
        ?bool $taxpayerCreditTaxpayer = null,
        ?string $otherWithholdingEligibility = null,
        string $taxRegime = 'advance',
        string $socialDiscountStatus = 'not_claimed',
        ?int $existingEmployeeId = null,
        string $healthTopUpResponsibility = 'employer_obstacle_verified',
    ): array {
        $pdo = $this->db->pdo();
        $primary = $existingEmployeeId === null;
        if ($primary) {
            $taxpayerCreditTaxpayer ??= $taxDeclarationSigned;
            $pdo->prepare(
                'INSERT INTO payroll_employees
                    (supplier_id, full_name, taxpayer_type, employment_type,
                     tax_declaration_signed, tax_credit_taxpayer, child_count,
                     monthly_gross, auto_post, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 1)',
            )->execute([
                $this->supplierId,
                $name,
                $taxpayerType,
                $employmentType,
                $taxDeclarationSigned ? 1 : 0,
                $taxpayerCreditTaxpayer ? 1 : 0,
            ]);
            $employeeId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO payroll_employee_profiles
                    (supplier_id, employee_id, profile_status)
                 VALUES (?, ?, "ready")',
            )->execute([$this->supplierId, $employeeId]);
        } else {
            $employeeId = $existingEmployeeId;
        }
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type,
                 status, start_date, actual_start_date, is_primary)
             VALUES (?, ?, ?, ?, ?, "active",
                     "2026-01-01", "2026-01-01", ?)',
        )->execute([
            $this->supplierId,
            $employeeId,
            $officeId,
            "FLOW-{$sequence}",
            $relationType,
            $primary ? 1 : 0,
        ]);
        $employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from,
                 planned_start_on, actual_start_on, weekly_hours,
                 workload_basis_points, social_insurance_participation,
                 health_insurance_participation, tax_regime,
                 tax_declaration_signed, is_primary)
             VALUES (?, ?, ?, "2026-01-01", "2026-01-01", "2026-01-01",
                     ?, ?, "automatic", "automatic", ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $employmentId,
            $officeId,
            $weeklyHours,
            $workloadBasisPoints,
            $taxRegime,
            $taxDeclarationSigned ? 1 : 0,
            $primary ? 1 : 0,
        ]);
        if ($otherWithholdingEligibility !== null) {
            $pdo->prepare(
                'UPDATE payroll_employment_terms
                    SET other_withholding_eligibility = ?
                  WHERE supplier_id = ? AND employment_id = ?',
            )->execute([$otherWithholdingEligibility, $this->supplierId, $employmentId]);
        }
        if (!$primary) {
            return ['employee_id' => $employeeId, 'employment_id' => $employmentId, 'name' => $name];
        }
        $evidence = $this->container->get(PayrollPersonStatutoryEvidenceRepository::class);
        if (!$evidence instanceof PayrollPersonStatutoryEvidenceRepository) {
            throw new \RuntimeException('Zákonná evidence osoby není dostupná.');
        }
        $healthEvidenceDocumentId = $this->createHealthEvidenceDocument($sequence);
        $evidence->save(
            $this->supplierId,
            $employeeId,
            $this->statutoryEvidence(
                $periodStart,
                $taxDeclarationSigned,
                $withHealthMonthEvidence,
                $healthEvidenceDocumentId,
                $socialDiscountStatus,
                $healthTopUpResponsibility,
            ),
            date('Y-m-t', strtotime($periodStart)),
            $this->actors[0],
            null,
            'payroll-full-flow-test',
        );
        $this->createOpeningBalances($employeeId, $sequence);
        $pdo->prepare(
            'INSERT INTO payroll_enforcement_person_month_evidence
                (supplier_id, employee_id, period_start,
                 claim_register_evidence_complete, dependants_evidence_complete,
                 spouse_evidence_complete, pension_evidence, updated_by)
             VALUES (?, ?, ?, 1, 1, 1, "none", ?)',
        )->execute([$this->supplierId, $employeeId, $periodStart, $this->actors[0]]);
        return ['employee_id' => $employeeId, 'employment_id' => $employmentId, 'name' => $name];
    }

    private function createComponent(string $code, string $kind, string $frequency): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment,
                 jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code, valid_from)
             VALUES (?, ?, ?, ?, "monetary", ?, "included",
                     "included", "included", "included", "included",
                     "included", "included", "included", "included",
                     "521", "331", "2026-01-01")',
        )->execute([$this->supplierId, $code, "Syntetická {$code}", $kind, $frequency]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function createApprovedInput(array $person, int $componentId, int $amountMinor, string $externalId, string $periodStart = '2026-06-01'): void
    {
        $component = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_component_definitions WHERE supplier_id = ? AND id = ?',
        );
        $component->execute([$this->supplierId, $componentId]);
        $row = $component->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $snapshot = [
            'code' => $row['code'],
            'name' => $row['name'],
            'component_kind' => $row['component_kind'],
            'value_kind' => $row['value_kind'],
            'frequency_kind' => $row['frequency_kind'],
            'tax_treatment' => $row['tax_treatment'],
            'social_participation_treatment' => $row['social_participation_treatment'],
            'social_treatment' => $row['social_treatment'],
            'health_participation_treatment' => $row['health_participation_treatment'],
            'health_treatment' => $row['health_treatment'],
            'average_earning_treatment' => $row['average_earning_treatment'],
            'enforcement_treatment' => $row['enforcement_treatment'],
            'jmhz_treatment' => $row['jmhz_treatment'],
            'statistics_treatment' => $row['statistics_treatment'],
            'accounting_debit_code' => $row['accounting_debit_code'],
            'accounting_credit_code' => $row['accounting_credit_code'],
            'annual_limit_minor' => null,
            'component_id' => $componentId,
            'component_row_version' => 1,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ];
        $json = CanonicalJson::encode($snapshot);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id,
                 period_start, amount_minor, source_kind, external_id, status,
                 component_snapshot_json, component_snapshot_hash,
                 approved_by, approved_at)
             VALUES (?, ?, ?, ?, ?, ?, "manual", ?, "approved",
                     ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $person['employee_id'],
            $person['employment_id'],
            $componentId,
            $periodStart,
            $amountMinor,
            $externalId,
            $json,
            hash('sha256', $json, true),
            $this->actors[0],
        ]);
    }

    /** @return array<string,mixed> */
    private function createApprovedAverage(int $employmentId, int $quarter = 2): array
    {
        $averageResponse = $this->absences->createAverage(
            $this->request('POST', '/api/payroll/absences/average')->withParsedBody([
                'employment_id' => $employmentId,
                'applicable_year' => 2026,
                'applicable_quarter' => $quarter,
                'decisive_from' => $quarter === 3 ? '2026-04-01' : '2026-01-01',
                'decisive_to' => $quarter === 3 ? '2026-06-30' : '2026-03-31',
                'gross_earnings_minor' => 12_000_000,
                'longer_period_allocated_minor' => 0,
                'worked_minutes' => 9_600,
                'worked_days' => 60,
                'probable_hourly_minor' => null,
                'rationale' => null,
            ]),
            new Response(),
        );
        self::assertSame(201, $averageResponse->getStatusCode(), (string) $averageResponse->getBody());
        $average = $this->json($averageResponse)['snapshot'];
        $approvedAverageResponse = $this->absences->approveAverage(
            $this->request('POST', '/api/payroll/absences/average/approve')->withParsedBody([
                'row_version' => $average['row_version'],
            ]),
            new Response(),
            ['id' => (string) $average['id']],
        );
        self::assertSame(200, $approvedAverageResponse->getStatusCode(), (string) $approvedAverageResponse->getBody());

        return $average;
    }

    /**
     * Vyplní údaje vztahu a identitu osoby, které měsíční hlášení vyžaduje.
     *
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @param array{first_name:string,last_name:string,birth_date:string,sex:string,birth_number:string}|null $identity
     *        null = výchozí syntetická osoba; u druhého vztahu téže osoby se identita nezakládá znovu
     */
    private function completeJmhzEmployment(
        array $person,
        ?string $activityCode = null,
        ?array $identity = null,
        bool $withIdentity = true,
    ): void {
        $relationType = (string) $this->scalar(
            'SELECT relation_type FROM payroll_employments WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $person['employment_id']],
        );
        [$defaultActivity, $detailCode] = PayrollEmploymentJmhzActivityFamily::firstRelationDefaults($relationType);
        $activityCode ??= $defaultActivity ?? '1';
        if ($activityCode === 'S') {
            $detailCode = '1';
        }
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET activity_code = ?,
                    jmhz_relationship_detail_code = ?,
                    work_place = "Hlavní město Praha",
                    jmhz_workplace_municipality_code = "554782",
                    jmhz_workplace_country_code = "CZ",
                    jmhz_external_codebook_overlay_key = ?,
                    jmhz_external_codebook_manifest_sha256 = ?,
                    jmhz_apz_contribution_status = "no",
                    jmhz_functional_benefits_status = "no",
                    jmhz_temporary_assignment_status = "no",
                    risky_work = 0
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([
            $activityCode,
            $detailCode,
            JmhzExternalCodebookCatalog::DEFAULT_OVERLAY_KEY,
            JmhzExternalCodebookCatalog::DEFAULT_MANIFEST_SHA256,
            $this->supplierId,
            $person['employment_id'],
        ]);
        if (!$withIdentity) {
            return;
        }
        $identity ??= [
            'first_name' => 'Dana',
            'last_name' => 'Testovací',
            'birth_date' => '1991-02-03',
            'sex' => 'female',
            'birth_number' => '9102030014',
        ];
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 birth_date, birth_place, birth_country_code,
                 citizenship_country_code, sex, effective_from)
             VALUES (?, ?, ?, ?, ?, ?, "Testov", "CZ", "CZ", ?, "2026-01-01")',
        )->execute([
            $this->supplierId,
            $person['employee_id'],
            $person['name'],
            $identity['first_name'],
            $identity['last_name'],
            $identity['birth_date'],
            $identity['sex'],
        ]);
        $this->insertPersonIdentifier($person['employee_id'], 'birth_number', $identity['birth_number']);
    }

    /**
     * Syntetické rodné číslo, které projde kontrolou dělitelnosti jedenácti.
     * Ženám se k měsíci přičítá 50.
     */
    private static function syntheticBirthNumber(string $birthDate, string $sex, int $sequence): string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $birthDate));
        $prefix = sprintf('%02d%02d%02d', $year % 100, $month + ($sex === 'female' ? 50 : 0), $day);
        for ($suffix = $sequence * 7; ; $suffix++) {
            $nine = $prefix . sprintf('%03d', $suffix % 1000);
            for ($digit = 0; $digit <= 9; $digit++) {
                if (((int) ($nine . $digit)) % 11 === 0) {
                    return $nine . $digit;
                }
            }
        }
    }

    /** Syntetické OIČ (10 číslic, poslední = zbytek prvních devíti po dělení 11). */
    private static function syntheticOic(int $sequence): string
    {
        for ($base = 100_000_000 + $sequence * 13; ; $base++) {
            $check = $base % 11;
            if ($check <= 9) {
                return $base . $check;
            }
        }
    }

    /**
     * Pracovní dny měsíce (bez víkendů a státních svátků), volitelně bez
     * vyjmenovaných dnů.
     *
     * @param list<string> $except
     * @return list<string>
     */
    private static function workdays(string $period, array $except = []): array
    {
        $first = new \DateTimeImmutable("{$period}-01");
        $holidays = (new CzechHolidayCalendar())->forYear((int) $first->format('Y'));
        $days = [];
        for ($day = $first; $day->format('Y-m') === $period; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            if ((int) $day->format('N') >= 6
                || array_key_exists($date, $holidays)
                || in_array($date, $except, true)
            ) {
                continue;
            }
            $days[] = $date;
        }

        return $days;
    }

    /**
     * Dny od–do včetně.
     *
     * @return list<string>
     */
    private static function dateRange(string $from, string $to): array
    {
        $days = [];
        for ($day = new \DateTimeImmutable($from); $day->format('Y-m-d') <= $to; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    /**
     * Zveřejněné osmihodinové směny (6:00–14:30 UTC, 30 minut přestávka)
     * na zadané dny. Z nich se počítají hodiny nepřítomnosti.
     *
     * @param list<string> $dates
     */
    private function publishShifts(int $employmentId, array $dates): void
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_shifts
                (supplier_id, employment_id, series_key, starts_at_utc,
                 ends_at_utc, timezone_name, break_minutes, status,
                 published_by, published_at)
             VALUES (?, ?, ?, ?, ?, "Europe/Prague", 30, "published", ?, NOW())',
        );
        foreach ($dates as $date) {
            $statement->execute([
                $this->supplierId,
                $employmentId,
                "flow-{$employmentId}-{$date}",
                "{$date} 06:00:00",
                "{$date} 14:30:00",
                $this->actors[0],
            ]);
        }
    }

    /**
     * Zapíše absenci přes API. Vrací odpověď, aby test mohl zaznamenat i
     * odmítnutí (místo, kde se účetní zasekne).
     *
     * @param array<string,mixed> $extra
     */
    private function requestAbsence(
        int $employmentId,
        string $type,
        string $from,
        string $to,
        ?int $averageId = null,
        array $extra = [],
    ): ResponseInterface {
        return $this->absences->create(
            $this->request('POST', '/api/payroll/absences')->withParsedBody($extra + [
                'employment_id' => $employmentId,
                'absence_type' => $type,
                'date_from' => $from,
                'date_to' => $to,
                'timezone_name' => 'Europe/Prague',
                'partial_first_minutes' => null,
                'partial_last_minutes' => null,
                'average_snapshot_id' => $averageId,
                'note' => 'Syntetická absence full-flow.',
            ]),
            new Response(),
        );
    }

    /**
     * Zapíše a schválí absenci. `$decisionExtra` nese potvrzení, která
     * schválení u daného druhu vyžaduje (DPN: pojištění, souběžná dávka).
     *
     * @param array<string,mixed> $extra
     * @param array<string,mixed> $decisionExtra
     * @return array<string,mixed>
     */
    private function createApprovedAbsence(
        int $employmentId,
        string $type,
        string $from,
        string $to,
        ?int $averageId = null,
        array $extra = [],
        array $decisionExtra = [],
    ): array {
        $created = $this->requestAbsence($employmentId, $type, $from, $to, $averageId, $extra);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $absence = $this->json($created)['absence'];
        $decision = $this->absences->decision(
            $this->request('POST', '/api/payroll/absences/decision')->withParsedBody($decisionExtra + [
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(200, $decision->getStatusCode(), (string) $decision->getBody());

        return $this->json($decision)['absence'] ?? $absence;
    }

    private function createApprovedTimeMonth(int $employmentId, string $period = '2026-06'): void
    {
        $this->ensureRegularCalendar($employmentId);
        $monthVersion = $this->recordWorkedDays($employmentId, ["{$period}-01"], 450);
        $preview = $this->timeMonthPreview($employmentId, $period);
        $approved = $this->time->approve(
            $this->request('POST', "/api/payroll/time/months/{$period}/approve")
                ->withParsedBody([
                    'employment_id' => $employmentId,
                    'row_version' => $monthVersion,
                    'jmhz_work_summary' => [
                        'source_snapshot_sha256' => $preview['source_snapshot_sha256'],
                        'standard_fund_hours' => $preview['suggestions']['agreed_fund_hours'],
                        'agreed_fund_hours' => $preview['suggestions']['agreed_fund_hours'],
                        'weekly_work_hours' => $preview['suggestions']['weekly_work_hours'] ?? '40',
                        'worked_hours' => $preview['suggestions']['worked_hours'],
                        'unworked_hours_occurred' => false,
                        'work_obstacles_occurred' => false,
                        'confirmation_note' => '',
                    ],
                ]),
            new Response(),
            ['period' => $period],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
    }

    /**
     * Docházka měsíce tak, jak ji účetní potvrdí v dialogu: odpracované dny,
     * pak schválení se VŠEMI návrhy náhledu (včetně hodin nepřítomnosti).
     * `$overrides` přepíše jednotlivá pole dialogu. Vrací odpověď schválení,
     * aby šlo zaznamenat, kde se schválení zasekne.
     *
     * @param list<string> $workedDates
     * @param array<string,mixed> $overrides
     */
    private function approveTimeMonth(
        int $employmentId,
        string $period,
        array $workedDates,
        array $overrides = [],
        int $dailyMinutes = 480,
    ): ResponseInterface {
        $this->ensureRegularCalendar($employmentId, $dailyMinutes);
        $monthVersion = $workedDates === []
            ? $this->timeMonthRowVersion($employmentId, $period)
            : $this->recordWorkedDays($employmentId, $workedDates, $dailyMinutes);
        $preview = $this->timeMonthPreview($employmentId, $period);
        $suggestions = $preview['suggestions'];
        $summary = [
            'source_snapshot_sha256' => $preview['source_snapshot_sha256'],
            'standard_fund_hours' => $suggestions['standard_fund_hours'],
            'agreed_fund_hours' => $suggestions['agreed_fund_hours'],
            'weekly_work_hours' => $suggestions['weekly_work_hours'],
            'worked_hours' => $suggestions['worked_hours'] ?? '0',
            'unworked_hours_occurred' => $suggestions['unworked_hours_occurred'] ?? false,
            'work_obstacles_occurred' => $suggestions['work_obstacles_occurred'] ?? false,
            'confirmation_note' => '',
        ];
        foreach ([
            'unworked_total_hours',
            'unworked_paid_hours',
            'dpn_without_employer_compensation_hours',
            'dpn_with_employer_compensation_hours',
            'vacation_hours',
            'care_hours',
            'employee_obstacle_paid_hours',
            'employer_obstacle_hours',
            'maternity_hours',
            'paternity_hours',
            'parental_hours',
            'unpaid_leave_hours',
            'unexcused_hours',
            'compensatory_time_off_hours',
        ] as $field) {
            $summary[$field] = $suggestions[$field] ?? null;
        }

        return $this->time->approve(
            $this->request('POST', "/api/payroll/time/months/{$period}/approve")
                ->withParsedBody([
                    'employment_id' => $employmentId,
                    'row_version' => $monthVersion,
                    'jmhz_work_summary' => $overrides + $summary,
                ]),
            new Response(),
            ['period' => $period],
        );
    }

    private function ensureRegularCalendar(int $employmentId, int $dailyMinutes = 480): void
    {
        if (isset($this->calendarEmployments[$employmentId])) {
            return;
        }
        $calendar = $this->time->calendar(
            $this->request('PUT', "/api/payroll/time/calendars/{$employmentId}")
                ->withParsedBody([
                    'name' => 'Syntetický pravidelný týden JMHZ',
                    'timezone' => 'Europe/Prague',
                    'schedule_type' => 'regular',
                    'week_pattern' => [
                        '1' => $dailyMinutes,
                        '2' => $dailyMinutes,
                        '3' => $dailyMinutes,
                        '4' => $dailyMinutes,
                        '5' => $dailyMinutes,
                        '6' => 0,
                        '7' => 0,
                    ],
                    'valid_from' => '2026-01-01',
                    'valid_to' => null,
                    'row_version' => 0,
                    'month_row_version' => 0,
                    'days' => [],
                ]),
            new Response(),
            ['employmentId' => (string) $employmentId],
        );
        self::assertSame(201, $calendar->getStatusCode(), (string) $calendar->getBody());
        $this->calendarEmployments[$employmentId] = true;
    }

    /**
     * Záznamy docházky od 8:00 v délce odpracovaných minut + 30 minut přestávky.
     *
     * @param list<string> $dates
     * @return int row_version měsíce po posledním záznamu
     */
    private function recordWorkedDays(int $employmentId, array $dates, int $workedMinutes = 480): int
    {
        $monthVersion = 0;
        foreach ($dates as $date) {
            $end = (new \DateTimeImmutable("{$date} 08:00:00"))
                ->modify('+' . ($workedMinutes + 30) . ' minutes')
                ->format('H:i:s');
            $entry = $this->time->entry(
                $this->request('POST', '/api/payroll/time/entries')->withParsedBody([
                    'employment_id' => $employmentId,
                    'starts_at' => "{$date}T08:00:00+02:00",
                    'ends_at' => "{$date}T{$end}+02:00",
                    'timezone' => 'Europe/Prague',
                    'category' => 'regular',
                    'break_minutes' => 30,
                    'row_version' => 0,
                    'month_row_version' => $monthVersion,
                    'supersedes_id' => null,
                ]),
                new Response(),
            );
            self::assertSame(201, $entry->getStatusCode(), (string) $entry->getBody());
            $monthVersion = (int) $this->json($entry)['month']['row_version'];
        }

        return $monthVersion;
    }

    /** @return array<string,mixed> položka měsíce pro vztah */
    private function timeMonthItem(int $employmentId, string $period): array
    {
        $overview = $this->time->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => $period]),
            new Response(),
        );
        self::assertSame(200, $overview->getStatusCode(), (string) $overview->getBody());
        foreach ($this->json($overview)['items'] as $candidate) {
            if (($candidate['employment']['id'] ?? null) === $employmentId) {
                return $candidate;
            }
        }
        self::fail("Měsíc {$period} neobsahuje vztah {$employmentId}.");
    }

    /** @return array<string,mixed> */
    private function timeMonthPreview(int $employmentId, string $period): array
    {
        $preview = $this->timeMonthItem($employmentId, $period)['jmhz_work_summary']['preview'] ?? null;
        self::assertIsArray($preview, "Měsíc {$period} vztahu {$employmentId} nemá náhled pracovního souhrnu.");

        return $preview;
    }

    private function timeMonthRowVersion(int $employmentId, string $period): int
    {
        $item = $this->timeMonthItem($employmentId, $period);

        return (int) ($item['month']['row_version'] ?? 0);
    }

    private function createActor(string $suffix): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, ?, "readonly", "cs", 1)',
        )->execute([
            'payroll-flow-' . $suffix . '-' . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
            'Synthetic ' . $suffix,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function employerPolicy(): array
    {
        return [
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'payday_day' => 10,
            'payday_month_offset' => 1,
            'payday_business_day_rule' => 'previous_business_day',
            'balance_rounding_mode' => 'exact_minor_units',
            'home_office_policy' => 'not_used',
            'travel_expense_policy' => 'not_used',
            'leave_entitlement_weeks' => 4,
            'automatic_posting_enabled' => false,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function statutoryEvidence(
        string $periodStart,
        bool $taxDeclarationSigned,
        bool $withHealthMonthEvidence,
        int $healthEvidenceDocumentId,
        string $socialDiscountStatus = 'not_claimed',
        string $healthTopUpResponsibility = 'employer_obstacle_verified',
    ): array
    {
        return [
            'effective_on' => date('Y-m-t', strtotime($periodStart)),
            'sections' => [
                'tax_declarations' => [[
                    'status' => $taxDeclarationSigned ? 'signed' : 'not-signed',
                    'evidence_reference' => 'document:synthetic-tax-declaration',
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ]],
                'tax_residences' => [[
                    'residence' => 'czech-resident',
                    'country_code' => 'CZ',
                    'evidence_reference' => 'document:synthetic-tax-residence',
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ]],
                // Slevy podle § 35ba mají vlastní scénář
                // (PayrollPersonStatutoryEvidenceApiTest); tady by jen posunuly
                // očekávané částky daně.
                'tax_credit_claims' => [],
                'social_jurisdictions' => [[
                    'jurisdiction' => 'czech_regime_verified',
                    'foreign_country_code' => null,
                    'jurisdiction_evidence_reference' => null,
                    'a1_status' => 'not_applicable',
                    'a1_certificate_reference' => null,
                    'a1_valid_until' => null,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ]],
                'social_discount_claims' => [[
                    'status' => $socialDiscountStatus,
                    'evidence_reference' => $socialDiscountStatus === 'verified'
                        ? 'document:synthetic-pension-decision'
                        : null,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ]],
                'health_coverages' => [[
                    'jurisdiction' => 'czech_regime_verified',
                    'foreign_country_code' => null,
                    'jurisdiction_evidence_reference' => null,
                    'insurer_status' => 'verified',
                    'insurer_code' => '111',
                    'insurer_evidence_reference' => 'document:synthetic-health-card',
                    'health_evidence_document_id' => $healthEvidenceDocumentId,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ]],
                'health_month_evidence' => $withHealthMonthEvidence ? [[
                    'period_start' => $periodStart,
                    'top_up_responsibility' => $healthTopUpResponsibility,
                    'top_up_responsibility_evidence_reference' =>
                        $healthTopUpResponsibility === 'employer_obstacle_verified'
                            ? 'document:synthetic-obstacle'
                            : null,
                    'selected_top_up_employer_reference' => null,
                    'selected_top_up_employer_evidence_reference' => null,
                ]] : [],
            ],
        ];
    }

    private function createHealthEvidenceDocument(int $sequence): int
    {
        $sha256 = hash('sha256', 'synthetic-health-evidence-' . $sequence);
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO documents
                (supplier_id, title, original_name, filename, sha256, mime_type,
                 size_bytes, doc_type, source, uploaded_by, scope)
             VALUES (?, "Syntetický zdravotní důkaz", "health-evidence.pdf", ?, ?,
                     "application/pdf", 1, "pdf", "manual", ?, "company")',
        );
        $statement->execute([
            $this->supplierId,
            $sha256 . '.pdf',
            $sha256,
            $this->actors[0],
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function createOpeningBalances(int $employeeId, int $sequence): void
    {
        $repository = $this->container->get(PayrollStatutoryAccumulatorRepository::class);
        if (!$repository instanceof PayrollStatutoryAccumulatorRepository) {
            throw new \RuntimeException('Roční zákonné akumulátory nejsou dostupné.');
        }
        $repository->appendOpeningBalance(
            $this->supplierId,
            $employeeId,
            2026,
            'social_insurance',
            ['assessment_base_minor_units' => 0],
            'synthetic:full-flow-social-opening',
            ['verified_zero' => true],
            "full-flow-social-opening-{$sequence}",
            actorUserId: $this->actors[0],
        );
        $repository->appendOpeningBalance(
            $this->supplierId,
            $employeeId,
            2026,
            'income_tax',
            [
                'completed_months' => 0,
                'advance_base_minor_units' => 0,
                'withholding_base_minor_units' => 0,
                'advance_tax_minor_units' => 0,
                'withholding_tax_minor_units' => 0,
                'applied_non_refundable_credits_minor_units' => 0,
                'applied_child_credit_minor_units' => 0,
                'tax_bonus_minor_units' => 0,
                'bonus_qualifying_income_minor_units' => 0,
            ],
            'synthetic:full-flow-tax-opening',
            ['verified_zero' => true],
            "full-flow-tax-opening-{$sequence}",
            actorUserId: $this->actors[0],
        );
    }

    /**
     * OIČ je údaj osoby: u dalšího vztahu téže osoby ho formulář už neposílá,
     * proto `$personIdentifier = null`.
     *
     * @param array{employee_id:int,employment_id:int,name:string} $person
     */
    private function assignJmhzIdentity(
        array $person,
        ?string $personIdentifier = '1000000001',
        string $employmentIdentifier = '200000000000000000004',
    ): void {
        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        if (!$identities instanceof PayrollRegistrationIdentityService) {
            throw new \RuntimeException('Registrační identita JMHZ není dostupná.');
        }
        $assigned = $identities->assignManualJmhzIdentity(
            $this->supplierId,
            $person['employment_id'],
            'test',
            $personIdentifier,
            $employmentIdentifier,
            '2026-01-01',
            null,
            true,
            $this->actors[0],
        );
        self::assertTrue($assigned['employment_external_identifier']['created']);
    }

    private function insertPersonIdentifier(int $employeeId, string $type, string $value): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_identifiers
                (supplier_id, employee_id, identifier_type,
                 value_ciphertext, value_hash, value_masked)
             VALUES (?, ?, ?, "enc:v2:pending", ?, "")',
        )->execute([$this->supplierId, $employeeId, $type, random_bytes(32)]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $sensitive = $this->container->get(PayrollSensitiveData::class);
        if (!$sensitive instanceof PayrollSensitiveData) {
            throw new \RuntimeException('Šifrování mzdových identifikátorů není dostupné.');
        }
        $sealed = $sensitive->seal(
            $value,
            PayrollSensitiveField::PERSONAL_IDENTIFIER,
            $this->supplierId,
            $id,
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_identifiers
                SET value_ciphertext = ?, value_hash = ?, value_masked = ?
              WHERE supplier_id = ? AND id = ?',
        )->execute([
            $sealed->ciphertext,
            $sealed->lookupHash,
            $sealed->masked,
            $this->supplierId,
            $id,
        ]);
    }

    private function componentId(string $code): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ?',
        );
        $statement->execute([$this->supplierId, $code]);
        $id = $statement->fetchColumn();
        if (!is_int($id) && !is_string($id)) {
            throw new \RuntimeException("Mzdová složka {$code} nebyla nalezena.");
        }

        return (int) $id;
    }

    private function transportAttemptCount(int $submissionId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_submission_transport_attempts
              WHERE supplier_id = ? AND submission_id = ?',
        );
        $statement->execute([$this->supplierId, $submissionId]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array{code:string,message:string}> */
    private function blockingValidations(int $revisionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT code, message
               FROM payroll_run_validations
              WHERE supplier_id = ? AND revision_id = ? AND severity = "blocker"
              ORDER BY code, id',
        );
        $statement->execute([$this->supplierId, $revisionId]);
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Varování, která před schválením běhu vyžadují výslovné potvrzení.
     *
     * @return list<array{id:int,code:string,message:string}>
     */
    private function unresolvedOverrideWarnings(int $revisionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, code, message
               FROM payroll_run_validations
              WHERE supplier_id = ? AND revision_id = ? AND severity = "warning"
                AND requires_override = 1 AND overridden_at IS NULL
              ORDER BY code, id',
        );
        $statement->execute([$this->supplierId, $revisionId]);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'message' => (string) $row['message'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * Běh měsíce od založení po výpočet. Když výpočet skončí s blokací,
     * vrací ji (místo, kde se účetní zasekne) a dál nepokračuje.
     *
     * Varování čekající na potvrzení se vrací v `warnings` a běh se před
     * schválením zastaví; potvrdit je musí test sám, stejně jako účetní.
     *
     * @return array{calculated:PayrollRunCommandResult,approved:?PayrollRunCommandResult,blockers:list<array{code:string,message:string}>,warnings:list<array{id:int,code:string,message:string}>}
     */
    private function runPayrollMonth(string $periodStart, string $payday, ?int $officeId, string $key): array
    {
        $run = $this->runs->createRun($this->supplierId, $periodStart, $payday, $officeId, $this->actors[0]);
        $locked = $this->runs->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            "{$key}-lock",
            $this->actors[0],
        );
        $calculated = $this->runs->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            "{$key}-calculate",
            $this->actors[0],
        );
        $blockers = $this->blockingValidations((int) $calculated->revision['id']);
        $warnings = $this->unresolvedOverrideWarnings((int) $calculated->revision['id']);
        if ($blockers !== [] || $warnings !== []) {
            return [
                'calculated' => $calculated,
                'approved' => null,
                'blockers' => $blockers,
                'warnings' => $warnings,
            ];
        }
        $reviewed = $this->runs->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            "{$key}-review",
            $this->actors[0],
        );
        $approved = $this->runs->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            "{$key}-approve",
            $this->actors[0],
        );

        return ['calculated' => $calculated, 'approved' => $approved, 'blockers' => [], 'warnings' => []];
    }

    /**
     * Příprava měsíčního hlášení JMHZ ze schválené revize (testovací prostředí).
     *
     * @return array{status:int,body:array<string,mixed>}
     */
    private function prepareJmhz(int $revisionId, string $key): array
    {
        $prepare = $this->container->get(PayrollJmhzPreparationAction::class);
        if (!$prepare instanceof PayrollJmhzPreparationAction) {
            throw new \RuntimeException('Příprava měsíčního hlášení JMHZ není dostupná.');
        }
        $response = $prepare(
            $this->request('POST', "/api/payroll/jmhz/preparations/{$revisionId}")
                ->withHeader('Idempotency-Key', $key)
                ->withParsedBody(['environment' => 'test']),
            new Response(),
            ['revisionId' => (string) $revisionId],
        );

        return ['status' => $response->getStatusCode(), 'body' => $this->json($response)];
    }

    /**
     * Testovací sestavení XML hlášení s XSD a kontrolami katalogu.
     *
     * @return array{status:int,body:array<string,mixed>}
     */
    private function dryRunJmhz(int $preparationId, int $officeId): array
    {
        $dryRun = $this->container->get(PayrollJmhzXmlDryRunAction::class);
        if (!$dryRun instanceof PayrollJmhzXmlDryRunAction) {
            throw new \RuntimeException('Testovací sestavení JMHZ není dostupné.');
        }
        $response = $dryRun(
            $this->request('GET', "/api/payroll/jmhz/preparations/{$preparationId}/test")
                ->withQueryParams(['environment' => 'test', 'office' => (string) $officeId]),
            new Response(),
            ['preparationId' => (string) $preparationId],
        );

        return ['status' => $response->getStatusCode(), 'body' => $this->json($response)];
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    private function request(string $method, string $uri): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->actors[0], 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
