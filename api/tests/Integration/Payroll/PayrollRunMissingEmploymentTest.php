<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollRunsAction;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollRunMissingEmploymentRepository;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Tests\Support\PayrollDeletionFixturesTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Vztah, který do období schváleného běhu patří, ale v jeho revizi není.
 *
 * Zaměstnanec založený až po schválení měsíce (nebo s nástupem posunutým
 * dozadu) dřív v běhu tiše chyběl: stránka běhu ani karta vztahu nic neřekly
 * a osoba zůstala bez mzdy i bez měsíčního hlášení za měsíc nástupu.
 */
#[Group('integration')]
final class PayrollRunMissingEmploymentTest extends TestCase
{
    use PayrollDeletionFixturesTrait;

    private PayrollRunMissingEmploymentRepository $missing;
    private PayrollRunSnapshotBuilder $builder;
    private PayrollEmploymentRepository $employments;
    private PayrollRunsAction $runsAction;
    private int $runId;
    private int $revisionId;

    protected function setUp(): void
    {
        $container = $this->bootPayrollFixtures();
        $this->missing = $container->get(PayrollRunMissingEmploymentRepository::class);
        $this->builder = $container->get(PayrollRunSnapshotBuilder::class);
        $this->employments = $container->get(PayrollEmploymentRepository::class);
        $this->runsAction = $container->get(PayrollRunsAction::class);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())'
        )->execute([$this->supplierId, $this->userId]);
        $this->profile($this->employeeId);

        // Schválený červen: v revizi je jen vztah založený fixturou.
        $this->runId = $this->insertRun($this->supplierId, '2026-06-01');
        $this->revisionId = $this->insertApprovedRevision($this->supplierId, $this->runId, 'missing-june');
        $this->linkEmploymentToRevision(
            $this->supplierId,
            $this->revisionId,
            $this->employeeId,
            $this->employmentId,
        );
        $this->setRunStatus($this->runId, 'approved');
    }

    protected function tearDown(): void
    {
        $this->shutdownPayrollFixtures();
    }

    public function testEmploymentAddedAfterApprovalIsReportedOnTheRunAndOnTheEmployment(): void
    {
        self::assertSame([], $this->missing->forRuns($this->supplierId, [$this->runId]));

        [$lateEmployeeId, $lateId] = $this->lateEmployment('Syntetická Pozdní', 'HPP-LATE', '2026-06-15');
        // Nástup až v červenci do června nepatří.
        [, $julyId] = $this->lateEmployment('Syntetický Červencový', 'HPP-JULY', '2026-07-01');

        $byRun = $this->missing->forRuns($this->supplierId, [$this->runId]);
        self::assertSame([$this->runId], array_keys($byRun));
        self::assertCount(1, $byRun[$this->runId]);
        self::assertSame($lateId, $byRun[$this->runId][0]['employment_id']);
        self::assertSame($lateEmployeeId, $byRun[$this->runId][0]['employee_id']);
        self::assertSame('Syntetická Pozdní', $byRun[$this->runId][0]['full_name']);
        self::assertSame('2026-06-15', $byRun[$this->runId][0]['start_date']);

        $byEmployment = $this->missing->forEmployments(
            $this->supplierId,
            [$this->employmentId, $lateId, $julyId],
        );
        self::assertSame([$lateId], array_keys($byEmployment));
        self::assertSame(
            [['run_id' => $this->runId, 'period' => '2026-06', 'status' => 'approved']],
            $byEmployment[$lateId],
        );
    }

    /**
     * Jedno místo pravdy: chybějící vztahy jsou přesně ty, které by do běhu
     * vzal nový snímek ({@see PayrollRunSnapshotBuilder::employmentRows()}),
     * bez vztahů schválené revize.
     */
    public function testMissingSetEqualsSnapshotSelectionMinusApprovedRevision(): void
    {
        $this->lateEmployment('Syntetická Pozdní', 'HPP-LATE', '2026-06-15');
        $this->lateEmployment('Syntetický Červencový', 'HPP-JULY', '2026-07-01');
        // Skončil před obdobím: do června nepatří ani podle snímku.
        [, $endedId] = $this->lateEmployment('Syntetický Skončený', 'HPP-ENDED', '2026-02-01');
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET end_date = "2026-05-31", status = "ended"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $endedId]);
        // Nenastoupil: snímek ho vyřadí stavem.
        [, $noShowId] = $this->lateEmployment('Syntetický Nenástup', 'HPP-NOSHOW', '2026-06-01');
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "no_show" WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $noShowId]);

        $selected = array_map(
            static fn (array $row): int => (int) $row['employment_id'],
            $this->builder->employmentRows($this->supplierId, '2026-06-01', '2026-06-30', null),
        );
        $expected = array_values(array_diff($selected, [$this->employmentId]));
        sort($expected);
        self::assertNotSame([], $expected);

        $reported = array_column(
            $this->missing->forRuns($this->supplierId, [$this->runId])[$this->runId] ?? [],
            'employment_id',
        );
        sort($reported);
        self::assertSame($expected, $reported);
    }

    public function testEarlierStartMovedIntoApprovedMonthIsReported(): void
    {
        [, $movedId] = $this->lateEmployment('Syntetický Posunutý', 'HPP-MOVED', '2026-07-01');
        self::assertSame([], $this->missing->forEmployments($this->supplierId, [$movedId]));

        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET start_date = "2026-06-10" WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $movedId]);

        self::assertSame(
            ['2026-06'],
            array_column($this->missing->forEmployments($this->supplierId, [$movedId])[$movedId] ?? [], 'period'),
        );
    }

    public function testOpenTakeoverAndForeignRunsAreNotReported(): void
    {
        [, $lateId] = $this->lateEmployment('Syntetická Pozdní', 'HPP-LATE', '2026-06-15');

        // Otevřená revize: hlídá ji detektor zastaralých podkladů, ne tenhle nález.
        $this->setRunStatus($this->runId, 'calculated');
        self::assertSame([], $this->missing->forRuns($this->supplierId, [$this->runId]));
        // Běh otevřený k opravě: nový snímek vztah vezme sám.
        $this->setRunStatus($this->runId, 'correction_pending');
        self::assertSame([], $this->missing->forRuns($this->supplierId, [$this->runId]));

        foreach (['approved', 'posted', 'payment_ready', 'paid', 'closed'] as $status) {
            $this->setRunStatus($this->runId, $status);
            self::assertSame(
                [$lateId],
                array_column($this->missing->forRuns($this->supplierId, [$this->runId])[$this->runId] ?? [], 'employment_id'),
                $status,
            );
        }

        // Cizí firma na běh nedosáhne.
        self::assertSame([], $this->missing->forRuns($this->otherSupplierId, [$this->runId]));
        self::assertSame([], $this->missing->forEmployments($this->otherSupplierId, [$lateId]));
    }

    public function testRunListAndEmploymentCardCarryTheGap(): void
    {
        [$lateEmployeeId, $lateId] = $this->lateEmployment('Syntetická Pozdní', 'HPP-LATE', '2026-06-15');

        $response = $this->runsAction->list(
            $this->request('GET', '/api/payroll/runs', null, null, 'admin'),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode());
        $runs = $this->json($response)['runs'];
        self::assertIsArray($runs);
        self::assertCount(1, $runs);
        self::assertIsArray($runs[0]);
        self::assertSame(
            [[
                'employment_id' => $lateId,
                'employee_id' => $lateEmployeeId,
                'full_name' => 'Syntetická Pozdní',
                'employment_code' => 'HPP-LATE',
                'relation_type' => 'employment',
                'start_date' => '2026-06-15',
            ]],
            $runs[0]['missing_employments'],
        );

        $card = $this->employments->listForEmployee($this->supplierId, $lateEmployeeId);
        self::assertCount(1, $card);
        self::assertSame(
            [['run_id' => $this->runId, 'period' => '2026-06', 'status' => 'approved']],
            $card[0]['locked_run_gaps'],
        );
        $included = $this->employments->listForEmployee($this->supplierId, $this->employeeId);
        self::assertSame([], $included[0]['locked_run_gaps']);
    }

    /** @return array{0:int,1:int} osoba a vztah */
    private function lateEmployment(string $name, string $code, string $startDate): array
    {
        $employeeId = $this->insertEmployee($this->supplierId, $name);
        $this->profile($employeeId);
        $employmentId = $this->insertEmployment($this->supplierId, $employeeId, $code);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET start_date = ? WHERE supplier_id = ? AND id = ?'
        )->execute([$startDate, $this->supplierId, $employmentId]);

        return [$employeeId, $employmentId];
    }

    private function profile(int $employeeId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employee_profiles (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "ready")'
        )->execute([$this->supplierId, $employeeId]);
    }

    private function setRunStatus(int $runId, string $status): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_runs SET status = ?, current_revision_no = 1 WHERE supplier_id = ? AND id = ?'
        )->execute([$status, $this->supplierId, $runId]);
    }
}
