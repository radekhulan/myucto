<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Repository\CashJournalNoteRepository;
use MyInvoice\Repository\DocumentLinkRepository;

/**
 * Poznámky a přílohy k pohybům peněžního deníku (P3 parity): poznámka visí na řádku
 * deníku (source_type, source_id), cizí pohyb se nedá okomentovat a ruční úhrada
 * faktury nese přílohy existující vazbou dokument ↔ entita.
 */
final class CashJournalNoteTest extends CashJournalTestCase
{
    private CashJournalNoteRepository $notes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notes = $this->container->get(CashJournalNoteRepository::class);
    }

    public function testNotesLiveOnCashJournalMovement(): void
    {
        $cash = $this->cashDoc('out', 'other', 120.0);

        self::assertTrue($this->notes->sourceBelongsToSupplier('cash', $cash, $this->supplierId));
        $first = $this->notes->add($this->supplierId, 'cash', $cash, 'Účtenka u účetní', false, $this->userId);
        $pinned = $this->notes->add($this->supplierId, 'cash', $cash, 'Připnutá', true, $this->userId);

        $list = $this->notes->list($this->supplierId, 'cash', $cash);
        self::assertSame([$pinned, $first], array_column($list, 'id'));

        self::assertTrue($this->notes->update($first, $this->supplierId, 'cash', $cash, 'Opraveno', null, $this->userId));
        self::assertFalse($this->notes->update($first, $this->supplierId, 'bank', $cash, 'Jiný pohyb', null, $this->userId),
            'Poznámka jednoho pohybu se nesmí dát upravit přes jiný typ pohybu se stejným id.');
        self::assertTrue($this->notes->softDelete($pinned, $this->supplierId, 'cash', $cash, $this->userId));

        $brief = $this->notes->briefForSupplier($this->supplierId);
        self::assertSame(['Opraveno'], array_column($brief['cash:' . $cash] ?? [], 'body'));
    }

    public function testForeignMovementIsRejected(): void
    {
        $other = $this->cloneSupplier('tax_evidence', false);
        $invoice = $this->saleInvoice($this->supplierId, ['status' => 'paid']);
        $payment = $this->invoicePayment($this->supplierId, $invoice, 100.0, 'manual');

        self::assertTrue($this->notes->sourceBelongsToSupplier('invoice_payment', $payment, $this->supplierId));
        self::assertFalse($this->notes->sourceBelongsToSupplier('invoice_payment', $payment, $other));
        self::assertFalse($this->notes->sourceBelongsToSupplier('unknown', $payment, $this->supplierId));

        $statement = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($statement, 500.0);
        self::assertTrue($this->notes->sourceBelongsToSupplier('bank', $tx, $this->supplierId));
        self::assertFalse($this->notes->sourceBelongsToSupplier('bank', $tx, $other));
    }

    public function testManualInvoicePaymentCarriesAttachment(): void
    {
        $invoice = $this->saleInvoice($this->supplierId, ['status' => 'paid']);
        $payment = $this->invoicePayment($this->supplierId, $invoice, 250.0, 'manual');
        $links = $this->container->get(DocumentLinkRepository::class);
        self::assertTrue($links->entityBelongsToSupplier('invoice_payment', $payment, $this->supplierId));

        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO documents (supplier_id, title, filename, original_name, mime_type, size_bytes, sha256, uploaded_by)
             VALUES (?, 'Avízo', 'avizo.pdf', 'avizo.pdf', 'application/pdf', 10, ?, ?)"
        )->execute([$this->supplierId, hash('sha256', uniqid('cjn', true)), $this->userId]);
        $documentId = (int) $pdo->lastInsertId();
        $links->attach($this->supplierId, $documentId, 'invoice_payment', $payment);

        self::assertSame(1, $this->notes->attachmentCounts($this->supplierId)['invoice_payment:' . $payment] ?? 0);
        $labels = $links->linksForDocument($documentId, $this->supplierId);
        self::assertSame('invoice_payment', $labels[0]['entity_type']);
        self::assertStringNotContainsString('#', $labels[0]['label']);
    }
}
