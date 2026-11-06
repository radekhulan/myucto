<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceJournalProof;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest;
use MyInvoice\Service\Property\PropertyInput;
use PDO;
final class ReliefCorrectionPlanner
{
    public function __construct(private readonly Connection $db){}
    public function current(int $supplier,array $request):array
    {
        PropertyInput::fields($request,['adjustment_charge_id','expected_version','accounting_date','reason','anchors','reliefs','credit_entitlement']);
        PropertyInput::text($request['reason']??null,'reason',4000);$date=IssuanceRequest::date($request['accounting_date']??null);
        $reader=new IssuanceReadRepository($this->db);$charge=$reader->find($supplier,PropertyInput::id($request['adjustment_charge_id']??null,'adjustment_charge_id'),true);
        if($charge['status']!=='draft'||$charge['row_version']!==($request['expected_version']??null)||$charge['previous_charge_id']===null||$charge['issue_mode']!=='difference'||bccomp($charge['amount_cents'],'0',0)>=0)PaymentException::reject('pending_negative_correction_required');
        if($date!==$charge['accounting_date'])PaymentException::reject('correction_date_mismatch');
        $batch=$reader->batch($supplier,$charge['batch_id'],true);if(count($batch['charges'])!==1)PaymentException::reject('single_unit_correction_batch_required');
        (new PaymentPostingDate($this->db))->assertOpen($supplier,$date);
        $history=(new CorrectionHistory($this->db))->current($supplier);
        $ancestors=[];$next=$charge['previous_charge_id'];
        while($next!==null){if(isset($ancestors[$next]))PaymentException::reject('history_corrupt');$old=$reader->find($supplier,$next,true);
            if($old['status']!=='posted'||$old['unit_id']!==$charge['unit_id']||$old['source_key']!==$charge['source_key']||$old['period_from']!==$charge['period_from']||$old['period_to']!==$charge['period_to']||$old['accounting_date']>$date)PaymentException::reject('correction_anchor');
            (new IssuanceJournalProof($this->db))->assertEntry($supplier,$old['journal_entry_id'],$old);$ancestors[$next]=$old;$next=$old['previous_charge_id'];
        }
        $negative=[];foreach($this->rows('SELECT id,line_json FROM unit_charge_lines WHERE supplier_id=? AND charge_id=? ORDER BY id FOR UPDATE',[$supplier,$charge['id']]) as $row){$line=json_decode($row['line_json'],true,flags:JSON_THROW_ON_ERROR);if(bccomp($line['amount_cents'],'0',0)>=0)PaymentException::reject('negative_correction_lines_required');$negative[(int)$row['id']]=$line;}
        $anchors=[];$sums=[];$originals=[];$reducing=[];
        foreach(PropertyInput::list($request['anchors']??null,'anchors') as $anchor){
            PropertyInput::fields($anchor,['adjustment_line_id','original_line_id','amount_cents','evidence']);PropertyInput::text($anchor['evidence']??null,'anchor.evidence',4000);
            $new=PropertyInput::id($anchor['adjustment_line_id']??null,'adjustment_line_id');$old=PropertyInput::id($anchor['original_line_id']??null,'original_line_id');$amount=StatutoryAllocationPolicy::cents($anchor['amount_cents']??null);
            if($amount==='0'||!isset($negative[$new])||isset($anchors[$new.':'.$old]))PaymentException::reject('correction_anchor');
            $raw=$this->rows('SELECT charge_id,line_json FROM unit_charge_lines WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$old])[0]??null;
            if(!$raw||!isset($ancestors[(int)$raw['charge_id']]))PaymentException::reject('correction_anchor');
            $fact=json_decode($raw['line_json'],true,flags:JSON_THROW_ON_ERROR);
            foreach(['component_id','service_id','account','dimensions'] as $field)if(PropertyInput::hash([$fact[$field]])!==PropertyInput::hash([$negative[$new][$field]]))PaymentException::reject('correction_anchor');
            if(bccomp($fact['amount_cents'],'0',0)<=0)PaymentException::reject('correction_anchor');
            $originals[$old]=['line'=>$fact,'charge'=>$ancestors[(int)$raw['charge_id']]];
            $anchors[$new.':'.$old]=['adjustment_line_id'=>$new,'original_line_id'=>$old,'amount_cents'=>$amount];$sums[$new]=bcadd($sums[$new]??'0',$amount,0);$reducing[$old]=bcadd($reducing[$old]??'0',$amount,0);
        }
        foreach($negative as $id=>$line)if(($sums[$id]??'0')!==ltrim($line['amount_cents'],'-'))PaymentException::reject('correction_anchor_margin');
        $allocations=[];$paid=[];
        foreach($this->rows("SELECT a.*,o.accounting_date FROM unit_payment_allocations a JOIN unit_payment_operations o ON o.supplier_id=a.supplier_id AND o.id=a.operation_id WHERE a.supplier_id=? AND o.status='posted' AND a.charge_line_id IS NOT NULL ORDER BY a.id FOR UPDATE",[$supplier]) as $row){
            $line=(int)$row['charge_line_id'];if(!isset($originals[$line]))continue;
            if($row['accounting_date']>$date)PaymentException::reject('backdated_payment');
            $left=bcsub($row['amount_cents'],$history['reliefs'][(int)$row['id']]??'0',0);if(bccomp($left,'0',0)<0)PaymentException::reject('history_corrupt');
            $allocations[(int)$row['id']]=$row+['available_cents'=>$left];$paid[$line]=bcadd($paid[$line]??'0',$left,0);
        }
        $needed=[];$relief='0';$journal=[];
        foreach($originals as $lineId=>$source){
            try{$calc=ReliefCalculation::calculate($source['line']['amount_cents'],$history['reductions'][$lineId]??'0',$paid[$lineId]??'0',$reducing[$lineId]);}catch(\DomainException){PaymentException::reject('correction_capacity');}
            $needed[$lineId]=$calc['relief_cents'];$relief=bcadd($relief,$calc['relief_cents'],0);
            if($calc['relief_cents']!=='0'){$origins=[];foreach($source['line']['dimensions'] as $type=>$value)$origins[$type]=['origin'=>'module','ref'=>'unit_charge:'.$source['charge']['id']];
                $journal[]=['account_code'=>$source['line']['account']['receivable_account'],'side'=>'debit','amount'=>bcdiv($calc['relief_cents'],'100',2),'dimensions'=>$source['line']['dimensions'],'dimension_origins'=>$origins];}
        }
        $parts=[];$actual=[];
        foreach(PropertyInput::list($request['reliefs']??null,'reliefs') as $part){PropertyInput::fields($part,['allocation_id','amount_cents']);$id=PropertyInput::id($part['allocation_id']??null,'allocation_id');$amount=StatutoryAllocationPolicy::cents($part['amount_cents']??null);
            if($amount==='0'||isset($parts[$id])||!isset($allocations[$id])||bccomp($amount,$allocations[$id]['available_cents'],0)>0)PaymentException::reject('relief_allocation');
            $line=(int)$allocations[$id]['charge_line_id'];$actual[$line]=bcadd($actual[$line]??'0',$amount,0);$parts[$id]=['allocation_id'=>$id,'amount_cents'=>$amount];
        }
        foreach($needed as $line=>$amount)if(($actual[$line]??'0')!==$amount)PaymentException::reject('relief_margin',['original_line_id'=>$line,'required_cents'=>$amount,'allocation_ids'=>array_keys(array_filter($allocations,fn($a)=>(int)$a['charge_line_id']===$line))]);
        $credit=null;
        if($relief!=='0'){
            $ent=$request['credit_entitlement']??null;if(!is_array($ent))PropertyInput::invalid('credit_entitlement');PropertyInput::fields($ent,['party_ids','evidence','due_date']);PropertyInput::text($ent['evidence']??null,'credit.evidence',4000);
            $ids=PropertyInput::list($ent['party_ids']??null,'party_ids');foreach($ids as $id)PropertyInput::id($id,'party_id');if($ids===[]||count(array_unique($ids))!==count($ids))PropertyInput::invalid('party_ids');
            $parties=(new PropertyReadRepository($this->db))->parties($supplier,$ids,true);if(count($parties)!==count($ids))PaymentException::reject('foreign_party');
            $unit=$this->rows('SELECT d.id,d.type_id FROM property_objects p JOIN dimension_values d ON d.supplier_id=p.supplier_id AND d.id=p.dimension_value_id WHERE p.supplier_id=? AND p.id=? FOR UPDATE',[$supplier,$charge['unit_id']])[0]??null;if(!$unit)PaymentException::reject('unit_dimension');
            $line=['account_code'=>'379.910','side'=>'credit','amount'=>bcdiv($relief,'100',2),'dimensions'=>[(int)$unit['type_id']=>(int)$unit['id']],'dimension_origins'=>[(int)$unit['type_id']=>['origin'=>'module','ref'=>'unit_charge:'.$charge['id']]]];
            $credit=['unit_id'=>$charge['unit_id'],'amount_cents'=>$relief,'due_date'=>IssuanceRequest::date($ent['due_date']??null),'parties'=>$parties,'evidence'=>$ent['evidence'],'line'=>$line];$journal[]=$line;
        }
        $anchors=array_values($anchors);usort($anchors,fn($a,$b)=>[$a['adjustment_line_id'],$a['original_line_id']]<=>[$b['adjustment_line_id'],$b['original_line_id']]);ksort($parts,SORT_NUMERIC);
        foreach($originals as &$original)$original['charge']=self::descriptor($original['charge']);unset($original);
        return ['request'=>$request,'adjustment'=>self::descriptor($charge),'anchors'=>$anchors,'reliefs'=>array_values($parts),'relief_cents'=>$relief,'lines'=>$journal,'credit'=>$credit,'originals'=>$originals,'allocation_receipts'=>$allocations];
    }
    private static function descriptor(array $charge):array{return array_intersect_key($charge,array_flip(['id','supplier_id','batch_id','unit_id','source_key','revision_no','previous_charge_id','row_version','status','accounting_date','period_from','period_to','amount_cents','journal_entry_id','snapshot_hash']));}
    private function rows(string $sql,array $params):array{$q=$this->db->pdo()->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);}
}
