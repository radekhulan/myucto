<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;

/** Fail-closed kontrola deklarovaných souborových oblastí a jejich DB vazeb. */
final class TenantDataFileAreaCoverageValidator
{
    /**
     * @param array<string,TenantSchemaTableInventory> $tables
     * @param array<string,TenantDataDefinition> $definitions
     * @return list<string>
     */
    public function issues(
        TenantDataDefinition $definition,
        array $tables,
        array $definitions,
    ): array {
        $key = $definition->key;
        if ($definition->kind !== TenantDataObjectKind::FileArea
            || !str_starts_with($key, 'file_area:')
            || !in_array($definition->policy, [
                TenantDataPolicy::TenantOwned,
                TenantDataPolicy::TenantOwnedIndirect,
            ], true)
        ) {
            return ['invalid_file_area_policy:' . $key];
        }

        $source = $definition->details['source'] ?? null;
        $target = $definition->details['target'] ?? null;
        $validation = $definition->details['validation'] ?? null;
        $issues = [];
        if (!$this->sourceIsSafe($source)) {
            $issues[] = 'invalid_file_area_source:' . $key;
        }
        if (!$this->targetIsSafe($target)) {
            $issues[] = 'invalid_file_area_target:' . $key;
        }
        if (!is_array($validation)
            || array_is_list($validation)
        ) {
            $issues[] = 'invalid_file_area_validation:' . $key;
        }

        $references = $this->rowReferences($source);
        if ($references === null || $references === []) {
            $issues[] = 'invalid_file_area_references:' . $key;
        } else {
            array_push(
                $issues,
                ...$this->referenceIssues(
                    $key,
                    $references,
                    $tables,
                    $definitions,
                ),
            );
        }

        sort($issues, SORT_STRING);
        return $issues;
    }

    private function sourceIsSafe(mixed $source): bool
    {
        if (!is_array($source)
            || array_is_list($source)
            || ($source['base'] ?? null) !== 'runtime_paths.storage'
            || !$this->safeRelativePath($source['relative_root'] ?? null)
            || ($source['require_relative_path'] ?? null) !== true
            || ($source['containment'] ?? null) !== 'case_insensitive'
            || ($source['outside_symlink'] ?? null) !== 'reject'
        ) {
            return false;
        }

        if (isset($source['row_reference'])) {
            return !isset($source['row_references'])
                && !isset($source['path_strategy'])
                && !isset($source['path_template']);
        }
        return ($source['path_strategy'] ?? null) === 'template_from_columns'
            && $this->safeRelativePath($source['path_template'] ?? null);
    }

    private function targetIsSafe(mixed $target): bool
    {
        return is_array($target)
            && !array_is_list($target)
            && in_array($target['strategy'] ?? null, [
                'regenerate_from_mapped_ids',
                'content_addressed_from_verified_sha256',
            ], true)
            && $this->safeRelativePath($target['template'] ?? null)
            && ($target['posix_mode'] ?? null) === '0600'
            && ($target['windows_acl'] ?? null) === 'owner_only';
    }

    private function safeRelativePath(mixed $path): bool
    {
        if (!is_string($path)
            || $path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:/', $path) === 1
        ) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<array{
     *   table:string,
     *   columns:list<string>,
     *   include_when?:string
     * }>|null
     */
    private function rowReferences(mixed $source): ?array
    {
        if (!is_array($source) || array_is_list($source)) {
            return null;
        }
        $legacy = $source['row_reference'] ?? null;
        if ($legacy !== null) {
            if (!is_array($legacy)
                || array_is_list($legacy)
                || !is_string($legacy['table'] ?? null)
                || !is_string($legacy['column'] ?? null)
            ) {
                return null;
            }
            return [[
                'table' => $legacy['table'],
                'columns' => [$legacy['column']],
            ]];
        }

        $raw = $source['row_references'] ?? null;
        if (!is_array($raw) || !array_is_list($raw)) {
            return null;
        }
        $references = [];
        $seen = [];
        foreach ($raw as $reference) {
            if (!is_array($reference)
                || array_is_list($reference)
                || !is_string($reference['table'] ?? null)
                || !in_array(
                    $reference['include_when'] ?? null,
                    ['all_rows', 'non_null', 'deleted_at_is_null'],
                    true,
                )
            ) {
                return null;
            }
            $columns = $this->uniqueStringList(
                $reference['columns'] ?? null,
            );
            if ($columns === null || $columns === []) {
                return null;
            }
            $signature = $reference['table'] . "\0"
                . implode("\0", $columns);
            if (isset($seen[$signature])) {
                return null;
            }
            $seen[$signature] = true;
            $references[] = [
                'table' => $reference['table'],
                'columns' => $columns,
                'include_when' => $reference['include_when'],
            ];
        }
        return $references;
    }

    /**
     * @param list<array{
     *   table:string,
     *   columns:list<string>,
     *   include_when?:string
     * }> $references
     * @param array<string,TenantSchemaTableInventory> $tables
     * @param array<string,TenantDataDefinition> $definitions
     * @return list<string>
     */
    private function referenceIssues(
        string $key,
        array $references,
        array $tables,
        array $definitions,
    ): array {
        $issues = [];
        foreach ($references as $reference) {
            $tableName = $reference['table'];
            $table = $tables[$tableName] ?? null;
            $tableDefinition = $definitions[$tableName] ?? null;
            if ($table === null || $tableDefinition === null) {
                $issues[] = 'file_area_reference_table_unregistered:'
                    . $key . '.' . $tableName;
                continue;
            }
            if (!in_array($tableDefinition->policy, [
                TenantDataPolicy::TenantRoot,
                TenantDataPolicy::TenantOwned,
                TenantDataPolicy::TenantOwnedIndirect,
                TenantDataPolicy::TenantRelation,
            ], true)) {
                $issues[] = 'file_area_reference_table_not_transferable:'
                    . $key . '.' . $tableName;
            }
            if (($reference['include_when'] ?? null)
                    === 'deleted_at_is_null'
                && !in_array('deleted_at', $table->columns, true)
            ) {
                $issues[] = 'file_area_reference_filter_column_missing:'
                    . $key . '.' . $tableName . '.deleted_at';
            }
            foreach ($reference['columns'] as $column) {
                if (!in_array($column, $table->columns, true)) {
                    $issues[] = 'file_area_reference_column_missing:'
                        . $key . '.' . $tableName . '.' . $column;
                }
            }
        }
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
