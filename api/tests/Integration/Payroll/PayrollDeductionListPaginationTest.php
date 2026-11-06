<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDeductionAgreementRepository;
use MyInvoice\Repository\Payroll\PayrollEnforcementRepository;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseStatus;
use MyInvoice\Service\Payroll\Net\DeductionAgreementStatus;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `GET /payroll/enforcement/cases` a `GET /payroll/deduction-agreements` nesmí
 * načíst celou historii firmy.
 *
 * ── Co se opravovalo ────────────────────────────────────────────────────────────
 * Oba seznamy měly oba filtry (zaměstnanec, stav) volitelné, takže volání bez
 * parametrů přečetlo VŠECHNY případy i dohody, které firma kdy vedla. Objem roste
 * s počtem zaměstnanců krát doba provozu — u firmy se stovkou lidí a pár lety
 * provozu to není pomalý dotaz, ale rostoucí zátěž bez jakéhokoli stropu.
 *
 * ── Co tenhle test hlídá ────────────────────────────────────────────────────────
 *  1. strop stránky je tvrdý a nejde ho zvednout parametrem z požadavku;
 *  2. `total` počítá VŠECHNY odpovídající řádky, ne velikost stránky;
 *  3. `offset` seznam skutečně posune a stránky se nepřekrývají;
 *  4. filtry na zaměstnance i stav zúží shodně stránku i `total`.
 */
#[Group('integration')]
final class PayrollDeductionListPaginationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEnforcementRepository $cases;
    private PayrollDeductionAgreementRepository $agreements;
    private int $supplierId;
    private int $employeeA;
    private int $employeeB;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->cases = $container->get(PayrollEnforcementRepository::class);
        $this->agreements = $container->get(PayrollDeductionAgreementRepository::class);
        foreach ([
            'payroll_enforcement_cases',
            'payroll_deduction_agreements',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Tabulka {$table} v testovací databázi chybí.");
            }
        }

        $pdo = $this->db->pdo();
        $stmt = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        $sourceSupplierId = $stmt === false ? 0 : (int) $stmt->fetchColumn();
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employeeA = $this->createEmployee('Synteticka Osoba A');
        $this->employeeB = $this->createEmployee('Synteticka Osoba B');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    /** Strop stránky případů je tvrdý — nesmí ho zvednout parametr z požadavku. */
    public function testEnforcementCaseCapCannotBeLiftedByAParameter(): void
    {
        $seeded = PayrollEnforcementRepository::LIST_MAX_LIMIT + 3;
        for ($i = 0; $i < $seeded; $i++) {
            $this->seedCase($this->employeeA, 'received');
        }

        $page = $this->cases->listCases($this->supplierId, null, null, 10_000, 0);

        self::assertCount(
            PayrollEnforcementRepository::LIST_MAX_LIMIT,
            $page['items'],
            'Strop nejde obejít vyšším limitem.',
        );
        self::assertSame(
            $seeded,
            $page['total'],
            'Total je počet VŠECH případů, ne velikost stránky.',
        );
    }

    /** Offset musí seznam případů skutečně posunout, ne vracet tytéž řádky. */
    public function testEnforcementCaseOffsetShiftsThePage(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seedCase($this->employeeA, 'received');
        }

        $first = $this->cases->listCases($this->supplierId, null, null, 2, 0);
        $second = $this->cases->listCases($this->supplierId, null, null, 2, 2);

        self::assertCount(2, $first['items']);
        self::assertCount(2, $second['items']);
        self::assertSame(5, $first['total']);
        self::assertSame(5, $second['total'], 'Total se posunem stránky nemění.');
        self::assertSame(
            [],
            array_intersect($this->ids($first['items']), $this->ids($second['items'])),
            'Stránky se nesmí překrývat.',
        );
    }

    /** Filtry musí zúžit stránku i `total` shodně, jinak stránkování lže. */
    public function testEnforcementCaseFiltersNarrowBothPageAndTotal(): void
    {
        $this->seedCase($this->employeeA, 'received');
        $this->seedCase($this->employeeA, 'received');
        $this->seedCase($this->employeeA, 'stopped');
        $this->seedCase($this->employeeB, 'received');

        $byEmployee = $this->cases->listCases($this->supplierId, $this->employeeA);
        self::assertSame(3, $byEmployee['total']);
        self::assertCount(3, $byEmployee['items']);
        foreach ($byEmployee['items'] as $case) {
            self::assertSame($this->employeeA, $case['employee_id']);
        }

        $byStatus = $this->cases->listCases(
            $this->supplierId,
            null,
            EnforcementCaseStatus::Stopped,
        );
        self::assertSame(1, $byStatus['total']);
        self::assertCount(1, $byStatus['items']);
        self::assertSame('stopped', $byStatus['items'][0]['status']);

        $both = $this->cases->listCases(
            $this->supplierId,
            $this->employeeB,
            EnforcementCaseStatus::Stopped,
        );
        self::assertSame(0, $both['total']);
        self::assertSame([], $both['items']);
    }

    /** Strop stránky dohod je tvrdý — nesmí ho zvednout parametr z požadavku. */
    public function testDeductionAgreementCapCannotBeLiftedByAParameter(): void
    {
        $seeded = PayrollDeductionAgreementRepository::LIST_MAX_LIMIT + 3;
        for ($i = 0; $i < $seeded; $i++) {
            $this->seedAgreement($this->employeeA, 'active');
        }

        $page = $this->agreements->listAgreements($this->supplierId, null, null, 10_000, 0);

        self::assertCount(
            PayrollDeductionAgreementRepository::LIST_MAX_LIMIT,
            $page['items'],
            'Strop nejde obejít vyšším limitem.',
        );
        self::assertSame(
            $seeded,
            $page['total'],
            'Total je počet VŠECH dohod, ne velikost stránky.',
        );
    }

    /** Offset musí seznam dohod skutečně posunout, ne vracet tytéž řádky. */
    public function testDeductionAgreementOffsetShiftsThePage(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seedAgreement($this->employeeA, 'active');
        }

        $first = $this->agreements->listAgreements($this->supplierId, null, null, 2, 0);
        $second = $this->agreements->listAgreements($this->supplierId, null, null, 2, 2);

        self::assertCount(2, $first['items']);
        self::assertCount(2, $second['items']);
        self::assertSame(5, $first['total']);
        self::assertSame(5, $second['total'], 'Total se posunem stránky nemění.');
        self::assertSame(
            [],
            array_intersect($this->ids($first['items']), $this->ids($second['items'])),
            'Stránky se nesmí překrývat.',
        );
    }

    /** Filtry musí zúžit stránku i `total` shodně, jinak stránkování lže. */
    public function testDeductionAgreementFiltersNarrowBothPageAndTotal(): void
    {
        $this->seedAgreement($this->employeeA, 'active');
        $this->seedAgreement($this->employeeA, 'active');
        $this->seedAgreement($this->employeeA, 'paused');
        $this->seedAgreement($this->employeeB, 'active');

        $byEmployee = $this->agreements->listAgreements($this->supplierId, $this->employeeA);
        self::assertSame(3, $byEmployee['total']);
        self::assertCount(3, $byEmployee['items']);
        foreach ($byEmployee['items'] as $agreement) {
            self::assertSame($this->employeeA, $agreement['employee_id']);
        }

        $byStatus = $this->agreements->listAgreements(
            $this->supplierId,
            null,
            DeductionAgreementStatus::Paused,
        );
        self::assertSame(1, $byStatus['total']);
        self::assertCount(1, $byStatus['items']);
        self::assertSame('paused', $byStatus['items'][0]['status']);

        $both = $this->agreements->listAgreements(
            $this->supplierId,
            $this->employeeB,
            DeductionAgreementStatus::Paused,
        );
        self::assertSame(0, $both['total']);
        self::assertSame([], $both['items']);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    private function createEmployee(string $fullName): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 30000, 0, 1)'
        )->execute([$this->supplierId, $fullName]);

        return (int) $pdo->lastInsertId();
    }

    private function seedCase(int $employeeId, string $status): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_enforcement_cases
                (supplier_id, employee_id, case_key, case_kind, status, effective_from)
             VALUES (?, ?, ?, "enforcement", ?, "2030-01-01")'
        )->execute([
            $this->supplierId,
            $employeeId,
            'case_' . bin2hex(random_bytes(16)),
            $status,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function seedAgreement(int $employeeId, string $status): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_deduction_agreements
                (supplier_id, employee_id, agreement_reference, title,
                 deduction_kind, status, priority_no, requested_minor, valid_from)
             VALUES (?, ?, ?, "Stravenky", "meal", ?, 100, 50000, "2030-01-01")'
        )->execute([
            $this->supplierId,
            $employeeId,
            'ref_' . bin2hex(random_bytes(16)),
            $status,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
