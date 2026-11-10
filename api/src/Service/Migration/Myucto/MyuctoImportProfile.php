<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Myucto;

/** Explicit first import profile: business records, no instance credentials or runtime jobs. */
final class MyuctoImportProfile
{
    /** Prvních 16 znaků otisku je součástí cesty importovaných souborů, takže dvojtečka musí stát až za nimi. */
    public const VERSION = 'vat-clearing-runs-v1';
    /** Starší profily, jejichž obnova se shodnými daty je pro aktuální profil úplná. */
    public const COMPATIBLE_VERSIONS = ['classifications-v1', 'posting-groups-v1'];
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
    ];
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
    ];
    public const OWNERS = [
        'projects' => ['client_id', 'clients'], 'work_reports' => ['invoice_id', 'invoices'],
        'invoice_items' => ['invoice_id', 'invoices'],
        'purchase_invoice_items' => ['purchase_invoice_id', 'purchase_invoices'],
        'recurring_invoice_template_items' => ['template_id', 'recurring_invoice_templates'],
        'bank_transactions' => ['statement_id', 'bank_statements'],
        'cash_document_vat_lines' => ['cash_document_id', 'cash_documents'],
    ];
    /** References without a declared database foreign key. */
    public const EXTRA_REFERENCES = [
        'purchase_invoice_items' => ['expense_category_id' => 'expense_categories'],
        'clients' => ['default_expense_category_id' => 'expense_categories', 'default_revenue_category_id' => 'revenue_categories'],
        'projects' => ['default_revenue_category_id' => 'revenue_categories'],
        'invoices' => ['revenue_category_id' => 'revenue_categories', 'booked_by' => 'users'],
        'purchase_invoices' => ['booked_by' => 'users', 'expense_category_id' => 'expense_categories'],
        'recurring_invoice_templates' => ['revenue_category_id' => 'revenue_categories'],
        'cash_documents' => ['created_by' => 'users', 'invoice_payment_id' => 'invoice_payments', 'reversal_entry_id' => 'journal_entries'],
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
    ];
    public const PRESERVED_IDS = ['idoklad_id', 'fakturoid_id', 'import_batch_id'];
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
    ];
    /**
     * Měkké vazby (záměrně bez cizího klíče) na nastavení, které obnova nepřenáší:
     * řada z číselníku, cenová hladina skladu, šablona fakturace výkazů, pravidlo
     * nákladů. Doklad si ponechá číslo, ceny i účty; vazba se vynuluje a obnova
     * počet odpojených vazeb vykáže v protokolu, nic se nezahodí potichu.
     */
    public const DETACHED_REFERENCES = [
        'clients' => ['price_level_id'],
        'projects' => ['billing_template_id'],
        'work_reports' => ['billing_template_id'],
        'invoices' => ['number_series_id', 'price_level_id'],
        'purchase_invoices' => ['number_series_id'],
        'purchase_invoice_items' => ['expense_rule_id'],
        'cash_documents' => ['number_series_id'],
        // Podání DPH obnova nepřenáší; běh zúčtování si ponechá formulář, variantu i čas podání.
        'vat_clearing_runs' => ['submission_id'],
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
    ];
    public const RESET_COLUMNS = [
        'invoices' => ['public_token', 'approval_token', 'approval_receipt_hash', 'approval_token_expires_at', 'pdf_path', 'pdf_generated_at'],
        'bank_transactions' => ['processing_pending_at', 'processing_error'],
        'recurring_invoice_templates' => ['last_error', 'last_error_at'],
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
