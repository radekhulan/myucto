<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryCoverageValidator;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataStructuredReferenceCoverageValidator;
use PHPUnit\Framework\TestCase;

final class TenantDataStructuredReferenceCoverageValidatorTest extends TestCase
{
    public function testJsonIdListIsFailClosed(): void
    {
        self::assertSame(
            [],
            (new TenantDataStructuredReferenceCoverageValidator())->issues(
                self::jsonSource(),
                self::jsonSourceInventory(),
                self::targetInventories(),
                self::targetDefinitions(),
            ),
        );
    }

    public function testTaggedDecimalIdMapsEachExactPrefix(): void
    {
        self::assertSame(
            [],
            (new TenantDataStructuredReferenceCoverageValidator())->issues(
                self::taggedSource(),
                self::taggedSourceInventory(),
                self::targetInventories(),
                self::targetDefinitions(),
            ),
        );
    }

    public function testJsonPoliciesAndTargetMustStayFailClosed(): void
    {
        self::assertSame(
            [
                'structured_reference_invalid_value_not_blocked:'
                    . 'bank_match_audit.invoice_ids',
                'structured_reference_null_policy_mismatch:'
                    . 'bank_match_audit.invoice_ids',
                'structured_reference_target_column_missing:'
                    . 'bank_match_audit.invoice_ids->invoices.ghost_id',
                'structured_reference_target_not_primary:'
                    . 'bank_match_audit.invoice_ids->invoices.ghost_id',
                'structured_reference_unresolved_not_blocked:'
                    . 'bank_match_audit.invoice_ids',
            ],
            (new TenantDataStructuredReferenceCoverageValidator())->issues(
                self::jsonSource([
                    'target_column' => 'ghost_id',
                    'null_value' => 'forbid',
                    'invalid_value' => 'preserve',
                    'unresolved' => 'preserve',
                ]),
                self::jsonSourceInventory(),
                self::targetInventories(),
                self::targetDefinitions(),
            ),
        );
    }

    public function testTaggedFormatDoesNotAcceptRegexOrUnknownTargets(): void
    {
        self::assertSame(
            [
                'invalid_structured_reference_target:'
                    . 'bank_posting_suggestions.note_reference',
                'structured_reference_tag_matching_not_deterministic:'
                    . 'bank_posting_suggestions.note_reference',
                'structured_reference_unknown_tag_not_blocked:'
                    . 'bank_posting_suggestions.note_reference',
                'structured_reference_unmatched_value_not_preserved:'
                    . 'bank_posting_suggestions.note_reference',
            ],
            (new TenantDataStructuredReferenceCoverageValidator())->issues(
                self::taggedSource([
                    'targets' => [
                        '/looks_like:#(\\d+)/' => [
                            'target_table' => 'bank_transactions',
                            'target_column' => 'id',
                        ],
                    ],
                    'tag_matching' => 'first_declared',
                    'unmatched_value' => 'block',
                    'unknown_tag' => 'preserve',
                ]),
                self::taggedSourceInventory(),
                self::targetInventories(),
                self::targetDefinitions(),
            ),
        );
    }

    public function testRegistryCoverageRunsStructuredReferenceValidator(): void
    {
        $registry = new TenantDataRegistry(
            1,
            [
                self::supplier(),
                self::jsonSource(['unresolved' => 'preserve']),
                self::targetDefinition('invoices'),
            ],
        );

        self::assertSame(
            [
                'structured_reference_unresolved_not_blocked:'
                    . 'bank_match_audit.invoice_ids',
            ],
            (new TenantDataRegistryCoverageValidator())->issues(
                $registry,
                [
                    self::supplierInventory(),
                    self::jsonSourceInventory(),
                    self::targetInventory('invoices'),
                ],
            ),
        );
    }

    /** @param array<string,mixed> $overrides */
    private static function jsonSource(
        array $overrides = [],
    ): TenantDataDefinition {
        return self::owned(
            'bank_match_audit',
            [
                'structured_references' => [
                    'invoice_ids' => [
                        'strategy' => 'json_id_list',
                        'column' => 'invoice_ids',
                        'target_table' => 'invoices',
                        'target_column' => 'id',
                        'null_value' => 'preserve',
                        'invalid_value' => 'block',
                        'unresolved' => 'block',
                        ...$overrides,
                    ],
                ],
            ],
        );
    }

    /** @param array<string,mixed> $overrides */
    private static function taggedSource(
        array $overrides = [],
    ): TenantDataDefinition {
        return self::owned(
            'bank_posting_suggestions',
            [
                'structured_references' => [
                    'note_reference' => [
                        'strategy' => 'tagged_decimal_id',
                        'column' => 'note',
                        'targets' => [
                            'corrected_from:#' => [
                                'target_table' => 'bank_transactions',
                                'target_column' => 'id',
                            ],
                            'looks_like:#' => [
                                'target_table' => 'bank_transactions',
                                'target_column' => 'id',
                            ],
                            'duplicate_suspect:#' => [
                                'target_table' => 'journal_entries',
                                'target_column' => 'id',
                            ],
                            'duplicate_suspect:' => [
                                'target_table' => 'journal_entries',
                                'target_column' => 'id',
                            ],
                        ],
                        'tag_matching' => 'longest_prefix',
                        'null_value' => 'preserve',
                        'unmatched_value' => 'preserve',
                        'unknown_tag' => 'block',
                        'invalid_value' => 'block',
                        'unresolved' => 'block',
                        ...$overrides,
                    ],
                ],
            ],
        );
    }

    /** @param array<string,mixed> $additionalDetails */
    private static function owned(
        string $table,
        array $additionalDetails = [],
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                ...$additionalDetails,
            ],
        );
    }

    private static function supplier(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:supplier',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantRoot,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'selected_supplier',
                    'column' => 'id',
                ],
                'secrets' => [],
            ],
        );
    }

    /** @return array<string,TenantDataDefinition> */
    private static function targetDefinitions(): array
    {
        return [
            'bank_transactions' => self::targetDefinition(
                'bank_transactions',
                TenantDataPolicy::TenantOwnedIndirect,
            ),
            'invoices' => self::targetDefinition('invoices'),
            'journal_entries' => self::targetDefinition('journal_entries'),
        ];
    }

    private static function targetDefinition(
        string $table,
        TenantDataPolicy $policy = TenantDataPolicy::TenantOwned,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
            ],
        );
    }

    /** @return array<string,TenantSchemaTableInventory> */
    private static function targetInventories(): array
    {
        return [
            'bank_transactions' => self::targetInventory(
                'bank_transactions',
            ),
            'invoices' => self::targetInventory('invoices'),
            'journal_entries' => self::targetInventory('journal_entries'),
        ];
    }

    private static function supplierInventory(): TenantSchemaTableInventory
    {
        return new TenantSchemaTableInventory(
            'supplier',
            'BASE TABLE',
            ['id'],
            ['id'],
            [],
            [['id']],
        );
    }

    private static function jsonSourceInventory(): TenantSchemaTableInventory
    {
        return new TenantSchemaTableInventory(
            'bank_match_audit',
            'BASE TABLE',
            ['id', 'supplier_id', 'invoice_ids'],
            ['id'],
            [],
            [['id']],
            ['invoice_ids'],
        );
    }

    private static function taggedSourceInventory(): TenantSchemaTableInventory
    {
        return new TenantSchemaTableInventory(
            'bank_posting_suggestions',
            'BASE TABLE',
            ['id', 'supplier_id', 'note'],
            ['id'],
            [],
            [['id']],
            ['note'],
        );
    }

    private static function targetInventory(
        string $table,
    ): TenantSchemaTableInventory {
        return new TenantSchemaTableInventory(
            $table,
            'BASE TABLE',
            ['id', 'supplier_id'],
            ['id'],
            [],
            [['id']],
        );
    }
}
