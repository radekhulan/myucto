<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Crm;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Crm\CrmAggregationService;
use PDO;
use PHPUnit\Framework\TestCase;

final class BankSyncActionItemsTest extends TestCase
{
    public function testBlockedConnectionsAreTenantScopedAndDisappearAfterSuccess(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE currencies (id INTEGER, supplier_id INTEGER, label TEXT, code TEXT, is_active INTEGER)');
        $pdo->exec('CREATE TABLE bank_connections (id INTEGER, supplier_id INTEGER, currency_id INTEGER, enabled INTEGER, token_ciphertext TEXT, last_sync_status TEXT, last_sync_error_code TEXT)');
        $pdo->exec("INSERT INTO currencies VALUES (1, 10, 'Test current', 'CZK', 1), (2, 10, 'Test savings', 'CZK', 1), (3, 20, 'Other tenant', 'CZK', 1), (4, 10, 'Inactive', 'CZK', 0)");
        $pdo->exec("INSERT INTO bank_connections VALUES (11, 10, 1, 1, 'synthetic', 'error', 'statement_reconciliation_required'), (12, 10, 2, 1, 'synthetic', 'error', 'bank_sync_failed'), (13, 20, 3, 1, 'synthetic', 'error', 'statement_reconciliation_required'), (14, 10, 4, 1, 'synthetic', 'error', 'bank_sync_failed')");
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $service = new CrmAggregationService($db);
        self::assertSame([], $service->bankSyncActionItems(10, false));
        $items = $service->bankSyncActionItems(10, true);
        self::assertCount(2, $items);
        self::assertSame('/bank?tab=accounts&currency_id=1', $items[0]['link']);
        self::assertSame('crm.action_items.bank_sync_reconciliation', $items[0]['hint_key']);
        self::assertSame('high', $items[0]['severity']);
        self::assertFalse($items[0]['dismissible']);
        self::assertSame('Test current (CZK)', $items[0]['title']);
        $pdo->exec("UPDATE bank_connections SET last_sync_status = 'success', last_sync_error_code = NULL WHERE id = 11");
        self::assertCount(1, $service->bankSyncActionItems(10, true));
        $pdo->exec('UPDATE bank_connections SET enabled = 0 WHERE id = 12');
        self::assertSame([], $service->bankSyncActionItems(10, true));
        $pdo->exec('UPDATE bank_connections SET enabled = 1, token_ciphertext = NULL WHERE id = 12');
        self::assertSame([], $service->bankSyncActionItems(10, true));
    }
}
