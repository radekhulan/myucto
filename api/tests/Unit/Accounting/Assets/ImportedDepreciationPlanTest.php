<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Assets;

use MyInvoice\Service\Accounting\Assets\DepreciationCalculator;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\DepreciationContext;
use MyInvoice\Service\Accounting\FiscalCalendar;
use PHPUnit\Framework\TestCase;

final class ImportedDepreciationPlanTest extends TestCase
{
    public function testSourceMonthlyAmountsSurvivePlanAndYearCalculation(): void
    {
        $calculator = new DepreciationCalculator();
        $ctx = $this->context();
        $plan = $calculator->plan($ctx, 'by_accounting', 'straight_line');
        self::assertSame([8990.0, 8990.0, 8990.0, 9030.0], array_column($plan['accounting'][0]['months'], 'amount'));
        self::assertSame(36000.0, $plan['tax'][0]['amount']);
        self::assertSame(0.0, $plan['tax'][0]['residual_end']);
        self::assertSame($plan['accounting'][0], $calculator->accountingYearRow($ctx, 2098, 'straight_line', 'by_accounting'));
        self::assertSame($plan['tax'][0], $calculator->taxYearRow($ctx, 'by_accounting', 2098));
        self::assertNull($calculator->taxYearRow($ctx, 'by_accounting', 2097));
    }

    public function testDisposalKeepsExactSourceMonthsThroughDisposalMonth(): void
    {
        $plan = (new DepreciationCalculator())->plan($this->context('2098-02-12'), 'by_accounting', 'straight_line');
        self::assertSame([8990.0, 8990.0], array_column($plan['accounting'][0]['months'], 'amount'));
        self::assertSame(17980.0, $plan['tax'][0]['amount']);
        self::assertSame(18020.0, $plan['tax'][0]['residual_end']);
    }

    public function testFiscalCalendarGroupsSourceMonthsWithoutAssumingJanuary(): void
    {
        $source = $this->source();
        foreach ($source['accounting'] as $i => &$row) $row['month'] = sprintf('2097-%02d', 8 + $i);
        unset($row);
        $source['tax'][0]['fiscal_year'] = 2097;
        $ctx = new DepreciationContext(96000.0, null, 'none', false, false, false,
            '2096-03-05', null, 20, 0.0, 2, 60000.0, 16, 60000.0,
            [], [], new FiscalCalendar(8), $source);
        $plan = (new DepreciationCalculator())->plan($ctx, 'by_accounting', 'straight_line');
        self::assertSame(2097, $plan['tax'][0]['fiscal_year']);
        self::assertSame(36000.0, $plan['tax'][0]['amount']);
        self::assertSame(['2097-08', '2097-09', '2097-10', '2097-11'], array_column($plan['accounting'][0]['months'], 'month'));
    }

    public function testMalformedOrIncompletePlansFailInsteadOfRecalculating(): void
    {
        foreach (['gap', 'duplicate', 'float', 'negative', 'sum', 'year', 'metadata'] as $case) {
            $source = $this->source();
            if ($case === 'gap') $source['accounting'][1]['month'] = '2098-05';
            if ($case === 'duplicate') $source['accounting'][1]['month'] = '2098-01';
            if ($case === 'float') $source['accounting'][0]['amount_cents'] = 899000.0;
            if ($case === 'negative') $source['accounting'][0]['amount_cents'] = -1;
            if ($case === 'sum') $source['accounting'][0]['amount_cents']++;
            if ($case === 'year') $source['tax'][0]['fiscal_year'] = 2097;
            if ($case === 'metadata') $source['source_program'] = '';
            try {
                (new DepreciationCalculator())->plan($this->context(null, $source), 'by_accounting', 'straight_line');
                self::fail('Malformed source plan accepted: ' . $case);
            } catch (AssetException $e) {
                self::assertSame('imported_plan_invalid', $e->errorCode);
            }
        }
    }

    public function testConfirmedYearsKeepMonthlySourceAndRebookingRecalculatesOnlyRequestedYear(): void
    {
        $ctx = $this->context(null, null, [['kind' => 'accounting', 'fiscal_year' => 2098,
            'amount' => 36000.0, 'full_amount' => 36000.0, 'is_paused' => false, 'is_half' => false]]);
        $calculator = new DepreciationCalculator();
        $row = $calculator->plan($ctx, 'by_accounting', 'straight_line')['accounting'][0];
        self::assertSame('confirmed', $row['source']);
        self::assertSame([8990.0, 8990.0, 8990.0, 9030.0], array_column($row['months'], 'amount'));
        $disposal = $this->context('2098-02-12', null, $ctx->confirmedEntries);
        self::assertSame(17980.0, $calculator->accountingYearRow($disposal, 2098, 'straight_line', 'by_accounting')['amount']);
    }

    public function testDisposalCannotRewriteHistoricalOpeningMonths(): void
    {
        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('Vyřazení nesmí zasáhnout');
        (new DepreciationCalculator())->plan($this->context('2097-12-15'), 'by_accounting', 'straight_line');
    }

    public function testRevisionPreservesPrefixAndAllocatesLastCentToFinalMonth(): void
    {
        $source = $this->source();
        $source['revisions'] = [[
            'revision' => 1, 'effective_from' => '2098-02', 'remaining_months' => 3,
            'remaining_amount_cents' => 2701000, 'reason' => 'Nový odhad využití softwaru',
            'user_id' => 7, 'created_at' => '2098-01-15T10:00:00+00:00', 'fiscal_start_month' => 1,
        ]];
        $plan = (new DepreciationCalculator())->plan($this->context(null, $source), 'by_accounting', 'straight_line');
        self::assertSame([8990.0, 9003.33, 9003.33, 9003.34], array_column($plan['accounting'][0]['months'], 'amount'));
        self::assertSame(36000.0, $plan['tax'][0]['amount']);
        self::assertSame(903000, $source['accounting'][3]['amount_cents'], 'Původní poslední měsíc zůstává auditním podkladem.');
    }

    public function testRepeatedRevisionKeepsEarlierRevisedMonthsAndExactAnnualSums(): void
    {
        $source = $this->source();
        $source['revisions'] = [$this->revision(1, '2098-02', 14, 2701000), $this->revision(2, '2099-02', 3, 385864)];
        $plan = (new DepreciationCalculator())->plan($this->context(null, $source), 'by_accounting', 'straight_line');
        self::assertSame([30212.08, 5787.92], array_column($plan['accounting'], 'amount'));
        self::assertSame([1929.28, 1286.21, 1286.21, 1286.22], array_column($plan['accounting'][1]['months'], 'amount'));
        self::assertSame(array_column($plan['accounting'], 'amount'), array_column($plan['tax'], 'amount'));
        self::assertSame(0.0, $plan['tax'][1]['residual_end']);
    }

    public function testRevisionCrossesNonCalendarFiscalBoundaryExactly(): void
    {
        $source = $this->source();
        foreach ($source['accounting'] as $i => &$row) $row['month'] = sprintf('2097-%02d', 8 + $i);
        unset($row);
        $source['tax'][0]['fiscal_year'] = 2097;
        $source['revisions'] = [$this->revision(1, '2097-09', 14, 2701000, 8)];
        $ctx = new DepreciationContext(96000.0, null, 'none', false, false, false,
            '2096-03-05', null, 20, 0.0, 2, 60000.0, 16, 60000.0, [], [], new FiscalCalendar(8), $source);
        $plan = (new DepreciationCalculator())->plan($ctx, 'by_accounting', 'straight_line');
        self::assertSame([2097, 2098], array_column($plan['tax'], 'fiscal_year'));
        self::assertSame([30212.08, 5787.92], array_column($plan['tax'], 'amount'));
        self::assertSame('2098-08', $plan['accounting'][1]['months'][0]['month']);
    }

    public function testMalformedRevisionHistoryCannotChangeAmountOrCalendar(): void
    {
        foreach (['amount', 'order', 'calendar', 'month', 'reason', 'actor'] as $case) {
            $source = $this->source();
            $revision = $this->revision(1, '2098-02', 3, 2701000);
            if ($case === 'amount') $revision['remaining_amount_cents']++;
            if ($case === 'order') $revision['revision'] = 2;
            if ($case === 'calendar') $revision['fiscal_start_month'] = 8;
            if ($case === 'month') $revision['effective_from'] = '2098-13';
            if ($case === 'reason') $revision['reason'] = ' ';
            if ($case === 'actor') $revision['user_id'] = 0;
            $source['revisions'] = [$revision];
            try {
                (new DepreciationCalculator())->plan($this->context(null, $source), 'by_accounting', 'straight_line');
                self::fail('Invalid revision accepted: ' . $case);
            } catch (AssetException $e) { self::assertSame('imported_plan_invalid', $e->errorCode); }
        }
    }

    public function testRevisionHistoryIsBounded(): void
    {
        $source = $this->source();
        $source['revisions'] = array_fill(0, 1001, $this->revision(1, '2098-02', 3, 2701000));
        $this->expectException(AssetException::class);
        (new DepreciationCalculator())->plan($this->context(null, $source), 'by_accounting', 'straight_line');
    }

    private function revision(int $number, string $from, int $months, int $amount, int $fiscalStart = 1): array
    {
        return ['revision' => $number, 'effective_from' => $from, 'remaining_months' => $months,
            'remaining_amount_cents' => $amount, 'reason' => 'Změna odhadu budoucího využití',
            'user_id' => 7, 'created_at' => '2097-01-15T10:00:00+00:00', 'fiscal_start_month' => $fiscalStart];
    }

    private function context(?string $disposal = null, ?array $source = null, array $confirmed = []): DepreciationContext
    {
        return new DepreciationContext(96000.0, null, 'none', false, false, false,
            '2096-08-05', $disposal, 20, 0.0, 2, 60000.0, 16, 60000.0,
            [], $confirmed, FiscalCalendar::calendar(), $source ?? $this->source());
    }

    private function source(): array
    {
        return ['version' => 1, 'source_program' => 'Synthetic source', 'source_tax_method' => 'source-tax',
            'source_accounting_method' => 'source-accounting', 'accounting' => [
                ['month' => '2098-01', 'amount_cents' => 899000], ['month' => '2098-02', 'amount_cents' => 899000],
                ['month' => '2098-03', 'amount_cents' => 899000], ['month' => '2098-04', 'amount_cents' => 903000],
            ], 'tax' => [['fiscal_year' => 2098, 'amount_cents' => 3600000]]];
    }
}
