<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\TaxBonus;

use MyInvoice\Service\Payroll\TaxBonus\TaxBonusClaim;
use MyInvoice\Service\Payroll\TaxBonus\TaxBonusRequestXmlBuilder;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Vygenerované žádosti musí projít připnutým EPO2 XSD (`api/xsd/dpzmb1.xsd`,
 * `api/xsd/dpzdb1.xsd`). Soft-skip, když schéma není přítomné.
 */
final class TaxBonusRequestXmlBuilderTest extends TestCase
{
    /**
     * Naložení s částkou z ř. 3 je u obou tiskopisů povinné (EPO na to má
     * nepropustnou kontrolu), takže ho potřebuje i každý test, který ověřuje
     * jen tvar dokumentu.
     */
    private const DISPOSITION = ['kc_ponech' => 100];

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
        ];
    }

    private function monthlyClaim(): TaxBonusClaim
    {
        return new TaxBonusClaim(TaxBonusClaim::FORM_MONTHLY, 2026, 5, '2026-06-11', 5_000, 1_200, 3_800);
    }

    private function annualClaim(): TaxBonusClaim
    {
        return new TaxBonusClaim(TaxBonusClaim::FORM_ANNUAL, 2025, null, '2026-04-10', 9_000, 1_000, 8_000);
    }

    public function testMonthlyRequestStructure(): void
    {
        $xml = (new TaxBonusRequestXmlBuilder())->build($this->supplier(), $this->monthlyClaim(), self::DISPOSITION)['xml'];

        self::assertStringContainsString('<Pisemnost', $xml);
        self::assertStringContainsString('<DPZMB1', $xml);
        self::assertStringContainsString('k_uladis="DPZ"', $xml);
        self::assertStringContainsString('dokument="MB1"', $xml);
        self::assertStringContainsString('zad_typ="B"', $xml);
        self::assertStringContainsString('c_ufo_cil="451"', $xml);
        self::assertStringContainsString('bonus_mesic="5"', $xml);
        self::assertStringContainsString('bonus_rok="2026"', $xml);
        self::assertStringContainsString('kc_bonus_celk="5000"', $xml);
        self::assertStringContainsString('kc_zalohy="1200"', $xml);
        self::assertStringContainsString('kc_bonus_vl="3800"', $xml);
        self::assertStringContainsString('d_bonus="11.6.2026"', $xml);
        // DIČ se z tvaru s mezerami normalizuje na kmenovou část (XSD `[0-9]{1,10}`).
        self::assertStringContainsString('dic="123456789"', $xml);
        self::assertStringContainsString('typ_ds="P"', $xml);
        self::assertStringContainsString('zkrobchjm="Ukázková firma s.r.o."', $xml);
        self::assertStringContainsString('sest_telef="601002003"', $xml);
        // VetaP žádostí DPZ tyhle atributy nezná — jejich přítomnost by podání shodila.
        self::assertStringNotContainsString('c_ufo="', $xml);
        self::assertStringNotContainsString('stat="', $xml);
        self::assertStringNotContainsString('c_telef="', $xml);
        self::assertStringNotContainsString('email="', $xml);
    }

    public function testAnnualRequestUsesTaxPeriodInsteadOfMonth(): void
    {
        $xml = (new TaxBonusRequestXmlBuilder())->build($this->supplier(), $this->annualClaim(), self::DISPOSITION)['xml'];

        self::assertStringContainsString('<DPZDB1', $xml);
        self::assertStringContainsString('dokument="DB1"', $xml);
        self::assertStringContainsString('bonus_zdobd="2025"', $xml);
        self::assertStringNotContainsString('bonus_mesic', $xml);
        self::assertStringNotContainsString('bonus_rok', $xml);
    }

    public function testMonthlyRequestPassesXsd(): void
    {
        $this->assertPassesXsd(TaxBonusClaim::FORM_MONTHLY, $this->monthlyClaim());
    }

    public function testAnnualRequestPassesXsd(): void
    {
        $this->assertPassesXsd(TaxBonusClaim::FORM_ANNUAL, $this->annualClaim());
    }

    public function testNaturalPersonEmitsNameInsteadOfCompany(): void
    {
        $supplier = $this->supplier();
        $supplier['taxpayer_type'] = 'fo';
        $supplier['company_name'] = 'Ing. Josef Novák, CSc.';
        $supplier['opr_jmeno'] = '';
        $supplier['opr_prijmeni'] = '';

        $xml = (new TaxBonusRequestXmlBuilder())->build($supplier, $this->monthlyClaim(), self::DISPOSITION)['xml'];

        self::assertStringContainsString('typ_ds="F"', $xml);
        self::assertStringContainsString('jmeno="Josef"', $xml);
        self::assertStringContainsString('prijmeni="Novák"', $xml);
        self::assertStringNotContainsString('zkrobchjm', $xml);
    }

    public function testMissingFinancialOfficeWarnsInsteadOfSilentlyDefaulting(): void
    {
        $supplier = $this->supplier();
        $supplier['financial_office_code'] = '';

        $result = (new TaxBonusRequestXmlBuilder())->build($supplier, $this->monthlyClaim(), self::DISPOSITION);

        self::assertNotEmpty($result['warnings']);
        self::assertStringContainsString('c_ufo_cil="451"', $result['xml']);
    }

    public function testRetainedAmountCannotExceedTheClaimedAmount(): void
    {
        $this->expectException(\DomainException::class);
        (new TaxBonusRequestXmlBuilder())->build(
            $this->supplier(),
            $this->monthlyClaim(),
            ['kc_ponech' => 3_801],
        );
    }

    public function testRetainedAmountIsEmittedWhenWithinTheClaim(): void
    {
        $result = (new TaxBonusRequestXmlBuilder())->build(
            $this->supplier(),
            $this->monthlyClaim(),
            ['kc_ponech' => 800],
        );

        self::assertStringContainsString('kc_ponech="800"', $result['xml']);
        $this->assertXsdPasses(TaxBonusClaim::FORM_MONTHLY, $result['xml']);
    }

    /**
     * Žádost bez naložení s částkou z ř. 3 daňový portál odmítne nepropustnou
     * kontrolou („Hodnota ř.3 je vyplněna a není vyplněna žádná částka vrácení,
     * převedení či ponechání"). Dřív se takové XML postavilo a spadlo až na
     * portálu; teď se odmítne rovnou a s vysvětlením.
     */
    public function testRequestWithoutAnyDispositionIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/jak se má s částkou z ř\. 3 naložit/u');

        (new TaxBonusRequestXmlBuilder())->build(
            $this->supplier(),
            $this->monthlyClaim(),
        );
    }

    public function testRefundToAccountEmitsVetaVAndPassesXsd(): void
    {
        $result = (new TaxBonusRequestXmlBuilder())->build(
            $this->supplier(),
            $this->monthlyClaim(),
            [
                'kc_vraceni' => 3_800,
                'vr_zpusob' => TaxBonusRequestXmlBuilder::VR_ZPUSOB_UCET,
                'vr_c_komds' => '100000008',
                'vr_k_bank' => '0800',
                'vr_naz_bank' => 'Testovací banka',
            ],
        );

        self::assertStringContainsString('<VetaV', $result['xml']);
        self::assertStringContainsString('kc_vraceni="3800"', $result['xml']);
        self::assertStringContainsString('vr_zpusob="U"', $result['xml']);
        // Číslo účtu patří do `vr_c_komds`; `vr_pbu` je jen předčíslí a má
        // v XSD nejvýš 6 číslic, takže záměna padne až na schématu.
        self::assertStringContainsString('vr_c_komds="100000008"', $result['xml']);
        self::assertStringNotContainsString('vr_pbu=', $result['xml']);
        $this->assertXsdPasses(TaxBonusClaim::FORM_MONTHLY, $result['xml']);
    }

    public function testRefundToAccountWithoutBankDetailsIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/číslo účtu/u');

        (new TaxBonusRequestXmlBuilder())->build(
            $this->supplier(),
            $this->monthlyClaim(),
            ['kc_vraceni' => 3_800, 'vr_zpusob' => TaxBonusRequestXmlBuilder::VR_ZPUSOB_UCET],
        );
    }

    public function testDispositionsTogetherCannotExceedTheClaimedAmount(): void
    {
        $this->expectException(\DomainException::class);

        (new TaxBonusRequestXmlBuilder())->build(
            $this->supplier(),
            $this->monthlyClaim(),
            [
                'kc_ponech' => 1_000,
                'kc_vraceni' => 3_000,
                'vr_zpusob' => TaxBonusRequestXmlBuilder::VR_ZPUSOB_ADRESA,
            ],
        );
    }

    private function assertPassesXsd(string $formCode, TaxBonusClaim $claim): void
    {
        $result = (new TaxBonusRequestXmlBuilder())->build($this->supplier(), $claim, self::DISPOSITION);
        $this->assertXsdPasses($formCode, $result['xml']);
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
