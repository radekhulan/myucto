<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Repository\NonprofitTaxCalculationManifestRepository;
use MyInvoice\Repository\NonprofitTaxSnapshotManifestRepository;
use MyInvoice\Repository\NonprofitTaxSourceEvidenceManifestRepository;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;

final readonly class J6HistoricProviderFactory
{
    public function __construct(
        private NonprofitTaxSnapshotManifestRepository $sources,
        private NonprofitTaxSnapshotHasher $sourceHasher,
        private NonprofitTaxCalculationManifestRepository $calculations,
        private J6CalculationPageHasher $calculationHasher,
        private NonprofitTaxSourceEvidenceManifestRepository $evidence,
        private J6SourceEvidencePageHasher $evidenceHasher,
    ) {}

    public function create(J6HistoricAssessmentBinding $binding, J6ReadMode $mode = J6ReadMode::HISTORIC): J6HistoricProviderSet
    {
        if ($mode === J6ReadMode::CURRENT) throw new \InvalidArgumentException('Historic J6 provider factory requires frozen mode.');
        $sourceContext = new J6ReadContext($binding->supplierId, $binding->periodId, $mode,
            $binding->assessmentId, $binding->sourceManifestId);
        $decisionContext = new J6DecisionContext($binding->supplierId, $binding->periodId, $mode, $binding->snapshotInputHash,
            assessmentId: $binding->assessmentId, decisionManifestId: $binding->decisionManifestId);
        $funding = new HistoricJ6FundingEvidenceProvider($this->evidence, $this->evidenceHasher, $binding);
        return new J6HistoricProviderSet(
            new HistoricJ6SourceProvider($this->sources, $this->sourceHasher, $sourceContext, $funding),
            new HistoricJ6DecisionProvider($this->calculations, $this->calculationHasher, $decisionContext),
            new HistoricJ6CalculationProvider($this->calculations, $this->calculationHasher, $binding),
        );
    }
}
