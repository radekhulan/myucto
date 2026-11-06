<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Settlement;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Services\Settlement\{SettlementDocumentRepository,ServiceLifecycleRepository};
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Service\Accounting\PostingService;
final class SettlementCostProof
{
    public function __construct(private readonly Connection $db){}
    /** Složený náklad z aktuálních čtení; v jedné transakci se skládá znovu jen po zápisu ({@see \MyInvoice\Infrastructure\Database\CurrentReadMemo}). */
    public function current(int $supplier,int $unit,int $service,string $date,array $period,array $selectors,SettlementOriginAdapter $adapter,bool $opening=false):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Composite service cost requires the owner transaction.');
        return \MyInvoice\Infrastructure\Database\CurrentReadMemo::remember($this->db,'composite-cost|'.md5(serialize([$supplier,$unit,$service,$date,$period,$selectors,$opening])),fn():array=>$this->compose($supplier,$unit,$service,$date,$period,$selectors,$adapter,$opening));
    }
    private function compose(int $supplier,int $unit,int $service,string $date,array $period,array $selectors,SettlementOriginAdapter $adapter,bool $opening):array
    {
        $selectors=PropertyInput::list($selectors,'cost_sources',100);if(count($selectors)<2)SettlementEvidence::reject('composite_cost_sources');$parts=[];$inputs=[];$known=0;$final=0;$case=null;$knownPeriod=null;$documents=new SettlementDocumentRepository($this->db,$adapter);
        foreach($selectors as $selector){if(!is_array($selector))PropertyInput::invalid('cost_sources');SettlementEvidence::fields($selector,['kind','id']);$id=PropertyInput::id($selector['id'],'cost_source_id');
            if($selector['kind']==='document'){$stored=$documents->find($supplier,$id,true);$facts=$stored['document'];if(isset($facts['cost']['sources'])||$facts['unit_id']!==$unit||$facts['service_id']!==$service||($opening?($facts['period_to']<$period['from']||$facts['period_from']>$period['to']):($facts['period_from']!==$period['from']||$facts['period_to']!==$period['to'])))SettlementEvidence::reject('composite_cost_scope');if((new SettlementHistory($this->db))->hasActiveApplicationForDocument($supplier,$id))SettlementEvidence::reject('cost_already_disposed');$nextPeriod=['from'=>$facts['period_from'],'to'=>$facts['period_to']];if($knownPeriod!==null&&$knownPeriod!==$nextPeriod)SettlementEvidence::reject('composite_cost_scope');$knownPeriod=$nextPeriod;$source=$stored;$cost=$facts['cost'];$known++;}
            elseif($selector['kind']==='lifecycle'){$source=(new ServiceLifecycleRepository($this->db))->current($supplier,$id);$case=$source['case'];if($case['policy']!=='service_balance_sheet')SettlementEvidence::reject('composite_cost_policy');if($case['unit_id']!==$unit||$case['service_id']!==$service||($opening?($case['period_to']<$period['from']||$case['period_from']>$period['to']):($case['period_from']!==$period['from']||$case['period_to']!==$period['to']))||$source['final']===null||$source['final']['accounting_date']>$date)SettlementEvidence::reject('composite_cost_scope');$cost=$adapter->nativeCostSource($supplier,$unit,$service,$source['final']['cost_line_id'],$date);if($cost['amount_cents']!==$source['final']['amount_cents'])SettlementEvidence::reject('composite_cost_changed');$final++;}
            else SettlementEvidence::reject('composite_cost_kind');
            $native=$adapter->nativeCostSource($supplier,$unit,$service,$cost['source_id'],$date);$input=$native['hash_input'];unset($native['hash_input']);if(isset($cost['hash_input']))unset($cost['hash_input']);if(PropertyInput::hash($native)!==PropertyInput::hash($cost))SettlementEvidence::reject('composite_cost_changed');if(isset($parts[$native['source_id']]))SettlementEvidence::reject('composite_cost_duplicate');if($selector['kind']==='document'){$query=$this->db->pdo()->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=? FOR UPDATE');$query->execute([$supplier,$native['source_id']]);$ledger=(new PaymentJournalProof($this->db))->current($supplier,(int)$query->fetchColumn());PostingService::assertBalanced($ledger['lines']);foreach($ledger['lines'] as $nativeLine)if(preg_match('/^[56]/D',$nativeLine['account_code']))SettlementEvidence::reject('composite_cost_policy');$source=['document'=>$source,'ledger'=>$ledger];}$parts[$native['source_id']]=$native+['provenance'=>['kind'=>$selector['kind'],'id'=>$id,'source_hash'=>PropertyInput::hash($source),'source_input'=>$source]];$inputs[]=$input;
        }
        if($known<1||$final!==1||$case===null)SettlementEvidence::reject('composite_cost_sources');if($knownPeriod!==['from'=>$case['period_from'],'to'=>$case['period_to']])SettlementEvidence::reject('composite_cost_scope');ksort($parts,SORT_NUMERIC);$parts=array_values($parts);$sum='0';$primary=$parts[0];foreach($parts as $part){$sum=bcadd($sum,$part['amount_cents'],0);}
        $cost=array_intersect_key($primary,array_flip(['source_id','source_hash','line']))+['amount_cents'=>$sum,'sources'=>$parts];SettlementEvidence::cost($cost);return ['cost'=>$cost,'hash_inputs'=>$inputs];
    }
    public function assertRecorded(int $supplier,int $unit,int $service,string $date,array $period,array $cost,SettlementOriginAdapter $adapter):void
    {
        if(!isset($cost['sources']))return;$selectors=array_map(fn($part)=>['kind'=>$part['provenance']['kind'],'id'=>$part['provenance']['id']],$cost['sources']);$current=$this->current($supplier,$unit,$service,$date,$period,$selectors,$adapter);if(PropertyInput::hash($current['cost'])!==PropertyInput::hash($cost))SettlementEvidence::reject('composite_cost_changed');
    }
}
