<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\Opening\{OpeningReadProof,OpeningException};
use MyInvoice\Service\Property\PropertyInput;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class OpeningBatchProofReuseTest extends TestCase
{
    public function testApplyValidationReadsTheBatchAndLedgerOnce():void
    {
        $pdo=new OpeningBatchProofPdo(24);$proof=new OpeningReadProof(self::connection($pdo));
        self::validate($proof,array_keys($pdo->items));
        self::assertSame(1,$pdo->counts['batch']);
        self::assertSame(1,$pdo->counts['ledger']);
        self::assertSame(1,$pdo->counts['lines']);
        self::assertSame(4,$pdo->counts['dimensions']);
        foreach(['item','components','parties','state','recipients','links','link_count'] as $kind)self::assertSame(1,$pdo->counts[$kind],$kind);
        self::assertSame(24,$pdo->lineRows);
        self::assertSame(14,array_sum($pdo->counts));
        self::assertTrue($pdo->transaction);
    }
    public function testCatalogBatchSeamReturnsExactKeyedItemsAndOneCurrentLedger():void
    {
        foreach([false,true] as $reversed){
            $a=new OpeningBatchProofPdo(24,$reversed);$b=new OpeningBatchProofPdo(24,$reversed);$ids=array_reverse(array_keys($a->items));
            $reader=new OpeningReadProof(self::connection($a));$expected=[];foreach($ids as $id)$expected[$id]=$reader->item(7,$id,true);
            $proof=new OpeningReadProof(self::connection($b));$actual=self::catalogItems($proof,$ids);
            self::assertSame(json_encode($expected,JSON_THROW_ON_ERROR),json_encode($actual,JSON_THROW_ON_ERROR));
            self::assertSame($ids,array_keys($actual));self::assertSame(1,$b->counts['batch']);self::assertSame($reversed?2:1,$b->counts['ledger']);
            $actual[24]['batch']['snapshot']['items'][0]['amount_cents']='999';$actual[24]['components'][0]['line']['dimensions'][31]=999;
            self::assertSame($expected[23],$actual[23]);
            self::assertSame($expected,self::catalogItems($proof,$ids));
        }
    }
    public function testCatalogBatchSeamCannotReuseCorruptCurrentLedger():void
    {
        $pdo=new OpeningBatchProofPdo();$proof=new OpeningReadProof(self::connection($pdo));self::catalogItems($proof,[1,2]);
        $pdo->dimensions[102]['assignment_origin_ref']='changed';
        $this->expectException(OpeningException::class);self::catalogItems($proof,[1,2]);
    }
    public function testDoublingItemsDoesNotRepeatFullBatchWork():void
    {
        foreach([12,24,48] as $size){
            $pdo=new OpeningBatchProofPdo($size);self::validate(new OpeningReadProof(self::connection($pdo)),array_keys($pdo->items));
            self::assertSame(1,$pdo->counts['batch']);self::assertSame(1,$pdo->counts['ledger']);self::assertSame($size,$pdo->lineRows);
            self::assertSame(14,array_sum($pdo->counts));
        }
    }
    public function testSingleItemOutputIsCopyOnWriteAndCannotPoisonNextScope():void
    {
        $pdo=new OpeningBatchProofPdo();$proof=new OpeningReadProof(self::connection($pdo));
        $first=$proof->item(7,1,true);$bytes=json_encode($first,JSON_THROW_ON_ERROR);
        $first['batch']['snapshot']['items'][0]['amount_cents']='999';$first['components'][0]['line']['dimensions'][31]=999;
        self::validate($proof,[1,2]);self::assertSame($bytes,json_encode($proof->item(7,1,true),JSON_THROW_ON_ERROR));
    }
    public function testOrdinaryCurrentProofNeverReusesAnEarlierRead():void
    {
        $pdo=new OpeningBatchProofPdo();$proof=new OpeningReadProof(self::connection($pdo));
        $first=$proof->item(7,1,true);$second=$proof->item(7,1,true);
        self::assertSame(json_encode($first,JSON_THROW_ON_ERROR),json_encode($second,JSON_THROW_ON_ERROR));
        self::assertSame(2,$pdo->counts['batch']);self::assertSame(2,$pdo->counts['ledger']);
        $pdo->batches[10]['snapshot_hash']=str_repeat('0',64);
        $this->expectException(OpeningException::class);$proof->item(7,1,true);
    }
    public function testOrdinaryHistoricalProofRetainsNonlockingReads():void
    {
        $pdo=new OpeningBatchProofPdo();$pdo->expectCurrent=false;$proof=new OpeningReadProof(self::connection($pdo));
        $first=$proof->item(7,1,false);$second=$proof->item(7,1,false);
        self::assertSame($first,$second);self::assertSame(2,$pdo->counts['batch']);self::assertSame(2,$pdo->counts['ledger']);
    }
    public function testEveryItemStillRejectsTamperingWithTheSameReason():void
    {
        $mutations=[
            'item_hash'=>static function($p){$p->items[2]['snapshot_hash']=str_repeat('0',64);},
            'item_column'=>static function($p){$p->items[2]['amount_cents']='999';},
            'item_batch'=>static function($p){$p->items[2]['batch_id']=11;},
            'batch_hash'=>static function($p){$p->batches[10]['snapshot_hash']=str_repeat('0',64);},
            'batch_request'=>static function($p){$p->batches[10]['request_hash']=str_repeat('0',64);},
            'planned_item'=>static function($p){$v=json_decode($p->batches[10]['snapshot_json'],true);$v['items'][1]['amount_cents']='999';$p->batches[10]=OpeningBatchProofPdo::fact($p->batches[10],$v,'snapshot');},
            'status'=>static function($p){$p->batches[10]['status']='pending';},
            'registry'=>static function($p){$v=json_decode($p->items[2]['snapshot_json'],true);$v['registry'][0]['revision']['state_hash']=str_repeat('0',64);$p->items[2]=OpeningBatchProofPdo::fact($p->items[2],$v,'snapshot');},
            'component_missing'=>static function($p){$p->components[2]=[];},
            'component_hash'=>static function($p){$p->components[2][0]['fact_hash']=str_repeat('0',64);},
            'component_column'=>static function($p){$p->components[2][0]['amount_cents']='999';},
            'ledger_line'=>static function($p){$p->lines[20][1]['amount']='9.99';},
            'ledger_entry'=>static function($p){$p->entries[20]['entry_date']='2025-02-02';},
            'ledger_dimension'=>static function($p){$p->dimensions[102]['dimension_value_id']=999;},
            'ledger_origin'=>static function($p){$p->dimensions[102]['assignment_origin']='changed';},
            'party'=>static function($p){$p->parties[2][0]['client_id']=99;},
            'party_missing'=>static function($p){$p->parties[2]=[];},
            'state'=>static function($p){$p->states[2]['paid_cents']='2';},
            'recipient'=>static function($p){$p->recipients[2]=[];},
            'link'=>static function($p){$p->links[2][0]['amount_cents']='2';},
            'hidden_link'=>static function($p){$p->linkCounts[2]=2;},
        ];
        foreach($mutations as $name=>$mutate){
            $a=new OpeningBatchProofPdo();$b=new OpeningBatchProofPdo();$mutate($a);$mutate($b);
            $expected=self::error(static function()use($a){$proof=new OpeningReadProof(self::connection($a));foreach(array_keys($a->items) as $id)$proof->item(7,$id,true);});
            $actual=self::error(static fn()=>self::validate(new OpeningReadProof(self::connection($b)),array_keys($b->items)));
            self::assertNotNull($expected,$name);if($name==='item_batch'&&method_exists(OpeningReadProof::class,'verifyCurrentBatchItems'))$expected='property_opening_item_corrupt';self::assertSame($expected,$actual,$name);
        }
    }
    public function testReversalLedgerIsCheckedOnceAndCannotBeCorrupt():void
    {
        $pdo=new OpeningBatchProofPdo(4,true);self::validate(new OpeningReadProof(self::connection($pdo)),[1,2,3,4]);
        self::assertSame(2,$pdo->counts['ledger']);self::assertSame(1,$pdo->counts['batch']);
        $pdo->lines[21][2]['side']='debit';
        $this->expectException(OpeningException::class);self::validate(new OpeningReadProof(self::connection($pdo)),[1,2,3,4]);
    }
    public function testNextScopeAndNextTransactionReadFreshFacts():void
    {
        $pdo=new OpeningBatchProofPdo();$proof=new OpeningReadProof(self::connection($pdo));
        self::validate($proof,[1,2]);self::validate($proof,[1,2]);self::assertSame(2,$pdo->counts['batch']);
        $pdo->transaction=false;$pdo->transaction=true;$pdo->lines[20][0]['amount']='9.99';
        $this->expectException(OpeningException::class);self::validate($proof,[1,2]);
    }
    public function testTransactionAndTenantRemainMandatory():void
    {
        $pdo=new OpeningBatchProofPdo();$proof=new OpeningReadProof(self::connection($pdo));$pdo->transaction=false;
        self::assertSame(\LogicException::class,self::error(static fn()=>self::validate($proof,[1,2])));
        $pdo->transaction=true;
        self::assertSame('property_opening_item_missing',self::error(static fn()=>$proof->item(8,1,true)));
    }
    private static function catalogItems(OpeningReadProof $proof,array $ids):array
    {
        if(method_exists($proof,'currentBatchItems'))return $proof->currentBatchItems(7,10,$ids);
        $items=[];foreach($ids as $id)$items[$id]=$proof->item(7,$id,true);return $items;
    }
    private static function validate(OpeningReadProof $proof,array $ids):void
    {
        if(method_exists($proof,'verifyCurrentBatchItems'))$proof->verifyCurrentBatchItems(7,10,$ids);
        else foreach($ids as $id)$proof->item(7,$id,true);
    }
    private static function error(callable $work):?string
    {
        try{$work();return null;}catch(OpeningException $e){return $e->errorCode;}catch(\LogicException $e){return $e::class;}
    }
    public static function connection(OpeningBatchProofPdo $pdo):Connection
    {
        $reflection=new ReflectionClass(Connection::class);$db=$reflection->newInstanceWithoutConstructor();$reflection->getProperty('pdo')->setValue($db,$pdo);return $db;
    }
}

final class OpeningBatchProofPdo extends PDO
{
    public bool $transaction=true,$expectCurrent=true;
    public array $batches=[],$items=[],$entries=[],$lines=[],$dimensions=[],$components=[],$parties=[],$states=[],$recipients=[],$links=[],$linkCounts=[],$counts=[];
    public int $lineRows=0;
    public function __construct(int $size=2,bool $reversed=false)
    {
        $facts=[];$request=['mode'=>'post_new','accounting_date'=>'2025-01-01','effective_at'=>'2025-01-01T00:00:00.000000Z','expected_revision'=>0];
        $this->entries[20]=['id'=>20,'period_id'=>30,'entry_date'=>'2025-01-01','source_type'=>'opening','source_id'=>10,'posted_at'=>'2025-01-01 00:00:00','reversed_by'=>$reversed?21:null];
        for($id=1;$id<=$size;++$id){
            $lineId=100+$id;$side=$id%2?'debit':'credit';$role=$id%2?'principal':'credit';
            $this->lines[20][]=['id'=>$lineId,'entry_id'=>20,'line_no'=>$id,'account_code'=>'311','side'=>$side,'amount'=>'10.00','currency_code'=>'CZK','amount_foreign'=>null,'fx_rate'=>null,'is_red_storno'=>0,'cost_center'=>null,'project_id'=>null];
            $this->dimensions[$lineId]=['line_id'=>$lineId,'dimension_type_id'=>31,'dimension_value_id'=>41,'assignment_origin'=>'manual','assignment_origin_ref'=>null];
            $line=['id'=>$lineId,'account_code'=>'311','side'=>$side,'amount'=>'10.00','currency_code'=>'CZK','amount_foreign'=>null,'fx_rate'=>null,'is_red_storno'=>false,'dimensions'=>[31=>41],'dimension_splits'=>[],'dimension_origins'=>[31=>['origin'=>'manual','ref'=>null]],'dimension_split_origins'=>[]];
            $part=['key'=>'part.'.$id,'role'=>$role,'amount_cents'=>'1000','account_code'=>'311','side'=>$side];
            $fact=['case_key'=>PropertyInput::hash(['case_ref'=>'case.'.$id,'occurrence_ref'=>'occ.'.$id]),'kind'=>$id%2?'debt':'credit','unit_id'=>5,'service_id'=>null,'origin_at'=>'2025-01-01T00:00:00.000000Z','due_date'=>'2025-01-15','period_from'=>null,'period_to'=>null,'amount_cents'=>'1000','registry'=>[['revision'=>['state'=>['id'=>5],'state_hash'=>PropertyInput::hash(['id'=>5])]]],'components'=>[$part],'creditor_ids'=>$id%2?[]:[18],'liability_groups'=>$id%2?[['group_key'=>'sjm','role'=>'debtor','members'=>[17,18]]]:[]];
            if($id===2)$fact['advance_state']=['purpose_ref'=>'reserve','prescribed_cents'=>'1000','paid_cents'=>'0','recipient_ids'=>[17,18],'unpaid_cases'=>[['case_ref'=>'case.1','occurrence_ref'=>'occ.1','amount_cents'=>'1000']]];
            $facts[]=$fact;
            $this->items[$id]=self::fact(['id'=>$id,'supplier_id'=>7,'batch_id'=>10,'case_key'=>$fact['case_key'],'kind'=>$fact['kind'],'unit_id'=>5,'service_id'=>null,'origin_at'=>'2025-01-01 00:00:00.000000','due_date'=>'2025-01-15','period_from'=>null,'period_to'=>null,'amount_cents'=>'1000'],$fact,'snapshot');
            $this->components[$id]=[self::fact(['id'=>1000+$id,'supplier_id'=>7,'item_id'=>$id,'component_key'=>$part['key'],'role'=>$role,'amount_cents'=>'1000','journal_entry_id'=>20,'journal_line_id'=>$lineId],['component'=>$part,'ledger_line'=>$line],'fact')];
            $this->parties[$id]=array_map(static fn($p)=>$p+['fact_json'=>json_encode($p,JSON_THROW_ON_ERROR)],OpeningReadProof::parties($fact));
            $this->states[$id]=$id===2?['profile'=>null,'purpose_ref'=>'reserve','counterpart_ref'=>null,'settlement_due_on'=>null,'prescribed_cents'=>'1000','paid_cents'=>'0','used_cents'=>'0','settled_cents'=>'0','cancelled_unpaid_cents'=>'0']:null;
            $this->recipients[$id]=$id===2?[['client_id'=>17],['client_id'=>18]]:[];
            $this->links[$id]=$id===2?[['case_key'=>$facts[0]['case_key'],'amount_cents'=>'1000']]:[];
            $this->linkCounts[$id]=count($this->links[$id]);
        }
        $snapshot=['request'=>$request,'period_id'=>30,'previous'=>null,'items'=>$facts];
        $this->batches[10]=self::fact(['id'=>10,'supplier_id'=>7,'period_id'=>30,'previous_id'=>null,'journal_entry_id'=>20,'reversal_entry_id'=>$reversed?21:null,'revision_no'=>1,'created_by'=>17,'status'=>$reversed?'reversed':'posted','mode'=>'post_new','accounting_date'=>'2025-01-01','effective_at'=>'2025-01-01 00:00:00.000000','recorded_at'=>'2025-01-02 01:02:03.123456','idempotency_key'=>'token','request_json'=>json_encode($request,JSON_THROW_ON_ERROR),'request_hash'=>PropertyInput::hash(['request'=>$request,'token'=>'token','actor'=>17])],$snapshot,'snapshot');
        if($reversed){$this->entries[21]=array_replace($this->entries[20],['id'=>21,'reversed_by'=>null]);$this->lines[21]=$this->lines[20];foreach($this->lines[21] as &$l){$l['side']=$l['side']==='debit'?'credit':'debit';$l['entry_id']=21;}unset($l);}
    }
    public static function fact(array $row,array $fact,string $field):array{return array_replace($row,[$field.'_json'=>json_encode($fact,JSON_THROW_ON_ERROR),$field.'_hash'=>PropertyInput::hash($fact)]);}
    public function inTransaction():bool{return $this->transaction;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new OpeningBatchProofStatement($this,$query);}
    public function run(string $sql,array $params):array
    {
        if(str_ends_with($sql,' FOR UPDATE')!==$this->expectCurrent)throw new \LogicException('Unexpected proof lock policy.');
        if($params[0]!==7)return [];
        if(str_contains($sql,' IN (')&&preg_match('/ FROM property_opening_(items|components|parties|states|recipients|case_links)(?: l)? /',$sql,$table)===1){
            if(count($params)!==substr_count($sql,'?'))throw new \LogicException('Unexpected batch proof parameter scope.');
            $ids=array_values(array_unique(array_slice($params,1)));sort($ids,SORT_NUMERIC);$rows=[];
            $kind=match($table[1]){'items'=>'item','states'=>'state','case_links'=>str_contains($sql,'case_links l ')?'links':'link_count',default=>$table[1]};
            foreach($ids as $item){
                if($kind==='item'){if(isset($this->items[$item]))$rows[]=$this->items[$item];continue;}
                if($kind==='state'){if(isset($this->states[$item]))$rows[]=['item_id'=>$item]+$this->states[$item];continue;}
                if($kind==='link_count'){
                    for($n=0;$n<($this->linkCounts[$item]??0);++$n){$link=$this->links[$item][$n]??null;$debt=array_search($link['case_key']??null,array_column($this->items,'case_key','id'),true);$rows[]=['item_id'=>$item,'debt_item_id'=>$debt===false?0:$debt,'amount_cents'=>$link['amount_cents']??'0'];}
                    continue;
                }
                foreach($this->{$kind}[$item]??[] as $row)$rows[]=['item_id'=>$item]+$row;
            }
            $this->counts[$kind]=($this->counts[$kind]??0)+1;return $rows;
        }
        $id=$params[1];
        if(str_contains($sql,' FROM property_opening_batches ')){$kind='batch';$rows=isset($this->batches[$id])?[$this->batches[$id]]:[];}
        elseif(str_contains($sql,' FROM property_opening_items ')){$kind='item';$rows=isset($this->items[$id])?[$this->items[$id]]:[];}
        elseif(str_contains($sql,' FROM journal_entries ')){$kind='ledger';$rows=isset($this->entries[$id])?[$this->entries[$id]]:[];}
        elseif(str_contains($sql,' FROM journal_entry_lines ')){$kind='lines';$rows=$this->lines[$id]??[];$this->lineRows+=count($rows);}
        elseif(str_contains($sql,' FROM journal_entry_line_dimension')){$kind='dimensions';$rows=str_contains($sql,'journal_entry_line_dimension_splits')?[]:array_values(array_intersect_key($this->dimensions,array_flip(array_slice($params,1))));}
        elseif(str_contains($sql,' FROM property_opening_components ')){$kind='components';$rows=$this->components[$id]??[];}
        elseif(str_contains($sql,' FROM property_opening_parties ')){$kind='parties';$rows=$this->parties[$id]??[];}
        elseif(str_contains($sql,' FROM property_opening_states ')){$kind='state';$rows=isset($this->states[$id])?[$this->states[$id]]:[];}
        elseif(str_contains($sql,' FROM property_opening_recipients ')){$kind='recipients';$rows=$this->recipients[$id]??[];}
        elseif(str_contains($sql,'SELECT COUNT(*) AS n FROM property_opening_case_links ')){$kind='link_count';$rows=[['n'=>$this->linkCounts[$id]??0]];}
        elseif(str_contains($sql,' FROM property_opening_case_links l ')){$kind='links';$rows=$this->links[$id]??[];}
        else throw new \LogicException('Unexpected SQL: '.$sql);
        $this->counts[$kind]=($this->counts[$kind]??0)+1;return $rows;
    }
}
final class OpeningBatchProofStatement extends PDOStatement
{
    private array $rows=[];
    public function __construct(private OpeningBatchProofPdo $owner,private string $sql){}
    public function execute(?array $params=null):bool{$this->rows=$this->owner->run($this->sql,$params??[]);return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
}
