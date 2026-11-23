<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollRecurringComponentRepository;
use MyInvoice\Service\Payroll\Absence\AverageEarningBatchService;
use MyInvoice\Service\Payroll\Component\PayrollRecurringMaterializer;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use Slim\Psr7\Response;

/**
 * Společný tok scénářů dovolené, pravděpodobného výdělku a dávek (PPM, OČR):
 * zaměstnanec s měsíční mzdou jako opakovanou složkou, kterou aplikace sama
 * krátí podle fondu pracovní doby, průměrný výdělek z uzavřených běhů
 * a měsíc od docházky po schválený běh, případně po dry-run hlášení.
 *
 * Cesta je stejná jako u mzdové účetní: předpis mzdy → nepřítomnost →
 * „Vytvořit vstupy z předpisů" → schválení vstupů → docházka → běh.
 */
trait PayrollE2eHpp2VacationFlowTrait
{
    use PayrollFullFlowTrait;

    private int $officeId;
    private int $wageComponentId;
    private int $sequence = 0;

    private function bootLeaveFlow(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('DOV', 'Syntetická účtárna nepřítomností', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->wageComponentId = $this->createComponent('MZDA_MESICNI_DOV', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->wageComponentId, '10329', null, $this->actors[0]);
    }

    /**
     * Zaměstnanec v pracovním poměru s měsíční mzdou vedenou jako opakovaná
     * složka (předpis), pravidelným týdnem a zveřejněnými směnami na pracovní
     * dny ledna až dubna 2026.
     *
     * @return array{employee_id:int,employment_id:int,name:string,sequence:int,daily_minutes:int,start:string}
     */
    private function hireMonthly(
        string $name,
        string $sex,
        string $birthDate,
        int $monthlyGrossMinor,
        int $weeklyHours = 40,
        int $workload = 10_000,
        string $start = '2026-01-01',
        string $healthTopUpResponsibility = 'employee',
    ): array {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            'hpp',
            'employment',
            $weeklyHours,
            $workload,
            true,
            '2026-04-01',
            healthTopUpResponsibility: $healthTopUpResponsibility,
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
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_employment_terms
                SET remuneration_type = "monthly", monthly_gross_minor = ?,
                    remuneration_basis_kind = "contract", remuneration_basis = "Syntetická pracovní smlouva"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$monthlyGrossMinor, $this->supplierId, $person['employment_id']]);
        if ($start !== '2026-01-01') {
            $pdo->prepare('UPDATE payroll_employments SET start_date = ?, actual_start_date = ? WHERE supplier_id = ? AND id = ?')
                ->execute([$start, $start, $this->supplierId, $person['employment_id']]);
            $pdo->prepare(
                'UPDATE payroll_employment_terms SET effective_from = ?, planned_start_on = ?, actual_start_on = ?
                  WHERE supplier_id = ? AND employment_id = ?',
            )->execute([$start, $start, $start, $this->supplierId, $person['employment_id']]);
        }
        $pdo->prepare(
            'INSERT INTO payroll_employment_events
                (supplier_id, employment_id, event_type, from_status, to_status, effective_on, created_by)
             VALUES (?, ?, "created", NULL, "active", ?, ?)',
        )->execute([$this->supplierId, $person['employment_id'], $start, $this->actors[0]]);
        $dailyMinutes = intdiv($weeklyHours * 60, 5);
        $this->ensureRegularCalendar($person['employment_id'], $dailyMinutes);
        foreach (['2026-01', '2026-02', '2026-03', '2026-04'] as $period) {
            $days = array_values(array_filter(self::workdays($period), static fn (string $day): bool => $day >= $start));
            $this->publishShiftMinutes($person['employment_id'], $days, $dailyMinutes);
            $pdo->prepare(
                'INSERT IGNORE INTO payroll_enforcement_person_month_evidence
                    (supplier_id, employee_id, period_start,
                     claim_register_evidence_complete, dependants_evidence_complete,
                     spouse_evidence_complete, pension_evidence, updated_by)
                 VALUES (?, ?, ?, 1, 1, 1, "none", ?)',
            )->execute([$this->supplierId, $person['employee_id'], "{$period}-01", $this->actors[0]]);
        }
        $recurring = $this->container->get(PayrollRecurringComponentRepository::class);
        self::assertInstanceOf(PayrollRecurringComponentRepository::class, $recurring);
        $recurring->create($this->supplierId, [
            'employment_id' => $person['employment_id'],
            'component_id' => $this->wageComponentId,
            'calculation_kind' => 'fixed_amount',
            'amount_minor' => $monthlyGrossMinor,
            'rate_basis_points' => null,
            'valid_from' => $start,
            'valid_to' => null,
            'allocation_rule' => 'full_month',
            'maximum_amount_minor' => null,
            'note' => null,
            'is_active' => true,
        ], $this->actors[0]);

        return $person + ['sequence' => $sequence, 'daily_minutes' => $dailyMinutes, 'start' => $start];
    }

    /**
     * Směny bez přestávky v délce denního úvazku (od 8:00 místního času).
     *
     * @param list<string> $dates
     */
    private function publishShiftMinutes(int $employmentId, array $dates, int $minutes): void
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_shifts
                (supplier_id, employment_id, series_key, starts_at_utc, ends_at_utc, timezone_name,
                 break_minutes, status, published_by, published_at)
             VALUES (?, ?, ?, ?, ?, "Europe/Prague", 0, "published", ?, NOW())',
        );
        foreach ($dates as $date) {
            $start = (new \DateTimeImmutable("{$date} 08:00:00", new \DateTimeZone('Europe/Prague')))
                ->setTimezone(new \DateTimeZone('UTC'));
            $statement->execute([
                $this->supplierId,
                $employmentId,
                "hpp2-{$employmentId}-{$date}",
                $start->format('Y-m-d H:i:s'),
                $start->modify("+{$minutes} minutes")->format('Y-m-d H:i:s'),
                $this->actors[0],
            ]);
        }
    }

    /**
     * „Vytvořit vstupy z předpisů" za měsíc a schválení vzniklých konceptů.
     * Vrací částku základní mzdy vztahu po krácení (haléře).
     *
     * @param array{employee_id:int,employment_id:int} $person
     */
    private function materializeWage(array $person, string $period): int
    {
        $materializer = $this->container->get(PayrollRecurringMaterializer::class);
        self::assertInstanceOf(PayrollRecurringMaterializer::class, $materializer);
        $result = $materializer->materialize($this->supplierId, $period, $this->actors[0]);
        self::assertSame([], $result['manual_review'], 'Zaseknutí: předpis mzdy. ' . CanonicalJson::encode($result['manual_review']));
        $inputs = $this->container->get(PayrollInputRepository::class);
        self::assertInstanceOf(PayrollInputRepository::class, $inputs);
        $statement = $this->db->pdo()->prepare(
            'SELECT id, row_version, status FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND component_id = ? AND period_start = ?',
        );
        $statement->execute([$this->supplierId, $person['employment_id'], $this->wageComponentId, "{$period}-01"]);
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['status'] === 'draft') {
                $inputs->approve($this->supplierId, (int) $row['id'], (int) $row['row_version'], $this->actors[0]);
            }
        }

        return $this->inputAmount($person['employment_id'], $period, 'MZDA_MESICNI_DOV');
    }

    /** Součet schválených (i zamčených) vstupů složky za měsíc v haléřích. */
    private function inputAmount(int $employmentId, string $period, string $code): int
    {
        return (int) $this->scalar(
            'SELECT COALESCE(SUM(input.amount_minor), 0)
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ? AND input.period_start = ?
                AND input.status IN ("approved", "locked") AND component.code = ?',
            [$this->supplierId, $employmentId, "{$period}-01", $code],
        );
    }

    /**
     * Docházka měsíce (odpracované dny = pracovní dny bez nepřítomnosti)
     * a schválení se všemi návrhy náhledu.
     *
     * @param array{employment_id:int,daily_minutes:int,start:string} $person
     * @param list<string> $absentDates celé dny nepřítomnosti
     * @param array<string,int> $partialDays den => odpracované minuty (půlden dovolené)
     */
    private function approveAttendance(array $person, string $period, array $absentDates = [], array $partialDays = []): void
    {
        $worked = array_values(array_filter(
            self::workdays($period, [...$absentDates, ...array_keys($partialDays)]),
            static fn (string $day): bool => $day >= $person['start'],
        ));
        foreach ($partialDays as $day => $minutes) {
            $this->recordWorkedDaysItem($person['employment_id'], [$day], $minutes);
        }
        $response = $this->approveTimeMonth($person['employment_id'], $period, $worked, dailyMinutes: $person['daily_minutes']);
        self::assertSame(200, $response->getStatusCode(), "Zaseknutí: docházka {$period}. " . (string) $response->getBody());
    }

    /**
     * Běh měsíce až po schválení; blokace nebo varování test zastaví s textem.
     *
     * @return array{revision_id:int,people:list<array<string,mixed>>,run:array<string,mixed>}
     */
    private function approvedRun(string $period, string $key): array
    {
        $payday = (new \DateTimeImmutable("{$period}-01"))->modify('+1 month')->format('Y-m-10');
        $run = $this->runPayrollMonth("{$period}-01", $payday, $this->officeId, "{$key}-{$period}");
        self::assertSame([], $run['blockers'], "Zaseknutí: výpočet {$period}. " . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], "Zaseknutí: varování {$period}. " . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);

        return [
            'revision_id' => (int) $run['approved']->revision['id'],
            'people' => $run['approved']->revision['result_snapshot']['statutory']['people'],
            'run' => $run['approved']->run,
        ];
    }

    /**
     * Plně odpracovaný měsíc: předpis, docházka, běh.
     *
     * @param list<array{employee_id:int,employment_id:int,daily_minutes:int,start:string}> $people
     * @return array{revision_id:int,people:list<array<string,mixed>>,run:array<string,mixed>}
     */
    private function workedMonth(array $people, string $period, string $key): array
    {
        foreach ($people as $person) {
            $this->materializeWage($person, $period);
            $this->approveAttendance($person, $period);
        }

        return $this->approvedRun($period, $key);
    }

    /**
     * Průměr čtvrtletí z přehledu „Průměrné výdělky": návrh z uzavřených běhů
     * a hromadné schválení. Vrací řádek schváleného průměru vztahu.
     *
     * @return array<string,mixed>
     */
    private function quarterAverage(int $employmentId, int $quarter): array
    {
        $batch = $this->container->get(AverageEarningBatchService::class);
        self::assertInstanceOf(AverageEarningBatchService::class, $batch);
        foreach ($batch->page($this->supplierId, 2026, $quarter, 100)['items'] as $item) {
            if ((int) $item['employment_id'] !== $employmentId) {
                continue;
            }
            self::assertNull($item['existing'], 'Průměr za čtvrtletí už existuje.');
            self::assertTrue($item['ready'], 'Zaseknutí: návrh průměru. ' . CanonicalJson::encode($item['blockers'] ?? $item));
            $batch->createBatch($this->supplierId, 2026, $quarter, [[
                'employment_id' => $employmentId,
                'input_version' => (string) $item['input_version'],
            ]], $this->actors[0]);

            return $this->approvedAverage($employmentId, $quarter);
        }
        self::fail("Vztah {$employmentId} není v přehledu průměrů.");
    }

    /** @return array<string,mixed> */
    private function approvedAverage(int $employmentId, int $quarter): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_average_earning_snapshots
              WHERE supplier_id = ? AND employment_id = ? AND applicable_year = 2026
                AND applicable_quarter = ? AND status = "approved"
              ORDER BY id DESC LIMIT 1',
        );
        $statement->execute([$this->supplierId, $employmentId, $quarter]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Schválený průměr {$quarter}. čtvrtletí vztahu {$employmentId} chybí.");

        return $row;
    }

    /**
     * Ručně zadaný průměr (podklady předchozího zpracování nebo pravděpodobný
     * výdělek). Vrací odpověď založení, aby šlo zaznamenat odmítnutí.
     *
     * @param array<string,mixed> $values
     */
    private function requestAverage(int $employmentId, int $quarter, array $values): \Psr\Http\Message\ResponseInterface
    {
        [$from, $to] = match ($quarter) {
            1 => ['2025-10-01', '2025-12-31'],
            2 => ['2026-01-01', '2026-03-31'],
            3 => ['2026-04-01', '2026-06-30'],
        };

        return $this->absences->createAverage(
            $this->request('POST', '/api/payroll/absences/average')->withParsedBody($values + [
                'employment_id' => $employmentId,
                'applicable_year' => 2026,
                'applicable_quarter' => $quarter,
                'decisive_from' => $from,
                'decisive_to' => $to,
                'gross_earnings_minor' => 0,
                'longer_period_allocated_minor' => 0,
                'worked_minutes' => 0,
                'worked_days' => 0,
                'probable_hourly_minor' => null,
                'rationale' => null,
            ]),
            new Response(),
        );
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed> schválený průměr
     */
    private function manualAverage(int $employmentId, int $quarter, array $values): array
    {
        $created = $this->requestAverage($employmentId, $quarter, $values);
        self::assertSame(201, $created->getStatusCode(), 'Zaseknutí: průměr. ' . (string) $created->getBody());
        $average = $this->json($created)['snapshot'];
        $approved = $this->absences->approveAverage(
            $this->request('POST', '/api/payroll/absences/average/approve')->withParsedBody(['row_version' => $average['row_version']]),
            new Response(),
            ['id' => (string) $average['id']],
        );
        self::assertSame(200, $approved->getStatusCode(), 'Zaseknutí: schválení průměru. ' . (string) $approved->getBody());

        return $this->approvedAverage($employmentId, $quarter);
    }

    /**
     * Příprava hlášení a dry-run (XSD a katalog kontrol); vrací XML bez mezer.
     */
    private function jmhzXml(int $revisionId, string $key): string
    {
        $preparation = $this->prepareJmhz($revisionId, $key);
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
        self::assertSame('source_ready', $preparation['body']['readiness_status'], 'Zaseknutí: příprava JMHZ. ' . CanonicalJson::encode($preparation['body']['issues'] ?? []));
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], CanonicalJson::encode($tested['body']));
        self::assertSame('dry_run_valid', $tested['body']['status'], 'Zaseknutí: test hlášení. ' . CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']));
        self::assertTrue($tested['body']['controls']['submittable']);

        return (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']);
    }

    /**
     * @param array<string,mixed> $person zákonný výsledek osoby
     * @param array{taxable:int,social:int,health_employee:int,health_employer:int,health_top_up:int,advance:int,net:int} $expected
     */
    private function assertPayroll(array $person, array $expected): void
    {
        $actual = [
            'taxable' => $person['income_tax']['advance_tax']['taxable_income_minor_units'],
            'social' => $person['social_insurance']['employee_contribution_minor_units'],
            'health_employee' => $person['health_insurance']['employee_contribution_minor_units'],
            'health_employer' => $person['health_insurance']['employer_contribution_minor_units'],
            'health_top_up' => $person['health_insurance']['employee_minimum_top_up_minor_units'],
            'advance' => $person['income_tax']['advance_tax']['tax_after_credits_minor_units'],
            'net' => $person['net_payable_minor_units'],
        ];
        self::assertSame($expected, $actual, 'Výpočet nesouhlasí s ručním přepočtem.');
    }
}
