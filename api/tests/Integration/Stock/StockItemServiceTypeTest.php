<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Service\Stock\PurchaseOrderService;
use MyInvoice\Service\Stock\StockDocumentService;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockIssueService;
use MyInvoice\Service\Stock\StockReceiptService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Karta typu služba zásobu nevede: faktura z ní nic nevydá, příjemka ji nenabídne, skladový
 * doklad ji odmítne a na objednávce zůstane řádkem bez karty. Ostatní karty beze změny, to
 * hlídá kontrolní případ se zbožím. Vše v transakci s rollbackem, data jsou syntetická.
 */
#[Group('integration')]
final class StockItemServiceTypeTest extends TestCase
{
    private ContainerInterface $container;
    private Connection $db;
    private StockItemRepository $items;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $czkId = 0;
    private int $vatRateId = 0;
    private int $clientId = 0;
    private int $warehouseId = 0;
    private string $date = '';

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->items = $this->container->get(StockItemRepository::class);
        $pdo = $this->db->pdo();

        $this->supplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId     = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->vatRateId  = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $czId             = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $this->czkId      = (int) $pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' LIMIT 1")->fetchColumn();
        if ($this->supplierId === 0 || $this->userId === 0 || $this->vatRateId === 0 || $czId === 0 || $this->czkId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $this->date = date('Y') . '-03-10';

        $pdo->beginTransaction();
        $pdo->prepare('UPDATE supplier SET stock_enabled = 1, stock_auto_issue = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_vendor, is_customer, is_vat_payer)
             VALUES (?, "Služby partner s.r.o. (PHPUnit)", "Testovaci 1", "Praha", "11000", ?,
                     "sluzby@example.test", "cs", ?, 1, 1, 1)'
        )->execute([$this->supplierId, $czId, $this->czkId]);
        $this->clientId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO warehouses (supplier_id, code, name) VALUES (?, ?, "Sklad (PHPUnit)")')
            ->execute([$this->supplierId, 'T' . bin2hex(random_bytes(4))]);
        $this->warehouseId = (int) $pdo->lastInsertId();
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

    public function testServiceCardKeepsNoStockWhateverTheEditorSends(): void
    {
        $id = $this->card('service');
        self::assertFalse((bool) $this->items->find($this->supplierId, $id)['is_stocked']);

        $this->items->updateEshopFields($this->supplierId, $id, ['is_stocked' => true]);
        self::assertFalse((bool) $this->items->find($this->supplierId, $id)['is_stocked'], 'Volba z e-shopové části službu nepřepne.');

        $item = $this->items->find($this->supplierId, $id);
        $this->items->update($this->supplierId, $id, ['item_type' => 'goods', 'sku' => $item['sku'], 'name' => $item['name']]);
        self::assertTrue((bool) $this->items->find($this->supplierId, $id)['is_stocked'], 'Návrat na zboží evidenci zapne.');

        $this->items->update($this->supplierId, $id, ['item_type' => 'service', 'sku' => $item['sku'], 'name' => $item['name']]);
        self::assertFalse((bool) $this->items->find($this->supplierId, $id)['is_stocked']);
    }

    public function testOtherCardsKeepTheirStockFlag(): void
    {
        $id = $this->card('goods');
        $this->items->updateEshopFields($this->supplierId, $id, ['is_stocked' => false]);
        $item = $this->items->find($this->supplierId, $id);

        $this->items->update($this->supplierId, $id, ['item_type' => 'goods', 'sku' => $item['sku'], 'name' => $item['name']]);

        self::assertFalse((bool) $this->items->find($this->supplierId, $id)['is_stocked'], 'Dnešní neskladová karta zůstává.');
    }

    public function testInvoiceWithServiceNeedsNoStockAndIssuesNothing(): void
    {
        $invoice = $this->invoice($this->card('service'));
        $service = $this->container->get(StockIssueService::class);

        $service->assertAvailableForInvoice($this->supplierId, $invoice);
        $service->issueForInvoice($this->supplierId, $invoice, $this->userId);

        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM stock_documents WHERE supplier_id = ? AND invoice_id = ?');
        $count->execute([$this->supplierId, $invoice['id']]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testInvoiceWithGoodsStillRequiresStock(): void
    {
        $invoice = $this->invoice($this->card('goods'));

        $this->expectException(StockException::class);
        $this->container->get(StockIssueService::class)->assertAvailableForInvoice($this->supplierId, $invoice);
    }

    public function testPurchaseInvoiceServiceLineIsNotOfferedForReceipt(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_is_vat_payer, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, 1, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", 100, 0, 100, "received", "40", "full", ?)'
        )->execute([
            $this->supplierId, $this->clientId, 'SRV-' . bin2hex(random_bytes(3)),
            $this->date, $this->date, $this->date, $this->date, $this->czkId, $this->userId,
        ]);
        $purchase = (int) $pdo->lastInsertId();
        $line = $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code, stock_item_id)
             VALUES (?, ?, 1, "ks", 100, ?, 0, 100, 0, 100, ?, "40", ?)'
        );
        $line->execute([$purchase, 'Služba', $this->vatRateId, 0, $this->card('service')]);
        $goods = $this->card('goods');
        $line->execute([$purchase, 'Zboží', $this->vatRateId, 1, $goods]);

        $proposal = $this->container->get(StockReceiptService::class)->proposeForPurchaseInvoice($this->supplierId, $purchase);

        self::assertSame([$goods], array_column($proposal['lines'], 'stock_item_id'));
        self::assertSame(['Služba'], array_column($proposal['cost_candidates'], 'description'));
    }

    public function testStockDocumentRefusesServiceLine(): void
    {
        try {
            $this->container->get(StockDocumentService::class)->create($this->supplierId, [
                'doc_type' => 'receipt',
                'doc_date' => $this->date,
                'description' => 'Příjem (PHPUnit)',
                'warehouse_id' => $this->warehouseId,
                'lines' => [['stock_item_id' => $this->card('service'), 'qty' => '1', 'unit_cost' => '10']],
            ], $this->userId);
            self::fail('Skladový doklad se službou neměl vzniknout.');
        } catch (StockException $e) {
            self::assertSame('service_item_no_stock', $e->errorCode);
        }
    }

    public function testPurchaseOrderKeepsServiceAsLineWithoutCard(): void
    {
        $service = $this->card('service');
        $goods = $this->card('goods');

        $order = $this->container->get(PurchaseOrderService::class)->create($this->supplierId, [
            'vendor_id' => $this->clientId,
            'order_date' => $this->date,
            'warehouse_id' => $this->warehouseId,
            'currency_id' => $this->czkId,
            'lines' => [
                ['stock_item_id' => $service, 'qty_ordered' => '1', 'unit_price' => '100'],
                ['stock_item_id' => $goods, 'qty_ordered' => '1', 'unit_price' => '100'],
            ],
        ], $this->userId);

        $lines = $this->db->pdo()->prepare('SELECT stock_item_id, description FROM purchase_order_lines WHERE supplier_id = ? AND order_id = ? ORDER BY line_no');
        $lines->execute([$this->supplierId, (int) $order['id']]);
        $rows = $lines->fetchAll(\PDO::FETCH_ASSOC);
        self::assertNull($rows[0]['stock_item_id']);
        self::assertSame('Karta service', $rows[0]['description'], 'Popis se převezme z karty.');
        self::assertSame($goods, (int) $rows[1]['stock_item_id']);
    }

    private function card(string $type): int
    {
        return $this->items->insert($this->supplierId, [
            'sku' => 'T' . bin2hex(random_bytes(5)),
            'name' => 'Karta ' . $type,
            'item_type' => $type,
        ]);
    }

    /** @return array<string,mixed> */
    private function invoice(int $stockItemId): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat, paid_total, status,
                 vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, 100, 0, 100, 0, "draft", "1", ?)'
        )->execute([
            $this->supplierId, 'SR' . random_int(10000000, 99999999), $this->clientId,
            $this->date, $this->date, $this->date, $this->czkId, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, stock_item_id)
             VALUES (?, "Řádek", 1, "ks", 100, ?, 0, 100, 0, 100, 0, ?)'
        )->execute([$id, $this->vatRateId, $stockItemId]);

        return ['id' => $id, 'invoice_type' => 'invoice', 'supplier_id' => $this->supplierId];
    }
}
