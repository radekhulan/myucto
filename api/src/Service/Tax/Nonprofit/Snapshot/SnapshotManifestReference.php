<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Snapshot;

final readonly class SnapshotManifestReference
{
    public function __construct(
        public int $id,
        public int $supplierId,
        public int $periodId,
        public string $inputHash,
        public string $rootHash,
        public int $rowCount,
        public int $chunkCount,
    ) {
        if ($id <= 0 || $supplierId <= 0 || $periodId <= 0 || preg_match('/^[a-f0-9]{64}$/D', $inputHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $rootHash) !== 1 || $rowCount < 0 || $chunkCount < 0) {
            throw new \InvalidArgumentException('Snapshot manifest reference is invalid.');
        }
    }
}
