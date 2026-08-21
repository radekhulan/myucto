<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataCodeReferenceCoverageValidator;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use PHPUnit\Framework\TestCase;

final class TenantDataCodeReferenceCoverageValidatorTest extends TestCase
{
    public function testApplicationRegistryCodeIsFailClosed(): void
    {
        self::assertSame(
            [],
            (new TenantDataCodeReferenceCoverageValidator())->issues(
                self::definition(),
                self::inventory(),
            ),
        );
    }

    public function testUnknownValuesAndNullabilityCannotDrift(): void
    {
        $definition = self::definition();
        $details = $definition->details;
        $references = $details['code_references'];
        self::assertIsArray($references);
        self::assertIsArray($references['parser_type'] ?? null);
        $references['parser_type']['unknown_value'] = 'preserve';
        $references['parser_type']['null_value'] = 'preserve';
        $details['code_references'] = $references;

        self::assertSame(
            [
                'code_reference_null_policy_mismatch:'
                    . 'bank_email_notice_providers.parser_type',
                'code_reference_unknown_value_not_blocked:'
                    . 'bank_email_notice_providers.parser_type',
            ],
            (new TenantDataCodeReferenceCoverageValidator())->issues(
                new TenantDataDefinition(
                    $definition->key,
                    $definition->kind,
                    $definition->policy,
                    $definition->profiles,
                    $details,
                ),
                self::inventory(),
            ),
        );
    }

    private static function definition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:bank_email_notice_providers',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'code_references' => [
                    'parser_type' => [
                        'strategy' => 'application_registry_code',
                        'registry' => 'bank_email_notice_parsers',
                        'unknown_value' => 'block',
                        'null_value' => 'forbid',
                    ],
                ],
            ],
        );
    }

    private static function inventory(): TenantSchemaTableInventory
    {
        return new TenantSchemaTableInventory(
            'bank_email_notice_providers',
            'BASE TABLE',
            ['id', 'parser_type'],
            ['id'],
            [],
            [['id']],
        );
    }
}
