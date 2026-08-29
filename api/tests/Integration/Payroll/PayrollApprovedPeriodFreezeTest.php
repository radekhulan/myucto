<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollApprovedPeriodFreeze;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hranice zmrazené mzdové historie.
 *
 * ⚠️ Běh otevřený k opravě z ní musí vypadnout. Jeho schválená revize zůstává
 * ve stavu `approved` — na `superseded` ji přepne teprve schválení revize
 * opravné — takže dokud se zámek ptal jen revize, účetní otevřela mzdu k opravě
 * a zákonná evidence jí zůstala zamčená. Opravit tedy nešlo právě to, kvůli
 * čemu opravu otevírala.
 */
#[Group('integration')]
final class PayrollApprovedPeriodFreezeTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollApprovedPeriodFreeze $freeze;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        $this->db = $db;
        if (!$db->hasTable('payroll_run_revisions')) {
            $this->markTestSkipped('Mzdové migrace neproběhly.');
        }
        $this->freeze = new PayrollApprovedPeriodFreeze($db);

        $pdo = $db->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testNothingIsFrozenWithoutAnApprovedRevision(): void
    {
        self::assertNull($this->freeze->frozenThrough($this->supplierId));
    }

    public function testApprovedRunFreezesThroughTheEndOfItsMonth(): void
    {
        $this->approvedRun('2026-08-01', 'approved');

        self::assertSame('2026-08-31', $this->freeze->frozenThrough($this->supplierId));
    }

    public function testRunOpenedForCorrectionStopsFreezingItsMonth(): void
    {
        $this->approvedRun('2026-07-01', 'closed');
        $runId = $this->approvedRun('2026-08-01', 'approved');
        self::assertSame('2026-08-31', $this->freeze->frozenThrough($this->supplierId));

        foreach (['correction_pending', 'reopened'] as $status) {
            $this->setRunStatus($runId, $status);
            self::assertSame(
                '2026-07-31',
                $this->freeze->frozenThrough($this->supplierId),
                "stav $status musí srpen odemknout a nechat zamčený červenec",
            );
        }
    }

    /**
     * Novější schválený měsíc drží zámek dál. Měnit podklad srpna pod
     * schváleným zářím by rozbilo i září.
     */
    public function testNewerApprovedRunKeepsTheFreezeEvenWhenAnOlderOneIsReopened(): void
    {
        $august = $this->approvedRun('2026-08-01', 'approved');
        $this->approvedRun('2026-09-01', 'approved');

        $this->setRunStatus($august, 'reopened');

        self::assertSame('2026-09-30', $this->freeze->frozenThrough($this->supplierId));
    }

    private function approvedRun(string $periodStart, string $runStatus): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                 (supplier_id, period_start, payment_date, status,
                  current_revision_no, row_version)
             VALUES (?, ?, ?, ?, 1, 1)'
        )->execute([$this->supplierId, $periodStart, $periodStart, $runStatus]);
        $runId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO payroll_run_revisions
                 (supplier_id, run_id, revision_no, status, schema_version,
                  ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                  idempotency_key_hash)
             VALUES (?, ?, 1, 'approved', 'test', ?, '{}', ?, ?)"
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            random_bytes(32),
        ]);

        return $runId;
    }

    private function setRunStatus(int $runId, string $status): void
    {
        $this->db->pdo()
            ->prepare('UPDATE payroll_runs SET status = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$status, $runId, $this->supplierId]);
    }
}
