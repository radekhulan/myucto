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
        // Kódy tabulky majetku nejsou pořadová čísla řádků tiskopisu: hotovost je 05a,
        // banka 06 a zásoby 03. Dřív tu byla ta trojice prohozená a úřad by to nevytkl,
        // protože kontroluje jen formát. Zdroj: úřední popis struktury DPFDP7,
        // rozbor v private/RESERSE-DPFO-MAJETEK.md.
        self::assertStringContainsString('kc_dpfmz05a="5000"', $xml);   // hotovost na začátku
        self::assertStringContainsString('kc_z_dpfmz05a="7000"', $xml); // hotovost na konci
        self::assertStringContainsString('kc_dpfmz06="20000"', $xml);   // banka na začátku
        self::assertStringContainsString('kc_z_dpfmz06="50000"', $xml); // banka na konci
        self::assertStringContainsString('kc_dpfmz03="30000"', $xml);   // zásoby na začátku
        self::assertStringContainsString('kc_z_dpfmz03="20000"', $xml); // zásoby na konci
        self::assertStringContainsString('kc_dpfmz04="40000"', $xml);   // pohledávky
        self::assertStringContainsString('kc_dpfmz02="100000"', $xml);  // hmotný majetek

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

    /**
     * ř.70 regrese — zkušební EPO 31. 8. 2026: „Oddíl 5/ř.70 - položka se nerovná hodnotě
     * příslušného vzorce (81300)". `da_slevy` je jen mezisoučet bez vlastního tištěného
     * řádku a jeho přítomnost kazila EPO kontrolu ř.70 (uhrn_slevy35ba) — builder ho
     * proto z VETA_D_FIELDS vynechává, i kdyby ho volající v `fields` poslal.
     */
    public function testDaSlevyNeverReachesXmlEvenIfPresentInFields(): void
    {
        $calc = [
            'fields' => ['kc_op15_1a' => 30840, 'uhrn_slevy35ba' => 30840, 'da_slevy' => 99999],
            's7' => ['income' => 0],
            'family' => [],
        ];
        $xml = (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc)['xml'];
        self::assertStringNotContainsString('da_slevy="', $xml);
        self::assertStringContainsString('uhrn_slevy35ba="30840"', $xml);
    }

    /**
     * ř.77a regrese — zkušební EPO 31. 8. 2026: „Oddíl 5/ř.77a - hodnota položky
     * neodpovídá výpočtu uvedenému v pokynech k vyplnění DAP. (0)". `kc_db_po_odpd` se
     * dřív do XML vůbec nedostal (chyběl ve VETA_D_FIELDS); EPO chce i nulovou hodnotu
     * poslanou výslovně, stejně jako u kc_dan_po_db/kc_dan_celk.
     */
    public function testKcDbPoOdpdEmittedEvenAsZero(): void
    {
        $calc = [
            'fields' => ['kc_dan_po_db' => 50460, 'kc_dan_celk' => 50460, 'kc_db_po_odpd' => 0],
            's7' => ['income' => 0],
            'family' => [],
        ];
        $xml = (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc)['xml'];
        self::assertStringContainsString('kc_db_po_odpd="0"', $xml);
    }

    /**
     * Příloha 1/ř.113 regrese — zkušební EPO 31. 8. 2026: „Příloha 1/ř.113 - hodnota
     * položky neodpovídá hodnotě příslušného vzorce". EPO si ř.113 (kc_zd7p) dopočítává
     * ze součtu ř.104-112; ř.104 (kc_hosp_rozd) se dřív do VetaT vůbec nedostal. Builder
     * ho bere z `s7.before_adjustments` (§7 základ PŘED zvýšením/snížením), ne z
     * `s7.base` (=kc_zd7p, který úpravy už zahrnuje).
     */
    public function testKcHospRozdUsesBeforeAdjustmentsNotFinalBase(): void
    {
        $calc = [
            'fields' => [],
            's7' => ['income' => 150000, 'expenses' => 90000, 'base' => 75000, 'before_adjustments' => 60000, 'increase' => 20000, 'decrease' => 5000],
            'family' => [],
        ];
        $xml = (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc)['xml'];
        self::assertStringContainsString('kc_hosp_rozd="60000"', $xml);
        self::assertStringContainsString('kc_zd7p="75000"', $xml);
        // Nenulové increase/decrease bez itemizovaného rozpisu → jeden souhrnný řádek
        // VetaC/VetaE (viz níže, testAdjustmentSectionEFallback...), otestováno tady jen
        // že vůbec vzniknou, ať tahle regrese hlídá i oddíl E.
        self::assertStringContainsString('<VetaC kc_uprzvys_235="20000"', $xml);
        self::assertStringContainsString('<VetaE kc_uprsniz_235="5000"', $xml);
    }

    // ── Příloha 1 oddíl E (VetaC/VetaE) — private/AUDIT-DPFO-XML.md nález č. 2 ──────

    public function testAdjustmentSectionEAbsentWhenIncreaseAndDecreaseAreZero(): void
    {
        $calc = [
            'fields' => [],
            's7' => ['income' => 100000, 'expenses' => 60000, 'base' => 40000, 'increase' => 0, 'decrease' => 0],
            'family' => [],
        ];
        $xml = (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc)['xml'];
        self::assertStringNotContainsString('<VetaC', $xml);
        self::assertStringNotContainsString('<VetaE', $xml);
    }

    /**
     * Bez itemizovaného rozpisu (`s7.increase_items`/`decrease_items` prázdné nebo
     * chybí) builder NEVYMÝŠLÍ položky — pošle jeden souhrnný řádek s celou částkou
     * a musí zároveň varovat, že jde o zástupnou agregaci, ne doložený rozpis.
     */
    public function testAdjustmentSectionEFallbackSingleRowWithWarning(): void
    {
        $calc = [
            'fields' => [],
            's7' => ['income' => 150000, 'expenses' => 90000, 'base' => 75000, 'increase' => 20000, 'decrease' => 5000],
            'family' => [],
        ];
        $result = (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc);
        $xml = $result['xml'];

        self::assertSame(1, substr_count($xml, '<VetaC'));
        self::assertStringContainsString('<VetaC kc_uprzvys_235="20000" uprzvys_235=', $xml);
        self::assertSame(1, substr_count($xml, '<VetaE'));
        self::assertStringContainsString('<VetaE kc_uprsniz_235="5000" uprsniz_235=', $xml);

        $vetaCWarning = array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'VetaC') && str_contains($w, 'souhrnný řádek'));
        self::assertNotEmpty($vetaCWarning, 'chybí varování o zástupném souhrnném řádku VetaC');
        $vetaEWarning = array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'VetaE') && str_contains($w, 'souhrnný řádek'));
        self::assertNotEmpty($vetaEWarning, 'chybí varování o zástupném souhrnném řádku VetaE');
    }

    /**
     * S itemizovaným rozpisem (jak by ho jednou mohl předat DpfoReturnDataProvider,
     * nebo účetnictví FO s ručními položkami §23) builder postaví JEDEN řádek na
     * položku — žádný „souhrnný" fallback warning.
     */
    public function testAdjustmentSectionEItemizedRowsSkipFallbackWarning(): void
    {
        $calc = [
            'fields' => [],
            's7' => [
                'income' => 150000, 'expenses' => 90000, 'base' => 75000,
                'increase' => 20000, 'decrease' => 5000,
                'increase_items' => [['amount' => 20000, 'description' => 'Neuhrazené pojistné zaměstnavatele']],
                'decrease_items' => [['amount' => 5000, 'text' => 'Rozdíl účetních a daňových odpisů']],
            ],
            'family' => [],
        ];
        $result = (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc);
        $xml = $result['xml'];

        self::assertStringContainsString('<VetaC kc_uprzvys_235="20000" uprzvys_235="Neuhrazené pojistné zaměstnavatele"/>', $xml);
        self::assertStringContainsString('<VetaE kc_uprsniz_235="5000" uprsniz_235="Rozdíl účetních a daňových odpisů"/>', $xml);
        self::assertSame([], array_values(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'souhrnný řádek'),
        )));
    }

    public function testAdjustmentSectionEFollowsVetaTAndPrecedesVetaV(): void
    {
        $calc = [
            'fields' => [],
            's7' => ['income' => 150000, 'expenses' => 90000, 'base' => 75000, 'increase' => 20000, 'decrease' => 5000],
            's9' => ['income' => 180000, 'expenses' => 60000, 'base' => 120000],
            'family' => [],
        ];
        $xml = (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc)['xml'];

        $vetaTPos = strpos($xml, '<VetaT ');
        $vetaCPos = strpos($xml, '<VetaC ');
        $vetaEPos = strpos($xml, '<VetaE ');
        $vetaVPos = strpos($xml, '<VetaV ');
        self::assertNotFalse($vetaTPos);
        self::assertNotFalse($vetaCPos);
        self::assertNotFalse($vetaEPos);
        self::assertNotFalse($vetaVPos);
        self::assertLessThan($vetaCPos, $vetaTPos, 'VetaC musí následovat za VetaT v XSD sekvenci.');
        self::assertLessThan($vetaEPos, $vetaCPos, 'VetaE musí následovat za VetaC v XSD sekvenci.');
        self::assertLessThan($vetaVPos, $vetaEPos, 'VetaE musí předcházet VetaV v XSD sekvenci.');
    }

    // ── VetaD.m_deti*/m_detiztpp* — private/AUDIT-DPFO-XML.md nález č. 1 ────────────

    /** @return array<string,mixed> */
    private function childrenCalc(): array
    {
        return [
            'fields' => [],
            's7' => ['income' => 0, 'expenses' => 0],
            'family' => ['children' => [
                [
                    'first_name' => 'Jan', 'last_name' => 'Novák', 'birth_date' => '2015-01-01',
                    'months' => array_map(static fn (int $m): array => ['month' => $m, 'claimed' => true, 'order' => 1, 'ztpp' => false], range(1, 12)),
                ],
                [
                    'first_name' => 'Eva', 'last_name' => 'Nováková', 'birth_date' => '2018-01-01',
                    // jen 6 měsíců (narození/nástup v půlce roku) — cross-check musí sečíst přesně tohle.
                    'months' => array_map(static fn (int $m): array => ['month' => $m, 'claimed' => true, 'order' => 2, 'ztpp' => false], range(1, 6)),
                ],
                [
                    'first_name' => 'Petr', 'last_name' => 'Novák', 'birth_date' => '2020-01-01',
                    'months' => array_map(static fn (int $m): array => ['month' => $m, 'claimed' => true, 'order' => 3, 'ztpp' => true], range(1, 12)),
                ],
            ]],
        ];
    }

    public function testChildMonthTotalsMatchSumOfIndividualVetaARows(): void
    {
        $xml = (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $this->childrenCalc())['xml'];

        self::assertSame(3, substr_count($xml, '<VetaA '));
        self::assertStringContainsString('m_deti="12"', $xml);   // 1. dítě, bez ZTP/P (Jan)
        self::assertStringContainsString('m_detiztpp="0"', $xml);
        self::assertStringContainsString('m_deti2="6"', $xml);   // 2. dítě, bez ZTP/P (Eva, 6 měsíců)
        self::assertStringContainsString('m_detiztpp2="0"', $xml);
        self::assertStringContainsString('m_deti3="0"', $xml);   // 3.+ dítě bez ZTP/P — Petr má ZTP/P
        self::assertStringContainsString('m_detiztpp3="12"', $xml);

        $validator = new XmlSchemaValidator();
        if (!$validator->hasSchema('dpfdp7')) {
            self::markTestSkipped('XSD dpfdp7_epo2.xsd není k dispozici.');
        }
        $validation = $validator->validate($xml, 'dpfdp7');
        self::assertSame('passed', $validation['status'], 'XSD chyby: ' . implode(' | ', $validation['errors']));
    }

    public function testChildMonthTotalsAbsentWithoutChildren(): void
    {
        $calc = ['fields' => [], 's7' => ['income' => 0, 'expenses' => 0], 'family' => ['children' => []]];
        $xml = (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc)['xml'];
        self::assertStringNotContainsString('m_deti=', $xml);
        self::assertStringNotContainsString('m_detiztpp', $xml);
        self::assertStringNotContainsString('m_deti2', $xml);
        self::assertStringNotContainsString('m_deti3', $xml);
    }
}
