<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\PayrollHealthInsurerWriter;
use MyInvoice\Service\Payroll\PayrollStatutoryEvidenceBulkDefaults;
use MyInvoice\Tests\Support\PayrollE2eRegistrationTrait;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hromadné zadání zdravotní pojišťovny nad hranicemi vět pojištění:
 * před první větou, za skončenou poslední větou (další dohoda brigádníka)
 * a mezera mezi větami.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eRegHealthInsurerTest extends TestCase
{
    use PayrollFullFlowTrait;
    use PayrollE2eRegistrationTrait;

    private PayrollHealthInsurerWriter $writer;

    protected function setUp(): void
    {
        $this->bootRegistrationFlow('2026-11-11');
        $writer = $this->container->get(PayrollHealthInsurerWriter::class);
        self::assertInstanceOf(PayrollHealthInsurerWriter::class, $writer);
        $this->writer = $writer;
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Brigádník: pojištění vedené jen za lednovou dohodu (do 31. 1.), další
     * dohoda od března. Hromadné zadání za březen naváže větou bez konce,
     * lednová věta zůstane beze změny (stejně jako hromadné zákonné údaje).
     */
    public function testAssignmentAfterEndedLastRowAppendsOpenRow(): void
    {
        [$employeeId] = $this->regPerson('Pojištění', 'Navazuje', 71, 'dpp', '2026-01-05');
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_health_coverage_history SET effective_to = "2026-01-31"
              WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $employeeId]);

        $this->writer->assign($this->supplierId, $employeeId, '211', '2026-03-01', $this->actors[0], null, null);

        // Řada pojištění musí navazovat den po dni, nová věta proto začíná
        // hned po skončené (únor osoba bez vztahu nemá, ZP za něj nic nehlásí).
        self::assertSame(
            [['2026-01-01', '2026-01-31', '111'], ['2026-02-01', null, '211']],
            $this->rows($employeeId),
        );
    }

    /**
     * Totéž pro hromadné výchozí zákonné údaje: rezidence a příslušnost
     * skončené s lednovou dohodou, další dohoda od března (únor mezi nimi).
     */
    public function testBulkStatutoryDefaultsAfterGapMonthStayContiguous(): void
    {
        [$employeeId] = $this->regPerson('Výchozí', 'Mezera', 77, 'dpp', '2026-03-02');
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM payroll_person_tax_residences WHERE supplier_id = ? AND employee_id = ?')
            ->execute([$this->supplierId, $employeeId]);
        $pdo->prepare('DELETE FROM payroll_person_social_jurisdictions WHERE supplier_id = ? AND employee_id = ?')
            ->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_residences
                (supplier_id, employee_id, residence, country_code, effective_from, effective_to)
             VALUES (?, ?, "czech-resident", "CZ", "2026-01-01", "2026-01-31")'
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_social_jurisdictions
                (supplier_id, employee_id, jurisdiction, a1_status, effective_from, effective_to)
             VALUES (?, ?, "czech_regime_verified", "not_applicable", "2026-01-01", "2026-01-31")'
        )->execute([$this->supplierId, $employeeId]);
        $bulk = $this->container->get(PayrollStatutoryEvidenceBulkDefaults::class);
        self::assertInstanceOf(PayrollStatutoryEvidenceBulkDefaults::class, $bulk);

        $result = $bulk->apply(
            $this->supplierId,
            '2026-03-31',
            [$employeeId],
            ['tax_residences', 'social_jurisdictions'],
            false,
            $this->actors[0],
            null,
            null,
        );

        self::assertSame(1, $result['counts']['applied'], json_encode($result, JSON_UNESCAPED_UNICODE));
        foreach (['payroll_person_tax_residences', 'payroll_person_social_jurisdictions'] as $table) {
            $statement = $pdo->prepare(
                "SELECT effective_from, effective_to FROM {$table} WHERE supplier_id = ? AND employee_id = ? ORDER BY effective_from",
            );
            $statement->execute([$this->supplierId, $employeeId]);
            self::assertSame(
                [['2026-01-01', '2026-01-31'], ['2026-02-01', null]],
                $statement->fetchAll(\PDO::FETCH_NUM),
                $table,
            );
        }
    }

    /** Před první větou se doplní ohraničená věta, existující řada se nemění. */
    public function testAssignmentBeforeFirstRowPrependsBoundedRow(): void
    {
        [$employeeId] = $this->regPerson('Pojištění', 'Předchází', 73, 'dpp', '2026-01-05');
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_health_coverage_history SET effective_from = "2026-03-01"
              WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $employeeId]);

        $this->writer->assign($this->supplierId, $employeeId, '111', '2026-01-01', $this->actors[0], null, null);

        self::assertSame(
            [['2026-01-01', '2026-02-28', '111'], ['2026-03-01', null, '111']],
            $this->rows($employeeId),
        );
    }

    /** Mezera mezi dvěma větami zůstává na člověku: srozumitelná chyba, nic se nezapíše. */
    public function testGapBetweenRowsIsRefusedWithGuidance(): void
    {
        [$employeeId] = $this->regPerson('Pojištění', 'Mezera', 75, 'dpp', '2026-01-05');
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_health_coverage_history SET effective_to = "2026-01-31"
              WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $employeeId]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction, insurer_status,
                 insurer_code, insurer_evidence_reference, effective_from)
             VALUES (?, ?, "czech_regime_verified", "verified", "111", "karta-pojistence-2", "2026-05-01")',
        )->execute([$this->supplierId, $employeeId]);

        try {
            $this->writer->assign($this->supplierId, $employeeId, '211', '2026-03-01', $this->actors[0], null, null);
            self::fail('Mezera mezi větami se doplnila bez člověka.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('ručně', $exception->getMessage());
        }
        self::assertCount(2, $this->rows($employeeId));
    }

    /** @return list<array{0:string,1:?string,2:?string}> */
    private function rows(int $employeeId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT effective_from, effective_to, insurer_code FROM payroll_person_health_coverage_history
              WHERE supplier_id = ? AND employee_id = ? ORDER BY effective_from',
        );
        $statement->execute([$this->supplierId, $employeeId]);

        return array_map(
            static fn (array $row): array => [(string) $row[0], $row[1] === null ? null : (string) $row[1], $row[2] === null ? null : (string) $row[2]],
            $statement->fetchAll(\PDO::FETCH_NUM),
        );
    }
}
