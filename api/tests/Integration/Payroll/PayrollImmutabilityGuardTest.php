<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P-13 a C-16 — neměnnost vynucená DATABÁZÍ, ne jen aplikační cestou.
 *
 * `payroll_deduction_ledger`, `payroll_net_results` a `payroll_payout_allocations`
 * nesou sražené částky, čistou mzdu a rozpis, kam se má vyplatit — a jako jediné
 * z modulu neměly do W25 v databázi žádný guard. Aplikace do nich zapisuje výhradně
 * INSERTem, jenže backfill, import ani ruční UPDATE v konzoli se aplikace neptají.
 *
 * Testuje se to nejnižší patro (holé SQL), protože právě tudy vede cesta, kterou
 * scénářový test přes službu nikdy neuvidí. Zároveň se ověřuje OPAČNÁ větev: guard,
 * který zakáže i legitimní zápis, je horší než žádný — propagace odvozeného období
 * z migrace 1593 musí dál fungovat.
 *
 * Všechno běží v transakci, kterou tearDown vrací zpět; SIGNAL z triggeru ruší
 * jen příkaz, ne transakci, takže se z odmítnutí dá pokračovat.
 */
#[Group('integration')]
final class PayrollImmutabilityGuardTest extends TestCase
{
    private const PERIOD = '2099-07-01';
    private const PAYMENT = '2099-08-31';
    private const ZERO_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private Connection $db;
    private PDO $pdo;
    private int $supplierId = 0;
    private int $employeeId = 0;
    private int $runId = 0;
    private int $revisionId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->db = Bootstrap::buildApp()->getContainer()->get(Connection::class);
            $this->pdo = $this->db->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach ([
            'payroll_deduction_ledger',
            'payroll_net_results',
            'payroll_payout_allocations',
            'payroll_runs',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Mzdové migrace neproběhly (' . $table . ').');
            }
        }

        $this->supplierId = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }

        $this->pdo->beginTransaction();
        $this->pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name) VALUES (?, ?)')
            ->execute([$this->supplierId, 'W25 guard']);
        $this->employeeId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date)
             VALUES (?, ?, ?)'
        )->execute([$this->supplierId, self::PERIOD, self::PAYMENT]);
        $this->runId = (int) $this->pdo->lastInsertId();
        $this->revisionId = $this->insertRevision('snapshot');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    /** Append-only ledger srážek: oprava je protipohyb, ne přepis ani smazání. */
    public function testDeductionLedgerRejectsUpdateAndDelete(): void
    {
        $ledgerId = $this->insertLedgerMovement();

        $this->assertRejected(
            'UPDATE payroll_deduction_ledger SET amount_minor = 1 WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $ledgerId],
            'immutable',
        );
        $this->assertRejected(
            'DELETE FROM payroll_deduction_ledger WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $ledgerId],
            'append-only',
        );
    }

    /** Rozpis výplaty patří k jednomu výsledku; jiný rozpis = jiná revize. */
    public function testPayoutAllocationRejectsUpdateAndDelete(): void
    {
        $netResultId = $this->insertNetResult();
        $allocationId = $this->insertPayoutAllocation($netResultId);

        $this->assertRejected(
            'UPDATE payroll_payout_allocations SET amount_minor = 1 WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $allocationId],
            'immutable',
        );
        $this->assertRejected(
            'DELETE FROM payroll_payout_allocations WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $allocationId],
            'append-only',
        );
    }

    /** Výsledek čisté mzdy se nesmí přepsat ani smazat. */
    public function testNetResultRejectsAmountUpdateAndDelete(): void
    {
        $netResultId = $this->insertNetResult();

        $this->assertRejected(
            'UPDATE payroll_net_results SET net_payable_minor = 1 WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $netResultId],
            'immutable',
        );
        $this->assertRejected(
            'UPDATE payroll_net_results SET result_hash = ? WHERE supplier_id = ? AND id = ?',
            [self::ZERO_HASH, $this->supplierId, $netResultId],
            'immutable',
        );
        $this->assertRejected(
            'DELETE FROM payroll_net_results WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $netResultId],
            'append-only',
        );
    }

    /**
     * Opačná větev: propagace odvozeného období z migrace 1593 musí projít dál.
     *
     * Guard, který zavře i legitimní cestu, je horší než žádný — po jeho nasazení
     * by nešlo změnit období dosud editovatelného běhu, protože by se propagace
     * do výsledků odmítla a s ní celý UPDATE.
     */
    public function testDerivedPeriodPropagationStillWorksOnAnEditableRun(): void
    {
        $netResultId = $this->insertNetResult();

        $this->pdo->prepare(
            'UPDATE payroll_runs SET period_start = ? WHERE supplier_id = ? AND id = ?'
        )->execute(['2099-06-01', $this->supplierId, $this->runId]);

        $stmt = $this->pdo->prepare(
            'SELECT period_start FROM payroll_net_results WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$this->supplierId, $netResultId]);
        self::assertSame('2099-06-01', (string) $stmt->fetchColumn());
    }

    /** C-16: identitu běhu ani zpětný chod verze databáze nepustí. */
    public function testRunGuardRejectsIdentityChangeAndVersionRegression(): void
    {
        $this->assertRejected(
            'UPDATE payroll_runs SET supplier_id = supplier_id + 1 WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $this->runId],
            'identity is immutable',
        );
        $this->pdo->prepare(
            'UPDATE payroll_runs SET row_version = 5 WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->runId]);
        $this->assertRejected(
            'UPDATE payroll_runs SET row_version = 2 WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $this->runId],
            'must not go backwards',
        );
        $this->pdo->prepare(
            'UPDATE payroll_runs SET current_revision_no = 3 WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->runId]);
        $this->assertRejected(
            'UPDATE payroll_runs SET current_revision_no = 1 WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $this->runId],
            'must not go backwards',
        );
    }

    /**
     * C-16 jádro: po schválení už období ani datum výplaty posunout nejde.
     *
     * Tohle je díra, kvůli které guard vznikl. `trg_payroll_run_result_period_propagate`
     * (migrace 1593) při změně `period_start` PŘERAZÍTKUJE období všem výsledkům
     * běhu — tedy i těm, které patří schválené a jinak neměnné revizi (1621).
     * Formálně to není přepis částky, takže by to všechna ostatní neměnnost mlčky
     * pustila; věcně by se schválené a vyplacené mzdy přesunuly do jiného měsíce.
     */
    public function testApprovedRunPeriodAndPaymentDateAreFrozen(): void
    {
        $this->pdo->prepare(
            'UPDATE payroll_run_revisions SET status = "approved", approved_at = NOW()
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->revisionId]);

        $this->assertRejected(
            'UPDATE payroll_runs SET period_start = "2099-06-01" WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $this->runId],
            'frozen',
        );
        $this->assertRejected(
            'UPDATE payroll_runs SET payment_date = "2099-09-30" WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $this->runId],
            'frozen',
        );

        // Stav se dál měnit smí — workflow po schválení pokračuje (posted, paid).
        $this->pdo->prepare(
            'UPDATE payroll_runs SET status = "posted", row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->runId]);
        self::assertSame('posted', (string) $this->scalar(
            'SELECT status FROM payroll_runs WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $this->runId],
        ));
    }

    /** @param list<mixed> $params */
    private function assertRejected(string $sql, array $params, string $expectedFragment): void
    {
        try {
            $this->pdo->prepare($sql)->execute($params);
            self::fail('Zápis měl být odmítnut databází: ' . $sql);
        } catch (PDOException $e) {
            self::assertStringContainsString(
                $expectedFragment,
                $e->getMessage(),
                'Odmítnuto jinou chybou, než jakou guard hlásí: ' . $e->getMessage(),
            );
        }
    }

    private function insertRevision(string $status): int
    {
        $json = '{"guard":1}';
        $this->pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 idempotency_key_hash)
             VALUES (?, ?, 1, "regular", ?, "test", ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $this->runId,
            $status,
            self::ZERO_HASH,
            $json,
            hash('sha256', $json),
            random_bytes(32),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertLedgerMovement(): int
    {
        $this->pdo->prepare(
            'INSERT INTO payroll_deduction_agreements
                (supplier_id, employee_id, agreement_reference, title, deduction_kind,
                 status, requested_minor, valid_from)
             VALUES (?, ?, "w25-guard", "W25 guard", "other", "active", 10000, ?)'
        )->execute([$this->supplierId, $this->employeeId, self::PERIOD]);
        $agreementId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO payroll_deduction_ledger
                (supplier_id, agreement_id, revision_id, employee_id, event_kind,
                 amount_minor, event_key_hash, metadata_json)
             VALUES (?, ?, ?, ?, "withheld", 10000, ?, "{}")'
        )->execute([
            $this->supplierId,
            $agreementId,
            $this->revisionId,
            $this->employeeId,
            random_bytes(32),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertNetResult(): int
    {
        $json = '{"guard":"net"}';
        $this->pdo->prepare(
            'INSERT INTO payroll_net_results
                (supplier_id, revision_id, employee_id, cash_income_minor,
                 non_cash_income_minor, employee_social_minor, employee_health_minor,
                 advance_tax_minor, withholding_tax_minor, tax_bonus_minor,
                 correction_minor, annual_settlement_minor, deducted_minor,
                 net_payable_minor, result_json, result_hash)
             VALUES (?, ?, ?, 100000, 0, 0, 0, 0, 0, 0, 0, 0, 0, 100000, ?, ?)'
        )->execute([
            $this->supplierId,
            $this->revisionId,
            $this->employeeId,
            $json,
            hash('sha256', $json),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertPayoutAllocation(int $netResultId): int
    {
        $this->pdo->prepare(
            'INSERT INTO payroll_payout_allocations
                (supplier_id, revision_id, employee_id, net_result_id,
                 allocation_reference, destination_kind, allocation_kind,
                 amount_minor, allocation_order)
             VALUES (?, ?, ?, ?, "w25-guard", "bank", "remainder", 100000, 1)'
        )->execute([
            $this->supplierId,
            $this->revisionId,
            $this->employeeId,
            $netResultId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }
}
