<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateFormCatalog;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use PHPUnit\Framework\TestCase;

final class AnnualTaxCertificateFormCatalogTest extends TestCase
{
    public function testMaps2026AdvanceTaxCertificateToOfficialForm(): void
    {
        self::assertSame(
            [
                'tax_year' => 2026,
                'document_kind' => PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
                'form_number' => '25 5460',
                'ministry_form' => 'MFin 5460',
                'pattern_number' => 33,
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-12-31',
                'official_url' =>
                    'https://financnisprava.gov.cz/assets/tiskopisy/5460_33.pdf',
            ],
            AnnualTaxCertificateFormCatalog::resolve(
                2026,
                PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            ),
        );
    }

    public function testMaps2026WithholdingTaxCertificateToOfficialForm(): void
    {
        self::assertSame(
            [
                'tax_year' => 2026,
                'document_kind' => PayrollDocumentKind::TaxableIncomeWithholdingCertificate,
                'form_number' => '25 5460/A',
                'ministry_form' => 'MFin 5460/A',
                'pattern_number' => 12,
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-12-31',
                'official_url' =>
                    'https://financnisprava.gov.cz/assets/tiskopisy/5460-A_12.pdf',
            ],
            AnnualTaxCertificateFormCatalog::resolve(
                2026,
                PayrollDocumentKind::TaxableIncomeWithholdingCertificate,
            ),
        );
    }

    public function testRejectsTaxYearWithoutKnownMinistryForm(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Pro rok 2027 není znám vzor tiskopisu ročního daňového potvrzení;',
        );

        AnnualTaxCertificateFormCatalog::resolve(
            2027,
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
        );
    }

    /**
     * Za rok 2027 potvrzení o srážkové dani neexistuje — ani kdyby MF vzor
     * vydalo: z příjmů podle § 6 odst. 4 ZDP se od 1. 1. 2027 sráží záloha.
     * Hláška to musí říct, ne tvrdit, že jen chybí vzor.
     */
    public function testWithholdingCertificateDoesNotExistFrom2027(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Za rok 2027 se potvrzení o srážkové dani ze závislé činnosti nevystavuje',
        );

        AnnualTaxCertificateFormCatalog::resolve(
            2027,
            PayrollDocumentKind::TaxableIncomeWithholdingCertificate,
        );
    }

    public function testKnownTaxYearsAreEnumeratedInsteadOfCompared(): void
    {
        self::assertSame([2026], AnnualTaxCertificateFormCatalog::knownTaxYears());
    }

    public function testRejectsNonCertificateDocumentKind(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Nepodporovaný druh ročního daňového potvrzení: payroll_sheet.',
        );

        AnnualTaxCertificateFormCatalog::resolve(
            2026,
            PayrollDocumentKind::PayrollSheet,
        );
    }
}
