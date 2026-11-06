<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Property\Services\Settlement;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Services\Settlement\{SettlementDocument,SettlementEvidence,SettlementOriginAdapter,SettlementHistory};
use PDO;
final class SettlementDocumentRepository
{
    public function __construct(private readonly Connection $db,private readonly SettlementOriginAdapter $origins){}
    public function save(int $supplier,SettlementDocument $document,array $selectors,string $date,int $expectedRevision,string $token,int $actor):array
    {
        PropertyInput::id($supplier,'supplier_id');PropertyInput::id($actor,'actor');PropertyInput::code($token,'idempotency_key');
        $facts=$document->snapshot();if($facts['supplier_id']!==$supplier)SettlementEvidence::reject('origin_scope');PropertyInput::code($facts['segment_key'],'segment_key');if($expectedRevision<0)SettlementEvidence::reject('revision');
        $request=['document_hash'=>$document->hash(),'selectors'=>$selectors,'accounting_date'=>$date,'expected_revision'=>$expectedRevision,'token'=>$token,'actor'=>$actor];$requestHash=PropertyInput::hash($request);
        return (new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$document,$facts,$selectors,$date,$expectedRevision,$token,$actor,$request,$requestHash):array{
            (new PaymentLocks($this->db))->acquire($supplier);
            $old=$this->one('SELECT id,request_hash FROM property_service_settlements WHERE supplier_id=? AND idempotency_key=? FOR UPDATE',[$supplier,$token]);
            if($old){if(!hash_equals($old['request_hash'],$requestHash))SettlementEvidence::reject('idempotency_conflict');return $this->find($supplier,(int)$old['id'],true);}
            $previous=$this->one('SELECT id,revision_no FROM property_service_settlements WHERE supplier_id=? AND unit_id=? AND service_id=? AND segment_key=? ORDER BY revision_no DESC LIMIT 1 FOR UPDATE',[$supplier,$facts['unit_id'],$facts['service_id'],$facts['segment_key']]);
            if((int)($previous['revision_no']??0)!==$expectedRevision)SettlementEvidence::reject('revision_conflict');
            if($previous&&(new SettlementHistory($this->db))->hasActiveApplicationForDocument($supplier,(int)$previous['id']))SettlementEvidence::reject('applied_document_requires_correction');
            $current=$this->origins->current($supplier,$facts['unit_id'],$facts['service_id'],$date,$selectors);
            if(PropertyInput::hash($current['origins'])!==PropertyInput::hash($facts['origins']))SettlementEvidence::reject('document_origin_changed');
            $costs=array_values($current['costs']);$matching=array_values(array_filter($costs,fn($cost)=>PropertyInput::hash($cost)===PropertyInput::hash($facts['cost'])));
            if(count($matching)!==1)SettlementEvidence::reject('documented_cost_source_required');
            $allocations=array_column($current['allocations'],null,'id');$cutoff=(new \DateTimeImmutable($facts['cutoff_at']))->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d');
            foreach($facts['cutoff_allocations'] as $allocation){$actual=$allocations[$allocation['id']]??null;if(!$actual||!$actual['active']||$actual['accounting_date']>$cutoff||$actual['source_hash']!==$allocation['source_hash']||$actual['amount_cents']!==$allocation['amount_cents']||$actual['origin_key']!==$allocation['origin_key'])SettlementEvidence::reject('cutoff_current_proof_required');}
            $receipt=['request'=>$request,'current'=>$current];$pdo=$this->db->pdo();
            $pdo->prepare('INSERT INTO property_service_settlements (supplier_id,unit_id,service_id,segment_key,revision_no,previous_id,document_json,document_hash,origin_receipt_json,origin_receipt_hash,request_hash,idempotency_key,created_by,cost_line_id,recorded_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))')
                ->execute([$supplier,$facts['unit_id'],$facts['service_id'],$facts['segment_key'],$expectedRevision+1,$previous['id']??null,self::json($facts),$document->hash(),self::json($receipt),PropertyInput::hash($receipt),$requestHash,$token,$actor,$facts['cost']['source_id']]);
            $id=(int)$pdo->lastInsertId();
            foreach($facts['origins'] as $i=>$source)$pdo->prepare('INSERT INTO property_service_settlement_origins (supplier_id,document_id,ordinal,charge_line_id,opening_component_id,opening_advance_component_id,debt_id) VALUES (?,?,?,?,?,?,?)')->execute([$supplier,$id,$i,$source['kind']==='s3_line'?$source['id']:null,$source['kind']==='opening_component'?$source['id']:null,$source['opening_advance_component_id'],$source['debt_id']]);
            return $this->find($supplier,$id,true);
        });
    }
    public function findLatest(int $supplier,int $id):array
    {
        $document=$this->find($supplier,$id,true);$facts=$document['document'];
        $latest=$this->one('SELECT id FROM property_service_settlements WHERE supplier_id=? AND unit_id=? AND service_id=? AND segment_key=? ORDER BY revision_no DESC LIMIT 1 FOR UPDATE',[$supplier,$facts['unit_id'],$facts['service_id'],$facts['segment_key']]);
        if(!$latest||(int)$latest['id']!==$id)SettlementEvidence::reject('document_superseded');return $document;
    }
    public function find(int $supplier,int $id,bool $current=false):array
    {
        if($current&&!$this->db->pdo()->inTransaction())throw new \LogicException('Current document read requires an owner transaction.');
        $suffix=$current?' FOR UPDATE':'';$row=$this->one('SELECT * FROM property_service_settlements WHERE supplier_id=? AND id=?'.$suffix,[$supplier,$id]);if(!$row)SettlementEvidence::reject('document_not_found');
        $facts=json_decode($row['document_json'],true,flags:JSON_THROW_ON_ERROR);$receipt=json_decode($row['origin_receipt_json'],true,flags:JSON_THROW_ON_ERROR);
        if(PropertyInput::hash($facts)!==$row['document_hash']||PropertyInput::hash($receipt)!==$row['origin_receipt_hash']||PropertyInput::hash($receipt['request'])!==$row['request_hash'])SettlementEvidence::reject('document_corrupt');
        $current=$receipt['current'];$currentHash=$current['current_hash'];unset($current['current_hash']);if(PropertyInput::hash($current)!==$currentHash||PropertyInput::hash($current['origins'])!==PropertyInput::hash($facts['origins']))SettlementEvidence::reject('document_corrupt');
        $input=$facts;unset($input['amounts']);if(SettlementDocument::fromEvidence($input)->hash()!==$row['document_hash'])SettlementEvidence::reject('document_corrupt');
        if($facts['cost']['source_id']!==(int)$row['cost_line_id']||$facts['supplier_id']!==$supplier||$facts['unit_id']!==(int)$row['unit_id']||$facts['service_id']!==(int)$row['service_id']||$facts['segment_key']!==$row['segment_key']||$receipt['request']['actor']!==(int)$row['created_by']||$receipt['request']['token']!==$row['idempotency_key']||$receipt['request']['document_hash']!==$row['document_hash']||$receipt['request']['expected_revision']+1!==(int)$row['revision_no'])SettlementEvidence::reject('document_corrupt');
        $q=$this->db->pdo()->prepare('SELECT ordinal,charge_line_id,opening_component_id,opening_advance_component_id,debt_id FROM property_service_settlement_origins WHERE supplier_id=? AND document_id=? ORDER BY ordinal'.$suffix);$q->execute([$supplier,$id]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==count($facts['origins']))SettlementEvidence::reject('document_corrupt');
        foreach($facts['origins'] as $i=>$source){$want=['ordinal'=>$i,'charge_line_id'=>$source['kind']==='s3_line'?$source['id']:null,'opening_component_id'=>$source['kind']==='opening_component'?$source['id']:null,'opening_advance_component_id'=>$source['opening_advance_component_id'],'debt_id'=>$source['debt_id']];$actual=array_map(fn($v)=>$v===null?null:(int)$v,$rows[$i]);if($actual!==$want)SettlementEvidence::reject('document_corrupt');}
        return ['id'=>$id,'supplier_id'=>$supplier,'revision_no'=>(int)$row['revision_no'],'previous_id'=>$row['previous_id']===null?null:(int)$row['previous_id'],'document'=>$facts,'document_hash'=>$row['document_hash'],'origin_receipt'=>$receipt,'origin_receipt_hash'=>$row['origin_receipt_hash'],'created_by'=>(int)$row['created_by'],'recorded_at'=>$row['recorded_at']];
    }
    private function one(string $sql,array $args):?array{$q=$this->db->pdo()->prepare($sql);$q->execute($args);return $q->fetch(PDO::FETCH_ASSOC)?:null;}
    private static function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
}
