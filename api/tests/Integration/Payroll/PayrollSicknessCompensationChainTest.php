<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollAbsenceAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Service\Payroll\Absence\AbsenceHolidayTreatment;
use MyInvoice\Service\Payroll\Absence\PayrollWageProrationService;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpExcludedPeriodDeriver;
use MyInvoice\Service\Payroll\Time\PayrollJmhzAbsenceHoursDeriver;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Náhrada mzdy při DPN podle § 192 ZP (audit podání mezd 2026-10, DPN-01 až DPN-04).
 *
 * - DPN-01: neschopnost zapsaná po částech je JEDNA neschopnost a má jedno 14denní okno.
 * - DPN-02: DPN bez nároku na nemocenské jde schválit bez náhrady.
 * - DPN-03: náhradu jde snížit podle § 192 odst. 4 a 5 ZP.
 * - DPN-04: okno končí nejpozději dnem skončení pracovního vztahu.
 *
 * Všechna data jsou syntetická; transakci vrací tearDown.
 */
#[Group('integration')]
final class PayrollSicknessCompensationChainTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollAbsenceAction $action;
    private PayrollAbsenceRepository $absences;
    private PayrollWageProrationService $proration;
    private EldpExcludedPeriodDeriver $eldp;
    private PayrollJmhzAbsenceHoursDeriver $jmhzDeriver;
    private int $userId;
    private int $supplierId;
    private int $employmentId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollAbsenceAction::class);
            $this->absences = $container->get(PayrollAbsenceRepository::class);
            $this->proration = $container->get(PayrollWageProrationService::class);
            $this->eldp = $container->get(EldpExcludedPeriodDeriver::class);
            $this->jmhzDeriver = $container->get(PayrollJmhzAbsenceHoursDeriver::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach (['payroll_employments', 'payroll_shifts', 'payroll_absences', 'payroll_sickness_events'] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí integrační tabulka {$table}.");
            }
        }
        $pdo = $this->db->pdo();
        $sourceSupplier = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplier === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplier);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $this->employmentId = $this->createEmployment('employment');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    /**
     * DPN-01: neschopnost 8. až 26. 6. 2026 zapsaná po částech (8.–19., 20.–22., 23.–26.).
     * Okno § 192 ZP končí 21. 6. Druhá část z něj má jen víkend 20.–21. 6., třetí nic.
     * Dřív dostala každá část vlastní okno a náhrada se vyplatila i za 22.–26. 6., kdy
     * už běží nemocenské.
     */
    public function testChainedSicknessPartsShareOneCompensationWindow(): void
    {
        $averageId = $this->createApprovedAverage(2026, 2);
        $this->workCalendar();
        $this->publishWeekdayShifts('2026-06-01', '2026-06-30');

        $first = $this->approve($this->createDpn('2026-06-08', '2026-06-19', $averageId));
        $second = $this->approve($this->createDpn('2026-06-20', '2026-06-22', $averageId));
        $third = $this->approve($this->createDpn('2026-06-23', '2026-06-26', $averageId));

        self::assertGreaterThan(0, $first['calculation']['compensation_minor']);
        self::assertSame(12, $second['absence']['sickness_window_carried_days']);
        self::assertSame('2026-06-20', $second['calculation']['compensation_window_from']);
        self::assertSame('2026-06-21', $second['calculation']['compensation_window_to']);
        self::assertSame(0, $second['calculation']['compensation_minor']);
        self::assertSame(14, $third['absence']['sickness_window_carried_days']);
        self::assertSame(0, $third['calculation']['compensation_minor']);
        self::assertSame('2026-06-22', $third['calculation']['compensation_window_to'], 'Prázdné okno končí den před začátkem.');

        $lastPaidDay = $this->db->pdo()->prepare(
            'SELECT MAX(segment.local_date)
               FROM payroll_sickness_compensation_segments segment
               JOIN payroll_sickness_events event
                 ON event.supplier_id = segment.supplier_id AND event.id = segment.sickness_event_id
              WHERE segment.supplier_id = ?'
        );
        $lastPaidDay->execute([$this->supplierId]);
        self::assertSame('2026-06-19', $lastPaidDay->fetchColumn(), 'Náhrada se nesmí vyplatit za dny po 21. 6.');

        $secondRow = $this->absences->find($this->supplierId, (int) $second['absence']['id']);
        self::assertNotNull($secondRow);
        self::assertSame([], $this->absences->publishedShiftSegments($secondRow, false, AbsenceHolidayTreatment::CompensateSickness));
        self::assertSame(
            ['2026-06-22'],
            array_column($this->absences->publishedShiftSegmentsBeyondSicknessWindow($secondRow, false), 'local_date'),
        );

        $inputs = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_sickness_input_materializations WHERE supplier_id = ?'
        );
        $inputs->execute([$this->supplierId]);
        self::assertSame(1, (int) $inputs->fetchColumn(), 'Mzdový vstup náhrady vzniká jen z první části.');

        $june = $this->proration->forMonth($this->supplierId, $this->employmentId, '2026-06', 4_200_000);
        self::assertTrue($june['supported'], json_encode($june) ?: '');
        self::assertSame(
            ['sickness_compensation' => 10 * 480, 'state_benefit' => 5 * 480],
            $june['replaced_minutes_by_title'],
            'Dny 22.–26. 6. jsou nemocenské (StateBenefit), ne náhrada mzdy.',
        );

        // Měsíční hlášení (10278/10277) čte řádky bez sloupce vyčerpaných dnů.
        $hours = $this->jmhzHours([$first, $second, $third]);
        self::assertTrue($hours['supported']);
        self::assertSame(10 * 480, $hours['minutes']['dpn_with_employer_compensation']);
        self::assertSame(5 * 480, $hours['minutes']['dpn_without_employer_compensation']);
    }

    /**
     * DPN-01: navazující část nejde schválit dřív než ta, na kterou navazuje — nebylo by
     * známo, kolik okna už vyčerpala.
     */
    public function testContinuationCannotBeApprovedBeforeItsPredecessor(): void
    {
        $averageId = $this->createApprovedAverage(2026, 2);
        $this->publishWeekdayShifts('2026-06-01', '2026-06-30');
        $this->createDpn('2026-06-08', '2026-06-19', $averageId);
        $second = $this->createDpn('2026-06-20', '2026-06-30', $averageId);

        $response = $this->decide($second);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('Schvalte nejdřív', $this->json($response)['error']['message']);
    }

    /**
     * DPN-01: zrušit první část pod schválenou navazující by navazující části nechalo
     * okno zkrácené o dny, které už nejsou.
     */
    public function testPredecessorCannotBeCancelledUnderApprovedContinuation(): void
    {
        $averageId = $this->createApprovedAverage(2026, 2);
        $this->publishWeekdayShifts('2026-06-01', '2026-06-30');
        $first = $this->approve($this->createDpn('2026-06-08', '2026-06-19', $averageId));
        $this->approve($this->createDpn('2026-06-20', '2026-06-30', $averageId));

        $response = $this->action->cancel(
            $this->request()->withParsedBody(['row_version' => $first['absence']['row_version']]),
            new Response(),
            ['id' => (string) $first['absence']['id']],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('Zrušte nejdřív navazující část', $this->json($response)['error']['message']);
    }

    /**
     * DPN-01: část navazující přes konec čtvrtletí pokračuje v okně první části
     * (25.–31. 3. je 7 dnů, zbývá 1.–7. 4.) s jejími pravidly, ale dny v dubnu berou
     * průměr 2. čtvrtletí: průměrný výdělek platí po celé čtvrtletí, ke kterému byl
     * zjištěn (§ 351 a § 354 odst. 1 ZP). Shodně počítají podaná hlášení PAMICA.
     */
    public function testContinuationInNextQuarterUsesThatQuarterAverage(): void
    {
        $averageQ1 = $this->createApprovedAverage(2026, 1);
        $this->createApprovedAverage(2026, 2, 15_000_000);
        $this->publishWeekdayShifts('2026-03-23', '2026-04-17');
        $this->approve($this->createDpn('2026-03-25', '2026-03-31', $averageQ1));

        $second = $this->approve($this->createDpn('2026-04-01', '2026-04-15', null));

        self::assertSame(7, $second['absence']['sickness_window_carried_days']);
        self::assertSame($averageQ1, $second['calculation']['average_snapshot_id']);
        self::assertSame('2026-04-07', $second['calculation']['compensation_window_to']);
        self::assertSame(
            ['2026-04-01', '2026-04-02', '2026-04-03', '2026-04-06', '2026-04-07'],
            array_column($second['calculation']['segments'], 'local_date'),
        );
        self::assertSame([93_750], array_values(array_unique(array_column($second['calculation']['segments'], 'hourly_average_minor'))));
    }

    /**
     * Průměr 2. čtvrtletí vzniká až ze mzdy za březen; neschopnost schválená dřív
     * na něj nečeká a dny v dubnu počítá z průměru 1. čtvrtletí.
     */
    public function testContinuationInNextQuarterFallsBackToFirstPartAverageUntilApproved(): void
    {
        $averageQ1 = $this->createApprovedAverage(2026, 1);
        $this->publishWeekdayShifts('2026-03-23', '2026-04-17');
        $this->approve($this->createDpn('2026-03-25', '2026-03-31', $averageQ1));

        $second = $this->approve($this->createDpn('2026-04-01', '2026-04-15', null));

        self::assertSame([75_000], array_values(array_unique(array_column($second['calculation']['segments'], 'hourly_average_minor'))));
    }

    /**
     * DPN-02: DPP pod limitem — zaměstnanec není účasten pojištění, náhrada mzdy ani
     * nemocenské nenáleží (§ 192 odst. 1 ZP, § 15a zák. č. 187/2006 Sb.). Schválení musí
     * projít bez průměru, bez mzdového vstupu a bez případu NEMPRI; mzda se za dobu
     * neschopnosti krátí a evidenční list jde větví „bez nároku".
     */
    public function testSicknessWithoutEntitlementIsApprovedWithoutCompensation(): void
    {
        $this->employmentId = $this->createEmployment('dpp');
        $this->workCalendar();
        $this->publishWeekdayShifts('2026-06-01', '2026-06-30');
        $absence = $this->createDpn('2026-06-08', '2026-06-26', null);

        $response = $this->decide($absence, [
            'insurance_eligibility' => 'not_eligible',
            'conflicting_benefit_excluded' => false,
            'first_day_fully_worked' => false,
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertNull($body['sickness_case']);
        self::assertFalse($body['calculation']['insurance_eligibility_confirmed']);
        self::assertSame(0, $body['calculation']['compensation_minor']);
        self::assertSame([], $body['calculation']['segments']);
        self::assertNull($body['calculation']['average_snapshot_id']);

        $inputs = $this->db->pdo()->prepare('SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ?');
        $inputs->execute([$this->supplierId]);
        self::assertSame(0, (int) $inputs->fetchColumn(), 'Bez nároku nevzniká NAHRADA_MZDY_DPN.');

        $june = $this->proration->forMonth($this->supplierId, $this->employmentId, '2026-06', 1_000_000);
        self::assertTrue($june['supported'], json_encode($june) ?: '');
        self::assertSame(['unpaid' => 15 * 480], $june['replaced_minutes_by_title']);

        $hours = $this->jmhzHours([$body]);
        self::assertSame(0, $hours['minutes']['dpn_with_employer_compensation']);
        self::assertSame(15 * 480, $hours['minutes']['dpn_without_employer_compensation']);

        $eldp = $this->eldp->deriveSection18([[
            'id' => (int) $absence['id'],
            'absence_type' => 'dpn',
            'date_from' => '2026-06-08',
            'date_to' => '2026-06-26',
            'compensation_window_from' => $body['calculation']['compensation_window_from'],
            'compensation_window_to' => $body['calculation']['compensation_window_to'],
            'insurance_eligibility_confirmed' => $body['calculation']['insurance_eligibility_confirmed'],
        ]], '2026-06-01', '2026-06-30');
        self::assertTrue($eldp['derivable']);
        self::assertSame(14, $eldp['components']['pracovniNeschopnost']);
        self::assertSame(0, $eldp['components']['vyplaceniDavek'], 'Za oknem DPN bez nároku dávka není.');
    }

    /**
     * DPN-03: § 192 odst. 4 ZP — náhrada na polovinu, z přesného čitatele před
     * zaokrouhlením. § 192 odst. 5 ZP — snížení o 100 % náhradu neposkytne a mzdový
     * vstup nevznikne.
     */
    public function testCompensationReductionIsAppliedAndStored(): void
    {
        $averageId = $this->createApprovedAverage(2026, 2);
        $this->publishWeekdayShifts('2026-06-01', '2026-06-30');

        $half = $this->decide($this->createDpn('2026-06-08', '2026-06-10', $averageId), [
            'insurance_eligibility_confirmed' => true,
            'conflicting_benefit_excluded' => true,
            'compensation_reduction' => 'half_192_4',
            'compensation_reduction_reason' => 'Syntetický případ § 31: opilost.',
        ]);
        self::assertSame(200, $half->getStatusCode(), (string) $half->getBody());
        $calculation = $this->json($half)['calculation'];
        self::assertSame('half_192_4', $calculation['compensation_reduction']);
        self::assertSame(5_000, $calculation['compensation_reduction_basis_points']);
        $before = (int) $calculation['calculation_trace']['compensation_before_reduction_minor'];
        self::assertGreaterThan(0, $before);
        self::assertSame((int) ceil($before / 200) * 100, $calculation['compensation_minor']);

        $none = $this->decide($this->createDpn('2026-06-15', '2026-06-17', $averageId), [
            'insurance_eligibility_confirmed' => true,
            'conflicting_benefit_excluded' => true,
            'compensation_reduction' => 'reduced_192_5',
            'compensation_reduction_basis_points' => 10_000,
            'compensation_reduction_reason' => 'Syntetické porušení režimu.',
        ]);
        self::assertSame(200, $none->getStatusCode(), (string) $none->getBody());
        $calculation = $this->json($none)['calculation'];
        self::assertSame(0, $calculation['compensation_minor']);
        $materialized = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_sickness_input_materializations WHERE supplier_id = ? AND sickness_event_id = ?'
        );
        $materialized->execute([$this->supplierId, $calculation['id']]);
        self::assertSame(0, (int) $materialized->fetchColumn());

        $missingReason = $this->decide($this->createDpn('2026-06-22', '2026-06-23', $averageId), [
            'insurance_eligibility_confirmed' => true,
            'conflicting_benefit_excluded' => true,
            'compensation_reduction' => 'half_192_4',
        ]);
        self::assertSame(422, $missingReason->getStatusCode());
    }

    /**
     * DPN-04: vztah skončil 10. 6., neschopnost trvá 5.–18. 6. Náhrada (směny i rozvrh
     * kalendáře) končí 10. 6. a schválení na to upozorní.
     */
    public function testCompensationWindowEndsWithEmployment(): void
    {
        $averageId = $this->createApprovedAverage(2026, 2);
        $this->workCalendar();
        $this->publishWeekdayShifts('2026-06-01', '2026-06-18');
        $this->db->pdo()->prepare('UPDATE payroll_employments SET end_date = "2026-06-10" WHERE supplier_id = ? AND id = ?')
            ->execute([$this->supplierId, $this->employmentId]);
        $absence = $this->createDpn('2026-06-05', '2026-06-18', $averageId);
        $row = $this->absences->find($this->supplierId, (int) $absence['id']);
        self::assertNotNull($row);

        $shiftDays = array_column(
            $this->absences->publishedShiftSegments($row, false, AbsenceHolidayTreatment::CompensateSickness),
            'local_date',
        );
        self::assertSame('2026-06-10', max($shiftDays));
        self::assertSame([], $this->absences->publishedShiftSegmentsBeyondSicknessWindow($row, false));

        $this->db->pdo()->prepare('DELETE FROM payroll_shifts WHERE supplier_id = ? AND employment_id = ?')
            ->execute([$this->supplierId, $this->employmentId]);
        $calendarDays = array_column($this->proration->sicknessCompensationSegments($row, false), 'local_date');
        self::assertSame('2026-06-10', max($calendarDays), 'Kalendářní cesta (převzatá DPN) končí koncem vztahu.');

        $this->publishWeekdayShifts('2026-06-01', '2026-06-18');
        $approved = $this->approve($absence);
        self::assertSame('2026-06-10', $approved['calculation']['compensation_window_to']);
        self::assertContains('sickness_beyond_employment_end', array_column($approved['warnings'], 'code'));
    }

    /**
     * Hodiny DPN pro měsíční hlášení za červen 2026. Řádky mají jen sloupce, které
     * vybírá souhrn měsíce pro JMHZ — bez vyčerpaných dnů okna.
     *
     * @param list<array<string,mixed>> $approved odpovědi schválení
     * @return array{supported:bool,minutes:array<string,int>,total:int,paid:int}
     */
    private function jmhzHours(array $approved): array
    {
        $rows = [];
        foreach ($approved as $decision) {
            $absence = $decision['absence'];
            $rows[] = [
                'id' => (int) $absence['id'],
                'absence_type' => $absence['absence_type'],
                'date_from' => $absence['date_from'],
                'date_to' => $absence['date_to'],
                'timezone_name' => $absence['timezone_name'],
                'partial_first_minutes' => null,
                'partial_last_minutes' => null,
                'status' => $absence['status'],
                'correction_pending' => false,
            ];
        }

        return $this->jmhzDeriver->derive($this->supplierId, $this->employmentId, '2026-06-01', '2026-07-01', $rows);
    }

    /** @return array<string,mixed> */
    private function createDpn(string $from, string $to, ?int $averageId): array
    {
        $response = $this->action->create(
            $this->request()->withParsedBody([
                'employment_id' => $this->employmentId,
                'absence_type' => 'dpn',
                'date_from' => $from,
                'date_to' => $to,
                'timezone_name' => 'Europe/Prague',
                'partial_first_minutes' => null,
                'partial_last_minutes' => null,
                'average_snapshot_id' => $averageId,
                'note' => 'Syntetický integrační test.',
            ]),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['absence'];
    }

    /**
     * @param array<string,mixed> $absence
     * @param array<string,mixed>|null $review
     */
    private function decide(array $absence, ?array $review = null): Response
    {
        return $this->action->decision(
            $this->request()->withParsedBody(($review ?? [
                'first_day_fully_worked' => false,
                'insurance_eligibility_confirmed' => true,
                'conflicting_benefit_excluded' => true,
            ]) + [
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
    }

    /**
     * @param array<string,mixed> $absence
     * @return array<string,mixed>
     */
    private function approve(array $absence): array
    {
        $response = $this->decide($absence);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    private function createEmployment(string $relationType): int
    {
        $employee = $this->db->pdo()->prepare(
            "INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, 'Syntetická Nemocná', 'employee', 1)"
        );
        $employee->execute([$this->supplierId]);
        $employeeId = (int) $this->db->pdo()->lastInsertId();
        $employment = $this->db->pdo()->prepare(
            "INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date, is_legacy_projection)
             VALUES (?, ?, ?, ?, 'active', '2025-10-01', 0)"
        );
        $employment->execute([$this->supplierId, $employeeId, 'SYNTH-' . strtoupper($relationType), $relationType]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function workCalendar(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_work_calendars
                (supplier_id, employment_id, name, week_pattern, weekly_minutes, valid_from)
             VALUES (?, ?, "Pondělí až pátek", ?, 2400, "2026-01-01")'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            json_encode([1 => 480, 2 => 480, 3 => 480, 4 => 480, 5 => 480, 6 => 0, 7 => 0]),
        ]);
    }

    /** Osmihodinové směny po–pá (6:00–14:30 UTC s půlhodinovou přestávkou). */
    private function publishWeekdayShifts(string $from, string $to): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO payroll_shifts
                (supplier_id, employment_id, series_key, starts_at_utc, ends_at_utc,
                 timezone_name, break_minutes, status, published_by, published_at)
             VALUES (?, ?, ?, ?, ?, 'Europe/Prague', 30, 'published', ?, NOW())"
        );
        $end = new \DateTimeImmutable($to);
        for ($day = new \DateTimeImmutable($from); $day <= $end; $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') >= 6) {
                continue;
            }
            $date = $day->format('Y-m-d');
            $stmt->execute([
                $this->supplierId,
                $this->employmentId,
                md5($this->employmentId . '|' . $date . '|' . microtime()),
                $date . ' 06:00:00',
                $date . ' 14:30:00',
                $this->userId,
            ]);
        }
    }

    private function createApprovedAverage(int $year, int $quarter, int $grossMinor = 12_000_000): int
    {
        $applicationStart = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, (($quarter - 1) * 3) + 1));
        $response = $this->action->createAverage(
            $this->request()->withParsedBody([
                'employment_id' => $this->employmentId,
                'applicable_year' => $year,
                'applicable_quarter' => $quarter,
                'decisive_from' => $applicationStart->modify('-3 months')->format('Y-m-d'),
                'decisive_to' => $applicationStart->modify('-1 day')->format('Y-m-d'),
                'gross_earnings_minor' => $grossMinor,
                'longer_period_allocated_minor' => 0,
                'worked_minutes' => 9_600,
                'worked_days' => 60,
                'probable_hourly_minor' => null,
                'rationale' => null,
            ]),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $average = $this->json($response)['snapshot'];
        $approved = $this->action->approveAverage(
            $this->request()->withParsedBody(['row_version' => $average['row_version']]),
            new Response(),
            ['id' => (string) $average['id']],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());

        return (int) $average['id'];
    }

    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payroll/time')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
