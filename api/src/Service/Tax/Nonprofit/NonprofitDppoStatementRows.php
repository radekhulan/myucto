<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Service\Accounting\Reports\Statement504Builder;
use MyInvoice\Service\Accounting\Reports\Statement504Catalog;
use MyInvoice\Service\Tax\Return\TaxReturnException;

final class NonprofitDppoStatementRows
{
    public function build(array $balance,array $income):array
    {
        foreach([$balance,$income]as$statement)if(($statement['accounting_framework']??null)!=='cz_504'||($statement['version_code']??null)!==Statement504Catalog::VERSION||($statement['scope']??null)!=='full')$this->invalid();
        if(NonprofitTaxRules::hash($balance['period']??[])!==NonprofitTaxRules::hash($income['period']??[]))$this->invalid();
        $catalog=[];$rows=[];$leaves=[];
        foreach(['UR'=>'assets','US'=>'liabilities','UT'=>'rows']as$sentence=>$key){
            $catalog[$sentence]=Statement504Catalog::rows($sentence);$source=$sentence==='UT'?$income:$balance;
            if(!is_array($source[$key]??null)||array_column($source[$key],'adis_row')!==array_column($catalog[$sentence],'number'))$this->invalid();
            $rows[$sentence]=array_column($source[$key],null,'adis_row');$parents=[];
            foreach($catalog[$sentence]as$row){$code=rtrim($row['display_code'],'.');$dot=strrpos($code,'.');if($dot!==false)$parents[substr($code,0,$dot)]=true;}
            $leaves[$sentence]=[];
            foreach($catalog[$sentence]as$row){$n=$row['number'];$code=rtrim($row['display_code'],'.');if($code!==''&&!isset($parents[$code])&&!($sentence==='UT'&&in_array($n,[62,63],true)))$leaves[$sentence][]=$n;}
        }
        $builder=new Statement504Builder();$profit=[];
        foreach(['main','economic']as$column){
            $raw=[];foreach($leaves['UT']as$n)$raw[$n]=$this->cents($rows['UT'][$n][$column]??null)*($n<39?-1:1);
            $target=$this->nearest($this->cents($rows['UT'][63][$column]??null));
            $values=$this->roundTo($raw,$target);foreach($values as$n=>&$v)if($n<39)$v=-$v;unset($v);
            $profit[$column]=Statement504Builder::profitTotals($builder->rollup($catalog['UT'],$values));
        }
        $out=['UR'=>[],'US'=>[],'UT'=>[]];$balanceValues=[];
        foreach(['start','end']as$column){
            $raw=[];$fixed=[];
            foreach(['UR','US']as$sentence){
                $raw[$sentence]=[];$fixed[$sentence]=0;
                foreach($leaves[$sentence]as$n){
                    if($sentence==='US'&&in_array($n,[7,8],true)){
                        if($n===7){if($column==='end')$fixed[$sentence]+=$profit['main'][63]+$profit['economic'][63];continue;}
                        if($column==='end')continue;
                    }
                    $raw[$sentence][$n]=$this->cents($rows[$sentence][$n][$column]??null);
                }
            }
            [$aMin,$aMax]=$this->range($raw['UR']);[$lMin,$lMax]=$this->range($raw['US']);$lMin+=$fixed['US'];$lMax+=$fixed['US'];
            $minimum=max($aMin,$lMin);$maximum=min($aMax,$lMax);if($minimum>$maximum)$this->invalid();
            $target=max($minimum,min($maximum,$this->nearest($this->cents($rows['UR'][85][$column]??null))));
            foreach(['UR','US']as$sentence){
                $values=$this->roundTo($raw[$sentence],$target-$fixed[$sentence]);
                if($sentence==='US'&&$column==='end')$values[7]=$fixed[$sentence];
                $balanceValues[$sentence][$column]=$builder->rollup($catalog[$sentence],$values);
            }
        }
        foreach(['UR','US']as$sentence)foreach($catalog[$sentence]as$row){
            $n=$row['number'];$item=['c_radku'=>$n];
            if($sentence!=='US'||$n!==7)$item['kc_prvni']=$balanceValues[$sentence]['start'][$n];
            if($sentence!=='US'||$n!==8)$item['kc_posledni']=$balanceValues[$sentence]['end'][$n];
            $out[$sentence][]=$item;
        }
        foreach($catalog['UT']as$row){$n=$row['number'];$out['UT'][]=['c_radku'=>$n,'kc_hlav'=>$profit['main'][$n],'kc_hosp'=>$profit['economic'][$n],'kc_celkem'=>$profit['main'][$n]+$profit['economic'][$n]];}
        return$out;
    }

    public function append(\DOMDocument $dom,\DOMElement $parent,array $rows):void
    {
        foreach(['UR','US','UT']as$sentence)foreach($rows[$sentence]??[]as$row){$element=$dom->createElement('Veta'.$sentence);foreach($row as$key=>$value)$element->setAttribute($key,(string)$value);$parent->appendChild($element);}
    }

    private function roundTo(array $raw,int $target):array
    {
        [$minimum,$maximum]=$this->range($raw);if($target<$minimum||$target>$maximum)$this->invalid();$values=array_map($this->nearest(...),$raw);$difference=$target-array_sum($values);if($difference===0)return$values;
        $direction=$difference<=>0;$candidates=[];
        foreach($raw as$n=>$amount){[$floor,$ceil]=$this->bounds($amount);if(($direction>0&&$values[$n]<$ceil)||($direction<0&&$values[$n]>$floor))$candidates[]=['number'=>$n,'score'=>$direction*($amount-$values[$n]*100000)];}
        usort($candidates,static fn(array$a,array$b):int=>($b['score']<=>$a['score'])?:($a['number']<=>$b['number']));
        foreach($candidates as$candidate){if($difference===0)break;$values[$candidate['number']]+=$direction;$difference-=$direction;}
        if($difference!==0)$this->invalid();return$values;
    }
    private function range(array $raw):array
    {$minimum=$maximum=0;foreach($raw as$amount){[$floor,$ceil]=$this->bounds($amount);$minimum+=$floor;$maximum+=$ceil;}return[$minimum,$maximum];}
    private function bounds(int $cents):array
    {$whole=intdiv($cents,100000);$remainder=$cents%100000;return[$whole-($remainder<0?1:0),$whole+($remainder>0?1:0)];}
    private function nearest(int $cents):int
    {return($cents<=>0)*intdiv(abs($cents)+50000,100000);}
    private function cents(mixed $amount):int
    {if((!is_float($amount)&&!is_int($amount))||!is_finite((float)$amount)||abs($amount)>99999999999999)$this->invalid();return(int)bcmul(number_format((float)$amount,2,'.',''),'100',0);}
    private function invalid():never
    {throw new TaxReturnException('nonprofit_dppo_statement_invalid','Účetní příloha 504 vyžaduje úplné výkazy stejného období a průkazné součty po zaokrouhlení na tisíce.',422);}
}
