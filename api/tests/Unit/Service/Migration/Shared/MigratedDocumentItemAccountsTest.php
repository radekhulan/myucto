<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\OssMigrationPolicy;
use MyInvoice\Service\Migration\Shared\MigratedDocumentItem;
use MyInvoice\Service\Migration\Shared\MigratedDocumentWriter;
use MyInvoice\Service\Migration\Shared\MigrationVatRateLookup;
use MyInvoice\Service\Migration\StereoNx\StereoNxImporter;
use PHPUnit\Framework\TestCase;

final class MigratedDocumentItemAccountsTest extends TestCase
{
    public function testWriterPreservesConfirmedItemAccountsAndAmounts(): void
    {
        $pdo = new \Pdo\Sqlite('sqlite::memory:');
        $pdo->exec('CREATE TABLE invoice_items (invoice_id INT, description TEXT, quantity NUMERIC,
            unit TEXT, unit_price_without_vat NUMERIC, vat_rate_id INT, vat_rate_snapshot NUMERIC,
            total_without_vat NUMERIC, total_vat NUMERIC, total_with_vat NUMERIC, order_index INT,
            vat_classification_code TEXT, oss_applicable INT, oss_consumer_country TEXT, oss_rate_type TEXT,
            oss_supply_type TEXT, oss_needs_manual_review INT, oss_taxable_amount_return NUMERIC,
            oss_vat_amount_return NUMERIC, revenue_account_code TEXT)');
        $pdo->exec('CREATE TABLE purchase_invoice_items (purchase_invoice_id INT, description TEXT,
            quantity NUMERIC, unit TEXT, unit_price_without_vat NUMERIC, vat_rate_id INT, vat_rate_snapshot NUMERIC,
            total_without_vat NUMERIC, total_vat NUMERIC, total_with_vat NUMERIC, order_index INT,
            vat_classification_code TEXT, is_fixed_asset INT, expense_kind TEXT, expense_account_code TEXT)');
        $db = new Connection(new Config([]));
        (new \ReflectionProperty($db, 'pdo'))->setValue($db, $pdo);
        $writer = new MigratedDocumentWriter($db);
        $pdo->exec('CREATE TABLE vat_rates (id INT, country TEXT, rate_percent NUMERIC,
            is_reverse_charge INT, valid_from TEXT, valid_to TEXT, is_default INT)');
        $pdo->exec("INSERT INTO vat_rates VALUES (1, 'CZ', 21, 0, NULL, NULL, 1)");
        $importer = (new \ReflectionClass(StereoNxImporter::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($importer, 'writer'))->setValue($importer, $writer);
        (new \ReflectionProperty($importer, 'rates'))->setValue($importer, new MigrationVatRateLookup($db));
        $insertItems = new \ReflectionMethod($importer, 'insertItems');
        $item = ['description' => 'Syntetická položka', 'quantity' => 1.0, 'unit' => 'ks',
            'unit_price_without_vat' => 100.0, 'vat_rate_snapshot' => 21.0,
            'total_without_vat' => 100.0, 'total_vat' => 21.0, 'total_with_vat' => 121.0];
        $insertItems->invoke($importer, 'issued', 11, [[...$item, 'vat_classification_code' => '1',
            'revenue_account_code' => '602007', 'target_revenue_account_code' => '602.007']], '2026-03-01');
        $insertItems->invoke($importer, 'purchase', 12, [[...$item, 'vat_classification_code' => '40',
            'expense_account_code' => '518001', 'target_expense_account_code' => '518.001']], '2026-03-01');
        $writer->insertPurchaseItem(13, MigratedDocumentItem::purchase(
            'Syntetické pořízení', 1.0, 'ks', 200.0, 1, 21.0, 200.0, 42.0, 242.0, '40',
            true, 'fixed_asset', expenseAccountCode: '042.003',
        ), 0);

        $issued = $pdo->query('SELECT * FROM invoice_items')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('602.007', $issued['revenue_account_code']);
        self::assertSame(100.0, (float) $issued['total_without_vat']);
        self::assertSame(21.0, (float) $issued['total_vat']);
        self::assertSame(0, (int) $issued['oss_applicable']);
        $purchases = $pdo->query('SELECT * FROM purchase_invoice_items ORDER BY purchase_invoice_id')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame('518.001', $purchases[0]['expense_account_code']);
        self::assertSame('042.003', $purchases[1]['expense_account_code']);
        self::assertSame('fixed_asset', $purchases[1]['expense_kind']);
        self::assertSame(1, (int) $purchases[1]['is_fixed_asset']);
        self::assertSame(242.0, (float) $purchases[1]['total_with_vat']);
    }

    public function testOtherImportersKeepOptionalAccountFieldsEmpty(): void
    {
        $issued = MigratedDocumentItem::issued('Syntetická služba', 1.0, 'ks', 100.0, 1, 21.0,
            100.0, 21.0, 121.0, '1', OssMigrationPolicy::DOMESTIC_COLUMNS);
        $purchase = MigratedDocumentItem::purchase('Syntetický náklad', 1.0, 'ks', 100.0, 1, 21.0,
            100.0, 21.0, 121.0, '40');
        self::assertNull($issued->revenueAccountCode);
        self::assertNull($issued->expenseAccountCode);
        self::assertNull($purchase->revenueAccountCode);
        self::assertNull($purchase->expenseAccountCode);
    }
}
