<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges\Issuance;

use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;

final class IssuanceEventPolicy
{
    public static function normalize(array $input,array $charge): array
    {
        PropertyInput::fields($input,['kind','effective_at','reason','evidence_ref','facts','expected_event_id']);
        $kind=$input['kind']??null;
        if(!in_array($kind,['reminder','liability_assessment','allocation_regime','security_basis'],true)) PropertyInput::invalid('kind');
        $expected=$input['expected_event_id']??null;
        if(!is_int($expected)||$expected<0) PropertyInput::invalid('expected_event_id');
        $at=PropertyInstant::normalize($input['effective_at']??null);
        if($at<$charge['snapshot']['claim_origin_at']) PropertyInput::invalid('effective_at');
        $facts=$input['facts']??null; if(!is_array($facts)) PropertyInput::invalid('facts');
        $members=[];
        foreach($charge['snapshot']['liability_groups'] as $group) if($group['role']==='debtor') foreach($group['members'] as $id) $members[$id]=true;
        if($kind==='reminder') {
            PropertyInput::fields($facts,['recipient_id','represented_ids','authority_ref','delivered_at','delivery_ref']);
            $recipient=PropertyInput::id($facts['recipient_id']??null,'recipient_id');
            $ids=self::members($facts['represented_ids']??null,$members);
            $delivery=PropertyInstant::normalize($facts['delivered_at']??null);
            if($delivery>$at) PropertyInput::invalid('delivered_at');
            $facts=['recipient_id'=>$recipient,'represented_ids'=>$ids,
                'authority_ref'=>PropertyInput::text($facts['authority_ref']??'','authority_ref',2000,$ids===[$recipient]),
                'delivered_at'=>$delivery,'delivery_ref'=>PropertyInput::text($facts['delivery_ref']??null,'delivery_ref',2000)];
        } elseif($kind==='allocation_regime') {
            PropertyInput::fields($facts,['parties']); $out=[];
            foreach(PropertyInput::list($facts['parties']??null,'parties') as $party) {
                if(!is_array($party)) PropertyInput::invalid('parties');
                PropertyInput::fields($party,['client_id','regime','statute_as_of','legal_reason','evidence_ref']);
                $id=PropertyInput::id($party['client_id']??null,'client_id');
                if(!isset($members[$id])||isset($out[$id])||!in_array($party['regime']??null,['consumer','general','other_documented'],true)) PropertyInput::invalid('regime');
                $out[$id]=['client_id'=>$id,'regime'=>$party['regime'],'statute_as_of'=>IssuanceRequest::date($party['statute_as_of']??null),
                    'legal_reason'=>PropertyInput::text($party['legal_reason']??null,'legal_reason',2000),
                    'evidence_ref'=>PropertyInput::text($party['evidence_ref']??null,'evidence_ref',2000)];
            }
            if($out===[]) PropertyInput::invalid('parties'); ksort($out,SORT_NUMERIC); $facts=['parties'=>array_values($out)];
        } elseif($kind==='security_basis') {
            PropertyInput::fields($facts,['assessment_scale','rank','legal_reason']);
            if(!is_int($facts['rank']??null)||$facts['rank']<0||$facts['rank']>1000000) PropertyInput::invalid('rank');
            $facts=['assessment_scale'=>PropertyInput::text($facts['assessment_scale']??null,'assessment_scale',500),'rank'=>$facts['rank'],
                'legal_reason'=>PropertyInput::text($facts['legal_reason']??null,'legal_reason',2000)];
        } else {
            PropertyInput::fields($facts,['client_ids','legal_reason']);
            $facts=['client_ids'=>self::members($facts['client_ids']??null,$members),
                'legal_reason'=>PropertyInput::text($facts['legal_reason']??null,'legal_reason',2000)];
        }
        return ['kind'=>$kind,'effective_at'=>$at,'reason'=>PropertyInput::text($input['reason']??null,'reason',2000),
            'evidence_ref'=>PropertyInput::text($input['evidence_ref']??null,'evidence_ref',2000),'facts'=>$facts,'expected_event_id'=>$expected];
    }

    private static function members(mixed $raw,array $members): array
    {
        $ids=PropertyInput::list($raw,'client_ids'); $seen=[];
        foreach($ids as $id) { PropertyInput::id($id,'client_id'); if(!isset($members[$id])||isset($seen[$id])) PropertyInput::invalid('client_id'); $seen[$id]=true; }
        if($ids===[]) PropertyInput::invalid('client_ids'); sort($ids,SORT_NUMERIC); return $ids;
    }
}
