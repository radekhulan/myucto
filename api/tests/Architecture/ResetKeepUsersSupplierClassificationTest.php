<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\System\GlobalSeedTables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `reset.php --keep-users-supplier` má zachovat účet, firmu a její KONFIGURACI a smazat
 * jen byznys data. Keep-list režimu vznikl dřív než historie plátcovství, režim a období
 * účetnictví nebo nastavení mezd, a reset je proto tiše mazal (re-import Q9a, nález R-1):
 * firma zůstala bez historie DPH a s mzdami bez účtárny.
 *
 * Guard proto vyžaduje zařazení KAŽDÉ tabulky schématu: ponechat
 * (RESET_KEEP / RESET_KEEP_USERS_SUPPLIER / smíšené RESET_PARTIAL), nebo smazat
 * (RESET_KEEP_USERS_SUPPLIER_WIPES, cache). Nová tabulka bez zařazení test shodí.
 */
final class ResetKeepUsersSupplierClassificationTest extends TestCase
{
    public function testEveryTableIsClassifiedForKeepUsersSupplierMode(): void
    {
        $classified = array_fill_keys(array_merge(
            array_keys(GlobalSeedTables::RESET_KEEP),
            GlobalSeedTables::RESET_KEEP_CACHE,
            array_keys(GlobalSeedTables::RESET_PARTIAL),
            GlobalSeedTables::RESET_KEEP_USERS_SUPPLIER,
            GlobalSeedTables::RESET_KEEP_USERS_SUPPLIER_WIPES,
        ), true);

        $missing = array_values(array_diff(array_keys(self::tables()), array_keys($classified)));
        sort($missing);

        self::assertSame(
            [],
            $missing,
            "Tabulky bez zařazení pro reset.php --keep-users-supplier. Konfiguraci firmy dej do\n"
            . 'GlobalSeedTables::RESET_KEEP_USERS_SUPPLIER, byznys data do RESET_KEEP_USERS_SUPPLIER_WIPES.',
        );
    }

    public function testClassificationIsUnambiguousAndCurrent(): void
    {
        $keep = GlobalSeedTables::RESET_KEEP_USERS_SUPPLIER;
        $wipes = GlobalSeedTables::RESET_KEEP_USERS_SUPPLIER_WIPES;

        self::assertSame([], array_values(array_diff_assoc($keep, array_unique($keep))), 'Duplicita v keep-listu.');
        self::assertSame([], array_values(array_diff_assoc($wipes, array_unique($wipes))), 'Duplicita v seznamu mazaných.');
        self::assertSame([], array_values(array_intersect($keep, $wipes)), 'Tabulka je buď konfigurace, nebo data.');

        $global = array_merge(array_keys(GlobalSeedTables::RESET_KEEP), array_keys(GlobalSeedTables::RESET_PARTIAL));
        self::assertSame(
            [],
            array_values(array_intersect($wipes, $global)),
            'Globální číselník nesmí být v seznamu mazaných ani v tomhle režimu.',
        );

        $tables = self::tables();
        $unknown = array_values(array_filter(
            array_merge($keep, $wipes),
            static fn (string $t): bool => !isset($tables[$t]),
        ));
        self::assertSame([], $unknown, 'Zařazení jmenuje tabulky, které ve schématu nejsou.');
    }

    /** @return list<array{0:string, 1:string}> */
    public static function companyConfiguration(): array
    {
        return [
            ['supplier_vat_status_history', 'historie plátcovství DPH (VatStatusService)'],
            ['supplier_tax_representation_history', 'zastoupení u správce daně v čase'],
            ['supplier_accounting_modes', 'režim účetnictví v čase'],
            ['supplier_accounting_frameworks', 'účetní rámec v čase'],
            ['accounting_periods', 'účetní období'],
            ['payroll_module_state', 'stav mzdového modulu a začátek zpracování'],
            ['payroll_employer_settings', 'nastavení zaměstnavatele'],
            ['payroll_employer_policies', 'zaměstnavatelská politika'],
            ['payroll_offices', 'účtárny'],
            ['payroll_office_registration_versions', 'registrace účtáren'],
            ['payroll_component_definitions', 'mzdové složky'],
            ['payroll_posting_map_proposals', 'předkontace mezd'],
            ['chart_of_accounts', 'účtová osnova'],
            ['posting_rules', 'předkontace včetně per-tenant override'],
            ['webauthn_credentials', 'passkeys zachovaného účtu'],
            ['user_technical_access', 'technický rozsah zachovaného účtu'],
            ['user_technical_suppliers', 'technické přiřazení firem zachovaného účtu'],
            ['instance_smtp_targets', 'schválené cíle provozní pošty instance'],
            ['instance_smtp_settings', 'aktivní provozní poštovní transport instance'],
            ['client_ai_extraction_usage', 'již vyčerpaný měsíční limit klientského vytěžování'],
        ];
    }

    #[DataProvider('companyConfiguration')]
    public function testKeepModePreservesCompanyConfiguration(string $table, string $why): void
    {
        $mode = GlobalSeedTables::resetKeepUsersSupplier(false);

        self::assertContains($table, $mode['keep'], "--keep-users-supplier musí ponechat `{$table}` ({$why}).");
        self::assertArrayNotHasKey($table, $mode['partial'], "`{$table}` se v tomhle režimu nesmí ani částečně mazat.");
    }

    public function testKeepModeStillWipesBusinessData(): void
    {
        $keep = GlobalSeedTables::resetKeepUsersSupplier(false)['keep'];
        foreach (['invoices', 'clients', 'journal_entries', 'bank_transactions', 'payroll_employees', 'payroll_runs', 'sample_data_entries', 'warehouses'] as $table) {
            self::assertNotContains($table, $keep);
        }
    }

    public function testResetScriptUsesKeepModeFromRegistry(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/reset.php');

        self::assertStringContainsString('GlobalSeedTables::resetKeepUsersSupplier($keepCache)', $source);
        self::assertStringNotContainsString('array_merge($keep, [', $source, 'reset.php nesmí mít vlastní keep-list režimu.');
    }

    /** @return array<string, mixed> */
    private static function tables(): array
    {
        $json = file_get_contents(dirname(__DIR__, 3) . '/db/schema.snapshot.json');
        self::assertIsString($json);
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR)['tables'];
    }
}
