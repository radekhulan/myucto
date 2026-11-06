<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\PristineChart500;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Výměna nedotčené osnovy 500 smí proběhnout jen tehdy, když ví o každém místě,
 * které na osnovu firmy odkazuje. Nový cizí klíč na chart_of_accounts nebo nový
 * sloupec s kódem účtu musí do seznamů v {@see PristineChart500}.
 */
#[Group('integration')]
final class PristineChart500CoverageTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
    }

    public function testEveryForeignKeyToChartIsChecked(): void
    {
        $stmt = $this->db->pdo()->query(
            "SELECT k.TABLE_NAME, k.COLUMN_NAME
               FROM information_schema.KEY_COLUMN_USAGE k
              WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = 'chart_of_accounts'
                AND k.REFERENCED_COLUMN_NAME = 'id'"
        );
        $missing = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$table, $column]) {
            if ($table === 'chart_of_accounts' && $column === 'parent_id') continue;
            if (!in_array($column, PristineChart500::ACCOUNT_ID_REFERENCES[$table] ?? [], true)) {
                $missing[] = $table . '.' . $column;
            }
        }
        self::assertSame([], $missing);
    }

    public function testEveryAccountCodeColumnIsChecked(): void
    {
        $stmt = $this->db->pdo()->query(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME LIKE '%account\\_code'"
        );
        $known = PristineChart500::NOT_CHART_REFERENCES;
        foreach (PristineChart500::ACCOUNT_CODE_REFERENCES as $table => $columns) {
            foreach ($columns as $column) $known[] = $table . '.' . $column;
        }
        foreach (PristineChart500::CHILD_CODE_REFERENCES as $table => [$column]) {
            $known[] = $table . '.' . $column;
        }
        foreach (PristineChart500::SUPPLIER_CODE_REFERENCES as $column) {
            $known[] = 'supplier.' . $column;
        }
        $missing = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$table, $column]) {
            if (!in_array($table . '.' . $column, $known, true)) $missing[] = $table . '.' . $column;
        }
        self::assertSame([], $missing);
    }
}
