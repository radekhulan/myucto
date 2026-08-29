<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Prohlášení k dani má jeden zdroj: zákonnou evidenci osoby.
 *
 * `payroll_employment_terms.tax_declaration_signed` bývalo druhé, nezávisle
 * editovatelné místo pro tentýž údaj. Když se obě hodnoty rozešly — a rozejít
 * se musely, protože prohlášení se podepisuje kdykoliv v průběhu vztahu, kdežto
 * smluvní podmínky se kvůli podpisu neverzují — mzdový běh spadl blokátorem
 * `tax_declaration_term_conflict`, který nešlo odstranit ničím rozumným.
 * Sloupec je proto odvozeným zrcadlem evidence a hodnota z těla požadavku se
 * ignoruje.
 */
#[Group('integration')]
final class PayrollEmploymentTaxDeclarationMirrorTest extends TestCase
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
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }

        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        foreach (['payroll_employment_terms', 'payroll_person_tax_declarations'] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $this->employments = $container->get(PayrollEmploymentRepository::class);
        $this->validator = $container->get(PayrollEmploymentValidator::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            "UPDATE supplier
                SET payroll_enabled = 1, accounting_mode = 'double_entry'
              WHERE id = ?"
        )->execute([$this->supplierId]);
        $pdo->prepare(
            "INSERT INTO payroll_offices (supplier_id, code, name, is_active)
             VALUES (?, 'HLAVNI', 'Hlavní účtárna', 1)"
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id)
             VALUES (?, ?)'
        )->execute([$this->supplierId, (int) $pdo->lastInsertId()]);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba", "employee", "hpp", 0, 0, 0, NULL, 0, 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
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

    public function testTermsTakeTheDeclarationFromEvidenceAndIgnoreTheRequestBody(): void
    {
        $this->insertDeclaration('signed', '2026-01-01');

        // Tělo tvrdí opak evidence — dřív se uložilo, jak přišlo, a mzdový běh
        // na ten rozpor spadl.
        $employment = $this->createEmployment(declarationInBody: false);

        self::assertTrue($employment['terms'][0]['tax_declaration_signed']);
        self::assertSame('signed', $employment['tax_declaration']['status']);
    }

    public function testNewTermsFollowTheDeclarationValidAtTheirStart(): void
    {
        $this->insertDeclaration('not-signed', '2026-01-01', '2026-05-31');
        $this->insertDeclaration('signed', '2026-06-01');
        $employment = $this->createEmployment(declarationInBody: true);

        self::assertFalse($employment['terms'][0]['tax_declaration_signed']);

        $updated = $this->employments->addTerms(
            $this->supplierId,
            (int) $employment['id'],
            $this->validator->terms($this->termsPayload('2026-07-01'), null, null),
            (int) $employment['row_version'],
            $this->userId,
            '127.0.0.1',
            'phpunit',
        );

        self::assertTrue($updated['terms'][0]['tax_declaration_signed']);
        self::assertFalse($updated['terms'][1]['tax_declaration_signed']);
    }

    public function testMissingEvidenceMeansNotSigned(): void
    {
        $employment = $this->createEmployment(declarationInBody: true);

        self::assertFalse($employment['terms'][0]['tax_declaration_signed']);
        self::assertNull($employment['tax_declaration']);
    }

    /** @return array<string,mixed> */
    private function createEmployment(bool $declarationInBody): array
    {
        return $this->employments->create(
            $this->supplierId,
            $this->employeeId,
            $this->validator->create([
                'code' => 'SYN-HPP',
                'relation_type' => 'employment',
                'monthly_gross_minor' => 4_200_000,
                'terms' => $this->termsPayload('2026-01-01', $declarationInBody),
            ]),
            $this->userId,
            '127.0.0.1',
            'phpunit',
        );
    }

    private function insertDeclaration(
        string $status,
        string $from,
        ?string $to = null,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, effective_from, effective_to)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $this->employeeId, $status, $from, $to]);
    }

    /** @return array<string,mixed> */
    private function termsPayload(string $effectiveFrom, bool $declarationInBody = true): array
    {
        return [
            'office_id' => null,
            'effective_from' => $effectiveFrom,
            'contract_signed_on' => '2025-12-15',
            'planned_start_on' => '2026-01-01',
            'actual_start_on' => null,
            'fixed_term_end_on' => null,
            'weekly_hours' => '40',
            'workload_basis_points' => 10000,
            'work_place' => 'Hlavní město Praha',
            'regular_workplace' => 'Praha',
            'jmhz_workplace_municipality_code' => '554782',
            'jmhz_workplace_country_code' => 'CZ',
            'jmhz_apz_contribution_status' => 'no',
            'jmhz_apz_instrument_code' => null,
            'jmhz_functional_benefits_status' => 'no',
            'jmhz_temporary_assignment_status' => 'unverified',
            'cz_isco_code' => null,
            'activity_code' => '1',
            'jmhz_relationship_detail_code' => '1',
            'social_insurance_participation' => 'automatic',
            'health_insurance_participation' => 'automatic',
            'tax_regime' => 'advance',
            'foreign_legislation_country_code' => null,
            'a1_certificate_until' => null,
            'risky_work' => false,
            'tax_declaration_signed' => $declarationInBody,
            'is_primary' => true,
            'change_reason' => 'Syntetický vztah',
        ];
    }
}
