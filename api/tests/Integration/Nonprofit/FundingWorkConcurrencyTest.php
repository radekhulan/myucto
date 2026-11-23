<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Nonprofit\FundingRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Nonprofit\Funding\Work\FundingWorkException;
use MyInvoice\Service\Nonprofit\Funding\Work\FundingWorkLedger;
use MyInvoice\Service\Nonprofit\Funding\Work\FundingWorkService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * R3 (#403): dva uživatelé přiřazují náklady k témuž zdroji současně. Druhý čeká na zámek
 * období; po potvrzení prvního vidí jeho přiřazení i se starším snímkem transakce a dostane
 * 422 se skutečným zůstatkem rozpočtu i kapacity řádku.
 */
#[Group('integration')]
final class FundingWorkConcurrencyTest extends TestCase
{
    use IsolatedSupplierTrait;

    private string $schema;
    private PDO $server;
    /** @var list<Connection> */
    private array $connections = [];

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $config = $container->get(Config::class);
        $source = (string) $container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $source);
        self::assertTrue(\MyInvoice\Tests\Support\TestDatabaseHost::isAllowed((string) $config->get('db.host')), 'Testovací DB musí být lokální nebo v db.test_allowed_hosts.');
        $this->schema = 'myucto_funding_work_' . bin2hex(random_bytes(5)) . '_test';
        $this->server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $cloner = new DatabaseCloner($this->server);
        $plan = $cloner->plan($source);
        $cloner->copyTables($plan, $this->schema, static function (): void {});
        $cloner->finalize($plan, $this->schema);
        $data = $config->all();
        $data['db']['name'] = $this->schema;
        for ($i = 0; $i < 2; ++$i) {
            $db = Connection::withoutSharedTestConnection(fn() => new Connection(new Config($data, $config->dataDir())));
            $db->pdo()->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $db->pdo()->exec('SET SESSION innodb_snapshot_isolation = 0');
            $db->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->connections[] = $db;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) {
            if ($db->pdo()->inTransaction()) $db->pdo()->rollBack();
            $db->close();
        }
        self::assertMatchesRegularExpression('/^myucto_funding_work_[a-f0-9]{10}_test$/D', $this->schema);
        DatabaseCloner::dropDatabase($this->server, $this->schema);
    }

    public function testSecondUserWaitsForLockAndThenSeesCommittedAllocation(): void
    {
        [$a, $b] = $this->connections;
        $pdo = $a->pdo();
        $pdo->beginTransaction();
        $supplier = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
        $actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$supplier]);
        $period = (new AccountingPeriodRepository($a))->create($supplier, 2026, '2026-01-01', '2026-12-31');
        $accounts = [];
        foreach (['221' => 'asset', '518' => 'expense'] as $code => $type) {
            $pdo->prepare('INSERT INTO chart_of_accounts(supplier_id, account_code, name, account_type) VALUES (?, ?, ?, ?)')->execute([$supplier, (string) $code, 'Synthetic ' . $code, $type]);
            $accounts[(string) $code] = (int) $pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO clients(supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer, is_vendor) VALUES (?, 'Synthetic provider', 'Test', 'Test', '10000', 203, 'provider@example.test', 'cs', ?, 0, 1)")
            ->execute([$supplier, (int) $pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn()]);
        $provider = (int) $pdo->lastInsertId();
        $lines = [];
        foreach (['600.00', '600.00'] as $amount) {
            $pdo->prepare("INSERT INTO journal_entries(supplier_id, period_id, entry_date, source_type, posted_at) VALUES (?, ?, '2026-05-01', 'manual', NOW())")->execute([$supplier, $period]);
            $entry = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO journal_entry_lines(supplier_id, entry_id, line_no, account_id, side, amount) VALUES (?, ?, 0, ?, 'debit', ?), (?, ?, 1, ?, 'credit', ?)")
                ->execute([$supplier, $entry, $accounts['518'], $amount, $supplier, $entry, $accounts['221'], $amount]);
            $q = $pdo->prepare("SELECT id FROM journal_entry_lines WHERE entry_id = ? AND side = 'debit'");
            $q->execute([$entry]);
            $lines[] = (int) $q->fetchColumn();
        }
        $workA = self::service($a);
        $workB = self::service($b);
        $input = ['provider_id' => $provider, 'kind' => 'gift', 'title' => 'Synthetic gift', 'contract_ref' => null, 'budget_cents' => 100000,
            'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'activity_kind_ids' => [], 'account_prefixes' => [], 'legacy_source_key' => null];
        $source = $workA->create($supplier, $period, $input, $actor)['source']['id'];
        $other = $workA->create($supplier, $period, ['title' => 'Synthetic second gift'] + $input, $actor)['source']['id'];
        $pdo->commit();

        // Uživatel B si načte stav (snímek transakce) dřív, než A potvrdí.
        $b->pdo()->beginTransaction();
        self::assertSame(100000, $workB->detail($supplier, $period, $source)['totals']['remaining_cents']);

        $pdo->beginTransaction();
        $workA->allocate($supplier, $period, $source, [['line_id' => $lines[0], 'amount_cents' => 60000]], $actor);

        // B čeká na zámek období, který drží nepotvrzený zápis A, i když přiřazuje týž řádek jinému zdroji.
        $b->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        foreach ([[$source, $lines[1]], [$other, $lines[0]]] as [$target, $line]) {
            try {
                $workB->allocate($supplier, $period, $target, [['line_id' => $line, 'amount_cents' => 100]], $actor);
                self::fail('Souběžný zápis musí čekat na zámek.');
            } catch (\PDOException $e) {
                self::assertTrue(TransactionFailure::lockConflict($e), $e->getMessage());
            }
        }
        // Akce převede zámek na 409 funding_work_busy, ne na chybu serveru.
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/accounting/periods/' . $period . '/funding-work/sources/' . $source . '/allocations')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $actor, 'role' => 'staff'])
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', new \MyInvoice\Security\EffectiveRole(92, 'Synthetic writer', 'staff', true, ['accounting' => 2]))
            ->withParsedBody(['items' => [['line_id' => $lines[1], 'amount_cents' => 100]]]);
        $response = (new \MyInvoice\Action\Nonprofit\FundingWorkAction($workB))->allocate($request, (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(), ['id' => (string) $period, 'sid' => (string) $source]);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('funding_work_busy', json_decode((string) $response->getBody(), true)['error']['code']);
        $pdo->commit();

        // Po potvrzení A: B se starším snímkem vidí přiřazení A (zamykací čtení) a dostane zůstatek.
        try {
            $workB->allocate($supplier, $period, $source, [['line_id' => $lines[1], 'amount_cents' => 50000]], $actor);
            self::fail('Rozpočet zdroje nesmí souběhem přetéct.');
        } catch (FundingWorkException $e) {
            self::assertSame('funding_work_budget_exceeded', $e->errorCode);
            self::assertSame(40000, $e->extra['remaining_cents']);
        }
        try {
            $workB->allocate($supplier, $period, $other, [['line_id' => $lines[0], 'amount_cents' => 1]], $actor);
            self::fail('Kapacita řádku nesmí souběhem přetéct.');
        } catch (FundingWorkException $e) {
            self::assertSame('funding_work_capacity_exceeded', $e->errorCode);
            self::assertSame(0, $e->extra['capacity_cents']);
        }
        $workB->allocate($supplier, $period, $source, [['line_id' => $lines[1], 'amount_cents' => 40000]], $actor);
        $b->pdo()->commit();
        self::assertSame(0, $workA->detail($supplier, $period, $source)['totals']['remaining_cents']);
        $q = $pdo->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM nonprofit_funding_work_allocations WHERE supplier_id = ? AND source_id = ? AND status = 'active'");
        $q->execute([$supplier, $source]);
        self::assertSame(100000, (int) $q->fetchColumn());
    }

    private static function service(Connection $db): FundingWorkService
    {
        $container = Bootstrap::buildContainer();
        $container->set(Connection::class, $db);
        return $container->get(FundingWorkService::class);
    }
}
