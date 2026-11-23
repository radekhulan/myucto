<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Checklist skončení odškrtává odhlášky podle STAVU podání, ne podle toho,
 * že povinnost vznikla. Povinnost se zakládá už při přípravě hlášení, takže
 * dřívější `status <> 'cancelled'` hlásilo „odhlášeno" nad odhláškou, která
 * ležela neodeslaná a lhůta běžela. Odhláška z evidence ČSSZ (REGZEC A2)
 * se neodškrtávala vůbec.
 */
#[Group('integration')]
final class PayrollEmploymentChecklistEvidenceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEmploymentRepository $repository;
    private int $supplierId;
    private int $userId;
    private int $employeeId;
    private int $employmentId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        $this->db = $db;
        foreach ([
            'payroll_employment_checklist_items',
            'payroll_obligations',
            'payroll_registration_event_snapshots',
        ] as $table) {
            if (!$db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $repository = $container->get(PayrollEmploymentRepository::class);
        if (!$repository instanceof PayrollEmploymentRepository) {
            throw new \RuntimeException('Repozitář vztahů není dostupný.');
        }
        $this->repository = $repository;
        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická osoba odhláška", "employee", 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 is_primary, start_date, actual_start_date, end_date)
             VALUES (?, ?, "ODH-1", "employment", "ended", 0,
                     "2026-01-01", "2026-01-01", "2026-08-31")',
        )->execute([$this->supplierId, $this->employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
        foreach (['health_insurance_deregistration', 'social_jmhz_deregistration'] as $key) {
            $pdo->prepare(
                'INSERT INTO payroll_employment_checklist_items
                    (supplier_id, employment_id, phase, item_key, status, due_date)
                 VALUES (?, ?, "offboarding", ?, "pending", "2026-09-08")',
            )->execute([$this->supplierId, $this->employmentId, $key]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testPreparedHealthDeregistrationIsNotReportedAsDone(): void
    {
        $this->obligation(
            'payroll_health_notification',
            'payroll_health_notification:' . $this->employmentId . ':employment_end:2026-08-31',
            'prepared',
        );

        self::assertSame('pending', $this->item('health_insurance_deregistration')['effective_status']);
        self::assertFalse($this->item('health_insurance_deregistration')['evidence_present']);
    }

    public function testSubmittedHealthDeregistrationCompletesTheItem(): void
    {
        $this->obligation(
            'payroll_health_notification',
            'payroll_health_notification:' . $this->employmentId . ':employment_end:2026-08-31',
            'submitted',
        );

        $item = $this->item('health_insurance_deregistration');
        self::assertSame('completed', $item['effective_status']);
        self::assertSame('health_end_obligation', $item['evidence_kind']);
    }

    public function testTestEnvironmentSubmissionIsNotEvidence(): void
    {
        $this->obligation(
            'payroll_health_notification',
            'payroll_health_notification:' . $this->employmentId . ':employment_end:2026-08-31',
            'fulfilled',
            'test',
        );

        self::assertSame('pending', $this->item('health_insurance_deregistration')['effective_status']);
    }

    public function testA2DeregistrationFollowsSubmissionState(): void
    {
        $eventId = $this->a2Event();
        $obligationId = $this->obligation(
            'payroll_employment_registration',
            'payroll_registration_event:' . $eventId,
            'prepared',
        );
        self::assertSame('pending', $this->item('social_jmhz_deregistration')['effective_status']);

        $this->db->pdo()->prepare(
            'UPDATE payroll_obligations SET status = "fulfilled" WHERE id = ?',
        )->execute([$obligationId]);

        $item = $this->item('social_jmhz_deregistration');
        self::assertSame('completed', $item['effective_status']);
        self::assertSame('deregistration_obligation', $item['evidence_kind']);
    }

    /** @return array<string,mixed> */
    private function item(string $key): array
    {
        foreach ($this->repository->listForEmployee($this->supplierId, $this->employeeId) as $employment) {
            foreach ($employment['checklist'] as $item) {
                if ($item['item_key'] === $key) {
                    return $item;
                }
            }
        }
        self::fail("Položka {$key} chybí.");
    }

    private function a2Event(): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_registration_event_snapshots
                (supplier_id, employee_id, employment_id, environment,
                 interaction_code, action_code, effective_on, source_kind,
                 source_reference, source_manifest_json, source_manifest_hash,
                 snapshot_ciphertext, snapshot_fingerprint, approved_by, approved_at)
             VALUES (?, ?, ?, "production", "termination", 2, "2026-08-31",
                     "employment_exit", "syntetika", "{}", ?, "enc:v2:syntetika", ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            str_repeat('c', 64),
            str_repeat('d', 64),
            $this->userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function obligation(
        string $sourceEventType,
        string $reference,
        string $status,
        string $environment = 'production',
    ): int {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type,
                 subject_reference, period_start, period_end, obligation_kind,
                 preferred_channel, status, source_event_type,
                 source_event_reference, source_event_hash, request_fingerprint,
                 idempotency_key_hash)
             VALUES (?, ?, "REGZEC25", "employment", ?, "2026-08-31", "2026-09-08",
                     "regular", "vrep_apep", ?, ?, ?, ?, ?, UNHEX(?))',
        )->execute([
            $this->supplierId,
            $environment,
            'employment:' . $this->employmentId,
            $status,
            $sourceEventType,
            $reference,
            str_repeat('a', 64),
            str_repeat('b', 64),
            bin2hex(random_bytes(32)),
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }
}
