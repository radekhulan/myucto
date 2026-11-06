<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Settlement;
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant};
final readonly class SettlementDocument
{
    private function __construct(private array $facts){}
    public static function fromEvidence(array $facts):self
    {
        $facts=SettlementEvidence::detached($facts);
        SettlementEvidence::fields($facts,['supplier_id','unit_id','service_id','segment_key','period_from','period_to','cutoff_at','known_at','recipient_ids','evidence','prescribed_cents','paid_cents','actual_cents','origins','cutoff_allocations','cost',...(array_key_exists('external_dossier',$facts)?['external_dossier']:[])]);
        foreach(['supplier_id','unit_id','service_id'] as $key)PropertyInput::id($facts[$key],$key);PropertyInput::text($facts['segment_key'],'segment_key');PropertyInput::text($facts['evidence'],'evidence',4000);
        foreach(['period_from','period_to'] as $key)SettlementEvidence::date($facts[$key]);if($facts['period_from']>$facts['period_to'])SettlementEvidence::reject('period');
        foreach(['cutoff_at','known_at'] as $key)$facts[$key]=PropertyInstant::normalize($facts[$key]);
        $facts['recipient_ids']=SettlementEvidence::ids($facts['recipient_ids']);$amounts=SettlementAmounts::calculate($facts['prescribed_cents'],$facts['paid_cents'],$facts['actual_cents']);
        $origins=[];$openingComponents=[];$total='0';foreach(PropertyInput::list($facts['origins'],'origins',10000) as $source){$key=SettlementEvidence::origin($source,$facts['supplier_id'],$facts['unit_id'],$facts['service_id']);if($source['source_period']['to']<$facts['period_from']||$source['source_period']['from']>$facts['period_to'])SettlementEvidence::reject('origin_period_disjoint');if(isset($origins[$key]))SettlementEvidence::reject('duplicate_origin');if($source['kind']==='opening_component'){$component=$source['opening_advance_component_id'];if(isset($openingComponents[$component]))SettlementEvidence::reject('shared_opening_advance_partition_required');$openingComponents[$component]=true;}$origins[$key]=$source;$total=bcadd($total,$source['prescribed_cents'],0);}
        if($total!==$facts['prescribed_cents'])SettlementEvidence::reject('origin_margin');ksort($origins,SORT_STRING);$facts['origins']=array_values($origins);
        $allocations=[];$paid='0';foreach($origins as $origin)$paid=bcadd($paid,$origin['carried_paid_cents'],0);$byOrigin=[];
        foreach(PropertyInput::list($facts['cutoff_allocations'],'cutoff_allocations',10000) as $allocation){
            SettlementEvidence::fields($allocation,['id','origin_key','source_hash','amount_cents']);PropertyInput::id($allocation['id'],'allocation_id');SettlementEvidence::hash($allocation['source_hash']);SettlementEvidence::cents($allocation['amount_cents']);
            if(isset($allocations[$allocation['id']])||!isset($origins[$allocation['origin_key']])||$allocation['amount_cents']==='0')SettlementEvidence::reject('cutoff_allocation');
            $allocations[$allocation['id']]=$allocation;$paid=bcadd($paid,$allocation['amount_cents'],0);$key=$allocation['origin_key'];$byOrigin[$key]=bcadd($byOrigin[$key]??'0',$allocation['amount_cents'],0);
            if(bccomp($byOrigin[$key],$origins[$key]['receivable_cents'],0)>0)SettlementEvidence::reject('cutoff_capacity');
        }
        if($paid!==$facts['paid_cents'])SettlementEvidence::reject('cutoff_margin');ksort($allocations,SORT_NUMERIC);$facts['cutoff_allocations']=array_values($allocations);
        $cost=SettlementEvidence::cost($facts['cost']);
        if($cost['amount_cents']!==$facts['actual_cents'])SettlementEvidence::reject('cost_margin');
        if(isset($cost['sources']))foreach($cost['sources'] as $part){$provenance=$part['provenance'];$scope=$provenance['kind']==='lifecycle'?($provenance['source_input']['case']??null):($provenance['source_input']['document']['document']??null);if(!is_array($scope))SettlementEvidence::reject('composite_cost_provenance');foreach(['supplier_id','unit_id','service_id','period_from','period_to'] as $key)if(($scope[$key]??null)!==$facts[$key])SettlementEvidence::reject('composite_cost_scope');}
        if(array_key_exists('external_dossier',$facts)){if(!is_array($facts['external_dossier']))PropertyInput::invalid('external_dossier');$facts['external_dossier']=SettlementDossier::normalize($facts['external_dossier'],$facts['period_from'],$facts['period_to']);if(SettlementDossier::recipientIds($facts['external_dossier'])!==$facts['recipient_ids'])SettlementEvidence::reject('dossier_recipient_scope');}
        return new self($facts+['amounts'=>$amounts]);
    }
    public function snapshot():array{return $this->facts;}
    public function hash():string{return PropertyInput::hash($this->facts);}
}
