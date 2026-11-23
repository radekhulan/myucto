<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentBulkClosureAction;
use MyInvoice\Action\Payroll\PayrollPayoutRulesAction;
use MyInvoice\Action\Payroll\PayrollRunsAction;
use MyInvoice\Action\Payroll\PayrollStatutoryEvidenceBulkDefaultsAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Absence\ProbableEarningBulkService;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Měření agentury s N brigádníky na DPP: kontrola před během, zamknutí,
 * výpočet, schválení, příprava a test JMHZ. Mimo běžnou sadu, pouští se ručně:
 *
 *   MYINVOICE_PAYROLL_AGENCY_BENCHMARK=150 php api/vendor/bin/phpunit -c api/phpunit.xml --filter PayrollE2eNavAgencyBenchmarkTest
 *
 * Vypíše dobu, počet SQL dotazů (`Questions` relace) a přírůstek špičky paměti
 * PHP každého kroku (výpočet, schválení a příprava JMHZ jsou jeden synchronní
 * požadavek, takže rozhoduje `max_execution_time` a `memory_limit`). Pro
 * odhalení N+1 se pouští dvakrát s různým N (např. 30 a 150) a porovná se
 * přírůstek dotazů na vztah. Data jsou syntetická, vše běží v transakci
 * nad `_test` databází a na konci se vrátí.
 *
 * `MYINVOICE_PAYROLL_AGENCY_BENCHMARK_TOP=8` navíc u každého kroku vypíše osm
 * nejčastěji opakovaných připravených dotazů (hledání dotazů v cyklu).
 */
#[Group('integration')]
#[Group('performance')]
final class PayrollE2eNavAgencyBenchmarkTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';
    private const PAYDAY = '2026-05-15';
    private const WORKED = ['2026-04-04', '2026-04-11', '2026-04-18'];

    protected function setUp(): void
    {
        if ((int) getenv('MYINVOICE_PAYROLL_AGENCY_BENCHMARK') < 1) {
            self::markTestSkipped('Nastavte MYINVOICE_PAYROLL_AGENCY_BENCHMARK na počet dohod (např. 150).');
        }
        $this->bootPayrollFullFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testAgencyMonthTimingsAndQueryCounts(): void
    {
        $size = min(2000, (int) getenv('MYINVOICE_PAYROLL_AGENCY_BENCHMARK'));
        $officeId = $this->createOffice('BEN', 'Syntetická účtárna měření', '1100001237');
        $this->configureSocialInsuranceOutput($officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $componentId = $this->createComponent('ODMENA_DPP_BEN', 'base_wage', 'regular');
        $this->container->get(PayrollComponentJmhzMappingRepository::class)
            ->put($this->supplierId, $componentId, '10329', null, $this->actors[0]);
        $employmentIds = [];
        for ($seq = 1; $seq <= $size; $seq++) {
            $employmentIds[] = $this->hire($officeId, $componentId, $seq);
        }
        $metrics = [];
        if (getenv('MYINVOICE_PAYROLL_AGENCY_BENCHMARK_MARK') === '1') {
            fwrite(STDERR, 'CONNECTION ' . $this->db->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn() . "\n");
        }
        // Hromadná akce bere nejvýš 500 vztahů na jedno volání.
        $metrics['probable_bulk'] = $this->measure(function () use ($employmentIds): void {
            foreach (array_chunk($employmentIds, 500) as $chunk) {
                $this->container->get(ProbableEarningBulkService::class)
                    ->apply($this->supplierId, 2026, 2, 15_000, 'Sjednaná sazba 150 Kč za hodinu.', $chunk, $this->actors[0], null, null);
            }
        });

        $readiness = $this->container->get(PayrollRunReadinessService::class);
        $metrics['preflight'] = $this->measure(fn () => $readiness->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY, $officeId));
        $run = $this->runs->createRun($this->supplierId, self::PERIOD_START, self::PAYDAY, $officeId, $this->actors[0]);
        $locked = null;
        $metrics['lock'] = $this->measure(function () use (&$locked, $run): void {
            $locked = $this->runs->lockInputs($this->supplierId, (int) $run['id'], (int) $run['row_version'], 'bench-lock', $this->actors[0]);
        });
        $calculated = null;
        $metrics['calculate'] = $this->measure(function () use (&$calculated, $run, $locked): void {
            $calculated = $this->runs->calculate($this->supplierId, (int) $run['id'], (int) $locked->run['row_version'], 'bench-calc', $this->actors[0]);
        });
        self::assertSame([], $this->blockingValidations((int) $calculated->revision['id']));
        $reviewed = $this->runs->review($this->supplierId, (int) $run['id'], (int) $calculated->run['row_version'], 'bench-review', $this->actors[0]);
        $approved = null;
        $metrics['approve'] = $this->measure(function () use (&$approved, $run, $reviewed): void {
            $approved = $this->runs->approve($this->supplierId, (int) $run['id'], (int) $reviewed->run['row_version'], 'bench-approve', $this->actors[0]);
        });
        $preparation = null;
        $metrics['jmhz_prepare'] = $this->measure(function () use (&$preparation, $approved): void {
            $preparation = $this->prepareJmhz((int) $approved->revision['id'], 'bench-prepare');
        });
        self::assertSame('source_ready', $preparation['body']['readiness_status'] ?? null, json_encode($preparation['body']['issues'] ?? null));
        $tested = null;
        $metrics['jmhz_dry_run'] = $this->measure(function () use (&$tested, $preparation, $officeId): void {
            $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $officeId);
        });
        self::assertSame('dry_run_valid', $tested['body']['status'] ?? null);

        $lines = [sprintf('Agentura %d DPP, období %s:', $size, self::PERIOD)];
        foreach ($metrics as $step => [$seconds, $queries, $peakBytes]) {
            $lines[] = sprintf(
                '  %-14s %8.2f s  %7d SQL  %6.1f SQL/vztah  paměť kroku %4d MB',
                $step,
                $seconds,
                $queries,
                $queries / $size,
                (int) round($peakBytes / 1_048_576),
            );
        }
        fwrite(STDERR, implode("\n", $lines) . "\n");
        if (getenv('MYINVOICE_PAYROLL_AGENCY_BENCHMARK_MARK') === '1') {
            $this->explainNewQueries($employmentIds[0]);
        }
        if (getenv('MYINVOICE_PAYROLL_AGENCY_BENCHMARK_SIZES') === '1') {
            $this->reportSnapshotSizes();
        }
    }

    /**
     * Velikost JSON snímků firmy po jednom měsíci: holá délka a délka po
     * kompresi zlib (odhad úspory u komprimovaného sloupce).
     */
    private function reportSnapshotSizes(): void
    {
        $columns = $this->db->pdo()->query(
            "SELECT column_info.TABLE_NAME, column_info.COLUMN_NAME
               FROM information_schema.COLUMNS column_info
               JOIN information_schema.COLUMNS supplier_column
                 ON supplier_column.TABLE_SCHEMA = column_info.TABLE_SCHEMA
                AND supplier_column.TABLE_NAME = column_info.TABLE_NAME
                AND supplier_column.COLUMN_NAME = 'supplier_id'
              WHERE column_info.TABLE_SCHEMA = DATABASE()
                AND column_info.TABLE_NAME LIKE 'payroll%'
                AND column_info.DATA_TYPE IN ('longtext', 'mediumtext', 'text', 'longblob', 'mediumblob', 'blob')
              ORDER BY column_info.TABLE_NAME, column_info.COLUMN_NAME"
        )->fetchAll(PDO::FETCH_NUM);
        $rows = [];
        $total = [0, 0];
        foreach ($columns as [$table, $column]) {
            $statement = $this->db->pdo()->prepare(
                "SELECT COUNT(`{$column}`), COALESCE(SUM(LENGTH(`{$column}`)), 0), COALESCE(SUM(LENGTH(COMPRESS(`{$column}`))), 0)
                   FROM `{$table}` WHERE supplier_id = ?"
            );
            $statement->execute([$this->supplierId]);
            [$count, $plain, $compressed] = array_map('intval', $statement->fetch(PDO::FETCH_NUM));
            if ($plain < 100_000) {
                continue;
            }
            $rows[] = sprintf('  %-48s %6d řádků %8.2f MB  zlib %7.2f MB', "{$table}.{$column}", $count, $plain / 1e6, $compressed / 1e6);
            $total[0] += $plain;
            $total[1] += $compressed;
        }
        $rows[] = sprintf('  %-48s %15s %8.2f MB  zlib %7.2f MB', 'celkem (sloupce nad 0,1 MB)', '', $total[0] / 1e6, $total[1] / 1e6);
        fwrite(STDERR, "Snímky firmy po měsíci:\n" . implode("\n", $rows) . "\n");
    }

    /**
     * Agentura jako v provozu: část brigádníků bez zákonné evidence a počátečních
     * stavů, s identitou až od pozdějšího data, se skončenou dohodou nebo
     * s haléřovým vstupem. Měří první načtení seznamu běhů (s kontrolou před
     * během) a náhledy hromadných akcí, které kontrola nabízí.
     */
    public function testAgencyWithGapsFirstLoad(): void
    {
        $size = min(2000, (int) getenv('MYINVOICE_PAYROLL_AGENCY_BENCHMARK'));
        $officeId = $this->createOffice('BEN', 'Syntetická účtárna měření', '1100001237');
        $this->configureSocialInsuranceOutput($officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $componentId = $this->createComponent('ODMENA_DPP_BEN', 'base_wage', 'regular');
        $this->container->get(PayrollComponentJmhzMappingRepository::class)
            ->put($this->supplierId, $componentId, '10329', null, $this->actors[0]);
        $employeeIds = [];
        for ($seq = 1; $seq <= $size; $seq++) {
            $employeeIds[] = $seq % 5 < 2
                ? $this->hireWithGaps($officeId, $componentId, $seq)
                : $this->hirePerson($officeId, $componentId, $seq)['employee_id'];
        }
        $metrics = [];
        $readiness = $this->container->get(PayrollRunReadinessService::class);
        $runs = $this->container->get(PayrollRunsAction::class);
        $statutory = $this->container->get(PayrollStatutoryEvidenceBulkDefaultsAction::class);
        $payout = $this->container->get(PayrollPayoutRulesAction::class);
        $closure = $this->container->get(PayrollEmploymentBulkClosureAction::class);
        $result = null;
        $metrics['preflight'] = $this->measure(function () use (&$result, $readiness, $officeId): void {
            $result = $readiness->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY, null);
        });
        $metrics['preflight#2'] = $this->measure(fn () => $readiness->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY, null));
        $metrics['runs_list'] = $this->measure(function () use ($runs): void {
            $response = $runs->list($this->request('GET', '/api/payroll/runs')->withQueryParams(['period' => self::PERIOD, 'limit' => '12', 'offset' => '0']), new Response());
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        });
        $metrics['statutory_bulk'] = $this->measure(function () use ($statutory): void {
            $response = $statutory->preview($this->request('POST', '/x')->withParsedBody(['effective_on' => self::PERIOD_START]), new Response());
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        });
        $metrics['payout_bulk'] = $this->measure(function () use ($payout, $employeeIds): void {
            $response = $payout->previewDefaultsBulk($this->request('POST', '/x')->withParsedBody(['employee_ids' => $employeeIds]), new Response());
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        });
        $metrics['closure_bulk'] = $this->measure(function () use ($closure): void {
            $response = $closure->preview($this->request('POST', '/x')->withParsedBody(['period' => self::PERIOD]), new Response());
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        });
        $codes = [];
        foreach ($result['findings'] as $finding) {
            $codes[] = $finding['code'] . '=' . $finding['count'];
        }
        $lines = [sprintf('Agentura %d DPP s mezerami, období %s: %s', $size, self::PERIOD, implode(', ', $codes))];
        foreach ($metrics as $step => [$seconds, $queries, $peakBytes]) {
            $lines[] = sprintf(
                '  %-14s %8.2f s  %7d SQL  %6.1f SQL/osoba  paměť kroku %4d MB',
                $step,
                $seconds,
                $queries,
                $queries / $size,
                (int) round($peakBytes / 1_048_576),
            );
        }
        fwrite(STDERR, implode("\n", $lines) . "\n");
        if (getenv('MYINVOICE_PAYROLL_AGENCY_BENCHMARK_DUMP') !== false) {
            file_put_contents((string) getenv('MYINVOICE_PAYROLL_AGENCY_BENCHMARK_DUMP'), json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    private function hireWithGaps(int $officeId, int $componentId, int $seq): int
    {
        $pdo = $this->db->pdo();
        $female = $seq % 2 === 0;
        $name = sprintf('%s Mezerový%s %03d', $female ? 'Syntetická' : 'Syntetický', $female ? 'á' : '', $seq);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type, tax_declaration_signed,
                 tax_credit_taxpayer, child_count, monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "dpp", 0, 0, 0, 0, 0, 1)',
        )->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_employee_profiles (supplier_id, employee_id, profile_status) VALUES (?, ?, "ready")')
            ->execute([$this->supplierId, $employeeId]);
        $endDate = $seq % 4 === 0 ? '2026-03-31' : null;
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type, status, start_date,
                 actual_start_date, end_date, is_primary)
             VALUES (?, ?, ?, ?, "dpp", "active", "2026-01-01", "2026-01-01", ?, 1)',
        )->execute([$this->supplierId, $employeeId, $officeId, "GAP-{$seq}", $endDate]);
        $employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from, planned_start_on, actual_start_on,
                 weekly_hours, workload_basis_points, social_insurance_participation,
                 health_insurance_participation, tax_regime, tax_declaration_signed, is_primary)
             VALUES (?, ?, ?, "2026-01-01", "2026-01-01", "2026-01-01", 10, 2500, "automatic", "automatic",
                     "advance", 0, 1)',
        )->execute([$this->supplierId, $employmentId, $officeId]);
        [$first, $last] = explode(' ', $name, 2);
        $pdo->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name, birth_date,
                 citizenship_country_code, sex, effective_from)
             VALUES (?, ?, ?, ?, ?, ?, "CZ", ?, ?)',
        )->execute([
            $this->supplierId,
            $employeeId,
            $name,
            $first,
            $last,
            sprintf('19%02d-%02d-%02d', 80 + $seq % 20, 1 + $seq % 12, 1 + $seq % 27),
            $female ? 'female' : 'male',
            $seq % 3 === 0 ? '2026-05-16' : '2026-01-01',
        ]);
        $person = ['employee_id' => $employeeId, 'employment_id' => $employmentId, 'name' => $name];
        if ($endDate !== null) {
            return $employeeId;
        }
        $this->publishShifts($employmentId, self::WORKED);
        $time = $this->approveTimeMonth($employmentId, self::PERIOD, self::WORKED, dailyMinutes: 240);
        self::assertSame(200, $time->getStatusCode(), (string) $time->getBody());
        $this->createApprovedInput($person, $componentId, $seq % 7 === 0 ? 360_050 : 360_000, 'gap-' . $employmentId, self::PERIOD_START);

        return $employeeId;
    }

    /**
     * Plány dotazů nad rostoucími tabulkami, které přidala agenda agentury
     * (mazání vztahu, zaokrouhlení vstupů, kontrola před během, zámek opravy).
     */
    private function explainNewQueries(int $employmentId): void
    {
        $queries = [
            'vstupy vztahu (mazání)' => ["SELECT status, MIN(period_start) FROM payroll_inputs WHERE supplier_id = ? AND employment_id = ? GROUP BY status", [$this->supplierId, $employmentId]],
            'haléřové vstupy období' => ['SELECT input.id FROM payroll_inputs input WHERE input.supplier_id = ? AND input.period_start = ? AND input.status IN ("draft", "approved", "locked") AND MOD(input.amount_minor, 100) <> 0', [$this->supplierId, self::PERIOD_START]],
            'běh vztahu (mazání)' => ['SELECT run.period_start FROM payroll_run_employments run_employment JOIN payroll_run_revisions revision ON revision.supplier_id = run_employment.supplier_id AND revision.id = run_employment.revision_id JOIN payroll_runs run ON run.supplier_id = revision.supplier_id AND run.id = revision.run_id WHERE run_employment.supplier_id = ? AND run_employment.employment_id = ?', [$this->supplierId, $employmentId]],
            'schválená docházka vztahu' => ["SELECT MIN(period_start) FROM payroll_time_months WHERE supplier_id = ? AND employment_id = ? AND status = 'approved'", [$this->supplierId, $employmentId]],
            'dohody po sjednaném konci' => ["SELECT employment.id FROM payroll_employments employment JOIN payroll_employees employee ON employee.supplier_id = employment.supplier_id AND employee.id = employment.employee_id WHERE employment.supplier_id = ? AND employment.status IN ('active', 'suspended') AND employment.end_date IS NOT NULL AND employment.end_date < ?", [$this->supplierId, self::PERIOD_START]],
            'revize období (zmrazený vstup)' => ['SELECT 1 FROM payroll_run_revisions revision JOIN payroll_runs run ON run.supplier_id = revision.supplier_id AND run.id = revision.run_id WHERE revision.supplier_id = ? AND run.period_start = ? AND revision.status <> "abandoned"', [$this->supplierId, self::PERIOD_START]],
        ];
        foreach ($queries as $label => [$sql, $params]) {
            $statement = $this->db->pdo()->prepare('EXPLAIN ' . $sql);
            $statement->execute($params);
            fwrite(STDERR, "EXPLAIN {$label}\n");
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                fwrite(STDERR, sprintf("  %-12s type=%-6s key=%-40s rows=%s %s\n", $row['table'], $row['type'], (string) $row['key'], (string) $row['rows'], (string) $row['Extra']));
            }
        }
    }

    /** @return array{0:float,1:int,2:int} sekundy, počet SQL dotazů, přírůstek špičky paměti PHP v bajtech */
    private function measure(callable $step): array
    {
        if (getenv('MYINVOICE_PAYROLL_AGENCY_BENCHMARK_MARK') === '1') {
            // Značka kroku pro obecný log serveru (hledání opakovaných dotazů).
            $this->db->pdo()->query("SELECT 'NAV_STEP'");
        }
        $top = (int) getenv('MYINVOICE_PAYROLL_AGENCY_BENCHMARK_TOP');
        if ($top > 0) {
            PayrollBenchmarkCountingStatement::$counts = [];
            PayrollBenchmarkCountingStatement::$milliseconds = [];
            $this->db->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PayrollBenchmarkCountingStatement::class]);
        }
        $before = $this->questions();
        // Přírůstek paměti kroku nad tím, co už drží testovací proces (kontejner,
        // fixtury); v HTTP požadavku k němu přibude jen základ aplikace.
        gc_collect_cycles();
        memory_reset_peak_usage();
        $memoryBefore = memory_get_usage();
        $started = hrtime(true);
        $step();
        $seconds = (hrtime(true) - $started) / 1e9;
        $queries = $this->questions() - $before - 1;
        if ($top > 0) {
            $this->db->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
            arsort(PayrollBenchmarkCountingStatement::$counts);
            foreach (array_slice(PayrollBenchmarkCountingStatement::$counts, 0, $top, true) as $sql => $count) {
                fwrite(STDERR, sprintf("    %5d× %s\n", $count, mb_substr((string) preg_replace('/\s+/', ' ', $sql), 0, 170)));
            }
            arsort(PayrollBenchmarkCountingStatement::$milliseconds);
            foreach (array_slice(PayrollBenchmarkCountingStatement::$milliseconds, 0, $top, true) as $sql => $milliseconds) {
                fwrite(STDERR, sprintf("    %7.1f ms %s\n", $milliseconds, mb_substr((string) preg_replace('/\s+/', ' ', $sql), 0, 170)));
            }
            fwrite(STDERR, "    ---\n");
        }

        return [$seconds, $queries, max(0, memory_get_peak_usage() - $memoryBefore)];
    }

    private function questions(): int
    {
        return (int) ($this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value'] ?? 0);
    }

    private function hire(int $officeId, int $componentId, int $seq): int
    {
        return $this->hirePerson($officeId, $componentId, $seq)['employment_id'];
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function hirePerson(int $officeId, int $componentId, int $seq): array
    {
        $female = $seq % 2 === 0;
        $name = sprintf('%s Měřený%s %03d', $female ? 'Syntetická' : 'Syntetický', $female ? 'á' : '', $seq);
        $person = $this->createEmployment($officeId, $name, $seq, 'dpp', 'dpp', 10, 2_500, $seq % 3 === 0, self::PERIOD_START);
        $birthDate = sprintf('19%02d-%02d-%02d', 80 + $seq % 20, 1 + $seq % 12, 1 + $seq % 27);
        [$first, $last] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, null, [
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => $birthDate,
            'sex' => $female ? 'female' : 'male',
            'birth_number' => self::syntheticBirthNumber($birthDate, $female ? 'female' : 'male', $seq),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($seq), sprintf('2%020d', $seq));
        $this->publishShifts($person['employment_id'], self::WORKED);
        $time = $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::WORKED, dailyMinutes: 240);
        self::assertSame(200, $time->getStatusCode(), (string) $time->getBody());
        $this->createApprovedInput($person, $componentId, 360_000, 'bench-' . $person['employment_id'], self::PERIOD_START);

        return $person;
    }
}

/** Počítá spuštění připravených dotazů podle jejich textu (jen pro měření výše). */
final class PayrollBenchmarkCountingStatement extends \PDOStatement
{
    /** @var array<string,int> */
    public static array $counts = [];

    /** @var array<string,float> */
    public static array $milliseconds = [];

    protected function __construct() {}

    public function execute(?array $params = null): bool
    {
        self::$counts[$this->queryString] = (self::$counts[$this->queryString] ?? 0) + 1;
        $started = hrtime(true);
        try {
            return parent::execute($params);
        } finally {
            self::$milliseconds[$this->queryString] = (self::$milliseconds[$this->queryString] ?? 0.0) + (hrtime(true) - $started) / 1e6;
        }
    }
}
