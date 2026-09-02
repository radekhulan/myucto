<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzGovTalkEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzGovTalkRequestShape;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use PHPUnit\Framework\TestCase;

/**
 * Tvar odesílané obálky je doložený podacím protokolem ČSSZ v1.7. Tenhle test
 * ho drží proti doslovným hodnotám z protokolu — kdyby se kterákoli změnila,
 * podání by VREP odmítl a poznalo by se to až z protokolu, tedy nejdřív den
 * po odeslání.
 */
final class JmhzGovTalkDocumentedShapeTest extends TestCase
{
    public function testDocumentedShapeMatchesTheCsszProtocol(): void
    {
        $shape = JmhzGovTalkRequestShape::documented();

        self::assertSame('request', $shape->submitQualifier);
        self::assertSame('poll', $shape->pollQualifier);
        self::assertSame('submit', $shape->function);
        self::assertSame('vars', $shape->variableSymbolKeyType);
        self::assertSame('1.2', $shape->bodyEnvelopeVersion);
        self::assertSame('JMHZ25', $shape->bodyEnvelopeType);
        self::assertStringContainsString('protokol', $shape->sourceReference);
    }

    public function testEnvelopeCarriesEveryDocumentedElement(): void
    {
        $xml = $this->build();

        self::assertStringContainsString('<EnvelopeVersion>2.0</EnvelopeVersion>', $xml);
        self::assertStringContainsString('<Class>CSSZ_JMHZ</Class>', $xml);
        self::assertStringContainsString('<Qualifier>request</Qualifier>', $xml);
        self::assertStringContainsString('<Function>submit</Function>', $xml);
        self::assertStringContainsString('Type="vars"', $xml);
        self::assertStringContainsString(
            '<TimestampVersion>xmldsig</TimestampVersion>',
            $xml,
        );
        self::assertStringContainsString('eType="JMHZ25"', $xml);
        self::assertStringContainsString('version="1.2"', $xml);
    }

    /**
     * Protokol: „CorrelationID … při odeslání musí být prázdné." Přiděluje ho
     * až VREP a je to identifikátor, pod kterým se pak podání dotazuje.
     */
    public function testCorrelationIdIsPresentButEmptyOnSubmit(): void
    {
        $xml = $this->build();

        self::assertMatchesRegularExpression(
            '~<CorrelationID\s*/>|<CorrelationID></CorrelationID>~',
            $xml,
        );
    }

    /**
     * VREP se autentizuje podpisovým certifikátem, ne jménem a heslem —
     * `SenderDetails` z ukázkové obálky patří jiné variantě protokolu a poslat
     * ho znamená posílat autentizační údaje, které nikdo nechce.
     */
    public function testEnvelopeCarriesNoSenderCredentials(): void
    {
        $xml = $this->build();

        self::assertStringNotContainsString('SenderDetails', $xml);
        self::assertStringNotContainsString('IDAuthentication', $xml);
    }

    /**
     * MPSV provozuje ještě B2B bránu s vlastní GovTalk obálkou (`Class=MPSV`,
     * klíč `Type="ico"`). Je to jiné API a záměna by znamenala podání, které
     * ČSSZ nikdy neuvidí.
     */
    public function testEnvelopeIsNotTheMpsvB2bVariant(): void
    {
        $xml = $this->build();

        self::assertStringNotContainsString('<Class>MPSV</Class>', $xml);
        self::assertStringNotContainsString('Type="ico"', $xml);
    }

    /**
     * `eType` NENÍ pro všechny agendy `JMHZ25`.
     *
     * `CSSZSubmClasses.pdf` má pro každý tiskopis vlastní řádek a sloupec
     * „Envelope 1.2 eType" v něm nese název formuláře. Registrační podání se
     * přesto stavěla přes `documented()`, takže na VREP odcházelo
     * `Class="CSSZ_REGZEC"` s tělem označeným `eType="JMHZ25"`. Ani XSD, ani
     * katalog kontrol na obálku nedosáhnou — projevilo by se to až odmítnutím.
     */
    public function testEveryDocumentedAgendaCarriesItsOwnEnvelopeType(): void
    {
        foreach ([
            'CSSZ_JMHZ' => 'JMHZ25',
            'CSSZ_REGZEC' => 'REGZEC25',
            'CSSZ_PREZEC' => 'PREZEC26',
        ] as $submissionClass => $envelopeType) {
            self::assertSame(
                $envelopeType,
                JmhzGovTalkRequestShape::forSubmissionClass($submissionClass)
                    ->bodyEnvelopeType,
            );
            self::assertSame(
                $envelopeType,
                JmhzGovTalkRequestShape::envelopeTypeFor($submissionClass),
            );
        }

        $this->expectException(JmhzTransportException::class);
        $this->expectExceptionMessage('není doložený `eType`');
        JmhzGovTalkRequestShape::forSubmissionClass('CSSZ_NEEXISTUJE');
    }

    /**
     * Hlavička a tělo musí mluvit o témže tiskopisu. Prohlášený tvar si volí
     * volající, takže samotná mapa v továrně nestačí — obálka musí záměnu
     * doložených agend odmítnout, ať ji sestaví kdokoli.
     */
    public function testEnvelopeRefusesAnEnvelopeTypeFromAnotherAgenda(): void
    {
        $this->expectException(JmhzTransportException::class);
        $this->expectExceptionMessage('patří jiné agendě');

        (new JmhzGovTalkEnvelope(JmhzGovTalkRequestShape::documented()))->build(
            JmhzXmlSample::minimal(),
            '1234567890',
            'CSSZ_REGZEC',
            'test',
            new JmhzSoftwareIdentification('MyÚčto.cz', '5.6.0'),
        );
    }

    private function build(): string
    {
        return (new JmhzGovTalkEnvelope(JmhzGovTalkRequestShape::documented()))->build(
            JmhzXmlSample::minimal(),
            '1234567890',
            'CSSZ_JMHZ',
            'test',
            new JmhzSoftwareIdentification('MyÚčto.cz', '5.6.0'),
        )->unsignedXml;
    }
}
