<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Opening;
use MyInvoice\Service\Property\{PropertyInput as Input,PropertyInstant};
use MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest;
use MyInvoice\Service\Property\Payments\StatutoryAllocationPolicy;
final class OpeningRequest
{
    public static function normalize(array $input):array
    {
        Input::fields($input,['mode','accounting_date','effective_at','expected_revision','journal_entry_id','journal_version','evidence_ref','reason','items','other_balances']);
        $mode=$input['mode']??null;
        if(!in_array($mode,['post_new','bind_existing','revision','reversal'],true))Input::invalid('mode');
        $date=IssuanceRequest::date($input['accounting_date']??null);$effective=PropertyInstant::normalize($input['effective_at']??null);
        if((new \DateTimeImmutable($effective))->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d')!==$date)Input::invalid('effective_at');
        $revision=$input['expected_revision']??null;if(!is_int($revision)||$revision<0)Input::invalid('expected_revision');
        if(in_array($mode,['revision','reversal'],true)!==($revision>0))Input::invalid('expected_revision');
        $entry=$mode==='bind_existing'?Input::id($input['journal_entry_id']??null,'journal_entry_id'):null;
        $version=$mode==='bind_existing'?Input::id($input['journal_version']??null,'journal_version'):null;
        $items=[];$cases=[];
        foreach(Input::list($input['items']??null,'items',10000) as $item){
            if(!is_array($item))Input::invalid('items');
            Input::fields($item,['case_ref','occurrence_ref','kind','unit_id','service_id','origin_at','period_from','period_to','due_date','title_ref','evidence_ref','liability_groups','creditor_ids','legal','security','reminders','components','service_state','advance_state']);
            $kind=$item['kind']??null;if(!in_array($kind,['debt','credit','advance','service_carry'],true))Input::invalid('kind');
            $unit=Input::id($item['unit_id']??null,'unit_id');$service=($item['service_id']??null)===null?null:Input::id($item['service_id'],'service_id');
            $origin=PropertyInstant::normalize($item['origin_at']??null);if($origin>$effective)Input::invalid('origin_at');
            $from=IssuanceRequest::date($item['period_from']??null);$to=IssuanceRequest::date($item['period_to']??null);if($to<$from)Input::invalid('period_to');
            $case=Input::text($item['case_ref']??null,'case_ref',190);$occurrence=Input::text($item['occurrence_ref']??null,'occurrence_ref',190);
            $key=Input::hash(['case_ref'=>$case,'occurrence_ref'=>$occurrence]);if(isset($cases[$key]))Input::invalid('duplicate_case');$cases[$key]=true;
            $components=[];$keys=[];$amount='0';$accrual=['accrual_receivable'=>'0','accrual_payable'=>'0'];
            foreach(Input::list($item['components']??null,'components',100) as $part){
                if(!is_array($part))Input::invalid('components');Input::fields($part,['key','role','account_code','amount_cents','source_line_id']);
                $partKey=Input::code($part['key']??null,'component_key');if(isset($keys[$partKey]))Input::invalid('component_key');$keys[$partKey]=true;
                $role=Input::code($part['role']??null,'role');$account=Input::text($part['account_code']??null,'account_code',10);$side=OpeningRolePolicy::assert($kind,$role,$account);
                $cents=self::cents($part['amount_cents']??null);if($cents==='0')Input::invalid('amount_cents');
                if(in_array($role,['service_advance','service_cost','accrual_receivable','accrual_payable'],true)&&$service===null)Input::invalid('service_id');
                if(isset($accrual[$role]))$accrual[$role]=bcadd($accrual[$role],$cents,0);
                if($role!=='accrual_payable')$amount=bcadd($amount,$cents,0);
                $components[]=['key'=>$partKey,'role'=>$role,'account_code'=>$account,'side'=>$side,'amount_cents'=>$cents,'source_line_id'=>$mode==='bind_existing'?Input::id($part['source_line_id']??null,'source_line_id'):null];
            }
            if($components===[]||$accrual['accrual_receivable']!==$accrual['accrual_payable'])Input::invalid('components');
            if($kind==='credit'&&count($components)!==1)Input::invalid('credit_components');
            usort($components,static fn($a,$b)=>$a['key']<=>$b['key']);
            $out=['case_ref'=>$case,'occurrence_ref'=>$occurrence,'case_key'=>$key,'kind'=>$kind,'unit_id'=>$unit,'service_id'=>$service,'origin_at'=>$origin,'period_from'=>$from,'period_to'=>$to,
                'due_date'=>in_array($kind,['debt','credit'],true)?IssuanceRequest::date($item['due_date']??null):null,'title_ref'=>Input::text($item['title_ref']??null,'title_ref',4000),'evidence_ref'=>Input::text($item['evidence_ref']??null,'evidence_ref',4000),'amount_cents'=>$amount,'components'=>$components];
            if($kind==='debt'){
                $out['liability_groups']=Input::list($item['liability_groups']??null,'liability_groups');
                $legal=$item['legal']??null;if(!is_array($legal))Input::invalid('legal');Input::fields($legal,['basis','legal_act_date','evidence','regime','component_disposition']);
                try{StatutoryAllocationPolicy::allocate('0',array_fill_keys(StatutoryAllocationPolicy::COMPONENTS,'0'),$date,$legal);}catch(\DomainException|\InvalidArgumentException){Input::invalid('legal');}$out['legal']=$legal;
                $security=$item['security']??null;if(!is_array($security)||!in_array($security['kind']??null,['secured','unsecured'],true))Input::invalid('security');Input::fields($security,['kind','evidence']);Input::text($security['evidence']??null,'security.evidence',4000);$out['security']=$security;
                $out['reminders']=Input::list($item['reminders']??[],'reminders');foreach($out['reminders'] as &$notice){if(!is_array($notice))Input::invalid('reminder');Input::fields($notice,['delivered_at','recipient_ids','evidence']);$notice['delivered_at']=PropertyInstant::normalize($notice['delivered_at']??null);if($notice['delivered_at']>$effective)Input::invalid('delivered_at');Input::text($notice['evidence']??null,'reminder.evidence',4000);foreach(Input::list($notice['recipient_ids']??null,'recipient_ids') as $id)Input::id($id,'recipient_id');}unset($notice);
            }elseif($kind==='credit'){$out['creditor_ids']=Input::list($item['creditor_ids']??null,'creditor_ids');foreach($out['creditor_ids'] as $id)Input::id($id,'creditor_id');sort($out['creditor_ids'],SORT_NUMERIC);if($out['creditor_ids']===[]||count(array_unique($out['creditor_ids']))!==count($out['creditor_ids']))Input::invalid('creditor_ids');}
            elseif($kind==='advance'){
                $state=$item['advance_state']??null;if(!is_array($state))Input::invalid('advance_state');Input::fields($state,['purpose_ref','settlement_due_on','prescribed_cents','paid_cents','used_cents','unpaid_cases']);
                Input::text($state['purpose_ref']??null,'purpose_ref',4000);$state['settlement_due_on']=IssuanceRequest::date($state['settlement_due_on']??null);
                foreach(['prescribed_cents','paid_cents','used_cents'] as $field)$state[$field]=self::cents($state[$field]??null);
                if(bcsub($state['prescribed_cents'],$state['used_cents'],0)!==$amount||bccomp($state['paid_cents'],$state['prescribed_cents'],0)>0)Input::invalid('advance_state');
                $state['unpaid_cases']=Input::list($state['unpaid_cases']??null,'unpaid_cases');$out['advance_state']=$state;
            }else{
                $state=$item['service_state']??null;if(!is_array($state))Input::invalid('service_state');Input::fields($state,['profile','allocation_ref','counterpart_ref','prescribed_cents','paid_cents','settled_cents','cancelled_unpaid_cents','unpaid_cases','recipient_ids','accrual_unwind']);
                if(!in_array($state['profile']??null,['service_balance_sheet','service_profit_and_loss'],true))Input::invalid('profile');foreach(['allocation_ref','counterpart_ref'] as $field)Input::text($state[$field]??null,$field,4000);
                foreach(['prescribed_cents','paid_cents','settled_cents','cancelled_unpaid_cents'] as $field)$state[$field]=self::cents($state[$field]??null);
                if(bccomp($state['paid_cents'],$state['prescribed_cents'],0)>0||bccomp(bcadd($state['settled_cents'],$state['cancelled_unpaid_cents'],0),$state['prescribed_cents'],0)>0)Input::invalid('service_state');
                $state['unpaid_cases']=Input::list($state['unpaid_cases']??null,'unpaid_cases');$state['recipient_ids']=Input::list($state['recipient_ids']??null,'recipient_ids');foreach($state['recipient_ids'] as $id)Input::id($id,'recipient_id');if($state['recipient_ids']===[]||count(array_unique($state['recipient_ids']))!==count($state['recipient_ids']))Input::invalid('recipient_ids');if(isset($state['accrual_unwind'])){$unwind=$state['accrual_unwind'];if(!is_array($unwind)||$state['profile']!=='service_profit_and_loss')Input::invalid('accrual_unwind');Input::fields($unwind,['expense_account','coverage_account','evidence_ref','expense_dimensions','coverage_dimensions']);foreach(['expense_dimensions','coverage_dimensions'] as $field){if(!is_array($unwind[$field]??null))Input::invalid($field);foreach($unwind[$field] as $type=>$value){Input::id($type,'dimension_type');Input::id($value,'dimension_value');}}foreach(['expense_account','coverage_account','evidence_ref'] as $field)Input::text($unwind[$field]??null,$field,4000);if(!preg_match('/^5[0-9]{2}(?:\.[0-9]+)?$/D',$unwind['expense_account'])||!preg_match('/^649(?:\.[0-9]+)?$/D',$unwind['coverage_account']))Input::invalid('accrual_unwind');}$out['service_state']=$state;
            }
            $items[]=$out;
        }
        if(($mode==='reversal')!==($items===[]))Input::invalid('items');usort($items,static fn($a,$b)=>$a['case_key']<=>$b['case_key']);
        $other=Input::list($input['other_balances']??[],'other_balances',1000);
        if(in_array($mode,['bind_existing','reversal'],true)&&$other!==[])Input::invalid('other_balances');
        foreach($other as &$row){if(!is_array($row))Input::invalid('other_balances');Input::fields($row,['account_code','side','amount_cents','dimensions']);$row['account_code']=Input::text($row['account_code']??null,'account_code',10);if(OpeningRolePolicy::relevant($row['account_code'])||str_starts_with($row['account_code'],'96'))Input::invalid('other_balances');if(!in_array($row['side']??null,['debit','credit'],true))Input::invalid('side');$row['amount_cents']=self::cents($row['amount_cents']??null);if($row['amount_cents']==='0')Input::invalid('amount_cents');$row['dimensions']=$row['dimensions']??[];if(!is_array($row['dimensions']))Input::invalid('dimensions');foreach($row['dimensions'] as $type=>$value){if(!ctype_digit((string)$type)||(int)$type<1)Input::invalid('dimension');Input::id($value,'dimension');}}unset($row);
        return ['mode'=>$mode,'accounting_date'=>$date,'effective_at'=>$effective,'expected_revision'=>$revision,'journal_entry_id'=>$entry,'journal_version'=>$version,'evidence_ref'=>Input::text($input['evidence_ref']??null,'evidence_ref',4000),'reason'=>Input::text($input['reason']??null,'reason',4000),'items'=>$items,'other_balances'=>$other];
    }
    public static function replay(array $request):array
    {
        foreach($request['items'] as &$item){unset($item['case_key'],$item['amount_cents']);foreach($item['components'] as &$part)unset($part['side']);unset($part);}unset($item);return $request;
    }
    public static function cents(mixed $value):string
    {
        if(!is_string($value)||!preg_match('/^(0|[1-9][0-9]{0,27})$/D',$value))Input::invalid('amount_cents');return $value;
    }
}
