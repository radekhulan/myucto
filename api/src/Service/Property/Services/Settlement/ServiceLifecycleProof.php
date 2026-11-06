<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Settlement;
use MyInvoice\Infrastructure\Database\{Connection,TransactionSavepointToken};
use MyInvoice\Repository\Property\Services\Settlement\ServiceLifecycleRepository;
use MyInvoice\Service\Property\PropertyInput;
final class ServiceLifecycleProof
{
 private readonly TransactionSavepointToken $epoch;
 private bool $used=false;
 private function __construct(private readonly Connection $db,private readonly ServiceLifecycleRepository $owner,private readonly int $supplier,private readonly int $event,private readonly array $snapshot)
 {$marker='service_lifecycle_'.bin2hex(random_bytes(10));$db->pdo()->exec('SAVEPOINT '.$marker);$this->epoch=TransactionSavepointToken::capture($db->pdo(),$marker);}
 public static function fromPending(Connection $db,ServiceLifecycleRepository $owner,int $supplier,int $event):self {return new self($db,$owner,$supplier,$event,$owner->pending($db,$supplier,$event));}
 private function __clone(){}
 public function __serialize():array {throw new \LogicException('Lifecycle proofs cannot be serialized.');}
 public function __unserialize(array $data):void {throw new \LogicException('Lifecycle proofs cannot be restored.');}
 private function current(Connection $db):array
 {if($this->used||$db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction()||!$this->epoch->current($db->pdo()))throw new \LogicException('Lifecycle proof requires its original live epoch.');$current=$this->owner->pending($db,$this->supplier,$this->event);if(PropertyInput::hash($current)!==PropertyInput::hash($this->snapshot))SettlementEvidence::reject('lifecycle_source_changed');return $current;}
 public function journal(Connection $db):array {return $this->current($db)['journal'];}
 public function indices(Connection $db,array $header,array $lines):array
 {$p=$this->current($db);if(PropertyInput::hash($header)!==PropertyInput::hash($p['journal']['header'])||PropertyInput::hash($lines)!==PropertyInput::hash($p['journal']['lines']))SettlementEvidence::reject('lifecycle_target_changed');return array_fill_keys(array_keys($lines),true);}
 public function complete(Connection $db,int $entry):void {if($this->used||$db->pdo()!==$this->db->pdo()||!$this->epoch->current($db->pdo()))throw new \LogicException('Lifecycle completion epoch expired.');$this->owner->complete($db,$this->supplier,$this->event,$entry);$this->used=true;}
}
