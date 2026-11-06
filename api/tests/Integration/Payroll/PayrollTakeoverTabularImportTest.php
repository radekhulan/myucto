<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollMigrationReconciliationRepository;
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
    private PayrollMigrationReconciliationRepository $reconciliation;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;

    use IsolatedSupplierTrait;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build()
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
        $this->reconciliation = $container->get(PayrollMigrationReconciliationRepository::class);
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
        self::assertLessThanOrEqual(32, count($header), 'Parser přijímá nejvýš 32 sloupců.');
    }

    /**
     * Vzorový soubor je lidský: osoba jménem a označením vztahu, částky
     * v korunách. Interní id ani haléře nikdo ze sestavy předchozího programu
     * neopíše.
     */
    public function testHumanColumnsIdentifyThePersonByNameAndTakeCrowns(): void
    {
        $header = explode(';', explode("\r\n", TakeoverTabularImportService::template())[0]);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
        self::assertContains('employee_name', $header);
        self::assertContains('gross', $header);
        self::assertNotContains('employee_id', $header);
        self::assertNotContains('gross_minor', $header);

        $columns = TakeoverTabularImportService::columns();
        $row = [
            'employee_name' => ' převzatá OSOBA ',
            'employment_code' => self::CODE,
            'period' => '2026-06',
            'pension_participation' => '1',
            'insurance_days' => '30',
            'excluded_days' => '0',
            'worked_days' => '21',
            'gross' => '40 000,50',
            'advance_tax' => '3000',
            'net_payable' => '30800.5',
            'payout_date' => '2026-07-10',
        ];
        $csv = implode(';', $columns) . "\r\n" . implode(';', array_map(
            static fn (string $column): string => $row[$column] ?? '0',
            $columns,
        )) . "\r\n";

        $result = $this->imports->apply($this->supplierId, 'other', 'csv', 'prevzate.csv', $csv);

        self::assertSame(1, $result['written']);
        $stored = $this->storedRow('2026-06');
        self::assertSame($this->employeeId, (int) $stored['employee_id']);
        self::assertSame(4_000_050, (int) $stored['gross_minor']);
        self::assertSame(300_000, (int) $stored['advance_tax_minor']);
        self::assertSame(3_080_050, (int) $stored['net_payable_minor']);

        $row['employee_name'] = 'Někdo Jiný';
        $unknown = implode(';', $columns) . "\r\n" . implode(';', array_map(
            static fn (string $column): string => $row[$column] ?? '0',
            $columns,
        )) . "\r\n";
        $preview = $this->imports->preview($this->supplierId, 'other', 'csv', 'prevzate.csv', $unknown);
        self::assertStringContainsString('„Někdo Jiný" s pracovním vztahem', $preview['errors'][0]['error_message']);
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

    /**
     * Měsíc, který MyÚčto spočítalo, není díra v roce.
     *
     * Spočítaná strana se dřív četla z `payroll_net_results`, do které pipeline
     * nikdy nezapisuje. Přehled roku přechodu proto hlásil každý spočítaný měsíc
     * jako „chybí" a srovnávací sestava neměla s čím porovnávat.
     */
    public function testCalculatedRunCountsAsCalculatedSide(): void
    {
        $this->imports->apply(
            $this->supplierId,
            'other',
            'csv',
            'prevzate.csv',
            $this->csv([$this->row('2026-07')]),
        );
        $this->calculatedRun('2026-08-01');

        $year = $this->reader->forSupplier($this->supplierId, self::YEAR);

        self::assertSame(['2026-08'], $year->calculatedPeriods);
        self::assertSame(PayrollTakeoverYear::PRESENCE_TAKEOVER_ONLY, $year->presence('2026-07'));
        self::assertSame(PayrollTakeoverYear::PRESENCE_CALCULATED_ONLY, $year->presence('2026-08'));
        self::assertNotContains('2026-08', $year->missingPeriods());

        $totals = $this->reconciliation->calculatedTotals($this->supplierId, self::YEAR);
        self::assertCount(1, $totals);
        self::assertSame('2026-08', $totals[0]['period']);
        self::assertSame(4_000_000, (int) $totals[0]['gross_minor']);
        self::assertSame(3_200_000, (int) $totals[0]['net_minor']);
        self::assertSame(300_000, (int) $totals[0]['advance_tax_minor']);
    }

    /** Spočítaný běh s jedním výsledkem osoby ve tvaru, který zapisuje výpočet. */
    private function calculatedRun(string $periodStart): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, ?, "approved", 1)'
        )->execute([$this->supplierId, $periodStart, substr($periodStart, 0, 8) . '20']);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "regular", "approved", "v1", ?, "{}", ?, "{}", ?, UNHEX(?))'
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('1', 64),
            hash('sha256', 'takeover-calculated-' . $this->supplierId . '-' . $periodStart),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $json = json_encode([
            'employee_id' => $this->employeeId,
            'statutory' => [
                'status' => 'calculated',
                'net_payable_minor_units' => 3_080_000,
                'net_pay' => [
                    'cash_income_minor_units' => 4_000_000,
                    'non_cash_income_minor_units' => 0,
                    'employee_social_minor_units' => 284_000,
                    'employee_health_minor_units' => 180_000,
                    'advance_tax_minor_units' => 300_000,
                    'withholding_tax_minor_units' => 0,
                    'tax_bonus_minor_units' => 0,
                    'deducted_minor_units' => 120_000,
                    'net_payable_minor_units' => 3_080_000,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO payroll_run_persons (supplier_id, revision_id, employee_id, result_json, result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")'
        )->execute([$this->supplierId, $revisionId, $this->employeeId, $json, hash('sha256', $json)]);
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

    /**
     * Starší tvar souboru: interní `employee_id` a částky v celých haléřích.
     * Import ho přijímá dál, aby fungovaly exporty připravené podle dřívějšího vzoru.
     *
     * @param list<array<string,string>> $rows
     */
    private function csv(array $rows): string
    {
        $columns = [
            'employee_id', 'employment_code', 'period', 'pension_participation', 'insurance_days',
            'excluded_days', 'worked_days', 'gross_minor', 'net_minor', 'deductions_minor',
            'net_payable_minor', 'social_base_minor', 'health_base_minor', 'employee_social_minor',
            'employee_health_minor', 'employer_social_minor', 'employer_health_minor',
            'advance_tax_minor', 'withholding_tax_minor', 'tax_bonus_minor', 'payout_date',
            'worked_hours', 'activity_code', 'external_relationship_ref',
        ];
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
