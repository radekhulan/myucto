<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final class NonprofitTaxDraftRepository
{
    public const int MAX_BATCH = 500;

    public function __construct(private readonly Connection $db) {}

    public function open(int $supplierId, int $periodId, string $sourceInputHash, array $header, int $actor, ?int $sourceManifestId = null): array
    {
        $this->transactionRequired();
        $stmt = $this->db->pdo()->prepare('SELECT * FROM nonprofit_tax_drafts WHERE supplier_id=? AND period_id=? AND created_by=? FOR UPDATE');
        $stmt->execute([$supplierId, $periodId, $actor]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            $stmt = $this->db->pdo()->prepare('INSERT INTO nonprofit_tax_drafts(supplier_id,period_id,source_manifest_id,source_input_hash,header_json,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))');
            $stmt->execute([$supplierId, $periodId, $sourceManifestId, $sourceInputHash, NonprofitTaxRules::canonicalJson($header), $actor]);
            return $this->find($supplierId, (int) $this->db->pdo()->lastInsertId(), true);
        }
        return self::decode($row);
    }

    /** Rozpracovaný koncept daného uživatele pro období (nejvýše jeden, uq_ntd_owner). */
    public function ownedByActor(int $supplierId, int $periodId, int $actor): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM nonprofit_tax_drafts WHERE supplier_id=? AND period_id=? AND created_by=?');
        $stmt->execute([$supplierId, $periodId, $actor]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::decode($row);
    }

    public function find(int $supplierId, int $draftId, bool $currentRead = false): array
    {
        if ($currentRead) $this->transactionRequired();
        $stmt = $this->db->pdo()->prepare('SELECT * FROM nonprofit_tax_drafts WHERE supplier_id=? AND id=?' . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $draftId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new NonprofitTaxAssessmentException('nonprofit_tax_draft_not_found', 'Koncept daňového posouzení nebyl nalezen.', 404);
        return self::decode($row);
    }

    public function reset(int $supplierId, int $draftId, int $expectedRevision, string $sourceInputHash, array $header, ?int $sourceManifestId = null): array
    {
        $this->advance($supplierId, $draftId, $expectedRevision, ['source_manifest_id' => $sourceManifestId, 'source_input_hash' => $sourceInputHash, 'header_json' => NonprofitTaxRules::canonicalJson($header)]);
        foreach (['nonprofit_tax_draft_funding_members', 'nonprofit_tax_draft_funding_links', 'nonprofit_tax_draft_funding_pools',
            'nonprofit_tax_draft_decisions', 'nonprofit_tax_draft_activities'] as $table) {
            $this->db->pdo()->prepare("DELETE FROM {$table} WHERE supplier_id=? AND draft_id=?")->execute([$supplierId, $draftId]);
        }
        return $this->find($supplierId, $draftId, true);
    }

    public function patchRecords(int $supplierId, int $draftId, int $expectedRevision, string $kind, array $upserts, array $deletes): array
    {
        $this->assertBatch($upserts, $deletes);
        $definition = $this->definition($kind);
        $this->advance($supplierId, $draftId, $expectedRevision);
        foreach ($upserts as $item) {
            if (!is_array($item)) throw new \InvalidArgumentException('Draft batch item is invalid.');
            $keys = [];
            foreach ($definition['keys'] as $key) $keys[] = $this->identifier($item[$key] ?? null, $key);
            $payload = $item['payload'] ?? null;
            if (!is_array($payload)) throw new \InvalidArgumentException('Draft batch payload is invalid.');
            $hash = NonprofitTaxRules::hash(['domain' => 'J6DRAFT.' . $kind, 'keys' => $keys, 'payload' => $payload]);
            $columns = implode(',', $definition['keys']);
            $marks = implode(',', array_fill(0, count($definition['keys']) + 4, '?'));
            $updates = 'payload_json=VALUES(payload_json),record_hash=VALUES(record_hash)';
            $stmt = $this->db->pdo()->prepare("INSERT INTO {$definition['table']}(supplier_id,draft_id,{$columns},payload_json,record_hash) VALUES({$marks}) ON DUPLICATE KEY UPDATE {$updates}");
            $stmt->execute([$supplierId, $draftId, ...$keys, NonprofitTaxRules::canonicalJson($payload), $hash]);
        }
        foreach ($deletes as $item) {
            if (!is_array($item)) throw new \InvalidArgumentException('Draft delete identity is invalid.');
            $where = [];
            $params = [$supplierId, $draftId];
            foreach ($definition['keys'] as $key) {
                $where[] = $key . '=?';
                $params[] = $this->identifier($item[$key] ?? null, $key);
            }
            $this->db->pdo()->prepare("DELETE FROM {$definition['table']} WHERE supplier_id=? AND draft_id=? AND " . implode(' AND ', $where))->execute($params);
        }
        return $this->find($supplierId, $draftId, true);
    }

    public function patchMembers(int $supplierId, int $draftId, int $expectedRevision, string $poolKind, string $anchor,
        array $upserts, array $deletes): array
    {
        $this->assertBatch($upserts, $deletes);
        $poolKind = $this->poolKind($poolKind);
        $anchor = $this->identifier($anchor, 'anchor');
        $this->advance($supplierId, $draftId, $expectedRevision);
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM nonprofit_tax_draft_funding_pools WHERE supplier_id=? AND draft_id=? AND pool_kind=? AND anchor=? FOR UPDATE');
        $stmt->execute([$supplierId, $draftId, $poolKind, $anchor]);
        if ($stmt->fetchColumn() === false) throw new NonprofitTaxAssessmentException('nonprofit_tax_draft_pool_not_found', 'Fond financování nebyl nalezen.', 404);
        $insert = $this->db->pdo()->prepare('INSERT IGNORE INTO nonprofit_tax_draft_funding_members(supplier_id,draft_id,pool_kind,anchor,member_id) VALUES(?,?,?,?,?)');
        foreach ($upserts as $member) $insert->execute([$supplierId, $draftId, $poolKind, $anchor, $this->identifier($member, 'member_id')]);
        $delete = $this->db->pdo()->prepare('DELETE FROM nonprofit_tax_draft_funding_members WHERE supplier_id=? AND draft_id=? AND pool_kind=? AND anchor=? AND member_id=?');
        foreach ($deletes as $member) $delete->execute([$supplierId, $draftId, $poolKind, $anchor, $this->identifier($member, 'member_id')]);
        return $this->find($supplierId, $draftId, true);
    }

    public function page(int $supplierId, int $draftId, string $kind, ?string $after = null, int $limit = 500): array
    {
        if ($limit < 1 || $limit > self::MAX_BATCH) throw new \InvalidArgumentException('Draft page limit is invalid.');
        $definition = $this->definition($kind);
        if (count($definition['keys']) !== 1) throw new \InvalidArgumentException('Composite draft records require their dedicated page.');
        $key = $definition['keys'][0];
        $params = [$supplierId, $draftId];
        $cursor = '';
        if ($after !== null) {
            $cursor = " AND {$key}>?";
            $params[] = $this->identifier($after, $key);
        }
        $stmt = $this->db->pdo()->prepare("SELECT {$key},payload_json,record_hash FROM {$definition['table']} WHERE supplier_id=? AND draft_id=?{$cursor} ORDER BY {$key} LIMIT {$limit}");
        $stmt->execute($params);
        return array_map(static function (array $row) use ($key): array {
            return [$key => $row[$key], 'payload' => json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR), 'record_hash' => $row['record_hash']];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function fundingPoolPage(int $supplierId, int $draftId, ?array $after = null, int $limit = 500): array
    {
        if ($limit < 1 || $limit > self::MAX_BATCH) throw new \InvalidArgumentException('Draft page limit is invalid.');
        $params = [$supplierId, $draftId];
        $cursor = '';
        if ($after !== null) {
            $cursor = ' AND (pool_kind>? OR (pool_kind=? AND anchor>?))';
            array_push($params, $this->poolKind($after['pool_kind'] ?? ''), $this->poolKind($after['pool_kind'] ?? ''), $this->identifier($after['anchor'] ?? null, 'anchor'));
        }
        $stmt = $this->db->pdo()->prepare("SELECT pool_kind,anchor,payload_json,record_hash FROM nonprofit_tax_draft_funding_pools WHERE supplier_id=? AND draft_id=?{$cursor} ORDER BY pool_kind,anchor LIMIT {$limit}");
        $stmt->execute($params);
        return array_map(static fn (array $row): array => ['pool_kind' => $row['pool_kind'], 'anchor' => $row['anchor'],
            'payload' => json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR), 'record_hash' => $row['record_hash']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function fundingMemberPoolPage(int $supplierId, int $draftId, string $poolKind, string $anchor,
        ?string $after = null, int $limit = 500): array
    {
        if ($limit < 1 || $limit > self::MAX_BATCH) throw new \InvalidArgumentException('Draft page limit is invalid.');
        $poolKind = $this->poolKind($poolKind);
        $anchor = $this->identifier($anchor, 'anchor');
        $params = [$supplierId, $draftId, $poolKind, $anchor];
        $cursor = '';
        if ($after !== null) {
            $cursor = ' AND member_id>?';
            $params[] = $this->identifier($after, 'member_id');
        }
        $stmt = $this->db->pdo()->prepare("SELECT member_id FROM nonprofit_tax_draft_funding_members
            WHERE supplier_id=? AND draft_id=? AND pool_kind=? AND anchor=?{$cursor} ORDER BY member_id LIMIT {$limit}");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function fundingMemberPage(int $supplierId, int $draftId, ?array $after = null, int $limit = 500): array
    {
        if ($limit < 1 || $limit > self::MAX_BATCH) throw new \InvalidArgumentException('Draft page limit is invalid.');
        $params = [$supplierId, $draftId];
        $cursor = '';
        if ($after !== null) {
            $poolKind = $this->poolKind($after['pool_kind'] ?? '');
            $anchor = $this->identifier($after['anchor'] ?? null, 'anchor');
            $member = $this->identifier($after['member_id'] ?? null, 'member_id');
            $cursor = ' AND (pool_kind>? OR (pool_kind=? AND anchor>?) OR (pool_kind=? AND anchor=? AND member_id>?))';
            array_push($params, $poolKind, $poolKind, $anchor, $poolKind, $anchor, $member);
        }
        $stmt = $this->db->pdo()->prepare("SELECT pool_kind,anchor,member_id FROM nonprofit_tax_draft_funding_members
            WHERE supplier_id=? AND draft_id=?{$cursor} ORDER BY pool_kind,anchor,member_id LIMIT {$limit}");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fundingLinkPage(int $supplierId, int $draftId, ?array $after = null, int $limit = 500): array
    {
        if ($limit < 1 || $limit > self::MAX_BATCH) throw new \InvalidArgumentException('Draft page limit is invalid.');
        $params = [$supplierId, $draftId];
        $cursor = '';
        if ($after !== null) {
            $cursor = ' AND (source_id>? OR (source_id=? AND cost_id>?))';
            $source = $this->identifier($after['source_id'] ?? null, 'source_id');
            array_push($params, $source, $source, $this->identifier($after['cost_id'] ?? null, 'cost_id'));
        }
        $stmt = $this->db->pdo()->prepare("SELECT source_id,cost_id,payload_json,record_hash FROM nonprofit_tax_draft_funding_links WHERE supplier_id=? AND draft_id=?{$cursor} ORDER BY source_id,cost_id LIMIT {$limit}");
        $stmt->execute($params);
        return array_map(static fn (array $row): array => ['source_id' => $row['source_id'], 'cost_id' => $row['cost_id'],
            'payload' => json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR), 'record_hash' => $row['record_hash']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function advance(int $supplierId, int $draftId, int $expectedRevision, array $fields = []): void
    {
        $this->transactionRequired();
        if ($expectedRevision <= 0) throw new \InvalidArgumentException('Draft expected revision is invalid.');
        $set = ['revision_no=revision_no+1', 'updated_at=UTC_TIMESTAMP(6)'];
        $params = [];
        foreach ($fields as $field => $value) {
            if (!in_array($field, ['source_manifest_id', 'source_input_hash', 'header_json'], true)) throw new \LogicException('Unsupported draft field.');
            $set[] = $field . '=?';
            $params[] = $value;
        }
        array_push($params, $supplierId, $draftId, $expectedRevision);
        $stmt = $this->db->pdo()->prepare('UPDATE nonprofit_tax_drafts SET ' . implode(',', $set) . ' WHERE supplier_id=? AND id=? AND revision_no=?');
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) throw new NonprofitTaxAssessmentException('nonprofit_tax_draft_changed', 'Koncept posouzení mezitím změnila jiná operace.', 409);
    }

    private function definition(string $kind): array
    {
        return match ($kind) {
            'activity' => ['table' => 'nonprofit_tax_draft_activities', 'keys' => ['activity_kind_id']],
            'decision' => ['table' => 'nonprofit_tax_draft_decisions', 'keys' => ['source_id']],
            'pool' => ['table' => 'nonprofit_tax_draft_funding_pools', 'keys' => ['pool_kind', 'anchor']],
            'link' => ['table' => 'nonprofit_tax_draft_funding_links', 'keys' => ['source_id', 'cost_id']],
            default => throw new \InvalidArgumentException('Draft record kind is invalid.'),
        };
    }

    private function assertBatch(array $upserts, array $deletes): void
    {
        if (count($upserts) + count($deletes) > self::MAX_BATCH) throw new \InvalidArgumentException('Draft mutation accepts at most 500 records.');
    }

    private function identifier(mixed $value, string $field): string
    {
        if (!is_string($value) && !is_int($value)) throw new \InvalidArgumentException('Draft identity is invalid.');
        $value = (string) $value;
        if (preg_match('/^[A-Za-z0-9_.:-]{1,160}$/D', $value) !== 1 || ($field === 'pool_kind' && !in_array($value, ['revenue', 'expense'], true))) {
            throw new \InvalidArgumentException('Draft identity is invalid.');
        }
        return $value;
    }

    private function poolKind(string $value): string
    {
        return in_array($value, ['revenue', 'expense'], true) ? $value : throw new \InvalidArgumentException('Funding pool kind is invalid.');
    }

    private function transactionRequired(): void
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Draft mutation or current read requires a transaction.');
    }

    private static function decode(array $row): array
    {
        foreach (['id', 'supplier_id', 'period_id', 'revision_no', 'created_by'] as $field) $row[$field] = (int) $row[$field];
        $row['source_manifest_id'] = isset($row['source_manifest_id']) ? (int) $row['source_manifest_id'] : null;
        $row['header'] = json_decode($row['header_json'], true, flags: JSON_THROW_ON_ERROR);
        unset($row['header_json']);
        return $row;
    }
}


