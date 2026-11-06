<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro odometer_source_cars — auta knihy jízd přiřazená k účtu u zdroje stavu
 * tachometru ({@see OdometerSourceAccountRepository}). Jedno přiřazení na auto; jedno auto
 * zdroje (`vehicle_ref`) nejvýš k jednomu autu firmy, i když se k témuž účtu u služby
 * přihlásí víc napojení.
 */
final class OdometerSourceCarRepository
{
    private const COLUMNS = 'sc.id, sc.supplier_id, sc.account_id, sc.car_id, sc.vehicle_ref, sc.last_sync_at, sc.last_sync_status,
        sc.last_sync_error_code, sc.last_odometer, sc.last_value_at, c.registration, c.name';

    public function __construct(private readonly Connection $db) {}

    public function findForCar(int $supplierId, int $carId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM odometer_source_cars sc
               JOIN cars c ON c.id = sc.car_id AND c.supplier_id = sc.supplier_id
              WHERE sc.supplier_id = ? AND sc.car_id = ?'
        );
        $stmt->execute([$supplierId, $carId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** @return list<array<string,mixed>> */
    public function listForAccount(int $supplierId, int $accountId): array
    {
        return $this->listForAccounts($supplierId, [$accountId])[$accountId] ?? [];
    }

    /**
     * @param list<int> $accountIds
     * @return array<int, list<array<string,mixed>>> auta podle účtu
     */
    public function listForAccounts(int $supplierId, array $accountIds): array
    {
        if ($accountIds === []) return [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM odometer_source_cars sc
               JOIN cars c ON c.id = sc.car_id AND c.supplier_id = sc.supplier_id
              WHERE sc.supplier_id = ? AND sc.account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')
              ORDER BY c.registration'
        );
        $stmt->execute([$supplierId, ...array_map('intval', $accountIds)]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $car = $this->cast($row);
            $out[$car['account_id']][] = $car;
        }
        return $out;
    }

    /** @return list<int> auta firmy přiřazená k některému účtu */
    public function assignedCarIds(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT car_id FROM odometer_source_cars WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string,int> auta zdroje firmy už přiřazená k autům knihy jízd: vehicle_ref => car_id */
    public function takenVehicles(int $supplierId, string $provider): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT sc.vehicle_ref, sc.car_id FROM odometer_source_cars sc
               JOIN odometer_source_accounts a ON a.id = sc.account_id AND a.supplier_id = sc.supplier_id
              WHERE sc.supplier_id = ? AND a.provider = ?'
        );
        $stmt->execute([$supplierId, $provider]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /**
     * Přiřadí auto k účtu (případné dosavadní přiřazení auta nahradí).
     * Vrací false, když auto zdroje už patří jinému autu firmy (přes kterýkoli účet téhož zdroje).
     */
    public function assign(int $supplierId, int $accountId, int $carId, string $vehicleRef, ?int $userId): bool
    {
        $pdo = $this->db->pdo();
        $taken = $pdo->prepare(
            'SELECT sc.car_id FROM odometer_source_cars sc
               JOIN odometer_source_accounts a ON a.id = sc.account_id AND a.supplier_id = sc.supplier_id
              WHERE sc.supplier_id = ? AND sc.vehicle_ref = ? AND sc.car_id <> ?
                AND a.provider = (SELECT provider FROM odometer_source_accounts WHERE id = ? AND supplier_id = ?)
              LIMIT 1'
        );
        $taken->execute([$supplierId, $vehicleRef, $carId, $accountId, $supplierId]);
        if ($taken->fetchColumn() !== false) return false;
        $pdo->prepare(
            'INSERT INTO odometer_source_cars (supplier_id, account_id, car_id, vehicle_ref, created_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 last_sync_at = IF(account_id = VALUES(account_id) AND vehicle_ref = VALUES(vehicle_ref), last_sync_at, NULL),
                 last_sync_status = IF(account_id = VALUES(account_id) AND vehicle_ref = VALUES(vehicle_ref), last_sync_status, NULL),
                 last_sync_error_code = IF(account_id = VALUES(account_id) AND vehicle_ref = VALUES(vehicle_ref), last_sync_error_code, NULL),
                 last_odometer = IF(account_id = VALUES(account_id) AND vehicle_ref = VALUES(vehicle_ref), last_odometer, NULL),
                 last_value_at = IF(account_id = VALUES(account_id) AND vehicle_ref = VALUES(vehicle_ref), last_value_at, NULL),
                 account_id = VALUES(account_id), vehicle_ref = VALUES(vehicle_ref)'
        )->execute([$supplierId, $accountId, $carId, $vehicleRef, $userId]);
        return true;
    }

    public function unassign(int $supplierId, int $carId): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM odometer_source_cars WHERE supplier_id = ? AND car_id = ?');
        $stmt->execute([$supplierId, $carId]);
        return $stmt->rowCount() > 0;
    }

    public function deleteForAccount(int $supplierId, int $accountId): void
    {
        $this->db->pdo()->prepare('DELETE FROM odometer_source_cars WHERE supplier_id = ? AND account_id = ?')
            ->execute([$supplierId, $accountId]);
    }

    public function recordSuccess(int $supplierId, int $id, int $odometer, string $valueAt): void
    {
        $this->db->pdo()->prepare(
            "UPDATE odometer_source_cars
                SET last_sync_at = CURRENT_TIMESTAMP, last_sync_status = 'success', last_sync_error_code = NULL,
                    last_odometer = ?, last_value_at = ?
              WHERE supplier_id = ? AND id = ?"
        )->execute([$odometer, $valueAt, $supplierId, $id]);
    }

    /** Chyba nebo varování jednoho auta ('warning' = stav nenavazuje a byl přeskočen). */
    public function recordProblem(int $supplierId, int $id, string $status, string $errorCode): void
    {
        $this->db->pdo()->prepare(
            'UPDATE odometer_source_cars
                SET last_sync_at = CURRENT_TIMESTAMP, last_sync_status = ?, last_sync_error_code = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([$status === 'warning' ? 'warning' : 'error', mb_substr($errorCode, 0, 64), $supplierId, $id]);
    }

    private function cast(array $r): array
    {
        return [
            'id'                   => (int) $r['id'],
            'supplier_id'          => (int) $r['supplier_id'],
            'account_id'           => (int) $r['account_id'],
            'car_id'               => (int) $r['car_id'],
            'registration'         => (string) $r['registration'],
            'name'                 => $r['name'] !== null ? (string) $r['name'] : null,
            'vehicle_ref'          => (string) $r['vehicle_ref'],
            'last_sync_at'         => $r['last_sync_at'] !== null ? (string) $r['last_sync_at'] : null,
            'last_sync_status'     => $r['last_sync_status'] !== null ? (string) $r['last_sync_status'] : null,
            'last_sync_error_code' => $r['last_sync_error_code'] !== null ? (string) $r['last_sync_error_code'] : null,
            'last_odometer'        => $r['last_odometer'] !== null ? (int) $r['last_odometer'] : null,
            'last_value_at'        => $r['last_value_at'] !== null ? (string) $r['last_value_at'] : null,
        ];
    }
}
