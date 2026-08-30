<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DpfoReturnCalculator;
use MyInvoice\Service\Tax\Return\DpfoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * `pln_moc` (VetaD) + `zast_*` (VetaP) — zastoupení daňovým poradcem (§29/2 DŘ), obdoba
 * {@see DppoXmlBuilderRepresentationTest} pro DPFO. Předtím builder posílal natvrdo
 * `pln_moc="N"` (přes `$meta['pln_moc'] ?? 'N'`) bez ohledu na evidenci — appka
 * zastoupení vůbec neevidovala. Hodnoty v testu jsou VŽDY vymyšlené.
 */
final class DpfoXmlBuilderRepresentationTest extends TestCase
{
    private function sampleSupplier(): array
    {
        return [
            'id' => 1,
            'company_name' => 'Jan Novák',
            'street' => 'Krátká 12/3',
            'city' => 'Praha',
            'zip' => '110 00',
            'country_iso2' => 'CZ',
            'ic' => '87654321',
            'dic' => 'CZ7801011234',
            'taxpayer_type' => 'fo',
            'financial_office_code' => '451',
            'cz_nace_code' => '62020',
        ];
    }

    private function sampleCalc(): array
    {
        return (new DpfoReturnCalculator())->compute(
            ['s7_income' => 1000000, 's7_expenses' => 600000, 's7_base' => 400000, 'expense_mode' => 'pausal', 'expense_rate' => 60],
            ['tax_paid_advances' => 0],
            [],
            TaxConstants::forYear(2025)
        );
    }

    private function build(array $meta = []): array
    {
        return (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $this->sampleCalc(), $meta);
    }

    /** Bez evidence zastoupení (žádné $meta['representation']) — dnešní chování: 'N', žádné zast_*. */
    public function testDefaultsToNWhenRepresentationMetaMissing(): void
    {
        $xml = $this->build()['xml'];
        self::assertStringContainsString('pln_moc="N"', $xml);
        self::assertStringNotContainsString('zast_typ', $xml);
    }

    /** Explicitní $meta['pln_moc'] má přednost před evidencí (BC pro stávající volající). */
    public function testExplicitPlnMocOverridesRepresentation(): void
    {
        $xml = $this->build([
            'pln_moc' => 'N',
            'representation' => ['represented' => true, 'type' => 'F', 'first_name' => 'A', 'last_name' => 'B', 'ev_number' => 'EV-1'],
        ])['xml'];
        self::assertStringContainsString('pln_moc="N"', $xml);
    }

    /** Fyzická osoba (daňový poradce) — pln_moc='A', zast_kod 4b, jméno/příjmení + evidenční číslo. */
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
        self::assertStringContainsString('pln_moc="A"', $xml);
        self::assertStringContainsString('zast_typ="F"', $xml);
        self::assertStringContainsString('zast_kod="4b"', $xml);
        self::assertStringContainsString('zast_jmeno="Vzorový"', $xml);
        self::assertStringContainsString('zast_prijmeni="Poradce"', $xml);
        self::assertStringContainsString('zast_ev_cislo="EV-0001"', $xml);
    }

    /** Právnická osoba (daňová poradenská společnost) — pln_moc='A', zast_kod 4c, název + IČO. */
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
        self::assertStringContainsString('pln_moc="A"', $xml);
        self::assertStringContainsString('zast_typ="P"', $xml);
        self::assertStringContainsString('zast_kod="4c"', $xml);
        self::assertStringContainsString('zast_nazev="Vzorová daňová poradna s.r.o."', $xml);
        self::assertStringContainsString('zast_ic="01234567"', $xml);
        self::assertStringContainsString('zast_ev_cislo="EV-0002"', $xml);
    }
}
