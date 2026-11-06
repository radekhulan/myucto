<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\Import\OpeningBalance\OpeningBalanceTabularImportService;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Obecný import počátečních stavů z tabulky (PAM-08).
 *
 * Dosud vedla dovnitř jediná cesta — hlášení JMHZ. Zákazník, jehož předchozí
 * software JMHZ nevydá, musel vyplnit třináct polí krát sedm měsíců krát
 * každý zaměstnanec ručně.
 */
#[Group('integration')]
final class PayrollOpeningBalanceTabularImportTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const CODE = 'HPP-001';

    private Connection $db;
    private OpeningBalanceTabularImportService $imports;
    private PayrollStatutoryAccumulatorRepository $accumulators;
    private int $supplierId;
    private int $employeeId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build()
            ?? throw new \RuntimeException('DI kontejner není dostupný.');
        $db = $container->get(Connection::class);
        $imports = $container->get(OpeningBalanceTabularImportService::class);
        $accumulators = $container->get(PayrollStatutoryAccumulatorRepository::class);
        if (!$db instanceof Connection
            || !$imports instanceof OpeningBalanceTabularImportService
            || !$accumulators instanceof PayrollStatutoryAccumulatorRepository
        ) {
            throw new \RuntimeException('Import počátečních stavů není dostupný.');
        }
        $this->db = $db;
        $this->imports = $imports;
        $this->accumulators = $accumulators;
        $pdo = $db->pdo();
        $source = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        $sourceSupplierId = $source === false ? 0 : (int) $source->fetchColumn();
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employeeId = $this->createEmployee($pdo);
        $this->createEmployment($pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    /** Sada sloupců vzoru se bere z kumulací, ne z vlastního seznamu. */
    public function testTemplateCoversEveryAccumulatorField(): void
    {
        $header = explode(';', explode("\r\n", OpeningBalanceTabularImportService::template())[0]);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];

        foreach (PayrollOpeningBalanceService::monthFields() as $field) {
            self::assertContains($field, $header, "Vzorový soubor nemá sloupec {$field}.");
        }
        foreach (['employee_id', 'employment_code', 'year', 'month'] as $key) {
            self::assertContains($key, $header);
        }
    }

    public function testCsvFillsAllThreeAccumulatorKinds(): void
    {
        $result = $this->imports->apply(
            $this->supplierId,
            'csv',
            'prevod.csv',
            $this->csv([$this->row(1), $this->row(2)]),
            null,
        );

        self::assertSame(1, $result['saved']);
        self::assertSame([], $result['skipped']);

        $social = $this->opening('social_insurance');
        $health = $this->opening('health_insurance');
        $tax = $this->opening('income_tax');
        self::assertSame(8_000_000, $social['values']['assessment_base_minor_units']);
        self::assertSame(8_000_000, $health['values']['assessment_base_minor_units']);
        self::assertSame(360_000, $health['values']['employee_contribution_minor_units']);
        self::assertSame(720_000, $health['values']['employer_contribution_minor_units']);
        self::assertSame(432_600, $tax['values']['advance_tax_minor_units']);
        self::assertSame(2, $tax['values']['completed_months']);
    }

    /** Vadný řádek shodí náhled i zápis — nic se nezapíše. */
    public function testInvalidRowIsRejectedInPreviewAndNothingIsApplied(): void
    {
        $bad = $this->row(1);
        // Srážková daň bez základu srážkové daně vzniknout nemůže.
        $bad['withholding_tax_minor_units'] = 70_000;
        $csv = $this->csv([$bad]);

        $preview = $this->imports->preview($this->supplierId, 'csv', 'prevod.csv', $csv);
        self::assertCount(1, $preview['errors']);
        self::assertStringContainsString(
            'Základ srážkové daně',
            $preview['errors'][0]['error_message'],
        );
        self::assertSame([], $preview['people']);

        try {
            $this->imports->apply($this->supplierId, 'csv', 'prevod.csv', $csv, null);
            self::fail('Vadný soubor se nesmí zapsat.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertNull($this->opening('social_insurance'));
    }

    /** Opakovaný import týchž čísel nesmí založit druhou verzi. */
    public function testRepeatedImportIsIdempotent(): void
    {
        $csv = $this->csv([$this->row(1), $this->row(2)]);
        $this->imports->apply($this->supplierId, 'csv', 'prevod.csv', $csv, null);
        $firstId = $this->opening('social_insurance')['id'];

        $again = $this->imports->apply($this->supplierId, 'csv', 'prevod.csv', $csv, null);

        self::assertSame(0, $again['saved']);
        self::assertSame(1, $again['unchanged']);
        self::assertSame($firstId, $this->opening('social_insurance')['id']);
        self::assertCount(
            1,
            $this->accumulators->openingVersions($this->supplierId, $this->employeeId, 2026, 'social_insurance'),
        );
    }

    /** Označení vztahu je párovací pojistka: samotné id se dá přepsat omylem. */
    public function testEmploymentCodeMustMatchTheEmployee(): void
    {
        $row = $this->row(1);
        $row['employment_code'] = 'JINY-KOD';

        $preview = $this->imports->preview($this->supplierId, 'csv', 'prevod.csv', $this->csv([$row]));

        self::assertCount(1, $preview['errors']);
        self::assertStringContainsString('neodpovídá zaměstnanci', $preview['errors'][0]['error_message']);
    }

    /** @return array<string,mixed>|null */
    private function opening(string $kind): ?array
    {
        return $this->accumulators->openingBalance($this->supplierId, $this->employeeId, 2026, $kind);
    }

    /** @return array<string,string|int> */
    private function row(int $month): array
    {
        return [
            'employee_id' => $this->employeeId,
            'employment_code' => self::CODE,
            'year' => 2026,
            'month' => $month,
            'social_assessment_base_minor_units' => 4_000_000,
            'health_assessment_base_minor_units' => 4_000_000,
            'health_employee_contribution_minor_units' => 180_000,
            'health_employer_contribution_minor_units' => 360_000,
            'health_minimum_top_up_minor_units' => 0,
            'advance_base_minor_units' => 4_000_000,
            'advance_tax_minor_units' => 216_300,
            'withholding_base_minor_units' => 0,
            'withholding_tax_minor_units' => 0,
            'applied_non_refundable_credits_minor_units' => 257_000,
            'applied_child_credit_minor_units' => 0,
            'tax_bonus_minor_units' => 0,
            'bonus_qualifying_income_minor_units' => 4_000_000,
        ];
    }

    /** @param list<array<string,string|int>> $rows */
    private function csv(array $rows): string
    {
        $columns = OpeningBalanceTabularImportService::columns();
        $lines = [implode(';', $columns)];
        foreach ($rows as $row) {
            $lines[] = implode(';', array_map(
                static fn (string $column): string => (string) ($row[$column] ?? '0'),
                $columns,
            ));
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    private function createEmployee(PDO $pdo): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)'
        );
        $stmt->execute([$this->supplierId, 'Převzatá osoba']);

        return (int) $pdo->lastInsertId();
    }

    private function createEmployment(PDO $pdo): void
    {
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01", "2026-01-01", 4000000, 0, 1)'
        )->execute([$this->supplierId, $this->employeeId, self::CODE]);
    }
}
