<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Automation;

use MyInvoice\Action\Automation\AutomationFeedAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Automation\AutomationFeedService;
use MyInvoice\Service\Automation\FeedQuery;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class AutomationFeedMembershipTest extends TestCase
{
    private Connection $db;
    private AutomationFeedService $feed;
    private bool $inTransaction = false;
    private int $supplierA = 0;
    private int $supplierB = 0;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 4);
        if (!is_file($root . '/cfg.php')) $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->feed = $container->get(AutomationFeedService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierA = (int) ($pdo->query("SELECT id FROM supplier WHERE accounting_mode='double_entry' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierA <= 0) $this->markTestSkipped('Chybí firma s podvojným účetnictvím.');

        $pdo->beginTransaction();
        $this->inTransaction = true;
        $copy = $pdo->prepare(
            "INSERT INTO supplier (company_name, display_name, street, city, zip, country_id,
                                   is_vat_payer, email, default_currency_id, default_vat_rate_id,
                                   default_payment_due_days, default_hourly_rate, accounting_mode)
             SELECT '__TEST AUTOMATION B', '__TEST AUTOMATION B', street, city, zip, country_id,
                    0, email, default_currency_id, default_vat_rate_id,
                    default_payment_due_days, default_hourly_rate, 'double_entry'
               FROM supplier WHERE id=?"
        );
        $copy->execute([$this->supplierA]);
        $this->supplierB = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTransaction && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testForeignSupplierFilterIsEmptyAndNoMembershipSeesNothing(): void
    {
        $roleId = (int) $this->db->pdo()->query("SELECT id FROM roles WHERE system_key='accountant' LIMIT 1")->fetchColumn();
        if ($roleId <= 0) $this->markTestSkipped('Chybí dynamická role accountant.');
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO users (email,password_hash,name,role_id,locale,is_active)
             VALUES (?, '\$2y\$10\$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', '__TEST Automation', ?, 'cs', 1)"
        );
        $stmt->execute(['__test_automation_' . bin2hex(random_bytes(6)) . '@example.test', $roleId]);
        $userId = (int) $this->db->pdo()->lastInsertId();

        self::assertSame([], $this->feed->allowedSupplierIds($userId, false));
        $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id,supplier_id,role_id) VALUES (?,?,NULL)')
            ->execute([$userId, $this->supplierA]);
        self::assertSame([$this->supplierA], $this->feed->allowedSupplierIds($userId, false));

        $result = $this->feed->feed($userId, false, new FeedQuery('pending', [$this->supplierB]));
        self::assertSame([], $result['items']);
        self::assertSame(0, $result['total']);
        self::assertSame([], $this->feed->counts($userId, false, null, null, [$this->supplierB])['per_supplier']);
    }

    /**
     * Token vázaný na firmu A nesmí přes kokpit automatizace číst firmy, ke kterým
     * má vlastník přístup jinak (člen více firem, globální admin). Webová relace
     * téhož uživatele vidí dál všechny své firmy.
     */
    public function testSupplierBoundTokenSeesOnlyItsCompany(): void
    {
        self::assertSame([$this->supplierA], $this->feed->allowedSupplierIds(0, true, $this->supplierA));
        self::assertSame([], $this->feed->allowedSupplierIds(0, true, 999999999));

        $action = new AutomationFeedAction($this->feed);
        $bound = fn (string $uri) => (new ServerRequestFactory())->createServerRequest('GET', $uri)
            ->withQueryParams($this->query($uri))
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 0, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => 'read', 'supplier_id' => $this->supplierA])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA);

        $counts = json_decode((string) $action->counts($bound('/api/automation/counts'), new Response())->getBody(), true);
        self::assertSame([$this->supplierA], array_column($counts['per_supplier'], 'supplier_id'));

        $foreign = '?supplier_id=' . $this->supplierB;
        self::assertSame(404, $action->stats($bound('/api/automation/stats' . $foreign), new Response())->getStatusCode());
        self::assertSame(404, $action->overview($bound('/api/automation/overview' . $foreign), new Response())->getStatusCode());
        self::assertSame(404, $action->checklist($bound('/api/automation/checklist' . $foreign), new Response())->getStatusCode());
        self::assertSame(200, $action->overview($bound('/api/automation/overview'), new Response())->getStatusCode());

        $feed = json_decode((string) $action->feed(
            $bound('/api/automation/feed?tab=needs_input&suppliers=' . $this->supplierB),
            new Response(),
        )->getBody(), true);
        self::assertSame(0, $feed['total']);
        $history = json_decode((string) $action->history(
            $bound('/api/automation/history?suppliers=' . $this->supplierB),
            new Response(),
        )->getBody(), true);
        self::assertSame(0, $history['total']);

        $session = (new ServerRequestFactory())->createServerRequest('GET', '/api/automation/counts')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 0, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        $all = json_decode((string) $action->counts($session, new Response())->getBody(), true);
        $ids = array_column($all['per_supplier'], 'supplier_id');
        self::assertContains($this->supplierA, $ids);
        self::assertContains($this->supplierB, $ids);
    }

    /** @return array<string,string> */
    private function query(string $uri): array
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        return $query;
    }

    public function testSuperadminScopeContainsAllDoubleEntrySuppliers(): void
    {
        $ids = $this->feed->allowedSupplierIds(0, true);
        self::assertContains($this->supplierA, $ids);
        self::assertContains($this->supplierB, $ids);
    }

    public function testSyntheticAiSourceIncludesKnnAndLlmOnly(): void
    {
        $userId = (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO bank_statements
                (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by)
             VALUES (?,"automation-ai.gpc",?,"1000000005","0100","CZK","2099-06-15",?)'
        );
        $statement->execute([$this->supplierA, hash('sha256', uniqid('automation-ai', true)), $userId]);
        $statementId = (int) $this->db->pdo()->lastInsertId();
        $txIds = [];
        foreach (['knn', 'llm', 'rule'] as $index => $source) {
            $this->db->pdo()->prepare(
                'INSERT INTO bank_transactions
                    (statement_id,source,posted_at,amount,currency,description,match_status)
                 VALUES (?,"statement","2099-06-15",?,"CZK",?,"unmatched")'
            )->execute([$statementId, 100 + $index, 'Zdroj ' . $source]);
            $txId = (int) $this->db->pdo()->lastInsertId();
            $txIds[$source] = $txId;
            $this->db->pdo()->prepare(
                'INSERT INTO bank_posting_suggestions
                    (supplier_id,bank_transaction_id,source,debit_account_code,credit_account_code,amount,status)
                 VALUES (?,?,?,"221","662",?,"pending")'
            )->execute([$this->supplierA, $txId, $source, 100 + $index]);
        }

        $result = $this->feed->feed(0, true, new FeedQuery('pending', [$this->supplierA], 'ai'));

        self::assertSame(2, $result['total']);
        self::assertEqualsCanonicalizing(
            [$txIds['knn'], $txIds['llm']],
            array_column(array_column($result['items'], 'refs'), 'bank_transaction_id'),
        );
    }

    public function testHttpFeedAcceptsSyntheticAiSource(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/api/automation/feed?tab=pending&source=ai',
        );
        $response = (new AutomationFeedAction($this->feed))->feed($request, new Response());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testFiltersSortingAnomalyAndSnoozeOrderAreAppliedBeforePagination(): void
    {
        $pdo = $this->db->pdo();
        $hasSnooze = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='bank_posting_suggestions'
                AND column_name='snoozed_until'"
        )->fetchColumn();
        if ($hasSnooze === 0) $this->markTestSkipped('Migrace 1081 není aplikovaná.');
        $userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by)
             VALUES (?, ?, ?, "1000000005", "0100", "CZK", "2099-08-01", ?)'
        )->execute([$this->supplierA, '__test_sort.gpc', hash('sha256', uniqid('automation-sort', true)), $userId]);
        $statementId = (int) $pdo->lastInsertId();
        $ids = [];
        foreach ([
            ['amount' => 100.0, 'confidence' => 0.91, 'note' => 'anomaly', 'snooze' => null],
            ['amount' => 200.0, 'confidence' => 0.95, 'note' => null, 'snooze' => null],
            ['amount' => 300.0, 'confidence' => 0.99, 'note' => null, 'snooze' => '2099-08-03 23:59:59'],
        ] as $index => $fixture) {
            $pdo->prepare(
                'INSERT INTO bank_transactions
                    (statement_id,source,posted_at,amount,currency,description,match_status)
                 VALUES (?,"statement","2099-08-01",?,"CZK",?,"unmatched")'
            )->execute([$statementId, -$fixture['amount'], '__TEST SORT ' . $index]);
            $txId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO bank_posting_suggestions
                    (supplier_id,bank_transaction_id,source,debit_account_code,credit_account_code,
                     amount,status,note,confidence,operation_type,snoozed_until)
                 VALUES (?, ?, "learned", "568", "221", ?, "pending", ?, ?, "bank.learned", ?)'
            )->execute([$this->supplierA, $txId, $fixture['amount'], $fixture['note'], $fixture['confidence'], $fixture['snooze']]);
            $ids[] = 'bps:' . (int) $pdo->lastInsertId();
        }

        $sorted = $this->feed->feed(0, true, new FeedQuery(
            'pending',
            [$this->supplierA],
            'learned',
            'bank.learned',
            '2099-08-01',
            '2099-08-01',
            1,
            2,
            sort: 'amount',
            direction: 'desc',
        ));
        self::assertSame(3, $sorted['total']);
        self::assertSame([$ids[0], $ids[1]], array_column($sorted['items'], 'id'), 'Anomálie je první a odložená položka až poslední.');

        $filtered = $this->feed->feed(0, true, new FeedQuery(
            'pending',
            [$this->supplierA],
            'learned',
            'bank.learned',
            '2099-08-01',
            '2099-08-01',
            1,
            50,
            minConfidence: 0.93,
            maxAmount: 250.0,
        ));
        self::assertSame([$ids[1]], array_column($filtered['items'], 'id'));
    }

    public function testPaidPurchaseAdvanceIsNotOfferedAsUnbookedDocument(): void
    {
        $pdo = $this->db->pdo();
        $vendorId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id={$this->supplierA} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id={$this->supplierA} AND code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($vendorId <= 0 || $currencyId <= 0 || $userId <= 0) {
            $this->markTestSkipped('Chybí vendor/CZK/user pro syntetické přijaté doklady.');
        }

        $before = $this->feed->counts(0, true, null, null, [$this->supplierA])['needs_input'];
        $insert = $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id,vendor_id,vendor_invoice_number,vendor_snapshot,document_kind,vat_deduction,
                 issue_date,tax_date,due_date,received_at,currency_id,reverse_charge,is_fixed_asset,
                 total_without_vat,total_vat,total_with_vat,status,created_by)
             VALUES (?,?,?,\'{}\',?,\'full\',\'2099-07-14\',\'2099-07-14\',\'2099-07-14\',\'2099-07-14\',?,0,0,100,21,121,?,?)'
        );
        $insert->execute([$this->supplierA, $vendorId, '__TEST ADVANCE', 'advance', $currencyId, 'paid', $userId]);
        $advanceId = (int) $pdo->lastInsertId();
        self::assertSame($before, $this->feed->counts(0, true, null, null, [$this->supplierA])['needs_input']);

        $insert->execute([$this->supplierA, $vendorId, '__TEST INVOICE', 'invoice', $currencyId, 'received', $userId]);
        $invoiceId = (int) $pdo->lastInsertId();
        self::assertSame($before + 1, $this->feed->counts(0, true, null, null, [$this->supplierA])['needs_input']);

        $result = $this->feed->feed(0, true, new FeedQuery(
            'needs_input', [$this->supplierA], from: '2099-07-14', to: '2099-07-14', perPage: 100,
        ));
        $ids = array_column($result['items'], 'id');
        self::assertNotContains('pi:' . $advanceId, $ids);
        self::assertContains('pi:' . $invoiceId, $ids);

        $documents = $this->feed->feed(0, true, new FeedQuery(
            'needs_input', [$this->supplierA], 'document', from: '2099-07-14', to: '2099-07-14', perPage: 100,
        ));
        self::assertNotEmpty($documents['items']);
        self::assertSame(['document'], array_values(array_unique(array_column($documents['items'], 'source'))));
    }

    public function testHistoryPagesHaveStableTotalsAndNoOverlap(): void
    {
        $pdo = $this->db->pdo();
        $before = $this->feed->history(0, true, new FeedQuery(
            'auto', [$this->supplierA], page: 1, perPage: 1,
        ))['total'];
        $userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $statement = $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by)
             VALUES (?, ?, ?, "1000000005", "0100", "CZK", "2099-07-15", ?)'
        );
        $statement->execute([
            $this->supplierA,
            '__test_history_' . bin2hex(random_bytes(4)) . '.gpc',
            hash('sha256', uniqid('automation-history', true)),
            $userId,
        ]);
        $statementId = (int) $pdo->lastInsertId();
        for ($index = 0; $index < 3; $index++) {
            $pdo->prepare(
                'INSERT INTO bank_transactions
                    (statement_id,source,posted_at,amount,currency,description,match_status)
                 VALUES (?,"statement","2099-07-15",?,"CZK",?,"unmatched")'
            )->execute([$statementId, 500 + $index, '__TEST HISTORY ' . $index]);
            $txId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO bank_posting_suggestions
                    (supplier_id,bank_transaction_id,source,debit_account_code,credit_account_code,amount,status,reviewed_at)
                 VALUES (?, ?, "rule", "221", "662", ?, "approved", NOW())'
            )->execute([$this->supplierA, $txId, 500 + $index]);
        }

        $first = $this->feed->history(0, true, new FeedQuery('auto', [$this->supplierA], page: 1, perPage: 2));
        $second = $this->feed->history(0, true, new FeedQuery('auto', [$this->supplierA], page: 2, perPage: 2));

        self::assertSame($before + 3, $first['total']);
        self::assertSame($first['total'], $second['total']);
        self::assertCount(2, $first['items']);
        self::assertEmpty(array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')));
        self::assertSame('__TEST HISTORY 2', $first['items'][0]['description']);
        self::assertSame('CZK', $first['items'][0]['currency']);
        self::assertSame('2099-07-15', $first['items'][0]['transaction_date']);
        self::assertSame($statementId, $first['items'][0]['statement_id']);
        self::assertSame(502.0, $first['items'][0]['amount']);
    }
}
