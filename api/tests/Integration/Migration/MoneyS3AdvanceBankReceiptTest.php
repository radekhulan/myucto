<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Accounting\AdvanceSettlementEvidenceReader;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Migration\MoneyS3\AdvanceReceiptMatcher;
use MyInvoice\Service\Migration\MoneyS3\DocumentLinker;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
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

    /**
     * Příjem 1 000 EUR, z něhož jde na 324 jen 100 EUR a zbytek na 311, řádky bez valuty:
     * část na 324 v eurech ocenit nejde, úhrada zálohy 1 000 EUR se nespáruje a protokol ji
     * ohlásí k ověření. Doložené čerpání zůstane nulové, ne 1 000 EUR.
     */
    public function testForeignReceiptSplitWithoutValutaIsLeftForReview(): void
    {
        [$supplierId, $protocol, $advance] = $this->importForeign(SyntheticAgenda::filesWithAdvanceForeignReceiptSplit(false));

        self::assertSame(0, $this->rowCount('invoice_payments', $supplierId, "invoice_id = {$advance} AND source = 'bank'"), $this->explain($protocol));
        self::assertSame(0.0, (new AdvanceSettlementEvidenceReader($this->db))->advanceReceived($supplierId, $advance));
        self::assertContains('advance_bank_receipt_unpriced', $this->codes($protocol), $this->explain($protocol));
    }

    /**
     * Řádky Money nesou valutu: na 324 přišlo 100 EUR, na zálohu 1 000 EUR nestačí. Příjem bez
     * symbolu (shoda jménem), aby ho nespároval už symbol zálohy.
     */
    public function testForeignReceiptSplitWithValutaDoesNotCoverWholeAdvance(): void
    {
        [$supplierId, $protocol, $advance] = $this->importForeign(SyntheticAgenda::filesWithAdvanceForeignReceiptSplit(true, 1000.0, ''));

        self::assertSame(0, $this->rowCount('invoice_payments', $supplierId, "invoice_id = {$advance} AND source = 'bank'"), $this->explain($protocol));
        self::assertSame(0.0, (new AdvanceSettlementEvidenceReader($this->db))->advanceReceived($supplierId, $advance));
    }

    /** Záloha 100 EUR: valuta řádku 324 (z bankovního řádku téhož řádku Money) sedí, úhrada je 100 EUR. */
    public function testForeignReceiptValutaOn324PairsOnlyReceivedPart(): void
    {
        [$supplierId, $protocol, $advance] = $this->importForeign(SyntheticAgenda::filesWithAdvanceForeignReceiptSplit(true, 100.0, ''));

        self::assertSame(1, $this->rowCount('invoice_payments', $supplierId, "invoice_id = {$advance} AND source = 'bank' AND amount = 100.00 AND currency = 'EUR'"), $this->explain($protocol));
        self::assertSame(100.0, (new AdvanceSettlementEvidenceReader($this->db))->advanceReceived($supplierId, $advance));
    }

    /** Dvě zálohy téhož odběratele na stejnou částku a jediný příjem bez symbolu: nepáruje se ani jedna. */
    public function testReceiptMatchingTwoAdvancesIsAmbiguous(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithAdvanceBankReceiptUnlinked('', 'Odběratel Zeta s.r.o.', true));
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        self::assertSame(0, $this->rowCount('invoice_payments', $supplierId,
            "source = 'bank' AND invoice_id IN (SELECT id FROM invoices WHERE supplier_id = {$supplierId} AND varsymbol IN ('ZV25053', 'ZV25054'))"), $this->explain($protocol));
        self::assertContains('advance_bank_receipt_ambiguous', $this->codes($protocol));
    }

    /** Jméno sedí na odběratele zálohy, ale symbol příjmu patří jiné zálohové faktuře. */
    public function testReceiptWithSymbolOfAnotherAdvanceIsRejected(): void
    {
        $this->assertIdentityConflict('25050', 'Odběratel Zeta s.r.o.');
    }

    /** Symbol sedí na zálohu, ale protistrana příjmu je jiná než odběratel zálohy. */
    public function testReceiptFromDifferentCounterpartyIsRejected(): void
    {
        $this->assertIdentityConflict('25053', 'Odběratel Omega a.s.');
    }

    /**
     * Běžný opakovaný převod vazbu v uzavřeném období nezaloží (ohlásí ji), opravný převod
     * vazeb ano - stejně jako ostatní párování úhrad.
     */
    public function testRegularImportDoesNotPairInClosedPeriodRelinkDoes(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithAdvanceBankReceiptUnlinked());
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $proforma = (int) $this->db->pdo()->query("SELECT id FROM invoices WHERE supplier_id = {$supplierId} AND varsymbol = 'ZV25053'")->fetchColumn();
        // Stav firmy převedené před párováním příjmu na 324: úhradu zálohy nese jen výplň.
        $this->db->pdo()->prepare("DELETE FROM invoice_payments WHERE invoice_id = ? AND source = 'bank'")->execute([$proforma]);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE supplier_id = ? AND fiscal_year = 2025")->execute([$supplierId]);

        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(0, $this->rowCount('invoice_payments', $supplierId, "invoice_id = {$proforma} AND source = 'bank'"), $this->explain($protocol));
        self::assertContains('advance_bank_receipt_closed_period', $this->codes($protocol), $this->explain($protocol));

        $relink = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT, relinkOnly: true));
        self::assertFalse($relink->hasErrors(), $this->explain($relink));
        self::assertSame(1, $this->rowCount('invoice_payments', $supplierId, "invoice_id = {$proforma} AND source = 'bank' AND amount = 1210.00"), $this->explain($relink));
    }

    public function testReceivedOn324Valuation(): void
    {
        $receipt = static fn (array $over): array => $over + ['czk' => 2500.0, 'total' => 25000.0, 'currency' => 'EUR', 'amount' => 1000.0,
            'lines' => 1, 'fx_lines' => 0, 'fx_currencies' => 0, 'fx_currency' => '', 'fx' => 0.0];
        self::assertNull(AdvanceReceiptMatcher::receivedOn324($receipt([]), 'EUR', 25.0), 'Rozdělený příjem ve měně zálohy bez valuty.');
        self::assertSame(['amount' => 1000.0, 'exact' => true], AdvanceReceiptMatcher::receivedOn324($receipt(['czk' => 25000.0]), 'EUR', 25.0));
        self::assertSame(['amount' => 100.0, 'exact' => true],
            AdvanceReceiptMatcher::receivedOn324($receipt(['fx_lines' => 1, 'fx_currencies' => 1, 'fx_currency' => 'EUR', 'fx' => 100.0]), 'EUR', 25.0));
        self::assertNull(AdvanceReceiptMatcher::receivedOn324($receipt(['lines' => 2, 'fx_lines' => 1, 'fx_currencies' => 1, 'fx_currency' => 'EUR', 'fx' => 100.0]), 'EUR', 25.0), 'Smíšené řádky.');
        self::assertNull(AdvanceReceiptMatcher::receivedOn324($receipt(['fx_lines' => 1, 'fx_currencies' => 1, 'fx_currency' => 'USD', 'fx' => 110.0]), 'EUR', 25.0));
        self::assertSame(['amount' => 2500.0, 'exact' => true], AdvanceReceiptMatcher::receivedOn324($receipt([]), 'CZK', null));
        self::assertSame(['amount' => 98.78, 'exact' => false], AdvanceReceiptMatcher::receivedOn324($receipt(['czk' => 2420.0, 'total' => 2420.0, 'currency' => 'CZK', 'amount' => 2420.0]), 'EUR', 24.5),
            'Příjem v Kč: Kč na 324 kurzem zálohy, čerpání nepřesáhne přijaté Kč.');
        self::assertNull(AdvanceReceiptMatcher::receivedOn324($receipt(['currency' => 'CZK']), 'EUR', null), 'Bez kurzu.');
    }

    public function testAmountWithinExchangeRateTolerance(): void
    {
        self::assertTrue(AdvanceReceiptMatcher::amountFits(2376.02, 97.0, 'EUR', 24.24, 'EUR', 97.0));
        self::assertTrue(AdvanceReceiptMatcher::amountFits(2420.0, 100.0, 'EUR', 24.5, 'CZK', 2420.0), 'Kurz dokladu 24,50, zaúčtováno 24,20.');
        self::assertFalse(AdvanceReceiptMatcher::amountFits(2000.0, 100.0, 'EUR', 24.5, 'CZK', 2000.0));
        self::assertTrue(AdvanceReceiptMatcher::amountFits(1210.0, 1210.0, 'CZK', null, 'CZK', 1210.0));
        self::assertFalse(AdvanceReceiptMatcher::amountFits(1200.0, 1210.0, 'CZK', null, 'CZK', 1200.0));
    }

    private function assertIdentityConflict(string $symbol, string $name): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithAdvanceBankReceiptUnlinked($symbol, $name));
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $proforma = (int) $this->db->pdo()->query("SELECT id FROM invoices WHERE supplier_id = {$supplierId} AND varsymbol = 'ZV25053'")->fetchColumn();

        self::assertSame(0, $this->rowCount('invoice_payments', $supplierId, "invoice_id = {$proforma} AND source = 'bank'"), $this->explain($protocol));
        self::assertContains('advance_bank_receipt_conflict', $this->codes($protocol), $this->explain($protocol));
    }

    /**
     * @param array<string,string> $files
     * @return array{0:int,1:ImportProtocol,2:int}
     */
    private function importForeign(array $files): array
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $files);
        $supplierId = $this->supplier();
        $this->db->pdo()->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'EUR', 'EUR', '€', 'Euro', 'Euro', 2, 1, 0)")->execute([$supplierId]);
        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $advance = (int) $this->db->pdo()->query("SELECT id FROM invoices WHERE supplier_id = {$supplierId} AND varsymbol = 'ZV25055'")->fetchColumn();
        self::assertGreaterThan(0, $advance, $this->explain($protocol));
        return [$supplierId, $protocol, $advance];
    }

    /** @return list<string> */
    private function codes(ImportProtocol $protocol): array
    {
        return array_column(array_column($protocol->toArray()['steps'], null, 'key')[DocumentLinker::STEP_PAYMENTS]['messages'] ?? [], 'code');
    }
}
