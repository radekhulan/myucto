<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;

/** Fail-closed kontrola odkazů na kódové registry poskytované aplikací. */
final class TenantDataCodeReferenceCoverageValidator
{
    /** @return list<string> */
    public function issues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
    ): array {
        $raw = $definition->details['code_references'] ?? [];
        if (!is_array($raw) || (array_is_list($raw) && $raw !== [])) {
            return ['invalid_code_reference_registry:' . $table->name];
        }

        $issues = [];
        foreach ($raw as $column => $reference) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || !is_array($reference)
                || array_is_list($reference)
                || ($reference['strategy'] ?? null)
                    !== 'application_registry_code'
                || !is_string($reference['registry'] ?? null)
                || preg_match(
                    '/^[a-z][a-z0-9._-]{0,63}$/D',
                    $reference['registry'],
                ) !== 1
            ) {
                $issues[] = 'invalid_code_reference:'
                    . $table->name . '.' . (string) $column;
                continue;
            }
            if (!in_array($column, $table->columns, true)) {
                $issues[] = 'code_reference_column_missing:'
                    . $table->name . '.' . $column;
                continue;
            }
            if (($reference['unknown_value'] ?? null) !== 'block') {
                $issues[] = 'code_reference_unknown_value_not_blocked:'
                    . $table->name . '.' . $column;
            }
            $expectedNullPolicy = in_array(
                $column,
                $table->nullableColumns,
                true,
            ) ? 'preserve' : 'forbid';
            if (($reference['null_value'] ?? null) !== $expectedNullPolicy) {
                $issues[] = 'code_reference_null_policy_mismatch:'
                    . $table->name . '.' . $column;
            }
        }

        sort($issues, SORT_STRING);
        return $issues;
    }
}
