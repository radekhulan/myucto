<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1BatchService;
use MyInvoice\Tests\Support\PayrollE2eRegistrationTrait;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Souběžná hromadná příprava A1 nesmí k jednomu vztahu založit dvě přihlášky.
 *
 * Dva skutečně souběžné požadavky tu pustit nejde: test běží v transakci,
 * kterou jiné spojení nevidí, a zapsat data natrvalo test nesmí. Hlídají se
 * proto obě pojistky zvlášť: příprava pod zámkem firmy vrátí při opakování
 * totéž podání a databáze sama odmítne druhé řádné podání téže povinnosti
 * i opakované založení téže povinnosti, kdyby se dva požadavky zámku vyhnuly.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eReg2ConcurrencyTest extends TestCase
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

    public function testDatabaseRefusesSecondRegistrationOfTheSameEmployment(): void
    {
        [, $employmentId] = $this->regPerson('Souběžná', 'Příprava', 87, 'dpp', self::START_ON);
        $this->regSaveA1Profile($employmentId, $this->regA1Profile(self::START_ON, 'T'));
        $batch = $this->container->get(PayrollRegistrationA1BatchService::class);
        self::assertInstanceOf(PayrollRegistrationA1BatchService::class, $batch);

        $first = $batch->prepare($this->supplierId, 'test', [$employmentId], $this->actors[0])['results'][0];
        self::assertSame('prepared', $first['status']);
        $submissionId = (int) $first['submission_id'];

        // Druhý požadavek, který přípravu zastihne hotovou, nic nezaloží.
        $second = $batch->prepare($this->supplierId, 'test', [$employmentId], $this->actors[0])['results'][0];
        self::assertSame('skipped', $second['status']);
        self::assertSame('live_registration', $second['code']);
        self::assertSame($submissionId, (int) $second['submission_id']);

        // Požadavek, který by kontrolu živé přihlášky minul, zastaví databáze:
        // na povinnost připadá jediné řádné podání …
        $this->assertDuplicate('uq_payroll_submissions_regular', fn () => $this->db->pdo()->prepare(
            'INSERT INTO payroll_submissions
                (supplier_id, environment, obligation_id, submission_kind, channel, status,
                 source_snapshot_hash, request_fingerprint, idempotency_key_hash)
             SELECT supplier_id, environment, obligation_id, submission_kind, channel, "draft",
                    source_snapshot_hash, request_fingerprint, ?
               FROM payroll_submissions WHERE supplier_id = ? AND id = ?',
        )->execute([hash('sha256', 'second-request', true), $this->supplierId, $submissionId]));
        // … a povinnost nese jedinečný klíč odvozený z údajů přihlášky, takže
        // tutéž povinnost podruhé založit nejde.
        $this->assertDuplicate('uq_payroll_obligations_idempotency', fn () => $this->db->pdo()->prepare(
            'INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type, subject_reference, period_start,
                 period_end, obligation_kind, preferred_channel, status, source_event_type,
                 source_event_reference, source_event_hash, request_fingerprint, idempotency_key_hash)
             SELECT obligation.supplier_id, obligation.environment, obligation.agenda_code,
                    obligation.subject_type, obligation.subject_reference, obligation.period_start,
                    obligation.period_end, obligation.obligation_kind, obligation.preferred_channel,
                    obligation.status, obligation.source_event_type, obligation.source_event_reference,
                    obligation.source_event_hash, obligation.request_fingerprint,
                    obligation.idempotency_key_hash
               FROM payroll_obligations obligation
               JOIN payroll_submissions submission
                 ON submission.supplier_id = obligation.supplier_id AND submission.obligation_id = obligation.id
              WHERE submission.supplier_id = ? AND submission.id = ?',
        )->execute([$this->supplierId, $submissionId]));

        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_submission_parts WHERE supplier_id = ? AND subject_reference = ?',
            [$this->supplierId, "payroll_employment:{$employmentId}"],
        ));
    }

    private function assertDuplicate(string $key, callable $insert): void
    {
        try {
            $insert();
            self::fail("Databáze přijala duplicitní řádek, klíč {$key} nehlídá.");
        } catch (\PDOException $exception) {
            self::assertSame('23000', (string) $exception->getCode(), $exception->getMessage());
            self::assertStringContainsString($key, $exception->getMessage());
        }
    }
}
