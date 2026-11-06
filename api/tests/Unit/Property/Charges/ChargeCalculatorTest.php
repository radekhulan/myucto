<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property\Charges;

use MyInvoice\Service\Property\Charges\ChargeCalculator;
use MyInvoice\Service\Property\Charges\ChargeException;
use PHPUnit\Framework\TestCase;

final class ChargeCalculatorTest extends TestCase
{
    private static function units(): array
    {
        return [
            ['id'=>3, 'attributes'=>['floor_area'=>['num'=>'81','den'=>'2'], 'share'=>['num'=>'1','den'=>'3'], 'persons'=>['num'=>'2','den'=>'1']]],
            ['id'=>1, 'attributes'=>['floor_area'=>['num'=>'40','den'=>'1'], 'share'=>['num'=>'1','den'=>'3'], 'persons'=>['num'=>'1','den'=>'1']], 'ownerships'=>[['members'=>[11,12]]]],
            ['id'=>2, 'attributes'=>['floor_area'=>['num'=>'79','den'=>'2'], 'share'=>['num'=>'1','den'=>'3'], 'persons'=>['num'=>'0','den'=>'1']]],
        ];
    }
    private static function rate(string $method, string $amount, string $mode='rate_per_basis'): array
    {
        return ['method'=>$method,'amount'=>$amount,'mode'=>$mode,'rounding'=>'aggregate_half_up_largest_remainder_unit_id_v1'];
    }
    public function testOneCentBudgetAndCanonicalOrder(): void
    {
        $a=ChargeCalculator::calculate(self::rate('equal','0.01','total_budget'),self::units());
        self::assertSame('1',$a['total_cents']);
        self::assertSame([1,2,3],array_column($a['lines'],'unit_id'));
        self::assertSame(['1','0','0'],array_column($a['lines'],'amount_cents'));
        self::assertSame($a,ChargeCalculator::calculate(self::rate('equal','0.01','total_budget'),array_reverse(self::units())));
    }
    public function testFractionalUnitRatesPreserveAggregateHalfUpMargin(): void
    {
        $a=ChargeCalculator::calculate(self::rate('unit','0.005'),self::units());
        self::assertSame('2',$a['total_cents']);
        self::assertSame(['1','1','0'],array_column($a['lines'],'amount_cents'));
        self::assertSame(['num'=>'1','den'=>'2'],$a['lines'][0]['exact_cents']);
    }
    public function testNegativeCorrectionMirrorsPositiveResidual(): void
    {
        $a=ChargeCalculator::calculate(self::rate('unit','-0.005'),self::units());
        self::assertSame('-2',$a['total_cents']);
        self::assertSame(['-1','-1','0'],array_column($a['lines'],'amount_cents'));
    }
    public function testAllFiveBasesUseUnitFactsNotPeopleInOwnership(): void
    {
        foreach(['unit'=>['100','100','100'],'equal'=>['100','100','100'],'floor_area'=>['4000','3950','4050'],'share'=>['34','33','33'],'person'=>['100','0','200']] as $method=>$expected) {
            $a=ChargeCalculator::calculate(self::rate($method,'1'),self::units());
            self::assertSame($expected,array_column($a['lines'],'amount_cents'),$method);
        }
    }
    public function testZeroRateAndZeroBasisStayZero(): void
    {
        $units=self::units(); foreach($units as &$u) $u['attributes']['persons']=['num'=>'0','den'=>'1']; unset($u);
        self::assertSame('0',ChargeCalculator::calculate(self::rate('person','10'),$units)['total_cents']);
        self::assertSame('0',ChargeCalculator::calculate(self::rate('person','0','total_budget'),$units)['total_cents']);
    }
    public function testNonzeroBudgetCannotRedistributeZeroBasis(): void
    {
        $units=self::units(); foreach($units as &$u) $u['attributes']['persons']=['num'=>'0','den'=>'1']; unset($u);
        $this->expectException(ChargeException::class);
        ChargeCalculator::calculate(self::rate('person','1','total_budget'),$units);
    }
    public function testMissingAttributeIsNotSilentlyZero(): void
    {
        $units=self::units(); unset($units[0]['attributes']['persons']);
        $this->expectException(ChargeException::class);
        ChargeCalculator::calculate(self::rate('person','1'),$units);
    }
    public function testDuplicateUnitCannotMultiplyBudget(): void
    {
        $units=self::units(); $units[]=$units[0];
        $this->expectException(ChargeException::class);
        ChargeCalculator::calculate(self::rate('equal','100','total_budget'),$units);
    }
    public function testLargeExactMoneyNeverUsesBinaryFloat(): void
    {
        $a=ChargeCalculator::calculate(self::rate('equal','999999999999.99','total_budget'),self::units());
        self::assertSame('99999999999999',$a['total_cents']);
        self::assertSame(['33333333333333','33333333333333','33333333333333'],array_column($a['lines'],'amount_cents'));
    }
    public function testOneCentAmongHundredAndOneUnitsHasOneDeterministicRecipient(): void
    {
        $units=[];foreach(range(101,1) as $id) $units[]=['id'=>$id,'attributes'=>[]];
        $a=ChargeCalculator::calculate(self::rate('equal','-0.01','total_budget'),$units);
        self::assertSame('-1',$a['total_cents']);self::assertSame('-1',$a['lines'][0]['amount_cents']);
        self::assertCount(100,array_filter($a['lines'],static fn($line)=>$line['amount_cents']==='0'));
    }

    public function testRatesRejectFloatingInput(): void
    {
        $rate=self::rate('unit','1'); $rate['amount']=0.1;
        $this->expectException(ChargeException::class);
        ChargeCalculator::calculate($rate,self::units());
    }
}
