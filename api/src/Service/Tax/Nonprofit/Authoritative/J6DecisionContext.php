<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

final readonly class J6DecisionContext
{
    public function __construct(
        public int $supplierId,
        public int $periodId,
        public J6ReadMode $mode,
        public string $sourceInputHash,
        public ?int $draftId = null,
        public ?int $draftRevision = null,
        public ?int $actorId = null,
        public ?int $assessmentId = null,
        public ?int $decisionManifestId = null,
    ) {
        $draft = $mode === J6ReadMode::CURRENT;
        $conversion = $mode === J6ReadMode::LEGACY_CONVERSION;
        if ($supplierId <= 0 || $periodId <= 0 || preg_match('/^[a-f0-9]{64}$/D', $sourceInputHash) !== 1
            || ($draft && ($draftId === null || $draftId <= 0 || $draftRevision === null || $draftRevision <= 0 || $actorId === null || $actorId <= 0))
            || ($draft && ($assessmentId !== null || $decisionManifestId !== null))
            || ($conversion && ($assessmentId === null || $assessmentId <= 0 || $decisionManifestId !== null))
            || (!$draft && !$conversion && ($assessmentId === null || $assessmentId <= 0 || $decisionManifestId === null || $decisionManifestId <= 0))
            || (!$draft && ($draftId !== null || $draftRevision !== null || $actorId !== null))) {
            throw new \InvalidArgumentException('J6 decision context is invalid.');
        }
    }
}
