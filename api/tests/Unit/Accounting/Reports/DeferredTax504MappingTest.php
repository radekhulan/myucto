<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\{DeferredTax504Statement, ReportException, Statement504Builder, Statement504Catalog, StatementMapper};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeferredTax504MappingTest extends TestCase
{
    public function testNoContextPreservesTheExactMap(): void
    {
        $maps = self::maps();
        $maps['income_statement'][] = self::entry('592', 'UT.2') + ['id' => 123];
        self::assertSame($maps, DeferredTax504Statement::maps($maps, null));
    }

    public function testDefaultMapsBuildStatementsAndRemainIdempotent(): void
    {
        $maps = DeferredTax504Statement::maps(self::maps(), []);
        $this->assertStatements($maps, '349', '592');
        self::assertSame($maps, DeferredTax504Statement::maps($maps, []));
    }

    public static function compatibleMappings(): array
    {
        return ['synthetic' => ['', false], 'analytic' => ['.100', false],
            'synthetic paired conditions' => ['', true], 'analytic paired conditions' => ['.100', true]];
    }

    #[DataProvider('compatibleMappings')]
    public function testCompatibleExplicitMappingsRemainUnchangedAndUnambiguous(string $suffix, bool $paired): void
    {
        $maps = self::maps();
        $explicit = [];
        foreach (['balance_sheet' => ['349', 'UR.60'], 'income_statement' => ['592', 'UT.38']] as $type => [$code, $row]) {
            foreach ($paired ? ['debit', 'credit'] : ['any'] as $condition) {
                $entry = self::entry($code . $suffix, $row, $condition) + ['source' => 'supplier', 'note' => 'Synthetic explicit choice'];
                $maps[$type][] = $entry;
                $explicit[$type][] = $entry;
            }
        }
        $result = DeferredTax504Statement::maps($maps, []);
        foreach ($explicit as $type => $entries) foreach ($entries as $entry) self::assertContains($entry, $result[$type]);
        $this->assertStatements($result, '349' . $suffix, '592' . $suffix);
        self::assertSame($result, DeferredTax504Statement::maps($result, []));
    }

    public static function incompatibleMappings(): array
    {
        $cases = [];
        foreach (['balance_sheet' => ['349', 'UR.60'], 'income_statement' => ['592', 'UT.38']] as $type => [$code, $row]) {
            foreach (['wrong row', 'correction target', 'opposite sign', 'debit only', 'credit only', 'invalid condition', 'duplicate', 'overlapping conditions'] as $case) {
                $cases[$type . ' ' . $case] = [$type, $code, $row, $case];
            }
        }
        return $cases;
    }

    #[DataProvider('incompatibleMappings')]
    public function testIncompatibleAnalyticMappingIsRejected(string $type, string $code, string $row, string $case): void
    {
        $maps = self::maps();
        $entry = self::entry($code . '.100', $row);
        if ($case === 'wrong row') $entry['row_code'] = $type === 'balance_sheet' ? 'US.20' : 'UT.2';
        if ($case === 'correction target') $entry['target'] = 'correction';
        if ($case === 'opposite sign') $entry['sign'] = -1;
        if ($case === 'debit only') $entry['balance_condition'] = 'debit';
        if ($case === 'credit only') $entry['balance_condition'] = 'credit';
        if ($case === 'invalid condition') $entry['balance_condition'] = 'invalid';
        $maps[$type][] = $entry;
        if ($case === 'duplicate') $maps[$type][] = $entry;
        if ($case === 'overlapping conditions') $maps[$type][] = array_replace($entry, ['balance_condition' => 'debit']);
        try {
            DeferredTax504Statement::maps($maps, []);
            self::fail('Conflicting deferred tax mapping must be rejected.');
        } catch (ReportException $e) {
            self::assertSame('statement_mapping_invalid', $e->errorCode);
        }
    }

    public function testUnrelatedMappingsAreNotDeduplicated(): void
    {
        $maps = self::maps();
        $unrelated = self::entry('777', 'UT.2');
        $maps['income_statement'][] = $unrelated;
        $maps['income_statement'][] = $unrelated;
        $result = DeferredTax504Statement::maps($maps, []);
        self::assertCount(2, array_filter($result['income_statement'], static fn (array $m): bool => $m['account_prefix'] === '777'));
    }

    private function assertStatements(array $maps, string $assetCode, string $expenseCode): void
    {
        $builder = new Statement504Builder(null, $maps);
        foreach ([1, -1] as $sign) {
            $profit = [['code' => $expenseCode, 'activity' => 'economic', 'balance_cents' => 4200000 * $sign]];
            $statement = $builder->incomeStatement($profit, 'full');
            $rows = array_column($statement['rows'], null, 'row_code');
            self::assertSame(42000.0 * $sign, $rows['UT.38']['total']);
            self::assertTrue($statement['checks']['profit_matches']);
        }
        $balances = [['code' => $assetCode, 'opening_cents' => 1200000, 'closing_cents' => 1800000]];
        $statement = $builder->balanceSheet($balances, [], 'full');
        $assets = array_column($statement['assets'], null, 'row_code');
        self::assertSame(12000.0, $assets['UR.60']['start']);
        self::assertSame(18000.0, $assets['UR.60']['end']);
        self::assertSame(18000.0, $builder->assetsNet($balances));
        foreach (['balance_sheet' => $assetCode, 'income_statement' => $expenseCode] as $type => $code) {
            foreach ([1, -1] as $sign) self::assertCount(1, (new StatementMapper())->entriesFor($maps[$type], $code, $sign));
        }
    }

    private static function maps(): array
    {
        return ['balance_sheet' => Statement504Catalog::accountMap('balance_sheet'),
            'income_statement' => Statement504Catalog::accountMap('income_statement')];
    }

    private static function entry(string $prefix, string $row, string $condition = 'any'): array
    {
        return ['account_prefix' => $prefix, 'row_code' => $row, 'target' => 'gross', 'balance_condition' => $condition, 'sign' => 1];
    }
}
