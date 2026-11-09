<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Approval\RequestApprovalAction;
use MyInvoice\Action\Approval\UpdateApprovalStatusAction;
use MyInvoice\Action\Invoice\IssueInvoiceAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Action\Stock\ReceiptInvoiceAction;
use MyInvoice\Action\Stock\StockDocumentAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\StockDocumentRepository;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockPriceLevelRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Eshop\Pricing\PriceCalculationService;
use MyInvoice\Service\Invoice\AutoIssueAndSendService;
use MyInvoice\Service\Stock\ReceiptInvoiceService;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Koncept vydané faktury z příjemky: ceny podle běžného nacenění, výběr řádků, přenos
 * sériových čísel do výdejky, vazba z obou stran a upozornění na druhou fakturu.
 */
#[Group('integration')]
final class ReceiptInvoiceTest extends StockTestCase
{
    /** Vystavení zakládá čítače číselné řady a test schválení zakázku; StockTestCase je neuklízí. */
    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($this->supplierIds as $sid) {
                $pdo->prepare('DELETE FROM invoice_counters WHERE supplier_id = ?')->execute([$sid]);
                $pdo->prepare('UPDATE invoices SET project_id = NULL WHERE supplier_id = ?')->execute([$sid]);
                $pdo->prepare('DELETE p FROM projects p JOIN clients c ON c.id = p.client_id WHERE c.supplier_id = ?')->execute([$sid]);
            }
        }
        parent::tearDown();
    }

    public function testPricesFollowClientLevelAndDocumentLevel(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->pricedItem($sid, 'RCP-1');
        $gold = $this->priceLevel($sid, 'GOLD', '10');
        $urgent = $this->priceLevel($sid, 'URG', '5');
        $client = $this->client($sid);
        $this->db->pdo()->prepare('UPDATE clients SET price_level_id = ? WHERE id = ?')->execute([$gold, $client]);
        $receipt = $this->postedReceipt($sid, $wh, [['stock_item_id' => $item, 'qty' => '2.000']]);

        $invoice = $this->created($this->create($sid, $receipt, ['client_id' => $client]));
        self::assertSame('draft', $invoice['status'], 'Nikdy se sama nevystaví.');
        self::assertFalse($invoice['prices_include_vat']);
        $line = $invoice['items'][0];
        self::assertEquals(900, $line['unit_price_without_vat'], 'Hladina odběratele.');
        self::assertEquals(1000, $line['list_unit_price']);
        self::assertEquals(2, $line['quantity']);
        self::assertSame($wh, $line['warehouse_id']);
        self::assertSame($item, $line['stock_item_id']);

        $urgentInvoice = $this->created($this->create($sid, $receipt, ['client_id' => $client, 'price_level_id' => $urgent, 'allow_duplicate' => true]));
        self::assertEquals(950, $urgentInvoice['items'][0]['unit_price_without_vat'], 'Hladina zvolená na dokladu přebije hladinu odběratele.');
        self::assertSame($urgent, $urgentInvoice['price_level_id']);
    }

    public function testPartialLinesAndManualPrice(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        [$a, $b, $c] = [$this->pricedItem($sid, 'RCP-A'), $this->pricedItem($sid, 'RCP-B'), $this->pricedItem($sid, 'RCP-C')];
        $receipt = $this->postedReceipt($sid, $wh, [
            ['stock_item_id' => $a, 'qty' => '1.000'],
            ['stock_item_id' => $b, 'qty' => '1.000'],
            ['stock_item_id' => $c, 'qty' => '1.000'],
        ]);
        [$lineA, , $lineC] = $this->receiptLineIds($sid, $receipt);

        $invoice = $this->created($this->create($sid, $receipt, [
            'client_id' => $this->client($sid),
            'lines' => [['line_id' => $lineA], ['line_id' => $lineC, 'manual_price' => true]],
        ]));
        self::assertSame([$a, $c], array_column($invoice['items'], 'stock_item_id'));
        self::assertFalse($invoice['items'][0]['manual_price']);
        self::assertEquals(1000, $invoice['items'][0]['unit_price_without_vat']);
        self::assertTrue($invoice['items'][1]['manual_price']);
        self::assertEquals(0, $invoice['items'][1]['unit_price_without_vat']);
        self::assertNull($invoice['items'][1]['list_unit_price']);

        $sent = $invoice['items'];
        $sent[1]['manual_price'] = true;
        $same = $this->container->get(UpdateInvoiceAction::class)($this->request($sid, ['items' => $sent]), new Psr7Response(), ['id' => (string) $invoice['id']]);
        self::assertSame(200, $same->getStatusCode(), (string) $same->getBody());
        self::assertTrue($this->items($invoice['id'])[1]['manual_price'], 'Bez ceny příznak trvá.');
        $sent[1]['unit_price_without_vat'] = 500;
        $this->container->get(UpdateInvoiceAction::class)($this->request($sid, ['items' => $sent]), new Psr7Response(), ['id' => (string) $invoice['id']]);
        self::assertFalse($this->items($invoice['id'])[1]['manual_price'], 'Zadaná cena příznak zhasne.');

        $this->assertError($this->create($sid, $receipt, ['client_id' => $this->client($sid), 'lines' => [['line_id' => 999999999]]]), 422, 'invalid_line');
        $this->assertError($this->create($sid, $receipt, ['client_id' => $this->client($sid), 'lines' => []]), 422, 'validation_failed');
    }

    public function testCardWithoutPriceNeedsManualPrice(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'RCP-NOPRICE');
        $this->db->pdo()->prepare('UPDATE stock_items SET vat_rate_id = ? WHERE id = ?')->execute([$this->vatRateId, $item]);
        $receipt = $this->postedReceipt($sid, $wh, [['stock_item_id' => $item, 'qty' => '1.000']]);

        $line = $this->created($this->create($sid, $receipt, ['client_id' => $this->client($sid)]))['items'][0];
        self::assertTrue($line['manual_price']);
        self::assertEquals(0, $line['unit_price_without_vat']);

        // Řádek bez ceny fakturu zastaví dřív než kontrola kladné částky.
        $invoiceId = (int) $line['invoice_id'];
        $issued = $this->container->get(IssueInvoiceAction::class)($this->request($sid, []), new Psr7Response(), ['id' => (string) $invoiceId]);
        $this->assertError($issued, 422, 'manual_price_missing');
    }

    public function testSerialsTravelToInvoiceAndIssueExactlyThoseUnits(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->pricedItem($sid, 'RCP-MOTO');
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'serial' WHERE id = ?")->execute([$moto]);
        // Starší kus na skladě nesmí vydání ovlivnit.
        $this->postedReceipt($sid, $wh, [['stock_item_id' => $moto, 'qty' => '1.000', 'tracking_allocations' => [['quantity' => '1', 'serial_number' => 'VIN-OLD']]]]);
        $receipt = $this->postedReceipt($sid, $wh, [[
            'stock_item_id' => $moto, 'qty' => '2.000',
            'tracking_allocations' => [['quantity' => '1', 'serial_number' => 'VIN-1'], ['quantity' => '1', 'serial_number' => 'VIN-2']],
        ]]);

        $invoice = $this->created($this->create($sid, $receipt, ['client_id' => $this->client($sid)]));
        self::assertSame(['VIN-1', 'VIN-2'], array_column($invoice['items'][0]['tracking_allocations'], 'serial_number'));

        $issued = $this->container->get(IssueInvoiceAction::class)($this->request($sid, []), new Psr7Response(), ['id' => (string) $invoice['id']]);
        self::assertSame(200, $issued->getStatusCode(), (string) $issued->getBody());
        $stmt = $this->db->pdo()->prepare(
            "SELECT u.serial_number FROM stock_tracking_allocations a
               JOIN stock_tracking_units u ON u.id = a.stock_tracking_unit_id
               JOIN stock_document_lines l ON l.id = a.stock_document_line_id
               JOIN invoice_items ii ON ii.id = l.invoice_item_id
              WHERE a.supplier_id = ? AND ii.invoice_id = ? AND a.direction = 'out' ORDER BY u.serial_number"
        );
        $stmt->execute([$sid, $invoice['id']]);
        self::assertSame(['VIN-1', 'VIN-2'], $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testDraftReceiptIsRefused(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->pricedItem($sid, 'RCP-DRAFT');
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'serial' WHERE id = ?")->execute([$moto]);
        $draft = $this->documents->create($sid, [
            'doc_type' => 'receipt', 'origin' => 'manual', 'warehouse_id' => $wh, 'doc_date' => '2099-01-10', 'description' => 'Návrh',
            'lines' => [['stock_item_id' => $moto, 'qty' => '1.000', 'unit_cost' => '100.000000', 'tracking_allocations' => [['quantity' => '1', 'serial_number' => 'VIN-D']]]],
        ], $this->userId);

        $this->assertError($this->create($sid, (int) $draft['id'], ['client_id' => $this->client($sid)]), 409, 'receipt_not_posted');
    }

    public function testIssueRefusesManualLineWithoutPriceEvenWhenTotalIsPositive(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        [$a, $b] = [$this->pricedItem($sid, 'RCP-M1'), $this->pricedItem($sid, 'RCP-M2')];
        $receipt = $this->postedReceipt($sid, $wh, [['stock_item_id' => $a, 'qty' => '1.000'], ['stock_item_id' => $b, 'qty' => '1.000']]);
        [$lineA, $lineB] = $this->receiptLineIds($sid, $receipt);
        $invoice = $this->created($this->create($sid, $receipt, [
            'client_id' => $this->client($sid),
            'lines' => [['line_id' => $lineA, 'manual_price' => true], ['line_id' => $lineB]],
        ]));
        self::assertGreaterThan(0, $invoice['total_with_vat']);

        $issue = fn (): ResponseInterface => $this->container->get(IssueInvoiceAction::class)($this->request($sid, []), new Psr7Response(), ['id' => (string) $invoice['id']]);
        $this->assertError($issue(), 422, 'manual_price_missing');

        $items = $this->items($invoice['id']);
        $items[0]['unit_price_without_vat'] = 1200;
        $saved = $this->container->get(UpdateInvoiceAction::class)($this->request($sid, ['items' => $items]), new Psr7Response(), ['id' => (string) $invoice['id']]);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame(200, $issue()->getStatusCode());
    }

    public function testApprovalPathsRefuseManualLineWithoutPrice(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        [$a, $b] = [$this->pricedItem($sid, 'RCP-A1'), $this->pricedItem($sid, 'RCP-A2')];
        $receipt = $this->postedReceipt($sid, $wh, [['stock_item_id' => $a, 'qty' => '1.000'], ['stock_item_id' => $b, 'qty' => '1.000']]);
        [$lineA, $lineB] = $this->receiptLineIds($sid, $receipt);
        $client = $this->client($sid);
        $invoice = $this->created($this->create($sid, $receipt, [
            'client_id' => $client,
            'lines' => [['line_id' => $lineA, 'manual_price' => true], ['line_id' => $lineB]],
        ]));
        $id = (int) $invoice['id'];
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO projects (client_id, name, currency_id, requires_work_report_approval) VALUES (?, "Zakázka se schválením", ?, 1)')
            ->execute([$client, $this->currencyIdFor($sid)]);
        $pdo->prepare('UPDATE invoices SET project_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);

        // Žádost o schválení se s nevyplněnou cenou vůbec neodešle.
        $requested = $this->container->get(RequestApprovalAction::class)($this->request($sid, []), new Psr7Response(), ['id' => (string) $id]);
        $this->assertError($requested, 422, 'manual_price_missing');

        // Schválení správcem: rozhodnutí se zapíše, ale automat fakturu nevystaví.
        $approved = $this->container->get(UpdateApprovalStatusAction::class)(
            $this->requestAs($sid, ['status' => 'approved'], ['invoices' => 2, 'invoices.approval' => 2]),
            new Psr7Response(),
            ['id' => (string) $id],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        self::assertSame(InvoiceAmountPolicy::MANUAL_PRICE_MISSING_MESSAGE, $this->decoded($approved)['auto_send_error'] ?? null);

        // Schválení klientem přes veřejný odkaz volá tentýž automat.
        try {
            $this->container->get(AutoIssueAndSendService::class)->run($id, null, '127.0.0.1', 'phpunit');
            self::fail('Automat vystavil fakturu s řádkem bez ceny.');
        } catch (\DomainException $e) {
            self::assertSame(InvoiceAmountPolicy::MANUAL_PRICE_MISSING_MESSAGE, $e->getMessage());
        }
        $state = $pdo->prepare('SELECT status, approval_status FROM invoices WHERE id = ?');
        $state->execute([$id]);
        self::assertSame(['status' => 'draft', 'approval_status' => 'approved'], $state->fetch(\PDO::FETCH_ASSOC));
    }

    public function testNonVatPayerGetsExemptRateAndCanIssue(): void
    {
        $sid = $this->createSupplier();
        $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = 0 WHERE id = ?')->execute([$sid]);
        $wh = $this->warehouse($sid);
        $receipt = $this->postedReceipt($sid, $wh, [['stock_item_id' => $this->pricedItem($sid, 'RCP-NP'), 'qty' => '1.000']]);

        $invoice = $this->created($this->create($sid, $receipt, ['client_id' => $this->foreignClient($sid, 'CZ', null, true)]));

        self::assertFalse((bool) $invoice['reverse_charge']);
        self::assertEquals(0, $invoice['items'][0]['vat_rate_snapshot']);
        self::assertEquals(0, $invoice['total_vat']);
        $issued = $this->container->get(IssueInvoiceAction::class)($this->request($sid, []), new Psr7Response(), ['id' => (string) $invoice['id']]);
        self::assertSame(200, $issued->getStatusCode(), (string) $issued->getBody());
    }

    public function testIdentifiedPersonUsesReverseChargeOnlyForEuBusiness(): void
    {
        $sid = $this->createSupplier();
        $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = 0, is_identified = 1 WHERE id = ?')->execute([$sid]);
        $wh = $this->warehouse($sid);
        $item = $this->pricedItem($sid, 'RCP-IO');
        $invoiceFor = function (int $client) use ($sid, $wh, $item): array {
            $receipt = $this->postedReceipt($sid, $wh, [['stock_item_id' => $item, 'qty' => '1.000']]);
            return $this->created($this->create($sid, $receipt, ['client_id' => $client]));
        };

        $eu = $invoiceFor($this->foreignClient($sid, 'DE', 'DE123456789', false));
        self::assertTrue((bool) $eu['reverse_charge']);
        self::assertSame('22', $eu['vat_classification_code']);
        self::assertEquals(0, $eu['items'][0]['vat_rate_snapshot']);

        foreach ([$this->foreignClient($sid, 'DE', null, true), $this->foreignClient($sid, 'US', 'US1', true), $this->foreignClient($sid, 'CZ', 'CZ12345678', true)] as $client) {
            self::assertFalse((bool) $invoiceFor($client)['reverse_charge']);
        }
    }

    public function testEuBusinessWithReverseChargeHasNoVatAndNoOss(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $receipt = $this->postedReceipt($sid, $wh, [['stock_item_id' => $this->pricedItem($sid, 'RCP-EU'), 'qty' => '1.000']]);

        $invoice = $this->created($this->create($sid, $receipt, ['client_id' => $this->foreignClient($sid, 'DE', 'DE123456789', true)]));

        self::assertTrue((bool) $invoice['reverse_charge']);
        self::assertEquals(0, $invoice['total_vat']);
        self::assertFalse((bool) $invoice['items'][0]['oss_applicable']);
        self::assertNotEmpty($invoice['items'][0]['vat_classification_code']);
        self::assertArrayNotHasKey('_warnings', $invoice);
    }

    public function testForeignClientOutsideOssKeepsDomesticRateAndWarns(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->pricedItem($sid, 'RCP-EXP');
        foreach ([['US', null], ['DE', 'DE123456789']] as [$country, $vatId]) {
            $receipt = $this->postedReceipt($sid, $wh, [['stock_item_id' => $item, 'qty' => '1.000']]);
            [$lineId] = $this->receiptLineIds($sid, $receipt);

            $invoice = $this->created($this->create($sid, $receipt, ['client_id' => $this->foreignClient($sid, $country, $vatId, false)]));

            self::assertSame($this->vatRateId, (int) $invoice['items'][0]['vat_rate_id']);
            self::assertContains(ReceiptInvoiceService::WARNING_FOREIGN_DOMESTIC_VAT, $invoice['_warnings']);
            self::assertSame($lineId, $invoice['_warning_meta'][ReceiptInvoiceService::WARNING_FOREIGN_DOMESTIC_VAT]['lines'][0]['line_id']);
        }
    }

    public function testSerialSoldElsewhereIsLeftOutAndReported(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->pricedItem($sid, 'RCP-SOLD');
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'serial' WHERE id = ?")->execute([$moto]);
        $receipt = $this->postedReceipt($sid, $wh, [[
            'stock_item_id' => $moto, 'qty' => '2.000',
            'tracking_allocations' => [['quantity' => '1', 'serial_number' => 'VIN-A'], ['quantity' => '1', 'serial_number' => 'VIN-B']],
        ]]);
        [$lineId] = $this->receiptLineIds($sid, $receipt);
        $issue = $this->documents->create($sid, [
            'doc_type' => 'issue', 'origin' => 'manual', 'warehouse_id' => $wh, 'doc_date' => date('Y-m-d'), 'description' => 'Prodej jinde',
            'lines' => [['stock_item_id' => $moto, 'qty' => '1.000', 'tracking_allocations' => [['quantity' => '1', 'serial_number' => 'VIN-A']]]],
        ], $this->userId);
        $this->documents->post($sid, (int) $issue['id'], $this->userId);

        $invoice = $this->created($this->create($sid, $receipt, ['client_id' => $this->client($sid)]));

        self::assertSame(['VIN-B'], array_column($invoice['items'][0]['tracking_allocations'], 'serial_number'));
        self::assertSame([ReceiptInvoiceService::WARNING_TRACKING_UNAVAILABLE], $invoice['_warnings']);
        $line = $invoice['_warning_meta'][ReceiptInvoiceService::WARNING_TRACKING_UNAVAILABLE]['lines'][0];
        self::assertSame($lineId, $line['line_id']);
        self::assertSame(1, $line['line_no']);
        self::assertSame('VIN-A', $line['units'][0]['serial_number']);
    }

    public function testSerialTransferredToAnotherWarehouseIsUnavailable(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $other = $this->warehouse($sid, 'DRUHY', false);
        $moto = $this->pricedItem($sid, 'RCP-MOVED');
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'serial' WHERE id = ?")->execute([$moto]);
        $allocation = [['quantity' => '1', 'serial_number' => 'VIN-M']];
        $receipt = $this->postedReceipt($sid, $wh, [['stock_item_id' => $moto, 'qty' => '1.000', 'tracking_allocations' => $allocation]]);
        $transfer = $this->documents->create($sid, [
            'doc_type' => 'transfer', 'origin' => 'manual', 'warehouse_id' => $wh, 'warehouse_to_id' => $other, 'doc_date' => date('Y-m-d'), 'description' => 'Převod',
            'lines' => [['stock_item_id' => $moto, 'qty' => '1.000', 'tracking_allocations' => $allocation]],
        ], $this->userId);
        $this->documents->post($sid, (int) $transfer['id'], $this->userId);

        $invoice = $this->created($this->create($sid, $receipt, ['client_id' => $this->client($sid)]));

        self::assertSame([], $invoice['items'][0]['tracking_allocations'] ?? []);
        self::assertContains(ReceiptInvoiceService::WARNING_TRACKING_UNAVAILABLE, $invoice['_warnings']);
    }

    public function testPartlySoldLotCarriesOnlyWhatRemains(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->pricedItem($sid, 'RCP-LOT');
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'lot' WHERE id = ?")->execute([$item]);
        $receipt = $this->postedReceipt($sid, $wh, [['stock_item_id' => $item, 'qty' => '10.000', 'tracking_allocations' => [['quantity' => '10', 'lot_code' => 'L-1']]]]);
        $issue = $this->documents->create($sid, [
            'doc_type' => 'issue', 'origin' => 'manual', 'warehouse_id' => $wh, 'doc_date' => date('Y-m-d'), 'description' => 'Část šarže',
            'lines' => [['stock_item_id' => $item, 'qty' => '4.000', 'tracking_allocations' => [['quantity' => '4', 'lot_code' => 'L-1']]]],
        ], $this->userId);
        $this->documents->post($sid, (int) $issue['id'], $this->userId);

        $invoice = $this->created($this->create($sid, $receipt, ['client_id' => $this->client($sid)]));

        self::assertEquals(6, $invoice['items'][0]['tracking_allocations'][0]['quantity']);
        $unit = $invoice['_warning_meta'][ReceiptInvoiceService::WARNING_TRACKING_UNAVAILABLE]['lines'][0]['units'][0];
        self::assertEquals(10, $unit['requested']);
        self::assertEquals(6, $unit['available']);
    }

    public function testRepeatedDraftDoesNotOfferTheSameSerialAgain(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $moto = $this->pricedItem($sid, 'RCP-TWICE');
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'serial' WHERE id = ?")->execute([$moto]);
        $receipt = $this->postedReceipt($sid, $wh, [['stock_item_id' => $moto, 'qty' => '1.000', 'tracking_allocations' => [['quantity' => '1', 'serial_number' => 'VIN-T']]]]);
        $client = $this->client($sid);
        $this->created($this->create($sid, $receipt, ['client_id' => $client]));

        $second = $this->created($this->create($sid, $receipt, ['client_id' => $client, 'allow_duplicate' => true]));

        self::assertSame([], $second['items'][0]['tracking_allocations'] ?? []);
        self::assertContains(ReceiptInvoiceService::WARNING_TRACKING_UNAVAILABLE, $second['_warnings']);
    }

    public function testInvoiceErrorsNameReceiptLines(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $unit = 'r' . substr(uniqid(), -6);
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO units (code, label_cs, label_en, quantity_decimals) VALUES (?, ?, ?, 0)')->execute([$unit, $unit, $unit]);
        try {
            [$whole, $fractional] = [$this->pricedItem($sid, 'RCP-E1'), $this->pricedItem($sid, 'RCP-E2')];
            $pdo->prepare('UPDATE stock_items SET unit = ? WHERE id = ?')->execute([$unit, $fractional]);
            $receipt = $this->postedReceipt($sid, $wh, [['stock_item_id' => $whole, 'qty' => '1.000'], ['stock_item_id' => $fractional, 'qty' => '1.500']]);
            [, $secondLine] = $this->receiptLineIds($sid, $receipt);

            $res = $this->create($sid, $receipt, ['client_id' => $this->client($sid)]);

            self::assertSame(400, $res->getStatusCode(), (string) $res->getBody());
            $item = $this->decoded($res)['error']['items'][0];
            self::assertSame($secondLine, $item['line_id']);
            self::assertArrayNotHasKey('index', $item);
        } finally {
            $pdo->prepare('DELETE FROM units WHERE code = ?')->execute([$unit]);
        }
    }

    public function testErrorFieldsAreTranslatedToReceiptLines(): void
    {
        $error = ReceiptInvoiceService::errorWithLineIds([
            'index' => 1,
            'fields' => ['items.0.quantity' => ['x'], 'items.1' => ['y'], 'amount_to_pay' => ['z']],
        ], [11, 22]);

        self::assertSame(22, $error['line_id']);
        self::assertArrayNotHasKey('index', $error);
        self::assertSame(['lines.11.quantity' => ['x'], 'lines.22' => ['y'], 'amount_to_pay' => ['z']], $error['fields']);
    }

    public function testFlagNeverLandsOnIssuedInvoice(): void
    {
        $sid = $this->createSupplier();
        $line = ['description' => 'Služba', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 1000, 'vat_rate_id' => $this->vatRateId];
        $created = $this->created($this->container->get(\MyInvoice\Action\Invoice\CreateInvoiceAction::class)($this->request($sid, [
            'client_id' => $this->client($sid), 'invoice_type' => 'invoice', 'currency_id' => $this->currencyIdFor($sid), 'items' => [$line],
        ]), new Psr7Response()));
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued', varsymbol = ? WHERE id = ?")->execute(['FV' . $created['id'], $created['id']]);

        // Úprava vystavené: příznak se zahodí, takže nulová faktura neobejde kontrolu částky.
        $zero = ['unit_price_without_vat' => 0, 'manual_price' => true] + $line;
        $res = $this->container->get(UpdateInvoiceAction::class)(
            $this->request($sid, ['items' => [$zero]])->withQueryParams(['force' => '1']), new Psr7Response(), ['id' => (string) $created['id']],
        );
        self::assertSame(400, $res->getStatusCode(), (string) $res->getBody());
        $this->container->get(InvoiceRepository::class)->replaceItems($created['id'], [$zero]);
        self::assertFalse($this->items($created['id'])[0]['manual_price'], 'Repozitář příznak u vystavené neuloží.');
    }

    public function testRepeatedCreationWarnsUntilAllowedAndLinkWorksBothWays(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        [$a, $b] = [$this->pricedItem($sid, 'RCP-D1'), $this->pricedItem($sid, 'RCP-D2')];
        $receipt = $this->postedReceipt($sid, $wh, [['stock_item_id' => $a, 'qty' => '1.000'], ['stock_item_id' => $b, 'qty' => '1.000']]);
        [$lineA, $lineB] = $this->receiptLineIds($sid, $receipt);
        $client = $this->client($sid, 'Dealer Jedna');

        $first = $this->created($this->create($sid, $receipt, ['client_id' => $client, 'lines' => [['line_id' => $lineA]]]));

        $this->created($this->create($sid, $receipt, ['client_id' => $client, 'lines' => [['line_id' => $lineB]]]));

        $clash = $this->create($sid, $receipt, ['client_id' => $client, 'lines' => [['line_id' => $lineA]]]);
        $this->assertError($clash, 409, 'receipt_already_invoiced');
        $detail = $this->decoded($clash)['error']['items'][0];
        self::assertSame($first['id'], $detail['invoice_id']);
        self::assertSame([1], $detail['line_nos']);

        $second = $this->created($this->create($sid, $receipt, ['client_id' => $client, 'lines' => [['line_id' => $lineA]], 'allow_duplicate' => true]));
        self::assertNotSame($first['id'], $second['id']);
        $log = $this->db->pdo()->prepare("SELECT user_id, entity_id, payload FROM activity_log WHERE supplier_id = ? AND action = 'stock.receipt_invoiced_again'");
        $log->execute([$sid]);
        $rows = $log->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows, 'Vědomé přebití upozornění je v historii, běžné vytvoření ne.');
        self::assertSame([$this->userId, $receipt], [(int) $rows[0]['user_id'], (int) $rows[0]['entity_id']]);
        self::assertSame(['line_nos' => [1], 'invoice_id' => $second['id']], json_decode((string) $rows[0]['payload'], true));

        $doc = $this->decoded($this->container->get(StockDocumentAction::class)->get($this->request($sid, []), new Psr7Response(), ['id' => (string) $receipt]));
        self::assertCount(3, $doc['sales_invoices']);
        self::assertSame($second['id'], $doc['sales_invoices'][0]['invoice_id']);
        self::assertSame('Dealer Jedna', $doc['sales_invoices'][0]['client_name']);

        $sources = $this->decoded($this->container->get(StockDocumentAction::class)->sourceReceipts($this->request($sid, []), new Psr7Response(), ['id' => (string) $first['id']]));
        self::assertSame([$receipt], array_column($sources, 'id'));

        // Stornovaná faktura zboží uvolní.
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'cancelled' WHERE id IN (?, ?)")->execute([$first['id'], $second['id']]);
        $this->created($this->create($sid, $receipt, ['client_id' => $client, 'lines' => [['line_id' => $lineA]]]));
    }

    public function testRefusesWhatIsNotAReceiptAndForeignData(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->pricedItem($sid, 'RCP-X');
        $receipt = $this->postedReceipt($sid, $wh, [['stock_item_id' => $item, 'qty' => '5.000']]);
        $client = $this->client($sid);

        $issue = $this->documents->create($sid, [
            'doc_type' => 'issue', 'origin' => 'manual', 'warehouse_id' => $wh, 'doc_date' => date('Y-m-d'), 'description' => 'Výdej',
            'lines' => [['stock_item_id' => $item, 'qty' => '1.000']],
        ], $this->userId);
        $this->assertError($this->create($sid, (int) $issue['id'], ['client_id' => $client]), 409, 'not_a_receipt');

        $this->assertError($this->create($sid, $receipt, []), 422, 'validation_failed');
        $other = $this->createSupplier();
        $this->assertError($this->create($sid, $receipt, ['client_id' => $this->client($other)]), 422, 'invalid_client');
        $this->assertError($this->create($sid, $receipt, ['client_id' => 999999999]), 422, 'invalid_client');
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE supplier_id IN (?, ?)');
        $count->execute([$sid, $other]);
        self::assertSame(0, (int) $count->fetchColumn(), 'Cizí odběratel nezaloží nic.');
        $this->assertError($this->create($other, $receipt, ['client_id' => $this->client($other)]), 404, 'not_found');

        $this->documents->reverse($sid, $receipt, ['reason' => 'Omyl'], $this->userId);
        $this->assertError($this->create($sid, $receipt, ['client_id' => $client]), 409, 'receipt_reversed');
        self::assertFalse($this->db->pdo()->inTransaction());
    }

    public function testCreatingNeedsInvoiceWriteRightNotJustStock(): void
    {
        $sid = $this->createSupplier();
        $receipt = $this->postedReceipt($sid, $this->warehouse($sid), [['stock_item_id' => $this->pricedItem($sid, 'RCP-ROLE'), 'qty' => '1.000']]);
        $client = $this->client($sid);

        foreach ([
            'jen sklad' => ['stock' => 2, 'stock.documents.write' => 2],
            'faktury jen číst' => ['stock' => 2, 'stock.documents.write' => 2, 'invoices' => 1, 'invoices.create' => 1],
        ] as $case => $permissions) {
            $res = $this->container->get(ReceiptInvoiceAction::class)->create($this->requestAs($sid, ['client_id' => $client], $permissions), new Psr7Response(), ['id' => (string) $receipt]);
            self::assertSame(403, $res->getStatusCode(), $case . ': ' . $res->getBody());
            self::assertSame('forbidden', $this->decoded($res)['error']['code'], $case);
        }
        $links = $this->db->pdo()->prepare('SELECT COUNT(*) FROM stock_receipt_invoice_links WHERE supplier_id = ?');
        $links->execute([$sid]);
        self::assertSame(0, (int) $links->fetchColumn());

        $allowed = $this->requestAs($sid, ['client_id' => $client], ['stock' => 1, 'stock.documents.write' => 2, 'invoices.create' => 2]);
        $this->created($this->container->get(ReceiptInvoiceAction::class)->create($allowed, new Psr7Response(), ['id' => (string) $receipt]));
    }

    public function testUserWithoutInvoiceReadSeesOnlyLineNumbers(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        [$a, $b] = [$this->pricedItem($sid, 'RCP-P1'), $this->pricedItem($sid, 'RCP-P2')];
        $receipt = $this->postedReceipt($sid, $wh, [['stock_item_id' => $a, 'qty' => '1.000'], ['stock_item_id' => $b, 'qty' => '1.000']]);
        [$lineA, $lineB] = $this->receiptLineIds($sid, $receipt);
        $client = $this->client($sid, 'Tajný Dealer');
        $invoice = $this->created($this->create($sid, $receipt, ['client_id' => $client, 'lines' => [['line_id' => $lineA]]]));
        $this->db->pdo()->prepare('UPDATE invoices SET varsymbol = ? WHERE id = ?')->execute(['FV2699001', $invoice['id']]);
        $cancelled = $this->created($this->create($sid, $receipt, ['client_id' => $client, 'lines' => [['line_id' => $lineB]]]));
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?")->execute([$cancelled['id']]);

        $stockOnly = $this->requestAs($sid, [], ['stock' => 1]);
        $res = $this->container->get(StockDocumentAction::class)->get($stockOnly, new Psr7Response(), ['id' => (string) $receipt]);
        self::assertSame([['line_nos' => [1]]], $this->decoded($res)['sales_invoices'], 'Bez čísla, odběratele, id i stornovaných.');
        self::assertStringNotContainsString('FV2699001', (string) $res->getBody());
        self::assertStringNotContainsString('Tajný Dealer', (string) $res->getBody());

        $createOnly = $this->requestAs($sid, ['client_id' => $client, 'lines' => [['line_id' => $lineA]]], ['stock' => 1, 'stock.documents.write' => 2, 'invoices.create' => 2]);
        $clash = $this->container->get(ReceiptInvoiceAction::class)->create($createOnly, new Psr7Response(), ['id' => (string) $receipt]);
        $this->assertError($clash, 409, 'receipt_already_invoiced');
        self::assertSame([['line_nos' => [1]]], $this->decoded($clash)['error']['items']);
        self::assertStringNotContainsString('FV2699001', (string) $clash->getBody());
        self::assertStringNotContainsString('Tajný Dealer', (string) $clash->getBody());

        $reader = $this->requestAs($sid, [], ['stock' => 1, 'invoices' => 1]);
        $full = $this->decoded($this->container->get(StockDocumentAction::class)->get($reader, new Psr7Response(), ['id' => (string) $receipt]))['sales_invoices'];
        self::assertSame(['FV2699001', 'Tajný Dealer'], [$full[1]['varsymbol'], $full[1]['client_name']]);
    }

    public function testFailedInvoiceLeavesNoLink(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->pricedItem($sid, 'RCP-LOCK');
        $receipt = $this->postedReceipt($sid, $wh, [['stock_item_id' => $item, 'qty' => '1.000']]);
        $this->db->pdo()->prepare('UPDATE stock_items SET vat_rate_id = NULL WHERE id = ?')->execute([$item]);

        $this->assertError($this->create($sid, $receipt, ['client_id' => $this->client($sid)]), 422, 'stock_item_vat_missing');
        $links = $this->db->pdo()->prepare('SELECT COUNT(*) FROM stock_receipt_invoice_links WHERE supplier_id = ?');
        $links->execute([$sid]);
        self::assertSame(0, (int) $links->fetchColumn());
    }

    private function foreignClient(int $supplierId, string $iso2, ?string $vatId, bool $reverseCharge): int
    {
        $client = $this->client($supplierId, 'Zahraniční ' . $iso2);
        $this->db->pdo()->prepare('UPDATE clients SET country_id = (SELECT id FROM countries WHERE iso2 = ?), dic = ?, reverse_charge = ? WHERE id = ?')
            ->execute([$iso2, $vatId, $reverseCharge ? 1 : 0, $client]);
        return $client;
    }

    /** @param array<string,mixed> $body */
    private function create(int $supplierId, int $documentId, array $body): ResponseInterface
    {
        return $this->container->get(ReceiptInvoiceAction::class)->create($this->request($supplierId, $body), new Psr7Response(), ['id' => (string) $documentId]);
    }

    /** @return array<string,mixed> */
    private function created(ResponseInterface $res): array
    {
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        return $this->decoded($res);
    }

    /** @return array<string,mixed> */
    private function decoded(ResponseInterface $res): array
    {
        return json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertError(ResponseInterface $res, int $status, string $code): void
    {
        self::assertSame($status, $res->getStatusCode(), (string) $res->getBody());
        self::assertContains($this->decoded($res)['error']['code'], [$code, 'stock.error.' . $code]);
    }

    /** @return list<array<string,mixed>> */
    private function items(int $invoiceId): array
    {
        return $this->container->get(InvoiceRepository::class)->find($invoiceId)['items'];
    }

    /** @param list<array<string,mixed>> $lines */
    private function postedReceipt(int $supplierId, int $warehouseId, array $lines): int
    {
        $draft = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'origin' => 'manual', 'warehouse_id' => $warehouseId, 'doc_date' => date('Y-m-d'), 'description' => 'Příjem na zakázku',
            'lines' => array_map(static fn (array $l): array => $l + ['unit_cost' => '100.000000'], $lines),
        ], $this->userId);
        $this->documents->post($supplierId, (int) $draft['id'], $this->userId);
        return (int) $draft['id'];
    }

    /** @return list<int> */
    private function receiptLineIds(int $supplierId, int $documentId): array
    {
        return array_column($this->container->get(StockDocumentRepository::class)->lines($supplierId, $documentId), 'id');
    }

    private function pricedItem(int $supplierId, string $sku): int
    {
        $id = $this->item($supplierId, $sku);
        $this->db->pdo()->prepare('UPDATE stock_items SET vat_rate_id = ? WHERE id = ?')->execute([$this->vatRateId, $id]);
        $this->container->get(StockItemPriceRepository::class)->upsert($supplierId, $id, 'CZK', [
            'price_mode' => 'fixed', 'markup_pct' => null, 'fixed_price' => '1000.00', 'rounding' => 'none', 'is_manual_override' => false,
        ]);
        $this->container->get(PriceCalculationService::class)->recompute($supplierId, $id);
        return $id;
    }

    private function priceLevel(int $supplierId, string $code, string $discountPct): int
    {
        return $this->container->get(StockPriceLevelRepository::class)->insert($supplierId, [
            'code' => $code, 'name' => $code, 'default_discount_pct' => $discountPct, 'is_active' => true, 'display_order' => 0,
        ]);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,int> $permissions klíč práva => úroveň (1 čtení, 2 zápis)
     */
    private function requestAs(int $supplierId, array $body, array $permissions): ServerRequestInterface
    {
        return $this->request($supplierId, $body)
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Syntetická role', 'staff', true, $permissions));
    }

    /** @param array<string,mixed> $body */
    private function request(int $supplierId, array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/test')
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }
}
