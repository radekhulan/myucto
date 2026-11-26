<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceItemsAction;
use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Repository\PurchaseOrderRepository;
use MyInvoice\Service\Stock\PurchaseOrderReceiptService;
use MyInvoice\Service\Stock\PurchaseOrderService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Náhrada položek přijaté faktury (DELETE + INSERT) nesmí zahodit vazbu řádku na řádek
 * nákupní objednávky (`purchase_invoice_items.purchase_order_line_id`). Bez ní příjem
 * z faktury objednávku neuzavře (zboží zůstane „na cestě") a příjem z objednávky
 * nevezme cenu z faktury.
 *
 * BEZ OPRAVY PADÁ: `replaceItems()` sloupec nevkládal a detail dokladu ho nevracel.
 */
#[Group('integration')]
final class PurchaseItemOrderLinkTest extends StockTestCase
{
    private int $sid = 0;
    private int $wh = 0;
    private int $card = 0;
    /** @var array<string,mixed> */
    private array $order = [];
    private int $polId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sid = $this->createSupplier();
        $this->wh = $this->warehouse($this->sid);
        $this->card = $this->item($this->sid, 'OBJ-VAZBA-1');
        $orders = $this->container->get(PurchaseOrderService::class);
        $order = $orders->create($this->sid, [
            'vendor_id' => $this->client($this->sid, 'Syntetický dodavatel objednávky'), 'order_date' => '2099-06-01',
            'warehouse_id' => $this->wh, 'currency_id' => $this->currencyIdFor($this->sid),
            'lines' => [['stock_item_id' => $this->card, 'qty_ordered' => '10', 'unit_price' => '100']],
        ], $this->userId);
        $this->order = $orders->send($this->sid, (int) $order['id'], $this->userId);
        $this->polId = (int) $this->order['lines'][0]['id'];
    }

    /** @return iterable<string,array{string,bool}> */
    public static function edits(): iterable
    {
        yield 'PUT položek beze změny' => ['items', false];
        yield 'PUT položek se změnou popisu a ceny' => ['items', true];
        yield 'PUT dokladu beze změny' => ['invoice', false];
        yield 'PUT dokladu se změnou popisu a ceny' => ['invoice', true];
    }

    #[DataProvider('edits')]
    public function testItemsReplaceKeepsOrderLineLinkAndReceivedQuantity(string $endpoint, bool $change): void
    {
        $piId = $this->linkedDraft();
        $repo = $this->container->get(PurchaseInvoiceRepository::class);
        $orders = $this->container->get(PurchaseOrderRepository::class);

        // Část zboží už přijatá příjemkou z objednávky; přepis faktury ji nesmí pohnout.
        $draft = $this->container->get(PurchaseOrderReceiptService::class)->createReceipt($this->sid, (int) $this->order['id'], [
            'doc_date' => '2099-06-02', 'lines' => [['purchase_order_line_id' => $this->polId, 'qty' => '4']],
        ], $this->userId);
        $this->documents->post($this->sid, (int) $draft['id'], $this->userId);
        $receivedBefore = $orders->receivedByOrder($this->sid, [(int) $this->order['id']]);
        self::assertSame('4.000', number_format((float) $receivedBefore[$this->polId], 3, '.', ''));

        // Klient posílá položky tak, jak je vrátil detail dokladu (s id), jen bez sloupců,
        // které nezná — přesně jako klient API, který o objednávkách neví.
        $stored = $repo->find($piId, $this->sid)['items'];
        $items = [];
        foreach ($stored as $row) {
            $items[] = [
                'id'                     => $row['id'],
                'description'            => $change ? $row['description'] . ' (upraveno)' : $row['description'],
                'quantity'               => $row['quantity'],
                'unit'                   => $row['unit'],
                'unit_price_without_vat' => $change ? 120 : $row['unit_price_without_vat'],
                'vat_rate_id'            => $row['vat_rate_id'],
                'stock_item_id'          => $row['stock_item_id'],
            ];
        }
        $this->save($endpoint, $piId, $items);

        $after = $this->storedLinks($piId);
        self::assertSame([$this->polId, null], array_column($after, 'purchase_order_line_id'),
            'Vazba na řádek objednávky po přepisu položek zůstává, řádek bez vazby ji nezíská.');
        self::assertSame($receivedBefore, $orders->receivedByOrder($this->sid, [(int) $this->order['id']]),
            'Přijaté množství objednávky se přepisem faktury nemění.');

        // Příjem z faktury po přepisu musí dál uzavírat objednávku.
        $proposal = $this->receipts->proposeForPurchaseInvoice($this->sid, $piId);
        self::assertSame($this->polId, $proposal['lines'][0]['purchase_order_line_id']);
        $receipt = $this->receipts->createReceipt($this->sid, $piId, [
            'warehouse_id' => $this->wh, 'doc_date' => '2099-06-03',
            'lines' => [['purchase_invoice_item_id' => (int) $after[0]['id'], 'quantity' => '6']],
        ], $this->userId);
        $this->documents->post($this->sid, (int) $receipt['id'], $this->userId);
        $received = $orders->receivedByOrder($this->sid, [(int) $this->order['id']]);
        self::assertSame('10.000', number_format((float) ($received[$this->polId] ?? 0), 3, '.', ''));
        self::assertSame('received', $orders->find($this->sid, (int) $this->order['id'])['state']);
    }

    public function testExplicitInputLinkIsAcceptedOnlyForOwnOrderLine(): void
    {
        $piId = $this->purchaseInvoice($this->sid, (int) $this->order['vendor_id'], ['status' => 'draft']);
        $foreign = $this->createSupplier();
        $foreignOrder = $this->container->get(PurchaseOrderService::class)->create($foreign, [
            'vendor_id' => $this->client($foreign, 'Cizí dodavatel'), 'order_date' => '2099-06-01',
            'warehouse_id' => $this->warehouse($foreign), 'currency_id' => $this->currencyIdFor($foreign),
            'lines' => [['stock_item_id' => $this->item($foreign, 'CIZI-1'), 'qty_ordered' => '1', 'unit_price' => '1']],
        ], $this->userId);

        $this->save('items', $piId, [
            $this->line('Zboží z objednávky') + ['purchase_order_line_id' => $this->polId],
            $this->line('Cizí řádek') + ['purchase_order_line_id' => (int) $foreignOrder['lines'][0]['id']],
        ]);
        $items = $this->container->get(PurchaseInvoiceRepository::class)->find($piId, $this->sid)['items'];
        self::assertSame($this->polId, $items[0]['purchase_order_line_id']);
        self::assertNull($items[1]['purchase_order_line_id'], 'Řádek objednávky jiné firmy se neuloží.');

        // Explicitní null vazbu zruší.
        $this->save('items', $piId, [
            ['id' => $items[0]['id'], 'purchase_order_line_id' => null] + $this->line('Zboží z objednávky'),
        ]);
        $items = $this->container->get(PurchaseInvoiceRepository::class)->find($piId, $this->sid)['items'];
        self::assertNull($items[0]['purchase_order_line_id']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Koncept s prvním řádkem napárovaným na řádek objednávky (vazba zapsaná přímo, jako u převzatých dat). */
    private function linkedDraft(): int
    {
        $piId = $this->purchaseInvoice($this->sid, (int) $this->order['vendor_id'], ['status' => 'draft']);
        $this->save('items', $piId, [
            $this->line('Zboží z objednávky', '10') + ['stock_item_id' => $this->card],
            $this->line('Doprava'),
        ]);
        $first = (int) $this->storedLinks($piId)[0]['id'];
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET purchase_order_line_id = ? WHERE id = ?')
            ->execute([$this->polId, $first]);
        self::assertSame([$this->polId, null], array_column($this->storedLinks($piId), 'purchase_order_line_id'),
            'Výchozí stav musí vazbu nést, jinak test netestuje nic.');

        return $piId;
    }

    /** @return list<array{id:int, purchase_order_line_id:?int}> */
    private function storedLinks(int $piId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, purchase_order_line_id FROM purchase_invoice_items WHERE purchase_invoice_id = ? ORDER BY order_index, id'
        );
        $stmt->execute([$piId]);

        return array_map(static fn (array $r): array => [
            'id'                     => (int) $r['id'],
            'purchase_order_line_id' => $r['purchase_order_line_id'] !== null ? (int) $r['purchase_order_line_id'] : null,
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed> */
    private function line(string $description, string $qty = '1'): array
    {
        return [
            'description'            => $description,
            'quantity'               => $qty,
            'unit'                   => 'ks',
            'unit_price_without_vat' => 100,
            'vat_rate_id'            => $this->vatRateId,
        ];
    }

    /** @param list<array<string,mixed>> $items */
    private function save(string $endpoint, int $piId, array $items): void
    {
        $action = $endpoint === 'items'
            ? $this->container->get(SetPurchaseInvoiceItemsAction::class)
            : $this->container->get(UpdatePurchaseInvoiceAction::class);
        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/purchase-invoices/' . $piId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody(['items' => $items]);
        $response = $action($request, new Psr7Response(), ['id' => (string) $piId]);
        $response->getBody()->rewind();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }
}
