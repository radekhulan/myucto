<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\PayrollTestContainer;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Identita osoby platí od jejího prvního vztahu (M8 z průchodu agentury).
 *
 * Osoba založená s dohodou od března měla jméno zapsané od března. Přibyl-li
 * jí potom vztah od února, únorové hlášení JMHZ jméno nemělo.
 */
#[Group('integration')]
final class PayrollIdentityFirstEmploymentStartTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEmploymentRepository $employments;
    private PayrollEmploymentValidator $validator;
    private int $supplierId;
    private int $employeeId;
    private int $userId;

    protected function setUp(): void
    {
        $container = PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->employments = $container->get(PayrollEmploymentRepository::class);
        $this->validator = $container->get(PayrollEmploymentValidator::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare("UPDATE supplier SET payroll_enabled = 1, accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $pdo->prepare("INSERT INTO payroll_offices (supplier_id, code, name, is_active) VALUES (?, 'HLAVNI', 'Hlavní účtárna', 1)")
            ->execute([$this->supplierId]);
        $pdo->prepare('INSERT INTO payroll_employer_settings (supplier_id, default_office_id) VALUES (?, ?)')
            ->execute([$this->supplierId, (int) $pdo->lastInsertId()]);
        $this->employeeId = $this->createEmployee('Syntetická Brigádnice');
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

    public function testEarlierEmploymentExtendsTheFirstIdentityVersion(): void
    {
        $this->insertIdentity($this->employeeId, '2026-03-19', 'Syntetická');
        $this->insertIdentity($this->employeeId, '2026-06-01', 'Provdaná');
        $this->createDpp($this->employeeId, 'DPP-B', '2026-03-19');

        $this->createDpp($this->employeeId, 'DPP-A', '2026-02-07');

        self::assertSame(
            [['2026-02-07', 'Syntetická', 2], ['2026-06-01', 'Provdaná', 1]],
            $this->identityVersions($this->employeeId),
        );
    }

    public function testIdentityThatAlreadyCoversTheStartIsLeftAlone(): void
    {
        $this->insertIdentity($this->employeeId, '2025-01-01', 'Syntetická');
        $this->createDpp($this->employeeId, 'DPP-B', '2026-03-19');

        $this->createDpp($this->employeeId, 'DPP-A', '2026-02-07');

        self::assertSame([['2025-01-01', 'Syntetická', 1]], $this->identityVersions($this->employeeId));
    }

    public function testNoShowEmploymentDoesNotMoveTheIdentity(): void
    {
        $this->insertIdentity($this->employeeId, '2026-03-19', 'Syntetická');
        $this->createDpp($this->employeeId, 'DPP-B', '2026-03-19');
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_employments (supplier_id, employee_id, code, relation_type, status, start_date)
             VALUES (?, ?, 'DPP-X', 'dpp', 'no_show', '2026-01-05')"
        )->execute([$this->supplierId, $this->employeeId]);

        $this->createDpp($this->employeeId, 'DPP-C', '2026-04-01');

        self::assertSame([['2026-03-19', 'Syntetická', 1]], $this->identityVersions($this->employeeId));
    }

    public function testMigrationRepairsExistingGapsOnceAndOnlyTheFirstVersion(): void
    {
        $gap = $this->employeeId;
        $covered = $this->createEmployee('Syntetický Pokrytý');
        $noShowOnly = $this->createEmployee('Syntetický Nenastoupil');
        $this->insertIdentity($gap, '2026-03-19', 'Syntetická');
        $this->insertIdentity($gap, '2026-06-01', 'Provdaná');
        $this->insertIdentity($covered, '2026-01-01', 'Pokrytý');
        $this->insertIdentity($noShowOnly, '2026-05-01', 'Nenastoupil');
        foreach ([
            [$gap, 'G-1', 'active', '2026-03-19', null],
            [$gap, 'G-2', 'ended', '2026-02-10', '2026-02-07'],
            [$covered, 'C-1', 'active', '2026-02-01', null],
            [$noShowOnly, 'N-1', 'no_show', '2026-02-01', null],
            [$noShowOnly, 'N-2', 'active', '2026-05-01', null],
        ] as [$employeeId, $code, $status, $start, $actualStart]) {
            $this->db->pdo()->prepare(
                "INSERT INTO payroll_employments (supplier_id, employee_id, code, relation_type, status, start_date, actual_start_date)
                 VALUES (?, ?, ?, 'dpp', ?, ?, ?)"
            )->execute([$this->supplierId, $employeeId, $code, $status, $start, $actualStart]);
        }

        $sql = (string) file_get_contents(dirname(__DIR__, 4) . '/db/migrations/2290_payroll_identity_from_first_employment.sql');
        $this->db->pdo()->exec($sql);
        $this->db->pdo()->exec($sql);

        self::assertSame([['2026-02-07', 'Syntetická', 2], ['2026-06-01', 'Provdaná', 1]], $this->identityVersions($gap));
        self::assertSame([['2026-01-01', 'Pokrytý', 1]], $this->identityVersions($covered));
        self::assertSame([['2026-05-01', 'Nenastoupil', 1]], $this->identityVersions($noShowOnly));
    }

    private function createEmployee(string $name): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type, tax_declaration_signed,
                 tax_credit_taxpayer, child_count, monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "dpp", 0, 0, 0, NULL, 0, 1)'
        )->execute([$this->supplierId, $name]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertIdentity(int $employeeId, string $from, string $lastName): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name, birth_date, effective_from)
             VALUES (?, ?, ?, "Syntetická", ?, "1999-04-01", ?)'
        )->execute([$this->supplierId, $employeeId, "Syntetická {$lastName}", $lastName, $from]);
        if ($from > '2026-01-01') {
            $this->db->pdo()->prepare(
                'UPDATE payroll_person_identity_history SET effective_to = DATE_SUB(?, INTERVAL 1 DAY)
                  WHERE supplier_id = ? AND employee_id = ? AND effective_from < ? AND effective_to IS NULL'
            )->execute([$from, $this->supplierId, $employeeId, $from]);
        }
    }

    private function createDpp(int $employeeId, string $code, string $start): void
    {
        $this->employments->create(
            $this->supplierId,
            $employeeId,
            $this->validator->create([
                'code' => $code,
                'relation_type' => 'dpp',
                'monthly_gross_minor' => null,
                'terms' => [
                    'office_id' => null,
                    'effective_from' => $start,
                    'contract_signed_on' => $start,
                    'planned_start_on' => $start,
                    'actual_start_on' => null,
                    'fixed_term_end_on' => null,
                    'weekly_hours' => '5',
                    'workload_basis_points' => 1250,
                    'work_place' => 'Hlavní město Praha',
                    'regular_workplace' => 'Praha',
                    'jmhz_workplace_municipality_code' => '554782',
                    'jmhz_workplace_country_code' => 'CZ',
                    'jmhz_apz_contribution_status' => 'no',
                    'jmhz_apz_instrument_code' => null,
                    'jmhz_functional_benefits_status' => 'no',
                    'jmhz_temporary_assignment_status' => 'unverified',
                    'cz_isco_code' => null,
                    'activity_code' => null,
                    'jmhz_relationship_detail_code' => null,
                    'social_insurance_participation' => 'automatic',
                    'health_insurance_participation' => 'automatic',
                    'tax_regime' => 'advance',
                    'foreign_legislation_country_code' => null,
                    'a1_certificate_until' => null,
                    'risky_work' => false,
                    'tax_declaration_signed' => false,
                    'is_primary' => false,
                    'change_reason' => 'Syntetická dohoda',
                ],
            ]),
            $this->userId,
            '127.0.0.1',
            'phpunit',
        );
    }

    /** @return list<array{0:string,1:string,2:int}> */
    private function identityVersions(int $employeeId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT effective_from, last_name, row_version FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ? ORDER BY effective_from'
        );
        $statement->execute([$this->supplierId, $employeeId]);

        return array_map(
            static fn (array $row): array => [(string) $row['effective_from'], (string) $row['last_name'], (int) $row['row_version']],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }
}
