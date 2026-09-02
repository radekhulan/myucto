<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeSweepRunner;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Výběr cílů noční detekce registračních změn proti skutečnému schématu.
 *
 * Jednotkový test průchodu ({@see \MyInvoice\Tests\Unit\Payroll\Submission\PayrollRegistrationChangeSweepRunnerTest})
 * ověřuje chování runneru; tady jde o to, co jednotkově ověřit nejde — že SQL
 * platí a že firma bez zapnutých mezd se do nočního běhu nedostane. Kdyby se
 * tam dostala, cron by na instalaci bez mezd vyráběl chyby, a to je přesně ten
 * šum, kvůli kterému by ho někdo vypnul i tam, kde mzdy jsou.
 */
#[Group('integration')]
final class PayrollRegistrationChangeSweepTargetsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollModuleStateRepository $repository;
    private PayrollRegistrationChangeSweepRunner $runner;
    private int $withPayrollId;
    private int $withoutPayrollId;
    private int $disabledModuleId;

    protected function setUp(): void
    {
        // Jeden kontejner na celý test: druhé `buildContainer()` by přineslo
        // vlastní spojení, které by neotevřenou transakci téhle sady nevidělo.
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasColumn('supplier', 'payroll_enabled')
            || !$this->db->hasTable('payroll_module_state')
        ) {
            $this->markTestSkipped('Migrace mzdového modulu neproběhly.');
        }
        $this->repository = $container->get(PayrollModuleStateRepository::class);
        $this->runner = $container->get(PayrollRegistrationChangeSweepRunner::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo
            ->query('SELECT id FROM supplier ORDER BY id LIMIT 1')
            ->fetchColumn();
        $pdo->beginTransaction();
        $this->withPayrollId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->withoutPayrollId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->disabledModuleId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);

        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->withPayrollId, $this->disabledModuleId]);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 0 WHERE id = ?')
            ->execute([$this->withoutPayrollId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period)
             VALUES (?, \'active\', \'2026-01-01\')
             ON DUPLICATE KEY UPDATE status = VALUES(status)'
        )->execute([$this->withPayrollId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period)
             VALUES (?, \'disabled\', NULL)
             ON DUPLICATE KEY UPDATE status = VALUES(status), start_period = NULL'
        )->execute([$this->disabledModuleId]);
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

    public function testOnlySuppliersWithPayrollAreTargets(): void
    {
        $targets = $this->repository->payrollEnabledSupplierIds();

        self::assertContains($this->withPayrollId, $targets);
        self::assertNotContains($this->withoutPayrollId, $targets);
        self::assertNotContains($this->disabledModuleId, $targets);
    }

    /** Cíle chodí vzestupně, aby dávkování bralo firmy v předvídatelném pořadí. */
    public function testTargetsAreOrdered(): void
    {
        $targets = $this->repository->payrollEnabledSupplierIds();
        $sorted = $targets;
        sort($sorted);

        self::assertSame($sorted, $targets);
    }

    /**
     * Běh omezený na firmu bez mezd se skutečné detekce ani nedotkne — a hlavně
     * kvůli tomu nespadne.
     */
    public function testRunOverSupplierWithoutPayrollDoesNothing(): void
    {
        if (!$this->db->hasTable('payroll_registration_change_proposals')
            || !$this->db->hasTable('payroll_registration_change_scans')
        ) {
            $this->markTestSkipped('Migrace detekce registračních změn neproběhly.');
        }
        $report = $this->runner->run('production', [$this->withoutPayrollId]);

        self::assertSame(0, $report['suppliers']);
        self::assertSame(0, $report['scanned']);
        self::assertSame(0, $report['created']);
        self::assertSame(0, $report['errors']);
        self::assertSame(0, $this->countProposals($this->withoutPayrollId));
    }

    /**
     * Firma se mzdami, která zatím nemá odeslanou žádnou registraci, projde
     * naprázdno — a opakovaný běh ji nezaloží povinnost podruhé. A3 hlásí změnu
     * údaje, který už úřad má; bez odeslané registrace není co měnit.
     */
    public function testRepeatedRunOverPayrollSupplierStaysEmpty(): void
    {
        if (!$this->db->hasTable('payroll_registration_change_proposals')
            || !$this->db->hasTable('payroll_registration_change_scans')
        ) {
            $this->markTestSkipped('Migrace detekce registračních změn neproběhly.');
        }
        $first = $this->runner->run('production', [$this->withPayrollId]);
        $second = $this->runner->run('production', [$this->withPayrollId]);

        self::assertSame(1, $first['suppliers']);
        self::assertSame(0, $first['errors']);
        self::assertSame(0, $second['errors']);
        self::assertSame($first['created'], $second['created']);
        self::assertSame(0, $this->countProposals($this->withPayrollId));
    }

    private function countProposals(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_registration_change_proposals
              WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);

        return (int) $stmt->fetchColumn();
    }
}
