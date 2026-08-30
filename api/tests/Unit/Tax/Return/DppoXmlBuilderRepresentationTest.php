<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * `dan_por` (VetaD) + `zast_*` (VetaP) — zastoupení daňovým poradcem (§29/2 DŘ), viz
 * migrace 1662 (`supplier_tax_representation_history`) a `TaxRepresentationService`.
 * Předtím builder posílal natvrdo `dan_por="N"` bez ohledu na cokoli — appka zastoupení
 * vůbec neevidovala. Hodnoty v testu jsou VŽDY vymyšlené.
 */
final class DppoXmlBuilderRepresentationTest extends TestCase
{
    private function sampleSupplier(): array
    {
        return [
            'company_name' => 'Ukázková firma s.r.o.', 'street' => 'Zkušební 123/4',
            'city' => 'Vzorov', 'zip' => '100 00', 'country_iso2' => 'CZ',
            'ic' => '12345678', 'dic' => 'CZ12345678', 'taxpayer_type' => 'po',
            'financial_office_code' => '451', 'cz_nace_code' => '62020',
            'opr_jmeno' => 'Jan', 'opr_prijmeni' => 'Jednatel', 'opr_postaveni' => 'jednatel',
        ];
    }

    private function sampleCalc(): array
    {
        return (new DppoReturnCalculator())->compute(
            ['vh' => 500000, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            ['tax_paid_advances' => 0],
            TaxConstants::forYear(2025)
        );
    }

    private function build(array $meta = []): array
    {
        return (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $this->sampleCalc(), $meta);
    }

    /** Bez evidence zastoupení (žádné $meta['representation']) — dnešní chování: 'N', žádné zast_*, opr_* jednatele beze změny. */
    public function testDefaultsToNWhenRepresentationMetaMissing(): void
    {
        $xml = $this->build()['xml'];
        self::assertStringContainsString('dan_por="N"', $xml);
        self::assertStringNotContainsString('zast_typ', $xml);
        self::assertStringContainsString('opr_jmeno="Jan"', $xml);
    }

    /** Evidence říká "nezastoupena" — stejně jako chybějící meta, žádná chyba. */
    public function testExplicitlyNotRepresented(): void
    {
        $xml = $this->build(['representation' => ['represented' => false]])['xml'];
        self::assertStringContainsString('dan_por="N"', $xml);
        self::assertStringNotContainsString('zast_typ', $xml);
    }

    /** Fyzická osoba (daňový poradce) — dan_por='A', zast_kod 4b, jméno/příjmení + evidenční číslo. */
    public function testRepresentedByNaturalPersonAdvisor(): void
    {
        $xml = $this->build(['representation' => [
            'represented' => true,
            'type' => 'F',
            'first_name' => 'Vzorový',
            'last_name' => 'Poradce',
            'company_name' => null,
            'ico' => null,
            'ev_number' => 'EV-0001',
        ]])['xml'];
        self::assertStringContainsString('dan_por="A"', $xml);
        self::assertStringContainsString('zast_typ="F"', $xml);
        self::assertStringContainsString('zast_kod="4b"', $xml);
        self::assertStringContainsString('zast_jmeno="Vzorový"', $xml);
        self::assertStringContainsString('zast_prijmeni="Poradce"', $xml);
        self::assertStringContainsString('zast_ev_cislo="EV-0001"', $xml);
        // Zkušební EPO 31. 8. 2026 vytklo „Je-li podepisující osobou fyzická osoba,
        // pak se jméno oprávněné osoby nevyplňuje" — opr_* jednatele musí zmizet,
        // podepisující osobou je teď poradce (zast_*), reálné referenční podání se
        // zast_typ='F' opr_* taky nemá.
        self::assertStringNotContainsString('opr_jmeno', $xml);
        self::assertStringNotContainsString('opr_prijmeni', $xml);
        self::assertStringNotContainsString('opr_postaveni', $xml);
    }

    /** Právnická osoba (daňová poradenská společnost) — dan_por='A', zast_kod 4c, název + IČO. */
    public function testRepresentedByLegalEntityAdvisor(): void
    {
        $xml = $this->build(['representation' => [
            'represented' => true,
            'type' => 'P',
            'first_name' => null,
            'last_name' => null,
            'company_name' => 'Vzorová daňová poradna s.r.o.',
            'ico' => '01234567',
            'ev_number' => 'EV-0002',
        ]])['xml'];
        self::assertStringContainsString('dan_por="A"', $xml);
        self::assertStringContainsString('zast_typ="P"', $xml);
        self::assertStringContainsString('zast_kod="4c"', $xml);
        self::assertStringContainsString('zast_nazev="Vzorová daňová poradna s.r.o."', $xml);
        self::assertStringContainsString('zast_ic="01234567"', $xml);
        self::assertStringContainsString('zast_ev_cislo="EV-0002"', $xml);
        // Právnická osoba jako zástupce (na rozdíl od fyzické, viz test výše) opr_*
        // jednatele NEmaže — identifikuje fyzickou osobu podepisující za tu firmu.
        self::assertStringContainsString('opr_jmeno="Jan"', $xml);
    }

    /** zast_* patří do VetaP, ne VetaD — ověřeno proti struktuře reálného podání. */
    public function testRepresentationAttributesLiveOnVetaPNotVetaD(): void
    {
        $xml = $this->build(['representation' => [
            'represented' => true, 'type' => 'F', 'first_name' => 'A', 'last_name' => 'B',
            'company_name' => null, 'ico' => null, 'ev_number' => 'EV-1',
        ]])['xml'];
        $vetaDEnd = strpos($xml, '/>', (int) strpos($xml, '<VetaD'));
        $vetaDTag = substr($xml, (int) strpos($xml, '<VetaD'), $vetaDEnd - (int) strpos($xml, '<VetaD'));
        self::assertStringNotContainsString('zast_typ', $vetaDTag);
        self::assertStringContainsString('dan_por="A"', $vetaDTag);

        $vetaPStart = (int) strpos($xml, '<VetaP');
        $vetaPEnd = strpos($xml, '/>', $vetaPStart);
        $vetaPTag = substr($xml, $vetaPStart, $vetaPEnd - $vetaPStart);
        self::assertStringContainsString('zast_typ="F"', $vetaPTag);
    }
}
