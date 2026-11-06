<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzAttributeProjection;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use PHPUnit\Framework\TestCase;

final class JmhzAttributeProjectionTest extends TestCase
{
    public function testEachPartKeepsItsOwnAttributes(): void
    {
        $projection = JmhzAttributeProjection::fromXml(JmhzXmlSample::minimal());

        self::assertSame(7, $projection->submission()->integer('10010'));
        self::assertSame(2026, $projection->submission()->integer('10011'));
        self::assertSame('1234567890', $projection->submission()->value('10221'));
        self::assertSame(150, $projection->summary()->integer('10034'));
        self::assertSame(1000, $projection->pvpoj()->integer('10023'));
        self::assertCount(1, $projection->forms());
        self::assertSame(1000, $projection->forms()[0]->integer('10286'));
    }

    /**
     * `hlavicka` je pod kořenem dokumentu i uvnitř součásti. Kdyby se části
     * rozlišovaly jen podle názvu elementu, spadl by příznak primárního PPV
     * do metadatové hlavičky a kontroly nad součástí by ho neviděly.
     */
    public function testFormHeaderDoesNotLeakIntoSubmissionHeader(): void
    {
        $projection = JmhzAttributeProjection::fromXml(JmhzXmlSample::minimal());

        self::assertFalse($projection->submission()->has('10495'));
        self::assertTrue($projection->forms()[0]->boolean('10495'));
    }

    /**
     * Slovník uvádí cestu k počtu formulářů bez prefixu hlavičky, přestože
     * element uvnitř hlavičky leží. Promítnutí proto bere nejdelší příponu
     * cesty, která je ve slovníku celou cestou.
     */
    public function testDictionaryPathWithoutHeaderPrefixStillResolves(): void
    {
        $projection = JmhzAttributeProjection::fromXml(JmhzXmlSample::minimal());

        self::assertSame(3, $projection->submission()->integer('10015'));
        self::assertSame(3, $projection->submission()->integer('10488'));
    }

    /**
     * Dva stejnojmenné listy s různým rodičem jsou různé atributy. Zdravotní
     * pojištění za zaměstnavatele a za zaměstnance se nesmí slít.
     */
    public function testSameLeafNameUnderDifferentParentsStaysDistinct(): void
    {
        $form = JmhzAttributeProjection::fromXml(JmhzXmlSample::minimal())->forms()[0];

        self::assertSame(90, $form->integer('10482'));
        self::assertSame(45, $form->integer('10371'));
    }

    public function testRepeatedEldpSectionsAreGroupedByTheirOwnBlock(): void
    {
        $form = JmhzAttributeProjection::fromXml(JmhzXmlSample::twoEldpSections())->forms()[0];

        self::assertSame(
            [
                ['10241' => '2026-07-01', '10242' => '2026-07-15', '10356' => '15'],
                ['10241' => '2026-07-16', '10242' => '2026-07-31', '10356' => '16'],
            ],
            $form->groupedBy(['10241', '10242', '10356']),
        );
    }

    public function testUnknownElementFailsClosed(): void
    {
        $xml = str_replace(
            '<mesic>7</mesic>',
            '<mesicek>7</mesicek>',
            JmhzXmlSample::minimal(),
        );

        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/nemá ve slovníku JMHZ/');
        JmhzAttributeProjection::fromXml($xml);
    }

    public function testTwoFormsProduceTwoScopes(): void
    {
        $projection = JmhzAttributeProjection::fromXml(JmhzXmlSample::twoForms());

        self::assertCount(2, $projection->forms());
        self::assertSame(0, $projection->forms()[0]->ordinal);
        self::assertSame(1, $projection->forms()[1]->ordinal);
        self::assertSame('1000000001', $projection->forms()[0]->value('10051'));
        self::assertSame('1000000012', $projection->forms()[1]->value('10051'));
    }

    public function testManyFormsKeepOrdinalsIncludingEmptySiblings(): void
    {
        $forms = '';
        for ($ordinal = 0; $ordinal <= 1_500; ++$ordinal) {
            $forms .= $ordinal === 750
                ? '<formularOsoby/>'
                : self::compactForm($ordinal);
        }

        $projection = JmhzAttributeProjection::fromXml(
            JmhzXmlSample::document($forms, formCount: 1_501),
        );

        self::assertCount(1_500, $projection->forms());
        self::assertSame(749, $projection->forms()[749]->ordinal);
        self::assertSame(751, $projection->forms()[750]->ordinal);
        self::assertSame('1000000751', $projection->forms()[750]->value('10051'));
        self::assertSame(1_500, $projection->forms()[1_499]->ordinal);
        self::assertContains(10051, $projection->presentAttributeIds());
        self::assertContains(10495, $projection->presentAttributeIds());
    }

    /**
     * XML ve jmenném prostoru 2027 se promítá přes slovník 1.5.0.3: součástí
     * je `formularZamestnani` a jeho hlavička leží ve jmenném prostoru
     * formuláře (form.xsd 1.5.0.3). Nové atributy (10575, 10587, 10580,
     * 10555) se najdou, primární PPV zůstává v hlavičce součásti.
     */
    public function testVersion2027DocumentProjectsThroughDictionary2027(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <jmhzPodani xmlns="http://schemas.cssz.cz/JMHZ/podani/2027"
                        xmlns:form="http://schemas.cssz.cz/JMHZ/form/2027">
              <hlavicka>
                <mesic>1</mesic>
                <rok>2027</rok>
              </hlavicka>
              <formulareZamestnani>
                <form:formularZamestnani>
                  <form:hlavicka>
                    <form:primarniPpv>true</form:primarniPpv>
                  </form:hlavicka>
                  <form:bezPriznaku>
                    <form:identifikace>
                      <form:ikMpsv>1000000001</form:ikMpsv>
                    </form:identifikace>
                    <form:souhrnDataZec>
                      <form:zalohaNaDan>
                        <form:prohlaseniPoplatnikaDane>
                          <form:datumPodpisu>2026-12-15</form:datumPodpisu>
                        </form:prohlaseniPoplatnikaDane>
                      </form:zalohaNaDan>
                    </form:souhrnDataZec>
                    <form:prubehZamestnani>
                      <form:statniZastupce>false</form:statniZastupce>
                      <form:odpracovaneHodiny>
                        <form:rozpad>
                          <form:riziko>
                            <form:hodinyOdpracovanePocet>143</form:hodinyOdpracovanePocet>
                            <form:smenyOdpracovanePocet>18</form:smenyOdpracovanePocet>
                            <form:kategorizaceRizika>1</form:kategorizaceRizika>
                          </form:riziko>
                        </form:rozpad>
                      </form:odpracovaneHodiny>
                    </form:prubehZamestnani>
                    <form:prijem>
                      <form:dan>
                        <form:prijemPar6>0</form:prijemPar6>
                      </form:dan>
                    </form:prijem>
                  </form:bezPriznaku>
                </form:formularZamestnani>
              </formulareZamestnani>
            </jmhzPodani>
            XML;

        $projection = JmhzAttributeProjection::fromXml($xml);

        self::assertSame(1, $projection->submission()->integer('10010'));
        self::assertSame(2027, $projection->submission()->integer('10011'));
        self::assertFalse($projection->submission()->has('10495'));
        self::assertCount(1, $projection->forms());
        $form = $projection->forms()[0];
        self::assertTrue($form->boolean('10495'));
        self::assertSame('1000000001', $form->value('10051'));
        self::assertSame('2026-12-15', $form->value('10575'));
        self::assertFalse($form->boolean('10587'));
        self::assertSame(143, $form->integer('10273'));
        self::assertSame(18, $form->integer('10580'));
        self::assertSame(0, $form->integer('10555'));
    }

    /** Element 2027 v dokumentu 2026 nemá ve slovníku 1.4.1.6 protějšek. */
    public function testVersion2027ElementInVersion2026DocumentFailsClosed(): void
    {
        $xml = str_replace(
            '<mesic>7</mesic>',
            '<mesic>7</mesic><statniZastupce>false</statniZastupce>',
            JmhzXmlSample::minimal(),
        );

        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/nemá ve slovníku JMHZ 1\.4\.1\.6/');
        JmhzAttributeProjection::fromXml($xml);
    }

    private static function compactForm(int $ordinal): string
    {
        $personId = sprintf('%010d', 1_000_000_000 + $ordinal);

        return <<<XML
                <formularOsoby>
                  <hlavicka>
                    <primarniPpv>true</primarniPpv>
                  </hlavicka>
                  <form:bezPriznaku>
                    <form:identifikace>
                      <form:ikMpsv>{$personId}</form:ikMpsv>
                    </form:identifikace>
                  </form:bezPriznaku>
                </formularOsoby>
            XML;
    }
}
