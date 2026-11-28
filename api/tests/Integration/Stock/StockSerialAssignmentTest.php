<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Stock\StockTrackingAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\StockLocationRepository;
use MyInvoice\Repository\StockTrackingRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockLedgerReader;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class StockSerialAssignmentTest extends StockTestCase
{
    public function testAssignsLocatedSerialsWithoutChangingStockValueAndSupportsIssueAndTransfer(): void
    {
        [$supplier, $warehouse, $item] = $this->openingStock();
        $destination = $this->warehouse($supplier, 'DRUHY', false);
        $location = $this->location($supplier, $warehouse, 'A');
        $target = $this->location($supplier, $destination, 'B');
        $ledger = $this->container->get(StockLedgerReader::class);
        $date = date('Y-m-d');
        $before = $ledger->page($supplier, $warehouse, $item, $date);
        self::assertSame([['warehouse_id' => $warehouse, 'warehouse_code' => 'HLAVNI', 'quantity' => 3]],
            $this->overview($supplier, $item)['serial_assignment_stock']);

        $result = $this->assign($supplier, $item, $warehouse, ['0001', 'SN-2'], $location);
        self::assertSame(201, $result->getStatusCode());
        $assignment = $this->json($result);
        self::assertSame(2, $assignment['assigned']);
        $document = $this->docsRepo->find($supplier, $assignment['document_id']);
        self::assertSame('serial_assignment', $document['origin']);
        self::assertSame('posted', $document['status']);
        self::assertSame($warehouse, $document['warehouse_to_id']);
        self::assertSame(['qtyT' => 3000, 'valueC' => 3702], $this->levels->current($supplier, $warehouse, $item));
        self::assertSame($before, $ledger->page($supplier, $warehouse, $item, $date));
        self::assertSame(['0001', 'SN-2'], array_column($this->overview($supplier, $item)['inventory'], 'serial_number'));
        self::assertSame(1, $this->overview($supplier, $item)['serial_assignment_stock'][0]['quantity']);
        self::assertSame('2.000', $this->container->get(StockLocationRepository::class)
            ->balances($supplier, $location, $item, $warehouse, 10, 0)['rows'][0]['quantity']);

        $issue = $this->postDocument($supplier, $warehouse, $item, 'issue', $date, '1', [
            'serial_number' => '0001', 'location_id' => $location, 'quantity' => '1',
        ]);
        $transfer = $this->postDocument($supplier, $warehouse, $item, 'transfer', $date, '1', [
            'serial_number' => 'SN-2', 'location_id' => $location, 'location_to_id' => $target, 'quantity' => '1',
        ], $destination);
        self::assertSame('12.34', $issue['lines'][0]['value_total']);
        self::assertSame('12.34', $transfer['lines'][0]['value_total']);
        self::assertSame(['qtyT' => 1000, 'valueC' => 1234], $this->levels->current($supplier, $warehouse, $item));
        self::assertSame(['qtyT' => 1000, 'valueC' => 1234], $this->levels->current($supplier, $destination, $item));
        $remainingSerial = $this->overview($supplier, $item)['inventory'][0];
        self::assertSame('SN-2', $remainingSerial['serial_number']);
        self::assertSame($target, $remainingSerial['location_id']);
    }

    public function testRejectsDuplicateExcessAndInvalidLocationAndRollsBackTheBatch(): void
    {
        [$supplier, $warehouse, $item] = $this->openingStock();
        $otherSupplier = $this->createSupplier();
        $otherWarehouse = $this->warehouse($otherSupplier);
        $foreignLocation = $this->location($otherSupplier, $otherWarehouse, 'CIZI');
        $inactiveLocation = $this->tracking()->saveLocation($supplier, $warehouse, null, 'OFF', 'OFF', false);
        self::assertSame(422, $this->assign($supplier, $item, $warehouse, ['A'], $foreignLocation)->getStatusCode());
        self::assertSame(422, $this->assign($supplier, $item, $warehouse, ['A'], $inactiveLocation)->getStatusCode());
        self::assertSame(422, $this->assign($supplier, $item, $warehouse, ['Dup', 'DUP'])->getStatusCode());
        self::assertSame(409, $this->assign($supplier, $item, $warehouse, ['A', 'B', 'C', 'D'])->getStatusCode());
        self::assertSame([], $this->tracking()->history($supplier, $item));
        self::assertNull($this->tracking()->findUnitByKey($supplier, $item, 'serial:Dup'));

        self::assertSame(201, $this->assign($supplier, $item, $warehouse, ['Z-EXISTUJICI'])->getStatusCode());
        self::assertSame(409, $this->assign($supplier, $item, $warehouse, ['A', 'Z-EXISTUJICI'])->getStatusCode());
        self::assertNull($this->tracking()->findUnitByKey($supplier, $item, 'serial:A'));
        self::assertSame(2, $this->overview($supplier, $item)['serial_assignment_stock'][0]['quantity']);
        self::assertSame(201, $this->assign($supplier, $item, $warehouse, ['A', 'B'])->getStatusCode());
        self::assertSame([], $this->overview($supplier, $item)['serial_assignment_stock']);
    }

    public function testReversalReleasesIdentitiesAndProtectsTheirUnderlyingStock(): void
    {
        [$supplier, $warehouse, $item, $opening] = $this->openingStock();
        $assignment = $this->json($this->assign($supplier, $item, $warehouse, ['SN-1', 'SN-2']));
        try {
            $this->documents->reverse($supplier, $opening['id'], [], $this->userId);
            self::fail('Storno příjemky by odebralo kusy, které už mají přiřazenou sérii.');
        } catch (StockException $e) {
            self::assertSame('serial_assignment_insufficient_stock', $e->errorCode);
        }
        self::assertSame('posted', $this->docsRepo->find($supplier, $opening['id'])['status']);
        $reversal = $this->documents->reverse($supplier, $assignment['document_id'], [], $this->userId)['reversal'];
        self::assertSame([], $this->tracking()->inventory($supplier, $item));
        self::assertSame(['qtyT' => 3000, 'valueC' => 3702], $this->levels->current($supplier, $warehouse, $item));
        $this->documents->reverse($supplier, $opening['id'], [], $this->userId);
        try {
            $this->documents->reverse($supplier, $reversal['id'], [], $this->userId);
            self::fail('Storno protidokladu by obnovilo série bez skladové zásoby.');
        } catch (StockException $e) {
            self::assertSame('serial_assignment_insufficient_stock', $e->errorCode);
        }
        self::assertSame([], $this->tracking()->inventory($supplier, $item));
        self::assertSame(['qtyT' => 0, 'valueC' => 0], $this->levels->current($supplier, $warehouse, $item));
    }

    public function testRequiresStockDocumentWritePermissionAndOwnSerialCard(): void
    {
        [$supplier, $warehouse, $item] = $this->openingStock();
        $readOnly = ['stock' => 1, 'stock.items.write' => 2];
        self::assertArrayNotHasKey('serial_assignment_stock', $this->overview($supplier, $item, $readOnly));
        self::assertSame(403, $this->assign($supplier, $item, $warehouse, ['SN'], permissions: $readOnly)->getStatusCode());
        $foreign = $this->createSupplier();
        $foreignItem = $this->item($foreign, 'CIZI-KARTA');
        self::assertSame(404, $this->assign($supplier, $foreignItem, $warehouse, ['SN'])->getStatusCode());
        $plainItem = $this->item($supplier, 'NESLEDOVANA');
        self::assertSame(422, $this->assign($supplier, $plainItem, $warehouse, ['SN'])->getStatusCode());
        self::assertSame([], $this->tracking()->inventory($supplier, $item));
    }

    public function testCannotConsumeSerialBeforeItsAssignmentDate(): void
    {
        [$supplier, $warehouse, $item] = $this->openingStock();
        self::assertSame(201, $this->assign($supplier, $item, $warehouse, ['SN'])->getStatusCode());
        try {
            $this->postDocument($supplier, $warehouse, $item, 'issue', date('Y-m-d', strtotime('-1 day')), '1', [
                'serial_number' => 'SN', 'quantity' => '1',
            ]);
            self::fail('Výdej s dřívějším datem by použil dosud nepřiřazenou sérii.');
        } catch (StockException $e) {
            self::assertSame('tracking_insufficient_stock', $e->errorCode);
        }
        self::assertSame(['qtyT' => 3000, 'valueC' => 3702], $this->levels->current($supplier, $warehouse, $item));
    }

    public function testCannotReverseAssignmentAfterSerialHasBeenIssuedAndReceivedAgain(): void
    {
        [$supplier, $warehouse, $item] = $this->openingStock();
        $assignment = $this->json($this->assign($supplier, $item, $warehouse, ['SN']));
        $allocation = ['serial_number' => 'SN', 'quantity' => '1'];
        $date = date('Y-m-d');
        $this->postDocument($supplier, $warehouse, $item, 'issue', $date, '1', $allocation);
        $this->postDocument($supplier, $warehouse, $item, 'receipt', $date, '1', $allocation);
        try {
            $this->documents->reverse($supplier, $assignment['document_id'], [], $this->userId);
            self::fail('Storno přiřazení by odebralo sérii z nově přijatého kusu.');
        } catch (StockException $e) {
            self::assertSame('serial_assignment_already_used', $e->errorCode);
        }
        self::assertSame(['SN'], array_column($this->tracking()->inventory($supplier, $item), 'serial_number'));
    }

    private function openingStock(): array
    {
        $supplier = $this->createSupplier();
        $warehouse = $this->warehouse($supplier);
        $item = $this->item($supplier, 'UVODNI-KARTA');
        $opening = $this->postDocument($supplier, $warehouse, $item, 'receipt', date('Y-m-d', strtotime('-2 days')), '3');
        $stmt = $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'serial' WHERE supplier_id = ? AND id = ?");
        $stmt->execute([$supplier, $item]);
        return [$supplier, $warehouse, $item, $opening];
    }

    private function postDocument(
        int $supplier, int $warehouse, int $item, string $type, string $date, string $qty,
        ?array $allocation = null, ?int $destination = null,
    ): array {
        $draft = $this->documents->create($supplier, [
            'doc_type' => $type, 'warehouse_id' => $warehouse, 'warehouse_to_id' => $destination,
            'doc_date' => $date, 'description' => 'Test sériových čísel',
            'lines' => [[
                'stock_item_id' => $item, 'qty' => $qty, 'unit_cost' => '12.34',
                'tracking_allocations' => $allocation === null ? [] : [$allocation],
            ]],
        ], $this->userId);
        return $this->documents->post($supplier, $draft['id'], $this->userId);
    }

    private function location(int $supplier, int $warehouse, string $code): int
    {
        return $this->tracking()->saveLocation($supplier, $warehouse, null, $code, $code, true);
    }

    private function tracking(): StockTrackingRepository
    {
        return $this->container->get(StockTrackingRepository::class);
    }

    private function request(string $method, string $path, int $supplier, array $permissions): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withAttribute('auth.effective_role', new EffectiveRole(101, 'Sklad', 'staff', true, $permissions));
    }

    private function assign(
        int $supplier, int $item, int $warehouse, array $serials, ?int $location = null,
        array $permissions = ['stock' => 1, 'stock.documents.write' => 2],
    ): \Psr\Http\Message\ResponseInterface {
        $request = $this->request('POST', "/api/stock/items/{$item}/serial-assignments", $supplier, $permissions)
            ->withParsedBody(['warehouse_id' => $warehouse, 'location_id' => $location, 'serial_numbers' => $serials]);
        return $this->container->get(StockTrackingAction::class)->assignSerials($request, new Response(), ['id' => $item]);
    }

    private function overview(int $supplier, int $item, array $permissions = ['stock' => 1, 'stock.documents.write' => 2]): array
    {
        $request = $this->request('GET', "/api/stock/items/{$item}/tracking", $supplier, $permissions);
        return $this->json($this->container->get(StockTrackingAction::class)->item($request, new Response(), ['id' => $item]));
    }

    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
