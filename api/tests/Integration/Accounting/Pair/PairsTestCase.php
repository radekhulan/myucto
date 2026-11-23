<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\PairedJournalAssertions;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Izolovaná firma s osnovou, obdobím a jednoduchou formou zápisu od 1. 1. roku.
 * Vše v transakci, tearDown ji vrátí.
 */
abstract class PairsTestCase extends TestCase
{
    use IsolatedSupplierTrait;
    use PairedJournalAssertions;

    protected const YEAR = 2099;

    protected ContainerInterface $container;
    protected Connection $db;
    protected PostingService $posting;
    protected int $supplierId = 0;
    protected int $userId = 0;
    protected int $currencyId = 0;
    protected int $czId = 0;
    protected int $periodId = 0;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->posting = $this->container->get(PostingService::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $this->currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $this->czId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->periodId = $this->container->get(AccountingPeriodRepository::class)
            ->create($this->supplierId, static::YEAR, static::YEAR . '-01-01', static::YEAR . '-12-31');
        $this->enableSingleEntryForm($pdo, $this->supplierId, static::YEAR . '-01-01');
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

    protected function client(string $name): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "dvojice@example.invalid", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, $name, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    protected function vatRateId(int $percent): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM vat_rates WHERE rate_percent = ? ORDER BY id LIMIT 1');
        $stmt->execute([$percent]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0) {
            self::markTestSkipped('V testovací DB chybí sazba DPH ' . $percent . ' %.');
        }
        return $id;
    }
}
