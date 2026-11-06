<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\ReliefCorrectionRepository;
use MyInvoice\Service\Property\PropertyInput;
final class PaymentCorrectionIntent
{
    private string $savepoint;
    private bool $claimed=false;
    private bool $prepared=false;
    private bool $completed=false;
    public readonly int $supplierId;
    public readonly int $intentId;
    public readonly int $chargeId;
    public readonly int $batchId;
    public readonly int $expectedVersion;
    public readonly string $snapshotHash;
    public readonly int $actor;
    private function __construct(private readonly Connection $db,private readonly ReliefCorrectionRepository $owner,private readonly array $context)
    {
        $this->supplierId=$context['supplier'];$this->intentId=$context['id'];$this->chargeId=$context['snapshot']['adjustment']['id'];
        $this->batchId=$context['snapshot']['adjustment']['batch_id'];$this->expectedVersion=$context['snapshot']['adjustment']['row_version'];
        $this->snapshotHash=$context['snapshot_hash'];$this->actor=$context['actor'];
        $this->savepoint='property_correction_'.bin2hex(random_bytes(10));$db->pdo()->exec('SAVEPOINT '.$this->savepoint);
    }
    public static function fromCurrentRequest(Connection $db,ReliefCorrectionRepository $owner,int $supplier,int $id):self
    {
        return new self($db,$owner,$owner->provePendingCorrection($db,$supplier,$id));
    }
    private function __clone() {}
    public function __serialize():array {throw new \LogicException('Correction intents cannot be serialized.');}
    public function __unserialize(array $data):void {throw new \LogicException('Correction intents cannot be restored.');}
    public function claim(Connection $db):void
    {
        if($this->claimed)throw new \LogicException('Correction intent has already been consumed.');
        $this->marker($db);$this->claimed=true;$this->current($db);
    }
    public function prepareWrite(Connection $db):void
    {
        if(!$this->claimed||$this->prepared||$this->completed)throw new \LogicException('Correction requires one claimed writer.');
        $this->current($db);$this->prepared=true;
        $this->savepoint='property_correction_write_'.bin2hex(random_bytes(10));$db->pdo()->exec('SAVEPOINT '.$this->savepoint);
    }
    public function complete(Connection $db):array
    {
        $this->connection($db);
        return $this->owner->completePendingCorrection($this);
    }
    public function completion(Connection $db):array
    {
        if(!$this->prepared||$this->completed)throw new \LogicException('Correction completion requires its pending writer.');
        $this->marker($db);$this->completed=true;return $this->context;
    }

    public function assertCharge(Connection $db,array $charge):void
    {
        if(!$this->prepared||$this->completed)throw new \LogicException('Correction posting requires its active writer.');
        $this->current($db);
        $expected=$this->context['snapshot']['adjustment'];
        if($charge['supplier_id']!==$this->supplierId||$charge['id']!==$this->chargeId||$charge['status']!=='draft'||$charge['row_version']!==$this->expectedVersion||$charge['snapshot_hash']!==$expected['snapshot_hash'])PaymentException::reject('correction_intent');
    }

    private function marker(Connection $db):void
    {
        $this->connection($db);
        try{$db->pdo()->exec('RELEASE SAVEPOINT '.$this->savepoint);}
        catch(\PDOException $e){if((int)($e->errorInfo[1]??0)===1305)throw new \LogicException('Correction belongs to a different or rolled back transaction.',0,$e);throw $e;}
    }
    private function connection(Connection $db):void
    {
        if($db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction())throw new \LogicException('Correction requires its current owner transaction.');
    }
    private function current(Connection $db):void
    {
        $this->connection($db);
        if(PropertyInput::hash($this->context)!==PropertyInput::hash($this->owner->provePendingCorrection($db,$this->supplierId,$this->intentId)))PaymentException::reject('correction_intent');
    }
}
