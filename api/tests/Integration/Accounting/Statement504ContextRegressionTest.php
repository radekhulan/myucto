<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\Reports\EntityCategoryService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\StatementFrameworkResolver;
use MyInvoice\Service\Accounting\Reports\StatementNotesService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class Statement504ContextRegressionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private int $supplier;
    private array $accounts = [];
    private array $periods = [];
    private array $activity;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        $seed = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $seed);
        $this->supplier = $this->createIsolatedSupplier($pdo, $seed);
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?, '1900-01-01', 'cz_504')")->execute([$this->supplier]);
        $this->activity = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2022-01-01')['activity'];
        foreach (['221' => 'asset', '022' => 'asset', '082' => 'asset', '901' => 'equity', '684' => 'revenue', '602' => 'revenue'] as $code => $type) {
            $pdo->prepare('INSERT INTO chart_of_accounts (supplier_id,account_code,name,account_type) VALUES (?,?,?,?)')
                ->execute([$this->supplier, (string) $code, 'Synthetic ' . $code, $type]);
            $this->accounts[$code] = (int) $pdo->lastInsertId();
        }
        foreach ([2022, 2023, 2024, 2099] as $year) {
            $this->periods[$year] = (new AccountingPeriodRepository($this->db))->create($this->supplier, $year, $year . '-01-01', $year . '-12-31');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    private function post(int $year, string $debit, string $credit, string $amount, string $source = 'manual', bool $classified = false): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO journal_entries (supplier_id,period_id,entry_date,source_type) VALUES (?,?,?,?)')
            ->execute([$this->supplier, $this->periods[$year], $year . '-01-01', $source]);
        $entry = (int) $pdo->lastInsertId();
        foreach (['debit' => $debit, 'credit' => $credit] as $side => $code) {
            $pdo->prepare('INSERT INTO journal_entry_lines (supplier_id,entry_id,account_id,side,amount) VALUES (?,?,?,?,?)')
                ->execute([$this->supplier, $entry, $this->accounts[$code], $side, $amount]);
            $line = (int) $pdo->lastInsertId();
            if ($classified && str_starts_with($code, '6')) {
                $pdo->prepare('INSERT INTO journal_entry_line_dimensions (supplier_id,line_id,dimension_type_id,dimension_value_id) VALUES (?,?,?,?)')
                    ->execute([$this->supplier, $line, $this->activity['id'], $this->activity['values']['main']]);
            }
        }
        $pdo->prepare('UPDATE journal_entries SET posted_at = NOW() WHERE id = ?')->execute([$entry]);
    }

    public static function nonAnnualPeriods(): iterable
    {
        yield 'six months' => ['2099-06-30', '8000000.00', 16000000.0, 'micro'];
        yield 'eighteen months' => ['2100-06-30', '45000000.00', 30000000.0, 'small'];
    }

    #[DataProvider('nonAnnualPeriods')]
    public function testAnnualTurnoverIsClassifiedOnce(string $endsOn, string $revenue, float $annualTurnover, string $expectedCategory): void
    {
        $period = $this->periods[2099];
        $this->db->pdo()->prepare('UPDATE accounting_periods SET ends_on = ? WHERE id = ?')->execute([$endsOn, $period]);
        $this->post(2099, '022', '901', '40000000.00', 'opening');
        $this->post(2099, '221', '602', $revenue, 'manual', true);
        $result = Bootstrap::buildContainer()->get(EntityCategoryService::class)->evaluate($this->supplier, $period);
        self::assertSame($annualTurnover, $result['criteria']['net_turnover']);
        self::assertSame($expectedCategory, $result['raw_current']);
    }

    private function history(): void
    {
        foreach ([2022, 2023] as $year) {
            $this->post($year, '022', '901', '12000000.00', 'opening');
            $this->post($year, '901', '082', '2000000.00', 'opening');
            $this->post($year, '221', '684', '20000000.00');
            $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periods[$year]]);
        }
        $this->post(2024, '221', '901', '1000.00', 'opening');
        $this->post(2024, '221', '602', '1000.00', 'manual', true);
    }

    public function testSupportedAutoStatementsKeepUnfrozenPre2024CategoryHistory(): void
    {
        $this->history();
        $container = Bootstrap::buildContainer();
        $statements = $container->get(FinancialStatementService::class);
        $balance = $statements->balanceSheet($this->supplier, $this->periods[2024], '2024-12-31', 'auto');
        $category = $container->get(EntityCategoryService::class)->evaluate($this->supplier, $this->periods[2024]);
        self::assertSame('micro', $category['raw_current']);
        self::assertSame('small', $category['raw_previous']);
        self::assertSame('small', $category['category']);
        self::assertSame(['assets_net' => 2000.0, 'net_turnover' => 1000.0, 'employees' => 0], $category['criteria']);
        self::assertSame('small', $balance['scope']);
        self::assertSame(2000.0, $balance['totals']['assets_end']);
        self::assertSame(serialize($balance), serialize($statements->balanceSheet($this->supplier, $this->periods[2024], null, 'auto')));
        self::assertSame(1000.0, $statements->incomeStatement($this->supplier, $this->periods[2024], '2024-12-31', 'auto')['checks']['profit_current']);
    }

    public function testSupportedNotesKeepUnfrozenPre2024CategoryHistory(): void
    {
        $this->history();
        $notes = Bootstrap::buildContainer()->get(StatementNotesService::class)->build($this->supplier, $this->periods[2024]);
        self::assertSame('small', $notes['category']);
        self::assertNotEmpty($notes['sections']);
    }

    public function testCategoryFreezeUsesRawHistoricNetAssetsAndAllRevenueWithoutActivity(): void
    {
        $this->history();
        $categories = Bootstrap::buildContainer()->get(EntityCategoryService::class);
        $categories->freeze($this->supplier, $this->periods[2023]);
        $stmt = $this->db->pdo()->prepare('SELECT assets_net, net_turnover, raw_category FROM entity_category_history WHERE supplier_id = ? AND period_id = ?');
        $stmt->execute([$this->supplier, $this->periods[2023]]);
        self::assertSame(['assets_net' => '30000000.00', 'net_turnover' => '20000000.00', 'raw_category' => 'small'], $stmt->fetch(PDO::FETCH_ASSOC));
        $categories->freeze($this->supplier, $this->periods[2024]);
        $stmt->execute([$this->supplier, $this->periods[2024]]);
        self::assertSame(['assets_net' => '2000.00', 'net_turnover' => '1000.00', 'raw_category' => 'micro'], $stmt->fetch(PDO::FETCH_ASSOC));
    }

    public function testFuture504DefaultDateClampsButExplicitInvalidDateIsRejected(): void
    {
        $this->post(2099, '221', '901', '1000.00', 'opening');
        $this->post(2099, '221', '602', '100.00', 'manual', true);
        $container = Bootstrap::buildContainer();
        $resolver = $container->get(StatementFrameworkResolver::class);
        $statements = $container->get(FinancialStatementService::class);
        foreach ([null, ''] as $asOf) {
            self::assertSame('2099-01-01', $resolver->context($this->supplier, $this->periods[2099], $asOf)['as_of']);
            self::assertSame(1100.0, $statements->balanceSheet($this->supplier, $this->periods[2099], $asOf, 'full')['totals']['assets_end']);
            self::assertSame(100.0, $statements->incomeStatement($this->supplier, $this->periods[2099], $asOf, 'full')['checks']['profit_current']);
        }
        foreach (['2098-12-31', '2100-01-01', '2099-02-30'] as $invalid) {
            $this->refused('invalid_statement_date', fn () => $resolver->context($this->supplier, $this->periods[2099], $invalid));
        }
    }

    public function testOfficialPre2024StatementsRemainUnsupported(): void
    {
        $this->history();
        $service = Bootstrap::buildContainer()->get(FinancialStatementService::class);
        $this->refused('statement_version_missing', fn () => $service->balanceSheet($this->supplier, $this->periods[2023], '2023-12-31', 'full'));
        $this->refused('statement_version_missing', fn () => $service->incomeStatement($this->supplier, $this->periods[2023], '2023-12-31', 'full'));
    }

    public function testCurrentCategoryAnnualizesAllRevenueWithoutWeakeningOfficialActivityCheck(): void
    {
        $this->db->pdo()->prepare("UPDATE accounting_periods SET ends_on = '2024-06-30' WHERE id = ?")->execute([$this->periods[2024]]);
        $this->post(2024, '221', '684', '600.00');
        $container = Bootstrap::buildContainer();
        $category = $container->get(EntityCategoryService::class)->evaluate($this->supplier, $this->periods[2024]);
        self::assertSame(600.0, $category['criteria']['assets_net']);
        self::assertSame(1200.0, $category['criteria']['net_turnover']);
        $this->refused('statement_activity_invalid', fn () => $container->get(FinancialStatementService::class)
            ->incomeStatement($this->supplier, $this->periods[2024], '2024-06-30', 'full'));
    }

    public function testSupportedCategoryStillRejectsInvalidCompanyBalanceMap(): void
    {
        $container = Bootstrap::buildContainer();
        $version = $container->get(\MyInvoice\Repository\StatementDefinitionRepository::class)->findVersion('balance_sheet', '2024-12-31', 'cz_504');
        $container->get(\MyInvoice\Repository\StatementOverrideRepository::class)->create($this->supplier, $version['id'], [
            'account_prefix' => '931', 'row_code' => 'US.9', 'target' => 'gross', 'balance_condition' => 'any', 'sign' => -1,
            'note' => 'Synthetic invalid category map',
        ], null);
        $this->refused('statement_mapping_invalid', fn () => $container->get(EntityCategoryService::class)->evaluate($this->supplier, $this->periods[2024]));
    }

    public function testFuture500DefaultRetainsOldSerializedBehavior(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework = 'cz_500' WHERE supplier_id = ?")->execute([$this->supplier]);
        $container = Bootstrap::buildContainer();
        $resolver = $container->get(StatementFrameworkResolver::class);
        $date = min('2099-12-31', date('Y-m-d'));
        self::assertSame($date, $resolver->context($this->supplier, $this->periods[2099], null)['as_of']);
        $service = $container->get(FinancialStatementService::class);
        self::assertSame(serialize($service->balanceSheet($this->supplier, $this->periods[2099], $date, 'full')),
            serialize($service->balanceSheet($this->supplier, $this->periods[2099], null, 'full')));
        self::assertSame(serialize($service->incomeStatement($this->supplier, $this->periods[2099], $date, 'full')),
            serialize($service->incomeStatement($this->supplier, $this->periods[2099], null, 'full')));
    }

    private function refused(string $code, callable $work): void
    {
        try {
            $work();
            self::fail('Expected refusal: ' . $code);
        } catch (ReportException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }
}
