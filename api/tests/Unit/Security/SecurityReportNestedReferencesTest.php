<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\Invoice\PriceListItemResolver;
use MyInvoice\Service\Invoice\PriceListResolutionException;
use MyInvoice\Service\Invoice\RecurringPriceListService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class SecurityReportNestedReferencesTest extends TestCase
{
    public function testForeignStockLinkCannotDeleteInvoiceItems(): void
    {
        $repo = (new \ReflectionClass(InvoiceRepository::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(InvoiceRepository::class, 'db'))->setValue($repo, $this->writeGuardConnection());
        $this->expectException(\InvalidArgumentException::class);
        $repo->replaceItems(1, [['stock_item_id' => 2]]);
    }

    public function testForeignWarehouseCannotDeleteInvoiceItems(): void
    {
        $repo = (new \ReflectionClass(InvoiceRepository::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(InvoiceRepository::class, 'db'))->setValue($repo, $this->writeGuardConnection());
        $this->expectException(\InvalidArgumentException::class);
        $repo->replaceItems(1, [['warehouse_id' => 2]]);
    }

    public function testForeignCatalogLinkCannotDeleteRecurringItems(): void
    {
        $repo = new RecurringTemplateRepository($this->writeGuardConnection());
        $this->expectException(PriceListResolutionException::class);
        $repo->replaceItems(1, [['price_list_item_id' => 2]]);
    }

    public function testFixedSnapshotCannotBypassCatalogOwnership(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, stock_enabled INTEGER)');
        $pdo->exec('INSERT INTO supplier VALUES (1, 0)');
        $pdo->exec('CREATE TABLE price_list_items (id INTEGER PRIMARY KEY, supplier_id INTEGER)');
        $pdo->exec('INSERT INTO price_list_items VALUES (2, 2)');
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $resolver = (new \ReflectionClass(PriceListItemResolver::class))->newInstanceWithoutConstructor();
        $service = new RecurringPriceListService($resolver, $db);
        $this->expectException(PriceListResolutionException::class);
        $service->prepareForSave([
            ['price_list_item_id' => 2, 'catalog_policy' => 'fixed', 'catalog_price_source' => 'base'],
        ], 1, 1, 1, false, new \DateTimeImmutable('2099-01-01'), false);
    }

    private function writeGuardConnection(): Connection
    {
        $owner = $this->createStub(PDOStatement::class);
        $owner->method('execute')->willReturn(true);
        $owner->method('fetchColumn')->willReturn(1);
        $foreign = $this->createStub(PDOStatement::class);
        $foreign->method('execute')->willReturn(true);
        $foreign->method('fetchAll')->willReturn([]);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(static function (string $sql) use ($owner, $foreign): PDOStatement {
            self::assertStringStartsWith('SELECT ', $sql, 'Neplatná vazba nesmí smazat ani změnit původní položky.');
            return str_starts_with($sql, 'SELECT supplier_id') ? $owner : $foreign;
        });
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        return $db;
    }
}
