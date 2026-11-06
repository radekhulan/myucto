<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\NonprofitTaxCalculationManifestRepository;
use MyInvoice\Repository\NonprofitTaxLegacySidecarRepository;
use MyInvoice\Repository\NonprofitTaxPagedAssessmentRepository;
use MyInvoice\Repository\NonprofitTaxSnapshotManifestRepository;
use MyInvoice\Repository\NonprofitTaxSourceEvidenceManifestRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Anonymization\Streaming\ChunkedJsonColumnReader;
use MyInvoice\Service\Tax\Nonprofit\J6AuthoritativeCalculator;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\J6SourceManifestMaterializer;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;

final readonly class J6LegacyAssessmentConversionService
{
    public function __construct(
        private Connection $db,
        private DimensionPeriodGuard $periods,
        private NonprofitTaxLegacySidecarRepository $legacy,
        private NonprofitTaxPagedAssessmentRepository $assessments,
        private J6SourceManifestMaterializer $sources,
        private NonprofitTaxSnapshotManifestRepository $snapshotManifests,
        private NonprofitTaxSnapshotHasher $snapshotHasher,
        private J6AuthoritativeCalculator $calculator,
        private NonprofitTaxCalculationManifestRepository $calculationManifests,
        private J6CalculationPageHasher $calculationHasher,
        private NonprofitTaxSourceEvidenceManifestRepository $evidenceManifests,
        private J6SourceEvidencePageHasher $evidenceHasher,
        private J6HistoricProviderFactory $historic,
    ) {}

    public function convert(int $supplierId, int $periodId, int $assessmentId, string $expectedStorageVersion,
        string $expectedInputHash, string $reason, int $actor, ?J6LegacyInputTransformer $transformer = null): J6AssessmentCompletion
    {
        if (!in_array($expectedStorageVersion, ['legacy_json_v1', 'legacy_json_v2'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $expectedInputHash) !== 1 || $reason === '' || $actor <= 0) {
            throw new \InvalidArgumentException('Legacy J6 conversion request is invalid.');
        }
        return $this->periods->transaction(function () use ($supplierId, $periodId, $assessmentId, $expectedStorageVersion,
            $expectedInputHash, $reason, $actor, $transformer): J6AssessmentCompletion {
            $statuses = $this->periods->lockPeriods($supplierId);
            if (!isset($statuses[$periodId])) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_period_not_found', 'Účetní období nebylo nalezeno.', 404);
            }
            $locked = $this->legacy->lock($supplierId, $periodId, $assessmentId);
            if ($locked['storage_version'] === 'paged_v2') return $this->completion($this->assessments->find($supplierId, $assessmentId, true));
            if ($locked['storage_version'] !== $expectedStorageVersion || !hash_equals($locked['input_hash'], $expectedInputHash)) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_assessment_changed', 'Historické posouzení se před převodem změnilo.', 409);
            }
            $input = new J6LegacyAssessmentInputProvider(new ChunkedJsonColumnReader($this->db->pdo()),
                $supplierId, $assessmentId, $periodId, $transformer);
            $input->assertSupportedShape();
            if (!hash_equals($locked['input_hash'], $input->storedInputHash())) {
                throw new \UnexpectedValueException('Legacy J6 snapshot hash does not match its assessment row.');
            }
            $snapshot = $this->sources->materialize($input->sourceHeader(), $input->sourcePages(), true);
            $sourceManifest = $this->snapshotManifests->manifest($supplierId, $snapshot->id, true);
            $source = new HistoricJ6SourceProvider($this->snapshotManifests, $this->snapshotHasher,
                new J6ReadContext($supplierId, $periodId, J6ReadMode::REBASED_HISTORIC,
                    $assessmentId, $snapshot->id), $input->fundingProvider($snapshot->id));
            $immutable = new J6ImmutableManifestSink($this->calculationManifests, $this->calculationHasher,
                $this->evidenceManifests, $this->evidenceHasher);
            $calculation = $this->calculator->calculate($source, $input->decisionProvider($snapshot->inputHash),
                new J6LegacyParitySink($immutable, $input));
            $receipt = J6AssessmentGraphReceipt::fromCalculation($snapshot, (int) $sourceManifest['finding_count'], $calculation);
            $this->legacy->promote($locked, $receipt, $reason, $actor);
            $assessment = $this->assessments->find($supplierId, $assessmentId, true);
            $providers = $this->historic->create($this->assessments->binding($assessment), J6ReadMode::REBASED_HISTORIC);
            $this->calculator->calculate($providers->sources, $providers->decisions,
                new J6VerificationSink($this->calculationHasher, $this->evidenceHasher, $receipt));
            return $this->completion($assessment);
        });
    }

    private function completion(array $assessment): J6AssessmentCompletion
    {
        if (!$assessment['graph_receipt'] instanceof J6AssessmentGraphReceipt) {
            throw new \UnexpectedValueException('Converted J6 assessment receipt is missing.');
        }
        return new J6AssessmentCompletion($assessment['id'], $assessment['supplier_id'], $assessment['period_id'],
            $assessment['revision_no'], $assessment['status'], $assessment['request_hash'], $assessment['graph_receipt']);
    }
}
