<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

final readonly class J6HistoricAssessmentBinding
{
    public function __construct(
        public int $supplierId,
        public int $periodId,
        public int $assessmentId,
        public int $sourceManifestId,
        public int $fundingReferenceManifestId,
        public int $fundingHistoryManifestId,
        public int $decisionManifestId,
        public int $resultManifestId,
        public int $coverageManifestId,
        public int $usageManifestId,
        public string $snapshotInputHash,
        public string $sourceInputHash,
        public string $calculationInputHash,
        public array $rootHashes,
        public array $evidenceRootHashes,
    ) {
        foreach ([$supplierId, $periodId, $assessmentId, $sourceManifestId, $fundingReferenceManifestId,
            $fundingHistoryManifestId, $decisionManifestId, $resultManifestId,
            $coverageManifestId, $usageManifestId] as $id) if ($id <= 0) throw new \InvalidArgumentException('Historic J6 binding identity is invalid.');
        foreach ([$snapshotInputHash, $sourceInputHash, $calculationInputHash] as $hash) if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new \InvalidArgumentException('Historic J6 binding input hash is invalid.');
        }
        foreach (['decision', 'result', 'coverage', 'usage'] as $kind) {
            if (preg_match('/^[a-f0-9]{64}$/D', (string) ($rootHashes[$kind] ?? '')) !== 1) {
                throw new \InvalidArgumentException('Historic J6 binding root hash is invalid.');
            }
        }
        foreach (['funding_reference', 'funding_history'] as $kind) {
            if (preg_match('/^[a-f0-9]{64}$/D', (string) ($evidenceRootHashes[$kind] ?? '')) !== 1) {
                throw new \InvalidArgumentException('Historic J6 binding source evidence root hash is invalid.');
            }
        }
    }

    public function manifestIds(): array
    {
        return ['decision' => $this->decisionManifestId, 'result' => $this->resultManifestId,
            'coverage' => $this->coverageManifestId, 'usage' => $this->usageManifestId];
    }

    public function evidenceManifestIds(): array
    {
        return ['funding_reference' => $this->fundingReferenceManifestId,
            'funding_history' => $this->fundingHistoryManifestId];
    }
}
