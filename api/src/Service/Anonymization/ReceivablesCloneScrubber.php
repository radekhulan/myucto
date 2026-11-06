<?php
declare(strict_types=1);
namespace MyInvoice\Service\Anonymization;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Repository\Property\Receivables\{PartyEventRepository,DebtCertificateRepository,FinancialHistoryRepository};
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Receivables\ReceivableException;
use PDO;
final class ReceivablesCloneScrubber
{
    private const TABLES=['property_claim_party_events','property_claim_party_event_debts','property_claim_party_members','property_debt_certificates'];
    private const TEXT=['reason','title_ref','evidence','liability_evidence','evidence_ref','legal_reason','legal_basis','applicable_law','knowledge_evidence','guarantee_evidence','authority_evidence','purpose','coverage_acknowledgement'];
    private array $hashes=[];
    public function __construct(private readonly Connection $db,private readonly TextScrubber $scrubber){}
    public function run(array $registryMap,array $issuanceMap,array $paymentMap):array
    {
        $pdo=$this->db->pdo();$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?,?,?)');$q->execute(self::TABLES);$count=(int)$q->fetchColumn();
        if($count===0)return [];
        if($count!==4||$pdo->inTransaction())throw new \LogicException('Receivables clone requires complete schema and its own transaction.');
        if(TriggerMetadata::any($pdo))throw new \LogicException('Receivables clone must precede trigger installation.');
        $this->hashes=$paymentMap;
        foreach($registryMap as $supplier=>$proofs)foreach($proofs as $id=>$proof){$this->verifyCurrent('property_revisions','state_hash',(int)$supplier,(int)$id,$proof['after_state_hash']);$this->hashes[$proof['before_state_hash']]=$proof['after_state_hash'];}
        foreach($issuanceMap as $supplier=>$proofs)foreach($proofs as $id=>$proof){$this->verifyCurrent('unit_charges','snapshot_hash',(int)$supplier,(int)$id,$proof['after_snapshot_hash']);$this->hashes[$proof['before_snapshot_hash']]=$proof['after_snapshot_hash'];}
        $pdo->beginTransaction();
        try{
            $events=$pdo->query('SELECT * FROM property_claim_party_events ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);$certificates=$pdo->query('SELECT * FROM property_debt_certificates ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
            $suppliers=array_values(array_unique(array_merge(array_column($events,'supplier_id'),array_column($certificates,'supplier_id'))));
            $financial=[];foreach($suppliers as $supplier){(new PartyEventRepository($this->db))->history((int)$supplier,true);$financial[(int)$supplier]=(new FinancialHistoryRepository($this->db))->read((int)$supplier,true);}
            foreach($certificates as $row)(new DebtCertificateRepository($this->db))->find((int)$row['supplier_id'],(int)$row['id'],true);
            foreach($events as $row){
                $snapshot=FinancialHistoryRepository::decode($row);
                foreach($snapshot['debts'] as $debt){
                    $old=$debt['source_snapshot_hash'];if(!isset($paymentMap[$old]))ReceivableException::reject('clone_payment_proof');$this->verifyCurrent('unit_payment_debts','snapshot_hash',(int)$row['supplier_id'],$debt['debt_id'],$paymentMap[$old]);
                    $source=$financial[(int)$row['supplier_id']]['debts'][$debt['debt_id']]??null;if(!$source||$source['snapshot_hash']!==$paymentMap[$old])ReceivableException::reject('clone_payment_proof');$currentEvents=array_column($source['events'],null,'id');
                    foreach($debt['financial_events'] as $event)if(!isset($currentEvents[$event['id']])||PropertyInput::hash($event)!==PropertyInput::hash($currentEvents[$event['id']]))ReceivableException::reject('clone_payment_proof');
                }
                $registry=$snapshot['registry'];$proof=$registryMap[$row['supplier_id']][$registry['id']]??null;if(!$proof||$proof['before_state_hash']!==$registry['state_hash'])ReceivableException::reject('clone_registry_proof');
                $request=$this->transform(json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR),$registryMap,(int)$row['supplier_id']);$snapshot=$this->transform($snapshot,$registryMap,(int)$row['supplier_id']);
                $new=PropertyInput::hash($snapshot);$this->hashes[$row['snapshot_hash']]=$new;$this->update('property_claim_party_events',$row,$request,$snapshot,$new);
            }
            foreach($certificates as $row){
                $snapshot=FinancialHistoryRepository::decode($row);$request=$this->transform(json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR),$registryMap,(int)$row['supplier_id']);$snapshot=$this->transform($snapshot,$registryMap,(int)$row['supplier_id']);
                foreach($snapshot['projection']['claims'] as $claim){if($claim['debt_id']!==null)$this->verifyCurrent('unit_payment_debts','snapshot_hash',(int)$row['supplier_id'],$claim['debt_id'],$claim['source_hash']);}
                $new=PropertyInput::hash($snapshot);$this->hashes[$row['snapshot_hash']]=$new;$this->update('property_debt_certificates',$row,$request,$snapshot,$new);
            }
            foreach($suppliers as $supplier)foreach((new PartyEventRepository($this->db))->history((int)$supplier,true) as $event)foreach($event['snapshot']['debts'] as $debt)$this->verifyCurrent('unit_payment_debts','snapshot_hash',(int)$supplier,$debt['debt_id'],$debt['source_snapshot_hash']);
            foreach($certificates as $row)(new DebtCertificateRepository($this->db))->find((int)$row['supplier_id'],(int)$row['id'],true);
            $pdo->commit();return $this->hashes;
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    private function transform(array $data,array $registryMap,int $supplier):array
    {
        $projection=isset($data['claims'],$data['totals'],$data['snapshot_hash']);
        if($projection){$raw=$data;unset($raw['snapshot_hash']);if(PropertyInput::hash($raw)!==$data['snapshot_hash'])ReceivableException::reject('history_corrupt');}
        if(isset($data['state'],$data['state_hash'],$data['id'])){
            $proof=$registryMap[$supplier][$data['id']]??null;if(!$proof||$proof['before_state_hash']!==$data['state_hash']||PropertyInput::hash($data['state'])!==$data['state_hash'])ReceivableException::reject('clone_registry_proof');
            $data['state']=(new PropertyCloneScrubber($this->db->pdo(),$this->scrubber))->scrubSnapshotState('unit',$data['state']);if(PropertyInput::hash($data['state'])!==$proof['after_state_hash'])ReceivableException::reject('clone_registry_proof');
        }
        $out=[];foreach($data as $key=>$value){
            if($key==='state'&&isset($data['state_hash']))$out[$key]=$value;
            elseif(is_array($value))$out[$key]=$this->transform($value,$registryMap,$supplier);
            elseif($key==='kind'&&isset($data['applicable_law'],$data['legal_basis'])&&is_string($value))$out[$key]=$this->scrubber->scrub($value);
            elseif(is_string($value)&&in_array((string)$key,self::TEXT,true))$out[$key]=$this->scrubber->scrub($value);
            elseif(is_string($value)&&in_array((string)$key,['company_name','first_name','last_name'],true))$out[$key]=json_decode($this->scrubber->scrubJson(self::json([$key=>$value])),true,flags:JSON_THROW_ON_ERROR)[$key];
            elseif(is_string($value)&&in_array((string)$key,['state_hash','source_snapshot_hash','source_hash','snapshot_hash','event_hash','hash'],true)){$out[$key]=$this->hashes[$value]??$value;}
            else $out[$key]=$value;
        }
        if($projection){unset($out['snapshot_hash']);$out['snapshot_hash']=PropertyInput::hash($out);}
        return $out;
    }
    private function verifyCurrent(string $table,string $field,int $supplier,int $id,string $hash):void{$q=$this->db->pdo()->prepare('SELECT '.$field.' FROM '.$table.' WHERE supplier_id=? AND id=?');$q->execute([$supplier,$id]);if($q->fetchColumn()!==$hash)ReceivableException::reject('clone_source_proof');}
    private function update(string $table,array $row,array $request,array $snapshot,string $hash):void{$q=$this->db->pdo()->prepare('UPDATE '.$table.' SET request_json=?,snapshot_json=?,request_hash=?,snapshot_hash=? WHERE supplier_id=? AND id=?');$q->execute([self::json($request),self::json($snapshot),PropertyInput::hash(['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]),$hash,$row['supplier_id'],$row['id']]);}
    private static function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
}
