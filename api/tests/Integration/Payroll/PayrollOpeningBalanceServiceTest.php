<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Počáteční stavy pro zaměstnance převzatého z jiného zpracování.
 *
 * Tabulka existovala od migrace 1258, ale nevedla k ní žádná cesta —
 * `appendOpeningBalance()` volaly jen testy. Zaměstnanec, který nastoupil dřív,
 * než firma začala vést mzdy v MyÚčtu, tak zablokoval celý mzdový běh.
 */
#[Group('integration')]
final class PayrollOpeningBalanceServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollOpeningBalanceService $service;
    private PayrollStatutoryAccumulatorRepository $accumulators;
    private int $supplierId;
    private int $employeeId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer()
            ?? throw new \RuntimeException('DI kontejner není dostupný.');
        $db = $container->get(Connection::class);
        $service = $container->get(PayrollOpeningBalanceService::class);
        $accumulators = $container->get(PayrollStatutoryAccumulatorRepository::class);
        if (!$db instanceof Connection
            || !$service instanceof PayrollOpeningBalanceService
            || !$accumulators instanceof PayrollStatutoryAccumulatorRepository
        ) {
            throw new \RuntimeException('Služba počátečních stavů není dostupná.');
        }
        $this->db = $db;
        $this->service = $service;
        $this->accumulators = $accumulators;
        $pdo = $db->pdo();
        $sourceSupplierId = $this->firstSupplierId($pdo);
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employeeId = $this->createEmployee($pdo, $this->supplierId);
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

    /**
     * Podané JMHZ zamkne, co z počátečního stavu vzalo: základ SP a daň. Zdravotní
     * pojistné jde doplnit dál. Podání nese běh jen přes povinnost, ne `source_revision_id`.
     */
    public function testFiledMonthlyReportLocksReportedTotalsButNotHealthInsurance(): void
    {
        $months = [$this->month(1, 4000000, 900000)];
        $this->service->save($this->supplierId, $this->employeeId, 2026, $months, 'Rekapitulace', null);
        $this->fileMonthlyReport('2026-08-01');

        self::assertNotNull($this->service->lockReason($this->supplierId, $this->employeeId, 2026));

        $saved = $this->service->save(
            $this->supplierId,
            $this->employeeId,
            2026,
            [array_replace($months[0], [
                'health_assessment_base_minor_units' => 2240000,
                'health_employee_contribution_minor_units' => 261900,
            ])],
            'Rekapitulace',
            null,
        );
        self::assertSame(261900, $saved['months'][0]['health_employee_contribution_minor_units']);

        $this->expectException(\DomainException::class);
        $this->service->save(
            $this->supplierId,
            $this->employeeId,
            2026,
            [$this->month(1, 5000000, 900000)],
            'Rekapitulace',
            null,
        );
    }

    private function fileMonthlyReport(string $periodStart): void
    {
        $pdo = $this->db->pdo();
        $userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $pdo->prepare(
            "INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, '2026-09-10', 'approved', 1)"
        )->execute([$this->supplierId, $periodStart]);
        $runId = (int) $pdo->lastInsertId();
        $json = '{"people":[]}';
        $pdo->prepare(
            "INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash,
                 approved_by, approved_at)
             VALUES (?, ?, 1, 'regular', 'approved', 'payroll-run-input.v2', ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([
            $this->supplierId, $runId, str_repeat('a', 64), $json, hash('sha256', $json),
            $json, hash('sha256', $json), hash('sha256', 'opening-lock-' . $runId, true), $userId,
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_run_persons (supplier_id, revision_id, employee_id, result_json, result_hash, status)
             VALUES (?, ?, ?, ?, ?, 'calculated')"
        )->execute([$this->supplierId, $revisionId, $this->employeeId, '{}', hash('sha256', '{}')]);
        $pdo->prepare(
            "INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type, subject_reference, period_start, period_end,
                 obligation_kind, preferred_channel, status, source_event_type, source_event_reference,
                 source_event_hash, request_fingerprint, idempotency_key_hash)
             VALUES (?, 'production', 'JMHZ25', 'payroll_run', ?, ?, LAST_DAY(?), 'regular', 'vrep_apep', 'submitted',
                     'jmhz_preparation_snapshot', 'jmhz_preparation:1', ?, ?, ?)"
        )->execute([
            $this->supplierId, 'payroll_run:' . $runId . ':office:1', $periodStart, $periodStart,
            str_repeat('b', 64), str_repeat('c', 64), hash('sha256', 'opening-lock-obligation-' . $runId, true),
        ]);
        $obligationId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_submissions
                (supplier_id, environment, obligation_id, submission_kind, channel, status,
                 source_revision_id, source_snapshot_hash, request_fingerprint, idempotency_key_hash, submitted_at)
             VALUES (?, 'production', ?, 'regular', 'vrep_apep', 'submitted', NULL, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([
            $this->supplierId, $obligationId, str_repeat('d', 64), str_repeat('e', 64),
            hash('sha256', 'opening-lock-submission-' . $runId, true),
        ]);
    }

    public function testMonthlyTotalsBecomeTheYearlyOpeningAndUnblockTheRun(): void
    {
        $saved = $this->service->save(
            $this->supplierId,
            $this->employeeId,
            2026,
            [$this->month(1, 4000000, 900000), $this->month(2, 4000000, 900000)],
            'Mzdová rekapitulace 1–2/2026 z předchozího programu',
            null,
        );

        self::assertCount(2, $saved['months']);
        self::assertFalse($saved['locked']);

        $social = $this->accumulators
            ->openingBalance($this->supplierId, $this->employeeId, 2026, 'social_insurance');
        $tax = $this->accumulators
            ->openingBalance($this->supplierId, $this->employeeId, 2026, 'income_tax');

        // Uživatel zadává po měsících, kumulace je roční součet.
        self::assertSame(8000000, $social['values']['assessment_base_minor_units']);
        self::assertSame(1800000, $tax['values']['advance_base_minor_units']);
        self::assertSame(2, $tax['values']['completed_months']);
        // Rozpis zůstává dohledatelný, jinak by po letech nešlo ověřit, z čeho součet vznikl.
        self::assertCount(2, $social['evidence']['months']);

        // A hlavně: běh mezd na tom už neztroskotá.
        $state = $this->accumulators->stateBeforePeriod(
            $this->supplierId,
            $this->employeeId,
            2026,
            '2026-03-01',
            'social_insurance',
        );
        self::assertSame(8000000, $state['totals']['assessment_base_minor_units']);
    }

    /** Reference podkladu je uživatelská poznámka a nesmí blokovat uložení. */
    public function testOpeningBalanceDoesNotRequireSourceReference(): void
    {
        $saved = $this->service->save(
            $this->supplierId,
            $this->employeeId,
            2026,
            [$this->month(1, 4000000, 900000)],
            '',
            null,
        );

        self::assertNotNull($saved['openings']['social_insurance']);
        self::assertSame('', $this->accumulators
            ->openingBalance($this->supplierId, $this->employeeId, 2026, 'social_insurance')
            ['source_reference']);
    }

    /**
     * Uložit dvakrát totéž je replay (uživatel klikl dvakrát), změna částky je
     * oprava navázaná na aktuální verzi. Klíč se proto odvozuje z dat, ne z času.
     */
    public function testSavingTwiceReplaysAndChangedAmountsCorrectThePreviousVersion(): void
    {
        $months = [$this->month(1, 4000000, 900000)];
        $first = $this->service->save(
            $this->supplierId, $this->employeeId, 2026, $months, 'Sestava 1/2026', null,
        );
        $replay = $this->service->save(
            $this->supplierId, $this->employeeId, 2026, $months, 'Sestava 1/2026', null,
        );
        self::assertSame(
            $first['openings']['social_insurance'],
            $replay['openings']['social_insurance'],
            'Stejná data podruhé nesmí založit další verzi.',
        );

        $corrected = $this->service->save(
            $this->supplierId,
            $this->employeeId,
            2026,
            [$this->month(1, 4500000, 900000)],
            'Sestava 1/2026 — oprava',
            null,
        );
        self::assertNotSame(
            $first['openings']['social_insurance'],
            $corrected['openings']['social_insurance'],
            'Jiná částka musí vytvořit novou verzi.',
        );
        self::assertSame(4500000, $this->accumulators
            ->openingBalance($this->supplierId, $this->employeeId, 2026, 'social_insurance')
            ['values']['assessment_base_minor_units']);
    }

    /** Nový zaměstnanec nemá předchozí měsíce, ale nulový počátek musí potvrdit. */
    public function testExplicitZeroOpeningUnblocksNewEmployee(): void
    {
        $saved = $this->service->save(
            $this->supplierId, $this->employeeId, 2026, [], '', null,
        );

        self::assertSame([], $saved['months']);
        self::assertNotNull($saved['openings']['social_insurance']);
        self::assertNotNull($saved['openings']['income_tax']);
        self::assertSame(0, $this->accumulators
            ->stateBeforePeriod(
                $this->supplierId,
                $this->employeeId,
                2026,
                '2026-08-01',
                'income_tax',
            )['totals']['completed_months']);
    }

    public function testOpeningStartsWithEmploymentMonthAndCountsOnlyWorkedMonths(): void
    {
        $saved = $this->service->save(
            $this->supplierId,
            $this->employeeId,
            2026,
            [
                $this->month(3, 100, 100),
                $this->month(4, 100, 100),
                $this->month(5, 100, 100),
                $this->month(6, 100, 100),
                $this->month(7, 100, 100),
            ],
            '',
            null,
        );

        self::assertSame([3, 4, 5, 6, 7], array_column($saved['months'], 'month'));
        self::assertSame(5, $this->accumulators
            ->stateBeforePeriod(
                $this->supplierId,
                $this->employeeId,
                2026,
                '2026-08-01',
                'income_tax',
            )['totals']['completed_months']);
    }

    public function testRejectsGapInsideOpeningMonthInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('souvislou řadu');
        $this->service->save(
            $this->supplierId,
            $this->employeeId,
            2026,
            [$this->month(3, 100, 100), $this->month(5, 100, 100)],
            '',
            null,
        );
    }

    public function testBothAccumulatorKindsRollBackWhenSecondWriteFails(): void
    {
        $month = $this->month(3, 100, 100);
        $month['advance_base_minor_units'] = -1;

        try {
            $this->service->save(
                $this->supplierId,
                $this->employeeId,
                2026,
                [$month],
                '',
                null,
            );
            self::fail('Neplatná daňová kumulace musí shodit oba openingy.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        self::assertNull($this->accumulators->openingBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'social_insurance',
        ));
        self::assertNull($this->accumulators->openingBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'income_tax',
        ));
    }

    public function testRejectsTwelveMonthsBecauseOpeningCoversOnlyPrecedingMonths(): void
    {
        $months = [];
        for ($month = 1; $month <= 12; ++$month) {
            $months[] = $this->month($month, 100, 100);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->service->save(
            $this->supplierId, $this->employeeId, 2026, $months, 'Sestava', null,
        );
    }

    /** @return array<string,int> */
    private function month(int $month, int $socialBase, int $advanceBase): array
    {
        return [
            'month' => $month,
            'social_assessment_base_minor_units' => $socialBase,
            'advance_base_minor_units' => $advanceBase,
            'advance_tax_minor_units' => 135000,
            'withholding_base_minor_units' => 0,
            'withholding_tax_minor_units' => 0,
            'applied_non_refundable_credits_minor_units' => 257000,
            'applied_child_credit_minor_units' => 0,
            'tax_bonus_minor_units' => 0,
            'bonus_qualifying_income_minor_units' => 0,
        ];
    }

    /**
     * Začátek vedení mezd v lednu nestačí: bez běhu MyÚčta od ledna mohl leden
     * až květen zpracovat předchozí program a nula by zkreslila roční úhrny.
     */
    public function testZeroOpeningNeedsMyUctoRunFromWindowStart(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, "active", "2026-01-01")')
            ->execute([$this->supplierId]);
        $this->createEmployment($pdo, '2026-01-01');
        $this->createRun($pdo, '2026-06-01', '2026-07-15');

        self::assertSame([], $this->service->seedProvableZeroOpenings($this->supplierId, '2026-06-01'));
        self::assertNull($this->accumulators->openingBalance($this->supplierId, $this->employeeId, 2026, 'income_tax'));

        $this->createRun($pdo, '2026-01-01', '2026-02-15');
        self::assertSame([$this->employeeId], $this->service->seedProvableZeroOpenings($this->supplierId, '2026-06-01'));
    }

    private function createEmployment(PDO $pdo, string $start): void
    {
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, "ZAM-T", "employment", "active", ?, ?, 4000000, 0, 1)'
        )->execute([$this->supplierId, $this->employeeId, $start, $start]);
    }

    private function createRun(PDO $pdo, string $periodStart, string $paymentDate): void
    {
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, ?, "approved", 1)'
        )->execute([$this->supplierId, $periodStart, $paymentDate]);
    }

    private function firstSupplierId(PDO $pdo): int
    {
        $stmt = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    private function createEmployee(PDO $pdo, int $supplierId): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)'
        );
        $stmt->execute([$supplierId, 'Převzatá osoba']);

        return (int) $pdo->lastInsertId();
    }
}
