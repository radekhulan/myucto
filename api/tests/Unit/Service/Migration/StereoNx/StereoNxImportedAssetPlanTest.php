<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\StereoNx\StereoNxAssets;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxIntangibleTables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../Fixtures/StereoNx/SyntheticStereoNxIntangibleTables.php';

final class StereoNxImportedAssetPlanTest extends TestCase
{
    #[DataProvider('calendars')]
    public function testExactSourcePlanPreservesOpeningAndUnequalFinalMonth(FiscalCalendar $calendar, array $months): void
    {
        $plan = $this->plan(SyntheticStereoNxIntangibleTables::tables($calendar), $calendar);
        self::assertSame(1, $plan['counts']['assets_ready'], json_encode($plan['warnings']));
        self::assertSame(0, $plan['counts']['assets_skipped']);
        $record = $plan['records']['assets'][0];
        $card = $record['card'];
        self::assertSame('in_use', $card['status']);
        self::assertSame('intangible', $card['kind']);
        self::assertSame('by_accounting', $card['tax_method']);
        self::assertSame('straight_line', $card['acc_method']);
        self::assertSame(20, $card['opening_acc_months']);
        self::assertSame(2, $card['opening_tax_years']);
        self::assertSame(20_000.0, $card['opening_acc_amount']);
        self::assertSame(20_000.0, $card['opening_tax_amount']);
        self::assertSame(24, $card['acc_useful_life_months']);
        self::assertSame(['version' => 1, 'source_program' => 'Stereo NX',
            'source_tax_method' => 'M', 'source_accounting_method' => 'R',
            'accounting' => array_map(static fn (string $month, int $amount): array => ['month' => $month, 'amount_cents' => $amount], $months, [100000, 100000, 100000, 100007]),
            'tax' => [['fiscal_year' => 2026, 'amount_cents' => 400007]]], $record['imported_depreciation_plan']);
        self::assertCount(24, $record['source_depreciation_plan']['accounting']);
        self::assertCount(3, $record['source_depreciation_plan']['tax']);
        self::assertSame([], $plan['warnings']);
    }

    public static function calendars(): array
    {
        return [
            'calendar' => [FiscalCalendar::calendar(), ['2026-01', '2026-02', '2026-03', '2026-04']],
            'fiscal_august' => [new FiscalCalendar(8), ['2026-08', '2026-09', '2026-10', '2026-11']],
        ];
    }

    public function testFingerprintIncludesExactFutureAmountsEvenWhenAnnualTotalDoesNotChange(): void
    {
        $tables = SyntheticStereoNxIntangibleTables::tables();
        $original = $this->plan($tables);
        self::assertCount(1, $original['records']['assets']);
        $tables['JUcOdpisy'][20]['Castka'] += 0.10;
        $tables['JUcOdpisy'][23]['Castka'] -= 0.10;
        $changed = $this->plan($tables);
        self::assertCount(1, $changed['records']['assets']);
        self::assertNotSame($original['records']['assets'][0]['source_hash'], $changed['records']['assets'][0]['source_hash']);
        self::assertSame(100010, $changed['records']['assets'][0]['imported_depreciation_plan']['accounting'][0]['amount_cents']);
        self::assertSame(99997, $changed['records']['assets'][0]['imported_depreciation_plan']['accounting'][3]['amount_cents']);
    }

    #[DataProvider('unsupportedCases')]
    public function testUnverifiedRegimesAndSchedulesAreSkipped(string $case): void
    {
        $tables = SyntheticStereoNxIntangibleTables::tables();
        switch ($case) {
            case 'tangible': $tables['JMajetek'][0]['Typ'] = 'H'; break;
            case 'old_regime': $tables['JMajetek'][0]['DatumPorizeni'] = '2020-04-10'; break;
            case 'other_accounting_method': $tables['JMajetek'][0]['ZpusobUcOdepisovani'] = 'Z'; break;
            case 'tax_total': $tables['JDanOdpisy'][2]['Castka'] += 0.01; break;
            case 'duplicate_month': $tables['JUcOdpisy'][] = $tables['JUcOdpisy'][23]; break;
            case 'missing_month': unset($tables['JUcOdpisy'][21]); break;
            case 'applied_gap': $tables['JUcOdpisy'][4]['DatumUplatneni'] = null; break;
            case 'partial_tax_year':
                $tables['JUcOdpisy'][20]['DatumUplatneni'] = $tables['JUcOdpisy'][20]['Datum'];
                $tables['JMajetek'][0]['DanoveOdepsano'] = $tables['JMajetek'][0]['UcetneOdepsano'] = 21_000.0;
                break;
            case 'null_amount': $tables['JUcOdpisy'][23]['Castka'] = null; break;
            case 'negative_amount': $tables['JUcOdpisy'][23]['Castka'] = -1_000.07; break;
            case 'subcent_amount': $tables['JUcOdpisy'][23]['Castka'] = 1_000.071; break;
            case 'duplicate_tax_year': $tables['JDanOdpisy'][] = $tables['JDanOdpisy'][2]; break;
            case 'invalid_applied_date': $tables['JDanOdpisy'][0]['DatumUplatneni'] = 'not-a-date'; break;
            case 'technical_improvement': $tables['JTechZhod'][] = ['InvCislo' => 'SYN-INT-EXACT', 'Castka' => 50.0]; break;
        }
        $plan = $this->plan($tables);
        self::assertSame(0, $plan['counts']['assets_ready'], $case);
        self::assertSame(1, $plan['counts']['assets_skipped'], $case);
        self::assertNotEmpty($plan['warnings'], $case);
    }

    public static function unsupportedCases(): array
    {
        return array_map(static fn (string $case): array => [$case], ['tangible', 'old_regime',
            'other_accounting_method', 'tax_total', 'duplicate_month', 'missing_month', 'applied_gap',
            'partial_tax_year', 'null_amount', 'negative_amount', 'subcent_amount', 'duplicate_tax_year',
            'invalid_applied_date', 'technical_improvement']);
    }

    private function plan(array $tables, ?FiscalCalendar $calendar = null): array
    {
        return StereoNxAssets::fromTables($tables, ['ico' => '00000000'], 0, $calendar);
    }
}
