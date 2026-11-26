<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Check\Checks\TaxEvidenceCashMissingCheck;
use MyInvoice\Service\Migration\MoneyS3\AccountingUnitSwitch;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\DocumentLinker;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Service\TaxEvidence\CashJournalService;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Agenda POHODY vedená v daňové evidenci ({@see SyntheticPohodaExport::writeTaxEvidence()})
 * se převede do firmy v daňové evidenci: doklady, banka a úhrady bez jakéhokoli účtování,
 * firma zůstane v daňové evidenci a automatika účtování se nemění. Přijatá faktura
 * uhrazená bankou je v peněžním deníku jednou. Izolovaná firma, transakce s rollbackem.
 */
#[Group('integration')]
final class PohodaTaxEvidenceImportTest extends TestCase
{
    private Connection $db;
    private PohodaImporter $importer;
    private AccountingUnitSwitch $unit;
    private CashJournalService $cashJournal;
    private string $tmp = '';
    private int $userId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private bool $inTx = false;
    private ?ImportProtocol $lastConversion = null;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(PohodaImporter::class);
            $this->unit = $container->get(AccountingUnitSwitch::class);
            $this->cashJournal = $container->get(CashJournalService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('pohoda_import_map', 'pohoda_key')) {
            $this->markTestSkipped('Chybí migrace pohoda_import_map.');
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_de_' . bin2hex(random_bytes(5));
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

    public function testTaxEvidenceAgendaStaysTaxEvidenceWithoutAccounting(): void
    {
        $export = PohodaExport::open(SyntheticPohodaExport::writeTaxEvidence($this->tmp));
        self::assertTrue(PohodaImporter::isTaxEvidenceAgenda($export));
        $supplierId = $this->supplier();
        $automation = $this->automation($supplierId);

        $preflight = $this->importer->preflight($supplierId, $export);
        $codes = array_column($preflight, 'code');
        self::assertContains('tax_evidence_kept', $codes, json_encode($preflight, JSON_UNESCAPED_UNICODE));
        self::assertNotContains('switch_to_double_entry', $codes);
        self::assertNotContains('file_state', $codes);
        self::assertSame([], array_values(array_filter($preflight, static fn (array $m): bool => $m['level'] === 'error')));

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $this->assertNoAccounting($supplierId);
        self::assertSame($automation, $this->automation($supplierId));
        self::assertSame('off', $protocol->get('automation')['during']);
        self::assertFalse($protocol->get('automation')['restored']);
        self::assertSame(PohodaImporter::stepKeys(true), array_values(array_diff(array_column($protocol->toArray()['steps'], 'key'), [PohodaImporter::STEP_PREFLIGHT])));

        // Přijatá faktura uhrazená bankou: vazba na pohyb a zaplaceno k datu pohybu.
        $purchase = $this->row('SELECT pi.id, pi.status, pi.paid_at, (SELECT COUNT(*) FROM payment_matches pm WHERE pm.purchase_invoice_id = pi.id) AS matches
            FROM purchase_invoices pi WHERE pi.supplier_id = ? AND pi.varsymbol = ?', [$supplierId, '26PF0001']);
        self::assertSame('paid', $purchase['status']);
        self::assertSame('2026-01-20', substr((string) $purchase['paid_at'], 0, 10));
        self::assertSame(1, (int) $purchase['matches']);
        // Vydaná faktura uhrazená bankou: evidovaná platba s vazbou na pohyb.
        $issued = $this->row("SELECT i.status, i.paid_total, (SELECT COUNT(*) FROM invoice_payments ip WHERE ip.invoice_id = i.id AND ip.source = 'bank' AND ip.bank_transaction_id IS NOT NULL) AS payments
            FROM invoices i WHERE i.supplier_id = ? AND i.varsymbol = ?", [$supplierId, '26FV0001']);
        self::assertSame('paid', $issued['status']);
        self::assertSame(1210.0, (float) $issued['paid_total']);
        self::assertSame(1, (int) $issued['payments']);
        // Neuhrazená faktura minulého roku zůstává neuhrazená.
        self::assertSame('sent', $this->row('SELECT status FROM invoices WHERE supplier_id = ? AND varsymbol = ?', [$supplierId, '25FV0099'])['status']);

        // Peněžní deník: výdaj za přijatou fakturu jednou (bankovní pohyb), žádný virtuální navíc.
        $journal = $this->cashJournal->build($supplierId, '2026-01-01', '2026-12-31', ['year' => 2026]);
        $rows = array_values(array_filter($journal['rows'], static fn (array $r): bool => $r['purchase_invoice_id'] === (int) $purchase['id']));
        self::assertCount(1, $rows, json_encode($rows, JSON_UNESCAPED_UNICODE));
        self::assertSame(605.0, (float) $rows[0]['expense']);
        self::assertSame('bank', $rows[0]['source_type']);

        // Poplatek bez dokladu převod nezařazuje, protokol ho hlásí k ručnímu zařazení.
        self::assertSame(1, self::stepCounts($protocol, 'tax_evidence_reconciliation')['bank_unclassified'] ?? null, $this->explain($protocol));

        // Opakovaný převod nic nezdvojí.
        $counts = $this->counts($supplierId);
        $again = $this->importer->run($supplierId, $this->userId, PohodaExport::open($export->dir), false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($counts, $this->counts($supplierId));
        $this->assertNoAccounting($supplierId);
    }

    /**
     * Doklad, který POHODA vede jako uhrazený bez pohybu peněz (zápočet), zůstane neuhrazený:
     * peněžní deník by jinak započetl jeho datum úhrady jako virtuální výdaj, ačkoli peníze
     * neodešly. Úhrada z minulého roku je u přeneseného dokladu vyrovnáním mimo peněžní deník.
     */
    public function testPaymentsWithoutMovementAreNotCountedInCashJournal(): void
    {
        $dir = SyntheticPohodaExport::writeTaxEvidence($this->tmp);
        SyntheticPohodaExport::withPurchaseSettledByOffset($dir);
        SyntheticPohodaExport::withPurchasePaidEarlier($dir);
        $supplierId = $this->supplier();

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $offset = $this->row('SELECT id, status, paid_at FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ?', [$supplierId, SyntheticPohodaExport::PURCHASE_SETTLED_BY_OFFSET]);
        self::assertNull($offset['paid_at']);
        self::assertContains('payment_without_movement', array_column($this->messages($protocol), 'code'));
        $earlier = $this->row('SELECT id, paid_at, settled_outside_amount FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ?', [$supplierId, SyntheticPohodaExport::PURCHASE_PAID_EARLIER]);
        self::assertNull($earlier['paid_at']);
        self::assertSame(600.0, (float) $earlier['settled_outside_amount']);

        $journal = $this->cashJournal->build($supplierId, '2025-01-01', '2026-12-31', ['year' => 2026]);
        $ids = [(int) $offset['id'], (int) $earlier['id']];
        self::assertSame([], array_values(array_filter($journal['rows'], static fn (array $r): bool => in_array($r['purchase_invoice_id'], $ids, true))));
    }

    /**
     * Pokladna bez zápisů v deníku (agenda daňové evidence deník nemá): převod ji založí
     * podle jejích dokladů a převezme výdaj i příjem. Výdajový doklad uhradí přijatou fakturu
     * a peněžní deník ji započte jednou, pokladním řádkem. Dřív převod pokladnu přeskočil
     * a všechny pokladní doklady hlásil „nemá pokladnu, nepřevzat".
     */
    public function testCashRegisterWithoutJournalIsImported(): void
    {
        $dir = SyntheticPohodaExport::writeTaxEvidence($this->tmp);
        SyntheticPohodaExport::withPurchasePaidByCash($dir);
        $supplierId = $this->supplier();

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertNotContains('register_missing', array_column($this->messages($protocol), 'code'), $this->explain($protocol));
        self::assertSame(1, (int) $this->row('SELECT COUNT(*) AS n FROM cash_registers WHERE supplier_id = ?', [$supplierId])['n']);

        $purchase = $this->row('SELECT id, status, paid_at FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ?', [$supplierId, SyntheticPohodaExport::PURCHASE_PAID_BY_CASH]);
        self::assertSame('paid', $purchase['status']);
        self::assertSame('2026-02-15', substr((string) $purchase['paid_at'], 0, 10));
        $payment = $this->row('SELECT purchase_invoice_id, purpose FROM cash_documents WHERE supplier_id = ? AND doc_number LIKE ?', [$supplierId, SyntheticPohodaExport::CASH_PAYMENT . '%']);
        self::assertSame((int) $purchase['id'], (int) $payment['purchase_invoice_id']);
        self::assertSame('purchase_payment', $payment['purpose']);

        $journal = $this->cashJournal->build($supplierId, '2026-01-01', '2026-12-31', ['year' => 2026]);
        $rows = array_values(array_filter($journal['rows'], static fn (array $r): bool => $r['purchase_invoice_id'] === (int) $purchase['id']));
        self::assertCount(1, $rows, json_encode($rows, JSON_UNESCAPED_UNICODE));
        self::assertSame('cash', $rows[0]['source_type']);
        self::assertSame(400.0, (float) $rows[0]['expense']);
        $receipts = array_values(array_filter($journal['rows'], static fn (array $r): bool => $r['source_type'] === 'cash' && (float) ($r['income'] ?? 0) === 300.0));
        self::assertCount(1, $receipts, json_encode($journal['rows'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Vydaná faktura uhrazená příjmovým pokladním dokladem: evidovaná platba z pokladny
     * a příjem v peněžním deníku jednou, pokladním řádkem.
     */
    public function testIssuedInvoicePaidByCashIsIncomeOnce(): void
    {
        $dir = SyntheticPohodaExport::writeTaxEvidence($this->tmp);
        SyntheticPohodaExport::withPurchasePaidByCash($dir);
        SyntheticPohodaExport::withIssuedPaidByCash($dir);
        $supplierId = $this->supplier();

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $this->assertCashPaid($supplierId);
    }

    /**
     * Firma převedená verzí převodu před podporou daňové evidence: převod šel postupem
     * podvojného účetnictví, pokladnu nezaložil (pokladní doklady „nemá pokladnu, nepřevzat",
     * úhrady „v převedené pokladně není") a úhrady pokladnou zakryl. Přijatý doklad vedl
     * uhrazený s úhradou mimo evidenci (virtuální výdaj v peněžním deníku), vydaný uhrazený
     * evidovanou platbou `settlement` (příjem v peněžním deníku chyběl). Opakovaný převod
     * daňové evidence doplní pokladnu a pokladní doklady, naváže úhrady a krytí z dřívějšího
     * převodu odstraní; další opakování nic nemění.
     */
    public function testRerunRepairsConversionWithoutCashRegister(): void
    {
        [$supplierId, $dir] = $this->convertedWithoutCash();
        $before = $this->cashJournal->build($supplierId, '2026-01-01', '2026-12-31', ['year' => 2026]);
        self::assertSame([], array_values(array_filter($before['rows'], static fn (array $r): bool => $r['source_type'] === 'cash')));

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertNotContains('payment_not_found', array_column($this->messages($protocol), 'code'), $this->explain($protocol));
        self::assertSame(2, self::stepCounts($protocol, 'payments')['converter_settlement_removed'] ?? null, $this->explain($protocol));
        $this->assertCashPaid($supplierId);

        $counts = $this->counts($supplierId) + ['cash' => $this->cashCount($supplierId)];
        $again = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($counts, $this->counts($supplierId) + ['cash' => $this->cashCount($supplierId)]);
        self::assertArrayNotHasKey('converter_settlement_removed', self::stepCounts($again, 'payments'));
        $this->assertCashPaid($supplierId);
    }

    /**
     * Firma, ve které účetní po převodu pokračuje (vlastní pokladna, první tržba 1. 3.): běžný
     * opakovaný převod ji odmítne, opravný převod (`relinkOnly`) zápisy z MyÚčtu nechá, doplní
     * pokladní doklady POHODY před prvním vlastním obchodním pokladním dokladem a jejich úhrady.
     * Vydaná faktura uhrazená pokladním dokladem z 5. 3. si krytí z dřívějšího převodu ponechá
     * (úhrada se nenavázala) a protokol ji ohlásí úplným seznamem. Nanečisto se nic nezmění.
     */
    public function testRelinkRepairsCashOfFirmWithOwnRecords(): void
    {
        [$supplierId, $dir] = $this->convertedWithoutCash();
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO cash_registers (supplier_id, name, account_code, currency_code, is_active) VALUES (?, 'Hlavní pokladna', '', 'CZK', 1)")->execute([$supplierId]);
        $register = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, description, total_amount, currency_code, status, created_by)
            VALUES (?, ?, 'in', 'sale', 'VLASTNI-1', '2026-03-01', 'Tržba v hotovosti', 5000, 'CZK', 'posted', ?)")->execute([$supplierId, $register, $this->userId]);

        $rerun = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertTrue($rerun->hasErrors());
        self::assertContains('tax_evidence_year_not_empty', array_column($this->messages($rerun), 'code'));

        $counts = $this->counts($supplierId) + ['cash' => $this->cashCount($supplierId)];
        $dry = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), true, relinkOnly: true);
        self::assertFalse($dry->hasErrors(), $this->explain($dry));
        self::assertSame($counts, $this->counts($supplierId) + ['cash' => $this->cashCount($supplierId)]);

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false, relinkOnly: true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $codes = array_column($this->messages($protocol), 'code');
        self::assertContains('relink_keeps_own_records', $codes);
        self::assertContains('own_cash_kept', $codes, $this->explain($protocol));
        self::assertContains('second_cash_register', $codes, $this->explain($protocol));
        self::assertSame([SyntheticPohodaExport::ISSUED_PAID_BY_CASH], $protocol->listOf('converter_settlement_kept'), $this->explain($protocol));
        self::assertSame(1, self::stepCounts($protocol, 'cash')['own_cash_kept'] ?? null);
        self::assertSame(3, $this->cashCount($supplierId));
        self::assertSame(1, (int) $this->row("SELECT COUNT(*) AS n FROM cash_documents WHERE supplier_id = ? AND doc_number = 'VLASTNI-1' AND register_id = ?", [$supplierId, $register])['n']);

        $purchase = $this->row('SELECT id, status, paid_at, settled_outside_amount FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ?', [$supplierId, SyntheticPohodaExport::PURCHASE_PAID_BY_CASH]);
        self::assertSame('paid', $purchase['status']);
        self::assertSame('2026-02-15', substr((string) $purchase['paid_at'], 0, 10));
        self::assertSame(0.0, (float) $purchase['settled_outside_amount']);
        $issued = $this->row("SELECT i.status, i.paid_at, i.paid_total, (SELECT COUNT(*) FROM invoice_payments ip WHERE ip.invoice_id = i.id) AS payments
            FROM invoices i WHERE i.supplier_id = ? AND i.varsymbol = ?", [$supplierId, SyntheticPohodaExport::ISSUED_PAID_BY_CASH]);
        self::assertSame(['paid', '2026-03-05', 1210.0, 1], [$issued['status'], substr((string) $issued['paid_at'], 0, 10), (float) $issued['paid_total'], (int) $issued['payments']]);
    }

    /**
     * Kontrola dat po převodu ({@see TaxEvidenceCashMissingCheck}) hlásí firmu v daňové
     * evidenci převedenou bez pokladních úhrad: vydaný doklad uhrazený doplatkem z převodu,
     * přijatý úhradou mimo evidenci v roce agendy, a v poznámce počty z protokolu převodu.
     * Po opravném převodu je bez nálezu. Firma v podvojném účetnictví se nekontroluje.
     */
    public function testMigrationCheckReportsTaxEvidenceWithoutCashPayments(): void
    {
        [$supplierId, $dir] = $this->convertedWithoutCash();
        $this->recordRun($supplierId, $this->lastConversion);
        $check = Bootstrap::buildContainer()->get(TaxEvidenceCashMissingCheck::class);
        self::assertTrue($check->appliesTo($supplierId));

        $result = $check->detect($supplierId);
        self::assertSame('review', $result->status());
        $labels = array_map(static fn ($i): string => $i->entityType . ':' . $i->label, $result->items);
        sort($labels);
        self::assertSame(['invoice:' . SyntheticPohodaExport::ISSUED_PAID_BY_CASH, 'purchase_invoice:' . SyntheticPohodaExport::PURCHASE_PAID_BY_CASH], $labels);
        self::assertSame(1610.0, $result->amountCzk());
        self::assertCount(1, $result->notes);
        self::assertStringContainsString('2 úhrad z pokladny nenalezeno', $result->notes[0]);
        self::assertStringContainsString('pohoda-relink.php --supplier=' . $supplierId, $result->notes[0]);

        // Doklad otevřený v MyÚčtu, který POHODA uhradila pokladnou, hlásí i bez krytí z převodu.
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'received', paid_at = NULL, settled_outside_amount = 0 WHERE supplier_id = ? AND varsymbol = ?")
            ->execute([$supplierId, SyntheticPohodaExport::PURCHASE_PAID_BY_CASH]);
        $open = array_values(array_filter($check->detect($supplierId)->items, static fn ($i): bool => $i->entityType === 'purchase_invoice'));
        self::assertSame([400.0, 'payment_not_found'], [$open[0]->amountCzk, $open[0]->details['kind']]);

        $repair = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false, relinkOnly: true);
        self::assertFalse($repair->hasErrors(), $this->explain($repair));
        $this->recordRun($supplierId, $repair);
        $after = $check->detect($supplierId);
        self::assertSame('ok', $after->status(), json_encode(array_map(static fn ($i): string => $i->label . ': ' . $i->reason, $after->items), JSON_UNESCAPED_UNICODE));
        self::assertSame([], $after->notes);

        $this->db->pdo()->prepare("INSERT INTO supplier_accounting_modes (supplier_id, effective_from, accounting_mode) VALUES (?, '2026-01-01', 'double_entry')")->execute([$supplierId]);
        $this->recordRun($supplierId, $this->lastConversion);
        self::assertSame('ok', Bootstrap::buildContainer()->get(TaxEvidenceCashMissingCheck::class)->detect($supplierId)->status());
    }

    /**
     * Úhrada, kterou opravný převod nenaváže (pokladní doklad POHODY za hranicí vlastní pokladny),
     * nesmí přijít o krytí z dřívějšího převodu: přijatý doklad zůstane uhrazený úhradou mimo
     * evidenci a výdaj zůstane v peněžním deníku, vydaný si ponechá zbytek úhrady. Oba doklady
     * protokol vypíše v úplném seznamu.
     */
    public function testUnlinkedPaymentKeepsConverterCoverage(): void
    {
        [$supplierId, $dir] = $this->convertedWithoutCash();
        $this->ownCash($supplierId, 'sale', '2026-02-01');

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false, relinkOnly: true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(3, self::stepCounts($protocol, 'cash')['own_cash_kept'] ?? null, $this->explain($protocol));
        $kept = $protocol->listOf('converter_settlement_kept');
        sort($kept);
        self::assertSame([SyntheticPohodaExport::ISSUED_PAID_BY_CASH, SyntheticPohodaExport::PURCHASE_PAID_BY_CASH], $kept);

        $purchase = $this->row('SELECT id, status, paid_at, settled_outside_amount FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ?', [$supplierId, SyntheticPohodaExport::PURCHASE_PAID_BY_CASH]);
        self::assertSame(['paid', '2026-02-15', 400.0], [$purchase['status'], substr((string) $purchase['paid_at'], 0, 10), (float) $purchase['settled_outside_amount']]);
        $issued = $this->row('SELECT status, paid_total FROM invoices WHERE supplier_id = ? AND varsymbol = ?', [$supplierId, SyntheticPohodaExport::ISSUED_PAID_BY_CASH]);
        self::assertSame(['paid', 1210.0], [$issued['status'], (float) $issued['paid_total']]);
        $journal = $this->cashJournal->build($supplierId, '2026-01-01', '2026-12-31', ['year' => 2026]);
        $expense = array_values(array_filter($journal['rows'], static fn (array $r): bool => $r['purchase_invoice_id'] === (int) $purchase['id']));
        self::assertSame([400.0], array_map(static fn (array $r): float => (float) $r['expense'], $expense));
    }

    /**
     * Doklad uhrazený mezitím v MyÚčtu: úhrada z POHODY se nenaváže podruhé (pokladní ani bankovní
     * dvojice spárovaná uživatelem) a krytí z dřívějšího převodu ustoupí skutečné úhradě. Přijatý
     * doklad kryje dobropis ({@see \MyInvoice\Support\Sql\PurchaseSettledExpr}).
     */
    public function testDocumentsPaidMeanwhileInMyUctoAreNotPaidTwice(): void
    {
        [$supplierId, $dir] = $this->convertedWithoutCash();
        $pdo = $this->db->pdo();
        $issued = (int) $this->row('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?', [$supplierId, SyntheticPohodaExport::ISSUED_PAID_BY_CASH])['id'];
        $pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, created_by) VALUES (?, ?, '2026-03-06', 1210, 'CZK', 'manual', ?)")
            ->execute([$supplierId, $issued, $this->userId]);
        $purchase = (int) $this->row('SELECT id FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ?', [$supplierId, SyntheticPohodaExport::PURCHASE_PAID_BY_CASH])['id'];
        $creditNote = (int) $this->row('SELECT id FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ?', [$supplierId, '26PF0001'])['id'];
        $pdo->prepare("INSERT INTO credit_note_offsets (supplier_id, doc_type, invoice_id, credit_note_id, amount, offset_on) VALUES (?, 'purchase_invoice', ?, ?, 400, '2026-02-14')")
            ->execute([$supplierId, $purchase, $creditNote]);
        // Bankovní úhradu 26FV0001 spároval uživatel v MyÚčtu, převod ji v mapě nemá.
        $bankIssued = (int) $this->row('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?', [$supplierId, '26FV0001'])['id'];
        $pdo->prepare("DELETE FROM pohoda_import_map WHERE supplier_id = ? AND kind = 'payment' AND pohoda_key LIKE ?")->execute([$supplierId, 'invoice|' . $bankIssued . '|%']);

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, self::stepCounts($protocol, 'payments')['already_matched'] ?? null, $this->explain($protocol));
        self::assertSame(2, self::stepCounts($protocol, 'payments')['already_paid'] ?? null, $this->explain($protocol));

        $i = $this->row("SELECT i.paid_total, (SELECT GROUP_CONCAT(ip.source ORDER BY ip.source) FROM invoice_payments ip WHERE ip.invoice_id = i.id) AS sources FROM invoices i WHERE i.id = ?", [$issued]);
        self::assertSame([1210.0, 'manual'], [(float) $i['paid_total'], $i['sources']]);
        $pi = $this->row('SELECT settled_outside_amount, (SELECT COUNT(*) FROM cash_documents cd WHERE cd.purchase_invoice_id = pi.id) AS cash FROM purchase_invoices pi WHERE pi.id = ?', [$purchase]);
        self::assertSame([0.0, 0], [(float) $pi['settled_outside_amount'], (int) $pi['cash']]);
        $bank = $this->row('SELECT (SELECT COUNT(*) FROM payment_matches pm WHERE pm.invoice_id = i.id) AS matches, (SELECT COUNT(*) FROM invoice_payments ip WHERE ip.invoice_id = i.id) AS payments FROM invoices i WHERE i.id = ?', [$bankIssued]);
        self::assertSame([1, 1], [(int) $bank['matches'], (int) $bank['payments']]);
    }

    /**
     * Pokladní doklad s DPH do období DPH podaného v MyÚčtu se bez volby nepřevezme a protokol
     * ho vypíše; s volbou `allowFiledVat` se převezme. Rok s dokončenou roční uzávěrkou daňové
     * evidence a doklady za zámkem převod nezmění, ani opakovaný.
     */
    public function testFiledVatPeriodAndClosedYearAreRespected(): void
    {
        [$supplierId, $dir] = $this->convertedWithoutCash();
        SyntheticPohodaExport::withVatCashSale($dir);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, period_quarter, form_variant, xml_content, xml_size_bytes, xml_sha256, validation_status, status, submitted_at, summary_json, generated_by)
            VALUES (?, 'dphdp3', 2026, 3, NULL, 'B', '<test/>', 7, ?, 'passed', 'submitted', NOW(), '{}', NULL)")->execute([$supplierId, hash('sha256', 'test')]);

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), true, relinkOnly: true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([SyntheticPohodaExport::CASH_VAT_SALE], $protocol->listOf('filed_vat_skipped'), $this->explain($protocol));
        $allowed = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false, relinkOnly: true, allowFiledVat: true);
        self::assertFalse($allowed->hasErrors(), $this->explain($allowed));
        self::assertSame(1, (int) $this->row('SELECT COUNT(*) AS n FROM cash_documents WHERE supplier_id = ? AND doc_number LIKE ?', [$supplierId, SyntheticPohodaExport::CASH_VAT_SALE . '%'])['n']);

        $snapshot = '{}';
        $pdo->prepare("INSERT INTO tax_evidence_closings (supplier_id, year, status, checklist, source_snapshot, source_hash, row_version, finalized_at, finalized_by)
            VALUES (?, 2026, 'final', '{}', ?, ?, 1, NOW(), ?)")->execute([$supplierId, $snapshot, hash('sha256', $snapshot), $this->userId]);
        foreach ([false, true] as $relink) {
            $final = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), true, relinkOnly: $relink);
            self::assertContains('closing_final', array_column($this->messages($final), 'code'), $this->explain($final));
        }
        $pdo->prepare("DELETE FROM tax_evidence_closings WHERE supplier_id = ?")->execute([$supplierId]);
        $pdo->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?) ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')->execute([$supplierId, '2026-06-30']);
        $locked = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), true);
        self::assertContains('tax_evidence_locked', array_column($this->messages($locked), 'code'), $this->explain($locked));
    }

    /**
     * Hranici vlastní pokladny určuje první zaúčtovaný obchodní pokladní doklad roku agendy:
     * vklad do pokladny ani koncept ji neposunou a převezmou se všechny pokladní doklady POHODY.
     */
    public function testOwnCashCutoffIgnoresDepositsAndDrafts(): void
    {
        [$supplierId, $dir] = $this->convertedWithoutCash();
        $this->ownCash($supplierId, 'other', '2026-01-02');
        $this->ownCash($supplierId, 'sale', '2026-01-03', 'draft');
        $this->ownCash($supplierId, 'sale', '2025-12-30');

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false, relinkOnly: true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertArrayNotHasKey('own_cash_kept', self::stepCounts($protocol, 'cash'), $this->explain($protocol));
        $this->assertCashPaid($supplierId);
    }

    /**
     * Opravný převod je jedna transakce: zrušení před krokem úhrad vrátí i založené pokladní
     * doklady a pokladnu, nic nezůstane bez navázané úhrady.
     */
    public function testCancelledRelinkLeavesNothing(): void
    {
        [$supplierId, $dir] = $this->convertedWithoutCash();
        $before = $this->counts($supplierId) + ['cash' => $this->cashCount($supplierId)];
        $calls = 0;
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false,
            shouldCancel: static function () use (&$calls): bool { return ++$calls >= 8; }, relinkOnly: true);
        self::assertTrue($protocol->hasErrors());
        self::assertContains('relink_rolled_back', array_column($this->messages($protocol), 'code'), $this->explain($protocol));
        self::assertGreaterThan(0, self::stepCounts($protocol, 'cash')['created'] ?? 0, $this->explain($protocol));
        self::assertSame($before, $this->counts($supplierId) + ['cash' => $this->cashCount($supplierId)]);
        self::assertSame(0, (int) $this->row('SELECT COUNT(*) AS n FROM cash_registers WHERE supplier_id = ?', [$supplierId])['n']);
    }

    /**
     * Doklady v cizí měně s úhradou v exportu: úhrady POHODY jsou v Kč, doklad zůstane bez
     * navázané úhrady a převod daňové evidence nespadne.
     */
    public function testForeignCurrencyDocumentsDoNotBreakPayments(): void
    {
        $dir = SyntheticPohodaExport::writeTaxEvidence($this->tmp);
        SyntheticPohodaExport::withForeignCurrencyDocuments($dir);
        $supplierId = $this->supplier();
        $foreignIssued = SyntheticPohodaExport::FOREIGN_ISSUED;
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([SyntheticPohodaExport::FOREIGN_PURCHASE], $protocol->listOf('foreign_currency_payment'), $this->explain($protocol));
        self::assertArrayNotHasKey('already_paid', self::stepCounts($protocol, 'payments'));

        // Vydaný doklad v měně s úhradou v novém exportu a se zbytkem úhrady z dřívějšího převodu:
        // opravný převod úhradu v Kč nenaváže a zbytek neupraví, oba nálezy ohlásí.
        $id = (int) $this->row("SELECT i.id FROM invoices i JOIN currencies c ON c.id = i.currency_id WHERE i.supplier_id = ? AND i.varsymbol = ? AND c.code = 'EUR'", [$supplierId, $foreignIssued])['id'];
        $file = $dir . '/12_faktury_issuedInvoice.xml';
        $xml = (string) file_get_contents($file);
        $at = strpos($xml, '</lst:invoice>', (int) strpos($xml, '<typ:numberRequested>' . $foreignIssued . '<'));
        file_put_contents($file, substr_replace($xml, '<inv:liquidations><typ:liquidation><typ:id>77001</typ:id><typ:date>2026-02-28</typ:date><typ:sourceAgenda>bank</typ:sourceAgenda>'
            . '<typ:sourceDocument><typ:number>BAN0099999</typ:number></typ:sourceDocument><typ:amount>4255.33</typ:amount></typ:liquidation></inv:liquidations>', $at, 0));
        $this->db->pdo()->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, created_by) VALUES (?, ?, '2026-02-28', 100, 'EUR', ?, 'settlement', ?)")
            ->execute([$supplierId, $id, DocumentLinker::REMAINDER_NOTE, $this->userId]);
        $again = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false, relinkOnly: true);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame([$foreignIssued], $again->listOf('foreign_converter_settlement'), $this->explain($again));
        self::assertContains($foreignIssued, $again->listOf('foreign_currency_payment'));
        self::assertSame(1, (int) $this->row("SELECT COUNT(*) AS n FROM invoice_payments WHERE invoice_id = ? AND source = 'settlement'", [$id])['n']);
    }

    /**
     * Odběratel zaplatil v hotovosti víc, než činí faktura: první převod úhradu naváže celou
     * (peněžní deník přeplatek rozdělí), nesmí ji vynechat jako „uhrazeno jinak".
     */
    public function testOverpaymentFromPohodaIsLinked(): void
    {
        $dir = SyntheticPohodaExport::writeTaxEvidence($this->tmp);
        SyntheticPohodaExport::withPurchasePaidByCash($dir);
        SyntheticPohodaExport::withIssuedPaidByCash($dir, 1220.0);
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([], $protocol->listOf('already_paid'), $this->explain($protocol));
        $issued = $this->row("SELECT i.id, i.status, i.paid_total, (SELECT SUM(ip.amount) FROM invoice_payments ip WHERE ip.invoice_id = i.id AND ip.source = 'cash') AS cash
            FROM invoices i WHERE i.supplier_id = ? AND i.varsymbol = ?", [$supplierId, SyntheticPohodaExport::ISSUED_PAID_BY_CASH]);
        self::assertSame(['paid', 1220.0, 1220.0], [$issued['status'], (float) $issued['paid_total'], (float) $issued['cash']]);
        $journal = $this->cashJournal->build($supplierId, '2026-01-01', '2026-12-31', ['year' => 2026]);
        $income = array_sum(array_map(static fn (array $r): float => (float) ($r['income'] ?? 0), array_filter($journal['rows'], static fn (array $r): bool => $r['invoice_id'] === (int) $issued['id'])));
        self::assertSame(1220.0, round($income, 2));
    }

    /**
     * Víc nenalezených úhrad z pokladny, než kolik zpráv protokol kroku unese: úplný seznam je
     * v oddílu seznamů protokolu a kontrola dat z něj ohlásí všechny doklady.
     */
    public function testMissingCashPaymentsAreListedBeyondMessageLimit(): void
    {
        $dir = SyntheticPohodaExport::writeTaxEvidence($this->tmp);
        SyntheticPohodaExport::withManyMissingCashPayments($dir, ImportProtocol::LIST_LIMIT + 5);
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertCount(ImportProtocol::LIST_LIMIT + 5, $protocol->listOf('cash_payment_missing'));
        $this->recordRun($supplierId, $protocol);
        $result = Bootstrap::buildContainer()->get(TaxEvidenceCashMissingCheck::class)->detect($supplierId);
        self::assertCount(ImportProtocol::LIST_LIMIT + 5, $result->items);
        self::assertStringContainsString((ImportProtocol::LIST_LIMIT + 5) . ' úhrad z pokladny nenalezeno.', $result->notes[0]);
    }

    /**
     * Přijatý doklad uhrazený dvěma pokladními doklady, z nichž jeden v exportu chybí: první
     * úhrada se naváže, krytí z dřívějšího převodu zůstane jen za chybějící část a doklad se
     * ohlásí zvlášť, protože peněžní deník u dokladu s pohybem zbytek krytí nezapočte. Nižší
     * úhrada minulého roku v novém exportu se převezme.
     */
    public function testPartialCoverageAndLowerPriorYearPayment(): void
    {
        [$supplierId, $dir] = $this->convertedWithoutCash();
        SyntheticPohodaExport::withPurchasePartlyPaidByCash($dir);
        SyntheticPohodaExport::withPurchasePaidEarlier($dir);
        $first = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE purchase_invoices SET settled_outside_amount = 1000, status = 'paid', paid_at = '2026-02-16' WHERE supplier_id = ? AND varsymbol = ?")
            ->execute([$supplierId, SyntheticPohodaExport::PURCHASE_PARTLY_BY_CASH]);
        $file = $dir . '/20_faktury_receivedInvoice.xml';
        file_put_contents($file, str_replace('<typ:sourceDocument><typ:number>BAN9990001</typ:number></typ:sourceDocument><typ:amount>600</typ:amount>',
            '<typ:sourceDocument><typ:number>BAN9990001</typ:number></typ:sourceDocument><typ:amount>500</typ:amount>', (string) file_get_contents($file)));

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $partly = $this->row('SELECT settled_outside_amount, status, (SELECT SUM(cd.total_amount) FROM cash_documents cd WHERE cd.purchase_invoice_id = pi.id) AS cash FROM purchase_invoices pi WHERE pi.supplier_id = ? AND pi.varsymbol = ?',
            [$supplierId, SyntheticPohodaExport::PURCHASE_PARTLY_BY_CASH]);
        self::assertSame([400.0, 'paid', 600.0], [(float) $partly['settled_outside_amount'], $partly['status'], (float) $partly['cash']]);
        self::assertSame(500.0, (float) $this->row('SELECT settled_outside_amount FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ?', [$supplierId, SyntheticPohodaExport::PURCHASE_PAID_EARLIER])['settled_outside_amount']);
        self::assertSame([SyntheticPohodaExport::PURCHASE_PARTLY_BY_CASH], $protocol->listOf('converter_coverage_partial'), $this->explain($protocol));
    }

    /** Tržba zadaná v MyÚčtu jako pokladní doklad „ostatní" zařazený v peněžním deníku určuje hranici vlastní pokladny. */
    public function testClassifiedOtherCashDocumentSetsCutoff(): void
    {
        [$supplierId, $dir] = $this->convertedWithoutCash();
        $this->ownCash($supplierId, 'other', '2026-02-01');
        $pdo = $this->db->pdo();
        $cash = (int) $this->row("SELECT id FROM cash_documents WHERE supplier_id = ? AND purpose = 'other'", [$supplierId])['id'];
        $pdo->prepare("INSERT INTO de_movement_classification (supplier_id, source_type, cash_document_id, tax_bucket, classified_by) VALUES (?, 'cash', ?, 'income_taxable', ?)")
            ->execute([$supplierId, $cash, $this->userId]);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false, relinkOnly: true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertCount(3, $protocol->listOf('own_cash_kept'), $this->explain($protocol));
    }

    public function testRelinkNeedsEarlierConversion(): void
    {
        $export = PohodaExport::open(SyntheticPohodaExport::writeTaxEvidence($this->tmp));
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false, relinkOnly: true);
        self::assertTrue($protocol->hasErrors());
        self::assertContains('relink_without_conversion', array_column($this->messages($protocol), 'code'));
        self::assertSame(0, $this->counts($supplierId)['invoices']);
    }

    public function testDryRunLeavesNothing(): void
    {
        $export = PohodaExport::open(SyntheticPohodaExport::writeTaxEvidence($this->tmp));
        $supplierId = $this->supplier();
        $before = $this->counts($supplierId);

        $protocol = $this->importer->run($supplierId, $this->userId, $export, true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertGreaterThan(0, self::stepCounts($protocol, 'purchase_invoices')['created'] ?? 0);
        self::assertSame($before, $this->counts($supplierId));
        $this->assertNoAccounting($supplierId);
    }

    public function testLegalEntityIsRefused(): void
    {
        $export = PohodaExport::open(SyntheticPohodaExport::writeTaxEvidence($this->tmp));
        $supplierId = $this->supplier('po');

        $codes = array_column($this->importer->preflight($supplierId, $export), 'code');
        self::assertContains('tax_evidence_legal_entity', $codes);
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertTrue($protocol->hasErrors());
        self::assertSame(['purchase_invoices' => 0, 'invoices' => 0, 'bank_transactions' => 0, 'map' => 0], $this->counts($supplierId));
    }

    public function testDoubleEntryTargetIsRefused(): void
    {
        $export = PohodaExport::open(SyntheticPohodaExport::writeTaxEvidence($this->tmp));
        $supplierId = $this->supplier();
        $this->db->pdo()->prepare("INSERT INTO supplier_accounting_modes (supplier_id, effective_from, accounting_mode) VALUES (?, '2026-01-01', 'double_entry')")
            ->execute([$supplierId]);

        $preflight = $this->importer->preflight($supplierId, $export);
        $codes = array_column($preflight, 'code');
        self::assertContains('target_double_entry', $codes, json_encode($preflight, JSON_UNESCAPED_UNICODE));
        self::assertNotContains('tax_evidence_kept', $codes);
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertTrue($protocol->hasErrors());
        self::assertSame(0, $this->counts($supplierId)['invoices']);
    }

    /**
     * Stav firmy po převodu verzí před podporou daňové evidence ({@see testRerunRepairsConversionWithoutCashRegister()}):
     * převod bez pokladních dokladů a krytí úhrad pokladnou, jak je zapsal postup podvojného
     * účetnictví (`DocumentLinker`: úhrada mimo evidenci u přijatého, zbytek úhrady u vydaného).
     *
     * @return array{0:int,1:string} firma a složka úplného exportu
     */
    private function convertedWithoutCash(): array
    {
        $dir = SyntheticPohodaExport::writeTaxEvidence($this->tmp);
        SyntheticPohodaExport::withPurchasePaidByCash($dir);
        SyntheticPohodaExport::withIssuedPaidByCash($dir);
        $supplierId = $this->supplier();
        $cash = $dir . '/28_pokladna.xml';
        rename($cash, $cash . '.bak');
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        $this->lastConversion = $protocol;
        rename($cash . '.bak', $cash);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([SyntheticPohodaExport::ISSUED_PAID_BY_CASH, SyntheticPohodaExport::PURCHASE_PAID_BY_CASH], $this->sorted($protocol->listOf('cash_payment_missing')));
        self::assertSame(0, $this->cashCount($supplierId));

        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = '2026-02-15', settled_outside_amount = 400, paid_amount_invoice_ccy = NULL WHERE supplier_id = ? AND varsymbol = ?")
            ->execute([$supplierId, SyntheticPohodaExport::PURCHASE_PAID_BY_CASH]);
        $issued = (int) $this->row('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?', [$supplierId, SyntheticPohodaExport::ISSUED_PAID_BY_CASH])['id'];
        $pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, created_by) VALUES (?, ?, '2026-03-05', 1210, 'CZK', ?, 'settlement', ?)")
            ->execute([$supplierId, $issued, DocumentLinker::REMAINDER_NOTE, $this->userId]);
        $pdo->prepare("UPDATE invoices SET status = 'paid', paid_total = 1210, paid_at = '2026-03-05' WHERE id = ?")->execute([$issued]);
        return [$supplierId, $dir];
    }

    /**
     * Přijatá faktura uhrazená výdajovým a vydaná příjmovým pokladním dokladem: vazba na
     * pokladní doklad, bez krytí mimo evidenci a v peněžním deníku každá jednou pokladním řádkem.
     */
    private function assertCashPaid(int $supplierId): void
    {
        $purchase = $this->row('SELECT id, status, paid_at, settled_outside_amount FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ?', [$supplierId, SyntheticPohodaExport::PURCHASE_PAID_BY_CASH]);
        self::assertSame('paid', $purchase['status']);
        self::assertSame('2026-02-15', substr((string) $purchase['paid_at'], 0, 10));
        self::assertSame(0.0, (float) $purchase['settled_outside_amount']);
        $issued = $this->row("SELECT i.id, i.status, i.paid_total, i.paid_at,
                (SELECT COUNT(*) FROM invoice_payments ip WHERE ip.invoice_id = i.id) AS payments,
                (SELECT COUNT(*) FROM invoice_payments ip WHERE ip.invoice_id = i.id AND ip.source = 'cash') AS cash
            FROM invoices i WHERE i.supplier_id = ? AND i.varsymbol = ?", [$supplierId, SyntheticPohodaExport::ISSUED_PAID_BY_CASH]);
        self::assertSame(['paid', 1210.0, '2026-03-05', 1, 1], [$issued['status'], (float) $issued['paid_total'], substr((string) $issued['paid_at'], 0, 10), (int) $issued['payments'], (int) $issued['cash']]);
        self::assertSame((int) $issued['id'], (int) $this->row('SELECT invoice_id FROM cash_documents WHERE supplier_id = ? AND doc_number LIKE ?', [$supplierId, SyntheticPohodaExport::CASH_ISSUED_PAYMENT . '%'])['invoice_id']);

        $journal = $this->cashJournal->build($supplierId, '2026-01-01', '2026-12-31', ['year' => 2026]);
        $expense = array_values(array_filter($journal['rows'], static fn (array $r): bool => $r['purchase_invoice_id'] === (int) $purchase['id']));
        self::assertCount(1, $expense, json_encode($expense, JSON_UNESCAPED_UNICODE));
        self::assertSame(['cash', 400.0], [$expense[0]['source_type'], (float) $expense[0]['expense']]);
        $income = array_values(array_filter($journal['rows'], static fn (array $r): bool => $r['invoice_id'] === (int) $issued['id']));
        self::assertCount(1, $income, json_encode($journal['rows'], JSON_UNESCAPED_UNICODE));
        self::assertSame(['cash', 1210.0], [$income[0]['source_type'], (float) $income[0]['income']]);
    }

    /** Dokončený převod v historii převodů, jak ho zapisuje průvodce i opravný převod. */
    private function recordRun(int $supplierId, ImportProtocol $protocol): void
    {
        $this->db->pdo()->prepare("INSERT INTO pohoda_imports (supplier_id, mode, status, agenda_year, protocol) VALUES (?, 'import', ?, 2026, ?)")
            ->execute([$supplierId, $protocol->status(), json_encode($protocol->toArray(), JSON_UNESCAPED_UNICODE)]);
    }

    private function ownCash(int $supplierId, string $purpose, string $date, string $status = 'posted'): void
    {
        $pdo = $this->db->pdo();
        $register = $pdo->prepare('SELECT id FROM cash_registers WHERE supplier_id = ? AND name = ?');
        $register->execute([$supplierId, 'Hlavní pokladna']);
        $id = $register->fetchColumn();
        if ($id === false) {
            $pdo->prepare("INSERT INTO cash_registers (supplier_id, name, account_code, currency_code, is_active) VALUES (?, 'Hlavní pokladna', '', 'CZK', 1)")->execute([$supplierId]);
            $id = $pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, description, total_amount, currency_code, status, created_by)
            VALUES (?, ?, 'in', ?, ?, ?, 'Vlastní doklad', 100, 'CZK', ?, ?)")->execute([$supplierId, (int) $id, $purpose, 'VL-' . $purpose . '-' . $date, $date, $status, $this->userId]);
    }

    /** @return array<string,mixed> kontext první zprávy s kódem */
    private function messageContext(ImportProtocol $protocol, string $code): array
    {
        foreach ($this->messages($protocol) as $m) {
            if (($m['code'] ?? '') === $code) {
                return (array) ($m['context'] ?? []);
            }
        }
        return [];
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);
        return $values;
    }

    private function cashCount(int $supplierId): int
    {
        return (int) $this->row('SELECT COUNT(*) AS n FROM cash_documents WHERE supplier_id = ?', [$supplierId])['n'];
    }

    private function assertNoAccounting(int $supplierId): void
    {
        $supplier = $this->row('SELECT accounting_mode, accounting_activation_status FROM supplier WHERE id = ?', [$supplierId]);
        self::assertSame('tax_evidence', $supplier['accounting_mode']);
        self::assertSame('none', $supplier['accounting_activation_status']);
        foreach (['accounting_periods', 'chart_of_accounts', 'journal_entries', 'posting_rules'] as $table) {
            self::assertSame(0, (int) $this->row("SELECT COUNT(*) AS n FROM {$table} WHERE supplier_id = ?", [$supplierId])['n'], $table);
        }
        self::assertSame(0, (int) $this->row('SELECT COUNT(*) AS n FROM invoices WHERE supplier_id = ? AND booked_at IS NOT NULL', [$supplierId])['n']);
        self::assertSame(0, (int) $this->row('SELECT COUNT(*) AS n FROM purchase_invoices WHERE supplier_id = ? AND booked_at IS NOT NULL', [$supplierId])['n']);
    }

    /** @return array<string,mixed> */
    private function automation(int $supplierId): array
    {
        return $this->unit->snapshot($supplierId)
            + $this->row('SELECT auto_post_invoices, auto_post_purchases FROM supplier WHERE id = ?', [$supplierId]);
    }

    /** @return array<string,int> */
    private function counts(int $supplierId): array
    {
        return [
            'purchase_invoices' => (int) $this->row('SELECT COUNT(*) AS n FROM purchase_invoices WHERE supplier_id = ?', [$supplierId])['n'],
            'invoices' => (int) $this->row('SELECT COUNT(*) AS n FROM invoices WHERE supplier_id = ?', [$supplierId])['n'],
            'bank_transactions' => (int) $this->row('SELECT COUNT(*) AS n FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE s.supplier_id = ?', [$supplierId])['n'],
            'map' => (int) $this->row('SELECT COUNT(*) AS n FROM pohoda_import_map WHERE supplier_id = ?', [$supplierId])['n'],
        ];
    }

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>
     */
    private function row(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, $sql);
        return $row;
    }

    private function supplier(?string $taxpayerType = 'fo'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, default_currency_id, default_vat_rate_id, accounting_mode, taxpayer_type)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, ?, ?, "tax_evidence", ?)'
        )->execute([SyntheticPohodaExport::NAME, $this->czId, SyntheticPohodaExport::ICO, 'CZ' . SyntheticPohodaExport::ICO, $this->currencyId, $this->vatRateId, $taxpayerType]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    /** @return list<array<string,mixed>> zprávy všech kroků i kontroly před převodem */
    private function messages(ImportProtocol $protocol): array
    {
        $out = (array) $protocol->get('preflight');
        foreach ($protocol->toArray()['steps'] as $step) {
            foreach ((array) ($step['messages'] ?? []) as $m) {
                $out[] = $m;
            }
        }
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

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps'], 'preflight' => $protocol->get('preflight')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
