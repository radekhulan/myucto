<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property\Services;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Services\Settlement\{SettlementDocument,SettlementPlanner,SettlementDispositionReceipt};
use PHPUnit\Framework\TestCase;
final class SettlementPlannerTest extends TestCase
{
    private function fixture(string $z='100000',string $cut='60000',string $s='80000',string $live='90000',string $kind='s3_line'):array
    {
        $line=static fn(string $code,int $id):array=>['account_id'=>$id,'account_code'=>$code,'dimensions'=>[10=>100,11=>110],'dimension_splits'=>[],'dimension_origins'=>[10=>['origin'=>'module','ref'=>'synthetic:1'],11=>['origin'=>'explicit','ref'=>null]],'dimension_split_origins'=>[],'cost_center'=>'SYN','project_id'=>null,'currency_code'=>null,'amount_foreign'=>null,'fx_rate'=>null];
        $source=['kind'=>$kind,'source_period'=>['from'=>'2026-01-01','to'=>'2026-12-31'],'id'=>17,'debt_id'=>20,'supplier_id'=>1,'unit_id'=>2,'service_id'=>3,'prescribed_cents'=>$z,'carried_paid_cents'=>$kind==='opening_component'?$cut:'0','receivable_cents'=>$kind==='opening_component'?bcsub($z,$cut,0):$z,'opening_advance_component_id'=>$kind==='opening_component'?18:null,'opening_state_hash'=>$kind==='opening_component'?str_repeat('6',64):null,'receivable_line'=>$line('315.110',31),'advance_line'=>$line('324.110',32)];
        if($kind==='opening_component'&&$z===$cut){$source['id']=18;$source['debt_id']=null;$source['receivable_line']=null;}
        $key=$kind.':'.$source['id'];$alloc=['id'=>40,'origin_key'=>$key,'source_hash'=>str_repeat('a',64),'amount_cents'=>$cut];
        $cost=['source_id'=>50,'source_hash'=>str_repeat('b',64),'amount_cents'=>$s,'line'=>$line('315.210',33)];
        $doc=['supplier_id'=>1,'unit_id'=>2,'service_id'=>3,'segment_key'=>'U02:2026','period_from'=>'2026-01-01','period_to'=>'2026-12-31','cutoff_at'=>'2027-04-01T00:00:00+02:00','known_at'=>'2027-04-01T12:00:00+02:00','recipient_ids'=>[101],'evidence'=>'Synthetic settlement and service recipient title','prescribed_cents'=>$z,'paid_cents'=>$cut,'actual_cents'=>$s,'origins'=>[$source],'cutoff_allocations'=>$cut==='0'||$kind==='opening_component'?[]:[$alloc],'cost'=>$cost];
        $allocations=[];if($cut!=='0'&&$kind!=='opening_component')$allocations[]=$alloc+['operation_version'=>1,'financial_head'=>str_repeat('c',64),'relieved_cents'=>'0','active'=>true,'accounting_date'=>'2027-03-20'];
        if(bccomp($live,$cut,0)>0)$allocations[]=['id'=>41,'origin_key'=>$key,'source_hash'=>str_repeat('d',64),'amount_cents'=>bcsub($live,$cut,0),'operation_version'=>1,'financial_head'=>str_repeat('e',64),'relieved_cents'=>'0','active'=>true,'accounting_date'=>'2027-04-10'];
        if(bccomp($live,$cut,0)<0){$allocations[0]['active']=false;if($live!=='0')$allocations[]=['id'=>42,'origin_key'=>$key,'source_hash'=>str_repeat('f',64),'amount_cents'=>$live,'operation_version'=>1,'financial_head'=>str_repeat('e',64),'relieved_cents'=>'0','active'=>true,'accounting_date'=>'2027-04-10'];}
        $current=['supplier_id'=>1,'segment_key'=>$doc['segment_key'],'as_of'=>'2027-04-20T10:00:00+02:00','revision'=>1,'application_id'=>null,'origins'=>[['source'=>$source,'version'=>1,'financial_head'=>str_repeat('1',64),'party_head'=>str_repeat('2',64),'prior_reduction_cents'=>'0','advance_available_cents'=>$z,'active'=>true]],'allocations'=>$allocations,'cost'=>['source'=>$cost,'version'=>1,'available_cents'=>$s],'parties'=>[['id'=>101,'supplier_id'=>1],['id'=>202,'supplier_id'=>1]]];
        $a=bccomp($z,$s,0)<0?$z:$s;$c=bccomp($live,$a,0)>0?bcsub($live,$a,0):'0';
        $reliefs=[];$left=$c;
        foreach(array_reverse($allocations) as $allocation){if(!$allocation['active']||$left==='0')continue;$part=bccomp($left,$allocation['amount_cents'],0)<0?$left:$allocation['amount_cents'];$left=bcsub($left,$part,0);$reliefs[]=['allocation_id'=>$allocation['id'],'amount_cents'=>$part,'entitlement'=>['party_ids'=>[202],'evidence'=>'Synthetic independent title to released allocation','due_date'=>'2027-06-30','line'=>$line('379.910',34)]];}
        $request=['accounting_date'=>'2027-04-20','evidence'=>'Synthetic anchored application decision','advances'=>[['origin_key'=>$key,'applied_cents'=>$a]],'reliefs'=>$reliefs,'opening_credits'=>$left!=='0'&&$kind==='opening_component'?[['origin_key'=>$key,'amount_cents'=>$left,'entitlement'=>['party_ids'=>[202],'evidence'=>'Synthetic carried paid entitlement','due_date'=>'2027-06-30','line'=>$line('379.910',34)]]]:[],'new_claim'=>bccomp($s,$z,0)>0?['party_ids'=>[101],'evidence'=>'Synthetic documented new claim','due_date'=>'2027-06-30','line'=>$line('315.300',35)]:null];
        if($z==='0'){$doc['origins']=[];$current['origins']=[];$request['advances']=[];}
        return [$doc,$current,$request];
    }
    public function testOptionalDossierPreservesLegacyDocumentRequestAndReceiptHashes():void
    {
        [$raw,$current,$request]=$this->fixture();$document=SettlementDocument::fromEvidence($raw);$receipt=SettlementPlanner::plan($document,$current,$request);
        self::assertArrayNotHasKey('external_dossier',$document->snapshot());
        self::assertSame('a9827433dd629f366baf630c8f0b76e25c77a9e0723114acb52d0880f7b6db5b',$document->hash());
        self::assertSame('59615bf3e5e459619cb763f6b2ae13e67f2f61e184286eb1504d314137444849',PropertyInput::hash($request));
        self::assertSame('1102c3bcd5f6e8a1895b8bdecf7f39bdd25c8f736da0f912619a3936d68922e5',$receipt->hash());
        $receipt->assertCurrent($document,$current,$request);
    }
    public function testLatePaymentKeepsDocumentAndProducesExactAnchoredThreeMovements():void
    {
        [$raw,$current,$request]=$this->fixture();$doc=SettlementDocument::fromEvidence($raw);$before=$doc->hash();$receipt=SettlementPlanner::plan($doc,$current,$request);$p=$receipt->snapshot();
        self::assertSame('20000',$doc->snapshot()['amounts']['cancelled_unpaid_cents']);
        self::assertSame(['advance_applied_cents'=>'80000','new_claim_cents'=>'0','reduction_cents'=>'20000','cancelled_unpaid_cents'=>'10000','released_allocations_cents'=>'10000','opening_paid_credit_cents'=>'0','new_credit_cents'=>'10000','remaining_old_claim_cents'=>'0','retained_paid_cents'=>'80000'],$p['totals']);
        self::assertSame(['advance_application','principal_reduction','allocation_release'],array_column($p['movements'],'kind'));
        self::assertSame(['80000','20000','10000'],array_column($p['movements'],'amount_cents'));
        self::assertSame(41,$p['reliefs'][0]['allocation_id']);self::assertSame([202],$p['credits'][0]['party_ids']);self::assertSame($before,$doc->hash());
        self::assertSame('90000',$p['current_paid_cents']);self::assertSame('10000',$p['credits'][0]['amount_cents']);
        $copy=$receipt->snapshot();$copy['totals']['reduction_cents']='0';self::assertSame('20000',$receipt->snapshot()['totals']['reduction_cents']);
        $receipt->assertCurrent($doc,$current,$request);
    }
    public function testOpeningComponentUsesRealIdentityAndSameAccountingWithoutFakeCharge():void
    {
        [$raw,$current,$request]=$this->fixture(kind:'opening_component');$p=SettlementPlanner::plan(SettlementDocument::fromEvidence($raw),$current,$request)->snapshot();
        self::assertSame('opening_component:17',$p['reductions'][0]['origin_key']);self::assertSame(20,$p['reductions'][0]['debt_id']);self::assertArrayNotHasKey('adjustment_charge_id',$p);
        self::assertSame('10000',$p['totals']['released_allocations_cents']);
    }
    public function testCurrentHashRejectsInterveningPaymentOrPartyChange():void
    {
        [$raw,$current,$request]=$this->fixture();$doc=SettlementDocument::fromEvidence($raw);$receipt=SettlementPlanner::plan($doc,$current,$request);$current['origins'][0]['party_head']=str_repeat('8',64);
        $this->expectException(\DomainException::class);$this->expectExceptionMessage('settlement_stale_disposition');$receipt->assertCurrent($doc,$current,$request);
    }
    public function testWithoutLatePaymentKeepsTheOriginalClaimAndNoCredit():void
    {
        [$raw,$current,$request]=$this->fixture(live:'60000');$p=SettlementPlanner::plan(SettlementDocument::fromEvidence($raw),$current,$request)->snapshot();
        self::assertSame('20000',$p['totals']['cancelled_unpaid_cents']);self::assertSame('20000',$p['totals']['remaining_old_claim_cents']);self::assertSame([],$p['credits']);self::assertCount(2,$p['movements']);
    }
    public function testReversedCutoffPaymentIsAnExplicitNewDispositionNotADocumentRewrite():void
    {
        [$raw,$current,$request]=$this->fixture(live:'0');$doc=SettlementDocument::fromEvidence($raw);$p=SettlementPlanner::plan($doc,$current,$request)->snapshot();
        self::assertSame([40],$p['changed_cutoff_allocation_ids']);self::assertSame('60000',$doc->snapshot()['paid_cents']);self::assertSame('80000',$p['totals']['remaining_old_claim_cents']);self::assertSame([],$p['credits']);
    }
    public function testNewlyKnownAllocationBeforeCutoffIsStillAdditionalByIdentity():void
    {
        [$raw,$current,$request]=$this->fixture();$current['allocations'][1]['accounting_date']='2027-03-30';$p=SettlementPlanner::plan(SettlementDocument::fromEvidence($raw),$current,$request)->snapshot();
        self::assertSame([41],$p['additional_allocation_ids']);self::assertSame('10000',$p['totals']['released_allocations_cents']);
    }
    public function testNewClaimBelongsToServiceRecipientNotCreditPersonOrCurrentOwner():void
    {
        [$raw,$current,$request]=$this->fixture(s:'120000',live:'90000');$p=SettlementPlanner::plan(SettlementDocument::fromEvidence($raw),$current,$request)->snapshot();
        self::assertSame([101],$p['new_claim']['party_ids']);self::assertSame('20000',$p['new_claim']['amount_cents']);self::assertSame('10000',$p['totals']['remaining_old_claim_cents']);self::assertSame([],$p['credits']);
        $request['new_claim']['party_ids']=[202];$this->expectException(\DomainException::class);$this->expectExceptionMessage('settlement_claim_recipient_changed');SettlementPlanner::plan(SettlementDocument::fromEvidence($raw),$current,$request);
    }
    public function testAllFourteenReferenceSegmentsKeepGrossClaimsAndCredits():void
    {
        $reference=require dirname(__DIR__,3).'/Fixtures/Property/ServiceSettlementReference.php';$totals=['advance_applied_cents'=>'0','new_claim_cents'=>'0','cancelled_unpaid_cents'=>'0','released_allocations_cents'=>'0','remaining_old_claim_cents'=>'0'];
        foreach($reference['segments'] as $label=>[$z,$paid,$actual]){[$raw,$current,$request]=$this->fixture($z,$paid,$actual,$paid);$p=SettlementPlanner::plan(SettlementDocument::fromEvidence($raw),$current,$request)->snapshot();foreach($totals as $key=>$v)$totals[$key]=bcadd($v,$p['totals'][$key],0);$debit='0';$credit='0';foreach($p['lines'] as $line){if($line['side']==='debit')$debit=bcadd($debit,$line['amount'],2);else$credit=bcadd($credit,$line['amount'],2);}self::assertSame($debit,$credit,$label);}
        self::assertSame(['advance_applied_cents'=>'12750000','new_claim_cents'=>'450000','cancelled_unpaid_cents'=>'200000','released_allocations_cents'=>'250000','remaining_old_claim_cents'=>'700000'],$totals);
    }
    public function testLedgerLeavesNoDuplicateUnpaidCancellationOrCreditAndPreservesTuple():void
    {
        [$raw,$current,$request]=$this->fixture();$p=SettlementPlanner::plan(SettlementDocument::fromEvidence($raw),$current,$request)->snapshot();$balances=['315.110'=>'10000','324.110'=>'-100000','315.210'=>'80000','379.910'=>'0','221'=>'90000','321'=>'-80000'];
        foreach($p['lines'] as $line){$amount=bcmul($line['amount'],'100',0);$balances[$line['account_code']]=bcadd($balances[$line['account_code']]??'0',$line['side']==='debit'?$amount:bcsub('0',$amount,0),0);self::assertSame([10=>100,11=>110],$line['dimensions']);self::assertSame('SYN',$line['cost_center']);}
        self::assertSame(['315.110'=>'0','324.110'=>'0','315.210'=>'0','379.910'=>'-10000','221'=>'90000','321'=>'-80000'],$balances);self::assertSame('0',array_reduce($balances,fn($a,$b)=>bcadd($a,$b,0),'0'));
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCurrentCases')]
    public function testInvalidOrIncompleteDispositionCannotProduceAPlan(string $case):void
    {
        [$raw,$c,$r]=$this->fixture();
        switch($case){
            case 'tenant':$c['supplier_id']=9;break;
            case 'origin_tenant':$c['origins'][0]['source']['supplier_id']=9;break;
            case 'tuple':$c['origins'][0]['source']['receivable_line']['project_id']=88;break;
            case 'provenance':$c['origins'][0]['source']['advance_line']['dimension_origins'][10]['ref']='changed';break;
            case 'inactive':$c['origins'][0]['active']=false;break;
            case 'prior_reduction':$c['origins'][0]['prior_reduction_cents']='100';break;
            case 'prior_relief':$c['allocations'][0]['relieved_cents']='100';break;
            case 'advance_capacity':$c['origins'][0]['advance_available_cents']='99999';break;
            case 'cost_capacity':$c['cost']['available_cents']='79999';break;
            case 'cost_identity':$c['cost']['source']['source_id']=999;break;
            case 'cutoff_missing':array_shift($c['allocations']);break;
            case 'cutoff_hash':$c['allocations'][0]['source_hash']=str_repeat('9',64);break;
            case 'duplicate':$c['allocations'][]=$c['allocations'][0];break;
            case 'foreign_allocation':$r['reliefs'][0]['allocation_id']=99;break;
            case 'relief_margin':$r['reliefs'][0]['amount_cents']='9999';break;
            case 'relief_capacity':$r['reliefs'][0]['amount_cents']='30001';break;
            case 'missing_entitlement':$r['reliefs'][0]['entitlement']['evidence']='';break;
            case 'foreign_creditor':$r['reliefs'][0]['entitlement']['party_ids']=[999];break;
            case 'float':$r['advances'][0]['applied_cents']=80000.0;break;
            case 'backdate':$c['allocations'][1]['accounting_date']='2027-04-21';break;
            case 'already_applied':$c['application_id']=12;break;
            case 'fx':$raw['cost']['line']['currency_code']='EUR';break;
            case 'unknown_fields':$r['trust_history']=true;break;
        }
        $this->expectException(in_array($case,['float','missing_entitlement','unknown_fields'],true)?\MyInvoice\Service\Property\PropertyException::class:\DomainException::class);SettlementPlanner::plan(SettlementDocument::fromEvidence($raw),$c,$r);
    }
    public static function invalidCurrentCases():iterable
    {
        foreach(['tenant','origin_tenant','tuple','provenance','inactive','prior_reduction','prior_relief','advance_capacity','cost_capacity','cost_identity','cutoff_missing','cutoff_hash','duplicate','foreign_allocation','relief_margin','relief_capacity','missing_entitlement','foreign_creditor','float','backdate','already_applied','fx','unknown_fields'] as $case)yield $case=>[$case];
    }
    public function testExactLargeAmountsAndGlobalBcscaleDoNotChangePlan():void
    {
        $old=bcscale(9);try{[$raw,$current,$request]=$this->fixture('900719925474099300','900719925474099300','900719925474099299','900719925474099300');$p=SettlementPlanner::plan(SettlementDocument::fromEvidence($raw),$current,$request)->snapshot();self::assertSame('1',$p['totals']['released_allocations_cents']);self::assertSame('9007199254740992.99',$p['lines'][0]['amount']);self::assertSame(9,bcscale());}finally{bcscale($old);}
    }
    public function testMultipleAnchorsRequireExplicitDistributionAndExactReliefIdentity():void
    {
        [$raw,$c,$r]=$this->fixture();$first=$raw['origins'][0];$first['prescribed_cents']='60000';$first['receivable_cents']='60000';$second=$first;$second['id']=18;$second['debt_id']=21;$second['prescribed_cents']='40000';$second['receivable_cents']='40000';$raw['origins']=[$first,$second];
        $c['origins'][0]['source']=$first;$c['origins'][0]['advance_available_cents']='60000';$next=$c['origins'][0];$next['source']=$second;$next['advance_available_cents']='40000';$c['origins'][]=$next;$c['allocations'][1]['origin_key']='s3_line:18';
        $r['advances']=[['origin_key'=>'s3_line:17','applied_cents'=>'60000'],['origin_key'=>'s3_line:18','applied_cents'=>'20000']];
        $p=SettlementPlanner::plan(SettlementDocument::fromEvidence($raw),$c,$r)->snapshot();self::assertSame(['0','20000'],array_column($p['reductions'],'reduction_cents'));self::assertSame('s3_line:18',$p['reliefs'][0]['origin_key']);self::assertSame('10000',$p['totals']['released_allocations_cents']);
        $r['reliefs'][0]['allocation_id']=40;$this->expectException(\DomainException::class);$this->expectExceptionMessage('settlement_relief_margin');SettlementPlanner::plan(SettlementDocument::fromEvidence($raw),$c,$r);
    }
    public function testReceiptDetachesReferencedInputAndBindsEveryInterveningState():void
    {
        [$raw,$c,$r]=$this->fixture();$head=str_repeat('1',64);$c['origins'][0]['financial_head']=&$head;$doc=SettlementDocument::fromEvidence($raw);$receipt=SettlementPlanner::plan($doc,$c,$r);$hash=$receipt->hash();$head=str_repeat('9',64);self::assertSame($hash,$receipt->hash());
        $this->expectException(\DomainException::class);$this->expectExceptionMessage('settlement_stale_disposition');$receipt->assertCurrent($doc,$c,$r);
    }
    public function testSmallCurrentAndHistoricalStatesReconcileIndependently():void
    {
        for($z=0;$z<=5;$z++)for($cut=0;$cut<=$z;$cut++)for($live=0;$live<=$z;$live++)for($s=0;$s<=7;$s++){
            [$raw,$c,$r]=$this->fixture((string)$z,(string)$cut,(string)$s,(string)$live);$p=SettlementPlanner::plan(SettlementDocument::fromEvidence($raw),$c,$r)->snapshot();$a=min($z,$s);$reduce=$z-$a;$credit=max($live-$a,0);$old=max($a-$live,0);
            self::assertSame((string)$a,$p['totals']['advance_applied_cents']);self::assertSame((string)$reduce,$p['totals']['reduction_cents']);self::assertSame((string)$credit,$p['totals']['released_allocations_cents']);self::assertSame((string)$old,$p['totals']['remaining_old_claim_cents']);self::assertSame((string)($reduce-$credit),$p['totals']['cancelled_unpaid_cents']);self::assertSame((string)$cut,$p['document']['paid_cents']);
        }
    }
    public function testOpeningPaidBasisHasNoInventedHistoricAllocationAndExactCurrentPrincipal():void
    {
        [$raw,$c,$r]=$this->fixture(kind:'opening_component');self::assertSame('40000',$raw['origins'][0]['receivable_cents']);self::assertSame([],$raw['cutoff_allocations']);self::assertSame([41],array_column($c['allocations'],'id'));
        $p=SettlementPlanner::plan(SettlementDocument::fromEvidence($raw),$c,$r)->snapshot();self::assertSame('90000',$p['current_paid_cents']);self::assertSame('20000',$p['reductions'][0]['reduction_cents']);self::assertSame('10000',$p['reliefs'][0]['amount_cents']);
    }
    public function testCreditFromOpeningPaidStateIsNotAFakeBankAllocationRelief():void
    {
        [$raw,$c,$r]=$this->fixture(s:'50000',kind:'opening_component');$p=SettlementPlanner::plan(SettlementDocument::fromEvidence($raw),$c,$r)->snapshot();
        self::assertSame('50000',$p['totals']['reduction_cents']);self::assertSame('40000',$p['reductions'][0]['reduction_cents']);self::assertSame('30000',$p['totals']['released_allocations_cents']);self::assertSame('10000',$p['totals']['opening_paid_credit_cents']);self::assertSame('40000',$p['totals']['new_credit_cents']);self::assertSame([41],array_column($p['reliefs'],'allocation_id'));self::assertNull($p['credits'][1]['allocation_id']);self::assertSame(18,$p['credits'][1]['opening_advance_component_id']);
        $m=$p['movements'][3];self::assertSame('opening_paid_credit',$m['kind']);self::assertSame('324.110',$m['debit']['account_code']);self::assertSame('379.910',$m['credit']['account_code']);
        $r['opening_credits']=[];$this->expectException(\DomainException::class);SettlementPlanner::plan(SettlementDocument::fromEvidence($raw),$c,$r);
    }
    public function testOpeningFullyPaidCarryCanCreateCreditWithoutFakeDebtPayment():void
    {
        [$raw,$c,$r]=$this->fixture('100000','100000','80000','100000','opening_component');$p=SettlementPlanner::plan(SettlementDocument::fromEvidence($raw),$c,$r)->snapshot();self::assertSame('0',$raw['origins'][0]['receivable_cents']);self::assertNull($raw['origins'][0]['debt_id']);self::assertNull($raw['origins'][0]['receivable_line']);self::assertSame([],$p['reliefs']);self::assertSame('20000',$p['totals']['opening_paid_credit_cents']);self::assertSame('0',$p['reductions'][0]['reduction_cents']);self::assertSame(['advance_application','opening_paid_credit'],array_column($p['movements'],'kind'));
    }
}
