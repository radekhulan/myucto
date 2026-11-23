<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Payment\PayrollPersonAccountVerificationConflictException;
use MyInvoice\Service\Payroll\Payment\PayrollPersonAccountVerificationService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollPersonAccountVerificationServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollPersonAccountVerificationService $service;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $otherEmployeeId;
    private int $accountId;
    private int $otherAccountId;
    private int $actorUserId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }

        try {
            $this->db = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(Connection::class);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $exception->getMessage());
        }
        if (!$this->db->hasTable('payroll_person_accounts')
            || !$this->db->hasColumn(
                'payroll_person_accounts',
                'verification_source',
            )
        ) {
            $this->markTestSkipped('Migrace 1271 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        $this->actorUserId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->actorUserId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employeeId = $this->insertEmployee(
            $this->supplierId,
            'Syntetický Zaměstnanec A',
        );
        $this->otherEmployeeId = $this->insertEmployee(
            $this->otherSupplierId,
            'Syntetický Zaměstnanec B',
        );
        $this->accountId = $this->insertAccount(
            $this->supplierId,
            $this->employeeId,
            '••••0005/0100',
            'synthetic-account-a',
        );
        $this->otherAccountId = $this->insertAccount(
            $this->otherSupplierId,
            $this->otherEmployeeId,
            '••••0013/0100',
            'synthetic-account-b',
        );
        $this->service = new PayrollPersonAccountVerificationService($this->db);
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

    public function testVerifiesOwnedActiveAccountAndReturnsOnlySafeMetadata(): void
    {
        $verified = $this->service->verify(
            $this->supplierId,
            $this->employeeId,
            $this->accountId,
            'employee_confirmation',
            date('Y-m-d'),
            $this->actorUserId,
            1,
        );

        self::assertSame([
            'id',
            'bank_account_masked',
            'verification_source',
            'verified_on',
            'verified_by',
            'row_version',
        ], array_keys($verified));
        self::assertSame($this->accountId, $verified['id']);
        self::assertSame('••••0005/0100', $verified['bank_account_masked']);
        self::assertSame('employee_confirmation', $verified['verification_source']);
        self::assertSame(date('Y-m-d'), $verified['verified_on']);
        self::assertSame($this->actorUserId, $verified['verified_by']);
        self::assertSame(2, $verified['row_version']);
        self::assertArrayNotHasKey('bank_account_ciphertext', $verified);
        self::assertArrayNotHasKey('bank_account_hash', $verified);
        self::assertArrayNotHasKey('bank_account', $verified);
    }

    public function testAccountHashChangeClearsVerificationThroughTrigger(): void
    {
        $this->service->verify(
            $this->supplierId,
            $this->employeeId,
            $this->accountId,
            'bank_document',
            date('Y-m-d'),
            $this->actorUserId,
            1,
        );

        $this->db->pdo()->prepare(
            'UPDATE payroll_person_accounts
                SET bank_account_hash = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND employee_id = ? AND id = ?'
        )->execute([
            hash('sha256', 'synthetic-account-changed', true),
            $this->supplierId,
            $this->employeeId,
            $this->accountId,
        ]);

        $row = $this->accountVerification($this->supplierId, $this->accountId);
        self::assertNull($row['verification_source']);
        self::assertNull($row['verified_on']);
        self::assertNull($row['verified_by']);
        self::assertSame(3, (int) $row['row_version']);
    }

    public function testRejectsCrossTenantAccountWithoutChangingIt(): void
    {
        try {
            $this->service->verify(
                $this->supplierId,
                $this->employeeId,
                $this->otherAccountId,
                'user_verified',
                date('Y-m-d'),
                $this->actorUserId,
                1,
            );
            self::fail('Cizí tenant nesmí ověřit účet.');
        } catch (\DomainException) {
            $row = $this->accountVerification(
                $this->otherSupplierId,
                $this->otherAccountId,
            );
            self::assertNull($row['verification_source']);
            self::assertSame(1, (int) $row['row_version']);
        }
    }

    public function testRejectsStaleExpectedVersion(): void
    {
        $this->service->verify(
            $this->supplierId,
            $this->employeeId,
            $this->accountId,
            'user_verified',
            date('Y-m-d'),
            $this->actorUserId,
            1,
        );

        try {
            $this->service->verify(
                $this->supplierId,
                $this->employeeId,
                $this->accountId,
                'bank_document',
                date('Y-m-d'),
                $this->actorUserId,
                1,
            );
            self::fail('Zastaralá verze nesmí změnit ověření.');
        } catch (PayrollPersonAccountVerificationConflictException $exception) {
            self::assertSame(2, $exception->currentVersion);
        }
    }

    public function testRejectsInvalidSourceAndDates(): void
    {
        foreach ([
            ['unknown_source', date('Y-m-d')],
            ['user_verified', '2026-02-30'],
            ['user_verified', date('Y-m-d', strtotime('+1 day'))],
        ] as [$source, $verifiedOn]) {
            try {
                $this->service->verify(
                    $this->supplierId,
                    $this->employeeId,
                    $this->accountId,
                    $source,
                    $verifiedOn,
                    $this->actorUserId,
                    1,
                );
                self::fail('Neplatný zdroj nebo datum nesmí ověřit účet.');
            } catch (\InvalidArgumentException) {
                self::assertNull(
                    $this->accountVerification(
                        $this->supplierId,
                        $this->accountId,
                    )['verification_source'],
                );
            }
        }
    }

    public function testRejectsInactiveAccountAndInvalidActor(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_accounts SET is_active = 0
              WHERE supplier_id = ? AND employee_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employeeId, $this->accountId]);

        try {
            $this->service->verify(
                $this->supplierId,
                $this->employeeId,
                $this->accountId,
                'user_verified',
                date('Y-m-d'),
                $this->actorUserId,
                1,
            );
            self::fail('Neaktivní účet nesmí být ověřen.');
        } catch (\DomainException) {
            self::assertNull(
                $this->accountVerification(
                    $this->supplierId,
                    $this->accountId,
                )['verification_source'],
            );
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->service->verify(
            $this->otherSupplierId,
            $this->otherEmployeeId,
            $this->otherAccountId,
            'user_verified',
            date('Y-m-d'),
            0,
            1,
        );
    }

    private function insertEmployee(int $supplierId, string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        );
        $stmt->execute([$supplierId, $name]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertAccount(
        int $supplierId,
        int $employeeId,
        string $masked,
        string $fingerprint,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_accounts
                (supplier_id, employee_id, label, bank_account_ciphertext,
                 bank_account_hash, bank_account_masked,
                 allocation_basis_points, effective_from, is_active)
             VALUES (?, ?, "Syntetický účet", ?, ?, ?, 10000, "2026-01-01", 1)'
        );
        $stmt->execute([
            $supplierId,
            $employeeId,
            'enc:v2:' . $fingerprint,
            hash('sha256', $fingerprint, true),
            $masked,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function accountVerification(int $supplierId, int $accountId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT verification_source, verified_on, verified_by, row_version
               FROM payroll_person_accounts
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Syntetický účet se nepodařilo načíst.');
        }
        return $row;
    }
}
