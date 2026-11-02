<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use PHPUnit\Framework\TestCase;

final class Statement504Test extends TestCase
{
    private \PDO $pdo;
    private FinancialStatementService $statements;
    private int $supplier;
    private int $period;
    private array $activity;
    private array $accounts = [];

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $this->pdo = $db->pdo();
        $this->statements = $container->get(FinancialStatementService::class);
        $this->pdo->beginTransaction();
        $this->pdo->exec("INSERT INTO supplier (company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id)
            SELECT 'Synthetic 504 statements','Test 1','Test','10000','statement504@example.com',
                (SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1),
                (SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1),
                (SELECT id FROM vat_rates ORDER BY id LIMIT 1)");
        $this->supplier = (int) $this->pdo->lastInsertId();
        $this->period = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2099, '2098-07-01', '2099-06-30');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?, '2098-07-01', 'cz_504')")->execute([$this->supplier]);
        $catalog = (new SystemDimensionCatalog($db))->ensureForFramework($this->supplier, '2098-07-01');
        $this->activity = $catalog['activity'];
        foreach (['221' => 'asset', '022' => 'asset', '082' => 'asset', '315' => 'asset', '379' => 'liability',
            '901' => 'equity', '931' => 'equity', '932' => 'equity', '933' => 'equity',
            '501' => 'expense', '518' => 'expense', '551' => 'expense', '602' => 'revenue',
            '649' => 'revenue', '682' => 'revenue', '684' => 'revenue', '691' => 'revenue'] as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts (supplier_id,account_code,name,account_type) VALUES (?,?,?,?)')
                ->execute([$this->supplier, (string) $code, 'Synthetic ' . $code, $type]);
            $this->accounts[$code] = (int) $this->pdo->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    private function post(string $date, string $debit, string $credit, float $amount, ?string $activity = null, string $source = 'manual'): int
    {
        $this->pdo->prepare('INSERT INTO journal_entries (supplier_id,period_id,entry_date,source_type) VALUES (?,?,?,?)')
            ->execute([$this->supplier, $this->period, $date, $source]);
        $entry = (int) $this->pdo->lastInsertId();
        foreach (['debit' => $debit, 'credit' => $credit] as $side => $code) {
            $this->pdo->prepare('INSERT INTO journal_entry_lines (supplier_id,entry_id,account_id,side,amount) VALUES (?,?,?,?,?)')
                ->execute([$this->supplier, $entry, $this->accounts[$code], $side, $amount]);
            $line = (int) $this->pdo->lastInsertId();
            if ($activity !== null && in_array($code[0], ['5', '6'], true)) {
                $this->pdo->prepare('INSERT INTO journal_entry_line_dimensions (supplier_id,line_id,dimension_type_id,dimension_value_id) VALUES (?,?,?,?)')
                    ->execute([$this->supplier, $line, $this->activity['id'], $this->activity['values'][$activity]]);
            }
        }
        $this->pdo->prepare('UPDATE journal_entries SET posted_at = NOW() WHERE id = ?')->execute([$entry]);
        return $entry;
    }

    private function reference(): void
    {
        $this->post('2098-07-01', '221', '901', 300_000, null, 'opening');
        foreach ([['602', 200_000, 'main'], ['602', 60_000, 'economic'], ['684', 40_000, 'main'], ['682', 25_000, 'main'], ['691', 30_000, 'main']] as [$code, $amount, $activity]) {
            $this->post('2098-07-01', '221', $code, $amount, $activity);
        }
        foreach ([['501', 100_000, 'main'], ['518', 100_000, 'main'], ['518', 15_000, 'economic']] as [$code, $amount, $activity]) {
            $this->post('2099-01-01', $code, '221', $amount, $activity);
        }
        $this->post('2099-01-01', '221', '901', 72_000);
        $this->post('2099-01-01', '022', '221', 120_000);
        $this->post('2099-06-30', '551', '082', 24_000, 'main');
        $this->post('2099-06-30', '901', '649', 14_400, 'main');
    }

    public function testActualReferenceStatementsUse504AndOpeningExcludesFirstDayTrading(): void
    {
        $this->reference();
        $pl = $this->statements->incomeStatement($this->supplier, $this->period, '2099-06-30', 'full');
        self::assertSame('vyhl504-2002/2016', $pl['version_code']);
        self::assertSame(130_400.0, $pl['checks']['profit_current']);
        $rows = array_column($pl['rows'], null, 'row_code');
        self::assertSame(85_400.0, $rows['UT.63']['main']);
        self::assertSame(45_000.0, $rows['UT.63']['economic']);
        $bs = $this->statements->balanceSheet($this->supplier, $this->period, '2099-06-30', 'full');
        self::assertSame(300_000.0, $bs['totals']['assets_start']);
        self::assertSame(488_000.0, $bs['totals']['assets_end']);
        self::assertSame(488_000.0, $bs['totals']['liabilities_end']);
    }

    public function testMissingPostedClassificationFailsBeforeAggregation(): void
    {
        $this->post('2099-01-01', '518', '221', 100);
        $this->expectException(ReportException::class);
        $this->statements->incomeStatement($this->supplier, $this->period, '2099-06-30', 'full');
    }

    public function testPurposeStatementIsExplicitlyUnsupported(): void
    {
        try {
            $this->statements->incomeStatementByFunction($this->supplier, $this->period, '2099-06-30', 'full');
            self::fail('504 has no purpose statement.');
        } catch (ReportException $e) {
            self::assertSame('statement_purpose_unsupported', $e->errorCode);
        }
    }

    public function testFrameworkChangeInsidePeriodIsNotHiddenByMatchingEndpoints(): void
    {
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?, '2098-09-01', 'cz_500'), (?, '2099-01-01', 'cz_504')")
            ->execute([$this->supplier, $this->supplier]);
        $this->expectException(ReportException::class);
        $this->statements->incomeStatement($this->supplier, $this->period, '2099-06-30', 'full');
    }

    public function testCurrent504AndManualDecreeDoNotChangeHistorical500Serialization(): void
    {
        $this->pdo->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework='cz_500' WHERE supplier_id=?")->execute([$this->supplier]);
        $this->post('2099-01-01', '221', '602', 1234.56);
        $this->post('2099-01-02', '518', '221', 234.56);
        $before = [
            $this->statements->balanceSheet($this->supplier, $this->period, '2099-06-30', 'full'),
            $this->statements->incomeStatement($this->supplier, $this->period, '2099-06-30', 'full'),
        ];
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?, '2099-07-01', 'cz_504')")->execute([$this->supplier]);
        $this->pdo->prepare("UPDATE supplier SET tax_accounting_decree = '504' WHERE id=?")->execute([$this->supplier]);
        $after = [
            $this->statements->balanceSheet($this->supplier, $this->period, '2099-06-30', 'full'),
            $this->statements->incomeStatement($this->supplier, $this->period, '2099-06-30', 'full'),
        ];
        self::assertSame(serialize($before), serialize($after));
        self::assertSame(1000.0, $after[1]['checks']['profit_current']);
    }

    public function testPendingResultBlocksOnlyOfficialShapeAndManagementReconciles(): void
    {
        $this->post('2098-07-01', '221', '931', 100, null, 'opening');
        $view = $this->statements->accountView($this->supplier, $this->period, '2098-08-01');
        self::assertSame('management', $view['view_kind']);
        self::assertSame(100.0, $view['checks']['unresolved_pending_result']);
        self::assertSame(0.0, $view['checks']['reconciliation_difference']);
        try {
            $this->statements->balanceSheet($this->supplier, $this->period, '2098-08-01', 'full');
            self::fail('An official balance sheet cannot omit 931.');
        } catch (ReportException $e) {
            self::assertSame('statement_result_pending_approval_unresolved', $e->errorCode);
        }
    }

    public function testAuditOverridesBothReducedScopes(): void
    {
        $this->pdo->prepare("INSERT INTO accounting_supplier_settings (supplier_id,statutory_audit) VALUES (?,1)")->execute([$this->supplier]);
        foreach (['small', 'micro', 'auto'] as $scope) {
            $out = $this->statements->incomeStatement($this->supplier, $this->period, '2099-06-30', $scope);
            self::assertSame('full', $out['scope']);
            self::assertCount(63, $out['rows']);
        }
    }

    public function testActivityUsesImmutableSemanticKeyAfterRenameAndRetirement(): void
    {
        $this->post('2099-01-01', '221', '602', 12.34, 'economic');
        $this->pdo->prepare("UPDATE dimension_values SET name='Synthetic renamed',code='changed',is_active=0 WHERE id=?")
            ->execute([$this->activity['values']['economic']]);
        $out = $this->statements->incomeStatement($this->supplier, $this->period, '2099-06-30', 'full');
        $rows = array_column($out['rows'], null, 'row_code');
        self::assertSame(12.34, $rows['UT.63']['economic']);
        self::assertSame(0.0, $rows['UT.63']['main']);
    }

    public function testFractionalActivityAllocationConservesCentsAndFiltersTheSameParts(): void
    {
        $entry = $this->post('2099-01-01', '518', '221', 0.03);
        $stmt = $this->pdo->prepare("SELECT id FROM journal_entry_lines WHERE entry_id=? AND side='debit'");
        $stmt->execute([$entry]);
        $line = (int) $stmt->fetchColumn();
        foreach (['main' => '0.5', 'economic' => '0.5'] as $key => $share) {
            $this->pdo->prepare('INSERT INTO journal_entry_line_dimension_splits (supplier_id,line_id,dimension_type_id,dimension_value_id,share) VALUES (?,?,?,?,?)')
                ->execute([$this->supplier, $line, $this->activity['id'], $this->activity['values'][$key], $share]);
        }
        $out = $this->statements->incomeStatement($this->supplier, $this->period, '2099-06-30', 'full');
        $rows = array_column($out['rows'], null, 'row_code');
        self::assertSame(0.03, $rows['UT.8']['total']);
        foreach (['main', 'economic'] as $key) {
            $id = $this->activity['values'][$key];
            $filter = new \MyInvoice\Service\Accounting\Dimension\DimensionFilter($this->activity['id'], $id, [$id]);
            $filtered = $this->statements->incomeStatement($this->supplier, $this->period, '2099-06-30', 'full', $filter);
            $filteredRows = array_column($filtered['rows'], null, 'row_code');
            self::assertSame($rows['UT.8'][$key], $filteredRows['UT.8']['total']);
        }
    }

    public function testActivityCombinedWithAnotherDimensionFiltersTheSameLine(): void
    {
        $this->pdo->prepare("INSERT INTO dimension_types (supplier_id,code,name,kind) VALUES (?,'test_project','Synthetic project','custom')")->execute([$this->supplier]);
        $type = (int) $this->pdo->lastInsertId();
        $projects = [];
        foreach (['a', 'b'] as $code) {
            $this->pdo->prepare('INSERT INTO dimension_values (type_id,supplier_id,code,name) VALUES (?,?,?,?)')
                ->execute([$type, $this->supplier, $code, 'Synthetic ' . $code]);
            $projects[$code] = (int) $this->pdo->lastInsertId();
        }
        foreach ([[100, 'main', 'a'], [40, 'economic', 'a'], [7, 'main', 'b']] as [$amount, $activity, $project]) {
            $entry = $this->post('2099-01-01', '518', '221', $amount, $activity);
            $this->pdo->prepare("INSERT INTO journal_entry_line_dimensions (supplier_id,line_id,dimension_type_id,dimension_value_id)
                SELECT supplier_id, id, ?, ? FROM journal_entry_lines WHERE entry_id = ? AND side = 'debit'")
                ->execute([$type, $projects[$project], $entry]);
        }
        $main = $this->activity['values']['main'];
        // Projekt jako první podmínka: činnost se musí najít i mezi dalšími podmínkami filtru.
        $filter = \MyInvoice\Service\Accounting\Dimension\DimensionFilter::all([
            new \MyInvoice\Service\Accounting\Dimension\DimensionFilter($type, $projects['a'], [$projects['a']]),
            new \MyInvoice\Service\Accounting\Dimension\DimensionFilter($this->activity['id'], $main, [$main]),
        ]);
        $rows = array_column($this->statements->incomeStatement($this->supplier, $this->period, '2099-06-30', 'full', $filter)['rows'], null, 'row_code');
        self::assertSame(100.0, $rows['UT.8']['total']);
        self::assertSame(100.0, $rows['UT.8']['main']);
        self::assertSame(0.0, $rows['UT.8']['economic']);
    }

    public function testReceivableAndOwnerCreditAreNeverCompensated(): void
    {
        $this->post('2099-01-01', '315', '379', 10000);
        $out = $this->statements->balanceSheet($this->supplier, $this->period, '2099-06-30', 'full');
        $assets = array_column($out['assets'], null, 'row_code');
        $liabilities = array_column($out['liabilities'], null, 'row_code');
        self::assertSame(10000.0, $assets['UR.57']['end']);
        self::assertSame(10000.0, $liabilities['US.38']['end']);
    }

    public function testLargeEntityCannotForceReduced504Scope(): void
    {
        $this->post('2098-07-01', '221', '602', 400_000_000, 'main');
        $this->pdo->prepare("INSERT INTO accounting_supplier_settings (supplier_id,statement_scope_override) VALUES (?,'micro')")->execute([$this->supplier]);
        self::assertSame('full', $this->statements->incomeStatement($this->supplier, $this->period, '2099-06-30', 'micro')['scope']);
    }

    public function testNonprofitCategoryIncludesMembershipAndGrantRevenue(): void
    {
        $this->post('2098-07-01', '221', '684', 120_000, 'main');
        $this->post('2098-07-01', '221', '691', 80_000, 'main');
        $category = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\EntityCategoryService::class)->evaluate($this->supplier, $this->period);
        self::assertSame(200_000.0, $category['criteria']['net_turnover']);
        self::assertSame(200_000.0, $category['criteria']['assets_net']);
    }

    public function testClosingTransfersDoNotChangeProfitOrBalance(): void
    {
        $this->reference();
        $before = $this->statements->balanceSheet($this->supplier, $this->period, '2099-06-30', 'full');
        $this->post('2099-06-30', '933', '518', 115_000, null, 'closing');
        $after = $this->statements->balanceSheet($this->supplier, $this->period, '2099-06-30', 'full');
        self::assertSame(serialize($before), serialize($after));
    }

    public function test504OverrideEditorDoesNotExposeA500Map(): void
    {
        $service = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\StatementOverrideService::class);
        try {
            $service->context($this->supplier, $this->period, 'balance_sheet');
            self::fail('A 500 editor cannot edit the 504 map.');
        } catch (ReportException $e) {
            self::assertSame('statement_mapping_editor_unsupported', $e->errorCode);
        }
    }

    public function testSameGroupOverrideCannotMovePendingResultToRetained(): void
    {
        $container = Bootstrap::buildContainer();
        $version = $container->get(\MyInvoice\Repository\StatementDefinitionRepository::class)->findVersion('balance_sheet', '2099-06-30', 'cz_504');
        $container->get(\MyInvoice\Repository\StatementOverrideRepository::class)->create($this->supplier, $version['id'], [
            'account_prefix' => '931', 'row_code' => 'US.9', 'target' => 'gross',
            'balance_condition' => 'any', 'sign' => -1, 'note' => 'Synthetic invalid override',
        ], null);
        $this->post('2098-07-01', '221', '931', 100, null, 'opening');
        try {
            $this->statements->balanceSheet($this->supplier, $this->period, '2099-06-30', 'full');
            self::fail('Mapping must not silently allocate the pending result.');
        } catch (ReportException $e) {
            self::assertSame('statement_mapping_invalid', $e->errorCode);
        }
    }

    public function testWrongAccountTypeCannotHideExpenseFromActivityChecks(): void
    {
        $this->pdo->prepare("UPDATE chart_of_accounts SET account_type='asset' WHERE id=?")->execute([$this->accounts['518']]);
        $this->post('2099-01-01', '518', '221', 100, 'main');
        try {
            $this->statements->incomeStatement($this->supplier, $this->period, '2099-06-30', 'full');
            self::fail('Account class and type must agree.');
        } catch (ReportException $e) {
            self::assertSame('statement_account_type_invalid', $e->errorCode);
        }
    }

    public function test504DefinitionsAreDatedAndDoNotDisplace500(): void
    {
        $definitions = Bootstrap::buildContainer()->get(\MyInvoice\Repository\StatementDefinitionRepository::class);
        foreach (['balance_sheet', 'income_statement'] as $type) {
            self::assertSame('vyhl500-2002/2024', $definitions->findVersion($type, '2026-12-31')['version_code']);
            self::assertSame('vyhl504-2002/2016', $definitions->findVersion($type, '2026-12-31', 'cz_504')['version_code']);
            self::assertNull($definitions->findVersion($type, '2023-12-31', 'cz_504'));
        }
    }

    public function testIndependentSplitsCannotBecomeJointJustBecauseACentRoundsToTheFullAmount(): void
    {
        $entry = $this->post('2099-01-01', '518', '221', 0.01);
        $stmt = $this->pdo->prepare("SELECT id FROM journal_entry_lines WHERE entry_id=? AND side='debit'");
        $stmt->execute([$entry]);
        $line = (int) $stmt->fetchColumn();
        $this->pdo->prepare("INSERT INTO dimension_types (supplier_id,code,name,kind) VALUES (?,'test_project','Synthetic project','custom')")->execute([$this->supplier]);
        $type = (int) $this->pdo->lastInsertId();
        $projectIds = [];
        foreach (['a', 'b'] as $code) {
            $this->pdo->prepare('INSERT INTO dimension_values (type_id,supplier_id,code,name) VALUES (?,?,?,?)')
                ->execute([$type, $this->supplier, $code, 'Synthetic ' . $code]);
            $projectIds[] = (int) $this->pdo->lastInsertId();
        }
        foreach ([$this->activity['id'] => array_values($this->activity['values']), $type => $projectIds] as $typeId => $values) {
            foreach ($values as $value) {
                $this->pdo->prepare('INSERT INTO journal_entry_line_dimension_splits (supplier_id,line_id,dimension_type_id,dimension_value_id,share) VALUES (?,?,?,?,0.5)')
                    ->execute([$this->supplier, $line, $typeId, $value]);
            }
        }
        $filter = new \MyInvoice\Service\Accounting\Dimension\DimensionFilter($type, $projectIds[1], [$projectIds[1]]);
        try {
            $this->statements->incomeStatement($this->supplier, $this->period, '2099-06-30', 'full', $filter);
            self::fail('Rounding cannot prove a joint allocation.');
        } catch (ReportException $e) {
            self::assertSame('statement_joint_allocation_required', $e->errorCode);
        }
    }
}
