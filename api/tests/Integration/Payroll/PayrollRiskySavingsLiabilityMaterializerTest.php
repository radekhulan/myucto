<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentBatchRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Repository\Payroll\PayrollRiskySavingsRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payment\CzechBankAccountValidator;
use MyInvoice\Service\Payment\IbanValidator;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentBatchBuilder;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentQueryService;
use MyInvoice\Service\Payroll\Payment\PayrollRiskySavingsLiabilityMaterializer;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[Group('integration')]
final class PayrollRiskySavingsLiabilityMaterializerTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRiskySavingsLiabilityMaterializer $materializer;
    private PayrollPaymentBatchBuilder $batches;
    private SecretEncryption $encryption;
    private int $supplierId;
    private int $actorId;
    private int $employmentId;
    private int $runId;
    private int $accountId;
    private int $payerCurrencyId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        $this->encryption = $container->get(SecretEncryption::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT MIN(id) FROM supplier',
        )->fetchColumn();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->actorId = $this->actor($pdo);
        $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "active", "2026-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->actorId]);
        $this->employmentId = $this->employment($pdo);
        $institutions = new PayrollInstitutionAccountRepository(
            $this->db,
            $sensitive,
            new PayrollInstitutionAccountDeletionRepository(
                $this->db,
                new ActivityLogger($this->db),
            ),
        );
        $institutions->create($this->supplierId, [
            'institution_type' => 'other_recipient',
            'institution_code' => 'PENSION1',
            'institution_name' => 'Syntetická penzijní společnost',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => null,
            'specific_symbol' => null,
            'constant_symbol' => '0558',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:pension-account',
            'verified_on' => '2026-07-01',
        ], $this->actorId);
        $account = $institutions->lockEffectivePaymentTargets(
            $this->supplierId,
            'other_recipient',
            'PENSION1',
            'CZK',
            '2026-09-30',
        )[0];
        $this->accountId = $account['id'];
        $this->payerCurrencyId = $this->payerCurrency($pdo);
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, "2026-08-01", "2026-09-15", "approved", 1)',
        )->execute([$this->supplierId]);
        $this->runId = (int) $pdo->lastInsertId();
        $repository = new PayrollRiskySavingsRepository($this->db);
        $this->materializer = new PayrollRiskySavingsLiabilityMaterializer(
            new PayrollPaymentLiabilityRepository($this->db),
            $repository,
            $institutions,
            $sensitive,
        );
        $this->batches = new PayrollPaymentBatchBuilder(
            new PayrollPaymentBatchRepository($this->db),
            $sensitive,
            $this->encryption,
            new IbanValidator(),
            new CzechBankAccountValidator(),
            new MockClock('2026-09-01 10:00:00 Europe/Prague'),
            $container->get(PayrollProductionGate::class),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        $this->db->close();
    }

    public function testCreatesIdempotentBatchableLiabilityAndCorrection(): void
    {
        $regularRevision = $this->revision(1, 'regular', null);
        $this->contribution($regularRevision, 40_000);
        $regular = $this->materializer->materialize(
            $this->supplierId,
            $regularRevision,
            $this->actorId,
        );
        self::assertSame(1, $regular['created_count']);
        $replay = $this->materializer->materialize(
            $this->supplierId,
            $regularRevision,
            $this->actorId,
        );
        self::assertSame(0, $replay['created_count']);
        self::assertSame($regular['liability_ids'], $replay['liability_ids']);
        $listed = (new PayrollPaymentQueryService($this->db))->listForPeriod(
            $this->supplierId,
            '2026-08',
        )['items'];
        self::assertSame('risky_savings', $listed[0]['liability_kind']);
        self::assertSame('ready', $listed[0]['batch_eligibility']);

        $batch = $this->batches->build(
            $this->supplierId,
            'abo',
            "currency:{$this->payerCurrencyId}",
            [[
                'liability_id' => $regular['liability_ids'][0],
                'amount_minor' => 40_000,
            ]],
            $this->actorId,
        );
        $instruction = $this->batchInstruction($batch['batch_id']);
        self::assertSame('7654321', $instruction['variable_symbol']);
        self::assertSame('Povinne sporeni rizikova prace', $instruction['payment_message']);

        $correctionRevision = $this->revision(
            2,
            'correction',
            $regularRevision,
        );
        $this->contribution($correctionRevision, 50_000);
        $correction = $this->materializer->materialize(
            $this->supplierId,
            $correctionRevision,
            $this->actorId,
        );
        self::assertSame(1, $correction['created_count']);
        $liability = $this->liability($correction['liability_ids'][0]);
        self::assertSame('outgoing', $liability['direction']);
        self::assertSame(10_000, (int) $liability['amount_minor']);
    }

    public function testChangedTargetCannotRedirectExistingLiability(): void
    {
        $revision = $this->revision(1, 'regular', null);
        $this->contribution($revision, 40_000);
        $result = $this->materializer->materialize(
            $this->supplierId,
            $revision,
            $this->actorId,
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_institution_accounts
                SET row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->accountId]);

        try {
            $this->materializer->materialize(
                $this->supplierId,
                $revision,
                $this->actorId,
            );
            self::fail('Změněný účet nesmí projít novou materializací.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('po schválení', $exception->getMessage());
        }
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('zmrazenému cíli');
        $this->batches->build(
            $this->supplierId,
            'abo',
            "currency:{$this->payerCurrencyId}",
            [[
                'liability_id' => $result['liability_ids'][0],
                'amount_minor' => 40_000,
            ]],
            $this->actorId,
        );
    }

    public function testNotDueZeroDoesNotCreatePaymentLiability(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_runs
                SET period_start = "2025-12-01", payment_date = "2026-01-15"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->runId]);
        $revision = $this->revision(1, 'regular', null);
        (new PayrollRiskySavingsRepository($this->db))->storeApproved(
            $this->supplierId,
            $revision,
            '2025-12-01',
            [[
                'status' => 'not_due',
                'employment_id' => $this->employmentId,
                'contribution_minor' => 0,
            ]],
        );

        $result = $this->materializer->materialize(
            $this->supplierId,
            $revision,
            $this->actorId,
        );

        self::assertSame([], $result['liability_ids']);
        self::assertSame(0, $result['created_count']);
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_payment_liabilities
              WHERE supplier_id = ? AND revision_id = ?
                AND liability_kind = "risky_savings"',
        );
        $statement->execute([$this->supplierId, $revision]);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    private function revision(
        int $revisionNo,
        string $kind,
        ?int $previousRevisionId,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_runs SET current_revision_no = ?
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
            $kind,
            str_repeat('a', 64),
            $input,
            hash('sha256', $input),
            $result,
            hash('sha256', $result),
            hash('sha256', "synthetic-risky:{$this->runId}:{$revisionNo}", true),
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function contribution(int $revisionId, int $amount): void
    {
        $repository = new PayrollRiskySavingsRepository($this->db);
        $target = $repository->paymentTarget(
            $this->supplierId,
            $this->accountId,
            '2026-09-30',
        );
        $repository->storeApproved(
            $this->supplierId,
            $revisionId,
            '2026-08-01',
            [[
                'status' => 'calculated',
                'employment_id' => $this->employmentId,
                'source_evidence_id' => null,
                'qualifying_shift_eighths' => 24,
                'assessment_base_minor' => 1_000_000,
                'contribution_minor' => $amount,
                'right_claimed_on' => '2026-07-01',
                'pension_company' => 'Syntetická penzijní společnost',
                'institution_account_id' => $this->accountId,
                'institution_account_row_version' =>
                    $target['institution_account_row_version'],
                'institution_account_hash' =>
                    $target['institution_account_hash'],
                'institution_account_masked' =>
                    $target['institution_account_masked'],
                'product_reference' => 'SYNTHETIC-PRODUCT',
                'variable_symbol' => '7654321',
                'specific_symbol' => '42',
                'payment_message' => 'Syntetická reference',
                'payment_due_on' => '2026-09-30',
            ]],
        );
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
        return $row;
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
        $json = $this->encryption->decryptFor(
            (string) $row['instruction_ciphertext'],
            "payroll-payment-item:{$this->supplierId}:{$row['item_reference']}",
        );
        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($value);
        return $value;
    }

    private function employment(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická osoba", "employee", 1)',
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, is_legacy_projection)
             VALUES (?, ?, "SYN-RISK-PAY", "employment", "active",
                     "2026-01-01", 0)',
        )->execute([$this->supplierId, $employeeId]);
        return (int) $pdo->lastInsertId();
    }

    private function actor(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetický uživatel", "accountant", "cs", 1)',
        )->execute([
            'risk-pay-' . bin2hex(random_bytes(5)) . '@example.invalid',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function payerCurrency(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en,
                 decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, "CZK", "Syntetický účet", "Kč",
                     "Česká koruna", "Czech koruna", 2, 1, 1,
                     "1000000005", "0100")',
        )->execute([$this->supplierId]);
        return (int) $pdo->lastInsertId();
    }
}
