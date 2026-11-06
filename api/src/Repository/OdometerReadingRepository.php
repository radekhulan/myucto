<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceRegistry;
use PDO;

/**
 * Repository pro odometer_readings — zjištěné stavy tachometru vozidla k datu.
 *
 * Jeden stav na auto, den a zdroj (UNIQUE car_id, reading_date, source): opakovaný
 * zápis téhož dne stav přepíše. `source` je 'manual' nebo kód registrovaného zdroje
 * automatického stavu tachometru — sloupec je VARCHAR, platné kódy hlídá registr.
 */
final class OdometerReadingRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly OdometerSourceRegistry $sources,
    ) {}

    /**
     * @return list<array<string,mixed>> od nejnovějšího
     */
    public function listForTenant(int $supplierId, ?int $carId = null, int $limit = 200): array
    {
        $sql = 'SELECT r.*, c.registration AS car_registration, c.name AS car_name
                  FROM odometer_readings r
                  JOIN cars c ON c.id = r.car_id AND c.supplier_id = r.supplier_id
                 WHERE r.supplier_id = ?';
        $params = [$supplierId];
        if ($carId !== null) {
            $sql .= ' AND r.car_id = ?';
            $params[] = $carId;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY r.reading_date DESC, r.id DESC LIMIT ' . max(1, $limit));
        $stmt->execute($params);
        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT r.*, c.registration AS car_registration, c.name AS car_name
               FROM odometer_readings r
               JOIN cars c ON c.id = r.car_id AND c.supplier_id = r.supplier_id
              WHERE r.id = ? AND r.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Nejbližší stavy kolem data: nejvyšší odečet i konec jízdy před datem, nejnižší
     * odečet po datu. Stavy téhož dne se neporovnávají — ruční a automatický stav
     * jednoho dne mohou být z různé denní doby.
     *
     * @return array{before:?int, after:?int}
     */
    public function bounds(int $supplierId, int $carId, string $date): array
    {
        $pdo = $this->db->pdo();
        $before = $pdo->prepare(
            'SELECT GREATEST(
                    COALESCE((SELECT MAX(odometer) FROM odometer_readings
                               WHERE supplier_id = ? AND car_id = ? AND reading_date < ?), 0),
                    COALESCE((SELECT MAX(odometer_end) FROM trips
                               WHERE supplier_id = ? AND car_id = ? AND trip_date < ?), 0))'
        );
        $before->execute([$supplierId, $carId, $date, $supplierId, $carId, $date]);
        $after = $pdo->prepare(
            'SELECT MIN(odometer) FROM odometer_readings
              WHERE supplier_id = ? AND car_id = ? AND reading_date > ?'
        );
        $after->execute([$supplierId, $carId, $date]);
        $b = (int) $before->fetchColumn();
        $a = $after->fetchColumn();
        return ['before' => $b > 0 ? $b : null, 'after' => $a !== null && $a !== false ? (int) $a : null];
    }

    public function upsert(int $supplierId, array $data, ?int $userId): int
    {
        $source = (string) ($data['source'] ?? 'manual');
        if (!$this->sources->isReadingSource($source)) {
            throw new \InvalidArgumentException('Neznámý zdroj stavu tachometru: ' . $source);
        }
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO odometer_readings (supplier_id, car_id, reading_date, reading_time, odometer, source, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), reading_time = VALUES(reading_time),
                                     odometer = VALUES(odometer), note = VALUES(note)'
        )->execute([
            $supplierId,
            (int) $data['car_id'],
            (string) $data['reading_date'],
            $this->nullableStr($data['reading_time'] ?? null),
            (int) $data['odometer'],
            $source,
            $this->nullableStr($data['note'] ?? null),
            $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function delete(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM odometer_readings WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    private function nullableStr(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s === '' ? null : mb_substr($s, 0, 255);
    }

    private function cast(array $r): array
    {
        return [
            'id'               => (int) $r['id'],
            'supplier_id'      => (int) $r['supplier_id'],
            'car_id'           => (int) $r['car_id'],
            'car_registration' => isset($r['car_registration']) ? (string) $r['car_registration'] : null,
            'car_name'         => isset($r['car_name']) ? (string) $r['car_name'] : null,
            'reading_date'     => (string) $r['reading_date'],
            'reading_time'     => $r['reading_time'] !== null ? substr((string) $r['reading_time'], 0, 5) : null,
            'odometer'         => (int) $r['odometer'],
            'source'           => (string) $r['source'],
            'note'             => $r['note'] !== null ? (string) $r['note'] : null,
            'created_at'       => (string) $r['created_at'],
        ];
    }
}
