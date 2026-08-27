<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Garnishment\Xmlzam;

use MyInvoice\Service\Payroll\Garnishment\Xmlzam\XmlzamCooperationRequestParser;
use MyInvoice\Service\Payroll\Garnishment\Xmlzam\XmlzamCooperationResponse;
use MyInvoice\Service\Payroll\Garnishment\Xmlzam\XmlzamCooperationResponseSerializer;
use MyInvoice\Service\Payroll\Garnishment\Xmlzam\XmlzamSchemaCatalog;
use MyInvoice\Service\Payroll\Garnishment\Xmlzam\XmlzamValidator;
use PHPUnit\Framework\TestCase;

final class XmlzamCooperationCoreTest extends TestCase
{
    public function testParsesPinnedOfficialCooperationRequestWithoutGuessing(): void
    {
        $request = (new XmlzamCooperationRequestParser(
            new XmlzamSchemaCatalog(),
        ))->parse(self::requestXml());

        self::assertSame('123-12345678-A1', $request->identifier);
        self::assertSame('EX 123456/26', $request->caseReference);
        self::assertSame('2026-08-26', $request->issuedOn);
        self::assertSame(
            ['vyse_srazek', 'trvani_praconiho_pomeru', 'poradi_exekuce'],
            $request->requestedScopes,
        );
        self::assertSame('Synthetic', $request->debtorGivenName);
        self::assertSame('Employee', $request->debtorFamilyName);
        self::assertSame('1990-01-01', $request->debtorBirthDate);
        self::assertSame('900101/0007', $request->debtorBirthNumber);
        self::assertSame('abc1234', $request->executorDataBoxId);
    }

    public function testSerializesExactMoneyAndPassesPinnedOfficialXsd(): void
    {
        $payload = new XmlzamCooperationResponse(
            identifier: '123-12345678-R1',
            reactionTo: '123-12345678-A1',
            issuedOn: '2026-08-27',
            note: 'Syntetická odpověď.',
            debtorContact: null,
            employerContact: [
                'phone' => ['420111222333'],
                'email' => ['payroll@example.invalid'],
            ],
            priority: 2,
            sharedPriority: true,
            employmentActive: true,
            employedFrom: '2025-01-01',
            employedTo: null,
            wages: [[
                'period' => '2026-07',
                'gross_minor' => 3500050,
                'withheld_minor' => 612325,
                'dependants' => 1,
            ]],
            enforcements: [[
                'priority' => 2,
                'subject' => 'Syntetický exekutorský úřad',
                'chamber' => '123',
                'case_reference' => 'EX 123456/26',
                'claim_kind' => 'neprednostni',
                'delivered_on' => '2026-04-03',
                'priority_on' => '2026-04-03',
                'outstanding_minor' => 11867869,
            ]],
            attachments: [],
        );
        $xml = (new XmlzamCooperationResponseSerializer())->serialize($payload);

        self::assertStringContainsString('>35000.50</mzda>', $xml);
        self::assertStringContainsString('srazeno_celkem="6123.25"', $xml);
        self::assertStringContainsString('>118678.69</exekuce>', $xml);
        self::assertStringNotContainsString(',', $xml);
        (new XmlzamValidator(new XmlzamSchemaCatalog()))
            ->validateResponse($payload, $xml);
    }

    public function testRejectsRequestWhoseDeclaredTypeIsNotCooperation(): void
    {
        $xml = str_replace('xs:type="soucinnost"', 'xs:type="zustatek"', self::requestXml());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('součinnost');
        (new XmlzamCooperationRequestParser(new XmlzamSchemaCatalog()))
            ->parse($xml);
    }

    public function testRejectsCaseVariantDtdBeforeXmlParsing(): void
    {
        $xml = str_replace(
            '<dokument ',
            '<!doctype dokument [<!entity leaked "synthetic">]><dokument ',
            self::requestXml(),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('DTD');
        (new XmlzamCooperationRequestParser(new XmlzamSchemaCatalog()))->parse($xml);
    }

    private static function requestXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<dokument xs:type="soucinnost" xmlns:xs="http://www.w3.org/2001/XMLSchema-instance">
  <identifikator>123-12345678-A1</identifikator>
  <znacka_rizeni>EX 123456/26</znacka_rizeni>
  <datum>2026-08-26</datum>
  <druh_dokumentu>soucinnost</druh_dokumentu>
  <druh_soucinnosti>vyse_srazek trvani_praconiho_pomeru poradi_exekuce</druh_soucinnosti>
  <exekutor>
    <nazev>Syntetický exekutorský úřad</nazev>
    <adresa>Testovací 1, 11000 Praha</adresa>
    <senat>123</senat>
    <ic>12345679</ic>
    <idds>abc1234</idds>
    <email>office@example.invalid</email>
    <telefon>420111222333</telefon>
    <platebni_udaje vs="1234567890" ss="55">1000000005/0100</platebni_udaje>
  </exekutor>
  <opravneny>
    <nazev>Syntetický oprávněný s.r.o.</nazev>
    <adresa>Vzorová 2, 11000 Praha</adresa>
    <ic>87654321</ic>
    <narozen></narozen>
  </opravneny>
  <povinny>
    <jmeno>Synthetic</jmeno>
    <prijmeni>Employee</prijmeni>
    <narozen>1990-01-01</narozen>
    <rc>900101/0007</rc>
  </povinny>
  <povereni_prehled>
    <povereni vydal="Syntetický soud" cislo="1 EXE 1/2026" vydano="2026-04-01" pravni_moc="2026-04-02"></povereni>
  </povereni_prehled>
  <prilohy></prilohy>
</dokument>
XML;
    }
}
