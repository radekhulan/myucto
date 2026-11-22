<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Invoice\BulkReissueAction;
use MyInvoice\Action\Invoice\CancelInvoiceAction;
use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Řádek faktury nese vybraná sériová čísla nebo šarže sledované karty
 * (`tracking_allocations`). Výběr přežije úpravu konceptu (replaceItems je DELETE +
 * INSERT), dobropis z faktury ho převezme, kopie faktury ne.
 */
#[Group('integration')]
final class InvoiceItemTrackingTest extends StockTestCase
{
    /** Vystavení přes akci zakládá čítače číselné řady; StockTestCase je neuklízí. */
    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($this->supplierIds as $sid) {
                $pdo->prepare('DELETE FROM invoice_counters WHERE supplier_id = ?')->execute([$sid]);
            }
        }
        parent::tearDown();
    }

    public function testDraftRoundTripKeepsTrackingAcrossEdits(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-1', 'serial');
        $invoiceId = $this->createInvoice($sid, [
            $this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-A']]),
            $this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-B']]),
        ]);

        $items = $this->storedItems($invoiceId);
        self::assertSame(['VIN-A', 'VIN-B'], [$items[0]['tracking_allocations'][0]['serial_number'], $items[1]['tracking_allocations'][0]['serial_number']]);
        self::assertSame('1.000', $items[0]['tracking_allocations'][0]['quantity']);

        $items[0]['unit_price_without_vat'] = 250000;
        $res = $this->updateInvoice($sid, $invoiceId, ['items' => $items]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $after = $this->storedItems($invoiceId);
        self::assertSame(['VIN-A', 'VIN-B'], [$after[0]['tracking_allocations'][0]['serial_number'], $after[1]['tracking_allocations'][0]['serial_number']]);

        $res = $this->updateInvoice($sid, $invoiceId, ['discount_percent' => 5]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('VIN-A', $this->storedItems($invoiceId)[0]['tracking_allocations'][0]['serial_number'], 'Přehrání uložených řádků při změně slevy výběr zachová.');
    }

    public function testLineWithoutTrackingReturnsEmptyList(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $plain = $this->item($sid, 'PLAIN-1');
        $invoiceId = $this->createInvoice($sid, [$this->line($plain, $wh, '2', null)]);
        self::assertSame([], $this->storedItems($invoiceId)[0]['tracking_allocations']);
    }

    public function testTrackingOnUntrackedItemIsRejected(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $plain = $this->item($sid, 'PLAIN-2');
        $res = $this->createInvoiceResponse($sid, [$this->line($plain, $wh, '1', [['quantity' => '1', 'serial_number' => 'X']])]);
        $this->assertError($res, 422, 'tracking_not_enabled');
    }

    public function testSerialQuantityMustBeOne(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-2', 'serial');
        $res = $this->createInvoiceResponse($sid, [$this->line($moto, $wh, '2', [['quantity' => '2', 'serial_number' => 'VIN-C']])]);
        $this->assertError($res, 422, 'invalid_tracking_quantity');
    }

    public function testTrackingWithoutStockItemIsRejected(): void
    {
        $sid = $this->createSupplier();
        $res = $this->createInvoiceResponse($sid, [$this->line(null, null, '1', [['quantity' => '1', 'serial_number' => 'VIN-D']])]);
        $this->assertError($res, 422, 'tracking_not_enabled');
    }

    public function testStockUnitIdIsNotAcceptedFromInvoice(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-3', 'serial');
        $invoiceId = $this->createInvoice($sid, [
            $this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-E', 'stock_tracking_unit_id' => 999999, 'location_id' => 3, 'location_to_id' => 5]]),
        ]);
        $stored = $this->storedItems($invoiceId)[0]['tracking_allocations'][0];
        self::assertArrayNotHasKey('stock_tracking_unit_id', $stored, 'Identita se ukládá textem, ne id jednotky.');
        self::assertArrayNotHasKey('location_to_id', $stored);
        self::assertArrayNotHasKey('location_id', $stored, 'Umístění se dohledá až při výdeji (obnova archivu, přesuny ve skladu).');
    }

    public function testLotWithExpiryRoundTrips(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $oil = $this->trackedItem($sid, 'OIL-1', 'lot');
        $invoiceId = $this->createInvoice($sid, [
            $this->line($oil, $wh, '3', [['quantity' => '2', 'lot_code' => 'L1', 'expires_on' => '2099-12-31'], ['quantity' => '1', 'lot_code' => 'L2']]),
        ]);
        $alloc = $this->storedItems($invoiceId)[0]['tracking_allocations'];
        self::assertSame([['L1', '2099-12-31', '2.000'], ['L2', null, '1.000']], array_map(
            static fn (array $a): array => [$a['lot_code'], $a['expires_on'], $a['quantity']],
            $alloc,
        ));
    }

    public function testCreditNoteFromInvoiceCopiesTracking(): void
    {
        $sid = $this->createSupplier(autoIssue: false);
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-4', 'serial');
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-F']])]);
        $this->markIssued($invoiceId);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody(['mode' => 'credit_note', 'reason' => 'Vrácení motocyklu']);
        $res = $this->container->get(CancelInvoiceAction::class)($request, new Psr7Response(), ['id' => (string) $invoiceId]);
        $body = $this->body($res);
        self::assertSame(201, $res->getStatusCode(), json_encode($body, JSON_UNESCAPED_UNICODE));

        $credit = $this->storedItems((int) $body['credit_note_id']);
        self::assertSame('VIN-F', $credit[0]['tracking_allocations'][0]['serial_number']);
    }

    public function testCloneDoesNotCopyTracking(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-5', 'serial');
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-G']])]);

        $cloneId = $this->container->get(BulkReissueAction::class)->cloneOne($invoiceId, '2099-06-20', false, $this->userId);
        $clone = $this->storedItems($cloneId);
        self::assertSame($moto, $clone[0]['stock_item_id'], 'Kopie převezme kartu.');
        self::assertSame([], $clone[0]['tracking_allocations'], 'Konkrétní kus se znovu neprodává.');
    }

    public function testFinalInvoiceFromProformaKeepsTracking(): void
    {
        $sid = $this->createSupplier(autoIssue: false);
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-6', 'serial');
        $proformaId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-H']])], 'proforma');
        $this->markIssued($proformaId);

        $finalId = $this->container->get(\MyInvoice\Service\Invoice\FinalFromProformaCreator::class)->create($proformaId, $this->userId);
        self::assertSame('VIN-H', $this->storedItems($finalId)[0]['tracking_allocations'][0]['serial_number'], 'Sklad hýbe až finál, výběr z proformy přechází s ním.');
    }

    public function testForeignTrackedItemIsRejected(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $foreign = $this->trackedItem($this->createSupplier(), 'CIZI-MOTO', 'serial');
        $res = $this->createInvoiceResponse($sid, [$this->line($foreign, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-X']])]);
        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
    }

    public function testInjectedTrackingJsonIsIgnored(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-20', 'serial');
        $line = $this->line($moto, $wh, '1', null);
        $line['tracking_json'] = '[{"quantity":"1.000","serial_number":"PODVRH"}]';
        $invoiceId = $this->createInvoice($sid, [$line]);
        self::assertSame([], $this->storedItems($invoiceId)[0]['tracking_allocations'], 'tracking_json z těla požadavku obchází validaci.');

        $items = $this->storedItems($invoiceId);
        $items[0]['tracking_json'] = '[{"quantity":"1.000","serial_number":"PODVRH"}]';
        self::assertSame(200, $this->updateInvoice($sid, $invoiceId, ['items' => $items])->getStatusCode());
        self::assertSame([], $this->storedItems($invoiceId)[0]['tracking_allocations']);
    }

    public function testPutWithoutTrackingClearsSelection(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-21', 'serial');
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-K']])]);
        $items = $this->storedItems($invoiceId);
        unset($items[0]['tracking_allocations']);
        self::assertSame(200, $this->updateInvoice($sid, $invoiceId, ['items' => $items])->getStatusCode());
        self::assertSame([], $this->storedItems($invoiceId)[0]['tracking_allocations'], 'Položky se nahrazují celé, výběr se posílá s nimi.');
    }

    public function testDeletedCardHidesStaleSelection(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-22', 'serial');
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-L']])]);
        // fk_ii_stock_item je ON DELETE SET NULL: smazaná karta nechá řádek bez vazby.
        $this->db->pdo()->prepare('UPDATE invoice_items SET stock_item_id = NULL WHERE invoice_id = ?')->execute([$invoiceId]);

        $items = $this->storedItems($invoiceId);
        self::assertSame([], $items[0]['tracking_allocations'], 'Řádek bez karty výběr nenabízí.');
        self::assertSame(200, $this->updateInvoice($sid, $invoiceId, ['items' => $items])->getStatusCode(), 'GET → PUT projde.');
    }

    public function testTrackingModeChangeIsBlockedWhileInvoiceSelectsUnits(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-23', 'serial');
        $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-M']])]);

        $res = $this->container->get(\MyInvoice\Action\Stock\StockItemAction::class)->update(
            $this->request('PUT', $sid, ['tracking_mode' => 'none']), new Psr7Response(), ['id' => (string) $moto],
        );
        $this->assertError($res, 409, 'tracking_mode_in_use');
    }

    public function testIssuedInvoiceWithStockDocumentRejectsSelectionChange(): void
    {
        $sid = $this->createSupplier(autoIssue: false);
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-24', 'serial');
        $this->receiveUnits($sid, $wh, $moto, ['VIN-N', 'VIN-O']);
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-N']])]);
        $this->markIssued($invoiceId);
        $issue = $this->documents->create($sid, [
            'doc_type' => 'issue', 'origin' => 'invoice', 'warehouse_id' => $wh, 'doc_date' => '2099-06-10',
            'description' => 'Výdej k faktuře', 'invoice_id' => $invoiceId,
            'lines' => [['stock_item_id' => $moto, 'qty' => '1.000', 'invoice_item_id' => $this->storedItems($invoiceId)[0]['id'],
                'tracking_allocations' => [['quantity' => '1', 'serial_number' => 'VIN-N']]]],
        ], $this->userId);
        $this->documents->post($sid, (int) $issue['id'], $this->userId);

        $items = $this->storedItems($invoiceId);
        $items[0]['tracking_allocations'] = [['quantity' => '1', 'serial_number' => 'VIN-O']];
        $res = $this->container->get(UpdateInvoiceAction::class)(
            $this->request('PUT', $sid, ['items' => $items])->withQueryParams(['force' => '1']), new Psr7Response(), ['id' => (string) $invoiceId],
        );
        $this->assertError($res, 409, 'tracking_locked');
        self::assertSame('VIN-N', $this->storedItems($invoiceId)[0]['tracking_allocations'][0]['serial_number']);

        $items = $this->storedItems($invoiceId);
        $items[0]['description'] = 'Motocykl, opravený popis';
        $res = $this->container->get(UpdateInvoiceAction::class)(
            $this->request('PUT', $sid, ['items' => $items])->withQueryParams(['force' => '1']), new Psr7Response(), ['id' => (string) $invoiceId],
        );
        self::assertSame(200, $res->getStatusCode(), 'Bez změny výběru úprava projde: ' . $res->getBody());
        self::assertSame('VIN-N', $this->storedItems($invoiceId)[0]['tracking_allocations'][0]['serial_number']);
    }

    // ── vystavení: výdejka, vratka, storno ──────────────────────────────────

    public function testIssueAllocatesChosenSerials(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-10', 'serial');
        $this->receiveTracked($sid, $wh, $moto, [['quantity' => '1', 'serial_number' => 'VIN-A'], ['quantity' => '1', 'serial_number' => 'VIN-B']]);
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-B']])]);

        $res = $this->issue($sid, $invoiceId);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(0, $this->serialBalance($sid, $moto, 'VIN-B'), 'Vydal se vybraný kus.');
        self::assertSame(1000, $this->serialBalance($sid, $moto, 'VIN-A'), 'Druhý kus zůstal skladem.');
        self::assertSame(['VIN-B'], $this->issuedSerials($sid, $invoiceId), 'Řádek výdejky je navázaný na řádek faktury se sériovým číslem.');
    }

    public function testIssueWithoutSelectionFailsBeforeNumbering(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-11', 'serial');
        $this->receiveTracked($sid, $wh, $moto, [['quantity' => '1', 'serial_number' => 'VIN-C']]);
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', null)]);

        $res = $this->issue($sid, $invoiceId);
        $this->assertError($res, 422, 'stock.error.tracking_selection_required');
        self::assertSame('MOTO-11', $this->body($res)['error']['items'][0]['sku'] ?? null, 'Chyba jmenuje kartu, u které výběr chybí.');
        $row = $this->invoiceRow($invoiceId);
        self::assertSame(['draft', null], [$row['status'], $row['varsymbol']], 'Koncept bez čísla, řada nespálená.');
    }

    public function testIncompleteSelectionFails(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-12', 'serial');
        $this->receiveTracked($sid, $wh, $moto, [['quantity' => '1', 'serial_number' => 'VIN-D'], ['quantity' => '1', 'serial_number' => 'VIN-E']]);
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '2', [['quantity' => '1', 'serial_number' => 'VIN-D']])]);

        $this->assertError($this->issue($sid, $invoiceId), 422, 'stock.error.tracking_selection_required');
    }

    public function testIssueOfSoldSerialFails(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-13', 'serial');
        $this->receiveTracked($sid, $wh, $moto, [['quantity' => '1', 'serial_number' => 'VIN-F'], ['quantity' => '1', 'serial_number' => 'VIN-G']]);
        $first = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-F']])]);
        self::assertSame(200, $this->issue($sid, $first)->getStatusCode());
        $second = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-F']])]);

        $res = $this->issue($sid, $second);
        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('stock.error.tracking_insufficient_stock', $this->body($res)['error']['code'] ?? null);
        self::assertSame('draft', $this->invoiceRow($second)['status']);
        self::assertSame(1000, $this->serialBalance($sid, $moto, 'VIN-G'));
    }

    public function testLotSelectionIssues(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $oil = $this->trackedItem($sid, 'OIL-10', 'lot');
        $this->receiveTracked($sid, $wh, $oil, [['quantity' => '5', 'lot_code' => 'L1', 'expires_on' => '2099-12-31']]);
        $invoiceId = $this->createInvoice($sid, [$this->line($oil, $wh, '2', [['quantity' => '2', 'lot_code' => 'L1', 'expires_on' => '2099-12-31']])]);

        self::assertSame(200, $this->issue($sid, $invoiceId)->getStatusCode());
        self::assertSame(3000, $this->lotBalance($sid, $oil, 'L1', '2099-12-31'));
    }

    public function testCreditNoteReturnsSameSerial(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-14', 'serial');
        $this->receiveTracked($sid, $wh, $moto, [['quantity' => '1', 'serial_number' => 'VIN-H']]);
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-H']])]);
        self::assertSame(200, $this->issue($sid, $invoiceId)->getStatusCode());

        $creditId = $this->cancel($sid, $invoiceId, 'credit_note')['credit_note_id'];
        $res = $this->issue($sid, (int) $creditId);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(1000, $this->serialBalance($sid, $moto, 'VIN-H'), 'Dobropis vrátil tentýž kus na sklad.');
    }

    public function testInternalCancelRestoresSerialAndUncancelIssuesItAgain(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-15', 'serial');
        $this->receiveTracked($sid, $wh, $moto, [['quantity' => '1', 'serial_number' => 'VIN-I']]);
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-I']])]);
        self::assertSame(200, $this->issue($sid, $invoiceId)->getStatusCode());

        $this->cancel($sid, $invoiceId, 'internal');
        self::assertSame(1000, $this->serialBalance($sid, $moto, 'VIN-I'), 'Interní storno vrátí kus.');

        $res = $this->container->get(\MyInvoice\Action\Invoice\UncancelInvoiceAction::class)(
            $this->request('POST', $sid, []), new Psr7Response(), ['id' => (string) $invoiceId],
        );
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(0, $this->serialBalance($sid, $moto, 'VIN-I'), 'Obnovení po stornu vydá tentýž kus znovu.');
    }

    public function testIssueFindsLocationOfSelectedSerial(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $shelf = $this->location($sid, $wh, 'A-01');
        $moto = $this->trackedItem($sid, 'MOTO-30', 'serial');
        $this->receiveTracked($sid, $wh, $moto, [['quantity' => '1', 'serial_number' => 'VIN-P', 'location_id' => $shelf]]);
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-P']])]);

        $res = $this->issue($sid, $invoiceId);
        self::assertSame(200, $res->getStatusCode(), 'Faktura umístění nezná, výdej ho dohledá: ' . $res->getBody());
        self::assertSame(0, $this->serialBalance($sid, $moto, 'VIN-P'));
    }

    public function testIssueSplitsLotAcrossLocations(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $a = $this->location($sid, $wh, 'A-02');
        $b = $this->location($sid, $wh, 'B-02');
        $oil = $this->trackedItem($sid, 'OIL-30', 'lot');
        $this->receiveTracked($sid, $wh, $oil, [
            ['quantity' => '2', 'lot_code' => 'L9', 'expires_on' => '2099-12-31', 'location_id' => $a],
            ['quantity' => '3', 'lot_code' => 'L9', 'expires_on' => '2099-12-31', 'location_id' => $b],
        ]);
        $invoiceId = $this->createInvoice($sid, [$this->line($oil, $wh, '4', [['quantity' => '4', 'lot_code' => 'L9', 'expires_on' => '2099-12-31']])]);

        self::assertSame(200, $this->issue($sid, $invoiceId)->getStatusCode());
        self::assertSame(1000, $this->lotBalance($sid, $oil, 'L9', '2099-12-31'), 'Šarže se vydala ze dvou umístění.');
        self::assertSame([0, 1000], [$this->locationBalance($sid, $a), $this->locationBalance($sid, $b)], 'Nejdřív se vyčerpalo umístění A-02 (řazení podle kódu umístění).');
    }

    public function testLotShortageReportsTheLotInsteadOfDuplicate(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $oil = $this->trackedItem($sid, 'OIL-40', 'lot');
        $this->receiveTracked($sid, $wh, $oil, [['quantity' => '3', 'lot_code' => 'L1'], ['quantity' => '7', 'lot_code' => 'L2']]);
        $invoiceId = $this->createInvoice($sid, [$this->line($oil, $wh, '5', [['quantity' => '5', 'lot_code' => 'L1']])]);

        $res = $this->issue($sid, $invoiceId);
        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('stock.error.tracking_insufficient_stock', $this->body($res)['error']['code'] ?? null);
        self::assertStringContainsString('L1', (string) ($this->body($res)['error']['message'] ?? ''), 'Hláška jmenuje šarži.');
        self::assertSame('draft', $this->invoiceRow($invoiceId)['status']);
    }

    public function testSameLotListedTwiceIsMerged(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $oil = $this->trackedItem($sid, 'OIL-41', 'lot');
        $this->receiveTracked($sid, $wh, $oil, [['quantity' => '5', 'lot_code' => 'L1']]);
        $invoiceId = $this->createInvoice($sid, [$this->line($oil, $wh, '5', [['quantity' => '2', 'lot_code' => 'L1'], ['quantity' => '3', 'lot_code' => 'L1']])]);
        self::assertSame(200, $this->issue($sid, $invoiceId)->getStatusCode());
        self::assertSame(0, $this->lotBalance($sid, $oil, 'L1', ''));
    }

    public function testTwoLinesOfSameLotShareStock(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $oil = $this->trackedItem($sid, 'OIL-42', 'lot');
        $this->receiveTracked($sid, $wh, $oil, [['quantity' => '4', 'lot_code' => 'L1']]);
        $invoiceId = $this->createInvoice($sid, [
            $this->line($oil, $wh, '3', [['quantity' => '3', 'lot_code' => 'L1']]),
            $this->line($oil, $wh, '2', [['quantity' => '2', 'lot_code' => 'L1']]),
        ]);
        $res = $this->issue($sid, $invoiceId);
        self::assertSame(409, $res->getStatusCode(), 'Dva řádky dohromady chtějí 5 ze 4: ' . $res->getBody());
        self::assertSame(4000, $this->lotBalance($sid, $oil, 'L1', ''));
    }

    public function testSerialMatchIgnoresLetterCase(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $shelf = $this->location($sid, $wh, 'C-01');
        $moto = $this->trackedItem($sid, 'MOTO-40', 'serial');
        $this->receiveTracked($sid, $wh, $moto, [['quantity' => '1', 'serial_number' => 'VIN-Q', 'location_id' => $shelf]]);
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'vin-q']])]);
        self::assertSame(200, $this->issue($sid, $invoiceId)->getStatusCode());
        self::assertSame(0, $this->serialBalance($sid, $moto, 'VIN-Q'));
    }

    public function testPackagingLineNeedsSelectionInBaseUnits(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $oil = $this->trackedItem($sid, 'OIL-43', 'lot');
        $this->db->pdo()->prepare('INSERT INTO stock_item_units (supplier_id, stock_item_id, unit_code, numerator, denominator, is_sales_unit) VALUES (?, ?, ?, 8, 1, 1)')
            ->execute([$sid, $oil, 'KT']);
        $this->receiveTracked($sid, $wh, $oil, [['quantity' => '16', 'lot_code' => 'L1']]);
        $short = $this->line($oil, $wh, '1', [['quantity' => '1', 'lot_code' => 'L1']]);
        $short['unit'] = 'KT';
        $invoiceId = $this->createInvoice($sid, [$short]);
        $this->assertError($this->issue($sid, $invoiceId), 422, 'stock.error.tracking_selection_required');

        $full = $this->line($oil, $wh, '1', [['quantity' => '8', 'lot_code' => 'L1']]);
        $full['unit'] = 'KT';
        $invoiceId = $this->createInvoice($sid, [$full]);
        self::assertSame(200, $this->issue($sid, $invoiceId)->getStatusCode(), '1 KT = 8 ks ze šarže L1.');
        self::assertSame(8000, $this->lotBalance($sid, $oil, 'L1', ''));
    }

    public function testCreditNoteWithoutSelectionFailsBeforeNumbering(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-41', 'serial');
        $this->receiveTracked($sid, $wh, $moto, [['quantity' => '1', 'serial_number' => 'VIN-R']]);
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-R']])]);
        self::assertSame(200, $this->issue($sid, $invoiceId)->getStatusCode());
        $creditId = (int) $this->cancel($sid, $invoiceId, 'credit_note')['credit_note_id'];
        $items = $this->storedItems($creditId);
        $items[0]['tracking_allocations'] = [];
        self::assertSame(200, $this->updateInvoice($sid, $creditId, ['items' => $items])->getStatusCode());

        $this->assertError($this->issue($sid, $creditId), 422, 'stock.error.tracking_selection_required');
        self::assertSame(['draft', null], [$this->invoiceRow($creditId)['status'], $this->invoiceRow($creditId)['varsymbol']]);
    }

    public function testCreditNoteCannotReturnUnitTheInvoiceDidNotIssue(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-42', 'serial');
        $this->receiveTracked($sid, $wh, $moto, [['quantity' => '1', 'serial_number' => 'VIN-S'], ['quantity' => '1', 'serial_number' => 'VIN-T']]);
        $a = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-S']])]);
        $b = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-T']])]);
        self::assertSame(200, $this->issue($sid, $a)->getStatusCode());
        self::assertSame(200, $this->issue($sid, $b)->getStatusCode());

        $creditId = (int) $this->cancel($sid, $a, 'credit_note')['credit_note_id'];
        $items = $this->storedItems($creditId);
        $items[0]['tracking_allocations'] = [['quantity' => '1', 'serial_number' => 'VIN-T']];
        self::assertSame(200, $this->updateInvoice($sid, $creditId, ['items' => $items])->getStatusCode());

        $this->assertError($this->issue($sid, $creditId), 422, 'stock.error.tracking_not_on_parent');
        self::assertSame(0, $this->serialBalance($sid, $moto, 'VIN-T'), 'Kus prodaný jinou fakturou se na sklad nevrátí.');
    }

    public function testSecondCreditNoteCannotReturnTheSameUnitAgain(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $oil = $this->trackedItem($sid, 'OIL-44', 'lot');
        $this->receiveTracked($sid, $wh, $oil, [['quantity' => '5', 'lot_code' => 'L1']]);
        $invoiceId = $this->createInvoice($sid, [$this->line($oil, $wh, '3', [['quantity' => '3', 'lot_code' => 'L1']])]);
        self::assertSame(200, $this->issue($sid, $invoiceId)->getStatusCode());

        $first = (int) $this->cancel($sid, $invoiceId, 'credit_note')['credit_note_id'];
        $items = $this->storedItems($first);
        $items[0]['quantity'] = -2;
        $items[0]['tracking_allocations'] = [['quantity' => '2', 'lot_code' => 'L1']];
        self::assertSame(200, $this->updateInvoice($sid, $first, ['items' => $items])->getStatusCode());
        self::assertSame(200, $this->issue($sid, $first)->getStatusCode(), 'Částečný dobropis 2 ze 3.');

        $second = (int) $this->cancel($sid, $invoiceId, 'credit_note')['credit_note_id'];
        $items = $this->storedItems($second);
        $items[0]['quantity'] = -2;
        $items[0]['tracking_allocations'] = [['quantity' => '2', 'lot_code' => 'L1']];
        self::assertSame(200, $this->updateInvoice($sid, $second, ['items' => $items])->getStatusCode());
        $this->assertError($this->issue($sid, $second), 422, 'stock.error.tracking_not_on_parent');
        self::assertSame(4000, $this->lotBalance($sid, $oil, 'L1', ''), 'Vrátit jde jen 1 zbývající kus.');
    }

    public function testServiceIssueWithoutSelectionFailsClearly(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-16', 'serial');
        $this->receiveTracked($sid, $wh, $moto, [['quantity' => '1', 'serial_number' => 'VIN-J']]);
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', null)]);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->issue->issueForInvoice($sid, ['id' => $invoiceId, 'invoice_type' => 'invoice', 'issue_date' => '2099-06-10', 'varsymbol' => 'X1'], $this->userId);
            self::fail('Opakovaná faktura nebo automatické vystavení bez výběru musí skončit srozumitelnou chybou.');
        } catch (\MyInvoice\Service\Stock\StockException $e) {
            self::assertSame('tracking_selection_required', $e->errorCode);
        } finally {
            $pdo->rollBack();
        }
    }

    // ── PDF faktury ─────────────────────────────────────────────────────────

    public function testPdfListsSelectedSerialsAndLots(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-PDF', 'serial');
        $oil = $this->trackedItem($sid, 'OIL-PDF', 'lot');
        $plain = $this->item($sid, 'PLAIN-PDF');
        $invoiceId = $this->createInvoice($sid, [
            $this->line($moto, $wh, '2', [['quantity' => '1', 'serial_number' => 'VIN-PDF-1'], ['quantity' => '1', 'serial_number' => 'VIN-PDF-2']]),
            $this->line($oil, $wh, '3', [['quantity' => '2', 'lot_code' => 'L-PDF-1', 'expires_on' => '2099-12-31'], ['quantity' => '1', 'lot_code' => 'L-PDF-2']]),
            $this->line($plain, $wh, '1', null),
        ]);

        $html = $this->pdfHtml($invoiceId);
        self::assertStringContainsString('Výr. č.: VIN-PDF-1, VIN-PDF-2', $html);
        self::assertStringContainsString('Šarže: L-PDF-1 (exp. 31. 12. 2099): 2 kus, L-PDF-2: 1 kus', $html);
        self::assertSame(1, substr_count($html, 'Výr. č.:'), 'Řádek bez výběru nic nevypisuje.');
    }

    public function testPdfLotQuantityUsesBaseUnitEvenWithoutPackagingBreakdown(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $oil = $this->trackedItem($sid, 'OIL-PDF-KT', 'lot');
        $this->db->pdo()->prepare('INSERT INTO stock_item_units (supplier_id, stock_item_id, unit_code, numerator, denominator, is_sales_unit) VALUES (?, ?, ?, 8, 1, 1)')
            ->execute([$sid, $oil, 'KT']);
        $this->db->pdo()->prepare('UPDATE supplier SET invoice_pdf_show_base_qty = 0 WHERE id = ?')->execute([$sid]);
        $carton = $this->line($oil, $wh, '1', [['quantity' => '8', 'lot_code' => 'L-KT']]);
        $carton['unit'] = 'KT';
        $invoiceId = $this->createInvoice($sid, [$carton]);

        $html = $this->pdfHtml($invoiceId);
        self::assertStringContainsString('Šarže: L-KT: 8 kus', $html, 'Množství šarže je v základní jednotce karty, ne v kartonech.');
        self::assertStringNotContainsString('L-KT: 8 KT', $html);
    }

    public function testPdfInEnglishUsesEnglishLabels(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-PDF-EN', 'serial');
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '1', [['quantity' => '1', 'serial_number' => 'VIN-EN-1']])]);
        $this->db->pdo()->prepare("UPDATE invoices SET language = 'en' WHERE id = ?")->execute([$invoiceId]);

        self::assertStringContainsString('Serial no.: VIN-EN-1', $this->pdfHtml($invoiceId));
    }

    // ── nabídka kusů pro editor faktury ─────────────────────────────────────

    public function testChoicesSumUnitsAcrossLocationsOfOneWarehouse(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $other = $this->warehouse($sid, 'DRUHY', false);
        $a = $this->location($sid, $wh, 'A-50');
        $b = $this->location($sid, $wh, 'B-50');
        $oil = $this->trackedItem($sid, 'OIL-50', 'lot');
        $this->receiveTracked($sid, $wh, $oil, [
            ['quantity' => '2', 'lot_code' => 'L1', 'expires_on' => '2099-12-31', 'location_id' => $a],
            ['quantity' => '3', 'lot_code' => 'L1', 'expires_on' => '2099-12-31', 'location_id' => $b],
        ]);
        $this->receiveTracked($sid, $other, $oil, [['quantity' => '7', 'lot_code' => 'L2']]);

        $body = $this->choices($sid, $oil, ['warehouse_id' => (string) $wh]);
        self::assertSame('lot', $body['tracking_mode']);
        self::assertSame([['L1', '2099-12-31', '5.000']], array_map(
            static fn (array $c): array => [$c['lot_code'], $c['expires_on'], $c['quantity']],
            $body['in_stock'],
        ), 'Jen zvolený sklad, šarže sečtená přes umístění.');
        self::assertNull($body['returnable']);
        self::assertArrayNotHasKey('history', $body, 'Editor historii pohybů nepotřebuje.');
    }

    public function testChoicesForCreditNoteOfferWhatTheInvoiceIssuedAndIsNotReturned(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-50', 'serial');
        $this->receiveTracked($sid, $wh, $moto, [['quantity' => '1', 'serial_number' => 'VIN-50A'], ['quantity' => '1', 'serial_number' => 'VIN-50B'], ['quantity' => '1', 'serial_number' => 'VIN-50C']]);
        $invoiceId = $this->createInvoice($sid, [$this->line($moto, $wh, '2', [['quantity' => '1', 'serial_number' => 'VIN-50A'], ['quantity' => '1', 'serial_number' => 'VIN-50B']])]);
        self::assertSame(200, $this->issue($sid, $invoiceId)->getStatusCode());

        $first = (int) $this->cancel($sid, $invoiceId, 'credit_note')['credit_note_id'];
        $items = $this->storedItems($first);
        $items[0]['quantity'] = -1;
        $items[0]['tracking_allocations'] = [['quantity' => '1', 'serial_number' => 'VIN-50A']];
        self::assertSame(200, $this->updateInvoice($sid, $first, ['items' => $items])->getStatusCode());
        self::assertSame(200, $this->issue($sid, $first)->getStatusCode());

        $second = (int) $this->cancel($sid, $invoiceId, 'credit_note')['credit_note_id'];
        $body = $this->choices($sid, $moto, ['warehouse_id' => (string) $wh, 'invoice_id' => (string) $second]);
        self::assertSame(['VIN-50B'], array_column($body['returnable'], 'serial_number'), 'Vrátit jde jen to, co faktura vydala a dosud se nevrátilo.');
        self::assertSame(['VIN-50A', 'VIN-50C'], array_column($body['in_stock'], 'serial_number'));
    }

    public function testChoicesIgnoreForeignInvoice(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->trackedItem($sid, 'MOTO-51', 'serial');
        $foreignSupplier = $this->createSupplier();
        $foreignInvoice = $this->invoiceDraft($foreignSupplier, $this->client($foreignSupplier), 'credit_note');
        $body = $this->choices($sid, $moto, ['warehouse_id' => (string) $wh, 'invoice_id' => (string) $foreignInvoice]);
        self::assertNull($body['returnable']);
    }

    // ── pomocníci ────────────────────────────────────────────────────────────

    /** @param list<string> $serials */
    private function receiveUnits(int $supplierId, int $warehouseId, int $itemId, array $serials): void
    {
        $this->receiveTracked($supplierId, $warehouseId, $itemId, array_map(
            static fn (string $s): array => ['quantity' => '1', 'serial_number' => $s],
            $serials,
        ));
    }

    /** @param list<array<string,mixed>> $allocations */
    private function receiveTracked(int $supplierId, int $warehouseId, int $itemId, array $allocations): void
    {
        $qty = array_sum(array_map(static fn (array $a): float => (float) $a['quantity'], $allocations));
        $draft = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'origin' => 'manual', 'warehouse_id' => $warehouseId, 'doc_date' => '2099-01-10',
            'description' => 'Příjem',
            'lines' => [['stock_item_id' => $itemId, 'qty' => number_format($qty, 3, '.', ''), 'unit_cost' => '100.000000', 'tracking_allocations' => $allocations]],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $draft['id'], $this->userId);
    }

    private function issue(int $supplierId, int $invoiceId): ResponseInterface
    {
        return $this->container->get(\MyInvoice\Action\Invoice\IssueInvoiceAction::class)(
            $this->request('POST', $supplierId, []), new Psr7Response(), ['id' => (string) $invoiceId],
        );
    }

    /** @return array<string,mixed> */
    private function cancel(int $supplierId, int $invoiceId, string $mode): array
    {
        $res = $this->container->get(CancelInvoiceAction::class)(
            $this->request('POST', $supplierId, ['mode' => $mode, 'reason' => 'Test']), new Psr7Response(), ['id' => (string) $invoiceId],
        );
        self::assertContains($res->getStatusCode(), [200, 201], (string) $res->getBody());
        return $this->body($res);
    }

    private function serialBalance(int $supplierId, int $itemId, string $serial): int
    {
        return $this->trackingBalance($supplierId, $itemId, 'serial:' . $serial);
    }

    private function lotBalance(int $supplierId, int $itemId, string $lot, string $expires): int
    {
        return $this->trackingBalance($supplierId, $itemId, 'lot:' . $lot . '|' . $expires);
    }

    private function trackingBalance(int $supplierId, int $itemId, string $key): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN a.direction = 'in' THEN a.quantity ELSE -a.quantity END), 0)
               FROM stock_tracking_allocations a
               JOIN stock_tracking_units u ON u.id = a.stock_tracking_unit_id
              WHERE a.supplier_id = ? AND u.stock_item_id = ? AND u.tracking_key = ?"
        );
        $stmt->execute([$supplierId, $itemId, $key]);
        return (int) round((float) $stmt->fetchColumn() * 1000);
    }

    /** @return list<string> */
    private function issuedSerials(int $supplierId, int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT u.serial_number
               FROM stock_tracking_allocations a
               JOIN stock_tracking_units u ON u.id = a.stock_tracking_unit_id
               JOIN stock_document_lines l ON l.id = a.stock_document_line_id
               JOIN invoice_items ii ON ii.id = l.invoice_item_id
              WHERE a.supplier_id = ? AND ii.invoice_id = ? AND a.direction = 'out'
              ORDER BY u.serial_number"
        );
        $stmt->execute([$supplierId, $invoiceId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array<string,mixed> */
    private function invoiceRow(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, varsymbol FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    private function location(int $supplierId, int $warehouseId, string $code): int
    {
        $this->db->pdo()->prepare('INSERT INTO warehouse_locations (supplier_id, warehouse_id, code, name) VALUES (?, ?, ?, ?)')
            ->execute([$supplierId, $warehouseId, $code, 'Regál ' . $code]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function locationBalance(int $supplierId, int $locationId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END), 0)
               FROM stock_tracking_allocations WHERE supplier_id = ? AND location_id = ?"
        );
        $stmt->execute([$supplierId, $locationId]);
        return (int) round((float) $stmt->fetchColumn() * 1000);
    }

    private function pdfHtml(int $invoiceId): string
    {
        $html = $this->container->get(\MyInvoice\Service\Pdf\InvoicePdfRenderer::class)->renderHtml(
            $this->container->get(InvoiceRepository::class)->find($invoiceId),
            includeCss: false,
            includeWorkReport: false,
        );
        return html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @param array<string,string> $query */
    private function choices(int $supplierId, int $itemId, array $query): array
    {
        $res = $this->container->get(\MyInvoice\Action\Stock\StockTrackingAction::class)->choices(
            $this->request('GET', $supplierId, [])->withQueryParams($query), new Psr7Response(), ['id' => (string) $itemId],
        );
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        return $this->body($res);
    }

    private function trackedItem(int $supplierId, string $sku, string $mode): int
    {
        $id = $this->item($supplierId, $sku);
        $this->db->pdo()->prepare('UPDATE stock_items SET tracking_mode = ? WHERE supplier_id = ? AND id = ?')->execute([$mode, $supplierId, $id]);
        return $id;
    }

    /**
     * @param list<array<string,mixed>>|null $tracking
     * @return array<string,mixed>
     */
    private function line(?int $itemId, ?int $warehouseId, string $qty, ?array $tracking): array
    {
        $line = [
            'description' => 'Položka ' . ($itemId ?? 'bez karty'),
            'quantity' => $qty,
            'unit' => 'ks',
            'unit_price_without_vat' => 1000,
            'vat_rate_id' => $this->vatRateId,
            'stock_item_id' => $itemId,
            'warehouse_id' => $warehouseId,
        ];
        if ($tracking !== null) {
            $line['tracking_allocations'] = $tracking;
        }
        return $line;
    }

    /** @param list<array<string,mixed>> $items */
    private function createInvoiceResponse(int $supplierId, array $items, string $type = 'invoice'): ResponseInterface
    {
        $body = [
            'client_id' => $this->client($supplierId),
            'invoice_type' => $type,
            'issue_date' => '2099-06-10',
            'tax_date' => '2099-06-10',
            'due_date' => '2099-06-24',
            'currency_id' => $this->currencyIdFor($supplierId),
            'items' => $items,
        ];
        return $this->container->get(CreateInvoiceAction::class)($this->request('POST', $supplierId, $body), new Psr7Response());
    }

    /** @param list<array<string,mixed>> $items */
    private function createInvoice(int $supplierId, array $items, string $type = 'invoice'): int
    {
        $res = $this->createInvoiceResponse($supplierId, $items, $type);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        return (int) $this->body($res)['id'];
    }

    /** @param array<string,mixed> $body */
    private function updateInvoice(int $supplierId, int $invoiceId, array $body): ResponseInterface
    {
        return $this->container->get(UpdateInvoiceAction::class)(
            $this->request('PUT', $supplierId, $body),
            new Psr7Response(),
            ['id' => (string) $invoiceId],
        );
    }

    /** @return list<array<string,mixed>> */
    private function storedItems(int $invoiceId): array
    {
        return array_values(array_filter(
            (array) ($this->container->get(InvoiceRepository::class)->find($invoiceId)['items'] ?? []),
            static fn (array $item): bool => ($item['item_kind'] ?? 'standard') !== 'discount',
        ));
    }

    private function markIssued(int $invoiceId): void
    {
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued', varsymbol = ? WHERE id = ?")
            ->execute(['FV' . random_int(10000000, 99999999), $invoiceId]);
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, int $supplierId, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/api/test')
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function body(ResponseInterface $res): array
    {
        $res->getBody()->rewind();
        return (array) json_decode((string) $res->getBody(), true);
    }

    private function assertError(ResponseInterface $res, int $status, string $code): void
    {
        self::assertSame($status, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame($code, $this->body($res)['error']['code'] ?? null);
    }
}
