<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Stock\StockItemAction;
use MyInvoice\Action\Stock\StockReportAction;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockSalesReportService;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Dohledatelnost prodejů skladových karet: hledání podle sériového čísla, šarže
 * a textového parametru, pohyby karty s fakturou a odběratelem a sestava Prodeje.
 */
#[Group('integration')]
final class StockSalesTraceabilityTest extends StockTestCase
{
    // ── hledání ──────────────────────────────────────────────────────────────

    public function testListSearchFindsItemBySerialNumberAndSaysWhere(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->serialItem($supplierId, 'MOTO-1');
        $this->receiveSerials($supplierId, $whId, $itemId, ['TSTVIN0000000123', 'TSTVIN0000000456']);
        $this->item($supplierId, 'OTHER-1');

        [$rows, $total] = $this->itemsRepo->listPaged($supplierId, ['q' => '0000000456'], 50, 0);
        self::assertSame(1, $total);
        self::assertSame($itemId, (int) $rows[0]['id']);

        $matches = $this->itemsRepo->searchMatches($supplierId, [$itemId], '0000000456');
        self::assertSame(['kind' => 'serial', 'value' => 'TSTVIN0000000456', 'attribute' => null], $matches[$itemId]);

        // Našeptávač v editoru dokladu hledá stejně.
        self::assertSame([$itemId], array_column($this->itemsRepo->search($supplierId, 'TSTVIN00000001'), 'id'));
    }

    public function testShortTextDoesNotSearchIdentifiers(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->serialItem($supplierId, 'MOTO-2');
        $this->receiveSerials($supplierId, $whId, $itemId, ['QZ99']);

        [, $total] = $this->itemsRepo->listPaged($supplierId, ['q' => 'QZ'], 50, 0);
        self::assertSame(0, $total, 'Dvouznakový text neprohledává sériová čísla.');
        [, $total] = $this->itemsRepo->listPaged($supplierId, ['q' => 'QZ9'], 50, 0);
        self::assertSame(1, $total);
    }

    public function testListSearchFindsTextAttributeButNotNumberAttribute(): void
    {
        $supplierId = $this->createSupplier();
        $itemId = $this->item($supplierId, 'CARD-VIN');
        $vin = $this->attribute($supplierId, 'vin', 'text');
        $power = $this->attribute($supplierId, 'power', 'number');
        $this->attributeValue($supplierId, $itemId, $vin, text: 'TSTATTR77001');
        $numberItem = $this->item($supplierId, 'CARD-KW');
        $this->attributeValue($supplierId, $numberItem, $power, number: '77001');

        [$rows, $total] = $this->itemsRepo->listPaged($supplierId, ['q' => 'ATTR770'], 50, 0);
        self::assertSame(1, $total);
        self::assertSame($itemId, (int) $rows[0]['id']);
        self::assertSame(
            ['kind' => 'attribute', 'value' => 'TSTATTR77001', 'attribute' => 'Parametr vin'],
            $this->itemsRepo->searchMatches($supplierId, [$itemId], 'ATTR770')[$itemId],
        );
        [, $total] = $this->itemsRepo->listPaged($supplierId, ['q' => '77001'], 50, 0);
        self::assertSame(1, $total, 'Číselný parametr se fulltextem neprohledává.');
    }

    public function testListActionFillsSearchMatchOnlyWhenNameDoesNotMatch(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $serialItem = $this->serialItem($supplierId, 'MOTO-3');
        $this->receiveSerials($supplierId, $whId, $serialItem, ['TSTMATCH001']);
        $namedItem = $this->item($supplierId, 'TSTMATCH-SKU');

        $rows = $this->callJson(StockItemAction::class, 'list', $supplierId, ['q' => 'TSTMATCH']);
        $bySku = array_column($rows, 'search_match', 'sku');
        self::assertSame('serial', $bySku['MOTO-3']['kind']);
        self::assertSame('TSTMATCH001', $bySku['MOTO-3']['value']);
        self::assertNull($bySku['TSTMATCH-SKU'], 'Shodu v kódu uživatel vidí sám.');
        self::assertSame(['MOTO-3', 'TSTMATCH-SKU'], array_values(array_intersect(['MOTO-3', 'TSTMATCH-SKU'], array_column($rows, 'sku'))));
        self::assertCount(2, $rows);
        unset($namedItem);
    }

    // ── pohyby karty ─────────────────────────────────────────────────────────

    public function testLedgerCarriesInvoiceClientAndSalePrice(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'LEDGER-1');
        $this->receiveStock($supplierId, $whId, $itemId, '5.000', 40.0);
        $clientId = $this->client($supplierId, 'Odběratel Ledger s.r.o.');
        $invoiceId = $this->issuedInvoice($supplierId, $clientId, [[$itemId, $whId, '2.000', 125.5]], varsymbol: '2099990001');

        $rows = $this->levelsRepo->ledgerForItem($supplierId, $itemId);
        self::assertCount(2, $rows);
        [$receipt, $issue] = $rows;
        self::assertNull($receipt['invoice_id']);
        self::assertNull($receipt['sale_unit_price']);

        self::assertSame($invoiceId, $issue['invoice_id']);
        self::assertSame('2099990001', $issue['invoice_number']);
        self::assertSame('invoice', $issue['invoice_type']);
        self::assertSame(['kind' => 'client', 'id' => $clientId, 'name' => 'Odběratel Ledger s.r.o.'], $issue['partner']);
        self::assertSame(125.5, (float) $issue['sale_unit_price']);
        self::assertSame('CZK', $issue['sale_currency']);
    }

    public function testSalePriceIsNetAlsoOnInvoiceWithPricesIncludingVat(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'LEDGER-GROSS');
        $this->receiveStock($supplierId, $whId, $itemId, '5.000', 40.0);
        $invoiceId = $this->issuedInvoice($supplierId, $this->client($supplierId), [[$itemId, $whId, '2.000', 121.0]]);
        // Faktura s cenami s DPH: jednotková cena řádku je brutto 121, základ 2 × 100.
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE invoices SET prices_include_vat = 1 WHERE id = ?')->execute([$invoiceId]);
        $pdo->prepare('UPDATE invoice_items SET total_without_vat = 200.00, total_vat = 42.00, total_with_vat = 242.00 WHERE invoice_id = ?')
            ->execute([$invoiceId]);

        $issue = $this->levelsRepo->ledgerForItem($supplierId, $itemId)[1];
        self::assertSame(100.0, (float) $issue['sale_unit_price'], 'Prodejní cena v knize karty je bez DPH.');
        $report = $this->sales($supplierId, ['date_from' => '2099-06-01', 'date_to' => '2099-06-30']);
        self::assertSame(100.0, (float) $report['items'][0]['unit_price'], 'Cena/MJ v sestavě je bez DPH.');
        self::assertSame(200.0, (float) $report['items'][0]['total_without_vat']);
    }

    public function testLedgerShowsVendorOfPurchaseReceipt(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'LEDGER-PF');
        $vendorId = $this->client($supplierId, 'Dodavatel adresář');
        $piId = $this->purchaseInvoice($supplierId, $vendorId, ['vendor_invoice_number' => 'DOD-TRACE-1']);
        $piItemId = $this->purchaseInvoiceItem($piId, $itemId, '3.000', 50.0);
        $draft = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'origin' => 'purchase_invoice', 'warehouse_id' => $whId, 'doc_date' => '2099-06-02',
            'description' => 'Příjem z PF', 'purchase_invoice_id' => $piId,
            'lines' => [['stock_item_id' => $itemId, 'qty' => '3.000', 'unit_cost' => '50.000000', 'purchase_invoice_item_id' => $piItemId]],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $draft['id'], $this->userId);

        $row = $this->levelsRepo->ledgerForItem($supplierId, $itemId)[0];
        self::assertSame($piId, $row['purchase_invoice_id']);
        self::assertSame('DOD-TRACE-1', $row['purchase_invoice_number']);
        self::assertSame(['kind' => 'vendor', 'id' => $vendorId, 'name' => 'Dodavatel test'], $row['partner']);
    }

    public function testMovementsHideInvoiceDataFromRoleWithoutInvoiceAccess(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'LEDGER-ROLE');
        $this->receiveStock($supplierId, $whId, $itemId, '1.000', 10.0);
        $this->issuedInvoice($supplierId, $this->client($supplierId), [[$itemId, $whId, '1.000', 99.0]]);

        $stockOnly = new EffectiveRole(9, 'Skladník', 'staff', true, ['stock' => 1]);
        $issue = $this->callJson(StockItemAction::class, 'movements', $supplierId, [], ['id' => (string) $itemId], $stockOnly)['items'][1];
        self::assertSame('issue', $issue['doc_type']);
        self::assertNull($issue['invoice_id']);
        self::assertNull($issue['invoice_number']);
        self::assertNull($issue['partner']);
        self::assertNull($issue['sale_unit_price']);

        $withInvoices = new EffectiveRole(10, 'Obchodník', 'staff', true, ['stock' => 1, 'invoices' => 1]);
        $issue = $this->callJson(StockItemAction::class, 'movements', $supplierId, [], ['id' => (string) $itemId], $withInvoices)['items'][1];
        self::assertNotNull($issue['invoice_id']);
        self::assertSame(99.0, (float) $issue['sale_unit_price']);
    }

    // ── sestava Prodeje ──────────────────────────────────────────────────────

    public function testSalesReportCountsIssuedSalesMinusCreditNotes(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'SALE-1');
        $this->receiveStock($supplierId, $whId, $itemId, '20.000', 10.0);
        $alpha = $this->client($supplierId, 'Alfa odběr s.r.o.');
        $beta = $this->client($supplierId, 'Beta odběr s.r.o.');

        $saleA = $this->issuedInvoice($supplierId, $alpha, [[$itemId, $whId, '3.000', 100.0]], date: '2099-03-05');
        $this->issuedInvoice($supplierId, $beta, [[$itemId, $whId, '2.000', 150.0]], date: '2099-03-06');
        $this->issuedInvoice($supplierId, $alpha, [[$itemId, $whId, '1.000', 100.0]], type: 'credit_note', date: '2099-03-07', parentId: $saleA);
        // Nepočítá se: koncept, proforma, stornovaná faktura, prodej mimo období.
        $draft = $this->invoiceDraft($supplierId, $alpha, 'invoice', ['issue_date' => '2099-03-08']);
        $this->invoiceItem($draft, $itemId, $whId, '9.000', 100.0);
        $proforma = $this->invoiceDraft($supplierId, $alpha, 'proforma', ['issue_date' => '2099-03-08']);
        $this->invoiceItem($proforma, $itemId, $whId, '9.000', 100.0);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$proforma]);
        $cancelled = $this->issuedInvoice($supplierId, $alpha, [[$itemId, $whId, '1.000', 100.0]], date: '2099-03-09');
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?")->execute([$cancelled]);
        $this->issuedInvoice($supplierId, $alpha, [[$itemId, $whId, '5.000', 100.0]], date: '2099-04-01');

        $report = $this->sales($supplierId, ['date_from' => '2099-03-01', 'date_to' => '2099-03-31', 'group_by' => 'client']);

        self::assertSame(3, $report['totals']['lines']);
        self::assertSame('4.000', $report['totals']['qty']);
        self::assertSame([['currency' => 'CZK', 'total_without_vat' => '500.00']], $report['totals']['amounts']);
        $credit = array_values(array_filter($report['items'], static fn (array $r): bool => $r['invoice_type'] === 'credit_note'))[0];
        self::assertSame(-1.0, (float) $credit['qty']);
        self::assertSame(-100.0, (float) $credit['total_without_vat']);

        $groups = array_column($report['groups'], null, 'label');
        self::assertSame('2.000', $groups['Alfa odběr s.r.o.']['qty']);
        self::assertSame(2, $groups['Alfa odběr s.r.o.']['documents']);
        self::assertSame('2.000', $groups['Beta odběr s.r.o.']['qty']);
        self::assertSame('300.00', $groups['Beta odběr s.r.o.']['amounts'][0]['total_without_vat']);

        $onlyBeta = $this->sales($supplierId, ['date_from' => '2099-03-01', 'date_to' => '2099-03-31', 'client_id' => (string) $beta]);
        self::assertSame(1, $onlyBeta['totals']['lines']);
        self::assertSame('Beta odběr s.r.o.', $onlyBeta['items'][0]['client_name']);
    }

    public function testSalesReportFiltersByCategorySubtree(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $bike = $this->item($supplierId, 'SALE-BIKE');
        $part = $this->item($supplierId, 'SALE-PART');
        $this->receiveStock($supplierId, $whId, $bike, '2.000', 1000.0);
        $this->receiveStock($supplierId, $whId, $part, '2.000', 10.0);
        $root = $this->category($supplierId, 'vozidla', null);
        $used = $this->category($supplierId, 'vozidla-ojeta', $root);
        $this->assignCategory($supplierId, $bike, $used);
        $this->issuedInvoice($supplierId, $this->client($supplierId), [[$bike, $whId, '1.000', 2000.0], [$part, $whId, '1.000', 20.0]], date: '2099-05-02');

        $report = $this->sales($supplierId, ['date_from' => '2099-05-01', 'date_to' => '2099-05-31', 'category_id' => (string) $root]);
        self::assertSame(['SALE-BIKE'], array_column($report['items'], 'sku'));
    }

    public function testSalesReportFindsSoldSerialNumberAndListsIt(): void
    {
        $supplierId = $this->createSupplier(autoIssue: false);
        $whId = $this->warehouse($supplierId);
        $itemId = $this->serialItem($supplierId, 'SALE-SERIAL');
        $this->receiveSerials($supplierId, $whId, $itemId, ['TSTSOLD0001', 'TSTSOLD0002']);
        $invoiceId = $this->issuedInvoice($supplierId, $this->client($supplierId), [[$itemId, $whId, '1.000', 500.0]], date: '2099-07-01');
        $invoiceItemId = (int) $this->db->pdo()->query('SELECT id FROM invoice_items WHERE invoice_id = ' . $invoiceId)->fetchColumn();
        $draft = $this->documents->create($supplierId, [
            'doc_type' => 'issue', 'origin' => 'invoice', 'warehouse_id' => $whId, 'doc_date' => '2099-07-01',
            'description' => 'Výdej k faktuře', 'invoice_id' => $invoiceId,
            'lines' => [[
                'stock_item_id' => $itemId, 'qty' => '1.000', 'invoice_item_id' => $invoiceItemId,
                'tracking_allocations' => [['quantity' => '1', 'serial_number' => 'TSTSOLD0002']],
            ]],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $draft['id'], $this->userId);

        $bySerial = $this->sales($supplierId, ['date_from' => '2099-07-01', 'date_to' => '2099-07-31', 'q' => 'SOLD0002']);
        self::assertSame(1, $bySerial['totals']['lines']);
        self::assertSame(['TSTSOLD0002'], $bySerial['items'][0]['identifiers']);

        $other = $this->sales($supplierId, ['date_from' => '2099-07-01', 'date_to' => '2099-07-31', 'q' => 'SOLD0001']);
        self::assertSame(0, $other['totals']['lines'], 'Neprodaný kus stejné karty prodej nenajde.');
    }

    public function testSalesFiltersAreValidated(): void
    {
        $today = new \DateTimeImmutable('2099-08-15');
        self::assertSame(
            ['date_from' => '2099-01-01', 'date_to' => '2099-08-15', 'group_by' => 'none'],
            StockSalesReportService::normalizeFilters([], $today),
        );
        foreach ([
            ['date_from' => '2099-13-01'],
            ['date_from' => '2099-05-02', 'date_to' => '2099-05-01'],
            ['client_id' => 'abc'],
            ['group_by' => 'warehouse'],
        ] as $input) {
            try {
                StockSalesReportService::normalizeFilters($input, $today);
                self::fail('Očekávána chyba validace pro ' . json_encode($input));
            } catch (StockException $e) {
                self::assertSame('validation_failed', $e->errorCode);
            }
        }
    }

    public function testSalesReportRequiresInvoiceAccess(): void
    {
        $supplierId = $this->createSupplier();
        $stockOnly = new EffectiveRole(9, 'Skladník', 'staff', true, ['stock' => 1]);
        $response = $this->call(StockReportAction::class, 'sales', $supplierId, [], [], $stockOnly);
        self::assertSame(403, $response->getStatusCode());

        $withInvoices = new EffectiveRole(10, 'Obchodník', 'staff', true, ['stock' => 1, 'invoices' => 1]);
        $response = $this->call(StockReportAction::class, 'sales', $supplierId, ['date_from' => '2099-01-01', 'date_to' => '2099-12-31'], [], $withInvoices);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testSearchMatchIsHiddenWhenNameMatchesWithAccentsOrEan(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->serialItem($supplierId, 'ACC-1');
        $this->db->pdo()->prepare("UPDATE stock_items SET name = 'Škoda díl', ean = '8590000000017' WHERE id = ?")->execute([$itemId]);
        $this->receiveSerials($supplierId, $whId, $itemId, ['SKODA-0001', 'X8590000000017']);

        self::assertSame([], $this->itemsRepo->searchMatches($supplierId, [$itemId], 'skoda'), 'Název vyhověl bez ohledu na diakritiku.');
        self::assertSame([], $this->itemsRepo->searchMatches($supplierId, [$itemId], '8590000000017'), 'Vyhověl EAN karty.');
    }

    public function testLedgerOpeningBalanceCountsBeyondFiveHundredRows(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'LEDGER-MANY');
        $draft = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'origin' => 'manual', 'warehouse_id' => $whId, 'doc_date' => '2099-01-10',
            'description' => 'Mnoho řádků',
            'lines' => array_fill(0, 505, ['stock_item_id' => $itemId, 'qty' => '1.000', 'unit_cost' => '1.000000']),
        ], $this->userId);
        $this->documents->post($supplierId, (int) $draft['id'], $this->userId);

        self::assertSame('501.000', $this->levelsRepo->ledgerQtyBefore($supplierId, $itemId, [], 501));
        $page = $this->callJson(StockItemAction::class, 'movements', $supplierId, ['offset' => '501', 'limit' => '10'], ['id' => (string) $itemId]);
        self::assertSame('501.000', $page['opening_balance']);
        self::assertSame('502.000', $page['items'][0]['balance_after']);
    }

    public function testLedgerVendorNameFallsBackToPersonName(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'LEDGER-OSVC');
        $vendorId = $this->client($supplierId, 'OSVČ');
        $piId = $this->purchaseInvoice($supplierId, $vendorId);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET vendor_snapshot = ? WHERE id = ?')
            ->execute([json_encode(['company_name' => '', 'first_name' => 'Jana', 'last_name' => 'Testová'], JSON_UNESCAPED_UNICODE), $piId]);
        $draft = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'origin' => 'purchase_invoice', 'warehouse_id' => $whId, 'doc_date' => '2099-06-02',
            'description' => 'Příjem z PF', 'purchase_invoice_id' => $piId,
            'lines' => [['stock_item_id' => $itemId, 'qty' => '1.000', 'unit_cost' => '5.000000']],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $draft['id'], $this->userId);

        self::assertSame('Jana Testová', $this->levelsRepo->ledgerForItem($supplierId, $itemId)[0]['partner']['name']);
    }

    public function testSalesReportIgnoresReversedIssueOfSerial(): void
    {
        $supplierId = $this->createSupplier(autoIssue: false);
        $whId = $this->warehouse($supplierId);
        $itemId = $this->serialItem($supplierId, 'SALE-REV');
        $this->receiveSerials($supplierId, $whId, $itemId, ['TSTREV0001', 'TSTREV0002']);
        $invoiceId = $this->issuedInvoice($supplierId, $this->client($supplierId), [[$itemId, $whId, '1.000', 500.0]], date: '2099-07-01');
        $invoiceItemId = (int) $this->db->pdo()->query('SELECT id FROM invoice_items WHERE invoice_id = ' . $invoiceId)->fetchColumn();
        $issue = function (string $serial) use ($supplierId, $whId, $itemId, $invoiceId, $invoiceItemId): int {
            $draft = $this->documents->create($supplierId, [
                'doc_type' => 'issue', 'origin' => 'invoice', 'warehouse_id' => $whId, 'doc_date' => '2099-07-01',
                'description' => 'Výdej k faktuře', 'invoice_id' => $invoiceId,
                'lines' => [['stock_item_id' => $itemId, 'qty' => '1.000', 'invoice_item_id' => $invoiceItemId,
                    'tracking_allocations' => [['quantity' => '1', 'serial_number' => $serial]]]],
            ], $this->userId);
            $this->documents->post($supplierId, (int) $draft['id'], $this->userId);
            return (int) $draft['id'];
        };
        $wrong = $issue('TSTREV0001');
        $this->documents->reverse($supplierId, $wrong, ['reason' => 'Špatný kus'], $this->userId);
        $issue('TSTREV0002');

        $report = $this->sales($supplierId, ['date_from' => '2099-07-01', 'date_to' => '2099-07-31']);
        self::assertSame(['TSTREV0002'], $report['items'][0]['identifiers']);
        self::assertSame(0, $this->sales($supplierId, ['date_from' => '2099-07-01', 'date_to' => '2099-07-31', 'q' => 'TSTREV0001'])['totals']['lines']);
    }

    public function testCreditNoteWithoutReturnLowersRevenueButNotQuantity(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'SALE-DISC');
        $this->receiveStock($supplierId, $whId, $itemId, '10.000', 10.0);
        $clientId = $this->client($supplierId);
        $sale = $this->issuedInvoice($supplierId, $clientId, [[$itemId, $whId, '5.000', 100.0]], date: '2099-09-01');
        // Dodatečná sleva: dobropis na kartu bez vratky na sklad.
        $credit = $this->invoiceDraft($supplierId, $clientId, 'credit_note', ['issue_date' => '2099-09-05', 'parent_invoice_id' => $sale]);
        $this->invoiceItem($credit, $itemId, $whId, '-5.000', 10.0);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$credit]);

        $report = $this->sales($supplierId, ['date_from' => '2099-09-01', 'date_to' => '2099-09-30']);
        self::assertSame('5.000', $report['totals']['qty']);
        self::assertSame('450.00', $report['totals']['amounts'][0]['total_without_vat']);
    }

    public function testSalesWarehouseFilterIncludesLinesWithoutWarehouseOnDefault(): void
    {
        $supplierId = $this->createSupplier();
        $main = $this->warehouse($supplierId, 'HLAVNI', true);
        $other = $this->warehouse($supplierId, 'POBOCKA', false);
        $itemId = $this->item($supplierId, 'SALE-WH');
        $this->receiveStock($supplierId, $main, $itemId, '5.000', 10.0);
        $this->issuedInvoice($supplierId, $this->client($supplierId), [[$itemId, $main, '1.000', 100.0]], date: '2099-10-01');
        $this->db->pdo()->prepare('UPDATE invoice_items SET warehouse_id = NULL WHERE stock_item_id = ?')->execute([$itemId]);

        $range = ['date_from' => '2099-10-01', 'date_to' => '2099-10-31'];
        self::assertSame(1, $this->sales($supplierId, $range + ['warehouse_id' => (string) $main])['totals']['lines']);
        self::assertSame(0, $this->sales($supplierId, $range + ['warehouse_id' => (string) $other])['totals']['lines']);
    }

    public function testMovementsExportIncludesInvoiceAndPartnerInPdfAndXlsx(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'EXPORT-1');
        $this->receiveStock($supplierId, $whId, $itemId, '2.000', 10.0); // ruční příjem bez partnera
        $this->issuedInvoice($supplierId, $this->client($supplierId, 'Exportní odběratel'), [[$itemId, $whId, '1.000', 50.0]], varsymbol: '2099990077');

        foreach (['pdf' => 'application/pdf', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'] as $format => $mime) {
            $response = $this->call(StockItemAction::class, 'movementsExport', $supplierId, ['format' => $format], ['id' => (string) $itemId]);
            self::assertSame(200, $response->getStatusCode(), $format . ': ' . substr((string) $response->getBody(), 0, 300));
            self::assertSame($mime, $response->getHeaderLine('Content-Type'));
        }
        $sheet = $this->xlsxRows($this->call(StockItemAction::class, 'movementsExport', $supplierId, ['format' => 'xlsx'], ['id' => (string) $itemId]));
        $issueRow = array_values(array_filter($sheet, static fn (array $r): bool => ($r[3] ?? null) === '2099990077'));
        self::assertCount(1, $issueRow);
        self::assertSame('Exportní odběratel', $issueRow[0][4]);
    }

    public function testSalesExportIsXlsxOnlyAndNeedsInvoiceAccess(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'EXPORT-SALE');
        $this->receiveStock($supplierId, $whId, $itemId, '2.000', 10.0);
        $this->issuedInvoice($supplierId, $this->client($supplierId, 'Souhrnný odběratel'), [[$itemId, $whId, '2.000', 75.0]], date: '2099-11-02');
        $query = ['date_from' => '2099-11-01', 'date_to' => '2099-11-30', 'group_by' => 'client'];

        $xlsx = $this->call(StockReportAction::class, 'export', $supplierId, $query + ['format' => 'xlsx'], ['name' => 'sales']);
        self::assertSame(200, $xlsx->getStatusCode(), substr((string) $xlsx->getBody(), 0, 300));
        self::assertContains('EXPORT-SALE', array_column($this->xlsxRows($xlsx, 'Řádky'), 4));
        self::assertContains('Souhrnný odběratel', array_column($this->xlsxRows($xlsx), 0), 'Při seskupení se sešit otevře na souhrnu.');
        self::assertSame(422, $this->call(StockReportAction::class, 'export', $supplierId, $query + ['format' => 'pdf'], ['name' => 'sales'])->getStatusCode());

        $stockOnly = new EffectiveRole(9, 'Skladník', 'staff', true, ['stock' => 1]);
        self::assertSame(403, $this->call(StockReportAction::class, 'export', $supplierId, $query + ['format' => 'xlsx'], ['name' => 'sales'], $stockOnly)->getStatusCode());
    }

    // ── převzaté faktury spárované podle kódu ───────────────────────────────

    public function testImportedLinesMatchedByCodeOnlyWithOptionAndWithoutStockEffect(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'IMP-A');
        $this->receiveStock($supplierId, $whId, $itemId, '5.000', 10.0);
        $client = $this->client($supplierId, 'Převzatý odběratel');
        $this->issuedInvoice($supplierId, $client, [[$itemId, $whId, '1.000', 100.0]], date: '2099-05-02');
        $imported = $this->importedInvoice($supplierId, $client, [['IMP-A', '2.000', 300.0], ['NEZNAMY', '1.000', 50.0]], date: '2099-05-03');
        // Ručně psaný řádek s kódem, ale bez importu, zůstává mimo.
        $this->importedInvoice($supplierId, $client, [['IMP-A', '7.000', 10.0]], date: '2099-05-04', batch: null);
        $period = ['date_from' => '2099-05-01', 'date_to' => '2099-05-31'];

        $off = $this->sales($supplierId, $period);
        self::assertSame(1, $off['totals']['lines']);
        self::assertFalse($off['items'][0]['matched_by_code']);

        $on = $this->sales($supplierId, $period + ['include_imported' => '1']);
        self::assertSame(2, $on['totals']['lines']);
        self::assertSame('3.000', $on['totals']['qty']);
        $matched = array_values(array_filter($on['items'], static fn (array $r): bool => $r['matched_by_code']));
        self::assertCount(1, $matched);
        self::assertSame([$imported, $itemId, '2.000'], [$matched[0]['invoice_id'], $matched[0]['stock_item_id'], $matched[0]['qty']]);

        // Sklad se nepohnul: 5 přijato, 1 vydáno skladovou fakturou, převzaté řádky nic.
        self::assertSame(4000, $this->level($supplierId, $whId, $itemId)['qtyT']);
        $docs = $this->db->pdo()->prepare('SELECT COUNT(*) FROM stock_documents WHERE supplier_id = ? AND invoice_id = ?');
        $docs->execute([$supplierId, $imported]);
        self::assertSame(0, (int) $docs->fetchColumn());
    }

    public function testImportedCreditNoteSubtractsQuantityOfItsLine(): void
    {
        $supplierId = $this->createSupplier();
        $this->item($supplierId, 'IMP-DOB');
        $client = $this->client($supplierId, 'Dobropisový odběratel');
        $sale = $this->importedInvoice($supplierId, $client, [['IMP-DOB', '3.000', 100.0]], date: '2099-06-02');
        $this->importedInvoice($supplierId, $client, [['IMP-DOB', '1.000', 100.0]], type: 'credit_note', date: '2099-06-03', parentId: $sale);

        $report = $this->sales($supplierId, ['date_from' => '2099-06-01', 'date_to' => '2099-06-30', 'include_imported' => '1']);

        self::assertSame('2.000', $report['totals']['qty']);
        self::assertSame([['currency' => 'CZK', 'total_without_vat' => '200.00']], $report['totals']['amounts']);
    }

    /** Dobropis vystavený v aplikaci k převzaté faktuře importní dávku nemá, odečte se přes původní fakturu. */
    public function testInAppCreditNoteToImportedInvoiceSubtractsQuantity(): void
    {
        $supplierId = $this->createSupplier();
        $this->item($supplierId, 'IMP-DOB2');
        $client = $this->client($supplierId);
        $sale = $this->importedInvoice($supplierId, $client, [['IMP-DOB2', '3.000', 100.0]], date: '2099-06-02');
        $this->importedInvoice($supplierId, $client, [['IMP-DOB2', '1.000', 100.0]], type: 'credit_note', date: '2099-06-03', parentId: $sale, batch: null);

        $report = $this->sales($supplierId, ['date_from' => '2099-06-01', 'date_to' => '2099-06-30', 'include_imported' => '1', 'group_by' => 'item']);

        self::assertSame('2.000', $report['totals']['qty']);
        self::assertSame('2.000', $report['groups'][0]['qty'], 'Souhrn po kartách sedí s celkem.');
    }

    public function testImportedLinesNeverMatchAnotherCompanysCard(): void
    {
        $supplierId = $this->createSupplier();
        $other = $this->createSupplier();
        $this->item($other, 'IMP-CIZI');
        $this->importedInvoice($supplierId, $this->client($supplierId), [['IMP-CIZI', '1.000', 10.0]], date: '2099-07-02');

        $report = $this->sales($supplierId, ['date_from' => '2099-07-01', 'date_to' => '2099-07-31', 'include_imported' => '1']);

        self::assertSame(0, $report['totals']['lines']);
    }

    public function testImportedLinesWithoutWarehouseAreVisibleOnlyAcrossAllWarehouses(): void
    {
        $supplierId = $this->createSupplier();
        $main = $this->warehouse($supplierId, 'HLAVNI', true);
        $branch = $this->warehouse($supplierId, 'POBOCKA', false);
        $this->item($supplierId, 'IMP-WH');
        $client = $this->client($supplierId);
        $unknown = $this->importedInvoice($supplierId, $client, [['IMP-WH', '2.000', 300.0]], date: '2099-05-03');
        $known = $this->importedInvoice($supplierId, $client, [['IMP-WH', '1.000', 100.0]], date: '2099-05-04');
        $this->db->pdo()->prepare('UPDATE invoice_items SET warehouse_id = ? WHERE invoice_id = ?')->execute([$branch, $known]);

        $params = ['date_from' => '2099-05-01', 'date_to' => '2099-05-31', 'include_imported' => '1'];
        self::assertSame([$known, $unknown], array_column($this->sales($supplierId, $params)['items'], 'invoice_id'));
        self::assertSame([], $this->sales($supplierId, $params + ['warehouse_id' => (string) $main])['items']);
        self::assertSame([$known], array_column($this->sales($supplierId, $params + ['warehouse_id' => (string) $branch])['items'], 'invoice_id'));
    }

    public function testReusedCodeDoesNotAssignImportedHistoryToAnotherItem(): void
    {
        $supplierId = $this->createSupplier();
        $other = $this->createSupplier();
        $old = $this->item($supplierId, 'IMPORTED-OLD');
        $deleted = $this->item($supplierId, 'IMPORTED-DELETE');
        $client = $this->client($supplierId);
        $items = [['IMPORTED-OLD', '2.000', 300.0], ['IMPORTED-DELETE', '1.000', 100.0]];
        $this->importedInvoice($supplierId, $client, $items, date: '2099-05-03');

        $params = ['date_from' => '2099-05-01', 'date_to' => '2099-05-31', 'include_imported' => '1'];
        self::assertSame([$old, $deleted], array_column($this->sales($supplierId, $params)['items'], 'stock_item_id'));

        $this->db->pdo()->prepare("UPDATE stock_items SET sku = 'NEW-CODE' WHERE id = ?")->execute([$old]);
        $this->db->pdo()->prepare('DELETE FROM stock_items WHERE id = ?')->execute([$deleted]);
        $this->item($supplierId, 'IMPORTED-OLD');
        $this->item($supplierId, 'IMPORTED-DELETE');
        $unrelated = $this->item($other, 'IMPORTED-OLD');
        $this->importedInvoice($other, $this->client($other), [$items[0]], date: '2099-05-03');
        $result = $this->sales($supplierId, $params + ['group_by' => 'item']);

        self::assertSame([], $result['items']);
        self::assertSame([], $result['groups']);
        self::assertSame([$unrelated], array_column($this->sales($other, $params)['items'], 'stock_item_id'));
    }

    public function testImportedLinesFollowItemFilter(): void
    {
        $supplierId = $this->createSupplier();
        $wanted = $this->item($supplierId, 'IMP-K1');
        $this->item($supplierId, 'IMP-K2');
        $this->importedInvoice($supplierId, $this->client($supplierId), [['IMP-K1', '1.000', 10.0], ['IMP-K2', '1.000', 20.0]], date: '2099-07-02');

        $report = $this->sales($supplierId, ['date_from' => '2099-07-01', 'date_to' => '2099-07-31', 'include_imported' => '1', 'stock_item_id' => (string) $wanted]);

        self::assertSame(['IMP-K1'], array_column($report['items'], 'sku'));
    }

    public function testImportedLinesFollowClientFilter(): void
    {
        $supplierId = $this->createSupplier();
        $this->item($supplierId, 'IMP-C');
        $alpha = $this->client($supplierId, 'Alfa převzatá');
        $beta = $this->client($supplierId, 'Beta převzatá');
        $this->importedInvoice($supplierId, $alpha, [['IMP-C', '1.000', 10.0]], date: '2099-07-02');
        $this->importedInvoice($supplierId, $beta, [['IMP-C', '2.000', 10.0]], date: '2099-07-03');

        $report = $this->sales($supplierId, ['date_from' => '2099-07-01', 'date_to' => '2099-07-31', 'include_imported' => '1', 'client_id' => (string) $beta]);

        self::assertSame(['Beta převzatá'], array_column($report['items'], 'client_name'));
    }

    public function testImportedLinesFollowPeriod(): void
    {
        $supplierId = $this->createSupplier();
        $this->item($supplierId, 'IMP-P');
        $client = $this->client($supplierId);
        $this->importedInvoice($supplierId, $client, [['IMP-P', '1.000', 10.0]], date: '2015-03-10');
        $this->importedInvoice($supplierId, $client, [['IMP-P', '4.000', 10.0]], date: '2016-03-10');

        $report = $this->sales($supplierId, ['date_from' => '2015-01-01', 'date_to' => '2015-12-31', 'include_imported' => '1']);

        self::assertSame('1.000', $report['totals']['qty']);
    }

    public function testImportedLinesFollowTextSearchInCardNameAndTextParameter(): void
    {
        $supplierId = $this->createSupplier();
        $byName = $this->item($supplierId, 'IMP-T1');
        $this->db->pdo()->prepare('UPDATE stock_items SET name = ? WHERE id = ?')->execute(['Motorka ZDMTSTNAME0001', $byName]);
        $byParam = $this->item($supplierId, 'IMP-T2');
        $this->attributeValue($supplierId, $byParam, $this->attribute($supplierId, 'vin', 'text'), 'ZDMTSTPARAM0002');
        $this->item($supplierId, 'IMP-T3');
        $this->importedInvoice($supplierId, $this->client($supplierId), [['IMP-T1', '1.000', 10.0], ['IMP-T2', '1.000', 10.0], ['IMP-T3', '1.000', 10.0]], date: '2099-08-02');
        $query = ['date_from' => '2099-08-01', 'date_to' => '2099-08-31', 'include_imported' => '1'];

        self::assertSame(['IMP-T1'], array_column($this->sales($supplierId, $query + ['q' => 'TSTNAME0001'])['items'], 'sku'));
        self::assertSame(['IMP-T2'], array_column($this->sales($supplierId, $query + ['q' => 'TSTPARAM0002'])['items'], 'sku'));
        self::assertSame(['IMP-T3'], array_column($this->sales($supplierId, $query + ['q' => 'IMP-T3'])['items'], 'sku'));
    }

    public function testImportedLinesFollowCategoryFilter(): void
    {
        $supplierId = $this->createSupplier();
        $inCategory = $this->item($supplierId, 'IMP-CAT1');
        $this->item($supplierId, 'IMP-CAT2');
        $this->assignCategory($supplierId, $inCategory, $this->category($supplierId, 'MOTO', null));
        $categoryId = (int) $this->db->pdo()->query('SELECT MAX(id) FROM stock_categories')->fetchColumn();
        $this->importedInvoice($supplierId, $this->client($supplierId), [['IMP-CAT1', '1.000', 10.0], ['IMP-CAT2', '1.000', 10.0]], date: '2099-08-02');

        $report = $this->sales($supplierId, ['date_from' => '2099-08-01', 'date_to' => '2099-08-31', 'include_imported' => '1', 'category_id' => (string) $categoryId]);

        self::assertSame(['IMP-CAT1'], array_column($report['items'], 'sku'));
    }

    public function testSalesExportMarksImportedLines(): void
    {
        $supplierId = $this->createSupplier();
        $this->item($supplierId, 'IMP-X');
        $this->importedInvoice($supplierId, $this->client($supplierId), [['IMP-X', '1.000', 10.0]], date: '2099-09-02');
        $query = ['date_from' => '2099-09-01', 'date_to' => '2099-09-30', 'include_imported' => '1', 'format' => 'xlsx'];

        $xlsx = $this->call(StockReportAction::class, 'export', $supplierId, $query, ['name' => 'sales']);

        self::assertSame(200, $xlsx->getStatusCode());
        self::assertContains('Faktura (převzatá, spárováno podle kódu)', array_column($this->xlsxRows($xlsx, 'Řádky'), 2));
    }

    // ── pomocníci ────────────────────────────────────────────────────────────

    private function serialItem(int $supplierId, string $sku): int
    {
        $id = $this->item($supplierId, $sku);
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'serial' WHERE supplier_id = ? AND id = ?")->execute([$supplierId, $id]);
        return $id;
    }

    /** @param list<string> $serials */
    private function receiveSerials(int $supplierId, int $whId, int $itemId, array $serials): void
    {
        $draft = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'origin' => 'manual', 'warehouse_id' => $whId, 'doc_date' => '2099-01-10',
            'description' => 'Příjem kusů',
            'lines' => [[
                'stock_item_id' => $itemId, 'qty' => count($serials) . '.000', 'unit_cost' => '100.000000',
                'tracking_allocations' => array_map(static fn (string $s): array => ['quantity' => '1', 'serial_number' => $s], $serials),
            ]],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $draft['id'], $this->userId);
    }

    private function attribute(int $supplierId, string $code, string $type): int
    {
        $this->db->pdo()->prepare('INSERT INTO stock_attributes (supplier_id, code, name, data_type) VALUES (?, ?, ?, ?)')
            ->execute([$supplierId, $code, 'Parametr ' . $code, $type]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function attributeValue(int $supplierId, int $itemId, int $attributeId, ?string $text = null, ?string $number = null): void
    {
        $this->db->pdo()->prepare('INSERT INTO stock_item_attribute_values (supplier_id, stock_item_id, attribute_id, value_text, value_num) VALUES (?, ?, ?, ?, ?)')
            ->execute([$supplierId, $itemId, $attributeId, $text, $number]);
    }

    private function category(int $supplierId, string $code, ?int $parentId): int
    {
        $pdo = $this->db->pdo();
        $parentPath = '/';
        if ($parentId !== null) {
            $stmt = $pdo->prepare('SELECT path FROM stock_categories WHERE id = ?');
            $stmt->execute([$parentId]);
            $parentPath = (string) $stmt->fetchColumn();
        }
        $pdo->prepare('INSERT INTO stock_categories (supplier_id, parent_id, code, name, path) VALUES (?, ?, ?, ?, ?)')
            ->execute([$supplierId, $parentId, $code, 'Kategorie ' . $code, '']);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE stock_categories SET path = ? WHERE id = ?')->execute([$parentPath . $id . '/', $id]);
        return $id;
    }

    private function assignCategory(int $supplierId, int $itemId, int $categoryId): void
    {
        $this->db->pdo()->prepare('INSERT INTO stock_item_categories (supplier_id, stock_item_id, category_id, is_primary) VALUES (?, ?, ?, 1)')
            ->execute([$supplierId, $itemId, $categoryId]);
    }

    /**
     * Vystavený doklad se snapshotem odběratele; faktura s automatickým výdejem
     * odepíše sklad stejně jako vystavení v aplikaci.
     *
     * @param list<array{0:int,1:int,2:string,3:float}> $lines
     */
    private function issuedInvoice(
        int $supplierId,
        int $clientId,
        array $lines,
        string $type = 'invoice',
        string $date = '2099-06-10',
        ?int $parentId = null,
        ?string $varsymbol = null,
    ): int {
        $invoiceId = $this->invoiceDraft($supplierId, $clientId, $type, [
            'issue_date' => $date, 'parent_invoice_id' => $parentId, 'varsymbol' => $varsymbol ?? (string) random_int(1000000, 9999999),
        ]);
        foreach ($lines as $i => [$itemId, $whId, $qty, $price]) {
            $this->invoiceItem($invoiceId, $itemId, $whId, $qty, $price, $i);
        }
        $stmt = $this->db->pdo()->prepare('SELECT company_name FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $snapshot = json_encode(['company_name' => (string) $stmt->fetchColumn()], JSON_UNESCAPED_UNICODE);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued', client_snapshot = ? WHERE id = ?")->execute([$snapshot, $invoiceId]);

        $row = $this->db->pdo()->query('SELECT id, supplier_id, invoice_type, parent_invoice_id, issue_date, tax_date, varsymbol FROM invoices WHERE id = ' . $invoiceId)
            ->fetch(\PDO::FETCH_ASSOC);
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['parent_invoice_id'] = $row['parent_invoice_id'] !== null ? (int) $row['parent_invoice_id'] : null;
        $pdo = $this->db->pdo();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try {
            $this->issue->issueForInvoice($supplierId, $row, $this->userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $invoiceId;
    }

    /**
     * Vystavený doklad jako po importu z jiného systému: řádky bez karty, jen s kódem
     * položky, a značka importní dávky. Sklad se při importu nehýbe.
     *
     * @param list<array{0:string,1:string,2:float}> $lines kód, množství, cena
     */
    private function importedInvoice(
        int $supplierId,
        int $clientId,
        array $lines,
        string $type = 'invoice',
        string $date = '2099-06-10',
        ?int $parentId = null,
        ?string $batch = 'import-test',
    ): int {
        $invoiceId = $this->invoiceDraft($supplierId, $clientId, $type, [
            'issue_date' => $date, 'parent_invoice_id' => $parentId, 'varsymbol' => (string) random_int(1000000, 9999999),
        ]);
        foreach ($lines as $i => [$code, $qty, $price]) {
            $itemId = $this->invoiceItem($invoiceId, null, null, $qty, $price, $i);
            $this->db->pdo()->prepare('UPDATE invoice_items SET seller_item_code = ? WHERE id = ?')->execute([$code, $itemId]);
        }
        $stmt = $this->db->pdo()->prepare('SELECT company_name FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $snapshot = json_encode(['company_name' => (string) $stmt->fetchColumn()], JSON_UNESCAPED_UNICODE);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued', client_snapshot = ?, import_batch_id = ? WHERE id = ?")
            ->execute([$snapshot, $batch, $invoiceId]);
        return $invoiceId;
    }

    /** @return list<list<mixed>> řádky listu XLSX (bez názvu aktivního) */
    private function xlsxRows(\Psr\Http\Message\ResponseInterface $response, ?string $sheet = null): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'stocktest_') . '.xlsx';
        file_put_contents($tmp, (string) $response->getBody());
        try {
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
            return ($sheet !== null ? $book->getSheetByNameOrThrow($sheet) : $book->getActiveSheet())->toArray(null, false, false, false);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function sales(int $supplierId, array $query): array
    {
        return $this->container->get(StockSalesReportService::class)
            ->report($supplierId, StockSalesReportService::normalizeFilters($query), 1, 500);
    }

    /**
     * @param array<string,string> $query
     * @param array<string,string> $args
     */
    private function call(string $action, string $method, int $supplierId, array $query, array $args = [], ?EffectiveRole $role = null): \Psr\Http\Message\ResponseInterface
    {
        $role ??= new EffectiveRole(0, 'Superadmin', 'superadmin', true, [], 'superadmin');
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/stock')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute('auth.effective_role', $role);
        return $this->container->get($action)->{$method}($request, new Psr7Response(), ...($args === [] ? [] : [$args]));
    }

    /**
     * @param array<string,string> $query
     * @param array<string,string> $args
     * @return array<string,mixed>
     */
    private function callJson(string $action, string $method, int $supplierId, array $query, array $args = [], ?EffectiveRole $role = null): array
    {
        $response = $this->call($action, $method, $supplierId, $query, $args, $role);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $json = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return $json['data'] ?? $json;
    }
}
