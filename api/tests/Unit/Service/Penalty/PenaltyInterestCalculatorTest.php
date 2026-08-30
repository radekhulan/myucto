<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Penalty;

use DateTimeImmutable;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Penalty\PenaltyInterestCalculator;
use MyInvoice\Service\Penalty\RepoRateProvider;
use PHPUnit\Framework\TestCase;

/**
 * Úrok z prodlení dle NV č. 351/2013 Sb.: roční sazba = repo ČNB k 1. dni
 * pololetí, ve kterém prodlení VZNIKLO (§ 2, dokonavý vid „došlo k prodlení")
 * + 8 p.b. — tato sazba se FIXUJE na celou dobu prodlení, i když trvá přes
 * další pololetí/pololetí s jinou sazbou. Denní úrok = jistina × sazba × dny /
 * (365|366); segmentace v `$segments` řeší hranici kalendářního roku a změnu
 * jistiny po částečné úhradě, sazbu neovlivňuje.
 */
final class PenaltyInterestCalculatorTest extends TestCase
{
    /** Fake zdroj repo sazby — klíč = 1. den pololetí (přesně jak volá kalkulátor). */
    private function calc(array $ratesByHalfYearStart): PenaltyInterestCalculator
    {
        $provider = new class ($ratesByHalfYearStart) implements RepoRateProvider {
            public function __construct(private array $rates) {}
            public function rateOn(DateTimeImmutable $date): ?float
            {
                return $this->rates[$date->format('Y-m-d')] ?? null;
            }
        };
        // Přirážka je roční daňová konstanta; testy fixují 8 bodů podle § 2 NV 351/2013,
        // aby ověřovaly VÝPOČET, ne obsah číselníku.
        $taxConstants = $this->createStub(TaxConstantsRepository::class);
        $taxConstants->method('penaltyRepoSurchargePoints')->willReturn(8.0);

        return new PenaltyInterestCalculator($provider, $taxConstants);
    }

    public function testSingleHalfYearNonLeap(): void
    {
        // jistina 100000, splatnost 2025-01-10, k 2025-02-09 → 30 dní prodlení
        // repo k 2025-01-01 = 4,00 % → roční 12,00 %; basis 365 (2025 není přestupný)
        // úrok = 100000 × 0,12 × 30 / 365 = 986,30
        $r = $this->calc(['2025-01-01' => 4.00])->compute(
            100000.0,
            new DateTimeImmutable('2025-01-10'),
            new DateTimeImmutable('2025-02-09'),
        );

        self::assertSame(30, $r['total_days']);
        self::assertSame(986.30, $r['total_interest']);
        self::assertSame(8.0, $r['surcharge_points']);
        self::assertCount(1, $r['segments']);
        self::assertSame(4.0, $r['segments'][0]['repo_rate']);
        self::assertSame(12.0, $r['segments'][0]['annual_rate']);
        self::assertSame(365, $r['segments'][0]['day_count_basis']);
        self::assertSame('2025-01-11', $r['segments'][0]['from']);
        self::assertSame('2025-02-09', $r['segments'][0]['to']);
    }

    public function testDelaySpanningHalfYearBoundaryUsesFixedRateFromDelayStart(): void
    {
        // jistina 100000, splatnost 2024-06-15 → prodlení VZNIKÁ 16.6.2024, tj.
        // v H1/2024 → sazba se FIXUJE na repo k 2024-01-01 (6,75 % → roční 14,75 %)
        // pro CELOU dobu prodlení, i když trvá až do 15.8. (přes hranici H1/H2).
        // Repo k 2024-07-01 (12,75 %) se NEPOUŽIJE vůbec — to je podstata opravy
        // Nálezu 1 (dřív se sazba mylně přepočítávala po pololetích).
        //
        // Celé prodlení (16.6.–15.8.) je v jednom kalendářním roce (2024, přestupný)
        // → jeden segment, 61 dní, basis 366.
        // úrok = 100000 × 0,1475 × 61 / 366 = 2458,33
        $r = $this->calc(['2024-01-01' => 6.75, '2024-07-01' => 12.75])->compute(
            100000.0,
            new DateTimeImmutable('2024-06-15'),
            new DateTimeImmutable('2024-08-15'),
        );

        self::assertSame(61, $r['total_days']);
        self::assertCount(1, $r['segments'], 'Sazba se nemění → jen jeden segment (hranice kalendářního roku nepadá do období).');
        self::assertSame(6.75, $r['segments'][0]['repo_rate'], 'Fixovaná sazba H1/2024 (počátek prodlení), NE H2/2024.');
        self::assertSame(14.75, $r['segments'][0]['annual_rate']);
        self::assertSame(366, $r['segments'][0]['day_count_basis']);
        self::assertSame(2458.33, $r['total_interest']);
    }

    public function testDelaySpanningCalendarYearBoundaryKeepsFixedRateButSplitsDayCountBasis(): void
    {
        // jistina 100000, splatnost 2023-12-10 → prodlení vzniká 11.12.2023 (H2/2023)
        // → sazba fixovaná na repo k 2023-07-01 (5,00 % → roční 13,00 %) pro celou
        // dobu prodlení, i když přesahuje do dalšího KALENDÁŘNÍHO ROKU (2024, přestupný).
        // Dny se MUSÍ rozdělit na hranici roku (jiný jmenovatel 365 vs. 366), ale
        // sazba zůstává stejná v obou segmentech.
        //   segment 1 (11.–31.12.2023, 21 dní, basis 365): 100000×0,13×21/365 = 747,95
        //   segment 2 (1.–5.1.2024, 5 dní, basis 366):      100000×0,13×5/366  = 177,60
        //   total_interest = round(747,945205… + 177,595628…, 2) = 925,54
        $r = $this->calc(['2023-07-01' => 5.00])->compute(
            100000.0,
            new DateTimeImmutable('2023-12-10'),
            new DateTimeImmutable('2024-01-05'),
        );

        self::assertSame(26, $r['total_days']);
        self::assertCount(2, $r['segments']);
        self::assertSame(21, $r['segments'][0]['days']);
        self::assertSame(365, $r['segments'][0]['day_count_basis']);
        self::assertSame(5, $r['segments'][1]['days']);
        self::assertSame(366, $r['segments'][1]['day_count_basis']);
        // Sazba IDENTICKÁ v obou segmentech — fixovaná k počátku prodlení (H2/2023).
        self::assertSame(5.0, $r['segments'][0]['repo_rate']);
        self::assertSame(5.0, $r['segments'][1]['repo_rate']);
        self::assertSame(13.0, $r['segments'][0]['annual_rate']);
        self::assertSame(13.0, $r['segments'][1]['annual_rate']);
        self::assertSame(747.95, $r['segments'][0]['interest']);
        self::assertSame(177.60, $r['segments'][1]['interest']);
        self::assertSame(925.54, $r['total_interest']);
    }

    public function testNotOverdueIsNoop(): void
    {
        $r = $this->calc(['2025-01-01' => 4.00])->compute(
            50000.0,
            new DateTimeImmutable('2025-03-10'),
            new DateTimeImmutable('2025-03-10'), // splatnost = dnešek → žádné prodlení
        );
        self::assertSame(0, $r['total_days']);
        self::assertSame(0.0, $r['total_interest']);
        self::assertSame([], $r['segments']);
    }

    public function testBeforeDueDateIsNoop(): void
    {
        $r = $this->calc(['2025-01-01' => 4.00])->compute(
            50000.0,
            new DateTimeImmutable('2025-03-10'),
            new DateTimeImmutable('2025-02-01'),
        );
        self::assertSame(0.0, $r['total_interest']);
    }

    public function testZeroPrincipalIsNoop(): void
    {
        $r = $this->calc(['2025-01-01' => 4.00])->compute(
            0.0,
            new DateTimeImmutable('2025-01-10'),
            new DateTimeImmutable('2025-06-30'),
        );
        self::assertSame(0.0, $r['total_interest']);
    }

    public function testMissingRepoRateThrows(): void
    {
        $this->expectException(\DomainException::class);
        // žádná sazba pro 2025-01-01 (pololetí vzniku prodlení) nastavena
        $this->calc([])->compute(
            100000.0,
            new DateTimeImmutable('2025-01-10'),
            new DateTimeImmutable('2025-02-09'),
        );
    }

    public function testFullYearDelayWithinSameCalendarYearIsSingleSegment(): void
    {
        // Splatnost 2025-01-01 → prodlení vzniká 2.1.2025 (H1/2025) → sazba fixovaná
        // na repo k 2025-01-01 (2,00 % → roční 10,00 %) pro celou dobu prodlení až
        // do 31.12.2025. Celé období je v jednom kalendářním roce → jeden segment.
        // (Repo k 2025-07-01 by se použilo, jen kdyby BYLO jiné A metodika byla
        // plovoucí — právě to Nález 1 opravil; tady je stejné, aby test i historicky
        // ukázal, že výsledná částka se opravou nezměnila, když se sazby shodují.)
        // 364 dní × 10 % / 365 = 9972,60
        $r = $this->calc(['2025-01-01' => 2.00, '2025-07-01' => 2.00])->compute(
            100000.0,
            new DateTimeImmutable('2025-01-01'),
            new DateTimeImmutable('2025-12-31'),
        );
        self::assertSame(364, $r['total_days']);
        self::assertCount(1, $r['segments'], 'Celé prodlení v jednom kalendářním roce → jeden segment (fixní sazba, žádné dělení po pololetích).');
        self::assertSame(364, $r['segments'][0]['days']);
        self::assertSame(9972.60, $r['total_interest']);
    }

    public function testPartialPaymentsReducePrincipalFromFollowingDay(): void
    {
        $r = $this->calc(['2025-01-01' => 4.00])->compute(
            100000.0,
            new DateTimeImmutable('2025-01-10'),
            new DateTimeImmutable('2025-01-30'),
            null,
            [
                ['paid_on' => '2025-01-20', 'amount' => 40000.0],
                ['paid_on' => '2025-01-30', 'amount' => 60000.0],
            ],
        );

        self::assertSame(20, $r['total_days']);
        self::assertCount(2, $r['segments']);
        self::assertSame('2025-01-11', $r['segments'][0]['from']);
        self::assertSame('2025-01-20', $r['segments'][0]['to']);
        self::assertSame('2025-01-21', $r['segments'][1]['from']);
        self::assertSame('2025-01-30', $r['segments'][1]['to']);
        self::assertSame(526.03, $r['total_interest']);
    }
}
