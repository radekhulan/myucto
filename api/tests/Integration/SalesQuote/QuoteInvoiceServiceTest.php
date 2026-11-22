<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\SalesQuote;

use MyInvoice\Action\Invoice\UncancelInvoiceAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\SalesQuote\QuoteException;
use MyInvoice\Service\SalesQuote\QuoteInvoiceService;
use MyInvoice\Service\SalesQuote\QuoteWorkflow;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class QuoteInvoiceServiceTest extends StockTestCase
{
    private const REQUIRED = '7d4ff6b0-73ee-44c8-b073-dea8cab9206c';
    private const OPTIONAL = 'd108d144-72ce-4916-b98c-1babf9fbd143';
    private const GOODS = 'b8ed9b3f-84b2-4ce9-88da-d3f9358c3657';
    private const LABOR = 'b88101e2-a6b2-4c03-aeb8-3792ca4d6bf5';

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            foreach ($this->supplierIds as $supplierId) {
                $pdo->prepare('DELETE FROM sales_quotes WHERE supplier_id = ?')->execute([$supplierId]);
                $pdo->prepare('DELETE FROM sales_orders WHERE supplier_id = ?')->execute([$supplierId]);
                $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ? ORDER BY id DESC')->execute([$supplierId]);
            }
        }
        parent::tearDown();
    }

    public function testDraftReservesOnlySelectedItemsAndPreservesOriginAfterEditing(): void
    {
        [$supplierId, $quote] = $this->offer();
        $conversion = $this->container->get(QuoteInvoiceService::class);
        $invoices = $this->container->get(InvoiceRepository::class);
        $calculator = $this->container->get(InvoiceCalculator::class);
        $request = [
            'type' => 'invoice',
            'idempotency_key' => 'draft-one',
            'items' => [['id' => self::GOODS, 'quantity' => 2]],
        ];

        $invoiceId = $conversion->convert($supplierId, $this->userId, $quote['id'], $request);
        $request['items'][0]['quantity'] = '2.000';
        self::assertSame($invoiceId, $conversion->convert($supplierId, $this->userId, $quote['id'], $request));
        $first = $conversion->summary($supplierId, $quote['id'], $quote);
        self::assertSame(180.0, $first['billing']['draft']);
        self::assertSame(90.0, $first['billing']['remaining']);
        self::assertSame(1.0, $first['quantities'][self::GOODS]['remaining_quantity']);
        self::assertArrayNotHasKey(self::LABOR, $first['quantities']);

        $this->assertConversionRejected($conversion, $supplierId, $quote, 'conversion_conflict', [
            ...$request, 'items' => [['id' => self::GOODS, 'quantity' => 1]],
        ]);
        $this->assertConversionRejected($conversion, $supplierId, $quote, 'quote_quantity_unavailable', [
            ...$request, 'idempotency_key' => 'draft-too-many',
        ]);
        $this->assertConversionRejected($conversion, $supplierId, $quote, 'item_not_accepted', [
            ...$request, 'idempotency_key' => 'draft-optional', 'items' => [['id' => self::LABOR, 'quantity' => 1]],
        ]);
        $otherSupplierId = $this->createSupplier();
        $this->assertConversionRejected($conversion, $otherSupplierId, $quote, 'quote_not_found', $request);

        $item = $invoices->itemsFor($invoiceId)[0];
        $invoices->replaceItems($invoiceId, [[...$item, 'description' => 'Přejmenovaná montážní sada', 'quantity' => 1]]);
        $calculator->recompute($invoiceId);
        $second = $conversion->listSummaries($supplierId, [$quote])[$quote['id']];
        self::assertSame(90.0, $second['billing']['draft']);
        self::assertSame(180.0, $second['billing']['remaining']);
        self::assertSame(2.0, $second['quantities'][self::GOODS]['remaining_quantity']);
        self::assertNotSame($item['id'], $invoices->itemsFor($invoiceId)[0]['id']);

        try {
            $invoices->replaceItems($invoiceId, [$item]);
            self::fail('Zastaralé uložení nesmí ztratit původ položky.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('mezitím změnily', $e->getMessage());
        }
        self::assertSame(2.0, $conversion->summary($supplierId, $quote['id'], $quote)['quantities'][self::GOODS]['remaining_quantity']);

        $invoices->delete($invoiceId);
        $released = $conversion->summary($supplierId, $quote['id'], $quote);
        self::assertSame(3.0, $released['quantities'][self::GOODS]['remaining_quantity']);
        self::assertSame(270.0, $released['billing']['remaining']);
    }

    public function testPercentAdvanceDoesNotCountAsFulfillment(): void
    {
        [$supplierId, $quote] = $this->offer();
        $conversion = $this->container->get(QuoteInvoiceService::class);
        $proformaId = $conversion->convert($supplierId, $this->userId, $quote['id'], [
            'type' => 'proforma', 'advance_percent' => 50, 'idempotency_key' => 'half-advance',
        ]);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE invoices SET status = 'paid' WHERE supplier_id = ? AND id = ?")->execute([$supplierId, $proformaId]);
        $summary = $conversion->summary($supplierId, $quote['id'], $quote);
        $advanceGross = round(135.0 * (1 + (float) $this->vatRatePercent / 100), 2);
        self::assertSame($advanceGross, $summary['billing']['advance_requested']);
        self::assertSame($advanceGross, $summary['billing']['advance_paid']);
        self::assertSame(0.0, $summary['billing']['billed']);
        self::assertSame(270.0, $summary['billing']['remaining']);

        $this->expectExceptionMessage('Částkovou zálohu vyúčtujte');
        $this->container->get(FinalFromProformaCreator::class)->create($proformaId, $this->userId);
    }

    public function testDraftProformaBlocksReopeningWhileLinked(): void
    {
        [$supplierId, $quote] = $this->offer();
        $workflow = $this->container->get(QuoteWorkflow::class);
        $proformaId = $this->container->get(QuoteInvoiceService::class)->convert($supplierId, $this->userId, $quote['id'], [
            'type' => 'proforma', 'advance_percent' => 50, 'idempotency_key' => 'advance-before-reopen',
        ]);
        self::assertTrue($workflow->detail($supplierId, $quote['id'], false)['reopen_blocked']);
        try {
            $workflow->state($supplierId, $quote['id'], $this->userId, ['status' => 'reopen']);
            self::fail('Navázaný doklad musí zablokovat opětovné otevření.');
        } catch (QuoteException $e) {
            self::assertSame('quote_has_documents', $e->errorCode);
        }
        $this->container->get(InvoiceRepository::class)->delete($proformaId);
        $workflow->state($supplierId, $quote['id'], $this->userId, ['status' => 'reopen']);
        self::assertSame('sent', $workflow->detail($supplierId, $quote['id'], false)['status']);
    }

    public function testFinalInvoiceFromQuantityAdvanceRetainsOrigin(): void
    {
        [$supplierId, $quote] = $this->offer();
        $conversion = $this->container->get(QuoteInvoiceService::class);
        $proformaId = $conversion->convert($supplierId, $this->userId, $quote['id'], [
            'type' => 'proforma', 'items' => [['id' => self::GOODS, 'quantity' => 2]], 'idempotency_key' => 'advance',
        ]);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE invoices SET status = 'paid' WHERE supplier_id = ? AND id = ?")->execute([$supplierId, $proformaId]);
        $finalId = $this->container->get(FinalFromProformaCreator::class)->create($proformaId, $this->userId);
        $summary = $conversion->summary($supplierId, $quote['id'], $quote);

        self::assertSame([$finalId, $proformaId], array_column($summary['documents'], 'invoice_id'));
        self::assertSame(180.0, $summary['billing']['draft']);
        self::assertSame(90.0, $summary['billing']['remaining']);

        $pdo->prepare("UPDATE invoices SET status = 'issued' WHERE supplier_id = ? AND id = ?")->execute([$supplierId, $finalId]);
        $summary = $conversion->summary($supplierId, $quote['id'], $quote);
        self::assertSame(180.0, $summary['billing']['billed']);
        self::assertSame(2.0, $summary['quantities'][self::GOODS]['billed_quantity']);
        self::assertSame(0.0, $summary['billing']['draft']);
        self::assertSame(90.0, $summary['billing']['remaining']);
    }

    public function testActiveOrderStopsAnotherInvoiceButAllowsAnAdvance(): void
    {
        [$supplierId, $quote] = $this->offer();
        $conversion = $this->container->get(QuoteInvoiceService::class);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO sales_orders (order_uuid, supplier_id, client_id, order_number, currency_id, currency_code, customer_snapshot)
              SELECT UUID(), supplier_id, client_id, 'TEST-QUOTE-ORDER', currency_id, 'CZK', '{}'
                FROM sales_quotes WHERE supplier_id = ? AND id = ?",
        )->execute([$supplierId, $quote['id']]);
        $orderId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO sales_quote_orders (supplier_id, quote_id, version_id, order_id, idempotency_key)
              SELECT supplier_id, id, current_version_id, ?, 'order-pending'
                FROM sales_quotes WHERE supplier_id = ? AND id = ?",
        )->execute([$orderId, $supplierId, $quote['id']]);

        $this->assertConversionRejected($conversion, $supplierId, $quote, 'quote_has_pending_order', [
            'type' => 'invoice', 'idempotency_key' => 'blocked',
        ]);
        self::assertGreaterThan(0, $conversion->convert($supplierId, $this->userId, $quote['id'], [
            'type' => 'proforma', 'idempotency_key' => 'advance-allowed',
        ]));

        $pdo->prepare("UPDATE sales_orders SET commercial_status = 'cancelled' WHERE supplier_id = ? AND id = ?")->execute([$supplierId, $orderId]);
        self::assertGreaterThan(0, $conversion->convert($supplierId, $this->userId, $quote['id'], [
            'type' => 'invoice', 'idempotency_key' => 'order-cancelled',
        ]));
    }

    public function testUndoCancellationWaitsUntilReplacementDraftReleasesQuoteQuantity(): void
    {
        [$supplierId, $quote] = $this->offer(stockEnabled: false);
        $conversion = $this->container->get(QuoteInvoiceService::class);
        $invoices = $this->container->get(InvoiceRepository::class);
        $undo = $this->container->get(UncancelInvoiceAction::class);
        $invoiceId = $conversion->convert($supplierId, $this->userId, $quote['id'], [
            'type' => 'invoice', 'items' => [['id' => self::GOODS, 'quantity' => 2]], 'idempotency_key' => 'first-invoice',
        ]);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE invoices SET status = 'cancelled' WHERE supplier_id = ? AND id = ?")->execute([$supplierId, $invoiceId]);
        $clientId = (int) $invoices->find($invoiceId)['client_id'];
        $cancellationId = $this->invoiceDraft($supplierId, $clientId, 'cancellation', ['parent_invoice_id' => $invoiceId]);
        $replacementId = $conversion->convert($supplierId, $this->userId, $quote['id'], [
            'type' => 'invoice', 'items' => [['id' => self::GOODS, 'quantity' => 2]], 'idempotency_key' => 'replacement-draft',
        ]);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/invoices/' . $invoiceId . '/uncancel')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');

        $rejected = $undo($request, new Response(), ['id' => (string) $invoiceId]);
        self::assertSame(409, $rejected->getStatusCode(), (string) $rejected->getBody());
        self::assertSame('quote_quantity_unavailable', json_decode((string) $rejected->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
        self::assertSame('cancelled', $invoices->find($invoiceId)['status']);
        self::assertNotNull($invoices->find($cancellationId));

        $invoices->delete($replacementId);
        $restored = $undo($request, new Response(), ['id' => (string) $invoiceId]);
        self::assertSame(200, $restored->getStatusCode(), (string) $restored->getBody());
        self::assertNull($invoices->find($cancellationId));
        self::assertSame(2.0, $conversion->summary($supplierId, $quote['id'], $quote)['quantities'][self::GOODS]['billed_quantity']);
    }

    private function offer(bool $stockEnabled = true): array
    {
        $supplierId = $this->createSupplier(stockEnabled: $stockEnabled);
        $clientId = $this->client($supplierId);
        $currency = $this->currencyIdFor($supplierId);
        $warehouseId = $this->warehouse($supplierId);
        $stockItemId = $this->item($supplierId, 'NAB-001');
        $goodsVat = round(270 * (float) $this->vatRatePercent / 100, 2);
        $laborVat = round(360 * (float) $this->vatRatePercent / 100, 2);
        $data = [
            'language' => 'cs', 'prices_include_vat' => false, 'accepted_section_ids' => [],
            'valid_until' => date('Y-m-d', strtotime('+30 days')),
            'sections' => [
                ['id' => self::REQUIRED, 'title' => 'Dodávka', 'kind' => 'required',
                 'totals' => ['without_vat' => 270, 'vat' => $goodsVat, 'with_vat' => 270 + $goodsVat], 'items' => [
                    [
                        'id' => self::GOODS, 'description' => 'Montážní sada', 'kind' => 'goods', 'quantity' => 3, 'unit' => 'ks',
                        'unit_price' => 100, 'discount_percent' => 10, 'vat_rate' => (float) $this->vatRatePercent,
                        'vat_rate_id' => $this->vatRateId, 'stock_item_id' => $stockItemId, 'warehouse_id' => $warehouseId,
                    ],
                ]],
                ['id' => self::OPTIONAL, 'title' => 'Možnosti', 'kind' => 'optional',
                 'totals' => ['without_vat' => 360, 'vat' => $laborVat, 'with_vat' => 360 + $laborVat], 'items' => [
                    [
                        'id' => self::LABOR, 'description' => 'Montáž', 'kind' => 'service', 'quantity' => 2, 'unit' => 'h',
                        'unit_price' => 180, 'discount_percent' => 0, 'vat_rate' => (float) $this->vatRatePercent,
                    ],
                ]],
            ],
        ];

        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO sales_quotes (supplier_id, quote_number, title, client_id, currency_id, valid_until, status)
             VALUES (?, 'NAB-2026-0001', 'Testovací nabídka', ?, ?, ?, 'accepted')",
        )->execute([$supplierId, $clientId, $currency, $data['valid_until']]);
        $quoteId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO sales_quote_versions (supplier_id, quote_id, revision, status, data_json)
             VALUES (?, ?, 1, 'accepted', ?)",
        )->execute([$supplierId, $quoteId, json_encode($data, JSON_THROW_ON_ERROR)]);
        $versionId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE sales_quotes SET current_version_id = ? WHERE supplier_id = ? AND id = ?')->execute([$versionId, $supplierId, $quoteId]);

        return [$supplierId, ['id' => $quoteId] + $data];
    }

    private function assertConversionRejected(QuoteInvoiceService $conversion, int $supplierId, array $quote, string $error, array $request): void
    {
        try {
            $conversion->convert($supplierId, $this->userId, $quote['id'], $request);
            self::fail('Převod měl být odmítnut.');
        } catch (QuoteException $e) {
            self::assertSame($error, $e->errorCode);
        }
    }
}
