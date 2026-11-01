<?php

declare(strict_types=1);

namespace MyInvoice\Service\Supplier;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Allowlist polí detailu firmy (`GET /api/settings/supplier`, `GET /api/suppliers/{id}`
 * a odpověď jejich PUT).
 *
 * Dřív odpověď vracela `SELECT s.*` minus denylist podle názvu sloupce, takže každý
 * nový sloupec tabulky `supplier` (cesty k souborům, adresy AI endpointů, počítadla,
 * identifikátory integrací, osobní čísla majitele) šel ven automaticky. Teď platí
 * opak: ven jde jen to, co je tady vyjmenované.
 *
 * Složení seznamů:
 *   - BASE      = pole, která čte UI (typ `Supplier` ve web/src/api/settings.ts,
 *                 stránky Nastavení firmy a Bankovní účty, synchronizace přepínače
 *                 firem), MCP (`default_hourly_rate`, `is_vat_payer`, `vat_status_history`)
 *                 a pole dokumentovaná v `SupplierFull` v api/openapi.yaml.
 *   - GATED     = citlivější skupiny jen pro toho, kdo je smí upravovat. API token
 *                 se scope `read` je nedostane nikdy.
 *   - MINIMAL   = bezpečné minimum pro `GET /api/suppliers/{id}` bez práva číst
 *                 nastavení firmy (stejný výřez jako seznam firem pro přepínač).
 *
 * Seznamy hlídá SupplierResponseFieldsTest (UI typ, Settings.vue i openapi).
 */
final class SupplierResponseFields
{
    /** @var list<string> */
    public const BASE = [
        'id', 'company_name', 'display_name', 'street', 'city', 'zip', 'country_id',
        'country_iso', 'country_name_cs', 'country_name_en', 'ic', 'dic', 'legal_form',
        'accounting_profile_recommendation', 'is_vat_payer', 'vat_status_history',
        'accounting_framework', 'accounting_framework_history', 'tax_accounting_decree_effective',
        'tax_accounting_decree_is_derived', 'is_identified', 'email', 'phone', 'web', 'tagline',
        'commercial_register', 'default_currency_id', 'default_currency', 'default_vat_rate_id',
        'default_payment_due_days', 'default_payment_due_unit', 'default_prices_include_vat',
        'default_rounding_mode', 'default_note_below_items_enabled', 'default_note_below_items_cs',
        'default_note_below_items_en', 'default_hourly_rate', 'auto_send_reminders',
        'reminder_days_after_due', 'auto_generate_recurring', 'embed_isdoc', 'public_isdoc_enabled',
        'email_attach_isdoc', 'invoice_qr_include_due_date', 'purchase_invoice_qr_include_due_date',
        'proforma_payment_document', 'allow_refund_invoices', 'purchase_import_received_at',
        'pohoda_account_code', 'pohoda_centre_code', 'pohoda_activity_code', 'pohoda_contract_code',
        'pohoda_accounting_code', 'invoice_number_format', 'proforma_number_format',
        'credit_note_number_format', 'simplified_number_format', 'simplified_hide_customer_default',
        'purchase_invoice_number_format', 'purchase_invoice_numbering', 'purchase_receipt_number_format',
        'purchase_credit_note_number_format', 'purchase_advance_number_format',
        'purchase_tax_document_number_format', 'invoice_number_period', 'email_branding_enabled',
        'email_accent_color', 'pdf_logo_show_name', 'invoice_pdf_monochrome', 'branding_profiles_enabled',
        'default_branding_profile_id', 'has_email_logo', 'payment_thanks_enabled',
        'payment_thanks_auto_send', 'payment_thanks_default_checked', 'payment_thanks_attach_paid_pdf',
        'payment_thanks_max_age_days', 'self_copy', 'cfg_self_copy_fallback', 'accounting_mode',
        'accounting_enabled', 'payroll_enabled', 'dimensions_enabled', 'stock_enabled', 'stock_method',
        'stock_auto_issue', 'stock_in_transit_from', 'invoice_pdf_show_base_qty',
        'invoice_pdf_show_issued_by', 'invoice_pdf_show_line_discount', 'invoice_pdf_compact_items', 'invoice_pdf_bank_in_footer', 'work_report_display_options',
        'auto_post_invoices', 'auto_post_purchases', 'receipt_payable_account_code', 'taxpayer_type', 'vat_period', 'flat_tax_band', 'oss_enabled',
        'oss_valid_from', 'oss_valid_to', 'oss_identification_country', 'oss_return_currency',
        'financial_office_code', 'workplace_code', 'cz_nace_code', 'cz_nace_resolved',
        'epo_taxpayer_code', 'tax_entity_status', 'tax_entity_status_date', 'tax_accounting_decree',
        'fiscal_year_start_month', 'tax_investment_incentive', 'tax_atad_cfc', 'tax_public_benefit',
        'tax_cooperating_person', 'tax_foreign_income_credit', 'tax_representation_history',
        'data_box_id', 'data_box_type', 'sest_jmeno', 'sest_prijmeni', 'sest_telefon', 'sest_email',
        'sest_funkce', 'street_number_pop', 'street_number_orient', 'person_title', 'opr_jmeno',
        'opr_prijmeni', 'opr_postaveni', 'cfg_varsymbol_fallback', 'number_series_collisions',
        'created_at', 'updated_at',
    ];

    /**
     * Citlivé skupiny: oprávnění, minimální úroveň a pole. Úroveň odpovídá tomu,
     * kdo pole v aplikaci upravuje, ne kdo jen prohlíží nastavení firmy.
     *
     * @var list<array{0:string, 1:AccessLevel, 2:list<string>}>
     */
    public const GATED = [
        // Osobní identifikátory majitele pro ČSSZ a zdravotní pojišťovnu. Edituje je
        // formulář Nastavení firmy, který ukládá jen `settings.company.write`.
        ['settings.company.write', AccessLevel::WRITE, ['health_insurance_number', 'cssz_vsdp', 'cssz_ossz_code']],
        // Volba AI brány a klientské vytěžování. Klíče, adresy endpointů a modely
        // nejdou ven vůbec; stránka Integrace je čte z vlastního endpointu.
        ['settings.ai_provider', AccessLevel::READ, [
            'ai_provider', 'ai_data_region', 'ai_eu_residency_required',
            'client_ai_extraction_enabled', 'client_ai_extraction_monthly_limit',
        ]],
        // Interní cesty k souborům loga a podpisu. UI používá `has_email_logo`.
        ['settings.branding', AccessLevel::WRITE, ['logo_path', 'signature_path']],
    ];

    /** @var list<string> */
    public const MINIMAL = [
        'id', 'company_name', 'display_name', 'ic', 'dic', 'is_vat_payer', 'email', 'accounting_mode', 'country_iso',
    ];

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function full(array $row, Request $request): array
    {
        $out = array_intersect_key($row, array_flip(self::BASE));
        if (self::readOnlyToken($request)) {
            return $out;
        }
        foreach (self::GATED as [$permission, $level, $fields]) {
            if (!RequestAuthorization::allows($request, $permission, $level)) {
                continue;
            }
            $out += array_intersect_key($row, array_flip($fields));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function minimal(array $row): array
    {
        return array_intersect_key($row, array_flip(self::MINIMAL));
    }

    /** @return list<string> všechna pole, která může odpověď kdy obsahovat */
    public static function allFields(): array
    {
        $fields = self::BASE;
        foreach (self::GATED as [, , $group]) {
            $fields = [...$fields, ...$group];
        }

        return $fields;
    }

    /** Token bez práva zápisu (scope `read`) citlivé skupiny nedostane ani s oprávněním uživatele. */
    private static function readOnlyToken(Request $request): bool
    {
        $token = $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN);

        return is_array($token) && (string) ($token['scope'] ?? '') !== 'read_write';
    }
}
