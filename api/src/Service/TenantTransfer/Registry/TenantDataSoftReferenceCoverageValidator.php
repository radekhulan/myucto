<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;

/** Fail-closed kontrola tenantových referencí bez fyzického cizího klíče. */
final class TenantDataSoftReferenceCoverageValidator
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
        $raw = $definition->details['soft_references'] ?? [];
        if (!is_array($raw) || (array_is_list($raw) && $raw !== [])) {
            return ['invalid_soft_reference_registry:' . $table->name];
        }

        $issues = [];
        foreach ($raw as $name => $reference) {
            if (!is_string($name)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) !== 1
                || !is_array($reference)
                || array_is_list($reference)
            ) {
                $issues[] = 'invalid_soft_reference:'
                    . $table->name . '.' . (string) $name;
                continue;
            }

            $strategy = $reference['strategy'] ?? null;
            if ($strategy === 'polymorphic_tenant_entity') {
                array_push(
                    $issues,
                    ...$this->polymorphicIssues(
                        $name,
                        $reference,
                        $table,
                        $tables,
                        $definitions,
                    ),
                );
                continue;
            }
            if ($strategy === 'direct_tenant_entity') {
                array_push(
                    $issues,
                    ...$this->directIssues(
                        $name,
                        $reference,
                        $table,
                        $tables,
                        $definitions,
                    ),
                );
                continue;
            }
            if ($strategy === 'runtime_derived_entity') {
                array_push(
                    $issues,
                    ...$this->runtimeDerivedIssues(
                        $name,
                        $reference,
                        $table,
                        $tables,
                        $definitions,
                    ),
                );
                continue;
            }
            $issues[] = 'invalid_soft_reference:'
                . $table->name . '.' . $name;
        }

        sort($issues, SORT_STRING);
        return $issues;
    }

    /**
     * @param array<mixed> $reference
     * @param array<string,TenantSchemaTableInventory> $tables
     * @param array<string,TenantDataDefinition> $definitions
     * @return list<string>
     */
    private function runtimeDerivedIssues(
        string $name,
        array $reference,
        TenantSchemaTableInventory $table,
        array $tables,
        array $definitions,
    ): array {
        $idColumn = $reference['id_column'] ?? null;
        $targetTable = $reference['target_table'] ?? null;
        $targetColumn = $reference['target_column'] ?? null;
        if (!is_string($idColumn)
            || !is_string($targetTable)
            || !is_string($targetColumn)
            || !$this->isIdentifier($idColumn)
            || !$this->isIdentifier($targetTable)
            || !$this->isIdentifier($targetColumn)
        ) {
            return ['invalid_soft_reference:' . $table->name . '.' . $name];
        }

        $issues = [];
        $sourceColumnExists = in_array($idColumn, $table->columns, true);
        if (!$sourceColumnExists) {
            $issues[] = 'soft_reference_column_missing:'
                . $table->name . '.' . $idColumn;
        } elseif ($this->hasForeignKey($table, $idColumn)) {
            $issues[] = 'soft_reference_has_fk:'
                . $table->name . '.' . $idColumn;
        }
        if ($sourceColumnExists) {
            if (!in_array($idColumn, $table->nullableColumns, true)) {
                $issues[] = 'soft_reference_runtime_target_not_nullable:'
                    . $table->name . '.' . $name;
            }
            if (($reference['null_value'] ?? null) !== 'preserve') {
                $issues[] = 'soft_reference_null_policy_mismatch:'
                    . $table->name . '.' . $name;
            }
        }
        if (($reference['target_omitted'] ?? null) !== 'set_null') {
            $issues[] = 'soft_reference_omitted_value_not_nullified:'
                . $table->name . '.' . $name;
        }

        $targetInventory = $tables[$targetTable] ?? null;
        $targetDefinition = $definitions[$targetTable] ?? null;
        $target = $table->name . '.' . $name . '->'
            . $targetTable . '.' . $targetColumn;
        if ($targetInventory === null || $targetDefinition === null) {
            $issues[] = 'soft_reference_target_unregistered:' . $target;
            return $issues;
        }
        if ($targetDefinition->policy !== TenantDataPolicy::RuntimeDerived) {
            $issues[] = 'soft_reference_target_not_runtime_derived:' . $target;
        }
        if (!in_array($targetColumn, $targetInventory->columns, true)) {
            $issues[] = 'soft_reference_target_column_missing:' . $target;
        }
        if ($targetInventory->primaryKey !== [$targetColumn]) {
            $issues[] = 'soft_reference_target_not_primary:' . $target;
        }
        return $issues;
    }

    /**
     * @param array<mixed> $reference
     * @param array<string,TenantSchemaTableInventory> $tables
     * @param array<string,TenantDataDefinition> $definitions
     * @return list<string>
     */
    private function polymorphicIssues(
        string $name,
        array $reference,
        TenantSchemaTableInventory $table,
        array $tables,
        array $definitions,
    ): array {
        $typeColumn = $reference['type_column'] ?? null;
        $idColumn = $reference['id_column'] ?? null;
        $targets = $reference['targets'] ?? null;
        if (!is_string($typeColumn)
            || !is_string($idColumn)
            || !is_array($targets)
            || array_is_list($targets)
        ) {
            return ['invalid_soft_reference:' . $table->name . '.' . $name];
        }

        $issues = [];
        foreach ([$typeColumn, $idColumn] as $column) {
            if (!in_array($column, $table->columns, true)) {
                $issues[] = 'soft_reference_column_missing:'
                    . $table->name . '.' . $column;
            }
        }
        if ($this->hasForeignKey($table, $idColumn)) {
            $issues[] = 'soft_reference_has_fk:'
                . $table->name . '.' . $idColumn;
        }
        if (($reference['unknown_value'] ?? null) !== 'block') {
            $issues[] = 'soft_reference_unknown_value_not_blocked:'
                . $table->name . '.' . $name;
        }

        $targetTypes = [];
        $targetMapValid = true;
        foreach ($targets as $type => $targetTable) {
            if (!is_string($type)
                || !is_string($targetTable)
                || !$this->isIdentifier($type)
                || !$this->isIdentifier($targetTable)
            ) {
                $targetMapValid = false;
                $issues[] = 'invalid_soft_reference_target:'
                    . $table->name . '.' . $name;
                continue;
            }
            $targetTypes[] = $type;
            $targetDefinition = $definitions[$targetTable] ?? null;
            if (!isset($tables[$targetTable])
                || $targetDefinition === null
            ) {
                $issues[] = 'soft_reference_target_unregistered:'
                    . $table->name . '.' . $name . '.' . $type
                    . '->' . $targetTable;
                continue;
            }
            if (!$this->isTransferable($targetDefinition)) {
                $issues[] = 'soft_reference_target_not_transferable:'
                    . $table->name . '.' . $name . '.' . $type
                    . '->' . $targetTable;
            }
        }

        $enumValues = $table->enumValues[$typeColumn] ?? null;
        if ($targetMapValid && $enumValues !== null) {
            sort($targetTypes, SORT_STRING);
            sort($enumValues, SORT_STRING);
            if ($targetTypes !== $enumValues) {
                $issues[] = 'soft_reference_target_map_mismatch:'
                    . $table->name . '.' . $name;
            }
        }
        return $issues;
    }

    /**
     * @param array<mixed> $reference
     * @param array<string,TenantSchemaTableInventory> $tables
     * @param array<string,TenantDataDefinition> $definitions
     * @return list<string>
     */
    private function directIssues(
        string $name,
        array $reference,
        TenantSchemaTableInventory $table,
        array $tables,
        array $definitions,
    ): array {
        $idColumn = $reference['id_column'] ?? null;
        $targetTable = $reference['target_table'] ?? null;
        $targetColumn = $reference['target_column'] ?? null;
        if (!is_string($idColumn)
            || !is_string($targetTable)
            || !is_string($targetColumn)
            || !$this->isIdentifier($idColumn)
            || !$this->isIdentifier($targetTable)
            || !$this->isIdentifier($targetColumn)
        ) {
            return ['invalid_soft_reference:' . $table->name . '.' . $name];
        }

        $issues = [];
        $sourceColumnExists = in_array($idColumn, $table->columns, true);
        if (!$sourceColumnExists) {
            $issues[] = 'soft_reference_column_missing:'
                . $table->name . '.' . $idColumn;
        } elseif ($this->hasForeignKey($table, $idColumn)) {
            $issues[] = 'soft_reference_has_fk:'
                . $table->name . '.' . $idColumn;
        }
        if ($sourceColumnExists) {
            $expectedNullPolicy = in_array(
                $idColumn,
                $table->nullableColumns,
                true,
            ) ? 'preserve' : 'forbid';
            if (($reference['null_value'] ?? null) !== $expectedNullPolicy) {
                $issues[] = 'soft_reference_null_policy_mismatch:'
                    . $table->name . '.' . $name;
            }
        }
        if (($reference['unresolved'] ?? null) !== 'block') {
            $issues[] = 'soft_reference_unresolved_not_blocked:'
                . $table->name . '.' . $name;
        }

        $targetInventory = $tables[$targetTable] ?? null;
        $targetDefinition = $definitions[$targetTable] ?? null;
        $target = $table->name . '.' . $name . '->'
            . $targetTable . '.' . $targetColumn;
        if ($targetInventory === null || $targetDefinition === null) {
            $issues[] = 'soft_reference_target_unregistered:' . $target;
            return $issues;
        }
        if (!$this->isTransferable($targetDefinition)) {
            $issues[] = 'soft_reference_target_not_transferable:' . $target;
        }
        if (!in_array($targetColumn, $targetInventory->columns, true)) {
            $issues[] = 'soft_reference_target_column_missing:' . $target;
        }
        if ($targetInventory->primaryKey !== [$targetColumn]) {
            $issues[] = 'soft_reference_target_not_primary:' . $target;
        }
        return $issues;
    }

    private function hasForeignKey(
        TenantSchemaTableInventory $table,
        string $column,
    ): bool {
        foreach ($table->foreignKeys as $foreignKey) {
            if ($foreignKey->column === $column) {
                return true;
            }
        }
        return false;
    }

    private function isIdentifier(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) === 1;
    }

    private function isTransferable(
        TenantDataDefinition $definition,
    ): bool {
        return in_array($definition->policy, [
            TenantDataPolicy::TenantRoot,
            TenantDataPolicy::TenantOwned,
            TenantDataPolicy::TenantOwnedIndirect,
            TenantDataPolicy::TenantRelation,
        ], true);
    }
}
