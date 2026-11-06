<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPost20GuideService;
use PHPUnit\Framework\TestCase;

/**
 * Průvodce po 20. dni: formulář za měsíc, který neměl být hlášen a nesl
 * příjem nebo pojistné, nulovou anulací opravit nejde (fail-closed).
 * Účetní volí variantu podle Pravidel podání 1.4.6, tabulka typových
 * situací (str. 115-117): VAR 1 plnění vráceno (oprava běhu a obsahová
 * oprava), VAR 2 plnění nevráceno (Odložený příjem, zatím ručně).
 */
final class JmhzPost20GuideActionTest extends TestCase
{
    private const NOT_ZERO = ['code' => 'jmhz_zero_amendment_original_not_zero', 'message' => 'příjem'];

    public function testFormWithIncomeOutsideEmploymentOffersVariants(): void
    {
        self::assertSame(
            'choose_variant',
            JmhzPost20GuideService::monthAction([], 'outside', false, self::NOT_ZERO),
        );
        self::assertSame(
            ['returned', 'not_returned'],
            array_column(JmhzPost20GuideService::variants(), 'key'),
        );
        self::assertSame(
            [true, false],
            array_column(JmhzPost20GuideService::variants(), 'supported'),
        );
    }

    public function testOtherSituationsKeepTheirAction(): void
    {
        self::assertSame('annul', JmhzPost20GuideService::monthAction([], 'outside', true, null));
        self::assertSame('amended', JmhzPost20GuideService::monthAction([['submission_id' => 1]], 'outside', false, self::NOT_ZERO));
        self::assertSame('review', JmhzPost20GuideService::monthAction([], 'partial', false, self::NOT_ZERO));
        self::assertSame(
            'manual',
            JmhzPost20GuideService::monthAction(
                [],
                'outside',
                false,
                ['code' => 'jmhz_zero_amendment_scenario_unsupported', 'message' => 'scénář'],
            ),
        );
        self::assertSame(
            'manual',
            JmhzPost20GuideService::monthAction(
                [],
                'inside',
                false,
                ['code' => 'jmhz_zero_amendment_employment_active_in_month', 'message' => 'trvá'],
            ),
        );
    }
}
