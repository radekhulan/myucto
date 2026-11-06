<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\ChargeReadRepository;
use MyInvoice\Service\Property\PropertyInput;

final class ChargePreviewService
{
    public function __construct(private readonly Connection $db) {}
    public function preview(int $supplier,array $input,bool $current=false): array
    {
        $request=ChargeRequest::version($input);
        $registry=(new ChargeRegistrySnapshot($this->db))->capture($supplier,$request['house_id'],$request['basis_at'],$current);
        $repo=new ChargeReadRepository($this->db);
        $components=$repo->components($supplier,$current);
        $services=array_column($registry['services'],null,'id');
        $selected=array_column($request['rates'],null,'component_id');
        $components=array_values(array_filter($components,static fn($c)=>isset($selected[$c['id']])));
        foreach($components as $component) if($component['effective_at']>$request['basis_at']) throw new ChargeException('property_charge_component_unavailable','Složka ještě není k okamžiku výpočtu účinná.',409);
        foreach($components as $component) if($component['service_id']!==null&&!isset($services[$component['service_id']])) throw new ChargeException('property_charge_service_unavailable','Služba složky není k okamžiku výpočtu dostupná.',409);
        $versions=$repo->versions($supplier,$request['house_id'],$current);
        $revision=$versions[0]['revision_no']??0;
        if($revision!==$request['expected_revision']) throw new ChargeException('property_charge_revision_conflict','Verze se mezitím změnila. Načtěte aktuální stav.',409);
        $before=$repo->at($supplier,$request['house_id'],$request['basis_at'],current:$current);
        $calculation=ChargePlan::calculate($request,$registry,$components);
        $sameBasis=null; $comparisonError=null;
        if($before!==null) {
            try { $sameBasis=ChargePlan::calculate($before['request'],$registry,array_column($before['preview']['calculation']['rates'],'component')); }
            catch(ChargeException $e) { $comparisonError=['code'=>$e->errorCode,'details'=>$e->details]; }
        }
        $overlaps=[];
        foreach($versions as $version) {
            $from=max($request['effective_from'],$version['effective_from']);
            $ends=array_values(array_filter([$request['effective_to'],$version['effective_to']],static fn($date)=>$date!==null));
            $to=$ends===[]?null:min($ends);
            if($to===null||$from<$to) $overlaps[]=['version_id'=>$version['id'],'revision'=>$version['revision_no'],'decision_ref'=>$version['request']['decision_ref'],'from'=>$from,'to'=>$to];
        }
        $preview=['request'=>$request,'legal_ruleset'=>['id'=>'cz_svj_s2_2020_07_01_v1','statutory_rules_from'=>'2020-07-01','historical_basis_requires_explicit_title'=>true],'registry'=>$registry,'calculation'=>$calculation,'previous_version_id'=>$before['id']??null,
            'previous_preview_hash'=>$before['preview_hash']??null,'previous_units'=>array_map(static fn($unit)=>['id'=>$unit['id'],'unit_no'=>$unit['unit_no']],$before['preview']['registry']['units']??[]),'previous_frozen_differences'=>ChargePlan::differences($before['preview']['calculation']??[],$calculation),
            'overlapping_versions'=>$overlaps,'same_basis_differences'=>$sameBasis===null?null:ChargePlan::differences($sameBasis,$calculation),'same_basis_unavailable'=>$comparisonError];
        return ['preview'=>$preview,'preview_hash'=>PropertyInput::hash($preview)];
    }
}
