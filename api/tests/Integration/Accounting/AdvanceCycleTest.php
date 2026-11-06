<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Zálohový cyklus 314/324 (Fáze B, B5): proforma → DDKP → vyúčtování na vydané straně
 * a symetricky zálohová PF → vyúčtování na přijaté straně. Ověřuje:
 *   (a) platba proformy = inkaso přijaté zálohy 221/324 (bankovní párování),
 *   (b) DDKP (tax_document) = 324/343 (ne 311/602),
 *   (c) vyúčtovací faktura z proformy = normální řádky + zúčtování zálohy 324/311
 *       v přesné výši SKUTEČNĚ přijaté zálohy,
 *   (d) přijatá strana: platba zálohové PF = 314/221, finální PF = zúčtování 321/314,
 *   (e) běžná faktura BEZ zálohy je beze změny (regrese),
 *   (f) DDKP + vyúčtování a víc vyúčtovacích faktur = hlasitá chyba (out of scope v1).
 *
 * Sdílí bankovní fixtury a rollback-per-test z {@see BankPostingTestCase}.
 */
#[Group('integration')]
final class AdvanceCycleTest extends BankPostingTestCase
{
    private int $vatRateId = 0;
    private SaldoService $saldo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->saldo = $this->container->get(SaldoService::class);
        $this->vatRateId = (int) ($this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vatRateId === 0) {
            self::markTestSkipped('Chybí vat_rates v DB.');
        }
    }

    // (a) — inkaso přijaté zálohy (viz i BankPostingServiceMatchedTest); tady s ověřením 324.
    public function testProformaPaymentBooksReceivedAdvance221Over324(): void
    {
        $client   = $this->client('Odběratel s.r.o.');
        $proforma = $this->saleWithItem('PRO-A', $client, 1000.00, 210.00, 'proforma');
        $tx = $this->payProformaViaBank($proforma, 1210.00);

        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        self::assertEqualsWithDelta(1210.00, $byAcc['221']['debit'], 0.001, '221 MD = přijatá záloha.');
        self::assertEqualsWithDelta(1210.00, $byAcc['324']['credit'], 0.001, '324 D = závazek z přijaté zálohy.');
        self::assertArrayNotHasKey('311', $byAcc, 'Proforma nezakládá saldokonto 311.');
    }

    // (b) — DDKP účtuje jen DPH 324/343, žádné 311/602.
    public function testTaxDocumentBooksVatOnly324Over343(): void
    {
        $client   = $this->client('Odběratel s.r.o.');
        $proforma = $this->saleWithItem('PRO-B', $client, 1000.00, 210.00, 'proforma');
        // DDKP k platbě 605 Kč (základ 500 / DPH 105), režim cen s DPH.
        $ddkp = $this->saleWithItem('DDKP-B', $client, 500.00, 105.00, 'tax_document', $proforma, 1);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $ddkp);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $ddkp, $lines, ['entry_date' => self::YEAR . '-06-16']);
        $byAcc   = $this->linesByAccountCode($entryId);

        self::assertEqualsWithDelta(105.00, $byAcc['324']['debit'], 0.001, '324 MD = čerpání přijaté zálohy o DPH.');
        self::assertEqualsWithDelta(105.00, $byAcc['343.200']['credit'], 0.001, '343 D = DPH na výstupu ze zálohy.');
        self::assertArrayNotHasKey('311', $byAcc, 'DDKP nezakládá pohledávku 311.');
        self::assertArrayNotHasKey('602', $byAcc, 'DDKP nenese výnos 602.');
        $this->assertBalancedEntry($entryId);
    }

    // (b2) — OPRAVNÝ vydaný DDKP (záporná DPH) obrací strany: 343 MD / 324 D.
    //        Bez otočení by se abs(vat) zaúčtovalo na původní strany, tedy přesně
    //        obráceně — zápis by zůstal vyvážený a integritní kontrola by to nechytila,
    //        protože porovnává ABS(l.amount). Zrcadlo přijaté větve.
    public function testNegativeTaxDocumentFlipsSides(): void
    {
        $client   = $this->client('Odběratel s.r.o.');
        $proforma = $this->saleWithItem('PRO-B2', $client, 1000.00, 210.00, 'proforma');
        $ddkp = $this->saleWithItem('DDKP-B2', $client, -500.00, -105.00, 'tax_document', $proforma, 1);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $ddkp);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $ddkp, $lines, ['entry_date' => self::YEAR . '-06-16']);
        $byAcc   = $this->linesByAccountCode($entryId);

        self::assertEqualsWithDelta(105.00, $byAcc['343.200']['debit'] ?? 0.0, 0.001, 'Opravný DDKP snižuje DPH na výstupu → 343 MD.');
        self::assertEqualsWithDelta(105.00, $byAcc['324']['credit'] ?? 0.0, 0.001, 'A vrací čerpání zálohy → 324 D.');
        self::assertSame(0.0, $byAcc['324']['debit'] ?? 0.0, 'Strany NESMÍ zůstat jako u kladného DDKP.');
        $this->assertBalancedEntry($entryId);
    }

    // (c) — vyúčtovací faktura: normální řádky + zúčtování zálohy 324/311 v přesné výši přijaté zálohy.
    public function testFinalInvoiceAppendsAdvanceSettlementForReceivedAmount(): void
    {
        $client   = $this->client('Odběratel s.r.o.');
        $proforma = $this->saleWithItem('PRO-C', $client, 1000.00, 210.00, 'proforma');
        // Částečně zaplacená záloha 605 Kč (proforma může být zaplacená jen zčásti).
        $this->payProformaViaBank($proforma, 605.00);

        // Finální faktura (bez DDKP → plná hodnota) navázaná na proformu.
        $final = $this->saleWithItem('FV-C', $client, 1000.00, 210.00, 'invoice', $proforma);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $final);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $final, $lines, ['entry_date' => self::YEAR . '-06-20']);
        $byAcc   = $this->linesByAccountCode($entryId);

        // Normální řádky faktury.
        self::assertEqualsWithDelta(1210.00, $byAcc['311']['debit'], 0.001, '311 MD = celková částka faktury.');
        self::assertEqualsWithDelta(1000.00, $byAcc['602']['credit'], 0.001, '602 D = výnos.');
        self::assertEqualsWithDelta(210.00, $byAcc['343.200']['credit'], 0.001, '343 D = DPH.');
        // Zúčtování zálohy v přesné výši přijaté zálohy (605).
        self::assertEqualsWithDelta(605.00, $byAcc['324']['debit'], 0.001, '324 MD = zúčtování přijaté zálohy.');
        self::assertEqualsWithDelta(605.00, $byAcc['311']['credit'], 0.001, '311 D = snížení pohledávky o zálohu.');
        $this->assertBalancedEntry($entryId);
    }

    public function testFinalInvoiceCapsOverpaidAdvanceSettlementAtInvoiceTotal(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $proforma = $this->saleWithItem('PRO-M37-A', $client, 1500.00, 0.00, 'proforma');
        $this->payProformaViaBank($proforma, 1500.00);
        $final = $this->saleWithItem('FV-M37-A', $client, 500.00, 105.00, 'invoice', $proforma);

        $lines = $this->posting->buildFromInvoice($this->supplierId, $final);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $final, $lines, [
            'entry_date' => self::YEAR . '-06-20',
        ]);
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(605.00, $byAcc['324']['debit'], 0.001);
        self::assertEqualsWithDelta(605.00, $byAcc['311']['credit'], 0.001);
        $this->assertBalancedEntry($entryId);
    }

    // (d) — přijatá strana: platba zálohové PF 314/221, finální PF zúčtování 321/314.
    public function testPaidAdvanceCycle314(): void
    {
        $vendor = $this->client('Dodavatel s.r.o.');
        // Zálohová PF (document_kind=advance) 605 Kč, uhrazená z banky → 314/221.
        $advPf = $this->purchaseInvoice('ZPF-D', $vendor, 605.00, 'advance');
        $tx = $this->payAdvancePfViaBank($advPf, 605.00);
        $payByAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        self::assertEqualsWithDelta(605.00, $payByAcc['314']['debit'], 0.001, '314 MD = poskytnutá záloha.');
        self::assertEqualsWithDelta(605.00, $payByAcc['221']['credit'], 0.001, '221 D = úbytek z banky.');

        // Finální PF navázaná na zálohu (advance_purchase_invoice_id) → normální řádky + zúčtování 321/314.
        $final = $this->purchaseWithItem('PF-D', $vendor, 1000.00, 210.00, 'invoice', $advPf);
        $lines   = $this->posting->buildFromPurchaseInvoice($this->supplierId, $final);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $final, $lines, ['entry_date' => self::YEAR . '-06-20']);
        $byAcc   = $this->linesByAccountCode($entryId);

        self::assertEqualsWithDelta(1000.00, $byAcc['518']['debit'], 0.001, '518 MD = náklad.');
        self::assertEqualsWithDelta(210.00, $byAcc['343.100']['debit'], 0.001, '343 MD = odpočet DPH.');
        self::assertEqualsWithDelta(1210.00, $byAcc['321']['credit'], 0.001, '321 D = závazek.');
        self::assertEqualsWithDelta(605.00, $byAcc['321']['debit'], 0.001, '321 MD = zúčtování zaplacené zálohy.');
        self::assertEqualsWithDelta(605.00, $byAcc['314']['credit'], 0.001, '314 D = zúčtování poskytnuté zálohy.');
        $this->assertBalancedEntry($entryId);
    }

    // (d1) — cizoměnová záloha zaplacená z eurového účtu: alokace payment_matches je v EUR,
    //        zúčtování 321/314 musí být v Kč kurzem zálohy (zrcadlo vydané strany), ne 121 „korun".
    public function testForeignCurrencyAdvanceSettlementIsConvertedToCzk(): void
    {
        $eur = $this->currencyRow($this->supplierId, 'EUR');
        $vendor = $this->client('Dodavatel EUR záloha');
        $advance = $this->purchaseInvoice('ZPF-FX', $vendor, 121.00, 'advance');
        $final = $this->purchaseWithItem('PF-FX', $vendor, 100.00, 21.00, 'invoice', $advance);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET currency_id = ?, exchange_rate = 25 WHERE id IN (?, ?)')
            ->execute([$eur, $advance, $final]);
        $tx = $this->transaction($this->statement(), -121.00, ['currency' => 'EUR', 'match_status' => 'manual']);
        $this->paymentMatch($tx, $advance, 121.00);
        $this->postPredpis('bank', $tx, '314', '221', 3025.00);

        $byAcc = $this->linesByAccountCode($this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $final,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $final),
            ['entry_date' => self::YEAR . '-06-20'],
        ));
        self::assertEqualsWithDelta(3025.00, $byAcc['314']['credit'] ?? 0.0, 0.001, '314 D = 121 EUR × 25.');
        self::assertEqualsWithDelta(3025.00, $byAcc['321']['debit'] ?? 0.0, 0.001);
    }

    // (d2) — poskytnutá záloha vedená přímo na závazku (předkontace 321/321): zúčtování by
    //        byl pár 321 MD / 321 D, který se vyruší a jen zdvojí obrat → nezapisuje se.
    public function testPurchaseAdvanceOnPayableHasNoSelfCancellingSettlementPair(): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
             VALUES (?, ?, "Záloha na 321", ?, ?, 0, 1)
             ON DUPLICATE KEY UPDATE debit_account_code = VALUES(debit_account_code), credit_account_code = VALUES(credit_account_code)'
        );
        $stmt->execute([$this->supplierId, 'advance.paid.payment', '321', '221']);
        $stmt->execute([$this->supplierId, 'advance.paid.settlement', '321', '321']);

        $vendor = $this->client('Dodavatel záloha na 321');
        $advPf = $this->purchaseInvoice('ZPF-D2', $vendor, 1210.00, 'advance');
        $tx = $this->payAdvancePfViaBank($advPf, 1210.00);
        $final = $this->purchaseWithItem('PF-D2', $vendor, 1000.00, 210.00, 'invoice', $advPf);
        $entryId = $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $final,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $final),
            ['entry_date' => self::YEAR . '-06-20'],
        );
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(1210.00, $byAcc['321']['credit'] ?? 0.0, 0.001, 'Závazek z faktury.');
        self::assertSame(0.0, $byAcc['321']['debit'] ?? 0.0, 'Žádný pár 321 MD / 321 D.');
        $sum = $this->accountSums([$this->entryIdForBankTx($tx), $entryId]);
        self::assertSame(0, self::cents($sum['321'] ?? 0), '321 po vyúčtování na nule.');
    }

    public function testSaldo324ShowsOnlyUnsettledReceivedAdvance(): void
    {
        $client = $this->client('Odběratel saldo 324');
        $proforma = $this->saleWithItem('PRO-SALDO-324', $client, 1000.00, 210.00, 'proforma');
        $baseline = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-06-16', '324')['accounts'][0];
        $baselineFinal = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '324')['accounts'][0];
        $this->payProformaViaBank($proforma, 1210.00);

        $open = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-06-16', '324');
        $openAcc = $open['accounts'][0];
        self::assertSame(self::cents($baseline['gl_balance']) + 121000, self::cents($openAcc['gl_balance']));
        self::assertSame(self::cents($baseline['open_items_total']) + 121000, self::cents($openAcc['open_items_total']));
        self::assertSame(self::cents($baseline['difference']), self::cents($openAcc['difference']));
        self::assertNotNull($this->saldoItem($openAcc, 'invoice', $proforma));

        $final = $this->saleWithItem('FV-SALDO-324', $client, 1000.00, 210.00, 'invoice', $proforma);
        $entryId = $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $final,
            $this->posting->buildFromInvoice($this->supplierId, $final),
            ['entry_date' => self::YEAR . '-06-20'],
        );
        $this->assertBalancedEntry($entryId);

        $settled = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '324');
        $settledAcc = $settled['accounts'][0];
        self::assertSame(self::cents($baselineFinal['gl_balance']), self::cents($settledAcc['gl_balance']));
        self::assertSame(self::cents($baselineFinal['open_items_total']), self::cents($settledAcc['open_items_total']));
        self::assertNull($this->saldoItem($settledAcc, 'invoice', $proforma));
    }

    public function testSaldo314ShowsOnlyUnsettledPaidAdvance(): void
    {
        $vendor = $this->client('Dodavatel saldo 314');
        $advance = $this->purchaseInvoice('ZPF-SALDO-314', $vendor, 605.00, 'advance');
        $baseline = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-06-16', '314')['accounts'][0];
        $baselineFinal = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '314')['accounts'][0];
        $this->payAdvancePfViaBank($advance, 605.00);

        $open = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-06-16', '314');
        $openAcc = $open['accounts'][0];
        self::assertSame(self::cents($baseline['gl_balance']) + 60500, self::cents($openAcc['gl_balance']));
        self::assertSame(self::cents($baseline['open_items_total']) + 60500, self::cents($openAcc['open_items_total']));
        self::assertSame(self::cents($baseline['difference']), self::cents($openAcc['difference']));
        self::assertNotNull($this->saldoItem($openAcc, 'purchase_invoice', $advance));

        $final = $this->purchaseWithItem('PF-SALDO-314', $vendor, 500.00, 105.00, 'invoice', $advance);
        $entryId = $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $final,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $final),
            ['entry_date' => self::YEAR . '-06-20'],
        );
        $this->assertBalancedEntry($entryId);

        $settled = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '314');
        $settledAcc = $settled['accounts'][0];
        self::assertSame(self::cents($baselineFinal['gl_balance']), self::cents($settledAcc['gl_balance']));
        self::assertSame(self::cents($baselineFinal['open_items_total']), self::cents($settledAcc['open_items_total']));
        self::assertNull($this->saldoItem($settledAcc, 'purchase_invoice', $advance));
    }

    public function testPurchaseFinalCapsOverpaidAdvanceSettlementAtInvoiceTotal(): void
    {
        $vendor = $this->client('Dodavatel s.r.o.');
        $advance = $this->purchaseInvoice('ZPF-M37-B', $vendor, 1500.00, 'advance');
        $this->payAdvancePfViaBank($advance, 1500.00);
        $final = $this->purchaseWithItem('PF-M37-B', $vendor, 500.00, 105.00, 'invoice', $advance);

        $lines = $this->posting->buildFromPurchaseInvoice($this->supplierId, $final);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $final, $lines, [
            'entry_date' => self::YEAR . '-06-20',
        ]);
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(605.00, $byAcc['321']['debit'], 0.001);
        self::assertEqualsWithDelta(605.00, $byAcc['314']['credit'], 0.001);
        $this->assertBalancedEntry($entryId);
    }

    // (e) — běžná faktura bez zálohy = beze změny (žádné 324/314).
    public function testPlainInvoiceWithoutAdvanceUnchanged(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $inv = $this->saleWithItem('FV-E', $client, 1000.00, 210.00, 'invoice');

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $inv);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $inv, $lines, ['entry_date' => self::YEAR . '-06-20']);
        $byAcc   = $this->linesByAccountCode($entryId);

        self::assertEqualsWithDelta(1210.00, $byAcc['311']['debit'], 0.001);
        self::assertEqualsWithDelta(1000.00, $byAcc['602']['credit'], 0.001);
        self::assertEqualsWithDelta(210.00, $byAcc['343.200']['credit'], 0.001);
        self::assertArrayNotHasKey('324', $byAcc, 'Faktura bez proformy nezúčtovává zálohu.');
        self::assertSame(0.0, $byAcc['311']['credit'] ?? 0.0, 'Žádné zúčtování zálohy proti 311.');
        $this->assertBalancedEntry($entryId);
    }

    // (f1) — DDKP + vyúčtování: DDKP odčerpal z 324 daň, vyúčtování s odpočtem § 37a
    //        (základ i daň DDKP odečtené) přizná výnos za celý základ a zúčtuje zbytek
    //        zálohy. 324 i 311 skončí na nule, 343 nese daň jen jednou (z DDKP).
    public function testFinalInvoiceWithTaxDocumentSettlesRemainingAdvance(): void
    {
        $client   = $this->client('Odběratel s.r.o.');
        $proforma = $this->saleWithItem('PRO-F1', $client, 1000.00, 210.00, 'proforma');
        $tx = $this->payProformaViaBank($proforma, 1210.00);
        $ddkp = $this->saleWithItem('DDKP-F1', $client, 1000.00, 210.00, 'tax_document', $proforma, 1);
        $ddkpEntry = $this->postInvoiceEntry($ddkp, self::YEAR . '-06-16');
        $final = $this->finalWithDeduction('FV-F1', $client, $proforma, 1000.00, 210.00, 1000.00, 210.00);

        $entryId = $this->postInvoiceEntry($final, self::YEAR . '-06-20');
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(1000.00, $byAcc['311']['debit'] ?? 0.0, 0.001, '311 MD = základ pokrytý DDKP.');
        self::assertEqualsWithDelta(1000.00, $byAcc['602']['credit'] ?? 0.0, 0.001, '602 D = výnos za celý základ.');
        self::assertArrayNotHasKey('343.200', $byAcc, 'Daň přiznal DDKP, vyúčtování ji znovu neúčtuje.');
        self::assertEqualsWithDelta(1000.00, $byAcc['324']['debit'] ?? 0.0, 0.001, '324 MD = zbytek zálohy po dani z DDKP.');
        self::assertEqualsWithDelta(1000.00, $byAcc['311']['credit'] ?? 0.0, 0.001, '311 D = vyrovnání předpisu zálohou.');
        $this->assertBalancedEntry($entryId);

        $sum = $this->accountSums([$this->entryIdForBankTx($tx), $ddkpEntry, $entryId]);
        self::assertSame(0, self::cents($sum['324'] ?? 0), '324 po vyúčtování na nule.');
        self::assertSame(0, self::cents($sum['311'] ?? 0), '311 po vyúčtování na nule.');
        self::assertSame(-21000, self::cents($sum['343.200'] ?? 0), '343 nese daň 210 právě jednou.');
    }

    // (f1a) — částečná úhrada s DDKP a vyúčtování na celou zakázku: zbytek zůstane
    //         pohledávkou, 324 se vyrovná.
    public function testFinalInvoiceWithPartialTaxDocumentLeavesRemainderOn311(): void
    {
        $client   = $this->client('Odběratel s.r.o.');
        $proforma = $this->saleWithItem('PRO-F1A', $client, 1000.00, 210.00, 'proforma');
        $tx = $this->payProformaViaBank($proforma, 605.00);
        $ddkp = $this->saleWithItem('DDKP-F1A', $client, 500.00, 105.00, 'tax_document', $proforma, 1);
        $ddkpEntry = $this->postInvoiceEntry($ddkp, self::YEAR . '-06-16');
        $final = $this->finalWithDeduction('FV-F1A', $client, $proforma, 1000.00, 210.00, 500.00, 105.00);

        $entryId = $this->postInvoiceEntry($final, self::YEAR . '-06-20');
        $this->assertBalancedEntry($entryId);
        $sum = $this->accountSums([$this->entryIdForBankTx($tx), $ddkpEntry, $entryId]);
        self::assertSame(0, self::cents($sum['324'] ?? 0), '324 po vyúčtování na nule.');
        self::assertSame(60500, self::cents($sum['311'] ?? 0), 'Na 311 zůstává nedoplatek 605.');
        self::assertSame(-100000, self::cents($sum['602'] ?? 0), 'Výnos za celou zakázku.');
        self::assertSame(-21000, self::cents($sum['343.200'] ?? 0), 'Daň 105 z DDKP + 105 z vyúčtování.');
    }

    // (f1e) — vyúčtování s DDKP, ale bez odpočtového řádku (smazaný v konceptu / ručně
    //         navázané vyúčtování): výnos by se přičetl podruhé → hlasitá chyba. Odpočet
    //         nad částku DDKP taky. Menší odpočet je postupné čerpání (f4).
    public function testFinalWithoutDeductionRowsThrowsAmbiguous(): void
    {
        $client   = $this->client('Odběratel s.r.o.');
        $proforma = $this->saleWithItem('PRO-F1E', $client, 1000.00, 210.00, 'proforma');
        $this->payProformaViaBank($proforma, 1210.00);
        $ddkp = $this->saleWithItem('DDKP-F1E', $client, 1000.00, 210.00, 'tax_document', $proforma, 1);
        $this->postInvoiceEntry($ddkp, self::YEAR . '-06-16');

        foreach ([
            'bez odpočtu' => $this->saleWithItem('FV-F1E', $client, 1000.00, 210.00, 'invoice', $proforma),
            'odpočet nad DDKP' => $this->finalWithDeduction('FV-F1E2', $client, $proforma, 2000.00, 420.00, 1500.00, 315.00),
        ] as $case => $final) {
            try {
                $this->posting->buildFromInvoice($this->supplierId, $final);
                self::fail("{$case}: nesoulad odpočtu s DDKP má vyhodit advance_settlement_ambiguous.");
            } catch (PostingException $e) {
                self::assertSame('advance_settlement_ambiguous', $e->errorCode, $case);
            }
            $this->db->pdo()->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?")->execute([$final]);
        }
    }

    // (f4) — postupné čerpání zálohy fakturami za milníky: každá zúčtuje 324/311 jen základ,
    //        který sama odečetla. Po poslední je 324 na nule, 343 nese daň z celého plnění
    //        právě jednou a na 311 zůstává jen doplatek milníků.
    public function testMilestoneInvoicesDrawAdvanceStepByStep(): void
    {
        $client   = $this->client('Odběratel s.r.o.');
        $proforma = $this->saleWithItem('PRO-F4', $client, 1000.00, 210.00, 'proforma');
        $tx = $this->payProformaViaBank($proforma, 1210.00);
        $ddkp = $this->saleWithItem('DDKP-F4', $client, 1000.00, 210.00, 'tax_document', $proforma, 1);
        $ddkpEntry = $this->postInvoiceEntry($ddkp, self::YEAR . '-06-16');

        $first = $this->finalWithDeduction('FV-F4a', $client, $proforma, 1000.00, 210.00, 300.00, 63.00);
        $firstEntry = $this->postInvoiceEntry($first, self::YEAR . '-06-20');
        $byAcc = $this->linesByAccountCode($firstEntry);
        self::assertEqualsWithDelta(1147.00, $byAcc['311']['debit'] ?? 0.0, 0.001, '311 MD = faktura 847 + vrácený základ odpočtu 300.');
        self::assertEqualsWithDelta(1000.00, $byAcc['602']['credit'] ?? 0.0, 0.001, '602 D = výnos za celý milník.');
        self::assertEqualsWithDelta(147.00, $byAcc['343.200']['credit'] ?? 0.0, 0.001, '343 D = daň milníku po odpočtu daně zálohy.');
        self::assertEqualsWithDelta(300.00, $byAcc['324']['debit'] ?? 0.0, 0.001, '324 MD = jen základ, který faktura odečetla.');
        self::assertEqualsWithDelta(300.00, $byAcc['311']['credit'] ?? 0.0, 0.001, '311 D = vyrovnání odečtené části zálohy.');
        $this->assertBalancedEntry($firstEntry);

        $afterFirst = $this->accountSums([$this->entryIdForBankTx($tx), $ddkpEntry, $firstEntry]);
        self::assertSame(-70000, self::cents($afterFirst['324'] ?? 0), 'Na 324 zbývá nevyčerpaný základ zálohy 700.');

        $second = $this->finalWithDeduction('FV-F4b', $client, $proforma, 2000.00, 420.00, 700.00, 147.00);
        $secondEntry = $this->postInvoiceEntry($second, self::YEAR . '-07-20');
        $this->assertBalancedEntry($secondEntry);

        $sum = $this->accountSums([$this->entryIdForBankTx($tx), $ddkpEntry, $firstEntry, $secondEntry]);
        self::assertSame(0, self::cents($sum['324'] ?? 0), '324 po vyčerpání zálohy na nule.');
        self::assertSame(242000, self::cents($sum['311'] ?? 0), 'Na 311 zůstává doplatek milníků 847 + 1573.');
        self::assertSame(-300000, self::cents($sum['602'] ?? 0), 'Výnos za oba milníky.');
        self::assertSame(-63000, self::cents($sum['343.200'] ?? 0), 'Daň 21 % z 3000 právě jednou.');
    }

    // (f4a) — postupné čerpání nad částku DDKP: faktura, která by zálohu přečerpala → hlasitá chyba.
    public function testMilestoneInvoicesOverdrawingAdvanceThrowAmbiguous(): void
    {
        $client   = $this->client('Odběratel s.r.o.');
        $proforma = $this->saleWithItem('PRO-F4A', $client, 1000.00, 210.00, 'proforma');
        $this->payProformaViaBank($proforma, 1210.00);
        $ddkp = $this->saleWithItem('DDKP-F4A', $client, 1000.00, 210.00, 'tax_document', $proforma, 1);
        $this->postInvoiceEntry($ddkp, self::YEAR . '-06-16');

        $first = $this->finalWithDeduction('FV-F4Aa', $client, $proforma, 1000.00, 210.00, 700.00, 147.00);
        $this->postInvoiceEntry($first, self::YEAR . '-06-20');
        $second = $this->finalWithDeduction('FV-F4Ab', $client, $proforma, 1000.00, 210.00, 700.00, 147.00);

        try {
            $this->posting->buildFromInvoice($this->supplierId, $second);
            self::fail('Odpočty nad částku DDKP mají vyhodit advance_settlement_ambiguous.');
        } catch (PostingException $e) {
            self::assertSame('advance_settlement_ambiguous', $e->errorCode);
        }
    }

    // (f4b) — postupné čerpání bez zaúčtované úhrady zálohy: zúčtování by čerpalo 324,
    //         na kterém nic neleží → hlasitá chyba.
    public function testMilestoneInvoiceWithoutPostedAdvancePaymentThrowsAmbiguous(): void
    {
        $client   = $this->client('Odběratel s.r.o.');
        $proforma = $this->saleWithItem('PRO-F4B', $client, 1000.00, 210.00, 'proforma');
        $ddkp = $this->saleWithItem('DDKP-F4B', $client, 1000.00, 210.00, 'tax_document', $proforma, 1);
        $this->postInvoiceEntry($ddkp, self::YEAR . '-06-16');
        $final = $this->finalWithDeduction('FV-F4B', $client, $proforma, 1000.00, 210.00, 300.00, 63.00);

        try {
            $this->posting->buildFromInvoice($this->supplierId, $final);
            self::fail('Postupné čerpání bez zaúčtované úhrady zálohy má vyhodit advance_settlement_ambiguous.');
        } catch (PostingException $e) {
            self::assertSame('advance_settlement_ambiguous', $e->errorCode);
        }
    }

    // (f4c) — služba čerpání: rozdělí odpočet podle DDKP, poslední čerpání bere přesný zbytek
    //         a nad zbytek zálohy čerpat nedovolí.
    public function testPartialDrawdownServiceSplitsAdvanceAcrossDrafts(): void
    {
        $client   = $this->client('Odběratel s.r.o.');
        $proforma = $this->saleWithItem('PRO-F4C', $client, 1000.00, 210.00, 'proforma');
        $this->saleWithItem('DDKP-F4C', $client, 1000.00, 210.00, 'tax_document', $proforma, 1);
        $drawdown = $this->container->get(\MyInvoice\Service\Invoice\AdvancePartialDrawdown::class);

        $first = $this->draftWithItem($client, 1000.00);
        $res = $drawdown->apply($this->supplierId, $first, $proforma, 363.00);
        self::assertEqualsWithDelta(847.00, $res['remaining'], 0.001);
        $line = $this->deductionLine($first);
        self::assertEqualsWithDelta(-300.00, $line['total_without_vat'], 0.001, 'Základ odpočtu 363 / 1,21.');
        self::assertEqualsWithDelta(-63.00, $line['total_vat'], 0.001);
        self::assertSame($proforma, $this->parentOf($first));

        // Opakované čerpání téhož konceptu odpočet nahradí, nepřidá.
        $drawdown->apply($this->supplierId, $first, $proforma, 363.00);
        self::assertSame(1, $this->deductionCount($first));

        $second = $this->draftWithItem($client, 2000.00);
        try {
            $drawdown->apply($this->supplierId, $second, $proforma, 900.00);
            self::fail('Čerpání nad zbytek zálohy má selhat.');
        } catch (\DomainException) {
        }
        $drawdown->apply($this->supplierId, $second, $proforma, 847.00);
        $rest = $this->deductionLine($second);
        self::assertEqualsWithDelta(-700.00, $rest['total_without_vat'], 0.001, 'Poslední čerpání = přesný zbytek základu DDKP.');
        self::assertEqualsWithDelta(-147.00, $rest['total_vat'], 0.001);

        $drawdown->remove($this->supplierId, $second);
        self::assertSame(0, $this->deductionCount($second));
        self::assertNull($this->parentOf($second));
    }

    // (f1f) — prodej drobného majetku přes proformu s DDKP: odpočtový řádek bez karty
    //         nesmí stáhnout výnos na 602, celý základ patří na 642.
    public function testAssetSaleThroughProformaWithTaxDocumentKeepsAssetRevenue(): void
    {
        $client = $this->client('Kupec majetku s.r.o.');
        $card = $this->smallAssetCard('Stroj', 30000.00);
        $proforma = $this->saleWithItem('PRO-F1F', $client, 12000.00, 2520.00, 'proforma');
        $this->payProformaViaBank($proforma, 14520.00);
        $ddkp = $this->saleWithItem('DDKP-F1F', $client, 12000.00, 2520.00, 'tax_document', $proforma, 1);
        $this->postInvoiceEntry($ddkp, self::YEAR . '-06-16');

        foreach (['plně předplaceno' => [12000.00, 2520.00], 's doplatkem' => [20000.00, 4200.00]] as $case => [$base, $vat]) {
            $final = $this->finalWithDeduction('FV-F1F-' . (int) $base, $client, $proforma, $base, $vat, 12000.00, 2520.00);
            $this->db->pdo()->prepare('UPDATE invoice_items SET small_asset_id = ? WHERE invoice_id = ? AND total_with_vat > 0')
                ->execute([$card, $final]);
            $byAcc = $this->linesByAccountCode($this->postInvoiceEntry($final, self::YEAR . '-06-20'));
            self::assertEqualsWithDelta($base, $byAcc['642']['credit'] ?? 0.0, 0.001, "{$case}: celý základ na 642.");
            self::assertArrayNotHasKey('602', $byAcc, "{$case}: odpočet nesmí jít na 602.");
            $this->db->pdo()->prepare("DELETE FROM journal_entries WHERE supplier_id = ? AND source_type = 'invoice' AND source_id = ?")
                ->execute([$this->supplierId, $final]);
            $this->db->pdo()->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?")->execute([$final]);
        }
    }

    // (f1g) — záloha s DDKP, ale úhrada zálohy není zaúčtovaná bankou/pokladnou: zúčtování
    //         se nesmí potichu vynechat (311 by nesl fantomovou pohledávku) → hlasitá chyba.
    public function testUnpostedAdvancePaymentWithTaxDocumentThrows(): void
    {
        $client   = $this->client('Odběratel s.r.o.');
        $proforma = $this->saleWithItem('PRO-F1G', $client, 1000.00, 210.00, 'proforma');
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source)
             VALUES (?, ?, ?, 1210.00, "CZK", "manual")'
        )->execute([$this->supplierId, $proforma, self::YEAR . '-06-15']);
        $ddkp = $this->saleWithItem('DDKP-F1G', $client, 1000.00, 210.00, 'tax_document', $proforma, 1);
        $this->postInvoiceEntry($ddkp, self::YEAR . '-06-16');
        $final = $this->finalWithDeduction('FV-F1G', $client, $proforma, 1000.00, 210.00, 1000.00, 210.00);

        try {
            $this->posting->buildFromInvoice($this->supplierId, $final);
            self::fail('Nezaúčtovaná úhrada zálohy s DDKP má vyhodit advance_settlement_ambiguous.');
        } catch (PostingException $e) {
            self::assertSame('advance_settlement_ambiguous', $e->errorCode);
        }
    }

    // (f1h) — vyúčtování MENŠÍ než záloha s DDKP: odpočet vrací víc daně, než nese plnění.
    //         Daň jde na opačnou stranu, na 324 zůstane nevyčerpaná záloha.
    public function testFinalSmallerThanAdvanceWithTaxDocumentBalances(): void
    {
        $client   = $this->client('Odběratel s.r.o.');
        $proforma = $this->saleWithItem('PRO-F1H', $client, 12000.00, 2520.00, 'proforma');
        $tx = $this->payProformaViaBank($proforma, 14520.00);
        $ddkp = $this->saleWithItem('DDKP-F1H', $client, 12000.00, 2520.00, 'tax_document', $proforma, 1);
        $ddkpEntry = $this->postInvoiceEntry($ddkp, self::YEAR . '-06-16');
        $final = $this->finalWithDeduction('FV-F1H', $client, $proforma, 10000.00, 2100.00, 12000.00, 2520.00);

        $entryId = $this->postInvoiceEntry($final, self::YEAR . '-06-20');
        $this->assertBalancedEntry($entryId);
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(9580.00, $byAcc['311']['debit'] ?? 0.0, 0.001);
        self::assertEqualsWithDelta(420.00, $byAcc['343.200']['debit'] ?? 0.0, 0.001, 'Přebytek daně z odpočtu → 343 MD.');
        self::assertEqualsWithDelta(10000.00, $byAcc['602']['credit'] ?? 0.0, 0.001);
        self::assertEqualsWithDelta(9580.00, $byAcc['324']['debit'] ?? 0.0, 0.001);
        self::assertEqualsWithDelta(9580.00, $byAcc['311']['credit'] ?? 0.0, 0.001);
        $sum = $this->accountSums([$this->entryIdForBankTx($tx), $ddkpEntry, $entryId]);
        self::assertSame(-242000, self::cents($sum['324'] ?? 0), 'Na 324 zůstává nevyčerpaná záloha 2 420.');
        self::assertSame(0, self::cents($sum['311'] ?? 0));
    }

    // (f1i) — DDKP jen s daní OSS: samostatně se neúčtuje → srozumitelná chyba s radou,
    //         ne věčné „nezaúčtovaný DDKP".
    public function testOssOnlyTaxDocumentGivesActionableError(): void
    {
        if (!$this->db->hasColumn('invoice_items', 'oss_applicable')) {
            self::markTestSkipped('Instance bez OSS schématu.');
        }
        $client   = $this->client('Odběratel OSS');
        $proforma = $this->saleWithItem('PRO-F1I', $client, 1000.00, 210.00, 'proforma');
        $this->payProformaViaBank($proforma, 1210.00);
        $ddkp = $this->saleWithItem('DDKP-F1I', $client, 1000.00, 210.00, 'tax_document', $proforma, 1);
        $this->db->pdo()->prepare('UPDATE invoice_items SET oss_applicable = 1 WHERE invoice_id = ?')->execute([$ddkp]);
        $final = $this->finalWithDeduction('FV-F1I', $client, $proforma, 1000.00, 210.00, 1000.00, 210.00);

        try {
            $this->posting->buildFromInvoice($this->supplierId, $final);
            self::fail('OSS DDKP má vyhodit advance_tax_document_oss.');
        } catch (PostingException $e) {
            self::assertSame('advance_tax_document_oss', $e->errorCode);
        }
    }

    // (f1j) — cizoměnové vyúčtování plně předplacené přes DDKP: předpis na 311 nese
    //         cizoměnovou stopu vráceného základu, ne nulu z hlavičky.
    public function testForeignCurrencyFinalWithTaxDocumentKeepsFxTrace(): void
    {
        $eur = $this->currencyRow($this->supplierId, 'EUR');
        $client = $this->client('Odběratel EUR');
        $proforma = $this->saleWithItem('PRO-F1J', $client, 100.00, 21.00, 'proforma');
        $ddkp = $this->saleWithItem('DDKP-F1J', $client, 100.00, 21.00, 'tax_document', $proforma, 1);
        $final = $this->finalWithDeduction('FV-F1J', $client, $proforma, 100.00, 21.00, 100.00, 21.00);
        $this->db->pdo()->prepare('UPDATE invoices SET currency_id = ?, exchange_rate = 25 WHERE id IN (?, ?, ?)')
            ->execute([$eur, $proforma, $ddkp, $final]);
        // Úhrada zálohy v EUR zaúčtovaná bankou (121 EUR × 25 = 3 025 Kč).
        $tx = $this->transaction($this->statement(), 3025.00);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id)
             VALUES (?, ?, ?, 121.00, "EUR", "bank", ?)'
        )->execute([$this->supplierId, $proforma, self::YEAR . '-06-15', $tx]);
        $this->postPredpis('bank', $tx, '221', '324', 3025.00);
        $this->postInvoiceEntry($ddkp, self::YEAR . '-06-16');

        $entryId = $this->postInvoiceEntry($final, self::YEAR . '-06-20');
        $this->assertBalancedEntry($entryId);
        $receivable = null;
        foreach ($this->journal->find($entryId, $this->supplierId)['lines'] as $l) {
            if ($this->accountCode((int) $l['account_id']) === '311' && $l['side'] === 'debit') {
                $receivable = $l;
            }
        }
        self::assertNotNull($receivable);
        self::assertEqualsWithDelta(2500.00, (float) $receivable['amount'], 0.001, '311 MD = základ 100 EUR × 25.');
        self::assertSame('EUR', $receivable['currency_code']);
        self::assertEqualsWithDelta(100.00, (float) $receivable['amount_foreign'], 0.001, 'Cizoměnová stopa = vrácený základ, ne 0.');
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(2500.00, $byAcc['324']['debit'] ?? 0.0, 0.001, 'Zúčtování = 3 025 − daň DDKP 525.');
    }

    // (f1d) — nezaúčtovaný DDKP: vyúčtování s ním počítá, ale jeho daň na 324/343 ještě
    //         není → hlasitá chyba místo přečerpání zálohy.
    public function testFinalInvoiceWithUnpostedTaxDocumentThrows(): void
    {
        $client   = $this->client('Odběratel s.r.o.');
        $proforma = $this->saleWithItem('PRO-F1D', $client, 1000.00, 210.00, 'proforma');
        $this->payProformaViaBank($proforma, 1210.00);
        $this->saleWithItem('DDKP-F1D', $client, 1000.00, 210.00, 'tax_document', $proforma, 1);
        $final = $this->finalWithDeduction('FV-F1D', $client, $proforma, 1000.00, 210.00, 1000.00, 210.00);

        try {
            $this->posting->buildFromInvoice($this->supplierId, $final);
            self::fail('Nezaúčtovaný DDKP má vyhodit advance_tax_document_unposted.');
        } catch (PostingException $e) {
            self::assertSame('advance_tax_document_unposted', $e->errorCode);
        }
    }

    // (f1b) — DRAFT DDKP blokovat NESMÍ: nemá zápis v deníku, z 324 nic neodčerpal
    //         a nevyrobí ani odpočtové řádky §37a. Blokace by shodila zaúčtování
    //         zcela legitimní vyúčtovací faktury.
    public function testDraftTaxDocumentDoesNotBlockSettlement(): void
    {
        $client   = $this->client('Odběratel s.r.o.');
        $proforma = $this->saleWithItem('PRO-F1B', $client, 1000.00, 210.00, 'proforma');
        $this->payProformaViaBank($proforma, 1210.00);
        $ddkp = $this->saleWithItem('DDKP-F1B', $client, 1000.00, 210.00, 'tax_document', $proforma, 1);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'draft' WHERE id = ?")->execute([$ddkp]);
        $final = $this->saleWithItem('FV-F1B', $client, 1000.00, 210.00, 'invoice', $proforma);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $final);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $final, $lines, ['entry_date' => self::YEAR . '-06-20']);
        $byAcc   = $this->linesByAccountCode($entryId);

        self::assertEqualsWithDelta(1210.00, $byAcc['324']['debit'] ?? 0.0, 0.001, 'Zúčtování zálohy má proběhnout normálně.');
        $this->assertBalancedEntry($entryId);
    }

    // (f1c) — DDKP navázaný JEN přes invoice_payments (historicky rozpojený doklad,
    //         parent_invoice_id NULL) se počítat MUSÍ: z 324 daň už odčerpal, takže
    //         zúčtování zálohy na plnou výši by 324 přečerpalo.
    public function testTaxDocumentLinkedOnlyViaPaymentReducesSettlement(): void
    {
        $client   = $this->client('Odběratel s.r.o.');
        $proforma = $this->saleWithItem('PRO-F1C', $client, 1000.00, 210.00, 'proforma');
        $txId     = $this->payProformaViaBank($proforma, 1210.00);
        // DDKP BEZ parent_invoice_id — vazba existuje jen na platebním řádku.
        $ddkp = $this->saleWithItem('DDKP-F1C', $client, 1000.00, 210.00, 'tax_document', null, 1);
        $this->db->pdo()->prepare(
            'UPDATE invoice_payments SET tax_document_invoice_id = ? WHERE invoice_id = ? AND bank_transaction_id = ?'
        )->execute([$ddkp, $proforma, $txId]);
        $this->postInvoiceEntry($ddkp, self::YEAR . '-06-16');
        $final = $this->finalWithDeduction('FV-F1C', $client, $proforma, 1000.00, 210.00, 1000.00, 210.00);

        $byAcc = $this->linesByAccountCode($this->postInvoiceEntry($final, self::YEAR . '-06-20'));
        self::assertEqualsWithDelta(1000.00, $byAcc['324']['debit'] ?? 0.0, 0.001, 'Zúčtuje se jen zbytek zálohy po dani DDKP.');
    }

    // (f2) — víc než jedna vyúčtovací faktura na proformu → hlasitá chyba.
    public function testFinalInvoiceWithMultipleFinalsThrowsAmbiguous(): void
    {
        $client   = $this->client('Odběratel s.r.o.');
        $proforma = $this->saleWithItem('PRO-F2', $client, 1000.00, 210.00, 'proforma');
        $this->payProformaViaBank($proforma, 1210.00);
        $final1 = $this->saleWithItem('FV-F2a', $client, 1000.00, 210.00, 'invoice', $proforma);
        $this->saleWithItem('FV-F2b', $client, 1000.00, 210.00, 'invoice', $proforma);

        try {
            $this->posting->buildFromInvoice($this->supplierId, $final1);
            self::fail('Dvě vyúčtovací faktury na proformu mají vyhodit advance_settlement_ambiguous.');
        } catch (PostingException $e) {
            self::assertSame('advance_settlement_ambiguous', $e->errorCode);
        }
    }

    // (g) — sloučená úhrada na VÍC proforem: settlement každé vyúčtovací faktury čerpá jen
    //       alokaci své proformy, ne cizí 324 řádek téhož zápisu (regrese Nález 1 revize B5).
    public function testMergedPaymentSettlesEachProformaByItsOwnAllocation(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $pro1 = $this->saleWithItem('PRO-G1', $client, 1000.00, 210.00, 'proforma'); // 1210
        $pro2 = $this->saleWithItem('PRO-G2', $client, 500.00, 105.00, 'proforma');  // 605

        // Jedna příchozí platba 1815 sloučeně spárovaná na obě proformy (dvě alokace).
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1815.00, ['match_status' => 'manual']);
        $this->invoicePayment($pro1, $tx, 1210.00);
        $this->invoicePayment($pro2, $tx, 605.00);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'Sloučené inkaso se má zaúčtovat: ' . ($res['reason'] ?? ''));

        // Jeden zápis nese dva 324 řádky (1210 + 605 = 1815).
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        self::assertEqualsWithDelta(1815.00, $byAcc['324']['credit'], 0.001, '324 D = obě přijaté zálohy v jednom zápisu.');

        // Vyúčtování PRO-1 → zúčtování zálohy čerpá JEN jeho alokaci 1210 (ne 1815).
        $final1 = $this->saleWithItem('FV-G1', $client, 1000.00, 210.00, 'invoice', $pro1);
        $lines1 = $this->posting->buildFromInvoice($this->supplierId, $final1);
        $eid1   = $this->posting->postDocument($this->supplierId, 'invoice', $final1, $lines1, ['entry_date' => self::YEAR . '-06-20']);
        $acc1   = $this->linesByAccountCode($eid1);
        self::assertEqualsWithDelta(1210.00, $acc1['324']['debit'], 0.001, 'Settlement PRO-1 = jen alokace 1210, ne cizí 605 navrch.');
        self::assertEqualsWithDelta(1210.00, $acc1['311']['credit'], 0.001, '311 D = snížení pohledávky o vlastní zálohu.');
        $this->assertBalancedEntry($eid1);

        // Vyúčtování PRO-2 → čerpá JEN 605 (a znovu nenačte tentýž zápis přes PRO-1).
        $final2 = $this->saleWithItem('FV-G2', $client, 500.00, 105.00, 'invoice', $pro2);
        $lines2 = $this->posting->buildFromInvoice($this->supplierId, $final2);
        $eid2   = $this->posting->postDocument($this->supplierId, 'invoice', $final2, $lines2, ['entry_date' => self::YEAR . '-06-20']);
        $acc2   = $this->linesByAccountCode($eid2);
        self::assertEqualsWithDelta(605.00, $acc2['324']['debit'], 0.001, 'Settlement PRO-2 = jen alokace 605.');
        self::assertEqualsWithDelta(605.00, $acc2['311']['credit'], 0.001, '311 D = snížení pohledávky o vlastní zálohu.');
        $this->assertBalancedEntry($eid2);
    }

    // (b') — přijatá strana: DDKP k POSKYTNUTÉ záloze účtuje jen odpočet DPH 343/314,
    //        žádný náklad 518 ani závazek 321 (zrcadlo (b) 324/343 na vydané straně).
    public function testPurchaseTaxDocumentBooksVatOnly343Over314(): void
    {
        $vendor  = $this->client('Dodavatel s.r.o.');
        $advance = $this->purchaseInvoice('ZPF-B2', $vendor, 605.00, 'advance');
        $this->payAdvancePfViaBank($advance, 605.00); // 314 MD / 221 D 605
        // DDKP k platbě: základ 500 / DPH 105, navázaný na zálohu (parent_purchase_invoice_id).
        $ddkp = $this->purchaseTaxDocument('DDKP-B2', $vendor, 500.00, 105.00, $advance);

        $lines   = $this->posting->buildFromPurchaseInvoice($this->supplierId, $ddkp);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $ddkp, $lines, ['entry_date' => self::YEAR . '-06-16']);
        $byAcc   = $this->linesByAccountCode($entryId);

        self::assertEqualsWithDelta(105.00, $byAcc['343.100']['debit'], 0.001, '343 MD = odpočet DPH ze zálohy.');
        self::assertEqualsWithDelta(105.00, $byAcc['314']['credit'], 0.001, '314 D = čerpání poskytnuté zálohy o DPH.');
        self::assertArrayNotHasKey('518', $byAcc, 'DDKP nezakládá náklad 518.');
        self::assertArrayNotHasKey('321', $byAcc, 'DDKP nezakládá závazek 321.');
        $this->assertBalancedEntry($entryId);
    }

    // (f3) — přijatá strana: DDKP + vyúčtovací PF na tutéž zálohu = hlasitá chyba (out of scope
    //        v1, zrcadlo (f1) na vydané straně) — jinak by settlement 321/314 přečerpal 314.
    public function testPurchaseFinalWithTaxDocumentThrowsAmbiguous(): void
    {
        $vendor  = $this->client('Dodavatel s.r.o.');
        $advance = $this->purchaseInvoice('ZPF-F3', $vendor, 605.00, 'advance');
        $this->payAdvancePfViaBank($advance, 605.00);
        $this->purchaseTaxDocument('DDKP-F3', $vendor, 500.00, 105.00, $advance);
        $final = $this->purchaseWithItem('PF-F3', $vendor, 1000.00, 210.00, 'invoice', $advance);

        try {
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $final);
            self::fail('DDKP + vyúčtování na přijaté straně má vyhodit advance_settlement_ambiguous.');
        } catch (PostingException $e) {
            self::assertSame('advance_settlement_ambiguous', $e->errorCode);
        }
    }

    // (f3b, task #17) — advance_settlement_ambiguous dřív řeklo jen "zaúčtuj ručně"; teď
    // rovnou spočítá, kolik DPH z finální faktury zbývá doúčtovat na 343 nad rámec toho,
    // co DDKP uplatnil už při platbě (DPH finální 210,00 − DPH DDKP 105,00 = 105,00).
    public function testPurchaseFinalWithTaxDocumentAmbiguousMessageStatesRemainingVat(): void
    {
        $vendor  = $this->client('Dodavatel s.r.o.');
        $advance = $this->purchaseInvoice('ZPF-F3B', $vendor, 605.00, 'advance');
        $this->payAdvancePfViaBank($advance, 605.00);
        $this->purchaseTaxDocument('DDKP-F3B', $vendor, 500.00, 105.00, $advance);
        $final = $this->purchaseWithItem('PF-F3B', $vendor, 1000.00, 210.00, 'invoice', $advance);

        try {
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $final);
            self::fail('DDKP + vyúčtování má vyhodit advance_settlement_ambiguous.');
        } catch (PostingException $e) {
            self::assertSame('advance_settlement_ambiguous', $e->errorCode);
            self::assertStringContainsString('105,00', $e->getMessage(),
                'Hláška musí obsahovat spočítaný doplatek DPH (210,00 − 105,00), ne jen "zaúčtuj ručně".');
        }
    }

    // (f3c, task #17) — finální PF navázaná PŘÍMO na SAMOSTATNÝ DDKP (nákup kartou bez
    // zálohové faktury, § 28/8 ZDPH — linkAdvance() to dovoluje, viz PurchaseAdvanceLinkTest::
    // testStandaloneTaxDocumentCanSettleFinalInvoice). appendAdvanceSettlementPurchase dřív
    // tenhle tvar vazby vůbec nerozpoznal (čekal jen document_kind='advance') → zaúčtování
    // tiše proběhlo BEZE zúčtování 321/314 a zůstatek na 314 zůstal navždy otevřený beze
    // stopy chyby. Teď musí vyhodit STEJNOU hlasitou chybu jako záloha s DDKP dítětem.
    public function testPurchaseFinalLinkedDirectlyToStandaloneTaxDocumentThrowsAmbiguous(): void
    {
        $vendor = $this->client('Dodavatel karta s.r.o.');
        // Samostatný DDKP = document_kind='tax_document' BEZ parent_purchase_invoice_id.
        $ddkp  = $this->purchaseWithItem('DDKP-F3C', $vendor, 500.00, 105.00, 'tax_document', null);
        $final = $this->purchaseWithItem('PF-F3C', $vendor, 1000.00, 210.00, 'invoice', $ddkp);

        try {
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $final);
            self::fail('Finální PF navázaná přímo na samostatný DDKP má vyhodit advance_settlement_ambiguous, ne se tiše zaúčtovat.');
        } catch (PostingException $e) {
            self::assertSame('advance_settlement_ambiguous', $e->errorCode);
            self::assertStringContainsString('105,00', $e->getMessage(),
                'Hláška musí obsahovat spočítaný doplatek DPH (210,00 − 105,00).');
        }
    }

    // (f3d, task #17) — reálný případ: nákup kartou vytvoří samostatný DDKP, žádná faktura
    // se s ním hned nespáruje. PurchaseInvoiceRepository::find() musí na DDKP samotném
    // ukázat, že na 314 zůstává otevřený zůstatek a existuje pravděpodobný kandidát k
    // spárování (has_settlement_candidates) — a spočítat, kolik DPH zbývá na 343. Zrcadlově
    // na finální faktuře musí najít DDKP jako kandidáta (has_advance_candidates) — dřív ho
    // vidělo jen document_kind='advance', takže tlačítko „spárovat se zálohou" se u
    // samostatného DDKP nikdy nezobrazilo.
    public function testStandaloneTaxDocumentShowsUnsettledNoticeAndAdvanceCandidateFlag(): void
    {
        $vendor = $this->client('Dodavatel karta N1');
        // Samostatný DDKP zaplacený kartou (bez zálohové faktury) — 605 Kč (základ 500 / DPH 105).
        $ddkp = $this->purchaseWithItem('DDKP-N1', $vendor, 500.00, 105.00, 'tax_document', null);
        $this->payAdvancePfViaBank($ddkp, 605.00);
        // Konečná faktura od téhož dodavatele, zatím NEspárovaná (přesně reálný případ #17).
        $final = $this->purchaseWithItem('PF-N1', $vendor, 1000.00, 210.00, 'invoice', null);

        $repo = $this->container->get(PurchaseInvoiceRepository::class);

        $ddkpRow = $repo->find($ddkp, $this->supplierId);
        self::assertTrue(
            $ddkpRow['has_settlement_candidates'],
            'DDKP musí nabídnout spárování s nespárovanou fakturou téhož dodavatele.',
        );
        self::assertNotNull($ddkpRow['unsettled_notice'], '314 zůstává otevřené a existuje kandidát — musí se to ukázat.');
        self::assertSame($final, $ddkpRow['unsettled_notice']['candidate']['id']);
        self::assertEqualsWithDelta(605.00, $ddkpRow['unsettled_notice']['paid_amount'], 0.01);
        self::assertEqualsWithDelta(
            105.00,
            $ddkpRow['unsettled_notice']['remaining_vat_on_343'],
            0.01,
            'Zbývající DPH na 343 = DPH finální faktury (210,00) − už uplatněná DPH z DDKP (105,00).',
        );
        self::assertStringContainsString('105,00', $ddkpRow['unsettled_notice']['message']);

        $finalRow = $repo->find($final, $this->supplierId);
        self::assertTrue(
            $finalRow['has_advance_candidates'],
            'Finální faktura musí najít samostatný DDKP jako kandidáta k spárování (root cause #17).',
        );
    }

    /**
     * Rok vedený v daňové evidenci mluví jazykem evidence, ne deníku. Doporučení spárovat
     * platí dál (bez něj se záloha započítá do výdajů dvakrát a DPH z DDKP zůstane bez
     * konečné faktury), ale čísla účtů 314/343 v daňové evidenci neexistují a hláška by
     * odkazovala na neexistující místo.
     *
     * Stav je dosažitelný přesně tehdy, když firma rok zaúčtovaný podvojně přepne zpátky
     * na daňovou evidenci: zápisy v deníku zůstanou, režim už ne.
     */
    public function testUnsettledNoticeDropsAccountNumbersInTaxEvidenceYear(): void
    {
        $vendor = $this->client('Dodavatel karta N2');
        $ddkp   = $this->purchaseWithItem('DDKP-N2', $vendor, 500.00, 105.00, 'tax_document', null);
        $this->payAdvancePfViaBank($ddkp, 605.00);
        $this->purchaseWithItem('PF-N2', $vendor, 1000.00, 210.00, 'invoice', null);

        $repo = $this->container->get(PurchaseInvoiceRepository::class);

        $this->accountingModeForTestYear('double_entry');
        $posted = $repo->find($ddkp, $this->supplierId)['unsettled_notice'];
        self::assertNotNull($posted);
        self::assertStringContainsString('Na účtu 314', $posted['message']);
        self::assertStringContainsString('na 343 doúčtovat', $posted['message']);

        $this->accountingModeForTestYear('tax_evidence');
        $evidence = $repo->find($ddkp, $this->supplierId)['unsettled_notice'];
        self::assertNotNull($evidence, 'Doporučení spárovat platí i v daňové evidenci.');
        self::assertStringNotContainsString('314', $evidence['message']);
        self::assertStringNotContainsString('343', $evidence['message']);
        self::assertStringContainsString('spárujte je tlačítkem výše', $evidence['message']);
        self::assertStringContainsString('105,00', $evidence['message'],
            'Zbývající DPH se uvádí dál — jen bez čísla účtu.');
        self::assertEqualsWithDelta(105.00, $evidence['remaining_vat_on_343'], 0.01,
            'Datová část odpovědi se nemění, mění se jen text hlášky.');
    }

    /** Režim účetnictví platný pro rok testovacích dokladů (rollback per test to uklidí). */
    private function accountingModeForTestYear(string $mode): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_accounting_modes (supplier_id, effective_from, accounting_mode)
             VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE accounting_mode = VALUES(accounting_mode)'
        )->execute([$this->supplierId, self::YEAR . '-01-01', $mode]);
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $account */
    private function saldoItem(array $account, string $docType, int $docId): ?array
    {
        foreach ($account['partners'] as $partner) {
            foreach ($partner['items'] as $item) {
                if ((string) $item['doc_type'] === $docType && (int) $item['doc_id'] === $docId) {
                    return $item;
                }
            }
        }
        return null;
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round((float) $amount * 100.0);
    }

    private function draftWithItem(int $clientId, float $base): int
    {
        $id = $this->saleWithItem('', $clientId, $base, round($base * 0.21, 2));
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'draft', varsymbol = NULL WHERE id = ?")->execute([$id]);
        return $id;
    }

    /** @return array{total_without_vat:float, total_vat:float} */
    private function deductionLine(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT total_without_vat, total_vat FROM invoice_items WHERE invoice_id = ? AND total_with_vat < 0');
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'Koncept má nést odpočtový řádek.');
        return ['total_without_vat' => (float) $row['total_without_vat'], 'total_vat' => (float) $row['total_vat']];
    }

    private function deductionCount(int $invoiceId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoice_items WHERE invoice_id = ? AND total_with_vat < 0');
        $stmt->execute([$invoiceId]);
        return (int) $stmt->fetchColumn();
    }

    private function parentOf(int $invoiceId): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT parent_invoice_id FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $parent = $stmt->fetchColumn();
        return $parent === null || $parent === false ? null : (int) $parent;
    }

    private function saleWithItem(string $vs, int $clientId, float $base, float $vat, string $type = 'invoice', ?int $parentId = null, int $pricesIncludeVat = 0): int
    {
        $with  = $base + $vat;
        $issue = self::YEAR . '-06-15';
        $stmt  = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, parent_invoice_id, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, prices_include_vat, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 0, "issued", "1", ?)'
        );
        $stmt->execute([$this->supplierId, $vs, $type, $parentId, $clientId, $issue, $issue, $issue,
            $this->currencyId, $pricesIncludeVat, $base, $vat, $with, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Položka', 1, 'ks', ?, ?, 21.00, ?, ?, ?, 0)"
        )->execute([$id, $base, $this->vatRateId, $base, $vat, $with]);
        return $id;
    }

    /**
     * Vyúčtovací faktura tak, jak ji staví FinalFromProformaCreator: položka zakázky
     * a záporný odpočtový řádek § 37a za daňový doklad k platbě.
     */
    private function finalWithDeduction(string $vs, int $clientId, int $proformaId, float $base, float $vat, float $ddkpBase, float $ddkpVat): int
    {
        $id = $this->saleWithItem($vs, $clientId, $base, $vat, 'invoice', $proformaId);
        $this->db->pdo()->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Odpočet zálohy', 1, '', ?, ?, 21.00, ?, ?, ?, 1)"
        )->execute([$id, -$ddkpBase, $this->vatRateId, -$ddkpBase, -$ddkpVat, -($ddkpBase + $ddkpVat)]);
        $this->db->pdo()->prepare(
            'UPDATE invoices SET total_without_vat = ?, total_vat = ?, total_with_vat = ? WHERE id = ?'
        )->execute([$base - $ddkpBase, $vat - $ddkpVat, $base + $vat - $ddkpBase - $ddkpVat, $id]);
        return $id;
    }

    private function smallAssetCard(string $name, float $price): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO small_assets (supplier_id, name, acquisition_date, quantity, unit_price, price, status)
             VALUES (?, ?, ?, 1, ?, ?, "in_use")'
        )->execute([$this->supplierId, $name, self::YEAR . '-01-10', $price, $price]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function postInvoiceEntry(int $invoiceId, string $date): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $invoiceId,
            $this->posting->buildFromInvoice($this->supplierId, $invoiceId),
            ['entry_date' => $date],
        );
    }

    /**
     * Σ (MD − D) po účtech přes dané zápisy.
     *
     * @param list<int> $entryIds
     * @return array<string,float>
     */
    private function accountSums(array $entryIds): array
    {
        $sum = [];
        foreach ($entryIds as $entryId) {
            foreach ($this->linesByAccountCode($entryId) as $code => $sides) {
                $sum[$code] = round(($sum[$code] ?? 0.0) + ($sides['debit'] ?? 0.0) - ($sides['credit'] ?? 0.0), 2);
            }
        }
        return $sum;
    }

    private function purchaseWithItem(string $number, int $vendorId, float $base, float $vat, string $kind = 'invoice', ?int $advanceId = null): int
    {
        $with  = $base + $vat;
        $issue = self::YEAR . '-06-15';
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, advance_purchase_invoice_id,
                 vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", ?, ?, "full", ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $kind, $advanceId, $issue, $issue, $issue, $issue,
            $this->currencyId, $base, $vat, $with, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Položka', 1, 'ks', ?, ?, 21.00, ?, ?, ?, 0)"
        )->execute([$id, $base, $this->vatRateId, $base, $vat, $with]);
        return $id;
    }

    private function purchaseTaxDocument(string $number, int $vendorId, float $base, float $vat, int $advanceId): int
    {
        $with  = $base + $vat;
        $issue = self::YEAR . '-06-15';
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, parent_purchase_invoice_id,
                 vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", "tax_document", ?, "full", ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $advanceId, $issue, $issue, $issue, $issue,
            $this->currencyId, $base, $vat, $with, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Položka', 1, 'ks', ?, ?, 21.00, ?, ?, ?, 0)"
        )->execute([$id, $base, $this->vatRateId, $base, $vat, $with]);
        return $id;
    }

    private function payProformaViaBank(int $proformaId, float $amount): int
    {
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, $amount, ['match_status' => 'auto_exact', 'matched_invoice_id' => $proformaId]);
        $this->invoicePayment($proformaId, $tx, $amount);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'Inkaso zálohy se má zaúčtovat: ' . ($res['reason'] ?? ''));
        return $tx;
    }

    private function payAdvancePfViaBank(int $advPfId, float $amount): int
    {
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -$amount, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $advPfId, $amount);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'Úhrada zálohové PF se má zaúčtovat: ' . ($res['reason'] ?? ''));
        return $tx;
    }

    private function entryIdForBankTx(int $txId): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries WHERE supplier_id={$this->supplierId}
              AND source_type='bank' AND source_id={$txId} AND reversed_by IS NULL LIMIT 1"
        )->fetchColumn();
    }

    private function assertBalancedEntry(int $entryId): void
    {
        $entry = $this->journal->find($entryId, $this->supplierId);
        $debit = 0;
        $credit = 0;
        foreach ($entry['lines'] as $l) {
            $cents = (int) round((float) $l['amount'] * 100);
            if ($l['side'] === 'debit') {
                $debit += $cents;
            } else {
                $credit += $cents;
            }
        }
        self::assertSame($debit, $credit, 'Σ MD == Σ D (v haléřích).');
    }
}
