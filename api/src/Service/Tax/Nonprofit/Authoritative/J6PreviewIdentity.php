<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

final readonly class J6PreviewIdentity
{
    public function __construct(
        public int $actorId,
        public int $supplierId,
        public int $periodId,
        public int $draftId,
        public int $draftRevision,
        public string $inputHash,
    ) {
        if ($actorId <= 0 || $supplierId <= 0 || $periodId <= 0 || $draftId <= 0 || $draftRevision <= 0
            || preg_match('/^[a-f0-9]{64}$/D', $inputHash) !== 1) {
            throw new \InvalidArgumentException('J6 preview identity is invalid.');
        }
    }

    public function toArray(): array
    {
        return ['actor_id' => $this->actorId, 'supplier_id' => $this->supplierId, 'period_id' => $this->periodId,
            'draft_id' => $this->draftId, 'draft_revision' => $this->draftRevision, 'input_hash' => $this->inputHash];
    }
}
