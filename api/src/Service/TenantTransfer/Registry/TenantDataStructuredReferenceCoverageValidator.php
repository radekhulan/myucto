<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;

/** Fail-closed kontrola tenantových ID uložených uvnitř JSON či textu. */
final class TenantDataStructuredReferenceCoverageValidator
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
        $raw = $definition->details['structured_references'] ?? [];
        if (!is_array($raw) || (array_is_list($raw) && $raw !== [])) {
            return [
                'invalid_structured_reference_registry:' . $table->name,
            ];
        }

        $issues = [];
        foreach ($raw as $name => $reference) {
            $prefix = $table->name . '.' . (string) $name;
            if (!is_string($name)
                || !$this->isIdentifier($name)
                || !is_array($reference)
                || array_is_list($reference)
            ) {
                $issues[] = 'invalid_structured_reference:' . $prefix;
                continue;
            }

            $column = $reference['column'] ?? null;
            $strategy = $reference['strategy'] ?? null;
            if (!is_string($column)
                || !$this->isIdentifier($column)
                || !is_string($strategy)
                || !in_array(
                    $strategy,
                    ['json_id_list', 'tagged_decimal_id'],
                    true,
                )
            ) {
                $issues[] = 'invalid_structured_reference:' . $prefix;
                continue;
            }

            array_push(
                $issues,
                ...$this->sourcePolicyIssues(
                    $prefix,
                    $column,
                    $reference,
                    $table,
                ),
            );

            if ($strategy === 'json_id_list') {
                array_push(
                    $issues,
                    ...$this->targetIssues(
                        $prefix,
                        $reference,
                        $tables,
                        $definitions,
                    ),
                );
                continue;
            }

            if (($reference['unmatched_value'] ?? null) !== 'preserve') {
                $issues[] = 'structured_reference_unmatched_value_not_preserved:'
                    . $prefix;
            }
            if (($reference['unknown_tag'] ?? null) !== 'block') {
                $issues[] = 'structured_reference_unknown_tag_not_blocked:'
                    . $prefix;
            }
            if (($reference['tag_matching'] ?? null) !== 'longest_prefix') {
                $issues[] =
                    'structured_reference_tag_matching_not_deterministic:'
                        . $prefix;
            }
            $targets = $reference['targets'] ?? null;
            if (!is_array($targets)
                || $targets === []
                || array_is_list($targets)
            ) {
                $issues[] = 'invalid_structured_reference_target:' . $prefix;
                continue;
            }
            foreach ($targets as $tag => $target) {
                if (!is_string($tag)
                    || preg_match(
                        '/^[a-z][a-z0-9_]{0,31}:#?$/D',
                        $tag,
                    ) !== 1
                    || !is_array($target)
                    || array_is_list($target)
                ) {
                    $issues[] = 'invalid_structured_reference_target:'
                        . $prefix;
                    continue;
                }
                array_push(
                    $issues,
                    ...$this->targetIssues(
                        $prefix,
                        $target,
                        $tables,
                        $definitions,
                    ),
                );
            }
        }

        $issues = array_values(array_unique($issues, SORT_STRING));
        sort($issues, SORT_STRING);
        return $issues;
    }

    /**
     * @param array<mixed> $reference
     * @return list<string>
     */
    private function sourcePolicyIssues(
        string $prefix,
        string $column,
        array $reference,
        TenantSchemaTableInventory $table,
    ): array {
        if (!in_array($column, $table->columns, true)) {
            return [
                'structured_reference_column_missing:'
                    . $prefix . '.' . $column,
            ];
        }

        $issues = [];
        $expectedNullPolicy = in_array(
            $column,
            $table->nullableColumns,
            true,
        ) ? 'preserve' : 'forbid';
        if (($reference['null_value'] ?? null) !== $expectedNullPolicy) {
            $issues[] = 'structured_reference_null_policy_mismatch:'
                . $prefix;
        }
        if (($reference['invalid_value'] ?? null) !== 'block') {
            $issues[] = 'structured_reference_invalid_value_not_blocked:'
                . $prefix;
        }
        if (($reference['unresolved'] ?? null) !== 'block') {
            $issues[] = 'structured_reference_unresolved_not_blocked:'
                . $prefix;
        }
        return $issues;
    }

    /**
     * @param array<mixed> $target
     * @param array<string,TenantSchemaTableInventory> $tables
     * @param array<string,TenantDataDefinition> $definitions
     * @return list<string>
     */
    private function targetIssues(
        string $prefix,
        array $target,
        array $tables,
        array $definitions,
    ): array {
        $targetTable = $target['target_table'] ?? null;
        $targetColumn = $target['target_column'] ?? null;
        if (!is_string($targetTable)
            || !$this->isIdentifier($targetTable)
            || !is_string($targetColumn)
            || !$this->isIdentifier($targetColumn)
        ) {
            return ['invalid_structured_reference_target:' . $prefix];
        }

        $targetPrefix = $prefix . '->'
            . $targetTable . '.' . $targetColumn;
        $targetInventory = $tables[$targetTable] ?? null;
        $targetDefinition = $definitions[$targetTable] ?? null;
        if ($targetInventory === null || $targetDefinition === null) {
            return [
                'structured_reference_target_unregistered:' . $targetPrefix,
            ];
        }

        $issues = [];
        if (!$this->isTransferable($targetDefinition)) {
            $issues[] = 'structured_reference_target_not_transferable:'
                . $targetPrefix;
        }
        if (!in_array($targetColumn, $targetInventory->columns, true)) {
            $issues[] = 'structured_reference_target_column_missing:'
                . $targetPrefix;
        }
        if ($targetInventory->primaryKey !== [$targetColumn]) {
            $issues[] = 'structured_reference_target_not_primary:'
                . $targetPrefix;
        }
        return $issues;
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
