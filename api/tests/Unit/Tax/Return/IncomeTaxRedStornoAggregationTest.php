<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Return\DpfoReturnDataProvider;
use MyInvoice\Service\Tax\Return\DppoReturnDataProvider;
use MyInvoice\Service\Tax\Return\NonDeductibleCostsService;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class IncomeTaxRedStornoAggregationTest extends TestCase
{
    private PDO $pdo;
    private Connection $db;
    private NonDeductibleCostsService $nonDeductibleCosts;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE chart_of_accounts (
            id INTEGER PRIMARY KEY,
            account_code TEXT,
            account_type TEXT,
            tax_deductibility TEXT,
            name TEXT
        )');
        $this->pdo->exec('CREATE TABLE journal_entries (
            id INTEGER PRIMARY KEY,
            supplier_id INTEGER,
            entry_date TEXT,
            source_type TEXT,
            source_id INTEGER,
            posted_at TEXT,
            reversed_by INTEGER
        )');
        $this->pdo->exec('CREATE TABLE journal_entry_lines (
            id INTEGER PRIMARY KEY,
            supplier_id INTEGER,
            entry_id INTEGER,
            account_id INTEGER,
            side TEXT,
            amount REAL,
            is_red_storno INTEGER NOT NULL DEFAULT 0,
            signed_amount REAL GENERATED ALWAYS AS (
                CASE WHEN is_red_storno = 1 THEN -amount ELSE amount END
            ) VIRTUAL
        )');
        $this->pdo->exec('CREATE TABLE purchase_invoices (
            id INTEGER PRIMARY KEY,
            supplier_id INTEGER,
            tax_deductible INTEGER
        )');

        $config = $this->createStub(\MyInvoice\Infrastructure\Config\Config::class);
        \MyInvoice\Tests\Support\AccountDefinitionSqliteFixture::install($this->pdo);
        $this->db = new Connection($config);
        (new ReflectionClass($this->db))->getProperty('pdo')->setValue($this->db, $this->pdo);
        $this->nonDeductibleCosts = new NonDeductibleCostsService($this->db);
    }

    public function testRedStornoReducesProfitAndNonDeductibleCostsInDppoAndDpfo(): void
    {
        $this->pdo->exec("INSERT INTO chart_of_accounts (id, account_code, account_type, tax_deductibility, name) VALUES
            (1,'518','expense','non_deductible','Ostatní služby'),
            (2,'602','revenue','deductible','Tržby')");

        $this->entry(1, 1, 'debit', 100.0, false);
        $this->entry(2, 1, 'debit', 20.0, true);
        $this->entry(3, 2, 'credit', 300.0, false);
        $this->entry(4, 2, 'credit', 50.0, true);

        $dppo = (new ReflectionClass(DppoReturnDataProvider::class))->newInstanceWithoutConstructor();
        $this->setProperty($dppo, 'db', $this->db);
        $this->setProperty($dppo, 'nonDeductibleCostsService', $this->nonDeductibleCosts);

        self::assertSame(170.0, $this->invoke($dppo, 'profitBeforeTax', [1, '2025-01-01', '2025-12-31']));
        self::assertSame(80.0, $this->invoke($dppo, 'nonDeductibleCosts', [1, '2025-01-01', '2025-12-31']));

        $dpfo = (new ReflectionClass(DpfoReturnDataProvider::class))->newInstanceWithoutConstructor();
        $this->setProperty($dpfo, 'db', $this->db);
        $this->setProperty($dpfo, 'nonDeductibleCostsService', $this->nonDeductibleCosts);

        [$revenues, $expenses] = $this->invoke($dpfo, 'vhBase', [1, 2025]);
        self::assertSame(250.0, $revenues);
        self::assertSame(0.0, $expenses);
    }

    private function entry(int $id, int $accountId, string $side, float $amount, bool $redStorno): void
    {
        $this->pdo->prepare(
            "INSERT INTO journal_entries (id, supplier_id, entry_date, source_type, source_id, posted_at, reversed_by)
             VALUES (?,1,'2025-06-30','manual',NULL,'2025-06-30',NULL)"
        )->execute([$id]);
        $this->pdo->prepare(
            'INSERT INTO journal_entry_lines
                (id, supplier_id, entry_id, account_id, side, amount, is_red_storno)
             VALUES (?,1,?,?,?,?,?)'
        )->execute([$id, $id, $accountId, $side, $amount, $redStorno ? 1 : 0]);
    }

    private function setProperty(object $object, string $property, mixed $value): void
    {
        (new ReflectionClass($object))->getProperty($property)->setValue($object, $value);
    }

    /** @param list<mixed> $arguments */
    private function invoke(object $object, string $method, array $arguments): mixed
    {
        return (new ReflectionClass($object))->getMethod($method)->invokeArgs($object, $arguments);
    }
}
