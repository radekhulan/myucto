<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Stock\StockTakeAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\StockTrackingRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockLocationService;
use MyInvoice\Service\Stock\StockValuationJobService;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Epic SKLAD, plán §8.2 scénář 12 — inventura end-to-end (start → snapshot →
 * počty → close → rozdílové doklady origin='inventory', posted) a A13 (otevřená
 * inventura blokuje post pohybů skladu).
 */
#[Group('integration')]
final class StockTakeTest extends StockTestCase
{
    public function testInventoryLifecycleProducesPostedDifferenceDocuments(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $shortageItem = $this->item($supplierId, 'INV-A');
        $surplusItem  = $this->item($supplierId, 'INV-B');

        $this->receiveStock($supplierId, $whId, $shortageItem, '10.000', 10.0, '2099-01-01');
        $this->receiveStock($supplierId, $whId, $surplusItem, '5.000', 20.0, '2099-01-01');

        $take = $this->takes->create($supplierId, [
            'warehouse_id' => $whId,
            'take_date'    => '2099-02-01',
            'counting_method' => 'physical_count',
            'responsible_count_name' => 'Testovací skladník',
            'responsible_inventory_name' => 'Testovací vedoucí',
        ], $this->userId);
        self::assertSame('draft', $take['status']);

        $started = $this->takes->start($supplierId, (int) $take['id'], $this->userId);
        self::assertSame('counting', $started['status']);
        self::assertCount(2, $started['lines']);

        $lineIdByItem = [];
        foreach ($started['lines'] as $l) {
            $lineIdByItem[(int) $l['stock_item_id']] = (int) $l['id'];
        }
        self::assertSame('10.000', $this->findLine($started, $shortageItem)['expected_qty']);
        self::assertSame('5.000', $this->findLine($started, $surplusItem)['expected_qty']);

        // Otevřená inventura blokuje normální pohyb na tomtéž skladu (A13).
        $blockedDraft = $this->documents->create($supplierId, [
            'doc_type'     => 'receipt',
            'origin'       => 'manual',
            'warehouse_id' => $whId,
            'doc_date'     => '2099-02-02',
            'description'  => 'Pohyb během inventury',
            'lines'        => [['stock_item_id' => $shortageItem, 'qty' => '1.000', 'unit_cost' => '10.000000']],
        ], $this->userId);
        try {
            $this->documents->post($supplierId, (int) $blockedDraft['id'], $this->userId);
            self::fail('post() během otevřené inventury měl vyhodit stock_take_in_progress.');
        } catch (StockException $e) {
            self::assertSame('stock_take_in_progress', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }

        $this->takes->updateCounts($supplierId, (int) $take['id'], [
            'lines' => [
                ['id' => $lineIdByItem[$shortageItem], 'counted_qty' => '7.000'], // manko −3
                ['id' => $lineIdByItem[$surplusItem], 'counted_qty' => '8.000'],  // přebytek +3
            ],
        ], $this->userId);

        $closed = $this->takes->close($supplierId, (int) $take['id'], $this->userId);
        self::assertSame('closed', $closed['status']);

        self::assertNotNull($closed['issue_document'], 'manko musí vytvořit posted výdejku (origin=inventory).');
        self::assertSame('issue', $closed['issue_document']['doc_type']);
        self::assertSame('inventory', $closed['issue_document']['origin']);
        self::assertSame('posted', $closed['issue_document']['status']);
        self::assertNull($closed['issue_document']['journal_entry_id'], 'inventurní doklad NESMÍ mít deníkový zápis (v1 způsob B).');

        self::assertNotNull($closed['receipt_document'], 'přebytek musí vytvořit posted příjemku (origin=inventory).');
        self::assertSame('receipt', $closed['receipt_document']['doc_type']);
        self::assertSame('inventory', $closed['receipt_document']['origin']);
        self::assertSame('posted', $closed['receipt_document']['status']);
        self::assertNull($closed['receipt_document']['journal_entry_id']);

        $shortageLevel = $this->level($supplierId, $whId, $shortageItem);
        self::assertSame(7000, $shortageLevel['qtyT']);
        $surplusLevel = $this->level($supplierId, $whId, $surplusItem);
        self::assertSame(8000, $surplusLevel['qtyT']);

        // Zavřená inventura znovu neblokuje běžné pohyby.
        $freeDraft = $this->documents->create($supplierId, [
            'doc_type'     => 'receipt',
            'origin'       => 'manual',
            'warehouse_id' => $whId,
            'doc_date'     => '2099-02-05',
            'description'  => 'Pohyb po uzavření inventury',
            'lines'        => [['stock_item_id' => $shortageItem, 'qty' => '1.000', 'unit_cost' => '10.000000']],
        ], $this->userId);
        $posted = $this->documents->post($supplierId, (int) $freeDraft['id'], $this->userId);
        self::assertSame('posted', $posted['status']);
    }

    public function testHistoricalSnapshotIncludesInactiveItemAndUsesDecisionDate(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INV-HIST');
        $this->receiveStock($supplierId, $whId, $itemId, '5.000', 10.0, '2099-01-01');
        $this->receiveStock($supplierId, $whId, $itemId, '2.000', 20.0, '2099-01-20');
        $this->itemsRepo->deactivate($supplierId, $itemId);

        $take = $this->takes->create($supplierId, $this->takeBody($whId, '2099-01-10'), $this->userId);
        $started = $this->takes->start($supplierId, (int) $take['id'], $this->userId);

        self::assertSame('5.000', $this->findLine($started, $itemId)['expected_qty']);
        self::assertNotNull($started['started_at']);
    }

    public function testZeroBookSurplusRequiresReproductionCost(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INV-SURPLUS');
        $take = $this->takes->create($supplierId, $this->takeBody($whId, '2099-03-01'), $this->userId);
        $started = $this->takes->start($supplierId, (int) $take['id'], $this->userId);
        self::assertSame([], $started['lines'], 'Karta, která na skladu nikdy nebyla, řádek nemá.');
        $added = $this->takes->updateCounts($supplierId, (int) $take['id'], ['add_item_ids' => [$itemId, $itemId]], $this->userId);
        self::assertCount(1, $added['lines'], 'Nalezenou kartu jde přidat ručně, jednou.');
        $line = $this->findLine($added, $itemId);
        self::assertSame('0.000', $line['expected_qty']);

        $this->takes->updateCounts($supplierId, (int) $take['id'], [
            'lines' => [['id' => $line['id'], 'counted_qty' => '2.000']],
        ], $this->userId);
        try {
            $this->takes->close($supplierId, (int) $take['id'], $this->userId);
            self::fail('Přebytek bez reprodukční ceny musí být odmítnut.');
        } catch (StockException $e) {
            self::assertSame('missing_surplus_unit_cost', $e->errorCode);
        }

        $this->takes->updateCounts($supplierId, (int) $take['id'], [
            'lines' => [['id' => $line['id'], 'counted_qty' => '2.000', 'surplus_unit_cost' => '37.500000']],
        ], $this->userId);
        $closed = $this->takes->close($supplierId, (int) $take['id'], $this->userId);
        self::assertSame('37.500000', $closed['receipt_document']['lines'][0]['unit_cost']);
        self::assertSame('75.00', $closed['receipt_document']['lines'][0]['value_total']);
    }

    public function testTakeListsOnlyItemsOfTheWarehouse(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $otherWh = $this->warehouse($supplierId, 'OTHER', false);
        $here = $this->item($supplierId, 'INV-HERE');
        $soldOut = $this->item($supplierId, 'INV-SOLDOUT');
        $elsewhere = $this->item($supplierId, 'INV-ELSEWHERE');
        $never = $this->item($supplierId, 'INV-NEVER');
        $later = $this->item($supplierId, 'INV-LATER');
        $this->receiveStock($supplierId, $whId, $here, '3.000', 10.0, '2099-01-01');
        $this->receiveStock($supplierId, $whId, $soldOut, '1.000', 10.0, '2099-01-01');
        $issue = $this->documents->create($supplierId, [
            'doc_type' => 'issue', 'origin' => 'manual', 'warehouse_id' => $whId, 'doc_date' => '2099-01-02',
            'description' => 'Výdej', 'lines' => [['stock_item_id' => $soldOut, 'qty' => '1.000']],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $issue['id'], $this->userId);
        $this->receiveStock($supplierId, $otherWh, $elsewhere, '4.000', 10.0, '2099-01-01');
        $this->receiveStock($supplierId, $whId, $later, '2.000', 10.0, '2099-03-01');

        $take = $this->takes->create($supplierId, $this->takeBody($whId, '2099-02-01'), $this->userId);
        $started = $this->takes->start($supplierId, (int) $take['id'], $this->userId);
        $items = array_map(static fn (array $l): int => (int) $l['stock_item_id'], $started['lines']);
        sort($items);
        self::assertSame([$here, $soldOut], $items,
            'Jen karty, které se na skladu k datu inventury pohnuly — i vyprodaná; ne cizí sklad, nikdy nenaskladněná ani naskladněná až po datu.');
        self::assertSame('0.000', $this->findLine($started, $soldOut)['expected_qty']);
        self::assertNotContains($never, $items);
    }

    public function testBackgroundPreparationListsOnlyItemsOfTheWarehouse(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $otherWh = $this->warehouse($supplierId, 'OTHER', false);
        $here = $this->item($supplierId, 'PREP-HERE');
        $elsewhere = $this->item($supplierId, 'PREP-ELSEWHERE');
        $this->item($supplierId, 'PREP-NEVER');
        $this->receiveStock($supplierId, $whId, $here, '3.000', 10.0, '2099-01-01');
        $this->receiveStock($supplierId, $otherWh, $elsewhere, '4.000', 10.0, '2099-01-01');

        $take = $this->takes->create($supplierId, $this->takeBody($whId, '2099-02-01'), $this->userId);
        $this->takes->prepare($supplierId, (int) $take['id'], $this->userId);
        $jobs = $this->container->get(\MyInvoice\Service\Stock\StockValuationJobService::class);
        for ($i = 0; $i < 20 && $this->takes->get($supplierId, (int) $take['id'])['status'] === 'preparing'; $i++) {
            $jobs->tick($supplierId);
        }
        $prepared = $this->takes->get($supplierId, (int) $take['id']);
        self::assertSame('counting', $prepared['status']);
        self::assertSame([$here], array_map(static fn (array $l): int => (int) $l['stock_item_id'], $prepared['lines']),
            'Příprava na pozadí má stejné pravidlo jako přímé spuštění.');
    }

    public function testLocationTakeListsAndValidatesItsScope(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $a = $this->location($supplierId, $warehouseId, 'A');
        $b = $this->location($supplierId, $warehouseId, 'B');
        $otherWarehouseId = $this->warehouse($supplierId, 'OTHER', false);
        $otherWarehouseLocation = $this->location($supplierId, $otherWarehouseId, 'WRONG');
        $otherSupplierId = $this->createSupplier();
        $foreignLocation = $this->location($otherSupplierId, $this->warehouse($otherSupplierId), 'FOREIGN');
        $action = $this->container->get(StockTakeAction::class);
        $request = $this->takeRequest($supplierId, 'POST');
        $body = $this->takeBody($warehouseId, '2099-02-01');

        foreach ([$otherWarehouseLocation, $foreignLocation, 0, true] as $invalid) {
            $response = $action->create($request->withParsedBody($body + ['location_id' => $invalid]), new Response());
            self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame('stock.error.invalid_location', json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
        }

        $response = $action->create($request->withParsedBody($body + ['location_id' => $a]), new Response());
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $takeA = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($a, $takeA['location_id']);

        $response = $action->create($request->withParsedBody($body + ['location_id' => $b]), new Response());
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $this->takes->start($supplierId, $takeA['id'], $this->userId);
        $this->takes->close($supplierId, $takeA['id'], $this->userId);

        $response = $action->create($request->withParsedBody($body + ['location_id' => $a]), new Response());
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('stock.error.stock_take_exists', json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
        $response = $action->create($request->withParsedBody($body + ['location_id' => $b]), new Response());
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $takeB = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $request = $this->takeRequest($supplierId, 'GET');
        $response = $action->list($request->withQueryParams(['location_id' => (string) $b]), new Response());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([$takeB['id']], array_column(json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR), 'id'));
        $response = $action->list($request->withQueryParams(['location_id' => '0']), new Response());
        self::assertSame(422, $response->getStatusCode());
    }

    public function testLocationTakePreparesHistoricalQuantitiesAndPostsScopedAdjustments(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $locationId = $this->location($supplierId, $warehouseId, 'A');
        $otherLocationId = $this->location($supplierId, $warehouseId, 'B');
        $here = $this->item($supplierId, 'HERE');
        $there = $this->item($supplierId, 'THERE');
        $found = $this->item($supplierId, 'FOUND');
        $tracked = $this->itemsRepo->insert($supplierId, [
            'sku' => 'TRACKED', 'name' => 'Sledovaná karta', 'item_type' => 'goods', 'unit' => 'ks', 'tracking_mode' => 'lot',
        ]);
        $this->receiveAt($supplierId, $warehouseId, $locationId, $here, '4.000', '10.000000', '2099-01-01');
        $this->receiveAt($supplierId, $warehouseId, $otherLocationId, $here, '6.000', '20.000000', '2099-01-01');
        $this->receiveAt($supplierId, $warehouseId, $otherLocationId, $there, '3.000', '10.000000', '2099-01-01');
        $this->receiveAt($supplierId, $warehouseId, $locationId, $here, '5.000', '16.000000', '2099-03-01');

        $body = $this->takeBody($warehouseId, '2099-02-01') + ['location_id' => $locationId];
        $take = $this->takes->create($supplierId, $body, $this->userId);
        $action = $this->container->get(StockTakeAction::class);
        $response = $action->start($this->takeRequest($supplierId, 'POST'), new Response(), ['id' => $take['id']]);
        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());
        $jobs = $this->container->get(StockValuationJobService::class);
        for ($i = 0; $i < 20 && $this->takes->get($supplierId, $take['id'])['status'] === 'preparing'; $i++) {
            $jobs->tick($supplierId);
        }
        $prepared = $this->takes->get($supplierId, $take['id']);
        self::assertSame('counting', $prepared['status']);
        self::assertSame([$here], array_column($prepared['lines'], 'stock_item_id'));
        $line = $prepared['lines'][0];
        self::assertSame('4.000', $line['expected_qty']);
        self::assertSame('64.00', $line['expected_value']);
        self::assertSame('16.000000', $line['surplus_unit_cost']);

        try {
            $this->takes->updateCounts($supplierId, $take['id'], ['add_item_ids' => [$tracked]], $this->userId);
            self::fail('Inventura lokace nesmí ručně přidat sledovanou kartu.');
        } catch (StockException $e) {
            self::assertSame('invalid_document', $e->errorCode);
        }
        $withFound = $this->takes->updateCounts($supplierId, $take['id'], ['add_item_ids' => [$found]], $this->userId);
        $foundLine = $this->findLine($withFound, $found);
        self::assertSame('0.000', $foundLine['expected_qty']);
        $this->takes->updateCounts($supplierId, $take['id'], ['lines' => [
            ['id' => $line['id'], 'counted_qty' => '3.000'],
            ['id' => $foundLine['id'], 'counted_qty' => '2.000', 'surplus_unit_cost' => '5.000000'],
        ]], $this->userId);
        $closed = $this->takes->close($supplierId, $take['id'], $this->userId);
        self::assertSame($locationId, $closed['issue_document']['lines'][0]['location_id']);
        self::assertSame($locationId, $closed['receipt_document']['lines'][0]['location_id']);
        $locations = $this->container->get(StockLocationService::class);
        $quantities = $locations->quantitiesAt($supplierId, $warehouseId, $locationId, '2099-02-01');
        ksort($quantities);
        self::assertSame([$here => '3.000', $found => '2.000'], $quantities);
        self::assertSame('6.000', $locations->quantitiesAt($supplierId, $warehouseId, $otherLocationId, '2099-02-01')[$here]);
        self::assertSame('8.000', $locations->quantitiesAt($supplierId, $warehouseId, $locationId, '2099-03-01')[$here]);
    }

    private function location(int $supplierId, int $warehouseId, string $code): int
    {
        return $this->container->get(StockTrackingRepository::class)
            ->saveLocation($supplierId, $warehouseId, null, $code, 'Regál ' . $code, true);
    }

    private function receiveAt(int $supplierId, int $warehouseId, int $locationId, int $itemId, string $qty, string $cost, string $date): void
    {
        $draft = $this->documents->create($supplierId, [
            'doc_type' => 'receipt',
            'warehouse_id' => $warehouseId,
            'doc_date' => $date,
            'description' => 'Příjem na regál',
            'lines' => [['stock_item_id' => $itemId, 'location_id' => $locationId, 'qty' => $qty, 'unit_cost' => $cost]],
        ], $this->userId);
        $this->documents->post($supplierId, $draft['id'], $this->userId);
    }

    private function takeRequest(int $supplierId, string $method): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/api/v1/stock/takes', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(101, 'Testovací skladník', 'staff', true, ['stock' => 1, 'stock.take' => 2]));
    }

    /** @return array<string,mixed> */
    private function takeBody(int $warehouseId, string $date): array
    {
        return [
            'warehouse_id' => $warehouseId,
            'take_date' => $date,
            'counting_method' => 'physical_count',
            'responsible_count_name' => 'Testovací skladník',
            'responsible_inventory_name' => 'Testovací vedoucí',
        ];
    }

    /** @param array<string,mixed> $take */
    private function findLine(array $take, int $stockItemId): array
    {
        foreach ($take['lines'] as $l) {
            if ((int) $l['stock_item_id'] === $stockItemId) {
                return $l;
            }
        }
        self::fail("řádek pro kartu #$stockItemId nenalezen.");
    }
}
