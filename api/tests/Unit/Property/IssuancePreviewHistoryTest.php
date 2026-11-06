<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property;

use MyInvoice\Service\Property\Charges\Issuance\IssuanceCaseIdentity;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewHistory;
use MyInvoice\Service\Property\Charges\Issuance\ServiceNoticePolicy;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class IssuancePreviewHistoryTest extends TestCase
{
    private function charge(int $id,int $unit,string $source,string $status,string $from,string $amount='100',string $frequency='monthly'): array
    {
        return ['id'=>$id,'supplier_id'=>10,'unit_id'=>$unit,'source_key'=>$source,'revision_no'=>1,'previous_charge_id'=>null,
            'row_version'=>1,'status'=>$status,'period_from'=>$from,'period_to'=>substr($from,0,7).'-28',
            'snapshot'=>['frequency'=>$frequency,'lines'=>[['component_id'=>7,'target_cents'=>$amount,'amount_cents'=>$amount,
                'account'=>['receivable_account'=>'315','counter_account'=>'324'],'dimensions'=>[1=>2]]],
                'liability_groups'=>[['group_key'=>'SJM','members'=>[30,31]]],'claim_origin_at'=>'2026-01-01T00:00:00.000000Z']];
    }

    private function request(string $source='A',string $period='2026-03-01'): array
    {
        return ['units'=>[['unit_id'=>20],['unit_id'=>21]],'source_key'=>$source,'period_from'=>$period];
    }

    public function testOnePassReducesEveryRequestedRevisionAndKeepsFullFamilyMetadata(): void
    {
        $first=$this->charge(1,20,'A','reversed','2026-01-01');
        $next=$first;$next['id']=3;$next['revision_no']=2;$next['previous_charge_id']=1;$next['status']='posted';
        $next['period_from']='2026-02-01';$next['period_to']='2026-02-28';$next['row_version']=4;
        $charges=[$first,$this->charge(2,21,'A','cancelled','2026-01-01'),$next,
            $this->charge(4,20,'B','draft','2026-03-01'),$this->charge(5,999,'OTHER','posted','2026-01-01')];
        $visited=[];
        $stream=(function()use($charges,&$visited){foreach($charges as $charge){$visited[]=$charge['id'];yield $charge;}})();
        $index=new IssuancePreviewHistory($stream,$this->request());
        self::assertSame([1,2,3,4,5],$visited);
        self::assertSame($next,$index->last(20));
        self::assertSame($charges[1],$index->last(21));
        self::assertNull($index->last(999));
        self::assertFalse($index->replacementReady(20));
        self::assertTrue($index->replacementReady(21));
        self::assertSame([7=>'100'],$index->oldLines(20));
        self::assertSame([],$index->oldLines(21));
        self::assertSame([],$index->families(999));
        $families=$index->families(20);
        self::assertSame(['A','B'],array_column($families,'source_key'));
        self::assertSame([['2026-01-01','2026-01-28'],['2026-02-01','2026-02-28']],$families[0]['periods']);
        self::assertSame(3,$families[0]['latest_charge_id']);
        self::assertSame(2,$families[0]['latest_revision']);
        self::assertSame(4,$families[0]['row_version']);
        self::assertSame('legacy_unclassified',$families[0]['identity_status']);
        self::assertSame('cancelled',$index->families(21)[0]['status']);
    }

    public function testNoticeUsesAllSourceKeysAndStablePeriodThenIdOrdering(): void
    {
        $charges=[$this->charge(1,20,'A','posted','2026-01-01'),
            $this->charge(2,20,'B','reversed','2026-02-01','200','quarterly'),
            $this->charge(3,20,'C','draft','2026-02-01','300'),
            $this->charge(4,20,'D','cancelled','2026-03-01','400'),
            $this->charge(5,20,'E','posted','2026-04-01','500'),
            $this->charge(6,20,'F','posted','2026-01-01','600')];
        $index=new IssuancePreviewHistory((function()use($charges){yield from $charges;})(),$this->request());
        self::assertFalse($index->changed(20,7,'300','monthly'));
        self::assertTrue($index->changed(20,7,'100','monthly'));
        self::assertTrue($index->changed(20,7,'300','quarterly'));
        self::assertFalse($index->changed(20,999,'100','monthly'));
        self::assertFalse($index->changed(21,7,'100','monthly'));
        $early=new IssuancePreviewHistory($charges,$this->request(period:'2026-01-01'));
        self::assertFalse($early->changed(20,7,'600','monthly'));
        foreach(['2025-12-01','2026-01-01','2026-02-01','2026-03-01','2026-04-01'] as $period) {
            $index=new IssuancePreviewHistory($charges,$this->request(period:$period));
            foreach([20,21] as $unit) foreach(['100','200','300','500','600'] as $amount) foreach(['monthly','quarterly'] as $frequency)
                self::assertSame($this->referenceChanged($charges,$unit,7,$amount,$frequency,$period),$index->changed($unit,7,$amount,$frequency));
        }
    }

    public function testDuplicateComponentKeepsFirstLineForEqualOrder(): void
    {
        $charge=$this->charge(1,20,'A','posted','2026-01-01');
        $duplicate=$charge['snapshot']['lines'][0];$duplicate['target_cents']='999';$duplicate['amount_cents']='999';
        $charge['snapshot']['lines'][]=$duplicate;
        $index=new IssuancePreviewHistory([$charge],$this->request());
        self::assertFalse($index->changed(20,7,'100','monthly'));
        self::assertFalse(ServiceNoticePolicy::changed([$charge],20,7,'100','monthly','2026-03-01'));
    }

    public function testUnrequestedUnitCorruptionIsStillRejected(): void
    {
        $charge=$this->charge(1,999,'OTHER','cancelled','2026-01-01');$charge['revision_no']=2;
        $this->expectException(IssuanceException::class);
        new IssuancePreviewHistory([$charge],$this->request());
    }

    public function testHistoricalIdentityMutationIsStillRejected(): void
    {
        $charge=$this->charge(1,20,'A','reversed','2026-01-01');
        $charge['snapshot']['case_identity']=['schema'=>1,'case_ref'=>'Případ Á','occurrence_ref'=>'2026-01','title_ref'=>'Synthetic title',
            'decision_ref'=>'Decision A','decided_on'=>'2025-12-01','evidence_ref'=>'Synthetic deed'];
        $next=$charge;$next['id']=2;$next['revision_no']=2;$next['previous_charge_id']=1;
        $next['snapshot']['case_identity']['title_ref']='Changed';
        $this->expectException(IssuanceException::class);
        new IssuancePreviewHistory([$charge,$next],$this->request());
    }

    public function testCancelledFamilyStillRequiresSameIdentityAndOverlapDistinction(): void
    {
        $charge=$this->charge(1,20,'A','cancelled','2026-03-01');
        $index=new IssuancePreviewHistory([$charge],$this->request());
        $request=$this->request('NEW')+['period_to'=>'2026-03-31','case_identity'=>['schema'=>1,'case_ref'=>'Independent',
            'occurrence_ref'=>'2026-03','title_ref'=>'Synthetic title','decision_ref'=>'Decision A','decided_on'=>'2025-12-01','evidence_ref'=>'Synthetic deed']];
        try {IssuanceCaseIdentity::assertInitial(10,$request,['unit_id'=>20],$index->families(20));self::fail('Cancelled overlap was omitted');}
        catch(IssuanceException $e){self::assertSame('property_charge_case_distinction_required',$e->errorCode);self::assertSame(['period_overlap'],$e->details['case_candidates'][0]['reasons']);}
    }

    #[RunInSeparateProcess]
    public function testFortyEightThousandSnapshotsAreNotRetained(): void
    {
        $count=0;
        $stream=(function()use(&$count){for($id=1;$id<=48000;$id++){
            $charge=$this->charge($id,20,'CASE-'.$id,'posted','2026-01-01');
            $charge['snapshot']['proof_payload']=str_repeat((string)($id%10),16384);
            $count++;yield $charge;
        }})();
        $before=memory_get_usage(true);memory_reset_peak_usage();
        $index=new IssuancePreviewHistory($stream,$this->request('NEW'));
        self::assertSame(48000,$count);
        self::assertCount(48000,$index->families(20));
        self::assertNull($index->last(20));
        self::assertLessThan(128*1024*1024,memory_get_peak_usage(true)-$before);
    }

    public function testPostedDifferencesPreserveAllDistinctHistoricalAnchorsAndFrozenLastParties(): void
    {
        $first=$this->charge(1,20,'A','posted','2026-01-01','100');
        $negative=$first;$negative['id']=2;$negative['revision_no']=2;$negative['previous_charge_id']=1;
        $negative['snapshot']['lines'][0]['amount_cents']='-25';
        $changed=$negative;$changed['id']=3;$changed['revision_no']=3;$changed['previous_charge_id']=2;
        $changed['snapshot']['lines'][0]['amount_cents']='5';$changed['snapshot']['lines'][0]['dimensions']=[1=>3];
        $reversed=$changed;$reversed['id']=4;$reversed['revision_no']=4;$reversed['previous_charge_id']=3;$reversed['status']='reversed';
        $index=new IssuancePreviewHistory([$first,$negative,$changed,$reversed],$this->request());
        self::assertSame([7=>'80'],$index->oldLines(20));
        self::assertCount(2,$index->oldAnchors(20)[7]);
        self::assertSame([[1=>2],[1=>3]],array_column(array_values($index->oldAnchors(20)[7]),'dimensions'));
        self::assertSame($reversed,$index->last(20));
        self::assertFalse($index->replacementReady(20));
    }

    #[RunInSeparateProcess]
    public function testLongRequestedRevisionChainRetainsOnlyLastSnapshotAndDistinctAnchors(): void
    {
        $stream=(function(){for($id=1;$id<=48000;$id++){
            $charge=$this->charge($id,20,'A','posted','2026-01-01','1');
            $charge['revision_no']=$id;$charge['previous_charge_id']=$id===1?null:$id-1;
            $charge['snapshot']['proof_payload']=str_repeat((string)($id%10),16384);
            yield $charge;
        }})();
        $before=memory_get_usage(true);memory_reset_peak_usage();
        $index=new IssuancePreviewHistory($stream,$this->request());
        self::assertSame(48000,$index->last(20)['revision_no']);
        self::assertSame([7=>'48000'],$index->oldLines(20));
        self::assertCount(1,$index->oldAnchors(20)[7]);
        self::assertCount(48000,$index->families(20)[0]['periods']);
        self::assertLessThan(128*1024*1024,memory_get_peak_usage(true)-$before);
    }

    private function referenceChanged(array $charges,int $unit,int $component,string $amount,string $frequency,string $periodFrom): bool
    {
        $latest=null;$target=null;
        foreach($charges as $charge) {
            if($charge['unit_id']!==$unit||!in_array($charge['status'],['draft','posted','reversed'],true)||$charge['period_from']>$periodFrom) continue;
            foreach($charge['snapshot']['lines'] as $line) if($line['component_id']===$component) {
                $order=[$charge['period_from'],$charge['id']];
                if($latest===null||$order>$latest){$latest=$order;$target=[$line['target_cents'],$charge['snapshot']['frequency']];}
            }
        }
        return $target!==null&&$target!==[$amount,$frequency];
    }
}
