<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentGraphReceipt;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final readonly class NonprofitTaxLegacySidecarRepository
{
    public function __construct(private Connection $db) {}

    public function lock(int $supplierId, int $periodId, int $assessmentId): array
    {
        $this->transactionRequired();
        $stmt = $this->db->pdo()->prepare("SELECT id,supplier_id,period_id,storage_version,input_hash,request_hash,
                SHA2(snapshot_json,256) AS snapshot_sha256,SHA2(assessment_json,256) AS assessment_sha256,
                SHA2(result_json,256) AS result_sha256
            FROM nonprofit_tax_assessments WHERE supplier_id=? AND period_id=? AND id=? FOR UPDATE");
        $stmt->execute([$supplierId, $periodId, $assessmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new NonprofitTaxAssessmentException('nonprofit_tax_assessment_not_found', 'Daňové posouzení nebylo nalezeno.', 404);
        if (($row['storage_version'] ?? null) === 'paged_v2') return $this->normalize($row);
        if (!in_array($row['storage_version'], ['legacy_json_v1', 'legacy_json_v2'], true)) {
            throw new NonprofitTaxAssessmentException('nonprofit_tax_legacy_schema_unsupported', 'Historické posouzení používá nepodporovaný formát.', 409);
        }
        return $this->normalize($row);
    }

    public function promote(array $locked, J6AssessmentGraphReceipt $receipt, string $reason, int $actor): void
    {
        $this->transactionRequired();
        if (($locked['storage_version'] ?? null) === 'paged_v2') return;
        if ($actor <= 0 || $reason === '' || mb_strlen($reason) > 500) throw new \InvalidArgumentException('Legacy J6 conversion audit is invalid.');
        $graph = $receipt->toArray();
        $stmt = $this->db->pdo()->prepare("UPDATE nonprofit_tax_assessments SET
                legacy_storage_version=storage_version,legacy_input_hash=input_hash,
                legacy_snapshot_sha256=?,legacy_assessment_sha256=?,legacy_result_sha256=?,
                sidecar_converted_at_utc=UTC_TIMESTAMP(6),sidecar_converted_by=?,sidecar_conversion_reason=?,
                storage_version='paged_v2',input_hash=?,source_manifest_id=?,funding_reference_manifest_id=?,
                funding_history_manifest_id=?,decision_manifest_id=?,result_manifest_id=?,coverage_manifest_id=?,usage_manifest_id=?,
                calculation_input_hash=?,result_root_hash=?,coverage_root_hash=?,summary_json=?,j6_receipt_json=?
            WHERE supplier_id=? AND period_id=? AND id=? AND storage_version=? AND input_hash=? AND request_hash=?
              AND SHA2(snapshot_json,256)=? AND SHA2(assessment_json,256)=? AND SHA2(result_json,256)=?");
        $stmt->execute([$locked['snapshot_sha256'], $locked['assessment_sha256'], $locked['result_sha256'], $actor, $reason,
            $receipt->snapshotInputHash, $receipt->snapshotManifestId, $receipt->evidenceManifestIds['funding_reference'],
            $receipt->evidenceManifestIds['funding_history'], $receipt->manifestIds['decision'], $receipt->manifestIds['result'],
            $receipt->manifestIds['coverage'], $receipt->manifestIds['usage'], $receipt->calculationInputHash,
            $receipt->rootHashes['result'], $receipt->rootHashes['coverage'], NonprofitTaxRules::canonicalJson($receipt->summary),
            NonprofitTaxRules::canonicalJson($graph), $locked['supplier_id'], $locked['period_id'], $locked['id'],
            $locked['storage_version'], $locked['input_hash'], $locked['request_hash'], $locked['snapshot_sha256'],
            $locked['assessment_sha256'], $locked['result_sha256']]);
        if ($stmt->rowCount() !== 1) {
            throw new NonprofitTaxAssessmentException('nonprofit_tax_assessment_changed', 'Historické posouzení se během převodu změnilo.', 409);
        }
        $this->assertRawIdentity($locked);
    }

    private function assertRawIdentity(array $locked): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT legacy_storage_version,legacy_input_hash,
                SHA2(snapshot_json,256),SHA2(assessment_json,256),SHA2(result_json,256)
            FROM nonprofit_tax_assessments WHERE supplier_id=? AND id=? FOR UPDATE');
        $stmt->execute([$locked['supplier_id'], $locked['id']]);
        $actual = $stmt->fetch(PDO::FETCH_NUM);
        $expected = [$locked['storage_version'], $locked['input_hash'], $locked['snapshot_sha256'],
            $locked['assessment_sha256'], $locked['result_sha256']];
        if ($actual !== $expected) throw new \UnexpectedValueException('Legacy J6 raw proof changed during conversion.');
    }

    private function normalize(array $row): array
    {
        foreach (['id', 'supplier_id', 'period_id'] as $field) $row[$field] = (int) $row[$field];
        foreach (['input_hash', 'request_hash', 'snapshot_sha256', 'assessment_sha256', 'result_sha256'] as $field) {
            $row[$field] = strtolower((string) $row[$field]);
            if (preg_match('/^[a-f0-9]{64}$/D', $row[$field]) !== 1) throw new \UnexpectedValueException('Legacy J6 identity hash is invalid.');
        }
        return $row;
    }

    private function transactionRequired(): void
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Legacy J6 conversion requires an owner transaction.');
    }
}
