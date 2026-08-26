<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use DomainException;
use MyInvoice\Repository\Payroll\PayrollPaymentMatchRepository;

final class PayrollPaymentReconciliationService
{
    public function __construct(
        private readonly PayrollPaymentMatchRepository $matches,
    ) {}

    public function match(
        PayrollPaymentReconciliationCommand $command,
    ): PayrollPaymentReconciliationResult {
        $this->assertCommand(
            $command->supplierId,
            $command->amountMinor,
            $command->idempotencyKey,
            $command->matchedBy,
        );
        if ($command->allocationId <= 0) {
            throw new \InvalidArgumentException(
                'Platební alokace musí být kladné číslo.',
            );
        }
        $this->assertEvidenceReference($command->evidence);
        $idempotencyHash = hash(
            'sha256',
            $command->idempotencyKey,
            true,
        );

        return $this->matches->transaction(function () use (
            $command,
            $idempotencyHash,
        ): PayrollPaymentReconciliationResult {
            $existing = $this->matches->findByIdempotency(
                $command->supplierId,
                $idempotencyHash,
            );
            if ($existing !== null) {
                return $this->replay(
                    $existing,
                    $command->allocationId,
                    'matched',
                    null,
                    $command->amountMinor,
                    $command->evidence,
                );
            }

            $allocation = $this->matches->lockAllocation(
                $command->supplierId,
                $command->allocationId,
            );
            if ($allocation === null) {
                throw new DomainException(
                    'Platební alokace nebyla nalezena v aktuální firmě.',
                );
            }
            $existing = $this->matches->findByIdempotency(
                $command->supplierId,
                $idempotencyHash,
            );
            if ($existing !== null) {
                return $this->replay(
                    $existing,
                    $command->allocationId,
                    'matched',
                    null,
                    $command->amountMinor,
                    $command->evidence,
                );
            }
            $this->assertChannel(
                $allocation['channel'],
                $command->evidence,
            );
            $evidenceAmount = $this->assertEvidence(
                $command->supplierId,
                $allocation['direction'],
                $allocation['currency_code'],
                'matched',
                $command->evidence,
                null,
            );
            $this->assertCapacity(
                $command->supplierId,
                $allocation,
                'matched',
                $command->amountMinor,
                $command->evidence,
                $evidenceAmount,
            );

            $matchId = $this->matches->insert(
                $command->supplierId,
                $command->allocationId,
                $allocation['liability_id'],
                'matched',
                null,
                $command->amountMinor,
                $command->evidence->bankStatementId,
                $command->evidence->bankTransactionId,
                $command->evidence->cashDocumentId,
                $idempotencyHash,
                $command->matchedBy,
            );
            $stored = $this->matches->lockMatch(
                $command->supplierId,
                $matchId,
            );
            if ($stored === null) {
                throw new \RuntimeException(
                    'Uložené spárování platby nelze načíst.',
                );
            }

            return $this->result($stored, false);
        });
    }

    public function matchIncomingRefund(
        PayrollIncomingRefundReconciliationCommand $command,
    ): PayrollIncomingRefundReconciliationResult {
        $this->assertCommand(
            $command->supplierId,
            $command->amountMinor,
            $command->idempotencyKey,
            $command->matchedBy,
        );
        if ($command->liabilityId <= 0) {
            throw new \InvalidArgumentException(
                'Příchozí mzdový závazek musí být kladné číslo.',
            );
        }
        $this->assertEvidenceReference($command->evidence);
        $idempotencyHash = hash('sha256', $command->idempotencyKey, true);

        return $this->matches->transaction(function () use (
            $command,
            $idempotencyHash,
        ): PayrollIncomingRefundReconciliationResult {
            $existing = $this->matches->findByIdempotency(
                $command->supplierId,
                $idempotencyHash,
            );
            if ($existing !== null) {
                return $this->replayIncomingRefund(
                    $existing,
                    $command->liabilityId,
                    'matched',
                    null,
                    $command->amountMinor,
                    $command->evidence,
                );
            }

            $liability = $this->matches->lockIncomingLiability(
                $command->supplierId,
                $command->liabilityId,
            );
            if ($liability === null) {
                throw new DomainException(
                    'Příchozí mzdový závazek nebyl nalezen v aktuální firmě.',
                );
            }
            if ($liability['direction'] !== 'incoming') {
                throw new DomainException(
                    'Bez platební dávky lze doložit pouze skutečně přijatou mzdovou vratku.',
                );
            }
            $existing = $this->matches->findByIdempotency(
                $command->supplierId,
                $idempotencyHash,
            );
            if ($existing !== null) {
                return $this->replayIncomingRefund(
                    $existing,
                    $command->liabilityId,
                    'matched',
                    null,
                    $command->amountMinor,
                    $command->evidence,
                );
            }

            $evidenceAmount = $this->assertEvidence(
                $command->supplierId,
                $liability['direction'],
                $liability['currency_code'],
                'matched',
                $command->evidence,
                null,
            );
            $this->assertIncomingRefundCapacity(
                $command->supplierId,
                $liability,
                'matched',
                $command->amountMinor,
                $command->evidence,
                $evidenceAmount,
            );

            $matchId = $this->matches->insert(
                $command->supplierId,
                null,
                $command->liabilityId,
                'matched',
                null,
                $command->amountMinor,
                $command->evidence->bankStatementId,
                $command->evidence->bankTransactionId,
                $command->evidence->cashDocumentId,
                $idempotencyHash,
                $command->matchedBy,
            );
            $stored = $this->matches->lockMatch(
                $command->supplierId,
                $matchId,
            );
            if ($stored === null) {
                throw new \RuntimeException(
                    'Uloženou přijatou mzdovou vratku nelze načíst.',
                );
            }

            return $this->incomingRefundResult($stored, false);
        });
    }

    public function reverseIncomingRefund(
        PayrollPaymentReversalCommand $command,
    ): PayrollIncomingRefundReconciliationResult {
        $this->assertCommand(
            $command->supplierId,
            $command->amountMinor,
            $command->idempotencyKey,
            $command->matchedBy,
        );
        if ($command->sourceMatchId <= 0) {
            throw new \InvalidArgumentException(
                'Zdrojová přijatá vratka musí být kladné číslo.',
            );
        }
        $this->assertEvidenceReference($command->evidence);
        $idempotencyHash = hash('sha256', $command->idempotencyKey, true);

        return $this->matches->transaction(function () use (
            $command,
            $idempotencyHash,
        ): PayrollIncomingRefundReconciliationResult {
            $existing = $this->matches->findByIdempotency(
                $command->supplierId,
                $idempotencyHash,
            );
            if ($existing !== null) {
                return $this->replayIncomingRefund(
                    $existing,
                    $existing['liability_id'],
                    'reversed',
                    $command->sourceMatchId,
                    -$command->amountMinor,
                    $command->evidence,
                );
            }

            $source = $this->matches->lockMatch(
                $command->supplierId,
                $command->sourceMatchId,
            );
            if ($source === null
                || $source['event_kind'] !== 'matched'
                || $source['allocation_id'] !== null
            ) {
                throw new DomainException(
                    'Zdrojová přijatá vratka nebyla nalezena nebo není přímý příjem.',
                );
            }
            $liability = $this->matches->lockIncomingLiability(
                $command->supplierId,
                $source['liability_id'],
            );
            if ($liability === null || $liability['direction'] !== 'incoming') {
                throw new DomainException(
                    'Příchozí mzdový závazek zdrojové vratky nebyl nalezen.',
                );
            }
            $existing = $this->matches->findByIdempotency(
                $command->supplierId,
                $idempotencyHash,
            );
            if ($existing !== null) {
                return $this->replayIncomingRefund(
                    $existing,
                    $source['liability_id'],
                    'reversed',
                    $command->sourceMatchId,
                    -$command->amountMinor,
                    $command->evidence,
                );
            }
            $sourceEvidenceKind = $source['bank_transaction_id'] === null
                ? 'cash'
                : 'bank';
            $this->assertChannel($sourceEvidenceKind, $command->evidence);
            if ($command->evidence->kind === 'cash'
                && $source['cash_document_id']
                    !== $command->evidence->cashDocumentId
            ) {
                throw new DomainException(
                    'Reverze hotovosti musí použít původní pokladní doklad.',
                );
            }
            $remaining = $source['amount_minor'] + $source['reversed_minor'];
            if ($remaining <= 0 || $command->amountMinor > $remaining) {
                throw new DomainException(
                    'Reverze přesahuje dosud nereverzovanou část přijaté vratky.',
                );
            }

            $evidenceAmount = $this->assertEvidence(
                $command->supplierId,
                $liability['direction'],
                $liability['currency_code'],
                'reversed',
                $command->evidence,
                $source,
            );
            $this->assertIncomingRefundCapacity(
                $command->supplierId,
                $liability,
                'reversed',
                $command->amountMinor,
                $command->evidence,
                $evidenceAmount,
            );
            if ($liability['settled_minor'] < $command->amountMinor) {
                throw new DomainException(
                    'Reverze by snížila přijaté vratky závazku pod nulu.',
                );
            }

            $matchId = $this->matches->insert(
                $command->supplierId,
                null,
                $source['liability_id'],
                'reversed',
                $command->sourceMatchId,
                -$command->amountMinor,
                $command->evidence->bankStatementId,
                $command->evidence->bankTransactionId,
                $command->evidence->cashDocumentId,
                $idempotencyHash,
                $command->matchedBy,
            );
            $stored = $this->matches->lockMatch(
                $command->supplierId,
                $matchId,
            );
            if ($stored === null) {
                throw new \RuntimeException(
                    'Uloženou reverzi přijaté mzdové vratky nelze načíst.',
                );
            }

            return $this->incomingRefundResult($stored, false);
        });
    }

    public function reverse(
        PayrollPaymentReversalCommand $command,
    ): PayrollPaymentReconciliationResult {
        $this->assertCommand(
            $command->supplierId,
            $command->amountMinor,
            $command->idempotencyKey,
            $command->matchedBy,
        );
        if ($command->sourceMatchId <= 0) {
            throw new \InvalidArgumentException(
                'Zdrojové spárování musí být kladné číslo.',
            );
        }
        $this->assertEvidenceReference($command->evidence);
        $idempotencyHash = hash(
            'sha256',
            $command->idempotencyKey,
            true,
        );

        return $this->matches->transaction(function () use (
            $command,
            $idempotencyHash,
        ): PayrollPaymentReconciliationResult {
            $existing = $this->matches->findByIdempotency(
                $command->supplierId,
                $idempotencyHash,
            );
            if ($existing !== null) {
                if ($existing['allocation_id'] === null) {
                    throw new DomainException(
                        'Idempotentní klíč už používá přijatá mzdová vratka.',
                    );
                }
                return $this->replay(
                    $existing,
                    $existing['allocation_id'],
                    'reversed',
                    $command->sourceMatchId,
                    -$command->amountMinor,
                    $command->evidence,
                );
            }

            $source = $this->matches->lockMatch(
                $command->supplierId,
                $command->sourceMatchId,
            );
            if ($source === null || $source['event_kind'] !== 'matched') {
                throw new DomainException(
                    'Zdrojové spárování nebylo nalezeno nebo není platba.',
                );
            }
            if ($source['allocation_id'] === null) {
                throw new DomainException(
                    'Přijatou mzdovou vratku lze reverzovat pouze vratkovou cestou.',
                );
            }
            $allocation = $this->matches->lockAllocation(
                $command->supplierId,
                $source['allocation_id'],
            );
            if ($allocation === null) {
                throw new DomainException(
                    'Platební alokace zdrojové platby nebyla nalezena.',
                );
            }
            $existing = $this->matches->findByIdempotency(
                $command->supplierId,
                $idempotencyHash,
            );
            if ($existing !== null) {
                if ($existing['allocation_id'] === null) {
                    throw new DomainException(
                        'Idempotentní klíč už používá přijatá mzdová vratka.',
                    );
                }
                return $this->replay(
                    $existing,
                    $source['allocation_id'],
                    'reversed',
                    $command->sourceMatchId,
                    -$command->amountMinor,
                    $command->evidence,
                );
            }
            $this->assertChannel(
                $allocation['channel'],
                $command->evidence,
            );
            if ($command->evidence->kind === 'cash'
                && $source['cash_document_id']
                    !== $command->evidence->cashDocumentId
            ) {
                throw new DomainException(
                    'Reverze hotovosti musí použít původní pokladní doklad.',
                );
            }
            $remaining = $source['amount_minor']
                + $source['reversed_minor'];
            if ($remaining <= 0 || $command->amountMinor > $remaining) {
                throw new DomainException(
                    'Reverze přesahuje dosud nereverzovanou část platby.',
                );
            }

            $evidenceAmount = $this->assertEvidence(
                $command->supplierId,
                $allocation['direction'],
                $allocation['currency_code'],
                'reversed',
                $command->evidence,
                $source,
            );
            $this->assertCapacity(
                $command->supplierId,
                $allocation,
                'reversed',
                $command->amountMinor,
                $command->evidence,
                $evidenceAmount,
            );
            if ($allocation['settled_minor'] < $command->amountMinor) {
                throw new DomainException(
                    'Reverze by snížila úhradu alokace pod nulu.',
                );
            }

            $matchId = $this->matches->insert(
                $command->supplierId,
                $source['allocation_id'],
                $source['liability_id'],
                'reversed',
                $command->sourceMatchId,
                -$command->amountMinor,
                $command->evidence->bankStatementId,
                $command->evidence->bankTransactionId,
                $command->evidence->cashDocumentId,
                $idempotencyHash,
                $command->matchedBy,
            );
            $stored = $this->matches->lockMatch(
                $command->supplierId,
                $matchId,
            );
            if ($stored === null) {
                throw new \RuntimeException(
                    'Uloženou reverzi platby nelze načíst.',
                );
            }

            return $this->result($stored, false);
        });
    }

    /**
     * @param array{
     *   id:int,
     *   liability_id:int,
     *   channel:string,
     *   amount_minor:int,
     *   direction:string,
     *   currency_code:string,
     *   settled_minor:int
     * } $allocation
     */
    private function assertCapacity(
        int $supplierId,
        array $allocation,
        string $eventKind,
        int $amountMinor,
        PayrollPaymentEvidenceReference $evidence,
        int $evidenceAmount,
    ): void {
        if ($eventKind === 'matched'
            && $allocation['settled_minor'] + $amountMinor
                > $allocation['amount_minor']
        ) {
            throw new DomainException(
                'Platba by překročila částku platební alokace.',
            );
        }
        $this->assertEvidenceCapacity(
            $supplierId,
            $eventKind,
            $amountMinor,
            $evidence,
            $evidenceAmount,
        );
    }

    /**
     * @param array{
     *   id:int,
     *   amount_minor:int,
     *   direction:string,
     *   currency_code:string,
     *   settled_minor:int
     * } $liability
     */
    private function assertIncomingRefundCapacity(
        int $supplierId,
        array $liability,
        string $eventKind,
        int $amountMinor,
        PayrollPaymentEvidenceReference $evidence,
        int $evidenceAmount,
    ): void {
        if ($eventKind === 'matched'
            && $liability['settled_minor'] + $amountMinor
                > $liability['amount_minor']
        ) {
            throw new DomainException(
                'Přijatá vratka by překročila příchozí mzdový závazek.',
            );
        }
        $this->assertEvidenceCapacity(
            $supplierId,
            $eventKind,
            $amountMinor,
            $evidence,
            $evidenceAmount,
        );
    }

    private function assertEvidenceCapacity(
        int $supplierId,
        string $eventKind,
        int $amountMinor,
        PayrollPaymentEvidenceReference $evidence,
        int $evidenceAmount,
    ): void {
        $used = $this->matches->evidenceUsedMinor(
            $supplierId,
            $eventKind,
            $evidence->bankStatementId,
            $evidence->bankTransactionId,
            $evidence->cashDocumentId,
        );
        if ($used > $evidenceAmount
            || $amountMinor > $evidenceAmount - $used
        ) {
            throw new DomainException(
                'Částka platebního důkazu už je vyčerpaná.',
            );
        }
    }

    /**
     * @param array{
     *   id:int,
     *   allocation_id:?int,
     *   liability_id:int,
     *   event_kind:string,
     *   source_match_id:?int,
     *   amount_minor:int,
     *   bank_statement_id:?int,
     *   bank_transaction_id:?int,
     *   cash_document_id:?int,
     *   actual_payment_date:string,
     *   evidence_amount_minor:int,
     *   evidence_currency_code:string,
     *   evidence_fact_hash:string,
     *   reversed_minor:int
     * }|null $source
     */
    private function assertEvidence(
        int $supplierId,
        string $liabilityDirection,
        string $liabilityCurrency,
        string $eventKind,
        PayrollPaymentEvidenceReference $reference,
        ?array $source,
    ): int {
        if ($reference->kind === 'bank') {
            $statementId = $reference->bankStatementId;
            $transactionId = $reference->bankTransactionId;
            if ($statementId === null || $transactionId === null) {
                throw new \LogicException(
                    'Bankovní reference není úplná.',
                );
            }
            $evidence = $this->matches->lockBankEvidence(
                $supplierId,
                $statementId,
                $transactionId,
            );
            if ($evidence === null) {
                throw new DomainException(
                    'Bankovní důkaz nebyl nalezen v aktuální firmě.',
                );
            }
            $ownership = $this->matches->bankEvidenceOwnership(
                $supplierId,
                $transactionId,
            );
            if ($ownership['invoice_payments'] > 0
                || $ownership['payment_matches'] > 0
            ) {
                throw new DomainException(
                    'Bankovní pohyb už vlastní fakturační párování.',
                );
            }
            if ($ownership['foreign_payroll'] > 0) {
                throw new DomainException(
                    'Bankovní pohyb už vlastní jiná firma.',
                );
            }
            if ($evidence['matched_invoice_id'] !== null
                || $evidence['match_status'] !== 'unmatched'
            ) {
                throw new DomainException(
                    'Bankovní pohyb už vlastní bankovní reconciliation.',
                );
            }
            $signedMinor = $this->decimalToMinor(
                $evidence['amount_decimal'],
            );
            if ($signedMinor === 0) {
                throw new DomainException(
                    'Bankovní důkaz má nulovou částku.',
                );
            }
            $direction = $signedMinor < 0 ? 'outgoing' : 'incoming';
            $expectedDirection = $eventKind === 'matched'
                ? $liabilityDirection
                : $this->oppositeDirection($liabilityDirection);
            if ($direction !== $expectedDirection) {
                throw new DomainException(
                    'Směr bankovního důkazu neodpovídá platební události.',
                );
            }
            $this->assertCurrency(
                $evidence['currency_code'],
                $liabilityCurrency,
            );

            return $this->absolute($signedMinor);
        }

        $cashDocumentId = $reference->cashDocumentId;
        if ($cashDocumentId === null) {
            throw new \LogicException('Pokladní reference není úplná.');
        }
        $evidence = $this->matches->lockCashEvidence(
            $supplierId,
            $cashDocumentId,
        );
        if ($evidence === null) {
            throw new DomainException(
                'Pokladní důkaz nebyl nalezen v aktuální firmě.',
            );
        }
        if ($evidence['invoice_id'] !== null
            || $evidence['purchase_invoice_id'] !== null
            || $evidence['invoice_payment_id'] !== null
            || $evidence['purpose'] !== 'other'
        ) {
            throw new DomainException(
                'Pokladní doklad už vlastní fakturační párování.',
            );
        }
        $expectedStatus = $eventKind === 'matched'
            ? 'posted'
            : 'reversed';
        if ($evidence['status'] !== $expectedStatus) {
            throw new DomainException(
                'Stav pokladního dokladu neodpovídá platební události.',
            );
        }
        $direction = $evidence['doc_type'] === 'out'
            ? 'outgoing'
            : ($evidence['doc_type'] === 'in' ? 'incoming' : null);
        if ($direction === null || $direction !== $liabilityDirection) {
            throw new DomainException(
                'Směr pokladního dokladu neodpovídá závazku.',
            );
        }
        if ($eventKind === 'reversed'
            && ($source === null
                || $source['cash_document_id'] !== $cashDocumentId)
        ) {
            throw new DomainException(
                'Pokladní reverze nenavazuje na původní platbu.',
            );
        }
        $this->assertCurrency(
            $evidence['currency_code'],
            $liabilityCurrency,
        );
        $amountMinor = $this->decimalToMinor($evidence['amount_decimal']);
        if ($amountMinor <= 0) {
            throw new DomainException(
                'Pokladní důkaz nemá kladnou částku.',
            );
        }

        return $amountMinor;
    }

    private function assertCurrency(
        string $evidenceCurrency,
        string $liabilityCurrency,
    ): void {
        if (preg_match('/^[A-Z]{3}$/D', $evidenceCurrency) !== 1
            || !hash_equals($liabilityCurrency, $evidenceCurrency)
        ) {
            throw new DomainException(
                'Měna platebního důkazu neodpovídá závazku.',
            );
        }
    }

    private function assertChannel(
        string $channel,
        PayrollPaymentEvidenceReference $evidence,
    ): void {
        if (!hash_equals($channel, $evidence->kind)) {
            throw new DomainException(
                'Druh důkazu neodpovídá kanálu platební dávky.',
            );
        }
    }

    private function assertCommand(
        int $supplierId,
        int $amountMinor,
        string $idempotencyKey,
        ?int $matchedBy,
    ): void {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma musí být kladné číslo.',
            );
        }
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException(
                'Částka párování musí být kladná.',
            );
        }
        if ($idempotencyKey === ''
            || strlen($idempotencyKey) > 190
            || preg_match('/^[\x21-\x7e]+$/D', $idempotencyKey) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Idempotentní klíč není platný.',
            );
        }
        if ($matchedBy !== null && $matchedBy <= 0) {
            throw new \InvalidArgumentException(
                'Uživatel párování musí být kladné číslo.',
            );
        }
    }

    private function assertEvidenceReference(
        PayrollPaymentEvidenceReference $reference,
    ): void {
        if ($reference->kind === 'bank'
            && $reference->bankStatementId !== null
            && $reference->bankStatementId > 0
            && $reference->bankTransactionId !== null
            && $reference->bankTransactionId > 0
            && $reference->cashDocumentId === null
        ) {
            return;
        }
        if ($reference->kind === 'cash'
            && $reference->bankStatementId === null
            && $reference->bankTransactionId === null
            && $reference->cashDocumentId !== null
            && $reference->cashDocumentId > 0
        ) {
            return;
        }
        throw new \InvalidArgumentException(
            'Reference platebního důkazu není platná.',
        );
    }

    /**
     * @param array{
     *   id:int,
     *   allocation_id:?int,
     *   liability_id:int,
     *   event_kind:string,
     *   source_match_id:?int,
     *   amount_minor:int,
     *   bank_statement_id:?int,
     *   bank_transaction_id:?int,
     *   cash_document_id:?int,
     *   actual_payment_date:string,
     *   evidence_amount_minor:int,
     *   evidence_currency_code:string,
     *   evidence_fact_hash:string,
     *   reversed_minor:int
     * } $stored
     */
    private function replay(
        array $stored,
        int $allocationId,
        string $eventKind,
        ?int $sourceMatchId,
        int $amountMinor,
        PayrollPaymentEvidenceReference $evidence,
    ): PayrollPaymentReconciliationResult {
        if ($stored['allocation_id'] !== $allocationId
            || $stored['event_kind'] !== $eventKind
            || $stored['source_match_id'] !== $sourceMatchId
            || $stored['amount_minor'] !== $amountMinor
            || $stored['bank_statement_id'] !== $evidence->bankStatementId
            || $stored['bank_transaction_id']
                !== $evidence->bankTransactionId
            || $stored['cash_document_id'] !== $evidence->cashDocumentId
        ) {
            throw new DomainException(
                'Idempotentní replay neodpovídá uložené platební události.',
            );
        }

        return $this->result($stored, true);
    }

    /**
     * @param array{
     *   id:int,
     *   allocation_id:?int,
     *   liability_id:int,
     *   event_kind:string,
     *   source_match_id:?int,
     *   amount_minor:int,
     *   bank_statement_id:?int,
     *   bank_transaction_id:?int,
     *   cash_document_id:?int,
     *   actual_payment_date:string,
     *   evidence_amount_minor:int,
     *   evidence_currency_code:string,
     *   evidence_fact_hash:string,
     *   reversed_minor:int
     * } $stored
     */
    private function replayIncomingRefund(
        array $stored,
        int $liabilityId,
        string $eventKind,
        ?int $sourceMatchId,
        int $amountMinor,
        PayrollPaymentEvidenceReference $evidence,
    ): PayrollIncomingRefundReconciliationResult {
        if ($stored['allocation_id'] !== null
            || $stored['liability_id'] !== $liabilityId
            || $stored['event_kind'] !== $eventKind
            || $stored['source_match_id'] !== $sourceMatchId
            || $stored['amount_minor'] !== $amountMinor
            || $stored['bank_statement_id'] !== $evidence->bankStatementId
            || $stored['bank_transaction_id']
                !== $evidence->bankTransactionId
            || $stored['cash_document_id'] !== $evidence->cashDocumentId
        ) {
            throw new DomainException(
                'Idempotentní replay neodpovídá uložené přijaté mzdové vratce.',
            );
        }

        return $this->incomingRefundResult($stored, true);
    }

    /**
     * @param array{
     *   id:int,
     *   allocation_id:?int,
     *   liability_id:int,
     *   event_kind:string,
     *   source_match_id:?int,
     *   amount_minor:int,
     *   bank_statement_id:?int,
     *   bank_transaction_id:?int,
     *   cash_document_id:?int,
     *   actual_payment_date:string,
     *   evidence_amount_minor:int,
     *   evidence_currency_code:string,
     *   evidence_fact_hash:string,
     *   reversed_minor:int
     * } $stored
     */
    private function result(
        array $stored,
        bool $replayed,
    ): PayrollPaymentReconciliationResult {
        if ($stored['allocation_id'] === null) {
            throw new \LogicException(
                'Výsledek odchozí úhrady nemá platební alokaci.',
            );
        }

        return new PayrollPaymentReconciliationResult(
            $stored['id'],
            $stored['allocation_id'],
            $stored['event_kind'],
            $stored['source_match_id'],
            $stored['amount_minor'],
            $stored['bank_transaction_id'] === null ? 'cash' : 'bank',
            $stored['bank_statement_id'],
            $stored['bank_transaction_id'],
            $stored['cash_document_id'],
            $stored['actual_payment_date'],
            $stored['evidence_amount_minor'],
            $stored['evidence_currency_code'],
            $stored['evidence_fact_hash'],
            $replayed,
        );
    }

    /**
     * @param array{
     *   id:int,
     *   allocation_id:?int,
     *   liability_id:int,
     *   event_kind:string,
     *   source_match_id:?int,
     *   amount_minor:int,
     *   bank_statement_id:?int,
     *   bank_transaction_id:?int,
     *   cash_document_id:?int,
     *   actual_payment_date:string,
     *   evidence_amount_minor:int,
     *   evidence_currency_code:string,
     *   evidence_fact_hash:string,
     *   reversed_minor:int
     * } $stored
     */
    private function incomingRefundResult(
        array $stored,
        bool $replayed,
    ): PayrollIncomingRefundReconciliationResult {
        if ($stored['allocation_id'] !== null) {
            throw new \LogicException(
                'Výsledek přijaté mzdové vratky nesmí mít platební alokaci.',
            );
        }

        return new PayrollIncomingRefundReconciliationResult(
            $stored['id'],
            $stored['liability_id'],
            null,
            $stored['event_kind'],
            $stored['source_match_id'],
            $stored['amount_minor'],
            $stored['bank_transaction_id'] === null ? 'cash' : 'bank',
            $stored['bank_statement_id'],
            $stored['bank_transaction_id'],
            $stored['cash_document_id'],
            $stored['actual_payment_date'],
            $stored['evidence_amount_minor'],
            $stored['evidence_currency_code'],
            $stored['evidence_fact_hash'],
            $replayed,
        );
    }

    private function decimalToMinor(string $value): int
    {
        if (preg_match(
            '/^(-?)(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D',
            $value,
            $match,
        ) !== 1) {
            throw new \UnexpectedValueException(
                'Částka platebního důkazu nemá přesný desetinný formát.',
            );
        }
        $wholeText = $match[2];
        $fractionText = str_pad($match[3] ?? '', 2, '0');
        if (strlen($wholeText) > strlen((string) PHP_INT_MAX)) {
            throw new \OverflowException(
                'Částka platebního důkazu přetekla.',
            );
        }
        $whole = (int) $wholeText;
        $fraction = (int) $fractionText;
        if ($whole > intdiv(PHP_INT_MAX - $fraction, 100)) {
            throw new \OverflowException(
                'Částka platebního důkazu přetekla.',
            );
        }
        $minor = ($whole * 100) + $fraction;

        return $match[1] === '-' ? -$minor : $minor;
    }

    private function oppositeDirection(string $direction): string
    {
        return match ($direction) {
            'outgoing' => 'incoming',
            'incoming' => 'outgoing',
            default => throw new \UnexpectedValueException(
                'Závazek má neplatný směr.',
            ),
        };
    }

    private function absolute(int $value): int
    {
        if ($value === PHP_INT_MIN) {
            throw new \OverflowException(
                'Absolutní částka platebního důkazu přetekla.',
            );
        }

        return abs($value);
    }
}
