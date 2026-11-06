<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationHeader;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6SourceEvidencePageHasher;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final readonly class NonprofitTaxSourceEvidenceManifestRepository
{
    public function __construct(private Connection $db) {}

    public function putChunk(int $supplierId, string $kind, array $chunk): int
    {
        $this->transactionRequired();
        J6SourceEvidencePageHasher::kind($kind);
        $descriptor = $chunk['descriptor'] ?? null;
        $payload = $chunk['payload'] ?? null;
        if (!is_array($descriptor) || !is_array($payload) || ($payload['kind'] ?? null) !== $kind
            || ($descriptor['row_count'] ?? 0) < 1 || ($descriptor['row_count'] ?? 0) > 500) {
            throw new \InvalidArgumentException('J6 source evidence chunk is invalid.');
        }
        $json = NonprofitTaxRules::canonicalJson($payload);
        $stmt = $this->db->pdo()->prepare('INSERT IGNORE INTO nonprofit_tax_source_evidence_chunks
            (supplier_id,evidence_kind,schema_version,chunk_hash,row_count,payload_json,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $stmt->execute([$supplierId, $kind, J6SourceEvidencePageHasher::SCHEMA, $descriptor['chunk_hash'],
            $descriptor['row_count'], $json]);
        $stmt = $this->db->pdo()->prepare('SELECT id,row_count,payload_json FROM nonprofit_tax_source_evidence_chunks
            WHERE supplier_id=? AND evidence_kind=? AND schema_version=? AND chunk_hash=? FOR UPDATE');
        $stmt->execute([$supplierId, $kind, J6SourceEvidencePageHasher::SCHEMA, $descriptor['chunk_hash']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['row_count'] !== $descriptor['row_count'] || !hash_equals($json, (string) $row['payload_json'])) {
            throw new \UnexpectedValueException('Stored J6 source evidence chunk does not match its hash.');
        }
        return (int) $row['id'];
    }

    public function appendManifest(J6CalculationHeader $header, string $kind, string $rootHash, int $rowCount, int $chunkCount,
        iterable $records): int
    {
        $this->transactionRequired();
        J6SourceEvidencePageHasher::kind($kind);
        if (preg_match('/^[a-f0-9]{64}$/D', $rootHash) !== 1 || $rowCount < 0 || $chunkCount < 0) {
            throw new \InvalidArgumentException('J6 source evidence manifest totals are invalid.');
        }
        $headerJson = NonprofitTaxRules::canonicalJson($header->toArray());
        $stmt = $this->db->pdo()->prepare('INSERT IGNORE INTO nonprofit_tax_source_evidence_manifests
            (supplier_id,period_id,evidence_kind,schema_version,source_input_hash,header_json,root_hash,row_count,chunk_count,created_at)
            VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $stmt->execute([$header->supplierId, $header->periodId, $kind, J6SourceEvidencePageHasher::SCHEMA,
            $header->sourceInputHash, $headerJson, $rootHash, $rowCount, $chunkCount]);
        $stmt = $this->db->pdo()->prepare('SELECT * FROM nonprofit_tax_source_evidence_manifests
            WHERE supplier_id=? AND evidence_kind=? AND schema_version=? AND root_hash=? FOR UPDATE');
        $stmt->execute([$header->supplierId, $kind, J6SourceEvidencePageHasher::SCHEMA, $rootHash]);
        $manifest = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($manifest === false || (int) $manifest['period_id'] !== $header->periodId
            || !hash_equals((string) $manifest['source_input_hash'], $header->sourceInputHash)
            || (int) $manifest['row_count'] !== $rowCount || (int) $manifest['chunk_count'] !== $chunkCount
            || !hash_equals((string) $manifest['header_json'], $headerJson)) {
            throw new \UnexpectedValueException('Stored J6 source evidence manifest does not match its root.');
        }
        $manifestId = (int) $manifest['id'];
        $batch = [];
        $ordinal = 0;
        foreach ($records as $record) {
            if (($record['descriptor']['ordinal'] ?? null) !== $ordinal++) {
                throw new \UnexpectedValueException('J6 source evidence manifest sequence is invalid.');
            }
            $batch[] = [$header->supplierId, $manifestId, $record['descriptor']['ordinal'], $record['chunk_id'],
                $record['descriptor']['chunk_hash']];
            if (count($batch) === 500) {
                $this->insertLinks($batch);
                $batch = [];
            }
        }
        if ($batch !== []) $this->insertLinks($batch);
        if ($ordinal !== $chunkCount) throw new \UnexpectedValueException('J6 source evidence manifest chunk count is invalid.');
        return $manifestId;
    }

    public function manifest(int $supplierId, int $manifestId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM nonprofit_tax_source_evidence_manifests WHERE supplier_id=? AND id=?');
        $stmt->execute([$supplierId, $manifestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new \UnexpectedValueException('J6 source evidence manifest was not found.');
        foreach (['id', 'supplier_id', 'period_id', 'row_count', 'chunk_count'] as $field) $row[$field] = (int) $row[$field];
        $row['header'] = json_decode($row['header_json'], true, flags: JSON_THROW_ON_ERROR);
        unset($row['header_json']);
        return $row;
    }

    public function chunkPage(int $supplierId, int $manifestId, int $afterOrdinal = -1, int $limit = 4): array
    {
        if ($afterOrdinal < -1 || $limit < 1 || $limit > 32) throw new \InvalidArgumentException('J6 source evidence chunk page is invalid.');
        $stmt = $this->db->pdo()->prepare("SELECT mc.ordinal,mc.chunk_hash AS link_hash,c.evidence_kind,c.schema_version,c.chunk_hash,c.row_count,c.payload_json
            FROM nonprofit_tax_source_evidence_manifest_chunks mc
            JOIN nonprofit_tax_source_evidence_chunks c ON c.supplier_id=mc.supplier_id AND c.id=mc.chunk_id
            WHERE mc.supplier_id=? AND mc.manifest_id=? AND mc.ordinal>? ORDER BY mc.ordinal LIMIT {$limit}");
        $stmt->execute([$supplierId, $manifestId, $afterOrdinal]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function insertLinks(array $batch): void
    {
        $values = implode(',', array_fill(0, count($batch), '(?,?,?,?,?)'));
        $params = [];
        foreach ($batch as $row) array_push($params, ...$row);
        $this->db->pdo()->prepare("INSERT IGNORE INTO nonprofit_tax_source_evidence_manifest_chunks
            (supplier_id,manifest_id,ordinal,chunk_id,chunk_hash) VALUES {$values}")->execute($params);
    }

    private function transactionRequired(): void
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('J6 source evidence publication requires an owner transaction.');
    }
}
