<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentSurchargePolicyAction;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults;
use MyInvoice\Service\Payroll\PayrollHourlyEvidenceService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * Hodinová mzda (podmínky vztahu s hodinovou sazbou, docházka po dnech)
 * s ručními přepočty podle zákoníku práce a sady 2026.
 *
 * Společné podklady:
 *  - mzda = odpracované hodiny × sjednaná sazba (§ 109 odst. 1, § 141 odst. 2 ZP);
 *  - neodpracovaný svátek v obvyklý pracovní den: náhrada mzdy ve výši
 *    průměrného výdělku za ušlou mzdu (§ 115 odst. 3 ZP);
 *  - práce ve svátek: dosažená mzda a náhradní volno, příplatek ve výši
 *    průměrného výdělku jen byl-li sjednán (§ 115 odst. 1 a 2 ZP);
 *  - přesčas: dosažená mzda a 25 % průměrného výdělku (§ 114 odst. 1), noc
 *    10 % (§ 116), sobota a neděle 10 % (§ 118 odst. 1);
 *  - minimální mzda 2026: 22 400 Kč měsíčně, 134,40 Kč/h při 40 h týdně.
 *    Do mzdy se pro srovnání nezahrnuje mzda za přesčas ani příplatky
 *    (§ 111 odst. 1 ZP) a doplatek se, není-li sjednáno jinak, počítá
 *    hodinovou minimální mzdou (§ 111 odst. 2 písm. a) ZP);
 *  - mzda se zaokrouhluje na celé koruny nahoru (§ 142 odst. 2 ZP);
 *  - sada 2026: SP zaměstnanec 7,1 %, ZP 13,5 % (zaměstnanec třetina),
 *    záloha 15 % ze základu zaokrouhleného na stokoruny nahoru, sleva
 *    na poplatníka 2 570 Kč.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eHpp2HourlyTest extends TestCase
{
    use PayrollFullFlowTrait;

    private int $officeId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        // Běh z kontejneru: zmrazení podkladů zakládá nároky hodinové mzdy.
        $runs = $this->container->get(PayrollRunCommandService::class);
        self::assertInstanceOf(PayrollRunCommandService::class, $runs);
        $this->runs = $runs;
        $this->officeId = $this->createOffice('HOD', 'Syntetická účtárna hodinových mezd', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $components = $this->container->get(PayrollComponentRepository::class);
        self::assertInstanceOf(PayrollComponentRepository::class, $components);
        $components->ensureDefaults($this->supplierId);
        $mappings = $this->container->get(PayrollComponentJmhzMappingDefaults::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingDefaults::class, $mappings);
        $mappings->apply($this->supplierId);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Duben 2026, sazba 200 Kč/h, průměrný výdělek 100 800 / 504 h = 200 Kč/h.
     * Odpracováno 20 dnů × 8 h = 160 h, dva svátky (3. 4. a 6. 4.) připadly
     * na rozvržené směny a neodpracovaly se.
     *
     * Ruční přepočet:
     *  - mzda 160 × 200 = 32 000 Kč;
     *  - náhrada za svátky 16 × 200 = 3 200 Kč (§ 115 odst. 3 ZP);
     *  - minimální mzda: 160 × 134,40 = 21 504 < 32 000, doplatek 0;
     *  - hrubá 35 200; SP 35 200 × 7,1 % = 2 499,20 → 2 500; ZP 4 752
     *    → 1 584 / 3 168; záloha 35 200 × 15 % = 5 280 − 2 570 = 2 710;
     *  - čistá 35 200 − 2 500 − 1 584 − 2 710 = 28 406 Kč.
     * JMHZ: mzda zúčtovaná a tarif 32 000 (10328, 10329), náhrady 3 200
     * (10337) z toho svátky 3 200 (10339), průměrný výdělek 200,00 (10345).
     */
    public function testHourlyWageWithUnworkedHolidaysReachesMonthlyReport(): void
    {
        $person = $this->hire('Syntetický Hodinový', 'male', '1984-03-12', '2026-04', 20_000, 2, 10_080_000);
        $id = $person['employment_id'];
        $workdays = self::workdays('2026-04');
        // Rozvrh směn včetně obou svátků dokládá, že svátek připadl na obvyklý pracovní den.
        $this->publishShifts($id, [...$workdays, '2026-04-03', '2026-04-06']);
        $cells = array_map(fn (string $day): array => $this->cell($id, 'regular', "{$day} 08:00", "{$day} 16:30", 30), $workdays);
        $approved = $this->approveCells($id, '2026-04', $cells);
        self::assertSame(200, $approved->getStatusCode(), 'Zaseknutí: docházka. ' . (string) $approved->getBody());
        $this->saveHourlyEvidence($id, '2026-04', [
            ['date' => '2026-04-03', 'duration_units' => 24_000],
            ['date' => '2026-04-06', 'duration_units' => 24_000],
        ]);

        $result = $this->submission('2026-04', '2026-05-14', 'hourly-holidays');

        self::assertSame(
            ['MZDA_HODINOVA_ZAKLAD' => 3_200_000, 'NAHRADA_MZDY_SVATEK' => 320_000],
            $this->inputs($id, '2026-04-01'),
        );
        $this->assertPayroll($result['person'], [
            'taxable' => 3_520_000,
            'social' => 250_000,
            'health_employee' => 158_400,
            'health_employer' => 316_800,
            'advance' => 271_000,
            'net' => 2_840_600,
        ]);
        $xml = $result['xml'];
        self::assertStringContainsString('<form:mzdaZuctovana>32000</form:mzdaZuctovana>', $xml);
        self::assertStringContainsString('<form:tarif>32000</form:tarif>', $xml);
        self::assertStringContainsString('<form:nahrady><form:mzdyZuctovane>3200</form:mzdyZuctovane><form:svatky>3200</form:svatky></form:nahrady>', $xml);
        self::assertStringContainsString('<form:vydelekPrumernyHod>200.00</form:vydelekPrumernyHod>', $xml);
        self::assertStringContainsString('<form:odpracovaneHodiny><form:pocet>160.000</form:pocet>', $xml);
        self::assertStringContainsString('<form:mzdaCista>28406</form:mzdaCista>', $xml);
    }

    /**
     * Bez doložení neodpracovaných svátků se hodinová mzda nespočítá naslepo:
     * běh se zastaví a řekne, co doplnit.
     */
    public function testUnassessedHolidayStopsTheRunWithGuidance(): void
    {
        $person = $this->hire('Syntetická Nedoložená', 'female', '1993-04-18', '2026-04', 20_000, 2, 10_080_000);
        $id = $person['employment_id'];
        $workdays = self::workdays('2026-04');
        $this->publishShifts($id, [...$workdays, '2026-04-03', '2026-04-06']);
        $cells = array_map(fn (string $day): array => $this->cell($id, 'regular', "{$day} 08:00", "{$day} 16:30", 30), $workdays);
        self::assertSame(200, $this->approveCells($id, '2026-04', $cells)->getStatusCode());

        $run = $this->runPayrollMonth('2026-04-01', '2026-05-14', $this->officeId, 'hpp2-hourly-unassessed');

        self::assertNull($run['approved']);
        $blockers = array_column($run['blockers'], 'message', 'code');
        self::assertArrayHasKey('hourly_holiday_evidence_missing', $blockers, CanonicalJson::encode($run['blockers']));
        self::assertStringContainsString('neodpracované svátky', $blockers['hourly_holiday_evidence_missing']);
        self::assertSame([], $this->inputs($id, '2026-04-01'), 'Bez doložení nesmí vzniknout částečná mzda.');
    }

    /**
     * Březen 2026 (22 pracovních dnů, 176 h, bez svátku), sazba 130 Kč/h pod
     * minimální mzdou, průměrný výdělek pro 1. čtvrtletí 68 040 / 504 h
     * = 135 Kč/h. Dvě noční směny (16 h v noční době) a sobotní přesčas 8 h.
     *
     * Ruční přepočet:
     *  - mzda 176 × 130 = 22 880 Kč;
     *  - dosažená mzda za přesčas 8 × 130 = 1 040; příplatek 8 × 135 × 25 %
     *    = 270; noc 16 × 135 × 10 % = 216; sobota 8 × 135 × 10 % = 108;
     *  - doplatek do minimální mzdy jen z běžné doby a bez mzdy za přesčas
     *    a příplatků (§ 111 odst. 1 ZP): 176 × 134,40 = 23 654,40 − 22 880
     *    = 774,40 → 775 Kč;
     *  - hrubá 22 880 + 1 040 + 270 + 216 + 108 + 775 = 25 289 Kč;
     *  - SP 25 289 × 7,1 % = 1 795,52 → 1 796; ZP 25 289 × 13,5 % = 3 414,02
     *    → 3 415, zaměstnanec třetina 1 138,33 → 1 139;
     *  - záloha: základ 25 300 × 15 % = 3 795 − 2 570 = 1 225;
     *  - čistá 25 289 − 1 796 − 1 139 − 1 225 = 21 129 Kč.
     */
    public function testBelowMinimumRateGetsTopUpWithoutCountingOvertimeAndPremiums(): void
    {
        $person = $this->hire('Syntetická Minimální', 'female', '1991-06-24', '2026-03', 13_000, 1, 6_804_000);
        $id = $person['employment_id'];
        $cells = [];
        foreach (self::workdays('2026-03') as $day) {
            if (in_array($day, ['2026-03-10', '2026-03-11'], true)) {
                // Noční směna 22:00 až 6:30 s půlhodinovou přestávkou: 8 h v noční době.
                $next = (new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
                $cells[] = $this->cell($id, 'regular', "{$day} 22:00", "{$next} 06:30", 30);
                continue;
            }
            $cells[] = $this->cell($id, 'regular', "{$day} 08:00", "{$day} 16:30", 30);
        }
        // Sobota 14. 3.: 8 h přesčasu, noc a víkend se u hodinové mzdy odvodí ze záznamů.
        $cells[] = $this->cell($id, 'overtime', '2026-03-14 08:00', '2026-03-14 16:30', 30);
        $approved = $this->approveCells($id, '2026-03', $cells);
        self::assertSame(200, $approved->getStatusCode(), 'Zaseknutí: docházka. ' . (string) $approved->getBody());

        $run = $this->runPayrollMonth('2026-03-01', '2026-04-14', $this->officeId, 'hpp2-hourly-minimum');

        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        self::assertSame([
            'DOPLATEK_MINIMALNI_MZDA' => 77_500,
            'MZDA_DOSAZENA_PRESCAS' => 104_000,
            'MZDA_HODINOVA_ZAKLAD' => 2_288_000,
            'PRIPLATEK_NOCNI' => 21_600,
            'PRIPLATEK_PRESCAS' => 27_000,
            'PRIPLATEK_VIKEND' => 10_800,
        ], $this->inputs($id, '2026-03-01'));
        $this->assertPayroll($run['approved']->revision['result_snapshot']['statutory']['people'][0], [
            'taxable' => 2_528_900,
            'social' => 179_600,
            'health_employee' => 113_900,
            'health_employer' => 227_600,
            'advance' => 122_500,
            'net' => 2_112_900,
        ]);
    }

    /**
     * Duben 2026, sazba 200 Kč/h, průměrný výdělek 105 840 / 504 h = 210 Kč/h,
     * sjednaný příplatek za svátek místo náhradního volna (§ 115 odst. 2 ZP).
     * Velikonoční pondělí 6. 4. odpracováno (8 h), Velký pátek 3. 4. ne.
     *
     * Ruční přepočet:
     *  - mzda 21 dnů × 8 h = 168 × 200 = 33 600 Kč (dosažená mzda i za svátek);
     *  - příplatek za práci ve svátek 8 × 210 = 1 680 Kč;
     *  - náhrada za neodpracovaný svátek 8 × 210 = 1 680 Kč (§ 115 odst. 3);
     *  - hrubá 36 960; SP 36 960 × 7,1 % = 2 624,16 → 2 625; ZP 4 989,60
     *    → 4 990, zaměstnanec 1 663,33 → 1 664, zaměstnavatel 3 326;
     *  - záloha: základ 37 000 × 15 % = 5 550 − 2 570 = 2 980;
     *  - čistá 36 960 − 2 625 − 1 664 − 2 980 = 29 691 Kč.
     */
    public function testWorkedHolidayWithAgreedSurchargeAndUnworkedHoliday(): void
    {
        $person = $this->hire('Syntetický Svátkový', 'male', '1986-12-01', '2026-04', 20_000, 2, 10_584_000);
        $id = $person['employment_id'];
        $this->agreeSurchargePolicy($id, 'surcharge');
        $days = [...self::workdays('2026-04'), '2026-04-06'];
        $this->publishShifts($id, [...$days, '2026-04-03']);
        $cells = array_map(fn (string $day): array => $this->cell($id, 'regular', "{$day} 08:00", "{$day} 16:30", 30), $days);
        // Práce ve svátek se neodvozuje, zapisuje se příznakem k odpracované době.
        $cells[] = $this->cell($id, 'holiday', '2026-04-06 08:00', '2026-04-06 16:00', 0);
        $approved = $this->approveCells($id, '2026-04', $cells);
        self::assertSame(200, $approved->getStatusCode(), 'Zaseknutí: docházka. ' . (string) $approved->getBody());
        $this->saveHourlyEvidence($id, '2026-04', [['date' => '2026-04-03', 'duration_units' => 24_000]]);

        $result = $this->submission('2026-04', '2026-05-14', 'hourly-worked-holiday');

        self::assertSame(
            ['MZDA_HODINOVA_ZAKLAD' => 3_360_000, 'NAHRADA_MZDY_SVATEK' => 168_000, 'PRIPLATEK_SVATEK' => 168_000],
            $this->inputs($id, '2026-04-01'),
        );
        $this->assertPayroll($result['person'], [
            'taxable' => 3_696_000,
            'social' => 262_500,
            'health_employee' => 166_400,
            'health_employer' => 332_600,
            'advance' => 298_000,
            'net' => 2_969_100,
        ]);
        $xml = $result['xml'];
        self::assertStringContainsString('<form:mzdaZuctovana>35280</form:mzdaZuctovana>', $xml);
        self::assertStringContainsString('<form:tarif>33600</form:tarif>', $xml);
        self::assertStringContainsString('<form:priplatky><form:celkem>1680</form:celkem><form:svatek>1680</form:svatek></form:priplatky>', $xml);
        self::assertStringContainsString('<form:svatky>1680</form:svatky>', $xml);
        self::assertStringContainsString('<form:vydelekPrumernyHod>210.00</form:vydelekPrumernyHod>', $xml);
        self::assertStringContainsString('<form:odpracovaneHodiny><form:pocet>168.000</form:pocet>', $xml);
    }

    /**
     * Hodinová mzda převzatá z docházky částkou (složka „Hodinová mzda podle
     * docházky“ z importu Vzoru MyÚčto) a odměna, duben 2026.
     *
     * Ruční přepočet: 160 h × 185 Kč = 29 600 Kč a odměna 1 000 Kč, hrubá
     * 30 600; SP 30 600 × 7,1 % = 2 172,60 → 2 173; ZP 4 131 → 1 377 / 2 754;
     * záloha 30 600 × 15 % = 4 590 − 2 570 = 2 020; čistá 30 600 − 2 173
     * − 1 377 − 2 020 = 25 030 Kč. JMHZ: tarif 29 600 (10329), nepravidelná
     * odměna 1 000 (10331), mzda zúčtovaná 30 600 (10328).
     */
    public function testImportedHourlyWageAmountFlowsToTaxInsuranceAndMonthlyReport(): void
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment($this->officeId, 'Syntetická Docházková', $sequence, 'hpp', 'employment', 40, 10_000, true, '2026-04-01');
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => 'Syntetická',
            'last_name' => 'Docházková',
            'birth_date' => '1995-10-10',
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber('1995-10-10', 'female', $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));
        $this->average($person['employment_id'], 9_324_000, 30_240, 2);
        // Stejná deklarace složky, jakou zakládá import docházky (druh hodinová mzda, jednorázová).
        $hourly = $this->createComponent('MZDA_HODINOVA_DOCH', 'hourly_wage', 'one_off');
        $mappings = $this->container->get(PayrollComponentJmhzMappingDefaults::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingDefaults::class, $mappings);
        $mappings->apply($this->supplierId, [$hourly]);
        $this->createApprovedInput($person, $hourly, 2_960_000, 'attendance:2026-04:' . $person['employment_id'] . ':MZDA_HODINOVA_DOCH', '2026-04-01');
        $this->createApprovedInput($person, $this->componentId('ODMENA'), 100_000, 'attendance:2026-04:' . $person['employment_id'] . ':ODMENA', '2026-04-01');
        $approved = $this->approveTimeMonth($person['employment_id'], '2026-04', self::workdays('2026-04'));
        self::assertSame(200, $approved->getStatusCode(), 'Zaseknutí: docházka. ' . (string) $approved->getBody());

        $result = $this->submission('2026-04', '2026-05-14', 'hourly-imported');

        $this->assertPayroll($result['person'], [
            'taxable' => 3_060_000,
            'social' => 217_300,
            'health_employee' => 137_700,
            'health_employer' => 275_400,
            'advance' => 202_000,
            'net' => 2_503_000,
        ]);
        $xml = $result['xml'];
        self::assertStringContainsString('<form:mzdaZuctovana>30600</form:mzdaZuctovana>', $xml);
        self::assertStringContainsString('<form:tarif>29600</form:tarif>', $xml);
        self::assertStringContainsString('<form:odmenyNepravidelne>1000</form:odmenyNepravidelne>', $xml);
    }

    /**
     * Hodinově odměňovaný zaměstnanec na plný úvazek s průměrným výdělkem
     * pro čtvrtletí daného měsíce.
     *
     * @return array{employee_id:int,employment_id:int,name:string,average_id:int,sequence:int}
     */
    private function hire(string $name, string $sex, string $birthDate, string $period, int $hourlyRateMinor, int $quarter, int $averageGrossMinor): array
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment($this->officeId, $name, $sequence, 'hpp', 'employment', 40, 10_000, true, "{$period}-01");
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET remuneration_type = "hourly", hourly_rate_minor = ?, monthly_gross_minor = NULL,
                    remuneration_basis_kind = "contract", remuneration_basis = "Syntetická pracovní smlouva"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$hourlyRateMinor, $this->supplierId, $person['employment_id']]);
        $averageId = $this->average($person['employment_id'], $averageGrossMinor, 30_240, $quarter);

        return $person + ['average_id' => $averageId, 'sequence' => $sequence];
    }

    /** @param list<array<string,mixed>> $cells */
    private function approveCells(int $employmentId, string $period, array $cells): ResponseInterface
    {
        $this->ensureRegularCalendar($employmentId);
        $version = $this->timeMonthRowVersion($employmentId, $period);
        foreach ($cells as &$cell) {
            $cell['month_row_version'] = $version;
        }
        unset($cell);
        $batch = $this->time->entryBatch(
            $this->request('POST', '/api/payroll/time/entries/batch')
                ->withQueryParams(['period' => $period, 'employment_id' => (string) $employmentId])
                ->withParsedBody(['period' => $period, 'cells' => $cells]),
            new Response(),
        );
        self::assertSame(200, $batch->getStatusCode(), (string) $batch->getBody());
        $body = $this->json($batch);
        self::assertSame([], $body['failures'], CanonicalJson::encode($body['failures']));

        return $this->approveTimeMonth($employmentId, $period, []);
    }

    /**
     * Doložení neodpracovaných svátků v evidenci hodinové mzdy.
     *
     * @param list<array{date:string,duration_units:int}> $nonworkedHolidays 3 000 jednotek = 1 hodina
     */
    private function saveHourlyEvidence(int $employmentId, string $period, array $nonworkedHolidays): void
    {
        $evidence = $this->container->get(PayrollHourlyEvidenceService::class);
        self::assertInstanceOf(PayrollHourlyEvidenceService::class, $evidence);
        $view = $evidence->view($this->supplierId, $employmentId, "{$period}-01");
        $evidence->save($this->supplierId, $employmentId, "{$period}-01", [
            'row_version' => $view['row_version'],
            'reason' => 'Syntetické doložení svátků podle rozvrhu směn',
            'work_source_hash' => $view['work_source_hash'],
            'premium_segments' => [],
            'nonworked_holidays' => $nonworkedHolidays,
            'holiday_time_off_grants' => [],
            'overtime_time_off_allocations' => [],
        ], $this->actors[0]);
    }

    /** Průměrný výdělek pro čtvrtletí z předchozího čtvrtletí jako rozhodného období (§ 354 odst. 1 ZP). */
    private function average(int $employmentId, int $grossMinor, int $workedMinutes, int $quarter): int
    {
        $created = $this->absences->createAverage(
            $this->request('POST', '/api/payroll/absences/average')->withParsedBody([
                'employment_id' => $employmentId,
                'applicable_year' => 2026,
                'applicable_quarter' => $quarter,
                'decisive_from' => $quarter === 1 ? '2025-10-01' : '2026-01-01',
                'decisive_to' => $quarter === 1 ? '2025-12-31' : '2026-03-31',
                'gross_earnings_minor' => $grossMinor,
                'longer_period_allocated_minor' => 0,
                'worked_minutes' => $workedMinutes,
                'worked_days' => 63,
                'probable_hourly_minor' => null,
                'rationale' => null,
            ]),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $average = $this->json($created)['snapshot'];
        $approved = $this->absences->approveAverage(
            $this->request('POST', '/api/payroll/absences/average/approve')->withParsedBody(['row_version' => $average['row_version']]),
            new Response(),
            ['id' => (string) $average['id']],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());

        return (int) $average['id'];
    }

    /** @return array<string,mixed> */
    private function cell(int $employmentId, string $category, string $localStart, string $localEnd, int $breakMinutes): array
    {
        $zone = new \DateTimeZone('Europe/Prague');

        return [
            'employment_id' => $employmentId,
            'starts_at' => (new \DateTimeImmutable($localStart, $zone))->format('Y-m-d\TH:i:sP'),
            'ends_at' => (new \DateTimeImmutable($localEnd, $zone))->format('Y-m-d\TH:i:sP'),
            'timezone' => 'Europe/Prague',
            'category' => $category,
            'break_minutes' => $breakMinutes,
            'row_version' => 0,
            'month_row_version' => 0,
            'supersedes_id' => null,
        ];
    }

    /** @return array<string,int> kód složky => součet platných vstupů v haléřích */
    private function inputs(int $employmentId, string $periodStart): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT component.code, SUM(input.amount_minor)
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ? AND input.period_start = ?
                AND input.status IN ("approved", "locked")
              GROUP BY component.code ORDER BY component.code',
        );
        $statement->execute([$this->supplierId, $employmentId, $periodStart]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    private function agreeSurchargePolicy(int $employmentId, string $holidayMode): void
    {
        $policies = $this->container->get(PayrollEmploymentSurchargePolicyAction::class);
        self::assertInstanceOf(PayrollEmploymentSurchargePolicyAction::class, $policies);
        $response = $policies->create(
            $this->request('POST', "/api/payroll/employments/{$employmentId}/surcharge-policies")->withParsedBody([
                'valid_from' => '2026-01-01',
                'overtime_mode' => 'surcharge',
                'holiday_mode' => $holidayMode,
                'agreement_reference' => 'Syntetická pracovní smlouva čl. IV',
            ]),
            new Response(),
            ['id' => (string) $employmentId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * Běh, příprava JMHZ a dry-run; vrací zákonný výsledek první osoby a XML.
     *
     * @return array{person:array<string,mixed>,xml:string,revision_id:int}
     */
    private function submission(string $period, string $payday, string $scenario): array
    {
        $run = $this->runPayrollMonth("{$period}-01", $payday, $this->officeId, "hpp2-{$scenario}");
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];
        $people = $run['approved']->revision['result_snapshot']['statutory']['people'];

        $preparation = $this->prepareJmhz($revisionId, "hpp2-{$scenario}");
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
        self::assertSame('source_ready', $preparation['body']['readiness_status'], CanonicalJson::encode($preparation['body']['issues'] ?? []));
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], CanonicalJson::encode($tested['body']));
        self::assertSame('dry_run_valid', $tested['body']['status'], CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']));
        self::assertTrue($tested['body']['controls']['submittable']);

        return [
            'person' => $people[0],
            'xml' => (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']),
            'revision_id' => $revisionId,
        ];
    }

    /**
     * @param array<string,mixed> $person zákonný výsledek osoby
     * @param array{taxable:int,social:int,health_employee:int,health_employer:int,advance:int,net:int} $expected
     */
    private function assertPayroll(array $person, array $expected): void
    {
        self::assertSame($expected, [
            'taxable' => $person['income_tax']['advance_tax']['taxable_income_minor_units'],
            'social' => $person['social_insurance']['employee_contribution_minor_units'],
            'health_employee' => $person['health_insurance']['employee_contribution_minor_units'],
            'health_employer' => $person['health_insurance']['employer_contribution_minor_units'],
            'advance' => $person['income_tax']['advance_tax']['tax_after_credits_minor_units'],
            'net' => $person['net_payable_minor_units'],
        ], 'Výpočet nesouhlasí s ručním přepočtem.');
    }
}
