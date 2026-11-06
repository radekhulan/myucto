<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

final readonly class J6DraftContext
{
    public function __construct(
        public int $supplierId,
        public int $periodId,
        public int $draftId,
        public int $revision,
        public int $actor,
        public string $sourceInputHash,
    ) {
        if ($supplierId <= 0 || $periodId <= 0 || $draftId <= 0 || $revision <= 0 || $actor <= 0
            || preg_match('/^[a-f0-9]{64}$/D', $sourceInputHash) !== 1) {
            throw new \InvalidArgumentException('J6 draft context is invalid.');
        }
    }
}
