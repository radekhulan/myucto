<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\TaxBonus;

use MyInvoice\Service\Payroll\TaxBonus\TaxBonusClaim;
use MyInvoice\Service\Payroll\TaxBonus\TaxBonusClaimBasis;
use MyInvoice\Service\Payroll\TaxBonus\TaxBonusClaimCalculator;
use PHPUnit\Framework\TestCase;

final class TaxBonusClaimCalculatorTest extends TestCase
{
    private function basis(
        int $advance,
        int $monthly,
        int $annual,
        string $period = '2026-03-01',
        ?string $paymentDate = '2026-04-10',
    ): TaxBonusClaimBasis {
        return new TaxBonusClaimBasis($period, $paymentDate, $advance, $monthly, $annual, [7]);
    }

    public function testNoClaimWhenAdvancesCoverBonuses(): void
    {
        $result = (new TaxBonusClaimCalculator())->calculate(
            $this->basis(500_00, 300_00, 0),
        );

        self::assertNull($result['monthly']);
        self::assertNull($result['annual']);
    }

    public function testMonthlyBonusShortfallBecomesDpzmb1(): void
    {
        $result = (new TaxBonusClaimCalculator())->calculate(
            $this->basis(1_200_00, 5_000_00, 0, '2026-05-01', '2026-06-11'),
        );

        $claim = $result['monthly'];
        self::assertInstanceOf(TaxBonusClaim::class, $claim);
        self::assertSame(TaxBonusClaim::FORM_MONTHLY, $claim->formCode);
        self::assertSame(2026, $claim->bonusYear);
        self::assertSame(5, $claim->bonusMonth);
        self::assertSame(5_000, $claim->bonusTotalCzk);
        self::assertSame(1_200, $claim->advancesCzk);
        self::assertSame(3_800, $claim->ownFundsCzk);
        self::assertSame('11.6.2026', $claim->bonusDateEpo());
        self::assertNull($result['annual']);
    }

    /**
     * Roční zúčtování se provádí po skončení roku, takže doplatek vyplacený
     * v roce N patří ke zdaňovacímu období N−1.
     */
    public function testAnnualSettlementShortfallBecomesDpzdb1ForPreviousTaxYear(): void
    {
        $result = (new TaxBonusClaimCalculator())->calculate(
            $this->basis(0, 0, 9_000_00),
        );

        $claim = $result['annual'];
        self::assertInstanceOf(TaxBonusClaim::class, $claim);
        self::assertSame(TaxBonusClaim::FORM_ANNUAL, $claim->formCode);
        self::assertSame(2025, $claim->bonusYear);
        self::assertNull($claim->bonusMonth);
        self::assertSame(9_000, $claim->bonusTotalCzk);
        self::assertSame(0, $claim->advancesCzk);
        self::assertSame(9_000, $claim->ownFundsCzk);
        self::assertNull($result['monthly']);
    }

    /**
     * Zálohy kryjí nejdřív měsíční bonus (odst. 5) a teprve zbytkem doplatek
     * (odst. 9). Tady zálohy 4 000 spolknou celý měsíční bonus 3 000 a zbylou
     * 1 000 nasadí na doplatek 5 000.
     */
    public function testAdvancesCoverMonthlyBonusBeforeAnnualSettlement(): void
    {
        $result = (new TaxBonusClaimCalculator())->calculate(
            $this->basis(4_000_00, 3_000_00, 5_000_00),
        );

        self::assertNull($result['monthly'], 'Měsíční bonus se celý pokryl ze záloh.');
        $annual = $result['annual'];
        self::assertInstanceOf(TaxBonusClaim::class, $annual);
        self::assertSame(5_000, $annual->bonusTotalCzk);
        self::assertSame(1_000, $annual->advancesCzk);
        self::assertSame(4_000, $annual->ownFundsCzk);
    }

    public function testBothFormsWhenAdvancesRunOutOnTheMonthlyBonus(): void
    {
        $result = (new TaxBonusClaimCalculator())->calculate(
            $this->basis(1_000_00, 3_000_00, 2_000_00),
        );

        $monthly = $result['monthly'];
        $annual = $result['annual'];
        self::assertInstanceOf(TaxBonusClaim::class, $monthly);
        self::assertInstanceOf(TaxBonusClaim::class, $annual);
        self::assertSame(1_000, $monthly->advancesCzk);
        self::assertSame(2_000, $monthly->ownFundsCzk);
        self::assertSame(0, $annual->advancesCzk);
        self::assertSame(2_000, $annual->ownFundsCzk);
    }

    /**
     * Klíčová vazba na zbytek modulu: součet obou žádostí se musí rovnat
     * `advance_tax_offset_unapplied_minor`, které si materializer ukládá
     * k závazku odvodu záloh. Jinak by firma žádala o jinou částku, než jakou
     * má vykázanou jako pohledávku za správcem daně.
     */
    public function testClaimsTogetherMatchTheMaterializerShortfall(): void
    {
        $calculator = new TaxBonusClaimCalculator();
        foreach ([
            [0, 0, 0],
            [10_000_00, 1_000_00, 0],
            [1_000_00, 10_000_00, 0],
            [1_000_00, 0, 10_000_00],
            [4_000_00, 3_000_00, 5_000_00],
            [1_000_00, 3_000_00, 2_000_00],
            [0, 7_777_00, 3_333_00],
        ] as [$advance, $monthly, $annual]) {
            $basis = $this->basis($advance, $monthly, $annual);
            $result = $calculator->calculate($basis);
            $claimed = 0;
            foreach (['monthly', 'annual'] as $slot) {
                $claim = $result[$slot];
                if ($claim instanceof TaxBonusClaim) {
                    $claimed += $claim->ownFundsCzk * 100;
                }
            }
            self::assertSame(
                $basis->unappliedOffsetMinor(),
                $claimed,
                sprintf('Rozpad %d/%d/%d neodpovídá převisu.', $advance, $monthly, $annual),
            );
        }
    }

    public function testMissingPaymentDateBlocksTheClaimWithWarning(): void
    {
        $result = (new TaxBonusClaimCalculator())->calculate(
            $this->basis(0, 5_000_00, 0, '2026-03-01', null),
        );

        self::assertNull($result['monthly']);
        self::assertNull($result['annual']);
        self::assertNotEmpty($result['warnings']);
    }

    public function testFractionalHellerAmountsAreRoundedWithWarning(): void
    {
        $result = (new TaxBonusClaimCalculator())->calculate(
            $this->basis(0, 5_000_49, 0),
        );

        $claim = $result['monthly'];
        self::assertInstanceOf(TaxBonusClaim::class, $claim);
        self::assertSame(5_000, $claim->bonusTotalCzk);
        self::assertNotEmpty($claim->warnings);
    }
}
