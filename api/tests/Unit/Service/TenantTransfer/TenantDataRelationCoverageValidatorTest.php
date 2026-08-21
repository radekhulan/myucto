<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRelationCoverageValidator;
use PHPUnit\Framework\TestCase;

final class TenantDataRelationCoverageValidatorTest extends TestCase
{
    public function testMappedReferenceRecreationPasses(): void
    {
        self::assertSame(
            [],
            (new TenantDataRelationCoverageValidator())->issues(
                $this->relation([
                    'strategy' => 'recreate_from_mapped_references',
                    'raw_insert' => false,
                    'unresolved_row' => 'skip',
                ]),
                $this->table(),
            ),
        );
    }

    public function testRawOrUndecidedRelationWritesFailClosed(): void
    {
        self::assertSame(
            [
                'relation_raw_insert_not_forbidden:user_suppliers',
                'relation_unresolved_row_not_skipped:user_suppliers',
            ],
            (new TenantDataRelationCoverageValidator())->issues(
                $this->relation([
                    'strategy' => 'recreate_from_mapped_references',
                    'raw_insert' => true,
                    'unresolved_row' => 'block',
                ]),
                $this->table(),
            ),
        );
    }

    public function testMissingRelationImportPolicyFailsClosed(): void
    {
        self::assertSame(
            ['invalid_relation_import_policy:user_suppliers'],
            (new TenantDataRelationCoverageValidator())->issues(
                $this->relation(null),
                $this->table(),
            ),
        );
    }

    /** @param array<string,mixed>|null $relationImport */
    private function relation(?array $relationImport): TenantDataDefinition
    {
        $details = [
            'primary_key' => ['user_id', 'supplier_id'],
            'ownership' => [
                'strategy' => 'supplier_relation',
                'column' => 'supplier_id',
            ],
            'actor_references' => [
                'user_id' => [
                    'strategy' => 'map_existing_user_required',
                ],
            ],
            'secrets' => [],
        ];
        if ($relationImport !== null) {
            $details['relation_import'] = $relationImport;
        }

        return new TenantDataDefinition(
            'table:user_suppliers',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantRelation,
            [TenantDataRegistry::TRANSFER_PROFILE],
            $details,
        );
    }

    private function table(): TenantSchemaTableInventory
    {
        return new TenantSchemaTableInventory(
            'user_suppliers',
            'BASE TABLE',
            ['user_id', 'supplier_id'],
            ['user_id', 'supplier_id'],
            [],
            [['user_id', 'supplier_id']],
        );
    }
}
