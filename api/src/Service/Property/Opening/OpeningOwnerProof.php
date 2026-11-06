<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Opening;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Service\Property\PropertyInput;
final class OpeningOwnerProof
{
    private readonly string $savepoint;
    private readonly array $snapshot;
    private function __construct(private readonly Connection $db,private readonly OpeningRepository $owner,public readonly int $supplier,public readonly int $batchId)
    {
        $this->snapshot=$owner->pendingSnapshot($db,$supplier,$batchId);
        $this->savepoint='property_opening_'.bin2hex(random_bytes(10));$db->pdo()->exec('SAVEPOINT '.$this->savepoint);
    }
    public static function fromPending(Connection $db,OpeningRepository $owner,int $supplier,int $batchId):self{return new self($db,$owner,$supplier,$batchId);}
    private function __clone(){}
    public function __serialize():array{throw new \LogicException('Opening proofs cannot be serialized.');}
    public function __unserialize(array $data):void{throw new \LogicException('Opening proofs cannot be restored.');}
    public function journal(Connection $db,string $part):array
    {
        $snapshot=$this->current($db);if(!in_array($part,['journal','reversal'],true)||$snapshot[$part]===null)OpeningException::reject('intent_part');
        $journal=$snapshot[$part];$journal['header']+=['source_id'=>null,'posted_at'=>$snapshot['recorded_at'],'posted_by'=>$snapshot['actor'],'description'=>$snapshot['request']['reason']];return $journal;
    }
    public function indices(Connection $db,array $header,array $lines,string $part):array
    {
        $snapshot=$this->current($db);$journal=$this->journal($db,$part);
        foreach(['supplier_id','period_id','source_type','entry_date','document_date'] as $key)if(($header[$key]??null)!==($journal['header'][$key]??null))OpeningException::reject('intent_target');
        if(($header['source_id']??null)!==null||PropertyInput::hash($lines)!==PropertyInput::hash($journal['lines']))OpeningException::reject('intent_target');
        return $part==='reversal'?array_fill_keys(array_keys($lines),true):$snapshot['carried_indices'];
    }
    public function parts(Connection $db):array{$s=$this->current($db);return array_values(array_filter(['reversal','journal'],fn($part)=>$s[$part]!==null));}
    public function original(Connection $db):?array{return $this->current($db)['existing'];}
    private function current(Connection $db):array
    {
        if($db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction())throw new \LogicException('Opening proof requires its owner transaction.');
        try{$db->pdo()->exec('RELEASE SAVEPOINT '.$this->savepoint);}catch(\PDOException $e){if((int)($e->errorInfo[1]??0)===1305)throw new \LogicException('Opening proof belongs to a different transaction epoch.',0,$e);throw $e;}$db->pdo()->exec('SAVEPOINT '.$this->savepoint);
        $snapshot=$this->owner->pendingSnapshot($db,$this->supplier,$this->batchId);if(PropertyInput::hash($snapshot)!==PropertyInput::hash($this->snapshot))OpeningException::reject('intent_stale');return $snapshot;
    }
}
