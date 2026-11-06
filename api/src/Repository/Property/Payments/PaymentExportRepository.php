<?php

declare(strict_types=1);
namespace MyInvoice\Repository\Property\Payments;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant};
use MyInvoice\Service\Property\Payments\{PaymentLocks,PaymentException};
use MyInvoice\Service\Property\Payments\Workflow\PaymentExportPlan;
use PDO;

final class PaymentExportRepository
{
    public function __construct(private readonly Connection $db,private readonly PaymentRepository $payments) {}
    public function preview(int $supplier,array $request):array
    {
        return $this->transaction($supplier,function()use($supplier,$request):array{$snapshot=$this->plan($supplier,$request);return ['preview_hash'=>PropertyInput::hash($snapshot),'snapshot'=>$snapshot];});
    }
    public function confirm(int $supplier,array $request,string $hash,string $token,int $actor):array
    {
        PropertyInput::code($token,'idempotency_key');PropertyInput::id($actor,'actor');
        return $this->transaction($supplier,function()use($supplier,$request,$hash,$token,$actor):array{
            $requestHash=PropertyInput::hash(['request'=>$request,'token'=>$token,'actor'=>$actor]);
            $old=$this->one('SELECT * FROM unit_payment_exports WHERE supplier_id=? AND idempotency_key=? FOR UPDATE',[$supplier,$token]);
            if($old){if(!hash_equals($old['request_hash'],$requestHash)||!hash_equals($old['snapshot_hash'],$hash))PaymentException::reject('idempotency_conflict');return $this->result($supplier,$old);}
            $snapshot=$this->plan($supplier,$request);if(!hash_equals($hash,PropertyInput::hash($snapshot)))PaymentException::reject('preview_stale');$bytes=PaymentExportPlan::bytes($snapshot);
            $this->execute('INSERT INTO unit_payment_exports (supplier_id,request_json,snapshot_json,request_hash,snapshot_hash,content_base64,content_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$supplier,self::json($request),self::json($snapshot),$requestHash,$hash,base64_encode($bytes),hash('sha256',$bytes),$token,$actor]);$id=(int)$this->db->pdo()->lastInsertId();
            foreach($snapshot['reservations'] as $reservation){
                $this->execute('INSERT INTO unit_payment_export_items (supplier_id,export_id,reservation_id,amount_cents) VALUES (?,?,?,?)',[$supplier,$id,$reservation['id'],$reservation['amount_cents']]);
                $this->cas("UPDATE unit_credit_reservations SET status='submitted',row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=? AND status='reserved'",[$supplier,$reservation['id'],$reservation['row_version']]);
                $this->audit($supplier,$reservation['id'],'submitted',['export_id'=>$id,'snapshot_hash'=>$hash,'amount_cents'=>$reservation['amount_cents']],$actor);
            }
            return $this->result($supplier,$this->one('SELECT * FROM unit_payment_exports WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]));
        });
    }
    /**
     * Žádost o zrušení příkazu, který už odešel bance. Rezervaci NEuvolní: banka mohla
     * platbu provést, takže kredit zůstává blokovaný ve stavu „odesláno, zrušení čeká na
     * potvrzení banky". Uvolní ho až {@see releaseUnexecuted()} s výpisem, který neprovedení
     * dokládá, nebo spárování provedené vratky z výpisu (tím se rezervace vyplatí).
     */
    public function cancelSubmission(int $supplier,int $id,int $expected,string $reason,string $evidence,string $token,int $actor):array
    {
        PropertyInput::id($actor,'actor');PropertyInput::code($token,'idempotency_key');PropertyInput::text($reason,'reason',4000);PropertyInput::text($evidence,'evidence',4000);
        return $this->transaction($supplier,function()use($supplier,$id,$expected,$reason,$evidence,$token,$actor):array{
            $payload=['expected_version'=>$expected,'reason'=>$reason,'evidence'=>$evidence,'token'=>$token,'actor'=>$actor,'kind'=>'submission_cancel_requested'];
            $old=$this->auditByToken($supplier,$id,'cancel_requested',$token);
            if($old!==null){if(PropertyInput::hash($old)!==PropertyInput::hash($payload))PaymentException::reject('idempotency_conflict');return $this->reservationState($supplier,$id);}
            $reservation=$this->payments->reservation($supplier,$id);
            if($reservation['row_version']!==$expected)PaymentException::reject('reservation_version_conflict');
            if($reservation['status']!=='submitted')PaymentException::reject('reservation_not_submitted');
            if($this->cancelRequest($supplier,$id)!==null)PaymentException::reject('reservation_cancel_already_requested');
            $this->submittedExport($supplier,$id);
            $this->cas("UPDATE unit_credit_reservations SET row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=? AND status='submitted'",[$supplier,$id,$expected]);
            $this->audit($supplier,$id,'cancel_requested',$payload,$actor);
            return $this->reservationState($supplier,$id);
        });
    }

    /**
     * Uvolní rezervaci odeslaného příkazu, který banka neprovedla. Doklad je výpis účtu,
     * ze kterého příkaz odešel, k datu splatnosti nebo později, a ten nesmí obsahovat
     * odchozí platbu příjemci v částce příkazu. Textové prohlášení samo nestačí.
     */
    public function releaseUnexecuted(int $supplier,int $id,int $expected,int $statementId,string $evidence,string $token,int $actor):array
    {
        PropertyInput::id($actor,'actor');PropertyInput::code($token,'idempotency_key');PropertyInput::id($statementId,'statement_id');PropertyInput::text($evidence,'evidence',4000);
        return $this->transaction($supplier,function()use($supplier,$id,$expected,$statementId,$evidence,$token,$actor):array{
            $payload=['expected_version'=>$expected,'statement_id'=>$statementId,'evidence'=>$evidence,'token'=>$token,'actor'=>$actor,'kind'=>'submission_not_executed'];
            $old=$this->auditByToken($supplier,$id,'failed',$token);
            if($old!==null){if(PropertyInput::hash($old)!==PropertyInput::hash($payload))PaymentException::reject('idempotency_conflict');return $this->reservationState($supplier,$id);}
            $reservation=$this->payments->reservation($supplier,$id);$credit=$this->payments->credit($supplier,$reservation['credit_id']);
            if($reservation['row_version']!==$expected)PaymentException::reject('reservation_version_conflict');
            if($reservation['status']!=='submitted')PaymentException::reject('reservation_not_submitted');
            $export=$this->submittedExport($supplier,$id);
            $snapshot=self::decode($export['snapshot_json']);
            $statement=$this->one('SELECT bs.id,bs.account_number,bs.bank_code,bs.statement_date FROM bank_statements bs WHERE bs.id=? AND '.\MyInvoice\Repository\BankStatementOwnershipResolver::sql().' FOR UPDATE',
                [$statementId,...\MyInvoice\Repository\BankStatementOwnershipResolver::params($supplier)]);
            if(!$statement)PaymentException::reject('statement_not_found');
            if(self::account((string)$statement['account_number'],(string)$statement['bank_code'])!==self::account((string)$snapshot['payer']['account_number'],(string)$snapshot['payer']['bank_code']))PaymentException::reject('statement_account_mismatch');
            if((string)$statement['statement_date']<(string)$snapshot['request']['due_date'])PaymentException::reject('statement_before_due_date');
            $amount=bcsub($reservation['amount_cents'],$reservation['paid_cents'],0);
            $recipient=(string)$reservation['request']['recipient_account'];
            foreach($this->all('SELECT bt.amount,bt.counterparty_account,bt.counterparty_bank FROM bank_transactions bt WHERE '.\MyInvoice\Service\Bank\StatementTransactionScope::sql($statementId).' AND bt.amount<0 FOR UPDATE',[]) as $tx){
                [$number,$bank]=array_pad(explode('/',$recipient,2),2,'');
                if(bcmul(ltrim((string)$tx['amount'],'-'),'100',0)===$amount&&self::account((string)$tx['counterparty_account'],(string)$tx['counterparty_bank'])===self::account($number,$bank))PaymentException::reject('refund_executed_on_statement',['statement_id'=>$statementId]);
            }
            $this->cas("UPDATE unit_credit_reservations SET status='failed',row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=? AND status='submitted'",[$supplier,$id,$expected]);
            $this->cas('UPDATE unit_credit_lots SET row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=?',[$supplier,$credit['id'],$credit['row_version']]);
            $this->audit($supplier,$id,'failed',$payload,$actor);
            return $this->reservationState($supplier,$id);
        });
    }

    /** Rezervace se stavem žádosti o zrušení odeslaného příkazu. */
    private function reservationState(int $supplier,int $id):array
    {
        $request=$this->cancelRequest($supplier,$id);
        $reservation=$this->payments->reservation($supplier,$id);
        return $reservation+['cancel_requested'=>$request!==null&&$reservation['status']==='submitted','cancel_request'=>$request];
    }

    private function cancelRequest(int $supplier,int $id):?array
    {
        foreach($this->all("SELECT payload_json,payload_hash,recorded_at FROM unit_payment_audit WHERE supplier_id=? AND object_kind='reservation' AND object_id=? AND action='cancel_requested' ORDER BY id FOR UPDATE",[$supplier,$id]) as $event){
            $fact=self::decode($event['payload_json']);self::hash($event['payload_hash'],$fact);
            return ['reason'=>$fact['reason'],'evidence'=>$fact['evidence'],'recorded_at'=>$event['recorded_at']];
        }
        return null;
    }

    private function auditByToken(int $supplier,int $id,string $action,string $token):?array
    {
        foreach($this->all("SELECT payload_json,payload_hash FROM unit_payment_audit WHERE supplier_id=? AND object_kind='reservation' AND object_id=? AND action=? ORDER BY id FOR UPDATE",[$supplier,$id,$action]) as $event){
            $fact=self::decode($event['payload_json']);self::hash($event['payload_hash'],$fact);
            if(($fact['token']??null)===$token)return $fact;
        }
        return null;
    }

    private function submittedExport(int $supplier,int $id):array
    {
        $export=$this->one('SELECT e.* FROM unit_payment_exports e JOIN unit_payment_export_items i ON i.supplier_id=e.supplier_id AND i.export_id=e.id WHERE i.supplier_id=? AND i.reservation_id=? FOR UPDATE',[$supplier,$id]);
        if(!$export)PaymentException::reject('export_receipt_missing');
        $this->result($supplier,$export);
        return $export;
    }

    private static function account(string $number,string $bank):string
    {
        return (\MyInvoice\Service\Bank\AccountNumberNormalizer::czechNational($number)??trim($number)).'/'.trim($bank);
    }
    public function find(int $supplier,int $id):array
    {
        return $this->transaction($supplier,function()use($supplier,$id):array{$row=$this->one('SELECT * FROM unit_payment_exports WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]);if(!$row)PaymentException::reject('export_not_found');return $this->result($supplier,$row);});
    }
    public function metadataForReservation(int $supplier,int $id):?array
    {
        PropertyInput::id($id,'reservation_id');
        return $this->transaction($supplier,function()use($supplier,$id):?array{
            $reservation=$this->payments->reservation($supplier,$id);
            $links=$this->all('SELECT export_id FROM unit_payment_export_items WHERE supplier_id=? AND reservation_id=? ORDER BY export_id FOR UPDATE',[$supplier,$id]);
            $rows=$this->all("SELECT e.* FROM unit_payment_exports e WHERE e.supplier_id=? AND (EXISTS (SELECT 1 FROM unit_payment_export_items i WHERE i.supplier_id=e.supplier_id AND i.export_id=e.id AND i.reservation_id=?) OR EXISTS (SELECT 1 FROM JSON_TABLE(e.snapshot_json,'$.reservations[*]' COLUMNS(reservation_id BIGINT PATH '$.id')) p WHERE p.reservation_id=?)) ORDER BY e.id FOR UPDATE",[$supplier,$id,$id]);
            $audit=$this->all("SELECT payload_json,payload_hash FROM unit_payment_audit WHERE supplier_id=? AND object_kind='reservation' AND object_id=? AND action='submitted' ORDER BY id FOR UPDATE",[$supplier,$id]);
            $facts=[];
            foreach($audit as $event){$fact=self::decode($event['payload_json']);self::hash($event['payload_hash'],$fact);$facts[]=$fact;}
            if($rows===[]){if($links!==[]||$facts!==[]||$reservation['status']==='submitted')PaymentException::reject('export_receipt_missing');return null;}
            if(count($rows)!==1||count($links)!==1||count($facts)!==1)PaymentException::reject('export_receipt_mismatch');
            $row=$rows[0];$fact=$facts[0];
            if((int)$links[0]['export_id']!==(int)$row['id']||($fact['export_id']??null)!==(int)$row['id']||($fact['snapshot_hash']??null)!==$row['snapshot_hash'])PaymentException::reject('export_receipt_mismatch');
            $result=$this->result($supplier,$row);
            $snapshot=self::decode($row['snapshot_json']);$selected=array_values(array_filter($snapshot['reservations'],fn($part)=>$part['id']===$id));
            if(count($selected)!==1||($fact['amount_cents']??null)!==$selected[0]['amount_cents'])PaymentException::reject('export_receipt_mismatch');
            foreach($result['reservation_statuses'] as $status)if(!in_array($status,['submitted','paid','cancelled','failed'],true))PaymentException::reject('export_receipt_mismatch');
            return array_intersect_key($result,array_flip(['id','filename','encoding','reservation_ids','reservation_statuses']));
        });
    }
    public function reservationStatusAfterReversal(int $supplier,array $reservation):string
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Reservation restoration requires current payment context.');
        $row=$this->one('SELECT e.* FROM unit_payment_exports e JOIN unit_payment_export_items i ON i.supplier_id=e.supplier_id AND i.export_id=e.id WHERE i.supplier_id=? AND i.reservation_id=? FOR UPDATE',[$supplier,$reservation['id']]);
        if($row)$this->result($supplier,$row);
        return in_array($reservation['status'],['cancelled','failed'],true)?$reservation['status']:($row!==null?'submitted':'reserved');
    }
    private function plan(int $supplier,array $request):array
    {
        PropertyInput::fields($request,['reservation_ids','expected_versions','bank_account_id','due_date','reason','payment_symbols']);
        $ids=PropertyInput::list($request['reservation_ids']??null,'reservation_ids');foreach($ids as $id)PropertyInput::id($id,'reservation_id');
        if($ids===[]||count($ids)>100||count(array_unique($ids))!==count($ids))PropertyInput::invalid('reservation_ids');sort($ids,SORT_NUMERIC);
        if(!is_array($request['expected_versions']??null)||!is_array($request['payment_symbols']??null))PropertyInput::invalid('expected_versions');
        foreach(['expected_versions','payment_symbols'] as $field){$keys=array_map('intval',array_keys($request[$field]));sort($keys,SORT_NUMERIC);if($keys!==$ids)PropertyInput::invalid($field);}
        $date=\MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest::date($request['due_date']??null);PropertyInput::text($request['reason']??null,'reason',4000);
        $bank=$this->one('SELECT id,label,account_number,bank_code,currency,kind,is_active FROM supplier_bank_accounts WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,PropertyInput::id($request['bank_account_id']??null,'bank_account_id')]);
        if(!$bank||!(bool)$bank['is_active']||$bank['currency']!=='CZK'||$bank['kind']==='credit_card')PaymentException::reject('abo_account_setup_required');
        $payer=['id'=>(int)$bank['id'],'label'=>$bank['label'],'account_number'=>$bank['account_number'],'bank_code'=>$bank['bank_code'],'currency'=>$bank['currency']];
        $reservations=[];$total='0';
        foreach($ids as $id){
            $row=$this->payments->reservation($supplier,$id);$credit=$this->payments->credit($supplier,$row['credit_id']);
            if($row['row_version']!==($request['expected_versions'][$id]??null))PaymentException::reject('reservation_version_conflict');
            if($row['status']!=='reserved'||($row['request']['delivery_method']??'bank')!=='bank')PaymentException::reject('reservation_not_exportable');
            if($this->one('SELECT id FROM unit_payment_export_items WHERE supplier_id=? AND reservation_id=? FOR UPDATE',[$supplier,$id]))PaymentException::reject('reservation_already_exported');
            $symbols=$request['payment_symbols'][$id];if(!is_array($symbols))PropertyInput::invalid('payment_symbols');PropertyInput::fields($symbols,['variable_symbol','constant_symbol','specific_symbol','allow_missing_variable_symbol','message']);
            if(isset($symbols['message']))PropertyInput::text($symbols['message'],'message',4000);
            $amount=bcsub($row['amount_cents'],$row['paid_cents'],0);$total=bcadd($total,$amount,0);
            $reservations[]=['id'=>$id,'credit_id'=>$row['credit_id'],'row_version'=>$row['row_version'],'paid_cents'=>$row['paid_cents'],'amount_cents'=>$amount,'request'=>$row['request'],'request_hash'=>$row['request_hash'],'credit_snapshot_hash'=>PropertyInput::hash($credit['snapshot'])];
        }
        $snapshot=['request'=>$request,'payer'=>$payer,'reservations'=>$reservations,'total_cents'=>$total];PaymentExportPlan::bytes($snapshot);return $snapshot;
    }
    private function result(int $supplier,array $row):array
    {
        $request=self::decode($row['request_json']);$snapshot=self::decode($row['snapshot_json']);self::hash($row['request_hash'],['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]);self::hash($row['snapshot_hash'],$snapshot);
        if(PropertyInput::hash($snapshot['request'])!==PropertyInput::hash($request))PaymentException::reject('history_corrupt');
        $bytes=base64_decode($row['content_base64'],true);if($bytes===false||!hash_equals($row['content_hash'],hash('sha256',$bytes))||$bytes!==PaymentExportPlan::bytes($snapshot))PaymentException::reject('export_hash_mismatch');
        $items=$this->all('SELECT reservation_id,amount_cents FROM unit_payment_export_items WHERE supplier_id=? AND export_id=? ORDER BY reservation_id FOR UPDATE',[$supplier,$row['id']]);
        if(count($items)!==count($snapshot['reservations']))PaymentException::reject('export_receipt_mismatch');$states=[];
        foreach($snapshot['reservations'] as $index=>$fact){$item=$items[$index];if((int)$item['reservation_id']!==$fact['id']||$item['amount_cents']!==$fact['amount_cents'])PaymentException::reject('export_receipt_mismatch');
            $current=$this->payments->reservation($supplier,$fact['id']);
            $credit=$this->payments->credit($supplier,$fact['credit_id']);if(PropertyInput::hash($credit['snapshot'])!==$fact['credit_snapshot_hash'])PaymentException::reject('export_receipt_mismatch');
            if($current['credit_id']!==$fact['credit_id']||$current['request_hash']!==$fact['request_hash']||bcadd($fact['paid_cents'],$fact['amount_cents'],0)!==$current['amount_cents']||$current['row_version']<=$fact['row_version'])PaymentException::reject('export_receipt_mismatch');$states[$fact['id']]=$current['status'];
        }
        return ['id'=>(int)$row['id'],'filename'=>'vratky-'.$row['id'].'.kpc','content_base64'=>$row['content_base64'],'encoding'=>'windows-1250','reservation_ids'=>array_column($snapshot['reservations'],'id'),'reservation_statuses'=>$states];
    }
    private function transaction(int $supplier,callable $work):mixed{return (new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$work){(new PaymentLocks($this->db))->acquire($supplier);return $work();});}
    private function audit(int $supplier,int $id,string $action,array $payload,int $actor):void {$this->execute("INSERT INTO unit_payment_audit (supplier_id,object_kind,object_id,action,payload_json,payload_hash,created_by,recorded_at) VALUES (?,'reservation',?,?,?,?,?,UTC_TIMESTAMP(6))",[$supplier,$id,$action,self::json($payload),PropertyInput::hash($payload),$actor]);}
    private function execute(string $sql,array $params):\PDOStatement {$q=$this->db->pdo()->prepare($sql);$q->execute($params);return $q;}
    private function one(string $sql,array $params):?array {return $this->execute($sql,$params)->fetch(PDO::FETCH_ASSOC)?:null;}
    private function all(string $sql,array $params):array {return $this->execute($sql,$params)->fetchAll(PDO::FETCH_ASSOC);}
    private function cas(string $sql,array $params):void {if($this->execute($sql,$params)->rowCount()!==1)PaymentException::reject('version_conflict');}
    private static function json(array $v):string {return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
    private static function decode(string $v):array {return json_decode($v,true,flags:JSON_THROW_ON_ERROR);}
    private static function hash(string $hash,array $v):void {if(!hash_equals($hash,PropertyInput::hash($v)))PaymentException::reject('history_corrupt');}
}
