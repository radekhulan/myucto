<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceItemsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\Dimension\DraftItemDimensionRealign;
use MyInvoice\Tests\Support\OtherDocumentsPostingScenarios;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Dimenze položek konceptu se ukládají podle pořadí řádku. Po vložení nebo odebrání
 * řádku přes API (MCP posílá u položek jejich `id`) se musí vrátit ke svým položkám.
 *
 * BEZ OPRAVY PADÁ: vložený řádek převzal dimenzi řádku, na jehož místo se vložil,
 * a po odebrání řádku se dimenze dalších řádků posunuly o jeden výš.
 */
#[Group('integration')]
final class DraftItemDimensionRealignTest extends TestCase
{
    private ContainerInterface $container;
    private Connection $db;
    private OtherDocumentsPostingScenarios $scenarios;
    private DimensionService $dimensions;
    private int $userId = 0;
    private int $vatRateId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->db->pdo()->beginTransaction();
        $this->dimensions = $this->container->get(DimensionService::class);
        $this->scenarios = new OtherDocumentsPostingScenarios($this->container);
        $this->scenarios->run();
        $this->userId = (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->vatRateId = (int) $this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testInsertedAndRemovedInvoiceItemsKeepDimensionsWithTheirItems(): void
    {
        $id = $this->invoiceDraft();
        [$a, $b, $c] = $this->tagItems('invoice', $id);
        $ids = $this->itemIds('invoice_items', 'invoice_id', $id);

        // Nový řádek na 2. místě, pak ostatní v původním pořadí (tak to posílá add_invoice_item).
        $this->replaceInvoiceItems($id, [
            $this->line('A', 0, $ids[0]), $this->line('Nová', 1), $this->line('B', 2, $ids[1]), $this->line('C', 3, $ids[2]),
        ]);
        self::assertSame([1 => $a, 3 => $b, 4 => $c], $this->itemDims('invoice', $id));

        // Odebrání prvního řádku (remove_invoice_item).
        $ids = $this->itemIds('invoice_items', 'invoice_id', $id);
        $this->replaceInvoiceItems($id, [
            $this->line('Nová', 0, $ids[1]), $this->line('B', 1, $ids[2]), $this->line('C', 2, $ids[3]),
        ]);
        self::assertSame([2 => $b, 3 => $c], $this->itemDims('invoice', $id));
    }

    public function testWithoutItemIdsDimensionsStayByPosition(): void
    {
        $id = $this->invoiceDraft();
        $before = $this->tagItems('invoice', $id);

        $this->replaceInvoiceItems($id, [$this->line('X', 0), $this->line('Y', 1), $this->line('Z', 2)]);

        self::assertSame([1 => $before[0], 2 => $before[1], 3 => $before[2]], $this->itemDims('invoice', $id),
            'Klient bez `id` párovat nedovoluje, chování zůstává jako dřív.');
    }

    public function testPurchaseItemsEndpointRealignsDraftDimensions(): void
    {
        $id = $this->purchaseDraft();
        [$a, $b, $c] = $this->tagItems('purchase_invoice', $id);
        $ids = $this->itemIds('purchase_invoice_items', 'purchase_invoice_id', $id);

        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/purchase-invoices/' . $id . '/items')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->scenarios->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody(['items' => [$this->line('B', 0, $ids[1]), $this->line('C', 1, $ids[2])]]);
        $response = ($this->container->get(SetPurchaseInvoiceItemsAction::class))($request, new Psr7Response(), ['id' => (string) $id]);
        $response->getBody()->rewind();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        self::assertSame([1 => $b, 2 => $c], $this->itemDims('purchase_invoice', $id));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param list<array<string,mixed>> $items */
    private function replaceInvoiceItems(int $id, array $items): void
    {
        $realign = $this->container->get(DraftItemDimensionRealign::class);
        $before = $realign->snapshot('invoice', $id);
        $this->container->get(InvoiceRepository::class)->replaceItems($id, $items);
        $realign->realign($this->scenarios->supplierId, 'invoice', $id, $before, $items);
    }

    /** @return list<array<int,int>> dimenze tří položek v pořadí */
    private function tagItems(string $docType, int $id): array
    {
        $project = $this->scenarios->types()['project'];
        $dims = [];
        foreach (['RA-1', 'RA-2', 'RA-3'] as $i => $code) {
            $dims[$i + 1] = [$project => $this->scenarios->value($project, $code)];
        }
        $this->dimensions->saveDocument($this->scenarios->supplierId, $docType, $id, [], $dims);
        self::assertSame($dims, $this->itemDims($docType, $id), 'Výchozí stav musí dimenze nést.');

        return array_values($dims);
    }

    /** @return array<int,array<int,int>> */
    private function itemDims(string $docType, int $id): array
    {
        $items = $this->dimensions->documentDimensions($this->scenarios->supplierId, $docType, $id)['items'];
        ksort($items);

        return $items;
    }

    /** @return list<int> */
    private function itemIds(string $table, string $parent, int $id): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE {$parent} = ? ORDER BY order_index, id");
        $stmt->execute([$id]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array<string,mixed> */
    private function line(string $description, int $order, ?int $id = null): array
    {
        return ($id !== null ? ['id' => $id] : []) + [
            'description' => $description, 'quantity' => 1, 'unit' => 'ks',
            'unit_price_without_vat' => 100, 'vat_rate_id' => $this->vatRateId, 'order_index' => $order,
        ];
    }

    private function invoiceDraft(): int
    {
        $pdo = $this->db->pdo();
        $src = $pdo->query('SELECT client_id, currency_id FROM invoices WHERE id = ' . (int) $this->scenarios->ids['settled'])->fetch(\PDO::FETCH_ASSOC);
        $date = OtherDocumentsPostingScenarios::YEAR . '-06-10';
        $pdo->prepare(
            'INSERT INTO invoices (supplier_id, invoice_type, client_id, issue_date, tax_date, due_date, currency_id,
                                   reverse_charge, total_without_vat, total_vat, total_with_vat, paid_total, status, created_by)
             VALUES (?, "invoice", ?, ?, ?, ?, ?, 0, 300, 0, 300, 0, "draft", ?)'
        )->execute([$this->scenarios->supplierId, $src['client_id'], $date, $date, $date, $src['currency_id'], $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $this->container->get(InvoiceRepository::class)->replaceItems($id, [$this->line('A', 0), $this->line('B', 1), $this->line('C', 2)]);

        return $id;
    }

    private function purchaseDraft(): int
    {
        $pdo = $this->db->pdo();
        $src = $pdo->query('SELECT vendor_id, currency_id FROM purchase_invoices WHERE id = ' . (int) $this->scenarios->ids['purchase'])->fetch(\PDO::FETCH_ASSOC);
        $date = OtherDocumentsPostingScenarios::YEAR . '-06-11';
        $pdo->prepare(
            'INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, due_date,
                                            received_at, currency_id, vendor_snapshot, total_with_vat, status, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, "{}", 300, "draft", ?)'
        )->execute([$this->scenarios->supplierId, $src['vendor_id'], 'RA-' . random_int(100000, 999999), $date, $date, $date, $src['currency_id'], $this->userId]);
        $id = (int) $pdo->lastInsertId();
        foreach (['A', 'B', 'C'] as $order => $description) {
            $pdo->prepare(
                "INSERT INTO purchase_invoice_items (purchase_invoice_id, description, quantity, unit, unit_price_without_vat,
                                                    vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
                 VALUES (?, ?, 1, 'ks', 100, ?, 0, 100, 0, 100, ?)"
            )->execute([$id, $description, $this->vatRateId, $order]);
        }

        return $id;
    }
}
