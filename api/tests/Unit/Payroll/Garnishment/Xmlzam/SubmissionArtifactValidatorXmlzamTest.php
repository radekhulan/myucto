<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Garnishment\Xmlzam;

use MyInvoice\Service\Payroll\Garnishment\Xmlzam\XmlzamSchemaCatalog;
use MyInvoice\Service\Submission\SubmissionArtifactValidator;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\TestCase;

final class SubmissionArtifactValidatorXmlzamTest extends TestCase
{
    public function testOutboxValidatesXmlzamInsteadOfReportingSkipped(): void
    {
        $validator = new SubmissionArtifactValidator(
            new XmlSchemaValidator(),
            new XmlzamSchemaCatalog(),
        );
        $result = $validator->validateArtifact('XMLZAM', [
            'filename' => 'soucinnost-odpoved.xml',
            'mime' => 'application/xml',
            'bytes' => self::validResponse(),
        ]);

        self::assertSame('passed', $result['status']);
        self::assertSame([], $result['errors']);
    }

    public function testOutboxRejectsXmlzamWithDifferentDeclaredType(): void
    {
        $validator = new SubmissionArtifactValidator(
            new XmlSchemaValidator(),
            new XmlzamSchemaCatalog(),
        );
        $result = $validator->validateArtifact('XMLZAM', [
            'filename' => 'soucinnost-odpoved.xml',
            'mime' => 'application/xml',
            'bytes' => str_replace('soucinnost_odpoved', 'zustatek_dotaz', self::validResponse()),
        ]);

        self::assertSame('failed', $result['status']);
        self::assertNotSame([], $result['errors']);
    }

    public function testOutboxRejectsCaseVariantDtd(): void
    {
        $validator = new SubmissionArtifactValidator(
            new XmlSchemaValidator(),
            new XmlzamSchemaCatalog(),
        );
        $result = $validator->validateArtifact('XMLZAM', [
            'filename' => 'soucinnost-odpoved.xml',
            'mime' => 'application/xml',
            'bytes' => str_replace(
                '<dokument ',
                '<!doctype dokument><dokument ',
                self::validResponse(),
            ),
        ]);

        self::assertSame('failed', $result['status']);
        self::assertStringContainsString('DTD', implode(' ', $result['errors']));
    }

    private static function validResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<dokument xmlns:xs="http://www.w3.org/2001/XMLSchema-instance" xs:type="soucinnost_odpoved">
  <identifikator>123-12345678-R1</identifikator>
  <reakce_na>123-12345678-A1</reakce_na>
  <datum>2026-08-27</datum>
  <druh_dokumentu>soucinnost_odpoved</druh_dokumentu>
  <poradi_exekucniho_prikazu sdilene_poradi="false">1</poradi_exekucniho_prikazu>
  <pracovni_pomer>
    <aktivni>true</aktivni>
    <zamestnan_od>2025-01-01</zamestnan_od>
    <zamestnan_do></zamestnan_do>
    <mzda_prehled></mzda_prehled>
    <exekuce_prehled></exekuce_prehled>
  </pracovni_pomer>
</dokument>
XML;
    }
}
