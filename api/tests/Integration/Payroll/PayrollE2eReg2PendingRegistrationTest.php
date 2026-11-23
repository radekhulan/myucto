<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotException;
use MyInvoice\Tests\Support\PayrollE2eRegistrationTrait;
use MyInvoice\Tests\Support\PayrollE2eRegSweepTrait;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Chybějící OIČ a ID PPV u vztahu, jehož přihláška A1 je na cestě: karta
 * vztahu i hláška o chybějícím identifikátoru (odhláška, změna) řeknou, že
 * přihláška čeká ve frontě nebo na protokol ČSSZ, místo obecného „chybí".
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eReg2PendingRegistrationTest extends TestCase
{
    use PayrollFullFlowTrait;
    use PayrollE2eRegistrationTrait;
    use PayrollE2eRegSweepTrait;

    private const START_ON = '2026-11-16';

    private PayrollRegistrationIdentityService $identities;

    protected function setUp(): void
    {
        $this->bootRegistrationFlow('2026-11-11');
        $this->attempts = new PayrollSubmissionTransportAttemptRepository($this->db);
        if (!$this->attempts->isAvailable()) {
            self::markTestSkipped('Ledger pokusů o odeslání chybí.');
        }
        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        self::assertInstanceOf(PayrollRegistrationIdentityService::class, $identities);
        $this->identities = $identities;
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testMissingIdentifiersNameTheRegistrationOnItsWay(): void
    {
        [$employeeId, $employmentId] = $this->regPerson('Čekající', 'Protokol', 81, 'dpp', self::START_ON);

        // Bez přihlášky: obecná rada, kde číslo vzít.
        self::assertNull($this->identityStatus($employmentId)['pending_registration']);
        $plain = $this->missing($employeeId, $employmentId);
        self::assertSame('jmhz_identity_oic_missing', $plain->validationCode);
        self::assertStringContainsString('v protokolu o přijetí registrace', $plain->getMessage());

        // Připravená, neodeslaná přihláška.
        $this->regSaveA1Profile($employmentId, $this->regA1Profile(self::START_ON, 'T'));
        $prepared = $this->regPrepare($employmentId, ['registration_mode' => 'full']);
        $submissionId = (int) $prepared['submission_id'];
        self::assertSame(
            ['submission_id' => $submissionId, 'state' => 'queued', 'submitted_on' => null],
            $this->identityStatus($employmentId)['pending_registration'],
        );
        $queued = $this->missing($employeeId, $employmentId);
        self::assertSame('jmhz_identity_oic_missing', $queued->validationCode);
        self::assertStringContainsString('je připravená, ale ještě neodešla', $queued->getMessage());
        self::assertStringContainsString("podání č. {$submissionId}", $queued->getMessage());
        self::assertStringNotContainsString('v protokolu o přijetí registrace', $queued->getMessage());

        // Odeslaná, čeká na protokol.
        $correlation = 'B0000000000000000000000000000081';
        $this->markSent($prepared, $correlation);
        self::assertSame(
            ['submission_id' => $submissionId, 'state' => 'awaiting_result', 'submitted_on' => '2026-11-11'],
            $this->identityStatus($employmentId)['pending_registration'],
        );
        $awaiting = $this->missing($employeeId, $employmentId);
        self::assertSame('jmhz_identity_oic_missing', $awaiting->validationCode);
        self::assertStringContainsString('odeslaná 11. 11. 2026', $awaiting->getMessage());
        self::assertStringContainsString('čeká na protokol ČSSZ', $awaiting->getMessage());
        self::assertStringContainsString('doplní samo', $awaiting->getMessage());
        self::assertStringContainsString('„Zjistit výsledek"', $awaiting->getMessage());
        self::assertStringNotContainsString('v protokolu o přijetí registrace', $awaiting->getMessage());

        // Po přijetí už není na co čekat.
        $this->sweep([
            $this->protocol(
                $correlation,
                'OK',
                $this->birthNumber($submissionId) . ';1000000012;200000000000000000081',
            ),
            $this->acknowledgement($correlation),
        ])->run();
        $status = $this->identityStatus($employmentId);
        self::assertNull($status['pending_registration']);
        self::assertNotNull($status['person_external_identifier']);
        self::assertNotNull($status['employment_external_identifier']);
    }

    /** Čekající přihláška jiného vztahu téže osoby se k tomuhle vztahu nehlásí. */
    public function testPendingRegistrationOfAnotherEmploymentIsNotReported(): void
    {
        [$employeeId, $employmentId] = $this->regPerson('Čekající', 'Jinde', 83, 'dpp', self::START_ON);
        $this->regSaveA1Profile($employmentId, $this->regA1Profile(self::START_ON, 'T'));
        $this->markSent($this->regPrepare($employmentId, ['registration_mode' => 'full']), 'B0000000000000000000000000000083');
        $other = $this->regAdditionalEmployment($employeeId, 'DPP-2', 'dpp', '2026-12-01');

        $status = $this->identities->jmhzIdentityStatusAt($this->supplierId, $other, 'test', '2026-12-01');

        self::assertNull($status['pending_registration']);
    }

    /** @return array<string,mixed> */
    private function identityStatus(int $employmentId): array
    {
        return $this->identities->jmhzIdentityStatusAt($this->supplierId, $employmentId, 'test', self::START_ON);
    }

    private function missing(int $employeeId, int $employmentId): PayrollRegistrationIdentitySnapshotException
    {
        try {
            $this->identities->sensitiveJmhzIdentityAt($this->supplierId, $employeeId, $employmentId, 'test', self::START_ON);
        } catch (PayrollRegistrationIdentitySnapshotException $exception) {
            return $exception;
        }
        self::fail('Identifikátory nechybí.');
    }
}
