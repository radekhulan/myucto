<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Archive;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantSecretPolicy;

/** Ověřený projekční pohled jedné tabulky účetního archivního profilu. */
final readonly class AccountingArchiveTable
{
    /** @var list<string> */
    public array $primaryKey;

    /** @var array<string,mixed> */
    public array $ownership;

    /** @var list<string> */
    public array $omitColumns;

    /** @var array<string,string> */
    public array $softReferences;

    /** @var array<string,TenantSecretPolicy> */
    public array $secretPolicies;

    /**
     * @param list<string> $primaryKey
     * @param array<string,mixed> $ownership
     * @param list<string> $omitColumns
     * @param array<string,string> $softReferences
     * @param array<string,TenantSecretPolicy> $secretPolicies
     */
    private function __construct(
        public string $name,
        public TenantDataPolicy $policy,
        array $primaryKey,
        array $ownership,
        public int $exportOrder,
        public int $restoreOrder,
        public string $selector,
        array $omitColumns,
        array $softReferences,
        array $secretPolicies,
        public ?string $featureFlag,
    ) {
        $this->primaryKey = $primaryKey;
        $this->ownership = $ownership;
        $this->omitColumns = $omitColumns;
        $this->softReferences = $softReferences;
        $this->secretPolicies = $secretPolicies;
    }

    public static function fromDefinition(
        TenantDataDefinition $definition,
    ): self {
        if ($definition->kind !== TenantDataObjectKind::Table
            || !str_starts_with($definition->key, 'table:')
        ) {
            throw new \LogicException(
                'Účetní archiv obsahuje objekt, který není tabulkou.',
            );
        }
        $name = substr($definition->key, strlen('table:'));
        self::assertIdentifier($name, 'název tabulky');

        $primaryKey = self::identifierList(
            $definition->details['primary_key'] ?? null,
            'primární klíč tabulky ' . $name,
        );
        if ($primaryKey === []) {
            throw new \LogicException(
                'Tabulka účetního archivu musí mít stabilní primární klíč.',
            );
        }

        $archive = $definition->details['accounting_archive'] ?? null;
        if (!is_array($archive) || array_is_list($archive)) {
            throw new \LogicException(
                'Tabulka účetního archivu nemá projekční metadata.',
            );
        }
        $exportOrder = $archive['export_order'] ?? null;
        $restoreOrder = $archive['restore_order'] ?? null;
        if (!is_int($exportOrder)
            || $exportOrder < 1
            || !is_int($restoreOrder)
            || $restoreOrder < 1
        ) {
            throw new \LogicException(
                'Tabulka účetního archivu nemá platné stabilní pořadí.',
            );
        }
        $selector = $archive['selector'] ?? null;
        if (!is_string($selector)
            || !in_array($selector, [
                'ownership',
                'bank_transaction_relationships',
                'bank_statement_relationships',
                'accounting_period_currency',
            ], true)
        ) {
            throw new \LogicException(
                'Tabulka účetního archivu má neznámý selektor.',
            );
        }

        $ownership = $definition->details['ownership'] ?? [];
        if (!is_array($ownership)
            || array_is_list($ownership) && $ownership !== []
        ) {
            throw new \LogicException(
                'Tabulka účetního archivu má neplatnou deklaraci vlastnictví.',
            );
        }
        $validatedOwnership = [];
        foreach ($ownership as $field => $value) {
            if (!is_string($field)) {
                throw new \LogicException(
                    'Tabulka účetního archivu má neplatnou deklaraci vlastnictví.',
                );
            }
            $validatedOwnership[$field] = $value;
        }
        $omitColumns = self::identifierList(
            $archive['omit_columns'] ?? null,
            'vynechané sloupce tabulky ' . $name,
        );
        $secretPolicies = self::secretPolicies(
            $definition->details['secrets'] ?? null,
            $name,
        );
        foreach ($secretPolicies as $column => $policy) {
            if ($policy !== TenantSecretPolicy::NotSecret
                && !in_array($column, $omitColumns, true)
            ) {
                $omitColumns[] = $column;
            }
        }
        $softReferences = self::referenceMap(
            $archive['soft_references'] ?? null,
            $name,
        );
        $featureFlag = $archive['feature_flag'] ?? null;
        if ($featureFlag !== null && $featureFlag !== 'stock_enabled') {
            throw new \LogicException(
                'Tabulka účetního archivu má neznámý feature flag.',
            );
        }

        return new self(
            $name,
            $definition->policy,
            $primaryKey,
            $validatedOwnership,
            $exportOrder,
            $restoreOrder,
            $selector,
            $omitColumns,
            $softReferences,
            $secretPolicies,
            $featureFlag,
        );
    }

    /** @return list<string> */
    private static function identifierList(mixed $value, string $label): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \LogicException(
                'Účetní archiv má neplatnou deklaraci: ' . $label . '.',
            );
        }
        $result = [];
        $seen = [];
        foreach ($value as $identifier) {
            if (!is_string($identifier)) {
                throw new \LogicException(
                    'Účetní archiv má neplatnou deklaraci: ' . $label . '.',
                );
            }
            self::assertIdentifier($identifier, $label);
            if (isset($seen[$identifier])) {
                throw new \LogicException(
                    'Účetní archiv má duplicitní deklaraci: ' . $label . '.',
                );
            }
            $seen[$identifier] = true;
            $result[] = $identifier;
        }
        return $result;
    }

    /** @return array<string,string> */
    private static function referenceMap(mixed $value, string $table): array
    {
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw new \LogicException(
                'Tabulka účetního archivu má neplatné soft reference.',
            );
        }
        $result = [];
        foreach ($value as $column => $target) {
            if (!is_string($column) || !is_string($target)) {
                throw new \LogicException(
                    'Tabulka účetního archivu má neplatné soft reference.',
                );
            }
            self::assertIdentifier($column, 'soft reference tabulky ' . $table);
            self::assertIdentifier($target, 'cíl soft reference tabulky ' . $table);
            $result[$column] = $target;
        }
        return $result;
    }

    /** @return array<string,TenantSecretPolicy> */
    private static function secretPolicies(mixed $value, string $table): array
    {
        if (!is_array($value)
            || array_is_list($value) && $value !== []
        ) {
            throw new \LogicException(
                'Tabulka účetního archivu má neplatný registr secrets.',
            );
        }
        $result = [];
        foreach ($value as $column => $declaration) {
            if (!is_string($column)
                || !is_array($declaration)
                || array_is_list($declaration)
            ) {
                throw new \LogicException(
                    'Tabulka účetního archivu má neplatný registr secrets.',
                );
            }
            self::assertIdentifier(
                $column,
                'secret sloupec tabulky ' . $table,
            );
            $policyValue = $declaration['policy'] ?? null;
            $policy = is_string($policyValue)
                ? TenantSecretPolicy::tryFrom($policyValue)
                : null;
            if ($policy === null) {
                throw new \LogicException(
                    'Tabulka účetního archivu má neznámou secret politiku.',
                );
            }
            $result[$column] = $policy;
        }
        return $result;
    }

    private static function assertIdentifier(string $value, string $label): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) !== 1) {
            throw new \LogicException(
                'Účetní archiv má neplatný ' . $label . '.',
            );
        }
    }
}
