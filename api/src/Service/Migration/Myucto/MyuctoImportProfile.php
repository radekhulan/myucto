<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Myucto;

/** Explicit first import profile: business records, no instance credentials or runtime jobs. */
final class MyuctoImportProfile
{
    public const VERSION = 'classifications-v1';
    public const TABLES = [
        'currencies', 'revenue_categories', 'expense_categories', 'cost_centers',
        'chart_of_accounts', 'vat_classifications', 'accounting_periods', 'clients', 'client_bank_accounts',
        'projects', 'work_reports', 'cash_registers', 'supplier_bank_accounts',
        'price_list_items', 'price_list_item_prices', 'price_list_customer_overrides',
        'recurring_invoice_templates', 'recurring_invoice_template_items',
        'invoices', 'invoice_items', 'purchase_invoices', 'purchase_invoice_items',
        'bank_statements', 'bank_transactions', 'invoice_payments', 'payment_matches',
        'cash_documents', 'cash_document_vat_lines', 'invoice_settlements',
        'offset_agreements', 'offset_agreement_items', 'assets', 'asset_improvements',
        'depreciation_entries', 'small_assets', 'journal_entries', 'journal_entry_lines',
        'posting_rules', 'de_movement_classification', 'de_movement_classification_history',
    ];
    public const CONFIG_TABLES = ['supplier_vat_status_history'];
    public const GLOBAL_KEYS = ['countries' => ['iso2'], 'vat_rates' => ['code', 'rate_percent', 'country']];
    public const REUSE_KEYS = [
        'currencies' => ['code', 'decimals'], 'chart_of_accounts' => ['account_code'],
        'revenue_categories' => ['code'], 'expense_categories' => ['code'],
        'vat_classifications' => ['code'], 'cost_centers' => ['code'], 'cash_registers' => ['name', 'currency_code'],
        'supplier_bank_accounts' => ['account_number', 'bank_code'],
        'posting_rules' => ['rule_key', 'priority'],
    ];
    public const OWNERS = [
        'projects' => ['client_id', 'clients'], 'work_reports' => ['project_id', 'projects'],
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
        'offset_agreements' => ['created_by' => 'users'],
        'offset_agreement_items' => ['invoice_payment_id' => 'invoice_payments'],
        'assets' => ['created_by' => 'users', 'purchase_invoice_item_id' => 'purchase_invoice_items'],
        'depreciation_entries' => ['override_by' => 'users'],
        'accounting_periods' => ['approved_by' => 'users', 'closed_by' => 'users', 'reviewed_by' => 'users'],
        'bank_statements' => ['dedup_scope_supplier_id' => 'supplier'],
        'de_movement_classification' => ['bank_transaction_id' => 'bank_transactions',
            'cash_document_id' => 'cash_documents', 'classified_by' => 'users'],
        'de_movement_classification_history' => ['changed_by' => 'users'],
    ];
    public const PRESERVED_IDS = ['idoklad_id', 'fakturoid_id', 'import_batch_id'];
    public const RESET_COLUMNS = [
        'invoices' => ['public_token', 'approval_token', 'approval_receipt_hash', 'approval_token_expires_at', 'pdf_path', 'pdf_generated_at'],
        'bank_transactions' => ['processing_pending_at', 'processing_error'],
        'recurring_invoice_templates' => ['last_error', 'last_error_at'],
    ];
    public const DOCUMENT_TYPES = ['invoice' => 'invoices', 'purchase_invoice' => 'purchase_invoices'];
}
