<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property\Receivables;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\Receivables\ReceivableTimeline;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
final class FinancialHistoryProjectionTest extends TestCase
{
    public static function fixture():array
    {
        $json=static fn(array $v):string=>json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $proof=static fn(array $v,string $key='snapshot'):array=>[$key.'_json'=>$json($v),$key.'_hash'=>PropertyInput::hash($v)];
        $tables=[];$charges=[];
        $group=['group_key'=>'SJM','role'=>'debtor','members'=>[10,11],'extent'=>['num'=>'1','den'=>'1'],'valid_from'=>'2026-01-01T00:00:00.000000Z','valid_to'=>null];
        foreach([1=>'1000',2=>'500',3=>'-100',4=>'200',5=>'-10'] as $id=>$amount){
            $groups=[$group];if($id===2)$groups[0]['members']=[20];
            $snapshot=['liability_groups'=>$groups,'due_date'=>'2026-02-01','claim_origin_at'=>'2026-01-01T00:00:00.000000Z'];
            $charges[$id]=['id'=>$id,'unit_id'=>$id===2?21:20,'accounting_date'=>'2026-01-01','snapshot'=>$snapshot,'snapshot_hash'=>PropertyInput::hash($snapshot)];
            $line=['amount_cents'=>$amount,'component_id'=>1,'description'=>'Synthetic line'];
            $tables['unit_charge_lines'][]=['id'=>$id*10,'charge_id'=>$id,'amount_cents'=>$amount,'line_json'=>$json($line)];
            $tables['unit_charge_issue_audit'][]=['id'=>$id,'charge_id'=>$id,'action'=>'posted','recorded_at'=>'2026-01-01 10:00:00.000000']+$proof(['charge_id'=>$id],'payload');
            if($id>2)continue;
            $fact=['charge_id'=>$id,'unit_id'=>$charges[$id]['unit_id'],'amount_cents'=>$amount,'liability_groups'=>$groups,
                'due_date'=>'2026-02-01','title_ref'=>'Synthetic title','lines'=>[['id'=>$id*10,'line'=>$line]],'legal'=>['proof'=>'Synthetic full legal title']];
            $request=['charge_id'=>$id];$token='debt-'.$id;
            $tables['unit_payment_debts'][]=['id'=>$id,'charge_id'=>$id,'request_json'=>$json($request),'request_hash'=>PropertyInput::hash(['request'=>$request,'token'=>$token,'actor'=>1]),'idempotency_key'=>$token,'created_by'=>1]+$proof($fact);
        }
        $request=['accounting_date'=>'2026-05-01'];$token='charge-reverse';
        $tables['unit_charge_adjustments'][]=['id'=>1,'charge_id'=>2,'kind'=>'reversal','effective_at'=>'2026-05-01 00:00:00.000000','recorded_at'=>'2026-05-02 00:00:00.000000','created_by'=>1,'idempotency_key'=>$token,'request_json'=>$json($request),
            'request_hash'=>PropertyInput::hash(['charge_id'=>2,'request'=>$request,'idempotency_key'=>$token,'actor'=>1])];
        $tables['unit_claim_accessory_receipts'][]=['id'=>1,'debt_id'=>1,'amount_cents'=>'50','recorded_at'=>'2026-02-10 10:00:00.000000']+$proof(['debt_id'=>1,'ledger'=>['entry'=>['entry_date'=>'2026-02-10']]],'receipt');
        $allocation=['debt_id'=>1,'charge_id'=>1,'charge_line_id'=>10,'accessory_id'=>null,'debtor_id'=>10,'liability_group'=>'SJM','amount_cents'=>'300'];
        $second=$allocation;$second['amount_cents']='150';
        $credit=['unit_id'=>21,'amount_cents'=>'1000','due_date'=>'2026-03-01','parties'=>[['id'=>20,'company_name'=>'Synthetic claimant']]];
        $ops=[];
        foreach([1=>['payment','reversed','300'],2=>['credit','posted','1000'],3=>['refund','reversed','200'],4=>['reversal','posted','200'],5=>['setoff','posted','150'],6=>['reversal','posted','300']] as $id=>[$kind,$status,$amount]){
            $request=['kind'=>$kind,'amount_cents'=>$amount,'accounting_date'=>'2026-02-01','legal_effective_at'=>'2026-02-01T00:00:00.000000Z','payment'=>['received_at'=>'2026-02-01T00:00:00.000000Z','third_party'=>['basis'=>'guarantor'],'payer_id'=>30]];
            if($id===4||$id===6){$request['accounting_date']='2026-04-01';$request['legal_effective_at']='2026-04-01T00:00:00.000000Z';}
            $snapshot=['request'=>$request,'allocations'=>$id===1?[$allocation]:($id===5?[$second]:[]),'credit'=>$id===2?$credit:($id===3||$id===5?['id'=>1]:null),'lines'=>[['irrelevant_for_events'=>'Synthetic journal line']]];
            $original=$id===4?3:($id===6?1:null);
            if($original!==null){$snapshot['original_operation_id']=$original;$snapshot['original_snapshot_hash']=$ops[$original]['snapshot_hash'];}
            $token='op-'.$id;
            $ops[$id]=['id'=>$id,'kind'=>$kind,'status'=>$status,'amount_cents'=>$amount,'accounting_date'=>$request['accounting_date'],'recorded_at'=>$id===4||$id===6?'2026-04-02 00:00:00.000000':'2026-02-02 00:00:00.000000',
                'original_operation_id'=>$original,'journal_entry_id'=>$id+100,'reversal_entry_id'=>$id===1?106:($id===3?104:null),'created_by'=>1,'idempotency_key'=>$token,
                'request_json'=>$json($request),'request_hash'=>PropertyInput::hash(['request'=>$request,'token'=>$token,'actor'=>1])]+$proof($snapshot);
        }
        $tables['unit_payment_operations']=array_values($ops);
        foreach([1=>[1,$allocation],2=>[5,$second]] as $id=>[$op,$fact])$tables['unit_payment_allocations'][]=['id'=>$id,'operation_id'=>$op,'allocation_json'=>$json($fact)]+$fact;
        $request=['accounting_date'=>'2026-03-01','legal_effective_at'=>'2026-03-01T00:00:00.000000Z'];$token='correction';
        $tables['unit_payment_corrections'][]=['id'=>1,'status'=>'reversed','recorded_at'=>'2026-03-02 00:00:00.000000','created_by'=>1,'idempotency_key'=>$token,'request_json'=>$json($request),'request_hash'=>PropertyInput::hash(['request'=>$request,'token'=>$token,'actor'=>1])]
            +$proof(['anchors'=>[['original_line_id'=>10,'adjustment_line_id'=>30,'amount_cents'=>'100']],'reliefs'=>[['allocation_id'=>2,'amount_cents'=>'20']]]);
        $tables['unit_credit_parties'][]=['credit_id'=>1,'client_id'=>20,'party_json'=>$json($credit['parties'][0])];
        $tables['unit_credit_lots'][]=['id'=>1,'operation_id'=>2,'unit_id'=>21,'amount_cents'=>'1000','due_date'=>'2026-03-01']+$proof($credit);
        $tables['unit_credit_events']=[['id'=>1,'operation_id'=>3,'credit_id'=>1,'amount_cents'=>'-200','kind'=>'refund','reservation_id'=>1],['id'=>2,'operation_id'=>5,'credit_id'=>1,'amount_cents'=>'-150','kind'=>'setoff','reservation_id'=>null]];
        $request=['expected_version'=>1,'request'=>['amount_cents'=>'600']];$token='reservation';
        $tables['unit_credit_reservations'][]=['id'=>1,'credit_id'=>1,'amount_cents'=>'600','created_by'=>1,'idempotency_key'=>$token,'request_json'=>$json($request),
            'request_hash'=>PropertyInput::hash(['credit_id'=>1,'expected_version'=>1,'request'=>$request['request'],'token'=>$token,'actor'=>1])];
        foreach([1=>['reservation','reserved','2026-01-31 00:00:00.000000'],2=>['reservation','cancelled','2026-03-15 00:00:00.000000'],3=>['correction','reversed','2026-04-02 00:00:00.000000']] as $id=>[$kind,$action,$at])
            $tables['unit_payment_audit'][]=['id'=>$id,'object_id'=>1,'object_kind'=>$kind,'action'=>$action,'recorded_at'=>$at]+$proof(['request'=>['accounting_date'=>'2026-04-01','effective_at'=>'2026-04-01T00:00:00.000000Z']],'payload');
        return [$charges,$tables];
    }
    private function project(array $charges,array $tables,bool $compact):array
    {
        $reader=new FinancialHistoryRepository(new Connection(new Config([])));
        return new \ReflectionMethod($reader,'project')->invoke($reader,$charges,static function(string $table)use($tables):\Generator{yield from $tables[$table]??[];},$compact);
    }
    public function testCompactProjectionPreservesAllEventsAndOriginalSnapshotHashes():void
    {
        [$charges,$tables]=self::fixture();$full=$this->project($charges,$tables,false);$compact=$this->project($charges,$tables,true);
        foreach($full['debts'] as &$debt)$debt['snapshot']=array_intersect_key($debt['snapshot'],array_flip(['liability_groups','due_date','title_ref']));unset($debt);
        self::assertSame($full,$compact);
        self::assertSame(['claim:1','accessory:1','allocation:1','allocation:1:reversal','allocation:2','correction:1:anchor:10','correction:1:anchor:10:reversal','correction:1:relief:2','correction:1:relief:2:reversal'],array_column($compact['debts'][1]['events'],'id'));
        self::assertSame([50],$compact['unresolved_negative_charge_line_ids']);self::assertSame([40],$compact['unregistered_charge_line_ids']);
        self::assertSame($tables['unit_payment_debts'][0]['snapshot_hash'],$compact['debts'][1]['snapshot_hash']);
        self::assertSame(21,$compact['credits'][1]['unit_id']);self::assertSame(20,$compact['debts'][1]['unit_id']);
        $sum=ReceivableTimeline::sum($compact['credits'][1]['events'],'2026-05-01T00:00:00Z','2026-05-01T00:00:00Z');
        self::assertSame('850',$sum['gross_credit_cents']);self::assertSame('0',$sum['reserved_credit_cents']);
    }
    public function testHistoricalKnownAtAndDayOnlyUncertaintyRemainIntact():void
    {
        [$charges,$tables]=self::fixture();$history=$this->project($charges,$tables,true);
        $events=$history['debts'][1]['events'];
        self::assertSame('1000',ReceivableTimeline::sum($events,'2026-05-01T00:00:00Z','2026-01-02T00:00:00Z')['gross_receivable_cents']);
        self::assertNull(ReceivableTimeline::sum($events,'2026-02-10T10:00:00Z','2026-05-01T00:00:00Z')['gross_receivable_cents']);
        self::assertSame('900',ReceivableTimeline::sum($events,'2026-05-01T00:00:00Z','2026-05-01T00:00:00Z')['gross_receivable_cents']);
    }
    public function testCorruptUnrelatedSnapshotIsStillRejectedBeforeProjection():void
    {
        [$charges,$tables]=self::fixture();$tables['unit_payment_operations'][1]['snapshot_hash']=str_repeat('0',64);
        $this->expectException(PropertyException::class);$this->project($charges,$tables,true);
    }
    public function testCanonicalEquivalentPartyKeysKeepOriginalSnapshotOrder():void
    {
        [$charges,$tables]=self::fixture();
        $fact=json_decode($tables['unit_payment_debts'][0]['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);
        $fact['liability_groups'][0]=array_reverse($fact['liability_groups'][0],true);
        $tables['unit_payment_debts'][0]['snapshot_json']=json_encode($fact,JSON_THROW_ON_ERROR);
        $tables['unit_payment_debts'][0]['snapshot_hash']=PropertyInput::hash($fact);
        $compact=$this->project($charges,$tables,true);
        self::assertSame($fact['liability_groups'],$compact['debts'][1]['snapshot']['liability_groups']);
    }
    #[RunInSeparateProcess]
    public function testProjectionVerifiesButDoesNotRetainAllFullDebtProofs():void
    {
        [$sample]=self::fixture();$charges=[];
        for($id=1;$id<=5000;$id++){$charges[$id]=$sample[1];$charges[$id]['id']=$id;}
        $rows=static function(string $table)use($sample):\Generator{
            if(!in_array($table,['unit_charge_issue_audit','unit_charge_lines','unit_payment_debts'],true))return;
            for($id=1;$id<=5000;$id++){
                $line=['amount_cents'=>'1000','component_id'=>1];
                if($table==='unit_charge_issue_audit'){yield ['id'=>$id,'charge_id'=>$id,'action'=>'posted','recorded_at'=>'2026-01-01 00:00:00.000000','payload_json'=>'[]','payload_hash'=>PropertyInput::hash([])];continue;}
                if($table==='unit_charge_lines'){yield ['id'=>$id,'charge_id'=>$id,'amount_cents'=>'1000','line_json'=>json_encode($line,JSON_THROW_ON_ERROR)];continue;}
                $request=['charge_id'=>$id];$token='debt-'.$id;
                $fact=['charge_id'=>$id,'unit_id'=>20,'amount_cents'=>'1000','liability_groups'=>$sample[1]['snapshot']['liability_groups'],'due_date'=>'2026-02-01','title_ref'=>'Synthetic title',
                    'lines'=>[['id'=>$id,'line'=>$line]],'legal'=>['synthetic_proof'=>str_repeat((string)($id%10),16384)]];
                yield ['id'=>$id,'charge_id'=>$id,'request_json'=>json_encode($request,JSON_THROW_ON_ERROR),'request_hash'=>PropertyInput::hash(['request'=>$request,'token'=>$token,'actor'=>1]),
                    'idempotency_key'=>$token,'created_by'=>1,'snapshot_json'=>json_encode($fact,JSON_THROW_ON_ERROR),'snapshot_hash'=>PropertyInput::hash($fact)];
            }
        };
        $reader=new FinancialHistoryRepository(new Connection(new Config([])));$before=memory_get_usage(true);memory_reset_peak_usage();
        $history=new \ReflectionMethod($reader,'project')->invoke($reader,$charges,$rows,true);
        self::assertCount(5000,$history['debts']);self::assertSame('claim:5000',$history['debts'][5000]['events'][0]['id']);
        self::assertLessThan(32*1024*1024,memory_get_peak_usage(true)-$before);
    }
}
