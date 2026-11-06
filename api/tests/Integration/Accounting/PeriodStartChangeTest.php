<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\AccountingPeriodAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Začátek prvního účetního období firmy vzniklé v průběhu roku (§ 3 ZoÚ, #227):
 * automat založil 1. 1. – 31. 12., první období ale začíná dnem vzniku.
 *
 * Izolovaný supplier, transakce s rollbackem v tearDown, soft-skip bez cfg.php.
 */
#[Group('integration')]
final class PeriodStartChangeTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private AccountingPeriodAction $action;
    private AccountingPeriodRepository $periods;
    private PostingService $posting;

    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db      = $container->get(Connection::class);
            $this->action  = $container->get(AccountingPeriodAction::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->posting = $container->get(PostingService::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId   = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId    = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId         = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "period-start@example.com", ?, ?, "double_entry")'
        );
        $stmt->execute(['Vznik v průběhu roku s.r.o.', $czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
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

    public function testFirstPeriodStartsOnDayOfIncorporation(): void
    {
        $id = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31', 'setup');
        $this->db->pdo()->prepare('UPDATE supplier SET accounting_starts_on = ? WHERE id = ?')
            ->execute([self::YEAR . '-01-01', $this->supplierId]);

        $res = $this->patch($id, self::YEAR . '-07-21');

        self::assertSame(200, $res['status'], json_encode($res['body']));
        $period = $this->periods->findById($this->supplierId, $id);
        self::assertSame(self::YEAR . '-07-21', $period['starts_on']);
        self::assertSame(self::YEAR . '-12-31', $period['ends_on']);
        self::assertSame(self::YEAR, (int) $period['fiscal_year']);
        $startsOn = $this->db->pdo()->prepare('SELECT accounting_starts_on FROM supplier WHERE id = ?');
        $startsOn->execute([$this->supplierId]);
        self::assertSame(self::YEAR . '-07-21', $startsOn->fetchColumn());

        // Den před vznikem do žádného období nepatří, takže se do něj nedá účtovat.
        $this->expectException(PostingException::class);
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1000.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 1000.00],
        ], ['entry_date' => self::YEAR . '-07-20', 'posted_by' => $this->userId]);
    }

    public function testEntryBeforeNewStartBlocksChange(): void
    {
        $id = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1000.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 1000.00],
        ], ['entry_date' => self::YEAR . '-03-01', 'posted_by' => $this->userId]);

        $res = $this->patch($id, self::YEAR . '-07-21');

        self::assertSame(409, $res['status']);
        self::assertSame('entries_before_start', $res['body']['error']['code']);
        self::assertSame(1, $res['body']['error']['entries_count']);
        self::assertSame(self::YEAR . '-03-01', $res['body']['error']['first_entry_date']);
        self::assertSame(self::YEAR . '-01-01', $this->periods->findById($this->supplierId, $id)['starts_on']);
        // Zápis v den nového začátku a po něm změnu nebrání.
        self::assertSame(200, $this->patch($id, self::YEAR . '-03-01')['status']);
    }

    public function testOnlyFirstOpenPeriodInSameYear(): void
    {
        $first = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $next = $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');

        $res = $this->patch($next, (self::YEAR + 1) . '-02-01');
        self::assertSame(422, $res['status']);
        self::assertSame('not_first_period', $res['body']['error']['code']);

        $res = $this->patch($first, (self::YEAR - 1) . '-12-01');
        self::assertSame(422, $res['status']);
        self::assertSame('validation_failed', $res['body']['error']['code']);

        $res = $this->patch($first, self::YEAR . '-12-31');
        self::assertSame(422, $res['status']);

        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$first]);
        $res = $this->patch($first, self::YEAR . '-07-21');
        self::assertSame(422, $res['status']);
        self::assertSame('period_not_open', $res['body']['error']['code']);
    }

    public function testStaleRowVersionIsRejected(): void
    {
        $id = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $stale = $this->rowVersion($id);
        $this->db->pdo()->prepare('UPDATE accounting_periods SET row_version = row_version + 1 WHERE id = ?')->execute([$id]);

        $res = $this->patch($id, self::YEAR . '-07-21', $stale);

        self::assertSame(409, $res['status']);
        self::assertSame('version_conflict', $res['body']['error']['code']);
        self::assertSame(self::YEAR . '-01-01', $this->periods->findById($this->supplierId, $id)['starts_on']);
    }

    /**
     * Hospodářský rok pozná firma z tvaru období. Zkrácené první období ten tvar
     * nemá, takže bez nastavení firmy by se z ní stala firma s kalendářním rokem.
     */
    public function testFiscalYearCompanyNeedsStartMonthSetting(): void
    {
        $id = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30');

        $res = $this->patch($id, self::YEAR . '-07-21');
        self::assertSame(422, $res['status']);
        self::assertSame('fiscal_calendar_changed', $res['body']['error']['code']);
        self::assertSame(self::YEAR . '-07-01', $this->periods->findById($this->supplierId, $id)['starts_on']);

        $this->db->pdo()->prepare('UPDATE supplier SET fiscal_year_start_month = 7 WHERE id = ?')->execute([$this->supplierId]);
        self::assertSame(200, $this->patch($id, self::YEAR . '-07-21')['status']);
        self::assertSame(7, $this->periods->fiscalCalendar($this->supplierId)->startMonth());
    }

    private function rowVersion(int $id): int
    {
        return (int) $this->periods->findById($this->supplierId, $id)['row_version'];
    }

    /**
     * @return array{status:int, body:array<string,mixed>}
     */
    private function patch(int $id, string $startsOn, ?int $rowVersion = null): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/api/accounting/periods/' . $id)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody(['starts_on' => $startsOn, 'row_version' => $rowVersion ?? $this->rowVersion($id)]);
        $resp = $this->action->updateStart($req, new Psr7Response(), ['id' => (string) $id]);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
