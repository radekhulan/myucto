<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\ForeignIncome;

use MyInvoice\Service\Tax\ForeignIncome\ForeignIncomeKindCatalog;
use MyInvoice\Service\Tax\ForeignIncome\ForeignIncomeNotice;
use MyInvoice\Service\Tax\ForeignIncome\ForeignIncomeRemittance;
use MyInvoice\Service\Tax\ForeignIncome\ForeignIncomeXmlBuilder;
use MyInvoice\Service\Tax\ForeignIncome\ForeignPayee;
use MyInvoice\Service\Tax\ForeignIncome\TaxSecurityNotice;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Oznámení podle § 38da (DPSHL1) a hlášení podle § 38e (DPSZD1) musí projít
 * připnutým EPO XSD a držet kritické kontroly, které schéma popisuje slovy,
 * ale nevynucuje typem. Soft-skip, když schéma není přítomné.
 */
final class ForeignIncomeXmlBuilderTest extends TestCase
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

    private function company(): ForeignPayee
    {
        return new ForeignPayee(
            ForeignPayee::TYP_OBCHODNI_SPOLECNOST,
            null,
            null,
            'Beispiel Software GmbH',
            null,
            'DE811234567',
            ForeignPayee::DIC_TYP_DIC,
            'DE',
            'DE',
            'München',
            '80331',
            'Marienplatz 1',
            ForeignPayee::ADRESA_SIDLO,
        );
    }

    private function individual(): ForeignPayee
    {
        return new ForeignPayee(
            ForeignPayee::TYP_FYZICKA_OSOBA,
            'Peter',
            'Muster',
            null,
            '1980-04-17',
            null,
            null,
            null,
            'CH',
            'Zürich',
            '8001',
            'Bahnhofstrasse 7',
            ForeignPayee::ADRESA_BYDLISTE,
            'Bern',
            'CH',
        );
    }

    /** Licenční poplatek průmyslový, sazba 10 %, sražená daň odvedena. */
    private function royaltyNotice(
        string $variant = ForeignIncomeNotice::TYP_RADNE,
    ): ForeignIncomeNotice {
        return new ForeignIncomeNotice(
            $variant,
            $variant === ForeignIncomeNotice::TYP_NASLEDNE ? '2025-09-01' : null,
            $this->company(),
            5,
            100,
            ForeignIncomeNotice::UHRADA_POPLATNIKOVI,
            '2025-06-30',
            null,
            90_000_00,
            100_000_00,
            10_000,
            '2025-06-30',
            '2025-07-31',
            null,
            null,
            400_000,
            'EUR',
            'EUR',
            25_000,
            $variant === ForeignIncomeNotice::TYP_NASLEDNE
                ? 'Následné oznámení k řádnému oznámení ze dne 20.7.2025.'
                : null,
            [new ForeignIncomeRemittance('2025-07-25', 10_000, '7720-77628031/0710')],
        );
    }

    public function testIncomeNoticeHeaderAndIncomeRow(): void
    {
        $xml = (new ForeignIncomeXmlBuilder())
            ->buildIncomeNotice($this->supplier(), $this->royaltyNotice())['xml'];

        self::assertStringContainsString('<DPSHL1', $xml);
        self::assertStringContainsString('k_uladis="DPS"', $xml);
        self::assertStringContainsString('dokument="HL1"', $xml);
        self::assertStringContainsString('hl_typ="R"', $xml);
        self::assertStringContainsString('c_ufo_cil="451"', $xml);
        // Druh příjmu i jeho skupina pocházejí z TÉHOŽ řádku číselníku.
        self::assertStringContainsString('druh_prij="5"', $xml);
        self::assertStringContainsString('k_rozl_prij="12"', $xml);
        self::assertStringContainsString('sazba="10.0"', $xml);
        self::assertStringContainsString('d_uhrady="30.6.2025"', $xml);
        self::assertStringContainsString('kc_uhrady="90000.00"', $xml);
        self::assertStringContainsString('kc_zakldane="100000.00"', $xml);
        self::assertStringContainsString('sraz_dan="10000"', $xml);
        self::assertStringContainsString('kurz="25.000"', $xml);
        self::assertStringContainsString('kc_hrubprij_zahr="4000.00"', $xml);
        self::assertStringContainsString('k_stat_dr="DE"', $xml);
        self::assertStringContainsString('typ_popl="02"', $xml);
        self::assertStringContainsString('typ_adr="02"', $xml);
        self::assertStringContainsString('kc_odv="10000"', $xml);
    }

    public function testIndividualIncomeNoticeUsesBirthDate(): void
    {
        $notice = new ForeignIncomeNotice(
            ForeignIncomeNotice::TYP_RADNE,
            null,
            $this->individual(),
            ForeignIncomeKindCatalog::KIND_BODY_MEMBER_REMUNERATION,
            150,
            ForeignIncomeNotice::UHRADA_POPLATNIKOVI,
            '2025-03-31',
            null,
            85_000_00,
            100_000_00,
            15_000,
            '2025-03-31',
            '2025-04-30',
            remittances: [new ForeignIncomeRemittance('2025-04-28', 15_000)],
        );

        $xml = (new ForeignIncomeXmlBuilder())
            ->buildIncomeNotice($this->supplier(), $notice)['xml'];

        self::assertStringContainsString('typ_popl="01"', $xml);
        self::assertStringContainsString('jmeno_popl="Peter"', $xml);
        self::assertStringContainsString('prijmeni_popl="Muster"', $xml);
        self::assertStringContainsString('d_narozeni="17.4.1980"', $xml);
        self::assertStringContainsString('misto_nar="Bern"', $xml);
        self::assertStringContainsString('k_rozl_prij="16"', $xml);
        self::assertStringContainsString('sazba="15.0"', $xml);
    }

    public function testExemptIncomeUsesYearAndOmitsRemittances(): void
    {
        $notice = new ForeignIncomeNotice(
            ForeignIncomeNotice::TYP_RADNE,
            null,
            $this->company(),
            8,
            0,
            ForeignIncomeNotice::ZAUCTOVANI_ZAVAZKU,
            null,
            2025,
            1_500_000_00,
            1_500_000_00,
            0,
        );

        $xml = (new ForeignIncomeXmlBuilder())
            ->buildIncomeNotice($this->supplier(), $notice)['xml'];

        self::assertStringContainsString('r_uhrady="2025"', $xml);
        self::assertStringNotContainsString('d_uhrady=', $xml);
        self::assertStringNotContainsString('<VetaU', $xml);
        self::assertStringContainsString('sazba="0.0"', $xml);
    }

    public function testExemptReportingRejectedForIncomeKindOutsideParagraph38da1(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // Příjmy ze služeb (kód 1) osvobozené být nemohou — nulová sazba se
        // oznamuje jen u licenčních poplatků, dividend a úroků.
        new ForeignIncomeNotice(
            ForeignIncomeNotice::TYP_RADNE,
            null,
            $this->company(),
            1,
            0,
            ForeignIncomeNotice::UHRADA_POPLATNIKOVI,
            '2025-06-30',
            null,
            10_000_00,
            10_000_00,
            0,
        );
    }

    public function testPaymentDateAndYearAreMutuallyExclusive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ForeignIncomeNotice(
            ForeignIncomeNotice::TYP_RADNE,
            null,
            $this->company(),
            5,
            100,
            ForeignIncomeNotice::UHRADA_POPLATNIKOVI,
            '2025-06-30',
            2025,
            90_000_00,
            100_000_00,
            10_000,
        );
    }

    public function testRemittanceMismatchWarns(): void
    {
        $notice = new ForeignIncomeNotice(
            ForeignIncomeNotice::TYP_RADNE,
            null,
            $this->company(),
            5,
            100,
            ForeignIncomeNotice::UHRADA_POPLATNIKOVI,
            '2025-06-30',
            null,
            90_000_00,
            100_000_00,
            10_000,
            remittances: [new ForeignIncomeRemittance('2025-07-25', 9_000)],
        );

        $result = (new ForeignIncomeXmlBuilder())
            ->buildIncomeNotice($this->supplier(), $notice);

        self::assertNotEmpty(array_filter(
            $result['warnings'],
            static fn (string $warning): bool => str_contains($warning, 'Úhrn odvodů'),
        ));
    }

    private function securityNotice(
        string $variant = TaxSecurityNotice::TYP_RADNE,
    ): TaxSecurityNotice {
        return new TaxSecurityNotice(
            $variant,
            new ForeignPayee(
                ForeignPayee::TYP_OBCHODNI_SPOLECNOST,
                null,
                null,
                'Example Trading Ltd.',
                null,
                'GB123456789',
                ForeignPayee::DIC_TYP_DIC,
                'GB',
                'GB',
                'London',
                'EC1A 1BB',
                '1 Example Street',
                ForeignPayee::ADRESA_SIDLO,
            ),
            'příjmy ze služeb podle § 22 odst. 1 písm. c) zákona o daních z příjmů',
            TaxSecurityNotice::SAZBA_10_PROCENT,
            250_000_00,
            25_000,
            '2025-05-12',
            '2025-06-10',
            '2025-07-08',
            'Praha 1, Zkušební 123/4',
            $variant === TaxSecurityNotice::TYP_NASLEDNE
                ? 'Následné hlášení k řádnému hlášení ze dne 15.7.2025.'
                : null,
        );
    }

    public function testSecurityNoticeHeader(): void
    {
        $xml = (new ForeignIncomeXmlBuilder())
            ->buildSecurityNotice($this->supplier(), $this->securityNotice())['xml'];

        self::assertStringContainsString('<DPSZD1', $xml);
        self::assertStringContainsString('k_uladis="DPS"', $xml);
        self::assertStringContainsString('dokument="ZD1"', $xml);
        self::assertStringContainsString('hl_typ="R"', $xml);
        self::assertStringContainsString('obch_jm_popl="Example Trading Ltd."', $xml);
        self::assertStringContainsString('k_stat_dr="GB"', $xml);
        self::assertStringContainsString('sazba="B"', $xml);
        self::assertStringContainsString('kc_prijem="250000.00"', $xml);
        self::assertStringContainsString('kc_zajisteni="25000"', $xml);
        self::assertStringContainsString('d_ucpripadu="12.5.2025"', $xml);
        self::assertStringContainsString('d_rozhodne="10.6.2025"', $xml);
        self::assertStringContainsString('d_odvodu="8.7.2025"', $xml);
        self::assertStringContainsString('adr_provozovny_cr="Praha 1, Zkušební 123/4"', $xml);
    }

    public function testZeroSecurityRateOnlyInCorrection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TaxSecurityNotice(
            TaxSecurityNotice::TYP_RADNE,
            $this->company(),
            'příjmy ze služeb podle § 22 odst. 1 písm. c)',
            TaxSecurityNotice::SAZBA_NULA,
            250_000_00,
            0,
            '2025-05-12',
            '2025-06-10',
        );
    }

    public function testOrdinarySecurityNoticeRequiresPositiveAmounts(): void
    {
        $this->expectException(\DomainException::class);

        new TaxSecurityNotice(
            TaxSecurityNotice::TYP_RADNE,
            $this->company(),
            'příjmy ze služeb podle § 22 odst. 1 písm. c)',
            TaxSecurityNotice::SAZBA_10_PROCENT,
            0,
            0,
            '2025-05-12',
            '2025-06-10',
        );
    }

    public function testPayeeMustBeNonResident(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ForeignPayee(
            ForeignPayee::TYP_OBCHODNI_SPOLECNOST,
            null,
            null,
            'Tuzemská s.r.o.',
            null,
            '12345678',
            null,
            null,
            'CZ',
            'Praha',
            '11000',
            'Zkušební 1',
        );
    }

    public function testIncomeNoticePassesXsd(): void
    {
        $this->assertXsdPasses('dpshl1', (new ForeignIncomeXmlBuilder())
            ->buildIncomeNotice($this->supplier(), $this->royaltyNotice())['xml']);
    }

    public function testCorrectionIncomeNoticePassesXsd(): void
    {
        $this->assertXsdPasses('dpshl1', (new ForeignIncomeXmlBuilder())->buildIncomeNotice(
            $this->supplier(),
            $this->royaltyNotice(ForeignIncomeNotice::TYP_NASLEDNE),
        )['xml']);
    }

    public function testSecurityNoticePassesXsd(): void
    {
        $this->assertXsdPasses('dpszd1', (new ForeignIncomeXmlBuilder())
            ->buildSecurityNotice($this->supplier(), $this->securityNotice())['xml']);
    }

    public function testCorrectionSecurityNoticePassesXsd(): void
    {
        $this->assertXsdPasses('dpszd1', (new ForeignIncomeXmlBuilder())->buildSecurityNotice(
            $this->supplier(),
            $this->securityNotice(TaxSecurityNotice::TYP_NASLEDNE),
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
