<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Deadline\PayrollLevyDeadlinePolicy;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentBatchBuilder;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentQueryService;
use MyInvoice\Service\Payroll\Payment\PayrollSocialInsuranceLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollSocialOfficeAllocator;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollSocialInsuranceLiabilityMaterializerTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollSensitiveData $sensitiveData;
    private PayrollPaymentBatchBuilder $batches;
    private SecretEncryption $encryption;
    private int $supplierId;
    private int $actorId;
    private int $employeeId;
    private int $secondEmployeeId;
    private int $officeId;
    private int $secondOfficeId;
    private int $employmentId;
    private int $secondEmploymentId;
    private int $crossOfficeEmploymentId;
    private int $officelessEmploymentId;
    private int $runId;
    private int $payerCurrencyId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $sensitiveData = $container->get(PayrollSensitiveData::class);
        $batches = $container->get(PayrollPaymentBatchBuilder::class);
        $encryption = $container->get(SecretEncryption::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitiveData);
        self::assertInstanceOf(PayrollPaymentBatchBuilder::class, $batches);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        $this->db = $connection;
        $this->sensitiveData = $sensitiveData;
        $this->batches = $batches;
        $this->encryption = $encryption;
        $pdo = $connection->pdo();
        $source = $pdo->query('SELECT MIN(id) FROM supplier');
        self::assertInstanceOf(\PDOStatement::class, $source);
        $sourceSupplierId = (int) $source->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->actorId = $this->createActor($pdo);
        $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "active", "2026-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->actorId]);
        $this->payerCurrencyId = $this->createPayerCurrency($pdo);
        $pdo->prepare(
            'UPDATE supplier
                SET company_name = "Syntetická sociální firma",
                    display_name = NULL
              WHERE id = ?',
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická sociální osoba", "employee", 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, social_security_variable_symbol,
                 is_active, row_version)
             VALUES (?, "VZOROV", "Syntetická účtárna Vzorov",
                     "0012345678", 1, 1)',
        )->execute([$this->supplierId]);
        $this->officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, social_security_variable_symbol,
                 is_active, row_version)
             VALUES (?, "VZOROV2", "Syntetická účtárna Vzorov II",
                     "0087654321", 1, 1)',
        )->execute([$this->supplierId]);
        $this->secondOfficeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická sociální osoba II", "employee", 1)',
        )->execute([$this->supplierId]);
        $this->secondEmployeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id,
                 social_security_office_code)
             VALUES (?, ?, "P")',
        )->execute([$this->supplierId, $this->officeId]);
        (new PayrollInstitutionAccountRepository(
            $connection,
            $sensitiveData,
            new PayrollInstitutionAccountDeletionRepository(
                $connection,
                new ActivityLogger($connection),
            ),
        ))->create($this->supplierId, [
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
            'source_reference' => 'synthetic:cssz-account-notice',
            'verified_on' => '2026-06-15',
        ], $this->actorId);
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, office_id, period_start, payment_date,
                 status, current_revision_no)
             VALUES (?, ?, "2026-06-01", "2026-07-10",
                     "approved", 1)',
        )->execute([$this->supplierId, $this->officeId]);
        $this->runId = (int) $pdo->lastInsertId();
        $this->employmentId = $this->createEmployment(
            $pdo,
            $this->employeeId,
            $this->officeId,
            'PV-A',
        );
        $this->secondEmploymentId = $this->createEmployment(
            $pdo,
            $this->secondEmployeeId,
            $this->secondOfficeId,
            'PV-B',
        );
        $this->crossOfficeEmploymentId = $this->createEmployment(
            $pdo,
            $this->employeeId,
            $this->secondOfficeId,
            'PV-C',
        );
        $this->officelessEmploymentId = $this->createEmployment(
            $pdo,
            $this->employeeId,
            null,
            'PV-D',
        );
    }

    private function createEmployment(
        PDO $pdo,
        int $employeeId,
        ?int $officeId,
        string $code,
    ): int {
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type,
                 status, start_date)
             VALUES (?, ?, ?, ?, "employment", "active", "2026-01-01")',
        )->execute([$this->supplierId, $employeeId, $officeId, $code]);

        return (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testMaterializesOfficeScopedLiabilityAndCorrection(): void
    {
        $service = $this->service();
        $regularRevision = $this->createRevision(
            1,
            'regular',
            null,
            7_100,
            24_800,
            7_100,
        );
        $regular = $service->materialize(
            $this->supplierId,
            $regularRevision,
            $this->actorId,
        );
        $replay = $service->materialize(
            $this->supplierId,
            $regularRevision,
            $this->actorId,
        );
        self::assertSame(1, $regular['created_count']);
        self::assertSame(0, $replay['created_count']);
        self::assertSame($regular['liability_ids'], $replay['liability_ids']);
        $row = $this->liability($regular['liability_ids'][0]);
        self::assertSame('social_insurance', $row['liability_kind']);
        self::assertSame('outgoing', $row['direction']);
        self::assertSame('2026-07-20', $row['due_on']);
        self::assertSame(31_900, $this->integer(
            $row,
            'amount_minor',
        ));
        self::assertNull($row['employee_id']);
        $source = $this->jsonObject(
            $this->string($row, 'source_snapshot_json'),
        );
        self::assertSame(
            'payroll-payment-social-insurance-source.v1',
            $source['schema_reference'],
        );
        self::assertSame('0012345678', $source['variable_symbol']);
        self::assertSame($this->officeId, $source['payroll_office_id']);
        self::assertStringNotContainsString(
            '1000000005',
            $this->string($row, 'source_snapshot_json'),
        );
        self::assertArrayNotHasKey('bank_account_ciphertext', $source);
        $listed = (new PayrollPaymentQueryService($this->db))
            ->listForPeriod($this->supplierId, '2026-06')['items'];
        self::assertSame('ready', $listed[0]['batch_eligibility'] ?? null);
        $batch = $this->batches->build(
            $this->supplierId,
            'abo',
            "currency:{$this->payerCurrencyId}",
            [[
                'liability_id' => $regular['liability_ids'][0],
                'amount_minor' => 31_900,
            ]],
            $this->actorId,
        );
        $instruction = $this->batchInstruction($batch['batch_id']);
        self::assertSame('0012345678', $instruction['variable_symbol']);
        self::assertSame('7618', $instruction['constant_symbol']);
        self::assertSame(
            'Socialni pojisteni P',
            $instruction['payment_message'],
        );

        $correctionRevision = $this->createRevision(
            2,
            'correction',
            $regularRevision,
            7_100,
            25_000,
            7_100,
        );
        $correction = $service->materialize(
            $this->supplierId,
            $correctionRevision,
            $this->actorId,
        );
        $correctionRow = $this->liability(
            $correction['liability_ids'][0],
        );
        self::assertSame('outgoing', $correctionRow['direction']);
        self::assertSame(200, $this->integer(
            $correctionRow,
            'amount_minor',
        ));
        self::assertSame(
            $regular['liability_ids'][0],
            $this->integer($correctionRow, 'previous_liability_id'),
        );

        $decreaseRevision = $this->createRevision(
            3,
            'correction',
            $correctionRevision,
            7_100,
            24_500,
            7_100,
        );
        $decrease = $service->materialize(
            $this->supplierId,
            $decreaseRevision,
            $this->actorId,
        );
        $decreaseRow = $this->liability($decrease['liability_ids'][0]);
        self::assertSame('incoming', $decreaseRow['direction']);
        self::assertSame(500, $this->integer(
            $decreaseRow,
            'amount_minor',
        ));
    }

    public function testRejectsRootPersonTotalMismatch(): void
    {
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            7_100,
            24_800,
            7_000,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('součtu osob');
        $this->service()->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
    }

    public function testDoesNotUseAnotherTenantsEffectiveAccount(): void
    {
        $source = $this->db->pdo()->query(
            'SELECT MIN(id) FROM supplier',
        );
        self::assertInstanceOf(\PDOStatement::class, $source);
        $otherSupplierId = $this->createIsolatedSupplier(
            $this->db->pdo(),
            (int) $source->fetchColumn(),
        );
        (new PayrollInstitutionAccountRepository(
            $this->db,
            $this->sensitiveData,
            new PayrollInstitutionAccountDeletionRepository(
                $this->db,
                new ActivityLogger($this->db),
            ),
        ))->create($otherSupplierId, [
            'institution_type' => 'social_security',
            'institution_code' => 'P',
            'institution_name' => 'Jiná syntetická správa',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => null,
            'specific_symbol' => null,
            'constant_symbol' => '7618',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:other-tenant-cssz',
            'verified_on' => '2026-06-15',
        ], $this->actorId);
        $this->db->pdo()->prepare(
            'UPDATE payroll_institution_accounts
                SET valid_to = "2026-07-19",
                    row_version = row_version + 1
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            7_100,
            24_800,
            7_100,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nemá účinný ověřený účet');
        $this->service()->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
    }

    public function testRejectsEmployerDiscountMismatch(): void
    {
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            7_100,
            24_800,
            7_100,
            25_000,
            100,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('odvodu před slevou');
        $this->service()->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
    }

    /**
     * Celofiremní běh (`office_id` v kořeni je `null`) se rozpadne na tolik
     * závazků, kolik různých účtáren mají vztahy — a jejich součet sedí na
     * kořenový výsledek, na kterém stojí kontrolní součty i účetní rekonciliace.
     */
    public function testCompanyWideRunSplitsLiabilityPerOffice(): void
    {
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            8_000,
            24_000,
            0,
            null,
            0,
            [
                [
                    'employee_id' => $this->employeeId,
                    'employee_contribution' => 5_000,
                    'relationships' => [[
                        'employment_id' => $this->employmentId,
                        'office_id' => $this->officeId,
                        'capped_base' => 75_000,
                    ]],
                ],
                [
                    'employee_id' => $this->secondEmployeeId,
                    'employee_contribution' => 3_000,
                    'relationships' => [[
                        'employment_id' => $this->secondEmploymentId,
                        'office_id' => $this->secondOfficeId,
                        'capped_base' => 25_000,
                    ]],
                ],
            ],
            true,
        );

        $result = $this->service()->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
        self::assertSame(2, $result['created_count']);
        self::assertCount(2, $result['liability_ids']);

        $byOffice = [];
        foreach ($result['liability_ids'] as $liabilityId) {
            $row = $this->liability($liabilityId);
            $source = $this->jsonObject(
                $this->string($row, 'source_snapshot_json'),
            );
            $byOffice[$this->integer($source, 'payroll_office_id')] = [
                'amount' => $this->integer($row, 'amount_minor'),
                'reference' => $this->string($row, 'liability_reference'),
                'variable_symbol' => $source['variable_symbol'],
                'employee' => $source['employee_contribution_minor'],
                'employer' => $source['employer_contribution_minor'],
            ];
        }
        ksort($byOffice, SORT_NUMERIC);
        self::assertSame(
            [$this->officeId, $this->secondOfficeId],
            array_keys($byOffice),
        );
        // 75 % / 25 % vyměřovacího základu → 18 000 / 6 000 odvodu zaměstnavatele.
        self::assertSame(23_000, $byOffice[$this->officeId]['amount']);
        self::assertSame(5_000, $byOffice[$this->officeId]['employee']);
        self::assertSame(18_000, $byOffice[$this->officeId]['employer']);
        self::assertSame(
            "social-insurance:office:{$this->officeId}",
            $byOffice[$this->officeId]['reference'],
        );
        self::assertSame('0012345678', $byOffice[$this->officeId]['variable_symbol']);
        self::assertSame(9_000, $byOffice[$this->secondOfficeId]['amount']);
        self::assertSame(3_000, $byOffice[$this->secondOfficeId]['employee']);
        self::assertSame(6_000, $byOffice[$this->secondOfficeId]['employer']);
        self::assertSame(
            '0087654321',
            $byOffice[$this->secondOfficeId]['variable_symbol'],
        );
        // Součet závazků = kořenový výsledek; na tom stojí
        // PayrollPostingReconciliationService.
        self::assertSame(
            32_000,
            $byOffice[$this->officeId]['amount']
                + $byOffice[$this->secondOfficeId]['amount'],
        );

        $replay = $this->service()->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
        self::assertSame(0, $replay['created_count']);
        self::assertSame($result['liability_ids'], $replay['liability_ids']);

        /*
         * Obě účtárny platí na týž účet OSSZ, ale pod vlastním variabilním
         * symbolem — v dávce to musí být DVĚ platby. Dokud se seskupovalo jen
         * podle reference příjemce, skončil tenhle výběr chybou.
         */
        $batch = $this->batches->build(
            $this->supplierId,
            'abo',
            "currency:{$this->payerCurrencyId}",
            [
                [
                    'liability_id' => $result['liability_ids'][0],
                    'amount_minor' => $this->integer(
                        $this->liability($result['liability_ids'][0]),
                        'amount_minor',
                    ),
                ],
                [
                    'liability_id' => $result['liability_ids'][1],
                    'amount_minor' => $this->integer(
                        $this->liability($result['liability_ids'][1]),
                        'amount_minor',
                    ),
                ],
            ],
            $this->actorId,
        );
        $symbols = [];
        foreach ($this->batchInstructions($batch['batch_id']) as $instruction) {
            $symbols[] = $instruction['variable_symbol'];
        }
        sort($symbols, SORT_STRING);
        self::assertSame(['0012345678', '0087654321'], $symbols);
    }

    /**
     * Zbytek po celočíselném dělení nesmí zmizet ani přebýt — largest remainder
     * ho přiřadí deterministicky a součet zůstane na haléř roven kořeni.
     */
    public function testOfficeSplitKeepsRoundingRemainderWithinRootTotal(): void
    {
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            0,
            10_001,
            0,
            null,
            0,
            [
                [
                    'employee_id' => $this->employeeId,
                    'employee_contribution' => 0,
                    'relationships' => [[
                        'employment_id' => $this->employmentId,
                        'office_id' => $this->officeId,
                        'capped_base' => 50_000,
                    ]],
                ],
                [
                    'employee_id' => $this->secondEmployeeId,
                    'employee_contribution' => 0,
                    'relationships' => [[
                        'employment_id' => $this->secondEmploymentId,
                        'office_id' => $this->secondOfficeId,
                        'capped_base' => 50_000,
                    ]],
                ],
            ],
            true,
        );

        $result = $this->service()->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
        $total = 0;
        foreach ($result['liability_ids'] as $liabilityId) {
            $total += $this->integer(
                $this->liability($liabilityId),
                'amount_minor',
            );
        }
        self::assertSame(10_001, $total);
    }

    /**
     * Vztah bez účtárny se nesmí tiše přiřadit k cizímu variabilnímu symbolu
     * ani z odvodu vypadnout. Do běhu ho pustí jen historická data — blocker
     * `employment_without_office` ho jinak zastaví už při zamykání vstupů.
     */
    public function testRejectsEmploymentWithoutOffice(): void
    {
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            7_100,
            24_800,
            7_100,
            null,
            0,
            [[
                'employee_id' => $this->employeeId,
                'employee_contribution' => 7_100,
                'relationships' => [[
                    'employment_id' => $this->officelessEmploymentId,
                    'office_id' => null,
                    'capped_base' => 100_000,
                ]],
            ]],
            true,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nemá mzdovou účtárnu');
        $this->service()->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
    }

    /**
     * Osoba se dvěma vztahy ve dvou účtárnách: pojistné zaměstnance je
     * spočítané na osobu, takže se musí rozdělit uvnitř ní, ne padnout celé
     * pod jednu registraci.
     */
    public function testSplitsPersonContributionBetweenTwoOffices(): void
    {
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            6_000,
            12_000,
            0,
            null,
            0,
            [[
                'employee_id' => $this->employeeId,
                'employee_contribution' => 6_000,
                'relationships' => [
                    [
                        'employment_id' => $this->employmentId,
                        'office_id' => $this->officeId,
                        'capped_base' => 60_000,
                    ],
                    [
                        'employment_id' => $this->crossOfficeEmploymentId,
                        'office_id' => $this->secondOfficeId,
                        'capped_base' => 40_000,
                    ],
                ],
            ]],
            true,
        );

        $result = $this->service()->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
        self::assertSame(2, $result['created_count']);
        $amounts = [];
        foreach ($result['liability_ids'] as $liabilityId) {
            $row = $this->liability($liabilityId);
            $source = $this->jsonObject(
                $this->string($row, 'source_snapshot_json'),
            );
            $amounts[$this->integer($source, 'payroll_office_id')] =
                $source['employee_contribution_minor'];
        }
        self::assertSame(3_600, $amounts[$this->officeId]);
        self::assertSame(2_400, $amounts[$this->secondOfficeId]);
    }

    private function service(): PayrollSocialInsuranceLiabilityMaterializer
    {
        return new PayrollSocialInsuranceLiabilityMaterializer(
            new PayrollPaymentLiabilityRepository($this->db),
            new PayrollStatutoryResultRepository($this->db),
            new PayrollInstitutionAccountRepository(
                $this->db,
                $this->sensitiveData,
                new PayrollInstitutionAccountDeletionRepository(
                    $this->db,
                    new ActivityLogger($this->db),
                ),
            ),
            $this->sensitiveData,
            $this->db,
            new PayrollLevyDeadlinePolicy(),
            new PayrollSocialOfficeAllocator(),
        );
    }

    /**
     * @param list<array{
     *   employee_id:int,
     *   employee_contribution:int,
     *   relationships:list<array{employment_id:int,office_id:?int,capped_base:int}>
     * }>|null $layout
     */
    private function createRevision(
        int $revisionNo,
        string $revisionKind,
        ?int $previousRevisionId,
        int $employeeContribution,
        int $employerContribution,
        int $personEmployeeContribution,
        ?int $employerBeforeDiscount = null,
        int $partTimeDiscount = 0,
        ?array $layout = null,
        bool $companyWide = false,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_runs
                SET current_revision_no = ?
              WHERE supplier_id = ? AND id = ?',
        )->execute([$revisionNo, $this->supplierId, $this->runId]);
        $layout ??= [[
            'employee_id' => $this->employeeId,
            'employee_contribution' => $personEmployeeContribution,
            'relationships' => [[
                'employment_id' => $this->employmentId,
                'office_id' => $this->officeId,
                'capped_base' => 100_000,
            ]],
        ]];
        $inputPeople = [];
        foreach ($layout as $person) {
            $employments = [];
            foreach ($person['relationships'] as $relationship) {
                $employments[] = [
                    'employment' => [
                        'id' => $relationship['employment_id'],
                        'employee_id' => $person['employee_id'],
                        'office_id' => $relationship['office_id'],
                    ],
                ];
            }
            $inputPeople[] = [
                'employee' => ['id' => $person['employee_id']],
                'employments' => $employments,
            ];
        }
        $input = CanonicalJson::encode([
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $this->supplierId,
            'period_start' => '2026-06-01',
            'payment_date' => '2026-07-10',
            'office_id' => $companyWide ? null : $this->officeId,
            'people' => $inputPeople,
        ]);
        $result = '{"schema_version":"payroll-run-result.v2"}';
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id,
                 revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, ?, ?, ?, "approved", "payroll-run-input.v2",
                     ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $this->runId,
            $revisionNo,
            $previousRevisionId,
            $revisionKind,
            str_repeat('a', 64),
            $input,
            hash('sha256', $input),
            $result,
            hash('sha256', $result),
            hash(
                'sha256',
                "synthetic-social-revision:{$this->runId}:{$revisionNo}",
                true,
            ),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $personStatement = $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json,
                 result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")',
        );
        $employmentStatement = $pdo->prepare(
            'INSERT INTO payroll_run_employments
                (supplier_id, revision_id, employee_id, employment_id,
                 input_json, input_hash, status)
             VALUES (?, ?, ?, ?, ?, ?, "calculated")',
        );
        foreach ($layout as $person) {
            $personResultJson = json_encode([
                'employee_id' => $person['employee_id'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $personStatement->execute([
                $this->supplierId,
                $revisionId,
                $person['employee_id'],
                $personResultJson,
                hash('sha256', $personResultJson),
            ]);
            foreach ($person['relationships'] as $relationship) {
                $employmentJson = json_encode([
                    'employment_id' => $relationship['employment_id'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $employmentStatement->execute([
                    $this->supplierId,
                    $revisionId,
                    $person['employee_id'],
                    $relationship['employment_id'],
                    $employmentJson,
                    hash('sha256', $employmentJson),
                ]);
            }
        }
        (new PayrollStatutoryResultRepository($this->db))->store(
            $this->supplierId,
            $revisionId,
            'social_insurance',
            'payroll-social-result.v1',
            'calculated',
            'cz-social-2026',
            str_repeat('b', 64),
            $this->jsonObject($input),
            [
                'calculation_date' => '2026-06-30',
                'status' => 'calculated',
                'participating_assessment_base_minor_units' => 100_000,
                'capped_assessment_base_minor_units' => 100_000,
                'employee_contribution_minor_units' =>
                    $employeeContribution,
                'employer_contribution_before_discount_minor_units' =>
                    $employerBeforeDiscount ?? $employerContribution,
                'part_time_discount_assessment_base_minor_units' => 0,
                'part_time_discount_minor_units' => $partTimeDiscount,
                'employer_contribution_minor_units' =>
                    $employerContribution,
                'issues' => [],
                'ruleset_id' => 'cz-social-2026',
                'ruleset_hash' => str_repeat('b', 64),
            ],
            $this->statutoryPeople($layout),
            $this->actorId,
        );

        return $revisionId;
    }

    /**
     * @param list<array{
     *   employee_id:int,
     *   employee_contribution:int,
     *   relationships:list<array{employment_id:int,office_id:?int,capped_base:int}>
     * }> $layout
     * @return list<array<string,mixed>>
     */
    private function statutoryPeople(array $layout): array
    {
        $people = [];
        foreach ($layout as $person) {
            $relationships = [];
            foreach ($person['relationships'] as $relationship) {
                $relationships[] = [
                    'employment_id' => $relationship['employment_id'],
                    'input_snapshot' => [],
                    'result_snapshot' => [
                        'relationship_id' =>
                            "employment:{$relationship['employment_id']}",
                        'capped_assessment_base_minor_units' =>
                            $relationship['capped_base'],
                    ],
                    'result_status' => 'calculated',
                ];
            }
            $people[] = [
                'employee_id' => $person['employee_id'],
                'input_snapshot' => [],
                'relationships' => $relationships,
                'result_snapshot' => [
                    'person_id' => "employee:{$person['employee_id']}",
                    'status' => 'calculated',
                    'employee_contribution_minor_units' =>
                        $person['employee_contribution'],
                ],
                'result_status' => 'calculated',
            ];
        }

        return $people;
    }

    /** @return array<string,mixed> */
    private function liability(int $id): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_payment_liabilities
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $id]);

        return $this->row($statement->fetch(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed> */
    private function jsonObject(string $json): array
    {
        return $this->row(json_decode(
            $json,
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    /** @return array<string,mixed> */
    private function row(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Testovací řádek není pole.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Testovací řádek nemá textové klíče.',
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "Testovací pole {$field} není text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Testovací pole {$field} není číslo.",
            );
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new \UnexpectedValueException(
                "Testovací pole {$field} není platné číslo.",
            );
        }

        return $integer;
    }

    private function createActor(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetický sociální uživatel",
                     "accountant", "cs", 1)',
        )->execute([
            'payroll-social-' . bin2hex(random_bytes(6))
                . '@example.invalid',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function createPayerCurrency(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en,
                 decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, "CZK", "Syntetický CZK účet", "Kč",
                     "Česká koruna", "Czech koruna", 2, 1, 1,
                     "1000000005", "0100")',
        )->execute([$this->supplierId]);

        return (int) $pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    private function batchInstructions(int $batchId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT item_reference, instruction_ciphertext
               FROM payroll_payment_items
              WHERE supplier_id = ? AND batch_id = ?
              ORDER BY id',
        );
        $statement->execute([$this->supplierId, $batchId]);
        $instructions = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $normalized = $this->row($row);
            $instructions[] = $this->jsonObject($this->encryption->decryptFor(
                $this->string($normalized, 'instruction_ciphertext'),
                "payroll-payment-item:{$this->supplierId}:"
                    . $this->string($normalized, 'item_reference'),
            ));
        }

        return $instructions;
    }

    /** @return array<string,mixed> */
    private function batchInstruction(int $batchId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT item_reference, instruction_ciphertext
               FROM payroll_payment_items
              WHERE supplier_id = ? AND batch_id = ?',
        );
        $statement->execute([$this->supplierId, $batchId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $normalized = $this->row($row);
        $json = $this->encryption->decryptFor(
            $this->string($normalized, 'instruction_ciphertext'),
            "payroll-payment-item:{$this->supplierId}:"
                . $this->string($normalized, 'item_reference'),
        );

        return $this->jsonObject($json);
    }
}
