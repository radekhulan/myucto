<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Supplier\SupplierResponseFields;
use PHPUnit\Framework\TestCase;

/**
 * Allowlist detailu firmy ({@see SupplierResponseFields}) musí pokrýt každé pole,
 * které čtou jeho konzumenti, a nic navíc nesmí jít ven bez vědomého rozhodnutí.
 *
 * Konzumenti (stav při zavedení allowlistu):
 *   - web/src/api/settings.ts, typ `Supplier`: Nastavení firmy, Bankovní účty,
 *     synchronizace přepínače firem (`supplierStore.patchSupplier`),
 *   - web/src/pages/admin/Settings.vue: čtení i ukládání formuláře,
 *   - MCP/src/tools.mjs: `default_hourly_rate` (sazba výkazu), `is_vat_payer`
 *     a `vat_status_history` (nástroj get_vat_status_history),
 *   - api/openapi.yaml, schéma `SupplierFull` (veřejné API).
 *
 * PDF, ISDOC a e-maily čtou firmu přímo z DB, tuhle odpověď nepoužívají. Branding
 * (logo) jde přes /settings/client/branding, AI brána přes vlastní endpoint
 * s pověřeními, přepínač firem přes GET /api/suppliers (pevný výčet sloupců).
 */
final class SupplierResponseAllowlistTest extends TestCase
{
    /** Klíče typu `Supplier`, které UI jen posílá (zápis), server je nevrací. */
    private const WRITE_ONLY = ['vat_status_effective_from', 'accounting_mode_effective_from'];

    /**
     * Pole odpovědi, která `SupplierFull` v openapi zatím nepopisuje. Zmražený seznam:
     * nové pole v allowlistu musí přibýt buď do openapi, nebo sem (vědomě).
     */
    private const UNDOCUMENTED = [
        'country_name_cs', 'country_name_en', 'default_vat_rate_id', 'auto_generate_recurring', 'embed_isdoc',
        'proforma_payment_document', 'pohoda_account_code', 'pohoda_centre_code', 'pohoda_activity_code',
        'pohoda_contract_code', 'pohoda_accounting_code', 'pdf_logo_show_name', 'branding_profiles_enabled',
        'default_branding_profile_id', 'payment_thanks_enabled', 'payment_thanks_auto_send',
        'payment_thanks_default_checked', 'payment_thanks_attach_paid_pdf', 'cfg_self_copy_fallback',
        'accounting_enabled', 'payroll_enabled', 'auto_post_invoices', 'auto_post_purchases', 'workplace_code',
        'cz_nace_code', 'cz_nace_resolved', 'tax_representation_history', 'sest_jmeno', 'sest_prijmeni',
        'sest_telefon', 'sest_email', 'sest_funkce', 'street_number_pop', 'street_number_orient', 'opr_jmeno',
        'opr_prijmeni', 'opr_postaveni', 'cfg_varsymbol_fallback', 'number_series_collisions',
        'health_insurance_number', 'cssz_vsdp', 'cssz_ossz_code', 'ai_provider', 'ai_data_region',
        'ai_eu_residency_required', 'logo_path', 'signature_path',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testUiSupplierTypeIsCoveredByAllowlist(): void
    {
        $missing = array_diff($this->tsSupplierKeys(), SupplierResponseFields::allFields(), self::WRITE_ONLY);
        self::assertSame([], array_values($missing),
            'Typ Supplier ve web/src/api/settings.ts čte pole, které detail firmy nevrací. Doplňte ho do SupplierResponseFields.');
    }

    public function testSettingsPageReadsOnlyAllowlistedFields(): void
    {
        $source = (string) file_get_contents(self::root() . '/web/src/pages/admin/Settings.vue');
        // `supplier.x`, `supplier.value.x`, `(supplier.value as any).x`; ne i18n klíče 'supplier.x'.
        preg_match_all('/(?<![\'"\w.])\(?supplier(?:\.value)?(?:\s+as\s+any\))?\??\.([a-z_][a-z0-9_]*)/', $source, $m);
        $read = array_unique(array_diff($m[1], ['value']));
        self::assertNotEmpty($read);
        $missing = array_diff($read, SupplierResponseFields::allFields(), self::WRITE_ONLY);
        self::assertSame([], array_values($missing),
            'Settings.vue čte pole firmy mimo allowlist detailu firmy (SupplierResponseFields).');
    }

    public function testMcpSupplierReadsAreAllowlisted(): void
    {
        $source = (string) file_get_contents(self::root() . '/MCP/src/tools.mjs');
        preg_match_all('/\bsupplier\?\.([a-z_][a-z0-9_]*)/', $source, $m);
        $read = array_unique($m[1]);
        self::assertContains('default_hourly_rate', $read);
        self::assertSame([], array_values(array_diff($read, SupplierResponseFields::BASE)),
            'MCP čte z /settings/supplier pole mimo základní allowlist (token by ho nedostal).');
    }

    public function testOpenApiSupplierFullMatchesAllowlist(): void
    {
        $documented = $this->openApiSupplierFullProperties();
        self::assertNotEmpty($documented);
        $all = SupplierResponseFields::allFields();

        self::assertSame([], array_values(array_diff($documented, $all, self::WRITE_ONLY)),
            'SupplierFull v openapi popisuje pole, které odpověď nevrací.');
        self::assertSame([], array_values(array_diff($all, $documented, self::UNDOCUMENTED)),
            'Allowlist vrací pole, které není v SupplierFull ani ve vědomém seznamu UNDOCUMENTED.');
        self::assertSame([], array_values(array_intersect(self::UNDOCUMENTED, $documented)),
            'Pole už je v openapi, odeberte ho z UNDOCUMENTED.');
    }

    public function testNoSecretLikeFieldIsAllowlisted(): void
    {
        foreach (SupplierResponseFields::allFields() as $field) {
            self::assertDoesNotMatchRegularExpression(
                '/_enc$|password|secret|api_key|token|passphrase|private_key|salt|base_url|endpoint|deployment|_model$|extractions_count|workspace|idoklad|fakturoid|seeded_at/',
                $field,
                "Pole {$field} nepatří do odpovědi detailu firmy.",
            );
        }
    }

    public function testGatedFieldsAreNotInBase(): void
    {
        foreach (SupplierResponseFields::GATED as [, , $fields]) {
            self::assertSame([], array_values(array_intersect($fields, SupplierResponseFields::BASE)));
        }
        self::assertSame([], array_values(array_diff(SupplierResponseFields::MINIMAL, SupplierResponseFields::BASE)));
    }

    /** @return list<string> */
    private function tsSupplierKeys(): array
    {
        $lines = file(self::root() . '/web/src/api/settings.ts', FILE_IGNORE_NEW_LINES) ?: [];
        $keys = [];
        $inside = false;
        foreach ($lines as $line) {
            if (preg_match('/^export interface Supplier \{/', $line)) { $inside = true; continue; }
            if (!$inside) continue;
            if (str_starts_with($line, '}')) break;
            if (preg_match('/^  (?:readonly )?([a-z0-9_]+)\??:/', $line, $m)) $keys[] = $m[1];
        }
        self::assertNotEmpty($keys, 'Typ Supplier se nepodařilo najít ve web/src/api/settings.ts.');

        return $keys;
    }

    /** @return list<string> */
    private function openApiSupplierFullProperties(): array
    {
        $lines = file(self::root() . '/api/openapi.yaml', FILE_IGNORE_NEW_LINES) ?: [];
        $props = [];
        $inSchema = false;
        $inProps = false;
        foreach ($lines as $line) {
            if (preg_match('/^    SupplierFull:/', $line)) { $inSchema = true; continue; }
            if (!$inSchema) continue;
            if (preg_match('/^    [A-Za-z]/', $line)) break;
            if (preg_match('/^      properties:/', $line)) { $inProps = true; continue; }
            if ($inProps && preg_match('/^        ([a-z0-9_]+):/', $line, $m)) $props[] = $m[1];
        }

        return $props;
    }
}
