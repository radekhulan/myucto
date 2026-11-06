<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Settlement;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
final class SettlementHistoricalCarrierProof
{
    private readonly string $marker;
    private function __construct(private readonly Connection $db,private readonly SettlementApplicationRepository $owner,private readonly int $supplier,private readonly int $document,private readonly array $request,private readonly int $expected,private readonly ?int $pending,private readonly array $snapshot)
    {$this->marker='settlement_carrier_'.bin2hex(random_bytes(10));$db->pdo()->exec('SAVEPOINT '.$this->marker);}
    public static function fromCurrent(Connection $db,SettlementApplicationRepository $owner,int $supplier,int $document,array $request,int $expected):self
    {return new self($db,$owner,$supplier,$document,$request,$expected,null,$owner->currentCarrierSnapshot($db,$supplier,$document,$request,$expected));}
    public static function fromPending(Connection $db,SettlementApplicationRepository $owner,int $supplier,int $pending):self
    {return new self($db,$owner,$supplier,0,[],0,$pending,$owner->currentCarrierSnapshot($db,$supplier,0,[],0,$pending));}
    private function __clone(){}
    public function __serialize():array{throw new \LogicException('Settlement carriers cannot be serialized.');}
    public function __unserialize(array $data):void{throw new \LogicException('Settlement carriers cannot be restored.');}
    public function journal(Connection $db):array{return $this->current($db)['journal'];}
    public function indices(Connection $db,array $header,array $lines):array
    {
        $snapshot=$this->current($db);$journal=$snapshot['journal'];
        if(PropertyInput::hash($header)!==PropertyInput::hash($journal['header'])||PropertyInput::hash($lines)!==PropertyInput::hash($journal['lines']))SettlementEvidence::reject('carrier_target_mismatch');
        $document=$snapshot['preview']['plan']['document']??null;if($document===null)SettlementEvidence::reject('carrier_source_missing');$tuples=[];foreach(SettlementEvidence::costSources($document['cost']) as $part)$tuples[PropertyInput::hash(self::tuple($part['line']))]=true;
        foreach($document['origins'] as $origin)foreach(['advance_line','receivable_line'] as $key)if($origin[$key]!==null)$tuples[PropertyInput::hash(self::tuple($origin[$key]))]=true;
        $indices=[];foreach($lines as $index=>$line){if(!isset($tuples[PropertyInput::hash(self::tuple($line))]))SettlementEvidence::reject('carrier_source_tuple');$indices[$index]=true;}return $indices;
    }
    private function current(Connection $db):array
    {
        if($db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction())throw new \LogicException('Settlement carrier requires the same live owner transaction.');
        try{$db->pdo()->exec('RELEASE SAVEPOINT '.$this->marker);}catch(\PDOException $e){if((int)($e->errorInfo[1]??0)===1305)throw new \LogicException('Settlement carrier transaction epoch expired.',0,$e);throw $e;}$db->pdo()->exec('SAVEPOINT '.$this->marker);
        $current=$this->owner->currentCarrierSnapshot($db,$this->supplier,$this->document,$this->request,$this->expected,$this->pending);if(PropertyInput::hash($current)!==PropertyInput::hash($this->snapshot))SettlementEvidence::reject('carrier_source_changed');return $current;
    }
    private static function tuple(array $line):array
    {
        $tuple=PaymentJournalProof::canonicalLines([$line+['side'=>'debit','amount'=>'0']])[0];unset($tuple['account_code'],$tuple['side'],$tuple['amount']);return $tuple;
    }
}
