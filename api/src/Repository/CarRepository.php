<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro cars — číselník automobilů knihy jízd.
 *
 * Per tenant (supplier_id). UNIQUE (supplier_id, registration). Jen jedno auto
 * smí být is_default — udržuje create()/update() v transakci.
 */
final class CarRepository
{
    private const FUEL_TYPES = ['diesel', 'petrol', 'lpg', 'cng', 'electric', 'hybrid', 'other'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $supplierId, bool $includeArchived = false): array
    {
        $sql = 'SELECT c.*,
                       (SELECT COUNT(*) FROM trips    t WHERE t.car_id = c.id) AS trips_count,
                       (SELECT COUNT(*) FROM fuelings f WHERE f.car_id = c.id) AS fuelings_count,
                       (SELECT MAX(t2.odometer_end) FROM trips t2 WHERE t2.car_id = c.id) AS last_trip_odometer,
                       pe.full_name AS driver_name
                  FROM cars c
             LEFT JOIN payroll_employees pe ON pe.id = c.driver_employee_id AND pe.supplier_id = c.supplier_id
                 WHERE c.supplier_id = ?';
        if (!$includeArchived) $sql .= ' AND c.is_archived = 0';
        $sql .= ' ORDER BY c.is_default DESC, c.is_archived ASC, c.registration ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*, pe.full_name AS driver_name
               FROM cars c
          LEFT JOIN payroll_employees pe ON pe.id = c.driver_employee_id AND pe.supplier_id = c.supplier_id
              WHERE c.id = ? AND c.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Řidič a režim užívání / odpočtu DPH (migrace 1802). Zvlášť od update(), aby klient
     * API, který nová pole neposílá, nastavení vozidla nevynuloval.
     * Hodnoty musí projít VehicleVatPolicy::normalize() — CHECK v DB je jen pojistka.
     *
     * @param array{driver_employee_id?:int|null, usage_mode?:string, vat_deduction_mode?:string, vat_deduction_percent?:float} $usage
     */
    public function updateUsage(int $id, int $supplierId, array $usage): void
    {
        $set = [];
        $params = [];
        foreach (['driver_employee_id', 'usage_mode', 'vat_deduction_mode', 'vat_deduction_percent'] as $col) {
            if (array_key_exists($col, $usage)) {
                $set[] = $col . ' = ?';
                $params[] = $usage[$col];
            }
        }
        if ($set === []) return;
        $params[] = $id;
        $params[] = $supplierId;
        $this->db->pdo()->prepare('UPDATE cars SET ' . implode(', ', $set) . ' WHERE id = ? AND supplier_id = ?')
            ->execute($params);
    }

    /** Počet aktivních (nearchivovaných) aut tenantu — pro default-car logiku ve frontendu/scanneru. */
    public function countActive(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM cars WHERE supplier_id = ? AND is_archived = 0');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** Výchozí auto tenantu (is_default), jinak jediné aktivní auto, jinak null. */
    public function defaultCarId(int $supplierId): ?int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id FROM cars WHERE supplier_id = ? AND is_archived = 0 AND is_default = 1 LIMIT 1');
        $stmt->execute([$supplierId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int) $id;

        $stmt = $pdo->prepare('SELECT id FROM cars WHERE supplier_id = ? AND is_archived = 0 LIMIT 2');
        $stmt->execute([$supplierId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    /** Najde auto podle SPZ nebo názvu (case-insensitive) — pro CSV/XLSX import. */
    public function findByRegistrationOrName(int $supplierId, string $needle): ?array
    {
        $needle = trim($needle);
        if ($needle === '') return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM cars
              WHERE supplier_id = ? AND (LOWER(registration) = LOWER(?) OR LOWER(name) = LOWER(?))
              ORDER BY is_archived ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, $needle, $needle]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function create(int $supplierId, array $data, ?int $userId): int
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            if (!empty($data['is_default'])) {
                $this->clearDefault($supplierId);
            }
            $pdo->prepare(
                'INSERT INTO cars (supplier_id, registration, name, brand, model, vin, fuel_type,
                                   odometer_start, odometer_start_date, is_default, is_archived, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute($this->bind($supplierId, $data, $userId));
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function update(int $id, int $supplierId, array $data): bool
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            if (!empty($data['is_default'])) {
                $this->clearDefault($supplierId, $id);
            }
            $stmt = $pdo->prepare(
                'UPDATE cars
                    SET registration = ?, name = ?, brand = ?, model = ?, vin = ?, fuel_type = ?,
                        odometer_start = ?, odometer_start_date = ?, is_default = ?, is_archived = ?, note = ?
                  WHERE id = ? AND supplier_id = ?'
            );
            $b = $this->bind($supplierId, $data, null);
            // bind() vrací [supplier_id, registration, …, created_by]; pro UPDATE vyřízneme bez supplier_id a created_by.
            $stmt->execute([
                $b[1], $b[2], $b[3], $b[4], $b[5], $b[6], $b[7], $b[8], $b[9], $b[10], $b[11],
                $id, $supplierId,
            ]);
            $ok = $stmt->rowCount() >= 0;
            $pdo->commit();
            return $ok;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Hard delete jen pokud auto nemá jízdy/tankování; jinak BLOCK (lze archivovat při úpravě). */
    public function delete(int $id, int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM trips WHERE car_id = ?) +
                    (SELECT COUNT(*) FROM fuelings WHERE car_id = ?) AS usage_count'
        );
        $stmt->execute([$id, $id]);
        $usage = (int) $stmt->fetchColumn();

        // Auto s navázanými jízdami/tankováním NELZE smazat (jen archivovat při úpravě).
        if ($usage > 0) {
            return ['deleted' => false, 'blocked' => true, 'usage_count' => $usage];
        }
        $pdo->prepare('DELETE FROM cars WHERE id = ? AND supplier_id = ?')->execute([$id, $supplierId]);
        return ['deleted' => true, 'blocked' => false];
    }

    private function clearDefault(int $supplierId, ?int $exceptId = null): void
    {
        $sql = 'UPDATE cars SET is_default = 0 WHERE supplier_id = ? AND is_default = 1';
        $params = [$supplierId];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    /** @return list<mixed> Pořadí dle INSERT sloupců (supplier_id … created_by). */
    private function bind(int $supplierId, array $data, ?int $userId): array
    {
        $fuel = $data['fuel_type'] ?? null;
        if ($fuel !== null && !in_array($fuel, self::FUEL_TYPES, true)) $fuel = null;
        $odoDate = trim((string) ($data['odometer_start_date'] ?? ''));
        return [
            $supplierId,
            trim((string) ($data['registration'] ?? '')),
            $this->nullableStr($data['name'] ?? null),
            $this->nullableStr($data['brand'] ?? null),
            $this->nullableStr($data['model'] ?? null),
            $this->nullableStr($data['vin'] ?? null),
            $fuel,
            isset($data['odometer_start']) && $data['odometer_start'] !== '' && $data['odometer_start'] !== null
                ? (int) $data['odometer_start'] : null,
            $odoDate !== '' ? $odoDate : null,
            !empty($data['is_default']) ? 1 : 0,
            !empty($data['is_archived']) ? 1 : 0,
            $this->nullableStr($data['note'] ?? null),
            $userId,
        ];
    }

    private function nullableStr(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s === '' ? null : $s;
    }

    private function cast(array $r): array
    {
        return [
            'id'                  => (int) $r['id'],
            'supplier_id'         => (int) $r['supplier_id'],
            'registration'        => (string) $r['registration'],
            'name'                => $r['name'] !== null ? (string) $r['name'] : null,
            'brand'               => $r['brand'] !== null ? (string) $r['brand'] : null,
            'model'               => $r['model'] !== null ? (string) $r['model'] : null,
            'vin'                 => $r['vin'] !== null ? (string) $r['vin'] : null,
            'fuel_type'           => $r['fuel_type'] !== null ? (string) $r['fuel_type'] : null,
            'odometer_start'      => $r['odometer_start'] !== null ? (int) $r['odometer_start'] : null,
            'odometer_start_date' => $r['odometer_start_date'] !== null ? (string) $r['odometer_start_date'] : null,
            'is_default'          => (bool) $r['is_default'],
            'is_archived'         => (bool) $r['is_archived'],
            'note'                => $r['note'] !== null ? (string) $r['note'] : null,
            // Řidič a režim užívání / odpočtu DPH (migrace 1802, VehicleVatPolicy).
            'driver_employee_id'    => isset($r['driver_employee_id']) ? (int) $r['driver_employee_id'] : null,
            'driver_name'           => isset($r['driver_name']) ? (string) $r['driver_name'] : null,
            'usage_mode'            => (string) ($r['usage_mode'] ?? 'business'),
            'vat_deduction_mode'    => (string) ($r['vat_deduction_mode'] ?? 'full'),
            'vat_deduction_percent' => isset($r['vat_deduction_percent']) ? (float) $r['vat_deduction_percent'] : 100.0,
            'created_at'          => (string) $r['created_at'],
            'trips_count'         => isset($r['trips_count']) ? (int) $r['trips_count'] : null,
            'fuelings_count'      => isset($r['fuelings_count']) ? (int) $r['fuelings_count'] : null,
            // Poslední známý konečný tachometr (z jízd), jinak počáteční stav auta —
            // pro předvyplnění „tachometr zahájení" u nového záznamu.
            'last_odometer'       => isset($r['last_trip_odometer']) && $r['last_trip_odometer'] !== null
                ? (int) $r['last_trip_odometer']
                : ($r['odometer_start'] !== null ? (int) $r['odometer_start'] : null),
        ];
    }
}
