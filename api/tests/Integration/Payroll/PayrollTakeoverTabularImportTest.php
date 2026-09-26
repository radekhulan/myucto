<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Import\Takeover\TakeoverTabularImportService;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverReader;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverYear;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Obecný import převzatých mezd z tabulky (PAM-09).
 *
 * Převzaté mzdy dosud uměl naplnit jen převod z PAMICA. Zákazník přicházející
 * z jiného mzdového programu tím pádem neměl jak dodat podklad pro evidenční
 * list důchodového pojištění za rok přechodu ani pro zpětnou evidenci plateb.
 */
#[Group('integration')]
final class PayrollTakeoverTabularImportTest extends TestCase
{
    private const CODE = 'HPP-001';
    private const YEAR = 2026;

    private Connection $db;
    private TakeoverTabularImportService $imports;
    private PayrollTakeoverReader $reader;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;

    use IsolatedSupplierTrait;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer()
            ?? throw new \RuntimeException('DI kontejner není dostupný.');
        $db = $container->get(Connection::class);
        $imports = $container->get(TakeoverTabularImportService::class);
        $reader = $container->get(PayrollTakeoverReader::class);
        if (!$db instanceof Connection
            || !$imports instanceof TakeoverTabularImportService
            || !$reader instanceof PayrollTakeoverReader
        ) {
            throw new \RuntimeException('Import převzatých mezd není dostupný.');
        }
        $this->db = $db;
        $this->imports = $imports;
        $this->reader = $reader;
        $pdo = $db->pdo();
        $source = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        $sourceSupplierId = $source === false ? 0 : (int) $source->fetchColumn();
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employeeId = $this->createEmployee($pdo);
        $this->employmentId = $this->createEmployment($pdo);
        $this->activatePayrollFrom($pdo, sprintf('%04d-08-01', self::YEAR));
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

    /** Vzor musí nést všechny povinné sloupce a vejít se do stropu parseru. */
    public function testTemplateCoversEveryRequiredColumn(): void
    {
        $header = explode(';', explode("\r\n", TakeoverTabularImportService::template())[0]);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];

        foreach (TakeoverTabularImportService::requiredColumns() as $column) {
            self::assertContains($column, $header, "Vzorový soubor nemá sloupec {$column}.");
        }
        self::assertLessThanOrEqual(24, count($header), 'Parser přijímá nejvýš 24 sloupců.');
    }

    /** Import naplní i nové sloupce — doby, srážky a čistou mzdu k výplatě. */
    public function testImportFillsDurationAndPaymentColumns(): void
    {
        $result = $this->imports->apply(
            $this->supplierId,
            'other',
            'csv',
            'prevzate-mzdy.csv',
            $this->csv([$this->row('2026-06'), $this->row('2026-07')]),
        );

        self::assertSame(2, $result['written']);
        self::assertSame(['2026-06', '2026-07'], $result['periods']);

        $stored = $this->storedRow('2026-06');
        self::assertSame(31, (int) $stored['insurance_days']);
        self::assertSame(4, (int) $stored['excluded_days']);
        self::assertSame(2150, (int) $stored['worked_days_hundredths'], '21,5 dne = 2150 setin dne.');
        self::assertSame(10_095, (int) $stored['worked_minutes'], '168,25 hodiny = 10 095 minut.');
        self::assertSame(120_000, (int) $stored['deductions_minor']);
        self::assertSame(3_080_000, (int) $stored['net_payable_minor']);
        self::assertSame('2026-07-10', (string) $stored['payout_date']);
        self::assertSame(1, (int) $stored['pension_participation']);
        self::assertSame('1', (string) $stored['activity_code']);
        // Trvání a druh vztahu se berou z evidence, ne ze souboru.
        self::assertSame('employment', (string) $stored['relation_type']);
        self::assertSame('2026-01-01', (string) $stored['relationship_start_date']);
        self::assertSame($this->employmentId, (int) $stored['employment_id']);
        self::assertSame('other', (string) $stored['source']);
    }

    /** Stereo NX smí označovat jen řádky ověřené čtečkou zálohy. */
    public function testManualImportCannotClaimStereoNxSource(): void
    {
        $csv = $this->csv([$this->row('2026-06')]);
        foreach (['preview', 'apply'] as $operation) {
            try {
                $this->imports->{$operation}($this->supplierId, 'stereo_nx', 'csv', 'prevzate.csv', $csv);
                self::fail("Ruční {$operation} nesmí vydávat mzdu za import Stereo NX.");
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Zdroj Stereo NX lze převzít jen ze zálohy Stereo NX.', $e->getMessage());
            }
        }
        self::assertSame(0, $this->storedCount());
    }

    /** Vadný řádek shodí náhled i zápis — do databáze se nedostane nic. */
    public function testInvalidRowIsRejectedAndNothingIsApplied(): void
    {
        $bad = $this->row('2026-06');
        // Dny účasti nad délku kalendářního měsíce jsou vždycky chyba přepisu.
        $bad['insurance_days'] = '45';
        $csv = $this->csv([$this->row('2026-05'), $bad]);

        $preview = $this->imports->preview($this->supplierId, 'other', 'csv', 'prevzate.csv', $csv);
        self::assertCount(1, $preview['errors']);
        self::assertStringContainsString('0 až 31', $preview['errors'][0]['error_message']);

        try {
            $this->imports->apply($this->supplierId, 'other', 'csv', 'prevzate.csv', $csv);
            self::fail('Vadný soubor se nesmí zapsat.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, $this->storedCount(), 'Vadný soubor nesmí zapsat ani platné řádky.');
    }

    /** Označení vztahu je párovací pojistka proti přepsanému id. */
    public function testEmploymentCodeMustMatchTheEmployee(): void
    {
        $row = $this->row('2026-06');
        $row['employment_code'] = 'JINY-KOD';

        $preview = $this->imports->preview($this->supplierId, 'other', 'csv', 'prevzate.csv', $this->csv([$row]));

        self::assertCount(1, $preview['errors']);
        self::assertStringContainsString('neodpovídá zaměstnanci', $preview['errors'][0]['error_message']);
    }

    /** Opakovaný import týchž dat řádky nezdvojí — přepíše je. */
    public function testRepeatedImportDoesNotDuplicate(): void
    {
        $csv = $this->csv([$this->row('2026-06'), $this->row('2026-07')]);
        $this->imports->apply($this->supplierId, 'other', 'csv', 'prevzate.csv', $csv);
        self::assertSame(2, $this->storedCount());

        $changed = $this->row('2026-06');
        $changed['gross_minor'] = '4100000';
        $this->imports->apply(
            $this->supplierId,
            'other',
            'csv',
            'prevzate.csv',
            $this->csv([$changed, $this->row('2026-07')]),
        );

        self::assertSame(2, $this->storedCount());
        self::assertSame(4_100_000, (int) $this->storedRow('2026-06')['gross_minor']);
    }

    /** Čtecí rozhraní odliší převzatý měsíc od spočítaného a nahlásí chybějící. */
    public function testReaderSeparatesTakeoverFromCalculatedAndReportsGaps(): void
    {
        $this->imports->apply(
            $this->supplierId,
            'other',
            'csv',
            'prevzate.csv',
            $this->csv([$this->row('2026-01'), $this->row('2026-03')]),
        );

        $year = $this->reader->forEmployee($this->supplierId, $this->employeeId, self::YEAR);

        self::assertSame('2026-08', $year->payrollStartPeriod);
        self::assertSame(['2026-01', '2026-03'], $year->takeoverPeriods());
        self::assertTrue($year->isHistorical('2026-03'));
        self::assertFalse($year->isHistorical('2026-08'));
        self::assertSame(PayrollTakeoverYear::PRESENCE_TAKEOVER_ONLY, $year->presence('2026-01'));
        self::assertSame(PayrollTakeoverYear::PRESENCE_NONE, $year->presence('2026-02'));
        self::assertContains('2026-02', $year->missingPeriods('2026-01', '2026-03'));
        self::assertSame(['2026-02'], $year->missingPeriods('2026-01', '2026-03'));
        self::assertSame(['other'], $year->sources());

        $months = $year->forEmployment($this->employmentId);
        self::assertCount(2, $months);
        self::assertSame('1++', $months[0]->eldpCode());
        self::assertSame(['2026-01-01', '2026-01-31'], $months[0]->insuranceSpan());
        self::assertSame(3_080_000, $months[0]->netPayableMinor);
    }

    /** @return array<string,mixed> */
    private function storedRow(string $period): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_migration_reference_totals
              WHERE supplier_id = ? AND period_start = ?'
        );
        $stmt->execute([$this->supplierId, $period . '-01']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Za období {$period} nic neleží.");

        return $row;
    }

    private function storedCount(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_migration_reference_totals WHERE supplier_id = ?'
        );
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,string> */
    private function row(string $period): array
    {
        $payout = (new \DateTimeImmutable($period . '-01'))
            ->modify('+1 month')->format('Y-m-10');

        return [
            'employee_id' => (string) $this->employeeId,
            'employment_code' => self::CODE,
            'period' => $period,
            'pension_participation' => '1',
            'insurance_days' => '31',
            'excluded_days' => '4',
            'worked_days' => '21,5',
            'worked_hours' => '168.25',
            'gross_minor' => '4000000',
            'net_minor' => '3200000',
            'deductions_minor' => '120000',
            'net_payable_minor' => '3080000',
            'social_base_minor' => '4000000',
            'health_base_minor' => '4000000',
            'employee_social_minor' => '284000',
            'employee_health_minor' => '180000',
            'employer_social_minor' => '992000',
            'employer_health_minor' => '360000',
            'advance_tax_minor' => '300000',
            'withholding_tax_minor' => '0',
            'tax_bonus_minor' => '0',
            'payout_date' => $payout,
            'activity_code' => '1',
            'external_relationship_ref' => '',
        ];
    }

    /** @param list<array<string,string>> $rows */
    private function csv(array $rows): string
    {
        $columns = TakeoverTabularImportService::columns();
        $lines = [implode(';', $columns)];
        foreach ($rows as $row) {
            $lines[] = implode(';', array_map(
                static fn (string $column): string => $row[$column] ?? '0',
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

    private function createEmployment(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01", "2026-01-01", 4000000, 0, 1)'
        )->execute([$this->supplierId, $this->employeeId, self::CODE]);

        return (int) $pdo->lastInsertId();
    }

    private function activatePayrollFrom(PDO $pdo, string $startPeriod): void
    {
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period)
             VALUES (?, "active", ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), start_period = VALUES(start_period)'
        )->execute([$this->supplierId, $startPeriod]);
    }
}
