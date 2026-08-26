<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentBatchRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payment\CzechBankAccountValidator;
use MyInvoice\Service\Payment\IbanValidator;
use MyInvoice\Service\Payroll\Deadline\PayrollLevyDeadlinePolicy;
use MyInvoice\Service\Payroll\Payment\PayrollHealthInsuranceLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentBatchBuilder;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentQueryService;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[Group('integration')]
final class PayrollHealthInsuranceLiabilityMaterializerTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollHealthInsuranceLiabilityMaterializer $materializer;
    private PayrollPaymentBatchBuilder $batches;
    private SecretEncryption $encryption;
    private PayrollSensitiveData $sensitiveData;
    private PayrollInstitutionAccountRepository $institutionAccounts;
    private int $supplierId;
    private int $actorId;
    private int $runId;
    private int $payerCurrencyId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        $encryption = $container->get(SecretEncryption::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        $this->db = $connection;
        $this->encryption = $encryption;
        $this->sensitiveData = $sensitive;
        $pdo = $connection->pdo();
        $sourceSupplier = $pdo->query(
            'SELECT MIN(id) FROM supplier',
        );
        self::assertInstanceOf(\PDOStatement::class, $sourceSupplier);
        $sourceSupplierId = (int) $sourceSupplier->fetchColumn();
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
        $institutionRepository = new PayrollInstitutionAccountRepository(
            $connection,
            $sensitive,
            new PayrollInstitutionAccountDeletionRepository(
                $connection,
                new ActivityLogger($connection),
            ),
        );
        $this->institutionAccounts = $institutionRepository;
        $institutionRepository->create($this->supplierId, [
            'institution_type' => 'health_insurer',
            'institution_code' => '111',
            'institution_name' => 'Syntetická zdravotní pojišťovna',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => '1234567890',
            'specific_symbol' => '2468',
            'constant_symbol' => '0558',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:official-account-notice',
            'verified_on' => '2026-06-15',
        ], $this->actorId);
        $this->payerCurrencyId = $this->createPayerCurrency($pdo);
        $pdo->prepare(
            'UPDATE supplier
                SET company_name = "Syntetická mzdová firma",
                    display_name = NULL
              WHERE id = ?',
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, "2026-06-01", "2026-07-10", "approved", 1)',
        )->execute([$this->supplierId]);
        $this->runId = (int) $pdo->lastInsertId();
        $liabilityRepository = new PayrollPaymentLiabilityRepository(
            $connection,
        );
        $this->materializer =
            new PayrollHealthInsuranceLiabilityMaterializer(
                $liabilityRepository,
                new PayrollStatutoryResultRepository($connection),
                $institutionRepository,
                $sensitive,
                new PayrollLevyDeadlinePolicy(),
            );
        $this->batches = new PayrollPaymentBatchBuilder(
            new PayrollPaymentBatchRepository($connection),
            $sensitive,
            $encryption,
            new IbanValidator(),
            new CzechBankAccountValidator(),
            new MockClock('2026-07-01 10:00:00 Europe/Prague'),
            $container->get(PayrollProductionGate::class),
        );
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

    public function testMaterializesFrozenInsurerTargetAndIncomingCorrection(): void
    {
        $regularRevision = $this->createRevision(
            1,
            'regular',
            null,
            13_500,
        );
        $regular = $this->materializer->materialize(
            $this->supplierId,
            $regularRevision,
            $this->actorId,
        );
        self::assertSame(1, $regular['created_count']);
        $outgoing = $this->liability($regular['liability_ids'][0]);
        self::assertSame('health_insurance', $outgoing['liability_kind']);
        self::assertSame('outgoing', $outgoing['direction']);
        self::assertSame('2026-07-20', $outgoing['due_on']);
        self::assertNull($outgoing['employee_id']);
        self::assertStringNotContainsString(
            '1000000005',
            $this->stringValue($outgoing, 'source_snapshot_json'),
        );

        $batch = $this->batches->build(
            $this->supplierId,
            'abo',
            "currency:{$this->payerCurrencyId}",
            [[
                'liability_id' => $regular['liability_ids'][0],
                'amount_minor' => 13_500,
            ]],
            $this->actorId,
        );
        $instruction = $this->batchInstruction($batch['batch_id']);
        self::assertSame('1234567890', $instruction['variable_symbol']);
        self::assertSame('2468', $instruction['specific_symbol']);
        self::assertSame('0558', $instruction['constant_symbol']);

        $correctionRevision = $this->createRevision(
            2,
            'correction',
            $regularRevision,
            0,
        );
        $correction = $this->materializer->materialize(
            $this->supplierId,
            $correctionRevision,
            $this->actorId,
        );
        self::assertSame(1, $correction['created_count']);
        $incoming = $this->liability($correction['liability_ids'][0]);
        self::assertSame('incoming', $incoming['direction']);
        self::assertSame(
            13_500,
            $this->integerValue($incoming, 'amount_minor'),
        );
        $listed = (new PayrollPaymentQueryService($this->db))
            ->listForPeriod($this->supplierId, '2026-06')['items'];
        self::assertCount(2, $listed);
        self::assertSame('ready', $listed[0]['batch_eligibility']);
        self::assertNull($listed[0]['batch_block_reason']);
        self::assertSame('blocked', $listed[1]['batch_eligibility']);
        self::assertSame(
            'unsupported_direction',
            $listed[1]['batch_block_reason'],
        );
        self::assertSame('correction', $listed[1]['revision_kind']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Příchozí opravný závazek');
        $this->batches->build(
            $this->supplierId,
            'abo',
            "currency:{$this->payerCurrencyId}",
            [[
                'liability_id' => $correction['liability_ids'][0],
                'amount_minor' => 13_500,
            ]],
            $this->actorId,
        );
    }

    public function testDueDateUsesPayrollPeriodWhenHistoricalRunIsCalculatedLater(): void
    {
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            13_500,
            calculationDate: '2026-08-04',
        );

        $result = $this->materializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );

        self::assertSame(
            '2026-07-20',
            $this->liability($result['liability_ids'][0])['due_on'],
        );
    }

    public function testFailsClosedForManualReviewAndMissingTarget(): void
    {
        $manualRevision = $this->createRevision(
            1,
            'regular',
            null,
            13_500,
            '111',
            'manual_review',
        );
        try {
            $this->materializer->materialize(
                $this->supplierId,
                $manualRevision,
                $this->actorId,
            );
            self::fail('Ruční kontrola nesmí vytvořit závazek.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'bez ruční kontroly',
                $exception->getMessage(),
            );
        }

        $this->db->pdo()->prepare(
            'UPDATE payroll_runs SET current_revision_no = 2
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->runId]);
        $missingRevision = $this->createRevision(
            2,
            'correction',
            $manualRevision,
            13_500,
            '201',
        );
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nemá k datu splatnosti ověřený účet');
        $this->materializer->materialize(
            $this->supplierId,
            $missingRevision,
            $this->actorId,
        );
    }

    public function testChangedInstitutionTargetCannotEnterBatch(): void
    {
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            13_500,
        );
        $result = $this->materializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_institution_accounts
                SET variable_symbol = "999", row_version = row_version + 1
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('zmrazenému cíli');
        $this->batches->build(
            $this->supplierId,
            'abo',
            "currency:{$this->payerCurrencyId}",
            [[
                'liability_id' => $result['liability_ids'][0],
                'amount_minor' => 13_500,
            ]],
            $this->actorId,
        );
    }

    public function testCorrectionRejectsChangedFrozenTarget(): void
    {
        $regularRevision = $this->createRevision(
            1,
            'regular',
            null,
            13_500,
        );
        $this->materializer->materialize(
            $this->supplierId,
            $regularRevision,
            $this->actorId,
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_institution_accounts
                SET specific_symbol = "9999",
                    row_version = row_version + 1
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
        $correctionRevision = $this->createRevision(
            2,
            'correction',
            $regularRevision,
            15_000,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('se proti předchozímu závazku změnil');
        $this->materializer->materialize(
            $this->supplierId,
            $correctionRevision,
            $this->actorId,
        );
    }

    public function testTwoInsurersMustExactlyMatchRootTotalAndStayTenantScoped(): void
    {
        $this->createInstitutionAccount('201', '555666777', '1111', '0308');
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            20_000,
            insurerAmounts: [
                '111' => 13_500,
                '201' => 6_500,
            ],
        );
        $result = $this->materializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
        self::assertSame(2, $result['created_count']);
        $first = $this->liability($result['liability_ids'][0]);
        $second = $this->liability($result['liability_ids'][1]);
        self::assertSame(
            ['health-insurance:i111', 'health-insurance:i201'],
            [
                $first['liability_reference'],
                $second['liability_reference'],
            ],
        );
        foreach ([$first, $second] as $row) {
            $source = $this->stringValue($row, 'source_snapshot_json');
            self::assertStringNotContainsString('1000000005', $source);
            self::assertStringNotContainsString(
                'Syntetická zdravotní pojišťovna',
                $source,
            );
            self::assertStringNotContainsString('bank_account_ciphertext', $source);
        }
        $listed = (new PayrollPaymentQueryService($this->db))
            ->listForPeriod($this->supplierId, '2026-06')['items'];
        self::assertCount(2, $listed);
        self::assertSame(
            [
                'Syntetická zdravotní pojišťovna',
                'Syntetická pojišťovna 201',
            ],
            array_column($listed, 'recipient_name'),
        );
        self::assertSame(
            ['111', '201'],
            array_column($listed, 'institution_code'),
        );
        foreach ($listed as $item) {
            self::assertSame('health_insurer', $item['institution_type']);
            self::assertSame('ready', $item['payment_target_status']);
            self::assertSame('ready', $item['batch_eligibility']);
            self::assertNotNull($item['payment_target_masked']);
            self::assertArrayNotHasKey('recipient_reference', $item);
            self::assertArrayNotHasKey('source_snapshot_json', $item);
            self::assertArrayNotHasKey('source_snapshot_hash', $item);
            self::assertArrayNotHasKey('bank_account_hash', $item);
        }

        $badRevision = $this->createRevision(
            2,
            'correction',
            $revisionId,
            20_001,
            insurerAmounts: [
                '111' => 13_500,
                '201' => 6_500,
            ],
        );
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('neodpovídá kořenovému výsledku');
        $this->materializer->materialize(
            $this->supplierId,
            $badRevision,
            $this->actorId,
        );
    }

    public function testMissingLocalTargetDoesNotUseOtherTenantAccount(): void
    {
        $sourceSupplier = $this->db->pdo()->query(
            'SELECT MIN(id) FROM supplier',
        );
        self::assertInstanceOf(\PDOStatement::class, $sourceSupplier);
        $otherSupplierId = $this->createIsolatedSupplier(
            $this->db->pdo(),
            (int) $sourceSupplier->fetchColumn(),
        );
        (new PayrollInstitutionAccountRepository(
            $this->db,
            $this->sensitiveData,
            new PayrollInstitutionAccountDeletionRepository(
                $this->db,
                new ActivityLogger($this->db),
            ),
        ))->create($otherSupplierId, [
            'institution_type' => 'health_insurer',
            'institution_code' => '201',
            'institution_name' => 'Jiná syntetická pojišťovna',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => '987654321',
            'specific_symbol' => null,
            'constant_symbol' => '0558',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:other-tenant',
            'verified_on' => '2026-06-15',
        ], $this->actorId);
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            13_500,
            '201',
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nemá k datu splatnosti ověřený účet');
        $this->materializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
    }

    public function testUnverifiedExpiredAndAmbiguousTargetsFailClosed(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_institution_accounts
                SET source_kind = "imported",
                    row_version = row_version + 1
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
        $unverifiedRevision = $this->createRevision(
            1,
            'regular',
            null,
            13_500,
        );
        try {
            $this->materializer->materialize(
                $this->supplierId,
                $unverifiedRevision,
                $this->actorId,
            );
            self::fail('Neověřený účet nesmí vytvořit závazek.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'úplné a účinné ověření',
                $exception->getMessage(),
            );
        }

        $this->db->pdo()->prepare(
            'UPDATE payroll_institution_accounts
                SET source_kind = "official_document",
                    verified_by = ?, valid_to = "2026-07-19",
                    row_version = row_version + 1
              WHERE supplier_id = ?',
        )->execute([$this->actorId, $this->supplierId]);
        $expiredRevision = $this->createRevision(
            2,
            'correction',
            $unverifiedRevision,
            13_500,
        );
        try {
            $this->materializer->materialize(
                $this->supplierId,
                $expiredRevision,
                $this->actorId,
            );
            self::fail('Prošlý účet nesmí vytvořit závazek.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'nemá k datu splatnosti',
                $exception->getMessage(),
            );
        }

        $this->db->pdo()->prepare(
            'UPDATE payroll_institution_accounts
                SET valid_to = NULL, row_version = row_version + 1
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
        $this->insertAmbiguousAccount();
        $ambiguousRevision = $this->createRevision(
            3,
            'correction',
            $expiredRevision,
            13_500,
        );
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nejednoznačný účet');
        $this->materializer->materialize(
            $this->supplierId,
            $ambiguousRevision,
            $this->actorId,
        );
    }

    public function testCorrectionsIncreaseDecreaseDisappearAndReplay(): void
    {
        $regularRevision = $this->createRevision(
            1,
            'regular',
            null,
            13_500,
        );
        $regular = $this->materializer->materialize(
            $this->supplierId,
            $regularRevision,
            $this->actorId,
        );
        self::assertSame(0, $this->materializer->materialize(
            $this->supplierId,
            $regularRevision,
            $this->actorId,
        )['created_count']);

        $increaseRevision = $this->createRevision(
            2,
            'correction',
            $regularRevision,
            15_000,
        );
        $increase = $this->materializer->materialize(
            $this->supplierId,
            $increaseRevision,
            $this->actorId,
        );
        $increaseRow = $this->liability($increase['liability_ids'][0]);
        self::assertSame('outgoing', $increaseRow['direction']);
        self::assertSame(1_500, $this->integerValue(
            $increaseRow,
            'amount_minor',
        ));

        $decreaseRevision = $this->createRevision(
            3,
            'correction',
            $increaseRevision,
            12_000,
        );
        $decrease = $this->materializer->materialize(
            $this->supplierId,
            $decreaseRevision,
            $this->actorId,
        );
        $decreaseRow = $this->liability($decrease['liability_ids'][0]);
        self::assertSame('incoming', $decreaseRow['direction']);
        self::assertSame(3_000, $this->integerValue(
            $decreaseRow,
            'amount_minor',
        ));

        $disappearRevision = $this->createRevision(
            4,
            'correction',
            $decreaseRevision,
            0,
        );
        $disappear = $this->materializer->materialize(
            $this->supplierId,
            $disappearRevision,
            $this->actorId,
        );
        $disappearRow = $this->liability($disappear['liability_ids'][0]);
        self::assertSame('incoming', $disappearRow['direction']);
        self::assertSame(12_000, $this->integerValue(
            $disappearRow,
            'amount_minor',
        ));
        self::assertSame(0, $this->materializer->materialize(
            $this->supplierId,
            $disappearRevision,
            $this->actorId,
        )['created_count']);
        self::assertSame(
            $regular['liability_ids'][0],
            $this->integerValue($increaseRow, 'previous_liability_id'),
        );
    }

    public function testOverReversedEarlierChainFailsClosed(): void
    {
        $regularRevision = $this->createRevision(
            1,
            'regular',
            null,
            13_500,
        );
        $regular = $this->materializer->materialize(
            $this->supplierId,
            $regularRevision,
            $this->actorId,
        );
        $zeroRevision = $this->createRevision(
            2,
            'correction',
            $regularRevision,
            0,
        );
        $zero = $this->materializer->materialize(
            $this->supplierId,
            $zeroRevision,
            $this->actorId,
        );
        $incoming = $this->liability($zero['liability_ids'][0]);
        $corruptRevision = $this->createRevision(
            3,
            'correction',
            $zeroRevision,
            0,
        );
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id,
                 liability_reference, liability_kind, direction,
                 recipient_reference, due_on, currency_code, amount_minor,
                 previous_liability_id, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash, created_by)
             VALUES (?, ?, NULL, ?, "health_insurance", "incoming", ?,
                     ?, "CZK", 1, ?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $corruptRevision,
            $incoming['liability_reference'],
            $incoming['recipient_reference'],
            $incoming['due_on'],
            $zero['liability_ids'][0],
            $incoming['source_snapshot_json'],
            $incoming['source_snapshot_hash'],
            random_bytes(32),
            $this->actorId,
        ]);
        $nextRevision = $this->createRevision(
            4,
            'correction',
            $corruptRevision,
            0,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Dřívější zdravotní závazky mají záporný zůstatek.',
        );
        $this->materializer->materialize(
            $this->supplierId,
            $nextRevision,
            $this->actorId,
        );
        self::assertSame(1, count($regular['liability_ids']));
    }

    /** @param array<int|string,int>|null $insurerAmounts */
    private function createRevision(
        int $revisionNo,
        string $revisionKind,
        ?int $previousRevisionId,
        int $total,
        string $insurerCode = '111',
        string $status = 'calculated',
        ?array $insurerAmounts = null,
        string $calculationDate = '2026-06-30',
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_runs
                SET current_revision_no = ?, status = "approved"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$revisionNo, $this->supplierId, $this->runId]);
        $input = '{"schema_version":"payroll-run-input.v2"}';
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
                "synthetic-health-revision:{$this->runId}:{$revisionNo}",
                true,
            ),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $insurerAmounts ??= $total === 0 ? [] : [$insurerCode => $total];
        $insurers = [];
        foreach ($insurerAmounts as $code => $amount) {
            $normalizedCode = (string) $code;
            $insurers[] = [
                'insurer_code' => $normalizedCode,
                'person_count' => 1,
                'assessment_base_minor_units' => 100_000,
                'employee_contribution_minor_units' => 4_500,
                'employer_contribution_minor_units' => 9_000,
                'total_contribution_minor_units' => $amount,
            ];
        }
        (new PayrollStatutoryResultRepository($this->db))->store(
            $this->supplierId,
            $revisionId,
            'health_insurance',
            'payroll-health-result.v1',
            $status,
            'cz-health-2026',
            str_repeat('b', 64),
            ['schema_version' => 'payroll-run-input.v2'],
            [
                'calculation_date' => $calculationDate,
                'status' => $status,
                'assessment_base_minor_units' =>
                    $total === 0 ? 0 : 100_000,
                'employee_contribution_minor_units' =>
                    $total === 0 ? 0 : 4_500,
                'employer_contribution_minor_units' =>
                    $total === 0 ? 0 : 9_000,
                'total_contribution_minor_units' => $total,
                'insurer_liabilities' => $insurers,
                'issues' => [],
                'ruleset_id' => 'cz-health-2026',
                'ruleset_hash' => str_repeat('b', 64),
            ],
            [],
            $this->actorId,
        );

        return $revisionId;
    }

    private function createInstitutionAccount(
        string $code,
        string $variableSymbol,
        string $specificSymbol,
        string $constantSymbol,
    ): void {
        $this->institutionAccounts->create($this->supplierId, [
            'institution_type' => 'health_insurer',
            'institution_code' => $code,
            'institution_name' => "Syntetická pojišťovna {$code}",
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => $variableSymbol,
            'specific_symbol' => $specificSymbol,
            'constant_symbol' => $constantSymbol,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => "synthetic:account:{$code}",
            'verified_on' => '2026-06-15',
        ], $this->actorId);
    }

    private function insertAmbiguousAccount(): void
    {
        $institution = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_institutions
              WHERE supplier_id = ?
                AND institution_type = "health_insurer"
                AND institution_code = "111"',
        );
        $institution->execute([$this->supplierId]);
        $institutionId = $institution->fetchColumn();
        if (!is_int($institutionId) && !is_string($institutionId)) {
            throw new \UnexpectedValueException(
                'Testovací instituce nebyla nalezena.',
            );
        }
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_institution_accounts
                (supplier_id, institution_id, institution_name,
                 bank_account_ciphertext, bank_account_hash,
                 bank_account_masked, currency_code, variable_symbol,
                 specific_symbol, constant_symbol, valid_from, valid_to,
                 source_kind, source_reference, verified_on, verified_by,
                 created_by, updated_by, row_version)
             VALUES (?, ?, "Duplicitní syntetická pojišťovna",
                     "pending:v1", ?, "••••", "CZK", "1234567890",
                     "2468", "0558", "2026-01-01", NULL,
                     "official_document", "synthetic:duplicate",
                     "2026-06-15", ?, ?, ?, 1)',
        )->execute([
            $this->supplierId,
            (int) $institutionId,
            random_bytes(32),
            $this->actorId,
            $this->actorId,
            $this->actorId,
        ]);
        $accountId = (int) $this->db->pdo()->lastInsertId();
        $sealed = $this->sensitiveData->seal(
            '1000000005/0100',
            \MyInvoice\Service\Payroll\Security\PayrollSensitiveField::BANK_ACCOUNT,
            $this->supplierId,
            $accountId,
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_institution_accounts
                SET bank_account_ciphertext = ?, bank_account_hash = ?,
                    bank_account_masked = ?
              WHERE supplier_id = ? AND id = ?',
        )->execute([
            $sealed->ciphertext,
            $sealed->lookupHash,
            $sealed->masked,
            $this->supplierId,
            $accountId,
        ]);
    }

    /** @return array<string,mixed> */
    private function liability(int $id): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_payment_liabilities
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $this->row($row);
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
            $this->stringValue($normalized, 'instruction_ciphertext'),
            "payroll-payment-item:{$this->supplierId}:"
                . $this->stringValue($normalized, 'item_reference'),
        );
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $this->row($decoded);
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

    private function createActor(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetický zdravotní uživatel",
                     "accountant", "cs", 1)',
        )->execute([
            'payroll-health-' . bin2hex(random_bytes(6))
                . '@example.invalid',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function row(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException(
                'Testovací databázový řádek není pole.',
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Testovací databázový řádek nemá textové klíče.',
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private function stringValue(array $row, string $field): string
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
    private function integerValue(array $row, string $field): int
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
}
