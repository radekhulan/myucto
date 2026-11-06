<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Service\Tax\Return\TaxReturnException;

final class NonprofitDppoClassificationBridge
{
    public function build(array $result,array $documentedLines=[]):array
    {
        if(($result['ruleset']??null)!==NonprofitTaxRules::VERSION||($result['scope']??null)!=='classification_bridge_before_general_adjustments'||!is_array($result['rows']??null))self::invalid();
        foreach($documentedLines as $documented)if(!is_array($documented))self::invalid();
        $lines=array_fill_keys([10,40,100,101,109,110,120],'0');$seen=[];$excluded='0';$costs='0';$details=[];
        foreach($result['rows'] as $row){
            if(!is_array($row))self::invalid();$id=$row['id']??null;if(!is_string($id)||$id===''||isset($seen[$id]))self::invalid();$seen[$id]=true;
            $amount=self::cents($row['amount_cents']??null);$adjustment=self::cents($row['adjustment_cents']??null);
            if(($row['kind']??null)==='expense'){
                $deductible=self::cents($row['deductible_cents']??null);if(bcsub($amount,$deductible,0)!==$adjustment)self::invalid();
                $lines[10]=bcsub($lines[10],$amount,0);$lines[40]=bcadd($lines[40],$adjustment,0);$costs=bcadd($costs,$adjustment,0);$line=40;
            }elseif(($row['kind']??null)==='revenue'){
                $lines[10]=bcadd($lines[10],$amount,0);$line=$this->revenueLine($row,$documentedLines[$id]??null);
                $expected=$line===null?'0':bcsub('0',$amount,0);if($adjustment!==$expected)self::invalid();
                if($line!==null){$lines[$line]=bcadd($lines[$line],$amount,0);$excluded=bcadd($excluded,$amount,0);}
            }else self::invalid();
            $details[]=['source_id'=>$id,'line'=>$line,'amount_cents'=>(int)($line===40?$adjustment:$amount)];
        }
        if(array_diff_key($documentedLines,$seen)!==[])self::invalid();
        foreach(['accounting_result_cents'=>$lines[10],'excluded_revenue_cents'=>$excluded,'cost_addbacks_cents'=>$costs,'assessed_result_cents'=>bcadd(bcsub($lines[10],$excluded,0),$costs,0)] as $key=>$value)if(self::cents($result[$key]??null)!==$value)self::invalid();
        foreach($lines as &$amount)$amount=(int)self::cents($this->integer($amount));unset($amount);
        return ['line_cents'=>$lines,'source_lines'=>$details,'external_adjustments'=>$result['external_adjustments']??[],'scope'=>'classification_only','general_adjustments_included'=>false];
    }

    private function revenueLine(array $row,?array $documented):?int
    {
        $classification=$row['classification']??[];$regime=$classification['regime']??null;$method=$classification['method']??null;$basis=$classification['legal_basis']??'';if(!is_string($basis))self::invalid();
        if($regime==='subject'&&$method==='return')$line=null;
        elseif($regime==='subject'&&$method==='final_withholding')$line=120;
        elseif($regime==='exempt'&&$method==='none'&&str_starts_with($basis,'zdp_19b_'))$line=109;
        elseif($regime==='exempt'&&$method==='none'&&str_starts_with($basis,'zdp_19_'))$line=110;
        elseif($regime==='out_of_scope'&&$method==='none'&&str_starts_with($basis,'zdp_18a_'))$line=101;
        elseif($regime==='out_of_scope'&&$method==='none'&&str_starts_with($basis,'zdp_18_2f_'))$line=100;
        else{
            if($documented===null||!in_array($documented['line']??null,$regime==='exempt'?[109,110]:($regime==='out_of_scope'?[100,101]:[]),true)||$method!=='none')self::invalid();
            NonprofitTaxRules::evidence($documented['evidence']??null);return$documented['line'];
        }
        if($documented!==null&&($documented['line']??null)!==$line)self::invalid();
        return$line;
    }

    private function integer(string $amount):int
    {
        if(bccomp($amount,'9999999999999900',0)>0||bccomp($amount,'-9999999999999900',0)<0)self::invalid();return(int)$amount;
    }

    private static function cents(mixed $amount):string
    {
        if(!is_int($amount)||$amount>9999999999999900||$amount< -9999999999999900)self::invalid();return(string)$amount;
    }

    private static function invalid():never
    {throw new TaxReturnException('nonprofit_dppo_bridge_invalid','Daňový můstek vyžaduje úplné a vzájemně shodné posouzení zdrojových řádků a jejich řádků přiznání.',422);}
}
