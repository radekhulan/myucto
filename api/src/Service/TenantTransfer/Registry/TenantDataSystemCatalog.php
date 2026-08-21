<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/**
 * Instanční a runtime objekty, které úplný tenantový snapshot nikdy nekopíruje.
 *
 * Explicitní zápis je bezpečnostní hranice: samotná přítomnost `supplier_id`
 * z tabulky nedělá přenositelná tenantová data (např. PAT audit nebo grant).
 */
final class TenantDataSystemCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        return [
            self::instance('api_request_log', ['id'], 'instance_api_audit', [
                'token_id' => self::notSecret('foreign_key_identifier_only'),
            ]),
            self::instance('api_token_ips', ['id'], 'instance_api_credentials', [
                'token_id' => self::notSecret('foreign_key_identifier_only'),
            ]),
            self::instance('api_tokens', ['id'], 'instance_api_credentials', [
                'token_hash' => self::omit(),
            ]),
            self::instance('app_meta', ['k'], 'instance_application_metadata'),
            self::runtime('ares_cache', ['ic'], 'runtime_external_lookup_cache'),
            self::runtime(
                'cron_dispatch_claims',
                ['script', 'minute_bucket'],
                'runtime_scheduler_state',
            ),
            self::runtime('cron_heartbeat', ['script'], 'runtime_scheduler_state'),
            self::runtime('cron_runs', ['id'], 'runtime_scheduler_state'),
            self::instance('cron_settings', ['id'], 'instance_scheduler_config'),
            self::runtime('crpdph_cache', ['dic'], 'runtime_external_lookup_cache'),
            self::instance('license', ['id'], 'instance_license', [
                'license_key' => self::omit(),
                'token' => self::omit(),
                'token_payload' => self::omit(),
                'last_nonce' => self::omit(),
            ]),
            self::runtime('login_attempts', ['id'], 'runtime_authentication_state'),
            self::runtime('login_otps', ['id'], 'runtime_authentication_state', [
                'code_hash' => self::omit(),
            ]),
            self::instance('mfa_recovery_codes', ['id'], 'instance_identity_credentials', [
                'code_hash' => self::omit(),
            ]),
            self::runtime(
                'mfa_step_up_proofs',
                ['token_hash'],
                'runtime_authentication_state',
                [
                    'token_hash' => self::omit(),
                    'session_id_hash' => self::omit(),
                    'auth_credential_id' => self::notSecret(
                        'foreign_key_identifier_only',
                    ),
                ],
            ),
            self::instance('migrations', ['filename'], 'instance_migration_state'),
            self::runtime('password_resets', ['id'], 'runtime_authentication_state', [
                'token_hash' => self::omit(),
            ]),
            self::runtime(
                'rate_limit_counters',
                ['bucket_key', 'window_start'],
                'runtime_rate_limit_state',
            ),
            self::instance(
                'remittance_map',
                ['id'],
                'instance_global_remittance_codebook',
            ),
            self::instance('roles', ['id'], 'instance_identity_authorization'),
            self::instance(
                'role_permissions',
                ['role_id', 'permission_key'],
                'instance_identity_authorization',
            ),
            self::instance('saved_filters', ['id'], 'instance_identity_preferences'),
            self::runtime('sessions', ['id'], 'runtime_authentication_state', [
                'id' => self::omit(),
                'csrf_token' => self::omit(),
                'auth_credential_id' => self::notSecret(
                    'foreign_key_identifier_only',
                ),
                'session_family_id' => self::omit(),
            ]),
            self::runtime(
                'tenant_transfer_grants',
                ['id'],
                'runtime_transfer_control_plane',
                ['grant_hash' => self::omit()],
            ),
            self::runtime(
                'tenant_transfer_grant_audit',
                ['id'],
                'runtime_transfer_control_plane',
            ),
            self::instance('trusted_devices', ['id'], 'instance_identity_credentials', [
                'token_hash' => self::omit(),
            ]),
            self::instance('users', ['id'], 'instance_identity', [
                'password_hash' => self::omit(),
                'totp_secret' => self::omit(),
            ]),
            self::instance(
                'user_preferences',
                ['id'],
                'instance_identity_preferences',
            ),
            self::runtime('vies_cache', ['vat_id'], 'runtime_external_lookup_cache'),
            self::runtime(
                'webauthn_ceremonies',
                ['flow_token_hash'],
                'runtime_authentication_state',
                [
                    'flow_token_hash' => self::omit(),
                    'challenge' => self::omit(),
                ],
            ),
            self::instance(
                'webauthn_credentials',
                ['id'],
                'instance_identity_credentials',
                [
                    'credential_id' => self::notSecret(
                        'public_credential_identifier',
                    ),
                    'credential_id_hash' => self::notSecret(
                        'lookup_hash_only',
                    ),
                ],
            ),
        ];
    }

    /**
     * @param list<string> $primaryKey
     * @param array<string,array<string,string>> $secrets
     */
    private static function instance(
        string $table,
        array $primaryKey,
        string $reason,
        array $secrets = [],
    ): TenantDataDefinition {
        return self::excluded(
            $table,
            $primaryKey,
            TenantDataPolicy::InstanceOwned,
            $reason,
            $secrets,
        );
    }

    /**
     * @param list<string> $primaryKey
     * @param array<string,array<string,string>> $secrets
     */
    private static function runtime(
        string $table,
        array $primaryKey,
        string $reason,
        array $secrets = [],
    ): TenantDataDefinition {
        return self::excluded(
            $table,
            $primaryKey,
            TenantDataPolicy::RuntimeDerived,
            $reason,
            $secrets,
        );
    }

    /**
     * @param list<string> $primaryKey
     * @param array<string,array<string,string>> $secrets
     */
    private static function excluded(
        string $table,
        array $primaryKey,
        TenantDataPolicy $policy,
        string $reason,
        array $secrets,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => $primaryKey,
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
