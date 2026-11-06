<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;

/**
 * Vyrovnání, která párovací symbol deníku Money sám nepřiřadí ({@see SyntheticAgenda::filesWithSettlementGaps()}):
 * číslo dokladu v popisu řádku, úhrada vydané faktury bez data úhrady v Money, jedna platba
 * za víc faktur a doklady téhož symbolu s různým IČO či odpočtem zálohy. Řádný i opravný
 * převod je musí promítnout do saldokonta a opakovaný běh nesmí nic dalšího změnit.
 */
final class MoneyS3SettlementGapsTest extends MoneyS3ImportTestCase
{
    private int $supplierId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithSettlementGaps());
        $this->supplierId = $this->supplier();
        $protocol = $this->import($this->supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
    }

    public function testSettlementByDocumentNumberInDescription(): void
    {
        $sid = $this->supplierId;
        // Zápočet bez symbolu dokladu vyrovná vydanou i přijatou fakturu (jediný doklad svého druhu - vazba).
        self::assertSame(1, $this->rowCount('journal_entry_document_links', $sid,
            "doc_type = 'invoice' AND doc_id = {$this->invoice('FV25020')} AND entry_id = {$this->entry('ID25020')}"));
        self::assertSame(1, $this->rowCount('journal_entry_document_links', $sid,
            "doc_type = 'purchase_invoice' AND doc_id = {$this->purchase('DF-2025-300')} AND entry_id = {$this->entry('ID25020')}"));
        // Zápočet dvou vydaných faktur v jednom řádku se rozdělí do jejich zůstatků.
        $settled = fn (string $no, string $amount): int => $this->rowCount('invoice_settlements', $sid,
            "doc_type = 'invoice' AND doc_id = {$this->invoice($no)} AND amount = {$amount} AND journal_entry_id = {$this->entry('ID25021')}
             AND account_id = (SELECT id FROM chart_of_accounts WHERE supplier_id = {$sid} AND account_code = '321.000')");
        self::assertSame(1, $settled('FV25023', '500.00'));
        self::assertSame(1, $settled('FV25024', '300.00'));
        self::assertSame(1, $this->rowCount('journal_entry_document_links', $sid,
            "doc_type = 'purchase_invoice' AND doc_id = {$this->purchase('DF-2025-301')} AND entry_id = {$this->entry('ID25021')}"));
        // Platba s číslem faktury v popisu hradí jen tuto fakturu, ne starší fakturu téhož symbolu.
        self::assertSame(1, $this->rowCount('invoice_payments', $sid, "invoice_id = {$this->invoice('FV25021')} AND source = 'bank' AND amount = 605.00"));
        self::assertSame(0, $this->rowCount('invoice_payments', $sid, "invoice_id = {$this->invoice('FV25022')}"));

        $open = $this->openDocuments();
        foreach (['FV25020', 'FP25020', 'FV25021', 'FV25023', 'FV25024', 'FP25021'] as $no) {
            self::assertNotContains($no, $open, 'Vyrovnaný doklad nesmí být v saldokontu otevřený: ' . json_encode($open));
        }
        self::assertContains('FV25022', $open);
        $this->assertRerunChangesNothing();
    }

    public function testIssuedInvoiceWithoutPaidDateGetsItsPayments(): void
    {
        $sid = $this->supplierId;
        $fv = $this->invoice('FV25025');
        self::assertSame(2, $this->rowCount('invoice_payments', $sid, "invoice_id = {$fv} AND source = 'bank' AND amount IN (500.00, 200.00)"));
        self::assertSame(1, $this->rowCount('invoices', $sid, "id = {$fv} AND status = 'sent' AND paid_total = 700.00"));

        // Firma převedená dřívější verzí: párování bez evidované úhrady. Opravný převod ji doplní.
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ?')->execute([$sid, $fv]);
        $pdo->prepare('UPDATE invoices SET paid_total = 0 WHERE id = ?')->execute([$fv]);
        $matches = $this->rowCount('payment_matches', $sid, "invoice_id = {$fv}");
        self::assertSame(2, $matches);

        $repair = $this->relink();
        self::assertFalse($repair->hasErrors(), $this->explain($repair));
        self::assertSame(2, $this->rowCount('invoice_payments', $sid, "invoice_id = {$fv} AND source = 'bank' AND amount IN (500.00, 200.00)"), $this->explain($repair));
        self::assertSame(1, $this->rowCount('invoices', $sid, "id = {$fv} AND status = 'sent' AND paid_total = 700.00"));
        self::assertSame($matches, $this->rowCount('payment_matches', $sid, "invoice_id = {$fv}"));
        $this->assertRerunChangesNothing(true);
    }

    public function testOnePaymentForTwoInvoicesIsSplitByJournalLines(): void
    {
        $this->assertSplitPayment();

        // Stav po dřívější verzi převodu: celý BP25028 u FV25026, FV25027 bez úhrady.
        $sid = $this->supplierId;
        $pdo = $this->db->pdo();
        $bp28 = $this->movement('BP25028');
        $fv26 = $this->invoice('FV25026');
        $fv27 = $this->invoice('FV25027');
        $pdo->prepare('UPDATE payment_matches SET amount = 1405.00 WHERE supplier_id = ? AND bank_transaction_id = ? AND invoice_id = ?')->execute([$sid, $bp28, $fv26]);
        $pdo->prepare('UPDATE invoice_payments SET amount = 1405.00 WHERE supplier_id = ? AND bank_transaction_id = ? AND invoice_id = ?')->execute([$sid, $bp28, $fv26]);
        $pdo->prepare('DELETE FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ?')->execute([$sid, $fv27]);
        $pdo->prepare("DELETE FROM money_s3_import_map WHERE supplier_id = ? AND kind = 'payment'
                         AND target_id IN (SELECT id FROM payment_matches WHERE supplier_id = ? AND invoice_id = ?)")->execute([$sid, $sid, $fv27]);
        $pdo->prepare('DELETE FROM payment_matches WHERE supplier_id = ? AND invoice_id = ?')->execute([$sid, $fv27]);

        $repair = $this->relink();
        self::assertFalse($repair->hasErrors(), $this->explain($repair));
        $this->assertSplitPayment();
        $this->assertRerunChangesNothing(true);
    }

    public function testRelinkKeepsPaymentSplitEnteredByUser(): void
    {
        $sid = $this->supplierId;
        $pdo = $this->db->pdo();
        $bp28 = $this->movement('BP25028');
        $fv26 = $this->invoice('FV25026');
        $fv27 = $this->invoice('FV25027');
        // Uživatel pohyb BP25028 přepároval po svém: celý k FV25026 (párování mimo mapu převodu).
        $pdo->prepare('DELETE FROM invoice_payments WHERE supplier_id = ? AND bank_transaction_id = ?')->execute([$sid, $bp28]);
        $pdo->prepare('DELETE FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id = ?')->execute([$sid, $bp28]);
        $pdo->prepare("INSERT INTO payment_matches (supplier_id, bank_transaction_id, invoice_id, amount, match_type, matched_by_user_id) VALUES (?, ?, ?, 1405.00, 'manual', ?)")
            ->execute([$sid, $bp28, $fv26, $this->userId]);

        $repair = $this->relink();
        self::assertFalse($repair->hasErrors(), $this->explain($repair));
        self::assertSame(1, $this->rowCount('payment_matches', $sid, "bank_transaction_id = {$bp28}"), $this->explain($repair));
        self::assertSame(1, $this->rowCount('payment_matches', $sid, "bank_transaction_id = {$bp28} AND invoice_id = {$fv26} AND amount = 1405.00"));
        self::assertSame(0, $this->rowCount('payment_matches', $sid, "invoice_id = {$fv27}"));
        self::assertSame(0, $this->rowCount('invoice_payments', $sid, "bank_transaction_id = {$bp28}"), 'K párování uživatele opravný převod úhradu nedoplňuje.');
    }

    /** Párování, které převod vytvořil a uživatel zrušil, opravný převod znovu nezaloží (ani nespadne na mapě). */
    public function testRelinkDoesNotRecreateMatchRemovedByUser(): void
    {
        $sid = $this->supplierId;
        $pdo = $this->db->pdo();
        $fv27 = $this->invoice('FV25027');
        $pdo->prepare('DELETE FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ?')->execute([$sid, $fv27]);
        $pdo->prepare('DELETE FROM payment_matches WHERE supplier_id = ? AND invoice_id = ?')->execute([$sid, $fv27]);

        $repair = $this->relink();
        self::assertFalse($repair->hasErrors(), $this->explain($repair));
        self::assertSame(0, $this->rowCount('payment_matches', $sid, "invoice_id = {$fv27}"), $this->explain($repair));
        self::assertSame(0, $this->rowCount('invoice_payments', $sid, "invoice_id = {$fv27}"));
    }

    public function testSharedSymbolRespectsPartnerOfEachDocumentAndAdvanceDeduction(): void
    {
        $sid = $this->supplierId;
        // Úhrada s IČO druhého dodavatele hradí jeho fakturu, ne fakturu dodavatele, který je ve skupině první.
        self::assertSame(1, $this->rowCount('payment_matches', $sid, "purchase_invoice_id = {$this->purchase('GF-2025-001')} AND amount = 605.00"));
        self::assertSame(0, $this->rowCount('payment_matches', $sid, "purchase_invoice_id = {$this->purchase('DF-2025-302')}"));
        // Úhrada bez IČO hradí fakturu, jejíž předpis IČO nenese.
        self::assertSame(1, $this->rowCount('invoice_payments', $sid, "invoice_id = {$this->invoice('FV25028')} AND source = 'bank' AND amount = 605.00"));
        // Konečná faktura s odpočtem zálohy větším než zbytek předpisu se páruje na 311
        // (vazbu na zálohu, která by snížila její částku k úhradě, agenda nemá).
        self::assertSame(1, $this->rowCount('invoice_payments', $sid, "invoice_id = {$this->invoice('FV25030')} AND source = 'bank' AND amount = 410.00"));
        $open = $this->openDocuments();
        foreach (['FP25023', 'FV25028'] as $no) {
            self::assertNotContains($no, $open, json_encode($open));
        }
        self::assertContains('FP25022', $open);
    }

    /**
     * Dobropis, který převod založil v jiné měně než opravovanou fakturu: MyÚčto ho
     * k faktuře nezapočte, saldo faktury ale zápočet z Money nést musí.
     */
    public function testCreditNoteInOtherCurrencyIsOffsetInInvoiceCurrency(): void
    {
        $sid = $this->supplierId;
        $pdo = $this->db->pdo();
        $fv = $this->invoice('FV25004');
        $dv = $this->invoice('DV25002');
        self::assertSame(1, $this->rowCount('credit_note_offsets', $sid, "invoice_id = {$fv} AND credit_note_id = {$dv} AND amount = 484.00"));
        $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                       VALUES (?, 'EUR', 'EUR', '€', 'Euro', 'Euro', 2, 1, 0)")->execute([$sid]);
        $eur = (int) $pdo->lastInsertId();
        $pdo->prepare('DELETE FROM credit_note_offsets WHERE supplier_id = ? AND credit_note_id = ?')->execute([$sid, $dv]);
        $pdo->prepare("DELETE FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ? AND source = 'credit_note'")->execute([$sid, $fv]);
        $pdo->prepare('UPDATE invoices SET currency_id = ?, exchange_rate = 24.2, total_with_vat = -20.00 WHERE id = ?')->execute([$eur, $dv]);

        $repair = $this->relink();
        self::assertFalse($repair->hasErrors(), $this->explain($repair));
        self::assertSame(1, $this->rowCount('invoice_payments', $sid, "invoice_id = {$fv} AND source = 'credit_note' AND amount = 484.00 AND currency = 'CZK'"), $this->explain($repair));
        self::assertSame(0, $this->rowCount('credit_note_offsets', $sid, "credit_note_id = {$dv}"), 'Zápočet dokladů v různé měně MyÚčto nevede.');
        self::assertNotContains('FV25004', $this->openDocuments());
        $this->assertRerunChangesNothing(true);
    }

    private function assertSplitPayment(): void
    {
        $sid = $this->supplierId;
        $fv26 = $this->invoice('FV25026');
        $fv27 = $this->invoice('FV25027');
        $bp27 = $this->movement('BP25027');
        $bp28 = $this->movement('BP25028');
        $state = fn (string $table, int $invoice): array => $this->db->pdo()->query(
            "SELECT bank_transaction_id, amount FROM {$table} WHERE supplier_id = {$sid} AND invoice_id = {$invoice} ORDER BY bank_transaction_id"
        )->fetchAll(PDO::FETCH_NUM);
        $expected26 = [[$bp27, '1620.00'], [$bp28, '800.00']];
        sort($expected26);
        self::assertEquals($expected26, $state('payment_matches', $fv26));
        self::assertEquals($expected26, $state('invoice_payments', $fv26));
        self::assertEquals([[$bp28, '605.00']], $state('payment_matches', $fv27));
        self::assertEquals([[$bp28, '605.00']], $state('invoice_payments', $fv27));
        $open = $this->openDocuments();
        self::assertNotContains('FV25026', $open);
        self::assertNotContains('FV25027', $open);
    }

    /** Opakovaný (opravný) převod nic dalšího nezmění. */
    private function assertRerunChangesNothing(bool $relink = false): void
    {
        $before = $this->state();
        $again = $relink ? $this->relink() : $this->import($this->supplierId);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($before, $this->state());
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $sid = $this->supplierId;
        $pdo = $this->db->pdo();
        $out = [];
        foreach ([
            'payment_matches' => 'SELECT id, bank_transaction_id, invoice_id, purchase_invoice_id, amount FROM payment_matches WHERE supplier_id = ? ORDER BY id',
            'invoice_payments' => 'SELECT id, invoice_id, amount, source, bank_transaction_id FROM invoice_payments WHERE supplier_id = ? ORDER BY id',
            'invoice_settlements' => 'SELECT id, doc_type, doc_id, amount FROM invoice_settlements WHERE supplier_id = ? ORDER BY id',
            'credit_note_offsets' => 'SELECT id, invoice_id, credit_note_id, amount FROM credit_note_offsets WHERE supplier_id = ? ORDER BY id',
            'links' => 'SELECT entry_id, doc_type, doc_id FROM journal_entry_document_links WHERE supplier_id = ? ORDER BY entry_id, doc_type, doc_id',
            'invoices' => 'SELECT id, status, paid_total FROM invoices WHERE supplier_id = ? ORDER BY id',
        ] as $key => $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$sid]);
            $out[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $out;
    }

    private function relink(): ImportProtocol
    {
        return $this->importer->run($this->supplierId, $this->userId, $this->backup(),
            new ImportOptions(ImportOptions::MODE_IMPORT, true, null, [], [], false, null, ImportOptions::DISPOSAL_YEAR_TAX_HALF, false, true));
    }

    /** @return list<string> čísla dokladů otevřených v saldokontu 311, 321 a 324 ke konci roku 2025 */
    private function openDocuments(): array
    {
        $saldo = $this->container(SaldoService::class);
        $period = $this->periodId($this->supplierId, 2025);
        $open = [];
        foreach (['311', '321', '324'] as $account) {
            foreach ($saldo->build($this->supplierId, $period, '2025-12-31', $account)['accounts'] as $a) {
                foreach ($a['partners'] as $partner) {
                    foreach ($partner['items'] as $item) {
                        $open[] = $item['doc_no'];
                    }
                }
            }
        }
        return $open;
    }

    private function invoice(string $no): int
    {
        return $this->lookupId('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?', $no);
    }

    private function purchase(string $vendorNo): int
    {
        return $this->lookupId('SELECT id FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number = ?', $vendorNo);
    }

    private function entry(string $no): int
    {
        return $this->lookupId("SELECT id FROM journal_entries WHERE supplier_id = ? AND source_type = 'manual' AND document_no = ?", $no);
    }

    private function movement(string $bankRef): int
    {
        return $this->lookupId('SELECT t.id FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE s.supplier_id = ? AND t.bank_ref = ?', $bankRef);
    }

    private function lookupId(string $sql, string $value): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$this->supplierId, $value]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id, "{$value} nenalezen");
        return $id;
    }
}
