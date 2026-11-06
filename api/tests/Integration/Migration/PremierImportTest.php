<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Premier\DocumentLinker;
use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierContext;
use MyInvoice\Service\Migration\Premier\PremierDocuments;
use MyInvoice\Service\Migration\Premier\PremierImporter;
use MyInvoice\Service\Migration\Premier\PremierJournal;
use MyInvoice\Service\Migration\Premier\PremierReconciler;
use MyInvoice\Service\Migration\Premier\PremierVat;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převod syntetické zálohy PREMIER (iCAB) do firmy v MyÚčtu rok po roku - celý řetěz nad
 * skutečnou DB: osnova, deník s počátečními stavy dopočtenými z minulého roku, adresář,
 * faktury se zařazením DPH po položkách, pokladna, banka, vazby, úhrady, rekonciliace
 * a kontrola proti KH uloženému v PREMIER. Izolovaná firma, transakce s rollbackem.
 */
#[Group('integration')]
final class PremierImportTest extends TestCase
{
    private Connection $db;
    private PremierImporter $importer;
    private PremierReconciler $reconciler;
    private DocumentLinker $linker;
    private DphPriznaniBuilder $dph;
    private SaldoService $saldo;
    private string $tmp = '';
    private int $userId = 0;
    private int $anyCurrencyId = 0;
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
            $this->importer = $container->get(PremierImporter::class);
            $this->reconciler = $container->get(PremierReconciler::class);
            $this->linker = $container->get(DocumentLinker::class);
            $this->dph = $container->get(DphPriznaniBuilder::class);
            $this->saldo = $container->get(SaldoService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        if (!$this->db->hasColumn('premier_import_map', 'premier_key')) {
            $this->markTestSkipped('Chybí migrace 1859 (premier_import_map).');
        }
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->anyCurrencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->anyCurrencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_int_' . bin2hex(random_bytes(5));
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

    public function testTwoYearsReconcileToTheCentAndAreIdempotent(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->backup();

        // ── Rok 2025 ────────────────────────────────────────────────────────────
        $first = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        $this->assertReconciled($first, SyntheticPremierBackup::YEAR1);
        $journal = $first->get('journal')[0];
        self::assertSame([15, 0, 0], [$journal['entries'], $journal['existing'], $journal['skipped_rows']], $this->explain($first));
        self::assertSame(15, self::stepCounts($first, 'journal')['entries'] ?? null);
        self::assertSame(0, $this->rows('journal_entries', $supplierId, "source_type = 'opening'"), 'První rok zálohy nemá z čeho počáteční stavy počítat.');

        // Vydané faktury: tuzemsko po položkách (kód 1), dobropis se zápornými částkami, oba uhrazené.
        self::assertSame(
            [['250001', 'invoice', 'paid', '12100.00', '1'], ['250002', 'credit_note', 'paid', '-1210.00', '1']],
            $this->fetch("SELECT varsymbol, invoice_type, status, total_with_vat, vat_classification_code FROM invoices WHERE supplier_id = ? ORDER BY varsymbol", $supplierId)
        );
        self::assertSame(
            [['Vývoj aplikace', '8000.00', '1680.00', '1'], ['Konzultace', '2000.00', '420.00', '1']],
            $this->fetch("SELECT it.description, it.total_without_vat, it.total_vat, it.vat_classification_code FROM invoice_items it JOIN invoices i ON i.id = it.invoice_id
                           WHERE i.supplier_id = ? AND i.varsymbol = '250001' ORDER BY it.order_index", $supplierId)
        );

        // Přijaté: tuzemsko 40, služba z EU 24e (daň 0, plný odpočet) - bez i se samovyměřením na 343
        // stejně, faktura v EUR přepočtená na deník.
        self::assertSame([
            ['PF250001/2025', 'invoice', 'paid', '1210.00', '210.00', '40', '0', 'full'],
            ['PF250002/2025', 'invoice', 'paid', '5000.00', '0.00', '24e', '1', 'full'],
            ['PF250003/2025', 'invoice', 'paid', '3000.00', '0.00', '24e', '1', 'full'],
            ['PF250004/2025', 'invoice', 'paid', '12162.58', '2110.86', '40', '0', 'full'],
        ], $this->fetch('SELECT varsymbol, document_kind, status, total_with_vat, total_vat, vat_classification_code, reverse_charge, vat_deduction
                           FROM purchase_invoices WHERE supplier_id = ? ORDER BY varsymbol', $supplierId));
        self::assertSame([
            ['PF250002/2025', '5000.00', '0.00', '21.00', '24e'],
            ['PF250003/2025', '3000.00', '0.00', '21.00', '24e'],
            ['PF250004/2025', '2512.30', '527.58', '21.00', '40'],
            ['PF250004/2025', '7539.42', '1583.28', '21.00', '40'],
        ], $this->fetch("SELECT p.varsymbol, it.total_without_vat, it.total_vat, it.vat_rate_snapshot, it.vat_classification_code
                           FROM purchase_invoice_items it JOIN purchase_invoices p ON p.id = it.purchase_invoice_id
                          WHERE p.supplier_id = ? AND p.varsymbol IN ('PF250002/2025', 'PF250003/2025', 'PF250004/2025') ORDER BY p.varsymbol, it.order_index", $supplierId));
        self::assertSame(2, self::stepCounts($first, 'purchase_invoices')['self_assessed'] ?? 0);
        // Záloha nese všechny roky: úhrada z ledna 2026 (vazba VAZBY) je vidět už při převodu 2025.
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "varsymbol = 'PF250002/2025' AND paid_at = '2026-01-15'"));

        // Pokladna: vklad bez DPH, výdej s řádkem DPH (kód 15 → 40).
        self::assertSame([
            ['in', 'other', 'none', '5000.00', null],
            ['out', 'purchase', 'vat', '605.00', '40|21.00|500.00|105.00|full'],
        ], $this->fetch("SELECT d.doc_type, d.purpose, d.vat_mode, d.total_amount,
                                (SELECT CONCAT_WS('|', l.vat_classification_code, l.vat_rate, l.base_amount, l.vat_amount, l.vat_deduction)
                                   FROM cash_document_vat_lines l WHERE l.cash_document_id = d.id LIMIT 1)
                           FROM cash_documents d WHERE d.supplier_id = ? ORDER BY d.issue_date, d.id", $supplierId));
        self::assertSame(2, $this->rows('cash_documents', $supplierId, 'journal_entry_id IS NOT NULL'), 'Pokladní doklady jsou navázané na zápis deníku.');

        // Banka: výpis na každý doklad řady BV, úhrady spárované podle vazeb PREMIER, pohyby bez faktury vyřízené.
        self::assertSame(7, $this->rows('bank_statements', $supplierId));
        self::assertSame(1, $this->rows('bank_statements', $supplierId, sprintf("account_number = '%s' AND prev_balance = 0.00 AND curr_balance = 200000.00", SyntheticPremierBackup::BANK_ACCOUNT)));
        self::assertSame(5, $this->rows('payment_matches', $supplierId));
        self::assertSame([['manual', null, 5], ['ignored', 'premier_booked', 2]], $this->txStatuses($supplierId));
        self::assertSame(3, $this->rows('clients', $supplierId), 'Odběratel, dodavatel a dodavatel z EU; vlastní firma z adresáře se přeskočí.');

        // Služba z EU v přiznání za duben: daň na výstupu ř. 5 a odpočet ř. 43.
        $lines = $this->dph->build($supplierId, SyntheticPremierBackup::YEAR1, SyntheticPremierBackup::RC_MONTH, 'monthly')['summary']['lines'];
        self::assertEqualsWithDelta(5000.0, (float) ($lines['5']['base'] ?? 0), 0.005, json_encode($lines, JSON_UNESCAPED_UNICODE));
        self::assertEqualsWithDelta(1050.0, (float) ($lines['5']['vat'] ?? 0), 0.005, json_encode($lines, JSON_UNESCAPED_UNICODE));
        self::assertEqualsWithDelta(5000.0, (float) ($lines['43']['base'] ?? 0), 0.005, json_encode($lines, JSON_UNESCAPED_UNICODE));
        self::assertEqualsWithDelta(1050.0, (float) ($lines['43']['vat'] ?? 0), 0.005, json_encode($lines, JSON_UNESCAPED_UNICODE));
        // Totéž se samovyměřením zaúčtovaným na 343 (květen) - nezdvojí se.
        $may = $this->dph->build($supplierId, SyntheticPremierBackup::YEAR1, 5, 'monthly')['summary']['lines'];
        self::assertSame([3000.0, 630.0, 3000.0, 630.0], [round((float) $may['5']['base'], 2), round((float) $may['5']['vat'], 2), round((float) $may['43']['base'], 2), round((float) $may['43']['vat'], 2)]);

        // KH za únor sedí na poslední (následné) podání v PREMIER.
        $verification = self::stepCounts($first, 'verification');
        self::assertSame([1, 1], [$verification['kh_months'] ?? null, $verification['kh_months_ok'] ?? null], $this->explain($first));

        // ── Rok 2026: počáteční stavy z deníku 2025 ────────────────────────────
        $second = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR2, false);
        self::assertFalse($second->hasErrors(), $this->explain($second));
        $this->assertReconciled($second, SyntheticPremierBackup::YEAR2);
        self::assertSame(4, $second->get('journal')[0]['entries'], $this->explain($second));
        self::assertSame(8, self::stepCounts($second, 'journal')['opening_accounts'] ?? null, $this->explain($second));

        self::assertSame([
            ['211.001', '4395.00', '0.00'],
            ['221.001', '194467.42', '0.00'],
            ['321.000', '0.00', '5000.00'],
            ['343.021', '535.86', '0.00'],
            ['343.100', '630.00', '0.00'],
            ['343.200', '0.00', '630.00'],
            ['411.000', '0.00', '205000.00'],
            ['431.000', '10601.72', '0.00'],
            ['701', '210630.00', '210630.00'],
        ], $this->fetch("SELECT a.account_code,
                                FORMAT(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE 0 END), 2, 'en_US'),
                                FORMAT(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE 0 END), 2, 'en_US')
                           FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id = e.id JOIN chart_of_accounts a ON a.id = l.account_id
                          WHERE e.supplier_id = ? AND e.source_type = 'opening' GROUP BY a.account_code ORDER BY a.account_code", $supplierId, true));

        // Výpis 2026 navazuje na zůstatek 221 z roku 2025.
        self::assertSame(
            [['194467.42', '189467.42'], ['189467.42', '213667.42']],
            $this->fetch("SELECT prev_balance, curr_balance FROM bank_statements WHERE supplier_id = ? AND statement_date >= '2026-01-01' ORDER BY statement_date", $supplierId)
        );
        // Úhrada služby z EU z minulého roku se spáruje s fakturou převedenou v roce 2025.
        self::assertSame(7, $this->rows('payment_matches', $supplierId));
        self::assertSame(1, $this->rows('payment_matches', $supplierId, "purchase_invoice_id = (SELECT id FROM purchase_invoices WHERE supplier_id = payment_matches.supplier_id AND varsymbol = 'PF250002/2025')"));
        self::assertSame(1, self::stepCounts($second, 'purchase_invoices')['existing'] ?? 0, 'Neuhrazená faktura minulého roku se nepřevádí znovu.');
        self::assertSame(1, self::stepCounts($second, 'link')['previous_period'] ?? 0, $this->explain($second));
        self::assertSame(1, $this->rows('invoices', $supplierId, "varsymbol = '260001' AND status = 'paid' AND total_with_vat = 24200.00"));
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "varsymbol = 'PF260001/2026' AND status = 'booked' AND total_with_vat = 2420.00"));

        // ── Opakovaný převod obou let nic nezdvojí ─────────────────────────────
        $before = $this->snapshot($supplierId);
        foreach ([SyntheticPremierBackup::YEAR1, SyntheticPremierBackup::YEAR2] as $year) {
            $again = $this->importer->run($supplierId, $this->userId, $backup, $year, false);
            self::assertFalse($again->hasErrors(), $this->explain($again));
            self::assertSame('completed', $again->status(), $this->explain($again));
            $journal = $again->get('journal')[0];
            self::assertSame(0, $journal['entries'], $this->explain($again));
            self::assertSame($year === SyntheticPremierBackup::YEAR1 ? 15 : 4, $journal['existing']);
            self::assertArrayNotHasKey('created', self::stepCounts($again, 'purchase_invoices'), $this->explain($again));
            self::assertArrayNotHasKey('created', self::stepCounts($again, 'issued_invoices'), $this->explain($again));
            self::assertArrayNotHasKey('transactions', self::stepCounts($again, 'bank'), $this->explain($again));
            $this->assertReconciled($again, $year);
        }
        self::assertSame($before, $this->snapshot($supplierId));
    }

    /**
     * Každý bankovní pohyb má vlastní zápis se zdrojem `bank` (MyÚčto jinak pohyb bere jako
     * nezaúčtovaný a Doúčtování by ho zaúčtovalo podruhé), řádky výpisu bez pohybu jdou
     * k pohybu hradícímu stejnou fakturu, kurzové přecenění EUR účtu pohyb nezakládá.
     */
    public function testEveryBankMovementHasOwnJournalEntry(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->backup(false, ['bank_split' => true]);

        $protocol = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $this->assertReconciled($protocol, SyntheticPremierBackup::YEAR1);
        $bankCheck = array_values(array_filter($protocol->get('reconciliation')[0]['checks'], static fn (array $c): bool => $c['key'] === 'bank_transactions_posted'));
        self::assertSame([true, 12, 0], [$bankCheck[0]['ok'] ?? null, $bankCheck[0]['transactions'] ?? null, $bankCheck[0]['unposted'] ?? null]);
        // 15 základních + 3 faktury, výpis BV 8 po pohybech (4 + zbytek), zápočet, dva EUR doklady.
        self::assertSame(26, $protocol->get('journal')[0]['entries'], $this->explain($protocol));

        $pdo = $this->db->pdo();
        $count = static function (string $sql) use ($pdo, $supplierId): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$supplierId]);
            return (int) $stmt->fetchColumn();
        };
        self::assertSame(12, $count('SELECT COUNT(*) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE s.supplier_id = ?'));
        self::assertSame(0, $count('SELECT COUNT(*) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE s.supplier_id = ? AND t.amount = 0'),
            'Kurzové přecenění EUR účtu (částka v měně 0) není pohyb.');
        self::assertSame(0, $count("SELECT COUNT(*) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                                     WHERE s.supplier_id = ? AND NOT EXISTS (SELECT 1 FROM journal_entries e WHERE e.supplier_id = s.supplier_id
                                       AND e.source_type = 'bank' AND e.source_id = t.id AND e.reversed_by IS NULL)"), 'Každý pohyb má vlastní zápis.');
        self::assertSame(1, $this->rows('journal_entries', $supplierId, "document_no = 'BV 8' AND source_type = 'manual'"), 'Kurzový rozdíl bez vazby je samostatný zápis dokladu.');

        // Úhrada VF 250005 i s haléřovým vyrovnáním (548) v jednom zápisu pohybu.
        self::assertSame([['221.001', 'debit', '1209.60'], ['311.000', 'credit', '0.40'], ['311.000', 'credit', '1209.60'], ['548.000', 'debit', '0.40']], $this->fetch(
            "SELECT a.account_code, l.side, l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
               JOIN journal_entries e ON e.id = l.entry_id JOIN bank_transactions t ON e.source_type = 'bank' AND t.id = e.source_id
              WHERE e.supplier_id = ? AND t.source_ref = 'BV 8 #50' ORDER BY a.account_code, l.amount", $supplierId));

        // Údaje pohybu z deníku: VS z VAR_DAL / VARIABL / HVAR, protiúčet z HUCET, KS a SS bez samých nul, zpráva, ID transakce banky.
        self::assertSame([
            ['BV 8 #47', '-1000.00', null, null, null, null, null, 'Výběr hotovosti', 'BV-47'],
            ['BV 8 #50', '1209.60', '250005', '0308', null, '1000000005', '0100', 'Úhrada VF 250005 | Platba faktury 250005', 'SYN-TX-0001'],
            ['BV 8 #52', '2420.00', '250006', null, null, null, null, 'Úhrada VF 250006', 'BV-52'],
            ['BV 8 #53', '-25.00', '777', null, '42', null, null, 'Poplatek za platbu', 'BV-53'],
            ['BE 1 #80', '1000.00', null, null, null, null, null, 'Vklad EUR', 'BE-80'],
        ], $this->fetch("SELECT t.source_ref, t.amount, t.variable_symbol, t.constant_symbol, t.specific_symbol, t.counterparty_account, t.counterparty_bank, t.description, t.bank_ref
                           FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                          WHERE s.supplier_id = ? AND (t.source_ref LIKE 'BV 8 %' OR t.source_ref LIKE 'BE %') ORDER BY t.id", $supplierId));

        // Faktury uhrazené bankou (i s haléřem) a zápočtem; zápis úhrady bez pohybu peněz je na fakturu navázaný.
        self::assertSame([['250005', 'paid'], ['250006', 'paid'], ['250007', 'paid']],
            $this->fetch("SELECT varsymbol, status FROM invoices WHERE supplier_id = ? AND varsymbol IN ('250005', '250006', '250007') ORDER BY varsymbol", $supplierId));
        self::assertSame([['250005', 'BV 8'], ['250007', 'ID 5']], $this->fetch(
            'SELECT i.varsymbol, e.document_no FROM journal_entry_document_links k JOIN invoices i ON i.id = k.doc_id JOIN journal_entries e ON e.id = k.entry_id
              WHERE k.supplier_id = ? AND k.doc_type = \'invoice\' AND k.note = ? ORDER BY i.varsymbol', $supplierId, false, [DocumentLinker::PAYMENT_NOTE]));
        // Ignorované jen pohyby s vlastním zápisem: vklad ZK, poplatky, výběr hotovosti a vklad na EUR účet.
        self::assertSame([['manual', null, 7], ['ignored', 'premier_booked', 5]], $this->txStatuses($supplierId));
        // Výběr hotovosti: pokladní doklad patří k zápisu svého pohybu, zdroj zápisu zůstává bance.
        self::assertSame([['in', 'transfer', '1000.00', 'BV 8 #47']], $this->fetch(
            "SELECT d.doc_type, d.purpose, d.total_amount, t.source_ref FROM cash_documents d JOIN journal_entries e ON e.id = d.journal_entry_id
               JOIN bank_transactions t ON e.source_type = 'bank' AND t.id = e.source_id WHERE d.supplier_id = ? AND d.doc_number LIKE 'BV%'", $supplierId));
        self::assertSame(1, $this->rows('journal_entry_document_links', $supplierId, "doc_type = 'cash' AND doc_id = (SELECT id FROM cash_documents WHERE supplier_id = journal_entry_document_links.supplier_id AND doc_number LIKE 'BV%')"));

        // Opakovaný převod téže zálohy do nově převedené firmy nic nezdvojí; u existujícího
        // pohybu doplní jen prázdné údaje (vlastní popis zůstane).
        $pdo->prepare("UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                          SET t.variable_symbol = NULL, t.counterparty_account = NULL, t.counterparty_bank = NULL, t.bank_ref = 'BV-50', t.description = 'Vlastní popis'
                        WHERE s.supplier_id = ? AND t.source_ref = 'BV 8 #50'")->execute([$supplierId]);
        $before = $this->snapshot($supplierId);
        $again = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame([0, 26], [$again->get('journal')[0]['entries'], $again->get('journal')[0]['existing']]);
        self::assertArrayNotHasKey('transactions', self::stepCounts($again, 'bank'), $this->explain($again));
        self::assertSame(1, self::stepCounts($again, 'bank')['enriched'] ?? null, $this->explain($again));
        self::assertSame($before, $this->snapshot($supplierId));
        self::assertSame([['250005', '1000000005', '0100', 'SYN-TX-0001', 'Vlastní popis']], $this->fetch(
            "SELECT t.variable_symbol, t.counterparty_account, t.counterparty_bank, t.bank_ref, t.description FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? AND t.source_ref = 'BV 8 #50'", $supplierId));
    }

    /**
     * Bankovní úhrada vydané faktury je evidovaná platba (`invoice_payments`), ze které
     * saldokonto čte uhrazenost - jinak by uhrazená faktura na 311 zůstala otevřená. Haléřové
     * vyrovnání v zápisu pohybu patří k platbě, zápočet ani vrácení dobropisu platbu nezakládá.
     * Opakovaný převod platby nezdvojí a chybějící (převod starší verzí) doplní.
     */
    public function testIssuedPaymentsAreRecordedAndBackfilledOnRerun(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->backup(false, ['bank_split' => true]);
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $expected = [
            ['250001', '2025-02-20', '12100.00', 'bank', 'BV 2 #14'],
            ['250005', '2025-10-15', '1210.00', 'bank', 'BV 8 #50'],
            ['250006', '2025-10-15', '2420.00', 'bank', 'BV 8 #52'],
        ];
        $payments = "SELECT i.varsymbol, p.paid_on, p.amount, p.source, t.source_ref FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id
                       LEFT JOIN bank_transactions t ON t.id = p.bank_transaction_id WHERE p.supplier_id = ? ORDER BY i.varsymbol, p.id";
        self::assertSame($expected, $this->fetch($payments, $supplierId));
        self::assertSame(3, self::stepCounts($protocol, 'payments')['issued_payments_recorded'] ?? null, $this->explain($protocol));

        $again = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($expected, $this->fetch($payments, $supplierId), 'Opakovaný převod platby nezdvojí.');
        self::assertArrayNotHasKey('issued_payments_recorded', self::stepCounts($again, 'payments'));

        // Firma převedená verzí, která platby nezakládala: úhrady jsou spárované, platby chybí.
        $this->db->pdo()->prepare('DELETE FROM invoice_payments WHERE supplier_id = ?')->execute([$supplierId]);
        $backfill = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($backfill->hasErrors(), $this->explain($backfill));
        self::assertSame(3, self::stepCounts($backfill, 'payments')['issued_payments_recorded'] ?? null, $this->explain($backfill));
        self::assertSame($expected, $this->fetch($payments, $supplierId));
    }

    /**
     * Saldokonto 311 po převodu sedí na hlavní knihu: uhrazené vydané faktury nejsou
     * otevřené položky (platby z banky), vrácený dobropis také ne.
     */
    public function testReceivablesSaldoMatchesLedgerAfterImport(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->backup();
        foreach ([SyntheticPremierBackup::YEAR1, SyntheticPremierBackup::YEAR2] as $year) {
            $protocol = $this->importer->run($supplierId, $this->userId, $backup, $year, false);
            self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
            $saldo = $this->saldo311($supplierId, $year);
            self::assertSame([0.0, 0.0, 0], [round((float) $saldo['gl_balance'], 2), round((float) $saldo['open_items_total'], 2), (int) $saldo['open_items_count']],
                $year . ': ' . json_encode($saldo['partners'], JSON_UNESCAPED_UNICODE));
        }
        self::assertSame([['250001', '12100.00'], ['260001', '24200.00']],
            $this->fetch('SELECT i.varsymbol, p.amount FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id WHERE p.supplier_id = ? ORDER BY i.varsymbol', $supplierId));
    }

    /**
     * Firma s více řadami vydaných faktur (`VFA`, `VFP`…): deník (`SB_KOD`) i vazby úhrad
     * označují fakturu kódem agendy `VF` a jejím `INTER`. Faktura se přesto naváže na svůj
     * zápis (je zaúčtovaná, částky z deníku), úhrada se k ní spáruje a saldo 311 sedí.
     */
    public function testIssuedSeriesOtherThanAgendaCodeIsLinked(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(false, ['issued_series' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $this->assertReconciled($protocol, SyntheticPremierBackup::YEAR1);
        self::assertSame(
            [['250001', 'invoice', 'paid', '12100.00', '1'], ['250002', 'credit_note', 'paid', '-1210.00', '1']],
            $this->fetch("SELECT varsymbol, invoice_type, status, total_with_vat, booked_at IS NOT NULL FROM invoices WHERE supplier_id = ? ORDER BY varsymbol", $supplierId)
        );
        self::assertSame(2, $this->rows('journal_entries', $supplierId, "source_type = 'invoice'"));
        self::assertArrayNotHasKey('document_missing', self::stepCounts($protocol, 'link'), $this->explain($protocol));
        self::assertSame(2, $this->rows('payment_matches', $supplierId, 'invoice_id IS NOT NULL'));
        self::assertSame(1, $this->rows('invoice_payments', $supplierId, "amount = 12100.00 AND source = 'bank'"));
        $saldo = $this->saldo311($supplierId, SyntheticPremierBackup::YEAR1);
        self::assertSame([0.0, 0.0], [round((float) $saldo['gl_balance'], 2), round((float) $saldo['open_items_total'], 2)]);
    }

    /**
     * Přeplatek bankovní úhrady jedné faktury, který interní doklad přeúčtoval na jinou
     * fakturu, je platbou té druhé - jinak by zůstala v saldu otevřená, přestože 311 je na nule.
     */
    public function testPaymentReassignedByInternalDocumentPaysTheOtherInvoice(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->backup(false, ['payment_reassigned' => true]);
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $payments = "SELECT i.varsymbol, p.amount, t.source_ref FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id
                       LEFT JOIN bank_transactions t ON t.id = p.bank_transaction_id WHERE p.supplier_id = ? ORDER BY i.varsymbol, p.id";
        $expected = [['250001', '12100.00', 'BV 2 #14'], ['250009', '1210.00', 'BV 9 #96']];
        self::assertSame($expected, $this->fetch($payments, $supplierId));
        $saldo = $this->saldo311($supplierId, SyntheticPremierBackup::YEAR1);
        self::assertSame([0.0, 0.0], [round((float) $saldo['gl_balance'], 2), round((float) $saldo['open_items_total'], 2)],
            json_encode($saldo['partners'], JSON_UNESCAPED_UNICODE));

        $again = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($expected, $this->fetch($payments, $supplierId), 'Opakovaný převod přeúčtovanou platbu nezdvojí.');
    }

    /**
     * Zápočet dobropisu s fakturou interním dokladem: dobropis má vazbu na fakturu a zápočet
     * (`credit_note_offsets`), faktura evidovanou platbu se zdrojem `credit_note`. Saldo sedí,
     * opakovaný převod nic nezdvojí a chybějící zápočet doplní.
     */
    public function testCreditNoteOffsetByInternalDocumentIsRecorded(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->backup(false, ['credit_note_offset' => true]);
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $offsets = 'SELECT i.varsymbol, c.varsymbol, o.amount, o.offset_on, c.parent_invoice_id = i.id, p.source, p.amount
                      FROM credit_note_offsets o JOIN invoices i ON i.id = o.invoice_id JOIN invoices c ON c.id = o.credit_note_id
                      LEFT JOIN invoice_payments p ON p.id = o.invoice_payment_id WHERE o.supplier_id = ?';
        $expected = [['250010', '250011', '1210.00', '2025-04-30', '1', 'credit_note', '1210.00']];
        self::assertSame($expected, $this->fetch($offsets, $supplierId));
        self::assertSame([['bank', '1210.00'], ['credit_note', '1210.00']], $this->fetch(
            "SELECT p.source, p.amount FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id WHERE p.supplier_id = ? AND i.varsymbol = '250010' ORDER BY p.source", $supplierId));
        $saldo = $this->saldo311($supplierId, SyntheticPremierBackup::YEAR1);
        self::assertSame([0.0, 0.0], [round((float) $saldo['gl_balance'], 2), round((float) $saldo['open_items_total'], 2)],
            json_encode($saldo['partners'], JSON_UNESCAPED_UNICODE));

        $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertSame($expected, $this->fetch($offsets, $supplierId), 'Opakovaný převod zápočet nezdvojí.');
        $this->db->pdo()->prepare('DELETE FROM credit_note_offsets WHERE supplier_id = ?')->execute([$supplierId]);
        $this->db->pdo()->prepare("DELETE FROM invoice_payments WHERE supplier_id = ? AND source = 'credit_note'")->execute([$supplierId]);
        $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertSame($expected, $this->fetch($offsets, $supplierId), 'Opakovaný převod chybějící zápočet doplní.');
    }

    /**
     * Záloha zaúčtovaná přijatou fakturou na 314 a čerpaná konečnou fakturou (vazba na řádek
     * zálohy): čerpání v `purchase_advance_deductions`, saldo 314 sedí na hlavní knihu.
     */
    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    public function testInvoicePrepaymentDeductedByFinalInvoice(bool $unlinked): void
    {
        $supplierId = $this->supplier();
        // Bez vazby se čerpání odvodí ze zaúčtování na 314 (záloha téhož dodavatele).
        $backup = $this->backup(false, ['invoice_prepayment' => true, 'prepayment_unlinked' => $unlinked]);
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $deductions = 'SELECT f.varsymbol, a.varsymbol, d.amount FROM purchase_advance_deductions d
                         JOIN purchase_invoices f ON f.id = d.final_invoice_id JOIN purchase_invoices a ON a.id = d.advance_invoice_id WHERE d.supplier_id = ?';
        self::assertSame([['PF250013/2025', 'PF250012/2025', '1000.00']], $this->fetch($deductions, $supplierId));
        $saldo = $this->saldo311($supplierId, SyntheticPremierBackup::YEAR1, '314');
        self::assertSame([0.0, 0.0], [round((float) $saldo['gl_balance'], 2), round((float) $saldo['open_items_total'], 2)],
            json_encode($saldo['partners'], JSON_UNESCAPED_UNICODE));
        $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertSame([['PF250013/2025', 'PF250012/2025', '1000.00']], $this->fetch($deductions, $supplierId));
    }

    /**
     * Vydaná faktura uhrazená přijatou zálohou (řádek „Vypořádání zálohy" 324/311 bez kódu
     * DPH): vypořádání je odpočet zálohy, ne položka - do DPH nevstoupí a faktura je uhrazená.
     */
    public function testAdvanceSettlementRowIsNotTaxedAsItem(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(false, ['advance_settlement' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $this->assertReconciled($protocol, SyntheticPremierBackup::YEAR1);
        self::assertSame([['1210.00', '1210.00', '0.00', 'paid']], $this->fetch(
            "SELECT total_with_vat, advance_paid_amount, amount_to_pay, status FROM invoices WHERE supplier_id = ? AND varsymbol = '250014'", $supplierId));
        self::assertSame([['1000.00', '210.00', '1']], $this->fetch(
            "SELECT it.total_without_vat, it.total_vat, it.vat_classification_code FROM invoice_items it JOIN invoices i ON i.id = it.invoice_id
              WHERE i.supplier_id = ? AND i.varsymbol = '250014'", $supplierId));
        $july = $this->dph->build($supplierId, SyntheticPremierBackup::YEAR1, 7, 'monthly')['summary']['lines'];
        self::assertEqualsWithDelta(1000.0, (float) ($july['1']['base'] ?? 0), 0.005, json_encode($july, JSON_UNESCAPED_UNICODE));
        $saldo = $this->saldo311($supplierId, SyntheticPremierBackup::YEAR1);
        self::assertSame([0.0, 0.0], [round((float) $saldo['gl_balance'], 2), round((float) $saldo['open_items_total'], 2)],
            json_encode($saldo['partners'], JSON_UNESCAPED_UNICODE));
    }

    /** Dobropis navázaný v PREMIER na opravovanou fakturu (`VAZBY` PF ↔ PF) má vazbu na ni. */
    public function testCreditNoteIsLinkedToCorrectedInvoice(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(false, ['small_assets' => true, 'credit_note_link' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([['PF250006/2025', 'credit_note', 'PF250005/2025']], $this->fetch(
            'SELECT c.varsymbol, c.document_kind, p.varsymbol FROM purchase_invoices c JOIN purchase_invoices p ON p.id = c.parent_purchase_invoice_id WHERE c.supplier_id = ?', $supplierId));
        self::assertSame(1, self::stepCounts($protocol, 'payments')['credit_notes_linked'] ?? null, $this->explain($protocol));
    }

    /** @return array<string,mixed> blok účtu 311 saldokonta ke konci roku */
    private function saldo311(int $supplierId, int $year, string $account = '311'): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, ends_on FROM accounting_periods WHERE supplier_id = ? AND fiscal_year = ?');
        $stmt->execute([$supplierId, $year]);
        $period = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($period);
        $report = $this->saldo->build($supplierId, (int) $period['id'], (string) $period['ends_on'], $account);
        return $report['accounts'][0];
    }

    /**
     * Firma převedená starší verzí má bankovní výpis jako jeden zápis za den. Nový převod
     * by k němu přidal zápisy po pohybech a banku zdvojil - krok deníku skončí chybou
     * a nic nezapíše.
     */
    public function testFirmConvertedByOlderVersionIsRefused(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->backup(false, ['bank_split' => true]);
        $first = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        // Mapa převodu staré verze: klíč bankovního dokladu bez pohybu.
        $this->db->pdo()->prepare("UPDATE premier_import_map SET premier_key = '2025|BV|8|2025-10-15||0' WHERE supplier_id = ? AND kind = ? AND premier_key = '2025|BV|8|2025-10-15||0|#50'")
            ->execute([$supplierId, PremierImportRepository::KIND_JOURNAL_ENTRY]);

        $before = $this->snapshot($supplierId);
        $again = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertTrue($again->hasErrors());
        self::assertContains('legacy_bank_entries', $this->messageCodes($again), $this->explain($again));
        self::assertStringContainsString('převeďte znovu do čisté firmy', $this->explain($again));
        self::assertSame($before, $this->snapshot($supplierId));
    }

    /** Rekonciliace ohlásí pohyb převodu bez vlastního zápisu deníku jako chybu. */
    public function testReconciliationFlagsBankMovementWithoutOwnEntry(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->backup();
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE journal_entries SET source_type = 'manual', source_id = NULL WHERE supplier_id = ? AND source_type = 'bank' ORDER BY id LIMIT 1")->execute([$supplierId]);
        $journal = new PremierJournal($backup);
        $ctx = new PremierContext($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, $journal, PremierVat::fromBackup($backup), false, new ImportProtocol('import'));
        $period = $pdo->prepare('SELECT id, starts_on, ends_on FROM accounting_periods WHERE supplier_id = ? AND YEAR(starts_on) = ?');
        $period->execute([$supplierId, SyntheticPremierBackup::YEAR1]);
        $p = $period->fetch(\PDO::FETCH_ASSOC);
        $ctx->period = ['id' => (int) $p['id'], 'starts_on' => (string) $p['starts_on'], 'ends_on' => (string) $p['ends_on'], 'status' => 'open', 'locked' => false];

        $this->reconciler->run($ctx);
        self::assertContains('bank_transactions_unposted', $this->messageCodes($ctx->protocol), $this->explain($ctx->protocol));
        $check = array_values(array_filter($ctx->protocol->get('reconciliation')[0]['checks'], static fn (array $c): bool => $c['key'] === 'bank_transactions_posted'));
        self::assertSame([false, 7, 1], [$check[0]['ok'], $check[0]['transactions'], $check[0]['unposted']]);

        // Pohyb bez vlastního zápisu se neoznačí jako vyřízený - Doúčtování ho musí vidět.
        $tx = $this->fetch("SELECT t.id, t.source_ref FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                             WHERE s.supplier_id = ? AND t.match_status = 'ignored'
                               AND NOT EXISTS (SELECT 1 FROM journal_entries e WHERE e.supplier_id = s.supplier_id AND e.source_type = 'bank' AND e.source_id = t.id)", $supplierId);
        self::assertCount(1, $tx);
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'unmatched', match_reason = NULL WHERE id = ?")->execute([(int) $tx[0][0]]);
        $ctx->bankTransactions = [(int) substr((string) strrchr((string) $tx[0][1], '#'), 1) => (int) $tx[0][0]];
        $this->linker->matchPayments($ctx, PremierDocuments::fromBackup($backup, $journal, $ctx->vat));
        self::assertSame(1, $this->rows('journal_entries', $supplierId, "source_type = 'manual' AND source_id IS NULL AND document_no LIKE 'BV %'"));
        $status = $pdo->prepare('SELECT match_status FROM bank_transactions WHERE id = ?');
        $status->execute([(int) $tx[0][0]]);
        self::assertSame('unmatched', $status->fetchColumn());
    }

    public function testDryRunLeavesNothingBehind(): void
    {
        $supplierId = $this->supplier();
        $before = $this->snapshot($supplierId);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), SyntheticPremierBackup::YEAR1, true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(15, $protocol->get('journal')[0]['entries'], 'Zkouška nanečisto projde celý převod.');
        self::assertSame($before, $this->snapshot($supplierId));
    }

    public function testBackupOfAnotherCompanyIsRefused(): void
    {
        $supplierId = $this->supplier('99999994');
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), SyntheticPremierBackup::YEAR1, false);
        self::assertTrue($protocol->hasErrors());
        self::assertContains('ico_mismatch', array_column((array) $protocol->get('preflight'), 'code'));
        self::assertSame(0, $this->rows('journal_entries', $supplierId));
    }

    public function testYearMissingInBackupIsRefused(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), 2019, false);
        self::assertTrue($protocol->hasErrors());
        self::assertContains('year_missing', array_column((array) $protocol->get('preflight'), 'code'));
    }

    /**
     * Vydaný doklad s kódem bez řádků přiznání, který nese daň (prodej koncovému
     * zákazníkovi na Slovensko): se zapnutým OSS jde do OSS, ne do tuzemského přiznání.
     */
    public function testIssuedDocumentOutsideReturnWithVatBecomesOssSupply(): void
    {
        $supplierId = $this->supplier();
        $this->db->pdo()->prepare(
            "UPDATE supplier SET oss_enabled = 1, oss_identification_country = 'CZ', oss_return_currency = 'EUR', oss_valid_from = '2025-01-01', oss_valid_to = NULL WHERE id = ?"
        )->execute([$supplierId]);
        $this->foreignRate(SyntheticPremierBackup::OSS_COUNTRY, SyntheticPremierBackup::OSS_RATE);

        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(true), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $row = $this->fetch(
            "SELECT i.status, i.vat_classification_code, it.vat_classification_code, it.oss_applicable, it.oss_consumer_country, r.country, it.vat_rate_snapshot, it.total_vat
               FROM invoices i JOIN invoice_items it ON it.invoice_id = i.id JOIN vat_rates r ON r.id = it.vat_rate_id
              WHERE i.supplier_id = ? AND i.varsymbol = ?", $supplierId, false, [SyntheticPremierBackup::OSS_DOCUMENT]
        );
        self::assertSame([['sent', null, null, '1', 'SK', 'SK', '23.00', '230.00']], $row, $this->explain($protocol));
        self::assertSame(1, self::stepCounts($protocol, 'issued_invoices')['oss_items'] ?? 0);

        $september = $this->dph->build($supplierId, SyntheticPremierBackup::YEAR1, 9, 'monthly')['summary']['lines'];
        self::assertEqualsWithDelta(0.0, (float) ($september['1']['base'] ?? 0), 0.005, 'OSS plnění do tuzemského ř. 1 nepatří.');
    }

    /**
     * OSS doklad v EUR: tuzemská evidence zůstává v Kč z deníku, ale do OSS podání jdou eura
     * z dokladu - ne koruny přepočtené zpátky kurzem ECB konce čtvrtletí (stejně jako POHODA).
     */
    public function testEurOssDocumentGoesToReturnInEurosFromTheDocument(): void
    {
        $supplierId = $this->supplier();
        $this->db->pdo()->prepare(
            "UPDATE supplier SET oss_enabled = 1, oss_identification_country = 'CZ', oss_return_currency = 'EUR', oss_valid_from = '2025-01-01', oss_valid_to = NULL WHERE id = ?"
        )->execute([$supplierId]);
        $this->foreignRate(SyntheticPremierBackup::OSS_COUNTRY, SyntheticPremierBackup::OSS_RATE);

        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(true, ['oss_eur' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([['1', '1000.00', '230.00', '40.00', '9.20']], $this->fetch(
            'SELECT it.oss_applicable, it.total_without_vat, it.total_vat, it.oss_taxable_amount_return, it.oss_vat_amount_return
               FROM invoices i JOIN invoice_items it ON it.invoice_id = i.id WHERE i.supplier_id = ? AND i.varsymbol = ?', $supplierId, false, [SyntheticPremierBackup::OSS_DOCUMENT]));

        $preview = Bootstrap::buildContainer()->get(\MyInvoice\Service\Oss\OssLedgerService::class)->preview($supplierId, SyntheticPremierBackup::YEAR1, 3);
        $sk = array_column($preview['countries'], null, 'country')[SyntheticPremierBackup::OSS_COUNTRY] ?? null;
        self::assertNotNull($sk, json_encode($preview, JSON_UNESCAPED_UNICODE));
        self::assertEqualsWithDelta(40.0, (float) $sk['base'], 0.001);
        self::assertEqualsWithDelta(9.2, (float) $sk['vat'], 0.001);
    }

    /**
     * Doklad v EUR se převezme v měně a kurzu z PREMIER, jen když částky položek v EUR kurzem
     * dají přesně Kč ze zaúčtování. Firma bez eura v číselníku měn převezme tentýž export
     * v Kč (jak to bylo dřív) - přiznání DPH, KH, OSS podání i deník obou firem se shodují.
     */
    public function testForeignCurrencyDocumentsAreTakenOverInTheirCurrencyWithIdenticalReturns(): void
    {
        $container = Bootstrap::buildContainer();
        $this->foreignRate(SyntheticPremierBackup::OSS_COUNTRY, SyntheticPremierBackup::OSS_RATE);
        $backup = $this->backup(true, ['oss_eur' => true, 'eur_exact' => true]);
        $run = function (bool $withEuro) use ($backup): array {
            $supplierId = $this->supplier();
            $this->db->pdo()->prepare(
                "UPDATE supplier SET oss_enabled = 1, oss_identification_country = 'CZ', oss_return_currency = 'EUR', oss_valid_from = '2025-01-01', oss_valid_to = NULL WHERE id = ?"
            )->execute([$supplierId]);
            if ($withEuro) {
                $this->db->pdo()->prepare(
                    "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default) VALUES (?, 'EUR', 'EUR', '€', 'Euro', 'Euro', 2, 1, 0)"
                )->execute([$supplierId]);
            }
            $protocol = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
            self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
            $this->assertReconciled($protocol, SyntheticPremierBackup::YEAR1);
            return [$supplierId, $protocol];
        };
        [$foreign, $protocol] = $run(true);
        [$home] = $run(false);

        $header = 'SELECT c.code, d.exchange_rate, d.total_without_vat, d.total_vat, d.total_with_vat FROM %s d JOIN currencies c ON c.id = d.currency_id WHERE d.supplier_id = ? AND d.varsymbol = ?';
        self::assertSame([['EUR', '25.120000', '140.00', '29.40', '169.40']], $this->fetch(sprintf($header, 'invoices'), $foreign, false, [SyntheticPremierBackup::EUR_DOCUMENT]));
        self::assertSame([['EUR', '25.000000', '40.00', '9.20', '49.20']], $this->fetch(sprintf($header, 'invoices'), $foreign, false, [SyntheticPremierBackup::OSS_DOCUMENT]));
        self::assertSame([['40.00', '9.20']], $this->fetch('SELECT it.oss_taxable_amount_return, it.oss_vat_amount_return FROM invoices i JOIN invoice_items it ON it.invoice_id = i.id WHERE i.supplier_id = ? AND i.varsymbol = ?', $foreign, false, [SyntheticPremierBackup::OSS_DOCUMENT]));
        // PF 250004: haléřový rozdíl kurzu dorovnaný na položku - kurzem nevyjde, zůstává v Kč.
        $kept = $this->fetch("SELECT c.code, d.total_with_vat, d.note_below_items FROM purchase_invoices d JOIN currencies c ON c.id = d.currency_id WHERE d.supplier_id = ? AND d.vendor_invoice_number = 'D-2025-7004'", $foreign);
        self::assertSame(['CZK', '12162.58'], array_slice($kept[0] ?? [], 0, 2));
        self::assertStringContainsString('doklad v EUR, převzat v Kč podle zaúčtování (základ 2. položky 300,10 × kurz 25,123 nedává 7 539,42 Kč ze zdroje)', (string) ($kept[0][2] ?? ''));
        self::assertSame(2, self::stepCounts($protocol, 'issued_invoices')['foreign_currency'] ?? 0, $this->explain($protocol));
        self::assertSame(1, self::stepCounts($protocol, 'purchase_invoices')['foreign_currency_in_home'] ?? 0, $this->explain($protocol));
        self::assertSame([['CZK']], $this->fetch("SELECT c.code FROM invoices d JOIN currencies c ON c.id = d.currency_id WHERE d.supplier_id = ? AND d.varsymbol = ?", $home, false, [SyntheticPremierBackup::EUR_DOCUMENT]));

        foreach ([7, 9, 10] as $month) {
            self::assertSame($this->dph->build($home, SyntheticPremierBackup::YEAR1, $month, 'monthly')['summary']['lines'],
                $this->dph->build($foreign, SyntheticPremierBackup::YEAR1, $month, 'monthly')['summary']['lines'], "DPH {$month}/2025");
            $kh = $container->get(\MyInvoice\Service\Report\KontrolniHlaseniBuilder::class);
            $khHome = $kh->build($home, SyntheticPremierBackup::YEAR1, $month);
            $khForeign = $kh->build($foreign, SyntheticPremierBackup::YEAR1, $month);
            self::assertSame($khHome['summary'], $khForeign['summary'], "KH {$month}/2025");
            $sections = static fn (string $xml): array => preg_match_all('~<Veta[ABC][^>]*/>~', $xml, $m) > 0 ? $m[0] : [];
            self::assertSame($sections($khHome['xml']), $sections($khForeign['xml']), "KH {$month}/2025");
        }
        $oss = $container->get(\MyInvoice\Service\Oss\OssLedgerService::class);
        // OSS podání: součty po státech stejné, řádek se liší jen tím, že doklad je v EUR.
        $countries = static fn (array $preview): array => array_map(static fn (array $c): array => array_diff_key($c, ['rows' => true]), $preview['countries']);
        self::assertSame($countries($oss->preview($home, SyntheticPremierBackup::YEAR1, 3)), $countries($oss->preview($foreign, SyntheticPremierBackup::YEAR1, 3)));
        $journal = 'SELECT a.account_code, SUM(CASE WHEN l.side = "debit" THEN l.amount ELSE -l.amount END) FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
                     WHERE l.supplier_id = ? GROUP BY a.account_code ORDER BY a.account_code';
        self::assertSame($this->fetch($journal, $home), $this->fetch($journal, $foreign));

        // Opakovaný převod porovná doklad v EUR s PREMIER v Kč - změna to není.
        $again = $this->importer->run($foreign, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertNotContains('changed_in_premier', $this->messageCodes($again), $this->explain($again));
    }

    /** Bez zapnutého OSS se cizí daň do tuzemského přiznání nepustí - doklad se nepřevezme, zbytek ano. */
    public function testIssuedDocumentOutsideReturnWithVatIsNotDomesticWhenOssIsOff(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(true), SyntheticPremierBackup::YEAR1, false);

        self::assertSame(0, $this->rows('invoices', $supplierId, sprintf("varsymbol = '%s' AND status <> 'draft'", SyntheticPremierBackup::OSS_DOCUMENT)), $this->explain($protocol));
        self::assertSame(0, $this->rows('invoices', $supplierId, sprintf("varsymbol = '%s' AND vat_classification_code IS NOT NULL", SyntheticPremierBackup::OSS_DOCUMENT)));
        self::assertContains('oss_setup', $this->messageCodes($protocol), $this->explain($protocol));
        self::assertSame(1, $this->rows('invoices', $supplierId, "varsymbol = '250001' AND status = 'paid'"), $this->explain($protocol));
        self::assertSame(4, $this->rows('purchase_invoices', $supplierId), $this->explain($protocol));
    }

    /**
     * Firma se zahraniční sazbou omylem založenou jako tuzemská a vypnutým OSS: doklad je
     * koncept s důvodem z OSS plánovače. Důvod plánovače je celá věta s tečkou - hláška
     * protokolu ani poznámka dokladu z ní nesmí udělat „..".
     */
    public function testOssPlannerReasonDoesNotEndWithDoubleDot(): void
    {
        $supplierId = $this->supplier();
        $this->foreignRate('CZ', SyntheticPremierBackup::OSS_RATE);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(true), SyntheticPremierBackup::YEAR1, false);

        $note = $this->fetch("SELECT status, note_below_items FROM invoices WHERE supplier_id = ? AND varsymbol = ?", $supplierId, false, [SyntheticPremierBackup::OSS_DOCUMENT]);
        self::assertSame('draft', $note[0][0] ?? null, $this->explain($protocol));
        self::assertStringContainsString('OSS', (string) $note[0][1]);
        self::assertStringNotContainsString('..', (string) $note[0][1]);
        $review = array_values(array_filter(array_merge(...array_map(static fn (array $s): array => $s['messages'] ?? [], $protocol->toArray()['steps'])),
            static fn (array $m): bool => $m['code'] === 'needs_review'));
        self::assertNotSame([], $review, $this->explain($protocol));
        self::assertStringNotContainsString('..', (string) $review[0]['text']);
    }

    /**
     * Tři pokladní doklady PP 1 v jednom roce, dva i ze stejného dne: poslední kandidát čísla
     * (řada, číslo a datum) je obsazený, doklad dostane příponu a rok se převede celý.
     */
    public function testDuplicateCashNumberOnTheSameDayGetsSuffix(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(false, ['cash_duplicate' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        self::assertSame(
            [['PP1/2025', '5000.00'], ['PP1/2025-01-02', '100.00'], ['PP1/2025-01-02-2', '200.00']],
            $this->fetch("SELECT doc_number, total_amount FROM cash_documents WHERE supplier_id = ? AND doc_number LIKE 'PP1/%' ORDER BY id", $supplierId)
        );
        self::assertContains('cash_number_duplicate', $this->messageCodes($protocol));
        $this->assertReconciled($protocol, SyntheticPremierBackup::YEAR1);
    }

    /**
     * Krácený odpočet (§ 76): první převedený rok dostane zálohový koeficient ze svých
     * dokladů, převod dalšího roku ho vypořádá a nový rok ho převezme - jako u Money S3.
     */
    public function testReducedDeductionGetsCoefficientSoTheReturnCanBeBuilt(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->backup(false, ['reduced_deduction' => true]);
        $first = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        self::assertGreaterThan(0, $this->rows('purchase_invoices', $supplierId, "vat_deduction = 'reduced'"));
        self::assertContains('provisional_from_own_year', $this->messageCodes($first));
        $this->dph->build($supplierId, SyntheticPremierBackup::YEAR1, 3, 'monthly');

        $second = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR2, false);
        self::assertFalse($second->hasErrors(), $this->explain($second));
        self::assertSame(
            [[(string) SyntheticPremierBackup::YEAR1, '1']],
            $this->fetch('SELECT year, settled_at IS NOT NULL FROM vat_coefficients WHERE supplier_id = ? ORDER BY year', $supplierId)
        );
        $this->dph->build($supplierId, SyntheticPremierBackup::YEAR2, 3, 'monthly');
    }

    /**
     * Služba z EU s další položkou bez kódu DPH (poplatek, který PREMIER do přiznání
     * nezahrnul): doklad je samovyměření, ale položka mimo přiznání v přiznání být nesmí.
     * Bez kódu by ji evidence DPH podle příznaku samovyměření zařadila jako službu z EU
     * a dopočítala z ní daň na ř. 5 i 43.
     */
    public function testUncodedLineOnSelfAssessedPurchaseStaysOutsideTheReturn(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(false, ['rc_uncoded_line' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([['5200.00', '1', '24e']], $this->fetch("SELECT total_with_vat, reverse_charge, vat_classification_code FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = 'PF250002/2025'", $supplierId));
        $lines = $this->dph->build($supplierId, SyntheticPremierBackup::YEAR1, SyntheticPremierBackup::RC_MONTH, 'monthly')['summary']['lines'];
        self::assertSame([5000.0, 1050.0, 5000.0, 1050.0], [round((float) ($lines['5']['base'] ?? 0), 2), round((float) ($lines['5']['vat'] ?? 0), 2),
            round((float) ($lines['43']['base'] ?? 0), 2), round((float) ($lines['43']['vat'] ?? 0), 2)], json_encode($lines, JSON_UNESCAPED_UNICODE));
        self::assertSame([['5000.00', '24e'], ['200.00', 'mimo']], $this->fetch("SELECT it.total_without_vat, it.vat_classification_code FROM purchase_invoice_items it
            JOIN purchase_invoices p ON p.id = it.purchase_invoice_id WHERE p.supplier_id = ? AND p.varsymbol = 'PF250002/2025' ORDER BY it.order_index", $supplierId));
    }

    private function assertReconciled(ImportProtocol $protocol, int $year): void
    {
        $reconciliation = $protocol->get('reconciliation');
        self::assertCount(1, $reconciliation, $this->explain($protocol));
        self::assertSame($year, $reconciliation[0]['year']);
        self::assertTrue($reconciliation[0]['ok'], json_encode($reconciliation[0], JSON_UNESCAPED_UNICODE));
        self::assertSame([], $reconciliation[0]['journal_diffs']);
    }

    /** @param array<string,bool> $flags */
    private function backup(bool $oss = false, array $flags = []): PremierBackup
    {
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'backup_' . ($oss ? 'oss' : 'base') . implode('_', array_keys(array_filter($flags)));
        if (!is_dir($dir)) {
            PremierBackup::extractArchive(SyntheticPremierBackup::writeCab($this->tmp . DIRECTORY_SEPARATOR . 'zaloha.icab', $this->tmp, $oss, $flags), $dir);
        }
        return PremierBackup::open($dir);
    }

    private function foreignRate(string $country, float $rate): void
    {
        $pdo = $this->db->pdo();
        $code = $country . '-' . (int) $rate;
        $stmt = $pdo->prepare('SELECT id FROM vat_rates WHERE code = ?');
        $stmt->execute([$code]);
        if ($stmt->fetchColumn() !== false) {
            return;
        }
        $pdo->prepare(
            'INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en, is_default, is_reverse_charge, valid_from, valid_to, display_order)
             VALUES (?, ?, ?, ?, ?, 0, 0, "2024-01-01", NULL, 900)'
        )->execute([$code, $rate, $country, $code, $code]);
    }

    private function supplier(string $ico = SyntheticPremierBackup::ICO): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, vat_period, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 1", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, "monthly", ?, ?, "tax_evidence")'
        )->execute([SyntheticPremierBackup::NAME, $this->czId, $ico, 'CZ' . $ico, $this->anyCurrencyId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    private function rows(string $table, int $supplierId, string $where = '1 = 1'): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$where}");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<mixed> $params
     * @return list<list<mixed>>
     */
    private function fetch(string $sql, int $supplierId, bool $stripThousands = false, array $params = []): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_merge([$supplierId], $params));
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as $row) {
            $out[] = array_map(static fn (mixed $v): mixed => $stripThousands && is_string($v) ? str_replace(',', '', $v) : (is_int($v) ? (string) $v : $v), $row);
        }
        return $out;
    }

    /** @return list<array{0:string,1:?string,2:int}> */
    private function txStatuses(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.match_status, t.match_reason, COUNT(*) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? GROUP BY t.match_status, t.match_reason ORDER BY t.match_status'
        );
        $stmt->execute([$supplierId]);
        return array_map(static fn (array $r): array => [$r[0], $r[1], (int) $r[2]], $stmt->fetchAll(\PDO::FETCH_NUM));
    }

    /** @return array<string,int> */
    private function snapshot(int $supplierId): array
    {
        $out = [];
        foreach (['journal_entries', 'journal_entry_lines', 'accounting_periods', 'purchase_invoices', 'invoices', 'invoice_payments', 'clients', 'cash_documents', 'cash_registers',
            'payment_matches', 'bank_statements', 'journal_entry_document_links', 'chart_of_accounts', 'supplier_bank_accounts'] as $t) {
            $out[$t] = $this->rows($t, $supplierId);
        }
        $out['map'] = array_sum((new PremierImportRepository($this->db))->counts($supplierId));
        return $out;
    }

    /** @return array<string,int|float> */
    private static function stepCounts(ImportProtocol $protocol, string $key): array
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            if ($step['key'] === $key) {
                return $step['counts'];
            }
        }
        return [];
    }

    /** @return list<string> */
    private function messageCodes(ImportProtocol $protocol): array
    {
        $codes = [];
        foreach ($protocol->toArray()['steps'] as $step) {
            foreach ($step['messages'] ?? [] as $m) {
                $codes[] = $m['code'];
            }
        }
        return $codes;
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps'], 'preflight' => $protocol->get('preflight')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
