<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Migration\PayrollTakeoverMonth;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverYear;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpAnnualStatement;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpAnnualStatementBuilder;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpExcludedPeriodDeriver;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementCopyService;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpValidationException;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpXmlValidator;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Pokrytí norem evidenčního listu: krytí vyloučené doby příjmem, odečtené doby
 * u kódu D, základ při přechodu na kód D, plný vyměřovací základ nad roční
 * maximum, typ listu, § 15a zákona o nemocenském pojištění, převzaté měsíce
 * s evidovanými nepřítomnostmi.
 *
 * Všechna data jsou syntetická (firma 7, osoba 11, vztah 101, souběžný vztah
 * 102, 10 000 Kč měsíčně), odkazy na příklady Metodické pomůcky ČSSZ k ELDP.
 */
final class EldpNormCoverageTest extends TestCase
{
    private const SUPPLIER_ID = 7;
    private const EMPLOYEE_ID = 11;
    private const EMPLOYMENT_ID = 101;
    private const CONCURRENT_ID = 102;

    /**
     * Příloha č. 3 bod a), př. 19 a 23: nemoc po celý měsíc a v měsíci
     * zúčtovaný příjem (odměna) — dny se do vyloučených dob nezahrnou.
     * Vztah trvá jen v březnu, takže bez krytí by sekce měla všechny dny
     * vyloučené a přitom základ (logický test 41).
     */
    public function testWholeMonthSicknessWithIncomeIsCoveredAndNotExcluded(): void
    {
        $statement = $this->build([
            $this->revision(2025, 3, start: '2025-03-01', end: '2025-03-31', baseMinor: 500_000, absences: [
                $this->absence(9001, 'dpn', '2025-02-20', '2025-04-10'),
            ]),
        ]);

        $section = $statement->sections()[0];
        self::assertSame(31, $section['insurance_days']);
        self::assertSame(0, $section['excluded_days_total']);
        self::assertSame(5_000, $section['assessment_base_czk']);
        $covered = $statement->payload['monthly_lines'][0]['excluded_days_covered'];
        self::assertSame(EldpExcludedPeriodDeriver::COVERED_WHOLE_MONTH_INCOME, $covered[0]['reason']);
        self::assertSame(31, $covered[0]['days']);
    }

    /** Bod b), př. 20 a 22: omluvný důvod netrval celý měsíc, ke krytí nedochází. */
    public function testPartialMonthSicknessWithIncomeStaysExcluded(): void
    {
        $revisions = $this->months(2025, 1, 12);
        $revisions[3] = $this->revision(2025, 4, absences: [
            $this->absence(9002, 'dpn', '2025-04-02', '2025-04-30'),
        ]);

        $section = $this->build($revisions)->sections()[0];

        self::assertSame(29, $section['excluded_days_total']);
        self::assertSame(365, $section['insurance_days']);
    }

    /** Bod c), př. 23: nemoc ode dne s odpracovanou částí směny je vyloučená až od dalšího dne. */
    public function testSicknessStartingOnPartlyWorkedDayIsExcludedFromTheNextDay(): void
    {
        $revisions = $this->months(2025, 1, 12);
        $revisions[3] = $this->revision(2025, 4, absences: [
            $this->absence(9003, 'dpn', '2025-04-02', '2025-04-30', ['partial_first_minutes' => 240]),
        ]);

        $statement = $this->build($revisions);

        self::assertSame(28, $statement->sections()[0]['excluded_days_total']);
        self::assertSame('2025-04-03', $statement->sections()[0]['excluded_days_provenance'][0]['counted_from']);
    }

    /**
     * Bod e), př. 40: souběžný vztah u téhož zaměstnavatele s příjmem kryje
     * nemoc, kterou souběžný vztah sám neměl. Př. 39: měl-li nemoc i souběžný
     * vztah, vyloučená doba zůstává u obou.
     */
    public function testConcurrentEmploymentIncomeCoversSicknessOnlyWhenItWasNotSickItself(): void
    {
        $sick = [$this->absence(9004, 'dpn', '2025-05-10', '2025-05-20')];
        $covered = $this->months(2025, 1, 12);
        $covered[4] = $this->revision(2025, 5, absences: $sick, concurrent: ['absences' => []]);
        $bothSick = $this->months(2025, 1, 12);
        $bothSick[4] = $this->revision(2025, 5, absences: $sick, concurrent: [
            'absences' => [$this->absence(9005, 'dpn', '2025-05-10', '2025-05-20')],
        ]);

        self::assertSame(0, $this->build($covered)->sections()[0]['excluded_days_total']);
        self::assertSame(11, $this->build($bothSick)->sections()[0]['excluded_days_total']);
    }

    /**
     * Metodická pomůcka, část II a př. 1: měsíce po dosažení maximálního
     * vyměřovacího základu jsou dobou pojištění a základ se do listu uvádí
     * v plné výši. Dřív krácení ročním maximem list zablokovalo.
     */
    public function testAssessmentBaseAboveAnnualMaximumIsReportedInFull(): void
    {
        $revisions = $this->months(2025, 1, 12);
        $revisions[11] = $this->revision(2025, 12, baseMinor: 30_000_000, cappedMinor: 12_000_000);

        $section = $this->build($revisions)->sections()[0];

        self::assertSame(365, $section['insurance_days']);
        self::assertSame(110_000 + 300_000, $section['assessment_base_czk']);
    }

    /**
     * § 38 odst. 4 písm. h) zákona č. 582/1991 Sb. a údaj 40 ELDP: po dovršení
     * důchodového věku se neplacené volno, nemoc a měsíc „X" odečítají a dny
     * jsou interval minus odečtené doby (logické testy 39 a 48).
     */
    public function testPensionAgeSectionDerivesDeductedDays(): void
    {
        $revisions = $this->months(2025, 1, 12);
        $revisions[4] = $this->revision(2025, 5, absences: [
            $this->absence(9010, 'unpaid_leave', '2025-05-12', '2025-05-16'),
        ]);
        $revisions[5] = $this->revision(2025, 6, absences: [
            $this->absence(9011, 'dpn', '2025-06-02', '2025-06-11'),
            $this->absence(9012, 'unexcused', '2025-06-20', '2025-06-20'),
        ]);
        $revisions[6] = $this->revision(2025, 7, baseMinor: 0, absences: [
            $this->absence(9013, 'unpaid_leave', '2025-07-01', '2025-07-31'),
        ]);

        $statement = $this->build($revisions, confirmation: $this->confirmation(['pension_age_reached_on' => '2025-04-01']));

        $sections = $statement->sections();
        self::assertSame(['1++', '1D+'], array_column($sections, 'code'));
        self::assertSame(5 + 10 + 1 + 31, $sections[1]['deducted_days_total']);
        self::assertSame(10, $sections[1]['excluded_days_total']);
        self::assertSame(275 - 47, $sections[1]['insurance_days']);
        self::assertLessThanOrEqual($sections[1]['deducted_days_total'], $sections[1]['excluded_days_total']);
        $xml = (new EldpXmlSerializer())->serialize($statement);
        self::assertStringContainsString('<odecitaneDobyCelkem>47</odecitaneDobyCelkem>', $xml);
        (new EldpXmlValidator())->validate($statement, $xml);
    }

    /**
     * Výjimka z testu 61: je-li celé období po dovršení věku dobou odečtenou,
     * uvede se základ úhrnem v řádku do dovršení věku.
     */
    public function testWhollyDeductedPensionAgeSectionMovesItsBaseToThePrecedingRow(): void
    {
        $revisions = $this->months(2025, 1, 12);
        $revisions[11] = $this->revision(2025, 12, baseMinor: 200_000, absences: [
            $this->absence(9020, 'unpaid_leave', '2025-12-01', '2025-12-31'),
        ]);

        $sections = $this->build(
            $revisions,
            confirmation: $this->confirmation(['pension_age_reached_on' => '2025-12-01']),
        )->sections();

        self::assertSame(['1++', '1D+'], array_column($sections, 'code'));
        self::assertSame(0, $sections[1]['insurance_days']);
        self::assertSame(0, $sections[1]['assessment_base_czk']);
        self::assertSame(110_000 + 2_000, $sections[0]['assessment_base_czk']);
    }

    /**
     * Všeobecné zásady, Kód — druhý znak: „P" nepřichází v úvahu u zaměstnání
     * malého rozsahu; příjem po skončení patří do měsíce skončení.
     */
    public function testPostTerminationIncomeOfSmallScaleEmploymentIsNotWrittenAsP(): void
    {
        $revisions = [
            ...$this->months(2025, 1, 3, end: '2025-03-31', relation: 'small_scale_employment'),
            $this->revision(2025, 4, end: '2025-03-31', relation: 'small_scale_employment', baseMinor: 50_000),
        ];

        try {
            $this->build($revisions);
            self::fail('Příjem po skončení ZMR nesmí vzniknout jako řádek P+.');
        } catch (EldpValidationException $exception) {
            self::assertSame(
                ['eldp_post_termination_small_scale_unsupported'],
                array_column($exception->blockers, 'code'),
            );
        }
    }

    /** Typ ELDP 02 i při skončení přesně 31. 12. (Všeobecné zásady, Typ ELDP). */
    public function testEmploymentEndingOnDecember31HasTerminationType(): void
    {
        $statement = $this->build($this->months(2025, 1, 12, end: '2025-12-31'));

        self::assertSame('02', $statement->payload['form']['eldp_type']);
        self::assertSame('2026-04-30', $statement->payload['deadline']['due_on']);
    }

    /** § 39 odst. 4 písm. b) ve znění do 2025: úmrtí — typ 03, předložení do 3 měsíců. */
    public function testDeathStatementHasType03AndThreeMonthDeadline(): void
    {
        $confirmation = $this->confirmation() + ['death_on' => '2025-05-20'];

        $statement = $this->build($this->months(2025, 1, 5, end: '2025-05-20'), confirmation: $confirmation);

        self::assertSame('03', $statement->payload['form']['eldp_type']);
        self::assertSame(EldpDeadlinePolicy::DEATH_RULESET, $statement->payload['deadline']['ruleset_id']);
        self::assertSame('2025-08-20', $statement->payload['deadline']['due_on']);
    }

    /** Logický test 54: datum vyhotovení nesmí být pozdější než den přijetí. */
    public function testPreparedOnInTheFutureIsRefused(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-02-10 10:00:00', new \DateTimeZone('Europe/Prague'));
            }
        };
        $confirmation = $this->confirmation() + ['prepared_on' => '2026-02-20'];

        try {
            (new EldpAnnualStatementBuilder(clock: $clock))->build(
                self::SUPPLIER_ID,
                self::EMPLOYMENT_ID,
                2026,
                $this->months(2026, 1, 1, end: '2026-01-31'),
                $confirmation,
            );
            self::fail('Datum vyhotovení v budoucnosti musí být odmítnuto.');
        } catch (EldpValidationException $exception) {
            self::assertSame('eldp_prepared_on_future', $exception->validationCode);
        }
    }

    /**
     * Zadání ELDP12, údaj Rok: hodnota je vždy větší než 2008 a nejvýš rok
     * přijetí listu. Rok 2008 i rok po dnešku list nesestaví.
     */
    public function testYearOutsideEldp12RangeIsRefused(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-02-10 10:00:00', new \DateTimeZone('Europe/Prague'));
            }
        };
        foreach ([2008, 2027] as $year) {
            try {
                (new EldpAnnualStatementBuilder(clock: $clock))->build(
                    self::SUPPLIER_ID,
                    self::EMPLOYMENT_ID,
                    $year,
                    $this->months($year, 1, 12),
                    $this->confirmation(),
                );
                self::fail("Rok {$year} je mimo meze údaje Rok ELDP12.");
            } catch (EldpValidationException $exception) {
                self::assertSame('eldp_year_out_of_range', $exception->validationCode, (string) $year);
            }
        }
        self::assertSame(
            2009,
            (new EldpAnnualStatementBuilder(clock: $clock))->build(
                self::SUPPLIER_ID,
                self::EMPLOYMENT_ID,
                2009,
                $this->months(2009, 1, 12),
                $this->confirmation(),
            )->scope()['year'],
        );
    }

    /**
     * § 15a zákona č. 187/2006 Sb., př. 31 a 38: nemoc v měsíci bez účasti
     * po třech měsících účasti je vyloučenou dobou, započte se do dnů a měsíc
     * zůstane „X". Př. 32: bez tří měsíců účasti se nevykazuje.
     */
    public function testSicknessInNonParticipatingMonthFollowsSection15a(): void
    {
        $sick = [$this->absence(9030, 'dpn', '2025-04-10', '2025-04-20')];
        $entitled = [
            ...$this->months(2025, 1, 3, end: '2025-06-30', relation: 'dpp'),
            $this->revision(2025, 4, end: '2025-06-30', relation: 'dpp', participates: false, baseMinor: 0, absences: $sick),
            ...$this->months(2025, 5, 6, end: '2025-06-30', relation: 'dpp'),
        ];
        $notEntitled = [
            $this->revision(2025, 1, end: '2025-06-30', relation: 'dpp', participates: false, baseMinor: 0),
            ...$this->months(2025, 2, 3, end: '2025-06-30', relation: 'dpp'),
            $this->revision(2025, 4, end: '2025-06-30', relation: 'dpp', participates: false, baseMinor: 0, absences: $sick),
            ...$this->months(2025, 5, 6, end: '2025-06-30', relation: 'dpp'),
        ];

        $withClaim = $this->build($entitled)->sections()[0];
        $withoutClaim = $this->build($notEntitled)->sections()[0];

        self::assertSame(11, $withClaim['excluded_days_total']);
        self::assertSame(31 + 28 + 31 + 11 + 31 + 30, $withClaim['insurance_days']);
        self::assertContains(4, $withClaim['months_without_insurance']);
        self::assertSame(0, $withoutClaim['excluded_days_total']);
        self::assertSame(28 + 31 + 31 + 30, $withoutClaim['insurance_days']);
    }

    /**
     * Všeobecné zásady, údaj „Od" u zaměstnání malého rozsahu a DPP, a Metodická
     * pomůcka př. 33: vznikne-li účast až v některém z dalších měsíců po nástupu,
     * „Od" je první den toho měsíce. Měsíce před vznikem účasti nejsou v intervalu
     * řádku, takže se ani nevyznačují „X". Pozdější měsíce bez účasti uvnitř
     * intervalu „X" zůstávají a „Výdělečná činnost od" nese skutečný nástup.
     */
    public function testSmallScaleEmploymentStartsTheRowWhenParticipationArises(): void
    {
        $revisions = [];
        foreach ([1 => false, 2 => false, 3 => true, 4 => false, 5 => false] as $month => $participates) {
            $revisions[] = $this->revision(
                2025,
                $month,
                start: '2025-01-04',
                end: '2025-05-31',
                baseMinor: $participates ? 450_000 : 140_000,
                relation: 'small_scale_employment',
                participates: $participates,
            );
        }

        $statement = $this->build($revisions);
        $section = $statement->sections()[0];

        self::assertCount(1, $statement->sections());
        self::assertSame('2025-03-01', $section['valid_from']);
        self::assertSame('2025-05-31', $section['valid_to']);
        self::assertSame([4, 5], $section['months_without_insurance']);
        self::assertSame(31, $section['insurance_days']);
        self::assertSame(4_500, $section['assessment_base_czk']);
        self::assertSame('2025-03-01', $statement->scope()['period_from']);
        self::assertSame('2025-01-04', $statement->payload['form']['employed_from']);
        $xml = (new EldpXmlSerializer())->serialize($statement);
        (new EldpXmlValidator())->validate($statement, $xml);
    }

    /**
     * ELDP12 údaj 21 (42, 63) „MR": povinný u každého řádku, A = zaměstnání
     * malého rozsahu (§ 7 zákona č. 187/2006 Sb.), N = jinak, i u DPP podle
     * § 7a. Řádek nese příznak a stejnopis ho vypíše.
     */
    public function testSmallScaleFlagIsAOnlyForSmallScaleEmployment(): void
    {
        $smallScale = $this->build($this->months(2025, 1, 3, end: '2025-03-31', relation: 'small_scale_employment'));
        $employment = $this->build($this->months(2025, 1, 3, end: '2025-03-31'));
        $agreement = $this->build([$this->revision(2025, 1, end: '2025-01-31', baseMinor: 1_200_000, relation: 'dpp')]);

        self::assertTrue($smallScale->sections()[0]['small_scale'] ?? false);
        self::assertSame('A', EldpStatementCopyService::sections($smallScale->payload)[0]['small_scale']);
        self::assertArrayNotHasKey('small_scale', $employment->sections()[0]);
        self::assertSame('N', EldpStatementCopyService::sections($employment->payload)[0]['small_scale']);
        self::assertSame('T++', $agreement->sections()[0]['code']);
        self::assertSame('N', EldpStatementCopyService::sections($agreement->payload)[0]['small_scale']);
    }

    /** Vznikne-li účast už v měsíci nástupu, „Od" je den nástupu. */
    public function testSmallScaleEmploymentParticipatingInTheStartMonthStartsOnTheStartDay(): void
    {
        $revisions = [];
        foreach ([2 => true, 3 => false] as $month => $participates) {
            $revisions[] = $this->revision(
                2025,
                $month,
                start: '2025-02-10',
                end: '2025-03-31',
                baseMinor: $participates ? 450_000 : 140_000,
                relation: 'small_scale_employment',
                participates: $participates,
            );
        }

        $section = $this->build($revisions)->sections()[0];

        self::assertSame('2025-02-10', $section['valid_from']);
        self::assertSame([3], $section['months_without_insurance']);
        self::assertSame(19, $section['insurance_days']);
    }

    /**
     * Převzatý měsíc s vyloučenými dobami: rozpad se odvodí z evidovaných
     * nepřítomností a jeho úhrn se musí shodovat s převzatým (nemoc od poloviny
     * ledna, únor a začátek března celé v nemoci s příjmem, tedy krytí). Celý
     * měsíc rodičovské bez příjmu je mimo dobu pojištění („X") i přes převzaté
     * kalendářní dny.
     */
    public function testTakeoverMonthsUseRecordedAbsences(): void
    {
        $takeover = $this->takeoverYear(
            [
                $this->takeoverMonth(1, ['insurance_days' => 31, 'excluded_days' => 20, 'social_base_minor' => 410_000]),
                $this->takeoverMonth(2, ['insurance_days' => 28, 'excluded_days' => 0, 'social_base_minor' => 150_000]),
                $this->takeoverMonth(3, ['insurance_days' => 3, 'excluded_days' => 0, 'social_base_minor' => 250_000]),
            ],
            [
                $this->absence(9040, 'unexcused', '2026-01-05', '2026-01-05'),
                $this->absence(9041, 'dpn', '2026-01-12', '2026-03-10'),
            ],
        );
        $statement = $this->build([], year: 2026, takeover: $takeover);
        $section = $statement->sections()[0];
        self::assertSame(62, $section['insurance_days']);
        self::assertSame(20, $section['excluded_days_total']);
        self::assertSame(20, $section['excluded_days']['docasNeschopnost']);
        self::assertSame(8_100, $section['assessment_base_czk']);

        $parental = $this->takeoverYear(
            [
                $this->takeoverMonth(1, ['insurance_days' => 31, 'social_base_minor' => 0, 'relationship_end_date' => '2026-02-28']),
                $this->takeoverMonth(2, ['insurance_days' => 28, 'social_base_minor' => 5_200_000, 'relationship_end_date' => '2026-02-28']),
            ],
            [$this->absence(9042, 'parental', '2026-01-01', '2026-03-20')],
        );
        $section = $this->build([], year: 2026, takeover: $parental)->sections()[0];
        self::assertSame(28, $section['insurance_days']);
        self::assertSame([1], $section['months_without_insurance']);
        self::assertSame(52_000, $section['assessment_base_czk']);
    }

    /** Rozpor převzatého úhrnu vyloučených dob s evidovanými nepřítomnostmi blokuje. */
    public function testTakeoverExcludedDaysDisagreeingWithAbsencesBlock(): void
    {
        $takeover = $this->takeoverYear(
            [$this->takeoverMonth(1, ['insurance_days' => 20, 'excluded_days' => 3, 'relationship_end_date' => '2026-01-20'])],
            [$this->absence(9050, 'dpn', '2026-01-10', '2026-01-14')],
        );

        try {
            $this->build([], year: 2026, takeover: $takeover);
            self::fail('Rozpor převzatých vyloučených dob s nepřítomnostmi musí blokovat.');
        } catch (EldpValidationException $exception) {
            self::assertSame(['eldp_takeover_excluded_days_mismatch'], array_column($exception->blockers, 'code'));
            self::assertSame(5, $exception->blockers[0]['detail']['derived_excluded_days']);
        }
    }

    // --- fixtures ---------------------------------------------------------

    /**
     * @param list<array<string,mixed>> $revisions
     * @param array<string,mixed>|null $confirmation
     */
    private function build(
        array $revisions,
        int $year = 2025,
        ?array $confirmation = null,
        ?PayrollTakeoverYear $takeover = null,
    ): EldpAnnualStatement {
        return (new EldpAnnualStatementBuilder())->build(
            self::SUPPLIER_ID,
            self::EMPLOYMENT_ID,
            $year,
            $revisions,
            $confirmation ?? $this->confirmation(),
            $takeover,
        );
    }

    /**
     * @param array<string,mixed> $pension
     * @return array<string,mixed>
     */
    private function confirmation(array $pension = []): array
    {
        return [
            'excluded_days_confirmed' => true,
            'pension_status' => $pension + [
                'pension_age_reached_on' => null,
                'early_pension_from' => null,
                'full_pension_paid_from' => null,
                'foreign_insurance' => false,
            ],
            'requested_by_authority' => false,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function months(int $year, int $from, int $to, ?string $end = null, string $relation = 'employment'): array
    {
        $revisions = [];
        for ($month = $from; $month <= $to; ++$month) {
            $revisions[] = $this->revision($year, $month, end: $end, relation: $relation);
        }

        return $revisions;
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function absence(int $id, string $type, string $from, string $to, array $extra = []): array
    {
        return ['id' => $id, 'absence_type' => $type, 'date_from' => $from, 'date_to' => $to] + $extra;
    }

    /**
     * @param list<array<string,mixed>> $absences
     * @param array{absences:list<array<string,mixed>>}|null $concurrent souběžný pracovní poměr 102 s příjmem
     * @param array{id:int,start:string}|null $rehire nový vztah téže osoby
     * @return array<string,mixed>
     */
    private function revision(
        int $year,
        int $month,
        ?string $start = null,
        ?string $end = null,
        array $absences = [],
        int $baseMinor = 1_000_000,
        ?int $cappedMinor = null,
        string $relation = 'employment',
        bool $participates = true,
        ?array $concurrent = null,
        ?array $rehire = null,
    ): array {
        $periodStart = sprintf('%04d-%02d-01', $year, $month);
        $start ??= sprintf('%04d-01-01', $year);
        [$activity, $detail, $kind] = match ($relation) {
            'dpp' => ['T', null, 'dpp'],
            default => ['1', '1', 'employment'],
        };
        $employments = [];
        $relationships = [];
        $resultEmployments = [];
        $inMonth = $end === null || $end >= $periodStart;
        if ($inMonth || $baseMinor > 0) {
            $employments[] = [
                'employment' => [
                    'id' => self::EMPLOYMENT_ID,
                    'employee_id' => self::EMPLOYEE_ID,
                    'relation_type' => $relation,
                    'start_date' => $start,
                    'actual_start_date' => $start,
                    'end_date' => $end,
                ],
                'term' => ['id' => 201, 'row_version' => 1, 'activity_code' => $activity, 'jmhz_relationship_detail_code' => $detail],
                'absences' => $absences,
                'inputs' => [],
            ];
            $resultEmployments[] = ['employment_id' => self::EMPLOYMENT_ID, 'totals' => []];
            $relationships[] = [
                'relationship_id' => 'employment:' . self::EMPLOYMENT_ID,
                'kind' => $kind,
                'participation' => [
                    'relationship_id' => 'employment:' . self::EMPLOYMENT_ID,
                    'status' => $participates ? 'participates' : 'does_not_participate',
                    'reason_codes' => [],
                ],
                'assessment_base_minor_units' => $baseMinor,
                'capped_assessment_base_minor_units' => $participates ? ($cappedMinor ?? $baseMinor) : 0,
            ];
        }
        foreach (array_filter([
            $concurrent !== null ? [self::CONCURRENT_ID, sprintf('%04d-01-01', $year), $concurrent['absences']] : null,
            $rehire !== null ? [$rehire['id'], $rehire['start'], []] : null,
        ]) as [$otherId, $otherStart, $otherAbsences]) {
            $employments[] = [
                'employment' => [
                    'id' => $otherId,
                    'employee_id' => self::EMPLOYEE_ID,
                    'relation_type' => 'employment',
                    'start_date' => $otherStart,
                    'actual_start_date' => $otherStart,
                    'end_date' => null,
                ],
                'term' => ['id' => 300 + $otherId, 'row_version' => 1, 'activity_code' => '2', 'jmhz_relationship_detail_code' => '1'],
                'absences' => $otherAbsences,
                'inputs' => [],
            ];
            $resultEmployments[] = ['employment_id' => $otherId, 'totals' => []];
            $relationships[] = [
                'relationship_id' => 'employment:' . $otherId,
                'kind' => 'employment',
                'participation' => ['relationship_id' => 'employment:' . $otherId, 'status' => 'participates', 'reason_codes' => []],
                'assessment_base_minor_units' => 800_000,
                'capped_assessment_base_minor_units' => 800_000,
            ];
        }
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => self::SUPPLIER_ID,
            'period_start' => $periodStart,
            'people' => [['employee' => ['id' => self::EMPLOYEE_ID], 'employments' => $employments]],
        ];
        $inputJson = CanonicalJson::encode($input);
        $result = [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => hash('sha256', $inputJson),
            'people' => [[
                'employee_id' => self::EMPLOYEE_ID,
                'employments' => $resultEmployments,
                'statutory' => ['social_insurance' => ['status' => 'calculated', 'relationships' => $relationships]],
            ]],
        ];
        $resultJson = CanonicalJson::encode($result);

        return [
            'id' => 400 + $month,
            'run_id' => 500 + $month,
            'revision_no' => 1,
            'current_revision_no' => 1,
            'revision_kind' => 'regular',
            'status' => 'approved',
            'period_start' => $periodStart,
            'input_snapshot_json' => $inputJson,
            'input_snapshot_hash' => hash('sha256', $inputJson),
            'result_snapshot_json' => $resultJson,
            'result_snapshot_hash' => hash('sha256', $resultJson),
        ];
    }

    /**
     * @param list<PayrollTakeoverMonth> $months
     * @param list<array<string,mixed>> $absences
     */
    private function takeoverYear(array $months, array $absences): PayrollTakeoverYear
    {
        return new PayrollTakeoverYear(
            self::SUPPLIER_ID,
            2026,
            '2026-09',
            $months,
            [],
            self::EMPLOYEE_ID,
            self::EMPLOYMENT_ID,
            null,
            [self::EMPLOYMENT_ID => $absences],
        );
    }

    /** @param array<string,mixed> $overrides */
    private function takeoverMonth(int $month, array $overrides = []): PayrollTakeoverMonth
    {
        $end = $overrides['relationship_end_date'] ?? '2026-03-03';

        return PayrollTakeoverMonth::fromRow([
            'period' => sprintf('2026-%02d', $month),
            'source' => 'pamica',
            'external_person_ref' => 'SYN-11',
            'external_relationship_ref' => 'SYN-11/1',
            'employee_id' => self::EMPLOYEE_ID,
            'employment_id' => self::EMPLOYMENT_ID,
            'relationship_start_date' => '2025-11-03',
            'relation_type' => 'employment',
            'activity_code' => '1',
            'pension_participation' => 1,
            'insurance_days' => 0,
            'excluded_days' => 0,
            'gross_minor' => 1_000_000,
            'social_base_minor' => 1_000_000,
            'import_reference' => 'synteticky-import-1',
            ...$overrides,
            'relationship_end_date' => $end,
        ]);
    }
}
