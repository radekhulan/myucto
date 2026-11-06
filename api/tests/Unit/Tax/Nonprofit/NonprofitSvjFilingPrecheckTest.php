<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\NonprofitSvjFilingPrecheck;
use PHPUnit\Framework\TestCase;

/**
 * Předkontrola SVJ podle § 38mb písm. b) ZDP na syntetických řádcích referenčního SVJ:
 * příspěvky na správu a služby mimo předmět, bankovní úrok srážkou, úrok z prodlení
 * a náhrada právního nákladu jako jiný zdanitelný příjem.
 */
final class NonprofitSvjFilingPrecheckTest extends TestCase
{
    private static function revenue(string $id, int $cents, string $regime, string $method): array
    {
        return ['id' => $id, 'kind' => 'revenue', 'amount_cents' => $cents, 'classification' => ['regime' => $regime, 'method' => $method]];
    }

    private static function exemptOnly(): array
    {
        return [
            self::revenue('management', 25000000, 'out_of_scope', 'none'),
            self::revenue('bank-interest', 79000, 'subject', 'final_withholding'),
            ['id' => 'legal-cost', 'kind' => 'expense', 'amount_cents' => 6000, 'classification' => ['regime' => 'subject', 'method' => 'cost']],
        ];
    }

    public function testOtherTaxableIncomeRequiresFilingDespiteZeroTax(): void
    {
        $rows = self::exemptOnly();
        $rows[] = self::revenue('legal-cost-recovery', 6000, 'subject', 'return');
        $rows[] = self::revenue('late-payment-interest', 4000, 'subject', 'return');
        self::assertSame(['status' => 'filing_required', 'reason' => 'other_taxable_income', 'legal_basis' => 'zdp_38mb_b', 'taxable_source_count' => 2],
            NonprofitSvjFilingPrecheck::evaluate(true, [array_slice($rows, 0, 3), array_slice($rows, 3)]));
    }

    public function testOnlyExcludedAndWithheldIncomeAllowsNoFiling(): void
    {
        self::assertSame('filing_not_required', NonprofitSvjFilingPrecheck::evaluate(true, [self::exemptOnly()])['status']);
    }

    public function testMissingAssessmentStaysUnassessedInsteadOfAutomaticExemption(): void
    {
        self::assertSame(['status' => 'unassessed', 'reason' => 'assessment_incomplete', 'legal_basis' => 'zdp_38mb_b', 'taxable_source_count' => 0],
            NonprofitSvjFilingPrecheck::evaluate(false, [self::exemptOnly()]));
    }

    public function testUnknownIncomeCategoryIsUnassessedNotExempt(): void
    {
        $rows = self::exemptOnly();
        $rows[] = self::revenue('unknown', 5000, 'pending', 'none');
        self::assertSame('income_unclassified', NonprofitSvjFilingPrecheck::evaluate(true, [$rows])['reason']);
        self::assertSame('unassessed', NonprofitSvjFilingPrecheck::evaluate(true, [$rows])['status']);
    }
}
