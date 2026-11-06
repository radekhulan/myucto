<?php
declare(strict_types=1);
namespace MyInvoice\Service\Anonymization;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Services\Settlement\{SettlementHistory,SettlementUseGuard};
use PDO;
final class SettlementCloneScrubber
{
    private array $nodes=[];
    private array $resolved=[];
    private array $resolving=[];
    private array $rows=[];
    private array $targets=[];
    private array $registryProofs=[];
    private function __construct(private readonly Connection $db,private readonly TextScrubber $scrubber,private readonly array $registryMap,private readonly array $hashes,private readonly \Closure $text,private readonly \Closure $map){foreach($hashes as $value)$this->targets[$value]=true;foreach($registryMap as $proofs)foreach($proofs as $proof)$this->targets[$proof['after_state_hash']]=true;}
    public static function run(Connection $db,TextScrubber $scrubber,array $registryMap,array $hashes,callable $text,callable $map,array $inputs=[]):void
    {
        $pdo=$db->pdo();if(!$pdo->inTransaction()||TriggerMetadata::any($pdo))throw new \LogicException('Settlement clone requires the payment clone transaction before trigger installation.');
        $hashes=ServiceLifecycleCloneScrubber::run($db,$scrubber,$hashes,$text,$map,$inputs)+$hashes;$hashes=FundingCloneScrubber::run($db,$scrubber,$registryMap,$hashes,$text,$map,$inputs)+$hashes;$owner=new self($db,$scrubber,$registryMap,$hashes,\Closure::fromCallable($text),\Closure::fromCallable($map));$owner->capture();foreach($inputs as $input)$owner->index($input);$owner->rewrite();SettlementCostCloneProof::verify($db,$inputs);$owner->verify();
    }
    public static function captureInputs(Connection $db):array
    {
        $pdo=$db->pdo();if($pdo->inTransaction()||TriggerMetadata::any($pdo))throw new \LogicException('Settlement clone capture must precede transformations and triggers.');
        $pdo->beginTransaction();try{
            $rows=$pdo->query('SELECT DISTINCT c.supplier_id,c.journal_entry_id FROM property_service_settlement_origins o JOIN unit_charge_lines l ON l.supplier_id=o.supplier_id AND l.id=o.charge_line_id JOIN unit_charges c ON c.supplier_id=l.supplier_id AND c.id=l.charge_id ORDER BY c.supplier_id,c.journal_entry_id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);$groups=[];$inputs=[];
            foreach($rows as $row)$groups[(int)$row['supplier_id']][]=(int)$row['journal_entry_id'];
            foreach($groups as $supplier=>$ids){(new \MyInvoice\Service\Property\Payments\PaymentLocks($db))->acquire($supplier);foreach((new \MyInvoice\Service\Property\Payments\PaymentJournalProof($db))->currentMany($supplier,$ids) as $ledger){$inputs[]=$ledger;}}
            $inputs=[...$inputs,...ServiceLifecycleCloneScrubber::captureInputs($db),...FundingCloneScrubber::captureInputs($db),...SettlementCostCloneProof::captureInputs($db)];$pdo->commit();return $inputs;
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    private function capture():void
    {
        foreach([...SettlementUseGuard::TABLES,...\MyInvoice\Repository\Property\Services\Settlement\SettlementDossierRepository::TABLES] as $table){
            $order=$table==='property_service_settlement_origins'?'supplier_id,document_id,ordinal':($table==='property_service_settlement_heads'?'supplier_id,document_id':'supplier_id,id');
            $this->rows[$table]=$this->db->pdo()->query('SELECT * FROM '.$table.' ORDER BY '.$order.' FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
            foreach($this->rows[$table] as $row)foreach($row as $key=>$value)if(str_ends_with($key,'_json')){
                $data=json_decode($value,true,flags:JSON_THROW_ON_ERROR);$hashKey=substr($key,0,-5).'_hash';if(isset($row[$hashKey])&&PropertyInput::hash($data)!==$row[$hashKey])throw new \LogicException('Settlement clone source hash mismatch.');$this->index($data);
            }
        }
        foreach($this->rows['property_service_settlement_requests'] as $row)if($row['reversal_of']===null){$preview=json_decode($row['preview_json'],true,flags:JSON_THROW_ON_ERROR);if(!isset($preview['origin_receipt'],$preview['party_receipt']))throw new \LogicException('Settlement clone requires its selected immutable hash inputs.');}
    }
    private function index(array $data):void
    {
        $this->nodes[PropertyInput::hash($data)]=$data;
        foreach(['current_hash','preview_hash'] as $key)if(isset($data[$key])&&is_string($data[$key])){$input=$data;unset($input[$key]);if(PropertyInput::hash($input)===$data[$key])$this->nodes[$data[$key]]=$input;}
        foreach($data as $value)if(is_array($value))$this->index($value);
    }
    private function hash(string $old):string
    {
        if(isset($this->hashes[$old]))return $this->hashes[$old];
        if(isset($this->resolved[$old]))return $this->resolved[$old];
        if(!isset($this->nodes[$old]))return $old;
        if(isset($this->resolving[$old]))throw new \LogicException('Settlement clone hash cycle.');$this->resolving[$old]=true;
        $new=PropertyInput::hash($this->transform($this->nodes[$old]));unset($this->resolving[$old]);return $this->resolved[$old]=$new;
    }
    private function transform(array $data):array
    {
        $data=($this->text)($this->registry($data));return $this->references($data);
    }
    private function references(array $data):array
    {
        foreach($data as $key=>&$value){if(is_array($value))$value=$this->references($value);elseif(is_string($value)&&preg_match('/^[a-f0-9]{64}$/D',$value)){if(str_ends_with((string)$key,'_hash')&&!isset($this->hashes[$value])&&!isset($this->nodes[$value])&&!isset($this->targets[$value]))throw new \LogicException('Settlement clone hash input missing: '.$key);$value=$this->hash($value);}}unset($value);return $data;
    }
    private function registry(array $data):array
    {
        if(isset($data['kind'],$data['supplier_id'],$data['revision']['state_hash'],$data['revision']['state'])){
            $revision=&$data['revision'];$proof=$this->registryMap[$data['supplier_id']][$revision['id']]??throw new \LogicException('Settlement clone registry proof missing.');
            if($proof['before_state_hash']!==$revision['state_hash']||PropertyInput::hash($revision['state'])!==$revision['state_hash'])throw new \LogicException('Settlement clone registry source mismatch.');
            $revision['state']=(new PropertyCloneScrubber($this->db->pdo(),$this->scrubber))->scrubSnapshotState($data['kind'],$revision['state']);if(PropertyInput::hash($revision['state'])!==$proof['after_state_hash'])throw new \LogicException('Settlement clone registry target mismatch.');$proofKey=$data['supplier_id'].':'.$revision['id'];if(!isset($this->registryProofs[$proofKey])){$q=$this->db->pdo()->prepare('SELECT state_hash FROM property_revisions WHERE supplier_id=? AND id=?');$q->execute([$data['supplier_id'],$revision['id']]);if($q->fetchColumn()!==$proof['after_state_hash'])throw new \LogicException('Settlement clone registry materialization mismatch.');$this->registryProofs[$proofKey]=true;}$revision['state_hash']=$proof['after_state_hash'];unset($revision);
        }
        foreach($data as &$value)if(is_array($value))$value=$this->registry($value);unset($value);return $data;
    }
    private function rewrite():void
    {
        foreach(['property_service_settlements','property_service_settlement_requests','property_service_settlement_applications','property_service_settlement_effects','property_service_settlement_dossier_events'] as $table)foreach($this->rows[$table] as $row){
            $changes=[];foreach($row as $key=>$value)if(str_ends_with($key,'_json')){$data=$this->transform(json_decode($value,true,flags:JSON_THROW_ON_ERROR));$changes[$key]=self::json($data);$hashKey=substr($key,0,-5).'_hash';if(isset($row[$hashKey])){$changes[$hashKey]=($this->map)($row[$hashKey],$data);$expected=$this->hash($row[$hashKey]);if($changes[$hashKey]!==$expected)throw new \LogicException('Settlement clone hash materialization mismatch.');}}
            if($table==='property_service_settlements'){$receipt=json_decode($changes['origin_receipt_json'],true,flags:JSON_THROW_ON_ERROR);$changes['request_hash']=($this->map)($row['request_hash'],$receipt['request']);}
            if($table==='property_service_settlement_applications')$changes['request_hash']=$this->hash($row['request_hash']);
            $this->db->pdo()->prepare('UPDATE '.$table.' SET '.implode(',',array_map(fn($key)=>$key.'=?',array_keys($changes))).' WHERE supplier_id=? AND id=?')->execute([...array_values($changes),$row['supplier_id'],$row['id']]);
        }
    }
    private function verify():void
    {
        $container=\MyInvoice\Bootstrap::buildContainer();$container->set(Connection::class,$this->db);$documents=$container->get(SettlementDocumentRepository::class);$history=new SettlementHistory($this->db);
        $bound=array_fill_keys(array_column($this->rows['property_service_settlement_applications'],'document_id'),true);$ledgers=[];
        foreach($this->rows['property_service_settlements'] as $row){$supplier=(int)$row['supplier_id'];(new \MyInvoice\Service\Property\Payments\PaymentLocks($this->db))->acquire($supplier);$doc=$documents->find($supplier,(int)$row['id'],true);if(!isset($bound[$row['id']]))continue;
            $q=$this->db->pdo()->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$supplier,$row['cost_line_id']]);$entry=$q->fetchColumn();if($entry===false)throw new \LogicException('Settlement clone cost source missing.');$key=$supplier.':'.$entry;$ledgers[$key]??=(new \MyInvoice\Service\Property\Payments\PaymentJournalProof($this->db))->current($supplier,(int)$entry);$lines=array_column($ledgers[$key]['lines'],null,'id');$line=$lines[$row['cost_line_id']]??null;if($line===null||PropertyInput::hash($line)!==$doc['document']['cost']['source_hash'])throw new \LogicException('Settlement clone cost source mismatch.');
            foreach($doc['origin_receipt']['current']['evidence'] as $evidence){if(isset($evidence['charge_id'])){$q=$this->db->pdo()->prepare('SELECT snapshot_hash FROM unit_charges WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$supplier,$evidence['charge_id']]);if($q->fetchColumn()!==$evidence['snapshot_hash'])throw new \LogicException('Settlement clone issued source mismatch.');}else foreach(['advance'=>'advance_item_id','service'=>'service_item_id'] as $kind=>$field){$q=$this->db->pdo()->prepare('SELECT snapshot_hash FROM property_opening_items WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$supplier,$evidence['state'][$field]]);if($q->fetchColumn()!==$evidence[$kind.'_hash'])throw new \LogicException('Settlement clone opening source mismatch.');}}
        }
        foreach($this->rows['property_service_settlement_applications'] as $row)$history->application((int)$row['supplier_id'],(int)$row['id'],true);
        $dossiers=$container->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDossierRepository::class);foreach($this->rows['property_service_settlements'] as $row)$dossiers->readLocked((int)$row['supplier_id'],(int)$row['id']);
        foreach(array_unique(array_column($this->rows['property_service_settlements'],'supplier_id')) as $supplier){$ids=(new \MyInvoice\Service\Property\Services\Settlement\SettlementDossierReferences($this->db))->allPartyIds((int)$supplier,true);if(count((new \MyInvoice\Repository\Property\PropertyReadRepository($this->db))->parties((int)$supplier,$ids,true))!==count($ids))throw new \LogicException('Settlement clone dossier party source missing.');}
    }
    private static function json(array $data):string{return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
}
