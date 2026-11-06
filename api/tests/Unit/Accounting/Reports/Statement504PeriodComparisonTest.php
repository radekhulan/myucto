<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\{ReportException, Statement504PeriodComparison};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Statement504PeriodComparisonTest extends TestCase
{
    private function snapshot(string $date, float $main, float $economic): array
    {
        return ['accounting_framework'=>'cz_504','statement_type'=>'income_statement','version_code'=>'test','scope'=>'full',
            'period'=>['id'=>1,'starts_on'=>'2024-07-01','ends_on'=>'2025-06-30'],'as_of'=>$date,
            'rows'=>[['row_code'=>'UT.63','display_code'=>'D.','label'=>'Synthetic result','main'=>$main,'economic'=>$economic,'total'=>$main+$economic]]];
    }

    public function testExactCentsAndNegativeHalfCentUseJournalRounding(): void
    {
        $previous=$this->snapshot('2024-12-31',100.01,0);
        $current=$this->snapshot('2025-01-31',69.97,30.03);
        $row=Statement504PeriodComparison::difference($current,$previous)[0];
        self::assertSame([-30.04,30.03,-0.01],[$row['main'],$row['economic'],$row['total']]);
        $row=Statement504PeriodComparison::difference($this->snapshot('2025-01-31',-0.005,0),$this->snapshot('2024-12-31',0,0))[0];
        self::assertSame(-0.01,$row['total']);
    }

    public function testMethodComponentsAreMonthlyDifferencesIncludingFirstRecognition(): void
    {
        $previous = $this->snapshot('2024-12-31', 0, 10);
        $current = $this->snapshot('2025-01-31', 0, 25);
        $current['rows'][0]['row_code'] = $previous['rows'][0]['row_code'] = 'UT.38';
        $part = static fn (string $key, float $amount): array => ['key' => $key, 'label' => $key, 'main' => 0.0, 'economic' => $amount, 'total' => $amount];
        $current['rows'][0]['components'] = [$part('current_tax', 15), $part('deferred_tax', 10)];
        $row = Statement504PeriodComparison::difference($current, $previous)[0];
        self::assertSame([5.0, 10.0], array_column($row['components'], 'total'));
        self::assertSame($row['total'], array_sum(array_column($row['components'], 'total')));
        $previous['rows'][0]['components'] = [$part('current_tax', 6), $part('deferred_tax', 4)];
        $row = Statement504PeriodComparison::difference($current, $previous)[0];
        self::assertSame([9.0, 6.0], array_column($row['components'], 'total'));
    }

    #[DataProvider('invalidCases')]
    public function testIncompatibleOrIncompleteSnapshotsFailClosed(string $case): void
    {
        $a=$this->snapshot('2025-01-31',10,20); $b=$this->snapshot('2024-12-31',1,2);
        match($case) {
            'framework'=>$b['accounting_framework']='cz_500',
            'scope'=>$b['scope']='small',
            'period'=>$b['period']['id']=2,
            'date'=>$b['as_of']='2025-01-31',
            'rows'=>$b['rows']=[],
            'duplicate'=>$b['rows'][]=$b['rows'][0],
            'total'=>$b['rows'][0]['total']=4,
            'null'=>$b['rows'][0]['main']=null,
        };
        $this->expectException(ReportException::class);
        Statement504PeriodComparison::difference($a,$b);
    }

    public static function invalidCases(): iterable
    {
        foreach(['framework','scope','period','date','rows','duplicate','total','null'] as $case) yield $case=>[$case];
    }
}
