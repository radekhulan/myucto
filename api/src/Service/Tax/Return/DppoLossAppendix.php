<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final class DppoLossAppendix
{
    public static function build(int $supplierId, string $type, int $year, array $period, array $losses, float $requested, float $yearLoss, int $carryYears, int $carrybackYears = 0, float $carrybackLimit = 0.0): array
    {
        if ($type !== 'po') throw new \InvalidArgumentException('DPPO appendix requires a corporate taxpayer.');
        $requestedCents = TaxLossAllocationPlan::cents($requested);$rows=[];
        $relevant=array_values(array_filter($losses,static fn(array$loss):bool=>TaxLossAllocationPlan::eligibleOrigin((int)($loss['origin_year']??0),$year,$carryYears,$carrybackYears)));
        if($relevant===[]&&bccomp($requestedCents,'0',0)===0&&bccomp(TaxLossAllocationPlan::cents($yearLoss),'0',0)===0){$card=['version'=>1,'period'=>['starts_on'=>$period['starts_on']??null,'ends_on'=>$period['ends_on']??null],'rows'=>[],'applied_cents'=>'0','remaining_cents'=>'0'];return$card+['proof_hash'=>NonprofitTaxRules::hash($card)];}
        if (($period['starts_on'] ?? null) !== sprintf('%04d-01-01', $year) || ($period['ends_on'] ?? null) !== sprintf('%04d-12-31', $year)) self::unsupported();
        foreach($relevant as&$loss){$loss['applied']=$loss['applied']??($loss['applied_before_period']??0);$loss['carried_back']=$loss['carried_back']??0;}unset($loss);
        $plan=TaxLossAllocationPlan::build($relevant,$year,$requested,$carryYears,$carrybackYears,$carrybackLimit);
        if(($plan['unallocated_cents']??null)!=='0')throw new TaxReturnException('loss_allocation_insufficient','Vypočtený odpočet ztráty nelze celý přiřadit k doloženým obdobím přílohy E.',409);
        $chunks=array_column($plan['chunks'],'amount_cents','loss_id');
        foreach ($relevant as $loss) {
            $origin=(int)($loss['origin_year']??0);
            TaxLossPeriodProof::verifyRow($supplierId,$type,$loss);
            $amount=TaxLossAllocationPlan::cents($loss['amount']??0);$prior=TaxLossAllocationPlan::cents($loss['applied_before_period']??0);$available=bcsub($amount,$prior,0);
            if(bccomp($available,'0',0)<0)self::invalid();$applied=$chunks[(int)$loss['id']]??'0';if(bccomp($applied,$available,0)>0)self::invalid();
            $rest=$origin<$year&&$origin+$carryYears===$year?'0':bcsub($available,$applied,0);
            $rows[]=self::row((int)$loss['id'],(string)$loss['origin_starts_on'],(string)$loss['origin_ends_on'],$amount,$prior,$applied,$rest);
        }
        $yearLossCents=TaxLossAllocationPlan::cents($yearLoss);if(bccomp($yearLossCents,'0',0)>0)$rows[]=self::row(null,$period['starts_on'],$period['ends_on'],$yearLossCents,'0','0',$yearLossCents);
        usort($rows,static fn(array$a,array$b):int=>strcmp($b['starts_on'],$a['starts_on']));if(count($rows)>40)self::invalid();
        $applied='0';$remaining='0';foreach($rows as$row){$applied=bcadd($applied,$row['applied_cents'],0);$remaining=bcadd($remaining,$row['remaining_cents'],0);}
        if($applied!==$requestedCents)self::invalid();$card=['version'=>1,'period'=>['starts_on'=>$period['starts_on'],'ends_on'=>$period['ends_on']],'rows'=>$rows,'applied_cents'=>$applied,'remaining_cents'=>$remaining];
        return$card+['proof_hash'=>NonprofitTaxRules::hash($card)];
    }

    public static function verify(array $card, float $expectedApplied, float $expectedYearLoss): void
    {
        $hash=$card['proof_hash']??null;unset($card['proof_hash']);if(($card['version']??null)!==1||!is_string($hash)||!hash_equals($hash,NonprofitTaxRules::hash($card))||($card['applied_cents']??null)!==TaxLossAllocationPlan::cents($expectedApplied)||!is_array($card['rows']??null)||count($card['rows'])>40)self::invalid();
        $period=$card['period']??null;if(!is_array($period)||!self::date($period['starts_on']??null)||!self::date($period['ends_on']??null)||$period['starts_on']>$period['ends_on'])self::invalid();
        $applied='0';$remaining='0';$ids=[];$current=false;$currentAmount='0';foreach($card['rows']as$row){
            if(!is_array($row)||!self::date($row['starts_on']??null)||!self::date($row['ends_on']??null)||$row['starts_on']>$row['ends_on'])self::invalid();
            $id=$row['loss_id']??null;if($id!==null&&(!is_int($id)||$id<=0||isset($ids[$id])))self::invalid();if($id!==null)$ids[$id]=true;
            foreach(['amount_cents','applied_before_cents','applied_cents','remaining_cents']as$key){self::whole($row[$key]??null);if(bccomp($row[$key],'0',0)<0)self::invalid();}
            $used=bcadd($row['applied_before_cents'],$row['applied_cents'],0);if(bccomp($used,$row['amount_cents'],0)>0||bccomp(bcadd($used,$row['remaining_cents'],0),$row['amount_cents'],0)>0)self::invalid();
            if($id===null){if($current||$row['starts_on']!==$period['starts_on']||$row['ends_on']!==$period['ends_on']||$row['applied_before_cents']!=='0'||$row['applied_cents']!=='0'||$row['remaining_cents']!==$row['amount_cents'])self::invalid();$current=true;$currentAmount=$row['amount_cents'];}
            elseif($row['starts_on']!==substr($row['starts_on'],0,4).'-01-01'||$row['ends_on']!==substr($row['starts_on'],0,4).'-12-31')self::invalid();
            $applied=bcadd($applied,$row['applied_cents'],0);$remaining=bcadd($remaining,$row['remaining_cents'],0);
        }
        if($applied!==$card['applied_cents']||$remaining!==($card['remaining_cents']??null)||$currentAmount!==TaxLossAllocationPlan::cents($expectedYearLoss))self::invalid();
    }

    public static function assertPlan(array $card,array $plan,float $expectedYearLoss):void
    {
        self::verify($card,(float)TaxLossAllocationPlan::amount((string)($plan['requested_cents']??'0')),$expectedYearLoss);$actual=[];foreach($card['rows']as$row)if(($row['loss_id']??null)!==null&&bccomp($row['applied_cents'],'0',0)>0)$actual[]=['loss_id'=>$row['loss_id'],'amount_cents'=>$row['applied_cents']];
        $expected=array_map(static fn(array$chunk):array=>['loss_id'=>(int)$chunk['loss_id'],'amount_cents'=>(string)$chunk['amount_cents']],$plan['chunks']??[]);$sort=static fn(array$a,array$b):int=>$a['loss_id']<=>$b['loss_id'];usort($actual,$sort);usort($expected,$sort);if($actual!==$expected)self::invalid();
    }

    public static function xmlProjection(array $card):array
    {
        $rows=[];foreach($card['rows']??[]as$row)$rows[]=['pr1e_sl_1_od'=>self::epoDate($row['starts_on']),'pr1e_sl_1_do'=>self::epoDate($row['ends_on']),'pr1e_sl_2'=>self::crowns($row['amount_cents']),'pr1e_sl_3'=>self::crowns($row['applied_before_cents']),'pr1e_sl_4'=>self::crowns($row['applied_cents']),'pr1e_sl_5'=>self::crowns($row['remaining_cents'])];
        return['rows'=>$rows,'totals'=>$rows===[]?null:['pr1e_sl_4_celk'=>self::crowns($card['applied_cents']),'kc_dppc65_d85'=>self::crowns($card['remaining_cents'])]];
    }

    public static function assertXml(array $card,\DOMXPath $xpath):void
    {
        $expected=self::xmlProjection($card);$actual=[];foreach($xpath->query('/Pisemnost/DPPDP9/VetaV')as$node){$attributes=[];foreach($node->attributes as$attribute)$attributes[$attribute->name]=$attribute->value;ksort($attributes);$actual[]=$attributes;}
        foreach($expected['rows']as&$row)ksort($row);unset($row);if($actual!==$expected['rows'])self::invalid();
        $totals=$xpath->query('/Pisemnost/DPPDP9/VetaI');if($expected['totals']===null){if($totals->length!==0)self::invalid();return;}if($totals->length!==1)self::invalid();$actualTotals=[];foreach($totals->item(0)->attributes as$attribute)$actualTotals[$attribute->name]=$attribute->value;ksort($actualTotals);ksort($expected['totals']);if($actualTotals!==$expected['totals'])self::invalid();
    }

    private static function row(?int $lossId,string $from,string $to,string $amount,string $prior,string $applied,string $remaining):array
    {
        foreach([$amount,$prior,$applied,$remaining]as$value)self::whole($value);return['loss_id'=>$lossId,'starts_on'=>$from,'ends_on'=>$to,'amount_cents'=>$amount,'applied_before_cents'=>$prior,'applied_cents'=>$applied,'remaining_cents'=>$remaining];
    }

    private static function whole(mixed $cents):void
    {if(!is_string($cents)||!preg_match('/^-?\d+$/D',$cents)||bcmod($cents,'100')!=='0')throw new TaxReturnException('loss_appendix_whole_crowns_required','Příloha E vyžaduje přesné částky daňových ztrát v celých Kč.',422);}
    private static function date(mixed $date):bool
    {if(!is_string($date)||preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})$/D',$date,$m)!==1)return false;return checkdate((int)$m[2],(int)$m[3],(int)$m[1]);}
    private static function epoDate(string $date):string
    {return(new \DateTimeImmutable($date))->format('d.m.Y');}
    private static function crowns(string $cents):string
    {return bcdiv($cents,'100',0);}
    private static function unsupported():never
    {throw new TaxReturnException('loss_allocation_period_unsupported','Příloha E je automaticky podporována pouze pro doložený řetězec kalendářních zdaňovacích období.',422);}
    private static function invalid():never
    {throw new TaxReturnException('loss_appendix_proof_invalid','Zmrazený podklad přílohy E nesouhlasí s evidencí a uplatněným odečtem daňové ztráty.',409);}
}
