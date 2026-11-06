<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Report\MonthlyReportService;
use PHPUnit\Framework\TestCase;

final class MonthlyReport504Test extends TestCase
{
    private \PDO $pdo;
    private int $supplier;
    private int $period;
    private array $accounts;
    private array $activity;

    protected function setUp(): void
    {
        $c = Bootstrap::buildContainer();
        $this->pdo = $c->get(Connection::class)->pdo();
        $this->pdo->beginTransaction();
        $this->pdo->exec("INSERT INTO supplier (company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Synthetic monthly 504','Test 1','Test','10000','monthly504@example.com',
                (SELECT id FROM countries WHERE iso2='CZ' LIMIT 1),(SELECT id FROM currencies WHERE code='CZK' LIMIT 1),
                (SELECT id FROM vat_rates ORDER BY id LIMIT 1),'double_entry'");
        $this->supplier = (int) $this->pdo->lastInsertId();
        $this->period = $c->get(AccountingPeriodRepository::class)->create($this->supplier, 2025, '2024-07-01', '2025-06-30');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?,'2024-07-01','cz_504')")->execute([$this->supplier]);
        $this->activity = $c->get(SystemDimensionCatalog::class)->ensureForFramework($this->supplier, '2024-07-01')['activity'];
        $this->accounts = [];
        foreach (['221'=>'asset','931'=>'equity','518'=>'expense','602'=>'revenue'] as $code=>$type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts (supplier_id,account_code,name,account_type) VALUES (?,?,?,?)')->execute([$this->supplier,(string)$code,'Synthetic '.$code,$type]);
            $this->accounts[$code] = (int) $this->pdo->lastInsertId();
        }
        $this->post('2024-12-15', '221', '602', '100.01', 'main');
        $this->post('2025-01-15', '221', '602', '20.02', 'main');
        $this->post('2025-01-15', '221', '602', '30.03', 'economic');
        $this->post('2025-01-15', '518', '221', '50.06', 'main');
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testMonthUsesActualActivityDifferenceAcrossFiscalYear(): void
    {
        $data = Bootstrap::buildContainer()->get(MonthlyReportService::class)->build($this->supplier, 2025, 1);
        $month = array_column($data['income_statement_month'], null, 'row_code')['UT.63'];
        self::assertSame(-30.04, $month['main']);
        self::assertSame(30.03, $month['economic']);
        self::assertSame(-0.01, $month['total']);
        self::assertSame(100.0, $data['income_statement_ytd']['checks']['profit_current']);
        self::assertSame('cz_504', $data['accounting_framework']);
        self::assertSame('2024-07-01', $data['income_statement_ytd']['period']['starts_on']);
    }

    public function testLegitimatePendingResultRemainsVisibleInMonthlyManagementBalance(): void
    {
        $this->post('2024-07-01', '221', '931', '1000.00', null, 'opening');
        $data = Bootstrap::buildContainer()->get(MonthlyReportService::class)->build($this->supplier, 2025, 1);
        self::assertSame('management', $data['balance_sheet']['view_kind']);
        self::assertSame(1000.0, $data['balance_sheet']['checks']['unresolved_pending_result']);
        self::assertSame(0.0, $data['balance_sheet']['checks']['reconciliation_difference']);
        self::assertSame(-1000.0, array_column($data['balance_sheet']['balances'], null, 'code')['931']['closing_debit_balance']);
    }

    public function testFirstFiscalMonthIsExactlyItsYtdRows(): void
    {
        $this->post('2024-07-31', '221', '602', '7.01', 'economic');
        $data = Bootstrap::buildContainer()->get(MonthlyReportService::class)->build($this->supplier, 2024, 7);
        self::assertSame($data['income_statement_ytd']['rows'], $data['income_statement_month']);
        self::assertSame(7.01, array_column($data['income_statement_month'], null, 'row_code')['UT.63']['total']);
    }

    public function testUnclassifiedPostedActivityCannotBecomeZeroOrMainActivity(): void
    {
        $this->post('2025-01-20', '221', '602', '5.00', null);
        try {
            Bootstrap::buildContainer()->get(MonthlyReportService::class)->build($this->supplier, 2025, 1);
            self::fail('Missing posted activity must prevent the report.');
        } catch (\MyInvoice\Service\Accounting\Reports\ReportException $e) {
            self::assertSame('statement_activity_invalid', $e->errorCode);
        }
    }

    private function post(string $date, string $debit, string $credit, string $amount, ?string $activity, string $source='manual'): void
    {
        $this->pdo->prepare('INSERT INTO journal_entries (supplier_id,period_id,entry_date,source_type) VALUES (?,?,?,?)')->execute([$this->supplier,$this->period,$date,$source]);
        $entry = (int) $this->pdo->lastInsertId();
        foreach (['debit'=>$debit,'credit'=>$credit] as $side=>$code) {
            $this->pdo->prepare('INSERT INTO journal_entry_lines (supplier_id,entry_id,account_id,side,amount) VALUES (?,?,?,?,?)')->execute([$this->supplier,$entry,$this->accounts[$code],$side,$amount]);
            if ($activity !== null && in_array($code[0], ['5','6'], true)) {
                $this->pdo->prepare('INSERT INTO journal_entry_line_dimensions (supplier_id,line_id,dimension_type_id,dimension_value_id) VALUES (?,?,?,?)')
                    ->execute([$this->supplier,(int)$this->pdo->lastInsertId(),$this->activity['id'],$this->activity['values'][$activity]]);
            }
        }
        $this->pdo->prepare('UPDATE journal_entries SET posted_at=NOW() WHERE id=?')->execute([$entry]);
    }
}
