<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\ImportYears;
use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Pohoda\PohodaImportJobService;
use PHPUnit\Framework\TestCase;

/**
 * Plán převodu vybraných roků z POHODY a roky z parametrů jobu: agendy vzestupně, pozdější
 * rok agendy jen s rokem agendy, nevybrané pozdější roky se přeskočí.
 */
final class PohodaImportJobPlanTest extends TestCase
{
    private const ICO = '12345678';

    public function testNotSelectedLaterYearIsSkipped(): void
    {
        $meta = self::meta([2025 => [2026]]);
        self::assertSame([['year' => 2025, 'skip' => [2026], 'later' => [2026]]], self::plan($meta, [2025]));
        self::assertSame([['year' => 2025, 'skip' => [], 'later' => [2026]]], self::plan($meta, [2025, 2026]));
    }

    public function testLaterYearAloneIsRefused(): void
    {
        $this->expectException(PohodaException::class);
        $this->expectExceptionMessage('jen spolu s rokem agendy');
        self::plan(self::meta([2025 => [2026]]), [2026]);
    }

    public function testNothingSelectedIsRefused(): void
    {
        $this->expectException(PohodaException::class);
        self::plan(self::meta([2025 => []]), []);
    }

    public function testYearWithOwnAgendaRunsOnItsOwn(): void
    {
        $meta = self::meta([2025 => [2026], 2026 => []]);
        self::assertSame([['year' => 2026, 'skip' => [], 'later' => []]], self::plan($meta, [2026]));
        self::assertSame([2025, 2026], array_column(self::plan($meta, [2025, 2026]), 'year'));
        self::assertSame([2026], self::plan($meta, [2025])[0]['skip']);
    }

    public function testAgendaOfOtherCompanyIsNotPlanned(): void
    {
        $meta = self::meta([2025 => []]);
        $meta['agendas'][0]['ico'] = '87654321';
        $this->expectException(PohodaException::class);
        self::plan($meta, [2025]);
    }

    public function testPayrollOnlyAgendaIsRefusedForAccounting(): void
    {
        $meta = self::meta([2025 => []]);
        $meta['agendas'][0]['has_accounting'] = false;
        $meta['agendas'][0]['has_payroll'] = true;
        self::assertSame([['year' => 2025, 'skip' => [], 'later' => []]], PohodaImportJobService::plan($meta, self::ICO, [2025], true, 1, str_repeat('a', 32)));
        try {
            self::plan($meta, [2025]);
            self::fail('Účetnictví z agendy jen se mzdami nejde převést.');
        } catch (PohodaException $e) {
            self::assertSame('invalid_kind', $e->errorCode);
        }
    }

    /** Běh účetnictví dostane přehled mezd své agendy: mzdy, které se nepřevedou, zapíše do protokolu. */
    public function testAgendaCarriesPayrollSummaryForAccountingRun(): void
    {
        $payroll = ['employees' => 4, 'months' => 10, 'first' => '2026-01', 'last' => '2026-10'];
        $meta = ['agendas' => [['dir' => self::ICO . '_2026', 'ico' => self::ICO, 'year' => 2026, 'has_accounting' => true,
            'has_payroll' => true, 'payroll' => $payroll, 'counts' => ['later_years' => []]]]];

        self::assertSame($payroll, PohodaImportJobService::agenda($meta, self::ICO, 2026)['payroll'] ?? null);
        self::assertNull(PohodaImportJobService::agenda(self::meta([2025 => []]), self::ICO, 2025)['payroll'] ?? null);
    }

    public function testYearsFromParamsAreAscendingAndKeepLegacyYear(): void
    {
        self::assertSame([2025, 2026], ImportYears::fromParams(['years' => ['2026', 2025, 2025], 'year' => 2024]));
        self::assertSame([2024], ImportYears::fromParams(['year' => '2024']));
        self::assertSame([], ImportYears::fromParams([]));
        self::assertTrue(ImportYears::validBody(['year' => 2024]));
        self::assertTrue(ImportYears::validBody(['years' => [2024, '2025']]));
        self::assertFalse(ImportYears::validBody(['years' => '2024']));
        self::assertFalse(ImportYears::validBody(['years' => [2024, 'x']]));
        self::assertFalse(ImportYears::validBody(['years' => [0]]));
    }

    public function testWorstStatusWins(): void
    {
        self::assertSame('completed', ImportYears::worstStatus(['completed', 'completed']));
        self::assertSame('completed_with_warnings', ImportYears::worstStatus(['completed', 'completed_with_warnings']));
        self::assertSame('cancelled', ImportYears::worstStatus(['completed_with_warnings', 'cancelled']));
        self::assertSame('failed', ImportYears::worstStatus(['completed', 'failed']));
        self::assertSame('failed', ImportYears::worstStatus([]));
    }

    /**
     * @param array<int,list<int>> $agendas rok agendy => pozdější roky v jejím deníku
     * @return array<string,mixed>
     */
    private static function meta(array $agendas): array
    {
        $out = [];
        foreach ($agendas as $year => $later) {
            $out[] = ['dir' => self::ICO . '_' . $year, 'ico' => self::ICO, 'year' => $year, 'has_accounting' => true, 'has_payroll' => false,
                'counts' => ['later_years' => $later]];
        }
        return ['agendas' => $out];
    }

    /**
     * @param array<string,mixed> $meta
     * @param list<int> $years
     * @return list<array{year:int,skip:list<int>,later:list<int>}>
     */
    private static function plan(array $meta, array $years): array
    {
        return PohodaImportJobService::plan($meta, self::ICO, $years, false, 1, str_repeat('a', 32));
    }
}
