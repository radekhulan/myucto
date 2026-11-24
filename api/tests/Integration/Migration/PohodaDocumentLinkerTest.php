<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport as X;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaMdbExport as Mdb;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vazby, které převod z POHODY doplní pro saldokonto ({@see \MyInvoice\Service\Migration\Pohoda\DocumentLinker}):
 * čerpání záloh (1:1 vazba i `purchase_advance_deductions`), DDKP k záloze, zápočet
 * dobropisu interním dokladem, vrácení a odpočet zálohy podle zaúčtování na 314
 * a kurzový rozdíl v zápisu úhrady. Agenda {@see X::withAdvancesAndSettlements()}.
 * Izolovaná firma, transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class PohodaDocumentLinkerTest extends TestCase
{
    private Connection $db;
    private PohodaImporter $importer;
    private string $tmp = '';
    private int $userId = 0;
    private int $anyCurrencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private bool $inTx = false;
    private int $supplierId = 0;
    private ?ImportProtocol $protocol = null;
    private string $exportDir = '';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(PohodaImporter::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        if (!$this->db->hasColumn('pohoda_import_map', 'pohoda_key') || !$this->db->hasTable('purchase_advance_deductions')) {
            $this->markTestSkipped('Chybí migrace 1844 (pohoda_import_map) nebo 2190 (purchase_advance_deductions).');
        }
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->anyCurrencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->anyCurrencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_link_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    /** 6) linkAdvances: jediná záloha = vazba na dokladu, víc záloh nebo zabraná záloha = purchase_advance_deductions. */
    public function testAdvanceDeductionItemsLinkOneToOneOrByDeductionRows(): void
    {
        $this->import();

        self::assertSame($this->purchase(X::LINK_ADVANCE), $this->column('purchase_invoices', 'advance_purchase_invoice_id', X::LINK_FINAL),
            'Faktura s jedinou položkou odpočtu se naváže na zálohu sloupcem.');
        self::assertSame([], $this->deductionsOf(X::LINK_FINAL), 'Vazba 1:1 nezakládá řádek čerpání.');

        self::assertNull($this->column('purchase_invoices', 'advance_purchase_invoice_id', X::LINK_FINAL_MULTI));
        self::assertSame([X::LINK_ADVANCE_FALLBACK => 1210.0, X::LINK_ADVANCE_PAIRED => 500.0], $this->deductionsOf(X::LINK_FINAL_MULTI),
            'Faktura odečítající dvě zálohy: řádek čerpání za každou zálohu s částkou odpočtu.');

        [$first, $second] = X::LINK_FINAL_SPLIT;
        self::assertSame($this->purchase(X::LINK_ADVANCE_SPLIT), $this->column('purchase_invoices', 'advance_purchase_invoice_id', $first),
            'První faktura čerpající zálohu dostane vazbu sloupcem.');
        self::assertNull($this->column('purchase_invoices', 'advance_purchase_invoice_id', $second));
        self::assertSame([X::LINK_ADVANCE_SPLIT => 400.0], $this->deductionsOf($second),
            'Záloha čerpaná postupně: další faktura (sloupec je jedinečný) jde do řádků čerpání.');

        $counts = self::stepCounts($this->protocol, 'link');
        self::assertSame(2, $counts['advance_linked'] ?? 0, $this->explain());
        self::assertSame(3, $counts['advance_deductions'] ?? 0, $this->explain());

        $before = $this->rows('purchase_advance_deductions');
        $again = $this->import(false);
        self::assertSame($before, $this->rows('purchase_advance_deductions'), 'Opakovaný převod čerpání nezdvojí.');
        self::assertArrayNotHasKey('advance_linked', self::stepCounts($again, 'link'));
        self::assertArrayNotHasKey('advance_deductions', self::stepCounts($again, 'link'));
    }

    /** 7) linkTaxDocumentsToAdvances: vazba z exportu i z textu dokladu, ověřená partnerem a částkou. */
    public function testTaxDocumentsLinkToAdvancesByLinkOrVerifiedText(): void
    {
        $this->import();

        foreach ([X::TAX_DOCUMENT_LINKED, X::TAX_DOCUMENT_BY_TEXT, X::TAX_DOCUMENT_WRONG_AMOUNT] as $number) {
            self::assertSame('tax_document', $this->column('purchase_invoices', 'document_kind', $number), "DDKP {$number} je převedený: " . $this->explain());
        }
        self::assertSame($this->purchase(X::LINK_ADVANCE), $this->column('purchase_invoices', 'parent_purchase_invoice_id', X::TAX_DOCUMENT_LINKED),
            'DDKP s vazbou na zálohu v exportu dostane rodiče.');
        self::assertSame($this->purchase(X::LINK_ADVANCE_FALLBACK), $this->column('purchase_invoices', 'parent_purchase_invoice_id', X::TAX_DOCUMENT_BY_TEXT),
            'DDKP bez vazby se naváže podle textu POHODY „(zálohová faktura č. …)", sedí-li partner i částka.');
        self::assertNull($this->column('purchase_invoices', 'parent_purchase_invoice_id', X::TAX_DOCUMENT_WRONG_AMOUNT),
            'Text odkazuje na zálohu jiné částky - vazba se nepřijme.');
        self::assertSame(2, self::stepCounts($this->protocol, 'link')['tax_document_advance_linked'] ?? 0, $this->explain());

        $again = $this->import(false);
        self::assertArrayNotHasKey('tax_document_advance_linked', self::stepCounts($again, 'link'));
    }

    public function testMdbTaxDocumentsLinkToTheirAdvanceAndInstallment(): void
    {
        $this->importMdbTaxDocuments(true);

        $advance = $this->invoice(Mdb::ISSUED_ADVANCE);
        [$first, $second] = Mdb::ISSUED_TAX_DOCUMENTS;
        foreach ([$first, $second] as $number) {
            self::assertSame('tax_document', $this->column('invoices', 'invoice_type', $number));
        }
        self::assertSame([$advance, $advance], [
            $this->column('invoices', 'parent_invoice_id', $first),
            $this->column('invoices', 'parent_invoice_id', $second),
        ]);
        self::assertSame(1209.99, (float) $this->column('purchase_invoices', 'total_with_vat', Mdb::RECEIVED_TAX_DOCUMENT));
        self::assertSame($this->purchase(Mdb::RECEIVED_ADVANCE),
            $this->column('purchase_invoices', 'parent_purchase_invoice_id', Mdb::RECEIVED_TAX_DOCUMENT));
        foreach ([Mdb::OTHER_PARTNER_TAX_DOCUMENT, Mdb::TEXT_ONLY_TAX_DOCUMENT] as $number) {
            self::assertSame('tax_document', $this->column('purchase_invoices', 'document_kind', $number));
            self::assertNull($this->column('purchase_invoices', 'parent_purchase_invoice_id', $number));
        }

        $sql = "SELECT p.source, p.amount, t.varsymbol
                  FROM invoice_payments p JOIN invoices t ON t.id = p.tax_document_invoice_id
                 WHERE p.supplier_id = ? AND p.invoice_id = {$advance} ORDER BY p.paid_on";
        $payments = $this->fetchAll($sql);
        self::assertSame([['bank', 605.0, $first], ['cash', 363.0, $second]],
            array_map(static fn (array $r): array => [$r['source'], (float) $r['amount'], $r['varsymbol']], $payments));
        self::assertSame($this->purchase(Mdb::TEXT_ADVANCE),
            $this->column('purchase_invoices', 'parent_purchase_invoice_id', Mdb::TEXT_FALLBACK_TAX_DOCUMENT));
        self::assertSame(4, self::stepCounts($this->protocol, 'link')['tax_document_advance_linked'] ?? 0, $this->explain());
        self::assertNotContains('tax_document_refs_missing', self::messageCodes($this->protocol, 'link'));

        $again = $this->import(false);
        self::assertSame($payments, $this->fetchAll($sql));
        self::assertArrayNotHasKey('tax_document_advance_linked', self::stepCounts($again, 'link'));
        self::assertArrayNotHasKey('tax_document_payment_linked', self::stepCounts($again, 'payments'));
    }

    public function testMdbExportWithoutRefDDUsesVerifiedText(): void
    {
        $this->importMdbTaxDocuments(false);

        self::assertSame($this->purchase(Mdb::RECEIVED_ADVANCE),
            $this->column('purchase_invoices', 'parent_purchase_invoice_id', Mdb::TEXT_ONLY_TAX_DOCUMENT));
        foreach (Mdb::ISSUED_TAX_DOCUMENTS as $number) {
            self::assertSame('tax_document', $this->column('invoices', 'invoice_type', $number));
            self::assertNull($this->column('invoices', 'parent_invoice_id', $number));
        }
        self::assertSame($this->purchase(Mdb::TEXT_ADVANCE),
            $this->column('purchase_invoices', 'parent_purchase_invoice_id', Mdb::TEXT_FALLBACK_TAX_DOCUMENT));
        self::assertSame(2, self::stepCounts($this->protocol, 'link')['tax_document_advance_linked'] ?? 0, $this->explain());
        self::assertContains('tax_document_refs_missing', self::messageCodes($this->protocol, 'link'), $this->explain());
    }

    /** P2-c: upozornění na starý export počítá vazby provedené v tomto běhu a DDKP, které zůstaly bez vazby. */
    public function testOldExportWarningCountsLinksMadeInThisRun(): void
    {
        $this->importMdbTaxDocuments(false);
        self::assertSame(['count' => 6, 'by_text' => 2, 'unlinked' => 4], self::message($this->protocol, 'link', 'tax_document_refs_missing')['context'] ?? null);

        $again = $this->import(false);
        self::assertSame(['count' => 6, 'by_text' => 0, 'unlinked' => 4], self::message($again, 'link', 'tax_document_refs_missing')['context'] ?? null, $this->explain($again));
    }

    /** P1-a: převod opraví vazbu DDKP z textu staršího exportu podle RefDD, platba zálohy B dostane DDKP. */
    public function testRerunMovesTextLinkToAdvanceOfRefDDPayment(): void
    {
        $this->importMdbTaxDocuments(false, Mdb::SCENARIO_RELINK);
        $taxDocument = Mdb::RELINK_TAX_DOCUMENT;
        self::assertSame($this->invoice(Mdb::RELINK_TEXT_ADVANCE), $this->column('invoices', 'parent_invoice_id', $taxDocument));

        $this->exportDir = Mdb::writeTaxDocumentPayments($this->tmp, true, Mdb::SCENARIO_RELINK);
        $again = $this->import(false);

        $paid = $this->invoice(Mdb::RELINK_PAID_ADVANCE);
        self::assertSame($paid, $this->column('invoices', 'parent_invoice_id', $taxDocument), $this->explain($again));
        self::assertSame(1, self::stepCounts($again, 'link')['tax_document_advance_relinked'] ?? 0);
        self::assertSame([$this->invoice($taxDocument)], array_map('intval', array_column(
            $this->fetchAll("SELECT tax_document_invoice_id FROM invoice_payments WHERE supplier_id = ? AND invoice_id = {$paid}"), 'tax_document_invoice_id')));
    }

    /** P1-a: v období s podaným přiznáním k DPH převod vazbu DDKP nemění a rozpor ohlásí. */
    public function testRerunKeepsTextLinkInFiledVatPeriodAndWarns(): void
    {
        $this->importMdbTaxDocuments(false, Mdb::SCENARIO_RELINK);
        $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, form_variant, status, submitted_at, xml_content, xml_size_bytes, xml_sha256)
             VALUES (?, 'dphdp3', 2026, 2, 'B', 'submitted', '2026-03-25 10:00:00', '<Pisemnost/>', 12, ?)"
        )->execute([$this->supplierId, hash('sha256', '<Pisemnost/>')]);

        $this->exportDir = Mdb::writeTaxDocumentPayments($this->tmp, true, Mdb::SCENARIO_RELINK);
        $again = $this->import(false);

        self::assertSame($this->invoice(Mdb::RELINK_TEXT_ADVANCE), $this->column('invoices', 'parent_invoice_id', Mdb::RELINK_TAX_DOCUMENT));
        $warning = self::message($again, 'link', 'tax_document_advance_conflict');
        self::assertSame(Mdb::RELINK_TAX_DOCUMENT, $warning['context']['document_no'] ?? null, $this->explain($again));
        self::assertStringContainsString(Mdb::RELINK_PAID_ADVANCE, (string) ($warning['text'] ?? ''));
        self::assertSame(0, $this->rows('invoice_payments', 'tax_document_invoice_id = ' . $this->invoice(Mdb::RELINK_TAX_DOCUMENT)));
    }

    /** P1-b: platba nalezená jen podle čísla pohybu či pokladního dokladu se k DDKP neváže, rodič DDKP ano. */
    public function testPaymentFoundOnlyByNumberIsNotLinkedToTaxDocument(): void
    {
        $this->importMdbTaxDocuments(true, Mdb::SCENARIO_NUMBER_ONLY);

        $advance = $this->invoice(Mdb::NUMBER_ONLY_ADVANCE);
        foreach (Mdb::NUMBER_ONLY_TAX_DOCUMENTS as $number) {
            self::assertSame($advance, $this->column('invoices', 'parent_invoice_id', $number), $this->explain());
        }
        $payments = $this->fetchAll("SELECT source, tax_document_invoice_id FROM invoice_payments WHERE supplier_id = ? AND invoice_id = {$advance} ORDER BY paid_on");
        self::assertSame([['bank', null], ['cash', null]], array_map(static fn (array $r): array => [$r['source'], $r['tax_document_invoice_id']], $payments));
        $uncertain = array_filter(self::messageCodes($this->protocol, 'payments'), static fn (string $c): bool => $c === 'tax_document_payment_uncertain');
        self::assertCount(2, $uncertain, $this->explain());
    }

    /** P1-c: DDKP s RefDD k záloze mimo export nejde podle textu na jinou zálohu se stejným číslem. */
    public function testTaxDocumentWithRefDDToMissingAdvanceIsNotLinkedByText(): void
    {
        $this->importMdbTaxDocuments(true, Mdb::SCENARIO_MISSING_ADVANCE);

        self::assertNull($this->column('purchase_invoices', 'parent_purchase_invoice_id', Mdb::MISSING_ADVANCE_TAX_DOCUMENT), $this->explain());
        self::assertSame(Mdb::MISSING_ADVANCE_TAX_DOCUMENT, self::message($this->protocol, 'link', 'tax_document_advance_missing')['context']['document_no'] ?? null);
    }

    /** P2-a: stornovaný DDKP rodiče nedostane, DDKP v konceptu nedaní platbu. */
    public function testCancelledAndDraftTaxDocumentsAreNotLinked(): void
    {
        $this->importMdbTaxDocuments(false);
        [$draft, $cancelled] = Mdb::ISSUED_TAX_DOCUMENTS;
        $status = $this->db->pdo()->prepare('UPDATE invoices SET status = ? WHERE supplier_id = ? AND varsymbol = ?');
        $status->execute(['draft', $this->supplierId, $draft]);
        $status->execute(['cancelled', $this->supplierId, $cancelled]);

        $this->exportDir = Mdb::writeTaxDocumentPayments($this->tmp, true);
        $again = $this->import(false);

        $advance = $this->invoice(Mdb::ISSUED_ADVANCE);
        self::assertSame($advance, $this->column('invoices', 'parent_invoice_id', $draft));
        self::assertNull($this->column('invoices', 'parent_invoice_id', $cancelled), $this->explain($again));
        self::assertSame(0, $this->rows('invoice_payments', 'tax_document_invoice_id IS NOT NULL'));
        self::assertContains('tax_document_advance_cancelled', self::messageCodes($again, 'link'));
        self::assertContains('tax_document_payment_not_linked', self::messageCodes($again, 'payments'));
    }

    /** P2-b: jeden DDKP k více platbám zálohy dostane rodiče, platby zůstanou bez DDKP a převod to ohlásí. */
    public function testTaxDocumentOfSeveralPaymentsIsReported(): void
    {
        $this->importMdbTaxDocuments(true, '', ['2' => '101']);

        $advance = $this->invoice(Mdb::ISSUED_ADVANCE);
        self::assertSame($advance, $this->column('invoices', 'parent_invoice_id', Mdb::ISSUED_TAX_DOCUMENTS[0]));
        self::assertSame(0, $this->rows('invoice_payments', 'tax_document_invoice_id IS NOT NULL'));
        $warning = self::message($this->protocol, 'payments', 'tax_document_multiple_payments');
        self::assertSame(Mdb::ISSUED_TAX_DOCUMENTS[0], $warning['context']['document_no'] ?? null, $this->explain());
        self::assertSame(['TEST-B1', 'TEST-K1'], $warning['context']['sources'] ?? null);
    }

    /** P2-b: DDKP úhrad dvou záloh zůstane bez rodiče a převod to ohlásí. */
    public function testTaxDocumentOfSeveralAdvancesIsReported(): void
    {
        $this->importMdbTaxDocuments(true, Mdb::SCENARIO_TWO_ADVANCES);

        self::assertNull($this->column('purchase_invoices', 'parent_purchase_invoice_id', Mdb::RECEIVED_TAX_DOCUMENT));
        $warning = self::message($this->protocol, 'link', 'tax_document_multiple_advances');
        self::assertSame(Mdb::RECEIVED_TAX_DOCUMENT, $warning['context']['document_no'] ?? null, $this->explain());
        self::assertSame([Mdb::RECEIVED_ADVANCE, Mdb::TEXT_ADVANCE], $warning['context']['advances'] ?? null);
    }

    /** Kolize s unikátní vazbou DDKP na platbu (1062) převod nezastaví, jen ji ohlásí. */
    public function testTaxDocumentAlreadyLinkedToAnotherPaymentIsReported(): void
    {
        $this->importMdbTaxDocuments(false);
        $advance = $this->invoice(Mdb::ISSUED_ADVANCE);
        $first = $this->invoice(Mdb::ISSUED_TAX_DOCUMENTS[0]);
        $this->db->pdo()->prepare("UPDATE invoice_payments SET tax_document_invoice_id = ? WHERE supplier_id = ? AND invoice_id = ? AND source = 'cash'")
            ->execute([$first, $this->supplierId, $advance]);

        $this->exportDir = Mdb::writeTaxDocumentPayments($this->tmp, true);
        $again = $this->import(false);

        self::assertSame($advance, $this->column('invoices', 'parent_invoice_id', Mdb::ISSUED_TAX_DOCUMENTS[0]));
        self::assertSame([['cash', $first]], array_map(static fn (array $r): array => [$r['source'], (int) $r['tax_document_invoice_id']],
            $this->fetchAll("SELECT source, tax_document_invoice_id FROM invoice_payments WHERE supplier_id = ? AND tax_document_invoice_id IS NOT NULL")));
        self::assertContains('tax_document_payment_taken', self::messageCodes($again, 'payments'), $this->explain($again));
    }

    /** @return array<string,mixed> první zpráva kroku s kódem */
    private static function message(?ImportProtocol $protocol, string $step, string $code): array
    {
        foreach ($protocol?->toArray()['steps'] ?? [] as $s) {
            if ($s['key'] === $step) {
                foreach ($s['messages'] ?? [] as $m) {
                    if (($m['code'] ?? '') === $code) {
                        return $m;
                    }
                }
            }
        }
        return [];
    }

    /** @return list<string> */
    private static function messageCodes(ImportProtocol $protocol, string $step): array
    {
        foreach ($protocol->toArray()['steps'] as $s) {
            if ($s['key'] === $step) {
                return array_values(array_map(static fn (array $m): string => (string) ($m['code'] ?? ''), $s['messages'] ?? []));
            }
        }
        return [];
    }

    /** 5) offsetCreditNote: faktura a dobropis vyrovnané interním dokladem = zápočet dobropisu. */
    public function testCreditNoteSettledByInternalDocumentBecomesCreditNoteOffset(): void
    {
        $this->import();
        $invoice = $this->invoice(X::OFFSET_INVOICE);
        $note = $this->invoice(X::OFFSET_CREDIT_NOTE);

        self::assertSame('credit_note', $this->column('invoices', 'invoice_type', X::OFFSET_CREDIT_NOTE, 'varsymbol'));
        self::assertSame($invoice, $this->column('invoices', 'parent_invoice_id', X::OFFSET_CREDIT_NOTE, 'varsymbol'),
            'Dobropis dostane vazbu na opravovanou fakturu.');
        $offsets = $this->fetchAll('SELECT doc_type, invoice_id, credit_note_id, amount, offset_on, invoice_payment_id FROM credit_note_offsets WHERE supplier_id = ?');
        self::assertCount(1, $offsets, $this->explain());
        self::assertSame(['invoice', $invoice, $note, 1210.0, '2026-02-25'],
            [$offsets[0]['doc_type'], (int) $offsets[0]['invoice_id'], (int) $offsets[0]['credit_note_id'], (float) $offsets[0]['amount'], $offsets[0]['offset_on']]);
        self::assertNotNull($offsets[0]['invoice_payment_id'], 'U vydané faktury i evidovaná platba.');
        $payment = $this->fetchAll('SELECT invoice_id, amount, source FROM invoice_payments WHERE id = ' . (int) $offsets[0]['invoice_payment_id']);
        self::assertSame([$invoice, 1210.0, 'credit_note'], [(int) $payment[0]['invoice_id'], (float) $payment[0]['amount'], $payment[0]['source']]);
        self::assertSame(1, self::stepCounts($this->protocol, 'payments')['internal_credit_note_offset'] ?? 0, $this->explain());

        $this->import(false);
        self::assertSame(1, $this->rows('credit_note_offsets'), 'Opakovaný převod zápočet nezdvojí.');
        self::assertSame(1, $this->rows('invoice_payments', "source = 'credit_note'"));
    }

    /** 8) inferAdvanceSettlements: vrácení zálohy podle VS a odpočet obyčejnou položkou FIFO. */
    public function testAdvanceRefundAndPlainItemDeductionAreInferredFrom314(): void
    {
        $this->import();

        self::assertSame([300.0], $this->refundsOf(X::REFUND_ADVANCE),
            'Příchozí platba na 314 s VS zálohy (= číslo dokladu dodavatele) se spáruje se zálohou: ' . $this->explain());

        [$older, $newer] = X::FIFO_ADVANCES;
        self::assertSame([$older => 500.0, $newer => 200.0], $this->deductionsOf(X::FIFO_FINAL),
            'Odečet zálohy obyčejnou položkou čerpá zálohy dodavatele od nejstarší do jejich zůstatku.');
        $counts = self::stepCounts($this->protocol, 'payments');
        $refunds = $this->rows('payment_matches', 'purchase_invoice_id IS NOT NULL AND bank_transaction_id IN (SELECT id FROM bank_transactions WHERE amount > 0)');
        self::assertSame($refunds, $counts['advance_refund_matched'] ?? 0, $this->explain());
        self::assertSame(2, $counts['advance_deduction_inferred'] ?? 0, $this->explain());

        $matches = $this->rows('payment_matches');
        $deductions = $this->rows('purchase_advance_deductions');
        $again = $this->import(false);
        self::assertSame($matches, $this->rows('payment_matches'), 'Opakovaný převod vrácení nezdvojí.');
        self::assertSame($deductions, $this->rows('purchase_advance_deductions'), 'Opakovaný převod odpočet nezdvojí.');
        self::assertArrayNotHasKey('advance_refund_matched', self::stepCounts($again, 'payments'));
        self::assertArrayNotHasKey('advance_deduction_inferred', self::stepCounts($again, 'payments'));
    }

    /**
     * Vrácení zálohy s VS zálohy (`typ:symVar`, v MyÚčtu `payment_variable_symbol`), který se
     * liší od čísla dokladu dodavatele i od čísla dokladu POHODY.
     */
    public function testAdvanceRefundMatchesByAdvancePaymentVariableSymbol(): void
    {
        $this->import();
        $advance = $this->purchase(X::REFUND_ADVANCE_BY_VS);
        self::assertSame(2026108, $this->column('purchase_invoices', 'payment_variable_symbol', X::REFUND_ADVANCE_BY_VS), 'Fixture: VS zálohy je převedený.');
        self::assertSame('ZF-2026-8', $this->column('purchase_invoices', 'vendor_invoice_number', X::REFUND_ADVANCE_BY_VS));

        self::assertNotSame(0, $advance);
        self::assertSame([400.0], $this->refundsOf(X::REFUND_ADVANCE_BY_VS));
    }

    /** 9) recordBankFxSettlements: kurzový rozdíl v zápisu úhrady = zápočet proti 663/563. */
    public function testFxDifferenceInPaymentEntryBecomesSettlement(): void
    {
        $this->import();

        $settlements = $this->fetchAll(
            "SELECT s.doc_type, s.doc_id, s.amount, s.settled_on, a.account_code, s.journal_entry_id, s.invoice_payment_id, e.source_type
               FROM invoice_settlements s JOIN chart_of_accounts a ON a.id = s.account_id
               LEFT JOIN journal_entries e ON e.id = s.journal_entry_id
              WHERE s.supplier_id = ? ORDER BY s.doc_type"
        );
        self::assertCount(2, $settlements, 'Zápočet kurzového rozdílu u přijaté i vydané faktury: ' . $this->explain());
        [$issued, $purchase] = $settlements;

        self::assertSame(['purchase_invoice', $this->purchase(X::FX_PURCHASE), 10.0, '2026-02-20', 'bank'],
            [$purchase['doc_type'], (int) $purchase['doc_id'], (float) $purchase['amount'], $purchase['settled_on'], $purchase['source_type']]);
        self::assertStringStartsWith('663', (string) $purchase['account_code'], 'Protiúčet = kurzový zisk z řádku zápisu úhrady.');
        self::assertNull($purchase['invoice_payment_id'], 'Přijatá faktura evidovanou platbu nemá.');

        self::assertSame(['invoice', $this->invoice(X::FX_ISSUED), 10.0, '2026-02-21', 'bank'],
            [$issued['doc_type'], (int) $issued['doc_id'], (float) $issued['amount'], $issued['settled_on'], $issued['source_type']]);
        self::assertStringStartsWith('563', (string) $issued['account_code'], 'Protiúčet = kurzová ztráta z řádku zápisu úhrady.');
        self::assertNotNull($issued['invoice_payment_id']);
        $payments = $this->fetchAll('SELECT source, amount FROM invoice_payments WHERE invoice_id = ' . $this->invoice(X::FX_ISSUED) . ' ORDER BY source');
        self::assertSame([['bank', 1200.0], ['settlement', 10.0]], array_map(static fn (array $r): array => [$r['source'], (float) $r['amount']], $payments),
            'Vydaná faktura: banka 1 200 + evidovaná platba zápočtu 10 = uhrazeno 1 210.');
        self::assertSame(2, self::stepCounts($this->protocol, 'payments')['bank_fx_settlement'] ?? 0, $this->explain());

        $again = $this->import(false);
        self::assertSame(2, $this->rows('invoice_settlements'), 'Opakovaný převod zápočet nezdvojí.');
        self::assertSame(1, $this->rows('invoice_payments', "source = 'settlement'"));
        self::assertArrayNotHasKey('bank_fx_settlement', self::stepCounts($again, 'payments'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string,string> $refDD */
    private function importMdbTaxDocuments(bool $withRefDD, string $scenario = '', array $refDD = []): void
    {
        $this->supplierId = $this->supplier();
        $this->exportDir = Mdb::writeTaxDocumentPayments($this->tmp, $withRefDD, $scenario, $refDD);
        $this->protocol = $this->import(false);
    }

    private function import(bool $fresh = true): ImportProtocol
    {
        if ($fresh) {
            $this->supplierId = $this->supplier();
            $dir = X::write($this->tmp);
            X::withAdvancesAndSettlements($dir);
            $this->exportDir = $dir;
        }
        $protocol = $this->importer->run($this->supplierId, $this->userId, PohodaExport::open($this->exportDir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        if ($fresh) {
            $this->protocol = $protocol;
        }
        return $protocol;
    }

    private function purchase(string $number): int
    {
        $id = $this->column('purchase_invoices', 'id', $number);
        self::assertNotNull($id, "Přijatý doklad {$number} chybí: " . $this->explain());
        return $id;
    }

    private function invoice(string $number): int
    {
        $id = $this->column('invoices', 'id', $number, 'varsymbol');
        self::assertNotNull($id, "Vydaný doklad {$number} chybí: " . $this->explain());
        return $id;
    }

    /** Hodnota sloupce dokladu podle čísla POHODY (přijatý: `varsymbol` = číslo dokladu). */
    private function column(string $table, string $column, string $number, string $by = 'varsymbol'): int|string|null
    {
        $stmt = $this->db->pdo()->prepare("SELECT {$column} FROM {$table} WHERE supplier_id = ? AND {$by} = ?");
        $stmt->execute([$this->supplierId, $number]);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }
        return is_numeric($value) && ctype_digit((string) $value) ? (int) $value : (string) $value;
    }

    /** @return array<string,float> číslo zálohy => čerpáno */
    private function deductionsOf(string $finalNumber): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.varsymbol, d.amount FROM purchase_advance_deductions d
               JOIN purchase_invoices f ON f.id = d.final_invoice_id
               JOIN purchase_invoices a ON a.id = d.advance_invoice_id
              WHERE d.supplier_id = ? AND f.varsymbol = ? ORDER BY a.varsymbol'
        );
        $stmt->execute([$this->supplierId, $finalNumber]);
        return array_map('floatval', $stmt->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    /** @return list<float> spárované příchozí platby (vrácení) zálohy */
    private function refundsOf(string $advanceNumber): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pm.amount FROM payment_matches pm
               JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id AND bt.amount > 0
               JOIN purchase_invoices a ON a.id = pm.purchase_invoice_id
              WHERE pm.supplier_id = ? AND a.varsymbol = ? ORDER BY pm.id'
        );
        $stmt->execute([$this->supplierId, $advanceNumber]);
        return array_map('floatval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<array<string,mixed>> */
    private function fetchAll(string $sql): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(str_contains($sql, '?') ? [$this->supplierId] : []);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function rows(string $table, string $where = '1 = 1'): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$where}");
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,int|float> */
    private static function stepCounts(?ImportProtocol $protocol, string $key): array
    {
        foreach ($protocol?->toArray()['steps'] ?? [] as $step) {
            if ($step['key'] === $key) {
                return $step['counts'];
            }
        }
        return [];
    }

    private function supplier(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, ?, ?, "tax_evidence")'
        )->execute([X::NAME, $this->czId, X::ICO, 'CZ' . X::ICO, $this->anyCurrencyId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    private function explain(?ImportProtocol $protocol = null): string
    {
        $protocol ??= $this->protocol;
        if ($protocol === null) {
            return '';
        }
        return (string) json_encode(['steps' => $protocol->toArray()['steps'], 'preflight' => $protocol->get('preflight')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
