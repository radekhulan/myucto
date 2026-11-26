<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaOtherLiabilityRepair;
use MyInvoice\Service\Migration\Shared\MigratedDocumentItem;
use MyInvoice\Service\Migration\Shared\MigratedDocumentWriter;
use MyInvoice\Service\Migration\Shared\MigratedPurchaseDocument;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ostatní závazky POHODY: převod a oprava firem, kterým starší převod založil ostatní
 * závazky jako přijaté faktury bez zápisu v deníku. Izolovaná firma, transakce s rollbackem.
 */
#[Group('integration')]
final class PohodaOtherLiabilityTest extends TestCase
{
    private Connection $db;
    private PohodaImporter $importer;
    private PohodaOtherLiabilityRepair $repair;
    private MigratedDocumentWriter $writer;
    private string $tmp = '';
    private int $userId = 0;
    private int $czkId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(PohodaImporter::class);
            $this->repair = $container->get(PohodaOtherLiabilityRepair::class);
            $this->writer = $container->get(MigratedDocumentWriter::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czkId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->czkId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_oz_' . bin2hex(random_bytes(5));
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

    /**
     * Ostatní závazek s DPH, který POHODA zaúčtovala pod číslem z doby před prodloužením
     * číselné řady (deník 26OZR033, doklad 26OZR0033), je přijatá faktura navázaná na svůj
     * zápis - ne doklad bez zaúčtování, který by Doúčtování zaúčtovalo podruhé.
     */
    public function testRenumberedVatCommitmentLinksItsJournalEntry(): void
    {
        $supplierId = $this->importRenumbered();
        $pi = $this->one("SELECT id FROM purchase_invoices WHERE supplier_id = {$supplierId} AND varsymbol = '" . SyntheticPohodaExport::RENUMBERED_VAT_COMMITMENT . "'");
        self::assertNotFalse($pi);
        self::assertSame('1', (string) $this->one("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$supplierId}
            AND document_no = '26OZR033' AND source_type = 'purchase_invoice' AND source_id = {$pi}"));
        self::assertSame('1', (string) $this->one("SELECT COUNT(*) FROM journal_entry_document_links WHERE supplier_id = {$supplierId}
            AND doc_type = 'purchase_invoice' AND doc_id = {$pi}"));
    }

    /** Přečíslovaný doklad zaúčtovaný později než vystavený: zápis nese datum zaúčtování. */
    public function testRenumberedCommitmentBookedLaterLinksEntryOfAccountingDate(): void
    {
        $supplierId = $this->importRenumbered();
        $pi = $this->one("SELECT id FROM purchase_invoices WHERE supplier_id = {$supplierId} AND varsymbol = '" . SyntheticPohodaExport::RENUMBERED_BOOKED_LATER . "'");
        self::assertNotFalse($pi);
        self::assertSame('1', (string) $this->one("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$supplierId}
            AND document_no = '26OZR050' AND source_type = 'purchase_invoice' AND source_id = {$pi}"));
    }

    /** Přečíslovaný ostatní závazek mimo přiznání převezme svůj zápis bez nového účtování. */
    public function testRenumberedCommitmentOutsideReturnTakesOverItsJournalEntry(): void
    {
        $supplierId = $this->importRenumbered();
        $item = $this->db->pdo()->query("SELECT id, status, account_code, journal_entry_id FROM other_items
            WHERE supplier_id = {$supplierId} AND document_no = '" . SyntheticPohodaExport::RENUMBERED_COMMITMENT . "'")->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($item);
        self::assertSame(['posted', '325.000'], [$item['status'], $item['account_code']]);
        self::assertSame('26OZR040', (string) $this->one("SELECT document_no FROM journal_entries WHERE id = " . (int) $item['journal_entry_id']));
        self::assertSame('other_item', (string) $this->one("SELECT source_type FROM journal_entries WHERE id = " . (int) $item['journal_entry_id']));
        self::assertSame('0', (string) $this->one("SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = {$supplierId}
            AND varsymbol = '" . SyntheticPohodaExport::RENUMBERED_COMMITMENT . "'"));
    }

    /**
     * Stav po převodu před 6.72: ostatní závazky bez DPH jsou přijaté faktury. Oprava je
     * převede na ostatní závazky - bez nového zápisu v deníku, s úhradami z banky, se
     * zápisem dokladu, který ho v POHODĚ má - a opakovaný převod ani oprava nic nezdvojí.
     */
    public function testRepairConvertsLegacyCommitmentInvoicesWithoutNewPosting(): void
    {
        [$supplierId, $export] = $this->importPayroll();
        $wage = $this->legacy($supplierId, SyntheticPohodaExport::PAYROLL_WAGE);
        $openingTax = $this->legacy($supplierId, SyntheticPohodaExport::PAYROLL_OPENING_TAX, opening: true);
        $share = $this->legacy($supplierId, '26OZB0001');
        $insurance = $this->legacy($supplierId, SyntheticPohodaExport::PAYROLL_INSURANCE);
        $journal = $this->rows('journal_entries', $supplierId);
        $lines = $this->rows('journal_entry_lines', $supplierId);
        $purchases = $this->rows('purchase_invoices', $supplierId);
        $items = $this->rows('other_items', $supplierId);

        $dry = $this->repair->run([$supplierId], false)[0];
        self::assertSame('would_repair', $dry['status'], json_encode($dry, JSON_UNESCAPED_UNICODE));
        // Daň ze mzdy minulého roku visela jako faktura v saldokontu 321; deník ji vede na 342.
        self::assertSame([-1500.0, 0.0], [$dry['saldo']['321']['before'], $dry['saldo']['321']['after']]);
        self::assertSame(3, $dry['totals']['converted']);
        self::assertSame(1, $dry['totals']['posted_kept']);
        self::assertSame($purchases, $this->rows('purchase_invoices', $supplierId), 'Nanečisto se nic nemění.');
        self::assertSame($items, $this->rows('other_items', $supplierId));

        $done = $this->repair->run([$supplierId], true)[0];
        self::assertSame('repaired', $done['status'], json_encode($done, JSON_UNESCAPED_UNICODE));
        self::assertSame([3, 0, 0], [$done['totals']['converted'], $done['totals']['refused'], $done['totals']['posted']]);
        $actions = array_column($done['documents'], 'action', 'document_no');
        self::assertSame(['other_item', 'other_item', 'other_item'], [$actions[SyntheticPohodaExport::PAYROLL_WAGE],
            $actions[SyntheticPohodaExport::PAYROLL_OPENING_TAX], $actions['26OZB0001']]);
        self::assertSame('364.000', array_column($done['documents'], 'account', 'document_no')['26OZB0001']);
        self::assertSame($purchases - 3, $this->rows('purchase_invoices', $supplierId));
        self::assertSame(0, $this->rows('purchase_invoices', $supplierId, "id IN ({$wage}, {$openingTax}, {$share})"));
        self::assertSame($journal, $this->rows('journal_entries', $supplierId), 'Oprava nezakládá zápisy.');
        self::assertSame($lines, $this->rows('journal_entry_lines', $supplierId));

        $item = fn (string $number): array => $this->db->pdo()->query("SELECT id, status, account_code, amount, journal_entry_id, settled_outside_amount
            FROM other_items WHERE supplier_id = {$supplierId} AND document_no = '{$number}'")->fetch(\PDO::FETCH_ASSOC) ?: [];
        $row = $item(SyntheticPohodaExport::PAYROLL_WAGE);
        self::assertSame(['confirmed', '20000.00', null], [$row['status'], (string) $row['amount'], $row['journal_entry_id']]);
        $row = $item(SyntheticPohodaExport::PAYROLL_OPENING_TAX);
        self::assertSame('confirmed', $row['status']);
        self::assertSame(0, $this->rows('journal_entry_document_links', $supplierId, "doc_type = 'purchase_invoice' AND doc_id = {$openingTax}"));
        // Účet závazku z úhrady (zápis banky 364/221), úhrada 400 Kč z banky přiřazená položce.
        $row = $item('26OZB0001');
        self::assertSame(['confirmed', '364.000', '900.00'], [$row['status'], $row['account_code'], (string) $row['amount']]);
        self::assertSame('400.00', (string) $this->db->pdo()->query("SELECT SUM(amount) FROM other_item_allocations WHERE other_item_id = {$row['id']}")->fetchColumn());
        self::assertSame(0, $this->rows('payment_matches', $supplierId, "purchase_invoice_id = {$share}"));
        self::assertSame(1, $this->rows('pohoda_import_map', $supplierId, "kind = 'payment' AND pohoda_key LIKE 'other_item|{$row['id']}|%'"));
        self::assertSame(0, $this->rows('pohoda_import_map', $supplierId, "kind = 'payment' AND pohoda_key LIKE 'purchase_invoice|{$share}|%'"));
        self::assertSame(3, $this->rows('pohoda_import_map', $supplierId, "kind = 'other_item' AND pohoda_key IN ('commitment|"
            . SyntheticPohodaExport::PAYROLL_WAGE . "|2026-02-08', 'commitment|" . SyntheticPohodaExport::PAYROLL_OPENING_TAX . "|2025-12-31', 'commitment|26OZB0001|2026-02-05')"));
        self::assertGreaterThan(0, $this->rows('activity_log', $supplierId, "action = '" . PohodaOtherLiabilityRepair::AUDIT_ACTION . "'"));

        // Zaúčtovaná faktura z ostatního závazku jen s --include-posted: převezme svůj zápis.
        $posted = $this->repair->run([$supplierId], true, null, true)[0];
        self::assertSame([1, 1], [$posted['totals']['converted'], $posted['totals']['posted']], json_encode($posted, JSON_UNESCAPED_UNICODE));
        $row = $item(SyntheticPohodaExport::PAYROLL_INSURANCE);
        self::assertSame(['posted', '336.000'], [$row['status'], $row['account_code']]);
        self::assertSame(1, $this->rows('journal_entries', $supplierId, "id = {$row['journal_entry_id']} AND source_type = 'other_item' AND source_id = {$row['id']}"));
        self::assertSame('6000.00', (string) $this->db->pdo()->query("SELECT SUM(amount) FROM other_item_allocations WHERE other_item_id = {$row['id']}")->fetchColumn());
        self::assertSame(0, $this->rows('purchase_invoices', $supplierId, "id = {$insurance}"));
        self::assertSame($journal, $this->rows('journal_entries', $supplierId));

        $again = $this->repair->run([$supplierId], true, null, true)[0];
        self::assertSame('nothing', $again['status']);
        self::assertSame([], $again['documents']);

        $items = $this->rows('other_items', $supplierId);
        $allocations = $this->rows('other_item_allocations', $supplierId);
        $purchases = $this->rows('purchase_invoices', $supplierId);
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([$items, $allocations, $purchases, $journal], [$this->rows('other_items', $supplierId),
            $this->rows('other_item_allocations', $supplierId), $this->rows('purchase_invoices', $supplierId), $this->rows('journal_entries', $supplierId)]);
    }

    /** Doklad za zámkem účtování a doklad s víc možnými zápisy se nemění, jen se ohlásí. */
    public function testRepairRefusesLockedAndAmbiguousDocuments(): void
    {
        [$supplierId] = $this->importPayroll();
        $wage = $this->legacy($supplierId, SyntheticPohodaExport::PAYROLL_WAGE);
        $share = $this->legacy($supplierId, '26OZB0001');
        $pdo = $this->db->pdo();
        $free = $pdo->query("SELECT e.id FROM journal_entries e WHERE e.supplier_id = {$supplierId} AND e.source_type = 'manual' AND e.source_id IS NULL
            AND NOT EXISTS (SELECT 1 FROM journal_entry_document_links l WHERE l.entry_id = e.id)
            AND NOT EXISTS (SELECT 1 FROM other_items oi WHERE oi.journal_entry_id = e.id) ORDER BY e.id LIMIT 2")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(2, $free);
        foreach (['2026-02-08', '2026-02-09'] as $i => $date) {
            $pdo->prepare("INSERT INTO pohoda_import_map (supplier_id, kind, pohoda_key, target_id) VALUES (?, 'journal_entry', ?, ?)")
                ->execute([$supplierId, '2026|Ostatní závazky|' . SyntheticPohodaExport::PAYROLL_WAGE . '|' . $date, (int) $free[$i]]);
        }
        $pdo->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?) ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')
            ->execute([$supplierId, '2026-02-05']);

        $r = $this->repair->run([$supplierId], true)[0];
        self::assertSame('nothing', $r['status'], json_encode($r, JSON_UNESCAPED_UNICODE));
        self::assertSame(2, $r['totals']['refused']);
        $reasons = array_column($r['documents'], 'reason', 'document_no');
        self::assertStringContainsString('víc zápisů', (string) $reasons[SyntheticPohodaExport::PAYROLL_WAGE]);
        self::assertStringContainsString('zámkem', (string) $reasons['26OZB0001']);
        self::assertSame(2, $this->rows('purchase_invoices', $supplierId, "id IN ({$wage}, {$share})"));
        self::assertSame(1, $this->rows('payment_matches', $supplierId, "purchase_invoice_id = {$share}"));
    }

    /**
     * Ostatní závazek s DPH je přijatá faktura oprávněně: oprava ji nepřevádí, jen ji naváže
     * na zápis s původním číslem přečíslovaného dokladu, který jí dřívější převod nedal.
     */
    public function testRepairLinksRenumberedVatCommitmentToItsEntry(): void
    {
        $supplierId = $this->importRenumbered();
        $pdo = $this->db->pdo();
        $pi = (int) $this->one("SELECT id FROM purchase_invoices WHERE supplier_id = {$supplierId} AND varsymbol = '" . SyntheticPohodaExport::RENUMBERED_VAT_COMMITMENT . "'");
        $entry = (int) $this->one("SELECT id FROM journal_entries WHERE supplier_id = {$supplierId} AND source_type = 'purchase_invoice' AND source_id = {$pi}");
        $pdo->exec("UPDATE journal_entries SET source_type = 'manual', source_id = NULL WHERE id = {$entry}");
        $pdo->exec("DELETE FROM journal_entry_document_links WHERE doc_type = 'purchase_invoice' AND doc_id = {$pi}");
        $pdo->exec("DELETE FROM journal_document_postings WHERE doc_type = 'purchase_invoice' AND doc_id = {$pi}");

        $r = $this->repair->run([$supplierId], true)[0];
        self::assertSame([1, 0], [$r['totals']['linked'], $r['totals']['converted']], json_encode($r, JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $this->rows('journal_entries', $supplierId, "id = {$entry} AND source_type = 'purchase_invoice' AND source_id = {$pi}"));
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "id = {$pi}"));
        self::assertSame('nothing', $this->repair->run([$supplierId], true)[0]['status']);
    }

    /**
     * Ostatní závazek s členěním do přiznání a nulovou daní převod bere jako fakturu; oprava
     * ho podle evidence DPH (v přiznání nic nenese) převede na ostatní závazek. Opakovaný
     * převod pak fakturu znovu nezaloží, i když ji členění POHODY pořád řadí do přiznání.
     */
    public function testReimportDoesNotRecreateInvoiceOfRepairedCommitment(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOtherItems($dir);
        SyntheticPohodaExport::withZeroVatClassifiedCommitment($dir);
        $export = PohodaExport::open($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $number = SyntheticPohodaExport::ZERO_VAT_CLASSIFIED_COMMITMENT;
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "varsymbol = '{$number}'"));

        $r = $this->repair->run([$supplierId], true)[0];
        self::assertSame(1, $r['totals']['converted'], json_encode($r, JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $this->rows('purchase_invoices', $supplierId, "varsymbol = '{$number}'"));

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(0, $this->rows('purchase_invoices', $supplierId, "varsymbol = '{$number}'"), 'Opakovaný převod fakturu znovu nezaloží.');
        self::assertSame(1, $this->rows('other_items', $supplierId, "document_no = '{$number}'"));
    }

    /**
     * Doklad, na který navazuje něco, co oprava nepřevádí (poznámka peněžního deníku, připojený soubor,
     * další zápis se zdrojem faktury nebo ve skupině zaúčtování), zůstává přijatou fakturou.
     */
    public function testRepairRefusesDocumentsWithReferencesOutsideTheConversion(): void
    {
        [$supplierId] = $this->importPayroll();
        $wage = $this->legacy($supplierId, SyntheticPohodaExport::PAYROLL_WAGE);
        $openingTax = $this->legacy($supplierId, SyntheticPohodaExport::PAYROLL_OPENING_TAX, opening: true);
        $share = $this->legacy($supplierId, '26OZB0001');
        $insurance = $this->legacy($supplierId, SyntheticPohodaExport::PAYROLL_INSURANCE);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO cash_journal_notes (supplier_id, source_type, source_id, body) VALUES (?, 'purchase_invoice', ?, 'Poznámka')")
            ->execute([$supplierId, $wage]);
        $pdo->exec("UPDATE purchase_invoices SET pdf_path = 'purchase/test.pdf' WHERE id = {$openingTax}");
        $period = (int) $this->one("SELECT id FROM accounting_periods WHERE supplier_id = {$supplierId} AND starts_on <= '2026-02-05' AND ends_on >= '2026-02-05'");
        $pdo->exec("INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, source_id, description)
            VALUES ({$supplierId}, {$period}, '2026-02-05', 'purchase_invoice', {$share}, 'Nezaúčtovaný zápis faktury')");
        $other = (int) $this->one("SELECT id FROM journal_entries WHERE supplier_id = {$supplierId} AND source_type = 'opening' ORDER BY id LIMIT 1");
        $pdo->exec("INSERT INTO journal_document_postings (supplier_id, doc_type, doc_id, entry_id) VALUES ({$supplierId}, 'purchase_invoice', {$insurance}, {$other})");

        $r = $this->repair->run([$supplierId], true, null, true)[0];
        self::assertSame([0, 4], [$r['totals']['converted'], $r['totals']['refused']], json_encode($r, JSON_UNESCAPED_UNICODE));
        $reasons = array_column($r['documents'], 'reason', 'document_no');
        self::assertStringContainsString('poznámka peněžního deníku', (string) $reasons[SyntheticPohodaExport::PAYROLL_WAGE]);
        self::assertStringContainsString('připojený soubor', (string) $reasons[SyntheticPohodaExport::PAYROLL_OPENING_TAX]);
        self::assertStringContainsString('další zápis', (string) $reasons['26OZB0001']);
        self::assertStringContainsString('další zápis', (string) $reasons[SyntheticPohodaExport::PAYROLL_INSURANCE]);
        self::assertSame(4, $this->rows('purchase_invoices', $supplierId, "id IN ({$wage}, {$openingTax}, {$share}, {$insurance})"));
    }

    /**
     * Zápisy čísla dokladu z jiné agendy patří jinému dokladu, stejně jako při převodu: má-li
     * číslo zápis v agendě roku běhu, který doklad založil, převezme se ten.
     */
    public function testRepairTakesEntryOfDocumentAgendaYear(): void
    {
        [$supplierId] = $this->importPayroll();
        $wage = $this->legacy($supplierId, SyntheticPohodaExport::PAYROLL_WAGE);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO pohoda_imports (supplier_id, mode, status, agenda_year) VALUES (?, 'import', 'completed', 2026)")->execute([$supplierId]);
        $run = (int) $pdo->lastInsertId();
        $pdo->exec("UPDATE pohoda_import_map SET run_id = {$run} WHERE supplier_id = {$supplierId} AND kind = 'purchase_invoice'");
        $own = (int) $this->one("SELECT id FROM journal_entries WHERE supplier_id = {$supplierId} AND document_no = '26IN0010'");
        $foreign = (int) $this->one("SELECT id FROM journal_entries WHERE supplier_id = {$supplierId} AND document_no = '26IN0011'");
        $map = $pdo->prepare("INSERT INTO pohoda_import_map (supplier_id, kind, pohoda_key, target_id) VALUES (?, 'journal_entry', ?, ?)");
        $map->execute([$supplierId, '2026|Ostatní závazky|' . SyntheticPohodaExport::PAYROLL_WAGE . '|2026-01-31', $own]);
        $map->execute([$supplierId, '2025|Ostatní závazky|' . SyntheticPohodaExport::PAYROLL_WAGE . '|2025-12-31', $foreign]);

        $r = $this->repair->run([$supplierId], true)[0];
        self::assertSame([1, 1, 0], [$r['totals']['converted'], $r['totals']['posted'], $r['totals']['refused']], json_encode($r, JSON_UNESCAPED_UNICODE));
        $item = $pdo->query("SELECT status, account_code, journal_entry_id FROM other_items WHERE supplier_id = {$supplierId}
            AND document_no = '" . SyntheticPohodaExport::PAYROLL_WAGE . "'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['posted', '331.000', (string) $own], [$item['status'], $item['account_code'], (string) $item['journal_entry_id']]);
        self::assertSame(0, $this->rows('purchase_invoices', $supplierId, "id = {$wage}"));
    }

    /**
     * Úhrada pokladním dokladem se přesune na ostatní závazek; pokladní doklad, který podle
     * mapy převodu hradí i jiný doklad, se nepřesouvá a doklad zůstává fakturou.
     */
    public function testRepairMovesCashPaymentUnlessSharedByAnotherDocument(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOtherItems($dir, true);
        SyntheticPohodaExport::withOtherItemSettlements($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $pi = $this->legacy($supplierId, SyntheticPohodaExport::OTHER_ITEM_PAID_BY_CASH);
        $cash = (int) $this->one("SELECT id FROM cash_documents WHERE supplier_id = {$supplierId} AND purchase_invoice_id = {$pi}");
        $other = (int) $this->one("SELECT id FROM purchase_invoices WHERE supplier_id = {$supplierId} AND id <> {$pi} ORDER BY id LIMIT 1");
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO pohoda_import_map (supplier_id, kind, pohoda_key, target_id) VALUES (?, 'payment', ?, ?)")
            ->execute([$supplierId, 'purchase_invoice|' . $other . '|shared', $cash]);

        $r = $this->repair->run([$supplierId], true, null, true)[0];
        self::assertSame([0, 1], [$r['totals']['converted'], $r['totals']['refused']], json_encode($r, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('hradí i jiné doklady', (string) $r['documents'][0]['reason']);
        self::assertSame($pi, (int) $this->one("SELECT purchase_invoice_id FROM cash_documents WHERE id = {$cash}"));

        $pdo->exec("DELETE FROM pohoda_import_map WHERE supplier_id = {$supplierId} AND kind = 'payment' AND pohoda_key = 'purchase_invoice|{$other}|shared'");
        $r = $this->repair->run([$supplierId], true, null, true)[0];
        self::assertSame([1, 0], [$r['totals']['converted'], $r['totals']['refused']], json_encode($r, JSON_UNESCAPED_UNICODE));
        $item = (int) $this->one("SELECT id FROM other_items WHERE supplier_id = {$supplierId} AND document_no = '" . SyntheticPohodaExport::OTHER_ITEM_PAID_BY_CASH . "'");
        self::assertSame('250.00', (string) $this->one("SELECT SUM(amount) FROM other_item_allocations WHERE other_item_id = {$item} AND cash_document_id = {$cash}"));
        self::assertNull($this->one("SELECT purchase_invoice_id FROM cash_documents WHERE id = {$cash}"));
        self::assertSame(1, $this->rows('pohoda_import_map', $supplierId, "kind = 'payment' AND pohoda_key LIKE 'other_item|{$item}|%' AND target_id <> {$cash}"));
    }

    /**
     * Nečekaná chyba jedné firmy vrátí jen její změny (i už převedené doklady) a běh
     * pokračuje další firmou.
     */
    public function testUnexpectedFailureRollsBackOnlyThatFirm(): void
    {
        [$supplierId] = $this->importPayroll();
        $share = $this->legacy($supplierId, '26OZB0001');
        $wage = $this->legacy($supplierId, SyntheticPohodaExport::PAYROLL_WAGE);
        $pdo = $this->db->pdo();
        // Faktura s neexistujícím dodavatelem: založení položky spadne na cizím klíči.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec("UPDATE purchase_invoices SET vendor_id = 999999999 WHERE id = {$wage}");
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $empty = $this->supplier();
        $pdo->exec("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = {$empty}");

        $rows = $this->repair->run([$supplierId, $empty], true);
        self::assertSame(['failed', 'nothing'], array_column($rows, 'status'), json_encode($rows, JSON_UNESCAPED_UNICODE));
        self::assertNotSame('', (string) $rows[0]['reason']);
        self::assertSame(2, $this->rows('purchase_invoices', $supplierId, "id IN ({$share}, {$wage})"), 'Převedený doklad firmy se vrátil.');
        self::assertSame(0, $this->rows('other_items', $supplierId, "document_no IN ('26OZB0001', '" . SyntheticPohodaExport::PAYROLL_WAGE . "')"));
    }

    /** @return array{0:int,1:PohodaExport} */
    private function importPayroll(): array
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOtherItems($dir, true);
        SyntheticPohodaExport::withPayrollItems($dir);
        SyntheticPohodaExport::withPurchasePaidEarlier($dir);
        SyntheticPohodaExport::withPayrollOpeningBalances($dir);
        $export = PohodaExport::open($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        return [$supplierId, $export];
    }

    /**
     * Ostatní závazek, jak ho převod před 6.72 založil: přijatá faktura bez DPH se stejným
     * klíčem mapy, zápis dokladu (má-li ho) vlastní faktura, úhrady z banky jsou párování
     * faktury. Doklad minulého období je navázaný na otevírací zápis.
     */
    private function legacy(int $supplierId, string $number, bool $opening = false): int
    {
        $pdo = $this->db->pdo();
        $oi = $pdo->query("SELECT * FROM other_items WHERE supplier_id = {$supplierId} AND document_no = '{$number}'")->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($oi, $number);
        $vendor = $oi['partner_id'] !== null ? (int) $oi['partner_id'] : (int) $this->one("SELECT id FROM clients WHERE supplier_id = {$supplierId} ORDER BY id LIMIT 1");
        $currency = (int) $this->one("SELECT default_currency_id FROM supplier WHERE id = {$supplierId}");
        $amount = (float) $oi['amount'];
        $paid = (float) $oi['settled_outside_amount'] + (float) $this->one("SELECT COALESCE(SUM(amount), 0) FROM other_item_allocations WHERE other_item_id = {$oi['id']}");
        $posted = $oi['journal_entry_id'] !== null;
        $id = $this->writer->insertPurchase(new MigratedPurchaseDocument(
            supplierId: $supplierId, vendorId: $vendor, vendorIsVatPayer: false, varsymbol: $number, vendorInvoiceNumber: $number,
            documentKind: 'invoice', issueDate: (string) $oi['issued_on'], taxDate: (string) $oi['issued_on'], dueDate: (string) $oi['due_on'],
            receivedAt: (string) $oi['issued_on'], receivedAtSource: 'import', currencyId: $currency, exchangeRate: null, pricesIncludeVat: false,
            reverseCharge: false, vendorSnapshot: '{}', totalWithoutVat: $amount, totalVat: 0.0, totalWithVat: $amount, rounding: 0.0,
            status: $paid >= $amount - 0.005 ? 'paid' : 'booked', vatDeduction: 'full', noteAboveItems: (string) $oi['title'],
            noteBelowItems: 'Převzato z Pohody, doklad ' . $number . '; v POHODĚ ostatní závazek; v přiznání DPH, proto převeden jako přijatá faktura',
            createdBy: $this->userId, bookedAt: $oi['issued_on'] . ' 00:00:00', bookedBy: $this->userId,
        ));
        $rate = (int) ($this->one('SELECT id FROM vat_rates WHERE rate_percent = 0 ORDER BY id LIMIT 1') ?: $this->vatRateId);
        $this->writer->insertPurchaseItems($id, [MigratedDocumentItem::purchase((string) $oi['title'], 1.0, 'ks', $amount, $rate, 0.0, $amount, 0.0, $amount, null)]);
        $pdo->exec("UPDATE pohoda_import_map SET kind = 'purchase_invoice', target_id = {$id} WHERE supplier_id = {$supplierId} AND kind = 'other_item' AND target_id = {$oi['id']}");
        if ($posted) {
            $pdo->exec("UPDATE journal_entries SET source_type = 'purchase_invoice', source_id = {$id} WHERE id = {$oi['journal_entry_id']}");
            $pdo->exec("INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note) VALUES ({$supplierId}, {$oi['journal_entry_id']}, 'purchase_invoice', {$id}, 'Převzato z Pohody')");
        }
        if ($opening) {
            $entry = (int) $this->one("SELECT id FROM journal_entries WHERE supplier_id = {$supplierId} AND source_type = 'opening' ORDER BY id LIMIT 1");
            $pdo->exec("INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note) VALUES ({$supplierId}, {$entry}, 'purchase_invoice', {$id}, 'Počáteční stav')");
        }
        foreach ($pdo->query("SELECT id, bank_transaction_id, amount FROM other_item_allocations WHERE other_item_id = {$oi['id']} AND bank_transaction_id IS NOT NULL")->fetchAll(\PDO::FETCH_ASSOC) as $a) {
            $pdo->exec("DELETE FROM other_item_allocations WHERE id = {$a['id']}");
            $pdo->exec("INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
                VALUES ({$supplierId}, {$a['bank_transaction_id']}, {$id}, {$a['amount']}, 'manual', {$this->userId})");
            $match = (int) $pdo->lastInsertId();
            $pdo->exec("UPDATE bank_transactions SET match_status = 'manual', matched_at = NOW() WHERE id = {$a['bank_transaction_id']}");
            $pdo->exec("UPDATE pohoda_import_map SET pohoda_key = REPLACE(pohoda_key, 'other_item|{$oi['id']}|', 'purchase_invoice|{$id}|'), target_id = {$match}
                WHERE supplier_id = {$supplierId} AND kind = 'payment' AND pohoda_key LIKE 'other_item|{$oi['id']}|%' AND target_id = {$a['id']}");
        }
        foreach ($pdo->query("SELECT id, cash_document_id FROM other_item_allocations WHERE other_item_id = {$oi['id']} AND cash_document_id IS NOT NULL")->fetchAll(\PDO::FETCH_ASSOC) as $a) {
            $pdo->exec("DELETE FROM other_item_allocations WHERE id = {$a['id']}");
            $pdo->exec("UPDATE cash_documents SET purchase_invoice_id = {$id}, purpose = 'purchase_payment' WHERE id = {$a['cash_document_id']}");
            $pdo->exec("UPDATE pohoda_import_map SET pohoda_key = REPLACE(pohoda_key, 'other_item|{$oi['id']}|', 'purchase_invoice|{$id}|'), target_id = {$a['cash_document_id']}
                WHERE supplier_id = {$supplierId} AND kind = 'payment' AND pohoda_key LIKE 'other_item|{$oi['id']}|%' AND target_id = {$a['id']}");
        }
        self::assertSame(0, (int) $this->one("SELECT COUNT(*) FROM other_item_allocations WHERE other_item_id = {$oi['id']}"), $number . ': jen úhrady z banky a pokladny');
        $pdo->exec("DELETE FROM other_item_posting_lines WHERE other_item_id = {$oi['id']}");
        $pdo->exec("DELETE FROM other_items WHERE id = {$oi['id']}");
        return $id;
    }

    private function rows(string $table, int $supplierId, string $where = '1 = 1'): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$where}");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function importRenumbered(): int
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOtherItems($dir);
        SyntheticPohodaExport::withRenumberedCommitments($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        return $supplierId;
    }

    private function one(string $sql): mixed
    {
        return $this->db->pdo()->query($sql)->fetchColumn();
    }

    private function supplier(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, ?, ?, "tax_evidence")'
        )->execute([SyntheticPohodaExport::NAME, $this->czId, SyntheticPohodaExport::ICO, 'CZ' . SyntheticPohodaExport::ICO, $this->czkId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
