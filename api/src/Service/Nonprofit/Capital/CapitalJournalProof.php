<?php
declare(strict_types=1);
namespace MyInvoice\Service\Nonprofit\Capital;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionSavepointToken;
final class CapitalJournalProof
{
    private readonly TransactionSavepointToken $epoch;
    private bool $used=false;
    private function __construct(private readonly Connection $db,private readonly CapitalService $owner,private readonly int $supplier,private readonly int $command,private readonly array $snapshot)
    {
        $marker='nonprofit_capital_'.bin2hex(random_bytes(10));$db->pdo()->exec('SAVEPOINT '.$marker);$this->epoch=TransactionSavepointToken::capture($db->pdo(),$marker);
    }
    public static function fromPending(Connection $db,CapitalService $owner,int $supplier,int $command):self
    {
        return new self($db,$owner,$supplier,$command,$owner->pending($db,$supplier,$command));
    }
    private function current(Connection $db):array
    {
        if($this->used||$db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction()||!$this->epoch->current($db->pdo()))throw new \LogicException('Capital proof requires its live owner epoch.');
        $pending=$this->owner->pending($db,$this->supplier,$this->command);if(CapitalPolicy::hash($pending)!==CapitalPolicy::hash($this->snapshot))CapitalPolicy::changed();return $pending;
    }
    public function journal(Connection $db):array{return $this->current($db)['journal'];}
    public function indices(Connection $db,array $header,array $lines):array
    {
        $pending=$this->current($db);if(CapitalPolicy::hash($header)!==CapitalPolicy::hash($pending['journal']['header'])||CapitalPolicy::hash($lines)!==CapitalPolicy::hash($pending['journal']['lines']))CapitalPolicy::changed();
        return $pending['journal']['source_entry_id']===null?[]:array_fill_keys(array_keys($lines),true);
    }
    public function complete(Connection $db,int $entry):void
    {
        if($this->used||$db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction()||!$this->epoch->current($db->pdo()))throw new \LogicException('Capital completion requires its live owner epoch.');
        $this->owner->complete($db,$this->supplier,$this->command,$entry);$this->used=true;
    }
    private function __clone(){}
    public function __serialize():array{throw new \LogicException('Capital proofs cannot be serialized.');}
    public function __unserialize(array $data):void{throw new \LogicException('Capital proofs cannot be restored.');}
}
