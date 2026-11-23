<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollPaymentTargetSchemaTest extends TestCase
{
    use IsolatedSupplierTrait;

    public function testEmployeePaymentAccountsCarryExplicitVerificationEvidence(): void
    {
        $connection = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        $columns = $connection->pdo()->query(
            'SHOW COLUMNS FROM payroll_person_accounts',
        );
        self::assertInstanceOf(\PDOStatement::class, $columns);
        $names = $columns->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('verification_source', $names);
        self::assertContains('verified_on', $names);
        self::assertContains('verified_by', $names);

        $create = (string) $connection->pdo()->query(
            'SHOW CREATE TABLE payroll_person_accounts',
        )->fetch(PDO::FETCH_NUM)[1];
        self::assertStringContainsString(
            'FOREIGN KEY (`verified_by`)',
            $create,
        );
        $triggers = $connection->pdo()->query('SHOW TRIGGERS');
        self::assertInstanceOf(\PDOStatement::class, $triggers);
        $triggerNames = $triggers->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('trg_payroll_person_account_verify_insert', $triggerNames);
        self::assertContains('trg_payroll_person_account_verify_update', $triggerNames);
        $connection->close();
    }

    public function testPaymentTargetChangeInvalidatesVerificationEvidence(): void
    {
        $connection = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT MIN(id) FROM supplier',
        )->fetchColumn();
        $userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        self::assertGreaterThan(0, $userId);

        $pdo->beginTransaction();
        try {
            $supplierId = $this->createIsolatedSupplier(
                $pdo,
                $sourceSupplierId,
            );
            $pdo->prepare(
                'INSERT INTO payroll_employees
                    (supplier_id, full_name, taxpayer_type, is_active)
                 VALUES (?, "Syntetický příjemce", "employee", 1)',
            )->execute([$supplierId]);
            $employeeId = (int) $pdo->lastInsertId();

            $this->assertDatabaseFailure(
                fn () => $pdo->prepare(
                    'INSERT INTO payroll_person_accounts
                        (supplier_id, employee_id, label,
                         bank_account_ciphertext, bank_account_hash,
                         bank_account_masked, effective_from,
                         verification_source)
                     VALUES (?, ?, "Neúplné ověření", ?, ?, "••••0005/0100",
                             "2099-01-01", "user_verified")',
                )->execute([
                    $supplierId,
                    $employeeId,
                    'enc:v2:synthetic-incomplete',
                    hash('sha256', 'synthetic-incomplete', true),
                ]),
                'Payroll person account verification is incomplete',
            );

            $pdo->prepare(
                'INSERT INTO payroll_person_accounts
                    (supplier_id, employee_id, label,
                     bank_account_ciphertext, bank_account_hash,
                     bank_account_masked, effective_from,
                     verification_source, verified_on, verified_by)
                 VALUES (?, ?, "Ověřený účet", ?, ?, "••••0005/0100",
                         "2099-01-01", "user_verified", "2099-01-02", ?)',
            )->execute([
                $supplierId,
                $employeeId,
                'enc:v2:synthetic-original',
                hash('sha256', 'synthetic-original', true),
                $userId,
            ]);
            $accountId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'UPDATE payroll_person_accounts
                    SET bank_account_ciphertext = ?,
                        bank_account_hash = ?,
                        bank_account_masked = "••••1116/0100"
                  WHERE supplier_id = ? AND id = ?',
            )->execute([
                'enc:v2:synthetic-changed',
                hash('sha256', 'synthetic-changed', true),
                $supplierId,
                $accountId,
            ]);
            $row = $pdo->query(
                "SELECT verification_source, verified_on, verified_by
                   FROM payroll_person_accounts
                  WHERE supplier_id = {$supplierId}
                    AND id = {$accountId}",
            )->fetch(PDO::FETCH_ASSOC);
            self::assertSame([
                'verification_source' => null,
                'verified_on' => null,
                'verified_by' => null,
            ], $row);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }
    }

    /** @param callable(): mixed $operation */
    private function assertDatabaseFailure(
        callable $operation,
        string $message,
    ): void {
        try {
            $operation();
            self::fail("Expected database failure containing: {$message}");
        } catch (PDOException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }
}
