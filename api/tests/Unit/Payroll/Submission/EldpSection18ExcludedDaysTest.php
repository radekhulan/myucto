<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Eldp\EldpExcludedPeriodDeriver;
use PHPUnit\Framework\TestCase;

/**
 * Vyloučené dny podle § 18 odst. 7 zákona č. 187/2006 Sb. (10366 a rozpad
 * 10473–10475).
 *
 * Jiná veličina než vyloučené DOBY podle § 16 odst. 4 zákona č. 155/1995 Sb.:
 * ty krátí osobní vyměřovací základ důchodu, tyhle rozhodné období denního
 * vyměřovacího základu nemocenských dávek. Neplacené volno není omluvným
 * důvodem podle § 16 odst. 4, ale vyloučeným dnem podle § 18 odst. 7 ANO —
 * bez něj počítá ČSSZ nemocenskou i z měsíce, ve kterém zaměstnanec
 * nevydělával.
 */
final class EldpSection18ExcludedDaysTest extends TestCase
{
    public function testUnpaidLeaveBecomesExcusedAbsenceDays(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->deriveSection18(
            [$this->absence(1, 'unpaid_leave', '2026-08-10', '2026-08-12')],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertTrue($derived['derivable']);
        self::assertSame(3, $derived['total']);
        self::assertSame(
            [
                'omluvenaNepritomnost' => 3,
                'pracovniNeschopnost' => 0,
                'vyplaceniDavek' => 0,
            ],
            $derived['components'],
        );
        self::assertSame(3, array_sum($derived['components']));
    }

    /**
     * Neplacené volno přesahující měsíc se ořízne na interval řezu — den mimo
     * dobu pojištění nemá z čeho být vyloučený.
     */
    public function testDaysAreClampedToTheReportedInterval(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->deriveSection18(
            [$this->absence(1, 'unpaid_leave', '2026-07-25', '2026-08-03')],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertTrue($derived['derivable']);
        self::assertSame(3, $derived['total']);
    }

    /**
     * Dovolená ani neomluvená absence vyloučeným dnem nejsou: u dovolené
     * náhrada příjmu náleží, neomluvená absence není OMLUVENÁ nepřítomnost.
     */
    public function testPaidAndUnexcusedAbsencesAreNotExcludedDays(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->deriveSection18(
            [
                $this->absence(1, 'vacation', '2026-08-03', '2026-08-07'),
                $this->absence(2, 'unexcused', '2026-08-10', '2026-08-10'),
                $this->absence(3, 'employee_obstacle', '2026-08-12', '2026-08-12'),
            ],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertTrue($derived['derivable']);
        self::assertSame(0, $derived['total']);
    }

    /**
     * Nemoc rozpad 10474/10475 rozhodnout nedovolí: dělicí čárou je čtrnáctý
     * kalendářní den podpůrčí doby, a ten `payroll_absences` neurčuje.
     * Rozpad se pak nevykazuje vůbec — část by tvrdila, že zbytek je nula.
     */
    public function testSicknessMakesTheBreakdownUnderivable(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->deriveSection18(
            [
                $this->absence(1, 'unpaid_leave', '2026-08-03', '2026-08-04'),
                $this->absence(2, 'dpn', '2026-08-10', '2026-08-20'),
            ],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertFalse($derived['derivable']);
        self::assertSame(['dpn'], $derived['undecidable_types']);
    }

    /**
     * Rodičovská je omluvená nepřítomnost bez náhrady příjmu, ale současně
     * náhradní doba pojištění hodnocená mimo hlášení; doložený způsob zápisu
     * do 10473 repozitář nemá, takže se fail-closed mlčí.
     */
    public function testParentalLeaveMakesTheBreakdownUnderivable(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->deriveSection18(
            [$this->absence(1, 'parental', '2026-08-01', '2026-08-31')],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertFalse($derived['derivable']);
        self::assertSame(['parental'], $derived['undecidable_types']);
    }

    /** @return array<string,mixed> */
    private function absence(int $id, string $type, string $from, string $to): array
    {
        return [
            'id' => $id,
            'absence_type' => $type,
            'date_from' => $from,
            'date_to' => $to,
        ];
    }
}
