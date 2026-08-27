<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollAbsenceAction;
use MyInvoice\Action\Payroll\PayrollHealthInsuranceOverviewAction;
use MyInvoice\Action\Payroll\PayrollJmhzPreparationAction;
use MyInvoice\Action\Payroll\PayrollJmhzSubmissionFreezeAction;
use MyInvoice\Action\Payroll\PayrollJmhzXmlDryRunAction;
use MyInvoice\Action\Payroll\PayrollTimeAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Service\Payroll\Posting\PayrollApprovedRevisionPostingService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCalculationPipeline;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunWorkflow;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreviewService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Jeden izolovaný měsíční řez: HPP, DPČ a DPP, dvě složky, dovolená,
 * výpočet a schválení jednou účetní, zdravotní přehled a JMHZ preview.
 * Vše běží v transakci nad myucto_test a tearDown ji vrátí zpět.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollSyntheticFullFlowTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ContainerInterface $container;
    private PayrollRunCommandService $runs;
    private PayrollAbsenceAction $absences;
    private PayrollTimeAction $time;
    private PayrollHealthInsuranceOverviewAction $healthOverview;
    private int $supplierId;
    /** @var list<int> */
    private array $actors = [];
    /** @var list<array{employee_id:int,employment_id:int,name:string}> */
    private array $people = [];

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $db = $this->container->get(Connection::class);
        $absences = $this->container->get(PayrollAbsenceAction::class);
        $healthOverview = $this->container->get(PayrollHealthInsuranceOverviewAction::class);
        $time = $this->container->get(PayrollTimeAction::class);
        if (!$db instanceof Connection
            || !$absences instanceof PayrollAbsenceAction
            || !$healthOverview instanceof PayrollHealthInsuranceOverviewAction
            || !$time instanceof PayrollTimeAction
        ) {
            throw new \RuntimeException('Služby syntetického mzdového toku nejsou dostupné.');
        }
        $this->db = $db;
        $this->absences = $absences;
        $this->healthOverview = $healthOverview;
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

        $officeId = $this->createOffice();
        $this->configureSocialInsuranceOutput($officeId);
        $this->configureHealthInsuranceOutput();
        $baseComponentId = $this->createComponent('MZDA_MESICNI_FLOW', 'base_wage', 'regular');
        $bonusComponentId = $this->createComponent('ODMENA_FLOW', 'bonus', 'one_off');
        $definitions = [
            [
                'name' => 'Alice Syntetická',
                'gross' => 4_200_000,
                'employment_type' => 'hpp',
                'relation_type' => 'employment',
                'weekly_hours' => 40,
                'workload_basis_points' => 10_000,
            ],
            [
                'name' => 'Boris Syntetický',
                'gross' => 3_600_000,
                'employment_type' => 'dpc',
                'relation_type' => 'dpc',
                'weekly_hours' => 20,
                'workload_basis_points' => 5_000,
            ],
            [
                'name' => 'Cyril Syntetický',
                'gross' => 1_500_000,
                'employment_type' => 'dpp',
                'relation_type' => 'dpp',
                'weekly_hours' => 10,
                'workload_basis_points' => 2_500,
            ],
        ];
        foreach ($definitions as $index => $definition) {
            $person = $this->createEmployment(
                $officeId,
                $definition['name'],
                $index + 1,
                $definition['employment_type'],
                $definition['relation_type'],
                $definition['weekly_hours'],
                $definition['workload_basis_points'],
            );
            $this->people[] = $person;
            $this->createApprovedInput($person, $baseComponentId, $definition['gross'], 'base-' . ($index + 1));
        }
        $this->createApprovedInput($this->people[0], $bonusComponentId, 25_000, 'bonus-1');
        $this->createApprovedVacation($this->people[1]['employment_id']);

        $runRepository = $this->container->get(PayrollRunRepository::class);
        if (!$runRepository instanceof PayrollRunRepository) {
            throw new \RuntimeException('Repository mzdového běhu není dostupné.');
        }
        $posting = $this->createStub(PayrollApprovedRevisionPostingService::class);
        $posting->method('post')->willReturn([]);
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

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testMixedEmploymentMonthReachesApprovedRunAndStatutoryOutputs(): void
    {
        self::assertCount(3, $this->people);
        self::assertSame(1, $this->countScenarioRows('payroll_absences'));
        self::assertSame(4, $this->countScenarioRows('payroll_inputs'));
        self::assertSame(
            [
                ['employment_type' => 'hpp', 'relation_type' => 'employment'],
                ['employment_type' => 'dpc', 'relation_type' => 'dpc'],
                ['employment_type' => 'dpp', 'relation_type' => 'dpp'],
            ],
            $this->employmentTypes(),
        );

        $run = $this->runs->createRun(
            $this->supplierId,
            '2026-06-01',
            '2026-07-15',
            null,
            $this->actors[0],
        );
        $locked = $this->runs->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'full-flow-lock',
            $this->actors[0],
        );
        $calculated = $this->runs->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'full-flow-calculate',
            $this->actors[0],
        );
        self::assertSame('calculated', $calculated->run['status']);
        self::assertCount(3, $calculated->revision['result_snapshot']['people']);
        self::assertSame(
            1,
            $this->frozenAbsenceCount($calculated->revision['input_snapshot']),
        );
        self::assertSame(
            9_325_000,
            $calculated->revision['result_snapshot']['totals']['source_amount_minor'],
        );
        $blockers = $this->blockingValidations((int) $calculated->revision['id']);
        self::assertSame([], $blockers, CanonicalJson::encode($blockers));

        $reviewed = $this->runs->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'full-flow-review',
            $this->actors[0],
        );
        $approved = $this->runs->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'full-flow-approve',
            $this->actors[0],
        );
        self::assertSame('approved', $approved->run['status']);
        self::assertSame($approved->revision['calculated_by'], $approved->revision['reviewed_by']);
        self::assertSame($approved->revision['reviewed_by'], $approved->revision['approved_by']);

        $revisionId = (int) $approved->revision['id'];
        $response = $this->healthOverview->index(
            $this->request('GET', "/api/payroll/submissions/health-overviews/{$revisionId}"),
            new Response(),
            ['revisionId' => (string) $revisionId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $overview = $this->json($response);
        self::assertFalse($overview['electronic_submission']['supported']);
        self::assertSame('health_insurance_transport_unavailable', $overview['electronic_submission']['reason_code']);
        self::assertNotEmpty($overview['items']);
        self::assertSame('111', $overview['items'][0]['insurer']['code']);
        self::assertCount(3, $overview['items'][0]['people']);

        $download = $this->healthOverview->download(
            $this->request('GET', "/api/payroll/submissions/health-overviews/{$revisionId}/111/download"),
            new Response(),
            ['revisionId' => (string) $revisionId, 'insurerCode' => '111'],
        );
        self::assertSame(200, $download->getStatusCode(), (string) $download->getBody());
        self::assertSame(hash('sha256', (string) $download->getBody()), $download->getHeaderLine('Content-SHA256'));

        $jmhz = $this->container->get(JmhzPvpojPreviewService::class);
        if (!$jmhz instanceof JmhzPvpojPreviewService) {
            throw new \RuntimeException('JMHZ PVPOJ preview není dostupné.');
        }
        $preview = $jmhz->preview($this->supplierId, $revisionId);
        self::assertSame('2026-06', $preview->period);
        self::assertSame('internal_jmhz_pvpoj_preview', $preview->toArray()['document_kind']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $preview->sha256());
    }

    public function testOrdinaryHppReachesValidJmhzTestSubmissionWithoutTransport(): void
    {
        $officeId = $this->createOffice('JMHZ', 'Syntetická registrace JMHZ', '9990001234');
        $person = $this->createEmployment(
            $officeId,
            'Dana Testovací',
            4,
            'hpp',
            'employment',
            40,
            10_000,
        );
        $this->completeJmhzEmployment($person);
        $this->createApprovedTimeMonth($person['employment_id']);
        $this->createApprovedAverage($person['employment_id']);
        $this->assignJmhzIdentity($person);

        $baseComponentId = $this->componentId('MZDA_MESICNI_FLOW');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        if (!$mappings instanceof PayrollComponentJmhzMappingRepository) {
            throw new \RuntimeException('Mapování mzdových složek JMHZ není dostupné.');
        }
        $mappings->put($this->supplierId, $baseComponentId, '10329', null, $this->actors[0]);
        $this->createApprovedInput($person, $baseComponentId, 4_200_000, 'jmhz-base');

        $run = $this->runs->createRun(
            $this->supplierId,
            '2026-06-01',
            '2026-07-15',
            $officeId,
            $this->actors[0],
        );
        $locked = $this->runs->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'jmhz-flow-lock',
            $this->actors[0],
        );
        $calculated = $this->runs->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'jmhz-flow-calculate',
            $this->actors[0],
        );
        self::assertSame([], $this->blockingValidations((int) $calculated->revision['id']));
        $reviewed = $this->runs->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'jmhz-flow-review',
            $this->actors[0],
        );
        $approved = $this->runs->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'jmhz-flow-approve',
            $this->actors[0],
        );
        $revisionId = (int) $approved->revision['id'];

        $prepare = $this->container->get(PayrollJmhzPreparationAction::class);
        $dryRun = $this->container->get(PayrollJmhzXmlDryRunAction::class);
        $freeze = $this->container->get(PayrollJmhzSubmissionFreezeAction::class);
        if (!$prepare instanceof PayrollJmhzPreparationAction
            || !$dryRun instanceof PayrollJmhzXmlDryRunAction
            || !$freeze instanceof PayrollJmhzSubmissionFreezeAction
        ) {
            throw new \RuntimeException('Akce měsíčního hlášení JMHZ nejsou dostupné.');
        }
        $preparationResponse = $prepare(
            $this->request('POST', "/api/payroll/jmhz/preparations/{$revisionId}")
                ->withHeader('Idempotency-Key', 'synthetic-jmhz-full-flow')
                ->withParsedBody(['environment' => 'test']),
            new Response(),
            ['revisionId' => (string) $revisionId],
        );
        self::assertSame(201, $preparationResponse->getStatusCode(), (string) $preparationResponse->getBody());
        $preparation = $this->json($preparationResponse);
        self::assertSame('source_ready', $preparation['readiness_status'], CanonicalJson::encode($preparation['issues']));
        self::assertSame(0, $preparation['issue_count']);

        $preparationId = (int) $preparation['id'];
        $dryRunResponse = $dryRun(
            $this->request('GET', "/api/payroll/jmhz/preparations/{$preparationId}/test")
                ->withQueryParams(['environment' => 'test', 'office' => (string) $officeId]),
            new Response(),
            ['preparationId' => (string) $preparationId],
        );
        self::assertSame(200, $dryRunResponse->getStatusCode(), (string) $dryRunResponse->getBody());
        $tested = $this->json($dryRunResponse);
        self::assertSame('dry_run_valid', $tested['status'], CanonicalJson::encode($tested));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $tested['xml_sha256']);
        self::assertSame(hash('sha256', $tested['xml']), $tested['xml_sha256']);

        $freezeResponse = $freeze(
            $this->request('POST', "/api/payroll/jmhz/preparations/{$preparationId}/submission")
                ->withParsedBody(['environment' => 'test', 'office' => $officeId]),
            new Response(),
            ['preparationId' => (string) $preparationId],
        );
        self::assertSame(201, $freezeResponse->getStatusCode(), (string) $freezeResponse->getBody());
        $submission = $this->json($freezeResponse);
        self::assertSame('test', $submission['environment']);
        self::assertSame('ready', $submission['status']);
        self::assertTrue($submission['created']);
        self::assertSame(0, $this->transportAttemptCount((int) $submission['submission_id']));
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
        return (int) $this->db->pdo()->lastInsertId();
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

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function createEmployment(
        int $officeId,
        string $name,
        int $sequence,
        string $employmentType,
        string $relationType,
        int $weeklyHours,
        int $workloadBasisPoints,
    ): array {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", ?, 1, 1, 0, 0, 0, 1)',
        )->execute([$this->supplierId, $name, $employmentType]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "ready")',
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type,
                 status, start_date, actual_start_date, is_primary)
             VALUES (?, ?, ?, ?, ?, "active",
                     "2026-01-01", "2026-01-01", 1)',
        )->execute([$this->supplierId, $employeeId, $officeId, "FLOW-{$sequence}", $relationType]);
        $employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from,
                 planned_start_on, actual_start_on, weekly_hours,
                 workload_basis_points, social_insurance_participation,
                 health_insurance_participation, tax_regime,
                 tax_declaration_signed, is_primary)
             VALUES (?, ?, ?, "2026-01-01", "2026-01-01", "2026-01-01",
                     ?, ?, "automatic", "automatic", "advance", 1, 1)',
        )->execute([
            $this->supplierId,
            $employmentId,
            $officeId,
            $weeklyHours,
            $workloadBasisPoints,
        ]);
        $evidence = $this->container->get(PayrollPersonStatutoryEvidenceRepository::class);
        if (!$evidence instanceof PayrollPersonStatutoryEvidenceRepository) {
            throw new \RuntimeException('Zákonná evidence osoby není dostupná.');
        }
        $evidence->save(
            $this->supplierId,
            $employeeId,
            $this->statutoryEvidence(),
            '2026-06-30',
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
             VALUES (?, ?, "2026-06-01", 1, 1, 1, "none", ?)',
        )->execute([$this->supplierId, $employeeId, $this->actors[0]]);
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
    private function createApprovedInput(array $person, int $componentId, int $amountMinor, string $externalId): void
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
             VALUES (?, ?, ?, ?, "2026-06-01", ?, "manual", ?, "approved",
                     ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $person['employee_id'],
            $person['employment_id'],
            $componentId,
            $amountMinor,
            $externalId,
            $json,
            hash('sha256', $json, true),
            $this->actors[0],
        ]);
    }

    private function createApprovedVacation(int $employmentId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_shifts
                (supplier_id, employment_id, series_key, starts_at_utc,
                 ends_at_utc, timezone_name, break_minutes, status,
                 published_by, published_at)
             VALUES (?, ?, "flow-vacation", "2026-06-15 06:00:00",
                     "2026-06-15 14:30:00", "Europe/Prague", 30,
                     "published", ?, NOW())',
        )->execute([$this->supplierId, $employmentId, $this->actors[0]]);
        $average = $this->createApprovedAverage($employmentId);
        $absenceResponse = $this->absences->create(
            $this->request('POST', '/api/payroll/absences')->withParsedBody([
                'employment_id' => $employmentId,
                'absence_type' => 'vacation',
                'date_from' => '2026-06-15',
                'date_to' => '2026-06-15',
                'timezone_name' => 'Europe/Prague',
                'partial_first_minutes' => null,
                'partial_last_minutes' => null,
                'average_snapshot_id' => $average['id'],
                'note' => 'Syntetická dovolená full-flow.',
            ]),
            new Response(),
        );
        self::assertSame(201, $absenceResponse->getStatusCode(), (string) $absenceResponse->getBody());
        $absence = $this->json($absenceResponse)['absence'];
        $decision = $this->absences->decision(
            $this->request('POST', '/api/payroll/absences/decision')->withParsedBody([
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(200, $decision->getStatusCode(), (string) $decision->getBody());
    }

    /** @return array<string,mixed> */
    private function createApprovedAverage(int $employmentId): array
    {
        $averageResponse = $this->absences->createAverage(
            $this->request('POST', '/api/payroll/absences/average')->withParsedBody([
                'employment_id' => $employmentId,
                'applicable_year' => 2026,
                'applicable_quarter' => 2,
                'decisive_from' => '2026-01-01',
                'decisive_to' => '2026-03-31',
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

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function completeJmhzEmployment(array $person): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET activity_code = "1",
                    jmhz_relationship_detail_code = "1",
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
            JmhzExternalCodebookCatalog::DEFAULT_OVERLAY_KEY,
            JmhzExternalCodebookCatalog::DEFAULT_MANIFEST_SHA256,
            $this->supplierId,
            $person['employment_id'],
        ]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 birth_date, birth_place, birth_country_code,
                 citizenship_country_code, sex, effective_from)
             VALUES (?, ?, ?, "Dana", "Testovací", "1991-02-03",
                     "Testov", "CZ", "CZ", "female", "2026-01-01")',
        )->execute([$this->supplierId, $person['employee_id'], $person['name']]);
        $this->insertPersonIdentifier($person['employee_id'], 'birth_number', '9102030014');
    }

    private function createApprovedTimeMonth(int $employmentId): void
    {
        $calendar = $this->time->calendar(
            $this->request('PUT', "/api/payroll/time/calendars/{$employmentId}")
                ->withParsedBody([
                    'name' => 'Syntetický pravidelný týden JMHZ',
                    'timezone' => 'Europe/Prague',
                    'schedule_type' => 'regular',
                    'week_pattern' => [
                        '1' => 480,
                        '2' => 480,
                        '3' => 480,
                        '4' => 480,
                        '5' => 480,
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
        $entry = $this->time->entry(
            $this->request('POST', '/api/payroll/time/entries')->withParsedBody([
                'employment_id' => $employmentId,
                'starts_at' => '2026-06-01T08:00:00+02:00',
                'ends_at' => '2026-06-01T16:00:00+02:00',
                'timezone' => 'Europe/Prague',
                'category' => 'regular',
                'break_minutes' => 30,
                'row_version' => 0,
                'month_row_version' => 0,
                'supersedes_id' => null,
            ]),
            new Response(),
        );
        self::assertSame(201, $entry->getStatusCode(), (string) $entry->getBody());
        $monthVersion = (int) $this->json($entry)['month']['row_version'];
        $overview = $this->time->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-06']),
            new Response(),
        );
        self::assertSame(200, $overview->getStatusCode(), (string) $overview->getBody());
        $item = null;
        foreach ($this->json($overview)['items'] as $candidate) {
            if (($candidate['employment']['id'] ?? null) === $employmentId) {
                $item = $candidate;
                break;
            }
        }
        self::assertIsArray($item);
        $preview = $item['jmhz_work_summary']['preview'];
        $approved = $this->time->approve(
            $this->request('POST', '/api/payroll/time/months/2026-06/approve')
                ->withParsedBody([
                    'employment_id' => $employmentId,
                    'row_version' => $monthVersion,
                    'jmhz_work_summary' => [
                        'source_snapshot_sha256' => $preview['source_snapshot_sha256'],
                        'standard_fund_hours' => $preview['suggestions']['agreed_fund_hours'],
                        'agreed_fund_hours' => $preview['suggestions']['agreed_fund_hours'],
                        'weekly_work_hours' => '40',
                        'worked_hours' => $preview['suggestions']['worked_hours'],
                        'unworked_hours_occurred' => false,
                        'work_obstacles_occurred' => false,
                        'confirmation_note' => '',
                    ],
                ]),
            new Response(),
            ['period' => '2026-06'],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
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
            'four_eyes_required' => false,
            'automatic_calculation_enabled' => true,
            'automatic_posting_enabled' => false,
            'automatic_payments_enabled' => false,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function statutoryEvidence(): array
    {
        return [
            'effective_on' => '2026-06-30',
            'sections' => [
                'tax_declarations' => [[
                    'status' => 'signed',
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
                    'status' => 'not_claimed',
                    'evidence_reference' => null,
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
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ]],
                'health_month_evidence' => [[
                    'period_start' => '2026-06-01',
                    'top_up_responsibility' => 'employer_obstacle_verified',
                    'top_up_responsibility_evidence_reference' => 'document:synthetic-obstacle',
                    'selected_top_up_employer_reference' => null,
                    'selected_top_up_employer_evidence_reference' => null,
                ]],
            ],
        ];
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

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function assignJmhzIdentity(array $person): void
    {
        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        if (!$identities instanceof PayrollRegistrationIdentityService) {
            throw new \RuntimeException('Registrační identita JMHZ není dostupná.');
        }
        $assigned = $identities->assignManualJmhzIdentity(
            $this->supplierId,
            $person['employment_id'],
            'test',
            '1000000001',
            '200000000000000000004',
            '2026-01-01',
            null,
            true,
            $this->actors[0],
        );
        self::assertTrue($assigned['person_external_identifier']['created']);
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

    /** @return list<array{employment_type:string,relation_type:string}> */
    private function employmentTypes(): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT employee.employment_type, employment.relation_type
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ?
              ORDER BY employment.code',
        );
        $statement->execute([$this->supplierId]);
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string,mixed> $snapshot */
    private function frozenAbsenceCount(array $snapshot): int
    {
        $count = 0;
        foreach ($snapshot['people'] ?? [] as $person) {
            foreach ($person['employments'] ?? [] as $employment) {
                $count += count($employment['absences'] ?? []);
            }
        }
        return $count;
    }

    private function countScenarioRows(string $table): int
    {
        if (!in_array($table, ['payroll_absences', 'payroll_inputs'], true)) {
            throw new \InvalidArgumentException('Nepodporovaná tabulka scénáře.');
        }
        $statement = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
        $statement->execute([$this->supplierId]);
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

    private function request(string $method, string $uri): \Psr\Http\Message\ServerRequestInterface
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
