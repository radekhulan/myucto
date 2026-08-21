<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;

/** Fail-closed kontrola kódových vazeb do číselníku stejného tenanta. */
final class TenantDataNaturalKeyReferenceCoverageValidator
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
        $raw = $definition->details['natural_key_references'] ?? [];
        if (!is_array($raw) || (array_is_list($raw) && $raw !== [])) {
            return [
                'invalid_natural_key_reference_registry:' . $table->name,
            ];
        }

        $issues = [];
        foreach ($raw as $name => $reference) {
            $prefix = $table->name . '.' . (string) $name;
            if (!is_string($name)
                || !$this->isIdentifier($name)
                || !is_array($reference)
                || array_is_list($reference)
                || ($reference['strategy'] ?? null)
                    !== 'tenant_natural_key'
            ) {
                $issues[] = 'invalid_natural_key_reference:' . $prefix;
                continue;
            }

            $sourceScope = $reference['source_scope_column'] ?? null;
            $inheritedScope = $reference['source_scope'] ?? null;
            $sourceColumns = $this->identifierList(
                $reference['source_columns'] ?? null,
            );
            $targetTable = $reference['target_table'] ?? null;
            $targetScope = $reference['target_scope_column'] ?? null;
            $targetColumns = $this->identifierList(
                $reference['target_columns'] ?? null,
            );
            $usesDirectScope = is_string($sourceScope)
                && $this->isIdentifier($sourceScope)
                && !array_key_exists('source_scope', $reference);
            $usesInheritedScope = !array_key_exists(
                'source_scope_column',
                $reference,
            ) && $inheritedScope === [
                'strategy' => 'ownership_tenant',
            ];
            if ((!$usesDirectScope && !$usesInheritedScope)
                || !is_string($targetTable)
                || !$this->isIdentifier($targetTable)
                || !is_string($targetScope)
                || !$this->isIdentifier($targetScope)
                || $sourceColumns === null
                || $targetColumns === null
                || count($sourceColumns) !== count($targetColumns)
            ) {
                $issues[] = 'invalid_natural_key_reference:' . $prefix;
                continue;
            }

            if ($usesDirectScope
                && !in_array($sourceScope, $table->columns, true)
            ) {
                $issues[] = 'natural_key_reference_source_column_missing:'
                    . $prefix . '.' . $sourceScope;
            }
            if (($usesDirectScope
                    && $this->ownershipColumn($definition) !== $sourceScope)
                || ($usesInheritedScope
                    && !$this->hasTenantOwnershipPath($definition))
            ) {
                $issues[] = 'natural_key_reference_source_scope_mismatch:'
                    . $prefix;
            }
            $sourceHasNullableColumn = false;
            foreach ($sourceColumns as $sourceColumn) {
                if (!in_array($sourceColumn, $table->columns, true)) {
                    $issues[] = 'natural_key_reference_source_column_missing:'
                        . $prefix . '.' . $sourceColumn;
                    continue;
                }
                $sourceHasNullableColumn = $sourceHasNullableColumn
                    || in_array(
                        $sourceColumn,
                        $table->nullableColumns,
                        true,
                    );
            }
            $expectedNullPolicy = $sourceHasNullableColumn
                ? 'preserve'
                : 'forbid';
            if (($reference['null_value'] ?? null) !== $expectedNullPolicy) {
                $issues[] = 'natural_key_reference_null_policy_mismatch:'
                    . $prefix;
            }
            if (($reference['unresolved'] ?? null) !== 'block') {
                $issues[] = 'natural_key_reference_unresolved_not_blocked:'
                    . $prefix;
            }

            $targetInventory = $tables[$targetTable] ?? null;
            $targetDefinition = $definitions[$targetTable] ?? null;
            $targetPrefix = $prefix . '->' . $targetTable;
            if ($targetInventory === null || $targetDefinition === null) {
                $issues[] = 'natural_key_reference_target_unregistered:'
                    . $targetPrefix;
                continue;
            }
            if ($targetDefinition->policy !== TenantDataPolicy::TenantOwned) {
                $issues[] = 'natural_key_reference_target_not_transferable:'
                    . $targetPrefix;
            }
            if ($this->ownershipColumn($targetDefinition) !== $targetScope) {
                $issues[] = 'natural_key_reference_target_scope_mismatch:'
                    . $targetPrefix;
            }

            $targetKey = [$targetScope, ...$targetColumns];
            foreach ($targetKey as $targetColumn) {
                if (!in_array($targetColumn, $targetInventory->columns, true)) {
                    $issues[] = 'natural_key_reference_target_column_missing:'
                        . $targetPrefix . '.' . $targetColumn;
                    continue;
                }
                if (in_array(
                    $targetColumn,
                    $targetInventory->nullableColumns,
                    true,
                )) {
                    $issues[] = 'natural_key_reference_target_nullable:'
                        . $targetPrefix . '.' . $targetColumn;
                }
            }
            if (!in_array($targetKey, $targetInventory->uniqueKeys, true)) {
                $issues[] = 'natural_key_reference_target_not_unique:'
                    . $targetPrefix;
            }
            if (!$this->hasMatchingImportIdentity(
                $targetDefinition,
                $targetKey,
            )) {
                $issues[] = 'natural_key_reference_target_identity_mismatch:'
                    . $targetPrefix;
            }
        }

        $issues = array_values(array_unique($issues, SORT_STRING));
        sort($issues, SORT_STRING);
        return $issues;
    }

    /** @return list<string>|null */
    private function identifierList(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            return null;
        }
        $result = [];
        foreach ($value as $identifier) {
            if (!is_string($identifier)
                || !$this->isIdentifier($identifier)
                || in_array($identifier, $result, true)
            ) {
                return null;
            }
            $result[] = $identifier;
        }
        return $result;
    }

    private function ownershipColumn(
        TenantDataDefinition $definition,
    ): ?string {
        $ownership = $definition->details['ownership'] ?? null;
        if (!is_array($ownership)
            || !in_array(
                $ownership['strategy'] ?? null,
                ['supplier_id', 'supplier_relation'],
                true,
            )
        ) {
            return null;
        }
        $column = $ownership['column'] ?? null;
        return is_string($column) ? $column : null;
    }

    private function hasTenantOwnershipPath(
        TenantDataDefinition $definition,
    ): bool {
        if ($definition->policy !== TenantDataPolicy::TenantOwnedIndirect) {
            return false;
        }
        $ownership = $definition->details['ownership'] ?? null;
        $path = is_array($ownership) ? ($ownership['path'] ?? null) : null;
        if (!is_array($ownership)
            || !in_array(
                $ownership['strategy'] ?? null,
                ['foreign_key_path', 'soft_reference_path'],
                true,
            )
            || !is_array($path)
            || !array_is_list($path)
            || $path === []
        ) {
            return false;
        }
        $root = $path[array_key_last($path)];
        return is_array($root)
            && !array_is_list($root)
            && ($root['to_table'] ?? null) === 'supplier'
            && ($root['to_column'] ?? null) === 'id';
    }

    /** @param list<string> $targetKey */
    private function hasMatchingImportIdentity(
        TenantDataDefinition $definition,
        array $targetKey,
    ): bool {
        $identity = $definition->details['import_identity'] ?? null;
        return is_array($identity)
            && !array_is_list($identity)
            && ($identity['strategy'] ?? null) === 'tenant_natural_key'
            && ($identity['keys'] ?? null) === $targetKey
            && ($identity['missing_row'] ?? null)
                === 'create_with_mapped_tenant'
            && ($identity['existing_row'] ?? null)
                === 'reuse_target_id_and_apply_source';
    }

    private function isIdentifier(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) === 1;
    }
}
