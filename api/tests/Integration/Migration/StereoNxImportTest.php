<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Action\Admin\Import\StereoNxMigrationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Service\Migration\StereoNx\StereoNxImporter;
use MyInvoice\Service\Migration\StereoNx\StereoNxImportJobService;
use MyInvoice\Service\Migration\StereoNx\StereoNxImportMap;
use MyInvoice\Service\Migration\StereoNx\StereoNxSourcePlan;
use MyInvoice\Service\Migration\StereoNx\StereoNxRunReportStore;
use MyInvoice\Service\Migration\StereoNx\StereoNxUploads;
use MyInvoice\Service\Migration\Shared\MigrationCompanyLock;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Service\TaxEvidence\CashJournalService;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxTables;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

/** Real encrypted NX1 ZIP → HTTP actions → database → VAT/cash journal, synthetic data only. */
#[Group('integration')]
final class StereoNxImportTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxImporter $importer;
    private StereoNxMigrationAction $action;
    private VatLedgerService $vat;
    private CashJournalService $journal;
    private int $supplierId;
    private int $userId;
    private string $tmp;
    private array $tokens = [];
    private array $runReportPaths = [];
    private const PASSWORD = 'Synthetic-NX-test-password';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php') && !getenv('MYINVOICE_DB_NAME')) {
            self::markTestSkipped('Integration database is not configured.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(StereoNxImporter::class);
        $this->action = $container->get(StereoNxMigrationAction::class);
        $this->vat = $container->get(VatLedgerService::class);
        $this->journal = $container->get(CashJournalService::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $source, 'Run ci-seed.php in the isolated test database.');
        self::assertGreaterThan(0, $this->userId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $identity = SyntheticStereoNxTables::identity();
        $pdo->prepare("UPDATE supplier SET company_name = ?, ic = ?, dic = ?, accounting_mode = 'tax_evidence', is_vat_payer = 1 WHERE id = ?")
            ->execute([$identity['name'], $identity['ico'], $identity['dic'], $this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
        $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
            VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech koruna', 2, 1, 1)")->execute([$this->supplierId]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $this->supplierId]);
        $this->tmp = sys_get_temp_dir() . '/stereo_e2e_' . bin2hex(random_bytes(8));
        mkdir($this->tmp, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->runReportPaths as $path) {
            $absolute = \MyInvoice\Infrastructure\Config\RuntimePaths::storage($path);
            if (is_file($absolute) && !is_link($absolute)) unlink($absolute);
        }
        foreach ($this->tokens as $token) {
            $dir = StereoNxUploads::dir($this->supplierId, $token);
            foreach (glob($dir . '/*') ?: [] as $path) if (is_file($path)) unlink($path);
            if (is_dir($dir)) rmdir($dir);
        }
        if (isset($this->tmp)) {
            foreach (glob($this->tmp . '/*') ?: [] as $path) if (is_file($path)) unlink($path);
            if (is_dir($this->tmp)) rmdir($this->tmp);
        }
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testDryRunWritesNoObjectsAndRepeatDoesNotDuplicateAnything(): void
    {
        $backup = $this->backup();
        $before = $this->snapshot();
        $dry = $this->importer->run($backup, $this->supplierId, $this->userId, true);
        self::assertTrue($dry['ok'], json_encode($dry, JSON_UNESCAPED_UNICODE));
        self::assertFalse($dry['database_writes']);
        self::assertCount(1, $dry['review_documents']);
        self::assertSame('purchase', $dry['review_documents'][0]['kind']);
        self::assertSame('PF-2', $dry['review_documents'][0]['document_no']);
        self::assertContains('vat_participation_unassigned', $dry['review_documents'][0]['review_codes']);
        self::assertNull($dry['review_documents'][0]['target_id'], 'A rolled-back dry run must not publish a nonexistent document ID.');
        self::assertGreaterThan(0, array_sum($dry['written']));
        self::assertSame($before, $this->snapshot());
        $import = $this->importer->run($backup, $this->supplierId, $this->userId, false);
        self::assertTrue($import['ok'], json_encode($import, JSON_UNESCAPED_UNICODE));
        self::assertTrue($import['database_writes']);
        $reviewId = $import['review_documents'][0]['target_id'];
        self::assertGreaterThan(0, $reviewId);
        self::assertSame('draft', $this->scalar('SELECT status FROM purchase_invoices WHERE id = ? AND supplier_id = ?', [$reviewId, $this->supplierId]));
        $after = $this->snapshot();
        self::assertGreaterThan($before['invoices'], $after['invoices']);
        $again = $this->importer->run($backup, $this->supplierId, $this->userId, false);
        self::assertTrue($again['ok'], json_encode($again, JSON_UNESCAPED_UNICODE));
        self::assertSame($after, $this->snapshot());
        self::assertSame('tax_evidence', $this->scalar('SELECT accounting_mode FROM supplier WHERE id = ?', [$this->supplierId]));
        self::assertSame(0, $after['journal_entries']);
    }

    public function testJobRefusesSecondImportWhileCompanyLockIsHeld(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $jobs = $container->get(ImportJobRepository::class);
        $jobId = $jobs->create($this->supplierId, StereoNxImportJobService::SOURCE,
            ['mode' => 'dry_run', 'token' => str_repeat('a', 32)], $this->userId);
        $lockDb = Connection::withoutSharedTestConnection(
            static fn (): Connection => new Connection($container->get(Config::class)));
        $lock = new MigrationCompanyLock($lockDb);
        try {
            self::assertTrue($lock->acquire(StereoNxImportJobService::SOURCE, $this->supplierId));
            $container->get(StereoNxImportJobService::class)->run($jobId);
            $job = $jobs->find($jobId, $this->supplierId);
            self::assertSame('failed', $job['status']);
            self::assertStringContainsString('už běží', (string) $job['last_error']);
        } finally {
            $lock->release(StereoNxImportJobService::SOURCE, $this->supplierId);
            $lockDb->close();
        }
    }

    public function testDifferentCompanyAndDoubleEntryAreRefusedWithoutWrites(): void
    {
        $backup = $this->backup();
        $before = $this->snapshot();
        $this->db->pdo()->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute(['00000019', $this->supplierId]);
        $wrong = $this->importer->run($backup, $this->supplierId, $this->userId, false);
        self::assertFalse($wrong['ok']);
        self::assertContains('ico_mismatch', array_column($wrong['errors'], 'code'));
        self::assertSame($before, $this->snapshot());
        $this->db->pdo()->prepare("UPDATE supplier SET ic = ?, accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([SyntheticStereoNxTables::identity()['ico'], $this->supplierId]);
        $wrong = $this->importer->run($backup, $this->supplierId, $this->userId, false);
        self::assertFalse($wrong['ok']);
        self::assertContains('accounting_mode_mismatch', array_column($wrong['errors'], 'code'));
        self::assertSame($before, $this->snapshot());
    }

    public function testDraftVatIsExcludedAndPhysicalPaymentsEnterCashJournalExactlyOnce(): void
    {
        $result = $this->importer->run($this->backup(), $this->supplierId, $this->userId, false);
        self::assertTrue($result['ok'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $stmt = $this->db->pdo()->prepare('SELECT varsymbol, status, total_with_vat, paid_amount_invoice_ccy FROM purchase_invoices WHERE supplier_id = ? ORDER BY varsymbol');
        $stmt->execute([$this->supplierId]);
        $purchases = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $purchases);
        self::assertSame('paid', $purchases[0]['status']);
        self::assertSame('draft', $purchases[1]['status']);
        self::assertSame(100.0, (float) $purchases[1]['total_with_vat']);
        self::assertSame(100.0, (float) $purchases[1]['paid_amount_invoice_ccy']);
        $ledger = $this->vat->rows($this->supplierId, '2025-03-01', '2025-03-31');
        self::assertCount(2, $ledger, 'Only the confirmed domestic sale and purchase enter VAT.');
        self::assertSame(['purchase', 'sale'], array_values(array_unique(array_column(array_reverse($ledger), 'source'))));
        foreach ($ledger as $row) {
            self::assertFalse($row['is_draft']);
            self::assertEqualsWithDelta(100.0, $row['base_czk'], 0.005);
            self::assertEqualsWithDelta(21.0, $row['vat_czk'], 0.005);
        }
        $journal = $this->journal->build($this->supplierId, '2025-01-01', '2025-12-31');
        self::assertCount(6, $journal['rows'], 'Five physical bank movements and one cash movement; payment links add no extra rows.');
        self::assertEqualsWithDelta(100.0, $journal['totals']['prijem_danovy'], 0.005);
        self::assertEqualsWithDelta(210.0, $journal['totals']['vydaj_danovy'], 0.005);
        self::assertEqualsWithDelta(91.0, $journal['totals']['prijem_nedanovy'], 0.005);
        self::assertEqualsWithDelta(21.0, $journal['totals']['vydaj_nedanovy'], 0.005);
        self::assertEqualsWithDelta(-40.0, $journal['closing_balance'], 0.005);
        self::assertEqualsWithDelta(0.0, $journal['totals']['nezarazeno'], 0.005);
    }

    public function testTargetPartnerCountryMismatchIsReportedAsActualDraft(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfh'][0]['FirmaStat'] = 'CZ';
        $tables['Svfh'][0]['FirmaDIC'] = 'CZ11111111';
        $plId = (int) $this->scalar("SELECT id FROM countries WHERE iso2 = 'PL'", []);
        self::assertGreaterThan(0, $plId);
        $currencyId = (int) $this->scalar('SELECT default_currency_id FROM supplier WHERE id = ?', [$this->supplierId]);
        $this->db->pdo()->prepare('INSERT INTO clients
            (supplier_id, company_name, ic, dic, street, city, zip, country_id, main_email, currency_default_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $this->supplierId, 'Syntetický odběratel', '11111111', 'CZ11111111',
            'Testovací 1', 'Vzorov', '10000', $plId, 'customer@example.invalid', $currencyId,
        ]);
        $backup = $this->backup($tables);

        $dry = $this->importer->run($backup, $this->supplierId, $this->userId, true);
        self::assertTrue($dry['ok'], json_encode($dry));
        self::assertSame(2, $dry['counts']['requires_draft']);
        self::assertSame(1, $dry['review_reasons']['partner_country_changed']);
        $issued = array_values(array_filter($dry['review_documents'],
            static fn (array $row): bool => $row['kind'] === 'issued'));
        self::assertCount(1, $issued);
        self::assertSame('VF-1', $issued[0]['document_no']);
        self::assertContains('partner_country_changed', $issued[0]['review_codes']);
        self::assertNull($issued[0]['target_id']);

        $import = $this->importer->run($backup, $this->supplierId, $this->userId, false);
        self::assertTrue($import['ok'], json_encode($import));
        self::assertSame(2, $import['counts']['requires_draft']);
        $issued = array_values(array_filter($import['review_documents'],
            static fn (array $row): bool => $row['kind'] === 'issued'));
        self::assertCount(1, $issued);
        self::assertGreaterThan(0, $issued[0]['target_id']);
        self::assertSame('draft', $this->scalar('SELECT status FROM invoices WHERE id = ? AND supplier_id = ?',
            [$issued[0]['target_id'], $this->supplierId]));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roundingAmounts')]
    public function testReversePurchaseRoundingRemainsTaxableCashExpense(float $rounding): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $total = 100.0 + $rounding;
        $tables['SPFH'][1]['Zaokrouhleni'] = $rounding;
        $tables['SPFH'][1]['Celkem'] = $total;
        $tables['Cpz'][2]['Celkem'] = $total;
        $tables['Cpz'][2]['Uhrazeno'] = $total;
        $tables['CBankap'][2]['Castka'] = $total;
        $tables['Cdenik'][2]['Celkem'] = $total;
        $report = $this->importer->run($this->backup($tables), $this->supplierId, $this->userId, false);
        self::assertTrue($report['ok'], json_encode($report));
        $journal = $this->journal->build($this->supplierId, '2025-01-01', '2025-12-31');
        self::assertEqualsWithDelta(210.0 + $rounding, $journal['totals']['vydaj_danovy'], 0.005);
        self::assertEqualsWithDelta(21.0, $journal['totals']['vydaj_nedanovy'], 0.005);
    }

    public static function roundingAmounts(): array
    {
        return ['positive' => [0.02], 'negative' => [-0.10]];
    }

    public function testChangedSourceRollsBackAndCannotOverwritePreviouslyImportedRecords(): void
    {
        $first = $this->importer->run($this->backup(), $this->supplierId, $this->userId, false);
        self::assertTrue($first['ok'], json_encode($first));
        $before = $this->snapshot();
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfh'][0]['Text'] = 'Syntetická změna po prvním importu';
        $changed = $this->importer->run($this->backup($tables), $this->supplierId, $this->userId, false);
        self::assertFalse($changed['ok']);
        self::assertContains('source_changed', array_column($changed['errors'], 'code'));
        self::assertSame($before, $this->snapshot());
    }

    public function testEuReviewLabelRenameKeepsLegacyDocumentHashButRealSourceChangeStillBlocks(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['LAdresy'][0]['Stat'] = 'EU';
        $tables['LAdresy'][0]['DIC'] = '';
        $tables['Svfh'][0]['FirmaStat'] = 'EU';
        $tables['Svfh'][0]['FirmaDIC'] = '';
        $backup = $this->backup($tables);
        $record = StereoNxSourcePlan::build($backup, true)['issued'][0];
        self::assertContains('partner_country_eu_unspecified', $record['review_codes']);
        unset($record['row'], $record['header']);
        $newHash = StereoNxImportMap::fingerprint($record);
        $legacy = $record;
        $legacy['review_codes'] = array_map(
            static fn (string $code): string => $code === 'partner_country_eu_unspecified'
                ? 'partner_country_unresolved' : $code,
            $legacy['review_codes'],
        );
        $legacyHash = StereoNxImportMap::fingerprint($legacy);
        self::assertNotSame($newHash, $legacyHash);

        $first = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($first['ok'], json_encode($first));
        self::assertSame($legacyHash, $this->scalar(
            'SELECT source_hash FROM stereo_nx_import_map WHERE supplier_id = ? AND kind = ? AND source_key = ?',
            [$this->supplierId, 'issued', $record['source_key']],
        ));
        $again = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($again['ok'], json_encode($again));
        self::assertSame(0, array_sum($again['written']));

        // Přechodná verze už mohla uložit otisk s novým názvem téhož důvodu.
        $this->db->pdo()->prepare('UPDATE stereo_nx_import_map SET source_hash = ?
            WHERE supplier_id = ? AND kind = ? AND source_key = ?')->execute([
            $newHash, $this->supplierId, 'issued', $record['source_key'],
        ]);
        $interim = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($interim['ok'], json_encode($interim));
        self::assertSame(0, array_sum($interim['written']));

        $tables['Svfh'][0]['Text'] = 'Skutečně změněný syntetický text';
        $changed = $this->importer->run($this->backup($tables), $this->supplierId, $this->userId, false, true);
        self::assertFalse($changed['ok']);
        self::assertContains('source_changed', array_column($changed['errors'], 'code'));
    }

    public function testOccupiedPeriodReportsEveryAgendaWithYearAndCount(): void
    {
        $backup = $this->backup();
        self::assertTrue($this->importer->run($backup, $this->supplierId, $this->userId, false)['ok']);
        $this->db->pdo()->prepare("DELETE FROM stereo_nx_import_map WHERE supplier_id = ? AND kind IN ('issued', 'purchase')")
            ->execute([$this->supplierId]);
        $before = $this->snapshot();
        $report = $this->importer->run($backup, $this->supplierId, $this->userId, true);
        self::assertFalse($report['ok']);
        $findings = array_values(array_filter($report['errors'], static fn (array $row): bool => $row['code'] === 'target_period_not_empty'));
        self::assertCount(2, $findings);
        self::assertSame(['invoices' => 1, 'purchase_invoices' => 2], array_column($findings, 'count', 'agenda'));
        foreach ($findings as $finding) {
            self::assertSame(2025, $finding['year']);
            self::assertStringContainsString('2025', $finding['message']);
            self::assertStringContainsString((string) $finding['count'], $finding['message']);
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testClosedPeriodPreventsImport(): void
    {
        $this->db->pdo()->prepare("INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
            VALUES (?, 2025, '2025-01-01', '2025-12-31', 'closed')")->execute([$this->supplierId]);
        $before = $this->snapshot();
        $report = $this->importer->run($this->backup(), $this->supplierId, $this->userId, false);
        self::assertFalse($report['ok']);
        self::assertContains('period_closed', array_column($report['errors'], 'code'));
        self::assertSame($before, $this->snapshot());
    }

    public function testHistoricalDocumentPreservesSourceDateAndRateSnapshot(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        foreach ($tables as &$rows) foreach ($rows as &$row) foreach ($row as &$value) {
            if (is_string($value) && preg_match('/^2025-\d{2}-\d{2}$/D', $value)) $value = '2023' . substr($value, 4);
        }
        unset($rows, $row, $value);
        $report = $this->importer->run($this->backup($tables), $this->supplierId, $this->userId, false);
        self::assertTrue($report['ok'], json_encode($report, JSON_UNESCAPED_UNICODE));
        self::assertSame('2023-03-15', $this->scalar('SELECT issue_date FROM invoices WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame(21.0, (float) $this->scalar('SELECT it.vat_rate_snapshot FROM invoice_items it JOIN invoices i ON i.id = it.invoice_id WHERE i.supplier_id = ? LIMIT 1', [$this->supplierId]));
    }

    public function testZeroOpeningCashRecordDoesNotCreateAnInvalidPhysicalPayment(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['CPokl'][0]['Castka'] = 0.0;
        foreach ($tables['Cdenik'] as &$row) if ($row['Agenda'] === 'P') $row['Celkem'] = 0.0;
        unset($row);
        $backup = $this->backup($tables);
        $report = $this->importer->run($backup, $this->supplierId, $this->userId, false);
        self::assertTrue($report['ok'], json_encode($report, JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $report['written']['skipped_zero_cash']);
        self::assertSame(0, $this->snapshot()['cash_documents']);
        $before = $this->snapshot();
        $again = $this->importer->run($backup, $this->supplierId, $this->userId, false);
        self::assertTrue($again['ok'], json_encode($again));
        self::assertSame($before, $this->snapshot());
    }

    /** Pokladní číslo delší než sloupec (30) a dvojí číslo ve zdroji neshodí převod. */
    public function testLongAndDuplicateCashNumbersGetUniqueNumbersWithinColumnLimit(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $long = 'P-' . str_repeat('7', 38);
        $tables['CPokl'][0]['Doklad'] = $long;
        $second = $tables['CPokl'][0];
        $second['DoklCislo'] = 2;
        $second['Castka'] = 5.0;
        $tables['CPokl'][] = $second;
        $journal = array_values(array_filter($tables['Cdenik'], static fn (array $row): bool => $row['Agenda'] === 'P'))[0];
        $journal['DoklCislo'] = 2;
        $journal['Celkem'] = 5.0;
        $tables['Cdenik'][] = $journal;

        $report = $this->importer->run($this->backup($tables), $this->supplierId, $this->userId, false);
        self::assertTrue($report['ok'], json_encode($report, JSON_UNESCAPED_UNICODE));
        $stmt = $this->db->pdo()->prepare('SELECT doc_number FROM cash_documents WHERE supplier_id = ? ORDER BY id');
        $stmt->execute([$this->supplierId]);
        $numbers = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $truncated = mb_substr($long, 0, 30);
        self::assertSame([$truncated, mb_substr($truncated, 0, 28) . '-2'], $numbers);
        self::assertContains('cash_number_duplicate', array_column($report['warnings'], 'code'));
    }

    public function testRepeatPreservesUserClassificationAndDisabledBankAccount(): void
    {
        $backup = $this->backup();
        $report = $this->importer->run($backup, $this->supplierId, $this->userId, false);
        self::assertTrue($report['ok'], json_encode($report));
        $id = (int) $this->scalar("SELECT bank_transaction_id FROM de_movement_classification WHERE supplier_id = ? AND tax_bucket = 'expense_taxable' LIMIT 1", [$this->supplierId]);
        self::assertGreaterThan(0, $id);
        $this->db->pdo()->prepare("UPDATE de_movement_classification SET tax_bucket = 'expense_nontax', note = 'Synthetic manual correction' WHERE supplier_id = ? AND bank_transaction_id = ?")
            ->execute([$this->supplierId, $id]);
        $this->db->pdo()->prepare('UPDATE supplier_bank_accounts SET is_active = 0 WHERE supplier_id = ?')->execute([$this->supplierId]);
        $again = $this->importer->run($backup, $this->supplierId, $this->userId, false);
        self::assertTrue($again['ok'], json_encode($again));
        self::assertSame('expense_nontax', $this->scalar('SELECT tax_bucket FROM de_movement_classification WHERE supplier_id = ? AND bank_transaction_id = ?', [$this->supplierId, $id]));
        self::assertSame(0, (int) $this->scalar('SELECT SUM(is_active) FROM supplier_bank_accounts WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame(0, $again['written']['classifications']);
    }

    public function testNewPaymentRefreshesPreviouslyImportedInvoiceAndStatement(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['CBankap'] = array_values(array_filter($tables['CBankap'], static fn (array $row): bool => $row['Klic'] !== 1));
        $tables['Cdenik'] = array_values(array_filter($tables['Cdenik'], static fn (array $row): bool => $row['Agenda'] !== 'B' || $row['Klic'] !== 1));
        $tables['Cpz'][0]['Uhrazeno'] = 0.0;
        $tables['Cpz'][0]['UhrazenoVse'] = false;
        $first = $this->importer->run($this->backup($tables), $this->supplierId, $this->userId, false);
        self::assertTrue($first['ok'], json_encode($first));
        self::assertSame(0.0, (float) $this->scalar('SELECT paid_total FROM invoices WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame(4, (int) $this->scalar('SELECT transaction_count FROM bank_statements WHERE supplier_id = ?', [$this->supplierId]));
        $second = $this->importer->run($this->backup(), $this->supplierId, $this->userId, false);
        self::assertTrue($second['ok'], json_encode($second));
        self::assertSame(121.0, (float) $this->scalar('SELECT paid_total FROM invoices WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame('paid', $this->scalar('SELECT status FROM invoices WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame(5, (int) $this->scalar('SELECT transaction_count FROM bank_statements WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame(3, (int) $this->scalar('SELECT matched_count FROM bank_statements WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame(1, $second['written']['payments']);
    }

    public function testUntaxedRecapSlotDoesNotTurnFullDeductionIntoAnAmbiguousDraft(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['SPFH'][0]['BezDane'] = 50.0;
        $tables['SPFH'][0]['Celkem'] = 171.0;
        $tables['Cpz'][1]['Celkem'] = 171.0;
        $tables['Cpz'][1]['UhrazenoVse'] = false;
        $report = $this->importer->run($this->backup($tables), $this->supplierId, $this->userId, false);
        self::assertTrue($report['ok'], json_encode($report, JSON_UNESCAPED_UNICODE));
        self::assertSame('received', $this->scalar("SELECT status FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = 'PF-1'", [$this->supplierId]));
        self::assertSame('full', $this->scalar("SELECT vat_deduction FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = 'PF-1'", [$this->supplierId]));
        self::assertSame(171.0, (float) $this->scalar("SELECT total_with_vat FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = 'PF-1'", [$this->supplierId]));
        $ledger = array_values(array_filter($this->vat->rows($this->supplierId, '2025-03-01', '2025-03-31'), static fn (array $row): bool => $row['source'] === 'purchase'));
        self::assertEqualsWithDelta(21.0, array_sum(array_column($ledger, 'vat_czk')), 0.005);
    }

    /** Existující kontakt s IČO uloženým v jiném tvaru (mezery) se spáruje, druhý nevznikne. */
    public function testExistingClientWithDifferentlyFormattedIcoIsReused(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO clients (supplier_id, company_name, ic, dic, street, city, zip, country_id, currency_default_id, is_customer, is_vendor, is_vat_payer, auto_send_reminders)
            VALUES (?, 'Odběratel už v MyÚčtu', '111 11 111', 'CZ11111111', 'Testovací 1', 'Vzorov', '10000',
                    (SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1), (SELECT default_currency_id FROM supplier WHERE id = ?), 1, 0, 1, 0)")->execute([$this->supplierId, $this->supplierId]);
        $existing = (int) $pdo->lastInsertId();

        $report = $this->importer->run($this->backup(), $this->supplierId, $this->userId, false);
        self::assertTrue($report['ok'], json_encode($report, JSON_UNESCAPED_UNICODE));
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM clients WHERE supplier_id = ? AND company_name = 'Syntetický odběratel'", [$this->supplierId]));
        self::assertSame($existing, (int) $this->scalar("SELECT client_id FROM invoices WHERE supplier_id = ? AND varsymbol LIKE 'VF%' LIMIT 1", [$this->supplierId]));
    }

    /** Krácený odpočet (§ 76): převod nastaví koeficient, jinak by přiznání s ř. 52 nešlo sestavit. */
    public function testReducedDeductionGetsCoefficientSoTheReturnCanBeBuilt(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Lsdph'][1]['Kraceni'] = true;
        $report = $this->importer->run($this->backup($tables), $this->supplierId, $this->userId, false);
        self::assertTrue($report['ok'], json_encode($report, JSON_UNESCAPED_UNICODE));
        self::assertSame('reduced', $this->scalar("SELECT vat_deduction FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = 'PF-1'", [$this->supplierId]));
        self::assertContains('provisional_from_own_year', array_column($report['warnings'], 'code'));
        $return = Bootstrap::buildContainer()->get(\MyInvoice\Service\Report\DphPriznaniBuilder::class)->build($this->supplierId, 2025, 3, 'monthly');
        self::assertEqualsWithDelta(21.0, (float) ($return['summary']['lines']['40k']['vat'] ?? 0), 0.005);
    }

    public function testFailureAfterDocumentWritesRollsBackAllObjects(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        // A distinct tenant claims the synthetic own account before the import.
        $other = $this->createIsolatedSupplier($this->db->pdo(), $this->supplierId);
        $this->db->pdo()->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals,
            is_active, is_default, account_number, bank_code) VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech koruna', 2,
            1, 1, ?, ?)")->execute([$other, $tables['LFirmaUc'][0]['BaUcet'], $tables['LFirmaUc'][0]['KodBanky']]);
        $before = $this->snapshot();
        $report = $this->importer->run($this->backup($tables), $this->supplierId, $this->userId, false);
        self::assertFalse($report['ok']);
        self::assertContains('bank_account_conflict', array_column($report['errors'], 'code'));
        self::assertSame($before, $this->snapshot(), 'Client and document inserts performed before the account failure must roll back.');
    }

    public function testAccountingDocumentsCannotBypassAClosedYearOutsideJournalDates(): void
    {
        $fixture = \MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxAccountingTables::class;
        $identity = $fixture::identity();
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET ic = ?, accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$identity['ico'], $this->supplierId]);
        $periods = Bootstrap::buildApp()->getContainer()->get(\MyInvoice\Repository\AccountingPeriodRepository::class);
        $period = $periods->create($this->supplierId, 2025, '2025-01-01', '2025-12-31');
        $pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ? AND supplier_id = ?")
            ->execute([$period, $this->supplierId]);
        $documents = SyntheticStereoNxTables::tables();
        $tables = array_replace($documents, $fixture::tables());
        $tables['LAdresy'] = $documents['LAdresy'];
        foreach (['CBanka', 'CBankap', 'CPokl'] as $table) $tables[$table] = [];
        $path = $this->tmp . '/closed-document-year.zip';
        SyntheticNx1Archive::write($path, $tables, $identity, self::PASSWORD);
        $before = $this->snapshot();
        $report = Bootstrap::buildApp()->getContainer()->get(\MyInvoice\Service\Migration\StereoNx\StereoNxAccountingImporter::class)
            ->run(StereoNxBackup::open($path, 0, self::PASSWORD), $this->supplierId, $this->userId, false);
        self::assertFalse($report['ok']);
        self::assertContains('document_date_locked', array_column($report['errors'], 'code'), json_encode($report));
        self::assertSame($before, $this->snapshot());
    }

    public function testAccountingJobRollsBackDryRunAndImportsJournalIdempotently(): void
    {
        $fixture = \MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxAccountingTables::class;
        $identity = $fixture::identity();
        $this->db->pdo()->prepare("UPDATE supplier SET ic = ?, dic = ?, accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$identity['ico'], $identity['dic'], $this->supplierId]);
        $path = $this->tmp . '/accounting.zip';
        SyntheticNx1Archive::write($path, $fixture::tables(), $identity, self::PASSWORD);
        $bytes = file_get_contents($path);
        $init = $this->call('init', ['file_name' => 'accounting.zip', 'size' => strlen($bytes)]);
        self::assertSame(201, $init->getStatusCode());
        $token = $this->json($init)['token'];
        $this->tokens[] = $token;
        self::assertSame(200, $this->chunk($token, 0, $bytes)->getStatusCode());
        self::assertSame(200, $this->call('complete', [], $token)->getStatusCode());
        $body = ['company' => 0, 'password' => self::PASSWORD, 'mode' => 'import'];
        self::assertSame(409, $this->call('run', $body, $token)->getStatusCode());
        $before = $this->snapshot();
        $dry = $this->runJob(['mode' => 'dry_run'] + $body, $token);
        self::assertTrue($dry['report']['ok'], json_encode($dry));
        self::assertSame('double_entry', $dry['report']['accounting_mode']);
        self::assertSame($before, $this->snapshot());
        // A dry run of another accounting mode must not authorize this import.
        $state = StereoNxUploads::state($this->supplierId, $token);
        $state['dry_run_accounting_mode'] = 'tax_evidence';
        StereoNxUploads::save($this->supplierId, $token, $state);
        self::assertSame(409, $this->call('run', $body, $token)->getStatusCode());
        $this->runJob(['mode' => 'dry_run'] + $body, $token);
        $result = $this->runJob($body, $token);
        self::assertTrue($result['report']['ok'], json_encode($result));
        self::assertTrue($result['report']['database_writes']);
        $after = $this->snapshot();
        self::assertSame(4, $after['journal_entries'] - $before['journal_entries']);
        $this->runJob(['mode' => 'dry_run'] + $body, $token);
        $again = $this->runJob($body, $token);
        self::assertTrue($again['report']['ok'], json_encode($again));
        self::assertSame($after, $this->snapshot());
        SyntheticNx1Archive::write($path, $fixture::tables(1400.00), $identity, self::PASSWORD);
        $changed = Bootstrap::buildApp()->getContainer()->get(\MyInvoice\Service\Migration\StereoNx\StereoNxAccountingImporter::class)
            ->run(StereoNxBackup::open($path, 0, self::PASSWORD), $this->supplierId, $this->userId, false);
        self::assertFalse($changed['ok']);
        self::assertFalse($changed['database_writes']);
        self::assertSame([], $changed['written']);
        self::assertSame($after, $this->snapshot());
    }

    public function testHttpUploadChecksOwnershipPasswordAndRequiresDryRunBeforeExecute(): void
    {
        $this->backup();
        $bytes = file_get_contents($this->tmp . '/backup.zip');
        $init = $this->call('init', ['file_name' => 'synthetic.zip', 'size' => strlen($bytes)]);
        self::assertSame(201, $init->getStatusCode(), (string) $init->getBody());
        $token = $this->json($init)['token'];
        $this->tokens[] = $token;
        $half = intdiv(strlen($bytes), 2);
        self::assertSame(200, $this->chunk($token, 0, substr($bytes, 0, $half))->getStatusCode());
        self::assertGreaterThanOrEqual(400, $this->chunk($token, 0, substr($bytes, 0, $half))->getStatusCode());
        self::assertGreaterThanOrEqual(400, $this->call('complete', [], $token)->getStatusCode());
        self::assertSame(200, $this->chunk($token, $half, substr($bytes, $half))->getStatusCode());
        self::assertSame(200, $this->call('complete', [], $token)->getStatusCode());
        $savedUpload = $this->json($this->call('index', []))['uploads'][0];
        self::assertSame($token, $savedUpload['token']);
        self::assertSame('synthetic.zip', $savedUpload['filename']);
        self::assertTrue($savedUpload['complete']);
        $wrongUser = $this->request([])->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId + 999999]);
        self::assertSame(404, $this->action->show($wrongUser, (new ResponseFactory())->createResponse(), ['token' => $token])->getStatusCode());
        $wrongTenant = $this->request([])->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId + 999999);
        self::assertSame(404, $this->action->show($wrongTenant, (new ResponseFactory())->createResponse(), ['token' => $token])->getStatusCode());
        $noPermission = $this->request([])->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', false, ['utilities.import' => 1]));
        self::assertSame(403, $this->action->run($noPermission, (new ResponseFactory())->createResponse(), ['token' => $token])->getStatusCode());
        self::assertSame(422, $this->call('preview', ['password' => 'incorrect-synthetic-password'], $token)->getStatusCode());
        $preview = $this->call('preview', ['password' => self::PASSWORD], $token);
        self::assertSame(200, $preview->getStatusCode(), (string) $preview->getBody());
        self::assertTrue($this->json($preview)['companies'][0]['matches_target']);
        $body = ['company' => 0, 'password' => self::PASSWORD, 'mode' => 'import'];
        self::assertSame(409, $this->call('run', $body, $token)->getStatusCode());
        $before = $this->snapshot();
        $dry = $this->runJob(['mode' => 'dry_run'] + $body, $token);
        self::assertTrue($dry['report']['ok'], json_encode($dry));
        self::assertSame($before, $this->snapshot());
        self::assertSame(409, $this->call('run', ['blank_country_is_cz' => true] + $body, $token)->getStatusCode(), 'Changing the country interpretation requires a fresh dry run.');
        self::assertSame($before, $this->snapshot());
        $import = $this->runJob($body, $token);
        self::assertTrue($import['report']['ok'], json_encode($import));
        self::assertStringNotContainsString(self::PASSWORD, file_get_contents(StereoNxUploads::dir($this->supplierId, $token) . '/state.json'));
        self::assertSame(409, $this->call('run', $body, $token)->getStatusCode(), 'Completed import consumes the dry-run gate.');
        self::assertSame(200, $this->call('delete', [], $token)->getStatusCode());
        self::assertFileDoesNotExist(StereoNxUploads::archive($this->supplierId, $token));
    }

    public function testBankSeriesSuggestionRequiresConfirmationAndBindsBothJobChecksToDryRun(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['LFirmaUc'][0]['DoklRada'] = '';
        $this->backup($tables);
        $bytes = file_get_contents($this->tmp . '/backup.zip');
        $init = $this->call('init', ['file_name' => 'synthetic.zip', 'size' => strlen($bytes)]);
        $token = $this->json($init)['token'];
        $this->tokens[] = $token;
        self::assertSame(200, $this->chunk($token, 0, $bytes)->getStatusCode());
        self::assertSame(200, $this->call('complete', [], $token)->getStatusCode());
        $body = ['company' => 0, 'password' => self::PASSWORD, 'mode' => 'dry_run'];
        $plan = $this->json($this->call('preview', $body, $token))['companies'][0]['bank_account_series'] ?? null;
        self::assertIsArray($plan);
        self::assertTrue($plan['requires_confirmation']);
        self::assertFalse($plan['ambiguous']);
        self::assertSame('B', $plan['assignments'][0]['series']);
        $before = $this->snapshot();
        foreach ([null, 'true', 1] as $invalidConfirmation) {
            self::assertSame(422, $this->call('run', ['match_bank_account_series' => $invalidConfirmation] + $body, $token)->getStatusCode());
        }
        $denied = $this->call('run', $body, $token);
        self::assertSame(422, $denied->getStatusCode());
        self::assertSame('bank_account_series_confirmation_required', $this->json($denied)['error']['code']);
        $body['match_bank_account_series'] = true;
        $dry = $this->runJob($body, $token);
        self::assertTrue($dry['report']['ok'], json_encode($dry));
        self::assertContains('bank_account_series_confirmed', array_column($dry['report']['warnings'], 'code'));
        self::assertSame($before, $this->snapshot());
        self::assertTrue(StereoNxUploads::state($this->supplierId, $token)['dry_run_match_bank_account_series']);
        $body['mode'] = 'import';
        // HTTP preflight must reject a different interpretation of the same ZIP.
        $state = StereoNxUploads::state($this->supplierId, $token);
        $state['dry_run_match_bank_account_series'] = false;
        StereoNxUploads::save($this->supplierId, $token, $state);
        self::assertSame(409, $this->call('run', $body, $token)->getStatusCode());
        $state['dry_run_match_bank_account_series'] = true;
        StereoNxUploads::save($this->supplierId, $token, $state);
        $started = $this->call('run', $body, $token);
        self::assertSame(202, $started->getStatusCode());
        $jobId = $this->json($started)['job_id'];
        // The worker repeats the same check after the request has returned.
        $state['dry_run_match_bank_account_series'] = false;
        StereoNxUploads::save($this->supplierId, $token, $state);
        Bootstrap::buildContainer()->get(StereoNxImportJobService::class)->run($jobId);
        $job = Bootstrap::buildContainer()->get(ImportJobRepository::class)->find($jobId, $this->supplierId);
        self::assertSame('failed', $job['status']);
        self::assertSame($before, $this->snapshot());
        $this->runJob(['mode' => 'dry_run'] + $body, $token);
        $import = $this->runJob($body, $token);
        self::assertTrue($import['report']['ok'], json_encode($import));
        self::assertSame(1, $import['report']['written']['bank_statements']);
        self::assertSame('', iterator_to_array(StereoNxBackup::open(StereoNxUploads::archive($this->supplierId, $token), 0, self::PASSWORD)->rows('LFirmaUc'), false)[0]['DoklRada']);
    }

    public function testUnavailableReportStorageDoesNotFailFinishedJob(): void
    {
        $this->backup();
        $bytes = file_get_contents($this->tmp . '/backup.zip');
        $token = $this->json($this->call('init', ['file_name' => 'synthetic.zip', 'size' => strlen($bytes)]))['token'];
        $this->tokens[] = $token;
        self::assertSame(200, $this->chunk($token, 0, $bytes)->getStatusCode());
        self::assertSame(200, $this->call('complete', [], $token)->getStatusCode());
        // Soubor místo adresáře reportů: trvalou kopii protokolu nejde uložit.
        $blocked = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('import-jobs/' . $this->supplierId . '/reports');
        if (!is_dir(dirname($blocked))) mkdir(dirname($blocked), 0700, true);
        file_put_contents($blocked, 'synthetic');
        try {
            $started = $this->call('run', ['company' => 0, 'password' => self::PASSWORD, 'mode' => 'dry_run'], $token);
            self::assertSame(202, $started->getStatusCode(), (string) $started->getBody());
            $jobId = (int) $this->json($started)['job_id'];
            $container = Bootstrap::buildContainer();
            $container->get(StereoNxImportJobService::class)->run($jobId);
            $jobs = $container->get(ImportJobRepository::class);
            $job = $jobs->find($jobId, $this->supplierId);
            self::assertSame('completed', $job['status'], (string) ($job['last_error'] ?? ''));
            self::assertNull($job['result_path']);
            $result = $this->action->result($this->request([]), (new ResponseFactory())->createResponse(), ['token' => $token, 'id' => (string) $jobId]);
            self::assertSame(200, $result->getStatusCode(), (string) $result->getBody());
            self::assertTrue($this->json($result)['report']['ok']);
        } finally {
            unlink($blocked);
            @rmdir(dirname($blocked));
        }
        $this->action->result($this->request([]), (new ResponseFactory())->createResponse(), ['token' => $token, 'id' => (string) $jobId]);
        $job = Bootstrap::buildContainer()->get(ImportJobRepository::class)->find($jobId, $this->supplierId);
        self::assertSame('import-jobs/' . $this->supplierId . '/reports/stereo-nx-' . $jobId . '.json', $job['result_path']);
        $this->runReportPaths[] = $job['result_path'];
    }

    public function testCompanyProfileAppliesSelectedFieldsAndKeepsSettingsAuthorization(): void
    {
        $this->backup();
        $bytes = file_get_contents($this->tmp . '/backup.zip');
        $init = $this->call('init', ['file_name' => 'profile.zip', 'size' => strlen($bytes)]);
        $token = $this->json($init)['token'];
        $this->tokens[] = $token;
        self::assertSame(200, $this->chunk($token, 0, $bytes)->getStatusCode());
        self::assertSame(200, $this->call('complete', [], $token)->getStatusCode());
        $this->db->pdo()->prepare("UPDATE supplier SET dic = '', company_name = 'Aktuální název' WHERE id = ?")->execute([$this->supplierId]);
        $body = ['company' => 0, 'password' => self::PASSWORD, 'fields' => ['dic'], 'expected_values' => ['dic' => '']];
        $preview = $this->json($this->call('preview', $body, $token));
        self::assertSame(SyntheticStereoNxTables::identity()['dic'], $preview['companies'][0]['profile_suggestions']['dic']);
        self::assertSame('Aktuální název', $preview['companies'][0]['profile_current']['company_name']);
        $limited = $this->request($body)->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', false, ['utilities.import' => 2]));
        $denied = $this->action->fillCompanyProfile($limited, (new ResponseFactory())->createResponse(), ['token' => $token]);
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('', $this->scalar('SELECT dic FROM supplier WHERE id = ?', [$this->supplierId]));
        $filled = $this->call('fillCompanyProfile', $body, $token);
        self::assertSame(200, $filled->getStatusCode(), (string) $filled->getBody());
        self::assertSame(['dic'], $this->json($filled)['filled_fields']);
        self::assertSame(SyntheticStereoNxTables::identity()['dic'], $this->scalar('SELECT dic FROM supplier WHERE id = ?', [$this->supplierId]));
        self::assertSame('Aktuální název', $this->scalar('SELECT company_name FROM supplier WHERE id = ?', [$this->supplierId]));
        self::assertSame(409, $this->call('fillCompanyProfile', $body, $token)->getStatusCode());
        $overwrite = ['company' => 0, 'password' => self::PASSWORD, 'fields' => ['company_name'], 'expected_values' => ['company_name' => 'Aktuální název']];
        self::assertSame(['company_name'], $this->json($this->call('fillCompanyProfile', $overwrite, $token))['filled_fields']);
        self::assertSame(SyntheticStereoNxTables::identity()['name'], $this->scalar('SELECT company_name FROM supplier WHERE id = ?', [$this->supplierId]));
        $this->db->pdo()->prepare("UPDATE supplier SET dic = '', ic = '99999999' WHERE id = ?")->execute([$this->supplierId]);
        $mismatch = $this->call('fillCompanyProfile', $body, $token);
        self::assertSame(422, $mismatch->getStatusCode());
        self::assertSame('ico_mismatch', $this->json($mismatch)['error']['code']);
        self::assertSame('', $this->scalar('SELECT dic FROM supplier WHERE id = ?', [$this->supplierId]));
    }

    public function testUploadListSurvivesReloadAndAllowsFreeingAFullQuota(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $response = $this->call('init', ['file_name' => 'synthetic-' . $i . '.zip', 'size' => 100]);
            self::assertSame(201, $response->getStatusCode());
            $this->tokens[] = $this->json($response)['token'];
        }
        self::assertGreaterThanOrEqual(400, $this->call('init', ['file_name' => 'fourth.zip', 'size' => 100])->getStatusCode());
        $listed = $this->json($this->call('index', []))['uploads'];
        self::assertCount(3, $listed);
        self::assertEqualsCanonicalizing($this->tokens, array_column($listed, 'token'));
        self::assertFalse($listed[0]['complete']);
        $wrongUser = $this->request([])->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId + 999999]);
        $response = (new ResponseFactory())->createResponse();
        self::assertSame([], $this->json($this->action->index($wrongUser, $response))['uploads']);
        $wrongTenant = $this->request([])->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId + 999999);
        self::assertSame([], $this->json($this->action->index($wrongTenant, (new ResponseFactory())->createResponse()))['uploads']);
        self::assertSame(200, $this->call('delete', [], $this->tokens[0])->getStatusCode());
        self::assertCount(2, $this->json($this->call('index', []))['uploads']);
        $replacement = $this->call('init', ['file_name' => 'replacement.zip', 'size' => 100]);
        self::assertSame(201, $replacement->getStatusCode());
        $this->tokens[] = $this->json($replacement)['token'];
    }

    private function backup(?array $tables = null): StereoNxBackup
    {
        $path = $this->tmp . '/backup.zip';
        SyntheticNx1Archive::write($path, $tables ?? SyntheticStereoNxTables::tables(), SyntheticStereoNxTables::identity(), self::PASSWORD);
        return StereoNxBackup::open($path, 0, self::PASSWORD);
    }

    private function snapshot(): array
    {
        $counts = [];
        foreach (['clients', 'invoices', 'purchase_invoices', 'bank_statements', 'cash_documents',
            'invoice_payments', 'payment_matches', 'de_movement_classification', 'stereo_nx_import_map', 'journal_entries'] as $table) {
            $counts[$table] = (int) $this->scalar("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?", [$this->supplierId]);
        }
        $counts['bank_transactions'] = (int) $this->scalar('SELECT COUNT(*) FROM bank_transactions bt JOIN bank_statements s ON s.id = bt.statement_id WHERE s.supplier_id = ?', [$this->supplierId]);
        return $counts;
    }

    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * Zkouška i převod běží jako job na pozadí: akce ho založí, test ho pustí synchronně
     * a výsledek přečte stejně jako průvodce. Heslo zálohy v řádku jobu nezůstane.
     */
    private function runJob(array $body, string $token): array
    {
        $started = $this->call('run', $body, $token);
        self::assertSame(202, $started->getStatusCode(), (string) $started->getBody());
        $jobId = (int) $this->json($started)['job_id'];
        $container = Bootstrap::buildContainer();
        $jobs = $container->get(ImportJobRepository::class);
        self::assertStringNotContainsString(self::PASSWORD, json_encode($jobs->find($jobId, $this->supplierId)['params']));
        $container->get(StereoNxImportJobService::class)->run($jobId);
        $job = $jobs->find($jobId, $this->supplierId);
        self::assertArrayNotHasKey('password_enc', $job['params']);
        self::assertSame('application/json', $job['result_mime']);
        self::assertSame('import-jobs/' . $this->supplierId . '/reports/stereo-nx-' . $jobId . '.json', $job['result_path']);
        $this->runReportPaths[] = $job['result_path'];
        $result = $this->action->result($this->request([]), (new ResponseFactory())->createResponse(), ['token' => $token, 'id' => (string) $jobId]);
        self::assertSame(200, $result->getStatusCode(), (string) $result->getBody());
        self::assertSame($this->json($result)['report'],
            $container->get(StereoNxRunReportStore::class)->read($this->supplierId, $jobId, $job['result_path']));
        return $this->json($result);
    }

    private function call(string $method, array $body, ?string $token = null): ResponseInterface
    {
        $request = $this->request($body);
        $response = (new ResponseFactory())->createResponse();
        return $token === null ? $this->action->{$method}($request, $response)
            : $this->action->{$method}($request, $response, ['token' => $token]);
    }

    private function chunk(string $token, int $offset, string $bytes): ResponseInterface
    {
        $path = $this->tmp . '/chunk_' . bin2hex(random_bytes(5));
        file_put_contents($path, $bytes);
        $request = $this->request(['offset' => $offset])->withUploadedFiles([
            'chunk' => new UploadedFile($path, 'chunk.bin', 'application/octet-stream', strlen($bytes)),
        ]);
        return $this->action->chunk($request, (new ResponseFactory())->createResponse(), ['token' => $token]);
    }

    private function request(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/imports/stereo-nx')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, ['utilities.import' => 2, 'settings.company.write' => 2]))
            ->withParsedBody($body);
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
