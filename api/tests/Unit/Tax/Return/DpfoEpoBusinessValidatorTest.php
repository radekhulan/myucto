<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DpfoEpoBusinessValidator;
use MyInvoice\Service\Tax\Return\DpfoReturnCalculator;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DpfoEpoBusinessValidatorTest extends TestCase
{
    #[DataProvider('matchingAmounts')]
    public function testMatchesSourceAmountsBeforeRoundingFormLines(
        int $activityCount,
        float $income,
        float $expenses,
        float $formIncome,
        float $formExpenses,
    ): void {
        $data = self::sourceData($activityCount, $income, $expenses);
        $result = (new DpfoReturnCalculator())->compute($data, [], [], TaxConstants::forYear(2026));

        self::assertSame($formIncome, $result['s7']['income']);
        self::assertSame($formExpenses, $result['s7']['expenses']);
        self::assertSame([], (new DpfoEpoBusinessValidator())->validate($result, $data));
    }

    public static function matchingAmounts(): iterable
    {
        yield 'jedna činnost' => [1, 1000.49, 800.49, 1000.0, 800.0];
        yield 'součet zaokrouhlených řádků se liší od zaokrouhleného úhrnu' => [2, 2000.98, 1600.98, 2000.0, 1600.0];
    }

    public function testDetectsSourceDifferencesEvenWhenWholeCrownValuesMatch(): void
    {
        $data = self::sourceData(1, 1000.0, 800.0);
        $result = (new DpfoReturnCalculator())->compute($data, [], [], TaxConstants::forYear(2026));

        self::assertSame([
            'DPFO: příjmy přiřazené činnostem §7 nesouhlasí s peněžním deníkem.',
            'DPFO: skutečné výdaje přiřazené činnostem §7 nesouhlasí s peněžním deníkem.',
        ], (new DpfoEpoBusinessValidator())->validate($result, $data));
    }

    private static function sourceData(int $activityCount, float $income, float $expenses): array
    {
        return [
            's7_income' => $income,
            's7_expenses' => $expenses,
            'expense_mode' => 'actual',
            'accounting_mode' => 'tax_evidence',
            'closing' => ['status' => 'final'],
            'activities' => array_fill(0, $activityCount, [
                'nace_code' => '62020',
                'expense_mode' => 'actual',
                'expense_rate' => 0,
                'income' => 1000.49,
                'expenses' => 800.49,
            ]),
        ];
    }
}
