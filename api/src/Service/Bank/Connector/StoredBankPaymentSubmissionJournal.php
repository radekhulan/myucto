<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Repository\BankPaymentOrderSubmissionRepository;

/** Evidence položek v tabulce bank_payment_submission_items pro jedno odeslání. */
final class StoredBankPaymentSubmissionJournal implements BankPaymentSubmissionJournal
{
    public function __construct(
        private readonly BankPaymentOrderSubmissionRepository $submissions,
        private readonly int $supplierId,
        private readonly int $submissionId,
    ) {}

    public function plan(array $items): array
    {
        try {
            return $this->submissions->planItems($this->supplierId, $this->submissionId, $items);
        } catch (\Throwable $e) {
            throw self::ambiguous($e instanceof \DomainException ? $e->getMessage() : 'payment_journal_unavailable');
        }
    }

    public function sending(int $lineNo): void
    {
        $this->move($lineNo, 'sending');
    }

    public function created(int $lineNo, string $signId, string $hash, string $instructionStatus): void
    {
        $this->move($lineNo, 'created', $signId, $hash, $instructionStatus);
    }

    public function rejected(int $lineNo, string $errorCode): void
    {
        $this->move($lineNo, 'rejected', errorCode: $errorCode);
    }

    public function unknown(int $lineNo, string $errorCode): void
    {
        $this->move($lineNo, 'unknown', errorCode: $errorCode);
    }

    public function batchSending(): void
    {
        try {
            $moved = $this->submissions->markBatchSending($this->supplierId, $this->submissionId);
        } catch (\Throwable) {
            throw self::ambiguous('payment_journal_unavailable');
        }
        if (!$moved) {
            throw self::ambiguous('csas_batch_outcome_unknown');
        }
    }

    private function move(int $lineNo, string $to, ?string $signId = null, ?string $hash = null, ?string $instructionStatus = null,
        ?string $errorCode = null): void
    {
        try {
            $moved = $this->submissions->moveItem($this->supplierId, $this->submissionId, $lineNo, $to, $signId, $hash,
                $instructionStatus, $errorCode);
        } catch (\Throwable) {
            throw self::ambiguous('payment_journal_unavailable');
        }
        if (!$moved) {
            throw self::ambiguous('payment_journal_conflict');
        }
    }

    private static function ambiguous(string $code): BankConnectorException
    {
        return new BankConnectorException($code, 'Evidenci odeslání nelze bezpečně zapsat.', true);
    }
}
