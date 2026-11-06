<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges\Issuance;

use MyInvoice\Service\Property\PropertyInput as Input;
use MyInvoice\Service\Property\PropertyInstant;

final class IssuanceRequest
{
    public static function normalize(array $input): array
    {
        Input::fields($input, ['version_id','source_key','title','issue_mode','period_from','period_to','claim_origin_at','issued_at','accounting_date','due_date','reason','units','accounts','case_identity']);
        if (!in_array($input['title'] ?? null, ['prescription','extra_contribution'], true)
            || !in_array($input['issue_mode'] ?? null, ['initial','replacement','difference'], true)) Input::invalid('title');
        $from = self::date($input['period_from'] ?? null); $to = self::date($input['period_to'] ?? null);
        if ($to < $from) Input::invalid('period_to');
        $units = [];
        foreach (Input::list($input['units'] ?? null, 'units', 1000) as $unit) {
            if (!is_array($unit)) Input::invalid('units');
            Input::fields($unit, ['unit_id','expected_revision','previous_charge_id','liability_groups','notices','case_distinction']);
            $id = Input::id($unit['unit_id'] ?? null, 'unit_id');
            if (isset($units[$id]) || !is_int($unit['expected_revision'] ?? null) || $unit['expected_revision'] < 0) Input::invalid('expected_revision');
            $previous = ($unit['previous_charge_id'] ?? null) === null ? null : Input::id($unit['previous_charge_id'], 'previous_charge_id');
            if (($input['issue_mode'] === 'initial') !== ($previous === null)) Input::invalid('previous_charge_id');
            $groups = Input::list($unit['liability_groups'] ?? null, 'liability_groups');
            foreach($groups as $group) {
                if(!is_array($group)) Input::invalid('liability_groups');
                foreach(Input::list($group['members']??null,'members') as $member) Input::id($member,'client_id');
            }
            $notices = Input::list($unit['notices'] ?? null, 'notices');
            $seen = [];
            foreach ($notices as &$notice) {
                if (!is_array($notice)) Input::invalid('notice');
                $component = Input::id($notice['component_id'] ?? null, 'component_id');
                if (isset($seen[$component]) || !is_array($notice['notice'] ?? null)) Input::invalid('notice');
                Input::fields($notice, ['component_id','notice']); $seen[$component] = true;
                foreach(Input::list($notice['notice']['deliveries']??[],'deliveries') as $delivery) {
                    if(!is_array($delivery)) Input::invalid('deliveries');
                    Input::id($delivery['recipient_id']??null,'recipient_id');
                }
            }
            unset($notice);
            usort($notices, static fn($a,$b) => $a['component_id'] <=> $b['component_id']);
            $units[$id] = ['unit_id'=>$id,'expected_revision'=>$unit['expected_revision'],'previous_charge_id'=>$previous,'liability_groups'=>$groups,'notices'=>$notices];
            if (array_key_exists('case_distinction',$unit)) $units[$id]['case_distinction'] = IssuanceCaseIdentity::distinction($unit['case_distinction']);
        }
        if ($units === []) Input::invalid('units');
        ksort($units, SORT_NUMERIC);
        $accounts = [];
        foreach (Input::list($input['accounts'] ?? null, 'accounts', 100) as $account) {
            if (!is_array($account)) Input::invalid('accounts');
            Input::fields($account, ['component_id','receivable_account','counter_account','basis_ref','settlement_due_on','dimensions']);
            $id = Input::id($account['component_id'] ?? null, 'component_id');
            if (isset($accounts[$id])) Input::invalid('component_id');
            foreach (['receivable_account','counter_account'] as $field) {
                if (!is_string($account[$field] ?? null) || !preg_match('/^[0-9A-Za-z.]{3,10}$/D', $account[$field])) Input::invalid($field);
            }
            $dimensions = $account['dimensions'] ?? [];
            if (!is_array($dimensions)) Input::invalid('dimensions');
            foreach ($dimensions as $type => $value) {
                if (!ctype_digit((string) $type) || (int) $type <= 0) Input::invalid('dimension_type');
                Input::id($value, 'dimension_value');
            }
            ksort($dimensions, SORT_NUMERIC);
            $accounts[$id] = ['component_id'=>$id,'receivable_account'=>$account['receivable_account'],'counter_account'=>$account['counter_account'],
                'basis_ref'=>Input::text($account['basis_ref'] ?? null, 'basis_ref', 2000),
                'settlement_due_on'=>($account['settlement_due_on'] ?? null) === null ? null : self::date($account['settlement_due_on']), 'dimensions'=>$dimensions];
        }
        ksort($accounts, SORT_NUMERIC);
        $request = ['version_id'=>Input::id($input['version_id'] ?? null, 'version_id'),'source_key'=>Input::code($input['source_key'] ?? null, 'source_key'),
            'title'=>$input['title'],'issue_mode'=>$input['issue_mode'],'period_from'=>$from,'period_to'=>$to,
            'claim_origin_at'=>PropertyInstant::normalize($input['claim_origin_at'] ?? null),'issued_at'=>PropertyInstant::normalize($input['issued_at'] ?? null),
            'accounting_date'=>self::date($input['accounting_date'] ?? null),'due_date'=>self::date($input['due_date'] ?? null),
            'reason'=>Input::text($input['reason'] ?? null, 'reason', 4000),'units'=>array_values($units),'accounts'=>array_values($accounts)];
        if (array_key_exists('case_identity',$input)) $request['case_identity'] = IssuanceCaseIdentity::normalize($input['case_identity']);
        return $request;
    }

    public static function date(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $value)) Input::invalid('date');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Prague'));
        if ($date === false || $date->format('Y-m-d') !== $value) Input::invalid('date');
        return $value;
    }

    public static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
