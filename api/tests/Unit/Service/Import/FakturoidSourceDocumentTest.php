<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\FakturoidSourceDocument;
use PHPUnit\Framework\TestCase;

final class FakturoidSourceDocumentTest extends TestCase
{
    public function testPricesIncludeVatOnlyWithTaxedLine(): void
    {
        $taxed = ['vat_price_mode' => 'from_total_with_vat', 'lines' => [['unit_price' => 1210, 'vat_rate' => 21]]];
        self::assertTrue(FakturoidSourceDocument::pricesIncludeVat($taxed));

        // Doklad bez DPH zůstává v režimu bez DPH jako před opravou #128.
        self::assertFalse(FakturoidSourceDocument::pricesIncludeVat(['vat_price_mode' => 'from_total_with_vat', 'lines' => [['unit_price' => 100, 'vat_rate' => 0]]]));
        self::assertFalse(FakturoidSourceDocument::pricesIncludeVat(['vat_price_mode' => 'from_total_with_vat', 'lines' => [['unit_price' => 100]]]));
        self::assertFalse(FakturoidSourceDocument::pricesIncludeVat(['vat_price_mode' => 'without_vat', 'lines' => [['unit_price' => 100, 'vat_rate' => 21]]]));
        self::assertFalse(FakturoidSourceDocument::pricesIncludeVat(['vat_price_mode' => null, 'lines' => [['unit_price' => 100, 'vat_rate' => 21]]]));
    }

    public function testTaxDeductible(): void
    {
        self::assertFalse(FakturoidSourceDocument::taxDeductible(['tax_deductible' => false]));
        self::assertFalse(FakturoidSourceDocument::taxDeductible(['tax_deductible' => 'false']));
        self::assertTrue(FakturoidSourceDocument::taxDeductible(['tax_deductible' => true]));
        self::assertTrue(FakturoidSourceDocument::taxDeductible([]));
        self::assertTrue(FakturoidSourceDocument::taxDeductible(['tax_deductible' => null]));
    }

    public function testProportionalVatDeduction(): void
    {
        self::assertSame(['vat_deduction' => 'proportional', 'vat_deduction_percent' => 80.0], FakturoidSourceDocument::vatDeduction(['proportional_vat_deduction' => 80]));
        self::assertSame(['vat_deduction' => 'proportional', 'vat_deduction_percent' => 45.5], FakturoidSourceDocument::vatDeduction(['proportional_vat_deduction' => '45.5']));
        self::assertSame(['vat_deduction' => 'none', 'vat_deduction_percent' => 0.0], FakturoidSourceDocument::vatDeduction(['proportional_vat_deduction' => 0]));
        self::assertNull(FakturoidSourceDocument::vatDeduction(['proportional_vat_deduction' => 100]));
        self::assertNull(FakturoidSourceDocument::vatDeduction(['proportional_vat_deduction' => null]));
        self::assertNull(FakturoidSourceDocument::vatDeduction(['proportional_vat_deduction' => -5]));
        self::assertNull(FakturoidSourceDocument::vatDeduction([]));
    }

    public function testEmptySummaryHasNoDifferences(): void
    {
        self::assertSame([], FakturoidSourceDocument::differences([['rate' => 0.0, 'base' => 100.0, 'vat' => 0.0]], []));
        self::assertSame([], FakturoidSourceDocument::differences(
            [['rate' => 0.0, 'base' => 100.0, 'vat' => 0.0]],
            FakturoidSourceDocument::vatSummary(['vat_rates_summary' => [['vat_rate' => 0, 'base' => '100.0', 'vat' => '0.0']]]),
        ));
    }

    public function testRoundingDifferenceIsAlignableButLargeOneIsNot(): void
    {
        $computed = [['rate' => 21.0, 'base' => 20.26, 'vat' => 4.26]];
        $diffs = FakturoidSourceDocument::differences($computed, [['rate' => 21.0, 'base' => 20.26, 'vat' => 4.25]]);
        self::assertCount(1, $diffs);
        self::assertSame([['rate' => 21.0, 'base' => 20.26, 'vat' => 4.25]], FakturoidSourceDocument::alignableOverrides($diffs, $computed));

        $large = FakturoidSourceDocument::differences($computed, [['rate' => 21.0, 'base' => 10.0, 'vat' => 2.1]]);
        self::assertNull(FakturoidSourceDocument::alignableOverrides($large, $computed));
    }

    public function testZeroRateDifferenceIsNeverAlignedByOverride(): void
    {
        $computed = [['rate' => 0.0, 'base' => 100.0, 'vat' => 0.0]];
        $diffs = FakturoidSourceDocument::differences($computed, [['rate' => 0.0, 'base' => 100.5, 'vat' => 0.0]]);
        self::assertNull(FakturoidSourceDocument::alignableOverrides($diffs, $computed));
    }

    public function testRoundingAdjustmentOnlyBelowOneUnit(): void
    {
        self::assertSame(-0.01, FakturoidSourceDocument::roundingAdjustment(['rounding_adjustment' => '-0.01']));
        self::assertSame(0.0, FakturoidSourceDocument::roundingAdjustment(['rounding_adjustment' => '5.0']));
        self::assertSame(0.0, FakturoidSourceDocument::roundingAdjustment([]));
    }
}
