<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Receivables;
use MyInvoice\Service\Property\PropertyInstant;
final class PropertySaldoSummary
{
    public static function fromCard(array $card,array $accessoryAllocations=[]):array
    {
        return PropertyInstant::withNormalizationContext(fn()=>self::fromPreparedCard($card,$accessoryAllocations));
    }
    private static function fromPreparedCard(array $card,array $accessoryAllocations):array
    {
        $accessory=[];$uncertain=[];$liabilityReview=0;$aging=['not_due'=>'0','1_30'=>'0','31_90'=>'0','91_plus'=>'0'];
        $day=(new \DateTimeImmutable($card['as_of']))->setTimezone(new \DateTimeZone('Europe/Prague'))->setTime(0,0);
        foreach($card['claims'] as $claim){
            if(!$claim['liability']['complete'])$liabilityReview++;
            $due=new \DateTimeImmutable($claim['due_date'],new \DateTimeZone('Europe/Prague'));$age=(int)$due->diff($day)->format('%r%a');$bucket=$age<=0?'not_due':($age<=30?'1_30':($age<=90?'31_90':'91_plus'));
            if($claim['amount_cents']===null)$aging[$bucket]=null;elseif($aging[$bucket]!==null)$aging[$bucket]=bcadd($aging[$bucket],$claim['amount_cents'],0);
            foreach($claim['events'] as $event){
                if(ReceivableTimeline::inclusion($event,$card['as_of'],$card['known_at'])===null)$uncertain[$event['id']]=true;
                if(str_starts_with($event['id'],'accessory:')||(preg_match('/^(?:allocation:|correction:[0-9]+:relief:)([0-9]+)/D',$event['id'],$m)&&isset($accessoryAllocations[(int)$m[1]])))$accessory[]=$event;
            }
        }
        foreach($card['credits'] as $credit)foreach($credit['uncertain_event_ids'] as $id)$uncertain[$id]=true;
        $accessoryTotal=ReceivableTimeline::sum($accessory,$card['as_of'],$card['known_at'])['gross_receivable_cents'];
        $complete=$card['coverage']['financial_complete'];
        if(!$complete){$accessoryTotal=null;foreach($aging as &$value)$value=null;unset($value);}
        return ['unit_id'=>$card['scope']['unit_id'],'totals'=>$card['totals']+[
            'accessory_receivable_cents'=>$accessoryTotal,
            'principal_receivable_cents'=>$card['totals']['gross_receivable_cents']===null||$accessoryTotal===null?null:bcsub($card['totals']['gross_receivable_cents'],$accessoryTotal,0)],
            'aging'=>$aging,'claim_count'=>count($card['claims']),'credit_count'=>count($card['credits']),
            'precision'=>$card['precision'],'uncertain_event_count'=>count($uncertain),'liability_review_count'=>$liabilityReview,
            'coverage'=>['scope'=>$card['coverage']['scope'],'financial_complete'=>$complete,'complete'=>false,
                'service_settlements'=>$card['coverage']['service_settlements'],'opening_import'=>$card['coverage']['opening_import'],
                'unresolved_negative_charge_line_count'=>count($card['coverage']['unresolved_negative_charge_line_ids'])]];
    }
}
