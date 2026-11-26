<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Assets;

use MyInvoice\Service\Accounting\Assets\DepreciationCalculator;
use MyInvoice\Service\Accounting\Assets\DepreciationContext;
use MyInvoice\Service\Accounting\Assets\Strategy\AccountingByTaxStrategy;
use MyInvoice\Service\Accounting\Assets\Strategy\TaxStraightLineStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Unit testy politiky „účetní odpis = daňový odpis" (`acc_method='by_tax'`).
 * Referenční karta = BMW 330d xDrive (VC 1 157 025, zařazení 30. 9. 2025,
 * skupina 2, rovnoměrné §31): 1. rok 11 % = 127 273, roky 2–5 22,25 % = 257 438.
 * Měsíc zařazení do roční sazby nevstupuje — právě to lineární měsíční odpis
 * napodobit nedokáže a proč tahle strategie existuje.
 */
final class AccountingByTaxStrategyTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private function ctx(array $overrides = []): DepreciationContext
    {
        $args = array_merge([
            'inputPrice' => 1157025.0,
            'taxGroup' => 2,
            'firstYearIncrease' => 'none',
            'isFirstOwner' => false,
            'isM1Vehicle' => false,
            'm1LimitException' => false,
            'putIntoUseDate' => '2025-09-30',
            'disposalDate' => null,
            'accUsefulLifeMonths' => null,
            'accResidualValue' => 0.0,
            'openingTaxYears' => 0,
            'openingTaxAmount' => 0.0,
            'openingAccMonths' => 0,
            'openingAccAmount' => 0.0,
            'improvements' => [],
            'confirmedEntries' => [],
        ], $overrides);

        return new DepreciationContext(...$args);
    }

    private function strategy(): AccountingByTaxStrategy
    {
        return new AccountingByTaxStrategy(new TaxStraightLineStrategy());
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,float> fiscal_year => amount
     */
    private function byYear(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['fiscal_year']] = round((float) $row['amount'], 2);
        }
        return $out;
    }

    public function testPlanMirrorsStraightLineTaxRates(): void
    {
        $years = $this->byYear($this->strategy()->plan($this->ctx()));

        self::assertSame(127273.0, $years[2025]); // ceil(11 % VC), bez ohledu na měsíc zařazení
        self::assertSame(257439.0, $years[2026]); // ceil(22,25 % VC)
        self::assertSame(257439.0, $years[2027]);
        self::assertSame(257439.0, $years[2028]);
        self::assertSame(257435.0, $years[2029]); // poslední rok capnutý na daňovou ZC
        self::assertSame(1157025.0, array_sum($years)); // plné odepsání VC
    }

    public function testPlanIsIdenticalToTaxPlan(): void
    {
        $ctx = $this->ctx();
        $tax = (new TaxStraightLineStrategy())->plan($ctx);
        $acc = $this->strategy()->plan($ctx);

        self::assertSameSize($tax, $acc);
        foreach ($tax as $i => $taxRow) {
            self::assertSame($taxRow['fiscal_year'], $acc[$i]['fiscal_year']);
            self::assertSame($taxRow['full_amount'], $acc[$i]['amount']);
            self::assertSame($taxRow['residual_end'], $acc[$i]['residual_end']);
        }
    }

    public function testYearRowRecomputesFreshEvenWithConfirmedTaxRow(): void
    {
        // bookYear volá accountingYearRow dřív, než upsertne daňový řádek roku —
        // rok se musí spočítat čerstvě, ne se vzít z potvrzeného daňového řádku.
        $ctx = $this->ctx(['confirmedEntries' => [
            ['fiscal_year' => 2025, 'kind' => 'tax', 'amount' => 1.0, 'full_amount' => 1.0,
                'is_paused' => false, 'is_half' => false],
        ]]);
        $row = $this->strategy()->yearRow($ctx, 2025);

        self::assertNotNull($row);
        self::assertSame(127273.0, round((float) $row['amount'], 2));
    }

    public function testConfirmedAccountingRowWinsInPlan(): void
    {
        $ctx = $this->ctx(['confirmedEntries' => [
            ['fiscal_year' => 2025, 'kind' => 'accounting', 'amount' => 57852.0, 'full_amount' => 57852.0,
                'is_paused' => false, 'is_half' => false],
        ]]);
        $rows = $this->strategy()->plan($ctx);

        self::assertSame(57852.0, round((float) $rows[0]['amount'], 2));
        self::assertSame('confirmed', $rows[0]['source']);
        self::assertSame('computed', $rows[1]['source']);
    }

    public function testMirrorsFullAmountNotDeductiblePartUnder30e(): void
    {
        // §30e krátí jen daňově uznatelnou část (amount), účetní náklad je celý odpis.
        $ctx = $this->ctx([
            'inputPrice' => 3000000.0,
            'isM1Vehicle' => true,
            'taxGroup' => 2,
        ]);
        $tax = (new TaxStraightLineStrategy())->plan($ctx);
        $acc = $this->strategy()->plan($ctx);

        self::assertLessThan($tax[0]['full_amount'], $tax[0]['amount']); // §30e se opravdu aplikoval
        self::assertSame($tax[0]['full_amount'], $acc[0]['amount']);
    }

    public function testDisposalYearMirrorsHalfDepreciation(): void
    {
        $ctx = $this->ctx(['disposalDate' => '2027-06-30']);
        $years = $this->byYear($this->strategy()->plan($ctx));

        self::assertSame(128720.0, $years[2027]); // půlodpis §26/7: ceil(22,25 % VC / 2)
    }

    /**
     * Karta převzatá z POHODY: daňové oprávky za 2022–2025 (4 roky), účetní oprávky
     * i s odpisem běžného roku 2026, který už je v deníku. Rok 2026 se znovu neplánuje
     * a součet oprávek nepřeroste vstupní cenu.
     */
    public function testOpeningAccountingAheadOfTaxAbsorbsCoveredYear(): void
    {
        $ctx = $this->ctx([
            'inputPrice' => 6000000.0,
            'taxGroup' => 5,
            'putIntoUseDate' => '2022-04-01',
            'openingTaxYears' => 4,
            'openingTaxAmount' => 696000.0,
            'openingAccMonths' => 56,
            'openingAccAmount' => 900000.0,
        ]);
        $strategy = $this->strategy();
        $tax = $this->byYear((new TaxStraightLineStrategy())->plan($ctx));
        $acc = $this->byYear($strategy->plan($ctx));

        self::assertSame(204000.0, $tax[2026]);
        self::assertSame(0.0, $acc[2026]);
        self::assertSame(204000.0, $acc[2027]);
        self::assertSame(0.0, round((float) $strategy->yearRow($ctx, 2026)['amount'], 2));
        self::assertSame(204000.0, round((float) $strategy->yearRow($ctx, 2027)['amount'], 2));
        self::assertSame(6000000.0, round(900000.0 + array_sum($acc), 2));
        $last = $strategy->plan($ctx);
        self::assertSame(0.0, round((float) end($last)['residual_end'], 2));
    }

    /**
     * Převzatá karta, jejíž účetní plán původního programu zaostával za daňovým: počáteční
     * účetní oprávky pokrývají celý rok 2026 (odpisy jsou v převzatém deníku), ale jsou nižší
     * než daňové oprávky po roce 2026. Rok 2026 se nedorovnává, jinak by se jeho odpis
     * v deníku zaúčtoval podruhé; zaostávání 93 000 dožene rok 2027 a plán skončí s nulovou
     * účetní zůstatkovou cenou.
     */
    public function testYearCoveredByOpeningIsNotTopUpAndLagIsCaughtUpNextYear(): void
    {
        $ctx = $this->ctx([
            'inputPrice' => 1200000.0,
            'putIntoUseDate' => '2023-06-15',
            'openingTaxYears' => 3,
            'openingTaxAmount' => 666000.0,
            'openingAccMonths' => 42,
            'openingAccAmount' => 840000.0,
        ]);
        $strategy = $this->strategy();
        $acc = $this->byYear($strategy->plan($ctx));

        self::assertSame(0.0, $acc[2026]);
        self::assertSame(0.0, round((float) $strategy->yearRow($ctx, 2026)['amount'], 2));
        self::assertSame(360000.0, round((float) $strategy->yearRow($ctx, 2027)['amount'], 2));
        self::assertSame(360000.0, $acc[2027]);
        self::assertSame(1200000.0, round(840000.0 + array_sum($acc), 2), 'Oprávky dosáhnou vstupní ceny.');
        $plan = $strategy->plan($ctx);
        self::assertSame(0.0, round((float) end($plan)['residual_end'], 2));
    }

    /**
     * Ručně zadaný historický majetek: počáteční účetní oprávky (3 měsíce) zaostávají za
     * daňovými, žádný rok plánu ale nepokrývají. Zaostávání se nedohání, odpis zůstává
     * daňový (beze změny proti dřívějšímu chování).
     */
    public function testOpeningAccountingBehindTaxKeepsTaxAmounts(): void
    {
        $ctx = $this->ctx(['openingTaxYears' => 1, 'openingTaxAmount' => 127273.0, 'openingAccMonths' => 3, 'openingAccAmount' => 57852.0]);

        self::assertSame(257439.0, $this->byYear($this->strategy()->plan($ctx))[2026]);
        self::assertSame(257439.0, round((float) $this->strategy()->yearRow($ctx, 2026)['amount'], 2));
    }

    /** Potvrzený nebo zaúčtovaný účetní odpis roku dorovnání nepřepočítá. */
    public function testCatchUpNeverRecomputesConfirmedYear(): void
    {
        $ctx = $this->ctx([
            'inputPrice' => 1200000.0,
            'putIntoUseDate' => '2023-06-15',
            'openingTaxYears' => 3,
            'openingTaxAmount' => 666000.0,
            'openingAccMonths' => 42,
            'openingAccAmount' => 840000.0,
            'confirmedEntries' => [['fiscal_year' => 2027, 'kind' => 'accounting', 'amount' => 267000.0, 'full_amount' => 267000.0, 'is_paused' => false, 'is_half' => false]],
        ]);

        self::assertSame(267000.0, round((float) $this->strategy()->yearRow($ctx, 2027)['amount'], 2));
        self::assertSame(267000.0, $this->byYear($this->strategy()->plan($ctx))[2027]);
    }

    public function testTaxMethodNoneYieldsEmptyPlan(): void
    {
        $strategy = new AccountingByTaxStrategy(null);

        self::assertSame([], $strategy->plan($this->ctx()));
        self::assertNull($strategy->yearRow($this->ctx(), 2025));
    }

    public function testCalculatorSelectsStrategyByAccMethod(): void
    {
        $calculator = new DepreciationCalculator();
        $ctx = $this->ctx(['accUsefulLifeMonths' => 60]);

        $byTax = $this->byYear($calculator->plan($ctx, 'straight', 'by_tax')['accounting']);
        $straight = $this->byYear($calculator->plan($ctx, 'straight', 'straight_line')['accounting']);

        self::assertSame(127273.0, $byTax[2025]);
        self::assertSame(57852.0, $straight[2025]); // 3 měsíce × round(1 157 025 / 60)
        self::assertSame(127273.0, round((float) $calculator->accountingYearRow($ctx, 2025, 'by_tax', 'straight')['amount'], 2));
        self::assertSame(57852.0, round((float) $calculator->accountingYearRow($ctx, 2025, 'straight_line', 'straight')['amount'], 2));
    }

    public function testCalculatorRejectsUnknownAccMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DepreciationCalculator())->plan($this->ctx(), 'straight', 'nonsense');
    }
}
