<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\CreditSetoffRepository;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Payments\PaymentException;

final class PaymentSetoffIntent
{
    private string $savepoint;
    private bool $claimed=false;
    private bool $journalled=false;
    private bool $completed=false;
    private function __construct(private readonly Connection $db,private readonly CreditSetoffRepository $owner,
        private readonly int $supplier,private readonly int $operation,private readonly string $snapshotHash)
    {
        $this->savepoint='property_setoff_'.bin2hex(random_bytes(10));
        $db->pdo()->exec('SAVEPOINT '.$this->savepoint);
    }
    public static function fromPending(Connection $db,CreditSetoffRepository $owner,int $supplier,int $id):self
    {
        $snapshot=$owner->currentCarrierSnapshot($db,$supplier,[],$id);
        return new self($db,$owner,$supplier,$id,PropertyInput::hash($snapshot));
    }
    private function __clone() {}
    public function __serialize():array {throw new \LogicException('Setoff intents cannot be serialized.');}
    public function __unserialize(array $data):void {throw new \LogicException('Setoff intents cannot be restored.');}
    public function claim(Connection $db):void
    {
        if($this->claimed)throw new \LogicException('Setoff intent has already been consumed.');
        $this->marker($db);$this->current($db);$this->claimed=true;
    }
    public function journal(Connection $db):array
    {
        if(!$this->claimed||$this->journalled||$this->completed)throw new \LogicException('Setoff journal requires one current claimed intent.');
        $this->current($db);
        $this->savepoint='property_setoff_journal_'.bin2hex(random_bytes(10));$db->pdo()->exec('SAVEPOINT '.$this->savepoint);
        $journal=$this->owner->provePendingSetoff($db,$this->supplier,$this->operation);$this->journalled=true;return $journal;
    }
    public function complete(Connection $db,int $entry):void
    {
        if(!$this->journalled||$this->completed)throw new \LogicException('Setoff completion requires its pending journal.');
        $this->marker($db);$this->current($db);$this->completed=true;
        $this->owner->completePendingSetoff($db,$this->supplier,$this->operation,$entry);
    }
    private function marker(Connection $db):void
    {
        if($db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction())throw new \LogicException('Setoff requires its current owner transaction.');
        try{$db->pdo()->exec('RELEASE SAVEPOINT '.$this->savepoint);}catch(\PDOException $e){if((int)($e->errorInfo[1]??0)===1305)throw new \LogicException('Setoff belongs to a different or rolled back transaction.',0,$e);throw $e;}
    }
    private function current(Connection $db):void
    {
        if($db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction())throw new \LogicException('Setoff requires its current owner transaction.');
        if(!hash_equals($this->snapshotHash,PropertyInput::hash($this->owner->currentCarrierSnapshot($db,$this->supplier,[],$this->operation))))PaymentException::reject('setoff_intent');
    }
}
