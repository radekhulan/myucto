<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro trips — jednotlivé jízdy knihy jízd. Per tenant (supplier_id).
 */
final class TripRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param array{car_id?:int, category_id?:int, year?:int, month?:int, date_from?:string, date_to?:string, q?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $supplierId, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($supplierId, $filters);
        $sql = 'SELECT t.*, c.registration AS car_registration, c.name AS car_name,
                       tc.label AS category_label, tc.is_private AS category_is_private
                  FROM trips t
                  JOIN cars c ON c.id = t.car_id AND c.supplier_id = t.supplier_id
             LEFT JOIN trip_categories tc ON tc.id = t.category_id AND tc.supplier_id = t.supplier_id
                 WHERE ' . implode(' AND ', $where) . '
              ORDER BY t.trip_date DESC, t.id DESC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Stránkovaná varianta pro list endpoint — COUNT(*) bez LIMIT + data s LIMIT/OFFSET.
     *
     * @param array{car_id?:int, category_id?:int, year?:int, month?:int, date_from?:string, date_to?:string, q?:string} $filters
     * @return array{0:list<array<string,mixed>>, 1:int} [rows, total]
     */
    public function listPaged(int $supplierId, array $filters, int $perPage, int $offset): array
    {
        [$where, $params] = $this->buildWhere($supplierId, $filters);
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM trips t WHERE ' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // LIMIT/OFFSET inlinujeme jako validované inty (vzor StockItemRepository::list /
        // DocumentRepository::search) — native prepared statements neumí LIMIT/OFFSET jako parametr.
        $sql = 'SELECT t.*, c.registration AS car_registration, c.name AS car_name,
                       tc.label AS category_label, tc.is_private AS category_is_private
                  FROM trips t
                  JOIN cars c ON c.id = t.car_id AND c.supplier_id = t.supplier_id
             LEFT JOIN trip_categories tc ON tc.id = t.category_id AND tc.supplier_id = t.supplier_id
                 WHERE ' . $whereSql . '
              ORDER BY t.trip_date DESC, t.id DESC
                 LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, $offset);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
        return [$rows, $total];
    }

    /**
     * Distinct roky jízd pro dropdown filtru (scope = supplier [+ auto], NE aktuální rok/měsíc
     * filtr — ať dropdown při výběru roku nezkolabuje na jednu položku). Vzor: BankStatementAction.
     *
     * @return list<int>
     */
    public function distinctYears(int $supplierId, ?int $carId = null): array
    {
        $where = ['supplier_id = ?'];
        $params = [$supplierId];
        if ($carId !== null) { $where[] = 'car_id = ?'; $params[] = $carId; }
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT YEAR(trip_date) AS y FROM trips WHERE ' . implode(' AND ', $where) . ' ORDER BY y DESC'
        );
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param array{car_id?:int, category_id?:int, year?:int, month?:int, date_from?:string, date_to?:string, q?:string} $filters
     * @return array{0:list<string>, 1:list<mixed>} [where, params]
     */
    private function buildWhere(int $supplierId, array $filters): array
    {
        $where = ['t.supplier_id = ?'];
        $params = [$supplierId];
        if (!empty($filters['car_id']))      { $where[] = 't.car_id = ?';      $params[] = (int) $filters['car_id']; }
        if (!empty($filters['category_id'])) { $where[] = 't.category_id = ?'; $params[] = (int) $filters['category_id']; }
        if (!empty($filters['year']))        { $y = (int) $filters['year']; $where[] = 't.trip_date >= ? AND t.trip_date < ?'; $params[] = sprintf('%04d-01-01', $y); $params[] = sprintf('%04d-01-01', $y + 1); }
        if (!empty($filters['month']))       { $where[] = 'MONTH(t.trip_date) = ?'; $params[] = (int) $filters['month']; }
        if (!empty($filters['date_from']))   { $where[] = 't.trip_date >= ?';  $params[] = (string) $filters['date_from']; }
        if (!empty($filters['date_to']))     { $where[] = 't.trip_date <= ?';  $params[] = (string) $filters['date_to']; }
        if (!empty($filters['q'])) {
            $where[] = '(t.purpose LIKE ? OR t.origin LIKE ? OR t.destination LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        return [$where, $params];
    }

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.*, c.registration AS car_registration, c.name AS car_name,
                    tc.label AS category_label, tc.is_private AS category_is_private
               FROM trips t
               JOIN cars c ON c.id = t.car_id AND c.supplier_id = t.supplier_id
          LEFT JOIN trip_categories tc ON tc.id = t.category_id AND tc.supplier_id = t.supplier_id
              WHERE t.id = ? AND t.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function create(int $supplierId, array $data, ?int $userId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO trips (supplier_id, car_id, trip_date, time_start, time_end,
                                odometer_start, odometer_end, distance_km, category_id,
                                purpose, origin, destination, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute($this->bind($supplierId, $data, $userId));
        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, int $supplierId, array $data): bool
    {
        $b = $this->bind($supplierId, $data, null);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE trips
                SET car_id = ?, trip_date = ?, time_start = ?, time_end = ?,
                    odometer_start = ?, odometer_end = ?, distance_km = ?, category_id = ?,
                    purpose = ?, origin = ?, destination = ?, note = ?
              WHERE id = ? AND supplier_id = ?'
        );
        // bind() = [supplier_id, car_id, trip_date, …, note, created_by] → vyřízneme supplier_id (0) a created_by (last).
        $stmt->execute([
            $b[1], $b[2], $b[3], $b[4], $b[5], $b[6], $b[7], $b[8], $b[9], $b[10], $b[11], $b[12],
            $id, $supplierId,
        ]);
        return $stmt->rowCount() >= 0;
    }

    public function delete(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM trips WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Distinct účely cest pro našeptávač (nejnověji použité první).
     *
     * @return list<string>
     */
    public function distinctPurposes(int $supplierId, int $limit = 200): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT purpose, MAX(trip_date) AS last_used, COUNT(*) AS cnt
               FROM trips
              WHERE supplier_id = ? AND purpose IS NOT NULL AND purpose <> ''
              GROUP BY purpose
              ORDER BY last_used DESC, cnt DESC
              LIMIT ?"
        );
        $stmt->bindValue(1, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
    }

    /**
     * Distinct místa (odkud i kam dohromady) pro našeptávač cílů — nejnověji použité první.
     *
     * @return list<string>
     */
    public function distinctPlaces(int $supplierId, int $limit = 200): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT place, MAX(last_used) AS last_used, SUM(cnt) AS cnt FROM (
                 SELECT origin AS place, MAX(trip_date) AS last_used, COUNT(*) AS cnt
                   FROM trips
                  WHERE supplier_id = ? AND origin IS NOT NULL AND origin <> ''
                  GROUP BY origin
                 UNION ALL
                 SELECT destination AS place, MAX(trip_date) AS last_used, COUNT(*) AS cnt
                   FROM trips
                  WHERE supplier_id = ? AND destination IS NOT NULL AND destination <> ''
                  GROUP BY destination
             ) u
             GROUP BY place
             ORDER BY last_used DESC, cnt DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(2, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(3, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
    }

    /** @return list<mixed> Pořadí dle INSERT sloupců. */
    private function bind(int $supplierId, array $data, ?int $userId): array
    {
        return [
            $supplierId,
            (int) ($data['car_id'] ?? 0),
            (string) ($data['trip_date'] ?? ''),
            $this->nullableTime($data['time_start'] ?? null),
            $this->nullableTime($data['time_end'] ?? null),
            $this->nullableInt($data['odometer_start'] ?? null),
            $this->nullableInt($data['odometer_end'] ?? null),
            (float) ($data['distance_km'] ?? 0),
            $this->nullableInt($data['category_id'] ?? null),
            $this->nullableStr($data['purpose'] ?? null, 255),
            $this->nullableStr($data['origin'] ?? null, 255),
            $this->nullableStr($data['destination'] ?? null, 255),
            $this->nullableStr($data['note'] ?? null),
            $userId,
        ];
    }

    private function nullableInt(mixed $v): ?int
    {
        if ($v === null || $v === '' ) return null;
        return (int) $v;
    }

    private function nullableTime(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s === '' ? null : $s;
    }

    private function nullableStr(mixed $v, ?int $max = null): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') return null;
        return $max !== null ? mb_substr($s, 0, $max) : $s;
    }

    private function cast(array $r): array
    {
        return [
            'id'                  => (int) $r['id'],
            'supplier_id'         => (int) $r['supplier_id'],
            'car_id'              => (int) $r['car_id'],
            'car_registration'    => isset($r['car_registration']) ? (string) $r['car_registration'] : null,
            'car_name'            => isset($r['car_name']) && $r['car_name'] !== null ? (string) $r['car_name'] : null,
            'trip_date'           => (string) $r['trip_date'],
            'time_start'          => $r['time_start'] !== null ? substr((string) $r['time_start'], 0, 5) : null,
            'time_end'            => $r['time_end'] !== null ? substr((string) $r['time_end'], 0, 5) : null,
            'odometer_start'      => $r['odometer_start'] !== null ? (int) $r['odometer_start'] : null,
            'odometer_end'        => $r['odometer_end'] !== null ? (int) $r['odometer_end'] : null,
            'distance_km'         => (float) $r['distance_km'],
            'category_id'         => $r['category_id'] !== null ? (int) $r['category_id'] : null,
            'category_label'      => isset($r['category_label']) && $r['category_label'] !== null ? (string) $r['category_label'] : null,
            'category_is_private' => isset($r['category_is_private']) && $r['category_is_private'] !== null ? (bool) $r['category_is_private'] : null,
            'purpose'             => $r['purpose'] !== null ? (string) $r['purpose'] : null,
            'origin'              => $r['origin'] !== null ? (string) $r['origin'] : null,
            'destination'         => $r['destination'] !== null ? (string) $r['destination'] : null,
            'note'                => $r['note'] !== null ? (string) $r['note'] : null,
            'created_at'          => (string) $r['created_at'],
        ];
    }
}
