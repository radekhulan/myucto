<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Eldp\EldpPensionAgeCode;
use PHPUnit\Framework\TestCase;

/** Jediné pravidlo kódu ELDP „D" pro roční evidenční list i měsíční hlášení JMHZ. */
final class EldpPensionAgeCodeTest extends TestCase
{
    /**
     * Kód D jen od dovršení důchodového věku (§ 29 odst. 1 nebo 3, Zadání
     * ELDP12 v1.04); předčasný starobní důchod ho nezakládá.
     */
    public function testCodeStartsOnlyOnReachingPensionAge(): void
    {
        self::assertNull(EldpPensionAgeCode::codeFrom([]));
        self::assertNull(EldpPensionAgeCode::codeFrom(['pension_age_reached_on' => null, 'early_pension_from' => null]));
        self::assertNull(EldpPensionAgeCode::codeFrom(['pension_age_reached_on' => null, 'early_pension_from' => '2024-11-01']));
        self::assertSame('2025-03-15', EldpPensionAgeCode::codeFrom(['pension_age_reached_on' => '2025-03-15', 'early_pension_from' => null]));
        self::assertSame('2025-03-15', EldpPensionAgeCode::codeFrom(['pension_age_reached_on' => '2025-03-15', 'early_pension_from' => '2024-11-01']));
    }

    public function testPlacementInsideAnInterval(): void
    {
        self::assertSame(EldpPensionAgeCode::PLAIN, EldpPensionAgeCode::placement(null, '2026-07-01', '2026-07-31'));
        self::assertSame(EldpPensionAgeCode::PLAIN, EldpPensionAgeCode::placement('2026-08-01', '2026-07-01', '2026-07-31'));
        self::assertSame(EldpPensionAgeCode::MID_INTERVAL, EldpPensionAgeCode::placement('2026-07-31', '2026-07-01', '2026-07-31'));
        self::assertSame(EldpPensionAgeCode::PENSION_AGE, EldpPensionAgeCode::placement('2026-07-01', '2026-07-01', '2026-07-31'));
        self::assertSame(EldpPensionAgeCode::PENSION_AGE, EldpPensionAgeCode::placement('2025-01-01', '2026-07-01', '2026-07-31'));
    }

    /**
     * Odečtené doby po dovršení věku. Předčasný důchod je neruší; plný
     * důchod je ruší až od roku 2025 a měsíc zahájení výplaty je ještě nese
     * (Metodická pomůcka ELDP, příklady 8 a 8a).
     */
    public function testDeductedPeriodsAfterPensionAge(): void
    {
        $pension = static fn (?string $age, ?string $early = null, ?string $full = null): array => [
            'pension_age_reached_on' => $age,
            'early_pension_from' => $early,
            'full_pension_paid_from' => $full,
        ];

        self::assertTrue(EldpPensionAgeCode::deductsPeriods($pension('2025-04-01'), '2025-05-01', '2025-05-31'));
        self::assertTrue(EldpPensionAgeCode::deductsPeriods($pension('2025-05-01'), '2025-05-01', '2025-05-31'));
        self::assertFalse(EldpPensionAgeCode::deductsPeriods($pension('2025-05-02'), '2025-05-01', '2025-05-31'));
        self::assertFalse(EldpPensionAgeCode::deductsPeriods($pension(null, '2024-09-01'), '2025-05-01', '2025-05-31'));
        // Předčasný důchodce po dovršení věku bez plné výplaty (§ 36 odst. 3, § 34 odst. 3 a 4).
        self::assertTrue(EldpPensionAgeCode::deductsPeriods($pension('2025-04-01', '2023-01-01'), '2025-05-01', '2025-05-31'));
        self::assertTrue(EldpPensionAgeCode::deductsPeriods($pension('2025-04-01', '2025-06-01'), '2025-05-01', '2025-05-31'));
        // Plný důchod od května: květen ještě odečítá (příklad 8a), červen už ne.
        self::assertTrue(EldpPensionAgeCode::deductsPeriods($pension('2025-03-29', null, '2025-05'), '2025-05-01', '2025-05-31'));
        self::assertFalse(EldpPensionAgeCode::deductsPeriods($pension('2025-03-29', null, '2025-05'), '2025-06-01', '2025-06-30'));
        self::assertTrue(EldpPensionAgeCode::deductsPeriods($pension('2025-04-01', null, '2025-06'), '2025-05-01', '2025-05-31'));
        // Do roku 2024 vedl odečtené doby i pracující poživatel plného důchodu (příklad 8).
        self::assertTrue(EldpPensionAgeCode::deductsPeriods($pension('2018-07-29', null, '2018-08'), '2024-09-01', '2024-09-30'));
    }

    public function testSecondPositionBecomesD(): void
    {
        self::assertSame('1D+', EldpPensionAgeCode::withPensionAge('1++'));
        self::assertSame('AD+', EldpPensionAgeCode::withPensionAge('A++'));
        self::assertSame('DD+', EldpPensionAgeCode::withPensionAge('D++'));
        self::assertSame('1DT', EldpPensionAgeCode::withPensionAge('1+T'));
    }
}
