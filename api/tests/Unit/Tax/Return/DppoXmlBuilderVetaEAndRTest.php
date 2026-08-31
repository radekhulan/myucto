<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * VetaE (příloha č. 1 II. oddílu, tabulka a) — rozpad ř. 40) a VetaR (zvláštní
 * textová příloha k ř. 62 II. oddílu) — chyběly úplně, zjištěno zkušebním EPO
 * (viz private/AUDIT-DPPO-XML.md §9.4a/b).
 */
final class DppoXmlBuilderVetaEAndRTest extends TestCase
{
    private function sampleSupplier(): array
    {
        return [
            'company_name' => 'Ukázková firma s.r.o.', 'street' => 'Zkušební 123/4',
            'city' => 'Vzorov', 'zip' => '100 00', 'country_iso2' => 'CZ',
            'ic' => '12345678', 'dic' => 'CZ12345678', 'taxpayer_type' => 'po',
            'financial_office_code' => '451', 'cz_nace_code' => '62020',
        ];
    }

    /** @param array<string,mixed> $extraData */
    private function calc(array $extraData = [], array $inputs = ['tax_paid_advances' => 0]): array
    {
        return (new DppoReturnCalculator())->compute(
            ['vh' => 500000, 'depreciation' => ['tax' => 0, 'accounting' => 0]] + $extraData,
            $inputs,
            TaxConstants::forYear(2025)
        );
    }

    private function build(array $calc, array $meta = [], array $appendix = []): array
    {
        return (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc, $meta, $appendix);
    }

    // ── VetaE ────────────────────────────────────────────────────────────────

    public function testVetaEOmittedWithoutNonDeductibleCosts(): void
    {
        $result = $this->build($this->calc());
        self::assertStringNotContainsString('<VetaE', $result['xml']);
        self::assertStringContainsString('zvl_pr="0"', $result['xml']);
    }

    public function testVetaEMatchesLine40AndBumpsPPr2od(): void
    {
        $xml = $this->build($this->calc(['non_deductible_costs' => 24800]))['xml'];

        self::assertStringContainsString('kc_ii50_40="24800"', $xml, 'ř. 40 II. oddílu (VetaO)');
        self::assertStringContainsString('<VetaE kc_dpp_a12="24800"', $xml, 'VetaE musí nést stejnou hodnotu jako ř. 40');
        self::assertStringContainsString('p_pr_2od="1"', $xml, 'jen VetaE, VetaF se bez odpisů nestaví');
    }

    public function testPPr2odCountsBothVetaEAndVetaF(): void
    {
        $xml = $this->build($this->calc([
            'non_deductible_costs' => 24800,
            'depreciation_by_group' => ['tangible' => [1 => 1000.0], 'intangible' => 0.0, 'unclassified' => 0.0],
        ]))['xml'];

        self::assertStringContainsString('<VetaE', $xml);
        self::assertStringContainsString('<VetaF', $xml);
        self::assertStringContainsString('p_pr_2od="2"', $xml);
    }

    public function testVetaEPrecedesVetaFAndFollowsVetaO(): void
    {
        $xml = $this->build($this->calc([
            'non_deductible_costs' => 24800,
            'depreciation_by_group' => ['tangible' => [1 => 1000.0], 'intangible' => 0.0, 'unclassified' => 0.0],
        ]))['xml'];

        $vetaOPos = strpos($xml, '<VetaO');
        $vetaEPos = strpos($xml, '<VetaE');
        $vetaFPos = strpos($xml, '<VetaF');
        self::assertNotFalse($vetaOPos);
        self::assertNotFalse($vetaEPos);
        self::assertNotFalse($vetaFPos);
        self::assertGreaterThan($vetaOPos, $vetaEPos);
        self::assertGreaterThan($vetaEPos, $vetaFPos);
    }

    public function testVetaEBuiltFromFlatRateTravelAddbackOnLine40(): void
    {
        // Paušál na dopravu (§24/2/zt) přesouvá add-back PHM z obecného ř. 62 na ř. 40 —
        // VetaE tedy musí vzniknout i tady, ale odpovídající položka NESMÍ skončit ve
        // VetaR (ta je jen pro to, co skutečně zůstalo na ř. 62).
        $calc = $this->calc([], [
            'tax_paid_advances' => 0,
            'manual_increase_items' => [['text' => 'PHM paušál doprava', 'amount' => 5000, 'kind' => 'flat_rate_travel']],
        ]);
        $xml = $this->build($calc)['xml'];

        self::assertStringContainsString('<VetaE kc_dpp_a12="5000"', $xml);
        self::assertStringNotContainsString('<VetaR', $xml);
        self::assertStringContainsString('zvl_pr="0"', $xml);
    }

    // ── VetaR / zvl_pr ───────────────────────────────────────────────────────

    public function testVetaRBuiltPerManualIncreaseItemAndZvlPrMatchesCount(): void
    {
        $calc = $this->calc([], [
            'tax_paid_advances' => 0,
            'manual_increase_items' => [
                ['text' => 'Reprezentace §25/1/t', 'amount' => 24800],
                ['text' => 'Smluvní pokuta §25/1/f', 'amount' => 5000],
            ],
        ]);
        $result = $this->build($calc);
        $xml = $result['xml'];

        self::assertStringContainsString('zvl_pr="2"', $xml);
        self::assertStringContainsString('<VetaR c_radku="62" t_prilohy="Reprezentace §25/1/t (24 800 Kč)" kod_sekce="2" poradi="1"', $xml);
        self::assertStringContainsString('<VetaR c_radku="62" t_prilohy="Smluvní pokuta §25/1/f (5 000 Kč)" kod_sekce="2" poradi="2"', $xml);
    }

    public function testVetaRExcludesFlatRateTravelItems(): void
    {
        $calc = $this->calc([], [
            'tax_paid_advances' => 0,
            'manual_increase_items' => [
                ['text' => 'Reprezentace §25/1/t', 'amount' => 24800],
                ['text' => 'PHM paušál doprava', 'amount' => 5000, 'kind' => 'flat_rate_travel'],
            ],
        ]);
        $xml = $this->build($calc)['xml'];

        self::assertStringContainsString('zvl_pr="1"', $xml);
        self::assertStringContainsString('poradi="1"', $xml);
        self::assertStringNotContainsString('PHM paušál doprava', $xml);
    }

    public function testVetaRTextTruncatedToXsdMaxLength(): void
    {
        $longText = str_repeat('Velmi dlouhý popis položky přesahující limit ', 3);
        $calc = $this->calc([], [
            'tax_paid_advances' => 0,
            'manual_increase_items' => [['text' => $longText, 'amount' => 1000]],
        ]);
        $xml = $this->build($calc)['xml'];

        self::assertMatchesRegularExpression('/t_prilohy="([^"]*)"/', $xml);
        preg_match('/t_prilohy="([^"]*)"/u', $xml, $matches);
        self::assertLessThanOrEqual(72, mb_strlen($matches[1]));
    }

    public function testVetaRFollowsVetaSAndPrecedesAppendixBlocks(): void
    {
        $appendix = [
            'balance_sheet' => ['assets' => [
                ['row_code' => 'AKTIVA', 'gross' => 1000.0, 'correction' => 0.0, 'net' => 1000.0, 'prev_net' => 1000.0],
            ], 'liabilities' => [
                ['row_code' => 'PASIVA', 'amount' => 1000.0, 'prev_amount' => 1000.0],
            ]],
            'income_statement' => ['rows' => [
                ['row_code' => 'I.', 'amount' => 1000000.0, 'prev_amount' => 0.0],
            ]],
        ];
        $calc = $this->calc([], [
            'tax_paid_advances' => 0,
            'manual_increase_items' => [['text' => 'Reprezentace §25/1/t', 'amount' => 24800]],
        ]);
        $xml = $this->build($calc, [], $appendix)['xml'];

        $vetaSPos = strpos($xml, '<VetaS');
        $vetaRPos = strpos($xml, '<VetaR');
        $vetaUAPos = strpos($xml, '<VetaUA');
        self::assertNotFalse($vetaSPos);
        self::assertNotFalse($vetaRPos);
        self::assertNotFalse($vetaUAPos);
        self::assertGreaterThan($vetaSPos, $vetaRPos);
        self::assertGreaterThan($vetaRPos, $vetaUAPos);
    }

    /**
     * Sídlo mimo ČR neznamená automaticky nerezidenta (rozhoduje místo vedení podle
     * § 17 odst. 3), ale tiše tvrdit „ostatní poplatník" u zahraničního sídla je
     * nepravdivé. Kód se proto nepřepisuje, jen se varuje.
     */
    public function testForeignSeatWarnsAboutTaxpayerType(): void
    {
        $supplier = $this->sampleSupplier();
        $supplier['country_iso2'] = 'SK';

        $result = (new DppoXmlBuilder())->build($supplier, 2025, $this->calc(), []);

        self::assertStringContainsString('typ_popldpp="1"', $result['xml']);
        self::assertNotEmpty(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'nerezident'),
        ));
    }

    public function testCzechSeatDoesNotWarn(): void
    {
        $result = (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $this->calc(), []);

        self::assertSame([], array_values(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'nerezident'),
        )));
    }
}