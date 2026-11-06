<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Settlement;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Service\Accounting\JournalLineAmount;
final class SettlementHistory
{
    use SettlementSql;
    public function __construct(private readonly Connection $db){}
    public function lifecycle(int $supplier,int $case):array
    {return(new \MyInvoice\Repository\Property\Services\Settlement\ServiceLifecycleRepository($this->db))->current($supplier,$case);}
    /** Hloubka probíhajícího čtení celé historie ({@see inReadScope()}). */
    private static int $readScope=0;
    /** @var array{pdo:\PDO,writes:int,applications:array<string,array>}|null snímky vyúčtování v jednom čtení historie */
    private static ?array $scopeMemo=null;
    /**
     * Jedno čtení finanční historie čte totéž vyúčtování u každého dluhu a kreditu, který
     * z něj vzešel. Uvnitř jednoho takového čtení se snímek vyúčtování ověřuje jednou.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function inReadScope(callable $work): mixed
    {
        self::$readScope++;
        try { return $work(); }
        finally { if(--self::$readScope===0)self::$scopeMemo=null; }
    }
    /**
     * Ověřené vyúčtování. Důkaz prochází žádost, deník, účinky, kredity, nároky i podklady
     * nákladu. Aktuální čtení se v jedné transakci opakuje jen po zápisu nebo hranici
     * transakce ({@see \MyInvoice\Infrastructure\Database\CurrentReadMemo}), snímek jen
     * v rámci jednoho čtení historie ({@see inReadScope()}).
     */
    public function application(int $supplier,int $id,bool $current):array
    {
        if($current)return \MyInvoice\Infrastructure\Database\CurrentReadMemo::remember($this->db,'settlement-application|'.$supplier.'|'.$id,fn():array=>$this->readApplication($supplier,$id,true));
        $pdo=$this->db->pdo();
        if(self::$readScope===0||!$pdo instanceof \MyInvoice\Infrastructure\Database\LoggingPdo||!$pdo->statementsTracked()||!$pdo->inTransaction())return $this->readApplication($supplier,$id,false);
        $key=$supplier.'|'.$id;
        if(self::$scopeMemo===null||self::$scopeMemo['pdo']!==$pdo||self::$scopeMemo['writes']!==$pdo->writeCount())self::$scopeMemo=['pdo'=>$pdo,'writes'=>$pdo->writeCount(),'applications'=>[]];
        if(isset(self::$scopeMemo['applications'][$key]))return self::$scopeMemo['applications'][$key];
        $application=$this->readApplication($supplier,$id,false);
        if(self::$scopeMemo!==null&&self::$scopeMemo['pdo']===$pdo&&self::$scopeMemo['writes']===$pdo->writeCount())self::$scopeMemo['applications'][$key]=$application;
        return $application;
    }
    private function readApplication(int $supplier,int $id,bool $current):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Settlement history requires a stable transaction.');$suffix=$current?' FOR UPDATE':'';
        $row=$this->one('SELECT * FROM property_service_settlement_applications WHERE supplier_id=? AND id=?'.$suffix,[$supplier,$id]);if(!$row)SettlementEvidence::reject('application_not_found');
        $request=$this->one('SELECT * FROM property_service_settlement_requests WHERE supplier_id=? AND id=?'.$suffix,[$supplier,$row['request_id']]);if(!$request)SettlementEvidence::reject('history_corrupt');
        $receipt=self::decode($row['receipt_json']);$envelope=self::decode($request['request_json']);$preview=self::decode($request['preview_json']);
        if(PropertyInput::hash($receipt)!==$row['receipt_hash']||PropertyInput::hash($envelope)!==$row['request_hash']||$request['request_hash']!==$row['request_hash']||PropertyInput::hash($preview)!==$request['preview_hash']||PropertyInput::hash($receipt['preview'])!==$request['preview_hash']||$request['document_id']!==$row['document_id']||$request['reversal_of']!==$row['reversal_of']||$request['created_by']!==$row['created_by']||$request['idempotency_key']!==$row['idempotency_key'])SettlementEvidence::reject('history_corrupt');
        $reversed=$this->one('SELECT id,journal_entry_id FROM property_service_settlement_applications WHERE supplier_id=? AND reversal_of=?'.$suffix,[$supplier,$id]);
        $ledger=(new PaymentJournalProof($this->db))->snapshot($supplier,(int)$row['journal_entry_id'],$current);$expected=$receipt['ledger'];$expected['entry']['reversed_by']=$reversed?(int)$reversed['journal_entry_id']:null;
        if(PropertyInput::hash($ledger)!==PropertyInput::hash($expected)||$ledger['entry']['source_type']!=='property_service_settlement'||$ledger['entry']['source_id']!==($row['reversal_of']===null?(int)$row['request_id']:null)||$ledger['entry']['entry_date']!==$row['accounting_date']||$ledger['entry']['period_id']!==(int)$row['period_id']||PropertyInput::hash(PaymentJournalProof::canonicalLines($ledger['lines']))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($preview['plan']['lines'])))SettlementEvidence::reject('ledger_mismatch');
        if($row['reversal_of']===null&&isset($preview['plan']['document']['cost']['sources'])){$plan=$preview['plan'];$input=$plan['document'];unset($input['amounts']);$canonical=SettlementPlanner::calculate(SettlementDocument::fromEvidence($input),$plan['current'],$plan['request']);if(PropertyInput::hash($canonical)!==PropertyInput::hash($plan))SettlementEvidence::reject('history_corrupt');$container=\MyInvoice\Bootstrap::buildContainer();$container->set(Connection::class,$this->db);(new SettlementCostProof($this->db))->assertRecorded($supplier,$input['unit_id'],$input['service_id'],$row['accounting_date'],['from'=>$input['period_from'],'to'=>$input['period_to']],$input['cost'],$container->get(SettlementOriginAdapter::class));}

        $expectedEffects=$row['reversal_of']===null?self::effects($preview,$receipt['ledger']):[];
        if(PropertyInput::hash($expectedEffects)!==PropertyInput::hash($receipt['effects']))SettlementEvidence::reject('history_corrupt');
        $effects=$this->rows('SELECT * FROM property_service_settlement_effects WHERE supplier_id=? AND application_id=? ORDER BY ordinal'.$suffix,[$supplier,$id]);if(count($effects)!==count($expectedEffects))SettlementEvidence::reject('history_corrupt');
        foreach($effects as $i=>&$effect){$fact=$expectedEffects[$i];if($effect['effect_hash']!==PropertyInput::hash($fact)||PropertyInput::hash(self::decode($effect['effect_json']))!==$effect['effect_hash']||(int)$effect['ordinal']!==$i)SettlementEvidence::reject('history_corrupt');foreach(['kind','debt_id','charge_line_id','opening_component_id','allocation_id','amount_cents'] as $key)if(($fact[$key]===null?null:(is_int($fact[$key])?(int)$effect[$key]:$effect[$key]))!==$fact[$key]||($fact[$key]===null&&$effect[$key]!==null))SettlementEvidence::reject('history_corrupt');$effect=$fact+['id'=>(int)$effect['id']];}unset($effect);
        $credits=$this->rows('SELECT l.* FROM unit_credit_lots l JOIN property_service_settlement_effects e ON e.supplier_id=l.supplier_id AND e.id=l.settlement_effect_id WHERE e.supplier_id=? AND e.application_id=? ORDER BY l.id'.$suffix,[$supplier,$id]);
        $expectedCredits=array_filter($effects,fn($e)=>isset($e['credit']));if(count($credits)!==count($expectedCredits))SettlementEvidence::reject('history_corrupt');$byEffect=array_column($effects,null,'id');foreach($credits as $credit){$fact=$byEffect[(int)$credit['settlement_effect_id']]['credit']??null;if(!$fact||$credit['operation_id']!==null||$credit['opening_item_id']!==null||PropertyInput::hash($fact)!==$credit['snapshot_hash']||PropertyInput::hash(self::decode($credit['snapshot_json']))!==$credit['snapshot_hash']||(int)$credit['unit_id']!==$fact['unit_id']||$credit['amount_cents']!==$fact['amount_cents']||$credit['due_date']!==$fact['due_date'])SettlementEvidence::reject('credit_origin');}
        $debts=$this->rows('SELECT d.* FROM unit_payment_debts d JOIN property_service_settlement_effects e ON e.supplier_id=d.supplier_id AND e.id=d.settlement_effect_id WHERE e.supplier_id=? AND e.application_id=? ORDER BY d.id'.$suffix,[$supplier,$id]);
        if(count($debts)!==count(array_filter($effects,fn($e)=>isset($e['claim']))))SettlementEvidence::reject('claim_origin');
        foreach($debts as $debt){$claim=$byEffect[(int)$debt['settlement_effect_id']]['claim']??null;if(!$claim||$debt['charge_id']!==null||$debt['opening_item_id']!==null)SettlementEvidence::reject('claim_origin');$fact=self::debtSnapshot((int)$debt['settlement_effect_id'],$claim,(int)$row['journal_entry_id']);if(PropertyInput::hash($fact)!==$debt['snapshot_hash']||PropertyInput::hash(self::decode($debt['snapshot_json']))!==$debt['snapshot_hash'])SettlementEvidence::reject('claim_origin');$r=self::decode($debt['request_json']);if($r!==['settlement_effect_id'=>(int)$debt['settlement_effect_id']]||PropertyInput::hash(['request'=>$r,'token'=>$debt['idempotency_key'],'actor'=>(int)$debt['created_by']])!==$debt['request_hash']||$debt['created_by']!==$row['created_by'])SettlementEvidence::reject('claim_origin');}
        if($row['reversal_of']!==null){
            if($reversed)SettlementEvidence::reject('history_corrupt');
            $original=$this->one('SELECT * FROM property_service_settlement_applications WHERE supplier_id=? AND id=?'.$suffix,[$supplier,$row['reversal_of']]);
            if(!$original||$original['reversal_of']!==null||$original['document_id']!==$row['document_id'])SettlementEvidence::reject('history_corrupt');
            $originalReceipt=self::decode($original['receipt_json']);
            if(PropertyInput::hash($originalReceipt)!==$original['receipt_hash']||($preview['application_id']??null)!==(int)$original['id']||($preview['application_hash']??null)!==$original['receipt_hash']||($preview['source_entry_id']??null)!==(int)$original['journal_entry_id']||($preview['document_id']??null)!==(int)$original['document_id']||($envelope['application_id']??null)!==(int)$original['id']||($envelope['accounting_date']??null)!==$row['accounting_date']||$row['accounting_date']<$original['accounting_date'])SettlementEvidence::reject('history_corrupt');
            $originalLedger=(new PaymentJournalProof($this->db))->snapshot($supplier,(int)$original['journal_entry_id'],$current);$expectedOriginal=$originalReceipt['ledger'];$expectedOriginal['entry']['reversed_by']=(int)$row['journal_entry_id'];
            $reversalLines=array_map(fn($line)=>JournalLineAmount::reversal($line),$originalReceipt['preview']['plan']['lines']);
            if(PropertyInput::hash($originalLedger)!==PropertyInput::hash($expectedOriginal)||PropertyInput::hash(PaymentJournalProof::canonicalLines($reversalLines))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($preview['plan']['lines'])))SettlementEvidence::reject('ledger_mismatch');
        }elseif($reversed){
            $reverse=$this->application($supplier,(int)$reversed['id'],$current);
            if($reverse['reversal_of']!==$id||$reverse['document_id']!==(int)$row['document_id'])SettlementEvidence::reject('history_corrupt');
        }
        return ['id'=>$id,'supplier_id'=>$supplier,'document_id'=>(int)$row['document_id'],'request_id'=>(int)$row['request_id'],'reversal_of'=>$row['reversal_of']===null?null:(int)$row['reversal_of'],'active'=>$row['reversal_of']===null&&!$reversed,'journal_entry_id'=>(int)$row['journal_entry_id'],'accounting_date'=>$row['accounting_date'],'recorded_at'=>$row['recorded_at'],'receipt_hash'=>$row['receipt_hash'],'receipt'=>$receipt,'effects'=>$effects,'credit_ids'=>array_map(fn($c)=>(int)$c['id'],$credits),'debt_ids'=>array_map(fn($d)=>(int)$d['id'],$debts)];
    }
    public function hasActiveApplicationForDocument(int $supplier,int $document):bool
    {
        $active=false;foreach($this->rows('SELECT id FROM property_service_settlement_applications WHERE supplier_id=? AND document_id=? AND reversal_of IS NULL ORDER BY id FOR UPDATE',[$supplier,$document]) as $row)if($this->application($supplier,(int)$row['id'],true)['active'])$active=true;return $active;
    }
    public function assertOriginAvailable(int $supplier,array $source):void
    {
        $rows=$this->rows('SELECT DISTINCT a.id FROM property_service_settlement_origins o JOIN property_service_settlement_applications a ON a.supplier_id=o.supplier_id AND a.document_id=o.document_id WHERE o.supplier_id=? AND a.reversal_of IS NULL AND (o.charge_line_id=? OR o.opening_component_id=? OR o.opening_advance_component_id=?) ORDER BY a.id FOR UPDATE',[$supplier,$source['kind']==='s3_line'?$source['id']:null,$source['kind']==='opening_component'?$source['id']:null,$source['opening_advance_component_id']]);
        foreach($rows as $row)if($this->application($supplier,(int)$row['id'],true)['active'])SettlementEvidence::reject('origin_already_disposed');
    }
    public function assertCostAvailable(int $supplier,int $cost):void
    {
        $rows=$this->rows("SELECT a.id FROM property_service_settlement_applications a JOIN property_service_settlements d ON d.supplier_id=a.supplier_id AND d.id=a.document_id WHERE a.supplier_id=? AND (d.cost_line_id=? OR EXISTS (SELECT 1 FROM JSON_TABLE(d.document_json,'$.cost.sources[*]' COLUMNS(line_id BIGINT PATH '$.source_id')) cs WHERE cs.line_id=?)) AND a.reversal_of IS NULL ORDER BY a.id FOR UPDATE",[$supplier,$cost,$cost]);
        foreach($rows as $row)if($this->application($supplier,(int)$row['id'],true)['active'])SettlementEvidence::reject('cost_already_disposed');
    }
    public const SELECTED_TABLES=['property_service_settlements','property_service_settlement_origins','property_service_settlement_applications','property_service_settlement_requests','property_service_settlement_effects','property_service_settlement_ledgers','unit_payment_debts','unit_payment_debt_lines','unit_credit_lots','unit_credit_parties','unit_charge_lines','unit_charges','property_opening_components','property_opening_items','property_opening_batches'];
    public static function selectedMaterializations(array $rows):?int
    {
        $service=($rows['property_service_settlements']??[])!==[]||($rows['property_service_settlement_applications']??[])!==[]||($rows['property_service_settlement_effects']??[])!==[];
        foreach(['unit_payment_debts','unit_credit_lots'] as $table)foreach($rows[$table]??[] as $row)if(($row['settlement_effect_id']??null)!==null)$service=true;
        if(!$service)return null;
        $suppliers=[];foreach(self::SELECTED_TABLES as $table)foreach($rows[$table]??[] as $row){if(!isset($row['supplier_id'])||(int)$row['supplier_id']<=0)SettlementEvidence::reject('history_corrupt');$suppliers[(int)$row['supplier_id']]=true;}
        if(count($suppliers)!==1)SettlementEvidence::reject('history_corrupt');$supplier=(int)array_key_first($suppliers);
        $documents=array_column($rows['property_service_settlements']??[],null,'id');$applications=array_column($rows['property_service_settlement_applications']??[],null,'id');$effects=array_column($rows['property_service_settlement_effects']??[],null,'id');$requests=array_column($rows['property_service_settlement_requests']??[],null,'id');$ledgers=array_column($rows['property_service_settlement_ledgers']??[],null,'id');$charges=array_column($rows['unit_charges']??[],null,'id');$chargeLines=array_column($rows['unit_charge_lines']??[],null,'id');$components=array_column($rows['property_opening_components']??[],null,'id');$items=array_column($rows['property_opening_items']??[],null,'id');$batches=array_column($rows['property_opening_batches']??[],null,'id');
        $origins=[];foreach($rows['property_service_settlement_origins']??[] as $row)$origins[(int)$row['document_id']][(int)$row['ordinal']]=$row;
        foreach($documents as $id=>$row){
            $facts=self::decode($row['document_json']);$receipt=self::decode($row['origin_receipt_json']);$current=$receipt['current'];$hash=$current['current_hash'];unset($current['current_hash']);$input=$facts;unset($input['amounts']);
            if(PropertyInput::hash($facts)!==$row['document_hash']||SettlementDocument::fromEvidence($input)->hash()!==$row['document_hash']||PropertyInput::hash($receipt)!==$row['origin_receipt_hash']||PropertyInput::hash($receipt['request'])!==$row['request_hash']||PropertyInput::hash($current)!==$hash||PropertyInput::hash($current['origins'])!==PropertyInput::hash($facts['origins'])||$facts['supplier_id']!==$supplier||$facts['unit_id']!==(int)$row['unit_id']||$facts['service_id']!==(int)$row['service_id']||$facts['segment_key']!==$row['segment_key']||$facts['cost']['source_id']!==(int)$row['cost_line_id']||count($origins[$id]??[])!==count($facts['origins']))SettlementEvidence::reject('history_corrupt');
            foreach($facts['origins'] as $ordinal=>$source){
                $link=$origins[$id][$ordinal]??null;$expected=['charge_line_id'=>$source['kind']==='s3_line'?$source['id']:null,'opening_component_id'=>$source['kind']==='opening_component'?$source['id']:null,'opening_advance_component_id'=>$source['opening_advance_component_id'],'debt_id'=>$source['debt_id']];if(!$link)SettlementEvidence::reject('history_corrupt');foreach($expected as $column=>$value)if(($link[$column]===null?null:(int)$link[$column])!==$value)SettlementEvidence::reject('history_corrupt');
                $key=$source['kind'].':'.$source['id'];$evidence=$current['evidence'][$key]??null;if(!$evidence)SettlementEvidence::reject('history_corrupt');
                if($source['kind']==='s3_line'){
                    $line=$chargeLines[$source['id']]??null;$charge=$line?($charges[(int)$line['charge_id']]??null):null;$ledger=$charge?($ledgers[(int)$charge['journal_entry_id']]['snapshot']??null):null;
                    if(!$charge||!$ledger||(int)$charge['id']!==$evidence['charge_id']||$charge['snapshot_hash']!==$evidence['snapshot_hash']||(int)$charge['unit_id']!==$facts['unit_id']||PropertyInput::hash($ledger)!==$evidence['ledger_hash'])SettlementEvidence::reject('history_corrupt');
                }else{
                    $advance=$components[$source['opening_advance_component_id']]??null;$principal=$components[$source['id']]??null;$advanceItem=$advance?($items[(int)$advance['item_id']]??null):null;$principalItem=$principal?($items[(int)$principal['item_id']]??null):null;
                    if(!$advanceItem||!$principalItem||$advanceItem['batch_id']!==$principalItem['batch_id']||!isset($batches[(int)$advanceItem['batch_id']])||PropertyInput::hash($evidence['state'])!==$source['opening_state_hash'])SettlementEvidence::reject('history_corrupt');
                }
            }
            foreach(SettlementEvidence::costSources($facts['cost']) as $part){$cost=null;foreach($ledgers as $ledger)foreach($ledger['snapshot']['lines'] as $line)if($line['id']===$part['source_id']){$cost=$line;if($ledger['snapshot']['entry']['reversed_by']!==null)SettlementEvidence::reject('ledger_mismatch');}if(!$cost||PropertyInput::hash($cost)!==$part['source_hash'])SettlementEvidence::reject('ledger_mismatch');}
        }
        $receipts=[];$byApplication=[];$reversals=[];
        foreach($effects as $effect){if(!isset($applications[(int)$effect['application_id']]))SettlementEvidence::reject('history_corrupt');$byApplication[(int)$effect['application_id']][(int)$effect['ordinal']]=$effect;}
        foreach($applications as $app)if($app['reversal_of']!==null){if(isset($reversals[(int)$app['reversal_of']]))SettlementEvidence::reject('history_corrupt');$reversals[(int)$app['reversal_of']]=$app;}
        foreach($applications as $id=>$app){
            $receipt=self::decode($app['receipt_json']);$request=$requests[(int)$app['request_id']]??null;$document=$documents[(int)$app['document_id']]??null;$ledger=$ledgers[(int)$app['journal_entry_id']]['snapshot']??null;
            if(!$request||!$document||!$ledger||PropertyInput::hash($receipt)!==$app['receipt_hash']||$request['request_hash']!==$app['request_hash']||$request['document_id']!==$app['document_id']||$request['reversal_of']!==$app['reversal_of']||PropertyInput::hash(self::decode($request['request_json']))!==$request['request_hash']||PropertyInput::hash(self::decode($request['preview_json']))!==$request['preview_hash']||PropertyInput::hash($receipt['preview'])!==$request['preview_hash'])SettlementEvidence::reject('history_corrupt');
            $expected=$receipt['ledger'];$expected['entry']['reversed_by']=isset($reversals[$id])?(int)$reversals[$id]['journal_entry_id']:null;if(PropertyInput::hash($expected)!==PropertyInput::hash($ledger))SettlementEvidence::reject('ledger_mismatch');
            $expectedEffects=$app['reversal_of']===null?self::effects($receipt['preview'],$receipt['ledger']):[];
            if(PropertyInput::hash($expectedEffects)!==PropertyInput::hash($receipt['effects'])||count($byApplication[$id]??[])!==count($expectedEffects))SettlementEvidence::reject('history_corrupt');
            foreach($expectedEffects as $ordinal=>$fact){$effect=$byApplication[$id][$ordinal]??null;if(!$effect||PropertyInput::hash($fact)!==$effect['effect_hash']||PropertyInput::hash(self::decode($effect['effect_json']))!==$effect['effect_hash'])SettlementEvidence::reject('history_corrupt');foreach(['kind','debt_id','charge_line_id','opening_component_id','allocation_id','amount_cents'] as $column)if(($fact[$column]===null?null:(is_int($fact[$column])?(int)$effect[$column]:$effect[$column]))!==$fact[$column]||($fact[$column]===null&&$effect[$column]!==null))SettlementEvidence::reject('history_corrupt');}
            if($app['reversal_of']===null){if($receipt['preview']['document_hash']!==$document['document_hash']||PropertyInput::hash($receipt['preview']['plan']['document'])!==$document['document_hash'])SettlementEvidence::reject('history_corrupt');}
            else{
                $original=$applications[(int)$app['reversal_of']]??null;$originalReceipt=$original?self::decode($original['receipt_json']):null;
                if(!$original||$original['reversal_of']!==null||$original['document_id']!==$app['document_id']||$receipt['preview']['application_hash']!==$original['receipt_hash']||$receipt['preview']['source_entry_id']!==(int)$original['journal_entry_id'])SettlementEvidence::reject('history_corrupt');
                $lines=array_map(fn($line)=>JournalLineAmount::reversal($line),$originalReceipt['preview']['plan']['lines']);if(PropertyInput::hash(PaymentJournalProof::canonicalLines($lines))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($receipt['preview']['plan']['lines'])))SettlementEvidence::reject('ledger_mismatch');
            }
            $receipts[$id]=$receipt;
        }
        $bindings=[];foreach($rows['unit_payment_debt_lines']??[] as $row)$bindings[(int)$row['debt_id']][]=$row;
        $debtEffects=[];$creditEffects=[];
        foreach($rows['unit_payment_debts']??[] as $debt)if(($debt['settlement_effect_id']??null)!==null){
            $debtEffects[(int)$debt['settlement_effect_id']]=($debtEffects[(int)$debt['settlement_effect_id']]??0)+1;
            $effect=$effects[(int)$debt['settlement_effect_id']]??null;$app=$effect?($applications[(int)$effect['application_id']]??null):null;$fact=$effect?self::decode($effect['effect_json']):null;
            if(!$app||!isset($fact['claim'])||$app['reversal_of']!==null||$debt['charge_id']!==null||$debt['opening_item_id']!==null||($bindings[(int)$debt['id']]??[])!==[])SettlementEvidence::reject('claim_origin');
            $expected=self::debtSnapshot((int)$effect['id'],$fact['claim'],(int)$app['journal_entry_id']);$request=['settlement_effect_id'=>(int)$effect['id']];
            if(PropertyInput::hash($expected)!==$debt['snapshot_hash']||PropertyInput::hash(self::decode($debt['snapshot_json']))!==$debt['snapshot_hash']||self::decode($debt['request_json'])!==$request||PropertyInput::hash(['request'=>$request,'token'=>$debt['idempotency_key'],'actor'=>(int)$debt['created_by']])!==$debt['request_hash']||$debt['created_by']!==$app['created_by'])SettlementEvidence::reject('claim_origin');
        }
        $parties=[];foreach($rows['unit_credit_parties']??[] as $row){$party=self::decode($row['party_json']);if(($party['id']??null)!==(int)$row['client_id']||isset($parties[(int)$row['credit_id']][(int)$row['client_id']]))SettlementEvidence::reject('credit_origin');$parties[(int)$row['credit_id']][(int)$row['client_id']]=$party;}
        foreach($rows['unit_credit_lots']??[] as $credit)if(($credit['settlement_effect_id']??null)!==null){
            $creditEffects[(int)$credit['settlement_effect_id']]=($creditEffects[(int)$credit['settlement_effect_id']]??0)+1;
            $effect=$effects[(int)$credit['settlement_effect_id']]??null;$fact=$effect?self::decode($effect['effect_json']):null;$app=$effect?($applications[(int)$effect['application_id']]??null):null;
            if(!$app||$app['reversal_of']!==null||!isset($fact['credit'])||$credit['operation_id']!==null||$credit['opening_item_id']!==null||PropertyInput::hash($fact['credit'])!==$credit['snapshot_hash']||PropertyInput::hash(self::decode($credit['snapshot_json']))!==$credit['snapshot_hash']||$credit['amount_cents']!==$fact['credit']['amount_cents']||(int)$credit['unit_id']!==$fact['credit']['unit_id']||$credit['due_date']!==$fact['credit']['due_date']||PropertyInput::hash(array_column($fact['credit']['parties'],null,'id'))!==PropertyInput::hash($parties[(int)$credit['id']]??[]))SettlementEvidence::reject('credit_origin');
        }
        foreach($effects as $id=>$effect){$fact=self::decode($effect['effect_json']);if(isset($fact['claim'])&&($debtEffects[$id]??0)!==1)SettlementEvidence::reject('claim_origin');if(isset($fact['credit'])&&($creditEffects[$id]??0)!==1)SettlementEvidence::reject('credit_origin');}
        return $supplier;
    }

    public function credit(int $supplier,array $row,bool $current):array
    {
        $suffix=$current?' FOR UPDATE':'';$effect=$this->one('SELECT application_id FROM property_service_settlement_effects WHERE supplier_id=? AND id=?'.$suffix,[$supplier,$row['settlement_effect_id']]);if(!$effect)SettlementEvidence::reject('credit_origin');
        $app=$this->application($supplier,(int)$effect['application_id'],$current);if(!in_array((int)$row['id'],$app['credit_ids'],true))SettlementEvidence::reject('credit_origin');return $app;
    }
    public function debt(int $supplier,array $row,bool $current):array
    {
        $effect=$this->one('SELECT application_id FROM property_service_settlement_effects WHERE supplier_id=? AND id=?'.($current?' FOR UPDATE':''),[$supplier,$row['settlement_effect_id']]);if(!$effect)SettlementEvidence::reject('claim_origin');$app=$this->application($supplier,(int)$effect['application_id'],$current);if(!in_array((int)$row['id'],$app['debt_ids'],true))SettlementEvidence::reject('claim_origin');return $app;
    }
    public function currentDebt(int $supplier,array $row,array $allocations,array $accessories):array
    {
        $app=$this->debt($supplier,$row,true);$fact=self::decode($row['snapshot_json']);$paid=[];$groups=[];$receipts=[];
        foreach($accessories as $accessory){$r=self::decode($accessory['receipt_json']);if(PropertyInput::hash($r)!==$accessory['receipt_hash']||$r['debt_id']!==(int)$row['id']||$r['line']['id']!==(int)$accessory['journal_line_id']||bcmul($r['line']['amount'],'100',0)!==$accessory['amount_cents']||PropertyInput::hash($r['liability_groups'])!==PropertyInput::hash($fact['liability_groups']))SettlementEvidence::reject('claim_origin');(new PaymentJournalProof($this->db))->assertSame($supplier,$r['ledger']);$receipts[(int)$accessory['id']]=['id'=>(int)$accessory['id'],'component'=>$accessory['component'],'amount_cents'=>$accessory['amount_cents'],'receipt'=>$r];}
        foreach($allocations as $a){if($a['charge_id']!==null||$a['charge_line_id']!==null||$a['opening_component_id']!==null)SettlementEvidence::reject('claim_origin');if($a['accessory_id']!==null){if(!isset($receipts[(int)$a['accessory_id']]))SettlementEvidence::reject('claim_origin');$key='accessory:'.$a['accessory_id'];}else{if((int)$a['settlement_effect_id']!==(int)$row['settlement_effect_id'])SettlementEvidence::reject('claim_origin');$key='principal:'.$row['settlement_effect_id'];}$paid[$key]=bcadd($paid[$key]??'0',$a['amount_cents'],0);$groups[$a['liability_group']]=bcadd($groups[$a['liability_group']]??'0',$a['amount_cents'],0);}
        if(!$app['active']&&array_any($paid,fn($a)=>$a!=='0'))SettlementEvidence::reject('claim_origin');
        return ['id'=>(int)$row['id'],'active'=>$app['active'],'snapshot'=>$fact,'paid'=>$paid,'group_paid'=>$groups,'accessories'=>array_values($receipts),'reductions'=>[],'opening_accounting_date'=>$app['accounting_date'],'opening_effective_at'=>$app['receipt']['preview']['claim_title']['effective_at']];
    }
    public static function debtSnapshot(int $effect,array $claim,int $entry):array
    {
        return ['charge_id'=>null,'settlement_effect_id'=>$effect,'unit_id'=>$claim['unit_id'],'journal_entry_id'=>$entry,'lines'=>[['id'=>$effect,'line'=>['amount_cents'=>$claim['amount_cents'],'dimensions'=>$claim['line']['dimensions'],'account'=>['receivable_account'=>$claim['line']['account_code']]],'ledger_line'=>$claim['line']]],'liability_groups'=>$claim['title']['liability_groups'],'amount_cents'=>$claim['amount_cents'],'title_ref'=>$claim['title']['title_ref'],'due_date'=>$claim['due_date'],'legal'=>$claim['title']['legal'],'security'=>$claim['title']['security'],'damages_for'=>null,'reminders'=>[]];
    }
    public function currentForDebts(int $supplier,?array $debtIds):array
    {
        $out=['reductions'=>[],'opening_reductions'=>[],'reliefs'=>[]];if($debtIds===[])return $out;$sql='SELECT DISTINCT application_id FROM property_service_settlement_effects WHERE supplier_id=?';$args=[$supplier];if($debtIds!==null){$sql.=' AND debt_id IN ('.implode(',',array_fill(0,count($debtIds),'?')).')';array_push($args,...$debtIds);}
        foreach($this->rows($sql.' ORDER BY application_id FOR UPDATE',$args) as $row){$app=$this->application($supplier,(int)$row['application_id'],true);if(!$app['active'])continue;foreach($app['effects'] as $effect){if($debtIds!==null&&!in_array($effect['debt_id'],$debtIds,true))continue;if($effect['kind']==='claim_reduction'){$key=$effect['charge_line_id']===null?'opening_reductions':'reductions';$id=$effect['charge_line_id']??$effect['opening_component_id'];$out[$key][$id]=bcadd($out[$key][$id]??'0',$effect['amount_cents'],0);}elseif($effect['kind']==='allocation_relief')$out['reliefs'][$effect['allocation_id']]=bcadd($out['reliefs'][$effect['allocation_id']]??'0',$effect['amount_cents'],0);}}
        return $out;
    }
    public function debtEvents(int $supplier,array $ids,bool $current):array
    {
        $out=[];if($ids===[])return $out;$suffix=$current?' FOR UPDATE':'';
        $rows=$this->rows('SELECT DISTINCT application_id FROM property_service_settlement_effects WHERE supplier_id=? AND debt_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY application_id'.$suffix,[$supplier,...$ids]);
        foreach($rows as $row){$app=$this->application($supplier,(int)$row['application_id'],$current);foreach($app['effects'] as $effect){
            if(!in_array($effect['debt_id'],$ids,true)||!in_array($effect['kind'],['claim_reduction','allocation_relief'],true))continue;
            $kind=$effect['kind']==='claim_reduction'?'claim':'paid';$events=$this->events($supplier,$app,'service-settlement:'.$app['id'].':effect:'.$effect['id'],$kind,'-'.$effect['amount_cents'],$current);
            if($kind==='paid'){$allocation=$this->one('SELECT debt_id,liability_group FROM unit_payment_allocations WHERE supplier_id=? AND id=?'.$suffix,[$supplier,$effect['allocation_id']]);if(!$allocation||(int)$allocation['debt_id']!==$effect['debt_id'])SettlementEvidence::reject('history_corrupt');foreach($events as &$event)$event['group_key']=$allocation['liability_group'];unset($event);}
            $out[$effect['debt_id']]??=[];array_push($out[$effect['debt_id']],...$events);
        }}return $out;
    }
    public function events(int $supplier,array $app,string $id,string $kind,string $amount,bool $current):array
    {
        $event=fn($key,$value,$date,$recorded)=>['id'=>$key,'kind'=>$kind,'amount_cents'=>$value,'date'=>$date,'effective_at'=>null,'recorded_at'=>\MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository::instant($recorded)];
        $out=[$event($id,$amount,$app['accounting_date'],$app['recorded_at'])];
        $row=$this->one('SELECT id FROM property_service_settlement_applications WHERE supplier_id=? AND reversal_of=?'.($current?' FOR UPDATE':''),[$supplier,$app['id']]);
        if($row){$reverse=$this->application($supplier,(int)$row['id'],$current);$out[]=$event($id.':reversal',bcsub('0',$amount,0),$reverse['accounting_date'],$reverse['recorded_at']);}return $out;
    }
    public static function effects(array $preview,array $ledger):array
    {
        $plan=$preview['plan'];$starts=SettlementPlanner::journal($plan['movements'])['starts'];$sources=[];foreach($plan['document']['origins'] as $source)$sources[$source['kind'].':'.$source['id']]=$source;$out=[];
        foreach($plan['movements'] as $index=>$movement){$source=$sources[$movement['origin_key']]??null;$kind=match($movement['kind']){'advance_application'=>'advance_applied','principal_reduction'=>'claim_reduction','allocation_release'=>'allocation_relief','opening_paid_credit'=>'opening_paid_credit','new_claim'=>'new_claim'};
            $effect=['kind'=>$kind,'debt_id'=>$source['debt_id']??null,'charge_line_id'=>($source['kind']??null)==='s3_line'?$source['id']:null,'opening_component_id'=>($source['kind']??null)==='opening_component'?$source['id']:null,'allocation_id'=>$movement['allocation_id'],'amount_cents'=>$movement['amount_cents'],'movement_index'=>$index];
            if(in_array($kind,['allocation_relief','opening_paid_credit'],true)){
                $matches=array_values(array_filter($plan['credits'],fn($c)=>$kind==='allocation_relief'?$c['allocation_id']===$movement['allocation_id']:($c['origin_key']??null)===$movement['origin_key']));if(count($matches)!==1)SettlementEvidence::reject('credit_origin');$c=$matches[0];
                $effect['credit']=['unit_id'=>$plan['document']['unit_id'],'amount_cents'=>$c['amount_cents'],'due_date'=>$c['due_date'],'parties'=>array_values(array_filter($preview['parties'],fn($p)=>in_array($p['id'],$c['party_ids'],true))),'line'=>$ledger['lines'][$starts[$index]+1],'entitlement'=>['basis'=>'documented_service_settlement','evidence'=>$c['evidence']]];
            }
            if($kind==='new_claim')$effect['claim']=['unit_id'=>$plan['document']['unit_id'],'amount_cents'=>$movement['amount_cents'],'due_date'=>$plan['new_claim']['due_date'],'line'=>$ledger['lines'][$starts[$index]],'title'=>$preview['claim_title']];
            $out[]=$effect;
        }
        return $out;
    }
}
