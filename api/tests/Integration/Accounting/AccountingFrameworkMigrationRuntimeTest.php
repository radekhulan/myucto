<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[Group('integration')]
final class AccountingFrameworkMigrationRuntimeTest extends TestCase
{
    private PDO $db;
    private bool $databaseCreated = false;
    private string $database;
    private string $rootDir;
    private Config $config;
    private const MIGRATION = '2078_supplier_accounting_frameworks.sql';

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        $this->config = Config::load($this->rootDir);
        $host = strtolower((string) $this->config->get('db.host', '127.0.0.1'));
        $configuredDatabase = (string) $this->config->get('db.name', '');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+_test$/D', $configuredDatabase, 'Fixture vyžaduje konfiguraci testovací DB.');
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        self::assertTrue($local || getenv('MYINVOICE_ALLOW_REMOTE_TEST_DB') === '1', 'Mimo localhost nastavte MYINVOICE_ALLOW_REMOTE_TEST_DB=1 pouze pro testovací DB server.');
        $this->database = 'myucto_framework_' . bin2hex(random_bytes(6)) . '_test';
        $this->db = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, (int) $this->config->get('db.port', 3306)),
            (string) $this->config->get('db.user'),
            (string) $this->config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->db->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->databaseCreated = true;
        $this->db->exec("USE `{$this->database}`");
        $this->db->exec('SET SESSION sql_mode = \'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION\'');
    }

    protected function tearDown(): void
    {
        if ($this->databaseCreated && isset($this->db, $this->database) && preg_match('/^myucto_framework_[0-9a-f]{12}_test$/D', $this->database) === 1) {
            $this->db->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        }
    }

    public function testMigrationBackfillsExistingSuppliersAndPreservesHistoryOnReapplication(): void
    {
        $this->db->exec('CREATE TABLE supplier (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
        $this->db->exec('INSERT INTO supplier (id) VALUES (11), (22)');
        $this->runMigration();
        self::assertSame([
            ['supplier_id' => 11, 'effective_from' => '1900-01-01', 'accounting_framework' => 'cz_500'],
            ['supplier_id' => 22, 'effective_from' => '1900-01-01', 'accounting_framework' => 'cz_500'],
        ], $this->history());
        $this->db->exec("UPDATE supplier_accounting_frameworks SET accounting_framework = 'cz_504', created_by = 42 WHERE supplier_id = 22");
        $this->db->exec("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (11, '2098-01-01', 'cz_504')");
        $before = $this->db->query('SELECT * FROM supplier_accounting_frameworks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->db->prepare('DELETE FROM migrations WHERE filename = ?')->execute([self::MIGRATION]);
        $this->runMigration();
        self::assertSame($before, $this->db->query('SELECT * FROM supplier_accounting_frameworks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
    }

    public function testCiSeedInitializesNewSuppliersAfterFrameworkMigration(): void
    {
        $this->createSeedSchema();
        $this->runMigration();
        self::assertSame([], $this->history());
        $this->runScript('api/bin/ci-seed.php');
        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM supplier')->fetchColumn());
        $rows = $this->history();
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame('1900-01-01', $row['effective_from']);
            self::assertSame('cz_500', $row['accounting_framework']);
        }
    }

    public function testCiSeedAlsoWorksBeforeFrameworkTableExists(): void
    {
        $this->createSeedSchema();
        $this->runScript('api/bin/ci-seed.php');
        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM supplier')->fetchColumn());
        self::assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'supplier_accounting_frameworks'")->fetchColumn());
    }

    private function history(): array
    {
        return $this->db->query('SELECT supplier_id, effective_from, accounting_framework FROM supplier_accounting_frameworks ORDER BY supplier_id, effective_from')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function runMigration(): void
    {
        $this->runScript('api/bin/migrate.php', ['--only=' . self::MIGRATION, '--no-backfills']);
    }

    private function runScript(string $script, array $arguments = []): void
    {
        $environment = getenv();
        $environment['MYINVOICE_DB_NAME'] = $this->database;
        $environment['MYSQL_DATABASE'] = $this->database;
        $process = new Process([PHP_BINARY, $this->rootDir . '/' . $script, ...$arguments], $this->rootDir, $environment, null, 60);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    private function createSeedSchema(): void
    {
        $this->db->exec("CREATE TABLE supplier (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_name VARCHAR(190), street VARCHAR(190), city VARCHAR(100), zip VARCHAR(20),
            country_id INT, email VARCHAR(190), is_vat_payer TINYINT, default_currency_id INT, default_vat_rate_id INT,
            default_payment_due_days INT, default_payment_due_unit VARCHAR(10), default_hourly_rate DECIMAL(12,2)
        ) ENGINE=InnoDB;
        CREATE TABLE countries (id INT PRIMARY KEY, iso2 CHAR(2));
        INSERT INTO countries VALUES (1, 'CZ');
        CREATE TABLE vat_rates (id INT PRIMARY KEY);
        INSERT INTO vat_rates VALUES (1);
        CREATE TABLE roles (id INT PRIMARY KEY, system_key VARCHAR(40));
        INSERT INTO roles VALUES (1, 'superadmin');
        CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190), password_hash VARCHAR(255), name VARCHAR(190), role VARCHAR(40), role_id INT, locale VARCHAR(10));
        CREATE TABLE currencies (id INT AUTO_INCREMENT PRIMARY KEY, supplier_id INT, code CHAR(3), label VARCHAR(40), symbol VARCHAR(10), name_cs VARCHAR(100), name_en VARCHAR(100), decimals INT, is_active TINYINT, is_default TINYINT, account_number VARCHAR(40), bank_code VARCHAR(10));
        CREATE TABLE supplier_vat_status_history (supplier_id INT, effective_from DATE, is_vat_payer TINYINT, is_identified TINYINT, annual_deduction_percent DECIMAL(5,2));
        CREATE TABLE bank_rule_template_defaults (template_key VARCHAR(50), name_cs VARCHAR(190), name_en VARCHAR(190), direction VARCHAR(20), operation_type VARCHAR(40), counterparty_bank VARCHAR(40), counterparty_prefix VARCHAR(40), vs_placeholder VARCHAR(40), message_contains VARCHAR(190), rule_key VARCHAR(50), default_priority INT, sort_order INT, is_active TINYINT);
        CREATE TABLE bank_rule_templates LIKE bank_rule_template_defaults;
        ALTER TABLE bank_rule_templates ADD COLUMN supplier_id INT;");
    }
}
