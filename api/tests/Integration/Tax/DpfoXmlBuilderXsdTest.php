<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Service\Tax\Return\DpfoReturnCalculator;
use MyInvoice\Service\Tax\Return\DpfoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Epic DP (issue #18) — vygenerované DPFDP7 XML musí projít validací proti EPO2 XSD
 * (api/xsd/dpfdp7_epo2.xsd). Soft-skip, když schema není přítomné.
 */
#[Group('integration')]
final class DpfoXmlBuilderXsdTest extends TestCase
{
    private function sampleSupplier(): array
    {
        return [
            'id' => 1,
            'company_name' => 'Jan Novák',
            'street' => 'Krátká 12/3',
            'street_number_pop' => '',
            'street_number_orient' => '',
            'city' => 'Praha',
            'zip' => '110 00',
            'country_iso2' => 'CZ',
            'ic' => '87654321',
            'dic' => 'CZ7801011234',
            'taxpayer_type' => 'fo',
            'financial_office_code' => '451',
            'cz_nace_code' => '62020',
            'phone' => '+420 601 002 003',
        ];
    }

    private function buildXml(): array
    {
        $calc = (new DpfoReturnCalculator())->compute(
            ['s7_income' => 1000000, 's7_expenses' => 600000, 's7_base' => 400000, 'expense_mode' => 'pausal', 'expense_rate' => 60],
            ['s6_employment' => ['income' => 300000, 'withholding' => 40000], 'tax_paid_advances' => 0],
            ['spouse_credit' => true, 'children_count' => 2, 'donations' => 20000, 'mortgage_interest' => 50000],
            TaxConstants::forYear(2025)
        );
        return (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc);
    }

    public function testStructure(): void
    {
        $xml = $this->buildXml()['xml'];
        self::assertStringContainsString('<DPFDP7', $xml);
        self::assertStringContainsString('k_uladis="DPF"', $xml);
        self::assertStringContainsString('dokument="DP7"', $xml);
        self::assertStringContainsString('rod_c="7801011234"', $xml);
        self::assertStringContainsString('kc_prij7="1000000"', $xml);
        self::assertStringContainsString('vyd7proc="A"', $xml);
        self::assertStringContainsString('jmeno="Jan"', $xml);
        self::assertStringContainsString('prijmeni="Novák"', $xml);
    }

    public function testPersonTitleAndCountryUseSharedEpoNormalization(): void
    {
        $supplier = $this->sampleSupplier();
        $supplier['company_name'] = 'MUDr. Josef Novák, Ph.D.';
        $supplier['country_iso2'] = 'DE';
        $calc = (new DpfoReturnCalculator())->compute(
            ['s7_base' => 100000, 'expense_mode' => 'actual', 'expense_rate' => 0],
            [],
            [],
            TaxConstants::forYear(2025)
        );
        $xml = (new DpfoXmlBuilder())->build($supplier, 2025, $calc)['xml'];
        self::assertStringContainsString('jmeno="Josef"', $xml);
        self::assertStringContainsString('prijmeni="Novák"', $xml);
        self::assertStringContainsString('stat="NĚMECKO"', $xml);
    }

    public function testExplicitPersonNameFieldsHavePriority(): void
    {
        $supplier = $this->sampleSupplier();
        $supplier['company_name'] = 'Obchodní označení';
        $supplier['opr_jmeno'] = 'Jana';
        $supplier['opr_prijmeni'] = 'Novotná';
        $calc = (new DpfoReturnCalculator())->compute(
            ['s7_base' => 100000, 'expense_mode' => 'actual', 'expense_rate' => 0],
            [],
            [],
            TaxConstants::forYear(2025)
        );
        $xml = (new DpfoXmlBuilder())->build($supplier, 2025, $calc)['xml'];
        self::assertStringContainsString('jmeno="Jana"', $xml);
        self::assertStringContainsString('prijmeni="Novotná"', $xml);
    }

    public function testPassesXsd(): void
    {
        $validator = new XmlSchemaValidator();
        if (!$validator->hasSchema('dpfdp7')) {
            self::markTestSkipped('XSD dpfdp7_epo2.xsd není k dispozici.');
        }
        $result = $this->buildXml();
        $validation = $validator->validate($result['xml'], 'dpfdp7');
        self::assertSame('passed', $validation['status'], 'XSD chyby: ' . implode(' | ', $validation['errors']));
        self::assertEmpty($validation['errors']);
    }

    /** DP v2 fáze 2 — dodatečné DPFO: dap_typ=D + d_zjist (důvody = e-příloha, ne ve větě). */
    private function buildAmendmentXml(): array
    {
        $calc = (new DpfoReturnCalculator())->compute(
            ['s7_income' => 1000000, 's7_expenses' => 600000, 's7_base' => 400000, 'expense_mode' => 'pausal', 'expense_rate' => 60],
            ['tax_paid_advances' => 0],
            ['children_count' => 0],
            TaxConstants::forYear(2025)
        );
        return (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc, [
            'dap_typ' => 'D',
            'd_zjist' => '2026-04-02',
            'duvod' => 'Opomenutý příjem §7 zjištěný po podání řádného přiznání.',
        ]);
    }

    public function testAmendmentAttributes(): void
    {
        $xml = $this->buildAmendmentXml()['xml'];
        self::assertStringContainsString('dap_typ="D"', $xml);
        self::assertStringContainsString('d_zjist="2.4.2026"', $xml);
        // duvpoddapdpf je jednoznakový kód (G/I), NE volný text — z důvodů podání se neplní.
        self::assertStringNotContainsString('duvpoddapdpf=', $xml);
    }

    public function testAmendmentPassesXsd(): void
    {
        $validator = new XmlSchemaValidator();
        if (!$validator->hasSchema('dpfdp7')) {
            self::markTestSkipped('XSD dpfdp7_epo2.xsd není k dispozici.');
        }
        $validation = $validator->validate($this->buildAmendmentXml()['xml'], 'dpfdp7');
        self::assertSame('passed', $validation['status'], 'XSD chyby: ' . implode(' | ', $validation['errors']));
    }

    // ── Fáze E: záporný kc_uhrn + uplatnění ztráty §34 (kc_ztrata2) ──────────

    /** Ztráta §7–§10: ř. 41 (kc_uhrn) záporný, základ = jen §6 — musí projít XSD. */
    private function buildLossYearXml(): array
    {
        $calc = (new DpfoReturnCalculator())->compute(
            ['s7_income' => 100000, 's7_expenses' => 400000, 's7_base' => -300000, 'expense_mode' => 'actual', 'expense_rate' => 0],
            ['s6_employment' => ['income' => 500000, 'withholding' => 0], 'tax_paid_advances' => 0],
            ['children_count' => 0],
            TaxConstants::forYear(2025)
        );
        return (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc);
    }

    public function testNegativeUhrnPassesXsd(): void
    {
        $xml = $this->buildLossYearXml()['xml'];
        self::assertStringContainsString('kc_uhrn="-300000"', $xml, 'ř. 41 vykazuje skutečnou ztrátu roku.');
        self::assertStringContainsString('kc_zakldan23="500000"', $xml, 'Základ daně = jen §6.');

        $validator = new XmlSchemaValidator();
        if (!$validator->hasSchema('dpfdp7')) {
            self::markTestSkipped('XSD dpfdp7_epo2.xsd není k dispozici.');
        }
        $validation = $validator->validate($xml, 'dpfdp7');
        self::assertSame('passed', $validation['status'], 'XSD chyby: ' . implode(' | ', $validation['errors']));
    }

    /** Uplatnění ztráty minulých let §34 → kc_ztrata2 (ř. 44), kc_zakldan (ř. 45). */
    private function buildLossCarryforwardXml(): array
    {
        $calc = (new DpfoReturnCalculator())->compute(
            ['s7_income' => 500000, 's7_expenses' => 200000, 's7_base' => 300000, 'expense_mode' => 'actual', 'expense_rate' => 0],
            ['loss_carryforward' => 120000, 'tax_paid_advances' => 0],
            ['children_count' => 0],
            TaxConstants::forYear(2025)
        );
        return (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc);
    }

    public function testLossCarryforwardPassesXsd(): void
    {
        $xml = $this->buildLossCarryforwardXml()['xml'];
        self::assertStringContainsString('kc_ztrata2="120000"', $xml, 'ř. 44 uplatněná ztráta.');
        self::assertStringContainsString('kc_zakldan="180000"', $xml, 'ř. 45 = 300000 − 120000.');
        self::assertStringContainsString('kc_zakldan23="300000"', $xml, 'ř. 42 základ daně beze změny.');

        $validator = new XmlSchemaValidator();
        if (!$validator->hasSchema('dpfdp7')) {
            self::markTestSkipped('XSD dpfdp7_epo2.xsd není k dispozici.');
        }
        $validation = $validator->validate($xml, 'dpfdp7');
        self::assertSame('passed', $validation['status'], 'XSD chyby: ' . implode(' | ', $validation['errors']));
    }

    public function testDetailedFamilyActivitiesAndClosingAreEmittedAndXsdValid(): void
    {
        $calc = (new DpfoReturnCalculator())->compute(
            [
                'activities' => [
                    ['name' => 'Řemeslo', 'nace_code' => '43320', 'income' => 300000, 'expense_mode' => 'pausal', 'expense_rate' => 80, 'active_months' => 12],
                    ['name' => 'Poradenství', 'nace_code' => '62020', 'income' => 200000, 'expense_mode' => 'pausal', 'expense_rate' => 60, 'active_months' => 6],
                ],
                'expense_mode' => 'pausal',
                'expense_rate' => 80,
                'accounting_mode' => 'tax_evidence',
                'closing' => [
                    'status' => 'final',
                    'opening_balances' => ['fixed_assets' => 100000, 'cash' => 5000, 'bank' => 20000, 'inventory' => 30000, 'receivables' => 40000, 'other_assets' => 0, 'liabilities' => 25000, 'reserves' => 0],
                    'closing_balances' => ['fixed_assets' => 80000, 'cash' => 7000, 'bank' => 50000, 'inventory' => 20000, 'receivables' => 10000, 'other_assets' => 0, 'liabilities' => 15000, 'reserves' => 0, 'depreciation' => 20000],
                ],
            ],
            [],
            [
                'children' => [[
                    'first_name' => 'Eva', 'last_name' => 'Nováková', 'birth_date' => '2020-05-06',
                    'months' => [['month' => 1, 'claimed' => true, 'order' => 1, 'ztpp' => true]],
                ]],
                'spouse_claim' => [
                    'first_name' => 'Jana', 'last_name' => 'Nováková', 'birth_date' => '1980-02-03',
                    'eligible_months' => 6, 'ztpp' => false,
                ],
            ],
            TaxConstants::forYear(2025),
        );
        $xml = (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc)['xml'];

        self::assertStringContainsString('<VetaA ', $xml);
        self::assertStringContainsString('vyzdite_ztpp="1"', $xml);
        self::assertStringContainsString('<Vetac ', $xml);
        // Vstup „62020" je zápis třídy 62.02.0 dle ČSÚ; v číselníku ČINNOSTI taková
        // hodnota NENÍ, kanonický kód je 620200 (poradenství v IT). Dřív se posílal
        // vstup tak, jak byl — tedy hodnota mimo číselník, kterou EPO odmítne.
        self::assertStringContainsString('c_nace_dal="620200"', $xml);
        self::assertStringContainsString('<VetaU ', $xml);
        self::assertStringContainsString('kc_z_dpfmz03="7000"', $xml);

        $validator = new XmlSchemaValidator();
        if (!$validator->hasSchema('dpfdp7')) {
            self::markTestSkipped('XSD dpfdp7_epo2.xsd není k dispozici.');
        }
        $validation = $validator->validate($xml, 'dpfdp7');
        self::assertSame('passed', $validation['status'], 'XSD chyby: ' . implode(' | ', $validation['errors']));
    }

    /** F12: aktivní §7 (příjmy > 0) bez platného NACE → varování o kritické kontrole c_nace. */
    public function testMissingNaceOnActiveSection7ProducesWarning(): void
    {
        $calc = [
            'fields' => ['kc_zd7' => 500000],
            's7' => [
                'income' => 500000,
                'expenses' => 0,
                'base' => 500000,
                'expense_mode' => 'actual',
                'activities' => [
                    ['income' => 500000, 'expenses' => 0, 'nace_code' => '', 'active_months' => 12],
                    ['income' => 200000, 'expenses' => 0, 'nace_code' => '', 'expense_rate' => 0],
                ],
            ],
            'family' => [],
        ];
        $result = (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc);
        self::assertStringNotContainsString('c_nace=', $result['xml']);
        self::assertContains(
            'Chybí nebo neplatný kód NACE u hlavní činnosti — EPO má na c_nace kritickou kontrolu, doplňte před podáním.',
            $result['warnings']
        );
        self::assertContains(
            'Chybí nebo neplatný kód NACE u vedlejší činnosti §7 — EPO má na c_nace_dal kritickou kontrolu, doplňte před podáním.',
            $result['warnings']
        );
    }

    // ── EPO zkušební podání 2026-08-30 ───────────────────────────────────────

    /** ř.101/102/113 — Příloha 1 "Celkem" řádek tabulky musí sedět na kc_prij7/kc_vyd7. */
    public function testCelkPrPrij7AndVydSumTableTotal(): void
    {
        $xml = $this->buildXml()['xml'];
        self::assertStringContainsString('kc_prij7="1000000"', $xml);
        self::assertStringContainsString('celk_pr_prij7="1000000"', $xml);
        self::assertStringContainsString('kc_vyd7="600000"', $xml);
        self::assertStringContainsString('celk_pr_vyd7="600000"', $xml);
    }

    /** ř.36 — kc_zd6p musí být ve VetaO vedle kc_zd6, jinak EPO hlásí ř.36 ≠ ř.34. */
    public function testKcZd6pIsEmittedInVetaO(): void
    {
        $xml = $this->buildXml()['xml'];
        self::assertStringContainsString('kc_zd6="300000"', $xml);
        self::assertStringContainsString('kc_zd6p="300000"', $xml);
    }

    /** da_dan16 je vždy celé Kč (§16 ZDP se zaokrouhluje nahoru) — bez ".00". */
    public function testDaDan16HasNoDecimalPoint(): void
    {
        $xml = $this->buildXml()['xml'];
        self::assertMatchesRegularExpression('/da_dan16="[0-9]+"/', $xml);
        self::assertStringNotContainsString('da_dan16="150000.00"', $xml);
    }

    /** F12: uplatněná sleva na manžela / bonus na děti bez identity → měkký warning. */
    /**
     * F12 regrese (EPO test 2026-08-30): da_slevy je daň PO slevách (mezisoučet), ne
     * částka slevy na manžela — warning se dřív spouštěl u KAŽDÉ nenulové daně, i bez
     * jakéhokoli nároku na slevu na manžela/manželku.
     */
    public function testNonzeroTaxWithoutSpouseCreditProducesNoWarning(): void
    {
        $calc = [
            'fields' => ['da_slevy' => 50460, 'kc_op15_1c' => 0],
            's7' => ['income' => 0],
            'family' => [],
        ];
        $result = (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc);
        self::assertSame([], array_values(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'sleva na manžela'),
        )));
    }

    public function testCreditWithoutIdentityProducesWarning(): void
    {
        $calc = [
            'fields' => ['kc_op15_1c' => 24840, 'kc_danbonus' => 15204],
            's7' => ['income' => 0],
            'family' => [],
        ];
        $result = (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc);
        self::assertContains(
            'Uplatněna sleva na manžela/manželku (kc_op15_1c), ale chybí jeho identifikace — EPO ji bez identity odmítne, doplňte před podáním.',
            $result['warnings']
        );
        self::assertContains(
            'Uplatněno daňové zvýhodnění / bonus na děti, ale chybí identifikace dětí a měsíce — EPO je bez identity odmítne, doplňte před podáním.',
            $result['warnings']
        );
    }
}
