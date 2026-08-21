<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Vazby uživatelů na firmu a instančně specifické doménové přihlašování. */
final class TenantDataIdentityCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        return [
            self::memberships(),
            self::runtime(
                'supplier_domains',
                'runtime_external_hostname_routing',
                [
                    'verification_token' => self::omit(),
                ],
            ),
            self::runtime(
                'supplier_domain_login_requests',
                'runtime_domain_authentication_state',
                [
                    'request_token_hash' => self::omit(),
                    'state_hash' => self::omit(),
                    'pkce_challenge' => self::omit(),
                    'authorization_code_hash' => self::omit(),
                    'auth_credential_id' => self::notSecret(
                        'foreign_key_identifier_only',
                    ),
                ],
            ),
        ];
    }

    private static function memberships(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:user_suppliers',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantRelation,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['user_id', 'supplier_id'],
                'feature_group' => 'identity',
                'ownership' => [
                    'strategy' => 'supplier_relation',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                'actor_references' => [
                    'user_id' => [
                        'strategy' => 'map_existing_user_required',
                    ],
                ],
                'instance_references' => [
                    'role_id' => [
                        'strategy' =>
                            'map_existing_by_natural_key_or_explicit',
                        'natural_key' => ['system_key'],
                        'natural_key_null' =>
                            'require_explicit_mapping',
                        'values' => [
                            'strategy' => 'require_equal',
                            'columns' => ['role_type'],
                        ],
                        'source_null' => 'preserve',
                        'missing' => 'block',
                        'ambiguous' => 'block',
                    ],
                ],
                'relation_import' => [
                    'strategy' => 'recreate_from_mapped_references',
                    'raw_insert' => false,
                    'unresolved_row' => 'skip',
                    'selection' => 'confirmed_actor_mappings_only',
                    'ensure_importing_superadmin' => true,
                ],
                'post_import' => [
                    'force_columns' => ['role' => null],
                    'reason' => 'legacy_role_column_not_authoritative',
                ],
            ],
        );
    }

    /**
     * @param array<string,array<string,string>> $secrets
     */
    private static function runtime(
        string $table,
        string $reason,
        array $secrets,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::RuntimeDerived,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'identity',
                'reason' => $reason,
                'secrets' => $secrets,
            ],
        );
    }

    /** @return array{policy:string} */
    private static function omit(): array
    {
        return ['policy' => TenantSecretPolicy::OmitAndReconfigure->value];
    }

    /** @return array{policy:string,reason:string} */
    private static function notSecret(string $reason): array
    {
        return [
            'policy' => TenantSecretPolicy::NotSecret->value,
            'reason' => $reason,
        ];
    }
}
