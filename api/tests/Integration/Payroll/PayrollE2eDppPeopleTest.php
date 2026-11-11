<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Zvláštní případy brigádníků agentury na DPP za duben 2026: cizí daňový
 * rezident, cizinka bez rodného čísla, pracující důchodce, nástup a odchod
 * uprostřed měsíce, dovolená na dohodě a roční limit 300 hodin. Každý jde
 * celou cestou až po XML hlášení proti XSD a katalogu kontrol.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eDppPeopleTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';
    private const PAYDAY = '2026-05-14';

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('DPP', 'Syntetická agentura', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('ODMENA_DOHODA', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        \assert($mappings instanceof PayrollComponentJmhzMappingRepository);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Cizí daňový rezident, cizinka bez rodného čísla (jen EČP), pracující
     * starobní důchodce a dohoda s nástupem i koncem uprostřed měsíce.
     * Částky jsou přepočtené ručně (rozpis ve zprávě úkolu):
     *  - nerezident s prohlášením má základní slevu na poplatníka
     *    (§ 35ba odst. 2 ZDP omezuje nerezidentovi jen ostatní slevy),
     *  - bez prohlášení pod 12 000 Kč srážková daň 15 % (§ 6 odst. 4 ZDP),
     *  - důchodce nad limitem: sleva na pojistném 6,5 % (§ 7e z. č. 589/1992 Sb.),
     *    minimum ZP se u státního pojištěnce nepoužije (§ 3 odst. 8 z. č. 592/1992 Sb.),
     *  - dohoda 13. až 24. 4.: minimum ZP v poměru dnů 22 400 x 12 / 30 = 8 960 Kč
     *    (§ 3 odst. 9 z. č. 592/1992 Sb.), základ 15 000 Kč ho převyšuje.
     */
    public function testForeignersPensionerAndMidMonthAgreementMatchHandCalculation(): void
    {
        $pdo = $this->db->pdo();
        $nonResident = $this->hire('Norbert Nerezident', 'male', '1991-01-11', 'dpp', 'dpp', true);
        $pdo->prepare("UPDATE payroll_person_tax_residences SET residence = 'non-resident', country_code = 'SK' WHERE supplier_id = ? AND employee_id = ?")
            ->execute([$this->supplierId, $nonResident['employee_id']]);
        $pdo->prepare("UPDATE payroll_person_identity_history SET citizenship_country_code = 'SK', birth_country_code = 'SK' WHERE supplier_id = ? AND employee_id = ?")
            ->execute([$this->supplierId, $nonResident['employee_id']]);
        $foreigner = $this->hire('Olena Bezrodná', 'female', '1992-02-12', 'dpp', 'dpp', false);
        $pdo->prepare('DELETE FROM payroll_person_identifiers WHERE supplier_id = ? AND employee_id = ?')
            ->execute([$this->supplierId, $foreigner['employee_id']]);
        $this->insertPersonIdentifier($foreigner['employee_id'], 'ecp', '9252120019');
        $pdo->prepare("UPDATE payroll_person_identity_history SET citizenship_country_code = 'UA', birth_country_code = 'UA' WHERE supplier_id = ? AND employee_id = ?")
            ->execute([$this->supplierId, $foreigner['employee_id']]);
        $pensioner = $this->hirePensioner('Pavel Penzista', 'male', '1958-03-13');
        $midMonth = $this->hire('Marta Uprostřed', 'female', '1993-04-14', 'dpp', 'dpp', true);
        $this->moveStart($midMonth['employment_id'], '2026-04-13');
        $this->setEnd($midMonth['employment_id'], '2026-04-24');
        foreach ([$nonResident, $foreigner, $pensioner] as $person) {
            $this->work($person, self::workdays(self::PERIOD), ['2026-04-07', '2026-04-08']);
        }
        $this->work($midMonth, ['2026-04-14', '2026-04-15'], ['2026-04-14', '2026-04-15']);
        $this->pay($nonResident, 1_000_000);
        $this->pay($foreigner, 1_100_000);
        $this->pay($pensioner, 1_500_000);
        $this->pay($midMonth, 1_500_000);

        // Cizinka má jen EČP: kontrola běhu jí nesmí vyčítat chybějící identifikátor.
        self::assertNotContains('identifier', $this->dataGaps($foreigner['employee_id']));

        [$revisionId, $xml] = $this->submission('people', self::PERIOD_START, self::PAYDAY);

        // [hrubá, SP zaměstnanec, ZP zaměstnanec, záloha, srážková daň, čistá] v haléřích
        // Nerezident 10 000 s prohlášením: záloha 1 500 - sleva 2 570 => 0.
        self::assertSame([1_000_000, 0, 0, 0, 0, 1_000_000], $this->net($revisionId, $nonResident['employee_id']));
        // 11 000 bez prohlášení pod 12 000: srážková daň 15 % = 1 650.
        self::assertSame([1_100_000, 0, 0, 0, 165_000, 935_000], $this->net($revisionId, $foreigner['employee_id']));
        // Důchodce 15 000: SP 7,1 % = 1 065 - sleva 6,5 % = 975 => 90; ZP 4,5 % = 675;
        // záloha 2 250 - 2 570 => 0; čistá 15 000 - 90 - 675 = 14 235.
        self::assertSame([1_500_000, 9_000, 67_500, 0, 0, 1_423_500], $this->net($revisionId, $pensioner['employee_id']));
        $health = $this->personResult($revisionId, 'health_insurance', $pensioner['employee_id']);
        self::assertSame([135_000, 0, 0], [
            (int) $health['employer_contribution_minor_units'],
            (int) $health['employer_minimum_top_up_minor_units'],
            (int) $health['effective_minimum_minor_units'],
        ]);
        // Dohoda 13. až 24. 4. za 15 000: SP 1 065, ZP 675, minimum ZP 8 960 bez doplatku.
        self::assertSame([1_500_000, 106_500, 67_500, 0, 0, 1_326_000], $this->net($revisionId, $midMonth['employee_id']));
        $health = $this->personResult($revisionId, 'health_insurance', $midMonth['employee_id']);
        self::assertSame([12, 896_000, 135_000, 0], [
            (int) $health['employment_calendar_days'],
            (int) $health['effective_minimum_minor_units'],
            (int) $health['employer_contribution_minor_units'],
            (int) $health['employer_minimum_top_up_minor_units'],
        ]);

        self::assertSame(4, substr_count($xml, '</formularOsoby>'));
        self::assertSame(4, substr_count($xml, '<primarniPpv>true</primarniPpv>'));
        // Základní sleva u tří osob s prohlášením včetně nerezidenta, bonus nikde.
        self::assertSame(3, substr_count($xml, '<form:prohlaseniPoplatnikaDane><form:zakladniSleva>2570</form:zakladniSleva></form:prohlaseniPoplatnikaDane>'));
        self::assertStringNotContainsString('<form:danBonus>', $xml);
        self::assertStringContainsString('<form:zvlastniSazbaDane><form:zakladDane>11000</form:zakladDane><form:srazenaDan>1650</form:srazenaDan></form:zvlastniSazbaDane>', $xml);
        // Sleva pracujícího důchodce ve formuláři i v přehledu o pojistném:
        // základ 30 000, zaměstnavatel 24,8 % = 7 440, zaměstnanci 2 x 1 065,
        // úhrada 9 570 - 975 = 8 595.
        self::assertStringContainsString('<form:slevaZamestnanceEvidovana>true</form:slevaZamestnanceEvidovana><form:slevaZamestnance><form:vyseSlevy>975</form:vyseSlevy></form:slevaZamestnance>', $xml);
        self::assertStringContainsString(
            '<pvpoj:zakladZamestnavateleA>30000</pvpoj:zakladZamestnavateleA><pvpoj:pojistneZamestnavateleA>7440</pvpoj:pojistneZamestnavateleA>'
                . '<pvpoj:pojistneZamestnavateleCelkem>7440</pvpoj:pojistneZamestnavateleCelkem><pvpoj:pojistneZamestnance>2130</pvpoj:pojistneZamestnance>'
                . '<pvpoj:pojistneCelkem>9570</pvpoj:pojistneCelkem>',
            $xml,
        );
        self::assertStringContainsString('<pvpoj:pojistneSleva>975</pvpoj:pojistneSleva></pvpoj:slevyZamestnancu><pvpoj:pojistneUhrada>8595</pvpoj:pojistneUhrada>', $xml);
        // Pojištění dohody uprostřed měsíce jen za dny trvání.
        self::assertStringContainsString('<form:pojisteniOd>2026-04-13</form:pojisteniOd><form:pojisteniDo>2026-04-24</form:pojisteniDo>', $xml);
        self::assertStringContainsString('<form:platnostOd>2026-04-13</form:platnostOd><form:platnostDo>2026-04-24</form:platnostDo><form:pocetDnu>12</form:pocetDnu><form:vymerovaciZaklad>15000</form:vymerovaciZaklad>', $xml);
    }

    /**
     * Dovolená na DPP a roční limit 300 hodin (§ 75 a § 77 odst. 8 ZP).
     * Náhrada za den dovolené se směnou 8 h při pravděpodobném výdělku
     * 200 Kč/h je 1 600 Kč a hlášení ji vede zvlášť od odměny (10260, 10337).
     * Kdo za rok odpracuje na dohodách 320 h, dostane v kontrole běhu
     * upozornění s odkazem na podmínky vztahu; mzda se přesto spočítá.
     */
    public function testVacationOnAgreementAndAnnualHoursOverTheLimit(): void
    {
        $vacation = $this->hire('Vanda Dovolená', 'female', '1994-05-15', 'dpp', 'dpp', true);
        $this->publishShifts($vacation['employment_id'], self::workdays(self::PERIOD));
        $this->probableAverage($vacation['employment_id'], 2, 20_000);
        $averageId = (int) $this->scalar(
            'SELECT id FROM payroll_average_earning_snapshots WHERE supplier_id = ? AND employment_id = ? ORDER BY id DESC LIMIT 1',
            [$this->supplierId, $vacation['employment_id']],
        );
        $this->createApprovedAbsence($vacation['employment_id'], 'vacation', '2026-04-09', '2026-04-09', $averageId);
        self::assertSame(200, $this->approveTimeMonth($vacation['employment_id'], self::PERIOD, ['2026-04-07', '2026-04-08'], dailyMinutes: 240)->getStatusCode());
        $this->pay($vacation, 800_000);

        $overLimit = $this->hire('Hynek Třistahodin', 'male', '1990-06-16', 'dpp', 'dpp', true);
        foreach (['2026-01', '2026-02', '2026-03'] as $period) {
            // 13 dnů po 8 h = 104 h měsíčně, za čtvrtletí 312 h.
            $this->publishShifts($overLimit['employment_id'], self::workdays($period));
            $response = $this->approveTimeMonth($overLimit['employment_id'], $period, array_slice(self::workdays($period), 0, 13), dailyMinutes: 480);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        }
        $this->publishShifts($overLimit['employment_id'], self::workdays(self::PERIOD));
        self::assertSame(200, $this->approveTimeMonth($overLimit['employment_id'], self::PERIOD, ['2026-04-07'], dailyMinutes: 480)->getStatusCode());
        $this->probableAverage($overLimit['employment_id'], 2, 20_000);
        $this->pay($overLimit, 200_000);

        $findings = $this->container->get(PayrollRunReadinessService::class)
            ->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY, $this->officeId)['findings'];
        $hours = array_values(array_filter($findings, static fn (array $f): bool => $f['code'] === 'dpp_annual_hours_exceeded'));
        self::assertCount(1, $hours, CanonicalJson::encode(array_column($findings, 'code')));
        self::assertSame('warning', $hours[0]['severity']);
        self::assertStringContainsString('celkem 320 h', $hours[0]['message']);
        self::assertStringContainsString('§ 75 zákoníku práce', $hours[0]['message']);
        self::assertSame(
            "/payroll/people?person={$overLimit['employee_id']}&employment={$overLimit['employment_id']}&panel=employment_terms",
            $hours[0]['remediation_path'],
        );

        [$revisionId, $xml] = $this->submission('vacation', self::PERIOD_START, self::PAYDAY);
        // 8 000 odměna + 1 600 náhrada = 9 600 pod limitem s prohlášením:
        // záloha 15 % z 9 600 = 1 440 - 2 570 => 0.
        self::assertSame([960_000, 0, 0, 0, 0, 960_000], $this->net($revisionId, $vacation['employee_id']));
        self::assertSame([200_000, 0, 0, 0, 0, 200_000], $this->net($revisionId, $overLimit['employee_id']));
        self::assertStringContainsString(
            '<form:neodpracovaneHodiny><form:hodinyNeodpracCelkem>8.000</form:hodinyNeodpracCelkem><form:hodinyNeodpracNahrada>8.000</form:hodinyNeodpracNahrada>'
                . '<form:hodinyNeodpracDovol>8.000</form:hodinyNeodpracDovol></form:neodpracovaneHodiny>',
            $xml,
        );
        self::assertStringContainsString('<form:mzdaZuctovana>8000</form:mzdaZuctovana>', $xml);
        self::assertStringContainsString('<form:nahrady><form:mzdyZuctovane>1600</form:mzdyZuctovane><form:dovolena>1600</form:dovolena></form:nahrady>', $xml);
        self::assertStringContainsString('<form:zakladDane>9600</form:zakladDane>', $xml);
    }

    /**
     * Docházka dvou půldnů a pravděpodobný výdělek 200 Kč/h.
     *
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @param list<string> $shiftDays
     * @param list<string> $workedDays
     */
    private function work(array $person, array $shiftDays, array $workedDays): void
    {
        $this->publishShifts($person['employment_id'], $shiftDays);
        $response = $this->approveTimeMonth($person['employment_id'], self::PERIOD, $workedDays, dailyMinutes: 240);
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: docházka. ' . (string) $response->getBody());
        $this->probableAverage($person['employment_id'], 2, 20_000);
    }

    /**
     * Chybějící zákonné údaje osoby podle kontroly běhu.
     *
     * @return list<string>
     */
    private function dataGaps(int $employeeId): array
    {
        $findings = $this->container->get(PayrollRunReadinessService::class)
            ->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY, $this->officeId)['findings'];
        foreach ($findings as $finding) {
            foreach ($finding['code'] === 'person_data_gap' ? $finding['entities'] : [] as $entity) {
                if ((int) $entity['entity_id'] === $employeeId) {
                    return $entity['gaps'];
                }
            }
        }

        return [];
    }

    /**
     * DPP poživatele starobního důchodu s ověřenou slevou na pojistném.
     *
     * @return array{employee_id:int,employment_id:int,name:string}
     */
    private function hirePensioner(string $name, string $sex, string $birthDate): array
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment($this->officeId, $name, $sequence, 'dpp', 'dpp', 10, 2_500, true, self::PERIOD_START, socialDiscountStatus: 'verified');
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $firstName, 'last_name' => $lastName, 'birth_date' => $birthDate, 'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));

        return $person;
    }

    private function moveStart(int $employmentId, string $startOn): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employments SET start_date = ?, actual_start_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$startOn, $startOn, $this->supplierId, $employmentId]);
        $pdo->prepare(
            'UPDATE payroll_employment_terms SET effective_from = ?, planned_start_on = ?, actual_start_on = ?
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$startOn, $startOn, $startOn, $this->supplierId, $employmentId]);
    }

    private function setEnd(int $employmentId, string $endOn): void
    {
        $this->db->pdo()->prepare('UPDATE payroll_employments SET end_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$endOn, $this->supplierId, $employmentId]);
        $this->db->pdo()->prepare('UPDATE payroll_employment_terms SET fixed_term_end_on = ? WHERE supplier_id = ? AND employment_id = ?')
            ->execute([$endOn, $this->supplierId, $employmentId]);
    }

    /** @return array{0:int,1:string} revize a XML bez mezer */
    private function submission(string $key, string $periodStart, string $payday): array
    {
        $run = $this->runPayrollMonth($periodStart, $payday, $this->officeId, "dpp-{$key}");
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování. ' . CanonicalJson::encode($run['warnings']));
        $revisionId = (int) $run['approved']->revision['id'];
        $preparation = $this->prepareJmhz($revisionId, "dpp-{$key}");
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
        self::assertSame('source_ready', $preparation['body']['readiness_status'], 'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']['issues'] ?? []));
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], CanonicalJson::encode($tested['body']));
        self::assertSame('dry_run_valid', $tested['body']['status'], 'Zaseknutí: XSD nebo kontroly. ' . CanonicalJson::encode(array_diff_key($tested['body'], ['xml' => true])));
        self::assertTrue($tested['body']['controls']['submittable']);

        return [$revisionId, (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml'])];
    }

    private function probableAverage(int $employmentId, int $quarter, int $hourlyMinor, int $year = 2026): mixed
    {
        $from = date('Y-m-d', strtotime(sprintf('%04d-%02d-01', $year, ($quarter - 1) * 3 + 1) . ' -3 months'));
        $to = date('Y-m-t', strtotime($from . ' +2 months'));
        $response = $this->absences->createAverage(
            $this->request('POST', '/api/payroll/absences/average')->withParsedBody([
                'employment_id' => $employmentId,
                'applicable_year' => $year,
                'applicable_quarter' => $quarter,
                'decisive_from' => $from,
                'decisive_to' => $to,
                'gross_earnings_minor' => 0,
                'longer_period_allocated_minor' => 0,
                'worked_minutes' => 0,
                'worked_days' => 0,
                'probable_hourly_minor' => $hourlyMinor,
                'rationale' => 'Pravděpodobný výdělek ze sjednané hodinové odměny dohody.',
            ]),
            new \Slim\Psr7\Response(),
        );
        if ($response->getStatusCode() !== 201) {
            return (string) $response->getBody();
        }
        $average = $this->json($response)['snapshot'];
        $approved = $this->absences->approveAverage(
            $this->request('POST', '/api/payroll/absences/average/approve')->withParsedBody(['row_version' => $average['row_version']]),
            new \Slim\Psr7\Response(),
            ['id' => (string) $average['id']],
        );

        return [$approved->getStatusCode(), $average['average_hourly_minor'] ?? null, (string) $approved->getBody()];
    }

    /** @return array{employee_id:int,employment_id:int,name:string,sequence:int} */
    private function hire(string $name, string $sex, string $birthDate, string $employmentType, string $relationType, bool $declaration, int $weekly = 10, int $workload = 2_500): array
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            $employmentType,
            $relationType,
            $weekly,
            $workload,
            $declaration,
            self::PERIOD_START,
        );
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));

        return $person + ['sequence' => $sequence];
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function pay(array $person, int $amountMinor, string $periodStart = self::PERIOD_START): void
    {
        $this->createApprovedInput($person, $this->baseComponentId, $amountMinor, 'odmena-' . $person['employment_id'] . '-' . $periodStart, $periodStart);
    }

    /** @return array{0:int,1:int,2:int,3:int,4:int,5:int} */
    private function net(int $revisionId, int $employeeId): array
    {
        $r = $this->personResult($revisionId, 'net_pay', $employeeId);
        self::assertIsArray($r);

        return [
            (int) $r['cash_income_minor_units'],
            (int) $r['employee_social_minor_units'],
            (int) $r['employee_health_minor_units'],
            (int) $r['advance_tax_minor_units'],
            (int) $r['withholding_tax_minor_units'],
            (int) $r['net_payable_minor_units'],
        ];
    }

    /** @return array<string,mixed>|null */
    private function personResult(int $revisionId, string $kind, int $employeeId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT person.result_snapshot_json
               FROM payroll_statutory_person_results person
               JOIN payroll_statutory_results result
                 ON result.supplier_id = person.supplier_id AND result.id = person.statutory_result_id
              WHERE result.supplier_id = ? AND result.revision_id = ? AND result.calculation_kind = ?',
        );
        $stmt->execute([$this->supplierId, $revisionId, $kind]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $json) {
            $row = json_decode((string) $json, true);
            if (is_array($row) && in_array("employee:{$employeeId}", [$row['person_reference'] ?? null, $row['person_id'] ?? null], true)) {
                return $row;
            }
        }

        return null;
    }
}
