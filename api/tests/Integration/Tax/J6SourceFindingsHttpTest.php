<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CurrentProviderFactory;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6SourceFindingService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Zjištění podkladů daňového posouzení 504 mimo koncept (#402): souhrn po účtu a kódu
 * ve stejném průchodu jako otisk podkladů a stránkovaný seznam řádků s proklikem.
 * Syntetický spolek, rok 2025.
 */
final class J6SourceFindingsHttpTest extends TestCase
{
    use IsolatedSupplierTrait;

    private \DI\Container $container;
    private Connection $db;
    private PDO $pdo;
    private int $supplier;
    private int $actor;
    private int $period;
    private array $catalog;
    private int $kind;
    private array $accounts = [];

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode='double_entry',taxpayer_type='po' WHERE id=?")->execute([$this->supplier]);
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2025-01-01','cz_504')")
            ->execute([$this->supplier]);
        $this->period = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2025, '2025-01-01', '2025-12-31');
        $this->catalog = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2025-01-01');
        $this->pdo->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,?,?)')
            ->execute([$this->catalog['activity_kind']['id'], $this->supplier, 'SPORT', 'Syntetický druh sport']);
        $this->kind = (int) $this->pdo->lastInsertId();
        foreach (['221' => 'asset', '518' => 'expense', '602' => 'revenue'] as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,?,?,?)')
                ->execute([$this->supplier, (string) $code, 'Syntetický účet ' . $code, $type]);
            $this->accounts[(string) $code] = (int) $this->pdo->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testSummaryGroupsFindingsByAccountAndCodeInTheFingerprintPass(): void
    {
        $this->entries('2025-02-01', '602', 'credit', 3, null);          // bez dimenzí
        $this->entries('2025-03-01', '518', 'debit', 2, ['activity']);  // jen činnost
        $this->entries('2025-04-01', '518', 'debit', 4, ['activity', 'activity_kind', 'tax_regime']);

        [$status, $summary] = $this->http('GET', '/source-findings');
        self::assertSame(200, $status, json_encode($summary));
        $fingerprint = $this->container->get(J6CurrentProviderFactory::class)->fingerprint($this->supplier, $this->period);
        self::assertSame($fingerprint->inputHash, $summary['source']['input_hash']);
        self::assertSame($fingerprint->findingCount, $summary['source']['finding_count']);
        self::assertSame($fingerprint->findingCount, array_sum(array_column($summary['groups'], 'line_count')));
        self::assertSame(5, $summary['line_count']);

        $groups = [];
        foreach ($summary['groups'] as $group) $groups[$group['account_code'] . ':' . $group['code']] = $group['line_count'];
        self::assertSame(3, $groups['602:missing_activity']);
        self::assertSame(3, $groups['602:missing_tax_regime']);
        self::assertSame(2, $groups['518:missing_activity_kind']);
        self::assertArrayNotHasKey('518:missing_activity', $groups);
        self::assertSame(array_keys($groups), array_values(array_unique(array_keys($groups))));
        self::assertSame('Syntetický účet 518', $summary['groups'][0]['account_name']);
        self::assertSame('518', $summary['groups'][0]['account_code'], 'Skupiny jsou seřazené podle účtu.');
    }

    public function testLinesFollowCursorAcrossSnapshotPagesWithFilters(): void
    {
        // 650 zápisů po dvou řádcích = 1 300 řádků deníku, tedy víc stránek podkladů po 500.
        $first = $this->entries('2025-01-10', '518', 'debit', 1, ['activity']);
        $this->entries('2025-02-10', '518', 'debit', 300, ['activity', 'activity_kind', 'tax_regime']);
        $middle = $this->entries('2025-06-10', '602', 'credit', 1, null);
        $this->entries('2025-07-10', '518', 'debit', 347, ['activity', 'activity_kind', 'tax_regime']);
        $last = $this->entries('2025-12-10', '518', 'debit', 1, ['activity']);

        $seen = [];
        $after = null;
        $calls = 0;
        do {
            [$status, $page] = $this->http('GET', '/source-findings/lines', ['limit' => '2'] + ($after === null ? [] : ['after' => $after]));
            self::assertSame(200, $status, json_encode($page));
            foreach ($page['rows'] as $row) $seen[] = $row['entry_id'];
            $after = $page['after'];
        } while ($after !== null && ++$calls < 10);
        self::assertSame([$first[0], $middle[0], $last[0]], $seen, 'Bez vynechání a bez duplicit přes hranice stránek.');

        [, $page] = $this->http('GET', '/source-findings/lines', ['code' => 'missing_activity']);
        self::assertSame([$middle[0]], array_column($page['rows'], 'entry_id'));
        $row = $page['rows'][0];
        self::assertSame('602', $row['account_code']);
        self::assertSame('2025-06-10', $row['date']);
        self::assertContains('missing_tax_regime', $row['codes'], 'Řádek nese všechny své kódy, nejen filtrovaný.');
        self::assertSame('manual', $row['source_type']);
        self::assertSame('Syntetická operace 602', $row['description']);
        self::assertSame('SYN-602', $row['document_no']);
        self::assertNull($page['after']);

        [, $page] = $this->http('GET', '/source-findings/lines', ['account' => '518', 'code' => 'missing_activity_kind']);
        self::assertSame([$first[0], $last[0]], array_column($page['rows'], 'entry_id'));
    }

    public function testLinesStopAtScanBudgetAndResumeFromCursor(): void
    {
        $count = intdiv(J6SourceFindingService::SCAN_BUDGET, 2) + 10;
        $this->entries('2025-02-01', '518', 'debit', $count, ['activity', 'activity_kind', 'tax_regime']);
        $tail = $this->entries('2025-11-01', '518', 'debit', 1, null);

        [$status, $page] = $this->http('GET', '/source-findings/lines');
        self::assertSame(200, $status, json_encode($page));
        self::assertSame([], $page['rows']);
        self::assertNotNull($page['after'], 'Po vyčerpání rozpočtu vrátí kurzor pro pokračování.');
        [, $page] = $this->http('GET', '/source-findings/lines', ['after' => $page['after']]);
        self::assertSame($tail, array_column($page['rows'], 'entry_id'));
    }

    public function testCursorBelongsToItsFilterAndInputIsValidated(): void
    {
        $this->entries('2025-02-01', '602', 'credit', 3, null);
        [, $page] = $this->http('GET', '/source-findings/lines', ['limit' => '1', 'code' => 'missing_activity']);
        self::assertNotNull($page['after']);
        [$status, $body] = $this->http('GET', '/source-findings/lines', ['limit' => '1', 'code' => 'missing_tax_regime', 'after' => $page['after']]);
        self::assertSame(409, $status);
        self::assertSame('nonprofit_tax_cursor_stale', $body['error']['code']);
        foreach ([['code' => 'Missing'], ['account' => '5 18'], ['limit' => '0'], ['limit' => '201'], ['after' => 'x!']] as $invalid) {
            [$status, $body] = $this->http('GET', '/source-findings/lines', $invalid);
            self::assertSame(400, $status, json_encode($invalid));
        }
        [$status, $body] = $this->http('GET', '/source-findings', [], null, '999999999');
        self::assertContains($status, [403, 404], 'Cizí nebo neexistující období nic nevydá.');
        self::assertArrayNotHasKey('groups', $body);
    }

    public function testFindingsAreSessionOnlyAndNeedAccountingRead(): void
    {
        $this->entries('2025-02-01', '602', 'credit', 1, null);
        foreach (['/source-findings', '/source-findings/lines'] as $path) {
            [$status, $body] = $this->http('GET', $path, [], 'bearer');
            self::assertSame(403, $status, $path);
            self::assertSame('nonprofit_tax_session_only', $body['error']['code']);
            [$status] = $this->http('GET', $path, [], 'session', null, []);
            self::assertSame(403, $status, $path . ' bez práva accounting');
            [$status] = $this->http('GET', $path, [], 'session', null, ['accounting' => 1]);
            self::assertSame(200, $status, $path . ' s právem accounting ke čtení');
        }
        $middleware = new \ReflectionClassConstant(\MyInvoice\Middleware\ApiScopeMiddleware::class, 'BEARER_SESSION_ONLY');
        $matches = array_filter($middleware->getValue(), fn (string $pattern): bool =>
            preg_match($pattern, '/api/accounting/periods/' . $this->period . '/nonprofit-tax/source-findings/lines') === 1);
        self::assertNotEmpty($matches, 'Veřejné API token zjištění podkladů nevydá.');
    }

    public function testSummaryLeavesSourceFingerprintAndClosingCheckUnchanged(): void
    {
        $this->entries('2025-02-01', '602', 'credit', 2, null);
        $factory = $this->container->get(J6CurrentProviderFactory::class);
        $before = $factory->fingerprint($this->supplier, $this->period)->toArray();
        $guard = $this->container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentGuard::class);
        $checkBefore = $guard->closingCheck($this->supplier, $this->period);
        $this->http('GET', '/source-findings');
        $this->http('GET', '/source-findings/lines');
        self::assertSame($before, $factory->fingerprint($this->supplier, $this->period)->toArray());
        self::assertSame($checkBefore, $guard->closingCheck($this->supplier, $this->period));
    }

    /**
     * Zaúčtuje $count syntetických zápisů (řádek výsledkového účtu + 221) jedním dávkovým
     * vložením. $axes = osy s doloženým původem, null = bez dimenzí.
     *
     * @param list<string>|null $axes
     * @return list<int> id zápisů
     */
    private function entries(string $date, string $account, string $side, int $count, ?array $axes): array
    {
        $values = ['activity' => $this->catalog['activity']['values']['main'], 'activity_kind' => $this->kind,
            'tax_regime' => $this->catalog['tax_regime']['values']['out_of_scope']];
        $ids = [];
        foreach (array_chunk(range(1, $count), 500) as $chunk) {
            $this->pdo->prepare('INSERT INTO journal_entries(supplier_id,period_id,entry_date,document_no,source_type,description,posted_at) VALUES '
                . implode(',', array_fill(0, count($chunk), "(?,?,?,?,'manual',?,NOW())")))
                ->execute(array_merge(...array_map(fn (): array => [$this->supplier, $this->period, $date, 'SYN-' . $account, 'Syntetická operace ' . $account], $chunk)));
            $firstId = (int) $this->pdo->lastInsertId();
            $batch = range($firstId, $firstId + count($chunk) - 1);
            $ids = [...$ids, ...$batch];
            $lines = [];
            foreach ($batch as $entry) {
                array_push($lines, $this->supplier, $entry, 1, $this->accounts[$account], $side, '100.00');
                array_push($lines, $this->supplier, $entry, 2, $this->accounts['221'], $side === 'debit' ? 'credit' : 'debit', '100.00');
            }
            $this->pdo->prepare('INSERT INTO journal_entry_lines(supplier_id,entry_id,line_no,account_id,side,amount) VALUES '
                . implode(',', array_fill(0, count($batch) * 2, '(?,?,?,?,?,?)')))->execute($lines);
            if ($axes === null || $axes === []) continue;
            $marks = implode(',', array_fill(0, count($batch), '?'));
            $stmt = $this->pdo->prepare("SELECT l.id FROM journal_entry_lines l WHERE l.supplier_id=? AND l.line_no=1 AND l.entry_id IN ($marks)");
            $stmt->execute([$this->supplier, ...$batch]);
            $dimensions = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $line) {
                foreach ($axes as $axis) array_push($dimensions, $this->supplier, (int) $line, $this->catalog[$axis]['id'], $values[$axis]);
            }
            $this->pdo->prepare('INSERT INTO journal_entry_line_dimensions(supplier_id,line_id,dimension_type_id,dimension_value_id,assignment_origin) VALUES '
                . implode(',', array_fill(0, intdiv(count($dimensions), 4), "(?,?,?,?,'explicit')")))->execute($dimensions);
        }
        return $ids;
    }

    /** @return array{0:int, 1:array<string,mixed>} */
    private function http(string $method, string $path, array $query = [], ?string $auth = 'session', ?string $period = null,
        array $permissions = ['accounting' => 2, 'accounting.periods.close' => 2]): array
    {
        $app = \Slim\Factory\AppFactory::create(new \Slim\Psr7\Factory\ResponseFactory(), $this->container);
        \MyInvoice\Routes::register($app);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest($method, '/api/accounting/periods/' . ($period ?? $this->period) . '/nonprofit-tax' . $path)
            ->withQueryParams($query)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, $auth)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new \MyInvoice\Security\EffectiveRole(125, 'Synthetic findings', 'staff', true, $permissions))
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier);
        $response = $app->handle($request);
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)];
    }
}
