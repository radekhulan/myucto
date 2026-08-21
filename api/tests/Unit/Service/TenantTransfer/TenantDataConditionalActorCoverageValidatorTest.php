<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaForeignKeyInventory;
use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataConditionalActorCoverageValidator;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use PHPUnit\Framework\TestCase;

final class TenantDataConditionalActorCoverageValidatorTest extends TestCase
{
    public function testNullableOwnerCanBecomeRequiredForUserScope(): void
    {
        self::assertSame(
            [],
            (new TenantDataConditionalActorCoverageValidator())->issues(
                self::definition('scope'),
                self::inventory(),
            ),
        );
    }

    public function testUnknownConditionColumnFailsClosed(): void
    {
        self::assertSame(
            [
                'conditional_actor_condition_column_missing:'
                    . 'documents.ghost_scope',
            ],
            (new TenantDataConditionalActorCoverageValidator())->issues(
                self::definition('ghost_scope'),
                self::inventory(),
            ),
        );
    }

    private static function definition(
        string $conditionColumn,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:documents',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'actor_references' => [
                    'owner_user_id' => [
                        'strategy' => 'map_existing_user_or_null',
                    ],
                ],
                'conditional_actor_references' => [
                    'owner_user_id' => [
                        'strategy' => 'map_existing_user_required_when',
                        'condition' => [
                            'column' => $conditionColumn,
                            'operator' => 'equals',
                            'value' => 'user',
                        ],
                    ],
                ],
            ],
        );
    }

    private static function inventory(): TenantSchemaTableInventory
    {
        return new TenantSchemaTableInventory(
            'documents',
            'BASE TABLE',
            ['id', 'scope', 'owner_user_id'],
            ['id'],
            [new TenantSchemaForeignKeyInventory(
                'owner_user_id',
                'users',
                'id',
            )],
            [['id']],
            ['owner_user_id'],
        );
    }
}
