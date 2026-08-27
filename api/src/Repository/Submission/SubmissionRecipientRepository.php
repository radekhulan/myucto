<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Číselník datových schránek institucí (migrace 1381).
 *
 * Systémové záznamy mají `supplier_id IS NULL` a vidí je všechny firmy;
 * firma si smí přidat vlastní. `source_url` je volitelný auditní údaj.
 */
final class SubmissionRecipientRepository
{
    private const TABLE = 'submission_recipients';

    private const COLUMNS = 'id, supplier_id, code, name, business_id, address,
        kind, isds_box_id, source_url,
        source_note, is_active, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable(self::TABLE);
    }

    /** @return list<array<string,mixed>> */
    public function listVisible(int $supplierId, ?string $kind = null): array
    {
        $this->assertAvailable();
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' recipient
                 WHERE (
                   recipient.supplier_id = ?
                   OR (
                     recipient.supplier_id IS NULL
                     AND NOT EXISTS (
                       SELECT 1 FROM ' . self::TABLE . ' own
                        WHERE own.supplier_id = ? AND own.code = recipient.code
                     )
                   )
                 )';
        $params = [$supplierId, $supplierId];
        if ($kind !== null) {
            $sql .= ' AND recipient.kind = ?';
            $params[] = $kind;
        }
        $sql .= ' ORDER BY recipient.kind ASC, recipient.name ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_values(array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /** @return array<string,mixed>|null */
    public function findVisible(int $supplierId, int $id): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
              WHERE id = ? AND (supplier_id IS NULL OR supplier_id = ?)'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::normalize($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function findActiveVisibleByExactBoxId(int $supplierId, string $boxId): array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' recipient
              WHERE recipient.is_active = 1
                AND LOWER(recipient.isds_box_id) = LOWER(?)
                AND (
                  recipient.supplier_id = ?
                  OR (
                    recipient.supplier_id IS NULL
                    AND NOT EXISTS (
                      SELECT 1 FROM ' . self::TABLE . ' own
                       WHERE own.supplier_id = ? AND own.code = recipient.code
                    )
                  )
                )
              ORDER BY recipient.supplier_id IS NULL ASC, recipient.id ASC'
        );
        $stmt->execute([$boxId, $supplierId, $supplierId]);
        return array_values(array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /**
     * @param array{code:string,name:string,business_id?:?string,address?:?string,
     *   kind:string,isds_box_id:?string,
     *   source_url:?string,source_note:?string,is_active:bool} $data
     */
    public function upsertForSupplier(int $supplierId, array $data, ?int $userId): int
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . self::TABLE . '
                (supplier_id, code, name, business_id, address, kind, isds_box_id,
                 source_url, source_note, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name), business_id = VALUES(business_id),
                address = VALUES(address), kind = VALUES(kind),
                isds_box_id = VALUES(isds_box_id),
                source_url = VALUES(source_url), source_note = VALUES(source_note),
                is_active = VALUES(is_active)'
        );
        $stmt->execute([
            $supplierId,
            $data['code'],
            $data['name'],
            $data['business_id'] ?? null,
            $data['address'] ?? null,
            $data['kind'],
            $data['isds_box_id'],
            $data['source_url'],
            $data['source_note'],
            $data['is_active'] ? 1 : 0,
            $userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        if ($id > 0) {
            return $id;
        }
        $existing = $this->findByCode($supplierId, $data['code']);
        return $existing !== null ? (int) $existing['id'] : 0;
    }

    /** @return array<string,mixed>|null */
    public function findByCode(int $supplierId, string $code): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' WHERE supplier_id = ? AND code = ?'
        );
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * Příjemce podle kódu VČETNĚ systémových záznamů.
     *
     * {@see findByCode()} vidí jen záznamy firmy, což stačí pro editaci
     * vlastního číselníku, ale ne pro vyhledání instituce, kterou seedujeme
     * systémově (schránky e-Podání ČSSZ, pojišťovny). Viditelnost je stejná
     * jako u {@see findVisible()}.
     *
     * Vlastní záznam firmy má přednost před systémovým: firma smí mít vlastní
     * variantu (typicky místně příslušné pracoviště) a ta nesmí být přebita
     * sdíleným záznamem.
     *
     * @return array<string,mixed>|null
     */
    public function findVisibleByCode(int $supplierId, string $code): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
              WHERE code = ? AND (supplier_id IS NULL OR supplier_id = ?)
              ORDER BY supplier_id IS NULL ASC
              LIMIT 1'
        );
        $stmt->execute([$code, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::normalize($row) : null;
    }

    /** Systémové záznamy smazat nejde — patří všem, ne jedné firmě. */
    public function deleteOwn(int $supplierId, int $id): bool
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new \DomainException('Číselník příjemců není v databázi k dispozici (chybí migrace 1381).');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null;
        $row['is_active'] = (bool) $row['is_active'];
        $row['is_system'] = $row['supplier_id'] === null;
        // Číselník bez ID schránky je legitimní stav (u finančních úřadů
        // nemáme doklad), ale UI to musí umět rozeznat na první pohled.
        $row['has_box_id'] = $row['isds_box_id'] !== null;
        return $row;
    }
}
