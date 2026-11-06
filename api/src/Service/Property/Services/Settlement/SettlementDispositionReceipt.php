<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Settlement;
use MyInvoice\Service\Property\PropertyInput;
final readonly class SettlementDispositionReceipt
{
    private function __construct(private array $facts){}
    public static function fromEvidence(SettlementDocument $document,array $current,array $request):self
    {
        return new self(SettlementPlanner::calculate($document,$current,$request));
    }
    public function snapshot():array{return $this->facts;}
    public function hash():string{return PropertyInput::hash($this->facts);}
    public function assertCurrent(SettlementDocument $document,array $current,array $request):void
    {
        if($this->facts['document_hash']!==$document->hash()||$this->facts['current_hash']!==PropertyInput::hash($current)||$this->facts['request_hash']!==PropertyInput::hash($request))SettlementEvidence::reject('stale_disposition');
        if($this->hash()!==PropertyInput::hash(SettlementPlanner::calculate($document,$current,$request)))SettlementEvidence::reject('stale_disposition');
    }
}
