<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Nonprofit\Catalog;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\FundingRepository;
use MyInvoice\Service\Nonprofit\Funding\FundingPolicy;
use MyInvoice\Service\Nonprofit\Funding\FundingRevisionShape;
use PDO;

final class FundingCatalogReadRepository
{
    private const HISTORY_BATCH = 500;

    public function __construct(private readonly Connection $db) {}

    public function page(int $supplier, int $after, int $limit, ?int $id = null): array
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Funding catalog read requires an owner transaction.');
        }
        if ($supplier <= 0 || $after < 0 || $limit < 1 || $limit > 100 || ($id !== null && $id <= 0)) {
            FundingPolicy::invalid('Neplatný rozsah katalogu účelových zdrojů.');
        }
        [$selected, $more] = $id === null
            ? $this->firstPage($supplier, $after, $limit)
            : $this->selectedFirst($supplier, $id);
        if ($selected === []) {
            return ['rows' => [], 'next_cursor' => null, 'revision_rows_verified' => 0];
        }
        $byKey = [];
        foreach ($selected as $first) {
            if (isset($byKey[$first['source_key']])) {
                FundingPolicy::invalid('Historie účelového zdroje není jednoznačná.', 'nonprofit_funding_integrity');
            }
            $byKey[$first['source_key']] = $first['id'];
        }
        $keys = array_keys($byKey);
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $afterKey = '';
        $afterId = 0;
        $previous = [];
        $current = [];
        $verified = 0;
        do {
            $query = $this->db->pdo()->prepare(
                "SELECT " . FundingRevisionShape::selectColumns() . " FROM nonprofit_funding_revisions FORCE INDEX(uq_nfr_chain)
                  WHERE supplier_id=? AND source_key IN ({$marks})
                    AND (source_key>? OR (source_key=? AND id>?))
                  ORDER BY source_key,id LIMIT 501"
            );
            $query->execute([$supplier, ...$keys, $afterKey, $afterKey, $afterId]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            $moreHistory = count($rows) > self::HISTORY_BATCH;
            if ($moreHistory) {
                array_pop($rows);
            }
            foreach ($rows as $raw) {
                $key = $raw['source_key'];
                if (!isset($byKey[$key])) {
                    FundingPolicy::invalid('Historie účelového zdroje překročila vybraný rozsah.', 'nonprofit_funding_integrity');
                }
                $row = FundingRepository::verifiedRevision($raw, $previous[$key] ?? null);
                if ($row['revision_no'] === 1 && $row['id'] !== $byKey[$key]) {
                    FundingPolicy::invalid('Stabilní identifikátor účelového zdroje není průkazný.', 'nonprofit_funding_integrity');
                }
                $previous[$key] = ['id' => $row['id'], 'revision_no' => $row['revision_no'], 'record_hash' => $row['record_hash']];
                $current[$byKey[$key]] = $row;
                $afterKey = $key;
                $afterId = $row['id'];
                ++$verified;
            }
            if ($moreHistory && $rows === []) {
                FundingPolicy::invalid('Historie účelového zdroje nelze stránkovat.', 'nonprofit_funding_integrity');
            }
        } while ($moreHistory);
        if (count($current) !== count($selected)) {
            FundingPolicy::invalid('Historie účelového zdroje není úplná.', 'nonprofit_funding_integrity');
        }
        $ordered = [];
        foreach ($selected as $first) {
            $row = $current[$first['id']];
            $row['_first_id'] = $first['id'];
            $ordered[] = $row;
        }
        return [
            'rows' => $ordered,
            'next_cursor' => $more ? $selected[array_key_last($selected)]['id'] : null,
            'revision_rows_verified' => $verified,
        ];
    }

    private function firstPage(int $supplier, int $after, int $limit): array
    {
        $query = $this->db->pdo()->prepare(
            'SELECT id,source_key FROM nonprofit_funding_revisions FORCE INDEX(ix_nfr_catalog_first)
              WHERE supplier_id=? AND revision_no=1 AND id>? ORDER BY id LIMIT ' . ($limit + 1)
        );
        $query->execute([$supplier, $after]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > $limit;
        if ($more) {
            array_pop($rows);
        }
        return [array_map(self::first(...), $rows), $more];
    }

    private function selectedFirst(int $supplier, int $id): array
    {
        $query = $this->db->pdo()->prepare(
            'SELECT id,source_key,revision_no FROM nonprofit_funding_revisions FORCE INDEX(uq_nfr_tenant)
              WHERE supplier_id=? AND id=? LIMIT 1'
        );
        $query->execute([$supplier, $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['revision_no'] !== 1) {
            return [[], false];
        }
        return [[self::first($row)], false];
    }

    private static function first(array $row): array
    {
        return ['id' => (int) $row['id'], 'source_key' => $row['source_key']];
    }
}
