<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollModuleStateRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   supplier_id:int,status:string,start_period:?string,row_version:int,
     *   activated_at:?string,suspended_at:?string,created_at:?string,updated_at:?string
     * }
     */
    public function get(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT supplier_id, status, start_period, row_version, activated_at,
                    suspended_at, created_at, updated_at
               FROM payroll_module_state
              WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return [
                'supplier_id' => $supplierId,
                'status' => 'disabled',
                'start_period' => null,
                'row_version' => 0,
                'activated_at' => null,
                'suspended_at' => null,
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        return $this->cast($row);
    }

    /**
     * @return array{
     *   supplier_id:int,status:string,start_period:?string,row_version:int,
     *   activated_at:?string,suspended_at:?string,created_at:?string,updated_at:?string
     * }
     */
    public function setActivation(
        int $supplierId,
        bool $enabled,
        ?string $startPeriod,
        int $expectedVersion,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $select = $pdo->prepare(
                'SELECT status, start_period, row_version
                   FROM payroll_module_state
                  WHERE supplier_id = ?
                  FOR UPDATE'
            );
            $select->execute([$supplierId]);
            $current = $select->fetch(PDO::FETCH_ASSOC);
            $currentVersion = is_array($current) ? (int) $current['row_version'] : 0;
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollStateConflictException($currentVersion);
            }
            $currentStatus = is_array($current) ? (string) $current['status'] : null;
            if (!$enabled && in_array(
                $currentStatus,
                ['qualification_required', 'active', 'suspended'],
                true,
            )) {
                throw new PayrollStateLockedException();
            }
            // Překlopení do `active` je jednosměrné. Uložení nastavení
            // aktivace proto nesmí modul stáhnout zpátky do `setup` — jinak by
            // se badge „Probíhá nastavení" vracel a zámek proti vypnutí by
            // šel obejít cyklem uložení nastavení → vypnout.
            $nextStatus = $enabled
                ? (in_array(
                    $currentStatus,
                    ['qualification_required', 'active', 'suspended'],
                    true,
                )
                    ? $currentStatus
                    : 'setup')
                : 'disabled';

            if (!is_array($current)) {
                $insert = $pdo->prepare(
                    'INSERT INTO payroll_module_state
                        (supplier_id, status, start_period, row_version, activated_by, activated_at)
                     VALUES (?, ?, ?, 1, NULL, NULL)'
                );
                $insert->execute([
                    $supplierId,
                    $nextStatus,
                    $enabled ? $startPeriod : null,
                ]);
            } else {
                $update = $pdo->prepare(
                    'UPDATE payroll_module_state
                        SET status = ?,
                            start_period = ?,
                            row_version = row_version + 1
                      WHERE supplier_id = ? AND row_version = ?'
                );
                $update->execute([
                    $nextStatus,
                    $enabled ? $startPeriod : null,
                    $supplierId,
                    $expectedVersion,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new PayrollStateConflictException($expectedVersion);
                }
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            // `inTransaction()` tu není nadbytečné: když selže samotný commit,
            // transakce už neběží a rollBack by vyhodil druhou výjimku, která
            // by tu původní — jedinou, která něco vysvětluje — zamaskovala.
            if ($ownsTransaction) {
                self::rollBackIfActive($pdo);
            }
            throw $e;
        }

        return $this->get($supplierId);
    }

    /**
     * Jednosměrné překlopení `setup` → `active` po dokončení běžného nastavení
     * nebo po prvním schváleném běhu. Globální interní release gate produktu
     * je oddělená a tento zákaznický stav ji nemůže obejít.
     *
     * @return array{
     *   supplier_id:int,status:string,start_period:?string,row_version:int,
     *   activated_at:?string,suspended_at:?string,created_at:?string,updated_at:?string
     * }|null null = stav se nezměnil (modul nebyl v `setup`)
     */
    public function promoteToActive(
        int $supplierId,
        int $userId,
        ?int $expectedVersion = null,
    ): ?array
    {
        if ($supplierId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException(
                'Firma a uživatel produkční aktivace musí být kladná čísla.',
            );
        }
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $select = $pdo->prepare(
                'SELECT status, row_version
                   FROM payroll_module_state
                  WHERE supplier_id = ?
                  FOR UPDATE'
            );
            $select->execute([$supplierId]);
            $current = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current) || !in_array(
                (string) $current['status'],
                ['setup', 'qualification_required'],
                true,
            )) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }

                return null;
            }
            $currentVersion = (int) $current['row_version'];
            if ($expectedVersion !== null && $expectedVersion !== $currentVersion) {
                throw new PayrollStateConflictException($currentVersion);
            }
            $update = $pdo->prepare(
                'UPDATE payroll_module_state
                    SET status = "active",
                        row_version = row_version + 1,
                        activated_by = ?,
                        activated_at = NOW()
                  WHERE supplier_id = ?
                    AND row_version = ?
                    AND status IN ("setup", "qualification_required")'
            );
            $update->execute([$userId, $supplierId, $currentVersion]);
            if ($update->rowCount() !== 1) {
                throw new PayrollStateConflictException($currentVersion);
            }
            $state = $this->get($supplierId);
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $state;
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                self::rollBackIfActive($pdo);
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *   supplier_id:int,status:string,start_period:?string,row_version:int,
     *   activated_at:?string,suspended_at:?string,created_at:?string,updated_at:?string
     * }
     */
    private function cast(array $row): array
    {
        return [
            'supplier_id' => (int) $row['supplier_id'],
            'status' => (string) $row['status'],
            'start_period' => $row['start_period'] === null ? null : substr((string) $row['start_period'], 0, 7),
            'row_version' => (int) $row['row_version'],
            'activated_at' => $row['activated_at'] === null ? null : (string) $row['activated_at'],
            'suspended_at' => $row['suspended_at'] === null ? null : (string) $row['suspended_at'],
            'created_at' => $row['created_at'] === null ? null : (string) $row['created_at'],
            'updated_at' => $row['updated_at'] === null ? null : (string) $row['updated_at'],
        ];
    }

    private static function rollBackIfActive(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
