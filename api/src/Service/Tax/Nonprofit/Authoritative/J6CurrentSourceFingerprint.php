<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotHeaderV2;

final readonly class J6CurrentSourceFingerprint
{
    public function __construct(
        public SnapshotHeaderV2 $header,
        public string $inputHash,
        public string $rootHash,
        public int $rowCount,
        public int $findingCount,
        public int $chunkCount,
    ) {
        foreach ([$inputHash, $rootHash] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) throw new \InvalidArgumentException('J6 source fingerprint hash is invalid.');
        }
        if ($rowCount < 0 || $findingCount < 0 || $chunkCount < 0) throw new \InvalidArgumentException('J6 source fingerprint totals are invalid.');
    }

    public function toArray(): array
    {
        return ['header' => $this->header->data, 'input_hash' => $this->inputHash, 'root_hash' => $this->rootHash,
            'row_count' => $this->rowCount, 'finding_count' => $this->findingCount, 'chunk_count' => $this->chunkCount];
    }
}
