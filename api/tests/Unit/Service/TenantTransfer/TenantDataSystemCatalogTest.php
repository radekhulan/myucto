<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataSystemCatalog;
use PHPUnit\Framework\TestCase;

final class TenantDataSystemCatalogTest extends TestCase
{
    public function testSystemBoundaryHasExplicitStablePrimaryKeys(): void
    {
        $expected = [
            'api_request_log' => ['id'],
            'api_token_ips' => ['id'],
            'api_tokens' => ['id'],
            'app_meta' => ['k'],
            'ares_cache' => ['ic'],
            'cron_dispatch_claims' => ['script', 'minute_bucket'],
            'cron_heartbeat' => ['script'],
            'cron_runs' => ['id'],
            'cron_settings' => ['id'],
            'crpdph_cache' => ['dic'],
            'license' => ['id'],
            'login_attempts' => ['id'],
            'login_otps' => ['id'],
            'mfa_recovery_codes' => ['id'],
            'mfa_step_up_proofs' => ['token_hash'],
            'migrations' => ['filename'],
            'password_resets' => ['id'],
            'rate_limit_counters' => ['bucket_key', 'window_start'],
            'remittance_map' => ['id'],
            'roles' => ['id'],
            'role_permissions' => ['role_id', 'permission_key'],
            'saved_filters' => ['id'],
            'sessions' => ['id'],
            'tenant_transfer_grants' => ['id'],
            'tenant_transfer_grant_audit' => ['id'],
            'trusted_devices' => ['id'],
            'users' => ['id'],
            'user_preferences' => ['id'],
            'vies_cache' => ['vat_id'],
            'webauthn_ceremonies' => ['flow_token_hash'],
            'webauthn_credentials' => ['id'],
        ];

        $actual = [];
        foreach (TenantDataSystemCatalog::definitions() as $definition) {
            self::assertSame(
                [TenantDataRegistry::TRANSFER_PROFILE],
                $definition->profiles,
            );
            self::assertContains($definition->policy, [
                TenantDataPolicy::InstanceOwned,
                TenantDataPolicy::RuntimeDerived,
            ]);
            self::assertIsString($definition->details['reason'] ?? null);
            $actual[self::tableName($definition)] =
                $definition->details['primary_key'] ?? null;
        }

        self::assertSame($expected, $actual);
    }

    public function testAuthenticationMaterialIsExplicitlyOmitted(): void
    {
        $definitions = self::byTable();

        self::assertSame(
            [
                'password_hash' => ['policy' => 'omit_and_reconfigure'],
                'totp_secret' => ['policy' => 'omit_and_reconfigure'],
            ],
            $definitions['users']->details['secrets'] ?? null,
        );
        self::assertSame(
            'omit_and_reconfigure',
            self::secretPolicy($definitions['sessions'], 'id'),
        );
        self::assertSame(
            'omit_and_reconfigure',
            self::secretPolicy($definitions['sessions'], 'csrf_token'),
        );
        self::assertSame(
            'not_secret',
            self::secretPolicy($definitions['sessions'], 'auth_credential_id'),
        );
        self::assertSame(
            'omit_and_reconfigure',
            self::secretPolicy($definitions['api_tokens'], 'token_hash'),
        );
        self::assertSame(
            'omit_and_reconfigure',
            self::secretPolicy($definitions['tenant_transfer_grants'], 'grant_hash'),
        );
        self::assertSame(
            'not_secret',
            self::secretPolicy(
                $definitions['webauthn_credentials'],
                'credential_id',
            ),
        );
    }

    public function testFactoryIncludesSystemBoundaryOnlyInTransferProfile(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $transferKeys = array_map(
            static fn (TenantDataDefinition $definition): string => $definition->key,
            $registry->definitionsFor(TenantDataRegistry::TRANSFER_PROFILE),
        );
        $archiveKeys = array_map(
            static fn (TenantDataDefinition $definition): string => $definition->key,
            $registry->definitionsFor(
                TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
            ),
        );

        self::assertContains('table:users', $transferKeys);
        self::assertContains('table:sessions', $transferKeys);
        self::assertContains('table:tenant_transfer_grants', $transferKeys);
        self::assertNotContains('table:users', $archiveKeys);
        self::assertNotContains('table:sessions', $archiveKeys);
        self::assertFalse($registry->isComplete(
            TenantDataRegistry::TRANSFER_PROFILE,
        ));
    }

    /** @return array<string,TenantDataDefinition> */
    private static function byTable(): array
    {
        $definitions = [];
        foreach (TenantDataSystemCatalog::definitions() as $definition) {
            $definitions[self::tableName($definition)] = $definition;
        }
        return $definitions;
    }

    private static function tableName(TenantDataDefinition $definition): string
    {
        self::assertStringStartsWith('table:', $definition->key);
        return substr($definition->key, strlen('table:'));
    }

    private static function secretPolicy(
        TenantDataDefinition $definition,
        string $column,
    ): string {
        $secrets = $definition->details['secrets'] ?? null;
        self::assertIsArray($secrets);
        $declaration = $secrets[$column] ?? null;
        self::assertIsArray($declaration);
        $policy = $declaration['policy'] ?? null;
        self::assertIsString($policy);
        return $policy;
    }
}
