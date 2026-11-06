<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

final readonly class J6CalculationReceipt
{
    public function __construct(
        public J6CalculationHeader $header,
        public array $manifestIds,
        public array $rootHashes,
        public array $counts,
        public array $summary,
        public ?string $previewToken = null,
        public array $evidenceManifestIds = [],
        public array $evidenceRootHashes = [],
        public array $evidenceCounts = [],
    ) {
        foreach (['decision', 'result', 'coverage', 'usage'] as $kind) {
            if (!isset($rootHashes[$kind]) || preg_match('/^[a-f0-9]{64}$/D', (string) $rootHashes[$kind]) !== 1
                || !isset($counts[$kind]) || !is_int($counts[$kind]) || $counts[$kind] < 0) {
                throw new \InvalidArgumentException('J6 calculation receipt is incomplete.');
            }
            if (isset($manifestIds[$kind]) && (!is_int($manifestIds[$kind]) || $manifestIds[$kind] <= 0)) {
                throw new \InvalidArgumentException('J6 calculation receipt manifest is invalid.');
            }
        }
        if ($previewToken !== null && preg_match('/^[a-f0-9]{64}$/D', $previewToken) !== 1) {
            throw new \InvalidArgumentException('J6 preview token is invalid.');
        }
        foreach (['funding_reference', 'funding_history'] as $kind) {
            if (!isset($evidenceRootHashes[$kind]) || preg_match('/^[a-f0-9]{64}$/D', (string) $evidenceRootHashes[$kind]) !== 1
                || !isset($evidenceCounts[$kind]) || !is_int($evidenceCounts[$kind]) || $evidenceCounts[$kind] < 0) {
                throw new \InvalidArgumentException('J6 source evidence receipt is incomplete.');
            }
            if (isset($evidenceManifestIds[$kind]) && (!is_int($evidenceManifestIds[$kind]) || $evidenceManifestIds[$kind] <= 0)) {
                throw new \InvalidArgumentException('J6 source evidence manifest is invalid.');
            }
        }
    }
}
