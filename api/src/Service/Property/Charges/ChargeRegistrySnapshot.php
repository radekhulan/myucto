<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\PropertyTimeline;

final class ChargeRegistrySnapshot
{
    public function __construct(private readonly Connection $db) {}
    public function capture(int $supplier,int $houseId,string $at,bool $current=false): array
    {
        $at=PropertyInstant::normalize($at);
        $reader=new PropertyReadRepository($this->db);
        $objects=$reader->objects($supplier,null,$current);
        $histories=$reader->histories($supplier,array_column($objects,'id'),$current);
        $house=null; $units=[]; $services=[];
        foreach($objects as $object) {
            $snap=PropertyTimeline::at($histories[$object['id']]??[],$at);
            $revision=$snap['revision'];
            if(!($revision['state']['active']??false)) continue;
            $item=['id'=>$object['id'],'code'=>$object['code'],'dimension_value_id'=>$object['dimension_value_id'],
                'revision_id'=>$revision['id'],'revision'=>$revision['revision'],'state_hash'=>$revision['state_hash'],
                'effective_from'=>$revision['effective_from'],'effective_to'=>$revision['effective_to'],'pending_revision_ids'=>$snap['pending_revision_ids']];
            if($object['kind']==='house'&&$object['id']===$houseId) $house=$item+['name'=>$revision['state']['name'],'share_scope'=>$revision['state']['share_scope'],'registry_complete'=>$revision['state']['registry_complete']];
            if($object['kind']==='unit'&&$object['parent_id']===$houseId) $units[]=$item+['unit_no'=>$revision['state']['unit_no'],'attributes'=>$revision['state']['attributes'],'services'=>$revision['state']['services']];
            if($object['kind']==='service') $services[]=$item+['name'=>$revision['state']['name']];
        }
        if($house===null) throw new ChargeException('property_charge_house_unavailable','Dům není v této firmě k okamžiku výpočtu aktivní.',409);
        $profile=(new PropertyProfileRepository($this->db))->at($supplier,$at,$current);
        if(!($profile['enabled']??false)) throw new ChargeException('property_charge_profile_required','Nová verze vyžaduje aktivní potvrzený profil SVJ.',409);
        return ['as_of'=>$at,'house'=>$house,'profile_revision_id'=>$profile['id'],'units'=>$units,'services'=>$services];
    }
}
