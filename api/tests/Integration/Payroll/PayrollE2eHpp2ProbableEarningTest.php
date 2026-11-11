<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pravděpodobný výdělek nového zaměstnance (§ 355 ZP) a jeho dopad na náhradu
 * za dovolenou a na údaj 10345 měsíčního hlášení, ručně přepočtené.
 *
 * „Jestliže zaměstnanec v rozhodném období neodpracoval alespoň 21 dnů,
 * použije se pravděpodobný výdělek" (§ 355 odst. 1). Zjistí se „z hrubé mzdy
 * nebo platu, které zaměstnanec dosáhl od počátku rozhodného období,
 * popřípadě z hrubé mzdy nebo platu, které by zřejmě dosáhl" (odst. 2).
 * Při vzniku zaměstnání v průběhu čtvrtletí je rozhodným obdobím doba od
 * vzniku zaměstnání do konce čtvrtletí (§ 354 odst. 3). Ani pravděpodobný
 * výdělek nesmí být nižší než minimální mzda (§ 357 odst. 1).
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eHpp2ProbableEarningTest extends TestCase
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
     * Nástup 16. 3. 2026, mzda 44 000 Kč, v březnu navíc odměna 3 000 Kč.
     *
     * Březen: odpracováno 12 dnů (96 h ze 176 h fondu), mzda 44 000 × 96/176
     * = 24 000 Kč, hrubá 27 000: SP 1 917; ZP 3 645, zaměstnanec 1 215;
     * záloha 27 000 × 15 % = 4 050 − 2 570 = 1 480; čistá 22 388.
     *
     * 2. čtvrtletí: 12 odpracovaných dnů < 21, proto pravděpodobný výdělek
     * z dosažené hrubé mzdy: 27 000 / 96 h = 281,25 Kč/h.
     *
     * Duben: dovolená 20. a 21. 4. (16 h), náhrada 16 × 281,25 = 4 500 Kč;
     * mzda 44 000 × 160/176 = 40 000; hrubá 44 500: SP 3 159,50 → 3 160;
     * ZP 6 007,50 → 6 008, zaměstnanec 2 002,67 → 2 003, zaměstnavatel 4 005;
     * záloha 44 500 × 15 % = 6 675 − 2 570 = 4 105; čistá 35 232.
     */
    public function testStarterBelowTwentyOneDaysGetsProbableEarningFromAchievedWage(): void
    {
        $bonus = $this->createComponent('ODMENA_DOV', 'bonus', 'one_off');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $bonus, '10331', null, $this->actors[0]);

        $person = $this->hireMonthly('Syntetický Nováček', 'male', '1995-03-08', 4_400_000, start: '2026-03-16');
        self::assertSame(2_400_000, $this->materializeWage($person, '2026-03'), 'Mzda za 96 h ze 176 h fondu.');
        $this->createApprovedInput($person, $bonus, 300_000, 'bonus-march', '2026-03-01');
        $this->approveAttendance($person, '2026-03');
        $march = $this->approvedRun('2026-03', 'probable');
        $this->assertPayroll($march['people'][0], [
            'taxable' => 2_700_000,
            'social' => 191_700,
            'health_employee' => 121_500,
            'health_employer' => 243_000,
            'health_top_up' => 0,
            'advance' => 148_000,
            'net' => 2_238_800,
        ]);

        // Skutečný průměr s 12 odpracovanými dny aplikace nepřijme a řekne proč.
        $actual = $this->requestAverage($person['employment_id'], 2, [
            'gross_earnings_minor' => 2_700_000,
            'worked_minutes' => 96 * 60,
            'worked_days' => 12,
        ]);
        self::assertSame(422, $actual->getStatusCode(), (string) $actual->getBody());
        self::assertStringContainsString('pravděpodobný', (string) $this->json($actual)['error']['message']);

        $average = $this->quarterAverage($person['employment_id'], 2);
        self::assertSame('probable', $average['source_kind']);
        self::assertSame(28_125, (int) $average['average_hourly_minor']);

        $this->createApprovedAbsence($person['employment_id'], 'vacation', '2026-04-20', '2026-04-21', (int) $average['id']);
        self::assertSame(4_000_000, $this->materializeWage($person, '2026-04'));
        self::assertSame(450_000, $this->inputAmount($person['employment_id'], '2026-04', 'NAHRADA_MZDY_DOVOLENA'));
        $this->approveAttendance($person, '2026-04', ['2026-04-20', '2026-04-21']);
        $april = $this->approvedRun('2026-04', 'probable');
        $this->assertPayroll($april['people'][0], [
            'taxable' => 4_450_000,
            'social' => 316_000,
            'health_employee' => 200_300,
            'health_employer' => 400_500,
            'health_top_up' => 0,
            'advance' => 410_500,
            'net' => 3_523_200,
        ]);

        $xml = $this->jmhzXml($april['revision_id'], 'probable');
        self::assertStringContainsString('<form:vydelekPrumernyHod>281.25</form:vydelekPrumernyHod>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracDovol>16.000</form:hodinyNeodpracDovol>', $xml);
        self::assertStringContainsString('<form:nahrady><form:mzdyZuctovane>4500</form:mzdyZuctovane><form:dovolena>4500</form:dovolena></form:nahrady>', $xml);
        self::assertStringContainsString('<form:mzdaCista>35232</form:mzdaCista>', $xml);
    }

    /**
     * Nástup 1. 4. 2026 za 44 000 Kč: rozhodné období žádné, dosažená mzda
     * ještě neexistuje, proto mzda, které by zaměstnanec zřejmě dosáhl:
     * sjednaná měsíční mzda přepočtená na hodinu průměrným měsíčním fondem
     * (§ 356 odst. 2: týdenní doba × 4,348): 44 000 / (40 × 4,348)
     * = 44 000 / 173,92 = 252,9899 → 252,99 Kč/h.
     *
     * Dovolená 27. a 28. 4. (16 h): náhrada 16 × 252,99 = 4 047,84 → 4 048 Kč;
     * mzda 44 000 × 160/176 = 40 000; hrubá 44 048: SP 3 127,41 → 3 128;
     * ZP 5 946,48 → 5 947, zaměstnanec 1 982,33 → 1 983; záloha 44 100 × 15 %
     * = 6 615 − 2 570 = 4 045; čistá 34 892.
     */
    public function testFirstMonthStarterGetsProbableEarningFromAgreedWage(): void
    {
        $person = $this->hireMonthly('Syntetická Dubnová', 'female', '1993-07-19', 4_400_000, start: '2026-04-01');
        $average = $this->quarterAverage($person['employment_id'], 2);
        self::assertSame('probable', $average['source_kind']);
        self::assertSame(25_299, (int) $average['average_hourly_minor']);

        $this->createApprovedAbsence($person['employment_id'], 'vacation', '2026-04-27', '2026-04-28', (int) $average['id']);
        self::assertSame(4_000_000, $this->materializeWage($person, '2026-04'));
        self::assertSame(404_800, $this->inputAmount($person['employment_id'], '2026-04', 'NAHRADA_MZDY_DOVOLENA'));
        $this->approveAttendance($person, '2026-04', ['2026-04-27', '2026-04-28']);
        $april = $this->approvedRun('2026-04', 'starter');
        $this->assertPayroll($april['people'][0], [
            'taxable' => 4_404_800,
            'social' => 312_800,
            'health_employee' => 198_300,
            'health_employer' => 396_400,
            'health_top_up' => 0,
            'advance' => 404_500,
            'net' => 3_489_200,
        ]);

        $xml = $this->jmhzXml($april['revision_id'], 'starter');
        self::assertStringContainsString('<form:vydelekPrumernyHod>252.99</form:vydelekPrumernyHod>', $xml);
        self::assertStringContainsString('<form:dovolena>4048</form:dovolena>', $xml);
    }

    /**
     * Ručně zadaný pravděpodobný výdělek: bez odůvodnění ho aplikace odmítne,
     * s odůvodněním přijme; hodnota pod minimální mzdou se zvýší na
     * 134,40 Kč/h (§ 357 odst. 1 ZP platí i pro pravděpodobný výdělek).
     */
    public function testManualProbableEarningNeedsRationaleAndRespectsMinimumWage(): void
    {
        $person = $this->hireMonthly('Syntetický Odhadnutý', 'male', '1990-01-15', 2_240_000, start: '2026-03-23');

        $withoutRationale = $this->requestAverage($person['employment_id'], 2, [
            'worked_days' => 7,
            'worked_minutes' => 56 * 60,
            'gross_earnings_minor' => 712_800,
            'probable_hourly_minor' => 12_000,
        ]);
        self::assertSame(422, $withoutRationale->getStatusCode(), (string) $withoutRationale->getBody());
        self::assertStringContainsString('odůvodnění', (string) $this->json($withoutRationale)['error']['message']);

        $average = $this->manualAverage($person['employment_id'], 2, [
            'worked_days' => 7,
            'worked_minutes' => 56 * 60,
            'gross_earnings_minor' => 712_800,
            'probable_hourly_minor' => 12_000,
            'rationale' => 'Mzda srovnatelného zaměstnance na stejné pozici.',
        ]);
        self::assertSame('probable', $average['source_kind']);
        self::assertSame(13_440, (int) $average['average_hourly_minor'], 'Pravděpodobný výdělek zvýšený na minimální mzdu.');
    }
}
