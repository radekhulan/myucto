<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceItemsAction;
use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Repository\StockLandedCostRepository;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PurchaseItemReferenceTest extends StockTestCase
{
    private int $supplierId;
    private int $warehouseId;
    private int $stockItemId;
    private int $invoiceId;
    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplierId = $this->createSupplier();
        $this->warehouseId = $this->warehouse($this->supplierId);
        $this->stockItemId = $this->item($this->supplierId, 'PF-PRIJEM');
        $this->invoiceId = $this->purchaseInvoice(
            $this->supplierId,
            $this->client($this->supplierId),
            ['status' => 'draft'],
        );
        $this->itemId = $this->purchaseInvoiceItem($this->invoiceId, $this->stockItemId, '6.000');
    }

    public static function replacements(): iterable
    {
        yield 'PUT dokladu podle ID' => [false, true];
        yield 'PUT položek podle ID' => [true, true];
        yield 'PUT položek podle pořadí a popisu' => [true, false];
    }

    #[DataProvider('replacements')]
    public function testReplacementPreservesPostedReceipt(bool $itemsEndpoint, bool $sendId): void
    {
        $receiptId = $this->receipt(true);
        $before = $this->receipts->proposeForPurchaseInvoice($this->supplierId, $this->invoiceId);
        self::assertSame('6.000', $before['lines'][0]['already_received']);

        $line = $this->line();
        if ($sendId) {
            $line['id'] = $this->itemId;
            $line['description'] = 'Upravený popis';
        }
        $response = $this->save($itemsEndpoint, [$line]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $saved = $this->savedInvoice()['items'][0];
        self::assertSame($line['description'], $saved['description']);
        self::assertEquals(175, $saved['unit_price_without_vat']);
        $newId = (int) $saved['id'];
        self::assertNotSame($this->itemId, $newId);
        self::assertSame($newId, $this->receiptItemId($receiptId));

        $after = $this->receipts->proposeForPurchaseInvoice($this->supplierId, $this->invoiceId);
        self::assertSame('6.000', $after['lines'][0]['already_received']);
        self::assertSame('0.000', $after['lines'][0]['remaining_qty']);
        self::assertFalse($after['has_lines_to_receive']);
        self::assertFalse($after['pf_changed_after_receipt']);
        self::assertSame(6000, $this->level($this->supplierId, $this->warehouseId, $this->stockItemId)['qtyT']);

        try {
            $this->receipts->createReceipt($this->supplierId, $this->invoiceId, $this->receiptPayload($newId), $this->userId);
            self::fail('Další příjem plně přijatého řádku musí být odmítnut.');
        } catch (StockException $e) {
            self::assertSame('over_receipt', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
    }

    public static function conflicts(): iterable
    {
        yield 'smazání přes PUT dokladu' => [false, true, false];
        yield 'smazání přes PUT položek' => [true, true, false];
        yield 'stejné ID ve dvou řádcích' => [true, true, true];
        yield 'smazání řádku rozpracované příjemky' => [true, false, false];
    }

    #[DataProvider('conflicts')]
    public function testUnmatchedReceiptLineRejectsEdit(bool $itemsEndpoint, bool $post, bool $duplicate): void
    {
        $receiptId = $this->receipt($post);
        $line = ['id' => $this->itemId] + $this->line();
        $response = $this->save($itemsEndpoint, $duplicate ? [$line, $line] : []);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $error = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error'];
        self::assertSame($post ? 'purchase_item_already_received' : 'purchase_item_on_draft_receipt', $error['code']);
        $saved = $this->savedInvoice();
        self::assertNotSame('Změna, která se nesmí uložit při konfliktu', $saved['notes']);
        self::assertSame([$this->itemId], array_map('intval', array_column($saved['items'], 'id')));
        self::assertSame($this->itemId, $this->receiptItemId($receiptId));

        if ($post && !$duplicate) {
            $this->documents->reverse($this->supplierId, $receiptId, [], $this->userId);
            $response = $this->save($itemsEndpoint, []);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame([], $this->savedInvoice()['items']);
        }
    }

    public function testReplacementRemapsOtherReferenceTables(): void
    {
        $draftId = $this->receipt(false);
        $landed = $this->container->get(StockLandedCostRepository::class);
        $costId = $landed->insert($this->supplierId, [
            'document_id' => $draftId, 'purchase_invoice_id' => $this->invoiceId,
            'purchase_invoice_item_id' => $this->itemId,
            'description' => 'Vedlejší náklad', 'amount' => '10.00', 'allocation' => 'by_qty',
        ]);
        $pdo = $this->db->pdo();
        $params = [$this->supplierId, $this->invoiceId, $this->itemId];
        $pdo->prepare("UPDATE purchase_invoice_items SET accrual_from = '2099-06-01', accrual_to = '2099-09-30' WHERE id = ?")
            ->execute([$this->itemId]);
        $smallAsset = $pdo->prepare(
            "INSERT INTO small_assets (supplier_id, purchase_invoice_id, purchase_invoice_item_id, name, acquisition_date, price)
             VALUES (?, ?, ?, ?, '2099-06-01', 100)"
        );
        $smallAsset->execute([...$params, 'Drobný majetek A']);
        $smallAsset->execute([...$params, 'Drobný majetek B']);
        $pdo->prepare(
            "INSERT INTO assets (supplier_id, purchase_invoice_id, purchase_invoice_item_id, inventory_number, name, acquisition_date, input_price)
             VALUES (?, ?, ?, 'DM-01', 'Dlouhodobý majetek', '2099-06-01', 100000)"
        )->execute($params);
        $pdo->prepare(
            "INSERT INTO accrual_items (supplier_id, source_id, source_item_id, source_type, item_no, title, kind, accrual_account_code, basis_amount, method)
             VALUES (?, ?, ?, 'purchase_invoice', 'CR-01', 'Časové rozlišení', 'prepaid_expense', '381', 100, 'manual')"
        )->execute($params);
        $pdo->prepare(
            "INSERT INTO fuelings (supplier_id, source_purchase_invoice_id, source_item_id, fueled_date, source)
             VALUES (?, ?, ?, '2099-06-01', 'invoice')"
        )->execute($params);

        $response = $this->save(true, [['id' => $this->itemId] + $this->line()]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $items = $this->savedInvoice()['items'];
        self::assertCount(1, $items);
        self::assertSame('2099-06-01', $items[0]['accrual_from']);
        self::assertSame('2099-09-30', $items[0]['accrual_to']);
        $newId = (int) $items[0]['id'];
        self::assertNotSame($this->itemId, $newId);
        self::assertSame($newId, $this->receiptItemId($draftId));
        self::assertSame($newId, $landed->find($this->supplierId, $costId)['purchase_invoice_item_id']);
        foreach ([
            'small_assets' => 'purchase_invoice_item_id', 'assets' => 'purchase_invoice_item_id',
            'accrual_items' => 'source_item_id', 'fuelings' => 'source_item_id',
        ] as $table => $column) {
            $stmt = $pdo->prepare("SELECT {$column} FROM {$table} WHERE supplier_id = ? ORDER BY id");
            $stmt->execute([$this->supplierId]);
            $expected = $table === 'small_assets' ? [$newId, $newId] : [$newId];
            self::assertSame($expected, array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)), $table);
        }
    }

    private function savedInvoice(): array
    {
        return $this->container->get(PurchaseInvoiceRepository::class)->find($this->invoiceId, $this->supplierId);
    }

    private function line(): array
    {
        return [
            'description' => 'Testovací položka PF', 'quantity' => 6, 'unit' => 'ks',
            'unit_price_without_vat' => 175, 'vat_rate_id' => $this->vatRateId,
        ];
    }

    private function receipt(bool $post): int
    {
        $draft = $this->receipts->createReceipt(
            $this->supplierId, $this->invoiceId, $this->receiptPayload($this->itemId), $this->userId,
        );
        $id = (int) $draft['id'];
        if ($post) {
            $this->documents->post($this->supplierId, $id, $this->userId);
        }
        self::assertSame($this->itemId, $this->receiptItemId($id));

        return $id;
    }

    private function receiptItemId(int $id): ?int
    {
        return $this->docsRepo->lines($this->supplierId, $id)[0]['purchase_invoice_item_id'];
    }

    private function receiptPayload(int $itemId): array
    {
        return [
            'warehouse_id' => $this->warehouseId, 'doc_date' => '2099-06-10',
            'lines' => [['purchase_invoice_item_id' => $itemId, 'quantity' => '6.000']],
        ];
    }

    private function save(bool $itemsEndpoint, array $items): ResponseInterface
    {
        $path = '/api/purchase-invoices/' . $this->invoiceId . ($itemsEndpoint ? '/items' : '');
        $action = $this->container->get($itemsEndpoint ? SetPurchaseInvoiceItemsAction::class : UpdatePurchaseInvoiceAction::class);
        $request = (new ServerRequestFactory())->createServerRequest('PUT', $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody(['items' => $items, 'notes' => 'Změna, která se nesmí uložit při konfliktu']);

        return $action($request, new Response(), ['id' => (string) $this->invoiceId]);
    }
}
