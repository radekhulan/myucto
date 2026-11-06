<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentGraphReceipt;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6HistoricAssessmentBinding;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final readonly class NonprofitTaxPagedAssessmentRepository
{
    public function __construct(private Connection $db) {}

    public function appendConfirmed(int $supplierId, int $periodId, ?array $previous, string $reason, string $idempotencyKey,
        string $requestHash, array $decisionHeader, J6AssessmentGraphReceipt $receipt, int $actor): array
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Paged J6 confirmation requires an owner transaction.');
        if ($supplierId <= 0 || $periodId <= 0 || $actor <= 0 || $reason === '' || $idempotencyKey === ''
            || preg_match('/^[a-f0-9]{64}$/D', $requestHash) !== 1) {
            throw new \InvalidArgumentException('Paged J6 confirmation identity is invalid.');
        }
        foreach (['activities', 'decisions', 'funding', 'funding_sources', 'funding_costs'] as $unbounded) {
            if (array_key_exists($unbounded, $decisionHeader)) throw new \InvalidArgumentException('Paged J6 assessment header contains row collections.');
        }
        $graph = $receipt->toArray();
        $snapshot = ['schema' => 'nonprofit-j6-snapshot-reference.v1', 'manifest_id' => $receipt->snapshotManifestId,
            'input_hash' => $receipt->snapshotInputHash, 'root_hash' => $receipt->snapshotRootHash];
        $assessment = ['schema' => 'nonprofit-j6-decision-reference.v1', 'header' => $decisionHeader,
            'manifest_id' => $receipt->manifestIds['decision'], 'root_hash' => $receipt->rootHashes['decision']];
        $result = ['schema' => 'nonprofit-j6-result-reference.v1', 'summary' => $receipt->summary,
            'manifest_id' => $receipt->manifestIds['result'], 'root_hash' => $receipt->rootHashes['result'],
            'coverage_manifest_id' => $receipt->manifestIds['coverage'], 'coverage_root_hash' => $receipt->rootHashes['coverage'],
            'usage_manifest_id' => $receipt->manifestIds['usage'], 'usage_root_hash' => $receipt->rootHashes['usage']];
        $stmt = $this->db->pdo()->prepare('INSERT INTO nonprofit_tax_assessments
            (supplier_id,period_id,revision_no,previous_id,status,reason,ruleset,storage_version,input_hash,request_hash,idempotency_key,
             snapshot_json,assessment_json,result_json,created_by,source_manifest_id,funding_reference_manifest_id,funding_history_manifest_id,
             decision_manifest_id,result_manifest_id,coverage_manifest_id,usage_manifest_id,calculation_input_hash,result_root_hash,
             coverage_root_hash,summary_json,j6_receipt_json,created_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $stmt->execute([$supplierId, $periodId, (int) ($previous['revision_no'] ?? 0) + 1, $previous['id'] ?? null,
            'confirmed', $reason, $receipt->ruleset, 'paged_v2', $receipt->snapshotInputHash, $requestHash, $idempotencyKey,
            NonprofitTaxRules::canonicalJson($snapshot), NonprofitTaxRules::canonicalJson($assessment),
            NonprofitTaxRules::canonicalJson($result), $actor, $receipt->snapshotManifestId,
            $receipt->evidenceManifestIds['funding_reference'], $receipt->evidenceManifestIds['funding_history'],
            $receipt->manifestIds['decision'], $receipt->manifestIds['result'], $receipt->manifestIds['coverage'],
            $receipt->manifestIds['usage'], $receipt->calculationInputHash, $receipt->rootHashes['result'],
            $receipt->rootHashes['coverage'], NonprofitTaxRules::canonicalJson($receipt->summary),
            NonprofitTaxRules::canonicalJson($graph)]);
        return $this->find($supplierId, (int) $this->db->pdo()->lastInsertId(), true);
    }

    public function appendWithdrawn(int $supplierId, int $periodId, array $previous, string $reason,
        string $idempotencyKey, string $requestHash, int $actor): array
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Paged J6 withdrawal requires an owner transaction.');
        $receipt = $previous['graph_receipt'] ?? null;
        if (!$receipt instanceof J6AssessmentGraphReceipt || ($previous['status'] ?? null) !== 'confirmed'
            || ($previous['supplier_id'] ?? null) !== $supplierId || ($previous['period_id'] ?? null) !== $periodId
            || $supplierId <= 0 || $periodId <= 0 || $actor <= 0 || $reason === '' || $idempotencyKey === ''
            || preg_match('/^[a-f0-9]{64}$/D', $requestHash) !== 1) {
            throw new \InvalidArgumentException('Paged J6 withdrawal identity is invalid.');
        }
        $graph = $receipt->toArray();
        $snapshot = ['schema' => 'nonprofit-j6-snapshot-reference.v1', 'manifest_id' => $receipt->snapshotManifestId,
            'input_hash' => $receipt->snapshotInputHash, 'root_hash' => $receipt->snapshotRootHash];
        $assessment = ['schema' => 'nonprofit-j6-withdrawal-reference.v1',
            'withdrawn_from_assessment_id' => $previous['id'], 'manifest_id' => $receipt->manifestIds['decision'],
            'root_hash' => $receipt->rootHashes['decision']];
        $result = ['schema' => 'nonprofit-j6-result-reference.v1', 'summary' => $receipt->summary,
            'manifest_id' => $receipt->manifestIds['result'], 'root_hash' => $receipt->rootHashes['result'],
            'coverage_manifest_id' => $receipt->manifestIds['coverage'], 'coverage_root_hash' => $receipt->rootHashes['coverage'],
            'usage_manifest_id' => $receipt->manifestIds['usage'], 'usage_root_hash' => $receipt->rootHashes['usage']];
        $stmt = $this->db->pdo()->prepare('INSERT INTO nonprofit_tax_assessments
            (supplier_id,period_id,revision_no,previous_id,status,reason,ruleset,storage_version,input_hash,request_hash,idempotency_key,
             snapshot_json,assessment_json,result_json,created_by,source_manifest_id,funding_reference_manifest_id,funding_history_manifest_id,
             decision_manifest_id,result_manifest_id,coverage_manifest_id,usage_manifest_id,calculation_input_hash,result_root_hash,
             coverage_root_hash,summary_json,j6_receipt_json,created_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $stmt->execute([$supplierId, $periodId, (int) $previous['revision_no'] + 1, $previous['id'],
            'withdrawn', $reason, $receipt->ruleset, 'paged_v2', $receipt->snapshotInputHash, $requestHash, $idempotencyKey,
            NonprofitTaxRules::canonicalJson($snapshot), NonprofitTaxRules::canonicalJson($assessment),
            NonprofitTaxRules::canonicalJson($result), $actor, $receipt->snapshotManifestId,
            $receipt->evidenceManifestIds['funding_reference'], $receipt->evidenceManifestIds['funding_history'],
            $receipt->manifestIds['decision'], $receipt->manifestIds['result'], $receipt->manifestIds['coverage'],
            $receipt->manifestIds['usage'], $receipt->calculationInputHash, $receipt->rootHashes['result'],
            $receipt->rootHashes['coverage'], NonprofitTaxRules::canonicalJson($receipt->summary),
            NonprofitTaxRules::canonicalJson($graph)]);
        return $this->find($supplierId, (int) $this->db->pdo()->lastInsertId(), true);
    }

    public function find(int $supplierId, int $assessmentId, bool $currentRead = false): array
    {
        $this->currentRead($currentRead);
        $stmt = $this->db->pdo()->prepare('SELECT ' . $this->columns() . ' FROM nonprofit_tax_assessments
            WHERE supplier_id=? AND id=?' . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $assessmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new NonprofitTaxAssessmentException('nonprofit_tax_assessment_not_found', 'Daňové posouzení nebylo nalezeno.', 404);
        return $this->decode($row);
    }

    public function latest(int $supplierId, int $periodId, bool $currentRead = false): ?array
    {
        $this->currentRead($currentRead);
        $stmt = $this->db->pdo()->prepare('SELECT ' . $this->columns() . ' FROM nonprofit_tax_assessments
            WHERE supplier_id=? AND period_id=? ORDER BY revision_no DESC LIMIT 1' . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $periodId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->decode($row);
    }

    public function byIdempotency(int $supplierId, string $key, bool $currentRead = false): ?array
    {
        $this->currentRead($currentRead);
        $stmt = $this->db->pdo()->prepare('SELECT ' . $this->columns() . ' FROM nonprofit_tax_assessments
            WHERE supplier_id=? AND idempotency_key=?' . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->decode($row);
    }

    public function historyPage(int $supplierId, int $periodId, int $afterRevision = 0, int $limit = 100): array
    {
        if ($afterRevision < 0 || $limit < 1 || $limit > 500) throw new \InvalidArgumentException('Paged J6 history page is invalid.');
        $stmt = $this->db->pdo()->prepare('SELECT ' . $this->columns() . " FROM nonprofit_tax_assessments
            WHERE supplier_id=? AND period_id=? AND revision_no>? ORDER BY revision_no LIMIT {$limit}");
        $stmt->execute([$supplierId, $periodId, $afterRevision]);
        return array_map($this->decode(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function historyHeader(int $supplierId, int $periodId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT p.id AS period_id,a.id AS assessment_id,a.revision_no,a.previous_id,a.status,
                a.reason,a.ruleset,a.storage_version,a.created_by,a.created_at,
                COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(a.j6_receipt_json,'$.inputs.source_hash')),''),a.input_hash) AS source_input_hash
            FROM accounting_periods p
            LEFT JOIN nonprofit_tax_assessments a ON a.supplier_id=p.supplier_id AND a.period_id=p.id
             AND a.revision_no=(SELECT MAX(head.revision_no) FROM nonprofit_tax_assessments head
                WHERE head.supplier_id=p.supplier_id AND head.period_id=p.id)
            WHERE p.supplier_id=? AND p.id=? LIMIT 1");
        $stmt->execute([$supplierId, $periodId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new NonprofitTaxAssessmentException('nonprofit_tax_period_not_found', 'Účetní období nebylo nalezeno.', 404);
        return ['period_id' => (int) $row['period_id'],
            'assessment_id' => $row['assessment_id'] === null ? null : (int) $row['assessment_id'],
            'revision_no' => $row['revision_no'] === null ? 0 : (int) $row['revision_no'],
            'previous_id' => $row['previous_id'] === null ? null : (int) $row['previous_id'],
            'status' => $row['status'], 'reason' => $row['reason'], 'ruleset' => $row['ruleset'],
            'storage_version' => $row['storage_version'],
            'created_by' => $row['created_by'] === null ? null : (int) $row['created_by'],
            'created_at' => $row['created_at'], 'source_input_hash' => $row['source_input_hash']];
    }

    public function effectiveBeforePage(int $supplierId, int $excludedPeriodId, string $endsBefore, int $afterPeriodId = 0,
        int $limit = 100, bool $currentRead = false): array
    {
        if ($limit < 1 || $limit > 500 || $afterPeriodId < 0) throw new \InvalidArgumentException('Paged J6 effective history page is invalid.');
        $this->currentRead($currentRead);
        $lock = $currentRead ? ' FOR UPDATE' : '';
        $stmt = $this->db->pdo()->prepare('SELECT ' . $this->columns('a') . ' FROM nonprofit_tax_assessments a
            JOIN accounting_periods p ON p.supplier_id=a.supplier_id AND p.id=a.period_id
            WHERE a.supplier_id=? AND a.period_id<>? AND p.ends_on<? AND a.period_id>?
              AND NOT EXISTS(SELECT 1 FROM nonprofit_tax_assessments newer
                WHERE newer.supplier_id=a.supplier_id AND newer.period_id=a.period_id AND newer.revision_no>a.revision_no)
            ORDER BY a.period_id LIMIT ' . $limit . $lock);
        $stmt->execute([$supplierId, $excludedPeriodId, $endsBefore, $afterPeriodId]);
        return array_map($this->decode(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function periodBounds(int $supplierId, int $periodId, bool $currentRead = false): array
    {
        $this->currentRead($currentRead);
        $stmt = $this->db->pdo()->prepare('SELECT starts_on,ends_on FROM accounting_periods WHERE supplier_id=? AND id=?'
            . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $periodId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new NonprofitTaxAssessmentException('nonprofit_tax_period_not_found', 'Účetní období nebylo nalezeno.', 404);
        return $row;
    }

    public function effectiveAfterPage(int $supplierId, int $excludedPeriodId, string $startsAfter,
        string $afterStartsOn = '', int $afterPeriodId = 0, int $limit = 100, bool $currentRead = false): array
    {
        if ($limit < 1 || $limit > 500 || $afterPeriodId < 0) throw new \InvalidArgumentException('Paged J6 later history page is invalid.');
        $this->currentRead($currentRead);
        $lock = $currentRead ? ' FOR UPDATE' : '';
        $stmt = $this->db->pdo()->prepare('SELECT ' . $this->columns('a') . ',p.starts_on AS period_starts_on
            FROM accounting_periods p JOIN nonprofit_tax_assessments a
              ON a.supplier_id=p.supplier_id AND a.period_id=p.id
            WHERE p.supplier_id=? AND p.id<>? AND p.starts_on>?
              AND (p.starts_on>? OR (p.starts_on=? AND p.id>?))
              AND NOT EXISTS(SELECT 1 FROM nonprofit_tax_assessments newer
                WHERE newer.supplier_id=a.supplier_id AND newer.period_id=a.period_id AND newer.revision_no>a.revision_no)
            ORDER BY p.starts_on,p.id LIMIT ' . $limit . $lock);
        $stmt->execute([$supplierId, $excludedPeriodId, $startsAfter, $afterStartsOn, $afterStartsOn, $afterPeriodId]);
        return array_map($this->decode(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function binding(array $assessment): J6HistoricAssessmentBinding
    {
        if (($assessment['storage_version'] ?? null) !== 'paged_v2' || !($assessment['graph_receipt'] ?? null) instanceof J6AssessmentGraphReceipt) {
            throw new NonprofitTaxAssessmentException('nonprofit_tax_legacy_history_requires_migration',
                'Historický podklad financování musí být převeden na stránkovaný neměnný graf.', 409);
        }
        $receipt = $assessment['graph_receipt'];
        return new J6HistoricAssessmentBinding($assessment['supplier_id'], $assessment['period_id'], $assessment['id'],
            $receipt->snapshotManifestId, $receipt->evidenceManifestIds['funding_reference'],
            $receipt->evidenceManifestIds['funding_history'], $receipt->manifestIds['decision'],
            $receipt->manifestIds['result'], $receipt->manifestIds['coverage'], $receipt->manifestIds['usage'],
            $receipt->snapshotInputHash, $receipt->sourceInputHash, $receipt->calculationInputHash,
            $receipt->rootHashes, $receipt->evidenceRootHashes);
    }

    private function decode(array $row): array
    {
        foreach (['id', 'supplier_id', 'period_id', 'revision_no', 'created_by'] as $field) $row[$field] = (int) $row[$field];
        $row['previous_id'] = $row['previous_id'] === null ? null : (int) $row['previous_id'];
        $row['graph_receipt'] = null;
        if ($row['j6_receipt_json'] !== null) {
            $row['graph_receipt'] = J6AssessmentGraphReceipt::fromArray(json_decode($row['j6_receipt_json'], true, flags: JSON_THROW_ON_ERROR));
        }
        unset($row['j6_receipt_json']);
        return $row;
    }

    private function columns(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        return implode(',', array_map(static fn (string $column): string => $prefix . $column,
            ['id', 'supplier_id', 'period_id', 'revision_no', 'previous_id', 'status', 'reason', 'ruleset', 'storage_version',
                'input_hash', 'request_hash', 'idempotency_key', 'created_by', 'created_at', 'j6_receipt_json']));
    }

    private function currentRead(bool $currentRead): void
    {
        if ($currentRead && !$this->db->pdo()->inTransaction()) throw new \LogicException('Current paged J6 reads require an owner transaction.');
    }
}
