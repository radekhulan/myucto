<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\{NonprofitDppoClassificationBridge,NonprofitTaxRules};
use MyInvoice\Service\Tax\Return\TaxReturnException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class NonprofitDppoClassificationBridgeTest extends TestCase
{
    private function fixtureResult():array
    {
        $rows=[];
        foreach([
            ['line:1',100000000,'subject','return','zdp_18'],
            ['line:2',10000000,'exempt','none','zdp_19b_2b_1_3'],
            ['line:3',2000000,'exempt','none','zdp_19_1a_2'],
            ['line:4',5000000,'out_of_scope','none','zdp_18a_1b'],
            ['line:5',3000000,'out_of_scope','none','zdp_18a_1a_3'],
            ['line:6',850000,'subject','final_withholding','zdp_36_5'],
        ] as [$id,$amount,$regime,$method,$basis])$rows[]=['id'=>$id,'kind'=>'revenue','amount_cents'=>$amount,'adjustment_cents'=>$id==='line:1'?0:-$amount,'classification'=>['regime'=>$regime,'method'=>$method,'legal_basis'=>$basis]];
        $rows[]=['id'=>'line:7','kind'=>'expense','amount_cents'=>60000000,'deductible_cents'=>40000000,'adjustment_cents'=>20000000];
        return ['ruleset'=>NonprofitTaxRules::VERSION,'scope'=>'classification_bridge_before_general_adjustments','rows'=>$rows,'accounting_result_cents'=>60850000,'excluded_revenue_cents'=>20850000,'cost_addbacks_cents'=>20000000,'assessed_result_cents'=>60000000,'external_adjustments'=>[]];
    }

    public function testDistinctLegalExclusionsAndCostAddbacksPreserveConfirmedTaxBridge():void
    {
        $bridge=(new NonprofitDppoClassificationBridge())->build($this->fixtureResult());
        self::assertSame([10=>60850000,40=>20000000,100=>0,101=>8000000,109=>10000000,110=>2000000,120=>850000],$bridge['line_cents']);
        self::assertSame(60000000,$bridge['line_cents'][10]+$bridge['line_cents'][40]-array_sum(array_intersect_key($bridge['line_cents'],array_fill_keys([100,101,109,110,120],true))));
        self::assertFalse($bridge['general_adjustments_included']);self::assertCount(7,$bridge['source_lines']);
    }

    public function testNativeNegativeCorrectionRetainsItsSign():void
    {
        $result=$this->fixtureResult();$result['rows'][]=['id'=>'line:8','kind'=>'expense','amount_cents'=>-100,'deductible_cents'=>0,'adjustment_cents'=>-100];$result['accounting_result_cents']=60850100;$result['cost_addbacks_cents']=19999900;
        $bridge=(new NonprofitDppoClassificationBridge())->build($result);self::assertSame(60850100,$bridge['line_cents'][10]);self::assertSame(19999900,$bridge['line_cents'][40]);
    }

    public function testOpaqueLegalBasisRequiresDocumentedFormLine():void
    {
        $result=$this->fixtureResult();$result['rows'][1]['classification']['legal_basis']='Synthetic individually documented basis';
        $bridge=(new NonprofitDppoClassificationBridge())->build($result,['line:2'=>['line'=>109,'evidence'=>'Synthetic approved exemption and line evidence']]);self::assertSame(10000000,$bridge['line_cents'][109]);
    }

    public static function invalidCases():array {return [['totals'],['duplicate'],['cost_adjustment'],['revenue_adjustment'],['unknown_basis'],['override'],['foreign_line']];}

    #[DataProvider('invalidCases')]
    public function testUnprovenOrContradictoryBridgeCannotReachTheReturn(string $case):void
    {
        $result=$this->fixtureResult();$lines=[];
        match($case){
            'totals'=>$result['assessed_result_cents']++,
            'duplicate'=>$result['rows'][]=$result['rows'][0],
            'cost_adjustment'=>$result['rows'][6]['adjustment_cents']=0,
            'revenue_adjustment'=>$result['rows'][1]['adjustment_cents']=0,
            'unknown_basis'=>$result['rows'][1]['classification']['legal_basis']='Synthetic unknown rule',
            'override'=>$lines=['line:2'=>['line'=>110,'evidence'=>'Synthetic contradictory line']],
            'foreign_line'=>$lines=['line:999'=>['line'=>109,'evidence'=>'Synthetic unrelated line']],
        };
        $this->expectException(TaxReturnException::class);(new NonprofitDppoClassificationBridge())->build($result,$lines);
    }
}
