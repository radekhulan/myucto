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
    public function testPaidHolidayTimeOffDoesNotCreateExcludedDaysOrPeriods(): void
    {
        $absences = [$this->absence(31, 'holiday_compensatory_time_off', '2026-08-10', '2026-08-12')];
        $deriver = new EldpExcludedPeriodDeriver();
        $section18 = $deriver->deriveSection18($absences, '2026-08-01', '2026-08-31');
        self::assertTrue($section18['derivable']);
        self::assertSame(0, $section18['total']);
        $section16 = $deriver->derive($absences, '2026-08-01', '2026-08-31', '2026-08');
        self::assertSame([], $section16['blockers']);
        self::assertSame(0, $section16['total']);
    }
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
     * Pracovní volno bez náhrady mzdy (výkon veřejné funkce, neplacená
     * překážka) je omluvená nepřítomnost bez náhrady příjmu — vyloučený den
     * § 18 odst. 7 stejně jako neplacené volno. Vyloučenou DOBOU podle § 16
     * odst. 4 písm. a) ale není a měsíc bez příjmu dobou pojištění nezůstane.
     */
    public function testUnpaidPublicFunctionAndObstacleAreExcusedAbsenceDays(): void
    {
        $absences = [
            $this->absence(1, 'public_function', '2026-08-10', '2026-08-11'),
            $this->absence(2, 'employee_obstacle_unpaid', '2026-08-13', '2026-08-13'),
        ];
        $deriver = new EldpExcludedPeriodDeriver();

        $section18 = $deriver->deriveSection18($absences, '2026-08-01', '2026-08-31');
        self::assertTrue($section18['derivable']);
        self::assertSame(3, $section18['components']['omluvenaNepritomnost']);

        $excluded = $deriver->derive($absences, '2026-08-01', '2026-08-31', '2026-08');
        self::assertSame([], $excluded['blockers']);
        self::assertSame(0, $excluded['total']);

        self::assertSame(
            EldpExcludedPeriodDeriver::MONTH_OUTSIDE_INSURANCE,
            EldpExcludedPeriodDeriver::insuranceMonthStatus($absences, 0, '2026-08-01', '2026-08-31'),
        );
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
     * Nemoc zmrazená dřív, než snapshot nesl okno náhrady mzdy: dělicí čáru
     * 10474/10475 nemá odkud vzít, rozpad se nevykazuje vůbec — část by
     * tvrdila, že zbytek je nula.
     */
    public function testSicknessWithoutFrozenWindowMakesTheBreakdownUnderivable(): void
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
     * DPN přes okno náhrady mzdy (§ 192 ZP): prvních 14 kalendářních dnů
     * s náhradou → 10474, dny za oknem s potvrzeným nárokem na nemocenské
     * → 10475 (pokyny MPSV k 10474 a 10475).
     */
    public function testSicknessSplitsAtTheCompensationWindow(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->deriveSection18(
            [$this->sickness(1, '2026-08-06', '2026-08-31', '2026-08-06', '2026-08-19', true)],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertTrue($derived['derivable']);
        self::assertSame(
            ['omluvenaNepritomnost' => 0, 'pracovniNeschopnost' => 14, 'vyplaceniDavek' => 12],
            $derived['components'],
        );
        self::assertSame(26, $derived['total']);
    }

    /**
     * Pokračující DPN z minulého měsíce: okno je u začátku nemoci, v novém
     * měsíci jsou všechny dny nemocenskou; 10474 nikdy nepřeroste okno.
     */
    public function testContinuingSicknessCountsOnlyTheRestOfTheWindow(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->deriveSection18(
            [$this->sickness(1, '2026-07-25', '2026-08-31', '2026-07-25', '2026-08-07', true)],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertSame(7, $derived['components']['pracovniNeschopnost']);
        self::assertSame(24, $derived['components']['vyplaceniDavek']);
    }

    /**
     * DPN bez nároku na nemocenské (nepotvrzená účast) nepatří za oknem nikam:
     * pokyny k 10473 dny DPN bez nároku výslovně vylučují a nemocenské se
     * nevyplácí, takže to není ani 10475. První den odpracovaný celý (okno
     * od druhého dne) vyloučeným dnem není.
     */
    public function testSicknessWithoutEligibilityCountsOnlyTheWindow(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->deriveSection18(
            [$this->sickness(1, '2026-08-05', '2026-08-31', '2026-08-06', '2026-08-19', false)],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertTrue($derived['derivable']);
        self::assertSame(
            ['omluvenaNepritomnost' => 0, 'pracovniNeschopnost' => 14, 'vyplaceniDavek' => 0],
            $derived['components'],
        );
    }

    /**
     * Navazující část neschopnosti za vyčerpaným oknem (DPN-01): okno je prázdné,
     * uložené jako den před začátkem části, a všechny její dny jsou nemocenskou
     * (10475). Okno obrácené o víc než den je vadný záznam a rozpad se nevykáže.
     */
    public function testContinuationBeyondExhaustedWindowIsAllBenefitDays(): void
    {
        $deriver = new EldpExcludedPeriodDeriver();
        $continuation = $deriver->deriveSection18(
            [$this->sickness(1, '2026-08-23', '2026-08-26', '2026-08-23', '2026-08-22', true)],
            '2026-08-01',
            '2026-08-31',
        );
        $broken = $deriver->deriveSection18(
            [$this->sickness(1, '2026-08-23', '2026-08-26', '2026-08-23', '2026-08-21', true)],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertTrue($continuation['derivable']);
        self::assertSame(
            ['omluvenaNepritomnost' => 0, 'pracovniNeschopnost' => 0, 'vyplaceniDavek' => 4],
            $continuation['components'],
        );
        self::assertFalse($broken['derivable']);
    }

    /**
     * Peněžitá pomoc v mateřství (i po porodu) a otcovská: celé dny s dávkou
     * → 10475.
     */
    public function testMaternityAndPaternityAreBenefitDays(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->deriveSection18(
            [
                $this->absence(1, 'paternity', '2026-08-03', '2026-08-16'),
                $this->absence(2, 'ppm', '2026-08-20', '2026-12-31')
                    + ['expected_childbirth_date' => '2026-09-01', 'childbirth_date' => null],
            ],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertTrue($derived['derivable']);
        self::assertSame(
            ['omluvenaNepritomnost' => 0, 'pracovniNeschopnost' => 0, 'vyplaceniDavek' => 26],
            $derived['components'],
        );
    }

    /**
     * Ošetřovné jen v podpůrčí době (9 dnů, osamělý zaměstnanec 16 dnů):
     * v ní dny s dávkou (10475), za ní omluvená nepřítomnost bez náhrady
     * příjmu (10473).
     */
    public function testCareSplitsAtTheSupportPeriod(): void
    {
        $deriver = new EldpExcludedPeriodDeriver();
        $common = $deriver->deriveSection18(
            [$this->absence(1, 'ocr', '2026-08-03', '2026-08-14') + ['lone_carer' => false]],
            '2026-08-01',
            '2026-08-31',
        );
        $lone = $deriver->deriveSection18(
            [$this->absence(1, 'ocr', '2026-08-03', '2026-08-21') + ['lone_carer' => true]],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertSame(
            ['omluvenaNepritomnost' => 3, 'pracovniNeschopnost' => 0, 'vyplaceniDavek' => 9],
            $common['components'],
        );
        self::assertSame(
            ['omluvenaNepritomnost' => 3, 'pracovniNeschopnost' => 0, 'vyplaceniDavek' => 16],
            $lone['components'],
        );
    }

    /**
     * Vyloučená doba ošetřování (10360) jen v rozsahu podpůrčí doby: pokyny
     * MPSV „nejvýše však v rozsahu prvních 9 kalendářních dnů … popřípadě
     * prvních 16", dlouhodobé ošetřovné nejdéle 90 dnů. Počítá se od prvního
     * dne nepřítomnosti, i když začala v minulém měsíci.
     */
    public function testCareExcludedPeriodEndsWithTheSupportPeriod(): void
    {
        $deriver = new EldpExcludedPeriodDeriver();
        $common = $deriver->derive(
            [$this->absence(1, 'ocr', '2026-08-03', '2026-08-20') + ['lone_carer' => false]],
            '2026-08-01',
            '2026-08-31',
            '2026-08',
        );
        $continuing = $deriver->derive(
            [$this->absence(1, 'ocr', '2026-07-28', '2026-08-10') + ['lone_carer' => true]],
            '2026-08-01',
            '2026-08-31',
            '2026-08',
        );
        $longTerm = $deriver->derive(
            [$this->absence(1, 'long_term_care', '2026-06-01', '2026-09-30')],
            '2026-08-01',
            '2026-08-31',
            '2026-08',
        );

        self::assertSame(9, $common['components']['osetrovaniClenaRodiny']);
        // 28. 7. + 15 dnů = 12. 8.; v srpnu 1.–10. 8.
        self::assertSame(10, $continuing['components']['osetrovaniClenaRodiny']);
        // 90 dnů od 1. 6. končí 29. 8.
        self::assertSame(29, $longTerm['components']['osetrovaniClenaRodiny']);
    }

    /** @return array<string,mixed> */
    private function sickness(
        int $id,
        string $from,
        string $to,
        string $windowFrom,
        string $windowTo,
        bool $eligible,
    ): array {
        return $this->absence($id, 'dpn', $from, $to) + [
            'compensation_window_from' => $windowFrom,
            'compensation_window_to' => $windowTo,
            'insurance_eligibility_confirmed' => $eligible,
        ];
    }

    /**
     * Rodičovskou dovolenou jmenují Pokyny MPSV k vyplnění MH 1.4.13 u 10473
     * výslovně jako omluvenou nepřítomnost bez náhrady příjmu. Bez vyloučených
     * dnů by ČSSZ počítala nemocenskou po návratu i z měsíců rodičovské.
     */
    public function testParentalLeaveBecomesExcusedAbsenceDays(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->deriveSection18(
            [$this->absence(1, 'parental', '2026-08-01', '2026-08-31')],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertTrue($derived['derivable']);
        self::assertSame(31, $derived['total']);
        self::assertSame(
            [
                'omluvenaNepritomnost' => 31,
                'pracovniNeschopnost' => 0,
                'vyplaceniDavek' => 0,
            ],
            $derived['components'],
        );
    }

    /**
     * Návrat z rodičovské uprostřed měsíce: vyloučené jsou jen dny rodičovské
     * uvnitř měsíce, sečtené s ostatní omluvenou nepřítomností bez náhrady.
     */
    public function testParentalLeaveInPartOfTheMonthCountsOnlyItsDays(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->deriveSection18(
            [
                $this->absence(1, 'parental', '2026-06-15', '2026-08-16'),
                $this->absence(2, 'unpaid_leave', '2026-08-24', '2026-08-25'),
            ],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertTrue($derived['derivable']);
        self::assertSame(18, $derived['total']);
        self::assertSame(18, $derived['components']['omluvenaNepritomnost']);
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
