<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ošetřovné a peněžitá pomoc v mateřství z pohledu mzdy: zaměstnavatel za
 * tyto dny mzdu ani náhradu neplatí (dávky vyplácí ČSSZ), měsíční mzda se
 * krátí hodinami z fondu a minimální vyměřovací základ zdravotního pojištění
 * se za tyto kalendářní dny nedopočítává. Hodnoty ručně přepočtené.
 *
 * Zákon č. 592/1992 Sb., § 3:
 *  - odst. 8 písm. d): minimální vyměřovací základ neplatí pro osobu, „za
 *    kterou je plátcem pojistného stát", trvá-li to celé rozhodné období
 *    (příjemkyně PPM, § 7 odst. 1 písm. d) zákona č. 48/1997 Sb.);
 *  - odst. 9: minimum „se snižuje na poměrnou část odpovídající počtu
 *    kalendářních dnů, pokud … b) zaměstnanci bylo poskytnuto pracovní volno
 *    pro důležité osobní překážky v práci" (ošetřování, § 191 ZP) nebo
 *    „c) zaměstnanec se stal v průběhu rozhodného období osobou, za kterou
 *    platí pojistné i stát";
 *  - odst. 10: z rozdílu do minima doplatí zaměstnanec 13,5 %.
 *
 * Sada 2026: minimum 22 400 Kč, ZP 13,5 % (třetina zaměstnanec), SP 7,1 %,
 * záloha 15 %, sleva na poplatníka 2 570 Kč. Duben 2026: fond 176 h včetně
 * svátků 3. 4. a 6. 4. (16 h), březen 176 h.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eHpp2CareLeaveTest extends TestCase
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
     * Minimální mzda 22 400 Kč, ošetřování člena rodiny 13. až 21. 4. 2026
     * (9 kalendářních dnů, 7 směn = 56 h).
     *
     * Mzda 22 400 × (176 − 56) / 176 = 15 272,73 → 15 273 Kč, žádná náhrada.
     * Minimum ZP za 30 − 9 = 21 dnů: 22 400 × 21/30 = 15 680 Kč. Mzda krácená
     * hodinami (68,2 %) je pod minimem kráceným kalendářními dny (70 %),
     * rozdíl 407 Kč doplácí zaměstnanec: pojistné z minima 15 680 × 13,5 %
     * = 2 116,80 → 2 117; ze skutečného základu 2 061,86 → 2 062 (zaměstnanec
     * 687,33 → 688, zaměstnavatel 1 374); doplatek 2 117 − 2 062 = 55 Kč.
     * SP 15 273 × 7,1 % = 1 084,38 → 1 085. Záloha 15 300 × 15 % = 2 295
     * − 2 570 → 0. Čistá 15 273 − 1 085 − 688 − 55 = 13 445.
     *
     * Hlášení: odpracováno 176 − 16 − 56 = 104 h; neodpracováno 56 + 16 = 72 h,
     * z toho s náhradou jen svátky 16 h; 9 dnů ošetřovného je vyloučenou
     * dobou (§ 16 odst. 4 písm. a) zákona č. 155/1995 Sb.).
     */
    public function testCareLeaveProratesWageAndHealthMinimumByCalendarDays(): void
    {
        $person = $this->hireMonthly('Syntetická Ošetřující', 'female', '1989-11-02', 2_240_000);
        // Průměr 2. čtvrtletí z podkladů: 67 200 Kč / 512 h = 131,25 → 134,40 Kč/h (§ 357).
        $average = $this->manualAverage($person['employment_id'], 2, [
            'gross_earnings_minor' => 6_720_000,
            'worked_minutes' => 512 * 60,
            'worked_days' => 63,
        ]);
        self::assertSame(13_440, (int) $average['average_hourly_minor']);

        $absence = $this->createApprovedAbsence($person['employment_id'], 'ocr', '2026-04-13', '2026-04-21');
        self::assertSame('none', $absence['compensation_policy'], 'Za ošetřování zaměstnavatel náhradu neplatí.');
        self::assertSame(1_527_300, $this->materializeWage($person, '2026-04'));
        $this->approveAttendance($person, '2026-04', self::dateRange('2026-04-13', '2026-04-21'));

        $run = $this->approvedRun('2026-04', 'care');
        $health = $run['people'][0]['health_insurance'];
        self::assertSame(9, $health['minimum_excluded_calendar_days']);
        self::assertSame(1_568_000, $health['effective_minimum_minor_units']);
        $this->assertPayroll($run['people'][0], [
            'taxable' => 1_527_300,
            'social' => 108_500,
            'health_employee' => 74_300,
            'health_employer' => 137_400,
            'health_top_up' => 5_500,
            'advance' => 0,
            'net' => 1_344_500,
        ]);

        $xml = $this->jmhzXml($run['revision_id'], 'care');
        self::assertStringContainsString('<form:pocet>104.000</form:pocet>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracCelkem>72.000</form:hodinyNeodpracCelkem>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracNahrada>16.000</form:hodinyNeodpracNahrada>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracOcr>56.000</form:hodinyNeodpracOcr>', $xml);
        self::assertStringContainsString('<form:vylouceneDobyCelkem>9</form:vylouceneDobyCelkem>', $xml);
        self::assertStringContainsString('<form:osetrovaniClenaRodiny>9</form:osetrovaniClenaRodiny>', $xml);
        self::assertStringContainsString('<form:docasNeschopnost>0</form:docasNeschopnost>', $xml);
        // Pojistné zaměstnance 688 + doplatek 55, zaměstnavatele 1 374.
        self::assertStringContainsString('<form:zdravPojZamestnavatel><form:zdravotniPojisteni>1374</form:zdravotniPojisteni></form:zdravPojZamestnavatel>', $xml);
        self::assertStringContainsString('<form:zdravPojZamestnanec><form:zdravotniPojisteni>743</form:zdravotniPojisteni></form:zdravPojZamestnanec>', $xml);
        self::assertStringContainsString('<form:vydelekPrumernyHod>134.40</form:vydelekPrumernyHod>', $xml);
        self::assertStringContainsString('<form:mzdaZuctovana>15273</form:mzdaZuctovana>', $xml);
        self::assertStringContainsString('<form:zuctovanoCelkem>15273</form:zuctovanoCelkem>', $xml);
        self::assertStringContainsString('<form:mzdaCista>13445</form:mzdaCista>', $xml);
    }

    /**
     * Mzda 24 000 Kč a totéž ošetřování: 24 000 × 120/176 = 16 363,64
     * → 16 364 Kč je nad poměrným minimem 15 680 Kč (ale pod plným minimem
     * 22 400 Kč), doplatek tedy nevzniká. SP 1 161,84 → 1 162; ZP 2 209,14
     * → 2 210, zaměstnanec 736,67 → 737, zaměstnavatel 1 473; záloha
     * 16 400 × 15 % = 2 460 − 2 570 → 0; čistá 16 364 − 1 162 − 737 = 14 465.
     */
    public function testCareLeaveAboveProratedMinimumHasNoTopUp(): void
    {
        $person = $this->hireMonthly('Syntetický Pečující', 'male', '1986-08-30', 2_400_000);
        $this->createApprovedAbsence($person['employment_id'], 'ocr', '2026-04-13', '2026-04-21');
        self::assertSame(1_636_400, $this->materializeWage($person, '2026-04'));
        $this->approveAttendance($person, '2026-04', self::dateRange('2026-04-13', '2026-04-21'));

        $run = $this->approvedRun('2026-04', 'care-high');
        $this->assertPayroll($run['people'][0], [
            'taxable' => 1_636_400,
            'social' => 116_200,
            'health_employee' => 73_700,
            'health_employer' => 147_300,
            'health_top_up' => 0,
            'advance' => 0,
            'net' => 1_446_500,
        ]);
    }

    /**
     * Nástup na peněžitou pomoc v mateřství 16. 3. 2026 (očekávaný porod
     * 8. 5. 2026, tedy v osmém týdnu před porodem), mzda 30 000 Kč.
     *
     * Březen: nepřítomnost 16. až 31. 3. = 12 směn = 96 h; mzda 30 000
     * × 80/176 = 13 636,36 → 13 637 Kč. Minimum ZP za 15 dnů před nástupem:
     * 22 400 × 15/31 = 10 838,71 Kč, mzda je nad ním, doplatek žádný.
     * SP 968,23 → 969; ZP 1 841,00 → 1 841, zaměstnanec 613,67 → 614,
     * zaměstnavatel 1 227; záloha 13 700 × 15 % = 2 055 − 2 570 → 0;
     * čistá 13 637 − 969 − 614 = 12 054.
     *
     * Duben: celý měsíc na PPM, mzda 0 Kč, pojistné 0 Kč; minimum ZP se
     * nepoužije (státní pojištěnka po celý měsíc). V hlášení je celý měsíc
     * vyloučenou dobou (PPM před porodem) a zaměstnankyně není v evidenčním
     * stavu.
     */
    public function testMaternityFromMidMonthAndWholeMonthWithoutHealthMinimum(): void
    {
        $person = $this->hireMonthly('Syntetická Mateřská', 'female', '1994-02-17', 3_000_000);
        // Průměr 2. čtvrtletí z podkladů: 90 000 Kč / 512 h = 175,78 Kč/h.
        $average = $this->manualAverage($person['employment_id'], 2, [
            'gross_earnings_minor' => 9_000_000,
            'worked_minutes' => 512 * 60,
            'worked_days' => 63,
        ]);
        self::assertSame(17_578, (int) $average['average_hourly_minor']);
        $absence = $this->createApprovedAbsence(
            $person['employment_id'],
            'ppm',
            '2026-03-16',
            '2026-09-27',
            extra: ['expected_childbirth_date' => '2026-05-08'],
        );
        self::assertSame('none', $absence['compensation_policy']);

        self::assertSame(1_363_700, $this->materializeWage($person, '2026-03'));
        $this->approveAttendance($person, '2026-03', self::dateRange('2026-03-16', '2026-03-31'));
        $march = $this->approvedRun('2026-03', 'maternity');
        $health = $march['people'][0]['health_insurance'];
        self::assertSame(16, $health['minimum_excluded_calendar_days']);
        self::assertSame(1_083_871, $health['effective_minimum_minor_units']);
        $this->assertPayroll($march['people'][0], [
            'taxable' => 1_363_700,
            'social' => 96_900,
            'health_employee' => 61_400,
            'health_employer' => 122_700,
            'health_top_up' => 0,
            'advance' => 0,
            'net' => 1_205_400,
        ]);

        self::assertSame(0, $this->materializeWage($person, '2026-04'), 'Za celý měsíc na PPM mzda nepřísluší.');
        $this->approveAttendance($person, '2026-04', self::dateRange('2026-04-01', '2026-04-30'));
        $april = $this->approvedRun('2026-04', 'maternity');
        $health = $april['people'][0]['health_insurance'];
        self::assertSame(0, $health['effective_minimum_minor_units']);
        $this->assertPayroll($april['people'][0], [
            'taxable' => 0,
            'social' => 0,
            'health_employee' => 0,
            'health_employer' => 0,
            'health_top_up' => 0,
            'advance' => 0,
            'net' => 0,
        ]);

        $xml = $this->jmhzXml($april['revision_id'], 'maternity');
        self::assertStringContainsString('<form:pocetDnu>30</form:pocetDnu>', $xml);
        self::assertStringContainsString('<form:penezitaPomocMaterstvi>30</form:penezitaPomocMaterstvi>', $xml);
        self::assertStringContainsString('<form:vylouceneDobyCelkem>30</form:vylouceneDobyCelkem>', $xml);
        self::assertStringContainsString('<form:dnyEvidencniStav>0</form:dnyEvidencniStav>', $xml);
        self::assertStringContainsString('<form:zuctovanoCelkem>0</form:zuctovanoCelkem>', $xml);
        self::assertStringContainsString(
            '<form:zdravPojZamestnavatel><form:zdravotniPojisteni>0</form:zdravotniPojisteni></form:zdravPojZamestnavatel>'
                . '<form:zdravPojZamestnanec><form:zdravotniPojisteni>0</form:zdravotniPojisteni></form:zdravPojZamestnanec>',
            $xml,
        );
    }
}
