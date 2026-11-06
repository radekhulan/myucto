<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\AnnualClaimsPrintBreakdown;
use MyInvoice\Service\Payroll\Document\AnnualSettlementDocumentData;
use MyInvoice\Service\Payroll\Document\AnnualSettlementPdfRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tisk ročního zúčtování s ročně uplatňovanými položkami (§ 38h odst. 6).
 */
final class AnnualSettlementPrintClaimsTest extends TestCase
{
    /** @return array<string,mixed> */
    private function resultWithClaims(): array
    {
        return [
            'tax_base_before_deductions_minor_units' => 100_005_000,
            'non_taxable_deductions_minor_units' => 11_800_000,
            'enforcement_credit_minor_units' => 300_000,
            'trace' => [
                'annual_claims' => [
                    'items' => [],
                    'spouse' => ['months' => 12],
                ],
                'non_taxable' => [
                    'claimed_by_kind' => [
                        'donation' => 2_000_000,
                        'housing_interest' => 5_000_000,
                        'life_insurance' => 1_500_000,
                        'long_term_investment' => 1_000_000,
                        'pension_savings' => 3_000_000,
                    ],
                    'donation' => [
                        'claimed_minor_units' => 2_000_000,
                        'deducted_minor_units' => 2_000_000,
                    ],
                    'housing_interest' => [
                        'claimed_minor_units' => 5_000_000,
                        'deducted_minor_units' => 5_000_000,
                    ],
                    'retirement_and_care' => [
                        'claimed_minor_units' => 5_500_000,
                        'deducted_minor_units' => 4_800_000,
                    ],
                    'total_deducted_minor_units' => 11_800_000,
                ],
            ],
        ];
    }

    /** @param list<array{label:string,claimed_minor_units:int,amount_minor_units:int}> $rows */
    private function document(
        bool $present,
        int $roundedBase,
        int $baseBefore = 0,
        array $rows = [],
        int $deductions = 0,
        int $enforcement = 0,
    ): AnnualSettlementDocumentData {
        return new AnnualSettlementDocumentData(
            str_repeat('a', 64),
            2025,
            'Testovací zaměstnavatel s.r.o.',
            '12345678',
            'Ulice 1, Praha',
            'Jana Testovací',
            'Rodné číslo',
            '000101/0000',
            '2026-02-20',
            12,
            100_005_000,
            $roundedBase,
            13_000_000,
            [['label' => 'Sleva na poplatníka', 'amount_minor_units' => 3_000_000]],
            3_000_000,
            [],
            0,
            0,
            10_000_000,
            12_000_000,
            0,
            2_000_000,
            0,
            2_000_000,
            2_000_000,
            'overpayment',
            0,
            0,
            0,
            0,
            $present,
            $baseBefore,
            $rows,
            $deductions,
            $enforcement,
        );
    }

    public function testBreakdownSplitsCommonLimitInFormOrder(): void
    {
        $breakdown = AnnualClaimsPrintBreakdown::fromResult($this->resultWithClaims());

        self::assertTrue($breakdown['present']);
        $amounts = array_column($breakdown['rows'], 'amount_minor_units');
        // dary, úroky, penzijní produkty, životní pojištění, DIP (zkrácen na zbytek limitu)
        self::assertSame([2_000_000, 5_000_000, 3_000_000, 1_500_000, 300_000], $amounts);
        self::assertSame(11_800_000, array_sum($amounts));
        self::assertSame(1_000_000, $breakdown['rows'][4]['claimed_minor_units']);
        self::assertSame(300_000, $breakdown['enforcement_credit_minor_units']);
    }

    public function testBreakdownWithoutClaimsIsEmpty(): void
    {
        $breakdown = AnnualClaimsPrintBreakdown::fromResult(['trace' => ['completed_months' => 12]]);

        self::assertFalse($breakdown['present']);
        self::assertSame([], $breakdown['rows']);
    }

    public function testDocumentWithClaimsRendersAndKeepsInvariants(): void
    {
        $breakdown = AnnualClaimsPrintBreakdown::fromResult($this->resultWithClaims());
        $document = $this->document(
            true,
            88_200_000,
            $breakdown['tax_base_before_deductions_minor_units'],
            $breakdown['rows'],
            $breakdown['non_taxable_deductions_minor_units'],
            $breakdown['enforcement_credit_minor_units'],
        );

        $data = $document->toTemplateData();
        self::assertTrue($data['annual_claims_present']);
        self::assertCount(5, $data['non_taxable_rows']);

        $artifact = (new AnnualSettlementPdfRenderer())->render($document);
        self::assertStringStartsWith('%PDF', $artifact->bytes);
        self::assertSame('mz-25-annual-settlement-v3', AnnualSettlementPdfRenderer::VERSION);
    }

    public function testBaseAfterDeductionsMustMatchRoundedBase(): void
    {
        $breakdown = AnnualClaimsPrintBreakdown::fromResult($this->resultWithClaims());

        $this->expectException(\InvalidArgumentException::class);
        $this->document(
            true,
            88_300_000,
            $breakdown['tax_base_before_deductions_minor_units'],
            $breakdown['rows'],
            $breakdown['non_taxable_deductions_minor_units'],
        );
    }

    public function testRowsMustAddUpToDeductions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->document(
            true,
            88_200_000,
            100_005_000,
            [['label' => 'Dary', 'claimed_minor_units' => 1, 'amount_minor_units' => 1]],
            11_800_000,
        );
    }

    public function testDocumentWithoutClaimsIsUnchanged(): void
    {
        $document = $this->document(false, 100_000_000);

        $data = $document->toTemplateData();
        self::assertFalse($data['annual_claims_present']);
        self::assertSame([], $data['non_taxable_rows']);

        $artifact = (new AnnualSettlementPdfRenderer())->render($document);
        self::assertStringStartsWith('%PDF', $artifact->bytes);
    }

    public function testClaimsWithoutPresenceFlagAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->document(false, 100_000_000, 0, [], 0, 300_000);
    }
}
