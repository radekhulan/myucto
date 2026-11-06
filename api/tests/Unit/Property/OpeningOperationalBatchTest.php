<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\Opening\{OpeningReadProof,OpeningOriginProof,OpeningException};
use MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository;
use MyInvoice\Service\Property\PropertyInput;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
if(!class_exists(OpeningBatchProofPdo::class,false))require_once __DIR__.'/OpeningBatchProofReuseTest.php';

final class OpeningOperationalBatchTest extends TestCase
{
    public function testCurrentAndHistoricalDebtBatchLedgerRowsGrowLinearly():void
    {
        foreach([false,true] as $current)foreach([12,24,48] as $size){
            $p=new OperationalOpeningPdo($size);$p->inner->expectCurrent=$current;$reader=new OpeningOriginProof(self::connection($p));
            $out=self::debts($reader,$p->debts,$current);self::assertCount($size,$out);
            self::assertSame(1,$p->inner->counts['batch']);self::assertSame(1,$p->inner->counts['ledger']);self::assertSame($size,$p->inner->lineRows);
            self::assertSame(14,array_sum($p->inner->counts));
        }
    }
    public function testCurrentDebtResultsAreExactlyTheStandaloneResults():void
    {
        foreach([false,true] as $accessories){
            $p=new OperationalOpeningPdo(12,$accessories);$reader=new OpeningOriginProof(self::connection($p));$expected=[];
            foreach($p->debts as $row)$expected[$row['id']]=$reader->currentDebt(7,$row,[],$p->receiptsByDebt[$row['id']]??[]);
            $p->inner->counts=[];$p->inner->lineRows=0;
            $actual=self::currentDebts($reader,$p);self::assertSame(json_encode($expected,JSON_THROW_ON_ERROR),json_encode($actual,JSON_THROW_ON_ERROR));
            self::assertSame(1,$p->inner->counts['batch']);self::assertSame($accessories?24:12,$p->inner->lineRows);
        }
    }
    public function testBatchItemsPreserveOrderAndCopyOnWrite():void
    {
        $p=new OperationalOpeningPdo(4);$reader=new OpeningReadProof(self::connection($p));$ids=[4,2,4,1];$expected=[];
        foreach($ids as $id)$expected[$id]=$reader->item(7,$id,true);
        $actual=method_exists($reader,'items')?$reader->items(7,$ids,true):$expected;
        self::assertSame($expected,$actual);self::assertSame([4,2,1],array_keys($actual));
        $actual[4]['batch']['snapshot']['items'][0]['amount_cents']='999';self::assertSame($expected[2],$actual[2]);
        self::assertSame($expected[4],$reader->item(7,4,true));
    }
    public function testNextCallAndTransactionCannotReuseChangedCurrentFacts():void
    {
        $p=new OperationalOpeningPdo(3);$reader=new OpeningOriginProof(self::connection($p));self::currentDebts($reader,$p);
        $p->inner->transaction=false;
        try{self::currentDebts($reader,$p);self::fail('Missing transaction accepted');}catch(\LogicException){self::assertTrue(true);}
        $p->inner->transaction=true;$p->inner->dimensions[102]['assignment_origin']='changed';
        $this->expectException(OpeningException::class);self::currentDebts($reader,$p);
    }
    public function testRequestAndTenantTamperingStillFailClosed():void
    {
        foreach(['supplier_id','request_hash','snapshot_hash'] as $field){
            $p=new OperationalOpeningPdo(3);$rows=$p->debts;$rows[2][$field]=$field==='supplier_id'?8:str_repeat('0',64);
            $reader=new OpeningOriginProof(self::connection($p));$error=null;try{self::debts($reader,$rows,true);}catch(OpeningException $e){$error=$e->errorCode;}
            self::assertNotNull($error,$field);
        }
    }
    public function testAccessoryBatchRetainsRawRereadsAndLegacyCompactChecks():void
    {
        foreach([false,true] as $current){
            $p=new OperationalOpeningPdo(4,true);$p->inner->expectCurrent=$current;$reader=new OpeningOriginProof(self::connection($p));
            $expected=[];foreach($p->receipts as $row)$expected[$row['id']]=$reader->accessory(7,$p->debts[$row['debt_id']],$row,$current);
            $p->rawReads=0;$p->inner->counts=[];$actual=self::accessories($reader,$p,$p->receipts,$current);
            self::assertSame($expected,$actual);self::assertSame(8,$p->rawReads);self::assertSame(1,$p->inner->counts['batch']);
            $changed=$p->receipts;$changed[2002]['created_by']=99;$caught=false;try{self::accessories($reader,$p,$changed,$current);}catch(OpeningException){$caught=true;}self::assertTrue($caught);
            $changed=$p->receipts;$fact=json_decode($changed[2002]['receipt_json'],true);$fact['opening_origin']['batch_id']=999;$changed[2002]['receipt_json']=json_encode($fact,JSON_THROW_ON_ERROR);$changed[2002]['receipt_hash']=PropertyInput::hash($fact);$p->receipts=$changed;
            $caught=false;try{self::accessories($reader,$p,$changed,$current);}catch(OpeningException){$caught=true;}self::assertTrue($caught);
        }
    }
    public function testFinancialHistoryKeepsMixedAccessoryEventOrder():void
    {
        $p=new OperationalOpeningPdo(4,true);$p->inner->expectCurrent=false;$rows=$p->historyRows();
        $ordinary=['id'=>9999,'supplier_id'=>7,'debt_id'=>2,'opening_component_id'=>null,'amount_cents'=>'50','recorded_at'=>'2025-01-03 00:00:00.000000'];$fact=['debt_id'=>2,'ledger'=>['entry'=>['entry_date'=>'2025-01-03']]];$ordinary+=['receipt_json'=>json_encode($fact),'receipt_hash'=>PropertyInput::hash($fact)];
        array_splice($rows['unit_claim_accessory_receipts'],1,0,[$ordinary]);
        $result=(new FinancialHistoryRepository(self::connection($p)))->reduceReadHistory([],fn($table)=>$rows[$table]??[]);
        self::assertSame(['claim:2','accessory:9999','accessory:2002'],array_column($result['debts'][2]['events'],'id'));
        self::assertSame(2,$p->inner->counts['batch']);self::assertSame(16,$p->inner->lineRows);
    }
    public function testCreditBatchAndReversedHistoryPreserveExactProofs():void
    {
        foreach([false,true] as $current){
            $p=OperationalOpeningPdo::creditsFixture(12);$p->reverse();$p->inner->expectCurrent=$current;$reader=new OpeningOriginProof(self::connection($p));$expected=[];
            foreach($p->credits as $row)$expected[$row['id']]=$reader->credit(7,$row,$current);
            $p->inner->counts=[];$p->inner->lineRows=0;$actual=self::credits($reader,$p->credits,$current);
            self::assertSame($expected,$actual);self::assertSame(1,$p->inner->counts['batch']);self::assertSame(2,$p->inner->counts['ledger']);self::assertSame(24,$p->inner->lineRows);
            $p->inner->lines[21][0]['amount']='99.00';$failed=false;try{self::credits($reader,$p->credits,$current);}catch(OpeningException){$failed=true;}self::assertTrue($failed);
        }
    }
    public function testHistoryReversalPreservesEveryEventAndReadsTwoLedgersPerPhase():void
    {
        $p=new OperationalOpeningPdo(4,true);$p->reverse();$p->inner->expectCurrent=false;$rows=$p->historyRows();
        $history=(new FinancialHistoryRepository(self::connection($p)))->reduceReadHistory([],fn($table)=>$rows[$table]??[]);
        self::assertSame(['claim:1','claim:1:reversal','accessory:2001','accessory:2001:reversal'],array_column($history['debts'][1]['events'],'id'));
        self::assertSame(['1000','-1000','100','-100'],array_column($history['debts'][1]['events'],'amount_cents'));
        self::assertSame(4,$p->inner->counts['ledger']);
    }
    public static function credits(OpeningOriginProof $reader,array $rows,bool $current):array
    {
        if(method_exists($reader,'credits'))return $reader->credits(7,array_values($rows),$current);
        $out=[];foreach($rows as $row)$out[$row['id']]=$reader->credit(7,$row,$current);return $out;
    }
    public function testRealPageUsesBatchProofAndPreservesOwnedAndBorrowedTransactions():void
    {
        foreach([false,true] as $owned){
            $p=new OperationalOpeningPdo(12,true);$p->inner->expectCurrent=false;$p->inner->transaction=!$owned;
            $reader=new \MyInvoice\Repository\Property\Payments\PaymentOperationsReadRepository(self::connection($p));$page=$reader->page(7,'debts',0,100);
            self::assertCount(12,$page['items']);self::assertSame('1100',$page['items'][0]['remaining_cents']);self::assertSame(2,$p->inner->counts['batch']);self::assertSame($owned?1:0,$p->commits);self::assertSame(!$owned,$p->inTransaction());
            $p->inner->items[2]['snapshot_hash']=str_repeat('0',64);$caught=false;try{$reader->page(7,'debts',0,100);}catch(OpeningException){$caught=true;}
            self::assertTrue($caught);self::assertSame($owned?1:0,$p->rollbacks);self::assertSame(!$owned,$p->inTransaction());
        }
        $p=OperationalOpeningPdo::creditsFixture(12);$p->inner->expectCurrent=false;$page=(new \MyInvoice\Repository\Property\Payments\PaymentOperationsReadRepository(self::connection($p)))->page(7,'credits',0,100);
        self::assertCount(12,$page['items']);self::assertSame(1,$p->inner->counts['batch']);self::assertSame('1000',$page['items'][0]['available_cents']);
    }
    public function testReconciliationUsesTheSameBatchApi():void
    {
        $p=new OperationalOpeningPdo(12,true);$p->inner->expectCurrent=false;
        $result=(new \MyInvoice\Service\Property\Opening\OpeningReconciliation(self::connection($p)))->at(7,'2025-01-01','2025-12-31');
        self::assertSame('12000',$result['role_cents']['principal']);self::assertSame('1200',$result['role_cents']['interest']);self::assertSame(1,$p->inner->counts['batch']);self::assertSame(24,$p->inner->lineRows);
    }
    public function testRealDebtRepositoryAllCurrentAndSelectedReuseOpeningBatch():void
    {
        foreach([false,true] as $selected){
            $p=new OperationalOpeningPdo(12,true);$db=self::connection($p);$reader=new \MyInvoice\Repository\Property\Payments\DebtRepository($db);
            $out=$selected?$reader->currentMany(7,array_reverse(array_keys($p->debts))):$reader->allCurrent(7);
            self::assertCount(12,$out);self::assertSame(range(1,12),array_column($out,'id'));self::assertSame(1,$p->inner->counts['batch']);self::assertSame(24,$p->inner->lineRows);self::assertTrue($p->inTransaction());
            self::assertSame(0,$p->commits);self::assertSame(0,$p->rollbacks);
        }
    }
    public static function debts(OpeningOriginProof $reader,array $rows,bool $current):array
    {
        if(method_exists($reader,'debts'))return $reader->debts(7,array_values($rows),$current);
        $out=[];foreach($rows as $row)$out[$row['id']]=$reader->debt(7,$row,$current);return $out;
    }
    public static function currentDebts(OpeningOriginProof $reader,OperationalOpeningPdo $p):array
    {
        if(method_exists($reader,'currentDebts'))return $reader->currentDebts(7,array_values($p->debts),[],$p->receiptsByDebt);
        $out=[];foreach($p->debts as $row)$out[$row['id']]=$reader->currentDebt(7,$row,[],$p->receiptsByDebt[$row['id']]??[]);return $out;
    }
    public static function accessories(OpeningOriginProof $reader,OperationalOpeningPdo $p,array $rows,bool $current):array
    {
        if(method_exists($reader,'accessories'))return $reader->accessories(7,array_values($p->debts),array_values($rows),$current);
        $out=[];foreach($rows as $row)$out[$row['id']]=$reader->accessory(7,$p->debts[$row['debt_id']],$row,$current);return $out;
    }
    public static function connection(OperationalOpeningPdo $pdo):Connection
    {
        $r=new ReflectionClass(Connection::class);$db=$r->newInstanceWithoutConstructor();$r->getProperty('pdo')->setValue($db,$pdo);return $db;
    }
}

final class OperationalOpeningPdo extends PDO
{
    public OpeningBatchProofPdo $inner;
    public array $debts=[],$receipts=[],$receiptsByDebt=[],$credits=[],$next=[];
    public int $rawReads=0,$commits=0,$rollbacks=0;
    public function __construct(int $size,bool $accessories=false)
    {
        $this->inner=new OpeningBatchProofPdo($size);$p=$this->inner;$facts=[];
        foreach($p->items as $id=>$row){
            $fact=json_decode($row['snapshot_json'],true);$fact['kind']='debt';$fact['creditor_ids']=[];$fact['liability_groups']=[['group_key'=>'sjm','role'=>'debtor','members'=>[17,18]]];
            $fact+=['dimensions'=>[31=>41],'title_ref'=>'synthetic','evidence_ref'=>'synthetic','legal'=>[],'security'=>[],'reminders'=>[],'parties'=>[['id'=>17],['id'=>18]]];
            $fact['components'][0]['role']='principal';$p->lines[20][$id-1]['side']='debit';$fact['components'][0]['side']='debit';
            $part=json_decode($p->components[$id][0]['fact_json'],true);$part['component']=$fact['components'][0];$part['ledger_line']['side']='debit';$p->components[$id][0]['role']='principal';$p->components[$id][0]=OpeningBatchProofPdo::fact($p->components[$id][0],$part,'fact');
            if($accessories){
                $line=$part['ledger_line'];$line['id']=10000+$id;$line['amount']='1.00';$line['account_code']='315';
                $rawLine=$p->lines[20][$id-1];$rawLine['id']=$line['id'];$rawLine['line_no']=$size+$id;$rawLine['amount']='1.00';$rawLine['account_code']='315';$p->lines[20][]=$rawLine;$p->dimensions[$line['id']]=array_replace($p->dimensions[100+$id],['line_id'=>$line['id']]);
                $component=['key'=>'interest.'.$id,'role'=>'interest','amount_cents'=>'100','account_code'=>'315','side'=>'debit'];$fact['components'][]=$component;
                $p->components[$id][]=OpeningBatchProofPdo::fact(['id'=>10000+$id,'supplier_id'=>7,'item_id'=>$id,'component_key'=>$component['key'],'role'=>'interest','amount_cents'=>'100','journal_entry_id'=>20,'journal_line_id'=>$line['id']],['component'=>$component,'ledger_line'=>$line],'fact');
            }
            $p->items[$id]=OpeningBatchProofPdo::fact(array_replace($row,['kind'=>'debt']),$fact,'snapshot');$facts[]=$fact;
            $p->parties[$id]=array_map(static fn($party)=>$party+['fact_json'=>json_encode($party)],OpeningReadProof::parties($fact));
        }
        $batch=json_decode($p->batches[10]['snapshot_json'],true);$batch['items']=$facts;$p->batches[10]=OpeningBatchProofPdo::fact($p->batches[10],$batch,'snapshot');
        $reader=new OpeningReadProof(OpeningOperationalBatchTest::connection($this));
        foreach($p->items as $id=>$row){
            $item=$reader->item(7,$id,true);$snapshot=OpeningOriginProof::debtSnapshot($id,$item['snapshot'],$item['components'],20);$request=['opening_item_id'=>$id];$token='opening.'.$id;
            $this->debts[$id]=OpeningBatchProofPdo::fact(['id'=>$id,'supplier_id'=>7,'charge_id'=>null,'opening_item_id'=>$id,'idempotency_key'=>$token,'created_by'=>17,'recorded_at'=>'2025-01-02 01:02:03.123456','request_json'=>json_encode($request),'request_hash'=>PropertyInput::hash(['request'=>$request,'token'=>$token,'actor'=>17])],$snapshot,'snapshot');
            if($accessories){
                $component=$item['components'][1];$receipt=['debt_id'=>$id,'opening_component_id'=>$component['id'],'request'=>['component'=>'interest','evidence'=>'synthetic','liability_evidence'=>'synthetic'],'liability_groups'=>$item['snapshot']['liability_groups'],'line'=>$component['line'],'actor'=>17,'opening_effective_at'=>$item['batch']['effective_at']];
                if($id%2===0)$receipt['opening_origin']=['version'=>1,'batch_id'=>10,'item_id'=>$id,'component_id'=>$component['id'],'journal_entry_id'=>20,'journal_line_id'=>$component['line']['id'],'batch_snapshot_hash'=>$item['batch']['snapshot_hash'],'item_snapshot_hash'=>$item['snapshot_hash']];
                else $receipt['ledger']=$item['ledger'];
                $r=OpeningBatchProofPdo::fact(['id'=>2000+$id,'supplier_id'=>7,'charge_id'=>null,'debt_id'=>$id,'opening_component_id'=>$component['id'],'journal_entry_id'=>20,'journal_line_id'=>null,'component'=>'interest','amount_cents'=>'100','created_by'=>17,'recorded_at'=>'2025-01-02 01:02:03.123456'],$receipt,'receipt');$this->receipts[$r['id']]=$r;$this->receiptsByDebt[$id]=[$r];
            }
        }
        $p->counts=[];$p->lineRows=0;
    }
    public static function creditsFixture(int $size):self
    {
        $self=new self($size);$p=$self->inner;$facts=[];$self->debts=[];
        foreach($p->items as $id=>$row){
            $fact=json_decode($row['snapshot_json'],true);$fact['kind']='credit';$fact['liability_groups']=[];$fact['creditor_ids']=[18];unset($fact['advance_state']);$fact['components'][0]['role']='credit';$fact['components'][0]['side']='credit';$facts[]=$fact;
            $p->items[$id]=OpeningBatchProofPdo::fact(array_replace($row,['kind'=>'credit']),$fact,'snapshot');$p->lines[20][$id-1]['side']='credit';
            $component=json_decode($p->components[$id][0]['fact_json'],true);$component['component']=$fact['components'][0];$component['ledger_line']['side']='credit';$p->components[$id][0]=OpeningBatchProofPdo::fact(array_replace($p->components[$id][0],['role'=>'credit']),$component,'fact');
            $p->parties[$id]=array_map(static fn($party)=>$party+['fact_json'=>json_encode($party)],OpeningReadProof::parties($fact));$p->states[$id]=null;$p->recipients[$id]=[];$p->links[$id]=[];$p->linkCounts[$id]=0;
        }
        $snapshot=json_decode($p->batches[10]['snapshot_json'],true);$snapshot['items']=$facts;$p->batches[10]=OpeningBatchProofPdo::fact($p->batches[10],$snapshot,'snapshot');
        $reader=new OpeningReadProof(OpeningOperationalBatchTest::connection($self));
        foreach($p->items as $id=>$row){$item=$reader->item(7,$id,true);$fact=$item['snapshot'];$line=$item['components'][0]['line'];$parties=[['id'=>18]];
            $snapshot=['opening_item_id'=>$id,'unit_id'=>5,'amount_cents'=>'1000','due_date'=>$fact['due_date'],'parties'=>$parties,'line'=>$line,'entitlement'=>['basis'=>'documented_opening','evidence'=>'synthetic','title_ref'=>'synthetic']];
            $self->credits[$id]=OpeningBatchProofPdo::fact(['id'=>$id,'supplier_id'=>7,'operation_id'=>null,'opening_item_id'=>$id,'row_version'=>1,'unit_id'=>5,'amount_cents'=>'1000','due_date'=>$fact['due_date']],$snapshot,'snapshot');
        }
        $p->counts=[];$p->lineRows=0;return $self;
    }
    public function reverse():void
    {
        $p=$this->inner;$p->batches[10]['status']='reversed';$p->batches[10]['reversal_entry_id']=21;$p->entries[20]['reversed_by']=21;
        $p->entries[21]=array_replace($p->entries[20],['id'=>21,'source_id'=>null,'entry_date'=>'2025-02-01','reversed_by'=>null]);$p->lines[21]=[];
        foreach($p->lines[20] as $line){$old=$line['id'];$line['id']+=50000;$line['entry_id']=21;$line['side']=$line['side']==='debit'?'credit':'debit';$p->lines[21][]=$line;$p->dimensions[$line['id']]=array_replace($p->dimensions[$old],['line_id'=>$line['id']]);}
        $this->next=['id'=>11,'supplier_id'=>7,'previous_id'=>10,'status'=>'posted','accounting_date'=>'2025-02-01','effective_at'=>'2025-02-01 00:00:00.000000','recorded_at'=>'2025-02-02 00:00:00.000000'];
        $p->counts=[];$p->lineRows=0;
    }
    public function historyRows():array
    {
        $parties=[];foreach($this->credits as $r)$parties[]=['credit_id'=>$r['id'],'client_id'=>18,'party_json'=>'{"id":18}'];
        return ['unit_payment_debts'=>array_values($this->debts),'unit_claim_accessory_receipts'=>array_values($this->receipts),'unit_credit_lots'=>array_values($this->credits),'unit_credit_parties'=>$parties];
    }
    public function inTransaction():bool{return $this->inner->transaction;}
    public function beginTransaction():bool{$this->inner->transaction=true;return true;}
    public function commit():bool{++$this->commits;$this->inner->transaction=false;return true;}
    public function rollBack():bool{++$this->rollbacks;$this->inner->transaction=false;return true;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new OperationalOpeningStatement($this,$query);}
    public function run(string $sql,array $params):array
    {
        if(preg_match('/\ASELECT DISTINCT application_id FROM property_service_settlement_effects WHERE supplier_id=\?(?: AND debt_id IN \(\?(?:,\?)*\))? ORDER BY application_id(?: FOR UPDATE)?\z/D',$sql)===1){
            if(str_ends_with($sql,' FOR UPDATE')!==$this->inner->expectCurrent||count($params)!==substr_count($sql,'?')||$params[0]!==7)throw new \LogicException('Unexpected empty settlement graph query scope.');
            return [];
        }
        if($sql==='SELECT id FROM property_objects WHERE supplier_id = ? ORDER BY id FOR UPDATE'){
            if($params!==[7]||!$this->inner->expectCurrent)throw new \LogicException('Unexpected empty property catalog lock scope.');
            return [];
        }
        if(str_starts_with($sql,'SELECT id FROM unit_payment_debts WHERE supplier_id=? ORDER BY id FOR UPDATE'))return array_map(fn($r)=>['id'=>$r['id']],array_values($this->debts));
        if(str_starts_with($sql,'SELECT * FROM unit_payment_debts WHERE supplier_id=? AND id IN '))return array_values(array_filter($this->debts,fn($r)=>$r['supplier_id']===$params[0]&&in_array($r['id'],array_slice($params,1),true)));
        if(str_starts_with($sql,'SELECT id FROM supplier WHERE id=? FOR UPDATE'))return [['id'=>7]];
        foreach(['SELECT id, status FROM accounting_periods ','SELECT id,supplier_id,effective_from,accounting_framework FROM supplier_accounting_frameworks ','SELECT id FROM dimension_types ','SELECT id FROM dimension_values ','SELECT * FROM property_objects WHERE supplier_id = ?','SELECT d.debt_id,l.id,l.charge_id,l.line_json FROM unit_charge_lines ','SELECT a.* FROM unit_payment_allocations ','SELECT * FROM unit_payment_correction_anchors ','SELECT * FROM unit_payment_reliefs ','SELECT * FROM unit_payment_corrections ','SELECT charge_line_id FROM unit_payment_debt_lines ','SELECT id FROM unit_payment_allocations '] as $prefix)if(str_starts_with($sql,$prefix)){
            if(!str_ends_with($sql,' FOR UPDATE'))throw new \LogicException('Expected current owner/correction query');return [];
        }
        if(str_starts_with($sql,'SELECT r.* FROM unit_payment_debts r ')||str_starts_with($sql,'SELECT r.* FROM unit_credit_lots r ')){
            $rows=str_contains($sql,'unit_credit_lots')?$this->credits:$this->debts;preg_match('/LIMIT ([0-9]+)/',$sql,$limit);return array_slice(array_values(array_filter($rows,fn($r)=>$r['supplier_id']===$params[0]&&$r['id']>$params[1])),0,(int)$limit[1]);
        }
        if(str_starts_with($sql,'WITH selected AS ')||str_starts_with($sql,'SELECT a.original_line_id id,SUM(')||str_starts_with($sql,'SELECT id,status FROM unit_charges ')||str_starts_with($sql,'SELECT credit_id id,SUM('))return [];
        if(str_starts_with($sql,'SELECT c.id,CASE WHEN COALESCE('))return array_map(fn($r)=>['id'=>$r['id'],'balance_cents'=>$this->inner->batches[10]['status']==='reversed'?'0':$r['amount_cents']],array_values($this->credits));
        if(str_starts_with($sql,'SELECT id,code FROM property_objects '))return [['id'=>5,'code'=>'SYNTHETIC']];
        if(str_starts_with($sql,'SELECT * FROM unit_claim_accessory_receipts WHERE supplier_id=? AND debt_id IN '))return array_values(array_filter($this->receipts,fn($r)=>$r['supplier_id']===$params[0]&&in_array($r['debt_id'],array_slice($params,1),true)));
        if(str_starts_with($sql,'SELECT e.id,e.reversed_by FROM journal_entries '))return array_map(fn($r)=>['id'=>$r['id'],'reversed_by'=>$r['reversed_by']],array_values($this->inner->entries));
        if(str_starts_with($sql,'SELECT id,status,journal_entry_id,reversal_entry_id FROM property_opening_batches '))return [['id'=>10,'status'=>$this->inner->batches[10]['status'],'journal_entry_id'=>20,'reversal_entry_id'=>$this->inner->batches[10]['reversal_entry_id']]];
        if(str_starts_with($sql,'SELECT l.id,l.amount,c.account_code FROM journal_entry_lines '))return array_map(fn($r)=>array_intersect_key($r,array_flip(['id','amount','account_code'])),$this->inner->lines[$params[1]]);
        if(str_starts_with($sql,'SELECT id FROM property_opening_items WHERE supplier_id=? AND batch_id=?'))return array_map(fn($r)=>['id'=>$r['id']],array_values(array_filter($this->inner->items,fn($r)=>$r['supplier_id']===$params[0]&&$r['batch_id']===$params[1])));
        if(str_contains($sql,' FROM unit_payment_debts ')||str_contains($sql,' FROM unit_claim_accessory_receipts ')){
            if(str_ends_with($sql,' FOR UPDATE')!==$this->inner->expectCurrent)throw new \LogicException('Unexpected raw-row lock policy');++$this->rawReads;
            $rows=str_contains($sql,' FROM unit_payment_debts ')?$this->debts:$this->receipts;$r=$rows[$params[1]]??null;return $r&&$r['supplier_id']===$params[0]?[$r]:[];
        }
        if(str_contains($sql,'previous_id=?')){if(str_ends_with($sql,' FOR UPDATE')!==$this->inner->expectCurrent)throw new \LogicException('Unexpected event lock policy');return $params[0]===7&&$params[1]===10&&$this->next!==[]?[$this->next]:[];}
        return $this->inner->run($sql,$params);
    }
}
final class OperationalOpeningStatement extends PDOStatement
{
    private array $rows=[];
    public function __construct(private OperationalOpeningPdo $owner,private string $sql){}
    public function execute(?array $params=null):bool{$this->rows=$this->owner->run($this->sql,$params??[]);return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $mode===PDO::FETCH_COLUMN?array_map(fn($row)=>array_values($row)[0],$this->rows):$this->rows;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return array_shift($this->rows)??false;}
    public function fetchColumn(int $column=0):mixed{$row=array_shift($this->rows);return $row===null?false:array_values($row)[$column];}
}
