<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

final readonly class J6ReadContext
{
    public function __construct(
        public int $supplierId,
        public int $periodId,
        public J6ReadMode $mode,
        public ?int $assessmentId = null,
        public ?int $sourceManifestId = null,
        public bool $currentRead = false,
    ) {
        if ($supplierId <= 0 || $periodId <= 0 || ($assessmentId !== null && $assessmentId <= 0)
            || ($sourceManifestId !== null && $sourceManifestId <= 0)
            || $mode === J6ReadMode::LEGACY_CONVERSION
            || ($mode === J6ReadMode::CURRENT && ($sourceManifestId !== null || $assessmentId !== null))
            || ($mode === J6ReadMode::FROZEN_CURRENT && ($sourceManifestId === null || $assessmentId !== null || !$currentRead))
            || (in_array($mode, [J6ReadMode::HISTORIC, J6ReadMode::REBASED_HISTORIC], true)
                && ($assessmentId === null || $sourceManifestId === null || $currentRead))) {
            throw new \InvalidArgumentException('J6 read context is invalid.');
        }
    }
}
