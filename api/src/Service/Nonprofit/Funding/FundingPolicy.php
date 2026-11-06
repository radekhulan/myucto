<?php
declare(strict_types=1);
namespace MyInvoice\Service\Nonprofit\Funding;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
final class FundingPolicy
{
    public static function normalize(array $input): array
    {
        $fields = ['kind','title','purpose','evidence','provider_id','budget_cents','valid_from','valid_to','activity_kind_ids','account_prefixes','source_anchor','source_members'];
        if (array_diff(array_keys($input), $fields) !== []) self::invalid('Neznámé pole účelového zdroje.');
        foreach (['title','purpose','evidence'] as $field) $input[$field] = NonprofitTaxRules::evidence($input[$field] ?? null);
        if (!in_array($input['kind'] ?? null, ['operating_grant','investment_grant','gift'], true)) self::invalid('Neznámý druh účelového zdroje.');
        foreach (['provider_id','budget_cents'] as $field) if (!is_int($input[$field] ?? null) || $input[$field] <= 0) self::invalid('Poskytovatel a rozpočet musí mít kladné celočíselné hodnoty.');
        if ($input['budget_cents'] > 999999999999999) self::invalid('Rozpočet překračuje účetní rozsah.');
        foreach (['valid_from','valid_to'] as $field) {
            $date = $input[$field] ?? null;
            if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) self::invalid('Neplatné datum způsobilosti.');
        }
        if ($input['valid_from'] > $input['valid_to']) self::invalid('Období způsobilosti je obrácené.');
        foreach (['activity_kind_ids','account_prefixes','source_members'] as $field) {
            if (!is_array($input[$field] ?? null) || !array_is_list($input[$field]) || $input[$field] === [] || count($input[$field]) > 1000 || count(array_unique($input[$field], SORT_REGULAR)) !== count($input[$field])) self::invalid('Podklady způsobilosti musí tvořit neprázdný seznam bez duplicit.');
        }
        foreach ($input['activity_kind_ids'] as $id) if (!is_int($id) || $id <= 0) self::invalid('Neplatný druh činnosti.');
        foreach ($input['account_prefixes'] as $code) if (!is_string($code) || !preg_match('/^5[0-9]{2}(?:[.0-9A-Za-z]{0,7})$/D', $code)) self::invalid('Způsobilost vyžaduje konkrétní nákladové účty.');
        foreach ([$input['source_anchor'] ?? null, ...$input['source_members']] as $id) if (!is_string($id) || !preg_match('/^line:[1-9][0-9]*$/D', $id)) self::invalid('Chybí průkazný řádek zdroje.');
        if (!in_array($input['source_anchor'], $input['source_members'], true)) self::invalid('Kotva zdroje musí být jeho členem.');
        sort($input['activity_kind_ids'], SORT_NUMERIC); sort($input['account_prefixes'], SORT_STRING); sort($input['source_members'], SORT_STRING);
        return $input;
    }
    public static function assertCost(array $policy, array $row): void
    {
        if (!isset($row['kind'],$row['amount_cents'],$row['account_code'],$row['activity_kind_id'],$row['date'])) self::invalid('Náklad nemá průkazný účetní podklad.', 'nonprofit_funding_cost_ineligible');
        $matches = false;
        foreach ($policy['account_prefixes'] as $prefix) if (str_starts_with($row['account_code'], $prefix)) $matches = true;
        if ($row['kind'] !== 'expense' || $row['amount_cents'] <= 0 || !$matches || !in_array($row['activity_kind_id'], $policy['activity_kind_ids'], true)
            || $row['date'] < $policy['valid_from'] || $row['date'] > $policy['valid_to']) self::invalid('Čerpaný náklad nesplňuje doložené podmínky zdroje.', 'nonprofit_funding_cost_ineligible');
    }
    public static function assertTitle(array $policy, array $source): void
    {
        $expected = ['operating_grant'=>'public_operating_grant','investment_grant'=>'public_investment_grant','gift'=>'gift'][$policy['kind']];
        if (($source['kind'] ?? null) !== 'revenue' || ($source['decision']['title'] ?? null) !== $expected) self::invalid('Druh zdroje neodpovídá potvrzenému právnímu titulu.');
    }
    /** Členové fondu zdroje podle projekce posouzení (FundingAssessmentEvidence::project); bez fondu jen kotva. */
    public static function poolMembers(array $pools,string $anchor):array
    {
        $members=$pools[$anchor]??[$anchor];
        sort($members,SORT_STRING);return $members;
    }
    public static function sourceRows(array $catalog, array $members): array
    {
        $out=[];
        foreach($members as $id){
            if(!isset($catalog[$id]))self::invalid('Zdrojové členství není úplné.','nonprofit_funding_source_changed');
            $out[$id]=array_intersect_key($catalog[$id],array_flip(['id','line_id','entry_id','account_id','account_code','date','kind','amount_cents','activity_kind_id','origin','decision','classification']));
        }
        ksort($out,SORT_STRING);return $out;
    }
    public static function invalid(string $message, string $code = 'nonprofit_funding_invalid'): never
    {
        throw new NonprofitTaxAssessmentException($code, $message);
    }
}
