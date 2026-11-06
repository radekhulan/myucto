<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Property\Funds;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\{JournalLineAmount,PostingService,AccountRoleResolver,FrameworkOperationPolicy};
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant};
use MyInvoice\Service\Property\Funds\{FundingException as E,FundingSourceProof,FundingProof,FundingUseGuard};
use MyInvoice\Service\Property\Payments\{PaymentLocks,PaymentPostingDate,PaymentJournalProof};
use MyInvoice\Service\Property\Charges\ChargeCalculator;
use PDO;
final class FundingRepository
{
    public function __construct(private readonly Connection $db){}
    private function q(string $sql,array $args=[]):\PDOStatement {$q=$this->db->pdo()->prepare($sql);$q->execute($args);return $q;}
    private static function json(array $value):string {return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
    private function tx(int $supplier,callable $work):mixed {return(new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$work){(new PaymentLocks($this->db))->acquire($supplier);return $work();});}
    public static function normalize(array $request):array
    {
        PropertyInput::fields($request,['kind','house_id','accounting_date','effective_at','expected_revision','evidence_ref','reason','amount_cents','key','classification','ownership','cost_line_id','other_coverage','sources','bank_id','dispositions','purpose_ref','settlement_due_on','target','operation_id','recipient_account','recipient_id']);
        if(!in_array($request['kind']??null,['draw','income','transfer','payout','reverse'],true))PropertyInput::invalid('kind');
        $common=['kind','house_id','accounting_date','effective_at','expected_revision','evidence_ref','reason'];$specific=match($request['kind']){'draw'=>['amount_cents','key','classification','ownership','cost_line_id','other_coverage','sources'],'income'=>['amount_cents','key','bank_id'],'transfer'=>['amount_cents','dispositions','purpose_ref','settlement_due_on','target'],'payout'=>['amount_cents','bank_id','dispositions','recipient_account','recipient_id'],'reverse'=>['operation_id']};PropertyInput::fields($request,[...$common,...$specific]);
        if($request['kind']==='draw'&&!in_array($request['classification']??null,['repair','improvement','administration'],true))PropertyInput::invalid('classification');
        PropertyInput::id($request['house_id']??null,'house_id');$date=$request['accounting_date']??null;if(!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||date('Y-m-d',strtotime($date))!==$date)PropertyInput::invalid('accounting_date');
        if(!is_int($request['expected_revision']??null)||$request['expected_revision']<0)PropertyInput::invalid('expected_revision');
        foreach(['evidence_ref','reason'] as $field)$request[$field]=PropertyInput::text($request[$field]??null,$field,4000);
        $request['effective_at']=PropertyInstant::normalize($request['effective_at']??FundingSourceProof::instant($date));
        if((new \DateTimeImmutable($request['effective_at']))->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d')!==$date)E::reject('effective_date');
        if($request['kind']!=='reverse')$request['amount_cents']=FundingSourceProof::cents($request['amount_cents']??null);
        return $request;
    }
    private function revision(int $supplier,int $house):int
    {return(int)($this->q('SELECT revision FROM property_funding_heads WHERE supplier_id=? AND house_id=? FOR UPDATE',[$supplier,$house])->fetchColumn()?:0);}
    public function preview(int $supplier,array $request,int $actor):array
    {return $this->tx($supplier,function()use($supplier,$request,$actor){$request=self::normalize($request);$plan=$this->plan($supplier,$request,$actor);return ['preview_hash'=>PropertyInput::hash($plan),'plan'=>$plan];});}
    public function apply(int $supplier,array $request,string $hash,string $token,int $actor):array
    {return $this->tx($supplier,function()use($supplier,$request,$hash,$token,$actor){
        $request=self::normalize($request);PropertyInput::code($token,'idempotency_key');PropertyInput::id($actor,'actor');$identity=PropertyInput::hash(['request'=>$request,'preview_hash'=>$hash,'token'=>$token,'actor'=>$actor]);
        $old=$this->q('SELECT id,request_hash FROM property_funding_operations WHERE supplier_id=? AND idempotency_key=? FOR UPDATE',[$supplier,$token])->fetch(PDO::FETCH_ASSOC);
        if($old){if($old['request_hash']!==$identity)E::reject('idempotency_conflict');return $this->operation($supplier,(int)$old['id']);}
        $plan=$this->plan($supplier,$request,$actor);if(!hash_equals($hash,PropertyInput::hash($plan)))E::reject('preview_stale');
        $this->q('INSERT INTO property_funding_heads(supplier_id,house_id,revision) VALUES(?,?,0) ON DUPLICATE KEY UPDATE supplier_id=VALUES(supplier_id)',[$supplier,$request['house_id']]);
        $this->q('INSERT INTO property_funding_operations(supplier_id,house_id,kind,accounting_date,effective_at,revision,request_json,request_hash,snapshot_json,snapshot_hash,idempotency_key,reversal_of,created_by,recorded_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$supplier,$request['house_id'],$request['kind'],$request['accounting_date'],PropertyInstant::sql($request['effective_at']),$request['expected_revision']+1,self::json($request),$identity,self::json($plan),$hash,$token,$request['kind']==='reverse'?$request['operation_id']:null,$actor]);$id=(int)$this->db->pdo()->lastInsertId();
        (new JournalEntryRepository($this->db,new \MyInvoice\Service\ActivityLogger($this->db)))->insertPropertyFunding(FundingProof::fromPending($this->db,$this,$supplier,$id));
        return $this->operation($supplier,$id);
    });}
    private function used(int $supplier,string $kind,int $id,string $role):string
    {if($kind==='cost'&&$role==='cost')return$this->coveredCost($supplier,$id);return(string)$this->q('SELECT COALESCE(SUM(c.amount_cents),0) FROM property_funding_claims c JOIN property_funding_operations o ON o.supplier_id=c.supplier_id AND o.id=c.operation_id WHERE c.supplier_id=? AND c.source_kind=? AND c.source_id=? AND c.claim_role=? AND o.receipt_json IS NOT NULL AND NOT EXISTS(SELECT 1 FROM property_funding_operations r WHERE r.supplier_id=o.supplier_id AND r.reversal_of=o.id AND r.receipt_json IS NOT NULL)',[$supplier,$kind,$id,$role])->fetchColumn();}
    public function coveredCost(int $supplier,int $line):string
    {$total='0';foreach($this->costClaims($supplier,$line)as$claim)$total=bcadd($total,$claim['amount_cents'],0);return$total;}
    public function costClaims(int $supplier,int $line):array
    {return$this->tx($supplier,function()use($supplier,$line){PropertyInput::id($line,'cost_line_id');$ids=$this->q("SELECT DISTINCT o.id FROM property_funding_claims c JOIN property_funding_operations o ON o.supplier_id=c.supplier_id AND o.id=c.operation_id WHERE c.supplier_id=? AND c.source_kind='cost' AND c.source_id=? AND c.claim_role='cost' AND o.receipt_json IS NOT NULL ORDER BY o.id FOR UPDATE",[$supplier,$line])->fetchAll(PDO::FETCH_COLUMN);$claims=[];foreach($ids as $id){$operation=$this->operation($supplier,(int)$id);if(!$operation['active'])continue;foreach($operation['receipt']['claims'] as $claim)if($claim['source_kind']==='cost'&&$claim['source_id']===$line&&$claim['claim_role']==='cost')$claims[]=['operation_id'=>$operation['id'],'cost_line_id'=>$line,'amount_cents'=>$claim['amount_cents'],'request_hash'=>$operation['request_hash'],'snapshot_hash'=>$operation['snapshot_hash'],'receipt_hash'=>$operation['receipt_hash']];}return$claims;});}
    private function capacity(int $supplier,string $kind,int $id,string $role,string $amount,string $total):void
    {if($kind==='cost'&&$role==='cost'){$available=(new \MyInvoice\Service\Accounting\Funding\CostCoverage($this->db))->capacity($supplier,$id)['available_cents'];if(bccomp($amount,(string)$available,0)>0)E::reject('capacity',['source_kind'=>$kind,'source_id'=>$id,'role'=>$role]);return;}if(bccomp(bcadd($this->used($supplier,$kind,$id,$role),$amount,0),$total,0)>0)E::reject('capacity',['source_kind'=>$kind,'source_id'=>$id,'role'=>$role]);}
    private function claim(array &$claims,string $kind,int $id,string $role,string $amount):void
    {$key=$kind.':'.$id.':'.$role;if(isset($claims[$key]))$claims[$key]['amount_cents']=bcadd($claims[$key]['amount_cents'],$amount,0);else$claims[$key]=['source_kind'=>$kind,'source_id'=>$id,'claim_role'=>$role,'amount_cents'=>$amount];}
    private function addEffect(array &$effects,int $unit,string $account,string $amount,?array $source=null,?array $parties=null):void
    {$effects[]=['unit_id'=>$unit,'account_code'=>$account,'amount_cents'=>$amount,'source_kind'=>$source['kind']??null,'source_id'=>$source['id']??null,'parties'=>$parties];}
    private function plan(int $supplier,array $r,int $actor):array
    {
        PropertyInput::id($actor,'actor');$house=$r['house_id'];$date=$r['accounting_date'];if($this->revision($supplier,$house)!==$r['expected_revision'])E::reject('revision_conflict');(new PaymentPostingDate($this->db))->assertOpen($supplier,$date);
        $source=new FundingSourceProof($this->db);$lines=[];$effects=[];$claims=[];$proofs=[];$sourceEntry=null;$kind=$r['kind'];$sourceType='property_funding';$sourceId=null;
        if($kind==='draw'||$kind==='income'){
            if(!is_array($r['key']??null))PropertyInput::invalid('key');$registry=$source->registry($supplier,$house,$date,$r['key'],$r['amount_cents']);if(PropertyInstant::normalize($r['key']['at'])>$r['effective_at'])E::reject('key_date');$proofs['registry']=$registry;
            $allocations=array_column($registry['calculation']['lines'],'amount_cents','unit_id');
        }
        if($kind==='draw'){
            $classification=$r['classification']??null;if($classification!=='administration')FrameworkOperationPolicy::assertAllowed(AccountRoleResolver::fromConnection($this->db)->forDate($supplier,$date),'svj.common_part.'.$classification,['ownership'=>$r['ownership']??null,'evidence_ref'=>$r['evidence_ref']]);
            if($classification==='administration'&&$r['key']['legal_basis']!=='own_administration_equal')E::reject('key_basis');
            $cost=$source->cost($supplier,PropertyInput::id($r['cost_line_id']??null,'cost_line_id'),$date,$classification);$proofs['cost']=$cost;$propertyTypes=$this->q("SELECT id,system_key FROM dimension_types WHERE supplier_id=? AND system_key IN ('unit','service')",[$supplier])->fetchAll(PDO::FETCH_ASSOC);foreach($propertyTypes as $type){$typeId=(int)$type['id'];if(isset($cost['line']['dimension_splits'][$typeId]))E::reject('cost_scope');if(isset($cost['line']['dimensions'][$typeId])){if($type['system_key']==='service')E::reject('cost_scope');$bound=$this->q("SELECT parent_id FROM property_objects WHERE supplier_id=? AND kind='unit' AND dimension_value_id=? FOR UPDATE",[$supplier,$cost['line']['dimensions'][$typeId]])->fetchColumn();if((int)$bound!==$house||count($allocations)!==1)E::reject('cost_scope');}}
            $targetPeriod=$this->q('SELECT id FROM accounting_periods WHERE supplier_id=? AND starts_on<=? AND ends_on>=? FOR UPDATE',[$supplier,$date,$date])->fetchColumn();if((int)$targetPeriod!==$cost['ledger']['entry']['period_id'])E::reject('cost_period');
            $other='0';foreach(PropertyInput::list($r['other_coverage']??[],'other_coverage') as $coverage){PropertyInput::fields($coverage,['line_id','amount_cents','evidence_ref']);PropertyInput::text($coverage['evidence_ref']??null,'other_coverage.evidence_ref',4000);$id=PropertyInput::id($coverage['line_id']??null,'other_coverage.line_id');$amount=FundingSourceProof::cents($coverage['amount_cents']??null);$native=$source->coverage($supplier,$id,$date);$ledger=$native['ledger'];$line=$native['line'];$this->capacity($supplier,'coverage',$id,'coverage',bcadd($amount,$claims['coverage:'.$id.':coverage']['amount_cents']??'0',0),bcmul($line['amount'],'100',0));$this->claim($claims,'coverage',$id,'coverage',$amount);$proofs['other_coverage'][]=['input'=>$coverage,'ledger'=>$ledger];$other=bcadd($other,$amount,0);}
            $proofs['cost_capacity_hash']=PropertyInput::hash((new \MyInvoice\Service\Accounting\Funding\CostCoverage($this->db))->capacity($supplier,$r['cost_line_id']));
            $this->capacity($supplier,'cost',$r['cost_line_id'],'cost',bcadd($r['amount_cents'],$other,0),bcsub($cost['amount_cents'],$cost['direct_coverage_cents'],0));$this->claim($claims,'cost',$r['cost_line_id'],'cost',bcadd($r['amount_cents'],$other,0));
            $byUnit=[];$pointers=[];foreach(PropertyInput::list($r['sources']??null,'sources') as $selection){PropertyInput::fields($selection,['source','amount_cents','purpose_evidence']);PropertyInput::text($selection['purpose_evidence']??null,'purpose_evidence',4000);$amount=FundingSourceProof::cents($selection['amount_cents']??null);if(!is_array($selection['source']??null))PropertyInput::invalid('source');$fund=$source->advance($supplier,$selection['source'],$date,$r['effective_at']);$unit=$fund['unit_id'];if(!isset($allocations[$unit]))E::reject('source_scope');$account=$classification==='administration'?'324.200':'955.100';if(($classification==='administration'&&$fund['account_code']!=='324.200')||($classification!=='administration'&&substr($fund['account_code'],0,3)!=='955'))E::reject('source_account');$account=$fund['account_code'];$pointer=$fund['pointer'];$this->claim($claims,$pointer['kind'],$pointer['id'],'advance',$amount);$pointers[$pointer['kind'].':'.$pointer['id']]=$fund;$byUnit[$unit]=bcadd($byUnit[$unit]??'0',$amount,0);$this->addEffect($effects,$unit,$account,'-'.$amount,$pointer);$proofs['advances'][]=$fund;}
            foreach($claims as $claim)if($claim['claim_role']==='advance'){$fund=$pointers[$claim['source_kind'].':'.$claim['source_id']];$this->capacity($supplier,$claim['source_kind'],$claim['source_id'],'advance',$claim['amount_cents'],$fund['amount_cents']);}
            foreach($allocations as $unit=>$amount)if(($byUnit[$unit]??'0')!==$amount)E::reject('key_amount',['unit_id'=>$unit]);
            $balances=(new \MyInvoice\Service\Property\Funds\FundingRead($this->db))->at($supplier,$house,$date);if(!$balances['matches'])E::reject('reconciliation');$spent=[];foreach($effects as $effect){$key=$effect['unit_id'].':'.$effect['account_code'];$spent[$key]=bcadd($spent[$key]??'0',ltrim($effect['amount_cents'],'-'),0);if(bccomp($spent[$key],$balances['unit_balances'][$effect['unit_id']][$effect['account_code']]??'0',0)>0)E::reject('balance_capacity');}$proofs['available_balances']=['accounting_date'=>$date,'unit_balances'=>$balances['unit_balances'],'ledger_totals'=>$balances['ledger_totals']];
        } elseif($kind==='income'){
            if($r['key']['method']!=='share'||$r['key']['legal_basis']!=='management_share')E::reject('income_key');$bank=$source->bank($supplier,PropertyInput::id($r['bank_id']??null,'bank_id'),$date,'in');if($bank['amount_cents']!==$r['amount_cents'])E::reject('bank_amount');$proofs['bank']=$bank;$sourceType='bank';$sourceId=$r['bank_id'];$this->claim($claims,'bank',$sourceId,'bank',$r['amount_cents']);
            $effects=FundingSourceProof::incomeEffects($registry);
        } elseif($kind==='transfer'||$kind==='payout'){
            $total='0';$incomeClaims=[];
            if($kind==='transfer'){
                if(!in_array($r['target']??null,['long_term','admin'],true))E::reject('transfer_target');PropertyInput::text($r['purpose_ref']??null,'purpose_ref',4000);
                if(!is_string($r['settlement_due_on']??null)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$r['settlement_due_on']))E::reject('advance_term');
                if(date('Y-m-d',strtotime($r['settlement_due_on']))!==$r['settlement_due_on']||$r['settlement_due_on']<$date)E::reject('advance_term');$long=$r['settlement_due_on']>(new \DateTimeImmutable($date))->modify('+1 year')->format('Y-m-d');if(($r['target']==='long_term')!==$long)E::reject('advance_term');
            }
            foreach(PropertyInput::list($r['dispositions']??null,'dispositions') as $d){
                PropertyInput::fields($d,['source_id','amount_cents','party_ids','evidence_ref','recipient_authority_ref']);$id=PropertyInput::id($d['source_id']??null,'disposition.source_id');$amount=FundingSourceProof::cents($d['amount_cents']??null);PropertyInput::text($d['evidence_ref']??null,'disposition.evidence_ref',4000);$income=$this->effect($supplier,$id);
                if(!FundingSourceProof::isIncomeOperation($income['operation'])||!$income['operation']['active']||$income['operation']['house_id']!==$house||$income['operation']['accounting_date']>$date||PropertyInstant::normalize(str_replace(' ','T',$income['operation']['effective_at']).'Z')>$r['effective_at']||$income['account_code']!=='379.920'||bccomp($income['amount_cents'],'0',0)<=0)E::reject('income_source');
                $members=$d['party_ids']??null;if(!is_array($members)||!array_is_list($members))PropertyInput::invalid('party_ids');foreach($members as $party)PropertyInput::id($party,'party_ids');sort($members,SORT_NUMERIC);$expected=$income['parties']['members']??[];sort($expected,SORT_NUMERIC);if($members!==$expected)E::reject('disposition_parties');
                if($kind==='payout'){$recipient=PropertyInput::id($r['recipient_id']??null,'recipient_id');if(!in_array($recipient,$members,true))PropertyInput::text($d['recipient_authority_ref']??null,'recipient_authority_ref',4000);if(count((new \MyInvoice\Repository\Property\PropertyReadRepository($this->db))->parties($supplier,[$recipient],true))!==1)E::reject('recipient');}
                $this->claim($claims,'income',$id,'income',$amount);$incomeClaims[$id]=$income;$this->addEffect($effects,$income['unit_id'],'379.920','-'.$amount,['kind'=>'income','id'=>$id],$income['parties']);
                if($kind==='transfer'){$target=$r['target']==='long_term'?'955.100':'324.200';$this->addEffect($effects,$income['unit_id'],$target,$amount,null,$income['parties']);}
                $total=bcadd($total,$amount,0);$proofs['income'][]=$income;
            }
            if($total!==$r['amount_cents'])E::reject('disposition_amount');foreach($claims as $claim)if($claim['claim_role']==='income')$this->capacity($supplier,'income',$claim['source_id'],'income',$claim['amount_cents'],$incomeClaims[$claim['source_id']]['amount_cents']);
            if($kind==='payout'){
                $bank=$source->bank($supplier,PropertyInput::id($r['bank_id']??null,'bank_id'),$date,'out');if($bank['amount_cents']!==$total)E::reject('bank_amount');
                $account=PropertyInput::text($r['recipient_account']??null,'recipient_account',80);if(!preg_match('#^([0-9]{1,6}-)?[0-9]{1,10}/[0-9]{4}$#D',$account))PropertyInput::invalid('recipient_account');[$number,$code]=explode('/',$account);$actual=\MyInvoice\Service\Bank\AccountNumberNormalizer::canonical((string)$bank['raw']['counterparty_account']).'/'.$bank['raw']['counterparty_bank'];if(\MyInvoice\Service\Bank\AccountNumberNormalizer::canonical($number).'/'.$code!==$actual)E::reject('recipient_account');
                $proofs['bank']=$bank;$sourceType='bank';$sourceId=$r['bank_id'];$this->claim($claims,'bank',$sourceId,'bank',$total);
            }
        } elseif($kind==='reverse'){
            $original=$this->operation($supplier,PropertyInput::id($r['operation_id']??null,'operation_id'));if(!$original['active']||$original['kind']==='reverse'||$original['house_id']!==$house||$date<$original['accounting_date'])E::reject('reversal_source');
            foreach($original['receipt']['effects'] as $effect)if(bccomp($effect['amount_cents'],'0',0)>0){$dependencyKind=$effect['account_code']==='379.920'?'income':'transfer';if($this->used($supplier,$dependencyKind,$effect['id'],$dependencyKind==='income'?'income':'advance')!=='0')E::reject('reversal_dependency');}
            $sourceEntry=$original['journal_entry_id'];$ledger=(new PaymentJournalProof($this->db))->current($supplier,$sourceEntry);$sourceType=$ledger['entry']['source_type'];$proofs['original']=$original;

            foreach($original['receipt']['effects'] as $effect)$this->addEffect($effects,$effect['unit_id'],$effect['account_code'],bcsub('0',$effect['amount_cents'],0),$effect['source_kind']===null?null:['kind'=>$effect['source_kind'],'id'=>$effect['source_id']],$effect['parties']);
        }
        $this->assertChronology($supplier,$house,$date,$effects);
        $this->assertClaims($r,array_values($claims));
        $period=$this->q("SELECT id FROM accounting_periods WHERE supplier_id=? AND starts_on<=? AND ends_on>=? AND status='open' FOR UPDATE",[$supplier,$date,$date])->fetchColumn();if(!$period)E::reject('period');
        $plan=['request'=>$r,'actor'=>$actor,'proofs'=>$proofs,'effects'=>$effects,'claims'=>array_values($claims),'journal'=>['header'=>['supplier_id'=>$supplier,'period_id'=>(int)$period,'entry_date'=>$date,'document_date'=>$date,'document_no'=>null,'description'=>'SVJ '.$kind,'source_type'=>$sourceType,'source_id'=>$sourceId],'lines'=>$lines,'source_entry_id'=>$sourceEntry]];$proof=new \MyInvoice\Service\Property\Funds\FundingJournalProof($this->db);$plan['journal']['lines']=$proof->lines($supplier,$plan,true);$proof->assertPlan($supplier,$plan);return $plan;
    }
    /**
     * Zpětné datum smí operace mít jen vůči zůstatkům, kterých se týká. Pozdější pohyb
     * jiného zdroje (např. lednový příjem na 379.920) prosincové čerpání z 955 nijak
     * neovlivní, kdežto pozdější pohyb téhož účtu jednotky by zpětně změnil zůstatek,
     * proti kterému proběhla kontrola kapacity.
     */
    private function assertChronology(int $supplier,int $house,string $date,array $effects):void
    {
        $pairs=[];
        foreach($effects as $effect){
            $pairs[$effect['unit_id'].':'.$effect['account_code']]=[$effect['unit_id'],$effect['account_code']];
        }
        if($pairs===[])return;
        $conditions=[];$args=[$supplier,$house,$date];
        foreach($pairs as [$unit,$account]){
            $conditions[]='(e.unit_id=? AND e.account_code=?)';
            array_push($args,$unit,$account);
        }
        $later=$this->q(
            'SELECT o.id FROM property_funding_operations o'
            .' JOIN property_funding_effects e ON e.supplier_id=o.supplier_id AND e.operation_id=o.id'
            .' WHERE o.supplier_id=? AND o.house_id=? AND o.accounting_date>? AND o.receipt_json IS NOT NULL'
            .' AND ('.implode(' OR ',$conditions).') LIMIT 1',
            $args
        )->fetchColumn();
        if($later!==false)E::reject('chronology');
    }
    private function assertClaims(array $r,array $actual):void
    {
        $expected=[];$amount=$r['amount_cents']??'0';
        if($r['kind']==='draw'){$cost=$amount;foreach($r['sources'] as $source)$this->claim($expected,$source['source']['kind'],$source['source']['id'],'advance',$source['amount_cents']);foreach($r['other_coverage']??[] as $coverage){$this->claim($expected,'coverage',$coverage['line_id'],'coverage',$coverage['amount_cents']);$cost=bcadd($cost,$coverage['amount_cents'],0);}$this->claim($expected,'cost',$r['cost_line_id'],'cost',$cost);}
        if(in_array($r['kind'],['income','payout'],true))$this->claim($expected,'bank',$r['bank_id'],'bank',$amount);
        if(in_array($r['kind'],['transfer','payout'],true))foreach($r['dispositions'] as $d)$this->claim($expected,'income',$d['source_id'],'income',$d['amount_cents']);
        $expected=array_values($expected);$sort=static fn($a,$b)=>[$a['source_kind'],$a['source_id'],$a['claim_role']]<=>[$b['source_kind'],$b['source_id'],$b['claim_role']];usort($actual,$sort);usort($expected,$sort);if(PropertyInput::hash($expected)!==PropertyInput::hash($actual))E::reject('claim_mismatch');
    }
    public function pending(Connection $db,int $supplier,int $id):array
    {
        if($db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction())throw new \LogicException('Funding pending requires owner connection.');$row=$this->q('SELECT * FROM property_funding_operations WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id])->fetch(PDO::FETCH_ASSOC);if(!$row||$row['journal_entry_id']!==null||$row['receipt_json']!==null)E::reject('pending');$request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);$snapshot=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);if(PropertyInput::hash(['request'=>$request,'preview_hash'=>$row['snapshot_hash'],'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']])!==$row['request_hash'])E::reject('history_hash');if(PropertyInput::hash($snapshot)!==$row['snapshot_hash']||PropertyInput::hash($this->plan($supplier,$request,(int)$row['created_by']))!==$row['snapshot_hash'])E::reject('source_changed');
        $journal=$snapshot['journal'];$journal['header']['posted_by']=(int)$row['created_by'];$journal['header']['posted_at']=$row['recorded_at'];if($journal['header']['source_type']==='property_funding'&&$journal['source_entry_id']===null)$journal['header']['source_id']=$id;
        return ['snapshot'=>$snapshot,'journal'=>$journal];
    }
    public function complete(Connection $db,int $supplier,int $id,int $entry):void
    {
        if($db->pdo()!==$this->db->pdo())throw new \LogicException('Funding completion requires owner connection.');$row=$this->q('SELECT * FROM property_funding_operations WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id])->fetch(PDO::FETCH_ASSOC);if(!$row||$row['receipt_json']!==null)E::reject('pending');$snapshot=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);$ledger=(new PaymentJournalProof($this->db))->current($supplier,$entry);
        if(PropertyInput::hash(PaymentJournalProof::canonicalLines($ledger['lines']))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($snapshot['journal']['lines']))||$ledger['entry']['entry_date']!==$row['accounting_date']||$ledger['entry']['source_type']!==$snapshot['journal']['header']['source_type']||$ledger['entry']['source_id']!==($snapshot['journal']['source_entry_id']!==null?null:($ledger['entry']['source_type']==='property_funding'?$id:$snapshot['journal']['header']['source_id'])))E::reject('journal_mismatch');
        $effects=[];foreach($snapshot['effects'] as $effect){$this->q('INSERT INTO property_funding_effects(supplier_id,operation_id,unit_id,account_code,amount_cents,source_kind,source_id,parties_json) VALUES(?,?,?,?,?,?,?,?)',[$supplier,$id,$effect['unit_id'],$effect['account_code'],$effect['amount_cents'],$effect['source_kind'],$effect['source_id'],$effect['parties']===null?null:self::json($effect['parties'])]);$effects[]=['id'=>(int)$this->db->pdo()->lastInsertId()]+$effect;}
        foreach($snapshot['claims'] as $claim)$this->q('INSERT INTO property_funding_claims(supplier_id,operation_id,source_kind,source_id,claim_role,amount_cents) VALUES(?,?,?,?,?,?)',[$supplier,$id,$claim['source_kind'],$claim['source_id'],$claim['claim_role'],$claim['amount_cents']]);
        $receipt=['ledger'=>$ledger,'effects'=>$effects,'claims'=>$snapshot['claims']];$update=$this->q('UPDATE property_funding_operations SET journal_entry_id=?,receipt_json=?,receipt_hash=? WHERE supplier_id=? AND id=? AND receipt_json IS NULL',[$entry,self::json($receipt),PropertyInput::hash($receipt),$supplier,$id]);if($update->rowCount()!==1)E::reject('pending');$head=$this->q('UPDATE property_funding_heads SET revision=? WHERE supplier_id=? AND house_id=? AND revision=?',[(int)$row['revision'],$supplier,(int)$row['house_id'],(int)$row['revision']-1]);if($head->rowCount()!==1)E::reject('revision_conflict');
    }
    /**
     * Ověřená operace fondu čtená aktuálním čtením. Důkaz prochází žádost, deník, účinky,
     * nároky i zdroje; přehled fondu a každá další operace ho opakují pro celou historii
     * domu. V jedné transakci se počítá znovu jen po zápisu nebo hranici transakce
     * ({@see \MyInvoice\Infrastructure\Database\CurrentReadMemo}).
     */
    public function operation(int $supplier,int $id):array
    {
        return \MyInvoice\Infrastructure\Database\CurrentReadMemo::remember($this->db,'funding-operation|'.$supplier.'|'.$id,fn():array=>$this->readOperation($supplier,$id));
    }
    private function readOperation(int $supplier,int $id):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Funding history requires a stable transaction.');$row=$this->q('SELECT * FROM property_funding_operations WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id])->fetch(PDO::FETCH_ASSOC);if(!$row||$row['receipt_json']===null)E::reject('history_missing');
        $request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);$snapshot=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);$receipt=json_decode($row['receipt_json'],true,flags:JSON_THROW_ON_ERROR);
        if(PropertyInput::hash($snapshot)!==$row['snapshot_hash']||PropertyInput::hash($receipt)!==$row['receipt_hash']||PropertyInput::hash(['request'=>$request,'preview_hash'=>$row['snapshot_hash'],'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']])!==$row['request_hash']||$snapshot['request']!==$request||$snapshot['actor']!==(int)$row['created_by'])E::reject('history_hash');
        $this->assertClaims($request,$snapshot['claims']);
        foreach(['house_id','kind','accounting_date'] as $field)if((string)$row[$field]!== (string)$request[$field])E::reject('history_hash');
        if((int)$row['revision']!==$request['expected_revision']+1||$row['effective_at']!==PropertyInstant::sql($request['effective_at'])||($row['reversal_of']===null?null:(int)$row['reversal_of'])!==($request['kind']==='reverse'?$request['operation_id']:null))E::reject('history_hash');
        $reversal=$this->q('SELECT id,journal_entry_id FROM property_funding_operations WHERE supplier_id=? AND reversal_of=? AND receipt_json IS NOT NULL FOR UPDATE',[$supplier,$id])->fetch(PDO::FETCH_ASSOC);$ledger=(new PaymentJournalProof($this->db))->current($supplier,(int)$row['journal_entry_id']);$expected=$receipt['ledger'];$expected['entry']['reversed_by']=$reversal?(int)$reversal['journal_entry_id']:null;
        $header=$snapshot['journal']['header'];$expectedSource=$snapshot['journal']['source_entry_id']!==null?null:($header['source_type']==='property_funding'?$id:$header['source_id']);if($ledger['entry']['period_id']!==$header['period_id']||$ledger['entry']['entry_date']!==$row['accounting_date']||$ledger['entry']['source_type']!==$header['source_type']||$ledger['entry']['source_id']!==$expectedSource)E::reject('journal_mismatch');
        if($request['kind']==='reverse'){$original=$this->operation($supplier,$request['operation_id']);if($snapshot['journal']['source_entry_id']!==$original['journal_entry_id']||$snapshot['proofs']['original']['id']!==$original['id'])E::reject('journal_mismatch');$native=(new PaymentJournalProof($this->db))->current($supplier,$original['journal_entry_id']);$mirror=array_map(JournalLineAmount::reversal(...),$native['lines']);if($native['entry']['reversed_by']!==(int)$row['journal_entry_id']||PropertyInput::hash(PaymentJournalProof::canonicalLines($mirror))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($ledger['lines'])))E::reject('journal_mismatch');}
        if(PropertyInput::hash($ledger)!==PropertyInput::hash($expected)||PropertyInput::hash(PaymentJournalProof::canonicalLines($ledger['lines']))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($snapshot['journal']['lines'])))E::reject('journal_mismatch');
        (new \MyInvoice\Service\Property\Funds\FundingJournalProof($this->db))->assertPlan($supplier,$snapshot,$ledger['lines']);
        $effects=$this->q('SELECT id,unit_id,account_code,amount_cents,source_kind,source_id,parties_json FROM property_funding_effects WHERE supplier_id=? AND operation_id=? ORDER BY id FOR UPDATE',[$supplier,$id])->fetchAll(PDO::FETCH_ASSOC);foreach($effects as &$effect){foreach(['id','unit_id','source_id'] as $field)if($effect[$field]!==null)$effect[$field]=(int)$effect[$field];$effect['amount_cents']=(string)$effect['amount_cents'];$effect['parties']=$effect['parties_json']===null?null:json_decode($effect['parties_json'],true,flags:JSON_THROW_ON_ERROR);unset($effect['parties_json']);}unset($effect);
        $claims=$this->q('SELECT source_kind,source_id,claim_role,amount_cents FROM property_funding_claims WHERE supplier_id=? AND operation_id=? ORDER BY source_kind,source_id,claim_role FOR UPDATE',[$supplier,$id])->fetchAll(PDO::FETCH_ASSOC);foreach($claims as &$claim){$claim['source_id']=(int)$claim['source_id'];$claim['amount_cents']=(string)$claim['amount_cents'];}unset($claim);$saved=$receipt['claims'];$sort=static fn($a,$b)=>[$a['source_kind'],$a['source_id'],$a['claim_role']]<=>[$b['source_kind'],$b['source_id'],$b['claim_role']];usort($saved,$sort);usort($claims,$sort);
        $snapshotEffects=$effects;foreach($snapshotEffects as &$effect)unset($effect['id']);unset($effect);$snapshotClaims=$snapshot['claims'];usort($snapshotClaims,$sort);
        if(PropertyInput::hash($snapshotEffects)!==PropertyInput::hash($snapshot['effects'])||PropertyInput::hash($snapshotClaims)!==PropertyInput::hash($saved)||PropertyInput::hash($effects)!==PropertyInput::hash($receipt['effects'])||PropertyInput::hash($claims)!==PropertyInput::hash($saved))E::reject('materialization');
        if(isset($snapshot['proofs']['bank']))(new FundingSourceProof($this->db))->assertPostedBank($supplier,$snapshot['proofs']['bank'],$request);
        if(!$reversal&&$row['kind']!=='reverse'){
            $source=new FundingSourceProof($this->db);
            if(isset($snapshot['proofs']['registry'])){$current=$source->registry($supplier,$request['house_id'],$row['accounting_date'],$request['key'],$request['amount_cents']);if(FundingSourceProof::registryHash($current)!==FundingSourceProof::registryHash($snapshot['proofs']['registry']))E::reject('registry_changed');}
            foreach($snapshot['proofs']['advances']??[] as $saved)if(PropertyInput::hash($source->advance($supplier,$saved['pointer'],$row['accounting_date'],$request['effective_at']))!==PropertyInput::hash($saved))E::reject('source_changed');
            if(isset($snapshot['proofs']['cost'])){$cost=$source->cost($supplier,$request['cost_line_id'],$row['accounting_date'],$request['classification']);if(PropertyInput::hash($cost)!==PropertyInput::hash($snapshot['proofs']['cost']))E::reject('source_changed');}
            foreach($snapshot['proofs']['income']??[] as $saved)if(PropertyInput::hash($this->effect($supplier,$saved['id']))!==PropertyInput::hash($saved))E::reject('source_changed');
            foreach($snapshot['proofs']['other_coverage']??[] as $saved){$native=$source->coverage($supplier,$saved['input']['line_id'],$row['accounting_date'])['ledger'];if(PropertyInput::hash($native)!==PropertyInput::hash($saved['ledger']))E::reject('source_changed');}

        }
        foreach(['id','supplier_id','house_id','revision','created_by','journal_entry_id','reversal_of'] as $field)if($row[$field]!==null)$row[$field]=(int)$row[$field];unset($row['request_json'],$row['snapshot_json'],$row['receipt_json']);return $row+['request'=>$request,'snapshot'=>$snapshot,'receipt'=>$receipt,'active'=>$reversal===false];
    }
    public function effect(int $supplier,int $id):array
    {$row=$this->q('SELECT operation_id FROM property_funding_effects WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id])->fetchColumn();if(!$row)E::reject('income_source');$operation=$this->operation($supplier,(int)$row);$effects=array_column($operation['receipt']['effects'],null,'id');return ($effects[$id]??throw new E('property_funding_materialization'))+['operation'=>$operation];}
    public function list(int $supplier,int $house):array
    {return $this->tx($supplier,function()use($supplier,$house){$ids=$this->q('SELECT id FROM property_funding_operations WHERE supplier_id=? AND house_id=? AND receipt_json IS NOT NULL ORDER BY id FOR UPDATE',[$supplier,$house])->fetchAll(PDO::FETCH_COLUMN);return ['revision'=>$this->revision($supplier,$house),'items'=>array_map(fn($id)=>$this->operation($supplier,(int)$id),$ids)];});}
}