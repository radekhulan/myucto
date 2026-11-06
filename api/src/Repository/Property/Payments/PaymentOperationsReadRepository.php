<?php

declare(strict_types=1);
namespace MyInvoice\Repository\Property\Payments;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant};
use MyInvoice\Service\Property\Payments\PaymentException;
use PDO;

final class PaymentOperationsReadRepository
{
    private const TABLES=['charges'=>'unit_charges','debts'=>'unit_payment_debts','credits'=>'unit_credit_lots','reservations'=>'unit_credit_reservations','operations'=>'unit_payment_operations','corrections'=>'unit_payment_corrections'];
    public function __construct(private readonly Connection $db) {}

    public function page(int $supplier,string $kind,int $after=0,int $limit=25,?int $unit=null,?int $id=null):array
    {
        if(!isset(self::TABLES[$kind])||$after<0||$limit<1||$limit>100)PropertyInput::invalid('page');
        if($unit!==null)PropertyInput::id($unit,'unit_id');
        if($id!==null)PropertyInput::id($id,'id');
        $owned=!$this->db->pdo()->inTransaction();if($owned)$this->db->pdo()->beginTransaction();
        try{
            $table=self::TABLES[$kind];$where='r.supplier_id=? AND r.id>?';$params=[$supplier,$after];
            if($id!==null){$where.=' AND r.id=?';$params[]=$id;}
            if($unit!==null){
                $where.=' AND '.match($kind){
                    'charges','credits'=>'r.unit_id=?',
                    // Dluh má původ buď v předpisu, nebo v otevření (chk_unit_debt_origin); každá větev jde po indexu jednotky.
                    'debts'=>'(r.charge_id IN (SELECT c.id FROM unit_charges c WHERE c.supplier_id=r.supplier_id AND c.unit_id=?)'
                        .' OR r.opening_item_id IN (SELECT i.id FROM property_opening_items i WHERE i.supplier_id=r.supplier_id AND i.unit_id=?))',
                    'reservations'=>'EXISTS (SELECT 1 FROM unit_credit_lots c WHERE c.supplier_id=r.supplier_id AND c.id=r.credit_id AND c.unit_id=?)',
                    'corrections'=>'EXISTS (SELECT 1 FROM unit_charges c WHERE c.supplier_id=r.supplier_id AND c.id=r.adjustment_charge_id AND c.unit_id=?)',
                    'operations'=>'EXISTS ('.self::operationUnitRelationsSql('r.supplier_id','r.id,r.original_operation_id','?').')',
                };
                $params[]=$unit;
                if($kind==='debts')$params[]=$unit;
                if($kind==='operations')array_push($params,...array_fill(0,self::OPERATION_UNIT_BRANCHES-1,$unit));
            }
            $size=$limit+1;$rows=$this->all("SELECT r.* FROM $table r WHERE $where ORDER BY r.id LIMIT $size",$params);
            $more=count($rows)>$limit;if($more)array_pop($rows);
            $items=$this->project($supplier,$kind,$rows);
            if($owned)$this->db->pdo()->commit();
            return ['items'=>$items,'next_cursor'=>$more?(int)$rows[array_key_last($rows)]['id']:null,
                'coverage'=>['scope'=>'recorded_s3_s4','complete'=>false,'eligibility'=>'current_preview_required']];
        }catch(\Throwable $e){if($owned&&$this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();throw $e;}
    }

    /** Počet větví {@see operationUnitRelationsSql()}; každá nese vlastní parametry firmy, operací a jednotky. */
    public const OPERATION_UNIT_BRANCHES=4;

    public static function operationUnitRelationsSql(string $supplier,string $operations,?string $unit=null):string
    {
        $filter=static fn(string $column):string=>$unit===null?'':' AND '.$column.'='.$unit;
        // Jednotka dluhu se čte zvlášť z předpisu a zvlášť z otevření, ne přes COALESCE
        // odvozené tabulky: každá větev pak jde po indexu operace i jednotky.
        return 'SELECT c.operation_id,c.unit_id FROM unit_credit_lots c WHERE c.supplier_id='.$supplier.' AND c.operation_id IN ('.$operations.')'.$filter('c.unit_id')
            .' UNION ALL SELECT a.operation_id,ch.unit_id FROM unit_payment_allocations a JOIN unit_payment_debts d ON d.supplier_id=a.supplier_id AND d.id=a.debt_id'
            .' JOIN unit_charges ch ON ch.supplier_id=d.supplier_id AND ch.id=d.charge_id WHERE a.supplier_id='.$supplier.' AND a.operation_id IN ('.$operations.')'.$filter('ch.unit_id')
            .' UNION ALL SELECT a.operation_id,i.unit_id FROM unit_payment_allocations a JOIN unit_payment_debts d ON d.supplier_id=a.supplier_id AND d.id=a.debt_id'
            .' JOIN property_opening_items i ON i.supplier_id=d.supplier_id AND i.id=d.opening_item_id WHERE a.supplier_id='.$supplier.' AND a.operation_id IN ('.$operations.')'.$filter('i.unit_id')
            .' UNION ALL SELECT e.operation_id,c.unit_id FROM unit_credit_events e JOIN unit_credit_lots c ON c.supplier_id=e.supplier_id AND c.id=e.credit_id WHERE e.supplier_id='.$supplier.' AND e.operation_id IN ('.$operations.')'.$filter('c.unit_id');
    }

    public function detail(int $supplier,string $kind,int $id):array
    {
        $item=$this->page($supplier,$kind,0,1,null,$id)['items'][0]??null;
        if($item===null)PaymentException::reject('not_found');
        if($kind==='debts'){
            $now=\MyInvoice\Repository\Property\Receivables\PartyEventRepository::now();
            $card=$this->card($supplier,$now,$now,['unit_id'=>$item['unit_id']]);$claim=null;
            foreach($card['claims'] as $candidate)if($candidate['debt_id']===$id)$claim=$candidate;
            $item['groups']=$claim['groups']??[];$item['liability_as_of']=$now;
            $item['liability_complete']=($claim['complete']??false)&&($claim['liability']['complete']??false);
        }
        return $item;
    }

    public function parties(int $supplier,string $query='',int $after=0,int $limit=25):array
    {
        if($after<0||$limit<1||$limit>100||mb_strlen($query)>200)PropertyInput::invalid('page');
        $size=$limit+1;$where='supplier_id=? AND id>?';$params=[$supplier,$after];
        if($query!==''){$where.=" AND CONCAT_WS(' ',company_name,first_name,last_name) LIKE ?";$params[]='%'.addcslashes($query,"%_\\").'%';}
        $rows=$this->all("SELECT id,company_name,first_name,last_name FROM clients WHERE $where ORDER BY id LIMIT $size",$params);
        $more=count($rows)>$limit;if($more)array_pop($rows);
        return ['items'=>$this->partyLabels($rows),'next_cursor'=>$more?(int)$rows[array_key_last($rows)]['id']:null,'coverage'=>['scope'=>'tenant_clients','complete'=>!$more]];
    }
    public function bankAccounts(int $supplier):array
    {
        $rows=$this->all("SELECT id,label,account_number,bank_code FROM supplier_bank_accounts WHERE supplier_id=? AND is_active=1 AND currency='CZK' AND kind<>'credit_card' ORDER BY label,id",[$supplier]);
        return ['items'=>array_map(fn(array $row):array=>['id'=>(int)$row['id'],'label'=>$row['label'],'account_number'=>$row['account_number'],'bank_code'=>$row['bank_code']],$rows),'coverage'=>['currency'=>'CZK','eligibility'=>'current_preview_required']];
    }
    public function accessoryCandidates(int $supplier,int $after=0,int $limit=25):array
    {
        if($after<0||$limit<1||$limit>100)PropertyInput::invalid('page');$size=$limit+1;
        $rows=$this->all("SELECT l.id,l.entry_id journal_entry_id,e.entry_date,e.document_no document_number,e.description entry_description,a.account_code,l.amount FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND l.id>? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND l.side='debit' AND l.is_red_storno=0 AND l.amount>0 AND l.amount_foreign IS NULL AND l.currency_code IS NULL AND l.fx_rate IS NULL AND a.account_code LIKE '3%' AND a.account_type='asset' AND NOT ".DebtRepository::accessoryOwnedEntrySql()." AND NOT EXISTS (SELECT 1 FROM unit_claim_accessory_receipts r WHERE r.supplier_id=l.supplier_id AND r.journal_line_id=l.id) ORDER BY l.id LIMIT $size",[$supplier,$after]);
        $more=count($rows)>$limit;if($more)array_pop($rows);
        return ['items'=>array_map(fn(array $row):array=>['id'=>(int)$row['id'],'journal_entry_id'=>(int)$row['journal_entry_id'],'date'=>$row['entry_date'],'document_number'=>$row['document_number'],'label'=>$row['entry_description']??'','account_code'=>$row['account_code'],'amount_cents'=>bcmul($row['amount'],'100',0)],$rows),'next_cursor'=>$more?(int)$rows[array_key_last($rows)]['id']:null,'coverage'=>['eligibility'=>'current_receipt_required']];
    }

    public function correctionOptions(int $supplier,int $chargeId):array
    {
        $charge=$this->detail($supplier,'charges',$chargeId);
        if($charge['status']!=='draft'||bccomp($charge['amount_cents'],'0',0)>=0||$charge['previous_charge_id']===null)PaymentException::reject('negative_draft_correction_required');
        $chain=[];$previous=$charge['previous_charge_id'];$seen=[];
        while($previous!==null){if(isset($seen[$previous]))PaymentException::reject('history_corrupt');$seen[$previous]=true;$item=$this->detail($supplier,'charges',$previous);
            if($item['unit_id']!==$charge['unit_id'])PaymentException::reject('foreign_charge');
            if($item['status']==='posted')foreach($item['lines'] as $line)if(bccomp($line['amount_cents'],'0',0)>0)$chain[$line['id']]=$line;
            $previous=$item['previous_charge_id'];
        }
        $allocations=[];
        if($chain!==[]){$marks=implode(',',array_fill(0,count($chain),'?'));
            $rows=$this->all("SELECT a.id,a.charge_line_id,a.amount_cents,r.amount_cents relieved FROM unit_payment_allocations a JOIN unit_payment_operations o ON o.supplier_id=a.supplier_id AND o.id=a.operation_id LEFT JOIN (SELECT r.supplier_id,r.allocation_id,SUM(r.amount_cents) amount_cents FROM unit_payment_reliefs r JOIN unit_payment_corrections c ON c.supplier_id=r.supplier_id AND c.id=r.correction_id WHERE c.status='posted' GROUP BY r.supplier_id,r.allocation_id) r ON r.supplier_id=a.supplier_id AND r.allocation_id=a.id WHERE a.supplier_id=? AND a.charge_line_id IN ($marks) AND o.status='posted' ORDER BY a.id",[$supplier,...array_keys($chain)]);
            foreach($rows as $r)$allocations[]=['id'=>(int)$r['id'],'original_line_id'=>(int)$r['charge_line_id'],'label'=>$chain[$r['charge_line_id']]['label'],'amount_cents'=>$r['amount_cents'],'available_relief_cents'=>bcsub($r['amount_cents'],$r['relieved']??'0',0)];
        }
        return ['charge'=>$charge,'original_lines'=>array_values($chain),'allocations'=>$allocations,'parties'=>$charge['parties'],'coverage'=>['eligibility'=>'current_preview_required']];
    }

    private function project(int $supplier,string $kind,array $rows):array
    {
        if($rows===[])return [];
        $ids=array_map('intval',array_column($rows,'id'));$marks=implode(',',array_fill(0,count($ids),'?'));
        $facts=[];$requests=[];
        foreach($rows as $row){
            $id=(int)$row['id'];
            if(isset($row['snapshot_json'])){$facts[$id]=$this->fact($row,'snapshot');}
            if(isset($row['request_json'])){
                $request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);
                $payload=$kind==='reservations'?['credit_id'=>(int)$row['credit_id'],'expected_version'=>$request['expected_version'],'request'=>$request['request'],'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]
                    :['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']];
                if(!hash_equals($row['request_hash'],PropertyInput::hash($payload)))PaymentException::reject('history_corrupt');
                $requests[$id]=$kind==='reservations'?$request['request']:$request;
            }
        }
        $out=[];
        if($kind==='credits'){
            $openingRows=array_values(array_filter($rows,fn($row)=>($row['opening_item_id']??null)!==null));
            if($openingRows!==[])(new \MyInvoice\Service\Property\Opening\OpeningOriginProof($this->db))->credits($supplier,$openingRows,false);
            $balances=$this->map("SELECT c.id,CASE WHEN COALESCE(o.status,b.status)='reversed' THEN 0 ELSE c.amount_cents END+COALESCE(SUM(CASE WHEN eop.status='posted' THEN e.amount_cents ELSE 0 END),0) balance_cents FROM unit_credit_lots c LEFT JOIN unit_payment_operations o ON o.supplier_id=c.supplier_id AND o.id=c.operation_id LEFT JOIN property_opening_items i ON i.supplier_id=c.supplier_id AND i.id=c.opening_item_id LEFT JOIN property_opening_batches b ON b.supplier_id=i.supplier_id AND b.id=i.batch_id LEFT JOIN unit_credit_events e ON e.supplier_id=c.supplier_id AND e.credit_id=c.id LEFT JOIN unit_payment_operations eop ON eop.supplier_id=e.supplier_id AND eop.id=e.operation_id WHERE c.supplier_id=? AND c.id IN ($marks) GROUP BY c.id,c.amount_cents,o.status,b.status",[$supplier,...$ids]);
            $reserved=$this->map("SELECT credit_id id,SUM(amount_cents-paid_cents) amount_cents FROM unit_credit_reservations WHERE supplier_id=? AND credit_id IN ($marks) AND status IN ('reserved','submitted') GROUP BY credit_id",[$supplier,...$ids]);
            foreach($rows as $row){$id=(int)$row['id'];$fact=$facts[$id];foreach(['unit_id','amount_cents','due_date'] as $key)if((string)$row[$key]!== (string)$fact[$key])PaymentException::reject('history_corrupt');
                $balance=(string)$balances[$id]['balance_cents'];$reserve=$reserved[$id]['amount_cents']??'0';if(bccomp($balance,$reserve,0)<0)PaymentException::reject('credit_capacity_corrupt');
                $out[]=['id'=>$id,'row_version'=>(int)$row['row_version'],'unit_id'=>(int)$row['unit_id'],'amount_cents'=>$row['amount_cents'],'balance_cents'=>$balance,'reserved_cents'=>$reserve,'available_cents'=>bcsub($balance,$reserve,0),'due_date'=>$row['due_date'],'parties'=>$this->partyLabels($fact['parties']),'operation_id'=>$row['operation_id']===null?null:(int)$row['operation_id']];
            }
        }elseif($kind==='reservations'){
            $creditIds=array_values(array_unique(array_map('intval',array_column($rows,'credit_id'))));$creditMarks=implode(',',array_fill(0,count($creditIds),'?'));
            $credits=$this->map("SELECT id,unit_id,snapshot_json,snapshot_hash FROM unit_credit_lots WHERE supplier_id=? AND id IN ($creditMarks)",[$supplier,...$creditIds]);
            $cancelRequested=array_fill_keys(array_map('intval',array_column($this->all("SELECT DISTINCT object_id FROM unit_payment_audit WHERE supplier_id=? AND object_kind='reservation' AND action='cancel_requested' AND object_id IN ($marks)",[$supplier,...$ids]),'object_id')),true);
            foreach($rows as $row){$credit=$credits[$row['credit_id']]??null;if(!$credit)PaymentException::reject('history_corrupt');$fact=$this->fact($credit,'snapshot');$id=(int)$row['id'];
                $out[]=['id'=>$id,'credit_id'=>(int)$row['credit_id'],'row_version'=>(int)$row['row_version'],'status'=>$row['status'],'amount_cents'=>$row['amount_cents'],'paid_cents'=>$row['paid_cents'],'remaining_cents'=>in_array($row['status'],['reserved','submitted'],true)?bcsub($row['amount_cents'],$row['paid_cents'],0):'0','unit_id'=>(int)$credit['unit_id'],'parties'=>$this->partyLabels($fact['parties']),'request'=>$requests[$id],
                    'cancel_requested'=>$row['status']==='submitted'&&isset($cancelRequested[$id])];
            }
        }elseif($kind==='charges'){
            $lines=$this->all("SELECT l.id,l.charge_id,l.component_id,l.amount_cents,l.line_json,d.debt_id FROM unit_charge_lines l LEFT JOIN unit_payment_debt_lines d ON d.supplier_id=l.supplier_id AND d.charge_line_id=l.id WHERE l.supplier_id=? AND l.charge_id IN ($marks) ORDER BY l.id",[$supplier,...$ids]);$byCharge=[];
            foreach($lines as $line){$fact=json_decode($line['line_json'],true,flags:JSON_THROW_ON_ERROR);$expected=array_values(array_filter($facts[$line['charge_id']]['lines'],fn($l)=>$l['component_id']===(int)$line['component_id']));if(count($expected)!==1||PropertyInput::hash($expected[0])!==PropertyInput::hash($fact))PaymentException::reject('history_corrupt');$byCharge[$line['charge_id']][]=['id'=>(int)$line['id'],'component_id'=>(int)$line['component_id'],'label'=>$fact['description']??$fact['label']??($fact['component']['name']??''),'amount_cents'=>$line['amount_cents'],'registered_debt_id'=>$line['debt_id']===null?null:(int)$line['debt_id']];}
            foreach($rows as $row){$id=(int)$row['id'];$fact=$facts[$id];$out[]=['id'=>$id,'row_version'=>(int)$row['row_version'],'unit_id'=>(int)$row['unit_id'],'unit_label'=>$fact['registry']['revision']['state']['unit_no']??$fact['registry']['object']['code'],'accounting_date'=>$row['accounting_date'],'due_date'=>$row['due_date'],'status'=>$row['status'],'amount_cents'=>$row['amount_cents'],'previous_charge_id'=>$row['previous_charge_id']===null?null:(int)$row['previous_charge_id'],'lines'=>$byCharge[$id]??[],'parties'=>$this->partyLabels($fact['parties'])];}
        }elseif($kind==='operations'){
            $associationIds=$ids;foreach($rows as $row)if($row['original_operation_id']!==null)$associationIds[]=(int)$row['original_operation_id'];$associationIds=array_values(array_unique($associationIds));
            $associationMarks=implode(',',array_fill(0,count($associationIds),'?'));$unitLinks=$this->all(self::operationUnitRelationsSql('?',$associationMarks),array_merge(...array_fill(0,self::OPERATION_UNIT_BRANCHES,[$supplier,...$associationIds])));$associated=[];
            foreach($unitLinks as $link)$associated[(int)$link['operation_id']][(int)$link['unit_id']]=true;
            $credits=$this->map("SELECT operation_id id,id credit_id,unit_id FROM unit_credit_lots WHERE supplier_id=? AND operation_id IN ($marks)",[$supplier,...$ids]);
            $allocations=$this->all("SELECT a.*,c.unit_id FROM unit_payment_allocations a JOIN (SELECT d.id,d.supplier_id,COALESCE(ch.unit_id,i.unit_id) unit_id FROM unit_payment_debts d LEFT JOIN unit_charges ch ON ch.supplier_id=d.supplier_id AND ch.id=d.charge_id LEFT JOIN property_opening_items i ON i.supplier_id=d.supplier_id AND i.id=d.opening_item_id) c ON c.supplier_id=a.supplier_id AND c.id=a.debt_id WHERE a.supplier_id=? AND a.operation_id IN ($marks) ORDER BY a.id",[$supplier,...$ids]);$parts=[];$units=[];
            foreach($allocations as $a){$parts[$a['operation_id']][]=['id'=>(int)$a['id'],'debt_id'=>(int)$a['debt_id'],'charge_line_id'=>$a['charge_line_id']===null?null:(int)$a['charge_line_id'],'accessory_id'=>$a['accessory_id']===null?null:(int)$a['accessory_id'],'opening_component_id'=>($a['opening_component_id']??null)===null?null:(int)$a['opening_component_id'],'label'=>$facts[$a['operation_id']]['request']['reason'],'amount_cents'=>$a['amount_cents']];$units[$a['operation_id']][(int)$a['unit_id']]=true;}
            foreach($rows as $row){$id=(int)$row['id'];$fact=$facts[$id];$credit=$credits[$id]??null;$unitIds=array_keys(($units[$id]??[])+($associated[$id]??[])+($row['original_operation_id']===null?[]:($associated[(int)$row['original_operation_id']]??[])));if($credit)$unitIds[]=(int)$credit['unit_id'];$source=$fact['source']['receipt']??null;if(isset($fact['credit']['snapshot']['unit_id']))$unitIds[]=$fact['credit']['snapshot']['unit_id'];
                $out[]=['id'=>$id,'kind'=>$row['kind'],'status'=>$row['status'],'row_version'=>(int)$row['row_version'],'amount_cents'=>$row['amount_cents'],'accounting_date'=>$row['accounting_date'],'reason'=>$requests[$id]['reason'],'source_label'=>$source?($source['ledger']['entry']['document_number']??$source['date']):null,'unit_ids'=>array_values(array_unique($unitIds)),'credit_id'=>$credit?(int)$credit['credit_id']:($fact['credit']['id']??null),'allocations'=>$parts[$id]??[]];}
        }elseif($kind==='corrections'){
            $credits=$this->map("SELECT c.id,cl.id credit_id FROM unit_payment_corrections c LEFT JOIN unit_credit_lots cl ON cl.supplier_id=c.supplier_id AND cl.operation_id=c.operation_id WHERE c.supplier_id=? AND c.id IN ($marks)",[$supplier,...$ids]);
            foreach($rows as $row){$id=(int)$row['id'];$fact=$facts[$id];$out[]=['id'=>$id,'status'=>$row['status'],'row_version'=>(int)$row['row_version'],'adjustment_charge_id'=>(int)$row['adjustment_charge_id'],'operation_id'=>$row['operation_id']===null?null:(int)$row['operation_id'],'credit_id'=>isset($credits[$id]['credit_id'])?(int)$credits[$id]['credit_id']:null,'relief_cents'=>$fact['relief_cents'],'accounting_date'=>$requests[$id]['accounting_date'],'reason'=>$requests[$id]['reason'],'unit_id'=>$fact['adjustment']['unit_id'],'anchors'=>$fact['anchors'],'reliefs'=>$fact['reliefs']];}
        }else{
            $out=$this->debts($supplier,$rows,$facts,$marks,$ids);
        }
        $unitIds=[];foreach($out as $item){if(isset($item['unit_id']))$unitIds[$item['unit_id']]=true;foreach($item['unit_ids']??[] as $unitId)$unitIds[$unitId]=true;}
        $labels=$this->unitLabels($supplier,array_keys($unitIds));
        foreach($out as &$item){if(isset($item['unit_id']))$item['unit_label']??=$labels[$item['unit_id']]??'';if(isset($item['unit_ids'])){$item['unit_labels']=array_values(array_map(fn($unitId)=>$labels[$unitId]??'',$item['unit_ids']));unset($item['unit_ids']);}}unset($item);
        return $out;
    }

    private function debts(int $supplier,array $rows,array $facts,string $marks,array $ids):array
    {
        // Discovery totals use the same posted allocations/reliefs as the current command proof; they never authorize a write.
        $paid=$this->all("WITH selected AS (SELECT a.* FROM unit_payment_allocations a JOIN unit_payment_operations o ON o.supplier_id=a.supplier_id AND o.id=a.operation_id WHERE a.supplier_id=? AND a.debt_id IN ($marks) AND o.status='posted'), relief AS (SELECT r.allocation_id,SUM(r.amount_cents) relieved FROM unit_payment_reliefs r JOIN selected a ON a.supplier_id=r.supplier_id AND a.id=r.allocation_id JOIN unit_payment_corrections c ON c.supplier_id=r.supplier_id AND c.id=r.correction_id WHERE c.status='posted' GROUP BY r.allocation_id) SELECT a.*,COALESCE(r.relieved,0) relieved FROM selected a LEFT JOIN relief r ON r.allocation_id=a.id ORDER BY a.id",[$supplier,...$ids]);
        $payments=[];foreach($paid as $part){$key=$part['charge_line_id']!==null?'line:'.$part['charge_line_id']:(($part['opening_component_id']??null)!==null?'opening:'.$part['opening_component_id']:'accessory:'.$part['accessory_id']);$payments[$key]=bcadd($payments[$key]??'0',bcsub($part['amount_cents'],$part['relieved'],0),0);}
        $reductions=$this->map("SELECT a.original_line_id id,SUM(a.amount_cents) amount_cents FROM unit_payment_correction_anchors a JOIN unit_payment_corrections c ON c.supplier_id=a.supplier_id AND c.id=a.correction_id JOIN unit_payment_debt_lines d ON d.supplier_id=a.supplier_id AND d.charge_line_id=a.original_line_id WHERE a.supplier_id=? AND d.debt_id IN ($marks) AND c.status='posted' GROUP BY a.original_line_id",[$supplier,...$ids]);
        $accessories=$this->all("SELECT * FROM unit_claim_accessory_receipts WHERE supplier_id=? AND debt_id IN ($marks) ORDER BY id",[$supplier,...$ids]);$extra=[];$debtRows=array_column($rows,null,'id');
        $openingReader=new \MyInvoice\Service\Property\Opening\OpeningOriginProof($this->db);$openingRows=array_values(array_filter($rows,fn($row)=>($row['opening_item_id']??null)!==null));
        $opening=$openingRows===[]?[]:$openingReader->debts($supplier,$openingRows,false);$openingAccessories=array_values(array_filter($accessories,fn($row)=>($row['opening_component_id']??null)!==null));
        if($openingAccessories!==[])$openingReader->accessories($supplier,$rows,$openingAccessories,false);
        foreach($accessories as $a){$fact=$this->fact($a,'receipt');$extra[$a['debt_id']][]=['id'=>(int)$a['id'],'component'=>$a['component'],'label'=>$fact['line']['description']??$a['component'],'amount_cents'=>$a['amount_cents'],'remaining_cents'=>bcsub($a['amount_cents'],$payments['accessory:'.$a['id']]??'0',0)];}
        $chargeIds=array_values(array_unique(array_map('intval',array_column($rows,'charge_id'))));$chargeMarks=implode(',',array_fill(0,count($chargeIds),'?'));
        $charges=$this->map("SELECT id,status FROM unit_charges WHERE supplier_id=? AND id IN ($chargeMarks)",[$supplier,...$chargeIds]);$out=[];
        foreach($rows as $row){$id=(int)$row['id'];$fact=$facts[$id];$origin=$opening[$id]??null;$lines=[];$remaining='0';
            foreach($fact['lines'] as $line){$amount=bcsub($line['line']['amount_cents'],$origin===null?($reductions[$line['id']]['amount_cents']??'0'):'0',0);$left=bcsub($amount,$payments[($origin===null?'line:':'opening:').$line['id']]??'0',0);if(bccomp($left,'0',0)<0)PaymentException::reject('claim_capacity_corrupt');$lines[]=['id'=>$line['id'],'label'=>$line['line']['description']??$fact['title_ref'],'amount_cents'=>$amount,'remaining_cents'=>$left];$remaining=bcadd($remaining,$left,0);}
            foreach($extra[$id]??[] as $a)$remaining=bcadd($remaining,$a['remaining_cents'],0);
            if(($origin!==null&&!$origin['active'])||($row['charge_id']!==null&&($charges[$row['charge_id']]['status']??null)==='reversed'))$remaining='0';
            $out[]=['id'=>$id,'charge_id'=>$row['charge_id']===null?null:(int)$row['charge_id'],'unit_id'=>$fact['unit_id'],'title_ref'=>$fact['title_ref'],'due_date'=>$fact['due_date'],'amount_cents'=>$fact['amount_cents'],'remaining_cents'=>$remaining,'legal'=>$fact['legal'],'security'=>$fact['security'],'lines'=>$lines,'accessories'=>$extra[$id]??[]];
        }return $out;
    }

    public function card(int $supplier,string $asOf,string $knownAt,array $scope=[]):array
    {
        $card=(new \MyInvoice\Service\Property\Receivables\PropertyReceivableProjection($this->db))->at($supplier,$asOf,$knownAt,$scope);
        $labels=$this->unitLabels($supplier,array_values(array_unique([...array_column($card['claims'],'unit_id'),...array_column($card['credits'],'unit_id')])));
        $ids=[];foreach($card['claims'] as $claim)foreach($claim['groups'] as $group)foreach($group['members'] as $id)$ids[$id]=true;
        $parties=[];if($ids!==[]){$marks=implode(',',array_fill(0,count($ids),'?'));foreach($this->all("SELECT id,company_name,first_name,last_name FROM clients WHERE supplier_id=? AND id IN ($marks)",[$supplier,...array_keys($ids)]) as $p)$parties[(int)$p['id']]=$this->partyLabels([$p])[0];}
        foreach($card['claims'] as &$claim){$claim['unit_label']=$labels[$claim['unit_id']]??'';foreach($claim['groups'] as &$group)$group['members']=array_map(fn($id)=>$parties[$id]??['id'=>$id,'label'=>''],$group['members']);unset($group);}unset($claim);
        foreach($card['credits'] as &$credit){$credit['unit_label']=$labels[$credit['unit_id']]??'';$credit['parties']=$this->partyLabels($credit['parties']);}unset($credit);
        return $card;
    }

    private function unitLabels(int $supplier,array $ids):array
    {
        if($ids===[])return [];$marks=implode(',',array_fill(0,count($ids),'?'));$out=[];
        foreach($this->all("SELECT id,code FROM property_objects WHERE supplier_id=? AND kind='unit' AND id IN ($marks)",[$supplier,...$ids]) as $row)$out[(int)$row['id']]=$row['code'];return $out;
    }
    private function partyLabels(array $parties):array {return array_map(static fn($p)=>['id'=>(int)$p['id'],'label'=>trim(($p['company_name']??'').' '.($p['first_name']??'').' '.($p['last_name']??''))],$parties);}
    private function fact(array $row,string $prefix):array {$value=json_decode($row[$prefix.'_json'],true,flags:JSON_THROW_ON_ERROR);if(!hash_equals($row[$prefix.'_hash'],PropertyInput::hash($value)))PaymentException::reject('history_corrupt');return $value;}
    private function all(string $sql,array $params):array {$q=$this->db->pdo()->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);}
    private function map(string $sql,array $params):array {return array_column($this->all($sql,$params),null,'id');}
}
