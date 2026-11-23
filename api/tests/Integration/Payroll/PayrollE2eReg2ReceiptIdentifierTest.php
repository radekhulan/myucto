<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\PayrollE2eRegistrationTrait;
use MyInvoice\Tests\Support\PayrollE2eRegSweepTrait;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Protokol ČSSZ přijme přihlášku A1, ale přidělený identifikátor nemá
 * předepsaný tvar (OIČ nesplní kontrolní číslici podle kontroly 37 Katalogu
 * kontrol MH, ID PPV má víc než 22 číslic). Přihláška se uzavře jako přijatá,
 * vadné číslo se do evidence nezapíše a podání nese pojmenovaný nález
 * s návodem; ruční opis správného čísla z ePortálu projde.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eReg2ReceiptIdentifierTest extends TestCase
{
    use PayrollFullFlowTrait;
    use PayrollE2eRegistrationTrait;
    use PayrollE2eRegSweepTrait;

    private const START_ON = '2026-11-16';
    /** Deset číslic, ale kontrolní číslice by musela být 2. */
    private const MALFORMED_OIC = '1000000013';
    private const VALID_OIC = '1000000012';

    protected function setUp(): void
    {
        $this->bootRegistrationFlow('2026-11-11');
        $this->attempts = new PayrollSubmissionTransportAttemptRepository($this->db);
        if (!$this->attempts->isAvailable()) {
            self::markTestSkipped('Ledger pokusů o odeslání chybí.');
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testAcceptedProtocolWithMalformedOicClosesRegistrationAndNamesTheFinding(): void
    {
        [$employeeId, $employmentId] = $this->regPerson('Protokol', 'Vadné', 71, 'dpp', self::START_ON);
        $this->regSaveA1Profile($employmentId, $this->regA1Profile(self::START_ON, 'T'));
        $prepared = $this->regPrepare($employmentId, ['registration_mode' => 'full']);
        $submissionId = (int) $prepared['submission_id'];
        $correlation = 'B0000000000000000000000000000071';
        $this->markSent($prepared, $correlation);

        $result = $this->sweep([
            $this->protocol(
                $correlation,
                'OK',
                $this->birthNumber($submissionId) . ';' . self::MALFORMED_OIC . ';200000000000000000071',
            ),
            $this->acknowledgement($correlation),
        ])->run();

        self::assertSame(1, $result['completed'], CanonicalJson::encode($result));
        self::assertSame(0, $result['errors'], CanonicalJson::encode($result));
        // Přihláška u ČSSZ přijata je a podepsaný protokol je platný doklad.
        self::assertSame('accepted', $this->regSubmissionStatus($submissionId));
        self::assertSame(['trusted'], $this->receiptVerification($submissionId));
        // Vadné OIČ se nepřevzalo, ID PPV z téhož protokolu ano.
        self::assertSame(0, $this->oicCount($employeeId));
        self::assertSame(self::START_ON, $this->regIdentifier($employmentId, 'id_ppv'));
        self::assertSame(
            [[
                'severity' => 'error',
                'validation_stage' => 'remote',
                'issue_code' => 'registration_receipt_oic_invalid',
                'entity_type' => 'payroll_employment',
                'entity_reference' => "payroll_employment:{$employmentId}",
            ]],
            $this->issues($submissionId),
        );
        $again = $this->regPrepareRaw($employmentId, ['registration_mode' => 'full']);
        self::assertNotSame(201, $again['status'], 'Přijatá přihláška se nabídla znovu.');

        // Ruční opis téhož vadného čísla aplikace dál odmítá, správné číslo
        // z ePortálu ČSSZ projde.
        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        self::assertInstanceOf(PayrollRegistrationIdentityService::class, $identities);
        try {
            $identities->assignManualJmhzIdentity(
                $this->supplierId,
                $employmentId,
                'test',
                self::MALFORMED_OIC,
                null,
                self::START_ON,
                null,
                true,
                $this->actors[0],
            );
            self::fail('Ruční opis přijal OIČ, které nesplní kontrolní číslici.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('kontrolní', $exception->getMessage());
        }
        $identities->assignManualJmhzIdentity(
            $this->supplierId,
            $employmentId,
            'test',
            self::VALID_OIC,
            null,
            self::START_ON,
            null,
            true,
            $this->actors[0],
        );
        self::assertSame(1, $this->oicCount($employeeId));
        $identity = $identities->sensitiveJmhzIdentityAt($this->supplierId, $employeeId, $employmentId, 'test', '2026-11-30');
        self::assertSame(self::VALID_OIC, $identity['person_external_identifier']['value']);
        self::assertSame('200000000000000000071', $identity['employment_external_identifier']['value']);

        $second = $this->sweep([])->run();
        self::assertSame(0, $second['polled'], CanonicalJson::encode($second));
        self::assertCount(1, $this->issues($submissionId));
    }

    public function testAcceptedProtocolWithMalformedIdPpvKeepsOicAndNamesTheFinding(): void
    {
        [$employeeId, $employmentId] = $this->regPerson('Protokol', 'Dlouhé', 73, 'dpp', self::START_ON);
        $this->regSaveA1Profile($employmentId, $this->regA1Profile(self::START_ON, 'T'));
        $prepared = $this->regPrepare($employmentId, ['registration_mode' => 'full']);
        $submissionId = (int) $prepared['submission_id'];

        $this->regReceipt($prepared, 'accepted', self::VALID_OIC, '20000000000000000000073');

        self::assertSame('accepted', $this->regSubmissionStatus($submissionId));
        self::assertSame(1, $this->oicCount($employeeId));
        self::assertNull($this->regIdentifier($employmentId, 'id_ppv'));
        self::assertSame(
            ['registration_receipt_id_ppv_invalid'],
            array_column($this->issues($submissionId), 'issue_code'),
        );
    }

    /** Běžný protokol nález nedostane. */
    public function testWellFormedIdentifiersLeaveNoFinding(): void
    {
        [$employeeId, $employmentId] = $this->regPerson('Protokol', 'Správné', 75, 'dpp', self::START_ON);
        $this->regSaveA1Profile($employmentId, $this->regA1Profile(self::START_ON, 'T'));
        $prepared = $this->regPrepare($employmentId, ['registration_mode' => 'full']);

        $this->regReceipt($prepared, 'accepted', self::VALID_OIC, '200000000000000000075');

        self::assertSame([], $this->issues((int) $prepared['submission_id']));
        self::assertSame(1, $this->oicCount($employeeId));
        self::assertSame(self::START_ON, $this->regIdentifier($employmentId, 'id_ppv'));
    }

    /** @return list<array<string,mixed>> */
    private function issues(int $submissionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT severity, validation_stage, issue_code, entity_type, entity_reference
               FROM payroll_submission_issues
              WHERE supplier_id = ? AND submission_id = ?
              ORDER BY id',
        );
        $statement->execute([$this->supplierId, $submissionId]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<string> */
    private function receiptVerification(int $submissionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT verification_status FROM payroll_submission_receipts
              WHERE supplier_id = ? AND submission_id = ? ORDER BY id',
        );
        $statement->execute([$this->supplierId, $submissionId]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function oicCount(int $employeeId): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_person_external_ids WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $employeeId],
        );
    }
}
