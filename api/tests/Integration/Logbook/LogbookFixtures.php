<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use Psr\Container\ContainerInterface;

/**
 * Společný základ integračních testů knihy jízd: dvě izolované firmy (A, B) uvnitř
 * transakce, kterou tearDown zahodí, a syntetické vozidlo / pokladní doklad / výpis.
 */
trait LogbookFixtures
{
    use IsolatedSupplierTrait;

    private ContainerInterface $container;
    private Connection $db;
    private PDO $pdo;
    private int $supplierA = 0;
    private int $supplierB = 0;
    private int $userId = 0;

    private function bootLogbook(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->container = Bootstrap::buildApp()->getContainer();
            $this->db = $this->container->get(Connection::class);
            $this->pdo = $this->db->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $source = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Testovací DB nemá žádnou firmu.');
        }
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->pdo->beginTransaction();
        $this->supplierA = $this->createIsolatedSupplier($this->pdo, $source);
        $this->supplierB = $this->createIsolatedSupplier($this->pdo, $source);
    }

    private function shutdownLogbook(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    private function car(int $supplierId, string $registration, bool $default = false, ?string $name = null): int
    {
        $this->pdo->prepare('INSERT INTO cars (supplier_id, registration, name, is_default) VALUES (?, ?, ?, ?)')
            ->execute([$supplierId, $registration, $name, $default ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @var array<int,int> supplier → cash_registers.id (název i účet jsou per firma unikátní) */
    private array $registers = [];
    private int $docSeq = 0;

    private function cashRegister(int $supplierId): int
    {
        if (!isset($this->registers[$supplierId])) {
            $this->pdo->prepare("INSERT INTO cash_registers (supplier_id, name, account_code) VALUES (?, 'Testovací pokladna', '211')")
                ->execute([$supplierId]);
            $this->registers[$supplierId] = (int) $this->pdo->lastInsertId();
        }
        return $this->registers[$supplierId];
    }

    private function cashDocument(int $supplierId, string $description, float $total, array $extra = []): int
    {
        $register = $this->cashRegister($supplierId);
        $row = $extra + [
            'purpose' => 'purchase', 'status' => 'posted', 'issue_date' => '2099-03-05',
            'partner_name' => null, 'partner_ic' => null, 'doc_number' => 'VPD-T-' . (++$this->docSeq),
        ];
        $this->pdo->prepare(
            "INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, doc_number, issue_date,
                                         partner_name, partner_ic, description, total_amount, status)
             VALUES (?, ?, 'out', ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$supplierId, $register, $row['purpose'], $row['doc_number'], $row['issue_date'],
            $row['partner_name'], $row['partner_ic'], $description, $total, $row['status']]);
        return (int) $this->pdo->lastInsertId();
    }

    private function bankTransaction(int $supplierId, float $amount, string $date = '2099-03-05'): int
    {
        $this->pdo->prepare(
            "INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, currency, statement_date)
             VALUES (?, 'test.gpc', ?, '1000000005', 'CZK', ?)"
        )->execute([$supplierId, hash('sha256', uniqid('bs', true)), $date]);
        $statement = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, counterparty_name, description)
             VALUES (?, ?, ?, 'CZK', 'Testovací čerpací stanice', 'Platba kartou')"
        )->execute([$statement, $date, $amount]);
        return (int) $this->pdo->lastInsertId();
    }

    private function fuelStationClient(int $supplierId, string $name, string $ic): int
    {
        $stmt = $this->pdo->prepare('SELECT MIN(id) FROM currencies WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $currencyId = (int) ($stmt->fetchColumn() ?: 0);
        if ($currencyId === 0) {
            $this->pdo->prepare(
                "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                 VALUES (?, 'CZK', 'CZK', 'Kč', 'Koruna', 'Koruna', 2, 1, 1)"
            )->execute([$supplierId]);
            $currencyId = (int) $this->pdo->lastInsertId();
        }
        $this->pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, ic, street, city, zip, country_id, currency_default_id, is_vendor, is_fuel_station)
             VALUES (?, ?, ?, 'Testovací 1', 'Praha', '11000', (SELECT MIN(id) FROM countries), ?, 1, 1)"
        )->execute([$supplierId, $name, $ic, $currencyId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function employee(int $supplierId, string $name): int
    {
        $this->pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name) VALUES (?, ?)')
            ->execute([$supplierId, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    private function fuelingCount(int $supplierId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM fuelings WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }
}
