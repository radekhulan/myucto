<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;

/** Kontrola tabulek, které obsahují tenantové řádky i globální reference. */
final class TenantDataMixedOwnershipCoverageValidator
{
    /** @return list<string> */
    public function issues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
    ): array {
        $ownership = $definition->details['ownership'] ?? null;
        if (!is_array($ownership)
            || array_is_list($ownership)
            || ($ownership['strategy'] ?? null)
                !== 'supplier_id_or_global_reference'
            || ($ownership['tenant_rows'] ?? null) !== 'transfer'
        ) {
            return ['invalid_mixed_ownership:' . $table->name];
        }

        $issues = [];
        $column = $ownership['column'] ?? null;
        if ($column !== 'supplier_id'
            || !in_array($column, $table->columns, true)
        ) {
            $issues[] = 'mixed_ownership_column_missing:' . $table->name;
        } elseif (!in_array($column, $table->nullableColumns, true)) {
            $issues[] = 'mixed_ownership_column_not_nullable:'
                . $table->name . '.' . $column;
        }

        $globalRows = $ownership['global_rows'] ?? null;
        $mapping = is_array($globalRows)
            ? ($globalRows['mapping'] ?? null)
            : null;
        if (!is_array($globalRows)
            || array_is_list($globalRows)
            || ($globalRows['selector'] ?? null)
                !== 'supplier_id_is_null'
            || !is_array($mapping)
            || array_is_list($mapping)
            || ($mapping['strategy'] ?? null) !== 'natural_key'
            || ($mapping['missing'] ?? null) !== 'block'
            || ($mapping['ambiguous'] ?? null) !== 'block'
        ) {
            $issues[] = 'invalid_mixed_global_mapping:' . $table->name;
            sort($issues, SORT_STRING);
            return $issues;
        }

        $keys = $this->uniqueStringList($mapping['keys'] ?? null);
        if ($keys === null || $keys === []) {
            $issues[] = 'invalid_mixed_global_mapping:' . $table->name;
        } else {
            $keysAreKnown = true;
            foreach ($keys as $key) {
                if (!in_array($key, $table->columns, true)) {
                    $keysAreKnown = false;
                    $issues[] = 'mixed_global_mapping_key_missing:'
                        . $table->name . '.' . $key;
                } elseif (in_array($key, $table->nullableColumns, true)) {
                    $issues[] = 'mixed_global_mapping_nullable_key:'
                        . $table->name . '.' . $key;
                }
            }
            if (is_string($column)
                && in_array($column, $table->columns, true)
                && $keysAreKnown
                && !in_array([$column, ...$keys], $table->uniqueKeys, true)
            ) {
                $issues[] = 'mixed_global_mapping_not_unique:'
                    . $table->name;
            }
        }

        $values = $mapping['values'] ?? null;
        $valueColumns = is_array($values)
            ? $this->uniqueStringList($values['columns'] ?? null)
            : null;
        if (!is_array($values)
            || array_is_list($values)
            || ($values['strategy'] ?? null) !== 'require_equal'
            || $valueColumns === null
            || $valueColumns === []
        ) {
            $issues[] = 'invalid_mixed_global_value_policy:' . $table->name;
        } else {
            foreach ($valueColumns as $valueColumn) {
                if (!in_array($valueColumn, $table->columns, true)) {
                    $issues[] = 'mixed_global_value_column_missing:'
                        . $table->name . '.' . $valueColumn;
                }
            }
        }

        sort($issues, SORT_STRING);
        return $issues;
    }

    /** @return list<string>|null */
    private function uniqueStringList(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item) || in_array($item, $strings, true)) {
                return null;
            }
            $strings[] = $item;
        }
        return $strings;
    }
}
