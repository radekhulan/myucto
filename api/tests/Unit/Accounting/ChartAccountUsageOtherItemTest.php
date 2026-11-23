<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Accounting\ChartAccountUsage;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;

final class ChartAccountUsageOtherItemTest extends TestCase
{
    public function testLegacyOtherItemAccountsBlockDeletionOnlyForTheirSupplier(): void
    {
        $pdo = new Sqlite('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->createFunction('DATABASE', static fn (): string => 'main');
        $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $pdo->exec('CREATE TABLE information_schema.tables (table_schema TEXT, table_name TEXT)');
        $pdo->exec("INSERT INTO information_schema.tables VALUES ('main', 'other_items')");
        $pdo->exec('CREATE TABLE journal_entries (id INTEGER, supplier_id INTEGER)');
        $pdo->exec('CREATE TABLE journal_entry_lines (entry_id INTEGER, account_id INTEGER)');
        $pdo->exec('CREATE TABLE chart_of_accounts (supplier_id INTEGER, parent_id INTEGER)');
        $pdo->exec('CREATE TABLE journal_entry_templates (id INTEGER, supplier_id INTEGER)');
        $pdo->exec('CREATE TABLE journal_entry_template_lines (template_id INTEGER, account_code TEXT)');
        $pdo->exec('CREATE TABLE purchase_invoices (id INTEGER, supplier_id INTEGER)');
        $pdo->exec('CREATE TABLE purchase_invoice_items (purchase_invoice_id INTEGER, expense_account_code TEXT, counter_account_code TEXT)');
        $pdo->exec('CREATE TABLE invoices (id INTEGER, supplier_id INTEGER)');
        $pdo->exec('CREATE TABLE invoice_items (invoice_id INTEGER, counter_account_code TEXT)');
        $pdo->exec('CREATE TABLE other_items (supplier_id INTEGER, account_code TEXT, counter_account_code TEXT)');
        $pdo->exec("INSERT INTO other_items VALUES (7, '315.987', '518.987'), (8, '315.986', '518.986')");

        $db = new Connection(new Config([]));
        (new \ReflectionClass($db))->getProperty('pdo')->setValue($db, $pdo);
        $usage = new ChartAccountUsage($db);

        self::assertSame(['ostatní pohledávky a závazky'], $usage->usages(7, 1, '315.987'));
        self::assertSame(['ostatní pohledávky a závazky'], $usage->usages(7, 2, '518.987'));
        self::assertSame([], $usage->usages(8, 1, '315.987'));
    }
}
