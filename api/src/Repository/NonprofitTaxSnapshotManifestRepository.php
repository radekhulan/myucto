<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotCursor;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotHeaderV2;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotManifestReference;
use PDO;
use PDOException;

final class NonprofitTaxSnapshotManifestRepository
{
    private const int VERIFY_CHUNK_BATCH = 4;

    public function __construct(
        private readonly Connection $db,
        private readonly NonprofitTaxSnapshotHasher $hasher,
    ) {}

    /** @param array{payload:array<string,mixed>,descriptor:array<string,mixed>} $chunk */
    public function putChunk(int $supplierId, array $chunk): int
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Snapshot chunks require a transaction.');
        $descriptor = $chunk['descriptor'];
        $payloadJson = NonprofitTaxRules::canonicalJson($chunk['payload']);
        $first = $descriptor['first_cursor'] === null ? null : NonprofitTaxRules::canonicalJson($descriptor['first_cursor']);
        $last = $descriptor['last_cursor'] === null ? null : NonprofitTaxRules::canonicalJson($descriptor['last_cursor']);
        try {
            $stmt = $this->db->pdo()->prepare('INSERT INTO nonprofit_tax_snapshot_chunks
                (supplier_id,schema_version,chunk_hash,row_count,first_cursor_json,last_cursor_json,payload_json,created_at)
                VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
            $stmt->execute([$supplierId, SnapshotHeaderV2::SCHEMA, $descriptor['chunk_hash'], $descriptor['row_count'], $first, $last, $payloadJson]);
            return (int) $this->db->pdo()->lastInsertId();
        } catch (PDOException $failure) {
            if ((int) ($failure->errorInfo[1] ?? 0) !== 1062) throw $failure;
            $stmt = $this->db->pdo()->prepare('SELECT id,row_count,first_cursor_json,last_cursor_json,payload_json
                FROM nonprofit_tax_snapshot_chunks WHERE supplier_id=? AND schema_version=? AND chunk_hash=? FOR UPDATE');
            $stmt->execute([$supplierId, SnapshotHeaderV2::SCHEMA, $descriptor['chunk_hash']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || (int) $row['row_count'] !== $descriptor['row_count'] || $row['first_cursor_json'] !== $first
                || $row['last_cursor_json'] !== $last || $row['payload_json'] !== $payloadJson) {
                throw new \UnexpectedValueException('Content-addressed snapshot chunk collision.');
            }
            return (int) $row['id'];
        }
    }

    public function findByInputHash(int $supplierId, string $inputHash, bool $currentRead = false): ?SnapshotManifestReference
    {
        $this->assertCurrentRead($currentRead);
        $stmt = $this->db->pdo()->prepare('SELECT * FROM nonprofit_tax_snapshot_manifests
            WHERE supplier_id=? AND schema_version=? AND input_hash=?' . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, SnapshotHeaderV2::SCHEMA, $inputHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->reference($row);
    }

    /** @param iterable<array{chunk_id:int,descriptor:array<string,mixed>}> $links */
    public function appendManifest(array $manifest, iterable $links): SnapshotManifestReference
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Snapshot manifest requires a transaction.');
        $stmt = $this->db->pdo()->prepare('INSERT INTO nonprofit_tax_snapshot_manifests
            (supplier_id,period_id,schema_version,ruleset,header_json,header_hash,root_hash,input_hash,row_count,finding_count,chunk_count,membership_fact_count,captured_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $stmt->execute([$manifest['supplier_id'], $manifest['period_id'], SnapshotHeaderV2::SCHEMA, $manifest['ruleset'],
            NonprofitTaxRules::canonicalJson($manifest['header']), $manifest['header_hash'], $manifest['root_hash'], $manifest['input_hash'],
            $manifest['row_count'], $manifest['finding_count'], $manifest['chunk_count'], $manifest['membership_fact_count']]);
        $manifestId = (int) $this->db->pdo()->lastInsertId();
        $batch = [];
        $seen = 0;
        foreach ($links as $link) {
            $descriptor = $link['descriptor'];
            if (($descriptor['ordinal'] ?? null) !== $seen++) throw new \UnexpectedValueException('Snapshot manifest link order is invalid.');
            $batch[] = [(int) $manifest['supplier_id'], $manifestId, (int) $descriptor['ordinal'], (int) $link['chunk_id'], (string) $descriptor['chunk_hash']];
            if (count($batch) === 200) {
                $this->insertLinks($batch);
                $batch = [];
            }
        }
        if ($batch !== []) $this->insertLinks($batch);
        if ($seen !== (int) $manifest['chunk_count']) throw new \UnexpectedValueException('Snapshot manifest link count is invalid.');
        return new SnapshotManifestReference($manifestId, (int) $manifest['supplier_id'], (int) $manifest['period_id'],
            $manifest['input_hash'], $manifest['root_hash'], (int) $manifest['row_count'], (int) $manifest['chunk_count']);
    }

    public function manifest(int $supplierId, int $manifestId, bool $currentRead = false): array
    {
        $this->assertCurrentRead($currentRead);
        $stmt = $this->db->pdo()->prepare('SELECT * FROM nonprofit_tax_snapshot_manifests WHERE supplier_id=? AND id=?'
            . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $manifestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new \OutOfBoundsException('Snapshot manifest was not found.');
        $row['header'] = json_decode($row['header_json'], true, flags: JSON_THROW_ON_ERROR);
        unset($row['header_json']);
        foreach (['id', 'supplier_id', 'period_id', 'row_count', 'finding_count', 'chunk_count', 'membership_fact_count'] as $field) $row[$field] = (int) $row[$field];
        return $row;
    }

    public function chunkPage(int $supplierId, int $manifestId, int $afterOrdinal = -1, int $limit = 200, bool $currentRead = false): array
    {
        if ($limit < 1 || $limit > 500 || $afterOrdinal < -1) throw new \InvalidArgumentException('Snapshot chunk page bound is invalid.');
        $this->assertCurrentRead($currentRead);
        $lock = $currentRead ? ' FOR UPDATE' : '';
        $stmt = $this->db->pdo()->prepare("SELECT mc.ordinal,mc.chunk_hash AS link_hash,c.id AS chunk_id,c.schema_version,c.chunk_hash,c.row_count,
                c.first_cursor_json,c.last_cursor_json,c.payload_json
            FROM nonprofit_tax_snapshot_manifest_chunks mc
            JOIN nonprofit_tax_snapshot_chunks c ON c.supplier_id=mc.supplier_id AND c.id=mc.chunk_id
            WHERE mc.supplier_id=? AND mc.manifest_id=? AND mc.ordinal>? ORDER BY mc.ordinal LIMIT {$limit}{$lock}");
        $stmt->execute([$supplierId, $manifestId, $afterOrdinal]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function verify(int $supplierId, int $manifestId, bool $currentRead = false): SnapshotManifestReference
    {
        $manifest = $this->manifest($supplierId, $manifestId, $currentRead);
        $header = new SnapshotHeaderV2($manifest['header']);
        $headerHash = $this->hasher->header($header);
        if (!hash_equals($manifest['header_hash'], $headerHash)) throw new \UnexpectedValueException('Snapshot header hash mismatch.');
        $root = $this->hasher->startRoot();
        $ordinal = 0;
        $rows = 0;
        $findings = 0;
        while (true) {
            $page = $this->chunkPage($supplierId, $manifestId, $ordinal - 1, self::VERIFY_CHUNK_BATCH, $currentRead);
            if ($page === []) break;
            foreach ($page as $stored) {
                if ((int) $stored['ordinal'] !== $ordinal || $stored['schema_version'] !== SnapshotHeaderV2::SCHEMA
                    || !hash_equals($stored['link_hash'], $stored['chunk_hash'])) {
                    throw new \UnexpectedValueException('Snapshot chunk sequence is invalid.');
                }
                $payload = json_decode($stored['payload_json'], true, flags: JSON_THROW_ON_ERROR);
                $descriptor = $this->hasher->verifyChunk($payload, $stored['chunk_hash']);
                $first = $stored['first_cursor_json'] === null ? null : json_decode($stored['first_cursor_json'], true, flags: JSON_THROW_ON_ERROR);
                $last = $stored['last_cursor_json'] === null ? null : json_decode($stored['last_cursor_json'], true, flags: JSON_THROW_ON_ERROR);
                if ((int) $stored['row_count'] !== $descriptor['row_count'] || $first !== $descriptor['first_cursor'] || $last !== $descriptor['last_cursor']) {
                    throw new \UnexpectedValueException('Snapshot chunk metadata mismatch.');
                }
                $this->hasher->updateRoot($root, $descriptor);
                $rows += $descriptor['row_count'];
                $findings += $descriptor['finding_count'];
                ++$ordinal;
            }
            if (count($page) < self::VERIFY_CHUNK_BATCH) break;
        }
        $rootHash = $this->hasher->finishRoot($root, $ordinal);
        $inputHash = $this->hasher->input($header, $headerHash, $rootHash, $rows, $findings, $ordinal);
        if ($ordinal !== $manifest['chunk_count'] || $rows !== $manifest['row_count'] || $findings !== $manifest['finding_count']
            || !hash_equals($manifest['root_hash'], $rootHash) || !hash_equals($manifest['input_hash'], $inputHash)) {
            throw new \UnexpectedValueException('Snapshot manifest totals or hashes do not match.');
        }
        return $this->reference($manifest);
    }

    /** @param list<array{0:int,1:int,2:int,3:int,4:string}> $batch */
    private function insertLinks(array $batch): void
    {
        $values = implode(',', array_fill(0, count($batch), '(?,?,?,?,?)'));
        $params = [];
        foreach ($batch as $row) array_push($params, ...$row);
        $stmt = $this->db->pdo()->prepare("INSERT INTO nonprofit_tax_snapshot_manifest_chunks
            (supplier_id,manifest_id,ordinal,chunk_id,chunk_hash) VALUES {$values}");
        $stmt->execute($params);
    }

    private function reference(array $row): SnapshotManifestReference
    {
        return new SnapshotManifestReference((int) $row['id'], (int) $row['supplier_id'], (int) $row['period_id'],
            (string) $row['input_hash'], (string) $row['root_hash'], (int) $row['row_count'], (int) $row['chunk_count']);
    }

    private function assertCurrentRead(bool $currentRead): void
    {
        if ($currentRead && !$this->db->pdo()->inTransaction()) throw new \LogicException('Current snapshot manifest reads require a transaction.');
    }
}
