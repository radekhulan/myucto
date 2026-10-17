<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Repository\CashJournalNoteRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DocumentLinkRepository;
use MyInvoice\Repository\DocumentRequestRepository;
use MyInvoice\Repository\FuelingRepository;
use MyInvoice\Repository\MovementClassificationRepository;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Repository\TaxAdvanceScheduleRepository;
use MyInvoice\Service\Bank\BankNoticeConfirmationException;
use MyInvoice\Service\Bank\BankNoticeMutationGuard;

final class BankNoticeMutationGuardTest extends BankPostingTestCase
{
    public function testConfirmedAndInvalidatedNoticesCannotAcquireFreshReferences(): void
    {
        $notice = $this->transaction($this->statement(), 200.0);
        $sourceRef = 'synthetic-read-only-' . $notice;
        $this->db->pdo()->prepare("UPDATE bank_transactions SET source='email_notice',source_ref=? WHERE id=?")->execute([$sourceRef, $notice]);
        $this->db->pdo()->prepare("INSERT INTO bank_notice_confirmations (supplier_id,notice_transaction_id,notice_source_ref,notice_identity,state) VALUES (?,?,?,?,'confirmed')")
            ->execute([$this->supplierId, $notice, $sourceRef, hash('sha256', $sourceRef)]);
        $sid = $this->supplierId;
        $client = $this->client('Synthetic notice customer');
        $invoice = $this->saleInvoice('999157', $client, 200.0);
        $mutations = [
            'classification' => fn () => $this->container->get(MovementClassificationRepository::class)->upsert($sid, 'bank', $notice, 'transfer', null, $this->userId),
            'cash journal note' => fn () => $this->container->get(CashJournalNoteRepository::class)->add($sid, 'bank', $notice, 'Synthetic note', false, $this->userId),
            'document' => fn () => $this->container->get(DocumentLinkRepository::class)->attach($sid, 1, 'bank_transaction', $notice),
            'document request' => fn () => $this->container->get(DocumentRequestRepository::class)->create($sid, ['bank_transaction_id' => $notice], $this->userId),
            'document submission' => fn () => $this->container->get(PurchaseInvoiceSubmissionRepository::class)->create($sid, ['bank_transaction_id' => $notice]),
            'fueling create' => fn () => $this->container->get(FuelingRepository::class)->create($sid, ['source_bank_transaction_id' => $notice], $this->userId),
            'fueling scan' => fn () => $this->container->get(FuelingRepository::class)->insertScanned($sid, ['source_bank_transaction_id' => $notice], $this->userId),
            'fueling completion' => fn () => $this->container->get(FuelingRepository::class)->fillMissing(1, $sid, ['source_bank_transaction_id' => $notice]),
            'fueling link' => fn () => $this->container->get(FuelingRepository::class)->setLinks(1, $sid, ['source_bank_transaction_id' => $notice]),
            'dimension' => fn () => $this->container->get(DimensionAssignmentRepository::class)->replaceDocumentDimensions($sid, 'bank_transaction', $notice, [], []),
            'dimension fill' => fn () => $this->container->get(DimensionAssignmentRepository::class)->addHeaderDimensionIfMissing($sid, 'bank_transaction', $notice, 1, 1),
            'dimension copy' => fn () => $this->container->get(DimensionAssignmentRepository::class)->copyDocument($sid, 'bank_transaction', 1, $notice),
            'dimension split' => fn () => $this->container->get(DimensionAssignmentRepository::class)->replaceDocumentSplits($sid, 'bank_transaction', $notice, []),
            'sale payment' => fn () => $this->container->get(\MyInvoice\Service\Invoice\InvoicePaymentService::class)->recordPayment($invoice, 200.0, self::YEAR . '-06-15', ['source' => 'bank', 'bank_transaction_id' => $notice]),
            'sale reconciliation' => fn () => $this->container->get(\MyInvoice\Service\Invoice\InvoicePaymentService::class)->reconcileToBankTransaction($invoice, $notice),
            'purchase payment' => fn () => \MyInvoice\Service\Bank\PurchasePaymentMatchWriter::record($this->db->pdo(), $sid, $notice, 1, 200.0, 'manual'),
            'GoPay payout' => fn () => $this->container->get(\MyInvoice\Service\Accounting\GoPay\GoPayService::class)->associatePayoutTransaction($sid, 1, $notice, $this->userId),
            'transfer journal' => fn () => $this->container->get(\MyInvoice\Service\Accounting\Bank\TransferPairService::class)->postLeg($sid, $notice, $this->userId),
            'other item' => fn () => $this->container->get(\MyInvoice\Service\Accounting\OtherItemService::class)->allocate($sid, 1, ['bank_transaction_id' => $notice], $this->userId),
            'client bank reference' => fn () => $this->container->get(\MyInvoice\Repository\ClientBankAccountRepository::class)->captureFromBank($client, $sid, '1000000005', '0100', $notice),
            'counterparty observation' => fn () => $this->container->get(\MyInvoice\Service\Bank\Match\CounterpartyMapService::class)->record($sid, '1000000005', '0100', 'incoming', $client, true, null, $notice),
            'payroll signal' => fn () => $this->container->get(\MyInvoice\Repository\Payroll\PayrollPaymentSettlementSignalRepository::class)->insert($sid, 1, 'bank_notice', $notice, self::YEAR . '-06-15', 20000, null, $this->userId),
            'tax advance' => fn () => $this->container->get(TaxAdvanceScheduleRepository::class)->markPaid($sid, 1, 200.0, self::YEAR . '-06-15', $notice),
        ];
        foreach (['confirmed', 'review'] as $state) {
            if ($state === 'review') $this->db->pdo()->prepare("UPDATE bank_notice_confirmations SET state='review',reason='authoritative_removed' WHERE notice_transaction_id=?")->execute([$notice]);
            foreach ($mutations as $name => $mutation) {
                try {
                    $mutation();
                    self::fail("{$state}: {$name} must refuse a replaced notice.");
                } catch (BankNoticeConfirmationException $exception) {
                    self::assertSame('notice_read_only', $exception->errorCode, $name);
                    self::assertTrue($this->db->pdo()->inTransaction(), $name . ' must preserve caller transaction');
                }
            }
        }
    }

    public function testGuardAllowsActiveSourceAndKeepsCallerTransactionOpen(): void
    {
        $transaction = $this->transaction($this->statement(), 200.0);
        $guard = new BankNoticeMutationGuard($this->db);
        $result = $guard->mutate($this->supplierId, $transaction, function () use ($transaction): string {
            $this->db->pdo()->prepare('UPDATE bank_transactions SET description=? WHERE id=?')->execute(['Synthetic guarded write', $transaction]);
            return 'saved';
        });
        self::assertSame('saved', $result);
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame('Synthetic guarded write', $this->db->pdo()->query('SELECT description FROM bank_transactions WHERE id=' . $transaction)->fetchColumn());
    }

    public function testDeletedSourceCannotAcquireAnOrphanReference(): void
    {
        $transaction = $this->transaction($this->statement(), 200.0);
        $this->db->pdo()->prepare('DELETE FROM bank_transactions WHERE id=?')->execute([$transaction]);
        $called = false;
        try {
            (new BankNoticeMutationGuard($this->db))->mutate($this->supplierId, $transaction, static function () use (&$called): void { $called = true; });
            self::fail('A removed source must not acquire a fresh polymorphic reference.');
        } catch (BankNoticeConfirmationException $exception) {
            self::assertSame('transaction_not_found', $exception->errorCode);
            self::assertSame(404, $exception->httpStatus);
            self::assertFalse($called);
            self::assertTrue($this->db->pdo()->inTransaction());
        }
    }
}
