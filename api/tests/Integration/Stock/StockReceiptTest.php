<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Repository\StockLandedCostRepository;
use MyInvoice\Service\Stock\StockException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Epic SKLAD, plán §8.2 scénář 8 — příjem na sklad z přijaté faktury (PF):
 * částečné příjmy (remaining_qty se snižuje) a přeplnění → 409 over_receipt.
 */
#[Group('integration')]
final class StockReceiptTest extends StockTestCase
{
    /**
     * Zrcadlo § 5.4 z výdejové strany: ze zálohové faktury, DDKP ani dobropisu se
     * sklad nepohybuje.
     *
     * Bez filtru šlo naskladnit ze zálohy a pak ZNOVU z vyúčtovací faktury — dedup je
     * per doklad (`receivedQtyByPurchaseInvoiceItem` filtruje `purchase_invoice_id`),
     * takže obojí má vlastní kvótu a vazba `advance_purchase_invoice_id` se skladu
     * netýká. U dobropisu je to horší: se zápornou quantity spadne na `over_receipt`,
     * ale s KLADNOU quantity a zápornou cenou projde a vyrobí příjemku se ZÁPORNOU
     * pořizovací cenou.
     */
    public function testNonReceivableDocumentKindsAreRejected(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'PF-KIND');
        $vendorId = $this->client($supplierId, 'Dodavatel kind');

        foreach (['advance', 'tax_document', 'credit_note'] as $kind) {
            $piId = $this->purchaseInvoice($supplierId, $vendorId);
            $this->db->pdo()->prepare('UPDATE purchase_invoices SET document_kind = ? WHERE id = ?')
                ->execute([$kind, $piId]);
            $piItemId = $this->purchaseInvoiceItem($piId, $itemId, '5.000', 100.0);

            $proposal = $this->receipts->proposeForPurchaseInvoice($supplierId, $piId);
            self::assertSame([], $proposal['lines'], "Návrh pro {$kind} musí být prázdný (UI tlačítko se nenabídne).");
            self::assertSame($kind, $proposal['not_receivable_kind'] ?? null);
            self::assertSame([], $proposal['uncoded_lines']);

            try {
                $this->receipts->createReceipt($supplierId, $piId, [
                    'warehouse_id' => $whId,
                    'doc_date'     => '2099-01-10',
                    'lines'        => [['purchase_invoice_item_id' => $piItemId, 'quantity' => '5.000']],
                ], $this->userId);
                self::fail("Příjemka z dokladu typu {$kind} musí selhat.");
            } catch (StockException $e) {
                self::assertSame('invalid_document', $e->errorCode, "Typ {$kind}");
            }
        }
    }

    /** Běžná přijatá faktura zůstává beze změny — filtr nesmí zablokovat normální příjem. */
    public function testRegularInvoiceStillReceivable(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'PF-OK');
        $vendorId = $this->client($supplierId, 'Dodavatel ok');
        $piId = $this->purchaseInvoice($supplierId, $vendorId);
        $piItemId = $this->purchaseInvoiceItem($piId, $itemId, '3.000', 50.0);

        $proposal = $this->receipts->proposeForPurchaseInvoice($supplierId, $piId);
        self::assertCount(1, $proposal['lines']);
        self::assertArrayNotHasKey('not_receivable_kind', $proposal);

        $receipt = $this->receipts->createReceipt($supplierId, $piId, [
            'warehouse_id' => $whId,
            'doc_date'     => '2099-01-10',
            'lines'        => [['purchase_invoice_item_id' => $piItemId, 'quantity' => '3.000']],
        ], $this->userId);
        self::assertGreaterThan(0, (int) $receipt['id']);
    }

    public function testPartialReceiptsDecreaseRemainingQty(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'PF-1');
        $vendorId = $this->client($supplierId, 'Dodavatel PF-1');
        $piId = $this->purchaseInvoice($supplierId, $vendorId);
        $piItemId = $this->purchaseInvoiceItem($piId, $itemId, '10.000', 25.0);

        $proposalBefore = $this->receipts->proposeForPurchaseInvoice($supplierId, $piId);
        self::assertSame('10.000', $proposalBefore['lines'][0]['remaining_qty']);

        $firstReceipt = $this->receipts->createReceipt($supplierId, $piId, [
            'warehouse_id' => $whId,
            'doc_date'     => '2099-01-10',
            'lines'        => [[
                'purchase_invoice_item_id' => $piItemId,
                'quantity'                 => '6.000',
            ]],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $firstReceipt['id'], $this->userId);

        $proposalMid = $this->receipts->proposeForPurchaseInvoice($supplierId, $piId);
        self::assertSame('4.000', $proposalMid['lines'][0]['remaining_qty'], 'po první příjemce zbývá 10 − 6 = 4 ks.');

        $secondReceipt = $this->receipts->createReceipt($supplierId, $piId, [
            'warehouse_id' => $whId,
            'doc_date'     => '2099-01-12',
            'lines'        => [[
                'purchase_invoice_item_id' => $piItemId,
                'quantity'                 => '4.000',
            ]],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $secondReceipt['id'], $this->userId);

        $proposalAfter = $this->receipts->proposeForPurchaseInvoice($supplierId, $piId);
        self::assertSame('0.000', $proposalAfter['lines'][0]['remaining_qty']);

        $level = $this->level($supplierId, $whId, $itemId);
        self::assertSame(10000, $level['qtyT']);

        $receiptsForPi = $this->receipts->receiptsForPurchaseInvoice($supplierId, $piId);
        self::assertCount(2, $receiptsForPi);
    }

    public function testOverReceiptFails409(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'PF-2');
        $vendorId = $this->client($supplierId, 'Dodavatel PF-2');
        $piId = $this->purchaseInvoice($supplierId, $vendorId);
        $piItemId = $this->purchaseInvoiceItem($piId, $itemId, '5.000', 25.0);

        try {
            $this->receipts->createReceipt($supplierId, $piId, [
                'warehouse_id' => $whId,
                'doc_date'     => '2099-01-10',
                'lines'        => [[
                    'purchase_invoice_item_id' => $piItemId,
                    'quantity'                 => '6.000',
                ]],
            ], $this->userId);
            self::fail('createReceipt() nad rámec zbývajícího množství měl vyhodit over_receipt.');
        } catch (StockException $e) {
            self::assertSame('over_receipt', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }

        self::assertSame([], $this->receipts->receiptsForPurchaseInvoice($supplierId, $piId), 'neúspěšný pokus nesmí vytvořit doklad.');
    }

    public function testDraftEditReallocatesPersistedLandedCosts(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemA = $this->item($supplierId, 'LC-A');
        $itemB = $this->item($supplierId, 'LC-B');
        $draft = $this->documents->create($supplierId, [
            'doc_type' => 'receipt',
            'origin' => 'manual',
            'warehouse_id' => $whId,
            'doc_date' => '2099-02-01',
            'description' => 'Test vedlejších nákladů',
            'lines' => [
                ['stock_item_id' => $itemA, 'qty' => '1.000', 'unit_cost' => '100.000000'],
                ['stock_item_id' => $itemB, 'qty' => '1.000', 'unit_cost' => '100.000000'],
            ],
        ], $this->userId);
        /** @var StockLandedCostRepository $landed */
        $landed = $this->container->get(StockLandedCostRepository::class);
        $landed->insert($supplierId, [
            'document_id' => (int) $draft['id'],
            'description' => 'Doprava',
            'amount' => '30.00',
            'allocation' => 'by_qty',
        ]);

        $updated = $this->documents->updateDraft($supplierId, (int) $draft['id'], [
            'doc_type' => 'receipt',
            'origin' => 'manual',
            'warehouse_id' => $whId,
            'doc_date' => '2099-02-01',
            'description' => 'Test vedlejších nákladů',
            'lines' => [
                ['stock_item_id' => $itemA, 'qty' => '1.000', 'unit_cost' => '100.000000', 'extra_cost' => '999'],
                ['stock_item_id' => $itemB, 'qty' => '2.000', 'unit_cost' => '100.000000'],
            ],
        ], $this->userId);

        self::assertSame(['10.00', '20.00'], array_column($updated['lines'], 'extra_cost'));
        self::assertSame(3000, array_sum(array_map(
            static fn (array $line): int => (int) round((float) $line['extra_cost'] * 100),
            $updated['lines'],
        )));
    }
}
