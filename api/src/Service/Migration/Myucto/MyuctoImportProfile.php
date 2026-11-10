<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Myucto;

/** Explicit first import profile: business records, no instance credentials or runtime jobs. */
final class MyuctoImportProfile
{
    /** Prvních 16 znaků otisku je součástí cesty importovaných souborů, takže dvojtečka musí stát až za nimi. */
    public const VERSION = 'company-complete-v1';
    /** Starší profily, jejichž obnova se shodnými daty je pro aktuální profil úplná. */
    public const COMPATIBLE_VERSIONS = ['classifications-v1', 'posting-groups-v1', 'vat-clearing-runs-v1'];
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
        'income_tax_returns', 'tax_advance_schedules',
        'bank_posting_rules', 'bank_counterparty_map', 'bank_card_merchant_map', 'expense_classification_rules',
        'bank_rule_templates', 'auto_posting_policy',
        'accounting_corrections', 'accounting_closing_steps',
        'number_series', 'accounting_document_series',
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
        'document_dimensions' => ['key' => ['supplier_id', 'doc_type', 'doc_id', 'item_no', 'dimension_type_id'],
            'refs' => ['dimension_type_id' => 'dimension_types', 'dimension_value_id' => 'dimension_values'],
            'polymorphic' => ['doc_id' => ['doc_type', ['purchase_invoice' => 'purchase_invoices', 'invoice' => 'invoices',
                'cash_document' => 'cash_documents', 'bank_transaction' => 'bank_transactions', 'journal_template' => 'journal_entry_templates',
                'recurring_template' => 'recurring_invoice_templates', 'asset' => 'assets']]],
            'tolerant' => true],
        'document_links' => ['key' => ['document_id', 'entity_type', 'entity_id'],
            'refs' => ['document_id' => 'documents'],
            'polymorphic' => ['entity_id' => ['entity_type', ['client' => 'clients', 'invoice' => 'invoices',
                'purchase_invoice' => 'purchase_invoices', 'project' => 'projects', 'journal_entry' => 'journal_entries',
                'bank_transaction' => 'bank_transactions', 'cash_document' => 'cash_documents', 'invoice_payment' => 'invoice_payments',
                'internal_document' => 'internal_documents']]],
            'tolerant' => true],
        'pdf_signature_output_settings' => ['key' => ['supplier_id', 'output_type'], 'detached' => ['default_profile_id']],
        'invoice_counters' => ['key' => ['supplier_id', 'client_id', 'revenue_category_id', 'invoice_type', 'period'],
            'refs' => ['client_id' => 'clients', 'revenue_category_id' => 'revenue_categories'], 'counter' => ['last_number']],
        'purchase_invoice_counters' => ['key' => ['supplier_id', 'period'], 'counter' => ['last_number']],
        'number_series_counters' => ['key' => ['series_id', 'period_key'], 'refs' => ['series_id' => 'number_series'],
            'counter' => ['last_number']],
    ];
    /**
     * Výchozí nastavení, které nová firma dostane při založení nebo při prvním otevření
     * (šablony bankovních pravidel, politika automatického účtování, výstupy podpisu PDF,
     * doporučené šablony zápisů). Nese-li je export, první obnova je v cíli smaže a nahradí
     * nastavením zdroje. Hodnota je podmínka, která řádky cíle za výchozí považuje.
     */
    public const REPLACED_SETUP_TABLES = [
        'bank_rule_templates' => '1 = 1',
        'auto_posting_policy' => '1 = 1',
        'pdf_signature_output_settings' => '1 = 1',
        'journal_entry_templates' => 'is_seeded = 1',
    ];
    /** Číslovací nastavení firmy, které obnova převezme z řádku `supplier` zdroje (zbytek firmy zůstává). */
    public const SUPPLIER_NUMBERING_COLUMNS = ['invoice_number_period', 'purchase_invoice_numbering'];
    public const SUPPLIER_NUMBERING_SUFFIX = '_number_format';
    public const CONFIG_TABLES = ['supplier_vat_status_history'];
    /**
     * Tabulky, které se z exportu jen čtou ke kontrole shody s cílem. Nepřevádějí se,
     * nejsou v otisku dat a protokol je dál vede mimo rozsah převodu.
     */
    public const CONTEXT_TABLES = ['supplier_accounting_frameworks'];
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
    public const GLOBAL_KEYS = ['countries' => ['iso2'], 'vat_rates' => ['code', 'rate_percent', 'country']];
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
        'vat_clearing_runs' => ['entry_id' => 'journal_entries', 'computed_by' => 'users'],
        'documents' => ['deleted_by' => 'users'],
        'document_extractions' => ['document_id' => 'documents'],
        'journal_entry_templates' => ['created_by' => 'users'],
        'journal_entry_notes' => ['created_by' => 'users', 'updated_by' => 'users'],
        'income_tax_returns' => ['created_by' => 'users', 'finalized_by' => 'users'],
        'tax_advance_schedules' => ['source_return_id' => 'income_tax_returns'],
        'bank_posting_rules' => ['last_rejected_tx_id' => 'bank_transactions'],
        'accounting_closing_steps' => ['done_by' => 'users'],
        'number_series' => ['created_by' => 'users'],
        'internal_documents' => ['number_series_id' => 'number_series'],
    ];
    /**
     * Vazby z EXTRA_REFERENCES, které míří na řádek, jenž mohl ve zdroji zaniknout
     * (vytěžení smazaného dokumentu, odmítnutý pohyb, přiznání), nebo na řadu, kterou
     * obnova nepřenáší (export bez číselníku řad, řada ostatních dokladů). Export bez cíle
     * vazbu nevyřadí z obnovy; obnova ji vynuluje a vykáže mezi odpojenými.
     */
    public const SOFT_REFERENCES = [
        'invoices' => ['number_series_id'],
        'purchase_invoices' => ['number_series_id'],
        'cash_documents' => ['number_series_id'],
        'internal_documents' => ['number_series_id'],
        'document_extractions' => ['document_id'],
        'tax_advance_schedules' => ['source_return_id'],
        'bank_posting_rules' => ['last_rejected_tx_id'],
    ];
    /** Identifikátory datové schránky (ISDS), nikoli vazby na řádky databáze. */
    public const PRESERVED_IDS = ['idoklad_id', 'fakturoid_id', 'import_batch_id', 'dm_id', 'sender_box_id', 'recipient_box_id'];
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
            'revenue_category' => 'revenue_categories', 'bank_account' => 'supplier_bank_accounts']]],
        'accounting_document_series' => ['register_id' => ['series_code', ['cash_in' => 'cash_registers', 'cash_out' => 'cash_registers']]],
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
        // Podání DPH obnova nepřenáší; běh zúčtování si ponechá formulář, variantu i čas podání.
        'vat_clearing_runs' => ['submission_id'],
        // Skupiny firem, vozidla, podání, finální snímky přiznání, ostatní doklady, návrhy
        // automatiky a podpisové profily obnova nepřenáší.
        'dimension_types' => ['supplier_group_id'],
        'dimension_values' => ['supplier_group_id', 'car_id'],
        'income_tax_returns' => ['last_submission_id', 'final_snapshot_id'],
        'bank_posting_rules' => ['other_item_id'],
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
