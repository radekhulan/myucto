<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRiskySavingsConflictException;
use MyInvoice\Repository\Payroll\PayrollRiskySavingsRepository;
use MyInvoice\Service\Payroll\RiskySavings\PayrollRiskySavingsPolicy;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollRiskySavingsRepositoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRiskySavingsRepository $repository;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;
    private int $employmentId;
    private int $accountId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->repository = $container->get(PayrollRiskySavingsRepository::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn();
        $this->userId = (int) $pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )->fetchColumn();
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí syntetická firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employmentId = $this->employment($pdo);
        $this->accountId = $this->institutionAccount($pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        $this->db->close();
    }

    public function testApprovedEvidenceIsAppendOnlyOptimisticAndTenantSafe(): void
    {
        $target = $this->repository->paymentTarget(
            $this->supplierId,
            $this->accountId,
            '2026-09-30',
        );
        $first = $this->repository->saveEvidence(
            $this->supplierId,
            $this->employmentId,
            '2026-08-01',
            $this->evidence($target, 24, null, null),
            $this->userId,
        );
        self::assertSame(1, (int) $first['revision_no']);
        self::assertSame('approved', $first['status']);

        $second = $this->repository->saveEvidence(
            $this->supplierId,
            $this->employmentId,
            '2026-08-01',
            $this->evidence(
                $target,
                32,
                (int) $first['id'],
                (int) $first['row_version'],
            ),
            $this->userId,
        );
        self::assertNotSame((int) $first['id'], (int) $second['id']);
        self::assertSame(2, (int) $second['revision_no']);

        $statement = $this->db->pdo()->prepare(
            'SELECT qualifying_shift_eighths, status
               FROM payroll_risky_savings_evidence
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$this->supplierId, $first['id']]);
        $historical = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertSame(24, (int) $historical['qualifying_shift_eighths']);
        self::assertSame('superseded', $historical['status']);

        $this->expectException(PayrollRiskySavingsConflictException::class);
        try {
            $this->repository->saveEvidence(
                $this->supplierId,
                $this->employmentId,
                '2026-08-01',
                $this->evidence(
                    $target,
                    40,
                    (int) $first['id'],
                    (int) $first['row_version'],
                ),
                $this->userId,
            );
        } finally {
            try {
                $this->repository->paymentTarget(
                    $this->otherSupplierId,
                    $this->accountId,
                    '2026-09-30',
                );
                self::fail('Cizí firma nesmí přečíst platební cíl.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testApprovedEvidencePinsPaymentTargetVersionAndHash(): void
    {
        $target = $this->repository->paymentTarget(
            $this->supplierId,
            $this->accountId,
            '2026-09-30',
        );
        $saved = $this->repository->saveEvidence(
            $this->supplierId,
            $this->employmentId,
            '2026-08-01',
            $this->evidence($target, 24, null, null),
            $this->userId,
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_institution_accounts
                SET institution_name = "Nový název", row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->accountId]);

        $snapshot = $this->repository->snapshotMany(
            $this->supplierId,
            [$this->employmentId],
            '2026-08-01',
        )[$this->employmentId];
        self::assertSame(
            (int) $saved['institution_account_row_version'],
            (int) $snapshot['institution_account_row_version'],
        );
        self::assertSame(
            $saved['institution_account_hash'],
            $snapshot['institution_account_hash'],
        );
        self::assertContains(
            'risky_savings_payment_target_changed',
            (new PayrollRiskySavingsPolicy())->issues(
                $snapshot,
                '2026-08-01',
            ),
        );
    }

    public function testSnapshotUsesLatestRevisionEvenWhenItIsDraft(): void
    {
        $target = $this->repository->paymentTarget(
            $this->supplierId,
            $this->accountId,
            '2026-09-30',
        );
        $approved = $this->repository->saveEvidence(
            $this->supplierId,
            $this->employmentId,
            '2026-08-01',
            $this->evidence($target, 24, null, null),
            $this->userId,
        );
        $draft = $this->repository->saveEvidence(
            $this->supplierId,
            $this->employmentId,
            '2026-08-01',
            $this->evidence(
                $target,
                32,
                (int) $approved['id'],
                (int) $approved['row_version'],
                'draft',
            ),
            $this->userId,
        );

        $snapshot = $this->repository->snapshotMany(
            $this->supplierId,
            [$this->employmentId],
            '2026-08-01',
        )[$this->employmentId];

        self::assertSame((int) $draft['id'], (int) $snapshot['id']);
        self::assertSame('draft', $snapshot['status']);
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    private function evidence(
        array $target,
        int $eighths,
        ?int $sourceId,
        ?int $rowVersion,
        string $status = 'approved',
    ): array {
        return [
            'status' => $status,
            'source_evidence_id' => $sourceId,
            'row_version' => $rowVersion,
            'risk_factor' => 'vibration',
            'work_category' => 3,
            'qualifying_shift_eighths' => $eighths,
            'right_claimed_on' => '2026-07-31',
            'employee_informed_on' => '2026-07-01',
            'pension_company' => 'Syntetická penzijní společnost',
            'institution_account_id' => $target['institution_account_id'],
            'institution_account_row_version' =>
                $target['institution_account_row_version'],
            'institution_account_hash' => $target['institution_account_hash'],
            'institution_account_masked' => $target['institution_account_masked'],
            'product_reference' => 'SYNTHETIC-PRODUCT',
            'variable_symbol' => '123456',
            'specific_symbol' => null,
            'payment_message' => 'Syntetická platba',
            'evidence_reference' => null,
        ];
    }

    private function employment(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická osoba", "employee", 1)'
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, is_legacy_projection)
             VALUES (?, ?, "SYN-RISK", "employment", "active",
                     "2026-01-01", 0)'
        )->execute([$this->supplierId, $employeeId]);
        return (int) $pdo->lastInsertId();
    }

    private function institutionAccount(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_institutions
                (supplier_id, institution_type, institution_code)
             VALUES (?, "other_recipient", "SYN-PENSION")'
        )->execute([$this->supplierId]);
        $institutionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_institution_accounts
                (supplier_id, institution_id, institution_name,
                 bank_account_ciphertext, bank_account_hash,
                 bank_account_masked, currency_code, variable_symbol,
                 valid_from, source_kind, source_reference, verified_on,
                 verified_by, created_by)
             VALUES (?, ?, "Syntetická penzijní společnost",
                     "enc:v2:synthetic", UNHEX(?), "******0005 / 0100",
                     "CZK", "123456", "2026-01-01", "user_verified",
                     "synthetic:test", "2026-01-01", ?, ?)'
        )->execute([
            $this->supplierId,
            $institutionId,
            str_repeat('a', 64),
            $this->userId,
            $this->userId,
        ]);
        return (int) $pdo->lastInsertId();
    }
}
