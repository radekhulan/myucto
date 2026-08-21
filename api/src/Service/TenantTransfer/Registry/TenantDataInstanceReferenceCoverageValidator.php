<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaForeignKeyInventory;
use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;

/** Fail-closed kontrola FK z tenantových řádků do instančních číselníků. */
final class TenantDataInstanceReferenceCoverageValidator
{
    /**
     * @param array<string,TenantSchemaTableInventory> $tables
     * @param array<string,TenantDataDefinition> $definitions
     * @return list<string>
     */
    public function issues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
        array $tables,
        array $definitions,
    ): array {
        if (!in_array($definition->policy, [
            TenantDataPolicy::TenantRoot,
            TenantDataPolicy::TenantOwned,
            TenantDataPolicy::TenantOwnedIndirect,
            TenantDataPolicy::TenantRelation,
            TenantDataPolicy::PersonalSecretAttachment,
        ], true)) {
            return [];
        }

        $raw = $definition->details['instance_references'] ?? [];
        if (!is_array($raw) || (array_is_list($raw) && $raw !== [])) {
            return ['invalid_instance_reference_registry:' . $table->name];
        }

        $actual = [];
        foreach ($table->foreignKeys as $foreignKey) {
            $target = $definitions[$foreignKey->referencedTable] ?? null;
            if ($target === null
                || $target->policy !== TenantDataPolicy::InstanceOwned
                || $foreignKey->referencedTable === 'users'
            ) {
                continue;
            }
            $actual[$foreignKey->column] = $foreignKey;
        }

        $issues = [];
        foreach ($actual as $column => $foreignKey) {
            $declaration = $raw[$column] ?? null;
            if (!is_array($declaration) || array_is_list($declaration)) {
                $issues[] = 'instance_reference_policy_missing:'
                    . $table->name . '.' . $column;
                continue;
            }
            array_push(
                $issues,
                ...$this->declarationIssues(
                    $table,
                    $foreignKey,
                    $declaration,
                    $tables,
                ),
            );
        }

        foreach ($raw as $column => $declaration) {
            if (!is_string($column)
                || !is_array($declaration)
                || array_is_list($declaration)
            ) {
                $issues[] = 'invalid_instance_reference_registry:'
                    . $table->name;
                continue;
            }
            if (!isset($actual[$column])) {
                $issues[] = 'instance_reference_fk_missing:'
                    . $table->name . '.' . $column;
            }
        }

        sort($issues, SORT_STRING);
        return array_values(array_unique($issues));
    }

    /**
     * @param array<mixed> $declaration
     * @param array<string,TenantSchemaTableInventory> $tables
     * @return list<string>
     */
    private function declarationIssues(
        TenantSchemaTableInventory $source,
        TenantSchemaForeignKeyInventory $foreignKey,
        array $declaration,
        array $tables,
    ): array {
        $prefix = $source->name . '.' . $foreignKey->column;
        $issues = [];
        if (($declaration['strategy'] ?? null)
            !== 'map_existing_by_natural_key_or_explicit'
        ) {
            $issues[] = 'invalid_instance_reference_strategy:' . $prefix;
        }

        $target = $tables[$foreignKey->referencedTable] ?? null;
        if ($target === null) {
            return [
                ...$issues,
                'instance_reference_target_missing:'
                    . $prefix . '->' . $foreignKey->referencedTable,
            ];
        }

        $naturalKey = $this->stringList(
            $declaration['natural_key'] ?? null,
        );
        if ($naturalKey === null) {
            $issues[] = 'invalid_instance_reference_natural_key:' . $prefix;
        } else {
            foreach ($naturalKey as $column) {
                if (!in_array($column, $target->columns, true)) {
                    $issues[] =
                        'instance_reference_natural_key_column_missing:'
                            . $prefix . '->' . $target->name . '.' . $column;
                }
            }
            if (!in_array($naturalKey, $target->uniqueKeys, true)) {
                $issues[] = 'instance_reference_natural_key_not_unique:'
                    . $prefix;
            }
        }
        if (($declaration['natural_key_null'] ?? null)
            !== 'require_explicit_mapping'
        ) {
            $issues[] = 'invalid_instance_reference_natural_key_null:'
                . $prefix;
        }

        array_push(
            $issues,
            ...$this->valueIssues($prefix, $declaration, $target),
        );

        $expectedNull = in_array(
            $foreignKey->column,
            $source->nullableColumns,
            true,
        ) ? 'preserve' : 'forbid';
        if (($declaration['source_null'] ?? null) !== $expectedNull) {
            $issues[] = 'instance_reference_null_policy_mismatch:'
                . $prefix;
        }
        if (($declaration['missing'] ?? null) !== 'block') {
            $issues[] = 'instance_reference_missing_not_blocked:' . $prefix;
        }
        if (($declaration['ambiguous'] ?? null) !== 'block') {
            $issues[] = 'instance_reference_ambiguous_not_blocked:'
                . $prefix;
        }
        return $issues;
    }

    /**
     * @param array<mixed> $declaration
     * @return list<string>
     */
    private function valueIssues(
        string $prefix,
        array $declaration,
        TenantSchemaTableInventory $target,
    ): array {
        $values = $declaration['values'] ?? null;
        $columns = is_array($values)
            ? $this->stringList($values['columns'] ?? null)
            : null;
        if (!is_array($values)
            || array_is_list($values)
            || ($values['strategy'] ?? null) !== 'require_equal'
            || $columns === null
        ) {
            return ['invalid_instance_reference_value_policy:' . $prefix];
        }

        $issues = [];
        foreach ($columns as $column) {
            if (!in_array($column, $target->columns, true)) {
                $issues[] = 'instance_reference_value_column_missing:'
                    . $prefix . '->' . $target->name . '.' . $column;
            }
        }
        return $issues;
    }

    /** @return list<string>|null */
    private function stringList(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            return null;
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || isset($result[$item])) {
                return null;
            }
            $result[$item] = true;
        }
        return array_keys($result);
    }
}
