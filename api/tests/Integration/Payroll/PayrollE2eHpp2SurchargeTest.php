<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentSurchargePolicyAction;
use MyInvoice\Action\Payroll\PayrollQuickInputsAction;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunValidationOverrideService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * Příplatky ke mzdě u zaměstnance s měsíční mzdou (§ 114 až § 118 ZP), duben 2026.
 *
 * Společné podklady ručních přepočtů:
 *  - měsíční mzda 42 000 Kč, týdenní pracovní doba 40 h (pondělí až pátek po 8 h);
 *  - duben 2026 má 22 pracovních dnů včetně dvou svátků (Velký pátek 3. 4.
 *    a Velikonoční pondělí 6. 4.): fond včetně svátků 176 h, bez svátků 160 h;
 *  - průměrný výdělek pro 2. čtvrtletí 2026 z rozhodného období 1. čtvrtletí
 *    (§ 354 odst. 1 ZP): 126 000 Kč / 504 h = 250 Kč/h;
 *  - sazby příplatků podle ZP ve znění účinném v roce 2026: přesčas nejméně
 *    25 % průměrného výdělku a k tomu dosažená mzda (§ 114 odst. 1), svátek
 *    příplatek nejméně ve výši průměrného výdělku, byl-li sjednán místo
 *    náhradního volna (§ 115 odst. 2), noc 10 % (§ 116), sobota a neděle 10 %
 *    (§ 118 odst. 1);
 *  - dosažená mzda za hodinu přesčasu u měsíční mzdy = měsíční mzda / fond
 *    měsíce včetně svátků: 42 000 / 176 = 238,636 Kč;
 *  - mzda se zaokrouhluje na celé koruny nahoru (§ 142 odst. 2 ZP);
 *  - sada 2026: SP zaměstnanec 7,1 %, ZP 13,5 % (zaměstnanec třetina),
 *    záloha 15 % ze základu zaokrouhleného na stokoruny nahoru, sleva
 *    na poplatníka 2 570 Kč.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eHpp2SurchargeTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';
    private const PAYDAY = '2026-05-14';

    private int $officeId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('PRI', 'Syntetická účtárna příplatků', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        // Firma má výchozí číselník složek a jejich zařazení do JMHZ jako po zapnutí mezd.
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
     * Rychlý měsíční vstup: mzda 42 000 Kč, 6 h přesčasu, 8 h noční práce
     * a 4 h práce v sobotu. Neodpracované svátky měsíční mzdu nekrátí
     * (§ 115 odst. 3 ZP: žádná mzda neušla).
     *
     * Ruční přepočet:
     *  - dosažená mzda za přesčas 42 000 / 176 × 6 = 1 431,82 → 1 432 Kč;
     *  - příplatek za přesčas 250 × 25 % × 6 = 375 Kč;
     *  - noc 250 × 10 % × 8 = 200 Kč; sobota 250 × 10 % × 4 = 100 Kč;
     *  - hrubá mzda 42 000 + 1 432 + 375 + 200 + 100 = 44 107 Kč;
     *  - SP 44 107 × 7,1 % = 3 131,60 → 3 132; ZP 44 107 × 13,5 % = 5 954,45
     *    → 5 955, zaměstnanec 1 985, zaměstnavatel 3 970;
     *  - záloha: základ 44 200 × 15 % = 6 630 − 2 570 = 4 060;
     *  - čistá 44 107 − 3 132 − 1 985 − 4 060 = 34 930 Kč.
     */
    public function testQuickInputOvertimeNightAndWeekendReachMonthlyReport(): void
    {
        $person = $this->hire('Syntetický Přesčasový', 'male', '1984-03-12');
        $this->agreeMonthlyWage($person['employment_id'], 4_200_000);
        $this->ensureRegularCalendar($person['employment_id']);

        $item = $this->quickItem($person['employment_id']);
        self::assertSame(4_200_000, $item['base_amount_minor'], 'Svátky v pracovní dny měsíční mzdu nekrátí.');
        self::assertNull($item['base_proration']);
        self::assertSame(25_000, $item['overtime_hourly_rate_minor']);

        $saved = $this->saveQuick($item, 4_200_000, 6_000, ['night' => ['hours_milli' => 8_000], 'weekend' => ['hours_milli' => 4_000]]);
        self::assertSame(200, $saved->getStatusCode(), 'Zaseknutí: rychlý vstup. ' . (string) $saved->getBody());
        self::assertSame([], $this->json($saved)['failures'] ?? []);
        self::assertSame([
            'MZDA_HODINOVA' => 143_200,
            'MZDA_MESICNI' => 4_200_000,
            'PRIPLATEK_NOCNI' => 20_000,
            'PRIPLATEK_PRESCAS' => 37_500,
            'PRIPLATEK_VIKEND' => 10_000,
        ], $this->inputs($person['employment_id']));

        $approved = $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::workdays(self::PERIOD));
        self::assertSame(200, $approved->getStatusCode(), 'Zaseknutí: docházka. ' . (string) $approved->getBody());

        // Přesčas zadaný jen rychlým vstupem docházka nenese: běh to hlásí
        // varováním s povinným potvrzením (viz PayrollE2eFix4SurchargeTest).
        // Tady jde o přesčas odpracovaný v jiném měsíci, účetní ho potvrdí.
        $result = $this->submission('quick-surcharges', ['quick_overtime_hours_not_in_attendance']);

        $this->assertPayroll($result['person'], [
            'taxable' => 4_410_700,
            'social' => 313_200,
            'health_employee' => 198_500,
            'health_employer' => 397_000,
            'advance' => 406_000,
            'net' => 3_493_000,
        ]);
        // Hlášení: dosažená mzda za přesčas je mzda za práci (10329 tarif
        // 42 000 + 1 432), příplatky mají vlastní kolonky 10333 až 10335;
        // 10286 = 10328 = 44 107. Svátky: 16 neodpracovaných hodin bez krácení mzdy.
        foreach ([
            '<form:zuctovanoCelkem>44107</form:zuctovanoCelkem>',
            '<form:zakladDane>44107</form:zakladDane>',
            '<form:danZalohaPoSleve>4060</form:danZalohaPoSleve>',
            '<form:mzdaCista>34930</form:mzdaCista>',
            '<form:mzdaZuctovana>44107</form:mzdaZuctovana>',
            '<form:tarif>43432</form:tarif>',
            '<form:prescas>375</form:prescas>',
            '<form:nocni>200</form:nocni>',
            '<form:sobotaNedele>100</form:sobotaNedele>',
            '<form:hodinyNeodpracCelkem>16.000</form:hodinyNeodpracCelkem>',
            '<form:hodinyNeodpracNahrada>16.000</form:hodinyNeodpracNahrada>',
            '<form:vydelekPrumernyHod>250.00</form:vydelekPrumernyHod>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $result['xml']);
        }
    }

    /**
     * První zadání měsíce: základ i hodiny přesčasu v jednom uložení u vztahu,
     * který nemá sjednanou částku v podmínkách. Dosažená mzda se počítá ze
     * základu zadaného v témže řádku, 42 000 / 176 × 6 = 1 431,82 → 1 432 Kč.
     */
    public function testOvertimeHoursSavedTogetherWithTheBaseUseThatBase(): void
    {
        $person = $this->hire('Syntetická Společná', 'female', '1990-07-21');
        $this->ensureRegularCalendar($person['employment_id']);
        $item = $this->quickItem($person['employment_id']);
        self::assertSame(0, $item['base_amount_minor']);

        $saved = $this->saveQuick($item, 4_200_000, 6_000, []);

        self::assertSame(200, $saved->getStatusCode(), 'Zaseknutí: rychlý vstup. ' . (string) $saved->getBody());
        self::assertSame([], $this->json($saved)['failures'] ?? [], (string) $saved->getBody());
        self::assertSame(
            ['MZDA_HODINOVA' => 143_200, 'MZDA_MESICNI' => 4_200_000, 'PRIPLATEK_PRESCAS' => 37_500],
            $this->inputs($person['employment_id']),
        );
    }

    /**
     * Měsíc s dovolenou 9. až 10. 4. (16 h): základní mzda se krátí na
     * 42 000 × 160 / 176 = 38 181,82 → 38 182 Kč a dovolenou platí náhrada
     * 16 × 250 = 4 000 Kč. Dosažená mzda za hodinu přesčasu se tím nemění:
     * sjednaná měsíční mzda / fond = 42 000 / 176, za 6 h 1 432 Kč
     * (z kráceného základu by vyšlo jen 38 182 / 176 × 6 = 1 301,66 Kč).
     */
    public function testOvertimeWageInMonthWithVacationUsesTheAgreedMonthlyWage(): void
    {
        $person = $this->hire('Syntetický Dovolenkový', 'male', '1981-11-05');
        $this->agreeMonthlyWage($person['employment_id'], 4_200_000);
        $this->ensureRegularCalendar($person['employment_id']);
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        $this->createApprovedAbsence($person['employment_id'], 'vacation', '2026-04-09', '2026-04-10', $person['average_id']);

        $item = $this->quickItem($person['employment_id']);
        self::assertSame(3_818_200, $item['base_amount_minor']);
        $saved = $this->saveQuick($item, 3_818_200, 6_000, []);

        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame([], $this->json($saved)['failures'] ?? [], (string) $saved->getBody());
        self::assertSame([
            'MZDA_HODINOVA' => 143_200,
            'MZDA_MESICNI' => 3_818_200,
            'NAHRADA_MZDY_DOVOLENA' => 400_000,
            'PRIPLATEK_PRESCAS' => 37_500,
        ], $this->inputs($person['employment_id']));
    }

    /**
     * Přesčas, noc a sobota vedené v docházce (ne v rychlém vstupu).
     * Nárok je stejný jako v prvním scénáři: k příplatkům 375 + 200 + 100 Kč
     * náleží i dosažená mzda za 6 h přesčasu 1 432 Kč (§ 114 odst. 1 ZP),
     * protože měsíční mzda kryje jen fond pracovní doby.
     */
    public function testAttendanceOvertimeAlsoPaysTheAchievedWage(): void
    {
        $person = $this->hire('Syntetický Docházkový', 'male', '1979-02-17');
        $this->agreeMonthlyWage($person['employment_id'], 4_200_000);
        $id = $person['employment_id'];
        $cells = [];
        foreach (self::workdays(self::PERIOD) as $day) {
            if ($day === '2026-04-15') {
                // Noční směna 22:00 až 6:30 s přestávkou, 8 h v noční době.
                $cells[] = $this->cell($id, 'regular', "{$day} 22:00", '2026-04-16 06:30', 30);
                $cells[] = $this->cell($id, 'night', "{$day} 22:00", '2026-04-16 06:00', 0);
                continue;
            }
            $cells[] = $this->cell($id, 'regular', "{$day} 08:00", "{$day} 16:30", 30);
        }
        $cells[] = $this->cell($id, 'overtime', '2026-04-14 16:30', '2026-04-14 18:30', 0);
        // Sobota 18. 4.: 4 h přesčasu, zároveň práce v sobotu.
        $cells[] = $this->cell($id, 'overtime', '2026-04-18 08:00', '2026-04-18 12:00', 0);
        $cells[] = $this->cell($id, 'weekend', '2026-04-18 08:00', '2026-04-18 12:00', 0);

        $approved = $this->approveCells($id, $cells);
        self::assertSame(200, $approved->getStatusCode(), 'Zaseknutí: docházka. ' . (string) $approved->getBody());
        $this->createApprovedInput($person, $this->componentId('MZDA_MESICNI'), 4_200_000, 'base-' . $id, self::PERIOD_START);

        $inputs = $this->inputs($id);
        self::assertSame(37_500, $inputs['PRIPLATEK_PRESCAS'] ?? 0);
        self::assertSame(20_000, $inputs['PRIPLATEK_NOCNI'] ?? 0);
        self::assertSame(10_000, $inputs['PRIPLATEK_VIKEND'] ?? 0);
        self::assertSame(
            4_200_000 + 143_200 + 37_500 + 20_000 + 10_000,
            array_sum($inputs),
            'Dosažená mzda za 6 h přesčasu z docházky chybí: ' . CanonicalJson::encode($inputs),
        );

        // Stejná mzda jako v prvním scénáři; hlášení navíc nese hodiny přesčasu
        // z docházky: odpracováno 20 × 8 + 6 = 166 h, z toho přesčas 6 h.
        $result = $this->submission('attendance-overtime');
        $this->assertPayroll($result['person'], [
            'taxable' => 4_410_700,
            'social' => 313_200,
            'health_employee' => 198_500,
            'health_employer' => 397_000,
            'advance' => 406_000,
            'net' => 3_493_000,
        ]);
        foreach ([
            '<form:zuctovanoCelkem>44107</form:zuctovanoCelkem>',
            '<form:mzdaZuctovana>44107</form:mzdaZuctovana>',
            '<form:tarif>43432</form:tarif>',
            '<form:pocet>166.000</form:pocet>',
            '<form:prescas>6.000</form:prescas>',
            '<form:prescas>375</form:prescas>',
            '<form:nocni>200</form:nocni>',
            '<form:sobotaNedele>100</form:sobotaNedele>',
            '<form:mzdaCista>34930</form:mzdaCista>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $result['xml']);
        }
    }

    /**
     * Práce ve svátek (Velikonoční pondělí 6. 4., 8 h) se sjednaným příplatkem
     * místo náhradního volna (§ 115 odst. 2 ZP): příplatek 8 × 250 = 2 000 Kč.
     * Měsíční mzda svátek kryje (je ve fondu), přesčas to není.
     *
     * Ruční přepočet: hrubá 42 000 + 2 000 = 44 000; SP 3 124; ZP 5 940
     * → 1 980 / 3 960; záloha 44 000 × 15 % = 6 600 − 2 570 = 4 030;
     * čistá 44 000 − 3 124 − 1 980 − 4 030 = 34 866 Kč.
     */
    public function testWorkedHolidayWithAgreedSurchargePaysFullAverage(): void
    {
        $person = $this->hire('Syntetická Sváteční', 'female', '1988-09-30');
        $this->agreeMonthlyWage($person['employment_id'], 4_200_000);
        $id = $person['employment_id'];
        $this->agreeSurchargePolicy($id, 'surcharge');

        $approved = $this->approveCells($id, $this->holidayWorkCells($id));
        self::assertSame(200, $approved->getStatusCode(), 'Zaseknutí: docházka. ' . (string) $approved->getBody());
        $this->createApprovedInput($person, $this->componentId('MZDA_MESICNI'), 4_200_000, 'base-' . $id, self::PERIOD_START);
        self::assertSame(['MZDA_MESICNI' => 4_200_000, 'PRIPLATEK_SVATEK' => 200_000], $this->inputs($id));

        $result = $this->submission('holiday-surcharge');

        $this->assertPayroll($result['person'], [
            'taxable' => 4_400_000,
            'social' => 312_400,
            'health_employee' => 198_000,
            'health_employer' => 396_000,
            'advance' => 403_000,
            'net' => 3_486_600,
        ]);
        // Hlášení: příplatek za svátek v 10336, tarif 42 000, odpracováno 21 dnů
        // (20 běžných + svátek 6. 4.), neodpracovaný zůstal jen Velký pátek (8 h).
        foreach ([
            '<form:zuctovanoCelkem>44000</form:zuctovanoCelkem>',
            '<form:mzdaZuctovana>44000</form:mzdaZuctovana>',
            '<form:tarif>42000</form:tarif>',
            '<form:svatek>2000</form:svatek>',
            '<form:danZalohaPoSleve>4030</form:danZalohaPoSleve>',
            '<form:mzdaCista>34866</form:mzdaCista>',
            '<form:dnyOdpracovanePocet>21</form:dnyOdpracovanePocet>',
            '<form:hodinyNeodpracCelkem>8.000</form:hodinyNeodpracCelkem>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $result['xml']);
        }
    }

    /**
     * Práce ve svátek bez sjednaného příplatku: zákon dává náhradní volno
     * (§ 115 odst. 1 ZP). Schválení docházky se nesmí tiše obejít bez nároku
     * a musí říct, co udělat.
     */
    public function testWorkedHolidayWithoutArrangementStopsWithGuidance(): void
    {
        $person = $this->hire('Syntetický Nesjednaný', 'male', '1992-01-09');
        $this->agreeMonthlyWage($person['employment_id'], 4_200_000);
        $id = $person['employment_id'];

        $approved = $this->approveCells($id, $this->holidayWorkCells($id));

        self::assertSame(409, $approved->getStatusCode(), (string) $approved->getBody());
        $error = $this->json($approved)['error'];
        self::assertSame('holiday_arrangement_missing', $error['code']);
        self::assertStringContainsString('2026-04-06', $error['message']);
        self::assertStringContainsString('náhradní volno, nebo příplatek', $error['message']);
    }

    /**
     * @return array{employee_id:int,employment_id:int,name:string,average_id:int,sequence:int}
     */
    private function hire(string $name, string $sex, string $birthDate, int $averageGrossMinor = 12_600_000, int $averageMinutes = 30_240): array
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment($this->officeId, $name, $sequence, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));
        $averageId = $this->average($person['employment_id'], $averageGrossMinor, $averageMinutes);

        return $person + ['average_id' => $averageId, 'sequence' => $sequence];
    }

    /** Průměrný výdělek pro 2. čtvrtletí 2026 z rozhodného období 1. čtvrtletí. */
    private function average(int $employmentId, int $grossMinor, int $workedMinutes): int
    {
        $created = $this->absences->createAverage(
            $this->request('POST', '/api/payroll/absences/average')->withParsedBody([
                'employment_id' => $employmentId,
                'applicable_year' => 2026,
                'applicable_quarter' => 2,
                'decisive_from' => '2026-01-01',
                'decisive_to' => '2026-03-31',
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

    /** @param list<array<string,mixed>> $cells */
    private function approveCells(int $employmentId, array $cells): ResponseInterface
    {
        $this->ensureRegularCalendar($employmentId);
        $version = $this->timeMonthRowVersion($employmentId, self::PERIOD);
        foreach ($cells as &$cell) {
            $cell['month_row_version'] = $version;
        }
        unset($cell);
        $batch = $this->time->entryBatch(
            $this->request('POST', '/api/payroll/time/entries/batch')
                ->withQueryParams(['period' => self::PERIOD, 'employment_id' => (string) $employmentId])
                ->withParsedBody(['period' => self::PERIOD, 'cells' => $cells]),
            new Response(),
        );
        self::assertSame(200, $batch->getStatusCode(), (string) $batch->getBody());
        $body = $this->json($batch);
        self::assertSame([], $body['failures'], CanonicalJson::encode($body['failures']));

        return $this->approveTimeMonth($employmentId, self::PERIOD, []);
    }

    /** @return array<string,int> kód složky => součet platných vstupů v haléřích */
    private function inputs(int $employmentId): array
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
        $statement->execute([$this->supplierId, $employmentId, self::PERIOD_START]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    private function agreeMonthlyWage(int $employmentId, int $monthlyMinor): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET remuneration_type = "monthly", monthly_gross_minor = ?,
                    remuneration_basis_kind = "contract", remuneration_basis = "Syntetická pracovní smlouva"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$monthlyMinor, $this->supplierId, $employmentId]);
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
     * Odpracovaný měsíc včetně Velikonočního pondělí 6. 4. (8 h práce ve svátek).
     *
     * @return list<array<string,mixed>>
     */
    private function holidayWorkCells(int $employmentId): array
    {
        $cells = [];
        foreach ([...self::workdays(self::PERIOD), '2026-04-06'] as $day) {
            $cells[] = $this->cell($employmentId, 'regular', "{$day} 08:00", "{$day} 16:30", 30);
        }
        $cells[] = $this->cell($employmentId, 'holiday', '2026-04-06 08:00', '2026-04-06 16:00', 0);

        return $cells;
    }

    /** @return array<string,mixed> řádek rychlého měsíčního vstupu */
    private function quickItem(int $employmentId): array
    {
        $response = $this->quickInputs()->list(
            $this->request('GET', '/api/payroll/quick-inputs')->withQueryParams(['period' => self::PERIOD]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        foreach ($this->json($response)['month']['items'] as $item) {
            if ($item['employment_id'] === $employmentId) {
                return $item;
            }
        }
        self::fail("Rychlý vstup neobsahuje vztah {$employmentId}.");
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,array<string,mixed>> $surcharges
     */
    private function saveQuick(array $item, int $baseMinor, ?int $overtimeHoursMilli, array $surcharges): ResponseInterface
    {
        return $this->quickInputs()->save(
            $this->request('PUT', '/api/payroll/quick-inputs')->withParsedBody([
                'period' => self::PERIOD,
                'rows' => [[
                    'employment_id' => $item['employment_id'],
                    'employment_row_version' => $item['employment_row_version'],
                    'base_amount_minor' => $baseMinor,
                    'overtime_mode' => $overtimeHoursMilli === null ? 'amount' : 'hours',
                    'overtime_hours_milli' => $overtimeHoursMilli,
                    'overtime_amount_minor' => $overtimeHoursMilli === null ? 0 : null,
                    'overtime_average_snapshot_id' => $item['overtime_average_snapshot_id'],
                    'overtime_average_snapshot_version' => $item['overtime_average_snapshot_version'],
                    'bonus_amount_minor' => 0,
                    'surcharges' => $surcharges,
                    'versions' => ['base' => null, 'overtime' => null, 'bonus' => null, 'surcharges' => []],
                ]],
            ]),
            new Response(),
        );
    }

    private function quickInputs(): PayrollQuickInputsAction
    {
        $action = $this->container->get(PayrollQuickInputsAction::class);
        self::assertInstanceOf(PayrollQuickInputsAction::class, $action);

        return $action;
    }

    /**
     * Běh, příprava JMHZ a dry-run; vrací zákonný výsledek první osoby a XML.
     *
     * @param list<string> $confirmedWarnings kódy varování, která účetní před schválením potvrdí
     * @return array{person:array<string,mixed>,xml:string,revision_id:int}
     */
    private function submission(string $scenario, array $confirmedWarnings = []): array
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "hpp2-{$scenario}");
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame($confirmedWarnings, array_column($run['warnings'], 'code'), 'Zaseknutí: varování. ' . CanonicalJson::encode($run['warnings']));
        $approved = $run['approved'];
        if ($confirmedWarnings !== []) {
            // Účetní varování vědomě potvrdí (výjimka s odůvodněním, druhý
            // uživatel) a běh schválí.
            $overrides = $this->container->get(PayrollRunValidationOverrideService::class);
            self::assertInstanceOf(PayrollRunValidationOverrideService::class, $overrides);
            $runRow = $run['calculated']->run;
            $approver = $this->createActor('override-' . $scenario);
            foreach ($run['warnings'] as $warning) {
                $granted = $overrides->grant(
                    $this->supplierId,
                    (int) $runRow['id'],
                    $warning['id'],
                    (int) $runRow['row_version'],
                    "hpp2-{$scenario}-override-{$warning['id']}",
                    $approver,
                    'Přesčas byl odpracován v březnu a zúčtován až v dubnové mzdě, dubnová docházka ho proto nenese.',
                );
                $runRow = $granted->run;
            }
            $reviewed = $this->runs->review($this->supplierId, (int) $runRow['id'], (int) $runRow['row_version'], "hpp2-{$scenario}-review", $this->actors[0]);
            $approved = $this->runs->approve($this->supplierId, (int) $runRow['id'], (int) $reviewed->run['row_version'], "hpp2-{$scenario}-approve", $this->actors[0]);
        }
        self::assertNotNull($approved);
        $revisionId = (int) $approved->revision['id'];
        $people = $approved->revision['result_snapshot']['statutory']['people'];

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
