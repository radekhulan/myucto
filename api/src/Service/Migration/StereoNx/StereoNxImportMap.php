<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Trvalá identita zdrojových řádků; NX1 UID není identita dokladu ani pohybu. */
final class StereoNxImportMap
{
    public function __construct(private readonly Connection $db) {}

    /** @return array{target_id:int,source_hash:string}|null */
    public function get(int $supplierId, string $ico, int $companyIndex, string $kind, string $sourceKey): ?array
    {
        self::validateKey($kind, $sourceKey);
        $stmt = $this->db->pdo()->prepare('SELECT target_id, source_hash FROM stereo_nx_import_map
            WHERE supplier_id = ? AND source_ico = ? AND source_company_index = ? AND kind = ? AND source_key = ?');
        $stmt->execute([$supplierId, $ico, $companyIndex, $kind, $sourceKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['target_id' => (int) $row['target_id'], 'source_hash' => (string) $row['source_hash']];
    }

    /** @return array<string,string> Původní účty daného převodu → skutečné cílové kódy. */
    public function accountCodes(int $supplierId, string $ico, int $companyIndex): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT m.source_key, a.account_code
            FROM stereo_nx_import_map m
            JOIN chart_of_accounts a ON a.supplier_id = m.supplier_id AND a.id = m.target_id
            WHERE m.supplier_id = ? AND m.source_ico = ? AND m.source_company_index = ? AND m.kind = 'accounting_account'");
        $stmt->execute([$supplierId, $ico, $companyIndex]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function put(int $supplierId, string $ico, int $companyIndex, string $kind, string $sourceKey, string $hash, int $targetId): void
    {
        self::validateKey($kind, $sourceKey);
        if (preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1 || $targetId <= 0) {
            throw new StereoNxException('import_identity_invalid', 'Neplatná identita převáděného objektu.');
        }
        $this->db->pdo()->prepare('INSERT INTO stereo_nx_import_map
            (supplier_id, source_ico, source_company_index, kind, source_key, source_hash, target_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$supplierId, $ico, $companyIndex, $kind, $sourceKey, $hash, $targetId]);
    }

    /** @param array<string,mixed> $record */
    public static function fingerprint(array $record): string
    {
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return hash('sha256', $json);
    }

    private static function validateKey(string $kind, string $sourceKey): void
    {
        if ($kind === '' || strlen($kind) > 32 || $sourceKey === '' || strlen($sourceKey) > 190 || str_contains($sourceKey, "\0")) {
            throw new StereoNxException('import_identity_invalid', 'Neplatný zdrojový klíč převodu.');
        }
    }
}
