<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateDocumentData;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateFormCatalog;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificatePdfRenderer;
use MyInvoice\Service\Payroll\Document\PayrollDocumentEmployerSnapshot;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

final class AnnualTaxCertificateDocumentDataTest extends TestCase
{
    #[DataProvider('certificateKinds')]
    public function testRendererCreatesAuditablePdfWithExact2026FormContent(
        PayrollDocumentKind $kind,
        string $formNumber,
        int $patternNumber,
        int $expectedTaxCzk,
        int $expectedPageCount,
    ): void {
        $data = self::document($kind);
        $artifact = (new AnnualTaxCertificatePdfRenderer())->render($data);

        self::assertSame($kind, $artifact->kind);
        self::assertStringStartsWith('%PDF-', $artifact->bytes);
        self::assertSame('application/pdf', $artifact->mimeType);
        self::assertSame($data->sourceSnapshotSha256, $artifact->sourceSnapshotHash);
        self::assertStringNotContainsString(
            $data->employeeName,
            $artifact->suggestedFilename,
        );
        self::assertStringContainsString(
            'PayrollSourceSnapshotHMACSHA256',
            $artifact->bytes,
        );
        self::assertStringContainsString(
            'PayrollRendererVersion',
            $artifact->bytes,
        );

        $parsedPdf = (new Parser())->parseContent($artifact->bytes);
        self::assertCount($expectedPageCount, $parsedPdf->getPages());
        $text = $parsedPdf->getText();
        self::assertStringContainsString($formNumber, $text);
        self::assertStringContainsString("vzor č. {$patternNumber}", $text);
        if ($kind === PayrollDocumentKind::TaxableIncomeAdvanceCertificate) {
            self::assertStringContainsString('Syntetická osoba', $text);
        } else {
            self::assertStringContainsString('PŘÍJMENÍ Osoba', $text);
            self::assertStringContainsString('JMÉNO Syntetická', $text);
        }
        self::assertStringContainsString('420 000', $text);
        self::assertStringContainsString(
            number_format($expectedTaxCzk, 0, ',', ' '),
            $text,
        );
        self::assertStringContainsString('31. 1. 2027', $text);
        self::assertStringContainsString(
            'První vydání – nenahrazuje dřívější potvrzení',
            $text,
        );
        self::assertStringNotContainsString(
            'Toto potvrzení nahrazuje potvrzení vydané dne',
            $text,
        );
        if ($kind === PayrollDocumentKind::TaxableIncomeAdvanceCertificate) {
            self::assertStringContainsString(
                'Prohlášení poplatníka: učiněno',
                $text,
            );
            self::assertStringContainsString(
                'Podepsané kalendářní měsíce: 1–3, 5',
                $text,
            );
            self::assertStringContainsString(
                'na dlouhodobý investiční produkt',
                $text,
            );
            self::assertStringContainsString(
                'Děti uplatněné jako vyživované',
                $text,
            );
            self::assertStringContainsString(
                'Stupeň invalidity (ZTP/P)',
                $text,
            );
            self::assertStringContainsString(
                'Roční zúčtování záloh a daňového zvýhodnění nebylo provedeno',
                preg_replace('/\s+/u', ' ', $text) ?? $text,
            );
            self::assertNull(
                $data->toTemplateData()['nonresident_insurance_czk'],
            );
        } else {
            self::assertStringContainsString(
                'Zdaňovací období: kalendářní měsíce 1–3, 5 roku 2026',
                $text,
            );
            self::assertStringContainsString('Daňový rezident ČR', $text);
        }

        $qaOutput = getenv('MYINVOICE_ANNUAL_TAX_CERTIFICATE_QA_OUTPUT');
        if (is_string($qaOutput) && $qaOutput !== '') {
            $path = preg_replace(
                '/\.pdf$/D',
                '-' . $kind->value . '.pdf',
                $qaOutput,
            );
            self::assertIsString($path);
            $directory = dirname($path);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            file_put_contents($path, $artifact->bytes);
        }
    }

    public function testRejectsMinorUnitAmountThatCannotBeReportedInWholeCzk(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('celých Kč');

        self::document(
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            accruedIncomeMinorUnits: 42_000_050,
        );
    }

    public function testReplacementCertificatePrintsActualPreviousIssueDate(): void
    {
        $artifact = (new AnnualTaxCertificatePdfRenderer())->render(
            self::document(
                PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
                replacesIssuedAt: '2026-08-04',
            ),
        );

        $text = (new Parser())->parseContent($artifact->bytes)->getText();
        self::assertStringContainsString(
            'Toto potvrzení nahrazuje potvrzení vydané dne 4. 8. 2026',
            $text,
        );
        self::assertStringContainsString(
            'Oprava doložené částky zálohy na daň.',
            $text,
        );
        self::assertCount(
            2,
            (new Parser())->parseContent($artifact->bytes)->getPages(),
        );

        $qaOutput = getenv('MYINVOICE_ANNUAL_TAX_CERTIFICATE_QA_OUTPUT');
        if (is_string($qaOutput) && $qaOutput !== '') {
            $path = preg_replace(
                '/\.pdf$/D',
                '-advance-correction.pdf',
                $qaOutput,
            );
            self::assertIsString($path);
            $directory = dirname($path);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            file_put_contents($path, $artifact->bytes);
        }
    }

    public function testKindsCannotLeakIntoEachOther(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('srážkovou daň');

        new AnnualTaxCertificateDocumentData(
            sourceSnapshotSha256: str_repeat('c', 64),
            kind: PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            taxYear: 2026,
            form: AnnualTaxCertificateFormCatalog::resolve(
                2026,
                PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            ),
            employer: self::employer(),
            employeeName: 'Syntetická osoba',
            employeeFirstName: 'Syntetická',
            employeeLastName: 'Osoba',
            previousNames: [],
            personalIdentifierLabel: 'Rodné číslo',
            personalIdentifierValue: '0001010009',
            employeeAddress: 'Modelová 2, 602 00 Brno, CZ',
            months: [1],
            taxDeclarationStatus: 'signed',
            taxDeclarationSignedMonths: [1],
            taxResidenceStatus: 'czech-resident',
            taxResidenceCountryCode: 'CZ',
            issuedAt: '2026-08-04 12:30:00',
            replacesIssuedAt: null,
            correctionReason: null,
            employerProductContributionsMinorUnits: self::products(),
            childTaxBenefits: [],
            disabilityTaxCredits: [],
            annualSettlement: ['performed' => false, 'result' => null],
            nonresidentInsuranceMinorUnits: null,
            accruedIncomeMinorUnits: 10_000_000,
            paidIncomeMinorUnits: 10_000_000,
            advanceTaxMinorUnits: 1_500_000,
            withholdingTaxMinorUnits: 100_000,
            taxBonusMinorUnits: 0,
            paymentEvidenceCutoff: '2027-01-31',
            lastProvenPaymentDate: '2026-02-10',
        );
    }

    public function testAdvanceCertificateRendersCommonDocumentedVariants(): void
    {
        $data = self::documentWithCommonVariants();
        $artifact = (new AnnualTaxCertificatePdfRenderer())->render($data);

        $text = preg_replace(
            '/\s+/u',
            ' ',
            (new Parser())->parseContent(
                $artifact->bytes,
            )->getText(),
        ) ?? '';

        self::assertStringContainsString('Daňový rezident ČR (CZ)', $text);
        self::assertStringContainsString('Dítě Syntetické', $text);
        self::assertStringContainsString('1. 2. 2020', $text);
        self::assertStringContainsString('III. stupeň', $text);
        self::assertStringContainsString('průkaz ZTP/P', $text);
        self::assertStringContainsString('bylo provedeno s tímto výsledkem', $text);
        self::assertStringContainsString('Přeplatek na dani', $text);
        self::assertStringContainsString('1 200', $text);
        self::assertStringContainsString('1 500', $text);
        self::assertStringContainsString('1 700', $text);

        $qaOutput = getenv('MYINVOICE_ANNUAL_TAX_CERTIFICATE_QA_OUTPUT');
        if (is_string($qaOutput) && $qaOutput !== '') {
            $directory = dirname($qaOutput);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            file_put_contents($qaOutput, $artifact->bytes);
        }
    }

    public function testAdvanceCertificateRendersDocumentedNonresidentInsurance(): void
    {
        $data = self::document(
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            taxResidenceStatus: 'non-resident',
            taxResidenceCountryCode: 'SK',
            identifierLabel: 'Datum narození',
            identifierValue: '1. 1. 1990',
            employeeAddress: 'Modelová 2, 811 01 Bratislava, SK',
            nonresidentInsuranceMinorUnits: 4_130_000,
        );

        $text = preg_replace(
            '/\s+/u',
            ' ',
            (new Parser())->parseContent(
                (new AnnualTaxCertificatePdfRenderer())->render($data)->bytes,
            )->getText(),
        ) ?? '';

        self::assertStringContainsString('Daňový nerezident ČR (SK)', $text);
        self::assertStringContainsString('Povinné pojistné', $text);
        self::assertStringContainsString('41 300', $text);
    }

    /**
     * @return iterable<string,array{PayrollDocumentKind,string,int,int,int}>
     */
    public static function certificateKinds(): iterable
    {
        yield 'zálohová daň' => [
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            '25 5460',
            33,
            52_500,
            2,
        ];
        yield 'srážková daň' => [
            PayrollDocumentKind::TaxableIncomeWithholdingCertificate,
            '25 5460/A',
            12,
            63_000,
            1,
        ];
    }

    private static function document(
        PayrollDocumentKind $kind,
        int $accruedIncomeMinorUnits = 42_000_000,
        ?string $replacesIssuedAt = null,
        string $taxResidenceStatus = 'czech-resident',
        string $taxResidenceCountryCode = 'CZ',
        string $identifierLabel = 'Rodné číslo',
        string $identifierValue = '0001010009',
        string $employeeAddress = 'Modelová 2, 602 00 Brno, CZ',
        ?int $nonresidentInsuranceMinorUnits = null,
    ): AnnualTaxCertificateDocumentData {
        return new AnnualTaxCertificateDocumentData(
            sourceSnapshotSha256: str_repeat('b', 64),
            kind: $kind,
            taxYear: 2026,
            form: AnnualTaxCertificateFormCatalog::resolve(2026, $kind),
            employer: self::employer(),
            employeeName: 'Syntetická osoba',
            employeeFirstName: 'Syntetická',
            employeeLastName: 'Osoba',
            previousNames: ['Dřívější Syntetická'],
            personalIdentifierLabel: $identifierLabel,
            personalIdentifierValue: $identifierValue,
            employeeAddress: $employeeAddress,
            months: [1, 2, 3, 5],
            taxDeclarationStatus: $kind
                === PayrollDocumentKind::TaxableIncomeAdvanceCertificate
                    ? 'signed'
                    : null,
            taxDeclarationSignedMonths: $kind
                === PayrollDocumentKind::TaxableIncomeAdvanceCertificate
                    ? [1, 2, 3, 5]
                    : [],
            taxResidenceStatus: $taxResidenceStatus,
            taxResidenceCountryCode: $taxResidenceCountryCode,
            issuedAt: '2026-08-05 09:15:00',
            replacesIssuedAt: $replacesIssuedAt === null
                ? null
                : $replacesIssuedAt . ' 08:00:00',
            correctionReason: $replacesIssuedAt === null
                ? null
                : 'Oprava doložené částky zálohy na daň.',
            employerProductContributionsMinorUnits: self::products(),
            childTaxBenefits: [],
            disabilityTaxCredits: [],
            annualSettlement: ['performed' => false, 'result' => null],
            nonresidentInsuranceMinorUnits: $nonresidentInsuranceMinorUnits,
            accruedIncomeMinorUnits: $accruedIncomeMinorUnits,
            paidIncomeMinorUnits: 42_000_000,
            advanceTaxMinorUnits: $kind
                === PayrollDocumentKind::TaxableIncomeAdvanceCertificate
                    ? 5_250_000
                    : 0,
            withholdingTaxMinorUnits: $kind
                === PayrollDocumentKind::TaxableIncomeWithholdingCertificate
                    ? 6_300_000
                    : 0,
            taxBonusMinorUnits: 0,
            paymentEvidenceCutoff: '2027-01-31',
            lastProvenPaymentDate: '2026-04-15',
        );
    }

    private static function documentWithCommonVariants(): AnnualTaxCertificateDocumentData
    {
        return new AnnualTaxCertificateDocumentData(
            sourceSnapshotSha256: str_repeat('d', 64),
            kind: PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            taxYear: 2026,
            form: AnnualTaxCertificateFormCatalog::resolve(
                2026,
                PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            ),
            employer: self::employer(),
            employeeName: 'Rezident Syntetický',
            employeeFirstName: 'Rezident',
            employeeLastName: 'Syntetický',
            previousNames: [],
            personalIdentifierLabel: 'Rodné číslo',
            personalIdentifierValue: '0001010009',
            employeeAddress: 'Modelová 2, 602 00 Brno, CZ',
            months: [1, 2, 3],
            taxDeclarationStatus: 'signed',
            taxDeclarationSignedMonths: [1, 2, 3],
            taxResidenceStatus: 'czech-resident',
            taxResidenceCountryCode: 'CZ',
            issuedAt: '2027-01-15 09:15:00',
            replacesIssuedAt: null,
            correctionReason: null,
            employerProductContributionsMinorUnits: self::products(),
            childTaxBenefits: [[
                'name' => 'Dítě Syntetické',
                'identifier' => '1. 2. 2020',
                'ztpp_period' => '2–3',
                'first_child_period' => '1–3',
                'second_child_period' => '',
                'third_child_period' => '',
            ]],
            disabilityTaxCredits: [[
                'period' => '1–2',
                'degree' => 'III. stupeň',
            ], [
                'period' => '3',
                'degree' => 'průkaz ZTP/P',
            ]],
            annualSettlement: [
                'performed' => true,
                'result' => [
                    'tax_overpayment_minor_units' => 120_000,
                    'settlement_supplement_minor_units' => 170_000,
                    'tax_overpayment_after_credit_minor_units' => 150_000,
                    'tax_bonus_difference_minor_units' => 20_000,
                    'taxpayer_product_deductions_minor_units' => [
                        'pension_supplementary' => 0,
                        'supplementary_pension' => 0,
                        'pension_insurance' => 0,
                        'private_life_insurance' => 0,
                        'long_term_investment_product' => 0,
                    ],
                ],
            ],
            nonresidentInsuranceMinorUnits: null,
            accruedIncomeMinorUnits: 42_000_000,
            paidIncomeMinorUnits: 42_000_000,
            advanceTaxMinorUnits: 5_250_000,
            withholdingTaxMinorUnits: 0,
            taxBonusMinorUnits: 0,
            paymentEvidenceCutoff: '2027-01-31',
            lastProvenPaymentDate: '2026-04-15',
        );
    }

    /** @return array<string,int> */
    private static function products(): array
    {
        return [
            'supplementary_pension' => 0,
            'pension_insurance' => 0,
            'private_life_insurance' => 0,
            'long_term_investment_product' => 0,
        ];
    }

    private static function employer(): PayrollDocumentEmployerSnapshot
    {
        return new PayrollDocumentEmployerSnapshot(
            name: 'Syntetická společnost s.r.o.',
            identificationNumber: '00000019',
            taxIdentificationNumber: 'CZ00000019',
            streetLine: 'Testovací 1',
            city: 'Praha',
            postalCode: '100 00',
            countryCode: 'CZ',
            countryName: 'Česká republika',
            issuerName: 'Syntetická mzdová účtárna',
            issuerEmail: 'synthetic@example.invalid',
            issuerPhone: '+420 200 000 000',
        );
    }
}
