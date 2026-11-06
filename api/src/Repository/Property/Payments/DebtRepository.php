<?php

declare(strict_types=1);
namespace MyInvoice\Repository\Property\Payments;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceJournalProof;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Payments\{PaymentLocks,PaymentException,StatutoryAllocationPolicy};
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use PDO;
final class DebtRepository
{
    public function __construct(private readonly Connection $db) {}
    public function register(int $supplier,array $request,string $token,int $actor):array
    {
        PropertyInput::code($token,'idempotency_key');PropertyInput::id($actor,'actor');
        return (new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$request,$token,$actor):array{
            (new PaymentLocks($this->db))->acquire($supplier);$pdo=$this->db->pdo();
            $hash=PropertyInput::hash(['request'=>$request,'token'=>$token,'actor'=>$actor]);
            $old=$this->one('SELECT id,request_hash FROM unit_payment_debts WHERE supplier_id=? AND idempotency_key=? FOR UPDATE',[$supplier,$token]);
            if($old){if(!hash_equals($old['request_hash'],$hash))PaymentException::reject('idempotency_conflict');return $this->current($supplier,(int)$old['id']);}
            PropertyInput::fields($request,['charge_id','expected_version','principal_line_ids','title_ref','due_date','legal','security','damages_for']);
            $charge=(new IssuanceReadRepository($this->db))->find($supplier,PropertyInput::id($request['charge_id']??null,'charge_id'),true);
            if($charge['row_version']!==($request['expected_version']??null))PaymentException::reject('charge_version_conflict');
            $this->assertCharge($supplier,$charge);
            $ids=PropertyInput::list($request['principal_line_ids']??null,'principal_line_ids');foreach($ids as $id)PropertyInput::id($id,'principal_line_id');
            if($ids===[]||count(array_unique($ids))!==count($ids))PropertyInput::invalid('principal_line_ids');sort($ids,SORT_NUMERIC);
            PropertyInput::text($request['title_ref']??null,'title_ref',4000);self::date($request['due_date']??null);
            if(!is_array($request['legal']??null))PropertyInput::invalid('legal');
            PropertyInput::fields($request['legal'],['basis','legal_act_date','evidence','regime','component_disposition']);PropertyInput::text($request['legal']['evidence']??null,'legal.evidence',4000);
            if(isset($request['legal']['component_disposition'])){
                $disposition=$request['legal']['component_disposition'];if(!is_array($disposition))PropertyInput::invalid('component_disposition');PropertyInput::fields($disposition,['order','debtor_evidence','creditor_consent','old_interest_effect']);PropertyInput::text($disposition['debtor_evidence']??null,'debtor_evidence',4000);
                if(isset($disposition['creditor_consent']))PropertyInput::text($disposition['creditor_consent'],'creditor_consent',4000);
                if(isset($disposition['old_interest_effect'])){if(!is_array($disposition['old_interest_effect']))PropertyInput::invalid('old_interest_effect');PropertyInput::fields($disposition['old_interest_effect'],['resolution','evidence']);PropertyInput::text($disposition['old_interest_effect']['evidence']??null,'old_interest_effect.evidence',4000);}
            }
            try{StatutoryAllocationPolicy::ruleset($charge['accounting_date'],$request['legal']);}catch(\DomainException|\InvalidArgumentException){PropertyInput::invalid('legal');}
            if(!is_array($request['security']??null)||!in_array($request['security']['kind']??null,['unsecured','secured'],true))PropertyInput::invalid('security');
            PropertyInput::fields($request['security'],['kind','evidence']);
            PropertyInput::text($request['security']['evidence']??null,'security.evidence',4000);
            if(($request['damages_for']??null)!==null)$this->current($supplier,PropertyInput::id($request['damages_for'],'damages_for'));
            $lines=[];$amount='0';
            foreach($this->all('SELECT id,line_json FROM unit_charge_lines WHERE supplier_id=? AND charge_id=? ORDER BY id FOR UPDATE',[$supplier,$charge['id']]) as $line)if(in_array((int)$line['id'],$ids,true)){
                $bound=$this->one('SELECT debt_id FROM unit_payment_debt_lines WHERE supplier_id=? AND charge_line_id=? FOR UPDATE',[$supplier,$line['id']]);if($bound)PaymentException::reject('line_has_debt');
                $fact=json_decode($line['line_json'],true,flags:JSON_THROW_ON_ERROR);
                if(bccomp($fact['amount_cents'],'0',0)<=0)PaymentException::reject('positive_claim_required');
                $lines[]=['id'=>(int)$line['id'],'line'=>$fact];$amount=bcadd($amount,$fact['amount_cents'],0);
            }
            if(count($lines)!==count($ids))PaymentException::reject('foreign_charge_line');
            $snapshot=self::chargeSnapshot($charge,$lines,$amount,$request);
            $this->execute('INSERT INTO unit_payment_debts (supplier_id,charge_id,request_json,snapshot_json,request_hash,snapshot_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$supplier,$charge['id'],self::json($request),self::json($snapshot),$hash,PropertyInput::hash($snapshot),$token,$actor]);
            $id=(int)$pdo->lastInsertId();foreach($ids as $lineId)$this->execute('INSERT INTO unit_payment_debt_lines (supplier_id,debt_id,charge_line_id) VALUES (?,?,?)',[$supplier,$id,$lineId]);
            $payload=['snapshot_hash'=>PropertyInput::hash($snapshot),'charge_id'=>$charge['id']];
            $this->execute("INSERT INTO unit_payment_audit (supplier_id,object_kind,object_id,action,payload_json,payload_hash,created_by,recorded_at) VALUES (?,'debt',?,'registered',?,?,?,UTC_TIMESTAMP(6))",[$supplier,$id,self::json($payload),PropertyInput::hash($payload),$actor]);
            return $this->current($supplier,$id);
        });
    }
    /** Otisk dluhu z předpisu; sdílí ruční registrace i hromadná registrace při zaúčtování dávky. */
    public static function chargeSnapshot(array $charge,array $lines,string $amount,array $request):array
    {
        return ['charge_id'=>$charge['id'],'unit_id'=>$charge['unit_id'],'journal_entry_id'=>$charge['journal_entry_id'],'lines'=>$lines,'liability_groups'=>$charge['snapshot']['liability_groups'],
            'amount_cents'=>$amount,'title_ref'=>$request['title_ref'],'due_date'=>$request['due_date'],'legal'=>$request['legal'],'security'=>$request['security'],'damages_for'=>$request['damages_for']??null];
    }
    public static function accessoryOwnedEntrySql(bool $currentRead=false):string
    {
        $lock=$currentRead?' FOR UPDATE':'';
        return '(EXISTS (SELECT 1 FROM unit_charges c WHERE c.supplier_id=e.supplier_id AND c.journal_entry_id=e.id'.$lock.')'
            .' OR EXISTS (SELECT 1 FROM unit_payment_operations o WHERE o.supplier_id=e.supplier_id AND o.journal_entry_id=e.id'.$lock.')'
            .' OR EXISTS (SELECT 1 FROM property_opening_batches ob WHERE ob.supplier_id=e.supplier_id AND (ob.journal_entry_id=e.id OR ob.reversal_entry_id=e.id)'.$lock.'))';
    }
    public function bindAccessory(int $supplier,int $debtId,array $request,int $actor):array
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$debtId,$request,$actor):array{
            (new PaymentLocks($this->db))->acquire($supplier);PropertyInput::id($actor,'actor');$debt=$this->current($supplier,$debtId);if(!$debt['active'])PaymentException::reject('inactive_debt');
            PropertyInput::fields($request,['journal_entry_id','journal_line_id','component','evidence','liability_evidence','purpose']);
            $component=$request['component']??null;if(!in_array($component,['determined_cost','default_interest','interest'],true))PropertyInput::invalid('component');
            if(isset($request['purpose'])&&$request['purpose']!=='old_1932_interest_effect')PropertyInput::invalid('purpose');
            if(($request['purpose']??null)==='old_1932_interest_effect'&&($component!=='interest'||($debt['snapshot']['legal']['component_disposition']['old_interest_effect']['resolution']??null)!=='separately_evidenced'))PropertyInput::invalid('purpose');
            PropertyInput::text($request['evidence']??null,'evidence',4000);PropertyInput::text($request['liability_evidence']??null,'liability_evidence',4000);
            $entry=PropertyInput::id($request['journal_entry_id']??null,'journal_entry_id');$lineId=PropertyInput::id($request['journal_line_id']??null,'journal_line_id');
            $ledger=(new \MyInvoice\Service\Property\Payments\PaymentJournalProof($this->db))->current($supplier,$entry);
            if($ledger['entry']['reversed_by']!==null||$this->one('SELECT e.id FROM journal_entries e WHERE e.supplier_id=? AND e.id=? AND '.self::accessoryOwnedEntrySql(true).' FOR UPDATE',[$supplier,$entry]))PaymentException::reject('accessory_owned_elsewhere');
            $line=null;foreach($ledger['lines'] as $candidate)if($candidate['id']===$lineId)$line=$candidate;
            if($line===null||$line['side']!=='debit'||!str_starts_with($line['account_code'],'3')||$line['is_red_storno']||$line['amount_foreign']!==null||$line['currency_code']!==null||$line['fx_rate']!==null||bccomp($line['amount'],'0',2)<=0)PaymentException::reject('accessory_requires_receivable');
            $account=$this->one('SELECT account_type FROM chart_of_accounts WHERE supplier_id=? AND account_code=? FOR UPDATE',[$supplier,$line['account_code']]);if(($account['account_type']??null)!=='asset')PaymentException::reject('accessory_requires_receivable');
            $receipt=['debt_id'=>$debtId,'request'=>$request,'liability_groups'=>$debt['snapshot']['liability_groups'],'ledger'=>$ledger,'line'=>$line,'actor'=>$actor];$hash=PropertyInput::hash($receipt);
            $old=$this->one('SELECT id,receipt_hash FROM unit_claim_accessory_receipts WHERE supplier_id=? AND journal_line_id=? FOR UPDATE',[$supplier,$lineId]);
            if($old){if(!hash_equals($old['receipt_hash'],$hash))PaymentException::reject('accessory_owned_elsewhere');return ['id'=>(int)$old['id'],'receipt'=>$receipt];}
            (new \MyInvoice\Service\Property\Receivables\PartyHistoryUseGuard($this->db))->assertDebtMutable($supplier,$debtId);
            $this->execute('INSERT INTO unit_claim_accessory_receipts (supplier_id,charge_id,debt_id,journal_entry_id,journal_line_id,component,amount_cents,receipt_json,receipt_hash,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$supplier,$debt['snapshot']['charge_id'],$debtId,$entry,$lineId,$component,bcmul($line['amount'],'100',0),self::json($receipt),$hash,$actor]);
            $id=(int)$this->db->pdo()->lastInsertId();$payload=['receipt_hash'=>$hash];
            $this->execute("INSERT INTO unit_payment_audit (supplier_id,object_kind,object_id,action,payload_json,payload_hash,created_by,recorded_at) VALUES (?,'accessory',?,'bound',?,?,?,UTC_TIMESTAMP(6))",[$supplier,$id,self::json($payload),PropertyInput::hash($payload),$actor]);
            return ['id'=>$id,'receipt'=>$receipt];
        });
    }
    public function current(int $supplier,int $id):array
    {
        return $this->readMany($supplier,[$id],false)[0];
    }
    public function currentMany(int $supplier,array $debtIds):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Debt current proof requires owner locks.');
        foreach($debtIds as $id)PropertyInput::id($id,'debt_id');
        if(count(array_unique($debtIds))!==count($debtIds))PropertyInput::invalid('debts');
        (new PaymentLocks($this->db))->acquire($supplier);
        return \MyInvoice\Infrastructure\Database\CurrentReadMemo::remember($this->db,'debts|'.$supplier.'|'.implode(',',$debtIds),fn():array=>$this->readMany($supplier,$debtIds,true));
    }
    private function readMany(int $supplier,array $debtIds,bool $selected):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Debt current proof requires owner locks.');
        sort($debtIds,SORT_NUMERIC);$rows=[];$bound=[];$allocations=[];$receipts=[];
        foreach(array_chunk($debtIds,500) as $chunk){
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            array_push($rows,...$this->all('SELECT * FROM unit_payment_debts WHERE supplier_id=? AND id IN ('.$marks.') ORDER BY id FOR UPDATE',[$supplier,...$chunk]));
            foreach($this->all('SELECT d.debt_id,l.id,l.charge_id,l.line_json FROM unit_charge_lines l JOIN unit_payment_debt_lines d ON d.supplier_id=l.supplier_id AND d.charge_line_id=l.id WHERE d.supplier_id=? AND d.debt_id IN ('.$marks.') ORDER BY d.debt_id,l.id FOR UPDATE',[$supplier,...$chunk]) as $line)$bound[(int)$line['debt_id']][]=$line;
            foreach($this->all("SELECT a.* FROM unit_payment_allocations a JOIN unit_payment_operations o ON o.supplier_id=a.supplier_id AND o.id=a.operation_id WHERE a.supplier_id=? AND a.debt_id IN (".$marks.") AND o.status='posted' ORDER BY a.debt_id,a.id FOR UPDATE",[$supplier,...$chunk]) as $allocation)$allocations[(int)$allocation['debt_id']][]=$allocation;
            foreach($this->all('SELECT * FROM unit_claim_accessory_receipts WHERE supplier_id=? AND debt_id IN ('.$marks.') ORDER BY debt_id,id FOR UPDATE',[$supplier,...$chunk]) as $receipt)$receipts[(int)$receipt['debt_id']][]=$receipt;
        }
        if(count($rows)!==count($debtIds))PaymentException::reject('debt_not_found');
        if($rows===[])return [];
        $charges=(new IssuanceReadRepository($this->db))->findMany($supplier,array_map('intval',array_column(array_filter($rows,fn($row)=>$row['charge_id']!==null),'charge_id')),true);$proofs=[];
        foreach($charges as $charge){
            if($charge['status']!=='reversed'&&($charge['status']!=='posted'||bccomp($charge['amount_cents'],'0',0)<=0))PaymentException::reject('posted_positive_claim_required');
            $proofs[]=['entry_id'=>$charge['journal_entry_id'],'charge'=>$charge,'reversal'=>false];
            if($charge['status']==='reversed')$proofs[]=['entry_id'=>$charge['reversal_entry_id'],'charge'=>$charge,'reversal'=>true];
        }
        (new IssuanceJournalProof($this->db))->assertEntries($supplier,$proofs);
        $historyReader=new \MyInvoice\Service\Property\Payments\CorrectionHistory($this->db);
        $history=$selected?$historyReader->currentForDebts($supplier,$debtIds):$historyReader->current($supplier);
        $entryIds=[];foreach($receipts as $debtReceipts)foreach($debtReceipts as $receipt){if(($receipt['opening_component_id']??null)!==null)continue;$fact=json_decode($receipt['receipt_json'],true,flags:JSON_THROW_ON_ERROR);$entryIds[]=$fact['ledger']['entry']['id'];}
        $ledgers=(new \MyInvoice\Service\Property\Payments\PaymentJournalProof($this->db))->currentMany($supplier,$entryIds);$out=[];
        $openingRows=array_values(array_filter($rows,fn($row)=>($row['opening_item_id']??null)!==null));
        $opening=$openingRows===[]?[]:(new \MyInvoice\Service\Property\Opening\OpeningOriginProof($this->db))->currentDebts($supplier,$openingRows,$allocations,$receipts);
        foreach($rows as $row){
            $id=(int)$row['id'];
            if(($row['settlement_effect_id']??null)!==null){$out[]=(new \MyInvoice\Service\Property\Services\Settlement\SettlementHistory($this->db))->currentDebt($supplier,$row,$allocations[$id]??[],$receipts[$id]??[]);continue;}
            if(($row['opening_item_id']??null)!==null){
                $openingDebt=$opening[$id];
                foreach($allocations[$id]??[] as $a){$relief=$history['reliefs'][(int)$a['id']]??'0';if(bccomp($relief,$a['amount_cents'],0)>0)PaymentException::reject('history_corrupt');$key=$a['opening_component_id']!==null?'principal:'.$a['opening_component_id']:'accessory:'.$a['accessory_id'];$openingDebt['paid'][$key]=bcsub($openingDebt['paid'][$key],$relief,0);$openingDebt['group_paid'][$a['liability_group']]=bcsub($openingDebt['group_paid'][$a['liability_group']],$relief,0);}
                $openingDebt['reductions']=$history['opening_reductions'];$out[]=$openingDebt;continue;
            }
            $id=(int)$row['id'];$snapshot=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);$request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);
            if(!hash_equals($row['snapshot_hash'],PropertyInput::hash($snapshot))||!hash_equals($row['request_hash'],PropertyInput::hash(['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']])))PaymentException::reject('history_corrupt');
            $charge=$charges[(int)$row['charge_id']];
            if($snapshot['charge_id']!==$charge['id']||$snapshot['unit_id']!==$charge['unit_id']||$snapshot['journal_entry_id']!==$charge['journal_entry_id']||PropertyInput::hash($snapshot['liability_groups'])!==PropertyInput::hash($charge['snapshot']['liability_groups']))PaymentException::reject('debt_source_changed');
            $lines=[];foreach($bound[$id]??[] as $line)if((int)$line['charge_id']===$charge['id'])$lines[]=['id'=>(int)$line['id'],'line'=>json_decode($line['line_json'],true,flags:JSON_THROW_ON_ERROR)];
            if(PropertyInput::hash($lines)!==PropertyInput::hash($snapshot['lines']))PaymentException::reject('debt_source_changed');
            $paid=[];$groups=[];
            foreach($allocations[$id]??[] as $allocation){
                $amount=bcsub($allocation['amount_cents'],$history['reliefs'][(int)$allocation['id']]??'0',0);if(bccomp($amount,'0',0)<0)PaymentException::reject('history_corrupt');
                $key=$allocation['charge_line_id']!==null?'principal:'.$allocation['charge_line_id']:'accessory:'.$allocation['accessory_id'];$paid[$key]=bcadd($paid[$key]??'0',$amount,0);
                $groups[$allocation['liability_group']]=bcadd($groups[$allocation['liability_group']]??'0',$amount,0);
            }
            $accessories=[];
            foreach($receipts[$id]??[] as $receiptRow){
                $receipt=json_decode($receiptRow['receipt_json'],true,flags:JSON_THROW_ON_ERROR);
                if(!hash_equals($receiptRow['receipt_hash'],PropertyInput::hash($receipt))||$receipt['debt_id']!==$id||$receipt['request']['component']!==$receiptRow['component']||$receipt['line']['id']!==(int)$receiptRow['journal_line_id']||bcmul($receipt['line']['amount'],'100',0)!==$receiptRow['amount_cents']||PropertyInput::hash($receipt['liability_groups'])!==PropertyInput::hash($snapshot['liability_groups']))PaymentException::reject('history_corrupt');
                if(PropertyInput::hash($receipt['ledger'])!==PropertyInput::hash($ledgers[$receipt['ledger']['entry']['id']]))PaymentException::reject('ledger_mismatch');
                $accessories[]=['id'=>(int)$receiptRow['id'],'component'=>$receiptRow['component'],'amount_cents'=>$receiptRow['amount_cents'],'receipt'=>$receipt];
            }
            if($charge['status']==='reversed'&&array_any($paid,fn($value)=>$value!=='0'))PaymentException::reject('history_corrupt');
            $out[]=['id'=>$id,'active'=>$charge['status']==='posted','snapshot'=>$snapshot,'paid'=>$paid,'group_paid'=>$groups,'accessories'=>$accessories,'reductions'=>$history['reductions']];
        }
        return $out;
    }
    public function allCurrent(int $supplier):array
    {
        $ids=array_map('intval',array_column($this->all('SELECT id FROM unit_payment_debts WHERE supplier_id=? ORDER BY id FOR UPDATE',[$supplier]),'id'));
        if($ids===[])return [];(new PaymentLocks($this->db))->acquire($supplier);return $this->readMany($supplier,$ids,false);
    }
    private function assertCharge(int $supplier,array $charge):void
    {
        if($charge['status']!=='posted'||bccomp($charge['amount_cents'],'0',0)<=0)PaymentException::reject('posted_positive_claim_required');
        (new IssuanceJournalProof($this->db))->assertEntry($supplier,$charge['journal_entry_id'],$charge);
    }
    private function execute(string $sql,array $params):\PDOStatement{$s=$this->db->pdo()->prepare($sql);$s->execute($params);return $s;}
    private function one(string $sql,array $params):?array{return $this->execute($sql,$params)->fetch(PDO::FETCH_ASSOC)?:null;}
    private function all(string $sql,array $params):array{return $this->execute($sql,$params)->fetchAll(PDO::FETCH_ASSOC);}
    private static function json(array $v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
    private static function date(mixed $v):void{if(!is_string($v)||!($d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v))||$d->format('Y-m-d')!==$v)PropertyInput::invalid('due_date');}
}
