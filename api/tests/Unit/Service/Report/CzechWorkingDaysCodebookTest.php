<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;
use MyInvoice\Service\Report\CzechWorkingDays;
use MyInvoice\Service\Report\PublicHolidayProvider;
use PHPUnit\Framework\TestCase;

/**
 * P-7 — svátky se čtou z číselníku `public_holidays` (migrace 1781), ne z konstanty.
 *
 * Proč to hlídat testem: svátek posouvá přes § 33 odst. 4 daňového řádu VŠECHNY
 * lhůty podání. Dokud byl seznam konstantou v PHP, znamenala novela zákona
 * č. 245/2000 Sb. novou verzi aplikace. Číselník, který nikdo nečte, by ten
 * problém neřešil — jen ho zamaskoval, protože by vypadal jako hotová věc.
 */
final class CzechWorkingDaysCodebookTest extends TestCase
{
    protected function tearDown(): void
    {
        CzechWorkingDays::reset();
    }

    /**
     * @param list<array<string,mixed>> $rules
     */
    private function useCodebook(array $rules): void
    {
        CzechWorkingDays::configure(static fn (): PublicHolidayProvider => new class ($rules) implements PublicHolidayProvider {
            /** @param list<array<string,mixed>> $rules */
            public function __construct(private readonly array $rules) {}

            /** @inheritDoc */
            public function holidayRules(): array
            {
                /** @var list<array{code:string,name:string,rule_type:'fixed'|'easter',month_day:?string,easter_offset:?int,valid_from:string,valid_to:?string}> */
                return $this->rules;
            }
        });
    }

    /**
     * @return array<string,mixed>
     */
    private static function fixed(string $code, string $name, string $monthDay, string $from = '1900-01-01', ?string $to = null): array
    {
        return [
            'code' => $code, 'name' => $name, 'rule_type' => 'fixed',
            'month_day' => $monthDay, 'easter_offset' => null,
            'valid_from' => $from, 'valid_to' => $to,
        ];
    }

    /**
     * Jádro celého P-7: nový svátek v číselníku POSUNE termín podání, bez
     * zásahu do kódu a bez nového releasu. Bez toho by byl číselník k ničemu.
     *
     * 25. 8. 2026 je úterý, tedy běžný pracovní den (viz `CzechWorkingDaysTest`).
     * Jakmile je 25. 8. svátek, lhůta padá na středu 26. 8.
     */
    public function testNewHolidayInCodebookShiftsDeadline(): void
    {
        self::assertSame('2026-08-25', CzechWorkingDays::deadline(2026, 8), 'Výchozí stav: 25. 8. 2026 je úterý.');

        CzechWorkingDays::reset();
        $this->useCodebook([
            self::fixed('new_year', 'Nový rok', '01-01'),
            self::fixed('test_holiday', 'Zkušební svátek', '08-25', '2026-01-01'),
        ]);

        self::assertTrue(CzechWorkingDays::isPublicHoliday(new \DateTimeImmutable('2026-08-25')));
        self::assertFalse(CzechWorkingDays::isWorkingDay(new \DateTimeImmutable('2026-08-25')));
        self::assertSame('2026-08-26', CzechWorkingDays::deadline(2026, 8), 'Lhůta se posunula na nejbližší pracovní den.');
        self::assertFalse(CzechWorkingDays::usingFallback(), 'Odpověď musí přijít z číselníku, ne z pojistky.');
    }

    /**
     * Zrušený svátek číselník taky umí — a to je druhá polovina § 33/4: den, který
     * přestal být svátkem, lhůtu posouvat NESMÍ. 6. 7. 2026 je pondělí, takže bez
     * svátku je to normální pracovní den.
     */
    public function testRemovedHolidayNoLongerShiftsDeadline(): void
    {
        $this->useCodebook([
            self::fixed('jan_hus', 'Den upálení mistra Jana Husa', '07-06', '1900-01-01', '2025-12-31'),
        ]);

        self::assertTrue(CzechWorkingDays::isPublicHoliday(new \DateTimeImmutable('2025-07-06')), 'Do konce platnosti svátek je.');
        self::assertFalse(CzechWorkingDays::isPublicHoliday(new \DateTimeImmutable('2026-07-06')), 'Po `valid_to` už svátek není.');
        self::assertSame('2026-07-06', CzechWorkingDays::deadlineFromMonthDay(2026, '07-06'));
    }

    /** Platnost se posuzuje ke KONKRÉTNÍMU dni, ne k roku — `valid_from` uprostřed roku dělí rok napůl. */
    public function testValidFromIsEvaluatedPerDayNotPerYear(): void
    {
        $this->useCodebook([
            self::fixed('spring', 'Jarní svátek', '03-02', '2026-06-01'),
            self::fixed('autumn', 'Podzimní svátek', '10-01', '2026-06-01'),
        ]);

        self::assertFalse(
            CzechWorkingDays::isPublicHoliday(new \DateTimeImmutable('2026-03-02')),
            '2. 3. 2026 je před `valid_from` — svátek ještě neplatí.',
        );
        self::assertTrue(
            CzechWorkingDays::isPublicHoliday(new \DateTimeImmutable('2026-10-01')),
            '1. 10. 2026 je po `valid_from` — svátek už platí.',
        );
    }

    /**
     * Pohyblivé svátky drží číselník jako PRAVIDLO (posun ode dne Velikonoční
     * neděle), ne jako naseedovaná data — jinak by seznam tiše došel na konci
     * naseedovaného rozsahu. Velikonoce 2026: neděle 5. 4.
     */
    public function testEasterRuleIsComputedFromOffset(): void
    {
        $this->useCodebook([
            [
                'code' => 'good_friday', 'name' => 'Velký pátek', 'rule_type' => 'easter',
                'month_day' => null, 'easter_offset' => -2,
                'valid_from' => '1900-01-01', 'valid_to' => null,
            ],
            [
                'code' => 'easter_monday', 'name' => 'Velikonoční pondělí', 'rule_type' => 'easter',
                'month_day' => null, 'easter_offset' => 1,
                'valid_from' => '1900-01-01', 'valid_to' => null,
            ],
        ]);

        $holidays = CzechWorkingDays::holidaysForYear(2026);
        self::assertFalse(CzechWorkingDays::usingFallback());
        self::assertCount(2, $holidays, 'Číselník má dvě pravidla — nic víc se dopočítat nesmí.');
        self::assertArrayHasKey('2026-04-03', $holidays, 'Velký pátek 2026.');
        self::assertArrayHasKey('2026-04-06', $holidays, 'Velikonoční pondělí 2026.');
        self::assertSame('Velký pátek', $holidays['2026-04-03']['name']);

        // A ve vzdáleném roce taky — pravidlo nemá konec platnosti, seznam by ho měl.
        self::assertArrayHasKey('2099-04-10', CzechWorkingDays::holidaysForYear(2099), 'Velký pátek 2099.');
    }

    /**
     * Prázdný číselník = instalace bez seedu. Helper NESMÍ spadnout ani vrátit
     * „rok bez svátků" (to by tiše zrušilo posun všech lhůt); vrátí pojistku
     * zapečenou v kódu a PŘIZNÁ to.
     */
    public function testEmptyCodebookFallsBackLoudly(): void
    {
        $this->useCodebook([]);

        $holidays = CzechWorkingDays::holidaysForYear(2026);

        self::assertTrue(CzechWorkingDays::usingFallback(), 'Fallback se musí dát poznat, ne proběhnout potichu.');
        self::assertCount(13, $holidays, 'Pojistka drží celý zákon: 11 pevných + 2 velikonoční.');
        self::assertArrayHasKey('2026-07-06', $holidays);
        self::assertArrayHasKey('2026-04-03', $holidays, 'Velký pátek 2026 i v pojistce.');
        self::assertSame('2026-07-27', CzechWorkingDays::deadline(2026, 7), 'Posun podle § 33/4 platí i z pojistky.');
    }

    /** Rozbitý číselník (výjimka při čtení) se chová stejně jako prázdný — pojistka, ne pád. */
    public function testBrokenCodebookFallsBack(): void
    {
        CzechWorkingDays::configure(static function (): PublicHolidayProvider {
            throw new \RuntimeException('databáze není dostupná');
        });

        self::assertCount(13, CzechWorkingDays::holidaysForYear(2026));
        self::assertTrue(CzechWorkingDays::usingFallback());
    }

    /**
     * Pojistka v kódu musí dávat TOTÉŽ co seed migrace 1781 — jinak by odpověď
     * aplikace závisela na tom, jestli je instalace naseedovaná. Porovnává se
     * proti seedu přepsanému do pravidel, ne proti databázi (unit test).
     */
    public function testFallbackMatchesSeededCodebook(): void
    {
        $this->useCodebook([]);
        $fallback = CzechWorkingDays::holidaysForYear(2027);

        CzechWorkingDays::reset();
        $this->useCodebook(self::seedAsRules());
        $fromCodebook = CzechWorkingDays::holidaysForYear(2027);

        self::assertFalse(CzechWorkingDays::usingFallback());
        self::assertSame($fallback, $fromCodebook, 'Seed migrace 1781 a pojistka v kódu se rozešly.');
    }

    /**
     * Mzdový kalendář je nad `CzechWorkingDays` jen pojmenovaný pohled — musí
     * tedy vidět i změnu číselníku, ne jen konstantu (jinak by fond pracovní
     * doby počítal podle jiného zákona než lhůty podání).
     */
    public function testPayrollCalendarSeesCodebookChange(): void
    {
        $this->useCodebook([
            self::fixed('test_holiday', 'Zkušební svátek', '08-25', '2026-01-01'),
        ]);

        $calendar = (new CzechHolidayCalendar())->forYear(2026);

        self::assertSame(['2026-08-25'], array_keys($calendar));
        self::assertSame('Zkušební svátek', $calendar['2026-08-25']['name']);
    }

    /**
     * Seed migrace 1781 přepsaný do tvaru pravidel.
     *
     * @return list<array<string,mixed>>
     */
    private static function seedAsRules(): array
    {
        return [
            self::fixed('new_year', 'Nový rok', '01-01'),
            [
                'code' => 'good_friday', 'name' => 'Velký pátek', 'rule_type' => 'easter',
                'month_day' => null, 'easter_offset' => -2, 'valid_from' => '1900-01-01', 'valid_to' => null,
            ],
            [
                'code' => 'easter_monday', 'name' => 'Velikonoční pondělí', 'rule_type' => 'easter',
                'month_day' => null, 'easter_offset' => 1, 'valid_from' => '1900-01-01', 'valid_to' => null,
            ],
            self::fixed('labour_day', 'Svátek práce', '05-01'),
            self::fixed('victory_day', 'Den vítězství', '05-08'),
            self::fixed('cyril_methodius', 'Den slovanských věrozvěstů Cyrila a Metoděje', '07-05'),
            self::fixed('jan_hus', 'Den upálení mistra Jana Husa', '07-06'),
            self::fixed('statehood_day', 'Den české státnosti', '09-28'),
            self::fixed('independent_state_day', 'Den vzniku samostatného československého státu', '10-28'),
            self::fixed('freedom_democracy_day', 'Den boje za svobodu a demokracii', '11-17'),
            self::fixed('christmas_eve', 'Štědrý den', '12-24'),
            self::fixed('christmas_day', '1. svátek vánoční', '12-25'),
            self::fixed('boxing_day', '2. svátek vánoční', '12-26'),
        ];
    }
}
