<?php
declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\PostingException;

final class BankTransactionPreviewTest extends BankPostingTestCase
{
    public function testMatchedPreviewUsesInvoicePostingWithoutWritingJournal(): void
    {
        $inv = $this->saleInvoice('SYNTHETIC-PREVIEW', $this->client('Synthetic preview'), 100);
        $this->postPredpis('invoice', $inv, '311', '602', 100);
        $statement = $this->statement();
        $this->db->pdo()->prepare("UPDATE bank_statements SET source='bank_api' WHERE id=?")->execute([$statement]);
        $tx = $this->transaction($statement, 100, ['match_status'=>'auto_exact', 'matched_invoice_id'=>$inv]);
        $this->invoicePayment($inv, $tx, 100);
        $before = $this->db->pdo()->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
        $preview = $this->service->previewTransaction($this->supplierId, $tx);
        self::assertTrue($preview['matched']);
        self::assertNull($preview['reason']);
        self::assertSame(['221', '311'], array_column($preview['lines'], 'account_code'));
        self::assertSame($before, $this->db->pdo()->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn());
    }

    public function testOtherSupplierCannotPreviewTransaction(): void
    {
        $tx = $this->transaction($this->statement(), 100);
        $this->expectException(PostingException::class);
        $this->service->previewTransaction(PHP_INT_MAX, $tx);
    }

    public function testUnmatchedApiPreviewResolvesRegisteredBankAnalytic(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO supplier_bank_accounts
            (supplier_id,label,account_number,bank_code,bank_code_norm,currency,account_canonical,kind,analytic_suffix,source,is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, "current", ?, "manual", 1)')
            ->execute([$this->supplierId, 'Synthetic bank', '1000000005', '0100', '0100', 'CZK', '1000000005', '987']);
        $statement = $this->statement('1000000005', '0100');
        $pdo->prepare("UPDATE bank_statements SET source='bank_api' WHERE id=?")->execute([$statement]);
        $tx = $this->transaction($statement, 100);
        $preview = $this->service->previewTransaction($this->supplierId, $tx);
        self::assertSame('221.987', $preview['bank_account_code']);
        self::assertTrue($preview['resolved']);
        self::assertFalse($preview['matched']);
        self::assertSame([], $preview['lines']);
    }

    public function testUnpostedMatchedPaymentStillOffersInvoicePreview(): void
    {
        $inv = $this->saleInvoice('SYNTHETIC-UNPOST', $this->client('Synthetic preview'), 100);
        $this->postPredpis('invoice', $inv, '311', '602', 100);
        $tx = $this->transaction($this->statement(), 100, ['match_status'=>'auto_exact', 'matched_invoice_id'=>$inv]);
        $this->invoicePayment($inv, $tx, 100);
        $posted = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $posted['action']);
        $originalAccounts = array_values(array_filter(
            array_map('strval', array_keys($this->linesByAccountCode((int) $posted['entry_id']))),
            static fn (string $code): bool => $code !== '221',
        ));
        $this->service->unpost($this->supplierId, $tx, ['user_id'=>$this->userId]);
        $preview = $this->service->previewTransaction($this->supplierId, $tx);
        self::assertTrue($preview['matched']);
        self::assertNull($preview['reason']);
        self::assertSame($originalAccounts, array_column($preview['lines'], 'account_code'));
    }

    public function testMissingInvoicePostingIsExplainedRatherThanBlankManualPosting(): void
    {
        $inv = $this->saleInvoice('SYNTHETIC-NOPREVIEW', $this->client('Synthetic preview'), 100);
        $tx = $this->transaction($this->statement(), 100, ['match_status'=>'auto_exact', 'matched_invoice_id'=>$inv]);
        $this->invoicePayment($inv, $tx, 100);
        $preview = $this->service->previewTransaction($this->supplierId, $tx);
        self::assertSame('document_not_posted', $preview['reason']);
        self::assertSame([], $preview['lines']);
    }
}
