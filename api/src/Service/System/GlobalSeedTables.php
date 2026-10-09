<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

/**
 * Jediné místo, které ví, které tabulky jsou GLOBÁLNÍ (legislativní číselníky,
 * katalogy, evidence schématu, provozní údaje instance) a které jsou uživatelská data.
 *
 * Proč to není jen seznam v `api/bin/reset.php`: reset maže VŠECHNY tabulky kromě
 * keep-listu a globální seedy z migrací po smazání nikdo nevrátí, protože migrace
 * jsou evidované jako proběhlé. Keep-list ve skriptu tiše zaostal za migracemi
 * (svátky 1781, katalog klíčových slov nákladů 1740) a lhůty mezd pak běžely
 * z pojistky v kódu. Konstanty tady čte reset, dotah seedů
 * ({@see GlobalSeedRestorer}), Diagnostika a guard `ResetKeepsGlobalCodebooksTest`,
 * který každou tabulku plněnou migrací nutí zařadit sem.
 */
final class GlobalSeedTables
{
    /**
     * Tabulky, které reset ponechá CELÉ. Hodnota = proč.
     *
     * @var array<string, string>
     */
    public const RESET_KEEP = [
        'countries'      => 'globální číselník zemí',
        'vat_rates'      => 'globální sazby DPH',
        'units'          => 'globální měrné jednotky',
        'tax_constants'  => 'globální daňové konstanty',
        'exchange_rates' => 'cache kurzů ČNB, drahé refetchnout',
        'migrations'     => 'evidence schématu',
        // Značky jednorázových datových převodů v migracích (1194/1204). Patří
        // k evidenci schématu: bez nich by opakované spuštění migrace převod
        // zopakovalo nad daty, která už mají jiný tvar.
        'payroll_data_migration_markers' => 'evidence jednorázových převodů v migracích',
        // Účetní globální seedy z migrací.
        'statement_versions'    => 'definice výkazů (rozvaha/VZZ, vyhl. 500/2002), seed 1012',
        'statement_rows'        => 'řádky výkazů, seed 1012',
        'statement_account_map' => 'mapování účtů na řádky výkazů, seed 1012',
        'cnb_repo_rates'        => 'repo sazby ČNB pro úrok z prodlení, seed 1048',
        'bank_rule_template_defaults' => 'výchozí katalog bankovních pravidel pro nové firmy, seed 1748',
        'remittance_map'        => 'globální mapa odvodů na účty ČNB, seed 1056',
        // Legislativní číselník sazeb členských států (seed 1152/1292/1294, dotah 1319).
        // Když zmizí, import i vystavení odmítne každý doklad se sazbou vyšší než 0 %.
        'oss_member_state_rates' => 'sazby DPH členských států EU',
        // Svátky podle z. 245/2000 Sb. posouvají přes § 33 odst. 4 daňového řádu
        // všechny lhůty podání i odvodů ze mzdy.
        'public_holidays' => 'státní a ostatní svátky, seed 1781',
        // Globální katalog klíčových slov pro průvodce nastavením účtování.
        'expense_keyword_catalog' => 'katalog klíčových slov nákladů, seed 1740/1742/1743',
        // Provozní údaje INSTANCE, ne uživatelská data. `license` by se po smazání
        // založila znovu jako TRIAL, kontrakt záloh by dostal výchozí hodnoty místo
        // sjednaných a bez `cron_settings` spadne režim plánovaných úloh na
        // `individual`, takže na instalaci s dispatcherem hostingu NEBĚŽÍ NIC.
        'license'                  =>'licence instance, znovuzaložení ji degraduje na trial',
        'backup_schedule_contract' => 'parametry sjednané s poskytovatelem hostingu',
        'instance_storage_usage'   => 'podklad pro měření úložiště u poskytovatele',
        'cron_settings'            => 'režim plánovaných úloh, migrace 1184/1320',
        'mcp_server_settings'      => 'stav serverového MCP, migrace ho po resetu znovu nevloží',
    ];

    /**
     * Cache, které reset ponechá jen s `--keep-cache`.
     *
     * @var list<string>
     */
    public const RESET_KEEP_CACHE = ['ares_cache', 'vies_cache', 'crpdph_cache'];

    /**
     * Tabulky se smíšeným obsahem: reset smaže jen řádky splňující podmínku,
     * globální seed zůstane.
     *
     * @var array<string, string>
     */
    public const RESET_PARTIAL = [
        'vat_classifications'         => 'supplier_id IS NOT NULL',
        'bank_email_notice_providers' => 'supplier_id IS NOT NULL',
        'posting_rules'               => 'supplier_id IS NOT NULL',
        // Příjemci podání (ČSSZ e-Podání, zdravotní pojišťovny vč. ID datových schránek),
        // seed 1381/1410/1535. Per-tenant override firmy se maže.
        'submission_recipients'       => 'supplier_id IS NOT NULL',
        'role_permissions'            => 'role_id NOT IN (SELECT id FROM roles WHERE system_key IS NOT NULL)',
        'roles'                       => 'system_key IS NULL',
    ];

    /**
     * Tabulky, které migrace plní a které nemají `supplier_id`, a přesto je reset
     * ZÁMĚRNĚ maže. Hodnota = proč. Guard test tu výjimku uděluje jen jmenovitě.
     *
     * @var array<string, string>
     */
    public const RESET_WIPES = [
        'activity_log_chain_head' => 'hlava hash řetězu musí padnout spolu s auditním logem, jinak by ukazovala na smazané záznamy',
        'cron_heartbeat'          => 'obnoví se sám při nejbližším běhu plánovaných úloh',
        'client_revenue_cache'    => 'přepočitatelná cache obratů klientů (per-tenant přes klienta)',
        'project_revenue_cache'   => 'přepočitatelná cache obratů projektů (per-tenant přes projekt)',
        'bank_counterparty_observations' => 'per-tenant data přes bank_counterparty_map',
        'signing_credentials'     => 'per-tenant klíče přes signing_profiles',
    ];

    /**
     * Globální číselníky, které nesmí být prázdné. Hodnota = podmínka globálních
     * řádků (null = celá tabulka). Čte je Diagnostika.
     *
     * @var array<string, string|null>
     */
    public const CODEBOOKS = [
        'countries'                   => null,
        'vat_rates'                   => null,
        'units'                       => null,
        'statement_versions'          => null,
        'statement_rows'              => null,
        'statement_account_map'       => null,
        'cnb_repo_rates'              => null,
        'bank_rule_template_defaults' => null,
        'remittance_map'              => null,
        'oss_member_state_rates'      => 'is_custom = 0',
        'public_holidays'             => null,
        'expense_keyword_catalog'     => null,
        'vat_classifications'         => 'supplier_id IS NULL',
        'posting_rules'               => 'supplier_id IS NULL',
        'bank_email_notice_providers' => 'supplier_id IS NULL',
        'submission_recipients'       => 'supplier_id IS NULL',
        'roles'                       => 'system_key IS NOT NULL',
    ];

    /**
     * Seedy, které umí {@see GlobalSeedRestorer} přehrát z migrací: tabulka =>
     * [podmínka globálních řádků nebo null, migrace v pořadí]. Přehrávají se jen
     * INSERT/UPDATE statementy mířící na tabulku; guard test hlídá, že seznam migrací
     * je úplný, INSERTy jsou idempotentní a žádná migrace z tabulky nemaže.
     *
     * Sazby členských států tu nejsou: mají vlastní sebeopravnou migraci 1319
     * a dotah `backfill-oss-rates.php`.
     *
     * @var array<string, array{global: string|null, migrations: list<string>}>
     */
    public const RESTORABLE = [
        'public_holidays' => [
            'global'     => null,
            'migrations' => ['1781_public_holidays.sql'],
        ],
        'expense_keyword_catalog' => [
            'global'     => null,
            'migrations' => [
                '1740_accounting_setup_assistant.sql',
                '1742_accounting_setup_catalog_languages.sql',
                '1743_accounting_setup_catalog_vetoes.sql',
            ],
        ],
        'submission_recipients' => [
            'global'     => 'supplier_id IS NULL',
            'migrations' => [
                '1381_submission_channels.sql',
                '1410_submission_recipients_cssz_epodani.sql',
                '1535_health_insurer_recipient_codebook.sql',
                '1536_vozp_payroll_submission_databox.sql',
            ],
        ],
        'payroll_data_migration_markers' => [
            'global'     => null,
            'migrations' => [
                '1194_payroll_employer_payment_identifiers.sql',
                '1204_payroll_employer_payment_identifiers_marker.sql',
            ],
        ],
    ];

    /**
     * Režim `reset.php --keep-users-supplier`: tabulky, které nad rámec RESET_KEEP
     * zůstanou CELÉ, protože jsou účtem, firmou nebo její KONFIGURACÍ. Smaže se jen
     * byznys data ({@see self::RESET_KEEP_USERS_SUPPLIER_WIPES}). Tabulka z
     * RESET_PARTIAL, která je tady, se v tomhle režimu ponechá celá včetně
     * per-tenant override (ten je taky konfigurace firmy).
     *
     * Guard `ResetKeepUsersSupplierClassificationTest` nutí každou tabulku schématu
     * zařadit sem, nebo do seznamu mazaných. Nová tabulka bez zařazení test shodí:
     * dřív tenhle režim tiše mazal i historii plátcovství, režim a období účetnictví
     * a celé nastavení mezd, protože keep-list vznikl dřív než ty tabulky.
     *
     * @var list<string>
     */
    public const RESET_KEEP_USERS_SUPPLIER = [
        // Účet, přihlášení a jeho druhé faktory. Bez passkeys a záchranných kódů by
        // se uživatel s vynuceným MFA po resetu nepřihlásil.
        'users', 'sessions', 'trusted_devices', 'login_otps',
        'webauthn_credentials', 'mfa_recovery_codes', 'totp_used_steps',
        'user_preferences', 'saved_filters',
        // Dynamické role a per-firma override zůstávají spolu s uživateli.
        'roles', 'role_permissions', 'user_suppliers',
        // Identita firmy, skupiny firem, přihlašovací domény, měny, číslování dokladů.
        'supplier', 'supplier_groups', 'supplier_domains', 'branding_profiles',
        'currencies', 'invoice_counters', 'purchase_invoice_counters', 'app_meta',
        // API tokeny (PAT) včetně povolených IP.
        'api_tokens', 'api_token_ips',
        'mcp_server_settings', 'mcp_oauth_clients', 'mcp_oauth_grants',
        // Podepisování PDF a podání (konfigurace + klíče).
        'signing_profiles', 'signing_credentials', 'signing_settings',
        'signature_role_profiles', 'signature_user_profiles', 'signature_document_overrides',
        'pdf_signature_output_settings',
        'epo_signing_credentials', 'epo_signing_credential_suppliers',
        'tax_submission_settings', 'submission_channel_credentials',
        'submission_inbox_storage_settings', 'submission_isds_mobile_credentials',
        'isds_gateway_registrations', 'submission_recipients',
        'submission_inbox_categories', 'submission_inbox_category_rules',
        // E-mail / bankovní avíza (konfigurace, NE zpracované zprávy).
        'bank_email_imap_settings', 'bank_email_account_mappings', 'email_templates', 'email_profiles',
        'bank_email_notice_providers', 'bank_email_notice_provider_overrides',
        // Vlastní bankovní účty, karty a napojení bank. Bez účtů by
        // bank_email_account_mappings ukazovalo na neexistující účty a firma by neměla
        // kam účtovat banku (analytic_suffix, viz 1109). Hraniční: credit_card_accounts
        // nese opening_entry_id na smazaný zápis počátečního stavu; účet je přesto
        // konfigurace a počáteční stav se po převodu zaúčtuje znovu.
        'supplier_bank_accounts', 'payment_cards', 'credit_card_accounts',
        'credit_card_settings', 'card_clearing_settings',
        'bank_connections', 'bank_oauth_clients', 'bank_oauth_sessions',
        // Napojení e-shopů a platebních bran (přístupy a mapování, ne stažená data).
        // Kurzory synchronizace (integration_*_state) se mažou, aby se data stáhla znovu.
        'integration_connections', 'external_bank_account_mappings',
        'gopay_settings', 'shoptet_settings', 'catalog_import_profiles', 'catalog_import_sources',
        // DPH a daňová identita firmy v čase. Historie plátcovství je jediný zdroj
        // VatStatusService; bez ní by firma byla neplátce za všechna období.
        'supplier_vat_status_history', 'supplier_tax_representation_history',
        'supplier_osvc_month_statuses', 'vat_classifications',
        // Hraniční: zálohový koeficient § 76 zadává účetní ručně a z dat ho dopočítat
        // nejde; vypořádání (final_percent) se při novém výpočtu přepíše.
        'vat_coefficients',
        // Hraniční: zálohy na daň stanovené správcem daně jsou vstup, ne výsledek
        // výpočtu. Rozpis záloh s párováním plateb (tax_advance_schedules) se maže.
        'tax_advance_overrides',
        // Daňové profily VČETNĚ vazebních tabulek — samotné tax_profiles bez dětí
        // by zůstaly jako neúplný profil (děti, manžel/ka, činnosti).
        'tax_profiles', 'tax_profile_activities', 'tax_profile_children',
        'tax_profile_child_months', 'tax_profile_spouse_claims',
        // ÚČETNÍ KONFIGURACE. Účtová osnova musí přežít spolu se supplierem: firma si
        // nese double_entry, ale ChartOfAccountsSeeder se volá jen z aktivace účetnictví
        // nebo změny režimu. Režim v čase a účetní období jsou konfigurace firmy; bez
        // období by firma po resetu neměla kam účtovat. Hraniční: kategorie účetní
        // jednotky je zmrazená k období, které zůstává, proto zůstává s ním.
        'supplier_accounting_modes', 'accounting_periods', 'entity_category_history',
        'chart_of_accounts', 'accounting_supplier_settings', 'accounting_document_series',
        'accounting_fixed_exchange_rates',
        'auto_posting_policy', 'expense_classification_rules', 'posting_rules',
        'journal_entry_templates', 'journal_entry_template_lines',
        'bank_posting_rules', 'bank_rule_templates',
        'statement_account_overrides', 'statement_function_map',
        'cost_centers', 'dimension_types', 'dimension_values', 'dimension_account_rules',
        'dimension_account_map',
        // Per-supplier číselníky.
        'expense_categories', 'revenue_categories', 'trip_categories',
        // Předvolby AI (ztlumené zdroje návrhů), ne návrhy samotné.
        'ai_source_mutes',
        // NASTAVENÍ MEZD: stav modulu a jeho kvalifikace, zaměstnavatelská politika
        // s auditem, účtárny a jejich registrace, instituce a jejich účty, sazby
        // úrazového pojištění, mzdové složky a jejich mapování do JMHZ, střediska,
        // matice agend, skartační lhůty, profily importů a podpisů podání. Hraniční:
        // potvrzené návrhy předkontací mezd (payroll_posting_map_proposals) nesou
        // mapování mzdových složek na účty, tedy předkontaci mezd.
        'payroll_module_state', 'payroll_production_qualifications',
        'payroll_employer_settings', 'payroll_employer_policies', 'payroll_employer_policy_audit',
        'payroll_offices', 'payroll_office_registration_versions', 'payroll_regzel_employer_profiles',
        'payroll_institutions', 'payroll_institution_accounts', 'payroll_accident_insurance_rates',
        'payroll_component_definitions', 'payroll_component_jmhz_mappings',
        'payroll_posting_map_proposals', 'payroll_dimensions', 'payroll_agenda_matrix',
        'payroll_retention_policies', 'payroll_import_profiles', 'payroll_submission_signing_profiles',
        // Trvale skrytá varování mzdových běhů — rozhodnutí účetní o tom, co je
        // ve firmě v pořádku, ne výsledek výpočtu.
        'payroll_warning_suppressions',
        // Globální legislativa mezd a JMHZ (bez supplier_id): pravidla a číselníky
        // specifikace MPSV nejsou data firmy a jejich znovunačtení je drahé.
        'payroll_rulesets', 'payroll_ruleset_audit',
        'payroll_jmhz_spec_packages', 'payroll_jmhz_codebooks', 'payroll_jmhz_codebook_entries',
        'payroll_jmhz_dictionary_attributes', 'payroll_jmhz_field_requirements',
        'payroll_jmhz_control_catalogs', 'payroll_jmhz_control_definitions',
        'payroll_jmhz_control_attribute_refs', 'payroll_jmhz_control_parameters',
        'payroll_jmhz_control_parameter_refs', 'payroll_jmhz_control_parameter_values',
        'payroll_jmhz_interaction_definitions', 'payroll_jmhz_interaction_attribute_refs',
        'payroll_jmhz_master_attribute_axis', 'payroll_jmhz_matrix_evidence_axes',
        'payroll_jmhz_matrix_evidence_members', 'payroll_jmhz_requirement_matrices',
        'payroll_jmhz_scenario_catalogs', 'payroll_jmhz_scenario_definitions',
        // Globální cache kurzů ECB, stejně jako exchange_rates.
        'ecb_exchange_rates', 'ecb_exchange_rate_days',
    ];

    /**
     * Režim `--keep-users-supplier`: tabulky, které se mažou, protože jsou byznys
     * data, jejich evidence nebo provozní stopa. Seznam je úplný ZÁMĚRNĚ: guard
     * vyžaduje, aby každá tabulka schématu byla buď tady, nebo v keep-listu.
     *
     * Hraniční případy, které se mažou, i když vypadají jako konfigurace:
     * - sklady, stromy kategorií, katalog, ceníky, pokladny, vozidla, složky a štítky
     *   dokumentů: kontejnery byznys dat, která režim maže. Hlavně `sample_data_entries`
     *   se maže, takže kontejner z ukázkových dat by přežil BEZ evidence. Sample
     *   generátor pak padal na `uq_wh_supplier_code` („Duplicate entry '1-HLAVNI'")
     *   a purge už sirotka neuměl uklidit.
     * - pracovní kalendáře, směny a pravidla výplat mají employment/employee (osobní data).
     * - dimension_defaults jsou výchozí dimenze KLIENTA a projektu, které se mažou.
     * - payroll_period_ownership, accounting_setup_* a tax_advance_schedules jsou stopa
     *   převodu, průvodce nebo párování nad smazanými daty; převod je založí znovu.
     *
     * @var list<string>
     */
    public const RESET_KEEP_USERS_SUPPLIER_WIPES = [
        'accounting_archives', 'accounting_backfill_jobs', 'accounting_balance_inventory',
        'accounting_balance_inventory_items', 'accounting_closing_steps', 'accounting_corrections',
        'accounting_opening_balances', 'accounting_receivable_provisions',
        'accounting_reclassification_items', 'accounting_setup_proposals', 'accounting_setup_rule_bundles',
        'accounting_setup_runs',
        'activity_log', 'activity_log_chain_head',
        'ai_daily_usage', 'ai_embeddings', 'ai_jobs', 'ai_metrics', 'ai_suggestions',
        'api_request_log',
        'asset_improvements',
        'assets',
        'attachment_checks',
        'automation_recommendation_coverage', 'automation_recommendation_items',
        'automation_recommendation_snapshots',
        'bank_api_evidence_months', 'bank_api_months', 'bank_connector_cooldowns', 'bank_counterparty_map',
        'bank_counterparty_observations', 'bank_email_attachment_ingests', 'bank_email_processed_messages',
        'bank_match_audit', 'bank_match_suggestions', 'bank_payment_order_submissions',
        'bank_posting_suggestions', 'bank_statements', 'bank_transaction_imports', 'bank_transactions',
        'bank_notice_ignore_transfers',
        'bank_transfer_matches',
        'cars',
        'cash_document_vat_lines', 'cash_documents', 'cash_registers',
        'catalog_job_items', 'catalog_job_lanes', 'catalog_jobs',
        'client_bank_accounts', 'client_email_contacts', 'client_revenue_cache',
        'clients',
        'crm_action_item_dismissals', 'crm_monthly_summary',
        'cron_dispatch_claims', 'cron_heartbeat', 'cron_runs',
        'de_movement_classification', 'de_movement_classification_history',
        'depreciation_entries',
        'dimension_defaults',
        'document_dimension_splits', 'document_dimensions', 'document_dms_messages', 'document_embeddings',
        'document_extractions', 'document_files', 'document_folders', 'document_links', 'document_requests',
        'document_tag_map', 'document_tags',
        'documents',
        'external_entity_map',
        'fuelings',
        'fulfillment_return_items', 'fulfillment_returns', 'fulfillment_scan_operations',
        'fulfillment_shipment_items', 'fulfillment_shipments', 'fulfillment_task_lines', 'fulfillment_tasks',
        'gopay_clearings', 'gopay_movements',
        'import_jobs',
        'income_tax_finalization_overrides', 'income_tax_return_snapshots', 'income_tax_returns',
        'instance_exports',
        'integration_change_log', 'integration_change_state', 'integration_inbox', 'integration_inbox_state',
        'integration_outbox',
        'invoice_attachments', 'invoice_items', 'invoice_payment_schedule', 'invoice_payments',
        'invoice_pdfs', 'invoice_settlements',
        'invoices',
        'isds_gateway_sessions',
        'journal_entries', 'journal_entry_attachments', 'journal_entry_document_links',
        'journal_entry_line_dimension_splits', 'journal_entry_line_dimensions', 'journal_entry_lines',
        'journal_entry_notes', 'journal_integrity_findings', 'journal_line_pairing_items',
        'journal_line_pairings',
        'logbook_fuel_scans',
        'login_attempts',
        'mail_outbox', 'mail_rate_limit_events', 'mail_send_log',
        'mcp_oauth_codes',
        'manufacturers',
        'mfa_step_up_proofs',
        'migration_batch_items', 'migration_parallel_checks',
        'money_s3_import_map', 'money_s3_imports',
        'monthly_report_sends',
        'offset_agreement_items', 'offset_agreements',
        'oss_filing_evidence',
        'other_item_allocations', 'other_item_installments', 'other_item_posting_lines',
        'other_item_schedule_occurrences', 'other_item_schedules', 'other_items',
        'password_resets',
        'payment_matches', 'payment_order_items', 'payment_orders',
        'payroll_absences', 'payroll_annual_document_batch_attempts', 'payroll_annual_document_batch_items',
        'payroll_annual_document_batches', 'payroll_annual_document_revisions',
        'payroll_annual_document_sources', 'payroll_annual_settlement_certificates',
        'payroll_annual_settlement_other_caregivers', 'payroll_annual_settlement_outcomes',
        'payroll_annual_settlement_requests', 'payroll_attendance_import_rows', 'payroll_attendance_imports',
        'payroll_average_earning_snapshots', 'payroll_benefit_accumulators',
        'payroll_business_trip_free_meals', 'payroll_business_trip_items', 'payroll_business_trips',
        'payroll_calendar_days', 'payroll_deduction_agreement_versions', 'payroll_deduction_agreements',
        'payroll_deduction_ledger', 'payroll_dependants', 'payroll_discount_intents',
        'payroll_personnel_documents', 'payroll_personnel_notes',
        'payroll_document_access_codes', 'payroll_document_access_links', 'payroll_document_access_sessions',
        'payroll_document_batch_attempts', 'payroll_document_batch_items', 'payroll_document_batches',
        'payroll_document_data_keys', 'payroll_document_delivery_events', 'payroll_document_dms_links',
        'payroll_document_download_grants', 'payroll_eldp_manual_completions',
        'payroll_eldp_statement_claims', 'payroll_eldp_statement_sources', 'payroll_eldp_statements',
        'payroll_employee_profiles', 'payroll_employees', 'payroll_employment_checklist_items',
        'payroll_employment_deferred_incomes', 'payroll_employment_dimensions', 'payroll_employment_events',
        'payroll_employment_exit_revisions', 'payroll_employment_external_ids',
        'payroll_employment_surcharge_policies', 'payroll_employment_survivors',
        'payroll_employment_terminations', 'payroll_employment_terms', 'payroll_employments',
        'payroll_enforcement_allocations', 'payroll_enforcement_case_documents',
        'payroll_enforcement_case_parties', 'payroll_enforcement_cases',
        'payroll_enforcement_claim_breakdowns', 'payroll_enforcement_claims',
        'payroll_enforcement_dependants', 'payroll_enforcement_events', 'payroll_enforcement_ledger',
        'payroll_enforcement_month_results', 'payroll_enforcement_person_month_evidence',
        'payroll_enforcement_recipient_instructions', 'payroll_enforcement_termination_notices',
        'payroll_enforcement_xmlzam_dispatches', 'payroll_enforcement_xmlzam_requests',
        'payroll_enforcement_xmlzam_responses', 'payroll_erasure_proposal_items',
        'payroll_erasure_proposals', 'payroll_external_jmhz_submission_forms',
        'payroll_external_jmhz_submissions', 'payroll_generated_document_hidden',
        'payroll_generated_documents', 'payroll_identity_resolution_tasks', 'payroll_import_links',
        'payroll_imported_jmhz_protocols', 'payroll_input_import_rows', 'payroll_input_imports',
        'payroll_inputs', 'payroll_insolvency_payment_instructions', 'payroll_jmhz_deferral_submissions',
        'payroll_jmhz_deferrals', 'payroll_jmhz_eldp_evidence_snapshots',
        'payroll_jmhz_eldp_idempotency_claims', 'payroll_jmhz_employer_annual_evidence',
        'payroll_jmhz_ordinary_evidence_idempotency_claims', 'payroll_jmhz_ordinary_evidence_snapshots',
        'payroll_jmhz_preparation_idempotency_claims', 'payroll_jmhz_preparation_snapshots',
        'payroll_jmhz_protocol_form_outcomes', 'payroll_jmhz_work_month_revisions',
        'payroll_leave_entitlement_snapshots', 'payroll_leave_ledger', 'payroll_migration_reference_totals',
        'payroll_takeover_invariant_checks',
        'payroll_monthly_records', 'payroll_net_results', 'payroll_obligations',
        'payroll_operational_reconciliation_issue_events', 'payroll_operational_reconciliation_issues',
        'payroll_overtime_averaging_periods', 'payroll_overtime_compensations', 'payroll_overtime_consents',
        'payroll_overtime_protections', 'payroll_payment_allocations', 'payroll_payment_batch_discards',
        'payroll_payment_batches',
        'payroll_payment_export_download_grants', 'payroll_payment_export_hidden',
        'payroll_payment_export_idempotency_keys', 'payroll_payment_exports', 'payroll_payment_items',
        'payroll_payment_liabilities', 'payroll_payment_match_postings', 'payroll_payment_matches',
        'payroll_payment_settlement_signals', 'payroll_payout_allocations', 'payroll_payout_rules',
        'payroll_period_export_download_grants', 'payroll_period_export_job_attempts',
        'payroll_period_export_job_part_attempts', 'payroll_period_export_job_parts',
        'payroll_period_export_jobs', 'payroll_period_exports', 'payroll_period_ownership',
        'payroll_person_accounts', 'payroll_person_addresses', 'payroll_person_contacts',
        'payroll_person_external_ids', 'payroll_person_foreign_permits',
        'payroll_person_health_coverage_history', 'payroll_person_health_minimum_reductions',
        'payroll_person_health_month_evidence', 'payroll_person_health_other_employer_bases',
        'payroll_person_identifiers', 'payroll_person_identity_history',
        'payroll_person_pension_age', 'payroll_person_pensions',
        'payroll_person_social_discount_claims', 'payroll_person_social_jurisdictions',
        'payroll_person_tax_child_claims', 'payroll_person_tax_credit_claims',
        'payroll_person_tax_declarations', 'payroll_person_tax_residences', 'payroll_posting_allocations',
        'payroll_posting_batches', 'payroll_production_qualification_documents',
        'payroll_recurring_components', 'payroll_registration_a1_profiles',
        'payroll_registration_a2_evidence_ledger', 'payroll_registration_change_proposals',
        'payroll_registration_change_scans', 'payroll_registration_event_snapshots',
        'payroll_registration_identity_snapshots', 'payroll_regzel_payload_snapshots',
        'payroll_risky_savings_contributions', 'payroll_risky_savings_evidence', 'payroll_run_commands',
        'payroll_run_employments', 'payroll_run_events', 'payroll_run_persons', 'payroll_run_revisions',
        'payroll_run_validations', 'payroll_runs', 'payroll_shifts', 'payroll_sickness_case_decisive_months',
        'payroll_sickness_case_work_days', 'payroll_sickness_cases',
        'payroll_sickness_compensation_segments', 'payroll_sickness_events',
        'payroll_sickness_input_materializations', 'payroll_statutory_accumulator_entries',
        'payroll_statutory_accumulator_openings', 'payroll_statutory_obligation_evidence',
        'payroll_statutory_person_results', 'payroll_statutory_relationship_results',
        'payroll_statutory_results', 'payroll_submission_artifact_download_grants',
        'payroll_submission_artifacts', 'payroll_submission_deadlines', 'payroll_submission_inbox_items',
        'payroll_submission_issues', 'payroll_submission_manual_acceptances', 'payroll_submission_parts',
        'payroll_submission_receipts',
        'payroll_submission_transport_attempts', 'payroll_submissions',
        'payroll_surcharge_input_materializations', 'payroll_surcharge_period_claims',
        'payroll_takeover_payment_evidence', 'payroll_takeover_runs',
        'payroll_taxable_income_confirmation_requests', 'payroll_pension_requests', 'payroll_time_entries', 'payroll_time_import_errors',
        'payroll_time_imports', 'payroll_time_month_events', 'payroll_time_month_import_summaries',
        'payroll_time_months', 'payroll_travel_compensation_links', 'payroll_wage_statement_revisions',
        'payroll_work_calendars', 'payroll_year_closures',
        'abra_flexi_connections', 'abra_flexi_import_map', 'abra_flexi_imports',
        'pohoda_import_map', 'pohoda_imports',
        'premier_import_map', 'premier_imports',
        'price_list_customer_overrides', 'price_list_item_prices', 'price_list_items',
        'product_assemblies', 'product_master_axes', 'product_master_i18n', 'product_masters',
        'product_set_revisions', 'product_sets', 'product_variant_i18n_inheritance',
        'product_variant_options', 'product_variants',
        'project_billing_emails', 'project_revenue_cache',
        'projects',
        'purchase_invoice_approvals', 'purchase_invoice_inbox_dismissed',
        'purchase_invoice_items', 'purchase_invoice_submission_dimensions', 'purchase_invoice_submissions',
        'purchase_invoice_vat_allocations',
        'purchase_invoices', 'purchase_order_invoice_links', 'purchase_order_lines', 'purchase_orders',
        'rate_limit_counters',
        'recurring_invoice_template_items', 'recurring_invoice_templates',
        'retention_holds',
        'sales_order_fulfillment_links', 'sales_order_invoice_links', 'sales_order_lines',
        'sales_order_operation_keys', 'sales_order_reservations', 'sales_order_returns', 'sales_orders',
        'sample_data_entries',
        'scan_batch_items', 'scan_matches',
        'shoptet_import_batches', 'shoptet_orders',
        'small_assets',
        'statement_notes',
        'stereo_nx_import_map',
        'stock_attribute_i18n', 'stock_attribute_options', 'stock_attributes', 'stock_categories',
        'stock_category_i18n', 'stock_currencies', 'stock_cycle_count_documents', 'stock_cycle_count_lines',
        'stock_cycle_counts', 'stock_document_lines', 'stock_documents', 'stock_fee_types',
        'stock_item_attribute_values', 'stock_item_categories', 'stock_item_customer_prices',
        'stock_item_fees', 'stock_item_i18n', 'stock_item_prices', 'stock_item_promo_prices',
        'stock_item_relations', 'stock_item_tags', 'stock_item_templates', 'stock_item_units',
        'stock_item_vendors', 'stock_items', 'stock_landed_costs', 'stock_levels', 'stock_locales',
        'stock_media', 'stock_packaging_units', 'stock_price_level_rules', 'stock_price_levels',
        'stock_pricing_exchange_rates', 'stock_pricing_profiles', 'stock_pricing_rules', 'stock_tags',
        'stock_take_lines', 'stock_takes', 'stock_tracking_allocations', 'stock_tracking_units',
        'stock_valuation_rows', 'stock_valuation_snapshots',
        'submission_defect_notices', 'submission_inbox_messages', 'submission_inbox_polls',
        'submission_inbox_purge_manifest', 'submission_isds_auth_flows', 'submission_outbox',
        'submission_outbox_attempts',
        'supplier_domain_login_requests',
        'tax_advance_schedules', 'tax_evidence_closings', 'tax_evidence_non_cash_adjustments',
        'tax_loss_applications', 'tax_losses', 'tax_related_party_adjustments', 'tax_submission_artifacts',
        'tax_submission_attempts', 'tax_submission_status_events', 'tax_submissions',
        'tax_unpaid_liability_addbacks',
        'trips',
        'vat_clearing_runs', 'vat_deduction_adjustment_years', 'vat_deduction_adjustments',
        'vat_registration_corrections', 'vat_s43_corrections', 'vat_s46_corrections', 'vat_s74b_corrections',
        'warehouse_locations',
        'warehouses',
        'webauthn_ceremonies',
        'work_report_items', 'work_report_link_codes', 'work_report_link_sessions', 'work_report_links',
        'work_report_materials', 'work_reports',
    ];

    /**
     * Značky `supplier.*_sample_seeded_at` => tabulka, kam ukázka patří. Reset značku
     * vynuluje jen tehdy, když ukázku smazal.
     *
     * @var array<string, string>
     */
    public const SAMPLE_MARKERS = [
        'payroll_attendance_sample_seeded_at' => 'payroll_import_profiles',
        'integration_sample_seeded_at'        => 'integration_connections',
    ];

    /** @return list<string> */
    public static function resetKeep(bool $keepCache): array
    {
        $keep = array_keys(self::RESET_KEEP);
        return $keepCache ? array_merge($keep, self::RESET_KEEP_CACHE) : $keep;
    }

    /**
     * Keep-list režimu `--keep-users-supplier` a smíšené tabulky, u kterých se v tomto
     * režimu maže jen per-tenant část (ty, které jsou konfigurací, zůstávají celé).
     *
     * @return array{keep: list<string>, partial: array<string, string>}
     */
    public static function resetKeepUsersSupplier(bool $keepCache): array
    {
        $keep = array_values(array_unique(array_merge(self::resetKeep($keepCache), self::RESET_KEEP_USERS_SUPPLIER)));
        $partial = array_diff_key(self::RESET_PARTIAL, array_flip(self::RESET_KEEP_USERS_SUPPLIER));

        return ['keep' => $keep, 'partial' => $partial];
    }
}
