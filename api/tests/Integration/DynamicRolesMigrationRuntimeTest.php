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
        $db = $this->databasePdo();
        $this->createLegacyParentTables($db);
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
        $propertyPermissions = $db->query("SELECT r.system_key,rp.permission_key,rp.access_level FROM roles r JOIN role_permissions rp ON rp.role_id=r.id WHERE rp.permission_key LIKE 'property%' ORDER BY r.system_key,rp.permission_key")->fetchAll(PDO::FETCH_ASSOC);
        $expectedProperty = [];
        foreach (['accountant', 'admin', 'admin_plus', 'readonly'] as $systemKey) {
            $preset = in_array($systemKey, ['admin', 'admin_plus'], true)
                ? array_fill_keys($staffPermissions, 2)
                : (new PermissionCatalog())->legacyPreset($systemKey);
            foreach ($preset as $key => $level) {
                if (str_starts_with($key, 'property')) $expectedProperty[$systemKey . ':' . $key] = $level;
            }
        }
        $actualProperty = [];
        foreach ($propertyPermissions as $permission) $actualProperty[$permission['system_key'] . ':' . $permission['permission_key']] = (int) $permission['access_level'];
        ksort($expectedProperty);
        self::assertSame($expectedProperty, $actualProperty);

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

        self::assertSame(0, (int) $db->query('SELECT client_ai_extraction_enabled FROM supplier WHERE id = ' . $this->supplierA)->fetchColumn());
        $db->exec('UPDATE supplier SET client_ai_extraction_enabled = 1 WHERE id = ' . $this->supplierA);
        $db->exec("UPDATE roles SET name = 'Upravená účetní' WHERE system_key = 'accountant'");
        $permissionCount = (int) $db->query(
            'SELECT COUNT(*) FROM role_permissions WHERE role_id = ' . (int) $roles['accountant']
        )->fetchColumn();
        $db->prepare("UPDATE role_permissions SET access_level=0 WHERE role_id=? AND permission_key='property.registry.confirm'")->execute([(int) $roles['admin']]);
        $db->prepare("UPDATE role_permissions SET access_level=0 WHERE role_id=? AND permission_key='property.charges.confirm'")->execute([(int) $roles['admin']]);
        $db->prepare("UPDATE role_permissions SET access_level=0 WHERE role_id=? AND permission_key='property.charges.reverse'")->execute([(int) $roles['admin']]);
        $db->prepare("UPDATE role_permissions SET access_level=0 WHERE role_id=? AND permission_key='property.funds.post'")->execute([(int) $roles['admin']]);
        $db->prepare("UPDATE role_permissions SET access_level=0 WHERE role_id=? AND permission_key='property.funds.reverse'")->execute([(int) $roles['admin']]);
        $db->exec(
            "DELETE FROM migrations WHERE filename IN (
                '1074_dynamic_roles_permissions.sql',
                '1747_predefined_admin_roles.sql',
                '1748_bank_rule_templates_tenant_scope.sql',
                '2014_client_ai_extraction.sql',
                '2084_property_registry.sql',
                '2085_property_charge_versions.sql',
                '2086_property_charge_issuance.sql',
                '2090_property_payment_permissions.sql',
                '2095_property_opening.sql',
                '2109_property_funds_role_defaults.sql'
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
        self::assertSame(1, (int) $db->query('SELECT client_ai_extraction_enabled FROM supplier WHERE id = ' . $this->supplierA)->fetchColumn());
        self::assertSame(0, (int) $db->query("SELECT access_level FROM role_permissions WHERE role_id=" . (int) $roles['admin'] . " AND permission_key='property.registry.confirm'")->fetchColumn());
        self::assertSame(0, (int) $db->query("SELECT access_level FROM role_permissions WHERE role_id=" . (int) $roles['admin'] . " AND permission_key='property.charges.confirm'")->fetchColumn());
        self::assertSame(0, (int) $db->query("SELECT access_level FROM role_permissions WHERE role_id=" . (int) $roles['admin'] . " AND permission_key='property.charges.reverse'")->fetchColumn());
        self::assertSame(1, (int) $db->query("SELECT COUNT(*) FROM role_permissions WHERE role_id=" . (int) $roles['admin'] . " AND permission_key='property.funds.post'")->fetchColumn());
        self::assertSame(0, (int) $db->query("SELECT access_level FROM role_permissions WHERE role_id=" . (int) $roles['admin'] . " AND permission_key='property.funds.post'")->fetchColumn());
        self::assertSame(0, (int) $db->query("SELECT access_level FROM role_permissions WHERE role_id=" . (int) $roles['admin'] . " AND permission_key='property.funds.reverse'")->fetchColumn());

        $db->prepare("DELETE FROM role_permissions WHERE role_id=? AND permission_key='property.funds.post'")->execute([(int) $roles['admin']]);
        $db->exec("DELETE FROM migrations WHERE filename='2109_property_funds_role_defaults.sql'");
        $this->runMigrator();

        self::assertSame(0, (int) $db->query("SELECT COUNT(*) FROM role_permissions WHERE role_id=" . (int) $roles['admin'] . " AND permission_key='property.funds.post'")->fetchColumn());
        self::assertSame(0, (int) $db->query("SELECT access_level FROM role_permissions WHERE role_id=" . (int) $roles['admin'] . " AND permission_key='property.funds.reverse'")->fetchColumn());

        $settlements = "SELECT COUNT(*) FROM role_permissions WHERE role_id=" . (int) $roles['accountant'] . " AND permission_key='property.settlements.manage'";
        self::assertSame(1, (int) $db->query($settlements)->fetchColumn());
        $db->prepare("DELETE FROM role_permissions WHERE role_id=? AND permission_key='property.settlements.manage'")->execute([(int) $roles['accountant']]);
        $db->exec("DELETE FROM migrations WHERE filename='2097_property_service_settlement_permissions.sql'");
        $this->runMigrator();
        self::assertSame(0, (int) $db->query($settlements)->fetchColumn(), 'Opakovaný běh 2046 nesmí vrátit odebrané právo.');
        self::assertSame(1, (int) $db->query("SELECT COUNT(*) FROM role_permissions WHERE role_id=" . (int) $roles['accountant'] . " AND permission_key='property.settlements.post'")->fetchColumn());
    }

    public function testTaxSubmissionManagementDefaultsPreserveExplicitAndRemovedGrants(): void
    {
        $db = $this->databasePdo();
        $db->exec('SET SESSION sql_mode = \'STRICT_ALL_TABLES\'');
        $db->exec('CREATE TABLE roles (id INT UNSIGNED PRIMARY KEY, system_key VARCHAR(32) NULL, role_type VARCHAR(20) NOT NULL) ENGINE=InnoDB');
        $db->exec('CREATE TABLE role_permissions (role_id INT UNSIGNED NOT NULL, permission_key VARCHAR(120) NOT NULL, access_level TINYINT UNSIGNED NOT NULL, PRIMARY KEY (role_id, permission_key)) ENGINE=InnoDB');
        $db->exec('CREATE TABLE app_meta (k VARCHAR(64) PRIMARY KEY, v MEDIUMTEXT NOT NULL) ENGINE=InnoDB');
        $db->exec("INSERT INTO roles VALUES (1, 'admin', 'staff'), (2, 'admin_plus', 'staff'), (3, 'accountant', 'staff'), (4, 'readonly', 'staff'), (5, 'client', 'client'), (6, NULL, 'staff')");
        $db->exec("INSERT INTO role_permissions VALUES (2, 'reports.submissions.manage', 0), (6, 'reports.submit', 2)");
        $migration = ['2189_client_tax_submission_permissions.sql'];
        $this->runMigrator($migration);
        $grants = $db->query("SELECT role_id, access_level FROM role_permissions WHERE permission_key='reports.submissions.manage' ORDER BY role_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame([1 => 2, 2 => 0, 3 => 2], array_map('intval', $grants));
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM role_permissions WHERE role_id=5')->fetchColumn(), 'Klientský výchozí přístup zůstává vypnutý.');
        self::assertSame(2, (int) $db->query("SELECT access_level FROM role_permissions WHERE role_id=6 AND permission_key='reports.submit'")->fetchColumn());

        $db->exec("DELETE FROM role_permissions WHERE role_id=1 AND permission_key='reports.submissions.manage'");
        $db->exec("UPDATE role_permissions SET access_level=1 WHERE role_id=3 AND permission_key='reports.submissions.manage'");
        $db->exec("DELETE FROM migrations WHERE filename='2189_client_tax_submission_permissions.sql'");
        $this->runMigrator($migration);
        $grants = $db->query("SELECT role_id, access_level FROM role_permissions WHERE permission_key='reports.submissions.manage' ORDER BY role_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame([2 => 0, 3 => 1], array_map('intval', $grants), 'Opakování neobnoví odebrané právo ani nepřepíše výslovně uloženou úroveň.');
        self::assertSame('1', $db->query("SELECT v FROM app_meta WHERE k='rbac_tax_submission_defaults_initialized'")->fetchColumn());
    }

    private function createLegacyParentTables(PDO $db): void
    {
        $tables = [
            'countries' => 'id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'vat_rates' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'currencies' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT UNSIGNED NOT NULL, code CHAR(3) NOT NULL,
                label VARCHAR(60) NOT NULL, symbol VARCHAR(8) NOT NULL,
                name_cs VARCHAR(60) NOT NULL, name_en VARCHAR(60) NOT NULL,
                KEY idx_currencies_supplier (supplier_id)",
            'supplier' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_name VARCHAR(190) NOT NULL, street VARCHAR(190) NOT NULL,
                city VARCHAR(120) NOT NULL, zip VARCHAR(10) NOT NULL,
                country_id SMALLINT UNSIGNED NOT NULL, email VARCHAR(190) NOT NULL,
                default_currency_id INT UNSIGNED NOT NULL, default_vat_rate_id INT UNSIGNED NOT NULL,
                ai_eu_residency_required TINYINT(1) NOT NULL DEFAULT 0,
                CONSTRAINT fk_sup_country FOREIGN KEY (country_id) REFERENCES countries(id),
                CONSTRAINT fk_sup_vat FOREIGN KEY (default_vat_rate_id) REFERENCES vat_rates(id),
                CONSTRAINT fk_sup_currency FOREIGN KEY (default_currency_id) REFERENCES currencies(id)",
            'users' => "id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(190) NOT NULL, password_hash CHAR(60) NOT NULL,
                name VARCHAR(120) NOT NULL,
                role ENUM('admin','accountant','readonly','client') NOT NULL DEFAULT 'readonly',
                locale ENUM('cs','en') NOT NULL DEFAULT 'cs', is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_users_email (email)",
            'user_suppliers' => "user_id BIGINT UNSIGNED NOT NULL, supplier_id INT UNSIGNED NOT NULL,
                role ENUM('accountant','readonly') NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, supplier_id), KEY idx_usersup_supplier (supplier_id),
                CONSTRAINT fk_usersup_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_usersup_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE",
            'documents' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'bank_transactions' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'cash_documents' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'journal_entry_lines' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'accounting_periods' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL, UNIQUE KEY uq_period_tenant (supplier_id,id)',
            'purchase_invoices' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL',
            'document_requests' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, purchase_invoice_id BIGINT UNSIGNED NULL',
            'warehouses' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, is_active TINYINT(1) NOT NULL DEFAULT 1',
            'stock_documents' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'stock_document_lines' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'stock_items' => "id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                lifecycle_status ENUM('draft','ready','retired') NOT NULL DEFAULT 'ready'",
            'stock_item_prices' => 'supplier_id INT UNSIGNED NOT NULL, stock_item_id BIGINT UNSIGNED NOT NULL',
            'stock_item_i18n' => 'supplier_id INT UNSIGNED NOT NULL, stock_item_id BIGINT UNSIGNED NOT NULL',
            'stock_media' => 'supplier_id INT UNSIGNED NOT NULL, stock_item_id BIGINT UNSIGNED NOT NULL',
            'stock_levels' => 'supplier_id INT UNSIGNED NOT NULL, stock_item_id BIGINT UNSIGNED NOT NULL',
            'dimension_types' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'dimension_values' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, supplier_id INT UNSIGNED NULL',
            'clients' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL',
            'journal_entries' => "id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL,
                source_type ENUM('manual') NOT NULL DEFAULT 'manual'",
            'payroll_employees' => "id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT UNSIGNED NOT NULL, UNIQUE KEY uq_payroll_employee_supplier_id (supplier_id, id)",
            'external_entity_map' => "id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT UNSIGNED NOT NULL, source_key VARCHAR(100) NOT NULL,
                entity_type VARCHAR(40) NOT NULL, external_id VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
                internal_id BIGINT UNSIGNED NOT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                UNIQUE KEY uq_external_entity_identity (supplier_id, source_key, entity_type, external_id),
                KEY ix_external_entity_internal (supplier_id, entity_type, internal_id),
                FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE",
            'bank_rule_templates' => "id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                template_key VARCHAR(64) NOT NULL, name_cs VARCHAR(120) NOT NULL, name_en VARCHAR(120) NOT NULL,
                direction ENUM('incoming','outgoing') NOT NULL, operation_type VARCHAR(40) NOT NULL,
                counterparty_bank VARCHAR(10) NULL, counterparty_prefix VARCHAR(6) NULL,
                vs_placeholder VARCHAR(40) NULL, message_contains VARCHAR(120) NULL, rule_key VARCHAR(64) NOT NULL,
                default_priority SMALLINT UNSIGNED NOT NULL DEFAULT 100, sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY uq_brt_key (template_key)",
        ];
        foreach ($tables as $table => $columns) {
            $db->exec("CREATE TABLE `{$table}` ({$columns}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        $db->exec('ALTER TABLE currencies ADD FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE');
        $db->exec('INSERT INTO countries (id) VALUES (1)');
        $db->exec('INSERT INTO vat_rates (id) VALUES (1)');
        $db->exec("INSERT INTO bank_rule_templates
            (template_key, name_cs, name_en, direction, operation_type, rule_key) VALUES
            ('fixture.incoming', 'Příchozí test', 'Incoming test', 'incoming', 'bank.rule.custom', 'fixture.incoming'),
            ('fixture.outgoing', 'Odchozí test', 'Outgoing test', 'outgoing', 'bank.rule.custom', 'fixture.outgoing')");
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

    private function runMigrator(?array $only = null): void
    {
        $command = [
            PHP_BINARY,
            $this->rootDir . '/api/bin/migrate.php',
            '--no-backfills',
            '--no-analyze',
            '--only=' . implode(',', $only ?? [
                '0017_app_meta.sql',
                '1074_dynamic_roles_permissions.sql',
                '1186_payroll_module_foundation.sql',
                '1404_purchase_invoice_submissions.sql',
                '1747_predefined_admin_roles.sql',
                '1748_bank_rule_templates_tenant_scope.sql',
                '1792_stock_fulfillment.sql',
                '1796_integration_core.sql',
                '1888_other_items_role_permissions.sql',
                '1959_purchase_invoice_approvals.sql',
                '1962_payroll_personnel_file.sql',
                '2014_client_ai_extraction.sql',
                '2015_client_bank_permissions.sql',
                '2084_property_registry.sql',
                '2085_property_charge_versions.sql',
                '2086_property_charge_issuance.sql',
                '2087_unit_payments.sql',
                '2038_invoice_edit_issued_permission.sql',
                '2090_property_payment_permissions.sql',
                '2094_property_receivable_permissions.sql',
                '2095_property_opening.sql',
                '2097_property_service_settlement_permissions.sql',
                '2056_price_list_role_permissions.sql',
                '2057_operational_settings_role_permissions.sql',
                '2075_purchase_invoice_vat_treatment_permission.sql',
                '2109_property_funds_role_defaults.sql',
                '2118_property_service_unwind_permission.sql',
                '2183_eet_role_permissions.sql',
                '2189_client_tax_submission_permissions.sql',
            ]),
        ];
        $env = getenv();
        self::assertIsArray($env);
        $env['MYINVOICE_DB_NAME'] = $this->database;
        $env['MYSQL_DATABASE'] = $this->database;
        $env['MYINVOICE_SCHEMA_CACHE'] = '0';
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
