<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Migrátor se zkouší nad prázdným starým schématem i nad již změněnými hodnotami. */
#[Group('integration')]
final class InstanceAdminMigrationTest extends TestCase
{
    private const MIGRATION = '2190_instance_admin.sql';
    private PDO $pdo;
    private string $database;
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
        $config = Config::load($this->root);
        $this->database = 'myucto_224_schema_' . bin2hex(random_bytes(6)) . '_test';
        $this->pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4',
            (string) $config->get('db.host'), (int) $config->get('db.port', 3306)),
            (string) $config->get('db.user'), (string) $config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->pdo->exec("USE `{$this->database}`");
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $this->pdo->exec("CREATE TABLE roles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, system_key VARCHAR(32) UNIQUE NULL,
            name VARCHAR(120) NOT NULL, role_type ENUM('superadmin','staff','client') NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;
        CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY, role_id INT UNSIGNED NULL) ENGINE=InnoDB;
        CREATE TABLE supplier (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;
        CREATE TABLE app_meta (k VARCHAR(64) PRIMARY KEY, v MEDIUMTEXT NOT NULL) ENGINE=InnoDB");
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo, $this->database) && preg_match('/^myucto_224_schema_[0-9a-f]{12}_test$/D', $this->database)) {
            $this->pdo->exec("DROP DATABASE `{$this->database}`");
        }
    }

    public function testEmptyOldSchemaMigratesAndReplayPreservesRoleScopeAndSmtpSecrets(): void
    {
        $this->runMigrator();
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM roles WHERE system_key = 'instance_admin' AND role_type = 'instance_admin'")->fetchColumn());
        foreach (['user_technical_access', 'user_technical_suppliers', 'instance_smtp_targets',
            'instance_smtp_settings', 'instance_smtp_test_attempts', 'instance_admin_events'] as $table) {
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
        }
        $role = (int) $this->pdo->query("SELECT id FROM roles WHERE system_key = 'instance_admin'")->fetchColumn();
        $this->pdo->exec("UPDATE roles SET is_active = 0, name = 'Synthetic preserved role' WHERE id = {$role}");
        $this->pdo->exec("INSERT INTO users (id, role_id) VALUES (1, {$role}), (2, {$role});
            INSERT INTO supplier (id) VALUES (1);
            INSERT INTO user_technical_access (user_id, all_suppliers) VALUES (1, 0), (2, 1);
            INSERT INTO user_technical_suppliers (user_id, supplier_id) VALUES (1, 1);
            INSERT INTO instance_smtp_targets (id, name, host, port, encryption, username, from_email, test_recipient)
                VALUES (1, 'Synthetic target', 'smtp.example.invalid', 587, 'tls', 'synthetic', 'from@example.invalid', 'test@example.invalid');
            INSERT INTO instance_smtp_settings (id, target_id, password_ciphertext, test_attempted_at)
                VALUES (1, 1, 'enc:v2:synthetic-preserved', '2026-10-23 12:00:00')");
        $expectedRole = $this->pdo->query('SELECT id, name, role_type, is_active FROM roles')->fetchAll(PDO::FETCH_ASSOC);
        $expectedScope = $this->pdo->query('SELECT * FROM user_technical_suppliers')->fetchAll(PDO::FETCH_ASSOC);
        $expectedSettings = $this->pdo->query('SELECT * FROM instance_smtp_settings')->fetchAll(PDO::FETCH_ASSOC);
        // Evidence je podle názvu; záměrně znovu necháme migraci provést oficiálním migrátorem.
        $this->pdo->prepare('DELETE FROM migrations WHERE filename = ?')->execute([self::MIGRATION]);
        $this->runMigrator();
        self::assertSame($expectedRole, $this->pdo->query('SELECT id, name, role_type, is_active FROM roles')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($expectedScope, $this->pdo->query('SELECT * FROM user_technical_suppliers')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($expectedSettings, $this->pdo->query('SELECT * FROM instance_smtp_settings')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(0, (int) $this->pdo->query('SELECT all_suppliers FROM user_technical_access WHERE user_id = 1')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT all_suppliers FROM user_technical_access WHERE user_id = 2')->fetchColumn());
        $this->runMigrator();
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM migrations WHERE filename = '2190_instance_admin.sql'")->fetchColumn());
    }

    private function runMigrator(): void
    {
        $env = getenv();
        self::assertIsArray($env);
        $env['MYINVOICE_DB_NAME'] = $this->database;
        $env['MYSQL_DATABASE'] = $this->database;
        $env['MYINVOICE_SCHEMA_CACHE'] = '0';
        $pipes = [];
        $process = proc_open([PHP_BINARY, $this->root . '/api/bin/migrate.php', '--no-backfills', '--no-analyze', '--only=' . self::MIGRATION],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $env, ['bypass_shell' => true]);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), "Migrátor selhal.\n{$stdout}\n{$stderr}");
    }
}
