<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Funds;
use MyInvoice\Infrastructure\Database\{Connection,TransactionSavepointToken};
use MyInvoice\Repository\Property\Funds\FundingRepository;
use MyInvoice\Service\Property\PropertyInput;
final class FundingProof
{
    private readonly TransactionSavepointToken $epoch;
    private bool $used=false;
    private function __construct(private readonly Connection $db,private readonly FundingRepository $owner,private readonly int $supplier,private readonly int $operation,private readonly array $snapshot)
    {$marker='funding_'.bin2hex(random_bytes(10));$db->pdo()->exec('SAVEPOINT '.$marker);$this->epoch=TransactionSavepointToken::capture($db->pdo(),$marker);}
    public static function fromPending(Connection $db,FundingRepository $owner,int $supplier,int $operation):self {return new self($db,$owner,$supplier,$operation,$owner->pending($db,$supplier,$operation));}
    private function __clone(){}
    public function __serialize():array {throw new \LogicException('Funding proofs cannot be serialized.');}
    public function __unserialize(array $data):void {throw new \LogicException('Funding proofs cannot be restored.');}
    private function current(Connection $db):array
    {if($this->used||$db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction()||!$this->epoch->current($db->pdo()))throw new \LogicException('Funding proof requires its live owner epoch.');$current=$this->owner->pending($db,$this->supplier,$this->operation);if(PropertyInput::hash($current)!==PropertyInput::hash($this->snapshot))FundingException::reject('source_changed');return $current;}
    public function journal(Connection $db):array {return $this->current($db)['journal'];}
    public function indices(Connection $db,array $header,array $lines):array
    {$p=$this->current($db);if(PropertyInput::hash($header)!==PropertyInput::hash($p['journal']['header'])||PropertyInput::hash($lines)!==PropertyInput::hash($p['journal']['lines']))FundingException::reject('target_changed');return array_fill_keys(array_keys($lines),true);}
    public function complete(Connection $db,int $entry):void {if($this->used||$db->pdo()!==$this->db->pdo()||!$this->epoch->current($db->pdo()))throw new \LogicException('Funding completion epoch expired.');$this->owner->complete($db,$this->supplier,$this->operation,$entry);$this->used=true;}
}
