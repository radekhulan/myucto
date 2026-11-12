<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Service\Payroll\Import\Agreement\AgreementImportColumns;
use MyInvoice\Service\Payroll\Import\Agreement\AgreementImportService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMyUctoSampleProfile;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1BatchService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1DefaultsService;
use MyInvoice\Tests\Support\PayrollE2eRegistrationTrait;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Měření agentury s N brigádníky na DPP po krocích, jak je dělá účetní:
 * import dohod (náhled, založení po 50 osobách), společné údaje A1 (po 200),
 * hromadná příprava A1 (po 200), import docházky (náhled, použití se vstupy
 * a souhrnem docházky), kontrola před během, výpočet a schválení. Mimo
 * běžnou sadu, pouští se ručně:
 *
 *   MYINVOICE_PAYROLL_DPP_IMPORT_BENCHMARK=500 php api/vendor/bin/phpunit -c api/phpunit.xml --filter PayrollE2eDppImportBenchmarkTest
 *
 * Vypíše dobu a počet SQL dotazů každého kroku (`Questions` relace); cílem je
 * každý krok (jeden požadavek) do pěti sekund. `..._TOP=8` vypíše nejčastěji
 * opakované dotazy kroku. Data jsou syntetická, vše běží v transakci nad
 * `_test` databází a na konci se vrátí.
 */
#[Group('integration')]
#[Group('performance')]
final class PayrollE2eDppImportBenchmarkTest extends TestCase
{
    use PayrollFullFlowTrait;
    use PayrollE2eRegistrationTrait;

    private const PERIOD = '2026-10';
    private const START = '2026-10-01';

    protected function setUp(): void
    {
        if ((int) getenv('MYINVOICE_PAYROLL_DPP_IMPORT_BENCHMARK') < 1) {
            self::markTestSkipped('Nastavte MYINVOICE_PAYROLL_DPP_IMPORT_BENCHMARK na počet brigádníků (např. 500).');
        }
        $this->bootRegistrationFlow('2026-11-25');
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testAgencyImportTimings(): void
    {
        $size = min(2000, (int) getenv('MYINVOICE_PAYROLL_DPP_IMPORT_BENCHMARK'));
        $this->db->pdo()->prepare('DELETE FROM payroll_employer_settings WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->configureSocialInsuranceOutput($this->regOfficeId);
        $this->db->pdo()->prepare('UPDATE payroll_employer_settings SET social_security_office_code = "110" WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $people = [];
        for ($seq = 1; $seq <= $size; $seq++) {
            $female = $seq % 2 === 0;
            $birthDate = sprintf('19%02d-%02d-%02d', 80 + $seq % 20, 1 + $seq % 12, 1 + $seq % 27);
            $people[] = [
                'last' => 'Měř' . self::letters($seq) . ($female ? 'ová' : 'ný'),
                'first' => $female ? 'Eva' : 'Jan',
                'birth_number' => self::syntheticBirthNumber($birthDate, $female ? 'female' : 'male', $seq),
            ];
        }
        $metrics = [];
        $agreements = $this->container->get(AgreementImportService::class);
        $file = $this->agreementFile($people, false);
        $metrics['agr_preview'] = $this->measure(fn () => $agreements->preview($this->supplierId, [$file]));
        $keys = array_column($agreements->preview($this->supplierId, [$file])['persons'], 'key');
        $created = [];
        $chunks = array_chunk($keys, 50);
        $metrics['agr_apply_50'] = $this->measure(function () use ($agreements, $file, $chunks, &$created): void {
            $created = $agreements->apply($this->supplierId, [$file], [], $chunks[0], $this->actors[0], null, null)['results'];
        });
        $metrics['agr_apply_rest'] = $this->measure(function () use ($agreements, $file, $chunks, &$created): void {
            foreach (array_slice($chunks, 1) as $chunk) {
                array_push($created, ...$agreements->apply($this->supplierId, [$file], [], $chunk, $this->actors[0], null, null)['results']);
            }
        });
        $employmentIds = array_values(array_filter(array_map(static fn (array $row): ?int => $row['employment_id'], $created)));
        self::assertCount($size, $employmentIds, json_encode(array_slice($created, 0, 3), JSON_UNESCAPED_UNICODE) ?: '');
        $metrics['agr_again'] = $this->measure(fn () => $agreements->apply($this->supplierId, [$file], [], $chunks[0], $this->actors[0], null, null));

        $defaults = $this->container->get(PayrollRegistrationA1DefaultsService::class);
        $metrics['a1_defaults_200'] = $this->measure(fn () => $defaults->apply(
            $this->supplierId,
            array_slice($employmentIds, 0, 200),
            ['work_mode_code' => '1', 'continuous_operation' => false, 'leadership' => false, 'position_name' => 'Promotér'],
            false,
            true,
            $this->actors[0],
        ));
        $batch = $this->container->get(PayrollRegistrationA1BatchService::class);
        $offered = [];
        $metrics['a1_candidates'] = $this->measure(function () use ($batch, &$offered): void {
            $offered = $batch->candidates($this->supplierId, 'test', self::START, '2026-10-31')['items'];
        });
        $complete = array_values(array_map(
            static fn (array $item): int => $item['employment_id'],
            array_filter($offered, static fn (array $item): bool => $item['complete']),
        ));
        self::assertCount($size, $complete, 'Po importu mají být profily A1 úplné.');
        $prepared = [];
        $metrics['a1_prepare_200'] = $this->measure(function () use ($batch, $complete, &$prepared): void {
            $prepared = $batch->prepare($this->supplierId, 'test', array_slice($complete, 0, 200), $this->actors[0])['results'];
        });
        self::assertSame(['prepared'], array_values(array_unique(array_column($prepared, 'status'))), json_encode($prepared[0] ?? null, JSON_UNESCAPED_UNICODE) ?: '');

        $attendance = $this->container->get(AttendanceImportService::class);
        $profileId = (int) $attendance->restoreSampleProfile($this->supplierId, $this->actors[0], 'myucto')['profile']['id'];
        $hours = $this->attendanceFile($people);
        $metrics['att_preview'] = $this->measure(fn () => $attendance->preview($this->supplierId, self::PERIOD, [$hours], null, $profileId));
        $preview = $attendance->preview($this->supplierId, self::PERIOD, [$hours], null, $profileId);
        $linked = count(array_filter($preview['persons'], static fn (array $person): bool => in_array($person['match']['status'], ['linked', 'matched'], true)));
        self::assertSame($size, $linked);
        $metrics['att_apply'] = $this->measure(fn () => $attendance->apply(
            $this->supplierId,
            self::PERIOD,
            [$hours],
            null,
            [],
            true,
            true,
            $this->actors[0],
            null,
            true,
            $profileId,
            writeTimeSummary: true,
            approveCleanTimeMonths: true,
            sourceChecksConfirmed: true,
            fillRegistrationData: true,
        ));

        $statement = $this->db->pdo()->prepare('SELECT id FROM payroll_inputs WHERE supplier_id = ? AND status = "draft"');
        $statement->execute([$this->supplierId]);
        $inputIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $inputs = $this->container->get(PayrollInputRepository::class);
        $metrics['inputs_approve'] = $this->measure(function () use ($inputs, $inputIds): void {
            foreach (array_chunk($inputIds, 200) as $chunk) {
                $inputs->approveBatch($this->supplierId, $chunk, $this->actors[0]);
            }
        });
        $readiness = $this->container->get(PayrollRunReadinessService::class);
        $metrics['preflight'] = $this->measure(fn () => $readiness->inspect($this->supplierId, self::START, '2026-11-15', $this->regOfficeId));
        $run = $this->runs->createRun($this->supplierId, self::START, '2026-11-15', $this->regOfficeId, $this->actors[0]);
        $locked = null;
        $metrics['lock'] = $this->measure(function () use (&$locked, $run): void {
            $locked = $this->runs->lockInputs($this->supplierId, (int) $run['id'], (int) $run['row_version'], 'bench-lock', $this->actors[0]);
        });
        $calculated = null;
        $metrics['calculate'] = $this->measure(function () use (&$calculated, $run, $locked): void {
            $calculated = $this->runs->calculate($this->supplierId, (int) $run['id'], (int) $locked->run['row_version'], 'bench-calc', $this->actors[0]);
        });
        $blockers = $this->blockingValidations((int) $calculated->revision['id']);
        if ($blockers === []) {
            $reviewed = $this->runs->review($this->supplierId, (int) $run['id'], (int) $calculated->run['row_version'], 'bench-review', $this->actors[0]);
            $metrics['approve'] = $this->measure(fn () => $this->runs->approve($this->supplierId, (int) $run['id'], (int) $reviewed->run['row_version'], 'bench-approve', $this->actors[0]));
        }

        $lines = [sprintf('Agentura %d DPP, import dohod a docházky %s:', $size, self::PERIOD)];
        foreach ($metrics as $step => [$seconds, $queries]) {
            $lines[] = sprintf('  %-16s %8.2f s  %7d SQL  %6.1f SQL/osoba', $step, $seconds, $queries, $queries / $size);
        }
        if ($blockers !== []) {
            $lines[] = '  approve přeskočeno, blokující nálezy: ' . mb_substr(json_encode(array_slice($blockers, 0, 3), JSON_UNESCAPED_UNICODE) ?: '', 0, 400);
        }
        fwrite(STDERR, implode("\n", $lines) . "\n");
    }

    /**
     * @param list<array{last:string,first:string,birth_number:string}> $people
     * @return array{name:string,content:string,sha256:string,extension:string}
     */
    private function agreementFile(array $people, bool $withoutA1): array
    {
        $rows = [1 => [
            'A' => 'Příjmení', 'B' => 'Jméno', 'C' => 'Rodné číslo', 'D' => 'Druh vztahu', 'E' => 'Nástup',
            'F' => 'Konec dohody', 'G' => 'Zdravotní pojišťovna', 'H' => 'Ulice', 'I' => 'Číslo domu', 'J' => 'Obec',
            'K' => 'PSČ', 'L' => 'Státní občanství', 'M' => 'CZ-ISCO', 'N' => 'Místo výkonu práce', 'O' => 'Rodné příjmení',
            'P' => 'Místo narození', 'Q' => 'Stát narození', 'R' => 'Režim práce', 'S' => 'Nepřetržitý provoz',
            'T' => 'Název pozice', 'U' => 'Vedoucí pozice', 'V' => 'Hodinová sazba (Kč/h)', 'W' => 'Prohlášení poplatníka',
        ]];
        foreach ($people as $index => $person) {
            $rows[$index + 2] = [
                'A' => $person['last'], 'B' => $person['first'], 'C' => $person['birth_number'], 'D' => 'DPP',
                'E' => '1. 10. 2026', 'F' => '31. 12. 2026', 'G' => '111', 'H' => 'Dlouhá', 'I' => (string) (1 + $index % 90),
                'J' => 'Praha', 'K' => '110 00', 'L' => 'CZ', 'M' => '52231', 'N' => '554782', 'O' => $person['last'],
                'P' => 'Brno', 'Q' => 'CZ', 'R' => $withoutA1 ? null : '1', 'S' => $withoutA1 ? null : 'ne',
                'T' => $withoutA1 ? null : 'Promotér', 'U' => $withoutA1 ? null : 'ne', 'V' => '180', 'W' => 'ano',
            ];
        }

        return AttendanceFixture::file('dohody.xlsx', AttendanceFixture::xlsx([AgreementImportColumns::SHEET => ['rows' => $rows]]));
    }

    /**
     * @param list<array{last:string,first:string,birth_number:string}> $people
     * @return array{name:string,content:string,sha256:string,extension:string}
     */
    private function attendanceFile(array $people): array
    {
        $rows = [1 => [
            'A' => 'Příjmení a jméno', 'B' => 'Rodné číslo', 'C' => 'Druh vztahu', 'D' => 'Odpracováno (hod)',
            'E' => 'Odpracované dny', 'F' => 'Hodinová sazba (Kč/h)',
        ]];
        foreach ($people as $index => $person) {
            $rows[$index + 2] = [
                'A' => $person['last'] . ' ' . $person['first'], 'B' => $person['birth_number'], 'C' => 'DPP',
                'D' => 12 + $index % 20, 'E' => 3, 'F' => 180,
            ];
        }

        return AttendanceFixture::file('dochazka.xlsx', AttendanceFixture::xlsx([AttendanceMyUctoSampleProfile::SHEET => ['rows' => $rows]]));
    }

    private static function letters(int $number): string
    {
        $text = '';
        do {
            $text = chr(97 + $number % 26) . $text;
            $number = intdiv($number, 26);
        } while ($number > 0);

        return $text;
    }

    /** @return array{0:float,1:int} */
    private function measure(callable $step): array
    {
        $top = (int) getenv('MYINVOICE_PAYROLL_DPP_IMPORT_BENCHMARK_TOP');
        if ($top > 0) {
            PayrollBenchmarkCountingStatement::$counts = [];
            $this->db->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PayrollBenchmarkCountingStatement::class]);
        }
        $before = $this->questions();
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
            fwrite(STDERR, "    ---\n");
        }

        return [$seconds, $queries];
    }

    private function questions(): int
    {
        return (int) ($this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value'] ?? 0);
    }
}
