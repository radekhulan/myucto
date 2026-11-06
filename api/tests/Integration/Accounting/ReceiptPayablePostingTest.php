<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PurchasePayableAccount;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Účet závazku pro účtenky (supplier.receipt_payable_account_code) a úhrady podle účtu,
 * na kterém závazek dokladu vznikl ({@see PurchasePayableAccount}).
 */
#[Group('integration')]
final class ReceiptPayablePostingTest extends BankPostingTestCase
{
    /** Bez nastavení: účtenka 5xx/321, úhrada hotově 321/211 jako dosud. */
    public function testDefaultReceiptPostsToSupplierPayableAndCashSettlesIt(): void
    {
        $receipt = $this->postedPurchase('SYN-RCPT-DEFAULT', 'receipt');
        $entry = $this->linesByAccountCode($this->documentEntry('purchase_invoice', $receipt));
        self::assertSame(['debit' => 0.0, 'credit' => 121.0], $entry['321']);
        self::assertArrayNotHasKey('325', $entry);

        $cashEntry = $this->payCash($receipt, 121.0);
        self::assertSame([
            '321' => ['debit' => 121.0, 'credit' => 0.0],
            '211' => ['debit' => 0.0, 'credit' => 121.0],
        ], $this->linesByAccountCode($cashEntry));
    }

    /** S nastavením 325: účtenka x/325, hotově 325/211, bankou 325/221 — 325 dokladu je nula, 321 netknutý. */
    public function testReceiptWithSettingPostsTo325AndAllPaymentsSettle325(): void
    {
        $this->setReceiptPayable('325');
        $receipt = $this->postedPurchase('SYN-RCPT-325', 'receipt');
        $purchaseEntry = $this->documentEntry('purchase_invoice', $receipt);
        $lines = $this->linesByAccountCode($purchaseEntry);
        self::assertSame(['debit' => 0.0, 'credit' => 121.0], $lines['325'] ?? null, 'Účtenka se zaúčtuje na závazek 325.');
        self::assertArrayNotHasKey('321', $lines);

        $tx = $this->transaction($this->statement(), -100.0, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $receipt, 100.0);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], (string) json_encode($res));
        $bankEntry = $this->documentEntry('bank', $tx);
        $bankLines = $this->linesByAccountCode($bankEntry);
        self::assertSame(['debit' => 100.0, 'credit' => 0.0], $bankLines['325'] ?? null, 'Úhrada bankou vyrovná 325.');
        self::assertSame(100.0, $bankLines['221']['credit'] ?? null);
        self::assertArrayNotHasKey('321', $bankLines);

        // Zbytek 21 Kč hotově (pokladna bere jen celý zbytek dokladu).
        $cashEntry = $this->payCash($receipt, 21.0);
        $cashLines = $this->linesByAccountCode($cashEntry);
        self::assertSame(['debit' => 21.0, 'credit' => 0.0], $cashLines['325'] ?? null, 'Úhrada hotově vyrovná 325.');
        self::assertArrayNotHasKey('321', $cashLines);

        $entries = [$purchaseEntry, $cashEntry, $bankEntry];
        self::assertSame(0.0, $this->balance('325', $entries), 'Závazek účtenky na 325 je po úhradách nulový.');
        self::assertSame(0.0, $this->balance('321', $entries), '321 se účtenky netýká.');
    }

    /** Běžná přijatá faktura zůstává na 321 i se zapnutým nastavením. */
    public function testInvoiceStaysOn321WithReceiptSetting(): void
    {
        $this->setReceiptPayable('325');
        $invoice = $this->postedPurchase('SYN-RCPT-INVOICE', 'invoice');
        $lines = $this->linesByAccountCode($this->documentEntry('purchase_invoice', $invoice));
        self::assertSame(['debit' => 0.0, 'credit' => 121.0], $lines['321'] ?? null);
        self::assertArrayNotHasKey('325', $lines);

        $cashLines = $this->linesByAccountCode($this->payCash($invoice, 121.0));
        self::assertSame(['debit' => 121.0, 'credit' => 0.0], $cashLines['321'] ?? null);
    }

    /** Nastavení přijme jen závazkový účet z osnovy firmy. */
    public function testSettingRejectsNonLiabilityAccount(): void
    {
        $payables = new PurchasePayableAccount($this->db);
        self::assertSame('325', $payables->validateReceiptSetting($this->supplierId, ' 325 '));
        self::assertNull($payables->validateReceiptSetting($this->supplierId, ''));

        foreach (['311', '518', '343', '999999'] as $code) {
            try {
                $payables->validateReceiptSetting($this->supplierId, $code);
                self::fail('Účet ' . $code . ' nesmí projít jako účet závazku pro účtenky.');
            } catch (PostingException $e) {
                self::assertSame('invalid_account', $e->errorCode, $code);
                self::assertSame(422, $e->httpStatus, $code);
            }
        }
    }

    private function setReceiptPayable(?string $code): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET receipt_payable_account_code = ? WHERE id = ?')
            ->execute([$code, $this->supplierId]);
    }

    /** Přijatý doklad 121 Kč bez DPH, zaúčtovaný standardní cestou. */
    private function postedPurchase(string $number, string $kind): int
    {
        $id = $this->purchaseInvoice($number, $this->client('Synthetic receipt vendor ' . $number), 121.0, $kind);
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Synthetic receipt item', 1, 'ks', 121, (SELECT id FROM vat_rates ORDER BY id LIMIT 1), 0, 121, 0, 121, 0)"
        )->execute([$id]);
        $lines = $this->posting->buildFromPurchaseInvoice($this->supplierId, $id);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $id, $lines, [
            'entry_date' => self::YEAR . '-06-10',
            'posted'     => true,
            'posted_by'  => $this->userId,
        ]);
        return $id;
    }

    private function payCash(int $purchaseId, float $amount): int
    {
        $register = $this->container->get(CashRegisterService::class)->create(
            $this->supplierId,
            ['name' => 'Pokladna ' . $purchaseId, 'account_code' => '211', 'is_default' => false],
        );
        $doc = $this->container->get(CashDocumentService::class)->create($this->supplierId, [
            'register_id' => $register, 'issue_date' => self::YEAR . '-06-12', 'description' => 'Úhrada účtenky',
            'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => $amount,
            'purchase_invoice_id' => $purchaseId, 'post' => true,
        ], $this->userId);
        return $this->documentEntry('cash', (int) $doc['id']);
    }

    private function documentEntry(string $sourceType, int $sourceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ? AND reversed_by IS NULL
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$this->supplierId, $sourceType, $sourceId]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id, 'Chybí zápis ' . $sourceType . ' #' . $sourceId);
        return $id;
    }

    /** @param list<int> $entryIds MD − D účtu (vč. analytik) přes zadané zápisy. */
    private function balance(string $code, array $entryIds): float
    {
        $in = implode(',', array_map('intval', $entryIds));
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.entry_id IN ({$in})
                AND (a.account_code = ? OR a.account_code LIKE CONCAT(?, '.%'))"
        );
        $stmt->execute([$this->supplierId, $code, $code]);
        return round((float) $stmt->fetchColumn(), 2);
    }
}
