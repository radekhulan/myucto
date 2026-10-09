<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Jmhz;

use MyInvoice\Service\Payroll\Import\Jmhz\JmhzAttributeDocument;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportReader;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportFileException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSchemaCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Hlášení JMHZ složené z atributů datového slovníku (tak, jak je ukládá PAMICA): cesty
 * elementů ze slovníku, převod hodnot podle datového typu a opakované skupiny.
 */
final class JmhzAttributeDocumentTest extends TestCase
{
    private const HEADER = ['guid' => '11111111-1111-4111-8111-111111111111', 'type' => 'R', 'year' => 2026, 'month' => 2, 'filled_at' => '2026-03-10T08:00:00'];

    public function testValuesFollowDictionaryDataTypes(): void
    {
        self::assertSame('2026-03-01', JmhzAttributeDocument::value('1.3.2026', 'datum'));
        self::assertSame('2026-03-01', JmhzAttributeDocument::value('01.03.2026', 'datum'));
        self::assertSame('2026-03-01T08:05:00', JmhzAttributeDocument::value('1.3.2026 8:05', 'datumčas'));
        self::assertSame('true', JmhzAttributeDocument::value('A', 'příznak'));
        self::assertSame('false', JmhzAttributeDocument::value('n', 'příznak'));
        self::assertSame('37.5', JmhzAttributeDocument::value('37,5', 'číslo'));
        self::assertSame('12500', JmhzAttributeDocument::value('12 500', 'číslo'));
        self::assertSame('Brno', JmhzAttributeDocument::value(' Brno ', 'text'));
    }

    /**
     * Brána G1: atribut, který slovník nezná, složení formuláře vynechá; převod ho proto
     * musí umět vyjmenovat k varování. Atribut druhu těla (1) mezi neznámé nepatří.
     */
    public function testUnknownAttributeIdsAreListedForTheProtocol(): void
    {
        self::assertSame([99999901, 99999902], JmhzAttributeDocument::unknownAttributeIds([
            ['id' => 1, 'order' => 0, 'order2' => 0, 'value' => 'bezPriznaku'],
            ['id' => 10228, 'order' => 0, 'order2' => 0, 'value' => '1234567890123'],
            ['id' => 99999902, 'order' => 0, 'order2' => 0, 'value' => 'x'],
            ['id' => 99999901, 'order' => 0, 'order2' => 0, 'value' => 'y'],
            ['id' => 99999901, 'order' => 1, 'order2' => 0, 'value' => 'z'],
        ]));
        self::assertSame([], JmhzAttributeDocument::unknownAttributeIds([['id' => 10228, 'order' => 0, 'order2' => 0, 'value' => '1']]));
    }

    public function testDocumentPlacesAttributesByDictionaryPath(): void
    {
        $document = JmhzAttributeDocument::form(self::HEADER, [
            ['id' => 1, 'order' => 0, 'order2' => 0, 'value' => 'cinnostKS'],
            ['id' => 10012, 'order' => 0, 'order2' => 0, 'value' => '22222222-2222-4222-8222-222222222222'],
            ['id' => 10016, 'order' => 0, 'order2' => 0, 'value' => 'R'],
            ['id' => 10228, 'order' => 0, 'order2' => 0, 'value' => '1234567890123'],
            ['id' => 10240, 'order' => 0, 'order2' => 0, 'value' => '1'],
            ['id' => 10356, 'order' => 0, 'order2' => 0, 'value' => '20'],
            ['id' => 10240, 'order' => 1, 'order2' => 0, 'value' => '2'],
            ['id' => 10356, 'order' => 1, 'order2' => 0, 'value' => '8'],
            ['id' => 10357, 'order' => 1, 'order2' => 0, 'value' => '3'],
            // Prázdná hodnota a atribut, který slovník nezná, do hlášení nepatří.
            ['id' => 10053, 'order' => 0, 'order2' => 0, 'value' => ''],
            ['id' => 99999, 'order' => 0, 'order2' => 0, 'value' => 'x'],
        ]);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('p', JmhzSchemaCatalog::NS_PODANI);
        $xpath->registerNamespace('f', JmhzSchemaCatalog::NS_FORM);

        self::assertSame('R', $xpath->evaluate('string(/p:jmhz/p:hlavicka/p:typPodani)'));
        self::assertSame('2', $xpath->evaluate('string(/p:jmhz/p:hlavicka/p:mesic)'));
        self::assertSame('R', $xpath->evaluate('string(//p:formularOsoby/p:hlavicka/p:typFormulare)'));
        self::assertSame('1234567890123', $xpath->evaluate('string(//p:formularOsoby/f:cinnostKS/f:identifikace/f:idPpv)'));
        self::assertSame(2.0, $xpath->evaluate('count(//f:cinnostKS/f:pojisteni/f:eldpSeznam/f:eldp)'));
        self::assertSame(0.0, $xpath->evaluate('count(//f:identifikace/f:prijmeni)'));

        $form = (new JmhzReportReader())->formFromDocument($document, 7);
        self::assertSame(7, $form->position);
        self::assertSame('cinnostKS', $form->variant);
        self::assertSame(['code' => '1', 'insurance_days' => 28, 'excluded_days' => 3, 'sickness_excluded_days' => null, 'absence_days' => ['docasNeschopnost' => 0, 'penezitaPomocMaterstvi' => 0, 'osetrovaniClenaRodiny' => 0, 'pracovniNeschopnost' => 0, 'vyplaceniDavek' => 0]], $form->eldp);
    }

    public function testSecondRepeatedElementTakesSecondOrder(): void
    {
        // Partner a jeho děti: partner podle prvního pořadí, dítě podle druhého.
        $document = JmhzAttributeDocument::form(self::HEADER, [
            ['id' => 10421, 'order' => 0, 'order2' => 0, 'value' => 'Eva'],
            ['id' => 10539, 'order' => 0, 'order2' => 0, 'value' => 'Adam'],
            ['id' => 10539, 'order' => 0, 'order2' => 1, 'value' => 'Bára'],
            ['id' => 10421, 'order' => 1, 'order2' => 0, 'value' => 'Iva'],
        ]);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('f', JmhzSchemaCatalog::NS_FORM);

        self::assertSame(2.0, $xpath->evaluate('count(//f:slevaNaPartnera/f:partner)'));
        self::assertSame(2.0, $xpath->evaluate('count(//f:slevaNaPartnera/f:partner[1]/f:deti/f:dite)'));
        self::assertSame('Iva', $xpath->evaluate('string(//f:slevaNaPartnera/f:partner[2]/f:partnerUdaje/f:jmeno)'));
    }

    public function testInvalidFormIsRejectedLikeUploadedFile(): void
    {
        $this->expectException(RegistrationImportFileException::class);
        (new JmhzReportReader())->formFromDocument(JmhzAttributeDocument::form(self::HEADER, [
            ['id' => 10012, 'order' => 0, 'order2' => 0, 'value' => 'není-guid'],
            ['id' => 10016, 'order' => 0, 'order2' => 0, 'value' => 'R'],
        ]), 1);
    }
}
