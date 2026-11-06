<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

final readonly class J6CalculationHeader
{
    public function __construct(
        public int $supplierId,
        public int $periodId,
        public string $ruleset,
        public string $sourceInputHash,
        public string $decisionInputHash,
        public string $calculationInputHash,
        public ?int $sourceManifestId = null,
        public ?int $decisionManifestId = null,
        public array $decisionHeader = [],
        public ?string $snapshotInputHash = null,
    ) {
        if ($supplierId <= 0 || $periodId <= 0 || $ruleset === '') throw new \InvalidArgumentException('J6 calculation header is invalid.');
        foreach ([$sourceInputHash, $decisionInputHash, $calculationInputHash] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) throw new \InvalidArgumentException('J6 calculation input hash is invalid.');
        }
        if ($snapshotInputHash !== null && preg_match('/^[a-f0-9]{64}$/D', $snapshotInputHash) !== 1) {
            throw new \InvalidArgumentException('J6 snapshot input hash is invalid.');
        }
        if (($sourceManifestId !== null && $sourceManifestId <= 0) || ($decisionManifestId !== null && $decisionManifestId <= 0)) {
            throw new \InvalidArgumentException('J6 calculation manifest identity is invalid.');
        }
        foreach (['activities', 'decisions', 'funding', 'funding_sources', 'funding_costs'] as $unbounded) {
            if (array_key_exists($unbounded, $decisionHeader)) throw new \InvalidArgumentException('J6 calculation decision header contains paged records.');
        }
    }

    public function toArray(): array
    {
        return [
            'supplier_id' => $this->supplierId,
            'period_id' => $this->periodId,
            'ruleset' => $this->ruleset,
            'snapshot_input_hash' => $this->snapshotInputHash ?? $this->sourceInputHash,
            'source_input_hash' => $this->sourceInputHash,
            'decision_input_hash' => $this->decisionInputHash,
            'calculation_input_hash' => $this->calculationInputHash,
            'source_manifest_id' => $this->sourceManifestId,
            'decision_manifest_id' => $this->decisionManifestId,
            'decision_header' => $this->decisionHeader,
        ];
    }
}
