<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Dovolená zaměstnance s měsíční mzdou od ledna do dubna 2026: průměrný
 * výdělek zjištěný z uzavřených běhů 1. čtvrtletí, čerpání celých dnů
 * i půldne, přechod čtvrtletí a kratší pracovní doba. Očekávané hodnoty jsou
 * ručně přepočtené podle zákoníku práce a sady 2026.
 *
 * Pravidla ručního přepočtu:
 *  - náhrada za dovolenou = průměrný hodinový výdělek × hodiny dovolené
 *    (§ 222 odst. 1 ZP), měsíční úhrn na celé koruny nahoru (§ 142 odst. 2,
 *    § 144 ZP); čerpat lze i polovinu směny (§ 218 odst. 6 ZP);
 *  - průměrný výdělek = hrubá mzda zúčtovaná v rozhodném období / doba, za
 *    kterou mzda přísluší (§ 353 odst. 1 a 2 ZP); rozhodným obdobím je
 *    předchozí čtvrtletí (§ 354 odst. 1), průměr se zjišťuje k prvnímu dni
 *    následujícího měsíce (§ 354 odst. 2). U měsíční mzdy patří do této doby
 *    i svátek v obvyklý pracovní den, za který se mzda nekrátí (§ 115
 *    odst. 3 ZP): mzda za něj přísluší a je v hrubé mzdě obsažena;
 *  - měsíční mzda se krátí poměrem hodin z fondu měsíce včetně svátků
 *    (§ 109 odst. 1 ZP), na celé koruny nahoru;
 *  - fond 2026: leden 22 dnů (176 h, z toho svátek 1. 1.), únor 20 dnů
 *    (160 h), březen 22 dnů (176 h), duben 22 dnů (176 h, z toho svátky
 *    3. 4. a 6. 4.);
 *  - SP zaměstnanec 7,1 % nahoru; ZP 13,5 % nahoru, zaměstnanec třetina
 *    nahoru; záloha 15 % ze základu zaokrouhleného na stokoruny nahoru,
 *    sleva na poplatníka 2 570 Kč; minimální mzda 22 400 Kč, 134,40 Kč/h.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eHpp2VacationTest extends TestCase
{
    use PayrollE2eHpp2VacationFlowTrait;

    protected function setUp(): void
    {
        $this->bootLeaveFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Měsíční mzda 44 000 Kč, leden až březen odpracované celé, v dubnu
     * dovolená 13. a 14. 4. (celé dny) a 15. 4. odpoledne (půl směny).
     *
     * Průměr pro 2. čtvrtletí: 3 × 44 000 = 132 000 Kč; doba, za kterou mzda
     * přísluší: 176 + 160 + 176 = 512 h (leden včetně 8 h svátku 1. 1.);
     * 132 000 / 512 = 257,8125 → 257,81 Kč/h. Odpracováno 63 dnů (≥ 21).
     *
     * Duben: dovolená 8 + 8 + 4 = 20 h; mzda 44 000 × (176 − 20) / 176
     * = 39 000 Kč; náhrada 20 × 257,81 = 5 156,20 → 5 157 Kč; hrubá 44 157.
     * SP 44 157 × 7,1 % = 3 135,15 → 3 136; ZP 5 961,20 → 5 962, zaměstnanec
     * 1 987,33 → 1 988, zaměstnavatel 3 974; záloha 44 200 × 15 % = 6 630
     * − 2 570 = 4 060; čistá 44 157 − 3 136 − 1 988 − 4 060 = 34 973.
     */
    public function testVacationWholeDaysAndHalfDayFromActualQuarterAverage(): void
    {
        $person = $this->hireMonthly('Syntetická Dovolenková', 'female', '1988-04-12', 4_400_000);
        foreach (['2026-01', '2026-02', '2026-03'] as $period) {
            $run = $this->workedMonth([$person], $period, 'vacation');
            // 44 000: SP 3 124, ZP 5 940 / 3 = 1 980, záloha 6 600 − 2 570 = 4 030.
            $this->assertPayroll($run['people'][0], [
                'taxable' => 4_400_000,
                'social' => 312_400,
                'health_employee' => 198_000,
                'health_employer' => 396_000,
                'health_top_up' => 0,
                'advance' => 403_000,
                'net' => 3_486_600,
            ]);
        }

        $average = $this->quarterAverage($person['employment_id'], 2);
        self::assertSame('actual', $average['source_kind']);
        self::assertSame(13_200_000, (int) $average['gross_earnings_minor']);
        self::assertSame(512 * 60, (int) $average['worked_minutes'], 'Odpracovaná doba včetně svátku 1. 1. krytého měsíční mzdou.');
        self::assertSame(25_781, (int) $average['average_hourly_minor']);

        $absence = $this->createApprovedAbsence(
            $person['employment_id'],
            'vacation',
            '2026-04-13',
            '2026-04-15',
            (int) $average['id'],
            ['partial_last_minutes' => 240],
        );
        self::assertSame('average_100', $absence['compensation_policy']);
        // Dovolená se čerpá v hodinách: 8 + 8 + 4 h (půl směny, § 218 odst. 6 ZP).
        self::assertSame(-1_200, $this->leaveLedgerMinutes($person['employment_id'], 'taken'));
        self::assertSame(3_900_000, $this->materializeWage($person, '2026-04'), 'Mzda krácená o 20 h dovolené ze 176 h fondu.');
        self::assertSame(515_700, $this->inputAmount($person['employment_id'], '2026-04', 'NAHRADA_MZDY_DOVOLENA'));
        $this->approveAttendance($person, '2026-04', ['2026-04-13', '2026-04-14'], ['2026-04-15' => 240]);

        $run = $this->approvedRun('2026-04', 'vacation');
        $this->assertPayroll($run['people'][0], [
            'taxable' => 4_415_700,
            'social' => 313_600,
            'health_employee' => 198_800,
            'health_employer' => 397_400,
            'health_top_up' => 0,
            'advance' => 406_000,
            'net' => 3_497_300,
        ]);

        $xml = $this->jmhzXml($run['revision_id'], 'vacation');
        self::assertSame(1, substr_count($xml, '</formularOsoby>'));
        // Fond 10259/10260 včetně svátků, odpracováno 176 − 16 (svátky) − 20 (dovolená).
        self::assertStringContainsString('<form:stanovenyFond>176.000</form:stanovenyFond>', $xml);
        self::assertStringContainsString('<form:sjednanyFond>176.000</form:sjednanyFond>', $xml);
        self::assertStringContainsString('<form:pocet>140.000</form:pocet>', $xml);
        // 10275/10276: dovolená 20 h a dva svátky 16 h; 10279 jen dovolená.
        self::assertStringContainsString('<form:hodinyNeodpracCelkem>36.000</form:hodinyNeodpracCelkem>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracNahrada>36.000</form:hodinyNeodpracNahrada>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracDovol>20.000</form:hodinyNeodpracDovol>', $xml);
        self::assertStringContainsString('<form:vylouceneDobyCelkem>0</form:vylouceneDobyCelkem>', $xml);
        // 10286/10328 mzda bez náhrad, 10329 tarif, náhrady 10337 a z toho dovolená 10338.
        self::assertStringContainsString('<form:mzdaZuctovana>39000</form:mzdaZuctovana>', $xml);
        self::assertStringContainsString('<form:tarif>39000</form:tarif>', $xml);
        self::assertStringContainsString('<form:nahrady><form:mzdyZuctovane>5157</form:mzdyZuctovane><form:dovolena>5157</form:dovolena></form:nahrady>', $xml);
        // 10345 průměrný hodinový výdělek platný v měsíci hlášení.
        self::assertStringContainsString('<form:vydelekPrumernyHod>257.81</form:vydelekPrumernyHod>', $xml);
        self::assertStringContainsString('<form:zuctovanoCelkem>44157</form:zuctovanoCelkem>', $xml);
        self::assertStringContainsString('<form:mzdaCista>34973</form:mzdaCista>', $xml);
    }

    /**
     * Dovolená přes přelom čtvrtletí 30. 3. až 2. 4. 2026 (4 pracovní dny).
     *
     * Jedním zápisem ji aplikace odmítne a řekne, kde ji rozdělit: březnová
     * část se platí průměrem 1. čtvrtletí, dubnová průměrem 2. čtvrtletí
     * (§ 354 odst. 1 a 2 ZP).
     *
     * Průměr 1. čtvrtletí z podkladů předchozího zpracování: 124 800 Kč
     * / 480 h = 260,00 Kč/h. Březen: náhrada 16 × 260 = 4 160 Kč, mzda
     * 44 000 × 160 / 176 = 40 000 Kč, hrubá 44 160: SP 3 135,36 → 3 136;
     * ZP 5 961,60 → 5 962, zaměstnanec 1 988; záloha 44 200 × 15 % − 2 570
     * = 4 060; čistá 34 976.
     *
     * Průměr 2. čtvrtletí: mzda 44 000 + 44 000 + 40 000 = 128 000 Kč
     * (náhrada za dovolenou do průměru nevstupuje); doba 176 + 160 + 160
     * = 496 h; 128 000 / 496 = 258,0645 → 258,06 Kč/h. Duben: náhrada
     * 16 × 258,06 = 4 128,96 → 4 129 Kč, mzda 40 000, hrubá 44 129:
     * SP 3 133,16 → 3 134; ZP 5 957,42 → 5 958, zaměstnanec 1 986; záloha
     * 44 200 × 15 % − 2 570 = 4 060; čistá 34 949.
     */
    public function testVacationAcrossQuarterBoundaryUsesEachQuartersAverage(): void
    {
        $person = $this->hireMonthly('Syntetický Přelomový', 'male', '1984-09-21', 4_400_000);
        $first = $this->manualAverage($person['employment_id'], 1, [
            'gross_earnings_minor' => 12_480_000,
            'worked_minutes' => 480 * 60,
            'worked_days' => 60,
        ]);
        self::assertSame(26_000, (int) $first['average_hourly_minor']);
        $this->workedMonth([$person], '2026-01', 'quarter');
        $this->workedMonth([$person], '2026-02', 'quarter');

        $whole = $this->requestAbsence($person['employment_id'], 'vacation', '2026-03-30', '2026-04-02', (int) $first['id']);
        self::assertSame(422, $whole->getStatusCode(), (string) $whole->getBody());
        $message = (string) ($this->json($whole)['error']['message'] ?? $this->json($whole)['message'] ?? '');
        self::assertStringContainsString('2026-03-30 – 2026-03-31', $message, $message);
        self::assertStringContainsString('2026-04-01 – 2026-04-02', $message, $message);

        $this->createApprovedAbsence($person['employment_id'], 'vacation', '2026-03-30', '2026-03-31', (int) $first['id']);
        self::assertSame(4_000_000, $this->materializeWage($person, '2026-03'));
        self::assertSame(416_000, $this->inputAmount($person['employment_id'], '2026-03', 'NAHRADA_MZDY_DOVOLENA'));
        $this->approveAttendance($person, '2026-03', ['2026-03-30', '2026-03-31']);
        $march = $this->approvedRun('2026-03', 'quarter');
        $this->assertPayroll($march['people'][0], [
            'taxable' => 4_416_000,
            'social' => 313_600,
            'health_employee' => 198_800,
            'health_employer' => 397_400,
            'health_top_up' => 0,
            'advance' => 406_000,
            'net' => 3_497_600,
        ]);

        $second = $this->quarterAverage($person['employment_id'], 2);
        self::assertSame(12_800_000, (int) $second['gross_earnings_minor'], 'Náhrada za dovolenou do průměru nevstupuje.');
        self::assertSame(496 * 60, (int) $second['worked_minutes']);
        self::assertSame(25_806, (int) $second['average_hourly_minor']);

        $this->createApprovedAbsence($person['employment_id'], 'vacation', '2026-04-01', '2026-04-02', (int) $second['id']);
        self::assertSame(4_000_000, $this->materializeWage($person, '2026-04'));
        self::assertSame(412_900, $this->inputAmount($person['employment_id'], '2026-04', 'NAHRADA_MZDY_DOVOLENA'));
        $this->approveAttendance($person, '2026-04', ['2026-04-01', '2026-04-02']);
        $april = $this->approvedRun('2026-04', 'quarter');
        $this->assertPayroll($april['people'][0], [
            'taxable' => 4_412_900,
            'social' => 313_400,
            'health_employee' => 198_600,
            'health_employer' => 397_200,
            'health_top_up' => 0,
            'advance' => 406_000,
            'net' => 3_494_900,
        ]);
    }

    /**
     * Kratší pracovní doba 20 h týdně (4 h denně) za 11 200 Kč, tedy polovina
     * minimální mzdy. Dovolená 20. až 22. 4. (3 směny po 4 h = 12 h).
     *
     * Průměr 2. čtvrtletí: 33 600 Kč / (88 + 80 + 88 = 256 h) = 131,25 Kč/h,
     * což je pod hodinovou minimální mzdou 134,40 Kč, proto se zvýší na
     * 134,40 Kč/h (§ 357 odst. 1 ZP). Kratší pracovní doba (§ 80 ZP) sazbu
     * minimální mzdy nemění, ta se úměrně zvyšuje jen při kratší STANOVENÉ
     * týdenní pracovní době.
     *
     * Duben: fond 22 × 4 = 88 h; mzda 11 200 × 76 / 88 = 9 672,73 → 9 673;
     * náhrada 12 × 134,40 = 1 612,80 → 1 613; hrubá 11 286. SP 801,31 → 802.
     * ZP: ze skutečného základu 1 523,61 → 1 524 (zaměstnanec 508,
     * zaměstnavatel 1 016), z minima 22 400 × 13,5 % = 3 024, doplatek
     * zaměstnance 1 500 (§ 3 odst. 10 zákona č. 592/1992 Sb.). Záloha
     * 11 300 × 15 % = 1 695 − 2 570 → 0. Čistá 11 286 − 802 − 508 − 1 500
     * = 8 476.
     */
    public function testPartTimeVacationUsesMinimumWageFloorWithoutScalingTheRate(): void
    {
        $person = $this->hireMonthly('Syntetická Poloviční', 'female', '1991-06-03', 1_120_000, weeklyHours: 20, workload: 5_000);
        foreach (['2026-01', '2026-02', '2026-03'] as $period) {
            $this->markHealthTopUpEmployee($person['employee_id'], $period);
            $this->workedMonth([$person], $period, 'part-time');
        }
        $average = $this->quarterAverage($person['employment_id'], 2);
        self::assertSame(3_360_000, (int) $average['gross_earnings_minor']);
        self::assertSame(256 * 60, (int) $average['worked_minutes']);
        self::assertSame(13_440, (int) $average['average_hourly_minor'], 'Průměr zvýšený na minimální mzdu 134,40 Kč/h.');

        // Nárok za celý rok: kratší týdenní doba 20 h × 4 týdny = 80 h (§ 212 odst. 1 ZP).
        $entitlement = $this->absences->createEntitlement(
            $this->request('POST', '/api/payroll/time/leave-entitlements')->withParsedBody([
                'employment_id' => $person['employment_id'],
                'leave_year' => 2026,
                'weekly_minutes' => 1_200,
                'entitlement_weeks' => 4,
                'continuous_calendar_days' => 365,
                'worked_equivalent_minutes' => 52 * 1_200,
                'rationale' => 'Celoroční nárok při kratší týdenní pracovní době 20 h.',
            ]),
            new \Slim\Psr7\Response(),
        );
        self::assertSame(201, $entitlement->getStatusCode(), (string) $entitlement->getBody());
        self::assertSame(4_800, $this->leaveLedgerMinutes($person['employment_id'], 'entitlement'));

        $this->createApprovedAbsence($person['employment_id'], 'vacation', '2026-04-20', '2026-04-22', (int) $average['id']);
        // Tři směny po 4 h = 12 h čerpání, zůstatek 68 h.
        self::assertSame(-720, $this->leaveLedgerMinutes($person['employment_id'], 'taken'));
        self::assertSame(967_300, $this->materializeWage($person, '2026-04'));
        self::assertSame(161_300, $this->inputAmount($person['employment_id'], '2026-04', 'NAHRADA_MZDY_DOVOLENA'));
        $this->approveAttendance($person, '2026-04', ['2026-04-20', '2026-04-21', '2026-04-22']);
        $run = $this->approvedRun('2026-04', 'part-time');
        $this->assertPayroll($run['people'][0], [
            'taxable' => 1_128_600,
            'social' => 80_200,
            'health_employee' => 200_800,
            'health_employer' => 101_600,
            'health_top_up' => 150_000,
            'advance' => 0,
            'net' => 847_600,
        ]);

        $xml = $this->jmhzXml($run['revision_id'], 'part-time');
        self::assertStringContainsString('<form:stanovenyFond>176.000</form:stanovenyFond>', $xml);
        self::assertStringContainsString('<form:sjednanyFond>88.000</form:sjednanyFond>', $xml);
        self::assertStringContainsString('<form:stanovenaTydenniDoba>40.00</form:stanovenaTydenniDoba>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracDovol>12.000</form:hodinyNeodpracDovol>', $xml);
        self::assertStringContainsString('<form:vydelekPrumernyHod>134.40</form:vydelekPrumernyHod>', $xml);
        self::assertStringContainsString('<form:dovolena>1613</form:dovolena>', $xml);
    }

    /**
     * Dovolená přes Velikonoce 2. až 7. 4. 2026: Velký pátek 3. 4. a Velikonoční
     * pondělí 6. 4. se do dovolené nezapočítávají (§ 219 odst. 2 ZP) a měsíční
     * mzda se za ně nekrátí; dovolenou jsou jen čtvrtek 2. 4. a úterý 7. 4.
     *
     * Průměr 2. čtvrtletí z podkladů: 132 000 Kč / 512 h = 257,81 Kč/h.
     * Náhrada 16 × 257,81 = 4 124,96 → 4 125 Kč; mzda 44 000 × 160/176
     * = 40 000; hrubá 44 125: SP 3 132,88 → 3 133; ZP 5 956,88 → 5 957,
     * zaměstnanec 1 985,67 → 1 986; záloha 44 200 × 15 % − 2 570 = 4 060;
     * čistá 34 946.
     *
     * Průměr jiného čtvrtletí, než do kterého dovolená spadá, aplikace
     * k nepřítomnosti nepřipustí.
     */
    public function testVacationOverEasterDoesNotConsumeHolidays(): void
    {
        $person = $this->hireMonthly('Syntetická Velikonoční', 'female', '1982-12-01', 4_400_000);
        $first = $this->manualAverage($person['employment_id'], 1, [
            'gross_earnings_minor' => 12_480_000,
            'worked_minutes' => 480 * 60,
            'worked_days' => 60,
        ]);
        $second = $this->manualAverage($person['employment_id'], 2, [
            'gross_earnings_minor' => 13_200_000,
            'worked_minutes' => 512 * 60,
            'worked_days' => 63,
        ]);
        self::assertSame(25_781, (int) $second['average_hourly_minor']);

        $wrongQuarter = $this->requestAbsence($person['employment_id'], 'vacation', '2026-04-02', '2026-04-07', (int) $first['id']);
        self::assertSame(422, $wrongQuarter->getStatusCode(), (string) $wrongQuarter->getBody());
        $message = (string) $this->json($wrongQuarter)['error']['message'];
        self::assertStringContainsString('2. čtvrtletí 2026', $message);
        self::assertStringNotContainsString('snapshot', $message, 'Hláška pro účetní bez vývojářského žargonu.');

        $this->createApprovedAbsence($person['employment_id'], 'vacation', '2026-04-02', '2026-04-07', (int) $second['id']);
        self::assertSame(-960, $this->leaveLedgerMinutes($person['employment_id'], 'taken'), 'Svátky se do dovolené nezapočítávají.');
        self::assertSame(4_000_000, $this->materializeWage($person, '2026-04'));
        self::assertSame(412_500, $this->inputAmount($person['employment_id'], '2026-04', 'NAHRADA_MZDY_DOVOLENA'));
        $this->approveAttendance($person, '2026-04', ['2026-04-02', '2026-04-07']);
        $run = $this->approvedRun('2026-04', 'easter');
        $this->assertPayroll($run['people'][0], [
            'taxable' => 4_412_500,
            'social' => 313_300,
            'health_employee' => 198_600,
            'health_employer' => 397_100,
            'health_top_up' => 0,
            'advance' => 406_000,
            'net' => 3_494_600,
        ]);

        $xml = $this->jmhzXml($run['revision_id'], 'easter');
        // 10275/10276: dovolená 16 h + svátky 16 h; 10279 jen dovolená.
        self::assertStringContainsString('<form:pocet>144.000</form:pocet>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracCelkem>32.000</form:hodinyNeodpracCelkem>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracNahrada>32.000</form:hodinyNeodpracNahrada>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracDovol>16.000</form:hodinyNeodpracDovol>', $xml);
        self::assertStringContainsString('<form:dovolena>4125</form:dovolena>', $xml);
    }

    /**
     * Dovolenou bez schváleného průměru nejde schválit; odmítnutí říká proč.
     * Uložit ji lze (účetní se o ní dozví dřív, než je průměr hotový).
     */
    public function testVacationWithoutAverageCanBeSavedButApprovalExplainsWhatIsMissing(): void
    {
        $person = $this->hireMonthly('Syntetický Bezprůměrný', 'male', '1990-10-10', 4_400_000);
        $created = $this->requestAbsence($person['employment_id'], 'vacation', '2026-04-13', '2026-04-14');
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $absence = $this->json($created)['absence'];
        $decision = $this->absences->decision(
            $this->request('POST', '/api/payroll/absences/decision')->withParsedBody([
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
            ]),
            new \Slim\Psr7\Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertContains($decision->getStatusCode(), [409, 422], (string) $decision->getBody());
        $body = $this->json($decision);
        $message = (string) ($body['error']['message'] ?? $body['message'] ?? '');
        self::assertStringContainsString('průměr', mb_strtolower($message), CanonicalJson::encode($body));
    }

    /** Součet položek knihy dovolené daného druhu za rok 2026 v minutách. */
    private function leaveLedgerMinutes(int $employmentId, string $entryType): int
    {
        return (int) $this->scalar(
            'SELECT COALESCE(SUM(minutes_delta), 0) FROM payroll_leave_ledger
              WHERE supplier_id = ? AND employment_id = ? AND leave_year = 2026 AND entry_type = ?',
            [$this->supplierId, $employmentId, $entryType],
        );
    }

    /** Měsíční evidence, kdo hradí doplatek do minima ZP (zaměstnanec). */
    private function markHealthTopUpEmployee(int $employeeId, string $period): void
    {
        $repository = $this->container->get(\MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository::class);
        self::assertInstanceOf(\MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository::class, $repository);
        $view = $repository->editorView($this->supplierId, $employeeId, '2026-04-30');
        self::assertIsArray($view);
        $sections = $view['sections'];
        $sections['health_month_evidence'][] = [
            'period_start' => "{$period}-01",
            'top_up_responsibility' => 'employee',
            'top_up_responsibility_evidence_reference' => null,
            'selected_top_up_employer_reference' => null,
            'selected_top_up_employer_evidence_reference' => null,
        ];
        $repository->save(
            $this->supplierId,
            $employeeId,
            ['effective_on' => '2026-04-30', 'sections' => $sections],
            '2026-04-30',
            $this->actors[0],
            null,
            'payroll-e2e-hpp2',
        );
    }
}
