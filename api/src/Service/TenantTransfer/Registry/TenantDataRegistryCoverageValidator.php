<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;

/** Fail-closed kontrola úplnosti tabulek a politik citlivých sloupců. */
final class TenantDataRegistryCoverageValidator
{
    private readonly TenantDataFileAreaCoverageValidator $fileAreas;
    private readonly TenantDataSoftReferenceCoverageValidator $softReferences;
    private readonly TenantDataConditionalActorCoverageValidator
        $conditionalActors;
    private readonly TenantDataMixedOwnershipCoverageValidator $mixedOwnership;
    private readonly TenantDataCodeReferenceCoverageValidator $codeReferences;
    private readonly TenantDataNaturalKeyReferenceCoverageValidator
        $naturalKeyReferences;
    private readonly TenantDataStructuredReferenceCoverageValidator
        $structuredReferences;
    private readonly TenantDataInstanceReferenceCoverageValidator
        $instanceReferences;
    private readonly TenantDataRelationCoverageValidator $relations;

    public function __construct(
        ?TenantDataFileAreaCoverageValidator $fileAreas = null,
        ?TenantDataSoftReferenceCoverageValidator $softReferences = null,
        ?TenantDataConditionalActorCoverageValidator $conditionalActors = null,
        ?TenantDataMixedOwnershipCoverageValidator $mixedOwnership = null,
        ?TenantDataCodeReferenceCoverageValidator $codeReferences = null,
        ?TenantDataNaturalKeyReferenceCoverageValidator
            $naturalKeyReferences = null,
        ?TenantDataStructuredReferenceCoverageValidator
            $structuredReferences = null,
        ?TenantDataInstanceReferenceCoverageValidator $instanceReferences = null,
        ?TenantDataRelationCoverageValidator $relations = null,
    ) {
        $this->fileAreas = $fileAreas
            ?? new TenantDataFileAreaCoverageValidator();
        $this->softReferences = $softReferences
            ?? new TenantDataSoftReferenceCoverageValidator();
        $this->conditionalActors = $conditionalActors
            ?? new TenantDataConditionalActorCoverageValidator();
        $this->mixedOwnership = $mixedOwnership
            ?? new TenantDataMixedOwnershipCoverageValidator();
        $this->codeReferences = $codeReferences
            ?? new TenantDataCodeReferenceCoverageValidator();
        $this->naturalKeyReferences = $naturalKeyReferences
            ?? new TenantDataNaturalKeyReferenceCoverageValidator();
        $this->structuredReferences = $structuredReferences
            ?? new TenantDataStructuredReferenceCoverageValidator();
        $this->instanceReferences = $instanceReferences
            ?? new TenantDataInstanceReferenceCoverageValidator();
        $this->relations = $relations
            ?? new TenantDataRelationCoverageValidator();
    }

    /** @param array<mixed> $inventory */
    public function assertComplete(
        TenantDataRegistry $registry,
        array $inventory,
        string $profile = TenantDataRegistry::TRANSFER_PROFILE,
    ): void {
        if (!$registry->isComplete($profile)) {
            throw new IncompleteTenantDataRegistry(
                'Coverage nelze ověřit pro neúplný tenantový profil.',
            );
        }

        $issues = $this->issues($registry, $inventory, $profile);
        if ($issues !== []) {
            throw new IncompleteTenantDataRegistryCoverage($issues);
        }
    }

    /**
     * Bezpečný report použitelný i během postupného sestavování draft registru.
     *
     * @param array<mixed> $inventory
     * @return list<string>
     */
    public function issues(
        TenantDataRegistry $registry,
        array $inventory,
        string $profile = TenantDataRegistry::TRANSFER_PROFILE,
    ): array {

        $tables = $this->validatedInventory($inventory);
        $definitions = [];
        $fileAreas = [];
        foreach ($registry->definitionsFor($profile) as $definition) {
            if ($definition->kind === TenantDataObjectKind::FileArea) {
                $fileAreas[] = $definition;
                continue;
            }
            if (!str_starts_with($definition->key, 'table:')) {
                throw new \InvalidArgumentException(
                    'Tabulková definice tenantového registru nemá prefix table:.',
                );
            }
            $tableName = substr($definition->key, strlen('table:'));
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $tableName) !== 1) {
                throw new \InvalidArgumentException(
                    'Tenantový registr obsahuje neplatný název tabulky.',
                );
            }
            $definitions[$tableName] = $definition;
        }

        $issues = [];
        foreach ($tables as $tableName => $table) {
            $definition = $definitions[$tableName] ?? null;
            if ($definition === null) {
                $issues[] = 'unregistered_table:' . $tableName;
                continue;
            }
            array_push(
                $issues,
                ...$this->primaryKeyIssues($definition, $table),
                ...$this->policyCoverageIssues(
                    $definition,
                    $table,
                    $tables,
                    $definitions,
                ),
                ...$this->secretCoverageIssues($definition, $table),
                ...$this->referenceCoverageIssues(
                    $definition,
                    $table,
                    $definitions,
                ),
                ...$this->softReferences->issues(
                    $definition,
                    $table,
                    $tables,
                    $definitions,
                ),
                ...$this->codeReferences->issues($definition, $table),
                ...$this->naturalKeyReferences->issues(
                    $definition,
                    $table,
                    $tables,
                    $definitions,
                ),
                ...$this->structuredReferences->issues(
                    $definition,
                    $table,
                    $tables,
                    $definitions,
                ),
                ...$this->conditionalActors->issues($definition, $table),
                ...$this->instanceReferences->issues(
                    $definition,
                    $table,
                    $tables,
                    $definitions,
                ),
                ...$this->relations->issues($definition, $table),
            );
        }
        foreach (array_diff(array_keys($definitions), array_keys($tables)) as $tableName) {
            $issues[] = 'registered_table_missing:' . $tableName;
        }
        foreach ($fileAreas as $fileArea) {
            array_push(
                $issues,
                ...$this->fileAreas->issues(
                    $fileArea,
                    $tables,
                    $definitions,
                ),
            );
        }

        sort($issues, SORT_STRING);
        return $issues;
    }

    /** @return list<string> */
    private function primaryKeyIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
    ): array {
        $primaryKey = $definition->details['primary_key'] ?? null;
        if (!is_array($primaryKey)
            || !array_is_list($primaryKey)
            || $primaryKey !== $table->primaryKey
        ) {
            return ['primary_key_mismatch:' . $table->name];
        }
        return [];
    }

    /**
     * @param array<mixed> $inventory
     * @return array<string,TenantSchemaTableInventory>
     */
    private function validatedInventory(array $inventory): array
    {
        if (!array_is_list($inventory) || $inventory === []) {
            throw new \InvalidArgumentException('Inventura databázového schématu je prázdná nebo neplatná.');
        }
        $tables = [];
        foreach ($inventory as $table) {
            if (!$table instanceof TenantSchemaTableInventory) {
                throw new \InvalidArgumentException('Inventura databázového schématu obsahuje neplatnou tabulku.');
            }
            if (isset($tables[$table->name])) {
                throw new \InvalidArgumentException('Inventura databázového schématu obsahuje duplicitní tabulku.');
            }
            $tables[$table->name] = $table;
        }
        ksort($tables, SORT_STRING);
        return $tables;
    }

    /**
     * @param array<string,TenantSchemaTableInventory> $tables
     * @param array<string,TenantDataDefinition> $definitions
     * @return list<string>
     */
    private function policyCoverageIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
        array $tables,
        array $definitions,
    ): array {
        return match ($definition->policy) {
            TenantDataPolicy::TenantRoot => $this->directOwnershipIssues(
                $definition,
                $table,
                'selected_supplier',
                'id',
            ),
            TenantDataPolicy::TenantOwned => $this->tenantOwnedIssues(
                $definition,
                $table,
            ),
            TenantDataPolicy::TenantOwnedIndirect => $this->indirectOwnershipIssues(
                $definition,
                $table,
                $tables,
            ),
            TenantDataPolicy::TenantRelation => $this->directOwnershipIssues(
                $definition,
                $table,
                'supplier_relation',
                'supplier_id',
            ),
            TenantDataPolicy::GlobalReference => $this->globalReferenceIssues(
                $definition,
                $table,
            ),
            TenantDataPolicy::InstanceOwned,
            TenantDataPolicy::RuntimeDerived,
            TenantDataPolicy::Unsupported => $this->reasonIssues($definition, $table),
            TenantDataPolicy::PersonalSecretAttachment => $this->personalSecretIssues(
                $definition,
                $table,
                $tables,
                $definitions,
            ),
        };
    }

    /** @return list<string> */
    private function tenantOwnedIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
    ): array {
        $ownership = $definition->details['ownership'] ?? null;
        if (is_array($ownership)
            && ($ownership['strategy'] ?? null)
                === 'supplier_id_or_global_reference'
        ) {
            return $this->mixedOwnership->issues($definition, $table);
        }
        return $this->directOwnershipIssues(
            $definition,
            $table,
            'supplier_id',
            'supplier_id',
        );
    }

    /** @return list<string> */
    private function directOwnershipIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
        string $expectedStrategy,
        string $expectedColumn,
    ): array {
        $ownership = $definition->details['ownership'] ?? null;
        if (!is_array($ownership)
            || ($ownership['strategy'] ?? null) !== $expectedStrategy
            || ($ownership['column'] ?? null) !== $expectedColumn
        ) {
            return ['invalid_ownership_policy:' . $table->name];
        }
        if (!in_array($expectedColumn, $table->columns, true)) {
            return ['ownership_column_missing:' . $table->name . '.' . $expectedColumn];
        }
        return [];
    }

    /**
     * @param array<string,TenantSchemaTableInventory> $tables
     * @return list<string>
     */
    private function indirectOwnershipIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
        array $tables,
    ): array {
        $ownership = $definition->details['ownership'] ?? null;
        $strategy = is_array($ownership) ? ($ownership['strategy'] ?? null) : null;
        if (!is_array($ownership)
            || !in_array(
                $strategy,
                ['foreign_key_path', 'soft_reference_path'],
                true,
            )
        ) {
            return ['invalid_ownership_policy:' . $table->name];
        }
        $path = $ownership['path'] ?? null;
        if (!is_array($path) || !array_is_list($path) || $path === []) {
            return ['invalid_ownership_path:' . $table->name];
        }

        $issues = [];
        $current = $table;
        foreach ($path as $index => $step) {
            if (!is_array($step) || array_is_list($step)) {
                $issues[] = 'invalid_ownership_path:' . $table->name;
                break;
            }
            $fromColumn = $step['from_column'] ?? null;
            $toTable = $step['to_table'] ?? null;
            $toColumn = $step['to_column'] ?? null;
            $reference = $step['reference'] ?? (
                $strategy === 'soft_reference_path'
                    ? 'soft'
                    : 'foreign_key'
            );
            if (!is_string($fromColumn)
                || !is_string($toTable)
                || !is_string($toColumn)
                || !is_string($reference)
                || !in_array($reference, ['foreign_key', 'soft'], true)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $fromColumn) !== 1
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $toTable) !== 1
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $toColumn) !== 1
            ) {
                $issues[] = 'invalid_ownership_path:' . $table->name;
                break;
            }
            if (!in_array($fromColumn, $current->columns, true)) {
                $issues[] = 'ownership_path_column_missing:'
                    . $current->name . '.' . $fromColumn;
                break;
            }
            if ($reference === 'foreign_key'
                && !$this->hasForeignKey(
                    $current,
                    $fromColumn,
                    $toTable,
                    $toColumn,
                )
            ) {
                $issues[] = 'ownership_path_fk_missing:'
                    . $current->name . '.' . $fromColumn
                    . '->' . $toTable . '.' . $toColumn;
                break;
            }
            $target = $tables[$toTable] ?? null;
            if ($target === null || !in_array($toColumn, $target->columns, true)) {
                $issues[] = 'ownership_path_target_missing:'
                    . $toTable . '.' . $toColumn;
                break;
            }
            $current = $target;

            if ($index === array_key_last($path)
                && ($toTable !== 'supplier' || $toColumn !== 'id')
            ) {
                $issues[] = 'ownership_path_not_tenant_root:' . $table->name;
            }
        }
        return $issues;
    }

    private function hasForeignKey(
        TenantSchemaTableInventory $table,
        string $column,
        string $referencedTable,
        string $referencedColumn,
    ): bool {
        foreach ($table->foreignKeys as $foreignKey) {
            if ($foreignKey->column === $column
                && $foreignKey->referencedTable === $referencedTable
                && $foreignKey->referencedColumn === $referencedColumn
            ) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function globalReferenceIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
    ): array {
        $mapping = $definition->details['mapping'] ?? null;
        $keys = is_array($mapping) ? ($mapping['keys'] ?? null) : null;
        if (!is_array($mapping)
            || ($mapping['strategy'] ?? null) !== 'natural_key'
            || !is_array($keys)
            || !array_is_list($keys)
            || $keys === []
        ) {
            return ['invalid_global_mapping:' . $table->name];
        }
        foreach ($keys as $key) {
            if (!is_string($key)) {
                return ['invalid_global_mapping_key:' . $table->name];
            }
            if (!in_array($key, $table->columns, true)) {
                return ['global_mapping_column_missing:' . $table->name . '.' . $key];
            }
            if (in_array($key, $table->nullableColumns, true)) {
                return ['global_mapping_nullable_key:' . $table->name . '.' . $key];
            }
        }
        if (!in_array($keys, $table->uniqueKeys, true)) {
            return ['global_mapping_not_unique:' . $table->name];
        }

        $values = $mapping['values'] ?? null;
        $valueColumns = is_array($values) ? ($values['columns'] ?? null) : null;
        if (!is_array($values)
            || ($values['strategy'] ?? null) !== 'require_equal'
            || !is_array($valueColumns)
            || !array_is_list($valueColumns)
            || $valueColumns === []
        ) {
            return ['invalid_global_value_policy:' . $table->name];
        }
        $seenValueColumns = [];
        foreach ($valueColumns as $column) {
            if (!is_string($column)) {
                return ['invalid_global_value_column:' . $table->name];
            }
            if (isset($seenValueColumns[$column])) {
                return ['invalid_global_value_policy:' . $table->name];
            }
            $seenValueColumns[$column] = true;
            if (!in_array($column, $table->columns, true)) {
                return [
                    'global_value_column_missing:'
                        . $table->name . '.' . $column,
                ];
            }
        }
        return [];
    }

    /** @return list<string> */
    private function reasonIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
    ): array {
        $reason = $definition->details['reason'] ?? null;
        if (!is_string($reason)
            || preg_match('/^[a-z][a-z0-9._-]{2,127}$/D', $reason) !== 1
        ) {
            return ['missing_policy_reason:' . $table->name];
        }
        return [];
    }

    /**
     * @param array<string,TenantSchemaTableInventory> $tables
     * @param array<string,TenantDataDefinition> $definitions
     * @return list<string>
     */
    private function personalSecretIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
        array $tables,
        array $definitions,
    ): array {
        $issues = [];
        if (($definition->details['consent'] ?? null)
            !== 'source_and_target_owner'
        ) {
            $issues[] = 'invalid_personal_secret_consent:' . $table->name;
        }
        if (($definition->details['default_selected'] ?? null) !== false) {
            $issues[] = 'personal_secret_default_selected:' . $table->name;
        }

        $ownerColumn = $definition->details['owner_column'] ?? null;
        if (!is_string($ownerColumn)
            || !in_array($ownerColumn, $table->columns, true)
        ) {
            $issues[] = 'invalid_personal_secret_owner:' . $table->name;
        } elseif (in_array($ownerColumn, $table->nullableColumns, true)) {
            $issues[] = 'personal_secret_owner_nullable:'
                . $table->name . '.' . $ownerColumn;
        }

        array_push(
            $issues,
            ...$this->personalSecretDeduplicationIssues(
                $definition,
                $table,
                $ownerColumn,
            ),
        );
        if (!$this->personalSecretSelectorIsValid(
            $definition,
            $table,
            $tables,
            $definitions,
        )) {
            $issues[] = 'invalid_personal_secret_selector:' . $table->name;
        }

        $secrets = $definition->details['secrets'] ?? null;
        if (is_array($secrets)) {
            foreach ($secrets as $column => $declaration) {
                if (!is_string($column)
                    || !TenantSecretColumnDetector::matches($column)
                    || !is_array($declaration)
                    || ($declaration['policy'] ?? null)
                        === TenantSecretPolicy::ReencryptPersonalWithDualConsent
                            ->value
                ) {
                    continue;
                }
                $issues[] = 'personal_secret_policy_mismatch:'
                    . $table->name . '.' . $column;
            }
        }
        return $issues;
    }

    /** @return list<string> */
    private function personalSecretDeduplicationIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
        mixed $ownerColumn,
    ): array {
        $deduplication = $definition->details['deduplication'] ?? null;
        $rawKeys = is_array($deduplication)
            ? ($deduplication['keys'] ?? null)
            : null;
        $keys = $this->uniqueStringList($rawKeys, 2);
        if (!is_array($deduplication)
            || ($deduplication['strategy'] ?? null)
                !== 'target_owner_fingerprint'
            || ($deduplication['active_collision'] ?? null)
                !== 'reuse_without_overwrite'
            || ($deduplication['soft_deleted_collision'] ?? null)
                !== 'require_target_owner_decision'
            || $keys === null
            || !is_string($ownerColumn)
            || $keys[0] !== $ownerColumn
        ) {
            return [
                'invalid_personal_secret_deduplication:' . $table->name,
            ];
        }

        $issues = [];
        foreach ($keys as $key) {
            if (!in_array($key, $table->columns, true)) {
                $issues[] = 'personal_secret_deduplication_column_missing:'
                    . $table->name . '.' . $key;
                continue;
            }
            if (in_array($key, $table->nullableColumns, true)) {
                $issues[] = 'personal_secret_deduplication_nullable_key:'
                    . $table->name . '.' . $key;
            }
        }
        if (!in_array($keys, $table->uniqueKeys, true)) {
            $issues[] = 'personal_secret_deduplication_not_unique:'
                . $table->name;
        }
        return $issues;
    }

    /**
     * @param array<string,TenantSchemaTableInventory> $tables
     * @param array<string,TenantDataDefinition> $definitions
     */
    private function personalSecretSelectorIsValid(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
        array $tables,
        array $definitions,
    ): bool {
        $selector = $definition->details['candidate_selector'] ?? null;
        $references = is_array($selector)
            ? ($selector['references'] ?? null)
            : null;
        if (!is_array($selector)
            || ($selector['strategy'] ?? null) !== 'tenant_reference_union'
            || !is_array($references)
            || !array_is_list($references)
            || $references === []
            || count($table->primaryKey) !== 1
        ) {
            return false;
        }

        $seen = [];
        foreach ($references as $reference) {
            if (!is_array($reference) || array_is_list($reference)) {
                return false;
            }
            $sourceTable = $reference['table'] ?? null;
            $sourceColumn = $reference['column'] ?? null;
            if (!is_string($sourceTable) || !is_string($sourceColumn)) {
                return false;
            }
            $signature = $sourceTable . "\0" . $sourceColumn;
            if (isset($seen[$signature])) {
                return false;
            }
            $seen[$signature] = true;

            $source = $tables[$sourceTable] ?? null;
            $sourceDefinition = $definitions[$sourceTable] ?? null;
            $filters = $reference['filters'] ?? [];
            if (!is_array($filters)) {
                return false;
            }
            if ($source === null
                || $sourceDefinition === null
                || !in_array($sourceDefinition->policy, [
                    TenantDataPolicy::TenantOwned,
                    TenantDataPolicy::TenantOwnedIndirect,
                    TenantDataPolicy::TenantRelation,
                ], true)
                || !in_array($sourceColumn, $source->columns, true)
                || !$this->hasForeignKey(
                    $source,
                    $sourceColumn,
                    $table->name,
                    $table->primaryKey[0],
                )
                || !$this->personalSecretSelectorFiltersAreValid(
                    $filters,
                    $sourceTable,
                    $sourceDefinition,
                    $tables,
                    $definitions,
                )
            ) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string>|null */
    private function uniqueStringList(mixed $value, int $length): ?array
    {
        if (!is_array($value)
            || !array_is_list($value)
            || count($value) !== $length
        ) {
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

    /**
     * @param array<mixed> $filters
     * @param array<string,TenantSchemaTableInventory> $tables
     * @param array<string,TenantDataDefinition> $definitions
     */
    private function personalSecretSelectorFiltersAreValid(
        array $filters,
        string $sourceTable,
        TenantDataDefinition $sourceDefinition,
        array $tables,
        array $definitions,
    ): bool {
        if (!array_is_list($filters)) {
            return false;
        }
        $seen = [];
        foreach ($filters as $filter) {
            if (!is_array($filter) || array_is_list($filter)) {
                return false;
            }
            $filterTable = $filter['table'] ?? null;
            $column = $filter['column'] ?? null;
            if (!is_string($filterTable)
                || !is_string($column)
                || ($filter['operator'] ?? null) !== 'is_null'
                || !$this->selectorFilterTableIsInScope(
                    $sourceTable,
                    $sourceDefinition,
                    $filterTable,
                )
            ) {
                return false;
            }
            $filterInventory = $tables[$filterTable] ?? null;
            $filterDefinition = $definitions[$filterTable] ?? null;
            $signature = $filterTable . "\0" . $column;
            if (isset($seen[$signature])
                || $filterInventory === null
                || $filterDefinition === null
                || !in_array($column, $filterInventory->columns, true)
                || !in_array($column, $filterInventory->nullableColumns, true)
            ) {
                return false;
            }
            $seen[$signature] = true;
        }
        return true;
    }

    private function selectorFilterTableIsInScope(
        string $sourceTable,
        TenantDataDefinition $sourceDefinition,
        string $filterTable,
    ): bool {
        if ($sourceTable === $filterTable) {
            return true;
        }
        $ownership = $sourceDefinition->details['ownership'] ?? null;
        $path = is_array($ownership) ? ($ownership['path'] ?? null) : null;
        if (!is_array($path) || !array_is_list($path)) {
            return false;
        }
        foreach ($path as $step) {
            if (is_array($step)
                && ($step['to_table'] ?? null) === $filterTable
            ) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function secretCoverageIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
    ): array {
        $rawPolicies = $definition->details['secrets'] ?? [];
        if (!is_array($rawPolicies) || (array_is_list($rawPolicies) && $rawPolicies !== [])) {
            return ['invalid_secret_registry:' . $table->name];
        }

        $issues = [];
        $declaredColumns = [];
        foreach ($rawPolicies as $column => $declaration) {
            if (!is_string($column) || !in_array($column, $table->columns, true)) {
                $issues[] = 'secret_policy_unknown_column:' . $table->name . '.' . (string) $column;
                continue;
            }
            $declaredColumns[$column] = true;
            if (!is_array($declaration) || array_is_list($declaration)) {
                $issues[] = 'invalid_secret_policy:' . $table->name . '.' . $column;
                continue;
            }
            $policy = $declaration['policy'] ?? null;
            if (!is_string($policy)
                || TenantSecretPolicy::tryFrom($policy) === null
            ) {
                $issues[] = 'invalid_secret_policy:' . $table->name . '.' . $column;
                continue;
            }
            if ($policy === 'not_secret') {
                $reason = $declaration['reason'] ?? null;
                if (!is_string($reason)
                    || preg_match('/^[a-z][a-z0-9._-]{2,127}$/D', $reason) !== 1
                ) {
                    $issues[] = 'missing_not_secret_reason:' . $table->name . '.' . $column;
                    continue;
                }
            }
        }

        foreach ($table->columns as $column) {
            if (TenantSecretColumnDetector::matches($column)
                && !isset($declaredColumns[$column])
            ) {
                $issues[] = 'secret_policy_missing:' . $table->name . '.' . $column;
            }
        }
        return $issues;
    }

    /**
     * Fyzické FK do jiného tenantového objektu se remapují podle politiky cíle.
     * Odkazy do instanční identity jsou výjimka a musí mít explicitní actor mapu.
     *
     * @param array<string,TenantDataDefinition> $definitions
     * @return list<string>
     */
    private function referenceCoverageIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
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

        $actorReferences = $this->actorReferences(
            $definition,
            'actor_references',
        );
        if ($actorReferences === null) {
            return ['invalid_actor_reference_registry:' . $table->name];
        }
        $softActorReferences = $this->actorReferences(
            $definition,
            'soft_actor_references',
        );
        if ($softActorReferences === null) {
            return ['invalid_soft_actor_reference_registry:' . $table->name];
        }
        $personalAttachmentReferences = $this->personalAttachmentReferences(
            $definition,
        );
        if ($personalAttachmentReferences === null) {
            return [
                'invalid_personal_attachment_reference_registry:'
                    . $table->name,
            ];
        }
        $issues = [];
        $actualActorColumns = [];
        $actualPersonalAttachmentColumns = [];
        foreach ($table->foreignKeys as $foreignKey) {
            $target = $definitions[$foreignKey->referencedTable] ?? null;
            if ($target === null) {
                $issues[] = 'reference_target_unregistered:'
                    . $table->name . '.' . $foreignKey->column
                    . '->' . $foreignKey->referencedTable
                    . '.' . $foreignKey->referencedColumn;
                continue;
            }
            if ($target->policy
                === TenantDataPolicy::PersonalSecretAttachment
            ) {
                $actualPersonalAttachmentColumns[$foreignKey->column] = true;
                $strategy = $personalAttachmentReferences[
                    $foreignKey->column
                ] ?? null;
                $expected = in_array(
                    $foreignKey->column,
                    $table->nullableColumns,
                    true,
                )
                    ? 'map_selected_or_null'
                    : 'require_selected_or_skip_row';
                if ($strategy === null) {
                    $issues[] = 'personal_attachment_reference_policy_missing:'
                        . $table->name . '.' . $foreignKey->column;
                } elseif ($strategy !== $expected) {
                    $issues[] =
                        'personal_attachment_reference_policy_mismatch:'
                            . $table->name . '.' . $foreignKey->column;
                }
                continue;
            }
            if ($target->policy === TenantDataPolicy::InstanceOwned) {
                if ($foreignKey->referencedTable !== 'users') {
                    // Samostatný validator ověřuje přesnou mapovací politiku
                    // i přirozený klíč cílového instančního číselníku.
                    continue;
                }
                $actualActorColumns[$foreignKey->column] = true;
                $strategy = $actorReferences[$foreignKey->column] ?? null;
                $expected = in_array(
                    $foreignKey->column,
                    $table->nullableColumns,
                    true,
                )
                    ? 'map_existing_user_or_null'
                    : 'map_existing_user_required';
                if ($strategy !== $expected) {
                    $issues[] = 'actor_reference_policy_mismatch:'
                        . $table->name . '.' . $foreignKey->column;
                }
                continue;
            }
            if (in_array($target->policy, [
                TenantDataPolicy::RuntimeDerived,
                TenantDataPolicy::Unsupported,
            ], true)) {
                $issues[] = 'non_transferable_reference_target:'
                    . $table->name . '.' . $foreignKey->column
                    . '->' . $foreignKey->referencedTable;
            }
        }

        foreach (array_keys($actorReferences) as $column) {
            if (!isset($actualActorColumns[$column])) {
                $issues[] = 'actor_reference_fk_missing:'
                    . $table->name . '.' . $column;
            }
        }
        foreach (array_keys($personalAttachmentReferences) as $column) {
            if (!isset($actualPersonalAttachmentColumns[$column])) {
                $issues[] = 'personal_attachment_reference_fk_missing:'
                    . $table->name . '.' . $column;
            }
        }
        foreach ($softActorReferences as $column => $strategy) {
            if (!in_array($column, $table->columns, true)) {
                $issues[] = 'soft_actor_reference_column_missing:'
                    . $table->name . '.' . $column;
                continue;
            }
            if (isset($actorReferences[$column])
                || isset($actualActorColumns[$column])
            ) {
                $issues[] = 'soft_actor_reference_has_fk:'
                    . $table->name . '.' . $column;
                continue;
            }
            $expected = in_array($column, $table->nullableColumns, true)
                ? 'map_existing_user_or_null'
                : 'map_existing_user_required';
            if ($strategy !== $expected) {
                $issues[] = 'soft_actor_reference_policy_mismatch:'
                    . $table->name . '.' . $column;
            }
        }
        return $issues;
    }

    /**
     * @return array<string,string>|null
     */
    private function actorReferences(
        TenantDataDefinition $definition,
        string $detail,
    ): ?array {
        $raw = $definition->details[$detail] ?? [];
        if (!is_array($raw) || (array_is_list($raw) && $raw !== [])) {
            return null;
        }
        $references = [];
        foreach ($raw as $column => $declaration) {
            if (!is_string($column)
                || !is_array($declaration)
                || array_is_list($declaration)
            ) {
                return null;
            }
            $strategy = $declaration['strategy'] ?? null;
            if (!is_string($strategy)
                || !in_array($strategy, [
                    'map_existing_user_required',
                    'map_existing_user_or_null',
                ], true)
            ) {
                $references[$column] = 'invalid';
                continue;
            }
            $references[$column] = $strategy;
        }
        return $references;
    }

    /** @return array<string,string>|null */
    private function personalAttachmentReferences(
        TenantDataDefinition $definition,
    ): ?array {
        $raw = $definition->details['personal_attachment_references'] ?? [];
        if (!is_array($raw) || (array_is_list($raw) && $raw !== [])) {
            return null;
        }
        $references = [];
        foreach ($raw as $column => $declaration) {
            if (!is_string($column)
                || !is_array($declaration)
                || array_is_list($declaration)
            ) {
                return null;
            }
            $strategy = $declaration['strategy'] ?? null;
            if (!is_string($strategy)
                || !in_array($strategy, [
                    'map_selected_or_null',
                    'require_selected_or_skip_row',
                ], true)
            ) {
                $references[$column] = 'invalid';
                continue;
            }
            $references[$column] = $strategy;
        }
        return $references;
    }
}
