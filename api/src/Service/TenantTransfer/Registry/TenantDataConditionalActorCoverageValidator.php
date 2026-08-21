<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;

/** Zesílení nullable user FK, které je pro konkrétní business scope povinné. */
final class TenantDataConditionalActorCoverageValidator
{
    /** @return list<string> */
    public function issues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
    ): array {
        $raw = $definition->details['conditional_actor_references'] ?? [];
        if (!is_array($raw) || (array_is_list($raw) && $raw !== [])) {
            return [
                'invalid_conditional_actor_reference_registry:' . $table->name,
            ];
        }
        $actors = $definition->details['actor_references'] ?? [];
        if (!is_array($actors) || (array_is_list($actors) && $actors !== [])) {
            return [
                'invalid_conditional_actor_reference_registry:' . $table->name,
            ];
        }

        $issues = [];
        foreach ($raw as $column => $declaration) {
            if (!is_string($column)
                || !is_array($declaration)
                || array_is_list($declaration)
                || ($declaration['strategy'] ?? null)
                    !== 'map_existing_user_required_when'
            ) {
                $issues[] = 'invalid_conditional_actor_reference:'
                    . $table->name . '.' . (string) $column;
                continue;
            }
            $actor = $actors[$column] ?? null;
            if (!is_array($actor)
                || ($actor['strategy'] ?? null)
                    !== 'map_existing_user_or_null'
                || !$this->hasNullableUserForeignKey($table, $column)
            ) {
                $issues[] = 'conditional_actor_base_policy_mismatch:'
                    . $table->name . '.' . $column;
            }

            $condition = $declaration['condition'] ?? null;
            $conditionColumn = is_array($condition)
                ? ($condition['column'] ?? null)
                : null;
            if (!is_array($condition)
                || array_is_list($condition)
                || !is_string($conditionColumn)
                || ($condition['operator'] ?? null) !== 'equals'
                || !is_string($condition['value'] ?? null)
                || $condition['value'] === ''
            ) {
                $issues[] = 'invalid_conditional_actor_condition:'
                    . $table->name . '.' . $column;
                continue;
            }
            if (!in_array($conditionColumn, $table->columns, true)) {
                $issues[] = 'conditional_actor_condition_column_missing:'
                    . $table->name . '.' . $conditionColumn;
            }
        }

        sort($issues, SORT_STRING);
        return $issues;
    }

    private function hasNullableUserForeignKey(
        TenantSchemaTableInventory $table,
        string $column,
    ): bool {
        if (!in_array($column, $table->nullableColumns, true)) {
            return false;
        }
        foreach ($table->foreignKeys as $foreignKey) {
            if ($foreignKey->column === $column
                && $foreignKey->referencedTable === 'users'
                && $foreignKey->referencedColumn === 'id'
            ) {
                return true;
            }
        }
        return false;
    }
}
