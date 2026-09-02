<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\Reports\AccountStatementAction;
use MyInvoice\Action\Accounting\Reports\EntityCategoryAction;
use MyInvoice\Action\Accounting\Reports\FinancialStatementAction;
use MyInvoice\Action\Accounting\Reports\GeneralLedgerAction;
use MyInvoice\Action\Accounting\Reports\ReportingSettingsAction;
use MyInvoice\Action\Accounting\Reports\TrialBalanceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Integrační testy REST API účetních sestav (Epic F2, T16, vzor AccountingApiTest):
 * GET endpointy vrací 200 + tvar odpovědi, cizí supplier → 404, chybějící
 * period_id → 422, RBAC (readonly smí GET, PUT reporting-settings jen
 * admin/accountant) a exporty (Content-Type pro pdf|xlsx, 422 na csv).
 *
 * Vše běží v jedné transakci, kterou tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class ReportsApiTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private GeneralLedgerAction $generalLedgerAction;
    private TrialBalanceAction $trialBalanceAction;
    private AccountStatementAction $accountStatementAction;
    private FinancialStatementAction $financialStatementAction;
    private EntityCategoryAction $entityCategoryAction;
    private ReportingSettingsAction $reportingSettingsAction;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db                       = $container->get(Connection::class);
            $this->generalLedgerAction      = $container->get(GeneralLedgerAction::class);
            $this->trialBalanceAction       = $container->get(TrialBalanceAction::class);
            $this->accountStatementAction   = $container->get(AccountStatementAction::class);
            $this->financialStatementAction = $container->get(FinancialStatementAction::class);
            $this->entityCategoryAction     = $container->get(EntityCategoryAction::class);
            $this->reportingSettingsAction  = $container->get(ReportingSettingsAction::class);
            $posting                        = $container->get(PostingService::class);
            $periods                        = $container->get(AccountingPeriodRepository::class);
            $seeder                         = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }
        $hasSeed = (int) $pdo->query(
            "SELECT COUNT(*) FROM statement_versions WHERE version_code = 'vyhl500-2002/2024'"
        )->fetchColumn();
        if ($hasSeed < 2) {
            $this->markTestSkipped('Seed výkazů 1012 není aplikovaný (statement_versions).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        // Izolovaný supplier (kopie FK hodnot z prvního): kumulativní PS rozvahových
        // účtů (R6) jde přes celou historii deníku, sdílený dev supplier s reálnými
        // zápisy by rozbil bilanční asserty a previousPeriod.
        $isoStmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id, "double_entry"
               FROM supplier WHERE id = ?'
        );
        $isoStmt->execute(['Izolovaný test s.r.o.', 'izolace@example.com', $this->supplierId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');

        // jeden zaúčtovaný zápis, ať mají sestavy data
        $posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1210.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 1000.00],
            ['account_code' => '343', 'side' => 'credit', 'amount' => 210.00],
        ], ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId]);
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

    // ── GET endpointy (readonly smí číst) ──────────────────────────────────

    public function testGeneralLedgerReturnsData(): void
    {
        $res = $this->call($this->generalLedgerAction, 'get', 'GET', 'readonly', ['period_id' => (string) $this->periodId]);
        self::assertSame(200, $res['status']);
        self::assertArrayHasKey('accounts', $res['body']);
        self::assertArrayHasKey('months', $res['body']);
        self::assertArrayHasKey('totals', $res['body']);
        self::assertArrayHasKey('draft_count', $res['body']);
        self::assertNotEmpty($res['body']['accounts']);
    }

    public function testGeneralLedgerReturnsAllPeriodsWithoutPeriodId(): void
    {
        $res = $this->call($this->generalLedgerAction, 'get', 'GET', 'readonly', ['all_periods' => '1']);

        self::assertSame(200, $res['status']);
        self::assertTrue($res['body']['all_periods']);
        self::assertNull($res['body']['period']);
        self::assertSame(self::YEAR . '-01-01', $res['body']['from']);
        self::assertSame(self::YEAR . '-12-31', $res['body']['to']);
        self::assertNotEmpty($res['body']['accounts']);
    }

    public function testTrialBalanceReturnsChecks(): void
    {
        $res = $this->call($this->trialBalanceAction, 'get', 'GET', 'readonly', ['period_id' => (string) $this->periodId]);
        self::assertSame(200, $res['status']);
        self::assertArrayHasKey('rows', $res['body']);
        self::assertArrayHasKey('totals', $res['body']);
        self::assertArrayHasKey('checks', $res['body']);
        self::assertTrue($res['body']['checks']['turnover_balanced']);
        self::assertTrue($res['body']['checks']['matches_journal']);
    }

    public function testAccountStatementReturnsItems(): void
    {
        $accountId = $this->accountId('311');
        $res = $this->call($this->accountStatementAction, 'get', 'GET', 'readonly',
            ['from' => self::YEAR . '-01-01', 'to' => self::YEAR . '-12-31'],
            ['accountId' => (string) $accountId]);
        self::assertSame(200, $res['status']);
        self::assertSame('311', $res['body']['account']['code']);
        self::assertArrayHasKey('items', $res['body']);
        self::assertArrayHasKey('opening_balance', $res['body']);
        self::assertArrayHasKey('closing_balance', $res['body']);
        self::assertCount(1, $res['body']['items']);
    }

    public function testBalanceSheetAndIncomeStatementReturnData(): void
    {
        $query = ['period_id' => (string) $this->periodId, 'as_of' => self::YEAR . '-12-31', 'scope' => 'full'];

        $bs = $this->call($this->financialStatementAction, 'balanceSheet', 'GET', 'readonly', $query);
        self::assertSame(200, $bs['status']);
        self::assertSame('balance_sheet', $bs['body']['statement_type']);
        self::assertSame('full', $bs['body']['scope']);
        self::assertArrayHasKey('assets', $bs['body']);
        self::assertArrayHasKey('liabilities', $bs['body']);
        self::assertArrayHasKey('checks', $bs['body']);
        self::assertArrayHasKey('entity', $bs['body']);
        self::assertTrue($bs['body']['checks']['balanced']);

        $is = $this->call($this->financialStatementAction, 'incomeStatement', 'GET', 'readonly', $query);
        self::assertSame(200, $is['status']);
        self::assertSame('income_statement', $is['body']['statement_type']);
        self::assertArrayHasKey('rows', $is['body']);
        self::assertNotEmpty($is['body']['rows']);
    }

    public function testEntityCategoryReturnsData(): void
    {
        $res = $this->call($this->entityCategoryAction, 'get', 'GET', 'readonly', ['period_id' => (string) $this->periodId]);
        self::assertSame(200, $res['status']);
        self::assertArrayHasKey('category', $res['body']);
        self::assertArrayHasKey('criteria', $res['body']);
        self::assertArrayHasKey('thresholds', $res['body']);
        self::assertArrayHasKey('scope', $res['body']);
    }

    // ── validace a tenant izolace ──────────────────────────────────────────

    public function testMissingPeriodIdReturns422(): void
    {
        foreach ([
            [$this->trialBalanceAction, 'get'],
            [$this->generalLedgerAction, 'get'],
            [$this->financialStatementAction, 'balanceSheet'],
            [$this->entityCategoryAction, 'get'],
        ] as [$action, $method]) {
            $res = $this->call($action, $method, 'GET', 'readonly');
            self::assertSame(422, $res['status'], $action::class . '::' . $method . ' bez period_id → 422.');
            self::assertSame('validation_failed', $res['body']['error']['code']);
        }
    }

    /**
     * D4 (audit 2026-07): as_of mimo hranice zvoleného období → 422 (analogicky ke
     * kontrole from/to v GeneralLedgerAction). Platný as_of uvnitř období → 200.
     */
    public function testBalanceSheetAsOfMustBeWithinPeriod(): void
    {
        // as_of před začátkem období
        $before = $this->call($this->financialStatementAction, 'balanceSheet', 'GET', 'readonly',
            ['period_id' => (string) $this->periodId, 'as_of' => (self::YEAR - 1) . '-12-31']);
        self::assertSame(422, $before['status'], 'as_of před obdobím → 422.');
        self::assertSame('validation_failed', $before['body']['error']['code']);

        // as_of po konci období
        $after = $this->call($this->financialStatementAction, 'balanceSheet', 'GET', 'readonly',
            ['period_id' => (string) $this->periodId, 'as_of' => (self::YEAR + 1) . '-01-01']);
        self::assertSame(422, $after['status'], 'as_of po období → 422.');

        // stejná kontrola i pro výsledovku
        $isOut = $this->call($this->financialStatementAction, 'incomeStatement', 'GET', 'readonly',
            ['period_id' => (string) $this->periodId, 'as_of' => (self::YEAR + 1) . '-01-01']);
        self::assertSame(422, $isOut['status'], 'as_of mimo období platí i pro VZZ.');

        // as_of uvnitř období → 200
        $ok = $this->call($this->financialStatementAction, 'balanceSheet', 'GET', 'readonly',
            ['period_id' => (string) $this->periodId, 'as_of' => self::YEAR . '-06-30']);
        self::assertSame(200, $ok['status'], 'as_of uvnitř období projde.');
    }

    public function testForeignSupplierReturns404(): void
    {
        $foreign = $this->cloneForeignSupplier();

        $res = $this->call($this->trialBalanceAction, 'get', 'GET', 'readonly',
            ['period_id' => (string) $this->periodId], [], [], $foreign);
        self::assertSame(404, $res['status'], 'Cizí supplier nevidí období (ownership = 404).');
        self::assertSame('not_found', $res['body']['error']['code']);

        $accountId = $this->accountId('311');
        $res2 = $this->call($this->accountStatementAction, 'get', 'GET', 'readonly',
            ['from' => self::YEAR . '-01-01', 'to' => self::YEAR . '-12-31'],
            ['accountId' => (string) $accountId], [], $foreign);
        self::assertSame(404, $res2['status'], 'Cizí supplier nevidí účet osnovy.');
    }

    // ── RBAC reporting-settings ────────────────────────────────────────────

    public function testReportingSettingsRbac(): void
    {
        $get = $this->call($this->reportingSettingsAction, 'get', 'GET', 'readonly');
        self::assertSame(200, $get['status'], 'Readonly smí číst nastavení.');
        self::assertArrayHasKey('avg_employees', $get['body']);
        self::assertArrayHasKey('statement_scope_override', $get['body']);

        $denied = $this->call($this->reportingSettingsAction, 'update', 'PUT', 'readonly', [], [],
            ['avg_employees' => 5]);
        self::assertSame(403, $denied['status'], 'Readonly nesmí zapisovat nastavení.');

        $ok = $this->call($this->reportingSettingsAction, 'update', 'PUT', 'accountant', [], [],
            ['avg_employees' => 42, 'statement_scope_override' => 'micro']);
        self::assertSame(200, $ok['status'], 'Účetní smí zapisovat nastavení.');
        self::assertSame(42, (int) $ok['body']['avg_employees']);
        self::assertSame('micro', $ok['body']['statement_scope_override']);

        $admin = $this->call($this->reportingSettingsAction, 'update', 'PUT', 'admin', [], [],
            ['avg_employees' => null, 'statement_scope_override' => null]);
        self::assertSame(200, $admin['status'], 'Admin smí zapisovat nastavení.');
        self::assertNull($admin['body']['avg_employees']);

        $invalid = $this->call($this->reportingSettingsAction, 'update', 'PUT', 'accountant', [], [],
            ['statement_scope_override' => 'huge']);
        self::assertSame(422, $invalid['status']);
        self::assertSame('validation_failed', $invalid['body']['error']['code']);
    }

    /**
     * Task 14: účetní politika časového rozlišení drobného majetku (§DM) se ukládá
     * přes reporting-settings PUT bez nutnosti spouštět uzávěrku a čte se zpět v GET.
     */
    public function testReportingSettingsPersistsSmallAssetAccrualPolicy(): void
    {
        // flat_pct s procentem → uloží mode i pct
        $flat = $this->call($this->reportingSettingsAction, 'update', 'PUT', 'accountant', [], [],
            ['small_asset_accrual_mode' => 'flat_pct', 'small_asset_accrual_pct' => 40]);
        self::assertSame(200, $flat['status']);
        self::assertSame('flat_pct', $flat['body']['small_asset_accrual_mode']);
        self::assertEqualsWithDelta(40.0, (float) $flat['body']['small_asset_accrual_pct'], 0.001);

        $get = $this->call($this->reportingSettingsAction, 'get', 'GET', 'readonly');
        self::assertSame('flat_pct', $get['body']['small_asset_accrual_mode']);
        self::assertEqualsWithDelta(40.0, (float) $get['body']['small_asset_accrual_pct'], 0.001);

        // přepnutí na pro_rata → pct se vynuluje na NULL
        $pro = $this->call($this->reportingSettingsAction, 'update', 'PUT', 'accountant', [], [],
            ['small_asset_accrual_mode' => 'pro_rata']);
        self::assertSame(200, $pro['status']);
        self::assertSame('pro_rata', $pro['body']['small_asset_accrual_mode']);
        self::assertNull($pro['body']['small_asset_accrual_pct']);

        // flat_pct bez procenta → 422
        $noPct = $this->call($this->reportingSettingsAction, 'update', 'PUT', 'accountant', [], [],
            ['small_asset_accrual_mode' => 'flat_pct']);
        self::assertSame(422, $noPct['status']);

        // procento mimo rozsah → 422
        $badPct = $this->call($this->reportingSettingsAction, 'update', 'PUT', 'accountant', [], [],
            ['small_asset_accrual_mode' => 'flat_pct', 'small_asset_accrual_pct' => 150]);
        self::assertSame(422, $badPct['status']);

        // neznámý režim → 422
        $badMode = $this->call($this->reportingSettingsAction, 'update', 'PUT', 'accountant', [], [],
            ['small_asset_accrual_mode' => 'weird']);
        self::assertSame(422, $badMode['status']);
    }

    // ── exporty ────────────────────────────────────────────────────────────

    public function testExportContentTypesAndFormatWhitelist(): void
    {
        $query = ['period_id' => (string) $this->periodId];

        $xlsx = $this->raw($this->trialBalanceAction, 'export', 'GET', 'readonly',
            $query + ['format' => 'xlsx']);
        self::assertSame(200, $xlsx->getStatusCode());
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $xlsx->getHeaderLine('Content-Type'),
        );
        self::assertStringContainsString('obratova-predvaha', $xlsx->getHeaderLine('Content-Disposition'));

        $pdf = $this->raw($this->trialBalanceAction, 'export', 'GET', 'readonly',
            $query + ['format' => 'pdf']);
        self::assertSame(200, $pdf->getStatusCode());
        self::assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        $pdf->getBody()->rewind();
        self::assertStringStartsWith('%PDF', (string) $pdf->getBody(), 'Bytes jsou skutečné PDF.');

        $csv = $this->call($this->trialBalanceAction, 'export', 'GET', 'readonly',
            $query + ['format' => 'csv']);
        self::assertSame(422, $csv['status'], 'format=csv není ve whitelistu.');
        self::assertSame('validation_failed', $csv['body']['error']['code']);
    }

    public function testBalanceSheetExportXlsx(): void
    {
        $res = $this->raw($this->financialStatementAction, 'exportBalanceSheet', 'GET', 'readonly',
            ['period_id' => (string) $this->periodId, 'as_of' => self::YEAR . '-12-31', 'scope' => 'full', 'format' => 'xlsx']);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $res->getHeaderLine('Content-Type'),
        );
        self::assertStringContainsString('rozvaha', $res->getHeaderLine('Content-Disposition'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function accountId(string $code): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $stmt->execute([$this->supplierId, $code]);
        return (int) $stmt->fetchColumn();
    }

    /** Skutečný (nikoli neexistující) druhý double_entry supplier — od G6 gate
     *  (accounting_mode) 403uje neexistující/tax_evidence tenanta dřív, než se
     *  stihne vyhodnotit ownership 404 (viz Stock modul precedent, GuardsStockEnabled). */
    private function cloneForeignSupplier(): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id, "double_entry"
               FROM supplier WHERE id = ?'
        );
        $stmt->execute(['Cizí tenant s.r.o.', 'foreign-' . uniqid() . '@example.com', $this->supplierId]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string,string> $query
     * @param array<string,string> $args
     * @param array<string,mixed>  $body
     */
    private function raw(object $action, string $method, string $httpMethod, string $role, array $query = [], array $args = [], array $body = [], ?int $supplierId = null): ResponseInterface
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/accounting/reports')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId ?? $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        return $args === []
            ? $action->{$method}($req, new Psr7Response())
            : $action->{$method}($req, new Psr7Response(), $args);
    }

    /**
     * @param array<string,string> $query
     * @param array<string,string> $args
     * @param array<string,mixed>  $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(object $action, string $method, string $httpMethod, string $role, array $query = [], array $args = [], array $body = [], ?int $supplierId = null): array
    {
        $resp = $this->raw($action, $method, $httpMethod, $role, $query, $args, $body, $supplierId);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
