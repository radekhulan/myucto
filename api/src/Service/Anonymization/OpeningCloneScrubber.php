<?php
declare(strict_types=1);
namespace MyInvoice\Service\Anonymization;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Opening\OpeningReadProof;
use PDO;
final class OpeningCloneScrubber
{
    private array $verifiedRevisions=[];
    public function __construct(private readonly Connection $db,private readonly TextScrubber $scrubber){}
    public function run(array $registryMap,callable $text,callable $references,callable $map):void
    {
        $pdo=$this->db->pdo();if(!$pdo->inTransaction()||TriggerMetadata::any($pdo))throw new \LogicException('Opening clone requires the clone transaction before trigger installation.');
        $this->verifiedRevisions=[];
        $batches=$pdo->query('SELECT * FROM property_opening_batches ORDER BY supplier_id,id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
        $items=$pdo->query('SELECT * FROM property_opening_items ORDER BY supplier_id,id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
        foreach($items as $item)self::decode($item,'snapshot');
        foreach($batches as $row){$request=$text(self::decode($row,'request'));$snapshot=$references($this->transform(self::decode($row,'snapshot'),(int)$row['supplier_id'],$registryMap,$text));$snapshot['request']=$request;$this->update('property_opening_batches',$row,['request_json'=>self::json($request),'request_hash'=>$map($row['request_hash'],['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]),'snapshot_json'=>self::json($snapshot),'snapshot_hash'=>$map($row['snapshot_hash'],$snapshot)]);}
        foreach($items as $row){$snapshot=$this->transform(self::decode($row,'snapshot'),(int)$row['supplier_id'],$registryMap,$text);$this->update('property_opening_items',$row,['case_key'=>$snapshot['case_key'],'snapshot_json'=>self::json($snapshot),'snapshot_hash'=>$map($row['snapshot_hash'],$snapshot)]);}
        foreach($pdo->query('SELECT * FROM property_opening_components ORDER BY supplier_id,id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) as $row){$fact=$text(self::decode($row,'fact'));$this->update('property_opening_components',$row,['fact_json'=>self::json($fact),'fact_hash'=>$map($row['fact_hash'],$fact)]);}
        foreach($pdo->query('SELECT * FROM property_opening_parties ORDER BY supplier_id,item_id,group_key,client_id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) as $row){$fact=$text(json_decode($row['fact_json'],true,flags:JSON_THROW_ON_ERROR));$q=$pdo->prepare('UPDATE property_opening_parties SET fact_json=? WHERE supplier_id=? AND item_id=? AND group_key=? AND client_id=?');$q->execute([self::json($fact),$row['supplier_id'],$row['item_id'],$row['group_key'],$row['client_id']]);}
        foreach($pdo->query('SELECT * FROM property_opening_states ORDER BY supplier_id,item_id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) as $row){$value=$text($row);$q=$pdo->prepare('UPDATE property_opening_states SET purpose_ref=?,counterpart_ref=? WHERE supplier_id=? AND item_id=?');$q->execute([$value['purpose_ref'],$value['counterpart_ref'],$row['supplier_id'],$row['item_id']]);}
        $supplierItems=[];foreach($items as $item)$supplierItems[(int)$item['supplier_id']][]=(int)$item['id'];
        foreach($supplierItems as $supplier=>$ids)(new OpeningReadProof($this->db))->items($supplier,$ids,true);
    }
    private function transform(array $node,int $supplier,array $registryMap,callable $text):array
    {
        $node=$this->registry($node,$supplier,$registryMap);$node=$text($node);$this->verifyRegistry($node);return $node;
    }
    private function registry(array $node,int $supplier,array $registryMap):array
    {
        if(isset($node['kind'],$node['revision']['state_hash'],$node['revision']['state'])){
            $node['revision']=$this->revision($node['revision'],$node['kind'],$supplier,$registryMap,$node['id']??null);
            if(array_key_exists('history',$node)){
                if(!is_array($node['history'])||!array_is_list($node['history']))throw new \LogicException('Opening registry clone history malformed.');
                $seen=[];foreach($node['history'] as &$revision){if(!is_array($revision)||!isset($revision['id'])||isset($seen[$revision['id']]))throw new \LogicException('Opening registry clone history malformed.');$seen[$revision['id']]=true;$revision=$this->revision($revision,$node['kind'],$supplier,$registryMap,$node['id']??null);}unset($revision);
            }
        }
        foreach($node as &$value)if(is_array($value))$value=$this->registry($value,$supplier,$registryMap);unset($value);return $node;
    }
    private function revision(array $revision,string $kind,int $supplier,array $registryMap,?int $object):array
    {
        if(!isset($revision['id'],$revision['state_hash'],$revision['state'])||!is_array($revision['state'])||($object!==null&&isset($revision['object_id'])&&(int)$revision['object_id']!==$object)||(isset($revision['supplier_id'])&&(int)$revision['supplier_id']!==$supplier))throw new \LogicException('Opening registry clone source mismatch.');
        $proof=$registryMap[$supplier][$revision['id']]??throw new \LogicException('Opening registry clone proof missing.');
        if($revision['state_hash']!==$proof['before_state_hash']||PropertyInput::hash($revision['state'])!==$revision['state_hash'])throw new \LogicException('Opening registry clone source mismatch.');
        $revision['state']=(new PropertyCloneScrubber($this->db->pdo(),$this->scrubber))->scrubSnapshotState($kind,$revision['state']);$revision['state_hash']=$proof['after_state_hash'];
        if(PropertyInput::hash($revision['state'])!==$revision['state_hash'])throw new \LogicException('Opening registry clone target mismatch.');
        $key=$supplier.':'.$revision['id'];if(!isset($this->verifiedRevisions[$key])){$q=$this->db->pdo()->prepare('SELECT r.object_id,r.state_hash,o.kind FROM property_revisions r JOIN property_objects o ON o.supplier_id=r.supplier_id AND o.id=r.object_id WHERE r.supplier_id=? AND r.id=?');$q->execute([$supplier,$revision['id']]);$this->verifiedRevisions[$key]=$q->fetch(PDO::FETCH_ASSOC)?:throw new \LogicException('Opening registry clone proof missing.');}$native=$this->verifiedRevisions[$key];if($native['state_hash']!==$revision['state_hash']||$native['kind']!==$kind||($object!==null&&(int)$native['object_id']!==$object))throw new \LogicException('Opening registry clone target mismatch.');return $revision;
    }
    private function verifyRegistry(array $node):void
    {
        if(isset($node['state_hash'],$node['state'])&&PropertyInput::hash($node['state'])!==$node['state_hash'])throw new \LogicException('Opening registry clone target mismatch.');foreach($node as $value)if(is_array($value))$this->verifyRegistry($value);
    }
    private function update(string $table,array $row,array $changes):void{$this->db->pdo()->prepare('UPDATE '.$table.' SET '.implode(',',array_map(fn($key)=>$key.'=?',array_keys($changes))).' WHERE supplier_id=? AND id=?')->execute([...array_values($changes),$row['supplier_id'],$row['id']]);}
    private static function decode(array $row,string $key):array{$value=json_decode($row[$key.'_json'],true,flags:JSON_THROW_ON_ERROR);if($key!=='request'&&PropertyInput::hash($value)!==$row[$key.'_hash'])throw new \LogicException('Opening clone source hash mismatch.');return $value;}
    private static function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
}
