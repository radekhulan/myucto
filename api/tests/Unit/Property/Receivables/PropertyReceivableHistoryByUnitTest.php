<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property\Receivables;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository;
use MyInvoice\Service\Property\Receivables\{PropertyReceivableHistoryByUnit,PropertyReceivableProjection,PropertySaldoSummary};
use PHPUnit\Framework\TestCase;
final class PropertyReceivableHistoryByUnitTest extends TestCase
{
    public function testSlicesPreserveMetadataKeysAndGlobalUnionShadowingWithoutMutatingInput():void
    {
        [$history]=$this->fixture();$before=$history;$index=new PropertyReceivableHistoryByUnit($history);
        self::assertSame($before,$history);
        foreach([20,21,99,100] as $unit){
            $slice=$index->forUnit($unit);
            self::assertSame(array_keys($history),array_keys($slice));
            foreach(['unregistered_charge_line_ids','unresolved_negative_charge_line_ids','future_metadata'] as $key)self::assertSame($history[$key],$slice[$key]);
        }
        self::assertSame([1],array_keys($index->forUnit(20)['debts']));
        self::assertSame([2],array_keys($index->forUnit(21)['debts']));
        self::assertSame(['credit-key'],array_keys($index->forUnit(21)['credits']));
        self::assertSame(['unresolved-key'],array_keys($index->forUnit(20)['unresolved_negative_charge_lines']));
        self::assertSame($history['unregistered_claims'][-40],$index->forUnit(20)['unregistered_claims'][-40]);
        self::assertSame([],$index->forUnit(99)['unregistered_claims']);
        self::assertSame([],$index->forUnit(100)['debts']);
        $slice=$index->forUnit(20);$slice['debts'][1]['events']=[];
        self::assertSame($before['debts'][1]['events'],$index->forUnit(20)['debts'][1]['events']);
    }
    public function testFullCardsAndSummariesMatchForMixedFinancialAndPartyHistory():void
    {
        [$history,$events]=$this->fixture();$index=new PropertyReceivableHistoryByUnit($history);
        foreach([
            ['2025-12-31T23:00:00Z','2026-06-01T00:00:00Z'],
            ['2026-01-01T12:00:00+01:00','2026-06-01T00:00:00Z'],
            ['2026-03-01T12:00:00Z','2026-03-01T12:00:00Z'],
            ['2026-06-01T00:00:00Z','2026-06-01T00:00:00Z'],
        ] as [$at,$known])foreach([20,21,99,100] as $unit){
            $full=PropertyReceivableProjection::reduceReadHistory(7,$at,$known,['unit_id'=>$unit],$history,$events);
            $slice=PropertyReceivableProjection::reduceReadHistory(7,$at,$known,['unit_id'=>$unit],$index->forUnit($unit),$events);
            self::assertSame($full,$slice);
            self::assertSame(PropertySaldoSummary::fromCard($full,[1=>true]),PropertySaldoSummary::fromCard($slice,[1=>true]));
            self::assertSame(['cross-unit','future-head'],array_keys($slice['current_party_heads']));
        }
        $unknown=PropertyReceivableProjection::reduceReadHistory(7,'2026-01-01T12:00:00+01:00','2026-06-01T00:00:00Z',['unit_id'=>20],$index->forUnit(20),$events);
        self::assertFalse($unknown['coverage']['financial_complete']);self::assertSame('day',$unknown['precision']);self::assertNull($unknown['totals']['gross_receivable_cents']);
        $current=PropertyReceivableProjection::reduceReadHistory(7,'2026-06-01T00:00:00Z','2026-06-01T00:00:00Z',['unit_id'=>20],$index->forUnit(20),$events);
        self::assertSame([50],$current['coverage']['unresolved_negative_charge_line_ids']);
        self::assertContains('correction:1:relief:2:reversal',array_column($current['claims'][0]['events'],'id'));
    }
    public function testEachHistoryRecordReachesOnlyOneUnitReduction():void
    {
        [$sample]=$this->fixture();$history=$sample;
        foreach(['debts','unregistered_claims','credits','unresolved_negative_charge_lines'] as $collection)$history[$collection]=[];
        for($unit=1;$unit<=400;$unit++){
            $debt=$sample['debts'][1];$debt['unit_id']=$unit;$debt['id']=$unit;$history['debts'][$unit]=$debt;
            $credit=$sample['credits']['credit-key'];$credit['unit_id']=$unit;$credit['id']=$unit;$history['credits'][$unit]=$credit;
            $line=$sample['unresolved_negative_charge_lines']['unresolved-key'];$line['unit_id']=$unit;$line['id']=$unit;$history['unresolved_negative_charge_lines'][$unit]=$line;
        }
        $index=new PropertyReceivableHistoryByUnit($history);$visited=0;
        for($unit=1;$unit<=400;$unit++){
            $slice=$index->forUnit($unit);
            $visited+=count($slice['debts']+$slice['unregistered_claims'])+count($slice['credits'])+count($slice['unresolved_negative_charge_lines']);
            $card=PropertyReceivableProjection::reduceReadHistory(7,'2026-06-01T00:00:00Z','2026-06-01T00:00:00Z',['unit_id'=>$unit],$slice,[]);
            self::assertCount(1,$card['claims']);self::assertCount(1,$card['credits']);
        }
        self::assertSame(1200,$visited);
    }
    private function fixture():array
    {
        [$charges,$tables]=FinancialHistoryProjectionTest::fixture();
        $reader=new FinancialHistoryRepository(new Connection(new Config([])));
        $history=$reader->reduceReadHistory($charges,static fn(string $table):array=>$tables[$table]??[]);
        $history['credits']=['credit-key'=>$history['credits'][1]];
        $history['unresolved_negative_charge_lines']=['unresolved-key'=>$history['unresolved_negative_charge_lines'][0]];
        $history['future_metadata']=['unknown_state'=>'review','cross_unit_proof'=>['units'=>[20,21,99]],'precision'=>null];
        $history['debts'][1]['future_row_metadata']=['preserved'=>true];
        $history['debts'][1]['events'][0]['effective_at']=null;
        $shadow=$history['unregistered_claims'][-40];$shadow['unit_id']=99;$history['unregistered_claims'][1]=$shadow;
        $parts=[];
        foreach($history['debts'] as $debt)$parts[]=['debt_id'=>$debt['id'],'predecessor_event_id'=>null,'source_snapshot_hash'=>$debt['snapshot_hash'],
            'groups'=>$debt['snapshot']['liability_groups'],'remaining_cents'=>$debt['events'][0]['amount_cents'],'claim_baseline_cents'=>$debt['events'][0]['amount_cents'],
            'financial_events'=>[$debt['events'][0]],'group_paid_baseline'=>[],'payer_paid_baseline'=>[]];
        $events=['cross-unit'=>['id'=>7,'snapshot_hash'=>'synthetic-cross-unit','recorded_at'=>'2026-01-02T00:00:00.000000Z','effective_at'=>'2026-01-01T00:00:00.000000Z',
            'snapshot'=>['debts'=>$parts,'request'=>['decision'=>'review_required']]],
            'future-head'=>['id'=>8,'snapshot_hash'=>'synthetic-future','recorded_at'=>'2030-01-01T00:00:00.000000Z','effective_at'=>'2030-01-01T00:00:00.000000Z','snapshot'=>['debts'=>[]]]];
        return [$history,$events];
    }
}
