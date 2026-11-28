<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Stock\StockTrackingAction;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\StockTrackingRepository;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockLocationService;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class StockLocationTest extends StockTestCase
{
    public function testAutomaticIssueUsesUnassignedThenNamedStockAndReversalRestoresTheSplit(): void
    {
        $supplier = $this->createSupplier();
        $warehouse = $this->warehouse($supplier);
        $item = $this->item($supplier, 'LOC-AUTO');
        $a = $this->location($supplier, $warehouse, 'A');
        $b = $this->location($supplier, $warehouse, 'B');

        $this->post($supplier, $warehouse, 'receipt', '2099-01-01', [
            ['stock_item_id' => $item, 'qty' => '2', 'unit_cost' => '10'],
            ['stock_item_id' => $item, 'qty' => '3', 'unit_cost' => '10', 'location_id' => $a],
            ['stock_item_id' => $item, 'qty' => '4', 'unit_cost' => '10', 'location_id' => $b],
        ]);
        $issue = $this->post($supplier, $warehouse, 'issue', '2099-01-02', [['stock_item_id' => $item, 'qty' => '6']]);
        self::assertSame('0.000', $this->quantityAt($supplier, $warehouse, $a, $item, '2099-01-02'));
        self::assertSame('3.000', $this->quantityAt($supplier, $warehouse, $b, $item, '2099-01-02'));
        self::assertSame(['qtyT' => 3000, 'valueC' => 3000], $this->levels->current($supplier, $warehouse, $item));

        $this->documents->reverse($supplier, $issue['id'], [], $this->userId);
        self::assertSame('3.000', $this->quantityAt($supplier, $warehouse, $a, $item, '2099-01-02'));
        self::assertSame('4.000', $this->quantityAt($supplier, $warehouse, $b, $item, '2099-01-02'));
        self::assertSame(['qtyT' => 9000, 'valueC' => 9000], $this->levels->current($supplier, $warehouse, $item));
    }

    public function testChainedTransferWithinWarehouseReversesWithoutChangingTheWarehouseValue(): void
    {
        $supplier = $this->createSupplier();
        $warehouse = $this->warehouse($supplier);
        $item = $this->item($supplier, 'LOC-CHAIN');
        $a = $this->location($supplier, $warehouse, 'A');
        $b = $this->location($supplier, $warehouse, 'B');
        $this->receiveStock($supplier, $warehouse, $item, '5', 13.0, '2099-01-01');
        $transfer = $this->post($supplier, $warehouse, 'transfer', '2099-01-02', [
            ['stock_item_id' => $item, 'qty' => '5', 'location_to_id' => $a],
            ['stock_item_id' => $item, 'qty' => '5', 'location_id' => $a, 'location_to_id' => $b],
        ], $warehouse);
        self::assertSame($a, $transfer['lines'][1]['location_id']);
        self::assertSame($b, $transfer['lines'][1]['location_to_id']);
        self::assertSame(['0.00', '0.00'], array_column($transfer['lines'], 'value_total'));
        self::assertSame('5.000', $this->quantityAt($supplier, $warehouse, $b, $item, '2099-01-02'));
        $this->documents->post($supplier, $transfer['id'], $this->userId);
        self::assertSame('5.000', $this->quantityAt($supplier, $warehouse, $b, $item, '2099-01-02'));

        $this->documents->reverse($supplier, $transfer['id'], [], $this->userId);
        self::assertSame('0.000', $this->quantityAt($supplier, $warehouse, $a, $item, '2099-01-02'));
        self::assertSame('0.000', $this->quantityAt($supplier, $warehouse, $b, $item, '2099-01-02'));
        self::assertSame(['qtyT' => 5000, 'valueC' => 6500], $this->levels->current($supplier, $warehouse, $item));
    }

    public function testBackdatedTransferCannotUseFutureStockAndLeavesItsDraftIntact(): void
    {
        $supplier = $this->createSupplier();
        $warehouse = $this->warehouse($supplier);
        $item = $this->item($supplier, 'LOC-FUTURE');
        $a = $this->location($supplier, $warehouse, 'A');
        $this->receiveStock($supplier, $warehouse, $item, '5', 13.0, '2099-01-10');
        $draft = $this->documents->create($supplier, [
            'doc_type' => 'transfer', 'warehouse_id' => $warehouse, 'warehouse_to_id' => $warehouse,
            'doc_date' => '2099-01-09', 'description' => 'Přesun zásoby z budoucnosti',
            'lines' => [['stock_item_id' => $item, 'qty' => '1', 'location_to_id' => $a]],
        ], $this->userId);

        try {
            $this->documents->post($supplier, $draft['id'], $this->userId);
            self::fail('Přesun nesmí použít zásobu přijatou až později.');
        } catch (StockException $e) {
            self::assertSame('stock_location_insufficient', $e->errorCode);
            self::assertSame('0.000', $e->details['available']);
        }
        self::assertSame('draft', $this->docsRepo->find($supplier, $draft['id'])['status']);
        self::assertNull($this->quantityAt($supplier, $warehouse, $a, $item, '2099-01-10'));
    }

    public function testBackdatedTransferCannotUseUnassignedStockSpentBeforeAnotherReceiptOnTheSameDay(): void
    {
        $supplier = $this->createSupplier();
        $warehouse = $this->warehouse($supplier);
        $item = $this->item($supplier, 'LOC-SAME-DAY');
        $a = $this->location($supplier, $warehouse, 'A');
        $this->receiveStock($supplier, $warehouse, $item, '5', 13.0, '2099-01-01');
        $this->post($supplier, $warehouse, 'issue', '2099-01-03', [['stock_item_id' => $item, 'qty' => '5']]);
        $this->receiveStock($supplier, $warehouse, $item, '5', 13.0, '2099-01-03');

        try {
            $this->post($supplier, $warehouse, 'transfer', '2099-01-02', [
                ['stock_item_id' => $item, 'qty' => '1', 'location_to_id' => $a],
            ], $warehouse);
            self::fail('Mezi dvěma doklady téhož dne byla nealokovaná zásoba vyčerpaná.');
        } catch (StockException $e) {
            self::assertSame('stock_location_insufficient', $e->errorCode);
        }
    }

    public function testCrossWarehouseTransferAndLocationReadsRespectTenantOwnership(): void
    {
        $supplier = $this->createSupplier();
        $otherSupplier = $this->createSupplier();
        $warehouse = $this->warehouse($supplier);
        $destination = $this->warehouse($supplier, 'CIL', false);
        $foreignWarehouse = $this->warehouse($otherSupplier);
        $a = $this->location($supplier, $warehouse, 'A');
        $b = $this->location($supplier, $destination, 'B');
        $foreignLocation = $this->location($otherSupplier, $foreignWarehouse, 'A');
        $item = $this->item($supplier, 'LOC-CROSS');
        $this->post($supplier, $warehouse, 'receipt', '2099-01-01', [
            ['stock_item_id' => $item, 'qty' => '4', 'unit_cost' => '13', 'location_id' => $a],
        ]);
        foreach ([$a, $foreignLocation] as $invalidLocation) {
            try {
                $this->post($supplier, $warehouse, 'transfer', '2099-01-02', [
                    ['stock_item_id' => $item, 'qty' => '1', 'location_id' => $a, 'location_to_id' => $invalidLocation],
                ], $destination);
                self::fail('Cílová lokace musí patřit k cílovému skladu firmy.');
            } catch (StockException $e) {
                self::assertSame('stock_location_invalid', $e->errorCode);
            }
        }
        $transfer = $this->post($supplier, $warehouse, 'transfer', '2099-01-02', [
            ['stock_item_id' => $item, 'qty' => '1.250', 'location_id' => $a, 'location_to_id' => $b],
        ], $destination);
        self::assertSame('16.25', $transfer['lines'][0]['value_total']);

        $action = $this->container->get(StockTrackingAction::class);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/stock/items/' . $item . '/locations')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier);
        $byItem = json_decode((string) $action->itemLocations($request, new Response(), ['id' => $item])->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $byItem['meta']['total']);
        self::assertSame([$a => '2.750', $b => '1.250'], array_column($byItem['data'], 'quantity', 'location_id'));
        $byLocation = json_decode((string) $action->locationContents($request, new Response(), ['id' => $b])->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('LOC-CROSS', $byLocation['data'][0]['sku']);
        self::assertSame('1.250', $byLocation['data'][0]['quantity']);
        self::assertSame(404, $action->locationContents($request, new Response(), ['id' => $foreignLocation])->getStatusCode());

        $this->documents->reverse($supplier, $transfer['id'], [], $this->userId);
        self::assertSame('4.000', $this->quantityAt($supplier, $warehouse, $a, $item, '2099-01-02'));
        self::assertSame('0.000', $this->quantityAt($supplier, $destination, $b, $item, '2099-01-02'));
    }

    public function testContentsIncludeTrackedAndUntrackedItemsOnTheSameLocation(): void
    {
        $supplier = $this->createSupplier();
        $warehouse = $this->warehouse($supplier);
        $location = $this->location($supplier, $warehouse, 'A');
        $plain = $this->item($supplier, 'LOC-PLAIN');
        $serial = $this->itemsRepo->insert($supplier, ['sku' => 'LOC-SERIAL', 'name' => 'Sériová karta', 'tracking_mode' => 'serial']);
        $this->post($supplier, $warehouse, 'receipt', '2099-01-01', [
            ['stock_item_id' => $plain, 'qty' => '2', 'unit_cost' => '10', 'location_id' => $location],
            ['stock_item_id' => $serial, 'qty' => '1', 'unit_cost' => '20', 'tracking_allocations' => [
                ['serial_number' => 'LOC-SN-1', 'quantity' => '1', 'location_id' => $location],
            ]],
        ]);

        $action = $this->container->get(StockTrackingAction::class);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/stock/locations/' . $location . '/contents')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier);
        $contents = json_decode((string) $action->locationContents($request, new Response(), ['id' => $location])->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([$plain => '2.000', $serial => '1.000'], array_column($contents['data'], 'quantity', 'stock_item_id'));
        $serialLocations = json_decode((string) $action->itemLocations($request, new Response(), ['id' => $serial])->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([$location => '1.000'], array_column($serialLocations['data'], 'quantity', 'location_id'));
    }

    private function location(int $supplier, int $warehouse, string $code): int
    {
        return $this->container->get(StockTrackingRepository::class)->saveLocation($supplier, $warehouse, null, $code, $code, true);
    }

    private function quantityAt(int $supplier, int $warehouse, int $location, int $item, string $date): ?string
    {
        return $this->container->get(StockLocationService::class)->quantitiesAt($supplier, $warehouse, $location, $date)[$item] ?? null;
    }

    private function post(int $supplier, int $warehouse, string $type, string $date, array $lines, ?int $destination = null): array
    {
        $draft = $this->documents->create($supplier, [
            'doc_type' => $type, 'warehouse_id' => $warehouse, 'warehouse_to_id' => $destination,
            'doc_date' => $date, 'description' => 'Pohyb skladových pozic', 'lines' => $lines,
        ], $this->userId);
        return $this->documents->post($supplier, $draft['id'], $this->userId);
    }
}
