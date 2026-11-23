<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use MyInvoice\Service\Invoice\TakenOverPayment;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;

/**
 * Záloha z Money bez sazeb, první polovina uhrazená v Money s DDKP 21 %, druhá polovina
 * přijde po převodu bankou v MyÚčtu (typická záloha přes hranici roku). Sazbu plnění
 * určuje jediná sazba DDKP: DDKP k nové platbě i konečná faktura jdou vystavit se sazbou
 * 21 % a nepokrytá polovina zálohy se na konečné faktuře zdaní.
 */
final class MoneyS3ProformaSplitAdvanceTest extends MoneyS3ImportTestCase
{
    private int $supplierId = 0;
    private int $proforma = 0;
    private int $payment = 0;

    protected function setUp(): void
    {
        parent::setUp();
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithSettledWithoutPaymentDocument());
        $this->supplierId = $this->supplier();
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 1 WHERE id = ?')->execute([$this->supplierId]);
        $protocol = $this->import($this->supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $s = $pdo->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $s->execute([$this->supplierId, 'ZV25050']);
        $this->proforma = (int) $s->fetchColumn();
        // Záloha 2 420 (bez sazby), z Money uhrazeno 1 210 s DDKP DD25050 (1 000 + 210).
        $pdo->prepare('UPDATE invoice_items SET unit_price_without_vat = 2420, total_without_vat = 2420, total_with_vat = 2420 WHERE invoice_id = ?')->execute([$this->proforma]);
        $pdo->prepare("UPDATE invoices SET total_without_vat = 2420, total_with_vat = 2420, status = 'sent', paid_at = NULL WHERE id = ?")->execute([$this->proforma]);
        // Druhá polovina přijatá v MyÚčtu po převodu.
        $pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source) VALUES (?, ?, '2026-01-15', 1210.00, 'CZK', 'manual')")
            ->execute([$this->supplierId, $this->proforma]);
        $this->payment = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE invoices SET paid_total = 2420, status = 'paid', paid_at = '2026-01-15' WHERE id = ?")->execute([$this->proforma]);
    }

    public function testTaxDocumentForNativePaymentUsesRateOfMoneyTaxDocument(): void
    {
        $row = $this->db->pdo()->query("SELECT source, note FROM invoice_payments WHERE id = {$this->payment}")->fetch(PDO::FETCH_ASSOC);
        self::assertFalse(TakenOverPayment::isTaxDeclaredByOrigin($row));
        $tdId = $this->container(PaymentTaxDocumentCreator::class)->createForPayment($this->payment);
        $vat = (float) $this->db->pdo()->query("SELECT total_vat FROM invoices WHERE id = {$tdId}")->fetchColumn();
        self::assertSame(210.0, $vat);
    }

    public function testFinalInvoiceAfterSplitAdvance(): void
    {
        $finalId = $this->container(FinalFromProformaCreator::class)->create($this->proforma, $this->userId, '2026-01-20', '2026-01-30', requirePostedReceipt: false);
        $vat = (float) $this->db->pdo()->query("SELECT total_vat FROM invoices WHERE id = {$finalId}")->fetchColumn();
        self::assertSame(210.0, $vat, 'nezdaněná polovina zálohy na konečné faktuře');
    }
}
