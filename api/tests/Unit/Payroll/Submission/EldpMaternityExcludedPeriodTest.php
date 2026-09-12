<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Eldp\EldpExcludedPeriodDeriver;
use PHPUnit\Framework\TestCase;

/**
 * Peněžitá pomoc v mateřství ve vyloučených dobách a v době pojištění.
 *
 * § 16 odst. 4 věta třetí písm. a) zákona č. 155/1995 Sb.: vyloučenou dobou
 * jsou „doby před porodem, po kterou nebyla vykonávána výdělečná činnost
 * z důvodu těhotenství, nejdříve však od začátku osmého týdne před očekávaným
 * dnem porodu do dne, který bezprostředně předcházel dni porodu". Týž odkaz
 * dělá z předporodní části omluvný důvod § 11 odst. 2.
 *
 * Syntetický případ: očekávaný porod 20. 6. 2026, začátek osmého týdne před
 * ním 25. 4. 2026.
 */
final class EldpMaternityExcludedPeriodTest extends TestCase
{
    private const EXPECTED = '2026-06-20';

    public function testBirthInsideTheMonthCountsOnlyTheDaysBeforeIt(): void
    {
        $derived = $this->derive($this->ppm('2026-05-01', '2026-11-30', '2026-06-15'), '2026-06-01', '2026-06-30');

        self::assertSame([], $derived['blockers']);
        self::assertSame(14, $derived['components']['penezitaPomocMaterstvi']);
        self::assertSame(14, $derived['total']);
        self::assertSame('2026-06-01', $derived['provenance'][0]['counted_from']);
        // Den porodu už vyloučenou dobou není.
        self::assertSame('2026-06-14', $derived['provenance'][0]['counted_to']);
        self::assertSame('penezitaPomocMaterstvi', $derived['provenance'][0]['attribute']);
    }

    public function testExcludedPeriodStartsNoEarlierThanTheEighthWeekBeforeExpectedBirth(): void
    {
        $derived = $this->derive($this->ppm('2026-04-20', '2026-11-30', null), '2026-04-01', '2026-04-30');

        self::assertSame([], $derived['blockers']);
        self::assertSame(6, $derived['components']['penezitaPomocMaterstvi']);
        self::assertSame('2026-04-25', $derived['provenance'][0]['counted_from']);
    }

    /**
     * Nevyplněný den porodu = do konce vykazovaného intervalu k porodu
     * nedošlo. Platí pro měsíc, který končí před očekávaným dnem porodu.
     */
    public function testMonthBeforeExpectedBirthCountsWholeWithoutRecordedBirth(): void
    {
        $derived = $this->derive($this->ppm('2026-05-01', '2026-11-30', null), '2026-05-01', '2026-05-31');

        self::assertSame([], $derived['blockers']);
        self::assertSame(31, $derived['components']['penezitaPomocMaterstvi']);
    }

    /**
     * Měsíc, který na očekávaný den porodu sahá, bez dne porodu nerozhodne.
     * Zapomenutý den porodu by jinak vykázal jako vyloučenou dobu i dny po
     * porodu a nadhodnotil osobní vyměřovací základ.
     */
    public function testMonthReachingExpectedBirthWithoutRecordedBirthBlocks(): void
    {
        $derived = $this->derive($this->ppm('2026-05-01', '2026-11-30', null), '2026-06-01', '2026-06-30');

        self::assertSame(0, $derived['total']);
        self::assertSame('eldp_ppm_childbirth_missing', $derived['blockers'][0]['code']);
        self::assertSame(9100, $derived['blockers'][0]['detail']['absence_id']);
        self::assertStringContainsString('Doplňte u nepřítomnosti den porodu', $derived['blockers'][0]['message']);
    }

    public function testMissingExpectedBirthBlocks(): void
    {
        $absence = $this->ppm('2026-05-01', '2026-11-30', null);
        unset($absence['expected_childbirth_date']);

        $derived = $this->derive($absence, '2026-05-01', '2026-05-31');

        self::assertSame('eldp_ppm_expected_childbirth_missing', $derived['blockers'][0]['code']);
        self::assertSame(0, $derived['total']);
    }

    public function testMonthAfterBirthHasNoExcludedDays(): void
    {
        $derived = $this->derive($this->ppm('2026-05-01', '2026-11-30', '2026-06-15'), '2026-07-01', '2026-07-31');

        self::assertSame([], $derived['blockers']);
        self::assertSame(0, $derived['total']);
        self::assertSame([], $derived['provenance']);
    }

    /** Převzetí dítěte do péče po porodu (§ 34 odst. 1 písm. c) zákona č. 187/2006 Sb.). */
    public function testMaternityStartingAfterBirthHasNoExcludedDays(): void
    {
        $derived = $this->derive($this->ppm('2026-07-01', '2026-11-30', '2026-06-15'), '2026-07-01', '2026-07-31');

        self::assertSame([], $derived['blockers']);
        self::assertSame(0, $derived['total']);
    }

    public function testPreBirthOnlyMonthWithoutIncomeStaysInsured(): void
    {
        self::assertSame(
            EldpExcludedPeriodDeriver::MONTH_INSURED,
            EldpExcludedPeriodDeriver::insuranceMonthStatus(
                [$this->ppm('2026-05-01', '2026-11-30', null)],
                0,
                '2026-05-01',
                '2026-05-31',
            ),
        );
    }

    public function testPostBirthOnlyMonthWithoutIncomeIsOutsideInsurance(): void
    {
        self::assertSame(
            EldpExcludedPeriodDeriver::MONTH_OUTSIDE_INSURANCE,
            EldpExcludedPeriodDeriver::insuranceMonthStatus(
                [$this->ppm('2026-05-01', '2026-11-30', '2026-06-15')],
                0,
                '2026-07-01',
                '2026-07-31',
            ),
        );
    }

    public function testBirthMonthWithoutIncomeIsMixed(): void
    {
        self::assertSame(
            EldpExcludedPeriodDeriver::MONTH_MIXED,
            EldpExcludedPeriodDeriver::insuranceMonthStatus(
                [$this->ppm('2026-05-01', '2026-11-30', '2026-06-15')],
                0,
                '2026-06-01',
                '2026-06-30',
            ),
        );
    }

    public function testPostBirthMaternityWithUnpaidLeaveStaysOutsideInsurance(): void
    {
        self::assertSame(
            EldpExcludedPeriodDeriver::MONTH_OUTSIDE_INSURANCE,
            EldpExcludedPeriodDeriver::insuranceMonthStatus(
                [
                    $this->ppm('2026-05-01', '2026-07-20', '2026-06-15'),
                    [
                        'id' => 9101,
                        'absence_type' => 'unpaid_leave',
                        'date_from' => '2026-07-21',
                        'date_to' => '2026-07-31',
                    ],
                ],
                0,
                '2026-07-01',
                '2026-07-31',
            ),
        );
    }

    public function testIncomeKeepsEveryMaternityMonthInsured(): void
    {
        self::assertSame(
            EldpExcludedPeriodDeriver::MONTH_INSURED,
            EldpExcludedPeriodDeriver::insuranceMonthStatus(
                [$this->ppm('2026-05-01', '2026-11-30', '2026-06-15')],
                100,
                '2026-07-01',
                '2026-07-31',
            ),
        );
    }

    /**
     * @param array<string,mixed> $absence
     * @return array<string,mixed>
     */
    private function derive(array $absence, string $from, string $to): array
    {
        return (new EldpExcludedPeriodDeriver())->derive([$absence], $from, $to, substr($from, 0, 7));
    }

    /** @return array<string,mixed> */
    private function ppm(string $from, string $to, ?string $childbirth): array
    {
        return [
            'id' => 9100,
            'absence_type' => 'ppm',
            'date_from' => $from,
            'date_to' => $to,
            'expected_childbirth_date' => self::EXPECTED,
            'childbirth_date' => $childbirth,
        ];
    }
}
