<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDeadlineOverviewRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Podklady hlídače termínů pro roční zúčtování a povolení cizinců.
 *
 * Unit test služby drží lhůty; tady se ověřuje, že dotazy vyberou právě ty
 * lidi, u kterých povinnost trvá — a nikoho, u koho už zanikla (přiznání
 * místo zúčtování, povolení s nástupcem, skončený vztah).
 */
#[Group('integration')]
final class PayrollDeadlineAnnualAndPermitSourcesTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2025;

    private Connection $db;
    private PayrollDeadlineOverviewRepository $repository;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->repository = $container->get(PayrollDeadlineOverviewRepository::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        isset($this->db) && $this->db->close();
    }

    public function testSettlementsToPerformSkipThoseWhoMustFileAReturn(): void
    {
        $requested = $this->employee('Syntetický Žadatel');
        $mustFile = $this->employee('Syntetický Přiznání');
        $notRequested = $this->employee('Syntetický Bez žádosti');
        $this->settlementRequest($requested, 'requested', 'none');
        $this->settlementRequest($mustFile, 'requested', 'required');
        $this->settlementRequest($notRequested, 'not_requested', 'unknown');

        $rows = $this->repository->annualSettlementsToPerform($this->supplierId, [self::YEAR]);

        self::assertSame([$requested], array_column($rows, 'employee_id'));
        self::assertSame(self::YEAR, $rows[0]['tax_year']);
    }

    public function testUndecidedCountsOnlyPeopleWithIncomeAndNoDecision(): void
    {
        $revisionId = $this->approvedRevision(self::YEAR . '-06-01');
        $undecided = $this->employee('Syntetický Nerozhodnutý');
        $unknown = $this->employee('Syntetický Nevíme');
        $decided = $this->employee('Syntetický Rozhodnutý');
        $withoutIncome = $this->employee('Syntetický Bez příjmu');
        foreach ([$undecided, $unknown, $decided] as $employeeId) {
            $this->netResult($revisionId, $employeeId);
        }
        $this->settlementRequest($unknown, 'unknown', 'unknown');
        $this->settlementRequest($decided, 'not_requested', 'unknown');
        $this->settlementRequest($withoutIncome, 'unknown', 'unknown');

        self::assertSame(
            [self::YEAR => 2],
            $this->repository->annualSettlementUndecidedCounts($this->supplierId, [self::YEAR]),
        );
    }

    public function testPermitExpiryIgnoresRenewedPermitsAndEndedEmployments(): void
    {
        $expiring = $this->employee('Syntetický Cizinec');
        $renewed = $this->employee('Syntetický Prodloužený');
        $ended = $this->employee('Syntetický Odešlý');
        $this->employment($expiring, 'active', null);
        $this->employment($renewed, 'active', null);
        $this->employment($ended, 'ended', '2026-02-28');

        $expiringPermit = $this->permit($expiring, 'work', '2025-04-01', '2026-03-31');
        $this->permit($renewed, 'residence', '2025-04-01', '2026-03-31');
        $this->permit($renewed, 'residence', '2026-04-01', '2027-03-31');
        $this->permit($ended, 'work', '2025-04-01', '2026-03-31');

        $rows = $this->repository->foreignPermitExpiries($this->supplierId, '2026-01-01', '2026-06-30');

        self::assertSame([$expiringPermit], array_column($rows, 'permit_id'));
        self::assertSame('work', $rows[0]['permit_kind']);
        self::assertSame('2026-03-31', $rows[0]['valid_until']);
    }

    private function employee(string $name): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)',
        )->execute([$this->supplierId, $name]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function employment(int $employeeId, string $status, ?string $endDate): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, is_primary,
                 start_date, end_date)
             VALUES (?, ?, ?, "employment", ?, 1, "2025-04-01", ?)',
        )->execute([$this->supplierId, $employeeId, 'SYN-' . $employeeId, $status, $endDate]);
    }

    private function settlementRequest(int $employeeId, string $status, string $filing): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_annual_settlement_requests
                (supplier_id, employee_id, tax_year, request_status, requested_on,
                 request_evidence_reference, filing_obligation, filing_obligation_reason)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $employeeId,
            self::YEAR,
            $status,
            $status === 'requested' ? (self::YEAR + 1) . '-02-10' : null,
            $status === 'requested' ? 'SYN-ZADOST' : null,
            $filing,
            $filing === 'required' ? 'Syntetický důvod přiznání' : null,
        ]);
    }

    private function approvedRevision(string $periodStart): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, ?, "approved", 1)',
        )->execute([$this->supplierId, $periodStart, substr($periodStart, 0, 8) . '20']);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "regular", "approved", "v1", ?, "{}", ?, "{}", ?, UNHEX(?))',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('1', 64),
            hash('sha256', 'deadline-annual-revision-' . $this->supplierId . '-' . $periodStart),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Výsledek osoby tam, kam ho zapisuje výpočet. Dřív se plnila
     * `payroll_net_results`, do které pipeline nikdy nezapisuje, takže test
     * svítil zeleně, zatímco v provozu hlídač vždy hlásil nulu.
     */
    private function netResult(int $revisionId, int $employeeId): void
    {
        $json = '{"synthetic":' . $employeeId . '}';
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json, result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")',
        )->execute([$this->supplierId, $revisionId, $employeeId, $json, hash('sha256', $json)]);
    }

    private function permit(int $employeeId, string $kind, string $from, string $until): int
    {
        $pdo = $this->db->pdo();
        $sha = hash('sha256', "deadline-permit-{$this->supplierId}-{$employeeId}-{$kind}-{$from}");
        $pdo->prepare(
            'INSERT INTO documents
                (supplier_id, title, original_name, filename, sha256, mime_type,
                 size_bytes, doc_type, uploaded_by, scope)
             VALUES (?, "Syntetický podklad oprávnění", "permit.pdf", "permit.pdf", ?,
                     "application/pdf", 128, "pdf", ?, "company")',
        )->execute([$this->supplierId, $sha, $this->userId]);
        $documentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_person_foreign_permits
                (supplier_id, employee_id, permit_kind, permit_label, issuing_country_code,
                 effective_from, valid_until, document_supplier_id, document_id,
                 document_sha256, recorded_by)
             VALUES (?, ?, ?, "Syntetický doklad", "CZ", ?, ?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $employeeId,
            $kind,
            $from,
            $until,
            $this->supplierId,
            $documentId,
            $sha,
            $this->userId,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
