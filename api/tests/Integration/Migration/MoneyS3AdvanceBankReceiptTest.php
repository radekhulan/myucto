<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Accounting\AdvanceSettlementEvidenceReader;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Migration\MoneyS3\AdvanceReceiptMatcher;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;

/**
 * Záloha z Money uhrazená podle Money interním dokladem bez zápisu, ale týž den přijatá
 * bankou na 324 se symbolem zálohy ({@see SyntheticAgenda::filesWithAdvanceBankReceiptUnlinked()}):
 * převod úhradu zálohy spáruje s převzatým příjmem na 324, výplň bez dokladu úhrady zmizí
 * a konečná faktura v dalším roce nespadne na „Úhrada zálohy není zaúčtovaná".
 */
final class MoneyS3AdvanceBankReceiptTest extends MoneyS3ImportTestCase
{
    public function testAdvanceReceiptPairedWithTakenOverBankEntryOn324(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithAdvanceBankReceiptUnlinked());
        $supplierId = $this->supplier();
        $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = 1 WHERE id = ?')->execute([$supplierId]);
        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $proforma = (int) $this->db->pdo()->query("SELECT id FROM invoices WHERE supplier_id = {$supplierId} AND varsymbol = 'ZV25053'")->fetchColumn();

        self::assertSame(1210.0, (new AdvanceSettlementEvidenceReader($this->db))->advanceReceived($supplierId, $proforma), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('invoice_payments', $supplierId, "invoice_id = {$proforma} AND source = 'bank' AND amount = 1210.00"));
        self::assertSame(0, $this->rowCount('invoice_payments', $supplierId, "invoice_id = {$proforma} AND source = 'settlement'"), 'Výplň bez dokladu úhrady nahradila bankovní úhrada.');
        $finalId = $this->container(FinalFromProformaCreator::class)->create($proforma, $this->userId, '2026-01-15', '2026-01-29');
        self::assertGreaterThan(0, $finalId);

        $again = $this->import($supplierId);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame(1, $this->rowCount('invoice_payments', $supplierId, "invoice_id = {$proforma} AND source = 'bank'"));
    }

    public function testAmountWithinExchangeRateTolerance(): void
    {
        self::assertTrue(AdvanceReceiptMatcher::amountFits(2376.02, 97.0, 'EUR', 24.24, 'EUR', 97.0));
        self::assertTrue(AdvanceReceiptMatcher::amountFits(2420.0, 100.0, 'EUR', 24.5, 'CZK', 2420.0), 'Kurz dokladu 24,50, zaúčtováno 24,20.');
        self::assertFalse(AdvanceReceiptMatcher::amountFits(2000.0, 100.0, 'EUR', 24.5, 'CZK', 2000.0));
        self::assertTrue(AdvanceReceiptMatcher::amountFits(1210.0, 1210.0, 'CZK', null, 'CZK', 1210.0));
        self::assertFalse(AdvanceReceiptMatcher::amountFits(1200.0, 1210.0, 'CZK', null, 'CZK', 1200.0));
    }
}
