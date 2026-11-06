<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\ApiTokenService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Audit C3 (critical) — mazání firmy nesmí osiřet účetní data.
 *
 * Původní deleteSupplierById kontroloval závislosti jen na clients+invoices a pak
 * mazal se SET FOREIGN_KEY_CHECKS=0 → firma s deníkem, přijatými fakturami,
 * majetkem, pokladnou či skladem šla smazat a všechna účetní data zůstala v DB
 * osiřelá (neplatný supplier_id). Tento test reprodukuje díru: firma s majetkem
 * a pokladnou se dřív dala smazat, teď musí vrátit 409 a data zůstat netknutá.
 *
 * In-process request přes celou pipeline (Bootstrap::buildApp() → $app->handle()),
 * autentizace admin bearer PAT. Fixtures se po sobě uklízí.
 */
#[Group('integration')]
final class SupplierDeleteDependencyTest extends TestCase
{
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
            // Best-effort úklid firmy C i s daty. FK kontrola je vypnutá JEN kvůli tabulkám
            // s RESTRICT FK; samotný DELETE FROM supplier musí proběhnout se ZAPNUTOU
            // kontrolou, jinak se nespustí ON DELETE CASCADE a zbytek dat firmy zůstane
            // v databázi osiřelý (viz ForeignKeyChecksGuardTest).
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                foreach (['accounting_account_definition_history', 'assets', 'cash_registers', 'accounting_supplier_settings',
                          'invoice_counters', 'currencies', 'activity_log',
                          'payment_orders', 'document_folders', 'document_tags', 'work_report_links'] as $t) {
                    $pdo->prepare("DELETE FROM `$t` WHERE supplier_id = ?")->execute([$this->supplierC]);
                }
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierC]);
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

    public function testDeleteBlockedWhenSupplierHasAccountingData(): void
    {
        $pdo = $this->db->pdo();

        // Firma C vede majetek a pokladnu — přesně scénář, který dřív (clients=0,
        // invoices=0) prošel a osiřel data.
        $pdo->prepare(
            "INSERT INTO assets (supplier_id, inventory_number, name, input_price, acquisition_date)
             VALUES (?, 'INV-C3-001', 'Testovací notebook', 30000.00, '2026-01-01')"
        )->execute([$this->supplierC]);
        $assetId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO cash_registers (supplier_id, name) VALUES (?, 'Hlavní pokladna')")
            ->execute([$this->supplierC]);

        $token = $this->mkAdminToken();
        $res = $this->request('DELETE', '/api/suppliers/' . $this->supplierC, $token);

        self::assertSame(409, $res->getStatusCode(), 'Firma s majetkem/pokladnou nesmí jít smazat');
        $body = $this->json($res);
        self::assertSame('has_dependencies', $body['error']['code'] ?? null);
        $msg = (string) ($body['error']['message'] ?? '');
        self::assertStringContainsString('majetek', $msg, '409 musí vyjmenovat blokující kategorie');
        self::assertStringContainsString('pokladny', $msg);

        // Data zůstala netknutá — firma i majetek dál existují.
        self::assertSame(
            1,
            (int) $pdo->query('SELECT COUNT(*) FROM supplier WHERE id = ' . $this->supplierC)->fetchColumn(),
            'Firma po odmítnutém smazání musí zůstat'
        );
        self::assertSame(
            1,
            (int) $pdo->query('SELECT COUNT(*) FROM assets WHERE id = ' . $assetId)->fetchColumn(),
            'Majetek nesmí být osiřen/smazán'
        );
    }

    public function testDeleteBlockedWhenSupplierHasPaymentOrders(): void
    {
        // Doplňkový nález (security-review po C3): payment_orders nemá FK na supplier
        // vůbec — bez blokace by DELETE FROM supplier tiše osiřel příkazy k úhradě.
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payment_orders (supplier_id, payment_date, total_amount, item_count)
             VALUES (?, '2026-01-01', 1000.00, 1)"
        )->execute([$this->supplierC]);

        $token = $this->mkAdminToken();
        $res = $this->request('DELETE', '/api/suppliers/' . $this->supplierC, $token);

        self::assertSame(409, $res->getStatusCode(), 'Firma s příkazem k úhradě nesmí jít smazat');
        $body = $this->json($res);
        self::assertStringContainsString('příkazy k úhradě', (string) ($body['error']['message'] ?? ''));
        self::assertSame(
            1,
            (int) $pdo->query('SELECT COUNT(*) FROM payment_orders WHERE supplier_id = ' . $this->supplierC)->fetchColumn(),
            'Příkaz k úhradě nesmí zůstat osiřelý ani smazaný bez firmy'
        );
    }

    public function testDatedAccountDefinitionsBlockSupplierDeletionWithoutAConstraintError(): void
    {
        $token=$this->mkAdminToken();
        $container=Bootstrap::buildContainer();
        $container->set(Connection::class,$this->db);
        $container->get(\MyInvoice\Service\Accounting\ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierC);
        $chart=$container->get(\MyInvoice\Repository\ChartOfAccountsRepository::class);
        $account=$chart->findByCode($this->supplierC,'511');
        $definitions=new \MyInvoice\Repository\AccountDefinitionRepository($this->db);
        $definitions->change($this->supplierC,(int)$account['id'],'2026-01-01',['name'=>'Synthetic dated account definition'],$definitions->stateHash($this->supplierC,(int)$account['id']),$this->userIds[0],'Synthetic account configuration evidence');
        $before=$this->db->pdo()->query('SELECT * FROM accounting_account_definition_history WHERE supplier_id='.$this->supplierC.' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertNotEmpty($before);
        self::assertSame(0,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id='.$this->supplierC)->fetchColumn());
        $res=$this->request('DELETE','/api/suppliers/'.$this->supplierC,$token);
        self::assertSame(409,$res->getStatusCode());
        self::assertSame('has_dependencies',$this->json($res)['error']['code']??null);
        self::assertSame($before,$this->db->pdo()->query('SELECT * FROM accounting_account_definition_history WHERE supplier_id='.$this->supplierC.' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC));
        self::assertSame(1,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM supplier WHERE id='.$this->supplierC)->fetchColumn());
    }

    public function testEmptySupplierWithMetadataCleansUpWithoutOrphans(): void
    {
        // Doplňkový nález: work_report_links/document_folders/document_tags nemají FK
        // na supplier — bez explicitního úklidu by po úspěšném smazání zůstaly osiřelé.
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO document_folders (supplier_id, name) VALUES (?, 'Test')")
            ->execute([$this->supplierC]);
        $pdo->prepare("INSERT INTO document_tags (supplier_id, name) VALUES (?, 'test-tag')")
            ->execute([$this->supplierC]);

        $token = $this->mkAdminToken();
        $res = $this->request('DELETE', '/api/suppliers/' . $this->supplierC, $token);

        self::assertSame(200, $res->getStatusCode());
        $this->supplierCDeleted = true;

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM document_folders WHERE supplier_id = ' . $this->supplierC)->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM document_tags WHERE supplier_id = ' . $this->supplierC)->fetchColumn());
    }

    public function testEmptySupplierDeletesAndCascadesWithoutOrphans(): void
    {
        $pdo = $this->db->pdo();

        // CASCADE dítě, které dřív FK_CHECKS=0 osiřel — po opravě se musí kaskádově smazat.
        $pdo->prepare('INSERT INTO accounting_supplier_settings (supplier_id) VALUES (?)')
            ->execute([$this->supplierC]);

        $token = $this->mkAdminToken();
        $res = $this->request('DELETE', '/api/suppliers/' . $this->supplierC, $token);

        self::assertSame(200, $res->getStatusCode(), 'Prázdnou firmu musí jít smazat');
        self::assertTrue((bool) ($this->json($res)['deleted'] ?? false));
        $this->supplierCDeleted = true;

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM supplier WHERE id = ' . $this->supplierC)->fetchColumn());
        self::assertSame(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM currencies WHERE supplier_id = ' . $this->supplierC)->fetchColumn(),
            'Currencies firmy nesmí zůstat osiřelé'
        );
        self::assertSame(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM accounting_supplier_settings WHERE supplier_id = ' . $this->supplierC)->fetchColumn(),
            'CASCADE dítě se musí smazat s firmou (FK kontrola byla u DELETE supplier zapnutá)'
        );
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

    private function request(string $method, string $path, string $bearer): ResponseInterface
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $bearer);
        return $this->app()->handle($req);
    }

    private function json(ResponseInterface $res): array
    {
        $decoded = json_decode((string) $res->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
