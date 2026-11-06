<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Deletion;

use MyInvoice\Action\Invoice\DeleteInvoiceAction;
use MyInvoice\Action\Project\DeleteProjectAction;
use MyInvoice\Action\Stock\StockItemAction;
use MyInvoice\Action\Stock\WarehouseAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartAccountUsage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Mazání projde, pokud mu nebrání skutečný důvod (uzamčené období, data, která bez záznamu
 * nedávají smysl). Pouhé odkazy se uvolní, skutečné bloky vrátí 409 s vysvětlením, nikdy 500.
 * Vše v transakci s rollbackem, data jsou syntetická.
 */
#[Group('integration')]
final class DeleteReleasesReferencesTest extends TestCase
{
    private ContainerInterface $container;
    private Connection $db;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $czkId = 0;
    private int $clientId = 0;
    private string $date = '';

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();

        $this->supplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId     = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $czId             = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $this->czkId      = (int) $pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' LIMIT 1")->fetchColumn();
        if ($this->supplierId === 0 || $this->userId === 0 || $czId === 0 || $this->czkId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $year = (int) date('Y');
        $this->date = $year . '-03-10';

        $pdo->beginTransaction();

        $periods = $this->container->get(AccountingPeriodRepository::class);
        if ($periods->findByYear($this->supplierId, $year) === null) {
            $periods->create($this->supplierId, $year, $year . '-01-01', $year . '-12-31');
        }
        $pdo->prepare('UPDATE accounting_supplier_settings SET locked_until = NULL WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('UPDATE supplier SET stock_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);

        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_vendor, is_customer, is_vat_payer)
             VALUES (?, "Vazby partner s.r.o. (PHPUnit)", "Testovaci 1", "Praha", "11000", ?,
                     "vazby@example.test", "cs", ?, 1, 1, 1)'
        )->execute([$this->supplierId, $czId, $this->czkId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testProjectDeleteDetachesInvoicesAndWorkReports(): void
    {
        $project = $this->project();
        $invoice = $this->invoice('draft', $project);
        // Výkaz zůstává na zakázce i po přesunu své faktury na jinou zakázku.
        $this->db->pdo()->prepare('INSERT INTO work_reports (invoice_id, project_id, title) VALUES (?, ?, "Výkaz")')
            ->execute([$this->invoice('draft', null), $project]);
        $report = (int) $this->db->pdo()->lastInsertId();

        $res = $this->invoke(DeleteProjectAction::class, null, $project);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertFalse($this->exists('projects', $project));
        self::assertNull($this->value('invoices', 'project_id', $invoice), 'Faktura zůstává bez zakázky.');
        self::assertNull($this->value('work_reports', 'project_id', $report));
    }

    public function testProjectDeleteRefusedForInvoiceBehindLockedDate(): void
    {
        $project = $this->project();
        $invoice = $this->invoice('issued', $project);
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$this->supplierId, $this->date]);

        $res = $this->invoke(DeleteProjectAction::class, null, $project);

        self::assertSame(409, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('has_locked_invoices', $res['body']['error']['code']);
        self::assertTrue($this->exists('projects', $project));
        self::assertSame($project, (int) $this->value('invoices', 'project_id', $invoice));
    }

    public function testStockItemInStockTakeRefusedWithConflictNotServerError(): void
    {
        $item = $this->stockItem();
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO stock_takes (supplier_id, warehouse_id, take_date) VALUES (?, ?, ?)')
            ->execute([$this->supplierId, $this->warehouse(), $this->date]);
        $pdo->prepare('INSERT INTO stock_take_lines (stock_take_id, supplier_id, stock_item_id) VALUES (?, ?, ?)')
            ->execute([(int) $pdo->lastInsertId(), $this->supplierId, $item]);

        $res = $this->invoke(StockItemAction::class, 'delete', $item);

        self::assertSame(409, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('item_in_use', $res['body']['error']['code']);
        self::assertTrue($this->exists('stock_items', $item));
    }

    public function testStockItemDeleteReleasesOrderLine(): void
    {
        $warehouse = $this->warehouse();
        $item = $this->stockItem();
        $line = $this->purchaseOrderLine($warehouse, $item, null);

        $res = $this->invoke(StockItemAction::class, 'delete', $item);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertFalse($this->exists('stock_items', $item));
        self::assertNull($this->value('purchase_order_lines', 'stock_item_id', $line));
    }

    public function testWarehouseDeleteReleasesOptionalLineReference(): void
    {
        $main = $this->warehouse();
        $other = $this->warehouse();
        $line = $this->purchaseOrderLine($main, null, $other);

        $res = $this->invoke(WarehouseAction::class, 'delete', $other);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertFalse($this->exists('warehouses', $other));
        self::assertNull($this->value('purchase_order_lines', 'warehouse_id', $line));
    }

    public function testWarehouseOfOrderHeaderRefusedWithConflictNotServerError(): void
    {
        $warehouse = $this->warehouse();
        $this->purchaseOrderLine($warehouse, null, null);

        $res = $this->invoke(WarehouseAction::class, 'delete', $warehouse);

        self::assertSame(409, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('warehouse_in_use', $res['body']['error']['code']);
        self::assertTrue($this->exists('warehouses', $warehouse));
    }

    public function testIssuedInvoiceInPaymentOrderCanBeForceDeletedAndOrderKeepsItem(): void
    {
        $invoice = $this->invoice('draft', null);
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payment_orders (supplier_id, payment_date) VALUES (?, ?)')->execute([$this->supplierId, $this->date]);
        $order = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payment_order_items (payment_order_id, invoice_id, amount) VALUES (?, ?, 100)')->execute([$order, $invoice]);
        $item = (int) $pdo->lastInsertId();

        $res = $this->invoke(DeleteInvoiceAction::class, null, $invoice, ['force' => '1']);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertFalse($this->exists('invoices', $invoice));
        self::assertTrue($this->exists('payment_order_items', $item), 'Záznam pokynu pro banku zůstává.');
    }

    public function testAccountUsageNamesGoPaySettingsAndSettlements(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type) VALUES (?, "518.777", "Analytika", "expense")')
            ->execute([$this->supplierId]);
        $account = (int) $pdo->lastInsertId();
        $usage = $this->container->get(ChartAccountUsage::class);
        self::assertSame([], $usage->usages($this->supplierId, $account, '518.777'));

        $pdo->prepare('INSERT INTO gopay_settings (supplier_id, fee_account_id) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE fee_account_id = VALUES(fee_account_id)')->execute([$this->supplierId, $account]);

        self::assertContains('nastavení GoPay', $usage->usages($this->supplierId, $account, '518.777'));
    }

    private function project(): int
    {
        $this->db->pdo()->prepare('INSERT INTO projects (client_id, name, currency_id) VALUES (?, "Zakázka", ?)')
            ->execute([$this->clientId, $this->czkId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function invoice(string $status, ?int $project): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, project_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat, paid_total, status,
                 vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, ?, 0, 100, 0, 100, 0, ?, "1", ?)'
        )->execute([
            $this->supplierId, 'VZ' . random_int(10000000, 99999999), $this->clientId, $project,
            $this->date, $this->date, $this->date, $this->czkId, $status, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function warehouse(): int
    {
        $this->db->pdo()->prepare('INSERT INTO warehouses (supplier_id, code, name) VALUES (?, ?, "Sklad (PHPUnit)")')
            ->execute([$this->supplierId, 'T' . bin2hex(random_bytes(4))]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function stockItem(): int
    {
        $this->db->pdo()->prepare('INSERT INTO stock_items (supplier_id, sku, name) VALUES (?, ?, "Karta (PHPUnit)")')
            ->execute([$this->supplierId, 'T' . bin2hex(random_bytes(4))]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchaseOrderLine(int $orderWarehouse, ?int $item, ?int $lineWarehouse): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO purchase_orders (supplier_id, vendor_id, order_date, warehouse_id, currency_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$this->supplierId, $this->clientId, $this->date, $orderWarehouse, $this->czkId]);
        $order = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO purchase_order_lines (order_id, supplier_id, description, qty_ordered, stock_item_id, warehouse_id) VALUES (?, ?, "Řádek", 1, ?, ?)')
            ->execute([$order, $this->supplierId, $item, $lineWarehouse]);
        return (int) $pdo->lastInsertId();
    }

    private function exists(string $table, int $id): bool
    {
        return $this->db->pdo()->query("SELECT 1 FROM $table WHERE id = $id")->fetchColumn() !== false;
    }

    private function value(string $table, string $column, int $id): mixed
    {
        return $this->db->pdo()->query("SELECT $column FROM $table WHERE id = $id")->fetchColumn();
    }

    /**
     * @param class-string $class
     * @param array<string,string> $query
     * @return array{status:int, body:array<string,mixed>}
     */
    private function invoke(string $class, ?string $method, int $id, array $query = []): array
    {
        $action = $this->container->get($class);
        $req = (new ServerRequestFactory())
            ->createServerRequest('DELETE', '/api/test/' . $id)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withQueryParams($query);
        $args = ['id' => (string) $id];
        $response = $method === null ? $action($req, new Psr7Response(), $args) : $action->{$method}($req, new Psr7Response(), $args);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
