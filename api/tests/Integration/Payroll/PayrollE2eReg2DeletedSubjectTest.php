<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentAction;
use MyInvoice\Action\Payroll\PayrollSubmissionDetailAction;
use MyInvoice\Action\Payroll\PayrollSubmissionOverviewAction;
use MyInvoice\Repository\Payroll\PayrollEmployeeDeletionRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionQueueService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Tests\Support\PayrollE2eRegistrationTrait;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Podání registrace, jehož pracovní vztah byl smazán (včas zrušená nebo
 * odmítnutá přihláška smazání nebrání): přehled, detail i fronta podání ho
 * dál popisují jménem osoby a tím, že vztah už neexistuje, místo řádku bez
 * předmětu.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eReg2DeletedSubjectTest extends TestCase
{
    use PayrollFullFlowTrait;
    use PayrollE2eRegistrationTrait;

    private const START_ON = '2026-11-16';

    protected function setUp(): void
    {
        $this->bootRegistrationFlow('2026-11-11');
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testCancelledRegistrationOfDeletedEmploymentKeepsPersonAndSaysEmploymentIsGone(): void
    {
        [$employeeId, $employmentId] = $this->regPerson('Zrušená', 'Omylem', 77, 'employment', self::START_ON);
        // Druhý vztah drží osobu v evidenci i po smazání chybného vztahu.
        $this->regAdditionalEmployment($employeeId, 'DPP-K', 'dpp', '2026-12-01');
        $this->regSaveA1Profile($employmentId, $this->regA1Profile(self::START_ON));
        $prepared = $this->regPrepare($employmentId, ['registration_mode' => 'full']);
        $submissionId = (int) $prepared['submission_id'];
        $this->submissions()->transition(
            $this->supplierId,
            $submissionId,
            (int) $prepared['row_version'],
            'cancelled_in_time',
        );
        $before = $this->overviewItem($submissionId);
        self::assertSame('Zrušená Omylem', $before['subject_label']);
        self::assertSame($employeeId, $before['subject_employee_id']);

        self::assertSame(200, $this->deleteEmployment($employmentId));

        $after = $this->overviewItem($submissionId);
        self::assertSame('cancelled_in_time', $after['latest_submission']['status']);
        self::assertSame('Zrušená Omylem (smazaný pracovní vztah)', $after['subject_label']);
        self::assertSame($employeeId, $after['subject_employee_id'], 'Karta osoby dál existuje, odkaz na ni je platný.');
        $detail = $this->detail($submissionId);
        self::assertSame('Zrušená Omylem (smazaný pracovní vztah)', $detail['submission']['subject_label']);
        self::assertSame($employeeId, $detail['submission']['subject_employee_id']);
    }

    public function testRejectedRegistrationOfDeletedEmploymentIsDescribedInQueue(): void
    {
        [$employeeId, $employmentId] = $this->regPerson('Odmítnutá', 'Smazaná', 79, 'employment', self::START_ON);
        $this->regSaveA1Profile($employmentId, $this->regA1Profile(self::START_ON));
        $prepared = $this->regPrepare($employmentId, ['registration_mode' => 'full']);
        $submissionId = (int) $prepared['submission_id'];
        $this->regReceipt($prepared, 'rejected');
        self::assertSame('rejected', $this->regSubmissionStatus($submissionId));
        self::assertSame('Odmítnutá Smazaná', $this->queueItem($submissionId)['subject_label']);

        self::assertSame(200, $this->deleteEmployment($employmentId));

        // Osoba v evidenci zůstala: fronta ji dál jmenuje a vede na její kartu.
        $queued = $this->queueItem($submissionId);
        self::assertSame('Odmítnutá Smazaná (smazaný pracovní vztah)', $queued['subject_label']);
        self::assertSame($employeeId, $queued['subject_employee_id']);

        // Po smazání osoby zbude jen popis bez jména a bez odkazu.
        $people = $this->container->get(PayrollEmployeeDeletionRepository::class);
        self::assertInstanceOf(PayrollEmployeeDeletionRepository::class, $people);
        $people->delete($this->supplierId, $employeeId, $this->actors[0], null, null);

        $queued = $this->queueItem($submissionId);
        self::assertSame('smazaný pracovní vztah', $queued['subject_label']);
        self::assertNull($queued['subject_employee_id']);
        $overview = $this->overviewItem($submissionId);
        self::assertSame('smazaný pracovní vztah', $overview['subject_label']);
        self::assertNull($overview['subject_employee_id']);
    }

    private function submissions(): PayrollSubmissionService
    {
        $submissions = $this->container->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);

        return $submissions;
    }

    private function deleteEmployment(int $employmentId): int
    {
        $action = $this->container->get(PayrollEmploymentAction::class);
        self::assertInstanceOf(PayrollEmploymentAction::class, $action);
        $response = $action->delete(
            $this->request('DELETE', "/api/payroll/employments/{$employmentId}")->withParsedBody([
                'row_version' => (int) $this->scalar(
                    'SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?',
                    [$this->supplierId, $employmentId],
                ),
            ]),
            new Response(),
            ['id' => (string) $employmentId],
        );

        return $response->getStatusCode();
    }

    /** @return array<string,mixed> */
    private function overviewItem(int $submissionId): array
    {
        $period = substr((string) $this->scalar(
            'SELECT obligation.period_start FROM payroll_submissions submission
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = submission.supplier_id AND obligation.id = submission.obligation_id
              WHERE submission.supplier_id = ? AND submission.id = ?',
            [$this->supplierId, $submissionId],
        ), 0, 7);
        $action = $this->container->get(PayrollSubmissionOverviewAction::class);
        self::assertInstanceOf(PayrollSubmissionOverviewAction::class, $action);
        $response = $action(
            $this->request('GET', '/api/payroll/submissions')
                ->withQueryParams(['environment' => 'test', 'period' => $period]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        foreach ($this->json($response)['items'] as $item) {
            if ((int) ($item['latest_submission']['id'] ?? 0) === $submissionId) {
                return $item;
            }
        }
        self::fail('Podání v přehledu chybí: ' . CanonicalJson::encode($this->json($response)['items']));
    }

    /** @return array<string,mixed> */
    private function detail(int $submissionId): array
    {
        $action = $this->container->get(PayrollSubmissionDetailAction::class);
        self::assertInstanceOf(PayrollSubmissionDetailAction::class, $action);
        $response = $action(
            $this->request('GET', "/api/payroll/submissions/{$submissionId}")
                ->withQueryParams(['environment' => 'test']),
            new Response(),
            ['submissionId' => (string) $submissionId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /** @return array<string,mixed> */
    private function queueItem(int $submissionId): array
    {
        $queue = $this->container->get(PayrollSubmissionQueueService::class);
        self::assertInstanceOf(PayrollSubmissionQueueService::class, $queue);
        foreach ($queue->queue($this->supplierId, 'test', 200, 0)['items'] as $item) {
            if ((int) $item['submission_id'] === $submissionId) {
                return $item;
            }
        }
        self::fail('Podání ve frontě chybí.');
    }
}
