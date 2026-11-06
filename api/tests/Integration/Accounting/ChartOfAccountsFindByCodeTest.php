<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hledání účtu podle kódu musí u firmy na rámci 500 zůstat stejné jako před historií
 * definic účtů: kód porovnává databáze podle collation sloupce, takže analytika
 * převzatá s písmeny (`311.KB`) se najde i podle `311.kb` nebo s mezerou na konci.
 */
#[Group('integration')]
final class ChartOfAccountsFindByCodeTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ChartOfAccountsRepository $chart;
    private int $supplier = 0;
    private int $accountId = 0;

    protected function setUp(): void
    {
        self::assertStringEndsWith('_test', (string) getenv('MYINVOICE_DB_NAME'));
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->chart = $container->get(ChartOfAccountsRepository::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        $seed = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier = $this->createIsolatedSupplier($pdo, $seed);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplier]);
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier, '2025-01-01');

        $parent = $this->chart->findByCode($this->supplier, '311');
        self::assertNotNull($parent);
        $this->accountId = $this->chart->insert($this->supplier, [
            'account_code' => '311.KB',
            'name' => 'Synthetic analytic with letters',
            'account_type' => 'asset',
            'normal_side' => 'debit',
            'is_synthetic' => false,
            'parent_id' => $parent['id'],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testCodeLookupKeepsColumnCollationSemantics(): void
    {
        foreach (['311.KB', '311.kb', '311.KB '] as $code) {
            $row = $this->chart->findByCode($this->supplier, $code);
            self::assertNotNull($row, "Účet se má najít i podle kódu '{$code}'.");
            self::assertSame($this->accountId, $row['id']);
            self::assertSame('311.KB', $row['account_code']);
        }
        self::assertNull($this->chart->findByCode($this->supplier, '311.KC'));
    }

    public function testCodeLookupReturnsSameShapeAsListing(): void
    {
        $listed = array_column($this->chart->listForTenant($this->supplier, true), null, 'account_code');
        self::assertSame($listed['311.KB'], $this->chart->findByCode($this->supplier, '311.KB'));
        self::assertSame($listed['311'], $this->chart->findByCode($this->supplier, '311', '2025-06-30'));
    }
}
