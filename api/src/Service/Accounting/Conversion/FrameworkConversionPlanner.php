<?php

declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Conversion;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountDefinitionRepository,FrameworkConversionRepository as R};
use MyInvoice\Service\Accounting\{ChartOfAccountsTemplate504,ChartOfAccounts504Policy};
use MyInvoice\Service\Accounting\Closing\Fx504Builder;
use MyInvoice\Service\Accounting\Conversion\FrameworkConversionException as E;
final class FrameworkConversionPlanner
{
    public function __construct(private readonly Connection $db,private readonly R $read){}
    public function choices(int $supplier,int $source,int $target,bool $current=false):array
    {
        $s=$this->read->source($supplier,$source,$target,$current);(new FrameworkConversionPendingOwners($this->db))->assertSupported($supplier,$s,$current);$accounts=(new AccountDefinitionRepository($this->db))->listForDate($supplier,$s['source']['ends_on'],$current);$byId=array_column($accounts,null,'id');$definitions=[];
        foreach($accounts as $account){$code=$account['account_code'];$parent=$byId[$account['parent_id']??0]['account_code']??null;$default=ChartOfAccounts504Policy::defaultAccount($parent??$code);$definition=AccountDefinitionRepository::definition($account);
            $compatible=$default!==null&&$default['type']===$account['account_type'];
            if($compatible)$definition['normal_side']=$default['normal_side'];$definition['is_active']=$compatible?1:0;
            $definitions[$code]=['account_code'=>$code,'account_type'=>$account['account_type'],'parent_code'=>$parent,'definition'=>$definition,'existing'=>true];
        }
        foreach(ChartOfAccountsTemplate504::ACCOUNTS as $default)if(!isset($definitions[$default['code']]))$definitions[$default['code']]=['account_code'=>$default['code'],'account_type'=>$default['type'],'parent_code'=>$default['parent_code']??null,'definition'=>['name'=>$default['name'],'normal_side'=>$default['normal_side'],'tax_deductibility'=>'deductible','is_active'=>1],'existing'=>false];
        ksort($definitions);
        $dimensions=$this->read->rows('SELECT t.id AS type_id,t.name AS type_name,t.system_key,t.code AS type_code,v.id AS value_id,v.code,v.name,v.semantic_key FROM dimension_types t JOIN dimension_values v ON v.type_id=t.id AND v.supplier_id=t.supplier_id WHERE t.supplier_id=? ORDER BY t.id,v.id',[$supplier],$current);
        $cells=$this->read->cells($s);$byValue=array_column($dimensions,null,'value_id');$projects=array_column($this->read->rows('SELECT p.id,p.name FROM projects p JOIN clients c ON c.id=p.client_id WHERE c.supplier_id=? ORDER BY p.id',[$supplier],$current),'name','id');$labels=[];
        foreach($cells as $cell){$names=[];foreach($cell['dimensions'] as $type=>$value){$label=$byValue[$value]??null;E::need($label!==null&&(int)$label['type_id']===(int)$type,'conversion_source_dimension_unproven');$names[]=$label['type_name'].': '.$label['code'].' - '.$label['name'];}E::need($cell['project_id']===null||isset($projects[$cell['project_id']]),'conversion_source_project_unproven');$labels[$cell['cell_key']]=['cost_center'=>$cell['cost_center'],'project'=>$cell['project_id']===null?null:$projects[$cell['project_id']],'dimensions'=>$names];}
        return ['source'=>$s['source'],'target'=>$s['target'],'cells'=>$cells,'source_labels'=>$labels,'definitions'=>array_values($definitions),'before_definitions'=>array_column(array_map(static fn($a)=>['account_code'=>$a['account_code'],'definition'=>AccountDefinitionRepository::definition($a)],$accounts),'definition','account_code'),'dimension_choices'=>$dimensions,'source_hash'=>$s['source_hash']];
    }
    public function plan(int $supplier,array $command,bool $current=false):array
    {
        E::need(array_diff(array_keys($command),['source_period_id','target_period_id','evidence','definitions','mapping'])===[],'conversion_command_invalid',422);
        E::need(is_int($command['source_period_id']??null)&&is_int($command['target_period_id']??null)&&is_array($command['mapping']??null)&&is_array($command['definitions']??null),'conversion_command_invalid',422);
        E::need(is_string($command['evidence']??null)&&trim($command['evidence'])!==''&&mb_strlen($command['evidence'])<=1000,'conversion_evidence_required',422);
        $s=$this->read->source($supplier,$command['source_period_id'],$command['target_period_id'],$current);(new FrameworkConversionPendingOwners($this->db))->assertSupported($supplier,$s,$current);$target=$s['target'];$date=$target['starts_on'];
        E::need($target['status']==='open','conversion_target_not_open');
        $entries=$this->read->rows('SELECT id FROM journal_entries WHERE supplier_id=? AND entry_date>=? ORDER BY id',[$supplier,$date],$current);E::need($entries===[],'conversion_target_not_empty');
        foreach($s['frameworks'] as $history)E::need($history['effective_from']<$date,'conversion_future_framework_conflict');
        $old=$this->read->rows("SELECT id FROM accounting_framework_conversions WHERE supplier_id=? AND (source_period_id=? OR target_period_id=?) AND status<>'cancelled'",[$supplier,$command['source_period_id'],$command['target_period_id']],$current);E::need($old===[],'conversion_already_exists');
        $legal=$this->read->rows('SELECT legal_form,accounting_mode,dimensions_enabled FROM supplier WHERE id=?',[$supplier],$current)[0];
        $legalProfile=\MyInvoice\Service\Accounting\LegalFormProfileRecommendation::forLegalForm($legal['legal_form'],$legal['accounting_mode']);
        E::need($legalProfile['entity_profile']!=='svj','conversion_svj_adoption_required',409,['required'=>'Typed S6 opening adoption proof: units, owners, legal debt titles, service advances and long-term advances reconciled to every opening ledger cell.']);
        $property=$this->read->rows('SELECT enabled FROM property_module_profiles WHERE supplier_id=? AND effective_from<=? AND (effective_to IS NULL OR effective_to>?) ORDER BY effective_from DESC,id DESC LIMIT 1',[$supplier,$date.' 23:59:59',$date.' 00:00:00'],$current);
        E::need(!$property||!(bool)$property[0]['enabled'],'conversion_svj_adoption_required',409,['required'=>'Typed S6 opening adoption proof: units and owners, legal debt titles, service advances, long-term advances and reconciliation to each opening ledger cell.']);
        $choices=$this->choices($supplier,$command['source_period_id'],$command['target_period_id'],$current);$required=array_column($choices['definitions'],null,'account_code');$definitions=[];
        foreach($command['definitions'] as $input){E::need(is_array($input)&&array_diff(array_keys($input),['account_code','account_type','parent_code','definition','existing'])===[],'conversion_definition_invalid',422);$code=$input['account_code']??'';E::need(is_string($code)&&isset($required[$code])&&!isset($definitions[$code]),'conversion_definition_invalid',422);$identity=$required[$code];
            E::need(($input['account_type']??null)===$identity['account_type']&&($input['parent_code']??null)===$identity['parent_code']&&($input['existing']??null)===$identity['existing'],'conversion_account_identity_immutable');
            $definition=AccountDefinitionRepository::definition($input['definition']??[]);
            if($definition['is_active'])E::need(ChartOfAccounts504Policy::validationError($code,$identity['account_type'],$definition['normal_side'],$identity['parent_code'])===null,'conversion_target_definition_invalid',422,['account_code'=>$code]);
            $definitions[$code]=array_replace($identity,['definition'=>$definition]);
        }
        E::need(count($definitions)===count($required),'conversion_definitions_incomplete',422);ksort($definitions);
        $cells=$choices['cells'];$keys=array_column($cells,'cell_key');E::need(count($command['mapping'])===count($keys)&&array_diff(array_keys($command['mapping']),$keys)===[],'conversion_mapping_incomplete',422);
        $lines=[];$mapped=[];
        foreach($cells as $cell){$m=$command['mapping'][$cell['cell_key']];E::need(is_array($m)&&array_diff(array_keys($m),['account_code','activity','activity_kind','tax_regime'])===[],'conversion_mapping_invalid',422);$code=$m['account_code']??null;$account=$definitions[$code]??null;
            E::need($account!==null&&$account['definition']['is_active']===1&&in_array($account['account_type'],['asset','liability','equity'],true),'conversion_target_account_invalid',422);
            E::need($account['account_type']===$cell['account_type'],'conversion_account_type_changed');
            E::need($cell['currency_code']==='CZK'||$code===$cell['account_code'],'conversion_fx_source_account_adoption_required');
            E::need($cell['role']!=='pending_approval'||$code==='931','conversion_current_profit_requires_931');E::need($cell['role']!=='retained'||$code==='932','conversion_retained_requires_932');
            E::need($cell['role']==='pending_approval'||$code!=='931','conversion_prior_balance_not_current_profit');
            E::need(in_array($m['activity']??null,['main','economic'],true)&&in_array($m['tax_regime']??null,['subject','out_of_scope','exempt'],true),'conversion_classification_required',422);
            $kind=$m['activity_kind']??null;E::need(is_array($kind)&&array_keys($kind)===['code','name']&&is_string($kind['code'])&&preg_match('/^[A-Za-z0-9_.-]{1,50}$/D',$kind['code'])===1&&is_string($kind['name'])&&trim($kind['name'])!==''&&mb_strlen($kind['name'])<=100,'conversion_activity_kind_required',422);
            E::need(!in_array(substr($cell['account_code'],0,3),['063','065','251','253','911','914'],true),'conversion_native_subledger_adoption_required',409,['account_code'=>$cell['account_code'],'required'=>'Verified native lot, fund or grant contract schedule reconciled to each ledger cell.']);
            $cents=$cell['czk_cents'];$line=['account_code'=>$code,'side'=>$cents>0?'debit':'credit','amount'=>Fx504Builder::decimal(abs($cents)),'cost_center'=>$cell['cost_center'],'project_id'=>isset($cell['project_id'])?(int)$cell['project_id']:null,'source_dimensions'=>$cell['dimensions'],'classification'=>['activity'=>$m['activity'],'activity_kind'=>$kind,'tax_regime'=>$m['tax_regime']]];
            if($cell['currency_code']!=='CZK'){$line['currency_code']=$cell['currency_code'];$line['amount_foreign']=Fx504Builder::decimal(abs($cell['foreign_cents']));$line['fx_rate']=$cell['foreign_cents']===0?'1.000000':bcdiv((string)abs($cents),(string)abs($cell['foreign_cents']),6);}
            $lines[]=$line;$lines[]=array_replace($line,['account_code'=>'961','side'=>$cents>0?'credit':'debit','currency_code'=>null,'amount_foreign'=>null,'fx_rate'=>null]);$mapped[]=['source'=>$cell,'target'=>$line];
        }
        E::need(isset($definitions['961'])&&$definitions['961']['definition']['is_active']===1&&$definitions['961']['account_type']==='closing','conversion_opening_role_invalid');
        $physical=$this->read->rows('SELECT * FROM chart_of_accounts WHERE supplier_id=? ORDER BY id',[$supplier],$current);$history=$this->read->rows('SELECT * FROM accounting_account_definition_history WHERE supplier_id=? ORDER BY account_id,effective_from,id',[$supplier],$current);$dimensions=$this->read->rows('SELECT * FROM dimension_types WHERE supplier_id=? ORDER BY id',[$supplier],$current);$values=$this->read->rows('SELECT * FROM dimension_values WHERE supplier_id=? ORDER BY id',[$supplier],$current);$settings=$this->read->rows('SELECT * FROM accounting_supplier_settings WHERE supplier_id=?',[$supplier],$current);
        foreach($settings as $setting)E::need(!$setting['locked_until']||$date>$setting['locked_until'],'conversion_date_locked');
        foreach($history as $definition)E::need($definition['effective_from']<$date,'conversion_future_account_definition_conflict');
        $supplierState=$this->read->rows('SELECT dimensions_enabled FROM supplier WHERE id=?',[$supplier],$current)[0];
        $state=R::hash([$s['source_hash'],$target,$s['frameworks'],$physical,$history,$dimensions,$values,$settings,$property,$legal,$choices['source_labels']]);
        return ['state_hash'=>$state,'source_hash'=>$s['source_hash'],'source_period_id'=>$command['source_period_id'],'target_period_id'=>$command['target_period_id'],'source_entry_id'=>$s['source_entry_id'],'effective_from'=>$date,'definitions'=>array_values($definitions),'mapped_cells'=>$mapped,'lines'=>$lines,'preimage'=>['accounts'=>$physical,'history'=>$history,'frameworks'=>$s['frameworks'],'dimension_types'=>$dimensions,'dimension_values'=>$values,'dimensions_enabled'=>(int)$supplierState['dimensions_enabled']]];
    }
}
