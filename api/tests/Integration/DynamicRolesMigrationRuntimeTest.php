<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Security\PermissionCatalog;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class DynamicRolesMigrationRuntimeTest extends TestCase
{
    private ?PDO $server = null;
    private string $database = '';
    private string $rootDir = '';
    private Config $config;
    private int $supplierA = 0;
    private int $supplierB = 0;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 3);
        if (!is_file($this->rootDir . '/cfg.php')) $this->markTestSkipped('cfg.php missing');
        $this->config = Config::load($this->rootDir);
        $this->database = 'myucto_rbac_' . bin2hex(random_bytes(6));

        try {
            $this->server = new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;charset=utf8mb4',
                    (string) $this->config->get('db.host', '127.0.0.1'),
                    (int) $this->config->get('db.port', 3306),
                ),
                (string) $this->config->get('db.user'),
                (string) $this->config->get('db.pass', ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $this->server->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            $this->markTestSkipped('Nelze vytvořit izolovanou DB: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->database !== '') {
            $this->server->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        }
    }

    public function testLegacyBackfillAndIdempotentRerun(): void
    {
        $this->runMigrator('--until=1073_purchase_invoice_vat_allocations.sql');
        $db = $this->databasePdo();
        $this->seedLegacyScenarios($db);

        $this->runMigrator();

        $roles = $db->query('SELECT system_key, id FROM roles ORDER BY system_key')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(['accountant', 'admin', 'admin_plus', 'client', 'readonly', 'superadmin'], array_keys($roles));

        $staffPermissions = array_keys(array_filter(
            (new PermissionCatalog())->all(),
            static fn (array $definition): bool => in_array('staff', $definition['role_types'], true),
        ));
        sort($staffPermissions);
        foreach (['admin', 'admin_plus'] as $systemKey) {
            $stmt = $db->prepare(
                'SELECT permission_key FROM role_permissions WHERE role_id = ? AND access_level = 2 ORDER BY permission_key'
            );
            $stmt->execute([(int) $roles[$systemKey]]);
            self::assertSame($staffPermissions, $stmt->fetchAll(PDO::FETCH_COLUMN), $systemKey);
        }

        $templateDefaults = (int) $db->query('SELECT COUNT(*) FROM bank_rule_template_defaults')->fetchColumn();
        self::assertGreaterThan(0, $templateDefaults);
        foreach ([$this->supplierA, $this->supplierB] as $supplierId) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM bank_rule_templates WHERE supplier_id = ?');
            $stmt->execute([$supplierId]);
            self::assertSame($templateDefaults, (int) $stmt->fetchColumn());
        }
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM bank_rule_templates WHERE supplier_id IS NULL')->fetchColumn());

        $users = $db->query('SELECT email, role_id FROM users ORDER BY email')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame((int) $roles['superadmin'], (int) $users['admin@example.test']);
        self::assertSame((int) $roles['accountant'], (int) $users['limited@example.test']);
        self::assertSame((int) $roles['accountant'], (int) $users['unlimited-accountant@example.test']);
        self::assertSame((int) $roles['readonly'], (int) $users['unlimited-readonly@example.test']);
        self::assertSame((int) $roles['client'], (int) $users['client@example.test']);

        $ids = $db->query('SELECT email, id FROM users')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(0, $this->membershipCount($db, (int) $ids['admin@example.test']));
        self::assertSame(0, $this->membershipCount($db, (int) $ids['client@example.test']));
        self::assertSame(2, $this->membershipCount($db, (int) $ids['unlimited-accountant@example.test']));
        self::assertSame(2, $this->membershipCount($db, (int) $ids['unlimited-readonly@example.test']));

        $limited = $db->query(
            'SELECT supplier_id, role_id FROM user_suppliers WHERE user_id = ' . (int) $ids['limited@example.test']
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $limited, 'Již omezený uživatel nesmí dostat další firmy.');
        self::assertSame($this->supplierA, (int) $limited[0]['supplier_id']);
        self::assertSame((int) $roles['readonly'], (int) $limited[0]['role_id']);

        $db->exec("UPDATE roles SET name = 'Upravená účetní' WHERE system_key = 'accountant'");
        $permissionCount = (int) $db->query(
            'SELECT COUNT(*) FROM role_permissions WHERE role_id = ' . (int) $roles['accountant']
        )->fetchColumn();
        $db->exec(
            "DELETE FROM migrations WHERE filename IN (
                '1074_dynamic_roles_permissions.sql',
                '1747_predefined_admin_roles.sql',
                '1748_bank_rule_templates_tenant_scope.sql'
            )"
        );
        $this->runMigrator();

        self::assertSame('Upravená účetní', $db->query(
            "SELECT name FROM roles WHERE system_key = 'accountant'"
        )->fetchColumn(), 'Opakovaný běh nesmí přepsat administrátorskou úpravu role.');
        self::assertSame($permissionCount, (int) $db->query(
            'SELECT COUNT(*) FROM role_permissions WHERE role_id = ' . (int) $roles['accountant']
        )->fetchColumn());
        self::assertSame(6, (int) $db->query('SELECT COUNT(*) FROM roles')->fetchColumn());
    }

    private function seedLegacyScenarios(PDO $db): void
    {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        $countryId = (int) $db->query('SELECT MIN(id) FROM countries')->fetchColumn();
        $vatRateId = (int) $db->query('SELECT MIN(id) FROM vat_rates')->fetchColumn();
        self::assertGreaterThan(0, $countryId);
        self::assertGreaterThan(0, $vatRateId);
        $currencyId = (int) $db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM currencies')->fetchColumn();
        $this->supplierA = (int) $db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM supplier')->fetchColumn();
        $this->supplierB = $this->supplierA + 1;
        $db->exec("INSERT INTO currencies (id, supplier_id, code, label, symbol, name_cs, name_en) VALUES ({$currencyId}, {$this->supplierA}, 'CZK', 'CZK', 'Kč', 'Koruna', 'Crown')");
        $db->exec("INSERT INTO supplier (id, company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id) VALUES
            ({$this->supplierA}, 'Demo A', 'Testovací 1', 'Praha', '11000', {$countryId}, 'a@example.test', {$currencyId}, {$vatRateId}),
            ({$this->supplierB}, 'Demo B', 'Testovací 2', 'Brno', '60200', {$countryId}, 'b@example.test', {$currencyId}, {$vatRateId})");
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');

        $hash = '$2y$12$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234';
        $stmt = $db->prepare('INSERT INTO users (email, password_hash, name, role, locale, is_active) VALUES (?, ?, ?, ?, ?, 1)');
        foreach ([
            ['admin@example.test', 'Admin', 'admin'],
            ['unlimited-accountant@example.test', 'Unlimited accountant', 'accountant'],
            ['unlimited-readonly@example.test', 'Unlimited readonly', 'readonly'],
            ['limited@example.test', 'Limited', 'accountant'],
            ['client@example.test', 'Client', 'client'],
        ] as [$email, $name, $role]) {
            $stmt->execute([$email, $hash, $name, $role, 'cs']);
        }

        $limitedId = (int) $db->query("SELECT id FROM users WHERE email = 'limited@example.test'")->fetchColumn();
        $db->prepare("INSERT INTO user_suppliers (user_id, supplier_id, role) VALUES (?, ?, 'readonly')")
            ->execute([$limitedId, $this->supplierA]);
    }

    private function membershipCount(PDO $db, int $userId): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM user_suppliers WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    private function databasePdo(): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) $this->config->get('db.host', '127.0.0.1'),
                (int) $this->config->get('db.port', 3306),
                $this->database,
            ),
            (string) $this->config->get('db.user'),
            (string) $this->config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    private function runMigrator(?string $extraArgument = null): void
    {
        $command = [PHP_BINARY, $this->rootDir . '/api/bin/migrate.php', '--no-backfills'];
        if ($extraArgument !== null) $command[] = $extraArgument;
        $env = getenv();
        self::assertIsArray($env);
        $env['MYINVOICE_DB_NAME'] = $this->database;
        $env['MYSQL_DATABASE'] = $this->database;
        $pipes = [];
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->rootDir,
            $env,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        self::assertSame(0, $exitCode, "Migrátor selhal.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
    }
}
