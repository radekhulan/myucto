<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Obchodní data firmy a související bezpečně vynechaný runtime stav. */
final class TenantDataBusinessCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        return [
            self::owned(
                'email_profiles',
                ['id'],
                'email',
                secrets: [
                    'smtp_encryption' => self::notSecret(
                        'transport_security_mode',
                    ),
                    'smtp_password_enc' => self::reencrypt(),
                    'imap_encryption' => self::notSecret(
                        'transport_security_mode',
                    ),
                    'imap_password_enc' => self::reencrypt(),
                ],
                actorReferences: [
                    'created_by' => 'map_existing_user_or_null',
                ],
                postImport: [
                    'force_columns' => [
                        'is_active' => false,
                        'imap_sent_enabled' => false,
                    ],
                    'reason' => 'email_delivery_requires_manual_reactivation',
                ],
            ),
            self::owned('branding_profiles', ['id'], 'branding'),
            self::owned('revenue_categories', ['id'], 'core'),
            self::owned('price_list_items', ['id'], 'core'),
            self::owned('price_list_item_prices', ['id'], 'core'),
            self::owned('price_list_customer_overrides', ['id'], 'core'),
            self::indirect(
                'projects',
                ['id'],
                'core',
                [
                    self::path('client_id', 'clients'),
                    self::path('supplier_id', 'supplier'),
                ],
            ),
            self::indirect(
                'project_billing_emails',
                ['id'],
                'core',
                [
                    self::path('project_id', 'projects'),
                    self::path('client_id', 'clients'),
                    self::path('supplier_id', 'supplier'),
                ],
            ),
            self::indirect(
                'client_email_contacts',
                ['id'],
                'core',
                [
                    self::path('client_id', 'clients'),
                    self::path('supplier_id', 'supplier'),
                ],
            ),
            self::owned(
                'invoice_counters',
                [
                    'supplier_id',
                    'client_id',
                    'revenue_category_id',
                    'invoice_type',
                    'period',
                ],
                'core',
            ),
            self::owned(
                'purchase_invoice_counters',
                ['supplier_id', 'period'],
                'core',
            ),
            self::owned(
                'recurring_invoice_templates',
                ['id'],
                'core',
                actorReferences: [
                    'created_by' => 'map_existing_user_required',
                ],
                postImport: [
                    'force_columns' => [
                        'status' => 'paused',
                        'auto_issue' => false,
                        'auto_send_email' => false,
                        'last_error' => null,
                        'last_error_at' => null,
                    ],
                    'reason' => 'recurring_requires_manual_reactivation',
                ],
            ),
            self::indirect(
                'recurring_invoice_template_items',
                ['id'],
                'core',
                [
                    self::path('template_id', 'recurring_invoice_templates'),
                    self::path('supplier_id', 'supplier'),
                ],
            ),
            self::indirect(
                'work_reports',
                ['id'],
                'core',
                [
                    self::path('invoice_id', 'invoices'),
                    self::path('supplier_id', 'supplier'),
                ],
            ),
            self::indirect(
                'work_report_items',
                ['id'],
                'core',
                [
                    self::path('work_report_id', 'work_reports'),
                    self::path('invoice_id', 'invoices'),
                    self::path('supplier_id', 'supplier'),
                ],
            ),
            self::indirect(
                'work_report_materials',
                ['id'],
                'core',
                [
                    self::path('work_report_id', 'work_reports'),
                    self::path('invoice_id', 'invoices'),
                    self::path('supplier_id', 'supplier'),
                ],
            ),
            self::runtime(
                'client_revenue_cache',
                ['client_id', 'currency_id'],
                'runtime_business_summary_cache',
            ),
            self::runtime(
                'project_revenue_cache',
                ['project_id', 'currency_id'],
                'runtime_business_summary_cache',
            ),
            self::runtime(
                'crm_monthly_summary',
                ['supplier_id', 'period_ym', 'currency'],
                'runtime_business_summary_cache',
            ),
            self::instance(
                'crm_action_item_dismissals',
                ['id'],
                'instance_user_ui_state',
            ),
            self::runtime(
                'work_report_links',
                ['id'],
                'runtime_public_work_report_access',
                ['token' => self::omit()],
            ),
            self::runtime(
                'work_report_link_codes',
                ['id'],
                'runtime_public_work_report_access',
                ['code_hash' => self::omit()],
            ),
            self::runtime(
                'work_report_link_sessions',
                ['id'],
                'runtime_public_work_report_access',
                ['session_hash' => self::omit()],
            ),
        ];
    }

    /**
     * @param list<string> $primaryKey
     * @param array<string,array<string,string>> $secrets
     * @param array<string,string> $actorReferences
     * @param array<string,mixed> $postImport
     */
    private static function owned(
        string $table,
        array $primaryKey,
        string $featureGroup,
        array $secrets = [],
        array $actorReferences = [],
        array $postImport = [],
    ): TenantDataDefinition {
        return self::transferable(
            $table,
            $primaryKey,
            $featureGroup,
            TenantDataPolicy::TenantOwned,
            [
                'strategy' => 'supplier_id',
                'column' => 'supplier_id',
            ],
            $secrets,
            $actorReferences,
            $postImport,
        );
    }

    /**
     * @param list<string> $primaryKey
     * @param list<array{from_column:string,to_table:string,to_column:string}> $path
     * @param array<string,array<string,string>> $secrets
     * @param array<string,string> $actorReferences
     * @param array<string,mixed> $postImport
     */
    private static function indirect(
        string $table,
        array $primaryKey,
        string $featureGroup,
        array $path,
        array $secrets = [],
        array $actorReferences = [],
        array $postImport = [],
    ): TenantDataDefinition {
        return self::transferable(
            $table,
            $primaryKey,
            $featureGroup,
            TenantDataPolicy::TenantOwnedIndirect,
            [
                'strategy' => 'foreign_key_path',
                'path' => $path,
            ],
            $secrets,
            $actorReferences,
            $postImport,
        );
    }

    /**
     * @param list<string> $primaryKey
     * @param array<string,mixed> $ownership
     * @param array<string,array<string,string>> $secrets
     * @param array<string,string> $actorReferences
     * @param array<string,mixed> $postImport
     */
    private static function transferable(
        string $table,
        array $primaryKey,
        string $featureGroup,
        TenantDataPolicy $policy,
        array $ownership,
        array $secrets,
        array $actorReferences,
        array $postImport,
    ): TenantDataDefinition {
        $details = [
            'primary_key' => $primaryKey,
            'feature_group' => $featureGroup,
            'ownership' => $ownership,
            'secrets' => $secrets,
        ];
        if ($actorReferences !== []) {
            $details['actor_references'] = self::actorReferences(
                $actorReferences,
            );
        }
        if ($postImport !== []) {
            $details['post_import'] = $postImport;
        }
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::TRANSFER_PROFILE],
            $details,
        );
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

    /**
     * @param array<string,string> $references
     * @return array<string,array{strategy:string}>
     */
    private static function actorReferences(array $references): array
    {
        $result = [];
        foreach ($references as $column => $strategy) {
            $result[$column] = ['strategy' => $strategy];
        }
        return $result;
    }

    /** @return array{from_column:string,to_table:string,to_column:string} */
    private static function path(
        string $fromColumn,
        string $toTable,
    ): array {
        return [
            'from_column' => $fromColumn,
            'to_table' => $toTable,
            'to_column' => 'id',
        ];
    }

    /** @return array{policy:string} */
    private static function reencrypt(): array
    {
        return ['policy' => TenantSecretPolicy::ReencryptV1->value];
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
