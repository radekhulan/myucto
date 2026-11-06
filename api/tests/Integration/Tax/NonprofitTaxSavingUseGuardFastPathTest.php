<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingUseGuard;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Hlídač použití daňové úspory běží při každé změně deníku. U firmy bez aktivního
 * použití úspory nesmí zamykat období ani ověřovat uložené důkazy (XSD, DI kontejner).
 */
final class NonprofitTaxSavingUseGuardFastPathTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testSupplierWithoutSavingUseCostsOneIndexedQueryAndTakesNoLocks(): void
    {
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $period = (new AccountingPeriodRepository($this->db))->create($supplier, 2026, '2026-01-01', '2026-12-31');
        $this->pdo->prepare("INSERT INTO journal_entries(supplier_id,period_id,entry_date,source_type) VALUES(?,?,'2026-02-01','manual')")
            ->execute([$supplier, $period]);
        $entry = (int) $this->pdo->lastInsertId();
        $guard = new NonprofitTaxSavingUseGuard($this->db);
        $lockedBefore = $this->rowsLocked();

        $overhead = $this->questions(fn () => self::assertFalse($guard->assertEntryChange($supplier, $entry, [], '2026-02-01')));
        self::assertLessThanOrEqual(2, $overhead);
        $overhead = $this->questions(function () use ($guard, $supplier, $entry): void {
            $guard->assertEntriesMutable($supplier, [$entry]);
            $guard->assertLinesMutable($supplier, [1, 2, 3]);
        });
        self::assertLessThanOrEqual(2, $overhead);
        self::assertSame($lockedBefore, $this->rowsLocked());
    }

    public function testFastPathUsesTheCompositeBindingIndex(): void
    {
        $plan = $this->pdo->prepare('EXPLAIN SELECT 1 FROM nonprofit_tax_saving_cost_bindings b
            JOIN journal_entry_lines l ON l.supplier_id=b.supplier_id AND l.id=b.cost_line_id
            WHERE b.supplier_id=? AND l.entry_id=? LIMIT 1');
        $plan->execute([1, 1]);
        foreach ($plan->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['table'] === 'b') self::assertNotSame('ALL', $row['type']);
            if ($row['table'] === 'l') self::assertContains($row['type'], ['eq_ref', 'ref', 'const']);
        }
    }

    /** Počet příkazů odeslaných serveru během $work nad rámec samotného měření. */
    private function questions(callable $work): int
    {
        $baseline = $this->questionCount();
        $calibration = $this->questionCount() - $baseline;
        $before = $this->questionCount();
        $work();
        return $this->questionCount() - $before - $calibration;
    }

    private function questionCount(): int
    {
        return (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
    }

    private function rowsLocked(): int
    {
        $value = $this->pdo->query('SELECT trx_rows_locked FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=CONNECTION_ID()')
            ->fetchColumn();
        return $value === false ? 0 : (int) $value;
    }
}
