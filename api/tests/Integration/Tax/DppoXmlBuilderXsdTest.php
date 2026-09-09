<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Epic DP (issue #18) — vygenerované DPPDP9 XML musí projít validací proti
 * EPO2 XSD (api/xsd/dppdp9_epo2.xsd). Soft-skip, když schema není přítomné.
 */
#[Group('integration')]
final class DppoXmlBuilderXsdTest extends TestCase
{
    private function sampleSupplier(): array
    {
        return [
            'id' => 1,
            'company_name' => 'Ukázková firma s.r.o.',
            'street' => 'Zkušební 123/4',
            'street_number_pop' => '',
            'street_number_orient' => '',
            'city' => 'Vzorov',
            'zip' => '100 00',
            'country_iso2' => 'CZ',
            'ic' => '12345678',
            'dic' => 'CZ12345678',
            'taxpayer_type' => 'po',
            'financial_office_code' => '451',
            'cz_nace_code' => '62020',
            'phone' => '+420 601 002 003',
            'opr_jmeno' => 'Jan',
            'opr_prijmeni' => 'Novák',
            'opr_postaveni' => 'jednatel',
        ];
    }

    private function buildXml(): array
    {
        $calc = (new DppoReturnCalculator())->compute(
            [
                'vh' => 500000,
                'non_deductible_costs' => 20000,
                'disposal_nondeductible_residual' => 10000,
                'depreciation' => ['tax' => 40000, 'accounting' => 100000],
            ],
            [
                'manual_increase_items' => [['text' => 'Pokuta', 'amount' => 5000]],
                'loss_carryforward' => 100000,
                'donations' => 50000,
                'disabled_employees_avg' => 1,
                'tax_paid_advances' => 20000,
            ],
            TaxConstants::forYear(2025)
        );
        return (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc);
    }

    public function testStructure(): void
    {
        $result = $this->buildXml();
        $xml = $result['xml'];
        self::assertStringContainsString('<Pisemnost', $xml);
        self::assertStringContainsString('<DPPDP9', $xml);
        self::assertStringContainsString('k_uladis="DPP"', $xml);
        self::assertStringContainsString('dokument="DP9"', $xml);
        self::assertStringContainsString('kc_ii10_10="500000"', $xml);
        self::assertStringContainsString('rod_c="12345678"', $xml); // IČO
        self::assertStringContainsString('dic="12345678"', $xml);   // bez CZ prefixu
        self::assertStringContainsString('kc_ii270_280="21"', $xml); // sazba 21 %
        self::assertStringContainsString('kc_ii_360="75450"', $xml);
        self::assertStringContainsString('kc_v_1="20000"', $xml);
        self::assertStringContainsString('kc_v_4="-55450"', $xml);
        self::assertStringContainsString('kc_ii320_330="93450"', $xml); // shodné s kc_ii280_290 (ř.290 daň)
        self::assertStringContainsString('d_hospvysl="31.12.2025"', $xml); // fallback konec ZO
        self::assertStringContainsString('<VetaM', $xml);
        self::assertStringContainsString('kc_dpp_f1="18000"', $xml);
        self::assertStringContainsString('kc_dpp_f2="0"', $xml);
        self::assertStringContainsString('kc_dpp_f4="18000"', $xml);
    }

    /**
     * VetaG — tabulka C přílohy č. 1 II. oddílu (zákonné OP a rezervy podle z. 593/1992 Sb.).
     * Věta v XSD sekvenci sedí hned za VetaF a před VetaM; musí projít validací i s
     * naplněnými řádky obou dílčích tabulek (a) OP k pohledávkám, e) rezerva §7).
     */
    private function buildXmlWithLegalProvisions(): array
    {
        $calc = (new DppoReturnCalculator())->compute(
            [
                'vh' => 500000,
                'depreciation' => ['tax' => 0, 'accounting' => 0],
                'legal_provisions' => [
                    'allowance_balance' => 130000.0,
                    'allowance_declared_total' => 130000.0,
                    'allowance_declared_legal' => 130000.0,
                    'allowance_declared_acct' => 0.0,
                    'allowance_by_section' => ['8' => 10000.0, '8a' => 50000.0, '8b' => 20000.0, '8c' => 50000.0],
                    'allowance_unassigned' => 0.0,
                    'allowance_split_reliable' => true,
                    'allowance_created_split_reliable' => true,
                    'legal_allowance_created' => 130000.0,
                    'acct_allowance_created' => 0.0,
                    'legal_reserve_balance' => 250000.0,
                    'legal_reserve_created' => 100000.0,
                    'receivable_writeoff_deductible' => 33000.0,
                    'has_activity' => true,
                ],
            ],
            ['tax_paid_advances' => 0],
            TaxConstants::forYear(2025)
        );

        return (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc, [
            'zdobd_od' => '01.01.2025',
            'zdobd_do' => '31.12.2025',
        ]);
    }

    public function testVetaGStructure(): void
    {
        $xml = $this->buildXmlWithLegalProvisions()['xml'];
        self::assertStringContainsString('<VetaG', $xml);
        self::assertStringContainsString('kc_dpp_c6="50000"', $xml);   // ř. 6 tvorba §8a
        self::assertStringContainsString('kc_sop8c="50000"', $xml);    // ř. 11 stav §8c
        self::assertStringContainsString('kc_dpp_c8="33000"', $xml);   // ř. 12 odpis §24/2/y
        self::assertStringContainsString('kc_dpp_c19="250000"', $xml); // ř. 26 stav rezerv §7
        self::assertStringContainsString('p_pr_2od="1"', $xml);
    }

    public function testVetaGPassesXsd(): void
    {
        $validator = new XmlSchemaValidator();
        if (!$validator->hasSchema('dppdp9')) {
            self::markTestSkipped('XSD dppdp9_epo2.xsd není k dispozici.');
        }
        $validation = $validator->validate($this->buildXmlWithLegalProvisions()['xml'], 'dppdp9');
        self::assertSame('passed', $validation['status'], 'XSD chyby: ' . implode(' | ', $validation['errors']));
        self::assertEmpty($validation['errors']);
    }

    /**
     * § 35 odst. 4 ZDP — sleva za zastavenou exekuci. Tabulka H přílohy č. 1 II. oddílu
     * má na ř. 3 vlastní atribut `kc_dpp_f3`, který se dřív nikdy nevyplnil, a úhrn na
     * ř. 4 (`kc_dpp_f4` = ř. 1 + 2 + 3) ho tím pádem ani neobsahoval.
     */
    public function testStoppedExecutionCreditFillsTableHRow3AndTotal(): void
    {
        $calc = (new DppoReturnCalculator())->compute(
            ['vh' => 500000, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            ['disabled_employees_avg' => 1, 'stopped_execution_credit' => 900, 'tax_paid_advances' => 0],
            TaxConstants::forYear(2025)
        );
        $result = (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc);

        self::assertStringContainsString('kc_dpp_f1="18000"', $result['xml']);
        self::assertStringContainsString('kc_dpp_f3="900"', $result['xml']);
        self::assertStringContainsString('kc_dpp_f4="18900"', $result['xml'], 'ř. 4 tabulky H = ř. 1 + 2 + 3');

        $validator = new XmlSchemaValidator();
        if ($validator->hasSchema('dppdp9')) {
            $validation = $validator->validate($result['xml'], 'dppdp9');
            self::assertSame('passed', $validation['status'], 'XSD chyby: ' . implode(' | ', $validation['errors']));
        }
    }

    /** Bez nároku podle § 35/4 zůstává ř. 3 tabulky H prázdný — žádná nula naslepo. */
    public function testStoppedExecutionCreditOmittedWhenZero(): void
    {
        $xml = $this->buildXml()['xml'];
        self::assertStringContainsString('<VetaM', $xml);
        self::assertStringNotContainsString('kc_dpp_f3=', $xml);
    }

    public function testPassesXsd(): void
    {
        $validator = new XmlSchemaValidator();
        if (!$validator->hasSchema('dppdp9')) {
            self::markTestSkipped('XSD dppdp9_epo2.xsd není k dispozici.');
        }
        $result = $this->buildXml();
        $validation = $validator->validate($result['xml'], 'dppdp9');
        self::assertSame('passed', $validation['status'], 'XSD chyby: ' . implode(' | ', $validation['errors']));
        self::assertEmpty($validation['errors']);
    }

    /** Hospodářský rok 1. 7. 2025 – 30. 6. 2026: reálné zdobd datumy + typ_zo='B'. */
    private function buildFiscalXml(): array
    {
        $calc = (new DppoReturnCalculator())->compute(
            [
                'vh' => 500000,
                'non_deductible_costs' => 20000,
                'disposal_nondeductible_residual' => 10000,
                'depreciation' => ['tax' => 40000, 'accounting' => 100000],
                'period' => ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30'],
            ],
            ['tax_paid_advances' => 20000],
            TaxConstants::forYear(2025)
        );
        return (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc, [
            'zdobd_od' => '01.07.2025',
            'zdobd_do' => '30.06.2026',
            'typ_zo' => 'B',
        ]);
    }

    public function testFiscalYearZdobdAndTypZo(): void
    {
        $xml = $this->buildFiscalXml()['xml'];
        self::assertStringContainsString('zdobd_od="01.07.2025"', $xml);
        self::assertStringContainsString('zdobd_do="30.06.2026"', $xml);
        self::assertStringContainsString('typ_zo="B"', $xml);
    }

    public function testFiscalYearPassesXsd(): void
    {
        $validator = new XmlSchemaValidator();
        if (!$validator->hasSchema('dppdp9')) {
            self::markTestSkipped('XSD dppdp9_epo2.xsd není k dispozici.');
        }
        $validation = $validator->validate($this->buildFiscalXml()['xml'], 'dppdp9');
        self::assertSame('passed', $validation['status'], 'XSD chyby: ' . implode(' | ', $validation['errors']));
    }

    /** DP v2 fáze 2 — dodatečné přiznání: dapdpp_forma=D + d_zjist + V. oddíl iv1/iv2/iv3. */
    private function buildAmendmentXml(): array
    {
        $calc = (new DppoReturnCalculator())->compute(
            ['vh' => 600000, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            ['tax_paid_advances' => 0],
            TaxConstants::forYear(2025)
        );
        return (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc, [
            'dapdpp_forma' => 'D',
            'd_zjist' => '2026-03-15',
            'kc_dppiv1' => 126000, // nově zjištěná daň
            'kc_dppiv2' => 100000, // poslední známá
            'kc_dppiv3' => 26000,  // rozdíl
        ]);
    }

    public function testAmendmentAttributes(): void
    {
        $xml = $this->buildAmendmentXml()['xml'];
        self::assertStringContainsString('dapdpp_forma="D"', $xml);
        self::assertStringContainsString('d_zjist="15.03.2026"', $xml);
        self::assertStringContainsString('kc_dppiv1="126000"', $xml);
        self::assertStringContainsString('kc_dppiv2="100000"', $xml);
        self::assertStringContainsString('kc_dppiv3="26000"', $xml);
    }

    public function testAmendmentPassesXsd(): void
    {
        $validator = new XmlSchemaValidator();
        if (!$validator->hasSchema('dppdp9')) {
            self::markTestSkipped('XSD dppdp9_epo2.xsd není k dispozici.');
        }
        $validation = $validator->validate($this->buildAmendmentXml()['xml'], 'dppdp9');
        self::assertSame('passed', $validation['status'], 'XSD chyby: ' . implode(' | ', $validation['errors']));
    }

    public function testAmendmentMissingDateWarns(): void
    {
        $calc = (new DppoReturnCalculator())->compute(
            ['vh' => 100000, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            [],
            TaxConstants::forYear(2025)
        );
        $built = (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc, ['dapdpp_forma' => 'D']);
        self::assertStringNotContainsString('d_zjist=', $built['xml']);
        self::assertNotEmpty(array_filter($built['warnings'], fn ($w) => str_contains($w, 'datum zjištění')));
    }

    public function testMissingPeriodWarnsInsteadOfSilentCalendarFallback(): void
    {
        // Bez zdobd_od meta (firma bez účetního období) se dosadí kalendářní rok —
        // ale NE tiše: builder musí varovat, jinak by hospodářský rok dostal chybné
        // datumy a typ_zo. XML zůstane validní (kalendářní fallback).
        $calc = (new DppoReturnCalculator())->compute(
            ['vh' => 500000, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            ['tax_paid_advances' => 0],
            TaxConstants::forYear(2025)
        );
        $built = (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc);
        self::assertStringContainsString('zdobd_od="01.01.2025"', $built['xml']);
        self::assertNotEmpty(array_filter($built['warnings'], static fn ($w) => str_contains($w, 'Účetní období nebylo předáno')));
    }

    public function testFiscalPeriodMetaSuppressesFallbackWarning(): void
    {
        // S předaným zdobd_od (reálné období) se fallback warning NEobjeví.
        $built = $this->buildFiscalXml();
        self::assertEmpty(array_filter($built['warnings'], static fn ($w) => str_contains($w, 'Účetní období nebylo předáno')));
    }

    public function testLossDoesNotExportNegativeLine250(): void
    {
        $calc = (new DppoReturnCalculator())->compute(
            ['vh' => -50000, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            ['tax_paid_advances' => 1000],
            TaxConstants::forYear(2025)
        );
        $xml = (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc)['xml'];
        self::assertStringContainsString('kc_ii230_250="0"', $xml);
        self::assertStringContainsString('kc_ii_360="0"', $xml);
        self::assertStringContainsString('kc_v_4="1000"', $xml);
        // XSD kritická kontrola: kc_ii320_330 nesmí být vyplněna při daňové ztrátě na ř. 220.
        self::assertStringNotContainsString('kc_ii320_330=', $xml);
    }

    public function testZeroAdvancesOmitKcV1AndDefaultTypZoIsUppercase(): void
    {
        $calc = (new DppoReturnCalculator())->compute(
            ['vh' => 100000, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            [],
            TaxConstants::forYear(2025)
        );
        $xml = (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc)['xml'];
        // EPO konvence dle reálně podaného přiznání: nulové kc_v_1 se nevypisuje.
        self::assertStringNotContainsString('kc_v_1=', $xml);
        self::assertStringContainsString('typ_zo="A"', $xml);
    }

    // ── Příloha účetní závěrky (VetaUA/UB/UD/UZ) — Epic DP, viz
    // private/APPENDIX-XML-MAPPING-SPEC.md. Fixtura odpovídá reálně podanému přiznání
    // za rok 2025 (mikro ÚJ, ověřeno na 36 řádcích: 4 aktiva + 5 pasiva + 27 VZZ).

    /** @return array<string,mixed> tvar FinancialStatementService::balanceSheet() (jen ověřené řádky) */
    private function balanceSheetFixture(): array
    {
        return [
            'period' => ['id' => 1, 'fiscal_year' => 2025, 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31'],
            'assets' => [
                ['row_code' => 'AKTIVA', 'gross' => 7597000.0, 'correction' => 127000.0, 'net' => 7470000.0, 'prev_net' => 5889000.0],
                ['row_code' => 'B.', 'gross' => 1157000.0, 'correction' => 127000.0, 'net' => 1030000.0, 'prev_net' => 0.0],
                ['row_code' => 'C.', 'gross' => 6395000.0, 'correction' => 0.0, 'net' => 6395000.0, 'prev_net' => 5758000.0],
                ['row_code' => 'D.', 'gross' => 45000.0, 'correction' => 0.0, 'net' => 45000.0, 'prev_net' => 131000.0],
            ],
            'liabilities' => [
                ['row_code' => 'PASIVA', 'amount' => 7470000.0, 'prev_amount' => 5889000.0],
                ['row_code' => 'P.A.', 'amount' => 7215000.0, 'prev_amount' => 4611000.0],
                ['row_code' => 'P.B.', 'amount' => 0.0, 'prev_amount' => 0.0],
                ['row_code' => 'P.C.', 'amount' => 248000.0, 'prev_amount' => 1278000.0],
                ['row_code' => 'P.D.', 'amount' => 7000.0, 'prev_amount' => 0.0],
            ],
        ];
    }

    /** @return array<string,mixed> tvar FinancialStatementService::incomeStatement() (jen ověřené řádky) */
    private function incomeStatementFixture(): array
    {
        $pairs = [
            'I.' => [6674000.0, 7383000.0], 'A.' => [3198000.0, 1555000.0],
            'A.2.' => [292000.0, 115000.0], 'A.3.' => [2906000.0, 1440000.0],
            'D.' => [72000.0, 57000.0], 'D.1.' => [54000.0, 36000.0],
            'D.2.' => [18000.0, 21000.0], 'D.2.1.' => [18000.0, 12000.0], 'D.2.2.' => [0.0, 9000.0],
            'E.' => [127000.0, 0.0], 'E.1.' => [127000.0, 0.0], 'E.1.1.' => [127000.0, 0.0],
            'F.' => [48000.0, 3000.0], 'F.3.' => [1000.0, 0.0], 'F.5.' => [47000.0, 3000.0],
            'PVH' => [3229000.0, 5768000.0],
            'VI.' => [91000.0, 50000.0],
            'VII.' => [0.0, 5000.0], 'K.' => [24000.0, 4000.0],
            'FVH' => [67000.0, 51000.0], 'VHPZ' => [3296000.0, 5819000.0],
            'L.' => [692000.0, 1218000.0], 'L.1.' => [692000.0, 1218000.0],
            'VHPO' => [2604000.0, 4601000.0], 'VH' => [2604000.0, 4601000.0],
            'OBRAT' => [6674000.0, 7383000.0],
        ];
        $rows = [];
        foreach ($pairs as $code => [$amount, $prevAmount]) {
            $rows[] = ['row_code' => $code, 'amount' => $amount, 'prev_amount' => $prevAmount];
        }
        return ['rows' => $rows];
    }

    private function buildXmlWithAppendix(): array
    {
        $calc = (new DppoReturnCalculator())->compute(
            ['vh' => 3296000, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            ['tax_paid_advances' => 0],
            TaxConstants::forYear(2025)
        );
        $supplier = $this->sampleSupplier() + ['email' => 'fakturace@example.com'];
        $appendix = [
            'balance_sheet' => $this->balanceSheetFixture(),
            'income_statement' => $this->incomeStatementFixture(),
            'category' => ['category' => 'micro'],
            'settings' => ['statutory_audit' => false],
        ];
        return (new DppoXmlBuilder())->build($supplier, 2025, $calc, [], $appendix);
    }

    public function testAppendixStructure(): void
    {
        $xml = $this->buildXmlWithAppendix()['xml'];

        // VetaUA — rozvaha AKTIVA (spec §1.1).
        self::assertStringContainsString('<VetaUA c_radku="1" kc_brutto="7597" kc_korekce="127" kc_netto="7470" kc_netto_min="5889"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="3" kc_brutto="1157" kc_korekce="127" kc_netto="1030" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="37" kc_brutto="6395" kc_korekce="0" kc_netto="6395" kc_netto_min="5758"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="74" kc_brutto="45" kc_korekce="0" kc_netto="45" kc_netto_min="131"/>', $xml);

        // VetaUB — VZZ, vč. VI./VI.2. duplicity (c_radku 39 a 41 ze stejné hodnoty).
        self::assertStringContainsString('<VetaUB c_radku="1" kc_min="7383" kc_sled="6674"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="39" kc_min="50" kc_sled="91"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="41" kc_min="50" kc_sled="91"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="56" kc_min="7383" kc_sled="6674"/>', $xml);

        // VetaUD — rozvaha PASIVA, vč. dopočítaného c_radku=24 (P.B.+P.C.).
        self::assertStringContainsString('<VetaUD kc_sled="7470" c_radku="1" kc_min="5889"/>', $xml);
        self::assertStringContainsString('<VetaUD kc_sled="248" c_radku="24" kc_min="1278"/>', $xml);
        self::assertStringContainsString('<VetaUD kc_sled="248" c_radku="30" kc_min="1278"/>', $xml);
        self::assertStringContainsString('<VetaUD kc_sled="7" c_radku="64" kc_min="0"/>', $xml);

        // VetaUZ.
        self::assertStringContainsString('pr11_rozv="A"', $xml);
        self::assertStringContainsString('pr11_vzz="N"', $xml);
        self::assertStringContainsString('pr11_email="fakturace@example.com"', $xml);

        // VetaD metadata rozšíření.
        self::assertStringContainsString('uc_zav="A"', $xml);
        self::assertStringContainsString('audit="N"', $xml);
        self::assertStringContainsString('kat_uj="M"', $xml);
        self::assertStringContainsString('uv_rozsah_rozv="M"', $xml);
        self::assertStringContainsString('uv_rozsah_vzz="P"', $xml);
        self::assertStringContainsString('d_uv="31.12.2025"', $xml);
        self::assertStringContainsString('uv_mena="CZK"', $xml);
        self::assertStringContainsString('sam_pr="0"', $xml);
    }

    public function testAppendixPassesXsd(): void
    {
        $validator = new XmlSchemaValidator();
        if (!$validator->hasSchema('dppdp9')) {
            self::markTestSkipped('XSD dppdp9_epo2.xsd není k dispozici.');
        }
        $validation = $validator->validate($this->buildXmlWithAppendix()['xml'], 'dppdp9');
        self::assertSame('passed', $validation['status'], 'XSD chyby: ' . implode(' | ', $validation['errors']));
        self::assertEmpty($validation['errors']);
    }

    public function testAppendixOmittedWhenNoData(): void
    {
        // Zpětná kompatibilita: bez $appendix parametru (nebo prázdného) appendix
        // bloky vůbec nevzniknou — stávající volání/testy zůstávají beze změny.
        $xml = $this->buildXml()['xml'];
        self::assertStringNotContainsString('<VetaUA', $xml);
        self::assertStringNotContainsString('<VetaUB', $xml);
        self::assertStringNotContainsString('<VetaUD', $xml);
        self::assertStringNotContainsString('<VetaUZ', $xml);
        self::assertStringNotContainsString('uc_zav=', $xml);
    }
}
