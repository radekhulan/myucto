<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ClientAiExtractionMigrationRuntimeTest extends TestCase
{
    private ?PDO $server = null;
    private string $database;
    private string $root;
    private Config $config;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 4);
        $this->config = Config::load($this->root);
        $this->database = 'myucto_client_ai_migration_' . bin2hex(random_bytes(6)) . '_test';
        try {
            $this->server = $this->pdo(false);
            $this->server->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            $this->markTestSkipped('Nelze vytvořit izolovanou DB: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $this->server?->exec("DROP DATABASE IF EXISTS `{$this->database}`");
    }

    public function testOldSchemaUpgradeAndRepeatedMigrationPreserveOptInAndRoleOverrides(): void
    {
        $db = $this->pdo(true);
        // Minimální původní schéma a syntetické role; migrační SQL spouští pouze migrate.php.
        $db->exec('CREATE TABLE supplier (id INT UNSIGNED PRIMARY KEY, ai_eu_residency_required TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB');
        $db->exec('CREATE TABLE roles (id INT PRIMARY KEY, system_key VARCHAR(32) NULL) ENGINE=InnoDB');
        $db->exec('CREATE TABLE role_permissions (role_id INT NOT NULL, permission_key VARCHAR(100) NOT NULL, access_level TINYINT NOT NULL, PRIMARY KEY (role_id, permission_key)) ENGINE=InnoDB');
        $db->exec('INSERT INTO supplier (id, ai_eu_residency_required) VALUES (1, 1)');
        $db->exec("INSERT INTO roles (id, system_key) VALUES (1, NULL), (2, NULL), (3, 'client'), (4, NULL), (5, NULL)");
        $db->exec("INSERT INTO role_permissions VALUES (1, 'purchase_invoices.scan', 2), (2, 'purchase_invoices.scan', 2), (2, 'purchase_invoices.extract', 1), (4, 'purchase_invoices.scan', 0), (5, 'purchase_invoices.scan', 1)");
        $this->migrate();
        self::assertSame(0, (int) $db->query('SELECT client_ai_extraction_enabled FROM supplier WHERE id = 1')->fetchColumn());
        self::assertSame(100, (int) $db->query('SELECT client_ai_extraction_monthly_limit FROM supplier WHERE id = 1')->fetchColumn());
        self::assertSame(1, (int) $db->query('SELECT ai_eu_residency_required FROM supplier WHERE id = 1')->fetchColumn());
        self::assertSame([2, 1, 2, 0, 1], array_map('intval', $db->query("SELECT access_level FROM role_permissions WHERE permission_key = 'purchase_invoices.extract' ORDER BY role_id")->fetchAll(PDO::FETCH_COLUMN)));
        $db->exec('UPDATE supplier SET client_ai_extraction_enabled = 1, client_ai_extraction_monthly_limit = 7 WHERE id = 1');
        $db->exec("INSERT INTO client_ai_extraction_usage (supplier_id, period, used) VALUES (1, '2026-10', 3)");
        $db->exec("DELETE FROM migrations WHERE filename = '2014_client_ai_extraction.sql'");
        $this->migrate();
        self::assertSame(1, (int) $db->query('SELECT client_ai_extraction_enabled FROM supplier WHERE id = 1')->fetchColumn());
        self::assertSame(7, (int) $db->query('SELECT client_ai_extraction_monthly_limit FROM supplier WHERE id = 1')->fetchColumn());
        self::assertSame(3, (int) $db->query("SELECT used FROM client_ai_extraction_usage WHERE supplier_id = 1 AND period = '2026-10'")->fetchColumn());
        self::assertSame([2, 1, 2, 0, 1], array_map('intval', $db->query("SELECT access_level FROM role_permissions WHERE permission_key = 'purchase_invoices.extract' ORDER BY role_id")->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function pdo(bool $database): PDO
    {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;%scharset=utf8mb4',
                $this->config->get('db.host'), (int) $this->config->get('db.port', 3306),
                $database ? "dbname={$this->database};" : ''),
            (string) $this->config->get('db.user'), (string) $this->config->get('db.pass'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }

    private function migrate(): void
    {
        $env = getenv();
        $env['MYINVOICE_DB_NAME'] = $this->database;
        $env['MYSQL_DATABASE'] = $this->database;
        $process = proc_open([
            PHP_BINARY, $this->root . '/api/bin/migrate.php', '--no-backfills', '--no-analyze',
            '--only=2014_client_ai_extraction.sql',
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $env, ['bypass_shell' => true]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output);
    }
}
