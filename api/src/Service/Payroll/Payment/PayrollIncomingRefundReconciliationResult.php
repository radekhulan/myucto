<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

final readonly class PayrollIncomingRefundReconciliationResult
{
    public function __construct(
        public int $id,
        public int $liabilityId,
        public ?int $allocationId,
        public string $eventKind,
        public ?int $sourceMatchId,
        public int $amountMinor,
        public string $evidenceKind,
        public ?int $bankStatementId,
        public ?int $bankTransactionId,
        public ?int $cashDocumentId,
        public string $actualPaymentDate,
        public int $evidenceAmountMinor,
        public string $evidenceCurrencyCode,
        public string $evidenceFactHash,
        public bool $replayed,
    ) {}
}
