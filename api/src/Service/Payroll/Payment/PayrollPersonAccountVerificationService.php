<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollPersonAccountVerificationService
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   id:int,
     *   bank_account_masked:string,
     *   verification_source:string,
     *   verified_on:string,
     *   verified_by:int,
     *   row_version:int
     * }
     */
    public function verify(
        int $supplierId,
        int $employeeId,
        int $accountId,
        string $verificationSource,
        string $verifiedOn,
        int $actorUserId,
        int $expectedVersion,
    ): array {
        $this->positive($supplierId, 'supplier_id');
        $this->positive($employeeId, 'employee_id');
        $this->positive($accountId, 'account_id');
        $this->positive($actorUserId, 'actor_user_id');
        $this->positive($expectedVersion, 'expected_row_version');
        $source = PayrollPersonAccountVerificationSource::tryFrom(
            $verificationSource,
        );
        if ($source === null) {
            // Hláška musí vypsat přípustné zdroje — bez nich zbývá hádat, a to
            // u kroku, který teprve odemyká výplatu na účet.
            throw new \InvalidArgumentException(
                'Zdroj ověření zaměstnaneckého účtu není podporovaný. Přípustné hodnoty: '
                . implode(', ', array_map(
                    static fn (PayrollPersonAccountVerificationSource $case): string => $case->value,
                    PayrollPersonAccountVerificationSource::cases(),
                )) . '.',
            );
        }
        $this->verifiedDate($verifiedOn);

        $pdo = $this->db->pdo();
        $ownsTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownsTransaction = true;
        }

        try {
            $employeeLock = $pdo->prepare(
                'SELECT id
                   FROM payroll_employees
                  WHERE supplier_id = ? AND id = ?
                  FOR UPDATE'
            );
            $employeeLock->execute([$supplierId, $employeeId]);
            if ($employeeLock->fetchColumn() === false) {
                throw new \DomainException(
                    'Zaměstnanec pro ověření účtu neexistuje.',
                );
            }

            $accountLock = $pdo->prepare(
                'SELECT id, bank_account_masked, is_active, row_version
                   FROM payroll_person_accounts
                  WHERE supplier_id = ? AND employee_id = ? AND id = ?
                  FOR UPDATE'
            );
            $accountLock->execute([$supplierId, $employeeId, $accountId]);
            $row = $accountLock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new \DomainException(
                    'Zaměstnanecký účet pro ověření neexistuje.',
                );
            }
            if ($this->databaseInteger($row['is_active'], 'is_active') !== 1) {
                throw new \DomainException(
                    'Neaktivní zaměstnanecký účet nelze ověřit.',
                );
            }
            $currentVersion = $this->databaseInteger(
                $row['row_version'],
                'row_version',
            );
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollPersonAccountVerificationConflictException(
                    $currentVersion,
                );
            }

            $update = $pdo->prepare(
                'UPDATE payroll_person_accounts
                    SET verification_source = ?,
                        verified_on = ?,
                        verified_by = ?,
                        row_version = row_version + 1
                  WHERE supplier_id = ?
                    AND employee_id = ?
                    AND id = ?
                    AND row_version = ?'
            );
            $update->execute([
                $source->value,
                $verifiedOn,
                $actorUserId,
                $supplierId,
                $employeeId,
                $accountId,
                $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new PayrollPersonAccountVerificationConflictException(
                    $currentVersion,
                );
            }

            $result = [
                'id' => $this->databaseInteger($row['id'], 'id'),
                'bank_account_masked' => $this->databaseString(
                    $row['bank_account_masked'],
                    'bank_account_masked',
                ),
                'verification_source' => $source->value,
                'verified_on' => $verifiedOn,
                'verified_by' => $actorUserId,
                'row_version' => $expectedVersion + 1,
            ];

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $this->rollback($pdo);
            }
            throw $exception;
        }
    }

    private function rollback(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    private function databaseInteger(mixed $value, string $column): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }
        throw new \UnexpectedValueException(
            "Databázový sloupec {$column} neobsahuje celé číslo.",
        );
    }

    private function databaseString(mixed $value, string $column): string
    {
        if (is_string($value)) {
            return $value;
        }
        throw new \UnexpectedValueException(
            "Databázový sloupec {$column} neobsahuje text.",
        );
    }

    private function positive(int $value, string $field): void
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException("{$field} musí být kladné.");
        }
    }

    private function verifiedDate(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(
                'Datum ověření zaměstnaneckého účtu není platné.',
            );
        }
        if ($value > date('Y-m-d')) {
            throw new \InvalidArgumentException(
                'Datum ověření zaměstnaneckého účtu nesmí být v budoucnosti.',
            );
        }
    }
}
