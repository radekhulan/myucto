<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments;
use MyInvoice\Service\Property\PropertyInput;
final class ClaimPortionAllocator
{
    public static function distribute(string $amount,array $capacities,?array $declared,bool $proportional=false):array
    {
        StatutoryAllocationPolicy::cents($amount);$sum='0';foreach($capacities as $id=>$capacity){PropertyInput::id($id,'portion_id');$sum=bcadd($sum,StatutoryAllocationPolicy::cents($capacity),0);}if(bccomp($amount,$sum,0)>0)throw new \DomainException('distribution_capacity');
        $capacities=array_filter($capacities,fn($c)=>$c!=='0');$out=[];
        if($declared===null){
            if($amount==='0')return [];
            if($amount===$sum)$out=$capacities;
            elseif(count($capacities)===1)$out=[array_key_first($capacities)=>$amount];
            elseif($proportional)$out=self::proportional($amount,$capacities,$sum);
            else throw new \DomainException('distribution_required');
        }else{
            PropertyInput::fields($declared,['evidence','parts']);PropertyInput::text($declared['evidence']??null,'distribution.evidence',4000);$actual='0';
            foreach(PropertyInput::list($declared['parts']??null,'parts') as $part){PropertyInput::fields($part,['id','amount_cents']);$id=PropertyInput::id($part['id']??null,'portion_id');$value=StatutoryAllocationPolicy::cents($part['amount_cents']??null);
                if(isset($out[$id])||!isset($capacities[$id])||$value==='0'||bccomp($value,$capacities[$id],0)>0)throw new \DomainException('distribution_capacity');$out[$id]=$value;$actual=bcadd($actual,$value,0);}
            if($actual!==$amount)throw new \DomainException('distribution_margin');
        }
        ksort($out,SORT_NUMERIC);return $out;
    }

    /**
     * Výchozí rozdělení částečně uhrazené jistiny mezi řádky jednoho dluhu.
     *
     * Předpis jednotky je jedna pohledávka (§ 1932 OZ řeší pořadí jejích složek, ne analytických
     * řádků jistiny) a všechny řádky sdílejí splatnost dluhu, takže ani nejdříve splatná část podle
     * § 1933 OZ mezi nimi nerozhoduje. Bez určení dlužníkem (a souhlasu věřitele) se jistina proto
     * snižuje poměrně podle zbývajících částí řádků, žádná složka (fond oprav, služby) se tím
     * neupřednostní. Haléřové zbytky dostanou řádky s největším zlomkem, při shodě nižší id, aby
     * výsledek nezávisel na pořadí účetních řádků.
     */
    private static function proportional(string $amount,array $capacities,string $sum):array
    {
        $out=[];$remainders=[];$assigned='0';
        foreach($capacities as $id=>$capacity){
            $scaled=bcmul($amount,$capacity,0);
            $out[$id]=bcdiv($scaled,$sum,0);
            $remainders[$id]=bcmod($scaled,$sum,0);
            $assigned=bcadd($assigned,$out[$id],0);
        }
        $ids=array_keys($remainders);
        usort($ids,fn(int $a,int $b):int=>bccomp($remainders[$b],$remainders[$a],0)?:$a<=>$b);
        $left=(int)bcsub($amount,$assigned,0);
        foreach(array_slice($ids,0,$left) as $id)$out[$id]=bcadd($out[$id],'1',0);
        return array_filter($out,fn($value)=>$value!=='0');
    }
}
