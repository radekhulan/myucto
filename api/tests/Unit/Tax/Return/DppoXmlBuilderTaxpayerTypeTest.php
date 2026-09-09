<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\Return\TaxpayerTypeCodebook;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * `typ_popldpp` (VetaD) — typ poplatníka podle § 17 ZDP. Do P-1 se do přiznání
 * zapisovala natvrdo „1" (ostatní) a nikdo jinou hodnotu nepředával, takže
 * investiční fond i veřejně prospěšný poplatník dostali přiznání, které o nich
 * tvrdilo nepravdu. Číselník drží {@see TaxpayerTypeCodebook} (zdroj:
 * dokumentace atributu v api/xsd/dppdp9_epo2.xsd).
 *
 * Hodnoty v testu jsou VŽDY vymyšlené.
 */
final class DppoXmlBuilderTaxpayerTypeTest extends TestCase
{
    private function sampleSupplier(array $overrides = []): array
    {
        return $overrides + [
            'company_name' => 'Ukázková firma s.r.o.', 'street' => 'Zkušební 123/4',
            'city' => 'Vzorov', 'zip' => '100 00', 'country_iso2' => 'CZ',
            'ic' => '12345678', 'dic' => 'CZ12345678', 'taxpayer_type' => 'po',
            'financial_office_code' => '451', 'cz_nace_code' => '62020',
            'opr_jmeno' => 'Jan', 'opr_prijmeni' => 'Jednatel', 'opr_postaveni' => 'jednatel',
        ];
    }

    private function build(array $meta = [], array $supplierOverrides = []): array
    {
        $calc = (new DppoReturnCalculator())->compute(
            ['vh' => 500000, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            ['tax_paid_advances' => 0],
            TaxConstants::forYear(2025),
        );

        return (new DppoXmlBuilder())->build($this->sampleSupplier($supplierOverrides), 2025, $calc, $meta);
    }

    /**
     * Builder varuje i na věci mimo tenhle test (chybějící období, expirovaný NACE…),
     * takže se vybírají jen věty k testovanému tématu.
     *
     * @param array{warnings:list<string>} $built
     * @return list<string>
     */
    private function warningsAbout(array $built, string $needle): array
    {
        return array_values(array_filter(
            $built['warnings'],
            static fn (string $w): bool => str_contains($w, $needle),
        ));
    }

    /** Bez mety zůstává dosavadní chování — „ostatní" (kód 1). */
    public function testDefaultsToOrdinaryTaxpayerType(): void
    {
        $built = $this->build();
        self::assertStringContainsString('typ_popldpp="1"', $built['xml']);
        self::assertSame([], $this->warningsAbout($built, 'typ_popldpp'));
    }

    /** Platný kód z číselníku se propíše do XML a builder ho neodmítne. */
    public function testValidCodebookValueReachesXml(): void
    {
        foreach (array_keys(TaxpayerTypeCodebook::TAXPAYER_TYPES) as $code) {
            $built = $this->build(['typ_popldpp' => $code]);
            self::assertStringContainsString('typ_popldpp="' . $code . '"', $built['xml'], "kód $code");
            self::assertSame([], $this->warningsAbout($built, 'není v číselníku'), "kód $code nesmí varovat");
        }
    }

    /** Hodnota mimo číselník se odmítne: spadne na „1" a builder na to upozorní. */
    public function testInvalidCodeIsRejectedWithWarning(): void
    {
        foreach (['X', '10', '', 'A'] as $code) {
            $built = $this->build(['typ_popldpp' => $code]);
            self::assertStringContainsString('typ_popldpp="1"', $built['xml'], 'kód ' . $code . ' musí spadnout na 1');
            self::assertNotSame([], $this->warningsAbout($built, 'není v číselníku'), 'kód ' . $code . ' musí varovat');
        }
    }

    /** Povinný atribut nesmí být prázdný ani po odmítnutí hodnoty (XSD ho vyžaduje). */
    public function testAttributeIsNeverEmpty(): void
    {
        $built = $this->build(['typ_popldpp' => 'nesmysl']);
        self::assertStringNotContainsString('typ_popldpp=""', $built['xml']);
    }

    /** Zahraniční sídlo varuje jen u typu „ostatní" — u výslovného nerezidenta (2) ne. */
    public function testForeignSeatWarnsOnlyForOrdinaryType(): void
    {
        $ordinary = $this->build([], ['country_iso2' => 'SK']);
        self::assertNotSame([], $this->warningsAbout($ordinary, 'mimo ČR (SK)'));

        $nonResident = $this->build(['typ_popldpp' => '2'], ['country_iso2' => 'SK']);
        self::assertSame([], $this->warningsAbout($nonResident, 'mimo ČR'));
    }
}
