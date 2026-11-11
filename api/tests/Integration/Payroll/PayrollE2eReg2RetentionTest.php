<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollEmployeeDeletionRepository;
use MyInvoice\Service\Payroll\Retention\PayrollRetentionAssessment;
use MyInvoice\Service\Payroll\Retention\PayrollRetentionCatalog;
use MyInvoice\Service\Payroll\Retention\PayrollRetentionService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Tests\Support\PayrollE2eRegistrationTrait;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přihláška ČSSZ, která brání ručnímu smazání vztahu, nesmí osobu držet
 * proti výmazu napořád: posudek retence o osobě s přihláškou ví (lhůtu drží
 * profil A1 a identifikátory od ČSSZ) a po uplynutí lhůty ji navrhne
 * k anonymizaci. Dokládá důvod výjimky blokátoru `registration_submission`
 * v {@see \MyInvoice\Tests\Architecture\PayrollRetentionBlockerCoverageTest}.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eReg2RetentionTest extends TestCase
{
    use PayrollFullFlowTrait;
    use PayrollE2eRegistrationTrait;

    private const START_ON = '2026-11-16';
    /** Den po uplynutí nejdelší lhůty (45 kalendářních roků od roku poslední stopy). */
    private const AFTER_RETENTION = '2100-01-01';

    protected function setUp(): void
    {
        $this->bootRegistrationFlow('2026-11-11');
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testAcceptedRegistrationIsProposedForAnonymizationAfterRetention(): void
    {
        [$employeeId, $employmentId] = $this->regPerson('Přijatá', 'Retence', 93, 'dpp', self::START_ON);
        $this->regSaveA1Profile($employmentId, $this->regA1Profile(self::START_ON, 'T'));
        $this->regReceipt(
            $this->regPrepare($employmentId, ['registration_mode' => 'full']),
            'accepted',
            '1000000012',
            '200000000000000000093',
        );
        self::assertFalse($this->canDelete($employeeId));

        $within = $this->assessment($employeeId, '2026-12-31');
        self::assertSame(PayrollRetentionAssessment::BLOCK_WITHIN_RETENTION, $within->blockedBy);
        self::assertContains(PayrollRetentionCatalog::PENSION_EVIDENCE, array_column($within->categories, 'category'));

        $after = $this->assessment($employeeId, self::AFTER_RETENTION);
        self::assertNull($after->blockedBy, 'Přijatá přihláška drží osobu i po uplynutí lhůty.');
        self::assertTrue($after->isProposable());
        self::assertSame(PayrollRetentionAssessment::ACTION_ANONYMIZE, $after->action);
    }

    public function testQueuedRegistrationHasRetentionBasisAndStopsBlockingOnceCancelled(): void
    {
        [$employeeId, $employmentId] = $this->regPerson('Fronta', 'Retence', 95, 'dpp', self::START_ON);
        $this->regSaveA1Profile($employmentId, $this->regA1Profile(self::START_ON, 'T'));
        $prepared = $this->regPrepare($employmentId, ['registration_mode' => 'full']);
        self::assertFalse($this->canDelete($employeeId), 'Připravená přihláška má smazání držet.');

        // Lhůtu drží profil A1, bez kterého přihláška nevznikne.
        $queued = $this->assessment($employeeId, self::AFTER_RETENTION);
        self::assertContains(PayrollRetentionCatalog::PENSION_EVIDENCE, array_column($queued->categories, 'category'));
        self::assertNotSame(PayrollRetentionAssessment::BLOCK_NO_BASIS, $queued->blockedBy);
        self::assertTrue($queued->isProposable());

        // Blokátor je přechodný: zrušením přihlášky zmizí.
        $submissions = $this->container->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);
        $submissions->transition(
            $this->supplierId,
            (int) $prepared['submission_id'],
            (int) $prepared['row_version'],
            'cancelled_in_time',
        );
        self::assertTrue($this->canDelete($employeeId));
        self::assertSame(
            PayrollRetentionAssessment::ACTION_ERASE,
            $this->assessment($employeeId, self::AFTER_RETENTION)->action,
        );
    }

    private function canDelete(int $employeeId): bool
    {
        $deletion = $this->container->get(PayrollEmployeeDeletionRepository::class);
        self::assertInstanceOf(PayrollEmployeeDeletionRepository::class, $deletion);
        $decision = $deletion->canDelete($this->supplierId, $employeeId);
        self::assertNotNull($decision);

        return $decision->canDelete;
    }

    private function assessment(int $employeeId, string $asOf): PayrollRetentionAssessment
    {
        $retention = $this->container->get(PayrollRetentionService::class);
        self::assertInstanceOf(PayrollRetentionService::class, $retention);
        foreach ($retention->assess($this->supplierId, $asOf) as $assessment) {
            if ($assessment->employeeId === $employeeId) {
                return $assessment;
            }
        }
        self::fail('Osoba v posudku retence chybí.');
    }
}
