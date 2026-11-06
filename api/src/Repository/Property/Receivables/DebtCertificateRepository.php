<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Property\Receivables;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant};
use MyInvoice\Service\Property\Receivables\{PropertyReceivableProjection,ReceivableException};
use PDO;
final class DebtCertificateRepository
{
    public function __construct(private readonly Connection $db){}
    public function preview(int $supplier,array $request):array{return $this->transaction($supplier,fn()=> $this->plan($supplier,$request));}
    public function issue(int $supplier,array $request,string $previewHash,string $token,int $actor):array
    {
        PropertyInput::code($token,'idempotency_key');PropertyInput::id($actor,'actor');
        return $this->transaction($supplier,function()use($supplier,$request,$previewHash,$token,$actor){
            $hash=PropertyInput::hash(['request'=>$request,'token'=>$token,'actor'=>$actor]);$old=$this->row('SELECT id,request_hash,snapshot_hash FROM property_debt_certificates WHERE supplier_id=? AND idempotency_key=? FOR UPDATE',[$supplier,$token]);
            if($old){if(!hash_equals($old['request_hash'],$hash)||!hash_equals($old['snapshot_hash'],$previewHash))ReceivableException::reject('idempotency_conflict');return $this->find($supplier,(int)$old['id'],true);}
            $plan=$this->plan($supplier,$request);if(!hash_equals($plan['preview_hash'],$previewHash))ReceivableException::reject('preview_stale');
            $this->execute('INSERT INTO property_debt_certificates(supplier_id,unit_id,as_of,known_at,recorded_at,request_json,snapshot_json,request_hash,snapshot_hash,idempotency_key,created_by) VALUES (?,?,?,?,UTC_TIMESTAMP(6),?,?,?,?,?,?)',[$supplier,$request['unit_id'],PropertyInstant::sql($request['as_of']),PropertyInstant::sql($request['known_at']),self::json($request),self::json($plan['snapshot']),$hash,$previewHash,$token,$actor]);
            return $this->find($supplier,(int)$this->db->pdo()->lastInsertId(),true);
        });
    }
    public function find(int $supplier,int $id,bool $currentRead=false):array
    {
        if($currentRead&&!$this->db->pdo()->inTransaction())throw new \LogicException('Certificate current read requires owner transaction.');
        $row=$this->row('SELECT * FROM property_debt_certificates WHERE supplier_id=? AND id=?'.($currentRead?' FOR UPDATE':''),[$supplier,$id]);if(!$row)ReceivableException::reject('certificate_not_found');
        $snapshot=FinancialHistoryRepository::decode($row);$request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);
        if(!hash_equals($row['request_hash'],PropertyInput::hash(['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]))||PropertyInput::hash($request)!==PropertyInput::hash($snapshot['request'])||$request['unit_id']!==(int)$row['unit_id']||PropertyInstant::sql($request['as_of'])!==$row['as_of']||PropertyInstant::sql($request['known_at'])!==$row['known_at'])ReceivableException::reject('history_corrupt');
        return ['id'=>$id,'supplier_id'=>$supplier,'created_by'=>(int)$row['created_by'],'recorded_at'=>FinancialHistoryRepository::instant($row['recorded_at']),'snapshot'=>$snapshot,'snapshot_hash'=>$row['snapshot_hash']];
    }
    private function plan(int $supplier,array $request):array
    {
        PropertyInput::fields($request,['unit_id','as_of','known_at','requester_id','authority_evidence','purpose','coverage_acknowledgement']);
        $at=PropertyInstant::normalize($request['as_of']??null);$known=PropertyInstant::normalize($request['known_at']??null);$now=PartyEventRepository::now();if($at>$now||$known>$now)ReceivableException::reject('future_certificate');
        foreach(['authority_evidence','purpose','coverage_acknowledgement'] as $field)PropertyInput::text($request[$field]??null,$field,4000);
        $reader=new PropertyReadRepository($this->db);$unit=$reader->find($supplier,PropertyInput::id($request['unit_id']??null,'unit_id'),$at,$known,true);if($unit['kind']!=='unit')ReceivableException::reject('foreign_unit');
        $parties=$reader->parties($supplier,[PropertyInput::id($request['requester_id']??null,'requester_id')],true);if(count($parties)!==1)ReceivableException::reject('foreign_party');
        $projection=(new PropertyReceivableProjection($this->db))->at($supplier,$at,$known,['unit_id'=>$request['unit_id']],true);
        if(!$projection['coverage']['financial_complete']||array_any($projection['claims'],fn($row)=>!$row['liability']['complete']))ReceivableException::reject('certificate_review');
        $snapshot=['request'=>$request,'projection'=>$projection,'registry'=>$unit,'requester'=>$parties[0],'statement_scope'=>$projection['coverage']['scope'],'unconditional_no_debt'=>false];
        return ['snapshot'=>$snapshot,'preview_hash'=>PropertyInput::hash($snapshot)];
    }
    private function transaction(int $supplier,callable $work):mixed{return (new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$work){(new PaymentLocks($this->db))->acquire($supplier);return $work();});}
    private function execute(string $sql,array $args):\PDOStatement{$q=$this->db->pdo()->prepare($sql);$q->execute($args);return $q;}
    private function row(string $sql,array $args):?array{return $this->execute($sql,$args)->fetch(PDO::FETCH_ASSOC)?:null;}
    private static function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
}
