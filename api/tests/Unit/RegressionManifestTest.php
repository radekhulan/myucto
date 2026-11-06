<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use MyUctoRegression\Manifest;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/regression/bootstrap.php';

final class RegressionManifestTest extends TestCase
{
    public function testDppoProviderReceivesInputsInsteadOfReadOnlyEnvelope(): void
    {
        $container = new class {
            public function get(string $class): object
            {
                return new class {
                    public function readOnlyInputs(int $supplier, int $year, string $type): array
                    {
                        return ['inputs' => ['bank_account_id' => 5, 'tax_paid_advances' => 1234.56], 'status' => 'draft', 'advances_source' => 'return'];
                    }
                    public function gather(int $supplier, int $year, array $inputs): array
                    {
                        return $inputs;
                    }
                };
            }
        };
        self::assertSame(['bank_account_id' => 5, 'tax_paid_advances' => 1234.56], Manifest::invoke($container, ['kind' => 'dppo_data', 'args' => [1, 2025]]));
    }

    public function testDiscoveryIncludesCurrentFullYearForecastAndCompanyWithoutPeriod(): void
    {
        $pdo = self::discoveryDatabase();
        $manifest = Manifest::discover($pdo, '2026-06-30');
        $cases = array_values($manifest['cases']);
        $kinds = array_column($cases, 'kind');
        foreach (['dppo_forecast', 'closing_income_tax', 'closing_pl_balances', 'closing_bs_balances', 'opening_balances', 'dpfo_preview', 'cash_journal', 'tax_estimate'] as $kind) {
            self::assertContains($kind, $kinds);
        }
        self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'income_statement' && $c['args'][2] === '2026-12-31'));
        self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'vat_return' && $c['args'] === [2, 2025, 12, 'quarterly']));
        self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'dpfo_preview' && $c['args'] === [2, 2025, 'fo']));
        self::assertEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'control_statement' && $c['args'][0] === 1 && $c['args'][3] === 'quarterly'));
        self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'control_statement' && $c['args'][0] === 2 && $c['args'][3] === 'quarterly'));
        self::assertFalse($manifest['coverage']['complete']);
        self::assertTrue($manifest['coverage']['manifest_complete']);
    }

    public function testDiscoveryIncludesInactiveBranchFiltersAndJointCompanyDimensions(): void
    {
        $manifest = Manifest::discover(self::discoveryDatabase(), '2026-06-30');
        $cases = array_values($manifest['cases']);
        self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'income_statement' && ($c['dimension']['value_id'] ?? null) === 11 && $c['dimension']['descendants'] === true));
        self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'dimension_profit' && $c['args'][4] === [1, 2]));
    }

    public function testXmlProjectionPreservesDuplicatesZerosAndExactDecimalStrings(): void
    {
        $xml = '<Pisemnost><VetaA dic="12345678" zakl_dane1="123456789012345.67" dan1="0"/><VetaA zakl_dane1="10.01" dan1="2.10"/></Pisemnost>';
        $values = Manifest::xmlNumericProjection($xml);
        self::assertSame('123456789012345.67', $values['/Pisemnost/VetaA[1]/@zakl_dane1']);
        self::assertSame('0', $values['/Pisemnost/VetaA[1]/@dan1']);
        self::assertSame('10.01', $values['/Pisemnost/VetaA[2]/@zakl_dane1']);
        self::assertCount(4, $values);
        $this->expectException(\RuntimeException::class);
        Manifest::xmlNumericProjection('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///unread">]><x amount="1"/>');
    }

    public function testHistoricalTaxYearStillHasReturnButNoUnsupportedForecastCall(): void
    {
        $pdo = self::discoveryDatabase();
        $pdo->exec("INSERT INTO income_tax_returns VALUES (1, 2019, 'po')");
        $manifest = Manifest::discover($pdo, '2026-06-30');
        $cases = array_values($manifest['cases']);
        self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'dppo_preview' && $c['args'] === [1, 2019, 'po']));
        self::assertEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'dppo_forecast' && $c['args'] === [1, 2019]));
        self::assertSame(1, $manifest['coverage']['not_applicable']['dppo_forecast_year_outside_2020_2100']);
    }

    public function testApproximateCashJournalRatesCannotPassAsNumericalEvidence(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing_exchange_rates');
        Manifest::normalize(['warnings' => [['type' => 'fx_rate_approximate', 'blocking' => false]]], '2026-06-30');
    }

    public function testCoverageInvokesFinancialPreviewsAndTaxXmlInsteadOfLeavingGaps(): void
    {
        $manifest = Manifest::discover(self::discoveryDatabase(), '2026-06-30');
        $kinds = array_column(array_values($manifest['cases']), 'kind');
        foreach (['closing_entry_projection', 'closing_inventory', 'closing_stock', 'closing_fx', 'closing_small_asset_accrual', 'closing_prepaid_accrual', 'closing_deferred_revenue', 'closing_provisions', 'closing_deferred_tax', 'dppo_xml', 'dpfo_xml', 'accounting_mode_year'] as $kind) {
            self::assertContains($kind, $kinds, $kind . ' must call its actual financial service');
        }
        self::assertSame([], $manifest['coverage']['not_implemented']);
    }

    public function testIncomeTaxXmlProjectionDetectsChangedFinancialAttributes(): void
    {
        $before = Manifest::xmlNumericProjection('<Pisemnost><DPFDP7><VetaO kc_zd7="700000"/><VetaD da_slezap="74160"/></DPFDP7></Pisemnost>');
        $after = Manifest::xmlNumericProjection('<Pisemnost><DPFDP7><VetaO kc_zd7="700001"/><VetaD da_slezap="74160"/></DPFDP7></Pisemnost>');
        self::assertNotSame($before, $after);
        self::assertContains('74160', array_values($before));
    }

    public function testIncomeTaxAppendixAmountsAreNumericEvidenceWithoutIdentifiers(): void
    {
        $xml = '<Pisemnost><DPFDP7><VetaC prijmy7="1000000" vydaje7="300000" pr_prij7="200000" pr_vyd7="120000" pr_sazba="60"/><VetaF prijmy10="2000" vydaje10="1500" rozdil10="500" uhrn_prijmy10="2000" uhrn_vydaje10="1500" uhrn_rozdil10="500" rod_c="1234567890"/><VetaA ost_trans_sl1="123.45" ost_trans_sl2="0"/></DPFDP7></Pisemnost>';
        $values = Manifest::xmlNumericProjection($xml);
        self::assertCount(13, $values);
        self::assertSame('1000000', $values['/Pisemnost/DPFDP7/VetaC/@prijmy7']);
        self::assertSame('123.45', $values['/Pisemnost/DPFDP7/VetaA/@ost_trans_sl1']);
        self::assertSame('0', $values['/Pisemnost/DPFDP7/VetaA/@ost_trans_sl2']);
        self::assertArrayNotHasKey('/Pisemnost/DPFDP7/VetaF/@rod_c', $values);
        self::assertNotSame($values, Manifest::xmlNumericProjection(str_replace('prijmy7="1000000"', 'prijmy7="1000001"', $xml)));
    }

    public function testHistoricalModeAndStoredTaxpayerTypeAreExplicitCases(): void
    {
        $pdo = self::discoveryDatabase();
        $pdo->exec("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = 2");
        $pdo->exec("INSERT INTO supplier_accounting_modes VALUES (2, '2025-01-01', 'tax_evidence'), (2, '2026-01-01', 'double_entry')");
        $pdo->exec("INSERT INTO income_tax_returns VALUES (1, 2025, 'fo')");
        $manifest = Manifest::discover($pdo, '2026-06-30');
        $cases = array_values($manifest['cases']);
        foreach ([2025, 2026] as $year) self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'accounting_mode_year' && $c['args'] === [2, $year]));
        self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'cash_journal' && $c['args'][0] === 2 && $c['args'][1] === '2025-01-01'));
        foreach (['preview', 'inputs', 'balance_due', 'xml'] as $suffix) self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'dpfo_' . $suffix && $c['args'] === [1, 2025, 'fo']));
    }

    public function testFiledVatPredecessorCreatesEverySupportedAmendmentVariant(): void
    {
        $pdo = self::discoveryDatabase();
        $empty = Manifest::discover($pdo, '2026-06-30');
        self::assertGreaterThan(0, $empty['coverage']['not_applicable']['vat_amendments_without_filed_predecessor']);
        $pdo->exec("INSERT INTO tax_submissions (id,supplier_id,form_code,period_year,period_month,period_quarter,form_variant,status) VALUES (1, 2, 'dphdp3', 2025, 12, NULL, 'B', 'submitted'), (2, 2, 'dphkh1', 2025, 12, NULL, 'B', 'accepted'), (3, 2, 'dphshv', 2025, 12, NULL, 'R', 'submitted')");
        foreach (['DPHDP3', 'DPHKH1', 'DPHSHV'] as $index => $form) {
            $xml = '<Pisemnost><' . $form . '><VetaD rok="2025" mesic="12"/><VetaP/></' . $form . '></Pisemnost>';
            $pdo->prepare('UPDATE tax_submissions SET xml_content=?,xml_sha256=? WHERE id=?')->execute([$xml, hash('sha256', $xml), $index + 1]);
        }
        $manifest = Manifest::discover($pdo, '2026-06-30');
        $cases = array_values($manifest['cases']);
        foreach (['vat_return' => ['opravne', 'dodatecne'], 'control_statement' => ['opravne', 'nasledne', 'nasledne_opravne'], 'recapitulative_statement' => ['nasledne']] as $kind => $variants) {
            foreach ($variants as $variant) self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === $kind && $c['args'] === [2, 2025, 12, 'monthly', $variant, '2026-06-30']));
        }
        self::assertSame(3, $manifest['coverage']['filed_vat_amendment_periods']);
        self::assertSame($empty['coverage']['not_applicable']['vat_amendments_without_filed_predecessor'] - 3, $manifest['coverage']['not_applicable']['vat_amendments_without_filed_predecessor']);
        $pdo->exec("INSERT INTO tax_submissions (id,supplier_id,form_code,period_year,period_month,period_quarter,form_variant,status) VALUES (4, 2, 'dphdp3', 2025, 13, NULL, 'B', 'submitted')");
        $this->expectExceptionMessage('invalid_filed_vat_period');
        Manifest::discover($pdo, '2026-06-30');
    }

    public function testWrongFiledXmlCannotBecomeAnEmptyFinancialBaseline(): void
    {
        $pdo = self::discoveryDatabase();
        $xml = '<Pisemnost><WrongForm/></Pisemnost>';
        $pdo->prepare("INSERT INTO tax_submissions (id,supplier_id,form_code,period_year,period_month,form_variant,status,xml_content,xml_sha256) VALUES (1,2,'dphdp3',2025,12,'B','submitted',?,?)")->execute([$xml, hash('sha256', $xml)]);
        $this->expectExceptionMessage('invalid_filed_vat_xml');
        Manifest::discover($pdo, '2026-06-30');
    }

    public function testHistoricalModeUsesTheNativeAnnualContractAvailableOnBaseline(): void
    {
        $container = new class {
            public function get(string $class): object
            {
                return new class {
                    public function forYear(int $supplier, int $year): string
                    {
                        TestCase::assertSame([2, 2025], [$supplier, $year]);
                        return 'tax_evidence';
                    }
                };
            }
        };
        self::assertSame('tax_evidence', Manifest::invoke($container, ['kind' => 'accounting_mode_year', 'args' => [2, 2025]]));
    }

    private static function discoveryDatabase(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE supplier (id INTEGER, taxpayer_type TEXT, accounting_mode TEXT, supplier_group_id INTEGER)',
            "INSERT INTO supplier VALUES (1, 'po', 'double_entry', 7), (2, 'fo', 'tax_evidence', 7)",
            'CREATE TABLE accounting_periods (id INTEGER, supplier_id INTEGER, fiscal_year INTEGER, starts_on TEXT, ends_on TEXT)',
            "INSERT INTO accounting_periods VALUES (3, 1, 2026, '2026-01-01', '2026-12-31')",
            'CREATE TABLE dimension_types (id INTEGER, supplier_id INTEGER, supplier_group_id INTEGER)',
            'INSERT INTO dimension_types VALUES (10, NULL, 7)',
            'CREATE TABLE dimension_values (id INTEGER, type_id INTEGER, is_active INTEGER)',
            'INSERT INTO dimension_values VALUES (11, 10, 0)',
            'CREATE TABLE invoices (supplier_id INTEGER, issue_date TEXT, tax_date TEXT)',
            "INSERT INTO invoices VALUES (2, '2025-12-15', '2025-12-15')",
            'CREATE TABLE purchase_invoices (supplier_id INTEGER, issue_date TEXT, tax_date TEXT)',
            'CREATE TABLE cash_documents (supplier_id INTEGER, issue_date TEXT)',
            'CREATE TABLE bank_transactions (statement_id INTEGER, posted_at TEXT)',
            'CREATE TABLE bank_statements (id INTEGER, supplier_id INTEGER)',
            'CREATE TABLE income_tax_returns (supplier_id INTEGER, year INTEGER, taxpayer_type TEXT)',
            'CREATE TABLE supplier_accounting_modes (supplier_id INTEGER, effective_from TEXT, accounting_mode TEXT)',
            'CREATE TABLE tax_submissions (id INTEGER, supplier_id INTEGER, form_code TEXT, period_year INTEGER, period_month INTEGER, period_quarter INTEGER, form_variant TEXT, status TEXT, xml_content TEXT, xml_sha256 TEXT)',
            'CREATE TABLE tax_profiles (supplier_id INTEGER, year INTEGER)',
        ] as $sql) {
            $pdo->exec($sql);
        }
        return $pdo;
    }
}
