<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Repository\NonprofitTaxCalculationManifestRepository;
use MyInvoice\Repository\NonprofitTaxDraftRepository;
use MyInvoice\Repository\NonprofitTaxPagedAssessmentRepository;
use MyInvoice\Repository\NonprofitTaxSnapshotManifestRepository;
use MyInvoice\Repository\NonprofitTaxSourceEvidenceManifestRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Nonprofit\Funding\FundingEligibilityGuard;
use MyInvoice\Service\Tax\Nonprofit\J6AuthoritativeCalculator;
use MyInvoice\Service\Tax\Nonprofit\J6FundingCapacity;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotMaterializer;

final readonly class J6AssessmentConfirmationService
{
    public function __construct(
        private DimensionPeriodGuard $periods,
        private NonprofitTaxDraftRepository $drafts,
        private NonprofitTaxPagedAssessmentRepository $assessments,
        private J6CurrentProviderFactory $currentSources,
        private NonprofitTaxSnapshotMaterializer $materializer,
        private NonprofitTaxSnapshotManifestRepository $snapshots,
        private NonprofitTaxSnapshotHasher $snapshotHasher,
        private J6CurrentFundingEvidenceProvider $funding,
        private J6AuthoritativeCalculator $calculator,
        private NonprofitTaxCalculationManifestRepository $calculationManifests,
        private J6CalculationPageHasher $calculationHasher,
        private NonprofitTaxSourceEvidenceManifestRepository $evidenceManifests,
        private J6SourceEvidencePageHasher $evidenceHasher,
        private FundingEligibilityGuard $fundingEligibility,
        private J6FundingDependencyGuard $laterFunding,
        private J6FundingCapacity $fundingCapacity,
    ) {}

    public function confirm(J6DraftContext $context, string $idempotencyKey, string $reason, ?int $expectedAssessmentId): J6AssessmentCompletion
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 80 || $reason === '') {
            throw new \InvalidArgumentException('J6 confirmation request is invalid.');
        }
        $requestHash = NonprofitTaxRules::hash(['domain' => 'J6CONFIRM1', 'supplier_id' => $context->supplierId,
            'period_id' => $context->periodId, 'draft_id' => $context->draftId, 'draft_revision' => $context->revision,
            'source_input_hash' => $context->sourceInputHash, 'actor_id' => $context->actor,
            'idempotency_key' => $idempotencyKey, 'reason' => $reason, 'expected_assessment_id' => $expectedAssessmentId]);
        return $this->periods->transaction(function () use ($context, $idempotencyKey, $reason, $requestHash, $expectedAssessmentId): J6AssessmentCompletion {
            $statuses = $this->periods->lockPeriods($context->supplierId);
            if (!isset($statuses[$context->periodId])) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_period_not_found', 'Účetní období nebylo nalezeno.', 404);
            }
            if (!in_array($statuses[$context->periodId], ['open', 'closing'], true)) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_period_locked', 'V uzamčeném období nelze potvrdit daňové posouzení.', 409);
            }
            $existing = $this->assessments->byIdempotency($context->supplierId, $idempotencyKey, true);
            if ($existing !== null) {
                if (!hash_equals($existing['request_hash'], $requestHash)
                    || !($existing['graph_receipt'] instanceof J6AssessmentGraphReceipt)) {
                    throw new NonprofitTaxAssessmentException('nonprofit_tax_idempotency_conflict', 'Idempotentní klíč už patří jinému potvrzení.', 409);
                }
                return $this->completion($existing);
            }
            $draft = $this->drafts->find($context->supplierId, $context->draftId, true);
            if ($draft['period_id'] !== $context->periodId || $draft['created_by'] !== $context->actor) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_draft_not_found', 'Koncept daňového posouzení nebyl nalezen.', 404);
            }
            if ($draft['revision_no'] !== $context->revision
                || !hash_equals($draft['source_input_hash'], $context->sourceInputHash)) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_draft_changed', 'Koncept posouzení nebo jeho účetní podklad se změnil.', 409);
            }
            $previous = $this->assessments->latest($context->supplierId, $context->periodId, true);
            $currentConfirmedId = ($previous['status'] ?? null) === 'confirmed' ? $previous['id'] : null;
            if ($currentConfirmedId !== $expectedAssessmentId) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_assessment_changed', 'Mezitím vzniklo jiné potvrzené posouzení.', 409);
            }
            $fresh = $this->currentSources->fingerprint($context->supplierId, $context->periodId, true);
            if (!hash_equals($fresh->inputHash, $context->sourceInputHash)) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_draft_changed', 'Účetní podklad konceptu se změnil.', 409);
            }
            $snapshot = $this->materializer->materializeCurrent($context->supplierId, $context->periodId);
            if (!hash_equals($snapshot->inputHash, $context->sourceInputHash)) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_draft_changed', 'Účetní podklad se změnil během potvrzení.', 409);
            }
            $source = new HistoricJ6SourceProvider($this->snapshots, $this->snapshotHasher,
                new J6ReadContext($context->supplierId, $context->periodId, J6ReadMode::FROZEN_CURRENT,
                    sourceManifestId: $snapshot->id, currentRead: true), $this->funding);
            $sink = new J6ImmutableManifestSink($this->calculationManifests, $this->calculationHasher,
                $this->evidenceManifests, $this->evidenceHasher);
            $calculation = $this->calculator->calculate($source, new DraftJ6DecisionProvider($this->drafts, $context), $sink);
            $snapshotManifest = $this->snapshots->manifest($context->supplierId, $snapshot->id, true);
            $receipt = J6AssessmentGraphReceipt::fromCalculation($snapshot, $snapshotManifest['finding_count'], $calculation);
            $assessment = $this->assessments->appendConfirmed($context->supplierId, $context->periodId, $previous,
                $reason, $idempotencyKey, $requestHash, $draft['header'], $receipt, $context->actor);
            // Stejné brány jako legacy potvrzení: krytí nákladů napříč zdroji, politiky zdrojů
            // a navazující čerpání pozdějších období se ověří nad právě uloženým grafem;
            // selhání odvolá celou transakci.
            $this->fundingCapacity->assertRevisionCoverage($context->supplierId, $assessment);
            $this->fundingEligibility->assertRevision($context->supplierId, $assessment, true);
            $this->laterFunding->assertRevisionsUnused($context->supplierId, $context->periodId,
                array_values(array_filter([$previous, $assessment])));
            return $this->completion($assessment);
        });
    }

    private function completion(array $assessment): J6AssessmentCompletion
    {
        return new J6AssessmentCompletion($assessment['id'], $assessment['supplier_id'], $assessment['period_id'],
            $assessment['revision_no'], $assessment['status'], $assessment['request_hash'], $assessment['graph_receipt']);
    }
}


