<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Settlement;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository;
use MyInvoice\Service\Property\PropertyInput;
final class SettlementReversalIntent
{
    private \MyInvoice\Infrastructure\Database\TransactionSavepointToken $intentEpoch;
    private ?\MyInvoice\Infrastructure\Database\TransactionSavepointToken $journalEpoch=null;
    private bool $claimed=false;
    private bool $journalled=false;
    private bool $completed=false;
    private function __construct(private readonly Connection $db,private readonly SettlementApplicationRepository $owner,private readonly int $supplier,private readonly int $request,private readonly string $hash)
    {$marker='s8_intent_'.bin2hex(random_bytes(10));$db->pdo()->exec('SAVEPOINT '.$marker);$this->intentEpoch=\MyInvoice\Infrastructure\Database\TransactionSavepointToken::capture($db->pdo(),$marker);}
    public static function fromPending(Connection $db,SettlementApplicationRepository $owner,int $supplier,int $request):self
    {return new self($db,$owner,$supplier,$request,PropertyInput::hash($owner->pending($db,$supplier,$request)));}
    private function __clone(){}
    public function __serialize():array{throw new \LogicException('Settlement intents cannot be serialized.');}
    public function __unserialize(array $data):void{throw new \LogicException('Settlement intents cannot be restored.');}
    public function claim(Connection $db):void
    {if($this->claimed)throw new \LogicException('Intent already consumed.');$this->current($db);$this->claimed=true;}
    public function journal(Connection $db):array
    {
        if(!$this->claimed||$this->journalled||$this->completed)throw new \LogicException('Intent is not ready.');
        $proof=$this->current($db);$marker='s8_journal_'.bin2hex(random_bytes(10));$db->pdo()->exec('SAVEPOINT '.$marker);$this->journalEpoch=\MyInvoice\Infrastructure\Database\TransactionSavepointToken::capture($db->pdo(),$marker);$this->journalled=true;return $proof['journal'];
    }
    public function complete(Connection $db,int $entry):void
    {
        if(!$this->journalled||$this->completed)throw new \LogicException('Intent completion requires its journal.');
        $this->epoch($db);$this->completed=true;$this->owner->complete($db,$this->supplier,$this->request,$entry);
    }
    private function current(Connection $db):array
    {
        $this->epoch($db);
        $proof=$this->owner->pending($db,$this->supplier,$this->request);if(!hash_equals($this->hash,PropertyInput::hash($proof)))SettlementEvidence::reject('intent_stale');return $proof;
    }
    private function epoch(Connection $db):void
    {
        if($db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction())throw new \LogicException('Intent requires the same live owner transaction.');
        if(!$this->intentEpoch->current($db->pdo())||($this->journalEpoch!==null&&!$this->journalEpoch->current($db->pdo())))throw new \LogicException('Intent transaction epoch expired.');
    }
}
