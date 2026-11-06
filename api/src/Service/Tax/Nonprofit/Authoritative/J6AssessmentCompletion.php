<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

final readonly class J6AssessmentCompletion
{
    public function __construct(
        public int $assessmentId,
        public int $supplierId,
        public int $periodId,
        public int $revision,
        public string $status,
        public string $requestHash,
        public J6AssessmentGraphReceipt $receipt,
    ) {
        if ($assessmentId <= 0 || $supplierId <= 0 || $periodId <= 0 || $revision <= 0
            || !in_array($status, ['confirmed', 'withdrawn'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $requestHash) !== 1) {
            throw new \InvalidArgumentException('J6 assessment completion is invalid.');
        }
    }

    public function toArray(): array
    {
        return ['assessment_id' => $this->assessmentId, 'supplier_id' => $this->supplierId,
            'period_id' => $this->periodId, 'revision' => $this->revision, 'status' => $this->status,
            'request_hash' => $this->requestHash, 'receipt' => $this->receipt->toArray()];
    }
}
