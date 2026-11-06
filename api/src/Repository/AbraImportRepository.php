<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Service\Migration\Abra\AbraException;
use MyInvoice\Service\Migration\Abra\AbraSource;

final class AbraImportRepository extends AbstractMigrationImportRepository
{
    protected function runsTable(): string { return 'abra_flexi_imports'; }
    protected function mapTable(): string { return 'abra_flexi_import_map'; }
    protected function keyColumn(): string { return 'abra_key'; }
    protected function lockPrefix(): string { return 'abra_flexi'; }
    protected function runMeta(array $meta): array
    {
        return ['agenda_year' => isset($meta['year']) ? (int) $meta['year'] : null];
    }
    protected function nullableIntColumns(): array { return ['agenda_year']; }
    protected function mapConflict(string $kind, string $key): \RuntimeException
    {
        return new AbraException('map_conflict', 'Záznam z ABRA Flexi již byl převeden. Převod se zastavil, aby nevytvořil duplicitu.');
    }

    public function lookup(int $supplierId, string $evidence, string $sourceKey): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT target_type, target_id, source_hash, source_year
            FROM abra_flexi_import_map WHERE supplier_id = ? AND kind = ? AND abra_key = ?');
        $stmt->execute([$supplierId, $evidence, self::sourceKey($sourceKey)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        $row['target_id'] = (int) $row['target_id'];
        return $row;
    }

    public function remember(int $supplierId, string $evidence, string $sourceKey, string $hash,
        string $targetType, int $targetId, ?int $year): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new \InvalidArgumentException('Invalid source hash.');
        try {
            $this->db->pdo()->prepare('INSERT INTO abra_flexi_import_map
                (supplier_id, kind, abra_key, source_hash, target_type, target_id, source_year)
                VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
                    $supplierId, $evidence, self::sourceKey($sourceKey), $hash, $targetType, $targetId, $year,
                ]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') throw $e;
            throw $this->mapConflict($evidence, $sourceKey);
        }
    }

    public function hasImportedData(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM abra_flexi_import_map WHERE supplier_id = ? LIMIT 1');
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    public function maxMappedLinkId(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT MAX(CAST(abra_key AS UNSIGNED)) FROM abra_flexi_import_map
            WHERE supplier_id = ? AND kind = 'vazba' AND abra_key REGEXP '^[0-9]+$'");
        $stmt->execute([$supplierId]);
        return max(0, (int) $stmt->fetchColumn());
    }

    public function refreshBankHash(int $supplierId, string $sourceKey, string $previousHash, string $hash): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $previousHash)
            || !preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new \InvalidArgumentException('Invalid source hash.');
        $stmt = $this->db->pdo()->prepare("UPDATE abra_flexi_import_map SET source_hash = ?
            WHERE supplier_id = ? AND kind = 'banka' AND abra_key = ? AND source_hash = ?");
        $stmt->execute([$hash, $supplierId, self::sourceKey($sourceKey), $previousHash]);
        return $stmt->rowCount() === 1;
    }

    /** @return array<string,bool> */
    public function importedEndpointKeys(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT kind, abra_key FROM abra_flexi_import_map
            WHERE supplier_id = ? AND kind IN ('faktura-vydana', 'faktura-prijata', 'prodejka', 'zavazek', 'banka', 'pokladni-pohyb',
                'ostatni-zavazek', 'ostatni-pohledavka')");
        $stmt->execute([$supplierId]);
        $keys = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $keys[AbraSource::evidenceOfMapKind((string) $row['kind']) . '|' . $row['abra_key']] = true;
        }
        return $keys;
    }

    private static function sourceKey(string $key): string
    {
        if ($key === '') throw new \InvalidArgumentException('Source key is required.');
        return mb_strlen($key) <= 190 ? $key : 'sha256:' . hash('sha256', $key);
    }
}
