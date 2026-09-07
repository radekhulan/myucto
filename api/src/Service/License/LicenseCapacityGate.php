<?php

declare(strict_types=1);

namespace MyInvoice\Service\License;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;

/**
 * Jediná atomická brána pro mutace, které mohou změnit licenční kapacitu.
 *
 * Named lock serializuje uživatele, jejich per-firemní role, oprávnění rolí a
 * zakládání firem i tehdy, když zatím neexistuje žádný řádek vhodný pro FOR UPDATE.
 */
final class LicenseCapacityGate
{
    private const LOCK_SCOPE = 'myucto_license_capacity';
    private const LOCK_TIMEOUT_SECONDS = 10;
    private const SAVEPOINT = 'license_capacity_gate';

    public function __construct(
        private readonly Connection $db,
        private readonly LicenseService $license,
        private readonly SeatPolicy $seats,
        private readonly PayrollUsagePolicy $payroll,
    ) {}

    /**
     * @template T
     * @param callable():T $mutation
     * @return T
     */
    public function mutateSeats(callable $mutation): mixed
    {
        return $this->withLock(function () use ($mutation): mixed {
            $before = $this->seats->countActiveSeats();
            $payrollBefore = $this->payroll->countActiveUsers();

            return $this->transactional(function () use ($mutation, $before, $payrollBefore): mixed {
                $result = $mutation();
                $after = $this->seats->countActiveSeats();
                $payrollAfter = $this->payroll->countActiveUsers();
                $state = $this->license->current()->withActiveUsers($before);
                $reason = $state->seatCountBlockReason($after);
                if ($reason !== null) {
                    throw new LicenseSeatLimitExceeded($reason, $state, $before, $after);
                }
                $state = $state->withPayrollUsage($state->payrollEmployeesActive, $payrollBefore);
                $reason = $state->payrollUserBlockReason($payrollAfter);
                if ($reason !== null) {
                    throw new LicensePayrollLimitExceeded($reason, $state, $payrollBefore, $payrollAfter);
                }

                return $result;
            });
        });
    }

    /**
     * @template T
     * @param callable():T $mutation
     * @return T
     */
    public function mutatePayrollEmployees(callable $mutation): mixed
    {
        return $this->withLock(function () use ($mutation): mixed {
            $before = $this->payroll->countActiveEmployees();

            return $this->transactional(function () use ($mutation, $before): mixed {
                $result = $mutation();
                $after = $this->payroll->countActiveEmployees();
                $state = $this->license->current()->withPayrollUsage(
                    $before,
                    $this->payroll->countActiveUsers(),
                );
                $reason = $state->payrollEmployeeBlockReason($after);
                if ($reason !== null) {
                    throw new LicensePayrollLimitExceeded($reason, $state, $before, $after);
                }

                return $result;
            });
        });
    }

    /**
     * Serializuje kontrolu COUNT(*) a vytvoření právě jedné firmy.
     *
     * @template T
     * @param callable():T $mutation
     * @return T
     */
    public function createCompany(callable $mutation): mixed
    {
        return $this->withLock(function () use ($mutation): mixed {
            $count = $this->db->pdo()->query('SELECT COUNT(*) FROM supplier');
            if ($count === false) {
                throw new \RuntimeException('Počet firem se nepodařilo načíst.');
            }
            $companies = (int) $count->fetchColumn();
            $state = $this->license->current()->withActiveCompanies($companies);
            if (!$state->allowsNewCompany()) {
                throw new LicenseCompanyLimitExceeded($state, $companies);
            }

            return $mutation();
        });
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $lockName = $this->lockName();
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([$lockName, self::LOCK_TIMEOUT_SECONDS]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new \RuntimeException('Licenční kapacitní zámek se nepodařilo získat.');
        }

        try {
            return $callback();
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    private function lockName(): string
    {
        // Scoping podle databáze vznikl původně tady; dnes ho drží
        // {@see NamedLockName} pro všechny named locky v aplikaci.
        return NamedLockName::for($this->db, self::LOCK_SCOPE);
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transactional(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }

        try {
            $result = $callback();
            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            throw $e;
        }
    }
}
