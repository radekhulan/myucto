<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\TaxStatement;

use MyInvoice\Service\Payroll\TaxStatement\DependentActivityRow;
use MyInvoice\Service\Payroll\TaxStatement\DependentActivityStatement;
use MyInvoice\Service\Payroll\TaxStatement\TaxStatementXmlBuilder;
use MyInvoice\Service\Payroll\TaxStatement\WithholdingTaxRow;
use MyInvoice\Service\Payroll\TaxStatement\WithholdingTaxStatement;
use MyInvoice\Service\Payroll\TaxStatement\WorkplaceHeadcount;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Vygenerovaná vyúčtování musí projít připnutým EPO XSD (`api/xsd/dpzvd6.xsd`,
 * `api/xsd/dpsvd2.xsd`). Soft-skip, když schéma není přítomné.
 */
final class TaxStatementXmlBuilderTest extends TestCase
{
    /** @return array<string,mixed> */
    private function supplier(): array
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
            'dic' => 'CZ 123 456 789',
            'taxpayer_type' => 'po',
            'financial_office_code' => '451',
            'workplace_code' => '2001',
            'opr_jmeno' => 'Jan',
            'opr_prijmeni' => 'Novák',
            'opr_postaveni' => 'jednatel',
            'sest_jmeno' => 'Eva',
            'sest_prijmeni' => 'Účetní',
            'sest_telefon' => '+420 601 002 003',
            'sest_email' => 'ucetni@example.test',
        ];
    }

    private function dependentActivity(
        string $variant = DependentActivityStatement::TYP_RADNE,
    ): DependentActivityStatement {
        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = new DependentActivityRow(
                48_200,
                48_200,
                0,
                $month === 3 ? 15_400 : 0,
                $month === 3 ? 4_460 : 1_260,
                0,
                $month === 3 ? 28_340 : 46_940,
            );
        }

        return new DependentActivityStatement(
            2025,
            $variant,
            $months,
            array_fill_keys(range(1, 12), 7),
            [new WorkplaceHeadcount('554782', 'Hlavní město Praha', 'Hlavní město Praha', 7)],
            [['month' => 3, 'amount' => 15_400]],
            15_400,
            3_200,
            0,
        );
    }

    private function withholdingTax(
        string $variant = DependentActivityStatement::TYP_RADNE,
    ): WithholdingTaxStatement {
        $months = [];
        foreach ([1, 2, 3] as $month) {
            $months[$month] = new WithholdingTaxRow(900_50, 900_50, 0, 0, 0, 0, 900_50);
        }

        return new WithholdingTaxStatement(
            2025,
            $variant,
            WithholdingTaxStatement::DRUH_PRIJMU_FO,
            $months,
        );
    }

    public function testDependentActivityHeaderAndPartTwo(): void
    {
        $xml = (new TaxStatementXmlBuilder())
            ->buildDependentActivity($this->supplier(), $this->dependentActivity())['xml'];

        self::assertStringContainsString('<DPZVD6', $xml);
        self::assertStringContainsString('k_uladis="DPZ"', $xml);
        self::assertStringContainsString('dokument="VD6"', $xml);
        self::assertStringContainsString('vdadpz_typ="B"', $xml);
        self::assertStringContainsString('zdobd_od="1.1.2025"', $xml);
        self::assertStringContainsString('zdobd_do="31.12.2025"', $xml);
        self::assertStringContainsString('poc_zam12="7"', $xml);
        // Ř. 1 = úhrn sl. 1, ř. 2 = úhrn sl. 4, ř. 4 = úhrn sl. 5.
        self::assertStringContainsString('kc_dpzii01="578400"', $xml);
        self::assertStringContainsString('kc_dpzii02="15400"', $xml);
        self::assertStringContainsString('kc_dpzii03a="18320"', $xml);
        // Ř. 8 = ř. 1 − ř. 2 + ř. 3 − ř. 4 + ř. 5.
        self::assertStringContainsString('kc_dpzii08="544680"', $xml);
        // Ř. 9 = úhrn sl. 11, ř. 10 = ř. 9 − ř. 8.
        self::assertStringContainsString('kc_dpzii09="544680"', $xml);
        self::assertStringContainsString('kc_dpzii10="0"', $xml);
        self::assertStringContainsString('uhrnprepl="15400"', $xml);
        self::assertStringContainsString('uhrndopl="3200"', $xml);
    }

    public function testDependentActivityUsesThePayerVetaPWithoutVatAttributes(): void
    {
        $xml = (new TaxStatementXmlBuilder())
            ->buildDependentActivity($this->supplier(), $this->dependentActivity())['xml'];

        self::assertStringContainsString('dic="123456789"', $xml);
        self::assertStringContainsString('typ_ds="P"', $xml);
        self::assertStringContainsString('sest_email="ucetni@example.test"', $xml);
        // `VetaP` téhle rodiny tyhle atributy nemá — podání by na nich spadlo.
        self::assertStringNotContainsString('c_ufo="', $xml);
        self::assertStringNotContainsString('stat="', $xml);
        self::assertStringNotContainsString('c_telef="', $xml);
        self::assertStringNotContainsString(' email="', $xml);
    }

    public function testDependentActivityMonthlyColumnsAndAnnexOne(): void
    {
        $xml = (new TaxStatementXmlBuilder())
            ->buildDependentActivity($this->supplier(), $this->dependentActivity())['xml'];

        self::assertStringContainsString(
            '<VetaO mesic="3" kc_dpzi01="48200" kc_dpzi02="48200" kc_dpzi04="15400" '
            . 'kc_dpzi05="4460" kc_dpzi08="19860" kc_dpzi09="28340" kc_dpzi11="28340"/>',
            $xml,
        );
        self::assertStringContainsString('<VetaB c_obce_zuj="554782"', $xml);
        self::assertStringContainsString('naz_zko="Hlavní město Praha"', $xml);
        self::assertStringContainsString('poc_zam="7"', $xml);
        self::assertStringContainsString('<VetaG mesic_06="3" uhrnprepl_c="15400"/>', $xml);
        self::assertStringContainsString('s_kc_dpzi01="578400"', $xml);
        self::assertStringContainsString('s_kc_dpzi11="544680"', $xml);
    }

    public function testAdditionalDependentActivityOmitsWithheldRemittedAndPartTwo(): void
    {
        $result = (new TaxStatementXmlBuilder())->buildDependentActivity(
            $this->supplier(),
            $this->dependentActivity(DependentActivityStatement::TYP_DODATECNE),
            ['d_zjist' => '2026-05-04'],
        );
        $xml = $result['xml'];

        self::assertStringContainsString('vdadpz_typ="D"', $xml);
        self::assertStringContainsString('d_zjist="4.5.2026"', $xml);
        self::assertStringNotContainsString('kc_dpzi02=', $xml);
        self::assertStringNotContainsString('kc_dpzi11=', $xml);
        self::assertStringNotContainsString('kc_dpzii01=', $xml);
        self::assertStringNotContainsString('uhrnprepl=', $xml);
        self::assertStringNotContainsString('<VetaG', $xml);
        self::assertStringContainsString('kc_dpzi10="0"', $xml);
    }

    public function testAdditionalStatementWithoutDiscoveryDateWarns(): void
    {
        $result = (new TaxStatementXmlBuilder())->buildDependentActivity(
            $this->supplier(),
            $this->dependentActivity(DependentActivityStatement::TYP_DODATECNE),
        );

        self::assertNotEmpty(array_filter(
            $result['warnings'],
            static fn (string $warning): bool => str_contains($warning, 'datum zjištění'),
        ));
    }

    public function testWithholdingTaxKeepsTwoDecimalPlaces(): void
    {
        $xml = (new TaxStatementXmlBuilder())
            ->buildWithholdingTax($this->supplier(), $this->withholdingTax())['xml'];

        self::assertStringContainsString('<DPSVD2', $xml);
        self::assertStringContainsString('k_uladis="DPS"', $xml);
        self::assertStringContainsString('dokument="VD2"', $xml);
        self::assertStringContainsString('c_drp="772"', $xml);
        self::assertStringContainsString('dapdps_forma="B"', $xml);
        self::assertStringContainsString('kc_dpsi01="900.50"', $xml);
        self::assertStringContainsString('kc_dpsi08a="900.50"', $xml);
        self::assertStringContainsString('kc_dpsii01="2701.50"', $xml);
        self::assertStringContainsString('kc_dpsii04="2701.50"', $xml);
        self::assertStringContainsString('kc_dpsii05="0.00"', $xml);
    }

    public function testWithholdingTaxUnderpaymentIsNegativeOnRowFive(): void
    {
        $statement = new WithholdingTaxStatement(
            2025,
            DependentActivityStatement::TYP_RADNE,
            WithholdingTaxStatement::DRUH_PRIJMU_FO,
            [1 => new WithholdingTaxRow(1_000_00, 1_000_00, 0, 0, 0, 0, 400_00)],
        );

        $xml = (new TaxStatementXmlBuilder())
            ->buildWithholdingTax($this->supplier(), $statement)['xml'];

        self::assertStringContainsString('kc_dpsii05="-600.00"', $xml);
    }

    public function testMissingFinancialOfficeWarnsInsteadOfSilentlyDefaulting(): void
    {
        $supplier = $this->supplier();
        $supplier['financial_office_code'] = '';

        $result = (new TaxStatementXmlBuilder())
            ->buildDependentActivity($supplier, $this->dependentActivity());

        self::assertStringContainsString('c_ufo_cil="451"', $result['xml']);
        self::assertNotEmpty(array_filter(
            $result['warnings'],
            static fn (string $warning): bool => str_contains($warning, 'finanční úřad'),
        ));
    }

    public function testEmptyAnnexOneIsReported(): void
    {
        $statement = new DependentActivityStatement(
            2025,
            DependentActivityStatement::TYP_RADNE,
            [1 => new DependentActivityRow(100, 100, 0, 0, 0, 0, 100)],
            [1 => 1],
            [],
            [],
            0,
            0,
            0,
        );

        $result = (new TaxStatementXmlBuilder())
            ->buildDependentActivity($this->supplier(), $statement);

        self::assertNotEmpty(array_filter(
            $result['warnings'],
            static fn (string $warning): bool => str_contains($warning, 'Příloha č. 1'),
        ));
    }

    public function testDependentActivityPassesXsd(): void
    {
        $this->assertXsdPasses('dpzvd6', (new TaxStatementXmlBuilder())
            ->buildDependentActivity($this->supplier(), $this->dependentActivity())['xml']);
    }

    public function testAdditionalDependentActivityPassesXsd(): void
    {
        $this->assertXsdPasses('dpzvd6', (new TaxStatementXmlBuilder())->buildDependentActivity(
            $this->supplier(),
            $this->dependentActivity(DependentActivityStatement::TYP_DODATECNE),
            ['d_zjist' => '2026-05-04'],
        )['xml']);
    }

    public function testWithholdingTaxPassesXsd(): void
    {
        $this->assertXsdPasses('dpsvd2', (new TaxStatementXmlBuilder())
            ->buildWithholdingTax($this->supplier(), $this->withholdingTax())['xml']);
    }

    public function testAdditionalWithholdingTaxPassesXsd(): void
    {
        $this->assertXsdPasses('dpsvd2', (new TaxStatementXmlBuilder())->buildWithholdingTax(
            $this->supplier(),
            $this->withholdingTax(DependentActivityStatement::TYP_DODATECNE),
            ['d_zjist' => '2026-05-04'],
        )['xml']);
    }

    private function assertXsdPasses(string $formCode, string $xml): void
    {
        $validator = new XmlSchemaValidator();
        if (!$validator->hasSchema($formCode)) {
            self::markTestSkipped('XSD ' . $formCode . '.xsd není k dispozici.');
        }
        $validation = $validator->validate($xml, $formCode);
        self::assertSame(
            'passed',
            $validation['status'],
            'XSD chyby: ' . implode(' | ', $validation['errors']),
        );
    }
}
