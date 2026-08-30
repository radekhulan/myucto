<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\EpoSupplierBlockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * `representationFlag()`/`fillRepresentationAttributes()` — sdílený SSOT pro `dan_por`
 * (DPPO) i `pln_moc` (DPFO) + `zast_*` (viz DppoXmlBuilder/DpfoXmlBuilder). Hodnoty
 * v testu jsou VŽDY vymyšlené (žádné skutečné jméno/IČO/evidenční číslo poradce).
 */
final class EpoSupplierBlockBuilderRepresentationTest extends TestCase
{
    private function vetaP(): \DOMElement
    {
        $dom = new \DOMDocument('1.0', 'utf-8');
        $el = $dom->createElement('VetaP');
        $dom->appendChild($el);

        return $el;
    }

    public function testFlagIsNWhenNotRepresented(): void
    {
        self::assertSame('N', EpoSupplierBlockBuilder::representationFlag(['represented' => false]));
        self::assertSame('N', EpoSupplierBlockBuilder::representationFlag([]));
    }

    public function testFlagIsAWhenRepresented(): void
    {
        self::assertSame('A', EpoSupplierBlockBuilder::representationFlag(['represented' => true]));
    }

    public function testFillRepresentationAttributesSkipsWhenNotRepresented(): void
    {
        $vetaP = $this->vetaP();
        EpoSupplierBlockBuilder::fillRepresentationAttributes($vetaP, ['represented' => false]);
        self::assertFalse($vetaP->hasAttribute('zast_typ'));
        self::assertFalse($vetaP->hasAttribute('zast_kod'));
        self::assertFalse($vetaP->hasAttribute('zast_jmeno'));
        self::assertFalse($vetaP->hasAttribute('zast_ev_cislo'));
    }

    /** Fyzická osoba poradce → zast_kod 4b, jméno/příjmení, evidenční číslo. */
    public function testFillRepresentationAttributesNaturalPerson(): void
    {
        $vetaP = $this->vetaP();
        EpoSupplierBlockBuilder::fillRepresentationAttributes($vetaP, [
            'represented' => true,
            'type' => 'F',
            'first_name' => 'Vzorový',
            'last_name' => 'Poradce',
            'company_name' => null,
            'ico' => null,
            'ev_number' => 'EV-0001',
        ]);
        self::assertSame('F', $vetaP->getAttribute('zast_typ'));
        self::assertSame('4b', $vetaP->getAttribute('zast_kod'));
        self::assertSame('Vzorový', $vetaP->getAttribute('zast_jmeno'));
        self::assertSame('Poradce', $vetaP->getAttribute('zast_prijmeni'));
        self::assertSame('EV-0001', $vetaP->getAttribute('zast_ev_cislo'));
        self::assertFalse($vetaP->hasAttribute('zast_nazev'));
        self::assertFalse($vetaP->hasAttribute('zast_ic'));
    }

    /** Právnická osoba (daňově poradenská společnost) → zast_kod 4c, název + IČO. */
    public function testFillRepresentationAttributesLegalEntity(): void
    {
        $vetaP = $this->vetaP();
        EpoSupplierBlockBuilder::fillRepresentationAttributes($vetaP, [
            'represented' => true,
            'type' => 'P',
            'first_name' => null,
            'last_name' => null,
            'company_name' => 'Vzorová daňová poradna s.r.o.',
            'ico' => '01234567',
            'ev_number' => 'EV-0002',
        ]);
        self::assertSame('P', $vetaP->getAttribute('zast_typ'));
        self::assertSame('4c', $vetaP->getAttribute('zast_kod'));
        self::assertSame('Vzorová daňová poradna s.r.o.', $vetaP->getAttribute('zast_nazev'));
        self::assertSame('01234567', $vetaP->getAttribute('zast_ic'));
        self::assertSame('EV-0002', $vetaP->getAttribute('zast_ev_cislo'));
        self::assertFalse($vetaP->hasAttribute('zast_jmeno'));
        self::assertFalse($vetaP->hasAttribute('zast_prijmeni'));
    }

    /** IČO se čistí na pouhé číslice (XSD pattern [0-9]{1,10}) — stejná konvence jako normalizeDic(). */
    public function testIcoIsDigitsOnly(): void
    {
        $vetaP = $this->vetaP();
        EpoSupplierBlockBuilder::fillRepresentationAttributes($vetaP, [
            'represented' => true,
            'type' => 'P',
            'company_name' => 'Vzorová poradna',
            'ico' => '012 345 67',
            'ev_number' => 'EV-0003',
        ]);
        self::assertSame('01234567', $vetaP->getAttribute('zast_ic'));
    }
}
