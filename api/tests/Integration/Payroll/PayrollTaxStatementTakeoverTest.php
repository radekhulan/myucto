<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use MyInvoice\Service\Payroll\TaxStatement\TaxStatementService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vyúčtování daně za rok přechodu: leden až září vedl předchozí program,
 * od října MyÚčto.
 *
 * Dřív se podklad skládal jen ze schválených revizí, takže převzaté měsíce
 * ve vyúčtování tiše chyběly a varování radilo je „schválit" — což u měsíce
 * před začátkem vedení mezd nejde. Teď se plní ze stejných počátečních stavů,
 * ze kterých počítá roční zúčtování, a chybějící převzetí je překážka.
 */
#[Group('integration')]
final class PayrollTaxStatementTakeoverTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private TaxStatementService $service;
    private PayrollOpeningBalanceService $openings;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(TaxStatementService::class);
            $this->openings = $container->get(PayrollOpeningBalanceService::class);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $exception->getMessage());
        }
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí syntetický zdroj firmy nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1, financial_office_code = "451",
                    taxpayer_type = "po", dic = "CZ12345678"
              WHERE id = ?',
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, "active", "2026-10-01")',
        )->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testTakenOverMonthsFillTheStatementFromOpeningBalances(): void
    {
        $first = $this->employee('Převzatá osoba A', '2024-03-01');
        $second = $this->employee('Převzatá osoba B', '2026-02-01');
        $this->openings->save($this->supplierId, $first, 2026, $this->months(1, 9, 3_000_00, 0, 500_00), 'test', null);
        // Nástup v únoru: leden není převzatý měsíc, únor až září ano.
        $this->openings->save($this->supplierId, $second, 2026, $this->months(2, 9, 100_00, 1_200_00, 0), 'test', null);
        foreach (['2026-10-01', '2026-11-01', '2026-12-01'] as $period) {
            $this->seedApprovedMonth($period, 2, 3_100_00, 1_200_00, 500_00);
        }

        $preview = $this->service->preview($this->supplierId, 2026);
        $dpz = $preview['dpzvd6'];
        $dps = $preview['dpsvd2'];

        self::assertSame([], $dpz['blockers']);
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9], $dpz['taken_over_months']);
        self::assertCount(12, $dpz['months']);
        self::assertSame(1, $dpz['months'][0]['headcount']);
        self::assertTrue($dpz['months'][0]['taken_over']);
        self::assertSame(3_000, $dpz['months'][0]['advance_due']);
        self::assertSame(3_000, $dpz['months'][0]['remitted']);
        self::assertSame(2, $dpz['months'][1]['headcount']);
        self::assertSame(3_100, $dpz['months'][1]['advance_due']);
        self::assertSame(1_200, $dpz['months'][1]['bonus_paid']);
        // Plátce si vyplacené bonusy započte proti zálohám za celý měsíc
        // (§ 35d odst. 4), ne po osobách: 3 100 − 1 200.
        self::assertSame(1_900, $dpz['months'][1]['remitted']);
        self::assertFalse($dpz['months'][9]['taken_over']);
        // Rok sedí: 3 000 × 9 + 100 × 8 z převzetí, 3 100 × 3 z běhů.
        self::assertSame(27_000 + 800 + 9_300, $dpz['total']['advance_due']);
        self::assertSame(1_200 * 8 + 1_200 * 3, $dpz['total']['bonus_paid']);
        self::assertStringContainsString('předcházejí začátku vedení mezd', implode(' ', $dpz['warnings']));
        self::assertStringNotContainsString('nejdřív je schvalte', implode(' ', $dpz['warnings']));

        self::assertSame(500_00 * 9 + 500_00 * 3, $dps['total']['tax_due_minor']);
        self::assertSame(500_00, $dps['months'][0]['tax_due_minor']);
        self::assertSame(500_00, $dps['months'][0]['remitted_minor']);
        self::assertTrue($dps['months'][0]['taken_over']);
    }

    public function testMissingTakeoverMonthBlocksTheStatement(): void
    {
        $covered = $this->employee('Převzatá osoba C', '2025-01-01');
        $this->employee('Převzatá osoba D', '2025-06-01');
        $this->openings->save($this->supplierId, $covered, 2026, $this->months(1, 9, 3_000_00, 0, 0), 'test', null);
        $this->seedApprovedMonth('2026-10-01', 2, 3_100_00, 0, 0);

        $preview = $this->service->preview($this->supplierId, 2026);
        self::assertNotSame([], $preview['dpzvd6']['blockers']);
        self::assertStringContainsString('Za měsíce 1–9', $preview['dpzvd6']['blockers'][0]);
        self::assertStringContainsString('u 1 zaměstnanců', $preview['dpzvd6']['blockers'][0]);
        self::assertStringNotContainsString('Převzatá osoba', $preview['dpzvd6']['blockers'][0]);
        // U koho: náhled nese osoby zvlášť, aby šlo proklikem otevřít jejich kartu.
        self::assertSame('Převzatá osoba D', $preview['dpzvd6']['takeover_gaps'][0]['employee_name']);
        self::assertSame(range(1, 9), $preview['dpzvd6']['takeover_gaps'][0]['missing_months']);
        self::assertNotSame([], $preview['dpsvd2']['blockers']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('převzaté úhrny');
        $this->service->build($this->supplierId, 2026, TaxStatementService::FORM_DEPENDENT_ACTIVITY);
    }

    private function employee(string $name, string $start): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)',
        )->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, ?, "employment", "active", ?, ?, 4000000, 0, 1)',
        )->execute([$this->supplierId, $employeeId, 'ZAM-' . $employeeId, $start, $start]);

        return $employeeId;
    }

    /** @return list<array<string,int>> */
    private function months(int $from, int $to, int $advanceTax, int $bonus, int $withholding): array
    {
        $rows = [];
        for ($month = $from; $month <= $to; ++$month) {
            $rows[] = [
                'month' => $month,
                'social_assessment_base_minor_units' => 40_000_00,
                'advance_base_minor_units' => 40_000_00,
                'advance_tax_minor_units' => $advanceTax,
                'withholding_base_minor_units' => $withholding > 0 ? 3_000_00 : 0,
                'withholding_tax_minor_units' => $withholding,
                'applied_non_refundable_credits_minor_units' => 2_570_00,
                'applied_child_credit_minor_units' => 0,
                'tax_bonus_minor_units' => $bonus,
                'bonus_qualifying_income_minor_units' => 40_000_00,
            ];
        }

        return $rows;
    }

    private function seedApprovedMonth(
        string $periodStart,
        int $headcount,
        int $advanceMinor,
        int $bonusMinor,
        int $withholdingMinor,
    ): void {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status, current_revision_no, created_by, updated_by)
             VALUES (?, ?, ?, "approved", 1, ?, ?)',
        )->execute([$this->supplierId, $periodStart, substr($periodStart, 0, 8) . '15', $this->userId, $this->userId]);
        $runId = (int) $pdo->lastInsertId();
        $revisionResult = json_encode([
            'schema_version' => 'payroll-run-result.v2',
            'totals' => ['source_amount_minor' => $advanceMinor * 4],
            'people' => array_fill(0, $headcount, []),
        ], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "regular", "approved", "payroll-run-input.v2", ?, "{}", ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            hash('sha256', "takeover-statement-input:{$runId}"),
            $revisionResult,
            hash('sha256', $revisionResult),
            hash('sha256', "takeover-statement-revision:{$runId}", true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $taxResult = json_encode([
            'status' => 'calculated',
            'advance_tax_minor_units' => $advanceMinor,
            'tax_bonus_minor_units' => $bonusMinor,
            'withholding_tax_minor_units' => $withholdingMinor,
        ], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO payroll_statutory_results
                (supplier_id, revision_id, calculation_kind, schema_version, result_status,
                 ruleset_id, ruleset_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, result_set_hash, created_by)
             VALUES (?, ?, "income_tax", "payroll-income-tax-result.v1", "calculated",
                     "test", ?, "{}", ?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $revisionId,
            str_repeat('b', 64),
            hash('sha256', "takeover-statement-statutory-input:{$revisionId}"),
            $taxResult,
            hash('sha256', $taxResult),
            hash('sha256', "takeover-statement-statutory-result:{$revisionId}"),
            $this->userId,
        ]);
    }
}
