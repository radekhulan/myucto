<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time;

use MyInvoice\Service\Payroll\Time\Juvenile\JuvenileWorkTimeEvaluator;
use MyInvoice\Service\Payroll\Time\Juvenile\JuvenileWorkTimeFinding;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeEmploymentProfile;
use PHPUnit\Framework\TestCase;

/**
 * Pracovní doba mladistvých (§ 79a, § 88 odst. 1, § 90 odst. 1, § 245 odst. 1
 * zákoníku práce) nad odpracovanými záznamy. Květen 2026 je v Praze letní čas
 * (UTC+2); záznamy se zadávají místním časem.
 */
final class JuvenileWorkTimeEvaluatorTest extends TestCase
{
    private const PERIOD = '2026-05-01';
    private const JUVENILE = '2010-03-01';

    public function testDailyShiftOfEightHoursPassesAndOneMinuteMoreDoesNot(): void
    {
        self::assertSame([], $this->codes([$this->entry('2026-05-04 07:00', '2026-05-04 15:30', 30)]));

        $findings = $this->evaluate([$this->entry('2026-05-04 07:00', '2026-05-04 15:31', 30)]);
        self::assertSame([JuvenileWorkTimeFinding::CODE_DAILY], self::findingCodes($findings));
        self::assertSame('2026-05-04', $findings[0]->scopeFrom);
        self::assertSame(481, $findings[0]->actualMinutes);
        self::assertStringContainsString('4. 5. 2026', $findings[0]->message);
        self::assertStringContainsString('8:01 h', $findings[0]->message);
    }

    /** U více vztahů se doba sčítá; dělená směna je jedna směna dne. */
    public function testDailyLimitAddsUpEntriesOfTheSameDay(): void
    {
        self::assertSame([JuvenileWorkTimeFinding::CODE_DAILY], $this->codes([
            $this->entry('2026-05-04 07:00', '2026-05-04 11:00', 0),
            $this->entry('2026-05-04 11:30', '2026-05-04 15:31', 0),
        ]));
    }

    public function testWeekOfFortyHoursPassesAndOneMinuteMoreDoesNot(): void
    {
        $week = [];
        foreach (['04', '05', '06', '07', '08'] as $day) {
            $week[] = $this->entry("2026-05-{$day} 07:00", "2026-05-{$day} 15:30", 30);
        }
        self::assertSame([], $this->codes($week));

        $week[] = $this->entry('2026-05-09 08:00', '2026-05-09 08:01', 0);
        $findings = $this->evaluate($week);
        self::assertSame([JuvenileWorkTimeFinding::CODE_WEEKLY], self::findingCodes($findings));
        self::assertSame('2026-05-04', $findings[0]->scopeFrom);
        self::assertSame('2026-05-10', $findings[0]->scopeTo);
        self::assertSame(2401, $findings[0]->actualMinutes);
    }

    public function testRestOfTwelveHoursPassesAndShorterDoesNot(): void
    {
        self::assertSame([], $this->codes([
            $this->entry('2026-05-04 06:00', '2026-05-04 10:00', 0),
            $this->entry('2026-05-04 10:00', '2026-05-04 14:00', 0),
            $this->entry('2026-05-05 02:00', '2026-05-05 06:00', 0),
        ], nightless: true));

        $findings = $this->evaluate([
            $this->entry('2026-05-04 06:00', '2026-05-04 14:00', 30),
            $this->entry('2026-05-05 01:59', '2026-05-05 05:00', 0),
        ], nightless: true);
        self::assertSame([JuvenileWorkTimeFinding::CODE_REST], self::findingCodes($findings));
        self::assertSame('2026-05-05', $findings[0]->scopeFrom);
        self::assertSame(719, $findings[0]->actualMinutes);
    }

    public function testNightWorkIsReportedWithMinutesAndTheTrainingException(): void
    {
        self::assertSame([], $this->codes([$this->entry('2026-05-04 14:00', '2026-05-04 22:00', 30)]));

        $findings = $this->evaluate([$this->entry('2026-05-04 14:30', '2026-05-04 22:30', 30)]);
        self::assertSame([JuvenileWorkTimeFinding::CODE_NIGHT], self::findingCodes($findings));
        self::assertSame(30, $findings[0]->actualMinutes);
        self::assertStringContainsString('starší 16 let', $findings[0]->message);
        self::assertStringContainsString('nejvýše 1 h', $findings[0]->message);
    }

    public function testBreakIsRequiredOnlyAfterFourAndHalfHoursWithoutAnyBreak(): void
    {
        self::assertSame([], $this->codes([$this->entry('2026-05-04 08:00', '2026-05-04 12:30', 0)]));
        self::assertSame([], $this->codes([$this->entry('2026-05-04 08:00', '2026-05-04 12:46', 15)]));

        $findings = $this->evaluate([$this->entry('2026-05-04 08:00', '2026-05-04 12:31', 0)]);
        self::assertSame([JuvenileWorkTimeFinding::CODE_BREAK], self::findingCodes($findings));
        self::assertSame(271, $findings[0]->actualMinutes);
    }

    /** Den 18. narozenin a dny po něm se už nekontrolují. */
    public function testEighteenthBirthdayInMonthEndsTheCheck(): void
    {
        $findings = $this->evaluate([
            $this->entry('2026-05-14 07:00', '2026-05-14 16:00', 30),
            $this->entry('2026-05-15 07:00', '2026-05-15 16:00', 30),
            $this->entry('2026-05-16 07:00', '2026-05-16 16:00', 30),
        ], birthDate: '2008-05-15');

        self::assertSame([JuvenileWorkTimeFinding::CODE_DAILY], self::findingCodes($findings));
        self::assertSame('2026-05-14', $findings[0]->scopeFrom);
    }

    public function testAdultAndUnknownBirthDateAreNotChecked(): void
    {
        $long = [$this->entry('2026-05-04 07:00', '2026-05-04 23:00', 0)];
        self::assertSame([], self::findingCodes($this->evaluate($long, birthDate: '2000-01-01')));
        self::assertSame([], (new JuvenileWorkTimeEvaluator())->evaluate(
            $long,
            new OvertimeEmploymentProfile(null),
            self::PERIOD,
        ));
    }

    /** Záznam mimo měsíc se do nálezů měsíce nepropíše. */
    public function testEntriesOutsideMonthAreOnlyContext(): void
    {
        self::assertSame([], $this->codes([$this->entry('2026-04-30 07:00', '2026-04-30 18:00', 0)]));
    }

    /**
     * @param list<array{starts_at_utc:string,ends_at_utc:string,break_minutes:int,timezone_name:string}> $entries
     * @return list<string>
     */
    private function codes(array $entries, bool $nightless = false): array
    {
        return self::findingCodes($this->evaluate($entries, nightless: $nightless));
    }

    /**
     * @param list<array{starts_at_utc:string,ends_at_utc:string,break_minutes:int,timezone_name:string}> $entries
     * @return list<JuvenileWorkTimeFinding>
     */
    private function evaluate(array $entries, string $birthDate = self::JUVENILE, bool $nightless = false): array
    {
        // Odpočinek se testuje i přes noc; noční nález by tam jen překážel,
        // proto se noční doba posune mimo testované časy.
        $evaluator = $nightless ? new JuvenileWorkTimeEvaluator(23, 0) : new JuvenileWorkTimeEvaluator();

        return $evaluator->evaluate($entries, new OvertimeEmploymentProfile($birthDate), self::PERIOD);
    }

    /**
     * @param list<JuvenileWorkTimeFinding> $findings
     * @return list<string>
     */
    private static function findingCodes(array $findings): array
    {
        return array_map(static fn (JuvenileWorkTimeFinding $finding): string => $finding->code, $findings);
    }

    /** @return array{starts_at_utc:string,ends_at_utc:string,break_minutes:int,timezone_name:string} */
    private function entry(string $start, string $end, int $breakMinutes): array
    {
        $prague = new \DateTimeZone('Europe/Prague');
        $utc = new \DateTimeZone('UTC');

        return [
            'starts_at_utc' => (new \DateTimeImmutable($start, $prague))->setTimezone($utc)->format('Y-m-d H:i:s'),
            'ends_at_utc' => (new \DateTimeImmutable($end, $prague))->setTimezone($utc)->format('Y-m-d H:i:s'),
            'break_minutes' => $breakMinutes,
            'timezone_name' => 'Europe/Prague',
        ];
    }
}
