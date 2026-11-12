<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Tests\Support\PropertyChargeFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Smazání firmy v aplikaci projde VŽDY (rozhodnutí vlastníka produktu): firma s doklady,
 * deníkem, mzdami (tabulky jen pro přidávání), exekucemi i SVJ se smaže úplně, bez
 * osiřelých řádků a s obnovenými ochrannými triggery. Pojistkou je opsání IČO (názvu).
 *
 * In-process request přes celou pipeline (Bootstrap::buildApp() → $app->handle()),
 * autentizace admin bearer PAT. Fixtures se po sobě uklízí.
 */
#[Group('integration')]
final class SupplierDeleteDependencyTest extends TestCase
{
    use PropertyChargeFixture;

    private Connection $db;
    private Config $config;
    private ApiTokenService $svc;
    private ?App $app = null;

    private int $supplierC = 0;
    private int $currencyC = 0;
    private bool $supplierCDeleted = false;

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $this->config = Config::load($rootDir);
            $this->db = new Connection($this->config);
            $redis = new RedisFactory($this->config);
            $this->svc = new ApiTokenService($this->db, $redis);
            $this->db->pdo()->query('SELECT 1');
        } catch (\Exception $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'roles'")->fetchColumn() === false) {
            $this->markTestSkipped('Dynamické role chybí — spusť api/bin/migrate.php.');
        }

        // Musí existovat aspoň jeden „skutečný" supplier, aby C nebyl poslední
        // (jinak by delete spadl na cannot_delete_last dřív než na dependency check).
        $tpl = $pdo->query(
            'SELECT id, default_currency_id, country_id, default_vat_rate_id
               FROM supplier ORDER BY id LIMIT 1'
        )->fetch(\PDO::FETCH_ASSOC);
        if ($tpl === false) {
            $this->markTestSkipped('Žádný supplier v DB — spusť setup/migrace.');
        }

        // Založ throwaway firmu C s VLASTNÍ měnou CZK a default_currency_id na ni
        // (reálný cyklický FK supplier↔currencies, který musí delete rozbít).
        $ins = $pdo->prepare(
            "INSERT INTO supplier (company_name, display_name, street, city, zip, country_id,
                                   is_vat_payer, email, default_currency_id, default_vat_rate_id,
                                   default_payment_due_days, default_hourly_rate)
             VALUES ('__TEST C3 supplier', '__TEST C3 supplier', 'Testovací 1', 'Praha', '11000', ?,
                     0, 'c3-test@example.com', ?, ?, 14, 1000.00)"
        );
        $ins->execute([(int) $tpl['country_id'], (int) $tpl['default_currency_id'], (int) $tpl['default_vat_rate_id']]);
        $this->supplierC = (int) $pdo->lastInsertId();

        $cur = $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK — test', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        );
        $cur->execute([$this->supplierC]);
        $this->currencyC = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')
            ->execute([$this->currencyC, $this->supplierC]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();

        if ($this->supplierC > 0 && !$this->supplierCDeleted) {
            try {
                (new \MyInvoice\Service\System\CompanyPurgeService($this->db))->purge($this->supplierC, true, true);
            } catch (\Throwable) {
            }
        }

        if ($this->userIds !== []) {
            $place = implode(',', array_fill(0, count($this->userIds), '?'));
            $pdo->prepare("DELETE FROM activity_log WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM api_tokens WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM users WHERE id IN ($place)")->execute($this->userIds);
        }

        $this->userIds = [];
        $this->db->close();
        $this->app = null;
    }

    // ---------------------------------------------------------------- tests

    public function testCompanyWithAccountingPayrollAndEnforcementIsPurgedCompletely(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET ic = '00000027', taxpayer_type = 'po' WHERE id = ?")->execute([$this->supplierC]);
        $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'EUR', 'EUR', '€', 'euro', 'euro', 2, 1, 0)")->execute([$this->supplierC]);
        $admin = $this->superadminId();
        $container = Bootstrap::buildContainer();
        $container->set(Connection::class, $this->db);
        $generated = $container->get(\MyInvoice\Service\Sample\SampleDataGenerator::class)->generate($this->supplierC, $admin);
        self::assertGreaterThan(0, $generated['invoices']);
        self::assertGreaterThan(0, $generated['journal_entries']);
        self::assertGreaterThan(0, $generated['payroll_runs']);
        $employee = (int) $pdo->query('SELECT id FROM payroll_employees WHERE supplier_id = ' . $this->supplierC . ' ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare("INSERT INTO payroll_enforcement_cases (supplier_id, employee_id, case_key, case_kind, effective_from) VALUES (?, ?, 'synthetic-case', 'enforcement', '2026-01-01')")
            ->execute([$this->supplierC, $employee]);
        $file = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('invoices') . '/sup-' . $this->supplierC . '/synthetic.pdf';
        @mkdir(dirname($file), 0770, true);
        file_put_contents($file, '%PDF-synthetic');

        $preview = $this->json($this->request('GET', '/api/suppliers/' . $this->supplierC . '/delete-preview', $this->mkAdminToken()));
        self::assertSame('ic', $preview['confirm_with'] ?? null);
        self::assertGreaterThan(100, $preview['total_rows'] ?? 0);
        self::assertGreaterThan(0, $preview['protected_tables'] ?? 0);
        $tables = array_column($preview['tables'] ?? [], 'rows', 'table');
        self::assertArrayHasKey('journal_entries', $tables);
        self::assertArrayHasKey('payroll_enforcement_cases', $tables);

        $triggers = $this->triggers();
        $orphans = $this->orphanCount();
        $res = $this->request('DELETE', '/api/suppliers/' . $this->supplierC, $this->mkAdminToken(), ['confirm' => '00000027']);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->supplierCDeleted = true;
        self::assertSame([], $this->remainingRows($this->supplierC));
        self::assertSame($orphans, $this->orphanCount(), 'Smazání firmy nesmí nechat osiřelé řádky');
        self::assertSame($triggers, $this->triggers(), 'Ochranné triggery musí po smazání existovat');
        self::assertFileDoesNotExist($file);
        $audit = $pdo->query("SELECT supplier_id, payload FROM activity_log WHERE action = 'supplier.deleted' AND entity_type = 'supplier' AND entity_id = " . $this->supplierC)
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($audit);
        self::assertNull($audit['supplier_id']);
        $payload = json_decode((string) $audit['payload'], true);
        self::assertSame('00000027', $payload['ic']);
        self::assertGreaterThan(100, $payload['rows']);
        self::assertGreaterThan(0, $payload['protected_tables']);
    }

    public function testCompanyWithSvjHistoryIsPurgedCompletely(): void
    {
        $fixture = $this->chargeFixture($this->db);
        $supplier = (int) $fixture['supplier'];
        $pdo = $this->db->pdo();
        $name = (string) $pdo->query('SELECT company_name FROM supplier WHERE id = ' . $supplier)->fetchColumn();
        $ic = trim((string) $pdo->query('SELECT ic FROM supplier WHERE id = ' . $supplier)->fetchColumn());
        self::assertGreaterThan(0, (int) $pdo->query('SELECT COUNT(*) FROM property_revisions WHERE supplier_id = ' . $supplier)->fetchColumn());
        self::assertGreaterThan(0, (int) $pdo->query('SELECT COUNT(*) FROM unit_charge_components WHERE supplier_id = ' . $supplier)->fetchColumn());

        $triggers = $this->triggers();
        $orphans = $this->orphanCount();
        $res = $this->request('DELETE', '/api/suppliers/' . $supplier, $this->mkAdminToken(), ['confirm' => $ic !== '' ? $ic : $name]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([], $this->remainingRows($supplier));
        self::assertSame($orphans, $this->orphanCount());
        self::assertSame($triggers, $this->triggers());
    }

    public function testWrongConfirmationIsRejectedAndNothingIsDeleted(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO cash_registers (supplier_id, name) VALUES (?, 'Hlavní pokladna')")->execute([$this->supplierC]);
        $token = $this->mkAdminToken();

        foreach ([null, ['confirm' => ''], ['confirm' => '__TEST C3 supplie'], ['confirm' => '12345678']] as $body) {
            $res = $this->request('DELETE', '/api/suppliers/' . $this->supplierC, $token, $body);
            self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
            self::assertSame('confirmation_mismatch', $this->json($res)['error']['code'] ?? null);
        }
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM supplier WHERE id = ' . $this->supplierC)->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM cash_registers WHERE supplier_id = ' . $this->supplierC)->fetchColumn());

        $res = $this->request('DELETE', '/api/suppliers/' . $this->supplierC, $token, ['confirm' => ' __TEST C3 supplier ']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->supplierCDeleted = true;
        self::assertSame([], $this->remainingRows($this->supplierC));
    }

    public function testLastCompanyCannotBeDeleted(): void
    {
        $pdo = $this->db->pdo();
        $token = $this->mkAdminToken();
        $this->app();
        $pdo->prepare('CREATE TEMPORARY TABLE supplier_last_copy AS SELECT * FROM supplier WHERE id = ?')->execute([$this->supplierC]);
        $pdo->exec('CREATE TEMPORARY TABLE supplier AS SELECT * FROM supplier_last_copy');
        try {
            $res = $this->request('DELETE', '/api/suppliers/' . $this->supplierC, $token, ['confirm' => '__TEST C3 supplier']);
        } finally {
            $pdo->exec('DROP TEMPORARY TABLE supplier');
            $pdo->exec('DROP TEMPORARY TABLE supplier_last_copy');
        }
        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('cannot_delete_last', $this->json($res)['error']['code'] ?? null);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM supplier WHERE id = ' . $this->supplierC)->fetchColumn());
    }

    public function testDatedAccountDefinitionsAndGoPaySettingsDoNotBlockDeletion(): void
    {
        $container = Bootstrap::buildContainer();
        $container->set(Connection::class, $this->db);
        $container->get(\MyInvoice\Service\Accounting\ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierC);
        $chart = $container->get(\MyInvoice\Repository\ChartOfAccountsRepository::class);
        $account = $chart->findByCode($this->supplierC, '511');
        $definitions = new \MyInvoice\Repository\AccountDefinitionRepository($this->db);
        $token = $this->mkAdminToken();
        $definitions->change($this->supplierC, (int) $account['id'], '2026-01-01', ['name' => 'Synthetic dated account definition'],
            $definitions->stateHash($this->supplierC, (int) $account['id']), $this->userIds[0], 'Synthetic account configuration evidence');
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO gopay_settings (supplier_id, gopay_account_id) VALUES (?, ?)')
            ->execute([$this->supplierC, (int) $chart->findByCode($this->supplierC, '221')['id']]);
        $pdo->prepare('INSERT INTO accounting_supplier_settings (supplier_id) VALUES (?)')->execute([$this->supplierC]);
        $pdo->prepare("INSERT INTO payment_orders (supplier_id, payment_date, total_amount, item_count) VALUES (?, '2026-01-01', 1000.00, 1)")
            ->execute([$this->supplierC]);
        $pdo->prepare("INSERT INTO document_folders (supplier_id, name) VALUES (?, 'Test')")->execute([$this->supplierC]);

        $res = $this->request('DELETE', '/api/suppliers/' . $this->supplierC, $token, ['confirm' => '__TEST C3 supplier']);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->supplierCDeleted = true;
        self::assertSame([], $this->remainingRows($this->supplierC));
    }

    public function testBlockedPurgeRollsBackEverythingAndRestoresTriggers(): void
    {
        $pdo = $this->db->pdo();
        $actor = $this->superadminId();
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '1900-01-01', 'cz_504')")
            ->execute([$this->supplierC]);
        (new \MyInvoice\Repository\Property\PropertyProfileRepository($this->db))->save($this->supplierC, ['expected_revision' => 0,
            'effective_from' => '2026-01-01T00:00:00+01:00', 'enabled' => true, 'reason' => 'Synthetic profile', 'title_ref' => 'Synthetic deed',
            'idempotency_key' => 'profile'], $actor);
        $other = (int) $pdo->query('SELECT id FROM supplier WHERE id <> ' . $this->supplierC . ' ORDER BY id LIMIT 1')->fetchColumn();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $pdo->prepare('INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$other, 'Synthetic foreign reference', 'Synthetic', 'Synthetic', '10000', $country, 'foreign-ref@example.test', $this->currencyC]);
        $foreignClient = (int) $pdo->lastInsertId();
        $profiles = (int) $pdo->query('SELECT COUNT(*) FROM property_module_profiles WHERE supplier_id = ' . $this->supplierC)->fetchColumn();
        self::assertGreaterThan(0, $profiles);
        $triggers = $this->triggers();

        try {
            $res = $this->request('DELETE', '/api/suppliers/' . $this->supplierC, $this->mkAdminToken(), ['confirm' => '__TEST C3 supplier']);
        } finally {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$foreignClient]);
        }

        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('purge_blocked', $this->json($res)['error']['code'] ?? null);
        self::assertSame($triggers, $this->triggers());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM supplier WHERE id = ' . $this->supplierC)->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM currencies WHERE id = ' . $this->currencyC)->fetchColumn());
        self::assertSame($profiles, (int) $pdo->query('SELECT COUNT(*) FROM property_module_profiles WHERE supplier_id = ' . $this->supplierC)->fetchColumn());
    }

    public function testInterruptedPurgeRestoresTriggersOnNextRun(): void
    {
        $pdo = $this->db->pdo();
        $trigger = 'trg_pr_audit_delete';
        $create = $pdo->query('SHOW CREATE TRIGGER `' . $trigger . '`')->fetch(\PDO::FETCH_ASSOC);
        $file = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('company-purge') . '/triggers-' . $this->schema() . '.json';
        @mkdir(dirname($file), 0770, true);
        file_put_contents($file, json_encode(['schema' => $this->schema(), 'triggers' => [$trigger => [
            'sql' => $create['SQL Original Statement'], 'sql_mode' => $create['sql_mode']]]]));
        $pdo->exec('DROP TRIGGER `' . $trigger . '`');
        try {
            $restored = (new \MyInvoice\Service\System\CompanyPurgeService($this->db))->restorePendingTriggers();
        } finally {
            if (!in_array($trigger, $this->triggers(), true)) {
                $pdo->exec((string) $create['SQL Original Statement']);
            }
            @unlink($file);
        }
        self::assertSame(1, $restored);
        self::assertContains($trigger, $this->triggers());
    }

    // ------------------------------------------------------------- fixtures

    private function mkAdminToken(): string
    {
        $email = '__test_c3_' . bin2hex(random_bytes(6)) . '@example.com';
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
             VALUES (?, '\$2y\$10\$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', '__TEST C3',
                     (SELECT id FROM roles WHERE system_key = 'superadmin'), 'cs', 1)"
        );
        $stmt->execute([$email]);
        $userId = (int) $this->db->pdo()->lastInsertId();
        $this->userIds[] = $userId;

        $out = $this->svc->generate($userId, null, '__test_c3_' . bin2hex(random_bytes(4)), 'read_write', null);
        return $out['plaintext'];
    }

    // -------------------------------------------------------------- helpers

    private function app(): App
    {
        return $this->app ??= Bootstrap::buildApp();
    }

    /** @param array<string,mixed>|null $body */
    private function request(string $method, string $path, string $bearer, ?array $body = null): ResponseInterface
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $bearer);
        if ($body !== null) {
            $req = $req->withHeader('Content-Type', 'application/json');
            $req->getBody()->write((string) json_encode($body));
            $req->getBody()->rewind();
        }
        return $this->app()->handle($req);
    }

    private function json(ResponseInterface $res): array
    {
        $decoded = json_decode((string) $res->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,int> tabulka => počet řádků firmy, které po smazání zbyly */
    private function remainingRows(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $left = [];
        $types = $pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $tables = array_filter($pdo->query("SELECT TABLE_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'supplier_id'")->fetchAll(\PDO::FETCH_COLUMN),
            static fn (string $table): bool => in_array($types[$table] ?? null, ['BASE TABLE', 'SYSTEM VERSIONED'], true));
        foreach ($tables as $table) {
            if (in_array($table, \MyInvoice\Service\System\CompanyPurgeService::RETAINED_TABLES, true)) {
                continue;
            }
            $n = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE supplier_id = {$supplierId}")->fetchColumn();
            if ($n > 0) {
                $left[$table] = $n;
            }
        }
        if ((int) $pdo->query('SELECT COUNT(*) FROM supplier WHERE id = ' . $supplierId)->fetchColumn() > 0) {
            $left['supplier'] = 1;
        }
        return $left;
    }

    /** Řádky, jejichž cizí klíč ukazuje na neexistující řádek (přes všechny vazby schématu). */
    private function orphanCount(): int
    {
        $pdo = $this->db->pdo();
        $refs = $pdo->query('SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, CONSTRAINT_NAME
              FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_SCHEMA = DATABASE()
             ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION')->fetchAll(\PDO::FETCH_ASSOC);
        $constraints = [];
        foreach ($refs as $r) {
            $constraints[$r['TABLE_NAME'] . '|' . $r['CONSTRAINT_NAME']][] = $r;
        }
        $n = 0;
        foreach ($constraints as $columns) {
            $notNull = implode(' AND ', array_map(static fn (array $c): string => "c.`{$c['COLUMN_NAME']}` IS NOT NULL", $columns));
            $join = implode(' AND ', array_map(static fn (array $c): string => "p.`{$c['REFERENCED_COLUMN_NAME']}` = c.`{$c['COLUMN_NAME']}`", $columns));
            $n += (int) $pdo->query(sprintf('SELECT COUNT(*) FROM `%s` c WHERE %s AND NOT EXISTS (SELECT 1 FROM `%s` p WHERE %s)',
                $columns[0]['TABLE_NAME'], $notNull, $columns[0]['REFERENCED_TABLE_NAME'], $join))->fetchColumn();
        }
        return $n;
    }

    /** @return list<string> */
    private function triggers(): array
    {
        $names = array_column(\MyInvoice\Infrastructure\Database\TriggerMetadata::read($this->db->pdo(), $this->schema()), 'TRIGGER_NAME');
        usort($names, \MyInvoice\Infrastructure\Database\SchemaMetadata::compareNames(...));
        return $names;
    }

    private function schema(): string
    {
        return (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn();
    }

    private function superadminId(): int
    {
        $this->mkAdminToken();
        return $this->userIds[array_key_last($this->userIds)];
    }
}
