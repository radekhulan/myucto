<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\LoggingPdo;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Mapa přesměru na jedinou analytiku čtená aktuálním čtením se v jedné transakci
 * počítá jen jednou (zaúčtování dokladu ji jinak dotazuje opakovaně), ale změnu
 * osnovy vlastní transakcí i návrat k savepointu vidí hned.
 */
#[Group('integration')]
final class SingleAnalyticCurrentReadMemoTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const DATE = '2026-03-01';

    private Connection $db;
    private PostingService $posting;
    private int $supplier;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        self::assertInstanceOf(LoggingPdo::class, $pdo);
        $pdo->beginTransaction();
        $this->supplier = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->supplier, '2026-01-01');
        $this->posting = $container->get(PostingService::class);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testRepeatedCurrentReadInOneTransactionDoesNotQueryAgainButSeesOwnChanges(): void
    {
        [$first, $second] = $this->syntheticsWithoutAnalytics(2);
        $map = $this->posting->singleAnalyticRedirects($this->supplier, self::DATE, true);
        self::assertArrayNotHasKey($first, $map);

        self::assertSame(0, $this->queriesDuring(fn () => $this->posting->singleAnalyticRedirects($this->supplier, self::DATE, true)),
            'Opakované aktuální čtení mapy v téže transakci bez změny osnovy nemá posílat dotazy.');

        $this->addAnalytic($first);
        self::assertSame($first . '.100', $this->posting->singleAnalyticRedirects($this->supplier, self::DATE, true)[$first] ?? null);

        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT single_analytic_memo');
        $this->addAnalytic($second);
        self::assertSame($second . '.100', $this->posting->singleAnalyticRedirects($this->supplier, self::DATE, true)[$second] ?? null);
        $pdo->exec('ROLLBACK TO SAVEPOINT single_analytic_memo');
        $map = $this->posting->singleAnalyticRedirects($this->supplier, self::DATE, true);
        self::assertArrayNotHasKey($second, $map);
        self::assertSame($first . '.100', $map[$first] ?? null);
    }

    /** @return list<string> syntetiky bez analytik, které přesměr nevylučuje */
    private function syntheticsWithoutAnalytics(int $count): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT p.account_code FROM chart_of_accounts p
              WHERE p.supplier_id = ? AND p.is_active = 1 AND p.account_code REGEXP '^5[0-9]{2}$'
                AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c WHERE c.supplier_id = p.supplier_id AND c.parent_id = p.id)
              ORDER BY p.account_code LIMIT {$count}"
        );
        $stmt->execute([$this->supplier]);
        $codes = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount($count, $codes);
        return array_map('strval', $codes);
    }

    private function addAnalytic(string $synthetic): void
    {
        $chart = new ChartOfAccountsRepository($this->db);
        $parent = $chart->findByCode($this->supplier, $synthetic);
        $chart->insert($this->supplier, ['account_code' => $synthetic . '.100', 'name' => 'Synthetic analytic', 'account_type' => 'expense', 'normal_side' => 'debit',
            'parent_id' => (int) $parent['id'], 'tax_deductibility' => 'deductible']);
    }

    private function queriesDuring(callable $work): int
    {
        $pdo = $this->db->pdo();
        $count = static fn (): int => (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_NUM)[1];
        $before = $count();
        $work();
        return $count() - $before - 1;
    }
}
