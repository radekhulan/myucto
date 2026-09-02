<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyOverlapException;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollYearCloseRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\PayrollPeriodOwnedException;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Omyl v mzdovém běhu musí jít vzít zpět.
 *
 * Dvě pasti, obě bez cesty ven z aplikace:
 *
 * 1. Zrušení běhu nezahodilo jeho rozdělanou OPRAVNOU revizi. Uzávěrka roku
 *    ji počítala dál ({@see PayrollYearCloseRepository::openCorrectionCount()}),
 *    takže jedna zrušená oprava zablokovala uzávěrku NATRVALO — zrušený běh už
 *    novou revizi nezaloží, takže se ta stará nedala ani dokončit, ani odsunout.
 *
 * 2. Rezervaci mzdového období pro modul zabíralo už samo založení běhu a
 *    uvolnit ji nešlo NIKDY. Kdo běh založil na měsíc, který patřil původnímu
 *    ručnímu zaúčtování, zabral ho modulu napořád.
 */
#[Group('integration')]
final class PayrollRunCancelReversibilityTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRunRepository $runs;
    private PayrollYearCloseRepository $yearClose;
    private PayrollPeriodOwnershipService $ownership;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $connection = $container->get(Connection::class);
        $runs = $container->get(PayrollRunRepository::class);
        $yearClose = $container->get(PayrollYearCloseRepository::class);
        $ownership = $container->get(PayrollPeriodOwnershipService::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollRunRepository::class, $runs);
        self::assertInstanceOf(PayrollYearCloseRepository::class, $yearClose);
        self::assertInstanceOf(PayrollPeriodOwnershipService::class, $ownership);
        $this->db = $connection;
        $this->runs = $runs;
        $this->yearClose = $yearClose;
        $this->ownership = $ownership;

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí zdrojový tenant nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
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

    public function testCancellingARunAbandonsItsOpenCorrectionRevision(): void
    {
        $runId = $this->seedRun('2026-03-01', 'cancelled');
        $this->seedRevision($runId, 1, 'correction', 'calculated');

        self::assertSame(
            1,
            $this->yearClose->openCorrectionCount($this->supplierId, 2026),
            'Rozdělaná opravná revize má uzávěrku blokovat, dokud se nezahodí.',
        );

        $abandoned = $this->runs->abandonRevisionsOnCancel(
            $this->supplierId,
            $runId,
        );

        self::assertSame(1, $abandoned);
        self::assertSame(
            'abandoned',
            $this->revisionStatus($runId),
            'Revize zrušeného běhu musí skončit ve stavu `abandoned`.',
        );
        self::assertSame(
            0,
            $this->yearClose->openCorrectionCount($this->supplierId, 2026),
            'Zrušená oprava už uzávěrku mzdového roku blokovat nesmí.',
        );
    }

    /**
     * Nástupce se u zrušeného běhu nestampuje — a to je přesně ta výjimka,
     * kterou povoluje migrace 1722. Zbytek neměnnosti platí dál: schválenou
     * revizi zahodit nelze ani u zrušeného běhu.
     */
    public function testApprovedRevisionIsStillProtectedOnACancelledRun(): void
    {
        $runId = $this->seedRun('2026-04-01', 'cancelled');
        $this->seedRevision($runId, 1, 'regular', 'approved');

        self::assertSame(
            0,
            $this->runs->abandonRevisionsOnCancel($this->supplierId, $runId),
        );
        self::assertSame('approved', $this->revisionStatus($runId));
    }

    /**
     * Řádná revize se zrušením NEZAHAZUJE. Uzávěrku neblokuje, takže není co
     * napravovat — a kdyby se zahodila tady, přišla by o vazbu na nástupce,
     * kterou jí při `REOPEN` stampuje `supersedeAbandonedRevisions()`.
     */
    public function testRegularRevisionSurvivesCancelSoItsSuccessorLinkIsKept(): void
    {
        $runId = $this->seedRun('2026-09-01', 'cancelled');
        $this->seedRevision($runId, 1, 'regular', 'calculated');

        self::assertSame(
            0,
            $this->runs->abandonRevisionsOnCancel($this->supplierId, $runId),
        );
        self::assertSame('calculated', $this->revisionStatus($runId));
        self::assertSame(
            0,
            $this->yearClose->openCorrectionCount($this->supplierId, 2026),
            'Řádná revize uzávěrku nikdy neblokovala.',
        );
    }

    /**
     * Zahodit rozdělanou revizi smí JEN zrušený běh. U živého běhu by to
     * pod rukama zmizel podklad, ze kterého se právě počítá.
     */
    public function testLiveRunKeepsItsRevision(): void
    {
        $runId = $this->seedRun('2026-05-01', 'calculated');
        $this->seedRevision($runId, 1, 'correction', 'calculated');

        self::assertSame(
            0,
            $this->runs->abandonRevisionsOnCancel($this->supplierId, $runId),
        );
        self::assertSame('calculated', $this->revisionStatus($runId));
    }

    public function testPayrollPeriodClaimCanBeHandedBackWhenNothingLeansOnIt(): void
    {
        $runId = $this->seedRun('2026-06-01', 'cancelled');
        $this->ownership->claimPayroll(
            $this->supplierId,
            2026,
            6,
            'payroll_run',
            $runId,
            $this->userId,
        );

        $status = $this->ownership->payrollClaimStatus($this->supplierId, 2026, 6);
        self::assertTrue($status['claimed']);
        self::assertTrue($status['releasable'], 'Zrušený běh měsíc držet nemá.');

        $this->ownership->releasePayroll(
            $this->supplierId,
            2026,
            6,
            $this->userId,
            'Běh byl založený na špatný měsíc.',
        );

        self::assertFalse(
            $this->ownership->payrollClaimStatus($this->supplierId, 2026, 6)['claimed'],
        );
        // A teprve teď si měsíc smí vzít původní zaúčtování.
        $this->ownership->claimLegacy($this->supplierId, 2026, 6, 202606, $this->userId);
        self::assertSame(
            PayrollPeriodOwnershipService::PROCESSOR_LEGACY,
            $this->ownership->payrollClaimStatus($this->supplierId, 2026, 6)['processor'],
        );
    }

    public function testPayrollPeriodClaimStaysWhenAnApprovedRevisionExists(): void
    {
        $runId = $this->seedRun('2026-07-01', 'approved');
        $this->seedRevision($runId, 1, 'regular', 'approved');
        $this->ownership->claimPayroll(
            $this->supplierId,
            2026,
            7,
            'payroll_run',
            $runId,
            $this->userId,
        );

        $status = $this->ownership->payrollClaimStatus($this->supplierId, 2026, 7);
        self::assertFalse($status['releasable']);
        self::assertNotSame([], $status['blockers']);

        $this->expectException(PayrollPeriodOwnedException::class);
        $this->ownership->releasePayroll(
            $this->supplierId,
            2026,
            7,
            $this->userId,
            'Zkouším uvolnit schválený měsíc.',
        );
    }

    /**
     * Migrace 1723: neměnnost patří schválené revizi, ne rozdělané. Skutečnou
     * obranou jsou cizí klíče (`ON DELETE RESTRICT`), ne plošný trigger.
     */
    public function testUnapprovedRevisionCanBeDeletedButApprovedCannot(): void
    {
        $runId = $this->seedRun('2026-10-01', 'draft');
        $working = $this->seedRevision($runId, 1, 'regular', 'snapshot');

        $delete = $this->db->pdo()->prepare(
            'DELETE FROM payroll_run_revisions WHERE supplier_id = ? AND id = ?',
        );
        $delete->execute([$this->supplierId, $working]);
        self::assertSame(1, $delete->rowCount());

        $frozen = $this->seedRevision($runId, 2, 'regular', 'approved');
        try {
            $delete->execute([$this->supplierId, $frozen]);
            self::fail('Schválenou revizi musí databáze odmítnout smazat.');
        } catch (\PDOException $exception) {
            self::assertStringContainsString(
                'append-only',
                $exception->getMessage(),
            );
        }
    }

    /**
     * Hláška musí říct, kudy ven. „Už obsahuje neměnnou revizi" účetní
     * neporadilo nic — přitom po `lock_inputs` je to úplně běžný stav.
     */
    public function testLockedRunExplainsThatCancellingIsTheWayOut(): void
    {
        $runId = $this->seedRun('2026-11-01', 'draft');
        $this->seedRevision($runId, 1, 'regular', 'snapshot');

        $decision = $this->runs->canDelete($this->supplierId, $runId);
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_run_has_working_revision', $decision->blockerCode);
        self::assertStringContainsString('Zrušit běh', (string) $decision->blockerMessage);
    }

    public function testApprovedRunKeepsTheImmutabilityBlocker(): void
    {
        $runId = $this->seedRun('2026-12-01', 'approved');
        $this->seedRevision($runId, 1, 'regular', 'approved');

        $decision = $this->runs->canDelete($this->supplierId, $runId);
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_run_has_revision', $decision->blockerCode);
    }

    /**
     * Překryv mzdových politik je vstupní chyba, ne porucha serveru. Jako
     * `\RuntimeException` propadal ven a mzdový běh na něm skončil na HTTP 500.
     */
    public function testOverlappingEmployerPoliciesAreAnInputErrorNotACrash(): void
    {
        /*
         * Překryv politik `findEffective()` hlásil jako `\RuntimeException`,
         * takže mzdový běh na něm skončil na HTTP 500 — účetní viděla „chyba
         * serveru" a nemohla poznat, že jde o její dvě politiky.
         *
         * Vytvořit ten stav zevnitř testu nejde: od migrace 1276 překryv
         * odmítne `trg_payroll_employer_policy_overlap_insert` už při INSERTu
         * (ověřeno — INSERT skončí na SQLSTATE 45000). Větev v repozitáři je
         * tedy obrana pro data staršího data, ne běžná cesta. O to důležitější
         * je pojistit typ: `PayrollRunsAction` mapuje `InvalidArgumentException`
         * na 422, takže návrat k `RuntimeException` by 500 tiše vrátil zpátky.
         */
        self::assertTrue(
            is_subclass_of(
                PayrollEmployerPolicyOverlapException::class,
                \InvalidArgumentException::class,
            ),
            'Překryv politik musí zůstat vstupní chybou (422), ne poruchou (500).',
        );

        $policies = new PayrollEmployerPolicyRepository(
            $this->db,
            new PayrollEmployerPolicyDeletionRepository(
                $this->db,
                new ActivityLogger($this->db),
            ),
        );
        self::assertNull(
            $policies->findEffective($this->supplierId, '2026-03-01'),
            'Bez uložené politiky se nic nevyhazuje — jen se nic nenajde.',
        );
    }

    private function seedRun(string $periodStart, string $status): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no, row_version, created_by)
             VALUES (?, ?, ?, ?, 1, 2, ?)',
        )->execute([
            $this->supplierId,
            $periodStart,
            (new \DateTimeImmutable($periodStart))
                ->modify('+1 month +14 days')
                ->format('Y-m-d'),
            $status,
            $this->userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function seedRevision(
        int $runId,
        int $revisionNo,
        string $kind,
        string $status,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, idempotency_key_hash, approved_at,
                 approved_by)
             VALUES (?, ?, ?, ?, ?, "1", ?, "{}", ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $runId,
            $revisionNo,
            $kind,
            $status,
            str_repeat('a', 64),
            str_repeat('b', 64),
            random_bytes(32),
            $status === 'approved' ? '2026-01-01 00:00:00' : null,
            $status === 'approved' ? $this->userId : null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function revisionStatus(int $runId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT status FROM payroll_run_revisions
              WHERE supplier_id = ? AND run_id = ?
           ORDER BY revision_no DESC LIMIT 1',
        );
        $stmt->execute([$this->supplierId, $runId]);

        return (string) $stmt->fetchColumn();
    }
}
