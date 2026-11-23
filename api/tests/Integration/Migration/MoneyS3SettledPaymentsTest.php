<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use MyInvoice\Service\Invoice\PaymentTaxDocumentNotApplicableException;
use MyInvoice\Service\Invoice\TakenOverPayment;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3SettledPaymentRepair;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3SettledPayments;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;

/**
 * Úhrady, které Money vede jen datem úhrady a převedená data k nim doklad úhrady nemají
 * ({@see SyntheticAgenda::filesWithSettledWithoutPaymentDocument()}). Vydaný doklad musí
 * zůstat uhrazený i po přepočtu plateb, přijatý doklad nesmí párování banky brát jako
 * nekrytý a platba zálohy z Money se naváže na DDKP z Money. Opravné CLI doplní totéž
 * firmě převedené dřív.
 */
final class MoneyS3SettledPaymentsTest extends MoneyS3ImportTestCase
{
    private int $supplierId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithSettledWithoutPaymentDocument());
        $this->supplierId = $this->supplier();
        $this->db->pdo()->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'EUR', 'EUR', '€', 'Euro', 'Euro', 2, 1, 0)")->execute([$this->supplierId]);
        $protocol = $this->import($this->supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
    }

    public function testInvoiceSettledByInternalDocumentSurvivesPaymentRecompute(): void
    {
        $payments = $this->container(InvoicePaymentService::class);
        foreach (['FV25020' => 1210.0, 'FV25040' => 1210.0, 'FV25041' => 121.0] as $no => $paid) {
            $id = $this->invoice($no);
            $before = $this->invoiceRow($id);
            self::assertSame('paid', $before['status'], $no);
            self::assertSame($paid, (float) $before['paid_total'], $no);
            self::assertSame(1, $this->rowCount('invoice_payments', $this->supplierId, "invoice_id = {$id} AND source = 'settlement'"), $no);

            $result = $payments->recordPayment($id, 1.00, '2025-12-30');
            $payments->deletePayment((int) $result['payment_id']);

            $after = $this->invoiceRow($id);
            self::assertSame('paid', $after['status'], $no);
            self::assertSame($paid, (float) $after['paid_total'], $no);
            self::assertSame($before['paid_at'], $after['paid_at'], $no);
        }
    }

    public function testSettlementKindsFollowMoney(): void
    {
        $sid = $this->supplierId;
        // Zápis ID25020 navázaný na FV25020 se stane zápočtem s platbou: saldokonto ho čte jen jednou.
        $fv20 = $this->invoice('FV25020');
        self::assertSame(1, $this->rowCount('invoice_settlements', $sid,
            "doc_type = 'invoice' AND doc_id = {$fv20} AND amount = 1210.00 AND journal_entry_id = {$this->entry('ID25020')} AND invoice_payment_id IS NOT NULL"));
        self::assertSame(1, $this->rowCount('invoice_payments', $sid, "invoice_id = {$fv20} AND note = 'Převzato z Money S3, zápočet interním dokladem ID25020'"));
        // Zápočet, který párovací symbol nepřiřadil, se najde podle čísla a data úhrady z Money
        // (zápis vyrovnává vydanou i přijatou fakturu).
        $fv40 = $this->invoice('FV25040');
        $id40 = $this->entry('ID25040');
        self::assertSame(1, $this->rowCount('invoice_settlements', $sid,
            "doc_type = 'invoice' AND doc_id = {$fv40} AND amount = 1210.00 AND journal_entry_id = {$id40} AND invoice_payment_id IS NOT NULL"));
        self::assertSame(1, $this->rowCount('invoice_settlements', $sid,
            "doc_type = 'purchase_invoice' AND doc_id = {$this->purchase('DF-2025-640')} AND amount = 1210.00 AND journal_entry_id = {$id40}"));
        // Úhrada bez čísla nese chráněná platba bez dokladu úhrady.
        $fv30 = $this->invoice('FV25030');
        self::assertSame(1, $this->rowCount('invoice_payments', $sid,
            "invoice_id = {$fv30} AND source = 'settlement' AND amount = 800.00 AND paid_on = '2025-12-10' AND note = " . $this->db->pdo()->quote(MoneyS3SettledPayments::note())));
        // Platba v měně dokladu.
        $fv41 = $this->invoice('FV25041');
        self::assertSame(1, $this->rowCount('invoice_payments', $sid,
            "invoice_id = {$fv41} AND amount = 121.00 AND currency = 'EUR' AND paid_on = '2025-12-20' AND note = " . $this->db->pdo()->quote(TakenOverPayment::MONEY_S3_WITHOUT_DOCUMENT_NOTE)));
        // Úhrada po konci posledního převáděného roku platbu bez dokladu nedostane.
        self::assertSame(0, $this->rowCount('invoice_payments', $sid, "invoice_id = {$this->invoice('FV25042')}"));

        $open = $this->openDocuments();
        foreach (['FV25020', 'FV25030', 'FV25040', 'FV25041', 'FP25020', 'FP25040', 'FP25041'] as $no) {
            self::assertNotContains($no, $open, 'Uhrazený doklad nesmí být v saldokontu otevřený: ' . json_encode($open));
        }
        $this->assertRerunChangesNothing();
    }

    public function testPaidPurchaseInvoiceWithoutPaymentIsNotMatchCandidate(): void
    {
        $sid = $this->supplierId;
        $fp41 = $this->purchase('DF-2025-641');
        // Platba se stejným symbolem a částkou v dalším roce se s fakturou uhrazenou zápočtem
        // v Money nespáruje (nanejvýš ji párovač nabídne ke kontrole dvojí úhrady).
        $statement = (int) $this->scalar('SELECT MIN(id) FROM bank_statements WHERE supplier_id = ?', [$sid]);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, variable_symbol, counterparty_name, match_status)
            VALUES (?, '2026-01-20 00:00:00', -605.00, 'CZK', '2025651', 'Dodavatel Alfa s.r.o.', 'unmatched')")->execute([$statement]);
        $tx = (int) $pdo->lastInsertId();
        $result = $this->container(StatementMatcher::class)->matchBatch([$tx])[$tx] ?? [];

        self::assertNotContains($result['status'] ?? null, ['auto_exact', 'auto_partial', 'manual'], json_encode($result));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = ?', [$tx]));
        self::assertSame(605.0, (float) $this->scalar('SELECT settled_outside_amount FROM purchase_invoices WHERE id = ?', [$fp41]));

        // Zápis navázaný na FP25020 se stane zápočtem, mimo evidenci nezůstane nic.
        $fp20 = $this->purchase('DF-2025-300');
        self::assertSame(1, $this->rowCount('invoice_settlements', $sid,
            "doc_type = 'purchase_invoice' AND doc_id = {$fp20} AND amount = 1210.00 AND journal_entry_id = {$this->entry('ID25020')}"));
        self::assertSame(0.0, (float) $this->scalar('SELECT settled_outside_amount FROM purchase_invoices WHERE id = ?', [$fp20]));
    }

    public function testMoneyTaxDocumentIsLinkedToAdvancePayment(): void
    {
        $proforma = $this->invoice('ZV25050');
        $taxDocument = $this->invoice('DD25050');
        $payment = (int) $this->scalar("SELECT id FROM invoice_payments WHERE invoice_id = ? AND source = 'bank'", [$proforma]);
        self::assertGreaterThan(0, $payment);
        self::assertSame($taxDocument, (int) $this->scalar('SELECT tax_document_invoice_id FROM invoice_payments WHERE id = ?', [$payment]));

        // Úhrada zálohy zápočtem (platba bez dokladu úhrady) se naváže na svůj DDKP stejně.
        $settled = $this->invoice('ZV25051');
        self::assertSame($this->invoice('DD25051'), (int) $this->scalar("SELECT tax_document_invoice_id FROM invoice_payments WHERE invoice_id = ? AND source = 'settlement'", [$settled]));

        // Vystavení DDKP k platbě vrátí převzatý DDKP, nový nevznikne.
        $count = fn (): int => $this->rowCount('invoices', $this->supplierId, "invoice_type = 'tax_document'");
        $before = $count();
        self::assertSame($taxDocument, $this->container(PaymentTaxDocumentCreator::class)->createForPayment($payment));
        self::assertSame($before, $count());
    }

    /** Platba zálohy z Money, ke které převod DDKP nenašel, nový DDKP nedostane (daň přiznala Money). */
    public function testMoneyAdvancePaymentWithoutTaxDocumentRefusesNewOne(): void
    {
        $proforma = $this->invoice('ZV25050');
        $payment = (int) $this->scalar("SELECT id FROM invoice_payments WHERE invoice_id = ? AND source = 'bank'", [$proforma]);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?")->execute([$this->invoice('DD25050')]);
        $this->db->pdo()->prepare('UPDATE invoice_payments SET tax_document_invoice_id = NULL WHERE id = ?')->execute([$payment]);

        $this->expectException(PaymentTaxDocumentNotApplicableException::class);
        $this->container(PaymentTaxDocumentCreator::class)->createForPayment($payment);
    }

    public function testRepairSettledPaymentsDryRunCommitIdempotent(): void
    {
        $sid = $this->supplierId;
        $pdo = $this->db->pdo();
        $converted = $this->settlementKinds();
        // Stav firmy převedené dřív: úhrady bez dokladu jen v paid_total, zápis ID25020 jen vazbou,
        // přijaté doklady bez úhrady mimo evidenci a platba zálohy bez vazby na DDKP.
        foreach (['ID25020', 'ID25040'] as $entry) {
            $pdo->prepare("DELETE s, p FROM invoice_settlements s LEFT JOIN invoice_payments p ON p.id = s.invoice_payment_id
                            WHERE s.supplier_id = ? AND s.journal_entry_id = ?")->execute([$sid, $this->entry($entry)]);
        }
        $pdo->prepare("DELETE FROM invoice_payments WHERE supplier_id = ? AND source = 'settlement' AND note LIKE ?")
            ->execute([$sid, TakenOverPayment::MONEY_S3_WITHOUT_DOCUMENT_NOTE . '%']);
        $pdo->prepare('UPDATE purchase_invoices SET settled_outside_amount = 0 WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('UPDATE invoice_payments SET tax_document_invoice_id = NULL WHERE supplier_id = ?')->execute([$sid]);
        $before = $this->state();

        $repair = $this->container(MoneyS3SettledPaymentRepair::class);
        $dry = $repair->run([$sid], false)[0];
        self::assertSame('would_repair', $dry['status'], json_encode($dry));
        self::assertSame($before, $this->state(), 'Zkouška nanečisto nesmí nic změnit.');

        $done = $repair->run([$sid], true)[0];
        self::assertSame('repaired', $done['status']);
        self::assertSame(array_column($dry['invoices'], 'id'), array_column($done['invoices'], 'id'));
        self::assertSame($dry['issued_amount_czk'], $done['issued_amount_czk']);
        self::assertSame(2, $done['tax_document_links']);
        $after = $this->state();
        self::assertSame($before['journal'], $after['journal'], 'Deník se nemění.');
        self::assertSame($before['invoices'], $after['invoices'], 'Stav a uhrazená částka dokladů se nemění.');
        self::assertNotSame($before['payments'], $after['payments']);
        self::assertSame(1, $this->rowCount('invoice_settlements', $sid, "doc_type = 'invoice' AND doc_id = {$this->invoice('FV25020')} AND journal_entry_id = {$this->entry('ID25020')}"));
        // Zápočet ID25040 převod přiřadil podle čísla úhrady ze zálohy Money (UDoklad). CLI zálohu
        // nemá a v převzatých datech ho nic nepotvrzuje: popis „Vzájemný zápočet" nenese číslo
        // dokladu a vydanou FV25040 (Odběratel Beta) s přijatou DF-2025-640 (Dodavatel Alfa)
        // nespojuje partner. Shodu dne a částky proto jen navrhne (nanečisto i při zápisu)
        // a doklady dostanou výplň jako dřív.
        $fv40 = $this->invoice('FV25040');
        $fp40 = $this->purchase('DF-2025-640');
        $id40 = $this->entry('ID25040');
        $proposals = static fn (array $row): array => array_map(static fn (array $p): array => [$p['doc_type'], $p['id'], $p['entry_id'], $p['amount_czk']], $row['proposals'] ?? []);
        $expected = [['invoice', $fv40, $id40, 1210.0], ['purchase_invoice', $fp40, $id40, 1210.0]];
        self::assertSame(0, $this->rowCount('invoice_settlements', $sid, "journal_entry_id = {$id40}"));
        self::assertSame(1, $this->rowCount('invoice_payments', $sid,
            "invoice_id = {$fv40} AND source = 'settlement' AND amount = 1210.00 AND note = " . $pdo->quote(MoneyS3SettledPayments::note())));
        self::assertSame(1210.0, (float) $this->scalar('SELECT settled_outside_amount FROM purchase_invoices WHERE id = ?', [$fp40]));
        self::assertSame($expected, $proposals($dry));
        self::assertSame($expected, $proposals($done));
        $withoutId40 = array_values(array_filter($converted, static fn (array $s): bool => $s[2] !== 'ID25040'));
        self::assertSame($withoutId40, $this->settlementKinds(), 'Opravné CLI dá stejné zápočty jako převod kromě nepotvrzeného ID25040.');
        $audit = json_decode((string) $this->scalar("SELECT payload FROM activity_log WHERE supplier_id = ? AND action = ? AND entity_id = ? ORDER BY id LIMIT 1",
            [$sid, MoneyS3SettledPaymentRepair::AUDIT_ACTION, $fv40]), true);
        self::assertSame('ID25040', $audit['settlement_proposal']['entry_no'] ?? null, json_encode($audit));

        $again = $repair->run([$sid], true)[0];
        self::assertSame('nothing', $again['status'], json_encode($again));
        self::assertSame($after, $this->state());
        self::assertSame($expected, $proposals($again), 'Návrh zůstává vidět, dokud ho uživatel nevyřeší.');

        // Uživatel návrh potvrdí navázáním zápisu na doklady: opakovaná oprava dá zápočty jako převod.
        foreach ([['invoice', $fv40], ['purchase_invoice', $fp40]] as [$type, $doc]) {
            $pdo->prepare('INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id) VALUES (?, ?, ?, ?)')->execute([$sid, $id40, $type, $doc]);
        }
        $confirmed = $repair->run([$sid], true)[0];
        self::assertSame('repaired', $confirmed['status'], json_encode($confirmed));
        self::assertSame([], $confirmed['proposals']);
        self::assertSame($converted, $this->settlementKinds(), 'Po potvrzení dá opravné CLI stejné zápočty jako převod.');
        self::assertSame(0, $this->rowCount('invoice_payments', $sid, "invoice_id = {$fv40} AND note LIKE " . $pdo->quote(TakenOverPayment::MONEY_S3_WITHOUT_DOCUMENT_NOTE . '%')));
        self::assertSame(0.0, (float) $this->scalar('SELECT settled_outside_amount FROM purchase_invoices WHERE id = ?', [$fp40]));
        self::assertSame($before['invoices'], $this->state()['invoices']);
    }

    // ── pomocníci ────────────────────────────────────────────────────────────

    /** @return list<array{0:string,1:string,2:string}> zápočty firmy: druh dokladu, částka, číslo zápisu */
    private function settlementKinds(): array
    {
        return $this->db->pdo()->query("SELECT s.doc_type, s.amount, e.document_no FROM invoice_settlements s JOIN journal_entries e ON e.id = s.journal_entry_id
            WHERE s.supplier_id = {$this->supplierId} AND s.status = 'confirmed' ORDER BY s.doc_type, e.document_no, s.amount")->fetchAll(PDO::FETCH_NUM);
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $sid = $this->supplierId;
        $rows = fn (string $sql): array => $this->db->pdo()->query($sql)->fetchAll(PDO::FETCH_NUM);
        return [
            'journal' => $rows("SELECT COUNT(*), COALESCE(SUM(l.signed_amount), 0) FROM journal_entry_lines l WHERE l.supplier_id = {$sid}"),
            'invoices' => $rows("SELECT id, status, paid_total, paid_at FROM invoices WHERE supplier_id = {$sid} ORDER BY id"),
            'payments' => $rows("SELECT id, invoice_id, amount, source, note, tax_document_invoice_id FROM invoice_payments WHERE supplier_id = {$sid} ORDER BY id"),
            'settlements' => $rows("SELECT doc_type, doc_id, amount, journal_entry_id FROM invoice_settlements WHERE supplier_id = {$sid} ORDER BY id"),
            'purchases' => $rows("SELECT id, status, settled_outside_amount FROM purchase_invoices WHERE supplier_id = {$sid} ORDER BY id"),
        ];
    }

    private function assertRerunChangesNothing(): void
    {
        $before = $this->state();
        $protocol = $this->import($this->supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame($before, $this->state(), 'Opakovaný převod nesmí nic změnit.');
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

    /** @return array<string,mixed> */
    private function invoiceRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, paid_total, paid_at FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
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

    private function lookupId(string $sql, string $value): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$this->supplierId, $value]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id, "{$value} nenalezen");
        return $id;
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
