<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Bankovní a komunikační integrace včetně bezpečného stavu po importu. */
final class TenantDataIntegrationCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        return [
            self::owned(
                'bank_email_imap_settings',
                secrets: [
                    'password_enc' => self::reencrypt(),
                ],
                postImport: [
                    'force_columns' => [
                        'enabled' => false,
                        'last_scan_at' => null,
                        'last_scan_status' => null,
                        'last_scan_message' => null,
                    ],
                    'reason' =>
                        'bank_email_scanner_requires_manual_reactivation',
                ],
            ),
            self::mixedProvider(),
            self::owned('bank_email_notice_provider_overrides'),
            self::owned(
                'bank_email_account_mappings',
                codeReferences: [
                    'provider_code' => self::codeReference(
                        'bank_email_notice_system_providers',
                        'preserve',
                    ),
                ],
                postImport: [
                    'force_columns' => ['enabled' => false],
                    'reason' =>
                        'bank_email_mapping_requires_manual_reactivation',
                ],
            ),
            self::owned('bank_email_processed_messages'),
            self::owned('external_bank_account_mappings'),
            self::owned('supplier_bank_accounts'),
            self::owned(
                'monthly_report_sends',
                actorReferences: [
                    'sent_by_user_id' => 'map_existing_user_or_null',
                ],
            ),
            self::instance('email_templates', 'instance_email_templates'),
        ];
    }

    private static function mixedProvider(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:bank_email_notice_providers',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'bank',
                'ownership' => [
                    'strategy' => 'supplier_id_or_global_reference',
                    'column' => 'supplier_id',
                    'tenant_rows' => 'transfer',
                    'global_rows' => [
                        'selector' => 'supplier_id_is_null',
                        'mapping' => [
                            'strategy' => 'natural_key',
                            'keys' => ['code'],
                            'values' => [
                                'strategy' => 'require_equal',
                                'columns' => [
                                    'name',
                                    'parser_type',
                                    'enabled',
                                    'sender_whitelist',
                                    'subject_pattern',
                                    'body_pattern',
                                    'field_patterns',
                                    'normalizer_config',
                                ],
                            ],
                            'missing' => 'block',
                            'ambiguous' => 'block',
                        ],
                    ],
                ],
                'secrets' => [],
                'code_references' => [
                    'parser_type' => self::codeReference(
                        'bank_email_notice_parsers',
                        'forbid',
                    ),
                ],
                'post_import' => [
                    'force_columns' => ['enabled' => false],
                    'reason' =>
                        'bank_email_provider_requires_manual_reactivation',
                ],
            ],
        );
    }

    /**
     * @param array<string,array<string,string>> $secrets
     * @param array<string,string> $actorReferences
     * @param array<string,array<string,string>> $codeReferences
     * @param array<string,mixed> $postImport
     */
    private static function owned(
        string $table,
        array $secrets = [],
        array $actorReferences = [],
        array $codeReferences = [],
        array $postImport = [],
    ): TenantDataDefinition {
        $details = [
            'primary_key' => ['id'],
            'feature_group' => 'bank',
            'ownership' => [
                'strategy' => 'supplier_id',
                'column' => 'supplier_id',
            ],
            'secrets' => $secrets,
        ];
        if ($actorReferences !== []) {
            $details['actor_references'] = self::references(
                $actorReferences,
            );
        }
        if ($codeReferences !== []) {
            $details['code_references'] = $codeReferences;
        }
        if ($postImport !== []) {
            $details['post_import'] = $postImport;
        }

        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            $details,
        );
    }

    private static function instance(
        string $table,
        string $reason,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::InstanceOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'email',
                'reason' => $reason,
                'secrets' => [],
            ],
        );
    }

    /** @return array{policy:string} */
    private static function reencrypt(): array
    {
        return ['policy' => TenantSecretPolicy::ReencryptV1->value];
    }

    /**
     * @return array{
     *   strategy:string,
     *   registry:string,
     *   unknown_value:string,
     *   null_value:string
     * }
     */
    private static function codeReference(
        string $registry,
        string $nullValue,
    ): array {
        return [
            'strategy' => 'application_registry_code',
            'registry' => $registry,
            'unknown_value' => 'block',
            'null_value' => $nullValue,
        ];
    }

    /**
     * @param array<string,string> $references
     * @return array<string,array{strategy:string}>
     */
    private static function references(array $references): array
    {
        $result = [];
        foreach ($references as $column => $strategy) {
            $result[$column] = ['strategy' => $strategy];
        }
        return $result;
    }
}
