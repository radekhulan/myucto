<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Action\Admin\Import\MoneyS3MigrationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\AutoPostingPolicyService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Importer;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3ImportJobService;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Převod syntetické agendy Money S3 do firmy v MyÚčtu — celý řetěz nad skutečnou DB:
 * záloha → deník, doklady, banka, pokladna → vazby a úhrady → uzávěrka 2024 →
 * rekonciliace. Izolovaná firma, transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class MoneyS3ImportTest extends TestCase
{
    private \Psr\Container\ContainerInterface $container;
    private Connection $db;
    private MoneyS3Importer $importer;
    private MoneyS3ImportRepository $map;
    private AutoPostingPolicyService $policy;
    private string $tmp = '';
    private int $userId = 0;
    private int $anyCurrencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->container = $container;
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(MoneyS3Importer::class);
            $this->map = $container->get(MoneyS3ImportRepository::class);
            $this->policy = $container->get(AutoPostingPolicyService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->anyCurrencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->anyCurrencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ms3int_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        SyntheticAgenda::writeLz($this->tmp . '/agenda.lz');
        file_put_contents($this->tmp . '/predvaha-2024.csv', SyntheticAgenda::trialBalanceCsv2024());

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
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testRoundTripReconcilesToTheCent(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId, ImportOptions::MODE_IMPORT, [2024 => $this->tmp . '/predvaha-2024.csv']);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $reconciliation = $protocol->get('reconciliation');
        self::assertCount(2, $reconciliation);
        foreach ($reconciliation as $year) {
            self::assertTrue($year['ok'], "Rok {$year['year']} nesedí: " . json_encode($year, JSON_UNESCAPED_UNICODE));
            self::assertSame([], $year['journal_diffs']);
        }
        $checks2024 = array_column($reconciliation[0]['checks'], 'ok', 'key');
        self::assertTrue($checks2024['money_report'], 'Předvaha z Money (ručně spočtená) musí sedět na haléř.');

        // 2024: 4 řádky XP → 1 otevírací zápis (nulový a degenerovaný vypadnou),
        // 7 dokladů; smazaný doklad FP24099 se nepřenese.
        self::assertSame(8, $this->rowCount('journal_entries', $supplierId, "YEAR(entry_date) = 2024 AND source_type <> 'closing'"));
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId, "document_no = 'FP24099'"));
        self::assertSame(8, $this->rowCount('purchase_invoices', $supplierId));
        self::assertSame(3, $this->rowCount('invoices', $supplierId));
        self::assertSame(2, $this->rowCount('cash_documents', $supplierId));
        self::assertSame(2, $this->rowCount('clients', $supplierId));
        self::assertSame(2, $this->rowCount('payment_matches', $supplierId));
        // Čárový kód z Money (BarCode) je klíč pro připojení naskenovaných příloh.
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "external_barcode = '90000101'"));
        self::assertSame(1, $this->rowCount('cash_documents', $supplierId, "external_barcode = '90000201'"));
        self::assertSame([], $protocol->get('orphans'));

        $tb = $this->container(TrialBalanceService::class)->build($supplierId, $this->periodId($supplierId, 2024), null, null, false);
        $rows = array_column($tb['rows'], null, 'account_code');
        self::assertEqualsWithDelta(62150.0, $rows['221']['ks_md'], 0.001);
        self::assertEqualsWithDelta(20000.0, $rows['602']['turnover_d'], 0.001);
    }

    /**
     * DPH evidence (VatLedgerService, z níž se staví přiznání i KH) smí z převodu dostat
     * jen doklady, jejichž druh a členění DPH jsou jisté. Zálohová faktura vedle konečné
     * by DPH započetla dvakrát, dobropis jako faktura s opačným znaménkem, doklad
     * přenesené daňové povinnosti jako tuzemský a cizí měna s kurzem 1.
     */
    public function testUncertainTaxDocumentsStayOutOfVatLedgerAsDrafts(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $rows = $this->container(VatLedgerService::class)->rows($supplierId, '2025-01-01', '2025-12-31');
        $vat = ['purchase' => 0.0, 'sale' => 0.0];
        foreach ($rows as $r) {
            $vat[$r['source']] = ($vat[$r['source']] ?? 0.0) + (float) $r['vat_czk'];
        }
        // FP25001 1 050 + konečná FP25002 210 + FP24001 z roku 2025 63; vydaná jen FV25001.
        self::assertEqualsWithDelta(1323.0, $vat['purchase'], 0.001, json_encode($rows, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertEqualsWithDelta(210.0, $vat['sale'], 0.001);

        $drafts = $this->db->pdo()->prepare(
            "SELECT vendor_invoice_number FROM purchase_invoices WHERE supplier_id = ? AND status = 'draft' ORDER BY vendor_invoice_number"
        );
        $drafts->execute([$supplierId]);
        self::assertSame(['DB-2025-001', 'EU-2025-001', 'RC-2025-001', 'ZF-2025-001'], $drafts->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(1, $this->rowCount('invoices', $supplierId, "status = 'draft' AND varsymbol = 'ZV25001'"));
        self::assertSame(0, $this->rowCount('purchase_invoices', $supplierId, "status = 'draft' AND booked_at IS NOT NULL"),
            'Koncept k ruční kontrole nesmí být zamčený jako zaúčtovaný.');

        $steps = array_column($protocol->toArray()['steps'], null, 'key');
        self::assertSame(4, $steps['purchase_invoices']['counts']['review'] ?? 0);
        self::assertSame(1, $steps['issued_invoices']['counts']['review'] ?? 0);
        self::assertContains('needs_review', array_column($steps['purchase_invoices']['messages'], 'code'));
    }

    /**
     * Money čísluje řadu každý rok od začátku. Faktura FP24001 z roku 2025 narazí na
     * unikátní číslo dokladu z roku 2024 — nesmí shodit celý krok přijatých faktur
     * (a s ním pokladnu, banku, vazby a uzávěrku).
     */
    public function testRepeatedMoneyNumberingAcrossYearsDoesNotAbortImport(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "varsymbol = 'FP24001' AND vendor_invoice_number = 'DF-2024-017'"));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "varsymbol = 'FP24001/2025' AND vendor_invoice_number = 'DF-2025-020'"));
        $steps = array_column($protocol->toArray()['steps'], null, 'key');
        foreach (['purchase_invoices', 'cash', 'bank', 'link', 'payments', 'closing'] as $key) {
            self::assertNotSame('error', $steps[$key]['status'] ?? 'missing', "Krok {$key} nesmí selhat.");
        }
    }

    /**
     * Faktura převedená dřív jako neuhrazená a spárovaná s úhradou až v dalším běhu
     * (novější záloha) musí dostat i stav „uhrazeno" — samotný záznam párování nestačí.
     */
    public function testLaterRunMarksInvoicePaidWhenItsPaymentIsLinked(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);
        $pdo = $this->db->pdo();
        $find = $pdo->prepare("SELECT id FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number = 'DF-2024-017'");
        $find->execute([$supplierId]);
        $invoiceId = (int) $find->fetchColumn();
        // Stav po dřívějším běhu, kdy faktura v záloze ještě uhrazená nebyla.
        $pdo->prepare("UPDATE purchase_invoices SET status = 'booked', paid_at = NULL WHERE id = ? AND supplier_id = ?")->execute([$invoiceId, $supplierId]);
        $pdo->prepare('DELETE FROM payment_matches WHERE purchase_invoice_id = ? AND supplier_id = ?')->execute([$invoiceId, $supplierId]);
        $pdo->prepare("DELETE FROM money_s3_import_map WHERE supplier_id = ? AND kind = 'payment' AND money_key = 'p|2024|FP24001'")->execute([$supplierId]);

        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('payment_matches', $supplierId, "purchase_invoice_id = {$invoiceId}"));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "id = {$invoiceId} AND status = 'paid' AND paid_at = '2024-02-20'"));
    }

    /**
     * Opakovaný převod novější zálohy existující doklad nepřepisuje (může být už
     * zaúčtovaný nebo upravený v MyÚčtu), ale změnu v Money musí ohlásit.
     */
    public function testChangeInMoneyAfterImportIsReportedNotOverwritten(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET total_with_vat = 6000 WHERE supplier_id = ? AND vendor_invoice_number = 'DF-2025-003'")
            ->execute([$supplierId]);

        $protocol = $this->import($supplierId);

        $messages = array_column($protocol->toArray()['steps'], null, 'key')['purchase_invoices']['messages'];
        $changed = array_values(array_filter($messages, static fn (array $m): bool => $m['code'] === 'changed_in_money'));
        self::assertCount(1, $changed, $this->explain($protocol));
        self::assertSame('FP25001', $changed[0]['context']['document_no']);
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DF-2025-003' AND total_with_vat = 6000"));
    }

    public function testMoneyPaymentMethodIsKeptOnPurchaseInvoice(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);

        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DF-2025-010' AND payment_method = 'card'"));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DF-2025-003' AND payment_method = 'bank_transfer'"));
    }

    public function testNewYearsDayEntryStaysInNewYear(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);

        $stmt = $this->db->pdo()->prepare(
            "SELECT e.entry_date, p.fiscal_year FROM journal_entries e JOIN accounting_periods p ON p.id = e.period_id
              WHERE e.supplier_id = ? AND e.document_no = 'ID25001'"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['entry_date' => '2025-01-01', 'fiscal_year' => 2025], ['entry_date' => $row['entry_date'], 'fiscal_year' => (int) $row['fiscal_year']]);
        self::assertSame(1, $this->rowCount('journal_entries', $supplierId, "entry_date = '2024-12-31' AND source_type <> 'closing'"),
            'Z deníku Money patří na 31. 12. 2024 jen ID24001 (uzávěrkový zápis roku je vlastní zápis MyÚčta).');
    }

    public function testRepeatedImportCreatesNothingNew(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);
        $before = $this->snapshotCounts($supplierId);

        $second = $this->import($supplierId);

        self::assertFalse($second->hasErrors(), $this->explain($second));
        self::assertSame($before, $this->snapshotCounts($supplierId));
        $journal = array_column($second->toArray()['steps'], null, 'key')['journal'];
        self::assertSame(0, $journal['counts']['entries'] ?? 0);
    }

    public function testHistoricalYearIsClosedWithoutDoublingOpeningBalances(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        $closing = array_column($protocol->get('closing'), null, 'year');
        self::assertSame('closed', $closing[2024]['status'], $this->explain($protocol));
        self::assertSame('open', $closing[2025]['status']);

        $period2025 = $this->periodId($supplierId, 2025);
        self::assertSame(1, $this->rowCount('journal_entries', $supplierId, "source_type = 'opening' AND period_id = {$period2025}"));
        $tb = $this->container(TrialBalanceService::class)->build($supplierId, $period2025, null, null, false);
        $rows = array_column($tb['rows'], null, 'account_code');
        self::assertEqualsWithDelta(8250.0, $rows['431']['ps_d'], 0.001);
        self::assertEqualsWithDelta(62150.0, $rows['221']['ps_md'], 0.001);
        self::assertTrue($tb['checks']['opening_balanced']);

        $stmt = $this->db->pdo()->prepare(
            "SELECT status FROM accounting_periods WHERE supplier_id = ? AND fiscal_year = 2024"
        );
        $stmt->execute([$supplierId]);
        self::assertSame('closed', $stmt->fetchColumn());
    }

    /**
     * Uzavření knih a otevření dalšího roku jsou dva kroky průvodce. Selže-li otevření
     * (nebo kontrola po něm), rok zůstane uzavřený s nedokončeným „open_next" — další
     * běh ho nesmí přeskočit jako „už uzavřený", jinak se další rok nikdy neotevře.
     */
    public function testClosedYearWithPendingOpenNextIsFinishedOnRerun(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);
        $period2024 = $this->periodId($supplierId, 2024);
        $period2025 = $this->periodId($supplierId, 2025);
        $this->db->pdo()->prepare(
            "UPDATE accounting_closing_steps SET status = 'pending', done_at = NULL
              WHERE supplier_id = ? AND period_id = ? AND step_key = 'open_next'"
        )->execute([$supplierId, $period2024]);

        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $closing = array_column($protocol->get('closing'), null, 'year');
        self::assertSame('next_opened', $closing[2024]['status']);
        $step = $this->db->pdo()->prepare(
            "SELECT status FROM accounting_closing_steps WHERE supplier_id = ? AND period_id = ? AND step_key = 'open_next'"
        );
        $step->execute([$supplierId, $period2024]);
        self::assertSame('done', $step->fetchColumn());
        self::assertSame(1, $this->rowCount('journal_entries', $supplierId, "source_type = 'opening' AND period_id = {$period2025}"));
    }

    public function testBankEntriesCarryTransactionIdAndStatementSourceIsImport(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);

        $stmt = $this->db->pdo()->prepare(
            "SELECT e.source_type, e.source_id, t.id AS tx_id, s.source
               FROM journal_entries e
               JOIN bank_transactions t ON t.source_ref = e.document_no
               JOIN bank_statements s ON s.id = t.statement_id AND s.supplier_id = e.supplier_id
              WHERE e.supplier_id = ? AND e.document_no = 'BV24001'"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame('bank', $row['source_type']);
        self::assertSame((int) $row['tx_id'], (int) $row['source_id']);
        self::assertSame('import', $row['source']);
    }

    /**
     * Každý bankovní účet má v Money vlastní číselnou řadu, takže stejné číslo dokladu
     * na dvou účtech je běžné. Klíč jen „rok|číslo" by druhý pohyb zahodil (nebo shodil
     * celý krok banky) a oba pohyby by se navázaly na oba zápisy deníku.
     */
    public function testSameBankDocumentNumberOnTwoAccountsIsImportedTwice(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.amount, COUNT(k.entry_id) AS links
               FROM bank_transactions t
               JOIN bank_statements s ON s.id = t.statement_id
               LEFT JOIN journal_entry_document_links k ON k.supplier_id = s.supplier_id AND k.doc_type = 'bank' AND k.doc_id = t.id
              WHERE s.supplier_id = ? AND t.source_ref = 'BV25001'
              GROUP BY t.id, t.amount
              ORDER BY t.amount"
        );
        $stmt->execute([$supplierId]);
        self::assertSame([['amount' => -100.0, 'links' => 1], ['amount' => -40.0, 'links' => 1]],
            array_map(static fn (array $r): array => ['amount' => (float) $r['amount'], 'links' => (int) $r['links']], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** Pozdější záznam daňové evidence v historii režimů nesmí převedené roky vrátit do DE. */
    public function testLaterTaxEvidenceRecordDoesNotOverrideConvertedYears(): void
    {
        $supplierId = $this->supplier();
        $modes = $this->container(AccountingModeRepository::class);
        $modes->record($supplierId, '2025-06-01', 'tax_evidence');

        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame('double_entry', $modes->forYear($supplierId, 2025));
    }

    public function testAccountingModeIsRecordedInBothPlaces(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);

        $s = $this->db->pdo()->prepare('SELECT accounting_mode, accounting_enabled, accounting_starts_on FROM supplier WHERE id = ?');
        $s->execute([$supplierId]);
        self::assertSame(['accounting_mode' => 'double_entry', 'accounting_enabled' => 1, 'accounting_starts_on' => '2024-01-01'],
            array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $s->fetch(PDO::FETCH_ASSOC)));
        $h = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier_accounting_modes WHERE supplier_id = ? AND effective_from = ?');
        $h->execute([$supplierId, '2024-01-01']);
        self::assertSame('double_entry', $h->fetchColumn());
    }

    public function testAutomationIsOffDuringImportAndRestoredAfterwards(): void
    {
        $supplierId = $this->supplier(SyntheticAgenda::ICO, 'double_entry');
        $this->policy->applyPreset($supplierId, 'assisted', $this->userId);

        $protocol = $this->import($supplierId);

        $automation = $protocol->get('automation');
        self::assertSame('off', $automation['during']);
        self::assertTrue($automation['restored']);
        self::assertSame('assisted', $automation['after']);
    }

    /**
     * Spadlý worker nezapíše protokol a řádek běhu zůstane „running". Další běh musí
     * automatiku vrátit na stav PŘED prvním během, ne na „vypnuto" po pádu.
     */
    public function testCrashedImportRestoresAutomationFromBeforeTheCrash(): void
    {
        $supplierId = $this->supplier(SyntheticAgenda::ICO, 'double_entry');
        $this->policy->applyPreset($supplierId, 'assisted', $this->userId);
        $crashed = $this->map->startRun($supplierId, null, 'import', [], $this->userId);
        try {
            $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT), $crashed,
                static function (string $step): void {
                    if ($step === 'partners') {
                        throw new \RuntimeException('worker spadl');
                    }
                });
            self::fail('Běh měl spadnout.');
        } catch (\RuntimeException $e) {
            self::assertSame('worker spadl', $e->getMessage());
        }
        self::assertSame('off', $this->policy->listPolicy($supplierId)['automation_level']);

        self::assertSame(1, $this->map->closeInterruptedRuns($supplierId));
        self::assertSame('failed', $this->map->findRun($crashed, $supplierId)['status']);
        $next = $this->map->startRun($supplierId, null, 'import', [], $this->userId);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT), $next);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame('assisted', $protocol->get('automation')['after']);
        self::assertNull($this->map->pendingAutomationSnapshot($supplierId), 'Po obnovení nesmí čekat žádný snímek.');
    }

    public function testMapRefusesSecondTargetForTheSameMoneyRecord(): void
    {
        $supplierId = $this->supplier();
        $this->map->put($supplierId, MoneyS3ImportRepository::KIND_INVOICE, '2025|FV1', 10, null);

        $this->expectException(MoneyS3Exception::class);
        $this->map->put($supplierId, MoneyS3ImportRepository::KIND_INVOICE, '2025|FV1', 11, null);
    }

    /** Druhý worker téže firmy (třeba po úklidu „mrtvého" jobu zkoušky) se nespustí. */
    public function testSecondWorkerForTheSameCompanyIsRefused(): void
    {
        $supplierId = $this->supplier();
        $other = Connection::withoutSharedTestConnection(fn (): Connection => new Connection($this->container->get(Config::class)));
        $otherRuns = new MoneyS3ImportRepository($other);
        self::assertTrue($otherRuns->acquireLock($supplierId));
        try {
            self::assertFalse($this->map->isLockFree($supplierId));
            $jobs = $this->container(ImportJobRepository::class);
            $jobId = $jobs->create($supplierId, MoneyS3ImportJobService::SOURCE, ['token' => str_repeat('a', 16), 'mode' => 'dry_run'], $this->userId);

            $this->container(MoneyS3ImportJobService::class)->run($jobId);

            $job = $jobs->find($jobId, $supplierId);
            self::assertSame('failed', $job['status']);
            self::assertStringContainsString('už běží', (string) $job['last_error']);
            self::assertSame([], $this->map->listRuns($supplierId));
        } finally {
            $otherRuns->releaseLock($supplierId);
            $other->close();
        }
        self::assertTrue($this->map->isLockFree($supplierId));
    }

    public function testFirstActivationGetsAccountingUnitDefaultsAfterImport(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        self::assertSame('off', $protocol->get('automation')['during']);
        self::assertSame('full', $protocol->get('automation')['after']);
    }

    public function testDryRunLeavesNothingBehind(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId, ImportOptions::MODE_DRY_RUN);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertTrue($protocol->get('reconciliation')[0]['ok']);
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId));
        self::assertSame(0, $this->rowCount('accounting_periods', $supplierId));
        self::assertSame(0, $this->map->countAll($supplierId));
        $s = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $s->execute([$supplierId]);
        self::assertSame('tax_evidence', $s->fetchColumn());
    }

    /**
     * Zkouška nanečisto drží jednu transakci po celou dobu běhu. Kdyby v ní vypínala
     * automatiku zápisem do řádku firmy, držela by na něm zámek a běžná práce firmy
     * (každý nový doklad kontroluje cizí klíč na firmu) by čekala na konec zkoušky.
     */
    public function testDryRunDoesNotWriteCompanyAutomationWhileRunning(): void
    {
        $supplierId = $this->supplier(SyntheticAgenda::ICO, 'double_entry');
        $this->policy->applyPreset($supplierId, 'assisted', $this->userId);
        $this->db->pdo()->prepare('UPDATE supplier SET auto_post_invoices = 1 WHERE id = ?')->execute([$supplierId]);
        $seen = [];
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_DRY_RUN), null,
            function (string $step) use ($supplierId, &$seen): void {
                if ($step !== 'purchase_invoices') {
                    return;
                }
                $flag = $this->db->pdo()->prepare('SELECT auto_post_invoices FROM supplier WHERE id = ?');
                $flag->execute([$supplierId]);
                $seen = ['level' => $this->policy->listPolicy($supplierId)['automation_level'], 'auto_post_invoices' => (int) $flag->fetchColumn()];
            });

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(['level' => 'assisted', 'auto_post_invoices' => 1], $seen);
        self::assertSame('off', $protocol->get('automation')['during'], 'Protokol ukazuje, co udělá ostrý převod.');
        self::assertSame('closed', array_column($protocol->get('closing'), null, 'year')[2024]['status'], $this->explain($protocol));
    }

    public function testDifferenceAgainstMoneyReportIsReported(): void
    {
        $supplierId = $this->supplier();
        file_put_contents($this->tmp . '/chybna.csv', str_replace('10 300,00;0,00', '10 301,00;0,00', SyntheticAgenda::trialBalanceCsv2024()));
        $protocol = $this->import($supplierId, ImportOptions::MODE_DRY_RUN, [2024 => $this->tmp . '/chybna.csv']);

        $year = $protocol->get('reconciliation')[0];
        self::assertFalse($year['ok']);
        self::assertSame(['518'], array_column($year['money_report']['diffs'], 'account'));
        self::assertTrue($protocol->hasErrors());
    }

    public function testAgendaOfAnotherCompanyIsRejected(): void
    {
        $supplierId = $this->supplier(SyntheticAgenda::VENDOR_ICO);
        $protocol = $this->import($supplierId);

        self::assertTrue($protocol->failed());
        self::assertSame(['ico_mismatch'], array_column(array_filter($protocol->get('preflight'), static fn ($m) => $m['level'] === 'error'), 'code'));
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId));
        self::assertSame(0, $this->map->countAll($supplierId));
    }

    /**
     * Ostrý převod zapisuje deník, přepíná režim účetnictví a automatiku a uzavírá roky —
     * samotné právo na import dat na to nestačí. Zkouška nanečisto nic nezanechá, ta
     * zůstává na `utilities.import`.
     */
    public function testLiveImportRequiresAccountingAndCompanySettingsRights(): void
    {
        $supplierId = $this->supplier();
        $action = $this->container(MoneyS3MigrationAction::class);
        $importOnly = ['utilities.import' => 2];
        $withoutClose = $importOnly + ['accounting.journal.write' => 2, 'settings.company.write' => 2];
        $full = $withoutClose + ['accounting.periods.close' => 2];
        $status = function (array $permissions, array $body) use ($action, $supplierId): int {
            $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/imports/money-s3/uploads/' . str_repeat('a', 16) . '/start')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
                ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, $permissions))
                ->withParsedBody($body);
            return $action->start($request, (new ResponseFactory())->createResponse(), ['token' => str_repeat('a', 16)])->getStatusCode();
        };

        self::assertSame(403, $status($importOnly, ['mode' => 'import', 'close_history' => false]));
        self::assertSame(403, $status($withoutClose, ['mode' => 'import', 'close_history' => true]));
        // Práva sedí → požadavek projde autorizací a narazí až na neexistující zálohu.
        self::assertSame(404, $status($withoutClose, ['mode' => 'import', 'close_history' => false]));
        self::assertSame(404, $status($full, ['mode' => 'import', 'close_history' => true]));
        self::assertSame(404, $status($importOnly, ['mode' => 'dry_run']));
    }

    /**
     * Bez IČO na jedné ze stran nejde ověřit, že záloha patří téhle firmě. Zkouška
     * nanečisto jen upozorní, ostrý převod potřebuje výslovné potvrzení.
     */
    public function testLiveImportWithoutVerifiableIcoNeedsExplicitConfirmation(): void
    {
        $supplierId = $this->supplier('');

        $refused = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT));
        self::assertTrue($refused->failed());
        self::assertSame(['ico_unverified'], array_column(array_filter($refused->get('preflight'), static fn ($m) => $m['level'] === 'error'), 'code'));
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId));

        $dry = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_DRY_RUN));
        self::assertFalse($dry->hasErrors(), $this->explain($dry));

        $confirmed = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT, true, null, [], [], true));
        self::assertFalse($confirmed->hasErrors(), $this->explain($confirmed));
    }

    public function testImportIsScopedToTheTargetCompany(): void
    {
        $a = $this->supplier();
        $this->import($a);
        $countsA = $this->snapshotCounts($a);

        $b = $this->supplier();
        $protocol = $this->import($b);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame($countsA, $this->snapshotCounts($a), 'Převod do firmy B nesmí sáhnout na firmu A.');
        self::assertSame($countsA, $this->snapshotCounts($b), 'Firma B dostane vlastní kopii, ne odkaz na data firmy A.');

        $runA = $this->map->startRun($a, null, 'import', [], $this->userId);
        self::assertNull($this->map->findRun($runA, $b), 'Protokol cizí firmy není vidět.');
    }

    public function testExistingBookkeepingIsNotMixedWithMoneyJournal(): void
    {
        $supplierId = $this->supplier(SyntheticAgenda::ICO, 'double_entry');
        $this->container(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $periodId = $this->container(AccountingPeriodRepository::class)->create($supplierId, 2025, '2025-01-01', '2025-12-31');
        $stmt = $this->db->pdo()->prepare("SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code IN ('518', '321') ORDER BY account_code");
        $stmt->execute([$supplierId]);
        [$liability, $expense] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $this->container(JournalEntryRepository::class)->insert(
            ['supplier_id' => $supplierId, 'period_id' => $periodId, 'entry_date' => '2025-03-01', 'source_type' => 'manual', 'posted_at' => '2025-03-01 10:00:00'],
            [['account_id' => $expense, 'side' => 'debit', 'amount' => '10.00'], ['account_id' => $liability, 'side' => 'credit', 'amount' => '10.00']],
        );

        $protocol = $this->import($supplierId);

        self::assertTrue($protocol->failed());
        self::assertContains('journal_not_empty', array_column($protocol->get('preflight'), 'code'));
        self::assertSame(1, $this->rowCount('journal_entries', $supplierId));
    }

    // ── pomocníci ─────────────────────────────────────────────────────────────

    private function supplier(string $ico = SyntheticAgenda::ICO, string $mode = 'tax_evidence'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, ?, ?)'
        )->execute([SyntheticAgenda::NAME, $this->czId, $ico, $this->anyCurrencyId, $this->vatRateId, $mode]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    /** @param array<int,string> $reports */
    private function import(int $supplierId, string $mode = ImportOptions::MODE_IMPORT, array $reports = []): ImportProtocol
    {
        return $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions($mode, true, null, [], $reports));
    }

    private function backup(): Ms3Backup
    {
        return Ms3Backup::extract($this->tmp . '/agenda.lz', $this->tmp . '/agenda-' . bin2hex(random_bytes(3)));
    }

    private function rowCount(string $table, int $supplierId, string $where = '1 = 1'): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$where}");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,int> */
    private function snapshotCounts(int $supplierId): array
    {
        $out = [];
        foreach (['journal_entries', 'journal_entry_lines', 'accounting_periods', 'purchase_invoices', 'invoices', 'cash_documents',
            'cash_registers', 'bank_statements', 'clients', 'payment_matches', 'posting_rules', 'journal_entry_document_links'] as $t) {
            $out[$t] = $this->rowCount($t, $supplierId);
        }
        $tx = $this->db->pdo()->prepare('SELECT COUNT(*) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE s.supplier_id = ?');
        $tx->execute([$supplierId]);
        $out['bank_transactions'] = (int) $tx->fetchColumn();
        return $out;
    }

    private function periodId(int $supplierId, int $year): int
    {
        return (int) $this->container(AccountingPeriodRepository::class)->findByYear($supplierId, $year)['id'];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function container(string $class): object
    {
        return $this->container->get($class);
    }

    private function explain(ImportProtocol $protocol): string
    {
        return json_encode($protocol->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
    }
}
