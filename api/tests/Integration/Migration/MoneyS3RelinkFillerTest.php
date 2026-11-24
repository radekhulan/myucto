<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Invoice\TakenOverPayment;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use PHPUnit\Framework\Attributes\Group;

/**
 * Opravný převod vazeb (`relinkOnly`) u dokladu, který úhradu z Money nese jen výplní bez
 * dokladu úhrady: výplň evidovanou úhradou není, převod úhradu z převedené banky přidá
 * a výplň zmenší, stejně jako řádný převod.
 */
#[Group('integration')]
final class MoneyS3RelinkFillerTest extends MoneyS3ImportTestCase
{
    public function testRelinkAddsBankPaymentAndShrinksFiller(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $pdo = $this->db->pdo();
        $invoice = (int) $pdo->query("SELECT id FROM invoices WHERE supplier_id = {$supplierId} AND varsymbol = 'FV24001'")->fetchColumn();
        $tx = (int) $pdo->query("SELECT bank_transaction_id FROM invoice_payments WHERE invoice_id = {$invoice} AND source = 'bank'")->fetchColumn();
        self::assertGreaterThan(0, $tx);
        // Stav firmy převedené dřív: úhradu FV24001 nese jen výplň, pohyb je „zaúčtováno bez dokladu".
        $pdo->prepare('DELETE FROM invoice_payments WHERE invoice_id = ?')->execute([$invoice]);
        $pdo->prepare('DELETE FROM payment_matches WHERE invoice_id = ?')->execute([$invoice]);
        $pdo->prepare("DELETE FROM money_s3_import_map WHERE supplier_id = ? AND kind = 'payment' AND money_key = 'v|2024|FV24001'")->execute([$supplierId]);
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'ignored', match_reason = 'money_s3_booked', matched_invoice_id = NULL WHERE id = ?")->execute([$tx]);
        $pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source) VALUES (?, ?, '2024-03-20', 24200.00, 'CZK', ?, 'settlement')")
            ->execute([$supplierId, $invoice, TakenOverPayment::MONEY_S3_WITHOUT_DOCUMENT_NOTE . ' (BP24002)']);

        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT, relinkOnly: true));

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('invoice_payments', $supplierId, "invoice_id = {$invoice} AND source = 'bank' AND bank_transaction_id = {$tx} AND amount = 24200.00"), $this->explain($protocol));
        self::assertSame(0, $this->rowCount('invoice_payments', $supplierId, "invoice_id = {$invoice} AND source = 'settlement'"), 'Výplň zmizela.');
        self::assertSame('paid', (string) $pdo->query("SELECT status FROM invoices WHERE id = {$invoice}")->fetchColumn());
    }
}
