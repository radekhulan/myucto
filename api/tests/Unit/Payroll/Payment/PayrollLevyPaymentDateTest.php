<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Payment;

use MyInvoice\Service\Payroll\Deadline\PayrollLevyPaymentDate;
use PHPUnit\Framework\TestCase;

/**
 * P-08 — datum v příkazu k úhradě odvodu.
 *
 * Lhůta podle § 9 odst. 2 zák. 589/1992 Sb. je splněna PŘIPSÁNÍM na účet
 * instituce, ne podáním příkazu. Příkaz proto musí odejít o rezervu dřív,
 * jinak je firma u každého měsíčního odvodu systematicky v prodlení.
 */
final class PayrollLevyPaymentDateTest extends TestCase
{
    public function testMovesOrderOneWorkingDayBeforeTheDeadline(): void
    {
        // Pondělí 20. 7. 2026 → příkaz v pátek 17. 7. 2026 (pátek 17. je
        // pracovní den, sobota/neděle mezi tím se nepočítají).
        self::assertSame(
            '2026-07-17',
            PayrollLevyPaymentDate::forDueOn('2026-07-20'),
        );
    }

    public function testSkipsWeekendWhenSteppingBack(): void
    {
        // Pondělí 22. 6. 2026 (posunutý termín ze soboty 20. 6.) →
        // příkaz v pátek 19. 6. 2026.
        self::assertSame(
            '2026-06-19',
            PayrollLevyPaymentDate::forDueOn('2026-06-22'),
        );
    }

    public function testSkipsPublicHolidayWhenSteppingBack(): void
    {
        // Úterý 6. 1. 2026: den zpět je pondělí 5. 1. (pracovní).
        self::assertSame(
            '2026-01-05',
            PayrollLevyPaymentDate::forDueOn('2026-01-06'),
        );
        // Čtvrtek 2. 7. 2026: 1. 7. je pracovní středa.
        self::assertSame(
            '2026-07-01',
            PayrollLevyPaymentDate::forDueOn('2026-07-02'),
        );
        // Pondělí 6. 7. 2026 je svátek (Mistr Jan Hus) — krok zpět z úterý
        // 7. 7. ho musí přeskočit až na pátek 3. 7.
        self::assertSame(
            '2026-07-03',
            PayrollLevyPaymentDate::forDueOn('2026-07-07'),
        );
    }

    public function testNonWorkingDeadlineFallsBackToAWorkingDayFirst(): void
    {
        // Sobota 20. 6. 2026 jako vstup (nezposunutý termín) → nejdřív zpět
        // na pátek 19. 6., pak rezerva → čtvrtek 18. 6.
        self::assertSame(
            '2026-06-18',
            PayrollLevyPaymentDate::forDueOn('2026-06-20'),
        );
    }

    public function testZeroLeadKeepsTheDeadlineItself(): void
    {
        self::assertSame(
            '2026-07-20',
            PayrollLevyPaymentDate::forDueOn('2026-07-20', 0),
        );
    }

    public function testRejectsInvalidDeadline(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayrollLevyPaymentDate::forDueOn('2026-02-30');
    }

    public function testOnlyLevyKindsShiftTheOrderDate(): void
    {
        foreach ([
            'health_insurance',
            'social_insurance',
            'advance_tax',
            'withholding_tax',
        ] as $kind) {
            self::assertTrue(
                PayrollLevyPaymentDate::isLevyLiabilityKind($kind),
                $kind,
            );
            self::assertNotNull(
                PayrollLevyPaymentDate::levyForLiabilityKind($kind),
            );
        }
        // Čistá mzda má v `due_on` datum výplaty; předsouvat ho není na místě.
        foreach (['net_wage', 'enforcement', 'insolvency', 'risky_savings'] as $kind) {
            self::assertFalse(
                PayrollLevyPaymentDate::isLevyLiabilityKind($kind),
                $kind,
            );
            self::assertNull(
                PayrollLevyPaymentDate::levyForLiabilityKind($kind),
            );
        }
    }
}
