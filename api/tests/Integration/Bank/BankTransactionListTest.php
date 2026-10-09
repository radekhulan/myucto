<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Accounting\Bank\BankPostingSuggestionAction;
use MyInvoice\Action\Bank\BankTransactionListAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * #136: přehled „Všechny pohyby" (GET /api/bank-transactions) je bankovní přehled,
 * ne účetní funkce. Firma v daňové evidenci ho čte (dřív 403 z účetního endpointu),
 * dostane jen vlastní pohyby a žádný stav zaúčtování. Podvojné účetnictví dostane
 * totéž co z účetního endpointu, který zůstává jen pro double_entry.
 *
 * DB běží v transakci (rollback v tearDown).
 */
#[Group('integration')]
final class BankTransactionListTest extends TestCase
{
    private const YEAR = 2096;
    private const ACCOUNT = '9990136185';
    private const MARK = '__TEST-ISSUE136';

    private Connection $db;
    private BankTransactionListAction $list;
    private BankPostingSuggestionAction $accounting;
    private JournalEntryRepository $journal;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;

    private int $supplierId = 0;
    private int $userId = 0;
    /** @var array<string,int> */
    private array $tx = [];
    private int $foreignTx = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->db->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        // Akce mimo try: chybějící endpoint musí test shodit, ne tiše přeskočit.
        $this->list       = $container->get(BankTransactionListAction::class);
        $this->accounting = $container->get(BankPostingSuggestionAction::class);
        $this->journal    = $container->get(JournalEntryRepository::class);
        $this->periods    = $container->get(AccountingPeriodRepository::class);
        $this->seeder     = $container->get(ChartOfAccountsSeeder::class);

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/uživatel v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $statementId = $this->addStatement($this->supplierId, self::ACCOUNT);
        $this->tx['income'] = $this->addTx($statementId, self::YEAR . '-04-01', 1200.0, 'Odběratel');
        $this->tx['expense'] = $this->addTx($statementId, self::YEAR . '-05-02', -300.0, 'Dodavatel');
        $this->db->pdo()->prepare("UPDATE bank_transactions SET match_status = 'ignored' WHERE id = ?")
            ->execute([$this->tx['expense']]);

        // Cizí firma se STEJNÝM číslem účtu a stejnou značkou v popisu — do přehledu nesmí prosáknout.
        $foreign = $this->cloneSupplier();
        $this->foreignTx = $this->addTx($this->addStatement($foreign, self::ACCOUNT), self::YEAR . '-04-03', 999.0, 'Cizí');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testTaxEvidenceCompanyReadsAllOwnMovementsWithoutAccountingData(): void
    {
        $this->setMode('tax_evidence');

        $res = $this->movements(['q' => self::MARK]);
        self::assertSame(200, $res['status']);
        $ids = array_column($res['body']['items'], 'id');
        sort($ids);
        $expected = [$this->tx['income'], $this->tx['expense']];
        sort($expected);
        // Celý přehled: i ignorovaný pohyb, ale nic z cizí firmy.
        self::assertSame($expected, $ids);
        self::assertNotContains($this->foreignTx, $ids);
        self::assertSame(2, $res['body']['total']);
        self::assertSame('all', $res['body']['scope']);
        self::assertContains(self::YEAR, $res['body']['years']);
        foreach ($res['body']['items'] as $item) {
            self::assertNull($item['posting'], 'Mimo podvojné účetnictví žádný stav zaúčtování.');
            self::assertFalse($item['period_closed'], 'Daňová evidence nemá účetní období.');
        }

        // Filtry přehledu fungují i tady; zaúčtování se ignoruje.
        $byStatus = $this->movements(['q' => self::MARK, 'status' => 'ignored']);
        self::assertSame([$this->tx['expense']], array_column($byStatus['body']['items'], 'id'));
        $byYear = $this->movements(['q' => self::MARK, 'year' => (string) (self::YEAR - 1)]);
        self::assertSame(0, $byYear['body']['total']);
        $byAccount = $this->movements(['q' => self::MARK, 'account' => '000000' . self::ACCOUNT]);
        self::assertSame(2, $byAccount['body']['total']);
        $posting = $this->movements(['q' => self::MARK, 'posting_status' => 'posted']);
        self::assertSame(2, $posting['body']['total'], 'Filtr zaúčtování v daňové evidenci nic neodfiltruje.');
        $sorted = $this->movements(['q' => self::MARK, 'sort' => 'amount', 'direction' => 'asc']);
        self::assertSame([$this->tx['expense'], $this->tx['income']], array_column($sorted['body']['items'], 'id'));

        // Účetní fronta zůstává jen pro podvojné účetnictví.
        $queue = $this->decode($this->accounting->unposted($this->request(['scope' => 'unposted']), new Psr7Response()));
        self::assertSame(403, $queue['status']);
        $count = $this->decode($this->accounting->unpostedCount($this->request([]), new Psr7Response()));
        self::assertSame(403, $count['status']);
    }

    public function testDoubleEntryGetsPostingStateAndSameResultAsAccountingEndpoint(): void
    {
        $this->setMode('double_entry');
        $this->seeder->seedForSupplier($this->supplierId);
        $periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $accountId = (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '221' LIMIT 1"
        )->fetchColumn();
        if ($accountId === 0) {
            $this->markTestSkipped('Osnova nemá účet 221.');
        }
        $entryId = $this->journal->insert(
            [
                'supplier_id' => $this->supplierId, 'period_id' => $periodId, 'entry_date' => self::YEAR . '-04-01',
                'source_type' => 'bank', 'source_id' => $this->tx['income'],
                'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => $this->userId,
            ],
            [
                ['account_id' => $accountId, 'side' => 'debit', 'amount' => 1200.0],
                ['account_id' => $accountId, 'side' => 'credit', 'amount' => 1200.0],
            ],
        );

        $res = $this->movements(['q' => self::MARK]);
        self::assertSame(200, $res['status']);
        $byId = array_column($res['body']['items'], null, 'id');
        self::assertNotContains($this->foreignTx, array_keys($byId));
        self::assertSame('posted', $byId[$this->tx['income']]['posting']['status']);
        self::assertSame($entryId, $byId[$this->tx['income']]['posting']['journal_entry_id']);

        $posted = $this->movements(['q' => self::MARK, 'posting_status' => 'posted']);
        self::assertSame([$this->tx['income']], array_column($posted['body']['items'], 'id'));

        // Účetní endpoint se scope=all vrací totéž, fronta jen nezaúčtované a neignorované.
        $legacy = $this->decode($this->accounting->unposted($this->request(['q' => self::MARK, 'scope' => 'all']), new Psr7Response()));
        self::assertSame(200, $legacy['status']);
        self::assertSame($res['body'], $legacy['body']);
        $queue = $this->decode($this->accounting->unposted($this->request(['q' => self::MARK]), new Psr7Response()));
        self::assertSame([], array_column($queue['body']['items'], 'id'));
    }

    public function testMissingSupplierIsRejected(): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/api/bank-transactions')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
        self::assertSame(400, $this->decode(($this->list)($req, new Psr7Response()))['status']);
    }

    private function setMode(string $mode): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET accounting_mode = ? WHERE id = ?')->execute([$mode, $this->supplierId]);
    }

    private function addStatement(int $supplierId, string $account): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO bank_statements
                (supplier_id, source, file_name, file_hash, account_number, bank_code, currency,
                 statement_number, statement_date, prev_balance, curr_balance, transaction_count, imported_by)
             VALUES (?, 'gpc', ?, ?, ?, '0100', 'CZK', '1', ?, 0, 0, 2, NULL)"
        )->execute([
            $supplierId,
            self::MARK . '.gpc',
            hash('sha256', self::MARK . $supplierId . uniqid('', true)),
            $account,
            self::YEAR . '-05-31',
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function addTx(int $statementId, string $date, float $amount, string $counterparty): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, counterparty_account, counterparty_name,
                 description, import_fingerprint)
             VALUES (?, ?, ?, 'CZK', '1000000005', ?, ?, ?)"
        )->execute([
            $statementId, $date, $amount, $counterparty, self::MARK . ' ' . $counterparty,
            hash('sha256', self::MARK . $counterparty . uniqid('', true)),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function cloneSupplier(): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO supplier
                (company_name,display_name,street,city,zip,country_id,is_vat_payer,email,
                 default_currency_id,default_vat_rate_id,default_payment_due_days,default_hourly_rate,accounting_mode)
             SELECT '__TEST ISSUE136 B','__TEST ISSUE136 B',street,city,zip,country_id,0,
                    CONCAT('issue136-', id, '-', UNIX_TIMESTAMP(), '@example.test'),
                    default_currency_id,default_vat_rate_id,default_payment_due_days,default_hourly_rate,'tax_evidence'
               FROM supplier WHERE id=?"
        )->execute([$this->supplierId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param array<string,string> $query
     * @return array{status:int, body:array<string,mixed>}
     */
    private function movements(array $query): array
    {
        return $this->decode(($this->list)($this->request($query), new Psr7Response()));
    }

    /** @param array<string,string> $query */
    private function request(array $query): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/bank-transactions')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function decode(ResponseInterface $resp): array
    {
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
