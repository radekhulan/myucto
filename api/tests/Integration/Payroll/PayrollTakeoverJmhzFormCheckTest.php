<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzExternalSubmissionStore;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportForm;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotals;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotalsWriter;
use MyInvoice\Service\Payroll\Report\PayrollMigrationReconciliationService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hlášení JMHZ podané předchozím programem před opravou mzdy: kontrola převzetí
 * ho ukáže jako upozornění, převod nezastaví a zaokrouhlení ani opravené
 * hlášení za nález nepovažuje.
 */
#[Group('integration')]
final class PayrollTakeoverJmhzFormCheckTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const GUID_JUNE = 'A1111111-1111-4111-8111-111111111111';
    private const GUID_JULY = 'A2222222-2222-4222-8222-222222222222';
    private const GUID_JULY_O = 'A3333333-3333-4333-8333-333333333333';
    private const GUID_MAY = 'A4444444-4444-4444-8444-444444444444';

    private Connection $db;
    private JmhzExternalSubmissionStore $store;
    private PayrollMigrationReferenceTotalsWriter $writer;
    private PayrollMigrationReconciliationService $reconciliation;
    private int $supplierId = 0;
    private int $sickEmployee = 0;
    private int $sickEmployment = 0;
    private int $healthyEmployee = 0;
    private int $healthyEmployment = 0;

    protected function setUp(): void
    {
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->store = $container->get(JmhzExternalSubmissionStore::class);
            $this->writer = $container->get(PayrollMigrationReferenceTotalsWriter::class);
            $this->reconciliation = $container->get(PayrollMigrationReconciliationService::class);
        } catch (\Throwable $exception) {
            self::markTestSkipped('DI/DB nedostupné: ' . $exception->getMessage());
        }
        if (!$this->db->hasTable('payroll_external_jmhz_submissions')) {
            self::markTestSkipped('Chybí tabulka payroll_external_jmhz_submissions (migrace 1901).');
        }
        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        if ($source === 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, "active", "2026-08-01")')
            ->execute([$this->supplierId]);
        [$this->sickEmployee, $this->sickEmployment] = $this->person('Nemocná Syntetická', 'S-1');
        [$this->healthyEmployee, $this->healthyEmployment] = $this->person('Zdravý Zkušební', 'Z-1');
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

    public function testReportFiledBeforeWageCorrectionIsWarningAndDoesNotBlockTakeover(): void
    {
        // Červen: hlášení odešlo s plným tarifem, mzda se pak přepočítala na nulu
        // (celý měsíc v neschopnosti). Druhá osoba sedí až na haléře.
        $this->pamica('MH:6', '2026-06', 'R', self::GUID_JUNE, null, '2026-07-14 06:49:00', [
            [$this->sickEmployee, $this->sickEmployment, 'R', self::attributes('B1111111-1111-4111-8111-111111111111', 'R', 32_164, 2_260, 1_448, 2_895)],
            [$this->healthyEmployee, $this->healthyEmployment, 'R', self::attributes('B2222222-2222-4222-8222-222222222222', 'R', 40_000, 4_880, 1_801, 3_601)],
        ]);
        // Červenec: chybné řádné hlášení opravené opravným - účinný je opravný stav.
        $this->pamica('MH:7', '2026-07', 'R', self::GUID_JULY, null, '2026-08-12 08:00:00', [
            [$this->sickEmployee, $this->sickEmployment, 'R', self::attributes('B3333333-3333-4333-8333-333333333333', 'R', 32_164, 2_260, 1_448, 2_895)],
        ]);
        $this->pamica('MH:8', '2026-07', 'O', self::GUID_JULY_O, 'MH:7', '2026-08-20 08:00:00', [
            [$this->sickEmployee, $this->sickEmployment, 'O', self::attributes('B4444444-4444-4444-8444-444444444444', 'O', 0, 0, 0, 0)],
        ]);
        // Květen z nahraného XML (jiný tvar uloženého obsahu), shoda.
        $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_JMHZ_XML, [
            'source_key' => 'guid:' . self::GUID_MAY . ':R:2026-05:1',
            'document_kind' => 'monthly',
            'period' => '2026-05',
            'submission_type' => 'R',
            'submission_guid' => self::GUID_MAY,
            'corrected_source_key' => null,
            'status' => JmhzExternalSubmissionStore::STATUS_SENT,
            'filled_at' => '2026-06-10T08:00:00',
            'submitted_at' => null,
            'accepted_at' => null,
            'program' => 'Syntetický program',
            'file_name' => 'jmhz-2026-05.xml',
            'payload' => ['xml' => '<synthetic/>'],
        ], [[
            'position' => 1,
            'form_guid' => 'B5555555-5555-4555-8555-555555555555',
            'form_type' => 'R',
            'source_relation_ref' => null,
            'employee_id' => $this->sickEmployee,
            'employment_id' => $this->sickEmployment,
            'payload' => get_object_vars(new JmhzReportForm(
                1, 'B5555555-5555-4555-8555-555555555555', 'R', true, 'bezPriznaku',
                hasSummary: true, incomeTotal: 1_700,
                advance: ['base' => 1_700, 'computed' => 255, 'after_credits' => 0, 'bonus' => 0],
                socialBase: 1_700, employeeHealth: 77, employerHealth: 153,
            )),
        ]], null);

        // Převod (zápis převzatých mezd) projde i s hlášením, které konečné mzdě odporuje.
        $saved = $this->writer->store($this->supplierId, 'pamica', [
            $this->total('2026-05', $this->sickEmployee, $this->sickEmployment, 1_700_00, 0, 77_00, 153_00),
            $this->total('2026-06', $this->sickEmployee, $this->sickEmployment, 0, 0, 0, 0),
            $this->total('2026-07', $this->sickEmployee, $this->sickEmployment, 0, 0, 0, 0),
            $this->total('2026-06', $this->healthyEmployee, $this->healthyEmployment, 40_000_49, 4_879_99, 1_800_00, 3_600_00),
        ], 'synthetic');
        self::assertSame(4, $saved);

        $forms = $this->store->effectiveMonthlyForms($this->supplierId, 'production', 2026);
        self::assertCount(4, $forms, 'Účinné formuláře: květen, červen (dvě osoby) a opravený červenec.');

        $report = $this->reconciliation->report($this->supplierId, 2026);
        $findings = $report['takeover_check']['jmhz_form_differences'];

        self::assertCount(1, $findings, 'Hlásí se jen osoba a měsíc, kde hlášení odporuje konečné mzdě.');
        $finding = $findings[0];
        self::assertSame($this->sickEmployee, $finding['employee_id']);
        self::assertSame('Nemocná Syntetická', $finding['employee_name']);
        self::assertSame('2026-06', $finding['period']);
        self::assertSame([$this->sickEmployment], $finding['employment_ids']);
        self::assertSame('R', $finding['submission_type']);
        self::assertSame([
            ['metric' => 'gross', 'takeover_minor' => 0, 'jmhz_minor' => 32_164_00, 'difference_minor' => 32_164_00],
            ['metric' => 'social_base', 'takeover_minor' => 0, 'jmhz_minor' => 32_164_00, 'difference_minor' => 32_164_00],
            ['metric' => 'advance_tax', 'takeover_minor' => 0, 'jmhz_minor' => 2_260_00, 'difference_minor' => 2_260_00],
            ['metric' => 'health_insurance', 'takeover_minor' => 0, 'jmhz_minor' => 4_343_00, 'difference_minor' => 4_343_00],
        ], $finding['differences']);

        // Upozornění převzatá data nemění.
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM payroll_migration_reference_totals WHERE supplier_id = ?');
        $count->execute([$this->supplierId]);
        self::assertSame(4, (int) $count->fetchColumn());
    }

    /**
     * @param list<array{0:int,1:int,2:string,3:list<array{id:int,section:int,flag:int,order:int,order2:int,value:string}>}> $forms
     */
    private function pamica(string $sourceKey, string $period, string $type, string $guid, ?string $corrects, string $submittedAt, array $forms): void
    {
        $rows = [];
        foreach ($forms as $index => [$employeeId, $employmentId, $formType, $attributes]) {
            $rows[] = [
                'position' => $index + 1,
                'form_guid' => null,
                'form_type' => $formType,
                'source_relation_ref' => 'REL-' . $employmentId,
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'payload' => ['item' => [], 'attributes' => $attributes, 'error' => null],
            ];
        }
        $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_PAMICA, [
            'source_key' => $sourceKey,
            'document_kind' => 'monthly',
            'period' => $period,
            'submission_type' => $type,
            'submission_guid' => $guid,
            'corrected_source_key' => $corrects,
            'status' => JmhzExternalSubmissionStore::STATUS_SENT,
            'filled_at' => $submittedAt,
            'submitted_at' => $submittedAt,
            'accepted_at' => $submittedAt,
            'program' => 'PAMICA',
            'file_name' => null,
            'payload' => ['program' => 'PAMICA'],
        ], $rows, null);
    }

    /**
     * Formulář po atributech datového slovníku, jak ho ukládá převod z PAMICA.
     *
     * @return list<array{id:int,section:int,flag:int,order:int,order2:int,value:string}>
     */
    private static function attributes(string $formGuid, string $type, int $income, int $advance, int $employeeHealth, int $employerHealth): array
    {
        $out = [];
        foreach ([
            [1, 'bezPriznaku'], [10012, $formGuid], [10016, $type], [10495, 'A'],
            [10286, (string) $income], [10297, (string) $income], [10305, (string) $advance], [10306, '0'],
            [10371, (string) $employeeHealth], [10482, (string) $employerHealth], [10477, (string) $income],
        ] as [$id, $value]) {
            $out[] = ['id' => $id, 'section' => 0, 'flag' => 1, 'order' => 0, 'order2' => 0, 'value' => $value];
        }

        return $out;
    }

    private function total(string $period, int $employeeId, int $employmentId, int $gross, int $advance, int $employeeHealth, int $employerHealth): PayrollMigrationReferenceTotals
    {
        return new PayrollMigrationReferenceTotals(
            $period,
            'P-' . $employeeId,
            'REL-' . $employmentId,
            $employeeId,
            $employmentId,
            $gross,
            $gross - $advance,
            $gross,
            $gross,
            0,
            $employeeHealth,
            0,
            $employerHealth,
            $advance,
            0,
            0,
        );
    }

    /** @return array{0:int,1:int} */
    private function person(string $name, string $code): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, ?, "employee", 1)')
            ->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, end_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, ?, "employment", "active", "2024-01-01", "2024-01-01", NULL, 4000000, 0, 1)',
        )->execute([$this->supplierId, $employeeId, $code]);

        return [$employeeId, (int) $pdo->lastInsertId()];
    }
}
