<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Myucto;

use MyInvoice\Repository\DimensionAssignmentRepository;

/** Explicit first import profile: business records, no instance credentials or runtime jobs. */
final class MyuctoImportProfile
{
    /** Prvních 16 znaků otisku je součástí cesty importovaných souborů, takže dvojtečka musí stát až za nimi. */
    public const VERSION = 'company-payroll-v1';
    /** Starší profily, jejichž obnova se shodnými daty je pro aktuální profil úplná. */
    public const COMPATIBLE_VERSIONS = ['classifications-v1', 'posting-groups-v1', 'vat-clearing-runs-v1', 'company-complete-v1',
        'company-dimensions-v1', 'company-other-items-v1'];
    public const TABLES = [
        'currencies', 'revenue_categories', 'expense_categories', 'cost_centers',
        'chart_of_accounts', 'vat_classifications', 'accounting_periods', 'clients', 'client_bank_accounts',
        'projects', 'work_reports', 'cash_registers', 'supplier_bank_accounts',
        'price_list_items', 'price_list_item_prices', 'price_list_customer_overrides',
        'recurring_invoice_templates', 'recurring_invoice_template_items',
        'invoices', 'invoice_items', 'purchase_invoices', 'purchase_invoice_items',
        'bank_statements', 'bank_transactions', 'invoice_payments', 'payment_matches',
        'cash_documents', 'cash_document_vat_lines', 'invoice_settlements', 'credit_note_offsets',
        'offset_agreements', 'offset_agreement_items', 'assets', 'asset_improvements',
        'depreciation_entries', 'small_assets', 'journal_entries', 'journal_entry_lines', 'journal_document_postings',
        'vat_clearing_runs', 'posting_rules', 'de_movement_classification', 'de_movement_classification_history',
        'tax_evidence_bank_rules', 'supplier_email_templates',
        ...self::OPTIONAL_GRAPH_TABLES,
    ];
    /**
     * Tabulky s vlastním `id`, které starší exporty MyÚčta ani převod zálohy MyInvoice
     * nenesou. Chybějící tabulka znamená, že není co přenést; vlastní data cíle v nich
     * obnovu zastaví jen tehdy, když je export nese. Pořadí je závazné: párování
     * nastavení podle přirozeného klíče potřebuje už namapované rodiče.
     */
    public const OPTIONAL_GRAPH_TABLES = [
        'document_folders', 'documents', 'document_files', 'document_dms_messages', 'document_extractions', 'invoice_pdfs',
        'dimension_types', 'dimension_values',
        'internal_documents', 'journal_entry_document_links', 'journal_entry_templates', 'journal_entry_template_lines', 'journal_entry_notes',
        // Čerpání záloh konečnými doklady (vydané i přijaté).
        'invoice_advance_deductions', 'purchase_advance_deductions',
        // Podání (DPH, KH, SH, daň z příjmů) jako archiv odeslaných XML; obnova je znovu neodesílá.
        'tax_submissions',
        'income_tax_returns', 'tax_advance_schedules',
        'bank_posting_rules', 'bank_counterparty_map', 'bank_card_merchant_map', 'expense_classification_rules',
        'bank_rule_templates', 'auto_posting_policy',
        // Ostatní pohledávky a závazky: druhy (před číselnými řadami druhů), doklady, splátky, úhrady,
        // opakování a kontace. Zaúčtované se převezmou se svými zápisy deníku, znovu se neúčtují.
        'other_item_kinds', 'other_items', 'other_item_installments', 'other_item_allocations',
        'other_item_schedules', 'other_item_schedule_occurrences', 'other_item_posting_profiles', 'other_item_posting_overrides',
        // Rozpad řádků zápisů na doklady saldokonta a předpisy ostatních položek ze zůstatku.
        'journal_settlement_allocations',
        // Nastavení dimenzí firmy: pravidla povinnosti, mapa účtotvorné dimenze, výchozí dimenze karet.
        'dimension_account_rules', 'dimension_account_map', 'dimension_defaults',
        'accounting_corrections', 'accounting_closing_steps',
        'number_series', 'accounting_document_series',
        // Výkazy: kategorie účetní jednotky zmrazená uzávěrkou, výjimky mapování účtů a přílohy.
        'entity_category_history', 'statement_account_overrides', 'statement_notes',
        // Mzdy: pracoviště a instituce, politika zaměstnavatele, složky, osoby a jejich údaje,
        // pracovní poměry, nepřítomnosti, srážky a exekuce, převzaté JMHZ a počáteční stavy.
        // Pořadí sleduje povinné vazby (exekuce až po dokumentech DMS).
        ...self::PAYROLL_GRAPH_TABLES,
    ];
    /**
     * Mzdové tabulky s vlastním `id`. Šifrované osobní údaje obnova přešifruje pro cílovou
     * firmu ({@see MyuctoImportSealedValues}); mzdový modul cíle nesmí mít vlastní data.
     */
    public const PAYROLL_GRAPH_TABLES = [
        'payroll_offices', 'payroll_office_registration_versions',
        'payroll_institutions', 'payroll_institution_accounts',
        'payroll_employer_policies', 'payroll_employer_policy_audit',
        'payroll_component_definitions', 'payroll_component_jmhz_mappings',
        'payroll_employees', 'payroll_person_identifiers', 'payroll_person_identity_history', 'payroll_person_addresses',
        'payroll_person_contacts', 'payroll_person_accounts', 'payroll_person_external_ids', 'payroll_person_health_coverage_history',
        'payroll_person_social_discount_claims', 'payroll_person_social_jurisdictions', 'payroll_person_tax_declarations',
        'payroll_person_tax_residences', 'payroll_dependants', 'payroll_person_tax_child_claims', 'payroll_payout_rules',
        'payroll_employments', 'payroll_employment_terms', 'payroll_employment_events', 'payroll_employment_external_ids',
        'payroll_employment_terminations', 'payroll_recurring_components',
        'payroll_average_earning_snapshots', 'payroll_absences', 'payroll_leave_ledger',
        'payroll_deduction_agreements', 'payroll_deduction_agreement_versions',
        'payroll_enforcement_cases', 'payroll_enforcement_case_documents', 'payroll_enforcement_case_parties',
        'payroll_enforcement_claims', 'payroll_enforcement_events', 'payroll_enforcement_recipient_instructions',
        'payroll_enforcement_person_month_evidence',
        'payroll_external_jmhz_submissions', 'payroll_external_jmhz_submission_forms',
        'payroll_statutory_accumulator_openings', 'payroll_migration_reference_totals',
    ];
    /**
     * Tabulky bez vlastního `id` mimo deník. `key` je přirozený klíč řádku v cíli, `refs`
     * vazby na převáděný graf, `polymorphic` vazby, jejichž cíl určuje druh v jiném sloupci,
     * `users` autoři (obnova je přiřadí aktérovi), `detached` vazby na nastavení mimo export.
     * Hodnota 0 ve vazbě znamená „bez vazby" (počítadlo firmy, ne klienta). `counter` jsou
     * počítadla číselných řad: cíl smí být jen výš (další doklady už vydal), nikdy níž.
     * `tolerant` řádek s vazbou na doklad, který export nenese (smazaný doklad), vynechá
     * a vykáže, místo aby odmítl celou obnovu.
     */
    public const KEYLESS_TABLES = [
        'internal_document_lines' => ['key' => ['supplier_id', 'internal_document_id', 'position'],
            'refs' => ['internal_document_id' => 'internal_documents']],
        'journal_entry_line_dimensions' => ['key' => ['line_id', 'dimension_type_id'],
            'refs' => ['line_id' => 'journal_entry_lines', 'dimension_type_id' => 'dimension_types', 'dimension_value_id' => 'dimension_values']],
        'journal_entry_line_dimension_splits' => ['key' => ['line_id', 'dimension_type_id', 'dimension_value_id'],
            'refs' => ['line_id' => 'journal_entry_lines', 'dimension_type_id' => 'dimension_types', 'dimension_value_id' => 'dimension_values']],
        // Cíl `doc_id` podle druhu dokladu je v DimensionAssignmentRepository::DOC_TYPE_TABLES (jedno místo
        // i pro archiv účetnictví). Přiřazení dokladu, který export nenese (smazaný doklad), se vynechá a vykáže.
        'document_dimensions' => ['key' => ['supplier_id', 'doc_type', 'doc_id', 'item_no', 'dimension_type_id'],
            'refs' => ['dimension_type_id' => 'dimension_types', 'dimension_value_id' => 'dimension_values'],
            'polymorphic' => ['doc_id' => ['doc_type', DimensionAssignmentRepository::DOC_TYPE_TABLES]],
            'tolerant' => true],
        'document_dimension_splits' => ['key' => ['supplier_id', 'doc_type', 'doc_id', 'item_no', 'dimension_type_id', 'dimension_value_id'],
            'refs' => ['dimension_type_id' => 'dimension_types', 'dimension_value_id' => 'dimension_values'],
            'polymorphic' => ['doc_id' => ['doc_type', DimensionAssignmentRepository::DOC_TYPE_TABLES]],
            'tolerant' => true],
        'bank_posting_rule_dimensions' => ['key' => ['rule_id', 'dimension_type_id'],
            'refs' => ['rule_id' => 'bank_posting_rules', 'dimension_type_id' => 'dimension_types', 'dimension_value_id' => 'dimension_values']],
        'document_links' => ['key' => ['document_id', 'entity_type', 'entity_id'],
            'refs' => ['document_id' => 'documents'],
            'polymorphic' => ['entity_id' => ['entity_type', ['client' => 'clients', 'invoice' => 'invoices',
                'purchase_invoice' => 'purchase_invoices', 'project' => 'projects', 'journal_entry' => 'journal_entries',
                'bank_transaction' => 'bank_transactions', 'cash_document' => 'cash_documents', 'invoice_payment' => 'invoice_payments',
                'internal_document' => 'internal_documents', 'other_item' => 'other_items']]],
            'tolerant' => true],
        'other_item_posting_lines' => ['key' => ['supplier_id', 'other_item_id', 'position'],
            'refs' => ['other_item_id' => 'other_items']],
        'other_item_auto_match_blocks' => ['key' => ['supplier_id', 'other_item_id', 'bank_transaction_id'],
            'refs' => ['other_item_id' => 'other_items', 'bank_transaction_id' => 'bank_transactions']],
        'other_item_settings' => ['key' => ['supplier_id'], 'users' => ['updated_by']],
        'pdf_signature_output_settings' => ['key' => ['supplier_id', 'output_type'], 'detached' => ['default_profile_id']],
        'invoice_counters' => ['key' => ['supplier_id', 'client_id', 'revenue_category_id', 'invoice_type', 'period'],
            'refs' => ['client_id' => 'clients', 'revenue_category_id' => 'revenue_categories'], 'counter' => ['last_number']],
        'purchase_invoice_counters' => ['key' => ['supplier_id', 'period'], 'counter' => ['last_number']],
        'number_series_counters' => ['key' => ['series_id', 'period_key'], 'refs' => ['series_id' => 'number_series'],
            'counter' => ['last_number']],
        // Nastavení účetnictví firmy (úroveň automatiky, kurzy, uzamčení): nová firma má výchozí
        // řádek, první obnova ho nahradí nastavením zdroje (REPLACED_SETUP_TABLES).
        'accounting_supplier_settings' => ['key' => ['supplier_id']],
        // Mzdy: profil výplaty osoby, nastavení zaměstnavatele, stav modulu a kontrola převzetí.
        'payroll_employee_profiles' => ['key' => ['supplier_id', 'employee_id'], 'refs' => ['employee_id' => 'payroll_employees']],
        'payroll_employer_settings' => ['key' => ['supplier_id'], 'refs' => ['default_office_id' => 'payroll_offices']],
        'payroll_module_state' => ['key' => ['supplier_id'], 'users' => ['activated_by']],
        'payroll_takeover_invariant_checks' => ['key' => ['supplier_id', 'source']],
        // Párovací symbol řádku deníku ze zdrojového programu (saldo po řádcích, POHODA, Helios).
        'journal_line_pairing' => ['key' => ['supplier_id', 'entry_id', 'line_no'], 'refs' => ['entry_id' => 'journal_entries']],
        // Mapa převodu z Heliosu: převzaté záznamy chrání před automatikou MyÚčta (TakenOverRecord).
        // Cíl `target_id` určuje druh (HELIOS_MAP_TARGETS).
        'helios_import_map' => ['key' => ['supplier_id', 'kind', 'source_key'], 'helios_map' => true],
    ];
    /**
     * Druh záznamu mapy převodu z Heliosu => převáděná tabulka cíle. `null` = hodnota
     * není ID řádku (příznak, zakódované datum) a přenáší se beze změny. Úhrada míří
     * podle předpony klíče na párování banky, nebo na pokladní doklad; srážka ze mzdy
     * na dohodu o srážkách, nebo na exekuci. Druh mimo seznam obnova odmítne.
     */
    public const HELIOS_MAP_TARGETS = [
        'account' => 'chart_of_accounts', 'period' => 'accounting_periods', 'journal_entry' => 'journal_entries',
        'invoice' => 'invoices', 'purchase_invoice' => 'purchase_invoices', 'partner' => 'clients', 'partner_new' => 'clients',
        'doc_currency' => 'currencies', 'bank_statement' => 'bank_statements', 'bank_transaction' => 'bank_transactions',
        'cash_document' => 'cash_documents', 'cash_register' => 'cash_registers', 'asset_card' => 'assets', 'asset_small' => 'small_assets',
        'dimension_type' => 'dimension_types', 'dim_cost_center' => 'dimension_values', 'dim_cost_circle' => 'dimension_values',
        'dim_project' => 'dimension_values', 'dim_vehicle' => 'dimension_values', 'filing' => 'tax_submissions',
        'payroll_person' => 'payroll_employees', 'payroll_employment' => 'payroll_employments', 'payroll_month' => 'payroll_employments',
        'payroll_institution' => 'payroll_institutions', 'payroll_jmhz' => 'payroll_external_jmhz_submissions',
        'payment' => ['bank|' => 'payment_matches', 'cash|' => 'cash_documents'],
        // Srážka: dohoda o srážkách, nebo exekuce (podle reference dohody, MyuctoImporter::remapKeyless()).
        'payroll_deduction' => null, 'payroll_setup' => null, 'filing_lock_prev' => null,
        // Srovnání salda převodem podle saldokonta Heliosu: ostatní položka a vyrovnaný doklad.
        'saldo_item' => 'other_items',
        'saldo_settled' => ['invoice|' => 'invoices', 'purchase_invoice|' => 'purchase_invoices'],
    ];
    /**
     * Auditní stopa firmy (`activity_log`): záznamy se přenesou s přemapovanou entitou a ID
     * v payloadu a v cíli se znovu zapečetí do řetězu cílové instalace. Druh entity =>
     * tabulka; klíč payloadu => tabulka (seznam ID i jednotlivé ID, i v řádcích `lines`).
     */
    public const ACTIVITY_TABLE = 'activity_log';
    public const ACTIVITY_ENTITY_TARGETS = [
        'invoice' => 'invoices', 'purchase_invoice' => 'purchase_invoices', 'journal_entry' => 'journal_entries',
        'accounting_period' => 'accounting_periods', 'client' => 'clients', 'bank_statement' => 'bank_statements',
        'bank_transaction' => 'bank_transactions', 'cash_document' => 'cash_documents', 'asset' => 'assets',
        'payroll_employee' => 'payroll_employees', 'payroll_employment' => 'payroll_employments', 'payroll_module_state' => 'supplier',
        'document' => 'documents', 'other_item' => 'other_items',
    ];
    public const ACTIVITY_PAYLOAD_TARGETS = [
        'entry_id' => 'journal_entries', 'fx_reversal_entry_id' => 'journal_entries', 'entry_ids' => 'journal_entries',
        'taken_over_entry_ids' => 'journal_entries', 'reversal_entry_id' => 'journal_entries',
        'next_period_id' => 'accounting_periods', 'period_id' => 'accounting_periods',
        'invoice_ids' => 'invoices', 'purchase_ids' => 'purchase_invoices', 'invoice_id' => 'invoices', 'purchase_invoice_id' => 'purchase_invoices',
        'account_id' => 'chart_of_accounts', 'dependant_id' => 'payroll_dependants', 'claim_id' => 'payroll_person_tax_child_claims',
        'employee_id' => 'payroll_employees', 'employment_id' => 'payroll_employments',
        'overridden_by' => 'users', 'user_id' => 'users',
    ];
    /**
     * Výchozí nastavení, které nová firma dostane při založení nebo při prvním otevření
     * (šablony bankovních pravidel, politika automatického účtování, výstupy podpisu PDF,
     * doporučené šablony zápisů). Nese-li je export, první obnova je v cíli smaže a nahradí
     * nastavením zdroje. Hodnota je podmínka, která řádky cíle za výchozí považuje.
     */
    public const REPLACED_SETUP_TABLES = [
        'accounting_supplier_settings' => '1 = 1',
        'bank_rule_templates' => '1 = 1',
        'auto_posting_policy' => '1 = 1',
        'pdf_signature_output_settings' => '1 = 1',
        'journal_entry_templates' => 'is_seeded = 1',
    ];
    /** Číslovací nastavení firmy, které obnova převezme z řádku `supplier` zdroje (zbytek firmy zůstává). */
    public const SUPPLIER_NUMBERING_COLUMNS = ['invoice_number_period', 'purchase_invoice_numbering', 'purchase_numbering_date_basis'];
    public const SUPPLIER_NUMBERING_SUFFIX = '_number_format';
    public const CONFIG_TABLES = ['supplier_vat_status_history'];
    /**
     * Tabulky, které se z exportu jen čtou ke kontrole shody s cílem. Nepřevádějí se,
     * nejsou v otisku dat a protokol je dál vede mimo rozsah převodu.
     */
    public const CONTEXT_TABLES = ['supplier_accounting_frameworks', 'supplier_accounting_modes'];
    /**
     * Forma účetního zápisu zdroje: historie formy a k deníku patřící posouzení zápisů bez
     * dvojic a storna dvojic. Obnova je přenáší vlastní cestou (historie nahradí výchozí
     * formu instalace nové firmy, posouzení a storna nemají vlastní `id`). Export bez nich
     * (starší verze nebo firma bez volby) = složený zápis.
     */
    public const ENTRY_FORM_HISTORY_TABLE = 'accounting_entry_form_history';
    /** Tabulky deníku s klíčem (supplier_id, entry_id, …) místo `id`; čtou se v pořadí exportu. */
    public const KEYLESS_JOURNAL_TABLES = ['journal_entry_pair_status', 'journal_pair_reversals'];
    /** Vazby tabulek bez `id` na zápisy deníku. */
    public const KEYLESS_ENTRY_REFERENCES = [
        'journal_entry_pair_status' => ['entry_id'],
        'journal_pair_reversals' => ['entry_id', 'reversal_entry_id'],
    ];
    /** Tabulky účetnictví: když je export nese, musí cíl vést stejný účetní rámec (vyhláška 500/504). */
    public const FRAMEWORK_BOUND_TABLES = ['chart_of_accounts', 'accounting_periods', 'journal_entries'];
    public const GLOBAL_KEYS = ['countries' => ['iso2'], 'vat_rates' => ['code', 'rate_percent', 'country'],
        // Balíček specifikace JMHZ (sdílený mzdový číselník exportu): mapování složek na atributy JMHZ.
        'payroll_jmhz_spec_packages' => ['package_key']];
    /**
     * Globální číselníky, které zakládají migrace se stejnými ID v každé instalaci a export
     * je nenese (verze výkazů pro výjimky mapování účtů). Vazba si ID ponechá, obnova jen
     * ověří, že v cíli existuje.
     */
    public const SEEDED_GLOBAL_TABLES = ['statement_versions'];
    public const REUSE_KEYS = [
        'currencies' => ['code', 'decimals'], 'chart_of_accounts' => ['account_code'],
        'revenue_categories' => ['code'], 'expense_categories' => ['code'],
        'vat_classifications' => ['code'], 'cost_centers' => ['code'], 'cash_registers' => ['name', 'currency_code'],
        'supplier_bank_accounts' => ['account_number', 'bank_code'],
        'posting_rules' => ['rule_key', 'priority'],
        // Typy dimenzí zakládá i katalog systémových dimenzí (vyhláška 504) a číselné řady
        // průvodce řad; klíč s vazbou se porovnává s už přemapovanou hodnotou.
        'dimension_types' => ['code'], 'dimension_values' => ['type_id', 'code'],
        'number_series' => ['legacy_key'],
        'accounting_document_series' => ['series_code', 'fiscal_year', 'register_id'],
    ];
    public const OWNERS = [
        'projects' => ['client_id', 'clients'], 'work_reports' => ['invoice_id', 'invoices'],
        'invoice_items' => ['invoice_id', 'invoices'],
        'purchase_invoice_items' => ['purchase_invoice_id', 'purchase_invoices'],
        'recurring_invoice_template_items' => ['template_id', 'recurring_invoice_templates'],
        'bank_transactions' => ['statement_id', 'bank_statements'],
        'cash_document_vat_lines' => ['cash_document_id', 'cash_documents'],
        'document_dms_messages' => ['document_id', 'documents'],
        'invoice_pdfs' => ['invoice_id', 'invoices'],
        'journal_entry_template_lines' => ['template_id', 'journal_entry_templates'],
    ];
    /** References without a declared database foreign key. */
    public const EXTRA_REFERENCES = [
        'purchase_invoice_items' => ['expense_category_id' => 'expense_categories'],
        'clients' => ['default_expense_category_id' => 'expense_categories', 'default_revenue_category_id' => 'revenue_categories'],
        'projects' => ['default_revenue_category_id' => 'revenue_categories'],
        'invoices' => ['revenue_category_id' => 'revenue_categories', 'booked_by' => 'users', 'number_series_id' => 'number_series'],
        'purchase_invoices' => ['booked_by' => 'users', 'expense_category_id' => 'expense_categories', 'number_series_id' => 'number_series'],
        'recurring_invoice_templates' => ['revenue_category_id' => 'revenue_categories'],
        'cash_documents' => ['created_by' => 'users', 'invoice_payment_id' => 'invoice_payments', 'reversal_entry_id' => 'journal_entries',
            'number_series_id' => 'number_series'],
        'invoice_settlements' => ['created_by' => 'users', 'invoice_payment_id' => 'invoice_payments'],
        'credit_note_offsets' => ['created_by' => 'users', 'invoice_payment_id' => 'invoice_payments'],
        'offset_agreements' => ['created_by' => 'users'],
        'offset_agreement_items' => ['invoice_payment_id' => 'invoice_payments'],
        'assets' => ['created_by' => 'users', 'purchase_invoice_item_id' => 'purchase_invoice_items'],
        'depreciation_entries' => ['override_by' => 'users'],
        'accounting_periods' => ['approved_by' => 'users', 'closed_by' => 'users', 'reviewed_by' => 'users'],
        'bank_statements' => ['dedup_scope_supplier_id' => 'supplier'],
        'de_movement_classification' => ['bank_transaction_id' => 'bank_transactions',
            'cash_document_id' => 'cash_documents', 'classified_by' => 'users'],
        'de_movement_classification_history' => ['changed_by' => 'users'],
        'tax_evidence_bank_rules' => ['created_by' => 'users'],
        'vat_clearing_runs' => ['entry_id' => 'journal_entries', 'computed_by' => 'users', 'submission_id' => 'tax_submissions'],
        'documents' => ['deleted_by' => 'users'],
        'document_extractions' => ['document_id' => 'documents'],
        'journal_entry_templates' => ['created_by' => 'users'],
        'journal_entry_notes' => ['created_by' => 'users', 'updated_by' => 'users'],
        'income_tax_returns' => ['created_by' => 'users', 'finalized_by' => 'users', 'last_submission_id' => 'tax_submissions'],
        'tax_submissions' => ['submitted_by' => 'users', 'generated_by' => 'users'],
        'statement_notes' => ['updated_by' => 'users'],
        // Mzdy: autoři a schvalovatelé (obnova je přiřadí aktérovi), vazby bez cizího klíče
        // a vazby, které ve schématu nesou jen druhý sloupec složeného klíče.
        'payroll_absences' => ['requested_by' => 'users', 'decided_by' => 'users', 'childbirth_recorded_by' => 'users'],
        'payroll_average_earning_snapshots' => ['created_by' => 'users', 'approved_by' => 'users'],
        'payroll_employer_policies' => ['created_by' => 'users', 'updated_by' => 'users'],
        'payroll_employer_policy_audit' => ['actor_user_id' => 'users'],
        'payroll_leave_ledger' => ['created_by' => 'users'],
        'payroll_migration_reference_totals' => ['employee_id' => 'payroll_employees', 'employment_id' => 'payroll_employments'],
        'payroll_person_tax_child_claims' => ['superseded_by_id' => 'payroll_person_tax_child_claims'],
        'payroll_deduction_agreement_versions' => ['employee_id' => 'payroll_employees'],
        'payroll_employment_external_ids' => ['employee_id' => 'payroll_employees'],
        'payroll_component_jmhz_mappings' => ['spec_package_id' => 'payroll_jmhz_spec_packages'],
        'statement_account_overrides' => ['created_by' => 'users', 'version_id' => 'statement_versions'],
        'tax_advance_schedules' => ['source_return_id' => 'income_tax_returns'],
        'accounting_closing_steps' => ['done_by' => 'users'],
        'number_series' => ['created_by' => 'users'],
        // Pravidlo banky může párovat platby na konkrétní ostatní položku.
        'bank_posting_rules' => ['last_rejected_tx_id' => 'bank_transactions', 'other_item_id' => 'other_items'],
        'internal_documents' => ['number_series_id' => 'number_series'],
        // Typ hodnoty je součástí složeného FK (type_id, id) na dimension_values.
        'dimension_account_map' => ['dimension_type_id' => 'dimension_types'],
        'dimension_defaults' => ['dimension_type_id' => 'dimension_types'],
    ];
    /**
     * Řádky, jejichž vlastník leží mimo převáděný graf (výchozí dimenze produktu a skladové
     * kategorie, sklad obnova nepřenáší). Obnova je vynechá a vykáže mezi vynechanými.
     */
    public const OUTSIDE_GRAPH_OWNERS = [
        'dimension_defaults' => ['product_id', 'product_category_id'],
    ];
    /**
     * Vazby z EXTRA_REFERENCES, které míří na řádek, jenž mohl ve zdroji zaniknout
     * (vytěžení smazaného dokumentu, odmítnutý pohyb, přiznání), nebo na řadu, kterou
     * obnova nepřenáší (export bez číselníku řad, řada ostatních dokladů), nebo na ostatní
     * položku, kterou export nenese (starší export, záloha MyInvoice). Export bez cíle
     * vazbu nevyřadí z obnovy; obnova ji vynuluje a vykáže mezi odpojenými.
     */
    public const SOFT_REFERENCES = [
        'invoices' => ['number_series_id'],
        'purchase_invoices' => ['number_series_id'],
        'cash_documents' => ['number_series_id'],
        'internal_documents' => ['number_series_id'],
        'document_extractions' => ['document_id'],
        'tax_advance_schedules' => ['source_return_id'],
        // Starší export podání nenese; běh zúčtování a přiznání si ponechají formulář a čas podání.
        'vat_clearing_runs' => ['submission_id'],
        'income_tax_returns' => ['last_submission_id'],
        'bank_posting_rules' => ['last_rejected_tx_id', 'other_item_id'],
    ];
    /** Identifikátory datové schránky (ISDS), nikoli vazby na řádky databáze. */
    public const PRESERVED_IDS = ['idoklad_id', 'fakturoid_id', 'import_batch_id', 'dm_id', 'sender_box_id', 'recipient_box_id',
        // Kód mzdových pravidel (`cz-payroll-2026.compensation-averages.v1`), stejný v každé instalaci.
        'ruleset_id',
        // Registrační číslo uživatele při dočasném přidělení agenturou (JMHZ), ne řádek databáze.
        'jmhz_assignment_user_foreign_id'];
    /**
     * Deterministické klíče zdaňovacího období ({@see \MyInvoice\Service\Accounting\Vat\VatClearingService::sourceIdFor()}),
     * nikoli ID řádku. Mezi instancemi znamenají totéž období, proto se přenáší beze změny.
     */
    public const PERIOD_KEYS = ['vat_clearing_runs' => ['source_id']];
    /**
     * Polymorfní vazby: cílovou tabulku určuje druh dokladu nebo zdroje v řádku,
     * proto nemají cizí klíč. Přemapuje je MyuctoImporter::remap(), resp. references().
     */
    public const POLYMORPHIC_REFERENCES = [
        'journal_entries' => ['source_id'],
        'journal_document_postings' => ['doc_id'],
        'de_movement_classification_history' => ['source_id'],
        'invoice_settlements' => ['doc_id'],
        'offset_agreement_items' => ['doc_id'],
        'credit_note_offsets' => ['invoice_id', 'credit_note_id'],
        'journal_entry_document_links' => ['doc_id'],
        'accounting_corrections' => ['entity_id'],
        'number_series' => ['scope_id'],
        'accounting_document_series' => ['register_id'],
        'journal_settlement_allocations' => ['doc_id'],
    ];
    /**
     * Cíl polymorfních vazeb podle druhu v jiném sloupci řádku. Hodnota 0 znamená „bez
     * vazby" (řada firmy, ne pokladny). Druh, který tu chybí, obnova nezná a odmítne;
     * `null` = vazba se nepřemapovává.
     */
    public const POLYMORPHIC_TARGETS = [
        'journal_entry_document_links' => ['doc_id' => ['doc_type', ['invoice' => 'invoices', 'purchase_invoice' => 'purchase_invoices',
            'cash' => 'cash_documents', 'bank' => 'bank_transactions']]],
        'accounting_corrections' => ['entity_id' => ['entity_type', ['bank_transaction' => 'bank_transactions', 'invoice' => 'invoices',
            'purchase_invoice' => 'purchase_invoices', 'cash_document' => 'cash_documents', 'journal_entry' => 'journal_entries',
            'bank_posting_rule' => 'bank_posting_rules']]],
        'number_series' => ['scope_id' => ['scope_type', ['company' => null, 'cash_register' => 'cash_registers', 'client' => 'clients',
            'revenue_category' => 'revenue_categories', 'bank_account' => 'supplier_bank_accounts', 'other_item_kind' => 'other_item_kinds']]],
        'accounting_document_series' => ['register_id' => ['series_code', ['cash_in' => 'cash_registers', 'cash_out' => 'cash_registers',
            'other_receivable' => 'other_item_kinds', 'other_payable' => 'other_item_kinds']]],
        'journal_settlement_allocations' => ['doc_id' => ['doc_type', ['invoice' => 'invoices', 'purchase_invoice' => 'purchase_invoices',
            'other_item' => 'other_items']]],
    ];
    /**
     * Druhé sloupce složených cizích klíčů, které neodkazují na `id` (prostředí podání,
     * kopie zaměstnance kvůli vlastnictví, rok a druh výpočtu u řetězu počátečních stavů).
     * Hodnota se buď ponechá, nebo ji přemapuje vlastní vazba z EXTRA_REFERENCES.
     */
    public const COMPOSITE_FK_COLUMNS = [
        'payroll_employment_external_ids' => ['environment', 'employee_id'],
        'payroll_person_external_ids' => ['environment'],
        'payroll_deduction_agreement_versions' => ['employee_id'],
        'payroll_statutory_accumulator_openings' => ['employee_id', 'tax_year', 'calculation_kind'],
        // Atribut JMHZ je kód atributu ve slovníku balíčku, balíček mapuje GLOBAL_KEYS.
        'payroll_component_jmhz_mappings' => ['spec_package_id', 'target_attribute_id'],
    ];
    /** Tabulky s `id`, jejichž řádek s polymorfní vazbou mimo export (smazaný doklad) obnova vynechá a vykáže. */
    public const TOLERANT_TABLES = ['accounting_corrections'];
    /**
     * Měkké vazby (záměrně bez cizího klíče) na nastavení, které obnova nepřenáší:
     * cenová hladina skladu, šablona fakturace výkazů, pravidlo nákladů. Doklad si
     * ponechá číslo, ceny i účty; vazba se vynuluje a obnova počet odpojených vazeb
     * vykáže v protokolu, nic se nezahodí potichu.
     */
    public const DETACHED_REFERENCES = [
        'clients' => ['price_level_id'],
        'projects' => ['billing_template_id'],
        'work_reports' => ['billing_template_id'],
        'invoices' => ['price_level_id'],
        'purchase_invoice_items' => ['expense_rule_id'],
        // Skupiny firem, vozidla, podání, finální snímky přiznání, návrhy
        // automatiky a podpisové profily obnova nepřenáší.
        'dimension_types' => ['supplier_group_id'],
        'dimension_values' => ['supplier_group_id', 'car_id'],
        'income_tax_returns' => ['final_snapshot_id'],
        'accounting_corrections' => ['suggestion_id'],
    ];
    /**
     * Výchozí nastavení, které nová firma dostane při založení (bankovní účet a měny z ARES
     * a průvodce). Při první obnově do prázdné firmy se párují podle přirozeného klíče
     * a jejich popisné údaje převezme cíl ze zdroje, jinak by obnova ztratila IBAN,
     * analytiku nebo řadu bankovních dokladů a vícoměnový účet by zůstal jednoměnový.
     */
    public const ADOPTED_SETUP_COLUMNS = [
        'supplier_bank_accounts' => ['label', 'iban', 'currency', 'currency_id', 'analytic_suffix', 'document_series', 'kind', 'is_active'],
        'currencies' => ['account_number', 'bank_code', 'bank_name', 'iban', 'bic'],
        // Řady z průvodce převezmou předponu, název a šablonu zdroje, ať další doklad navazuje.
        'number_series' => ['name', 'prefix', 'template', 'period', 'is_default', 'valid_to', 'sort_order'],
        'accounting_document_series' => ['prefix', 'number_format', 'next_number'],
    ];
    public const RESET_COLUMNS = [
        'invoices' => ['public_token', 'approval_token', 'approval_receipt_hash', 'approval_token_expires_at', 'pdf_path', 'pdf_generated_at'],
        'bank_transactions' => ['processing_pending_at', 'processing_error'],
        'recurring_invoice_templates' => ['last_error', 'last_error_at'],
        // Náhled dokumentu se vygeneruje znovu v cílovém úložišti.
        'documents' => ['thumb_path'],
    ];
    public const DOCUMENT_TYPES = ['invoice' => 'invoices', 'purchase_invoice' => 'purchase_invoices'];
    /**
     * Význam účtu v osnově: obnova ho porovnává u účtů, které cíl už má. Do prázdné firmy
     * (první obnova, bez zápisů a dokladů) převezme význam i název zdroje, protože osnova
     * exportované firmy je pro její deník závazná; do firmy s převzatými daty rozdíl obnovu zastaví.
     */
    public const CHART_SEMANTIC_COLUMNS = ['account_type', 'normal_side', 'is_synthetic', 'tax_deductibility', 'is_clearing'];
    /** Popisné údaje účtu, které první obnova převezme spolu s významem. */
    public const CHART_ADOPTED_COLUMNS = ['name', 'is_active'];
    /** Vazby uvnitř šablony opakování ostatní položky (template_json) na převáděný graf. */
    public const OTHER_ITEM_TEMPLATE_REFERENCES = ['kind_id' => 'other_item_kinds', 'partner_id' => 'clients'];

    /**
     * Firma obnovená starším profilem ze shodných dat. Otisk dat zahrnuje i seznam
     * převáděných tabulek, takže shoda znamená, že export nové tabulky nenese
     * (zdroj bez skupin zaúčtování) a opakovaný import nemá co doplnit.
     */
    public static function isEarlierRestoreOfSameData(string $previous, string $digest): bool
    {
        [$version, $hash] = array_pad(explode(':', $previous, 2), 2, '');
        return in_array($version, self::COMPATIBLE_VERSIONS, true) && $hash !== ''
            && $digest === self::VERSION . ':' . $hash;
    }
}
