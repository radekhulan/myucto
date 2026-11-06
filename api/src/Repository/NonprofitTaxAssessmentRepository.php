<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final class NonprofitTaxAssessmentRepository
{
    public function __construct(private readonly Connection $db) {}

    public function latest(int $supplierId, int $periodId, bool $currentRead = false): ?array
    {
        return $this->one('supplier_id = ? AND period_id = ? ORDER BY revision_no DESC LIMIT 1', [$supplierId, $periodId], $currentRead);
    }

    public function find(int $supplierId, int $id): array
    {
        return $this->one('supplier_id = ? AND id = ?', [$supplierId, $id], false)
            ?? throw new NonprofitTaxAssessmentException('nonprofit_tax_assessment_not_found', 'Daňové posouzení nebylo nalezeno.', 404);
    }

    public function byIdempotency(int $supplierId, string $key): ?array
    {
        return $this->one('supplier_id = ? AND idempotency_key = ?', [$supplierId, $key], true);
    }

    public function history(int $supplierId, int $periodId, bool $currentRead = false): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM nonprofit_tax_assessments WHERE supplier_id = ? AND period_id = ? ORDER BY revision_no' . $this->lock($currentRead));
        $stmt->execute([$supplierId, $periodId]);
        return array_map(self::decode(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function confirmedHistory(int $supplierId, bool $currentRead = false): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT * FROM nonprofit_tax_assessments WHERE supplier_id = ? AND status = 'confirmed' ORDER BY period_id, revision_no" . $this->lock($currentRead));
        $stmt->execute([$supplierId]);
        return array_map(self::decode(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Poslední revize každého období, které má aspoň jednu potvrzenou revizi.
     * Pořadí revizí určí databáze po indexu (supplier_id, period_id, revision_no),
     * JSON podklady se načítají jen pro vybrané revize.
     *
     * @return list<array<string,mixed>>
     */
    public function latestOfConfirmedPeriods(int $supplierId, bool $currentRead = false): array
    {
        $ids = $this->db->pdo()->prepare(
            "SELECT id FROM (
                SELECT id, period_id,
                       ROW_NUMBER() OVER (PARTITION BY period_id ORDER BY revision_no DESC) AS position,
                       MAX(status = 'confirmed') OVER (PARTITION BY period_id) AS has_confirmed
                  FROM nonprofit_tax_assessments
                 WHERE supplier_id = ?
             ) ranked
             WHERE position = 1 AND has_confirmed = 1
             ORDER BY period_id"
        );
        $ids->execute([$supplierId]);
        $selected = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
        if ($selected === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($selected), '?'));
        $stmt = $this->db->pdo()->prepare('SELECT * FROM nonprofit_tax_assessments WHERE supplier_id = ? AND id IN (' . $marks . ') ORDER BY period_id' . $this->lock($currentRead));
        $stmt->execute([$supplierId, ...$selected]);
        return array_map(self::decode(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function effectiveOtherPeriods(int $supplierId, int $periodId, bool $currentRead = false): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM nonprofit_tax_assessments WHERE supplier_id = ? AND period_id <> ? ORDER BY period_id, revision_no' . $this->lock($currentRead));
        $stmt->execute([$supplierId, $periodId]);
        $latest = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $latest[(int) $row['period_id']] = self::decode($row);
        return array_values($latest);
    }

    public function append(int $supplierId, int $periodId, ?array $previous, string $status, string $reason,
        string $key, string $requestHash, array $snapshot, array $assessment, array $result, int $actor): array
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Tax confirmation requires a transaction.');
        $stmt = $this->db->pdo()->prepare('INSERT INTO nonprofit_tax_assessments
            (supplier_id,period_id,revision_no,previous_id,status,reason,ruleset,input_hash,request_hash,idempotency_key,snapshot_json,assessment_json,result_json,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$supplierId, $periodId, ($previous['revision_no'] ?? 0) + 1, $previous['id'] ?? null, $status, $reason,
            NonprofitTaxRules::VERSION, $snapshot['input_hash'], $requestHash, $key,
            NonprofitTaxRules::canonicalJson($snapshot), NonprofitTaxRules::canonicalJson($assessment), NonprofitTaxRules::canonicalJson($result), $actor]);
        return $this->one('supplier_id = ? AND id = ?', [$supplierId, (int) $this->db->pdo()->lastInsertId()], true);
    }

    private function one(string $where, array $params, bool $currentRead): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM nonprofit_tax_assessments WHERE ' . $where . $this->lock($currentRead));
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::decode($row) : null;
    }

    private static function decode(array $row): array
    {
        foreach (['id', 'supplier_id', 'period_id', 'revision_no', 'created_by'] as $key) $row[$key] = (int) $row[$key];
        $row['previous_id'] = $row['previous_id'] === null ? null : (int) $row['previous_id'];
        foreach (['snapshot', 'assessment', 'result'] as $key) {
            $row[$key] = json_decode($row[$key . '_json'], true, flags: JSON_THROW_ON_ERROR);
            unset($row[$key . '_json']);
        }
        // Výslovně převedené legacy posouzení nese nový otisk stránkovaného grafu, ale jeho
        // původní JSON zůstává bajtově stejný (hlídá trigger v migraci 2129). Legacy čtenáři proto
        // dostávají původní otisk, aby převod nezměnil výsledek DPPO ani ověření financování.
        if (($row['storage_version'] ?? null) === 'paged_v2' && is_string($row['legacy_input_hash'] ?? null)) {
            $row['input_hash'] = $row['legacy_input_hash'];
        }
        return $row;
    }

    private function lock(bool $currentRead): string
    {
        if ($currentRead && !$this->db->pdo()->inTransaction()) throw new \LogicException('Current assessment reads require a transaction.');
        return $currentRead ? ' FOR UPDATE' : '';
    }
}
