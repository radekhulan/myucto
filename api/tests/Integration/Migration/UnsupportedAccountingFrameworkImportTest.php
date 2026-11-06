<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountingFrameworkException;
use MyInvoice\Service\Accounting\AccountingFrameworkImportGuard;
use MyInvoice\Service\Sample\SampleDataService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Migration\MoneyS3\{ImportOptions, MoneyS3Importer, Ms3Backup};
use MyInvoice\Service\Migration\Pohoda\{PohodaExport, PohodaImporter};
use MyInvoice\Service\Migration\Premier\{PremierBackup, PremierImporter};
use MyInvoice\Service\Migration\StereoNx\{StereoNxAccountingImporter, StereoNxBackup};
use MyInvoice\Service\Sample\SampleDataGenerator;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use MyInvoice\Tests\Fixtures\StereoNx\{SyntheticNx1Archive, SyntheticStereoNxAccountingTables};
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class UnsupportedAccountingFrameworkImportTest extends TestCase
{
    private \Psr\Container\ContainerInterface $container;
    private Connection $db;
    private int $supplier = 0;
    private int $user;
    private string $tmp;
    private array $triggers = [];
    private ?int $snapshotIsolation = null;
    private ?Connection $otherDb = null;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->user = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $this->user);
        $this->tmp = sys_get_temp_dir() . '/unsupported-framework-' . bin2hex(random_bytes(8));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($this->otherDb !== null) $this->otherDb->close();
        if ($this->snapshotIsolation !== null) $pdo->exec('SET SESSION innodb_snapshot_isolation = ' . $this->snapshotIsolation);
        foreach ($this->triggers as $name) $pdo->exec('DROP TRIGGER IF EXISTS `' . $name . '`');
        if ($this->supplier > 0) {
            $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$this->supplier]);
            $pdo->prepare('UPDATE supplier SET default_currency_id = (SELECT id FROM currencies WHERE supplier_id <> ? ORDER BY id LIMIT 1) WHERE id = ?')->execute([$this->supplier, $this->supplier]);
            $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplier]);
        }
        if (isset($this->tmp) && is_dir($this->tmp)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($this->tmp);
        }
    }

    public static function unsupportedImports(): array
    {
        $cases = [];
        foreach (['money', 'pohoda', 'premier', 'stereo', 'sample'] as $kind) {
            foreach (['504', 'mixed'] as $history) $cases[] = [$kind, $history];
        }
        return $cases;
    }

    #[DataProvider('unsupportedImports')]
    public function testRejectsUnsupportedIntervalBeforeFirstWrite(string $kind, string $history): void
    {
        [$run, $from, $to] = $this->fixture($kind);
        $this->framework('1900-01-01', 'cz_500');
        $this->framework($history === '504' ? '1900-01-01' : $from, 'cz_504');
        if ($history === 'mixed') {
            $this->db->pdo()->prepare("UPDATE supplier_accounting_frameworks SET effective_from = DATE_ADD(?, INTERVAL 3 MONTH) WHERE supplier_id = ? AND accounting_framework = 'cz_504'")->execute([$from, $this->supplier]);
            $this->framework((new \DateTimeImmutable($from))->modify('+6 months')->format('Y-m-d'), 'cz_500');
        }
        $before = $this->snapshot();
        $this->watchFirstWrites();
        $result = $run();
        self::assertSame($before, $this->snapshot());
        self::assertStringContainsString('accounting_framework_import_unsupported', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public static function accountingImporters(): array
    {
        return [['money'], ['pohoda'], ['premier'], ['stereo']];
    }

    #[DataProvider('accountingImporters')]
    public function testCurrentRecheckRejectsChangedFrameworkAfterOldSnapshot(string $kind): void
    {
        [$run, $from] = $this->fixture($kind);
        $this->framework('1900-01-01', 'cz_500');
        $before = $this->snapshot();
        $this->watchFirstWrites();
        $pdo = $this->db->pdo();
        $this->snapshotIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $pdo->exec('SET SESSION innodb_snapshot_isolation = 0');
        $this->otherDb = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer()->get(Connection::class));
        $pdo->beginTransaction();
        self::assertSame('cz_500', $pdo->query('SELECT accounting_framework FROM supplier_accounting_frameworks WHERE supplier_id = ' . $this->supplier)->fetchColumn());
        $this->otherDb->pdo()->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework = 'cz_504' WHERE supplier_id = ?")->execute([$this->supplier]);
        $result = $run();
        self::assertSame($before, $this->snapshot());
        self::assertStringContainsString('accounting_framework_import_unsupported', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    #[DataProvider('accountingImporters')]
    public function testHistorical500ImportStillWritesWithCurrent504(string $kind): void
    {
        [$run] = $this->fixture($kind, true);
        $this->framework('1900-01-01', 'cz_500');
        $this->framework(date('Y') . '-01-01', 'cz_504');
        $this->db->pdo()->beginTransaction();
        $result = $run();
        self::assertStringNotContainsString('accounting_framework_import_unsupported', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertGreaterThan(0, $this->snapshot()['journal_entries'], json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $stmt = $this->db->pdo()->prepare('SELECT account_code FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$this->supplier]);
        $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $baseline = array_column(\MyInvoice\Service\Accounting\ChartOfAccountsTemplate::ACCOUNTS, 'code');
        self::assertCount(244, $baseline);
        self::assertSame([], array_values(array_diff($baseline, $codes)));
        self::assertContains('701', $codes);
        self::assertContains('411', $codes);
        foreach (['901', '911', '961'] as $unsupported) self::assertNotContains($unsupported, $codes);
    }

    public function testFiscalHistoricalImportSeedsAtVerifiedAugustBoundary(): void
    {
        $this->supplier(SyntheticAgenda::ICO);
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET fiscal_year_start_month = 8 WHERE id = ?')->execute([$this->supplier]);
        $this->framework('1900-01-01', 'cz_504');
        $this->framework('2024-08-01', 'cz_500');
        $this->framework('2026-08-01', 'cz_504');
        $path = $this->tmp . '/fiscal.lz';
        SyntheticAgenda::writeLzFiles($path, SyntheticAgenda::filesFiscalYearFromAugust());
        $source = Ms3Backup::extract($path, $this->tmp . '/fiscal');
        $pdo->beginTransaction();
        $result = $this->container->get(MoneyS3Importer::class)->run($this->supplier, $this->user, $source, new ImportOptions(ImportOptions::MODE_IMPORT, true))->toArray();
        self::assertGreaterThan(0, $this->snapshot()['journal_entries'], json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $stmt = $pdo->prepare('SELECT starts_on, ends_on FROM accounting_periods WHERE supplier_id = ? ORDER BY starts_on');
        $stmt->execute([$this->supplier]);
        self::assertSame([['starts_on' => '2024-08-01', 'ends_on' => '2025-07-31'], ['starts_on' => '2025-08-01', 'ends_on' => '2026-07-31']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $stmt = $pdo->prepare("SELECT account_code FROM chart_of_accounts WHERE supplier_id = ? AND account_code IN ('701', '411', '901', '961') ORDER BY account_code");
        $stmt->execute([$this->supplier]);
        self::assertSame(['411', '701'], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testDocumentOnlyStereoTransferRemainsAvailableWith504Metadata(): void
    {
        $fixture = \MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxTables::class;
        $this->supplier($fixture::identity()['ico']);
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplier]);
        $this->framework('1900-01-01', 'cz_504');
        $path = $this->tmp . '/documents.nx1';
        SyntheticNx1Archive::write($path, $fixture::tables(), $fixture::identity());
        $this->db->pdo()->beginTransaction();
        $report = $this->container->get(\MyInvoice\Service\Migration\StereoNx\StereoNxImporter::class)->run(StereoNxBackup::open($path, 0), $this->supplier, $this->user, false);
        self::assertTrue($report['ok'], json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertGreaterThan(0, $this->snapshot()['invoices']);
        self::assertSame(0, $this->snapshot()['journal_entries']);
    }

    public function testDemoRejectsExisting504BeforeMetadataWritesOrRefresh(): void
    {
        $this->supplier('12345678');
        $this->framework('1900-01-01', 'cz_504');
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET email = 'demo-po@tenant.example.invalid', company_name = 'Demo obchodní s.r.o.' WHERE id = ?")->execute([$this->supplier]);
        $demo = $this->demo();
        $name = 'unsupported_' . bin2hex(random_bytes(6));
        $pdo->exec("CREATE TRIGGER `$name` BEFORE INSERT ON users FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'unsupported_demo_first_write_observed'");
        $this->triggers[] = $name;
        $failure = null;
        try { $demo->provision(true); } catch (\Throwable $e) { $failure = $e; }
        self::assertInstanceOf(AccountingFrameworkException::class, $failure, $failure?->getMessage() ?? 'No exception');
        self::assertSame('accounting_framework_import_unsupported', $failure->errorCode);
    }

    public static function purgeHistories(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('purgeHistories')]
    public function testDemoPurgePreflightRejectsBeforeItsFirstDelete(bool $race): void
    {
        $this->supplier('12345678');
        $this->framework('1900-01-01', $race ? 'cz_500' : 'cz_504');
        $pdo = $this->db->pdo();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id) SELECT id, 'Synthetic preserved sample', 'Test 1', 'Test', '11000', country_id, 'sample@example.invalid', default_currency_id FROM supplier WHERE id = ?")->execute([$this->supplier]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO sample_data_entries (supplier_id, entity_type, entity_id) VALUES (?, 'client', ?)")->execute([$this->supplier, $id]);
        $this->container->get(ActivityLogger::class)->log('sample.test', $this->user, 'client', $id, [], supplierId: $this->supplier);
        $name = 'unsupported_' . bin2hex(random_bytes(6));
        $pdo->exec('CREATE TRIGGER `' . $name . '` BEFORE DELETE ON activity_log FOR EACH ROW BEGIN IF OLD.supplier_id = ' . $this->supplier . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'unsupported_first_delete_observed'; END IF; END");
        $this->triggers[] = $name;
        $guard = new AccountingFrameworkImportGuard($this->db);
        $periods = $guard->periodsForYears($this->supplier, [(int) date('Y')]);
        $before = $this->snapshot();
        if ($race) {
            $this->snapshotIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
            $pdo->exec('SET SESSION innodb_snapshot_isolation = 0');
            $this->otherDb = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer()->get(Connection::class));
        }
        $failure = null;
        try {
            $this->container->get(SampleDataService::class)->purge($this->supplier, function () use ($guard, $periods, $race, $pdo): void {
                if ($race) {
                    self::assertSame('cz_500', $pdo->query('SELECT accounting_framework FROM supplier_accounting_frameworks WHERE supplier_id = ' . $this->supplier)->fetchColumn());
                    (new \MyInvoice\Repository\AccountingPeriodRepository($this->otherDb))->create($this->supplier, 2020, '2020-01-01', '2020-12-31');
                    $this->otherDb->pdo()->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '2020-01-01', 'cz_504'), (?, '2021-01-01', 'cz_500')")->execute([$this->supplier, $this->supplier]);
                    $periods = (new \ReflectionMethod(\MyInvoice\Service\Demo\DemoProvisioner::class, 'frameworkPeriods'))->invoke($this->demo(), $this->supplier, true);
                }
                $guard->assertCz500Periods($this->supplier, $periods, true);
            });
        } catch (\Throwable $e) { $failure = $e; }
        self::assertFalse($pdo->inTransaction());
        if ($race) $before['accounting_periods']++;
        self::assertSame($before, $this->snapshot());
        self::assertInstanceOf(AccountingFrameworkException::class, $failure, $failure?->getMessage() ?? 'No exception');
        self::assertSame('accounting_framework_import_unsupported', $failure->errorCode);
    }

    private function demo(): \MyInvoice\Service\Demo\DemoProvisioner
    {
        $pdo = $this->db->pdo();
        $config = $this->container->get(\MyInvoice\Infrastructure\Config\Config::class);
        $demoConfig = new \MyInvoice\Infrastructure\Config\Config(array_replace_recursive($config->all(), [
            'demo' => ['enabled' => true, 'login_email' => 'synthetic-demo@tenant.example.invalid', 'login_password' => 'SyntheticDemo123!sample', 'expected_database' => $pdo->query('SELECT DATABASE()')->fetchColumn(), 'expected_host' => 'demo.example.invalid'],
            'app' => ['url' => 'https://demo.example.invalid'],
        ]));
        return new \MyInvoice\Service\Demo\DemoProvisioner($demoConfig, $this->db,
            $this->container->get(\MyInvoice\Repository\RoleRepository::class),
            $this->container->get(\MyInvoice\Repository\UserSupplierRepository::class),
            $this->container->get(\MyInvoice\Service\Auth\PasswordHasher::class),
            $this->container->get(\MyInvoice\Repository\AccountingModeRepository::class),
            $this->container->get(\MyInvoice\Repository\JournalEntryTemplateRepository::class),
            $this->container->get(SampleDataGenerator::class), $this->container->get(SampleDataService::class));
    }

    public function testFiscalSampleRejectsOldestAffectedFrameworkBeforeWrite(): void
    {
        [$run] = $this->fixture('sample');
        $first = (int) date('Y') - 2;
        $this->db->pdo()->prepare('UPDATE supplier SET fiscal_year_start_month = 8 WHERE id = ?')->execute([$this->supplier]);
        $this->framework('1900-01-01', 'cz_500');
        $this->framework(($first - 1) . '-08-01', 'cz_504');
        $this->framework($first . '-08-01', 'cz_500');
        $before = $this->snapshot();
        $this->watchFirstWrites();
        $result = $run();
        self::assertSame($before, $this->snapshot());
        self::assertStringContainsString('accounting_framework_import_unsupported', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function testAtomicGuardRejectsUnknownIntervalWithoutCallingWriter(): void
    {
        $this->supplier('12345678');
        $this->framework('1900-01-01', 'cz_500');
        $pdo = $this->db->pdo();
        $failure = null;
        try {
            (new AccountingFrameworkImportGuard($this->db))->writeCz500Periods($this->supplier, [],
                fn () => $pdo->prepare("UPDATE supplier SET city = 'Unexpected write' WHERE id = ?")->execute([$this->supplier]));
        } catch (AccountingFrameworkException $e) { $failure = $e; }
        self::assertNotNull($failure);
        self::assertSame('accounting_framework_import_unsupported', $failure->errorCode);
        self::assertSame('Test', $pdo->query('SELECT city FROM supplier WHERE id = ' . $this->supplier)->fetchColumn());
    }

    private function fixture(string $kind, bool $historical = false): array
    {
        $year = match ($kind) { 'money' => 2024, 'pohoda', 'stereo' => $historical ? 2024 : 2026, 'sample' => (int) date('Y') - 2, default => 2025 };
        $ico = match ($kind) { 'money' => SyntheticAgenda::ICO, 'pohoda' => SyntheticPohodaExport::ICO, 'premier' => SyntheticPremierBackup::ICO, 'stereo' => SyntheticStereoNxAccountingTables::identity()['ico'], default => '12345678' };
        $this->supplier($ico);
        if ($kind === 'money') {
            SyntheticAgenda::writeLz($this->tmp . '/agenda.lz');
            $source = Ms3Backup::extract($this->tmp . '/agenda.lz', $this->tmp . '/money');
            $run = fn () => $this->container->get(MoneyS3Importer::class)->run($this->supplier, $this->user, $source, new ImportOptions(ImportOptions::MODE_IMPORT, true))->toArray();
        } elseif ($kind === 'pohoda') {
            $dir = SyntheticPohodaExport::write($this->tmp . '/pohoda');
            if ($historical) {
                $target = str_replace('_2026', '_2024', $dir);
                rename($dir, $target);
                $dir = $target;
                foreach (glob($dir . '/*') as $file) {
                    if (is_file($file)) file_put_contents($file, strtr(file_get_contents($file), ['2027' => '2025', '2026' => '2024', '2025' => '2023']));
                }
            }
            $source = PohodaExport::open($dir);
            $run = fn () => $this->container->get(PohodaImporter::class)->run($this->supplier, $this->user, $source, false)->toArray();
        } elseif ($kind === 'premier') {
            $source = PremierBackup::open(SyntheticPremierBackup::writeDir($this->tmp . '/premier'));
            $run = fn () => $this->container->get(PremierImporter::class)->run($this->supplier, $this->user, $source, $year, false)->toArray();
        } elseif ($kind === 'stereo') {
            $path = $this->tmp . '/stereo.nx1';
            $tables = SyntheticStereoNxAccountingTables::tables();
            if ($historical) {
                foreach ($tables as &$rows) foreach ($rows as &$row) foreach ($row as &$value) {
                    if (is_string($value)) $value = str_replace('2026-', '2024-', $value);
                }
                unset($rows, $row, $value);
            }
            SyntheticNx1Archive::write($path, $tables, SyntheticStereoNxAccountingTables::identity());
            $source = StereoNxBackup::open($path, 0);
            $run = fn () => $this->container->get(StereoNxAccountingImporter::class)->run($source, $this->supplier, $this->user, false);
        } else {
            $run = fn () => $this->container->get(SampleDataGenerator::class)->generate($this->supplier, $this->user);
        }
        return [static function () use ($run): array {
            try { return $run(); } catch (\Throwable $e) { return ['exception' => $e::class, 'code' => $e->errorCode ?? '', 'message' => $e->getMessage()]; }
        }, $year . '-01-01', $year . '-12-31'];
    }

    private function supplier(string $ico): void
    {
        $pdo = $this->db->pdo();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $currency = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT MIN(id) FROM vat_rates')->fetchColumn();
        $pdo->prepare("INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, taxpayer_type, default_currency_id, default_vat_rate_id, accounting_mode) VALUES ('Synthetic unsupported framework', 'Test 1', 'Test', '11000', ?, ?, ?, ?, 1, 'po', ?, ?, 'double_entry')")
            ->execute([$country, uniqid('unsupported-') . '@example.invalid', $ico, 'CZ' . $ico, $currency, $vat]);
        $this->supplier = (int) $pdo->lastInsertId();
        foreach (['CZK', 'EUR'] as $code) {
            $pdo->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default) VALUES (?, ?, ?, ?, ?, ?, 2, 1, ?)')
                ->execute([$this->supplier, $code, $code, $code, $code, $code, $code === 'CZK' ? 1 : 0]);
            if ($code === 'CZK') $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $this->supplier]);
        }
    }

    private function framework(string $date, string $framework): void
    {
        $this->db->pdo()->prepare('INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE accounting_framework = VALUES(accounting_framework)')->execute([$this->supplier, $date, $framework]);
    }

    private function snapshot(): array
    {
        $out = [];
        foreach (['clients', 'chart_of_accounts', 'journal_entries', 'accounting_periods', 'auto_posting_policy', 'invoices', 'purchase_invoices', 'sample_data_entries', 'activity_log'] as $table) {
            $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE supplier_id = ?');
            $stmt->execute([$this->supplier]);
            $out[$table] = (int) $stmt->fetchColumn();
        }
        return $out;
    }

    private function watchFirstWrites(): void
    {
        foreach (['clients', 'auto_posting_policy', 'chart_of_accounts'] as $table) {
            $name = 'unsupported_' . bin2hex(random_bytes(6));
            $this->db->pdo()->exec('CREATE TRIGGER `' . $name . '` BEFORE INSERT ON `' . $table . '` FOR EACH ROW BEGIN IF NEW.supplier_id = ' . $this->supplier . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'unsupported_first_write_observed'; END IF; END");
            $this->triggers[] = $name;
        }
    }
}
