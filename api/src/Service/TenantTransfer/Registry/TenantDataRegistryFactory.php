<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Produkční sestavení registru; transfer profil čeká na dokončení inventury. */
final class TenantDataRegistryFactory
{
    /**
     * Stabilní pořadí stávajícího účetního archivu. Je součástí registru, aby
     * export, obnova a tenant transfer neudržovaly vlastní konkurenční seznamy.
     *
     * @var list<string>
     */
    private const ACCOUNTING_ARCHIVE_EXPORT_ORDER = [
        'supplier',
        'accounting_periods',
        'chart_of_accounts',
        'posting_rules',
        'cost_centers',
        'accounting_supplier_settings',
        'accounting_closing_steps',
        'accounting_document_series',
        'journal_entries',
        'journal_entry_lines',
        'clients',
        'client_bank_accounts',
        'currencies',
        'invoices',
        'purchase_invoices',
        'assets',
        'asset_improvements',
        'depreciation_entries',
        'payment_matches',
        'cash_registers',
        'cash_documents',
        'invoice_payments',
        'income_tax_returns',
        'tax_losses',
        'tax_loss_applications',
        'tax_advance_schedules',
        'journal_entry_attachments',
        'cash_document_vat_lines',
        'warehouses',
        'stock_items',
        'manufacturers',
        'stock_media',
        'stock_categories',
        'stock_category_i18n',
        'stock_item_categories',
        'stock_tags',
        'stock_item_tags',
        'stock_attributes',
        'stock_attribute_options',
        'stock_attribute_i18n',
        'stock_item_attribute_values',
        'stock_fee_types',
        'stock_item_fees',
        'stock_item_prices',
        'stock_item_vendors',
        'stock_item_i18n',
        'stock_levels',
        'stock_documents',
        'stock_document_lines',
        'stock_landed_costs',
        'stock_takes',
        'stock_take_lines',
        'invoice_items',
        'purchase_invoice_items',
        'bank_transactions',
        'bank_statements',
        'exchange_rates',
    ];

    /**
     * Rodiče před dětmi pro obnovu archivu. Odlišnost od exportního pořadí je
     * záměrná; cykly a dopředné reference řeší druhý průchod importéru.
     *
     * @var list<string>
     */
    private const ACCOUNTING_ARCHIVE_RESTORE_ORDER = [
        'supplier',
        'accounting_periods',
        'chart_of_accounts',
        'currencies',
        'clients',
        'posting_rules',
        'cost_centers',
        'accounting_supplier_settings',
        'accounting_document_series',
        'invoices',
        'invoice_items',
        'purchase_invoices',
        'purchase_invoice_items',
        'assets',
        'asset_improvements',
        'depreciation_entries',
        'bank_statements',
        'bank_transactions',
        'client_bank_accounts',
        'journal_entries',
        'journal_entry_lines',
        'accounting_closing_steps',
        'payment_matches',
        'cash_registers',
        'cash_documents',
        'cash_document_vat_lines',
        'invoice_payments',
        'income_tax_returns',
        'tax_losses',
        'tax_loss_applications',
        'tax_advance_schedules',
        'warehouses',
        'stock_items',
        'manufacturers',
        'stock_media',
        'stock_categories',
        'stock_category_i18n',
        'stock_item_categories',
        'stock_tags',
        'stock_item_tags',
        'stock_attributes',
        'stock_attribute_options',
        'stock_attribute_i18n',
        'stock_item_attribute_values',
        'stock_fee_types',
        'stock_item_fees',
        'stock_item_prices',
        'stock_item_vendors',
        'stock_item_i18n',
        'stock_levels',
        'stock_documents',
        'stock_document_lines',
        'stock_landed_costs',
        'stock_takes',
        'stock_take_lines',
        'journal_entry_attachments',
        'exchange_rates',
    ];

    /**
     * První ověřená dávka používá stejné přímé tenant selektory jako účetní
     * ArchiveService. Přenosový profil zůstává neúplný, dokud nejsou explicitně
     * zařazené i všechny ostatní agendy aktuálního schématu.
     *
     * @var array<string,array<string,list<string>>>
     */
    private const DIRECT_TABLE_GROUPS = [
        'core' => [
            'clients' => ['id'],
            'client_bank_accounts' => ['id'],
            'currencies' => ['id'],
            'invoices' => ['id'],
            'purchase_invoices' => ['id'],
            'invoice_payments' => ['id'],
            'payment_matches' => ['id'],
        ],
        'accounting' => [
            'accounting_periods' => ['id'],
            'chart_of_accounts' => ['id'],
            'posting_rules' => ['id'],
            'cost_centers' => ['id'],
            'accounting_supplier_settings' => ['supplier_id'],
            'accounting_closing_steps' => ['id'],
            'accounting_document_series' => ['id'],
            'journal_entries' => ['id'],
            'journal_entry_lines' => ['id'],
            'assets' => ['id'],
            'small_assets' => ['id'],
            'asset_improvements' => ['id'],
            'depreciation_entries' => ['id'],
            'cash_registers' => ['id'],
            'cash_documents' => ['id'],
        ],
        'bank' => [
            'bank_statements' => ['id'],
        ],
        'tax' => [
            'income_tax_returns' => ['id'],
            'tax_losses' => ['id'],
            'tax_loss_applications' => ['id'],
            'tax_advance_schedules' => ['id'],
            'tax_submissions' => ['id'],
        ],
        'documents' => [
            'journal_entry_attachments' => ['id'],
        ],
        'stock' => [
            'warehouses' => ['id'],
            'purchase_orders' => ['id'],
            'purchase_order_lines' => ['id'],
            'stock_items' => ['id'],
            'manufacturers' => ['id'],
            'stock_media' => ['id'],
            'stock_item_categories' => ['stock_item_id', 'category_id'],
            'stock_tags' => ['id'],
            'stock_item_tags' => ['stock_item_id', 'tag_id'],
            'stock_attributes' => ['id'],
            'stock_attribute_options' => ['id'],
            'stock_item_attribute_values' => ['id'],
            'stock_fee_types' => ['id'],
            'stock_item_vendors' => ['id'],
            'stock_levels' => ['supplier_id', 'warehouse_id', 'stock_item_id'],
            'stock_documents' => ['id'],
            'stock_document_lines' => ['id'],
            'stock_landed_costs' => ['id'],
            'stock_takes' => ['id'],
            'stock_take_lines' => ['id'],
        ],
    ];

    /**
     * @var array<string,array{
     *   primary_key:list<string>,
     *   feature_group:string,
     *   path:list<array{
     *     from_column:string,
     *     to_table:string,
     *     to_column:string,
     *     reference?:string
     *   }>
     * }>
     */
    private const INDIRECT_TABLES = [
        'cash_document_vat_lines' => [
            'primary_key' => ['id'],
            'feature_group' => 'accounting',
            'path' => [
                ['from_column' => 'cash_document_id', 'to_table' => 'cash_documents', 'to_column' => 'id'],
                ['from_column' => 'supplier_id', 'to_table' => 'supplier', 'to_column' => 'id'],
            ],
        ],
        'bank_transactions' => [
            'primary_key' => ['id'],
            'feature_group' => 'bank',
            'path' => [
                ['from_column' => 'statement_id', 'to_table' => 'bank_statements', 'to_column' => 'id'],
                [
                    'from_column' => 'supplier_id',
                    'to_table' => 'supplier',
                    'to_column' => 'id',
                    'reference' => 'soft',
                ],
            ],
        ],
        'invoice_items' => [
            'primary_key' => ['id'],
            'feature_group' => 'core',
            'path' => [
                ['from_column' => 'invoice_id', 'to_table' => 'invoices', 'to_column' => 'id'],
                ['from_column' => 'supplier_id', 'to_table' => 'supplier', 'to_column' => 'id'],
            ],
        ],
        'purchase_invoice_items' => [
            'primary_key' => ['id'],
            'feature_group' => 'core',
            'path' => [
                ['from_column' => 'purchase_invoice_id', 'to_table' => 'purchase_invoices', 'to_column' => 'id'],
                ['from_column' => 'supplier_id', 'to_table' => 'supplier', 'to_column' => 'id'],
            ],
        ],
        'invoice_pdfs' => [
            'primary_key' => ['id'],
            'feature_group' => 'documents',
            'path' => [
                ['from_column' => 'invoice_id', 'to_table' => 'invoices', 'to_column' => 'id'],
                ['from_column' => 'supplier_id', 'to_table' => 'supplier', 'to_column' => 'id'],
            ],
        ],
        'invoice_attachments' => [
            'primary_key' => ['id'],
            'feature_group' => 'documents',
            'path' => [
                ['from_column' => 'invoice_id', 'to_table' => 'invoices', 'to_column' => 'id'],
                ['from_column' => 'supplier_id', 'to_table' => 'supplier', 'to_column' => 'id'],
            ],
        ],
    ];

    /** @var array<string,array<string,string>> */
    private const ACTOR_REFERENCES = [
        'bank_transactions' => [
            'matched_by' => 'map_existing_user_or_null',
        ],
        'bank_statements' => [
            'imported_by' => 'map_existing_user_or_null',
        ],
        'invoice_attachments' => [
            'uploaded_by' => 'map_existing_user_or_null',
        ],
        'invoice_payments' => [
            'created_by' => 'map_existing_user_or_null',
        ],
        'invoices' => [
            'created_by' => 'map_existing_user_or_null',
        ],
        'journal_entries' => [
            'posted_by' => 'map_existing_user_or_null',
        ],
        'journal_entry_attachments' => [
            'uploaded_by' => 'map_existing_user_or_null',
        ],
        'payment_matches' => [
            'matched_by_user_id' => 'map_existing_user_or_null',
        ],
        'purchase_invoices' => [
            'created_by' => 'map_existing_user_required',
        ],
        'small_assets' => [
            'created_by' => 'map_existing_user_or_null',
        ],
    ];

    /** @var array<string,array<string,string>> */
    private const SOFT_ACTOR_REFERENCES = [
        'purchase_orders' => [
            'confirmed_by' => 'map_existing_user_or_null',
            'closed_by' => 'map_existing_user_or_null',
            'cancelled_by' => 'map_existing_user_or_null',
            'created_by' => 'map_existing_user_or_null',
        ],
        'tax_submissions' => [
            'submitted_by' => 'map_existing_user_or_null',
            'generated_by' => 'map_existing_user_or_null',
        ],
    ];

    public static function draftV1(): TenantDataRegistry
    {
        $definitions = [
            self::supplier(),
            ...TenantDataSystemCatalog::definitions(),
            ...TenantDataIdentityCatalog::definitions(),
            ...TenantDataGlobalCatalog::definitions(),
            ...TenantDataSigningCatalog::definitions(),
            ...TenantDataDocumentCatalog::definitions(),
            ...TenantDataDocumentIntakeCatalog::definitions(),
            ...TenantDataAiCatalog::definitions(),
            ...TenantDataJobCatalog::definitions(),
            ...TenantDataBankMatchingCatalog::definitions(),
            ...TenantDataBankAutomationCatalog::definitions(),
            ...TenantDataStatementCatalog::definitions(),
            ...TenantDataJournalCatalog::definitions(),
            ...TenantDataBusinessCatalog::definitions(),
            ...TenantDataPurchaseOrderCatalog::definitions(),
            ...TenantDataEshopCatalog::definitions(),
            ...TenantDataLogbookCatalog::definitions(),
            ...TenantDataSettlementCatalog::definitions(),
            ...TenantDataPaymentOrderCatalog::definitions(),
            ...TenantDataIntegrationCatalog::definitions(),
        ];
        foreach (self::DIRECT_TABLE_GROUPS as $featureGroup => $tables) {
            foreach ($tables as $table => $primaryKey) {
                $definitions[] = self::tenantOwned(
                    $table,
                    $primaryKey,
                    $featureGroup,
                    self::secretPolicies($table),
                );
            }
        }
        foreach (self::INDIRECT_TABLES as $table => $details) {
            $definitions[] = self::tenantOwnedIndirect(
                $table,
                $details['primary_key'],
                $details['feature_group'],
                $details['path'],
                self::secretPolicies($table),
            );
        }
        $definitions[] = new TenantDataDefinition(
            'table:accounting_archives',
            TenantDataObjectKind::Table,
            TenantDataPolicy::RuntimeDerived,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'accounting',
                'reason' => 'generated_archive_metadata_and_local_file',
            ],
        );

        $definitions = self::withAccountingArchiveProfile($definitions);

        return new TenantDataRegistry(
            1,
            $definitions,
            [TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE],
        );
    }

    /**
     * @param list<TenantDataDefinition> $definitions
     * @return list<TenantDataDefinition>
     */
    private static function withAccountingArchiveProfile(array $definitions): array
    {
        $exportIsUnique = count(array_unique(
            self::ACCOUNTING_ARCHIVE_EXPORT_ORDER,
            SORT_STRING,
        )) === count(self::ACCOUNTING_ARCHIVE_EXPORT_ORDER);
        $restoreIsUnique = count(array_unique(
            self::ACCOUNTING_ARCHIVE_RESTORE_ORDER,
            SORT_STRING,
        )) === count(self::ACCOUNTING_ARCHIVE_RESTORE_ORDER);
        $sameTables = array_diff(
            self::ACCOUNTING_ARCHIVE_EXPORT_ORDER,
            self::ACCOUNTING_ARCHIVE_RESTORE_ORDER,
        ) === [] && array_diff(
            self::ACCOUNTING_ARCHIVE_RESTORE_ORDER,
            self::ACCOUNTING_ARCHIVE_EXPORT_ORDER,
        ) === [];
        if (!$exportIsUnique || !$restoreIsUnique || !$sameTables) {
            throw new \LogicException(
                'Export a obnova účetního archivu nemají stejnou sadu tabulek.',
            );
        }
        $byTable = [];
        foreach ($definitions as $index => $definition) {
            if (!str_starts_with($definition->key, 'table:')) {
                continue;
            }
            $byTable[substr($definition->key, strlen('table:'))] = $index;
        }

        foreach (self::ACCOUNTING_ARCHIVE_EXPORT_ORDER as $exportIndex => $table) {
            $definitionIndex = $byTable[$table] ?? null;
            $restoreIndex = array_search(
                $table,
                self::ACCOUNTING_ARCHIVE_RESTORE_ORDER,
                true,
            );
            if ($definitionIndex === null || $restoreIndex === false) {
                throw new \LogicException(
                    'Účetní archiv odkazuje na tabulku bez úplné registrace.',
                );
            }

            $definition = $definitions[$definitionIndex];
            $profiles = $definition->profiles;
            if (!in_array(
                TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
                $profiles,
                true,
            )) {
                $profiles[] = TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE;
            }
            $details = $definition->details;
            $details['accounting_archive'] = self::accountingArchiveDetails(
                $table,
                $exportIndex + 1,
                $restoreIndex + 1,
                $details,
            );
            $definitions[$definitionIndex] = new TenantDataDefinition(
                $definition->key,
                $definition->kind,
                $definition->policy,
                $profiles,
                $details,
            );
        }

        return array_values($definitions);
    }

    /**
     * @param array<string,mixed> $definitionDetails
     * @return array<string,mixed>
     */
    private static function accountingArchiveDetails(
        string $table,
        int $exportOrder,
        int $restoreOrder,
        array $definitionDetails,
    ): array {
        $details = [
            'export_order' => $exportOrder,
            'restore_order' => $restoreOrder,
            'selector' => match ($table) {
                'bank_transactions' => 'bank_transaction_relationships',
                'bank_statements' => 'bank_statement_relationships',
                'exchange_rates' => 'accounting_period_currency',
                default => 'ownership',
            },
            'omit_columns' => match ($table) {
                'supplier' => self::accountingArchiveSupplierOmissions(),
                'bank_statements' => ['file_content', 'pdf_content'],
                default => [],
            },
            'soft_references' => self::accountingArchiveSoftReferences($table),
        ];
        if (($definitionDetails['feature_group'] ?? null) === 'stock') {
            $details['feature_flag'] = 'stock_enabled';
        }
        return $details;
    }

    /** @return list<string> */
    private static function accountingArchiveSupplierOmissions(): array
    {
        return [
            'idoklad_client_id',
            'fakturoid_client_id',
            'fakturoid_access_token_expires_at',
        ];
    }

    /** @return array<string,string> */
    private static function accountingArchiveSoftReferences(string $table): array
    {
        return match ($table) {
            'stock_documents' => [
                'stock_take_id' => 'stock_takes',
                'reversal_document_id' => 'stock_documents',
            ],
            'stock_takes' => [
                'receipt_document_id' => 'stock_documents',
                'issue_document_id' => 'stock_documents',
            ],
            'tax_losses' => ['source_return_id' => 'income_tax_returns'],
            'tax_loss_applications' => [
                'applied_return_id' => 'income_tax_returns',
            ],
            'tax_advance_schedules' => [
                'source_return_id' => 'income_tax_returns',
            ],
            default => [],
        };
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
                'feature_group' => 'core',
                'ownership' => [
                    'strategy' => 'selected_supplier',
                    'column' => 'id',
                ],
                'secrets' => self::supplierSecretPolicies(),
                'post_import' => [
                    'disable_integrations' => true,
                    'force_columns' => [
                        'ai_assist_enabled' => false,
                        'ai_pseudo_salt' => null,
                        'ai_dpa_confirmations' => null,
                    ],
                    'state_transforms' => [
                        'accounting_activation_status' => [
                            'running' => 'failed',
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * @param list<string> $primaryKey
     * @param array<string,array<string,string>> $secrets
     */
    private static function tenantOwned(
        string $table,
        array $primaryKey,
        string $featureGroup,
        array $secrets,
    ): TenantDataDefinition {
        $details = [
            'primary_key' => $primaryKey,
            'feature_group' => $featureGroup,
            'ownership' => [
                'strategy' => 'supplier_id',
                'column' => 'supplier_id',
            ],
            'secrets' => $secrets,
        ];
        $actorReferences = self::actorReferences($table);
        if ($actorReferences !== []) {
            $details['actor_references'] = $actorReferences;
        }
        $softActorReferences = self::softActorReferences($table);
        if ($softActorReferences !== []) {
            $details['soft_actor_references'] = $softActorReferences;
        }
        $importIdentity = self::importIdentity($table);
        if ($importIdentity !== null) {
            $details['import_identity'] = $importIdentity;
        }
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            $details,
        );
    }

    /**
     * @param list<string> $primaryKey
     * @param list<array{
     *   from_column:string,
     *   to_table:string,
     *   to_column:string,
     *   reference?:string
     * }> $path
     * @param array<string,array<string,string>> $secrets
     */
    private static function tenantOwnedIndirect(
        string $table,
        array $primaryKey,
        string $featureGroup,
        array $path,
        array $secrets,
    ): TenantDataDefinition {
        $details = [
            'primary_key' => $primaryKey,
            'feature_group' => $featureGroup,
            'ownership' => [
                'strategy' => 'foreign_key_path',
                'path' => $path,
            ],
            'secrets' => $secrets,
        ];
        $actorReferences = self::actorReferences($table);
        if ($actorReferences !== []) {
            $details['actor_references'] = $actorReferences;
        }
        $softActorReferences = self::softActorReferences($table);
        if ($softActorReferences !== []) {
            $details['soft_actor_references'] = $softActorReferences;
        }
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwnedIndirect,
            [TenantDataRegistry::TRANSFER_PROFILE],
            $details,
        );
    }

    /** @return array<string,array{strategy:string}> */
    private static function actorReferences(string $table): array
    {
        $references = [];
        foreach (self::ACTOR_REFERENCES[$table] ?? [] as $column => $strategy) {
            $references[$column] = ['strategy' => $strategy];
        }
        return $references;
    }

    /** @return array<string,array{strategy:string}> */
    private static function softActorReferences(string $table): array
    {
        $references = [];
        foreach (self::SOFT_ACTOR_REFERENCES[$table] ?? [] as $column => $strategy) {
            $references[$column] = ['strategy' => $strategy];
        }
        return $references;
    }

    /**
     * @return array{
     *   strategy:string,
     *   keys:list<string>,
     *   missing_row:string,
     *   existing_row:string
     * }|null
     */
    private static function importIdentity(string $table): ?array
    {
        return match ($table) {
            'chart_of_accounts' => [
                'strategy' => 'tenant_natural_key',
                'keys' => ['supplier_id', 'account_code'],
                'missing_row' => 'create_with_mapped_tenant',
                'existing_row' => 'reuse_target_id_and_apply_source',
            ],
            'cost_centers' => [
                'strategy' => 'tenant_natural_key',
                'keys' => ['supplier_id', 'code'],
                'missing_row' => 'create_with_mapped_tenant',
                'existing_row' => 'reuse_target_id_and_apply_source',
            ],
            default => null,
        };
    }

    /** @return array<string,array<string,string>> */
    private static function secretPolicies(string $table): array
    {
        if ($table !== 'invoices') {
            return [];
        }
        return [
            'approval_token' => ['policy' => 'omit_and_reconfigure'],
            'approval_token_expires_at' => [
                'policy' => 'not_secret',
                'reason' => 'expiry_timestamp_reset_with_token',
            ],
            'public_token' => ['policy' => 'omit_and_reconfigure'],
        ];
    }

    /** @return array<string,array<string,string>> */
    private static function supplierSecretPolicies(): array
    {
        return [
            'idoklad_client_secret_enc' => ['policy' => 'reencrypt_v1'],
            'ai_pseudo_salt' => ['policy' => 'omit_and_reconfigure'],
            // Legacy iDoklad access token je plaintext cache. Do snapshotu
            // nepatří; cíl si vyžádá novou autorizaci.
            'idoklad_access_token' => ['policy' => 'omit_and_reconfigure'],
            'idoklad_token_expires_at' => [
                'policy' => 'not_secret',
                'reason' => 'expiry_timestamp_reset_with_token',
            ],
            'fakturoid_api_key_enc' => ['policy' => 'reencrypt_v1'],
            'anthropic_api_key_enc' => ['policy' => 'reencrypt_v1'],
            'fakturoid_client_secret_enc' => ['policy' => 'reencrypt_v1'],
            // OAuth access token je obnovitelná cache, nikoli dlouhodobá
            // konfigurace integrace.
            'fakturoid_access_token_enc' => ['policy' => 'omit_and_reconfigure'],
            'fakturoid_access_token_expires_at' => [
                'policy' => 'not_secret',
                'reason' => 'expiry_timestamp_reset_with_token',
            ],
            'azure_openai_api_key_enc' => ['policy' => 'reencrypt_v1'],
            'openai_api_key_enc' => ['policy' => 'reencrypt_v1'],
            'gemini_api_key_enc' => ['policy' => 'reencrypt_v1'],
        ];
    }
}
