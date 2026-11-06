<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Funding;

final class FundingRevisionShape
{
    private const MAX_MEMBERS = 1000;

    public static function selectColumns(string $alias = ''): string
    {
        if ($alias !== '' && !preg_match('/^[a-z][a-z0-9_]*$/D', $alias)) {
            throw new \InvalidArgumentException('Invalid funding revision SQL alias.');
        }
        $prefix = $alias === '' ? '' : $alias . '.';
        $columns = array_map(
            static fn (string $column): string => $prefix . $column,
            ['id', 'supplier_id', 'period_id', 'source_key', 'revision_no', 'previous_id', 'parent_hash',
                'provider_id', 'assessment_id'],
        );
        $columns[] = self::guardedJson($prefix . 'policy_json', 'policy_json', ['$.source_members' => ['ARRAY']]);
        $columns[] = self::guardedJson($prefix . 'basis_json', 'basis_json', [
            '$.source_rows' => ['ARRAY', 'OBJECT'],
            '$.funding_pool.members' => ['ARRAY'],
        ]);
        $columns[] = self::guardedJson($prefix . 'request_json', 'request_json', ['$.policy.source_members' => ['ARRAY']]);
        array_push($columns, ...array_map(
            static fn (string $column): string => $prefix . $column,
            ['reason', 'idempotency_key', 'request_hash', 'record_hash', 'created_by', 'created_at'],
        ));
        return implode(',', $columns);
    }

    public static function verify(array $policy, array $basis, array $request): void
    {
        self::optionalList($policy, 'source_members');
        self::optionalArray($basis, 'source_rows');
        if (array_key_exists('funding_pool', $basis)) {
            if (!is_array($basis['funding_pool'])) {
                FundingPolicy::invalid('Historie účelového zdroje není průkazná.', 'nonprofit_funding_integrity');
            }
            self::optionalList($basis['funding_pool'], 'members');
        }
        if (array_key_exists('policy', $request)) {
            if (!is_array($request['policy'])) {
                FundingPolicy::invalid('Historie účelového zdroje není průkazná.', 'nonprofit_funding_integrity');
            }
            self::optionalList($request['policy'], 'source_members');
        }
    }

    private static function guardedJson(string $column, string $output, array $paths): string
    {
        $checks = ['JSON_VALID(' . $column . ')=1'];
        foreach ($paths as $path => $types) {
            $value = "JSON_EXTRACT({$column},'{$path}')";
            $allowed = implode(',', array_map(static fn (string $type): string => "'{$type}'", $types));
            $checks[] = "(JSON_TYPE({$value}) IS NULL OR (JSON_TYPE({$value}) IN ({$allowed}) AND JSON_LENGTH({$value})<=" . self::MAX_MEMBERS . '))';
        }
        return 'CASE WHEN ' . implode(' AND ', $checks) . " THEN {$column} ELSE NULL END AS {$output}";
    }

    private static function optionalList(array $parent, string $field): void
    {
        if (!array_key_exists($field, $parent)) {
            return;
        }
        if (!is_array($parent[$field]) || !array_is_list($parent[$field]) || count($parent[$field]) > self::MAX_MEMBERS) {
            FundingPolicy::invalid('Historie účelového zdroje není průkazná.', 'nonprofit_funding_integrity');
        }
    }

    private static function optionalArray(array $parent, string $field): void
    {
        if (!array_key_exists($field, $parent)) {
            return;
        }
        if (!is_array($parent[$field]) || count($parent[$field]) > self::MAX_MEMBERS) {
            FundingPolicy::invalid('Historie účelového zdroje není průkazná.', 'nonprofit_funding_integrity');
        }
    }
}
