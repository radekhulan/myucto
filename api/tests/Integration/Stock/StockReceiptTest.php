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

    /**
     * Editor u příjemky s uloženými náklady posílá přidaný náklad jako položku
     * `landed_costs`, ne jako extra_cost řádku (ten server u takové příjemky nebere).
     * Náklad se uloží k ostatním, rozpustí do řádků a přežije další uložení i zaúčtování.
     */
    public function testDraftEditAddsLandedCostFromListAndKeepsItThroughPost(): void
    {
        [$supplierId, $draftId, $body] = $this->draftWithPersistedLandedCosts();

        $saved = $this->documents->updateDraft($supplierId, $draftId, $body + ['landed_costs' => [
            ['description' => 'Doprava z faktury', 'amount' => '30.00', 'allocation' => 'by_qty'],
            ['description' => 'Doprava ručně', 'amount' => '38.00', 'allocation' => 'by_qty'],
        ]], $this->userId);
        self::assertSame(['34.00', '34.00'], array_column($saved['lines'], 'extra_cost'));
        self::assertSame(['30.00', '38.00'], array_column($saved['landed_costs'], 'amount'));

        // Další uložení bez seznamu (hlavička, řádky) náklad ponechá a nepřičte podruhé.
        $again = $this->documents->updateDraft($supplierId, $draftId, $body, $this->userId);
        self::assertSame(['34.00', '34.00'], array_column($again['lines'], 'extra_cost'));

        $posted = $this->documents->post($supplierId, $draftId, $this->userId);
        self::assertSame('posted', $posted['status']);
        self::assertSame(['34.00', '34.00'], array_column($posted['lines'], 'extra_cost'));
        self::assertSame(['134.00', '134.00'], array_column($posted['lines'], 'value_total'));
    }

    /** Uložený náklad jde v seznamu upravit i smazat, řádky se přepočtou. */
    public function testDraftEditChangesAndRemovesPersistedLandedCosts(): void
    {
        [$supplierId, $draftId, $body] = $this->draftWithPersistedLandedCosts();
        /** @var StockLandedCostRepository $landed */
        $landed = $this->container->get(StockLandedCostRepository::class);

        $changed = $this->documents->updateDraft($supplierId, $draftId, $body + ['landed_costs' => [
            ['description' => 'Doprava z faktury', 'amount' => '50.00', 'allocation' => 'by_value'],
        ]], $this->userId);
        self::assertSame(['25.00', '25.00'], array_column($changed['lines'], 'extra_cost'));
        self::assertSame('by_value', $landed->listForDocument($supplierId, $draftId)[0]['allocation']);

        $removed = $this->documents->updateDraft($supplierId, $draftId, $body + ['landed_costs' => []], $this->userId);
        self::assertSame(['0.00', '0.00'], array_column($removed['lines'], 'extra_cost'));
        self::assertSame(['100.00', '100.00'], array_column($removed['lines'], 'value_total'));
        self::assertSame([], $landed->listForDocument($supplierId, $draftId));
    }

    /** Příjemka bez uložených nákladů bere extra_cost řádků jako dosud. */
    public function testDraftWithoutPersistedLandedCostsKeepsLineExtraCost(): void
    {
        $supplierId = $this->createSupplier();
        $body = [
            'doc_type' => 'receipt',
            'origin' => 'manual',
            'warehouse_id' => $this->warehouse($supplierId),
            'doc_date' => '2099-02-01',
            'description' => 'Bez uložených nákladů',
            'lines' => [['stock_item_id' => $this->item($supplierId, 'LC-M'), 'qty' => '1.000', 'unit_cost' => '100.000000', 'extra_cost' => '12.00']],
        ];
        $draftId = (int) $this->documents->create($supplierId, $body, $this->userId)['id'];
        $body['lines'][0]['extra_cost'] = '40.00';

        $updated = $this->documents->updateDraft($supplierId, $draftId, $body, $this->userId);

        self::assertSame(['40.00'], array_column($updated['lines'], 'extra_cost'));
        self::assertSame([], $updated['landed_costs']);
    }

    public function testLandedCostsAreRejectedOnNonReceiptDraft(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'LC-I');
        $this->receiveStock($supplierId, $whId, $itemId, '5', 100.0);
        $body = [
            'doc_type' => 'issue', 'origin' => 'manual', 'warehouse_id' => $whId,
            'doc_date' => '2099-02-01', 'description' => 'Výdej',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '1.000']],
        ];
        $draftId = (int) $this->documents->create($supplierId, $body, $this->userId)['id'];

        foreach ([[['amount' => '10']], []] as $costs) {
            try {
                $this->documents->updateDraft($supplierId, $draftId, $body + ['landed_costs' => $costs], $this->userId);
                self::fail('landed_costs u výdejky musí skončit 422.');
            } catch (StockException $e) {
                self::assertSame(422, $e->httpStatus);
            }
        }
    }

    /** Prázdný seznam u příjemky bez uložených nákladů nemaže extra_cost řádků. */
    public function testEmptyLandedCostListKeepsLineExtraCostWithoutStoredCosts(): void
    {
        $supplierId = $this->createSupplier();
        $body = [
            'doc_type' => 'receipt', 'origin' => 'manual', 'warehouse_id' => $this->warehouse($supplierId),
            'doc_date' => '2099-02-01', 'description' => 'Ruční náklad na řádku',
            'lines' => [['stock_item_id' => $this->item($supplierId, 'LC-E'), 'qty' => '1.000', 'unit_cost' => '100.000000', 'extra_cost' => '12.00']],
        ];
        $draftId = (int) $this->documents->create($supplierId, $body, $this->userId)['id'];

        $updated = $this->documents->updateDraft($supplierId, $draftId, $body + ['landed_costs' => []], $this->userId);

        self::assertSame(['12.00'], array_column($updated['lines'], 'extra_cost'));
    }

    /**
     * Příjemka bez uložených nákladů s ručním extra_cost po řádcích (např. založená přes API):
     * editor přidaný náklad rozpustí v prohlížeči a pošle extra_cost = ruční částka + podíl.
     * Dvojí uložení ani zaúčtování nic nepřičte ani neubere a seznam nákladů ji nepřepíše.
     */
    public function testManualLineExtraCostPlusEditorShareSurvivesSavesAndPost(): void
    {
        $supplierId = $this->createSupplier();
        $body = [
            'doc_type' => 'receipt', 'origin' => 'manual', 'warehouse_id' => $this->warehouse($supplierId),
            'doc_date' => '2099-02-01', 'description' => 'Ruční náklady po řádcích',
            'lines' => [
                ['stock_item_id' => $this->item($supplierId, 'LC-R1'), 'qty' => '1.000', 'unit_cost' => '100.000000', 'extra_cost' => '7.00'],
                ['stock_item_id' => $this->item($supplierId, 'LC-R2'), 'qty' => '3.000', 'unit_cost' => '100.000000', 'extra_cost' => '11.00'],
            ],
        ];
        $draftId = (int) $this->documents->create($supplierId, $body, $this->userId)['id'];
        // Doprava 40 Kč podle množství 1 : 3 = 10 + 30, jak ji rozpustí editor.
        $body['lines'][0]['extra_cost'] = '17.00';
        $body['lines'][1]['extra_cost'] = '41.00';

        foreach ([1, 2] as $save) {
            $saved = $this->documents->updateDraft($supplierId, $draftId, $body, $this->userId);
            self::assertSame(['17.00', '41.00'], array_column($saved['lines'], 'extra_cost'), "uložení {$save}");
        }
        try {
            $this->documents->updateDraft($supplierId, $draftId, $body + ['landed_costs' => [['amount' => '40']]], $this->userId);
            self::fail('seznam nákladů u příjemky bez uložených nákladů musí skončit 422.');
        } catch (StockException $e) {
            self::assertSame(422, $e->httpStatus);
        }

        $posted = $this->documents->post($supplierId, $draftId, $this->userId);
        self::assertSame(['17.00', '41.00'], array_column($posted['lines'], 'extra_cost'));
        self::assertSame(['117.00', '341.00'], array_column($posted['lines'], 'value_total'));
    }

    /** Neplatná položka ani odkaz na cizí fakturu nesmí uložené náklady smazat. */
    public function testInvalidLandedCostListLeavesStoredCostsUntouched(): void
    {
        [$supplierId, $draftId, $body] = $this->draftWithPersistedLandedCosts();
        $otherSupplier = $this->createSupplier();
        $foreignPi = $this->purchaseInvoice($otherSupplier, $this->client($otherSupplier, 'Cizí dodavatel'));
        $foreignItem = $this->purchaseInvoiceItem($foreignPi, null);
        /** @var StockLandedCostRepository $landed */
        $landed = $this->container->get(StockLandedCostRepository::class);

        foreach ([
            [['amount' => 'abc']],
            [['amount' => '0']],
            ['x' => ['amount' => '10']],
            [['amount' => '10', 'purchase_invoice_item_id' => $foreignItem]],
        ] as $costs) {
            try {
                $this->documents->updateDraft($supplierId, $draftId, $body + ['landed_costs' => $costs], $this->userId);
                self::fail('neplatný seznam nákladů musí skončit chybou.');
            } catch (StockException) {
            }
            self::assertSame(['30.00'], array_column($landed->listForDocument($supplierId, $draftId), 'amount'));
        }
    }

    /**
     * Koncept příjemky s řádky A, B (1 ks po 100 Kč) a uloženým nákladem 30 Kč podle
     * množství, rozpuštěným jako po založení z faktury (15 + 15).
     *
     * @return array{0:int,1:int,2:array<string,mixed>}
     */
    private function draftWithPersistedLandedCosts(): array
    {
        $supplierId = $this->createSupplier();
        $body = [
            'doc_type' => 'receipt',
            'origin' => 'manual',
            'warehouse_id' => $this->warehouse($supplierId),
            'doc_date' => '2099-02-01',
            'description' => 'Vedlejší náklady seznamem',
            'lines' => [
                ['stock_item_id' => $this->item($supplierId, 'LC-A'), 'qty' => '1.000', 'unit_cost' => '100.000000'],
                ['stock_item_id' => $this->item($supplierId, 'LC-B'), 'qty' => '1.000', 'unit_cost' => '100.000000'],
            ],
        ];
        $draftId = (int) $this->documents->create($supplierId, $body, $this->userId)['id'];
        /** @var StockLandedCostRepository $landed */
        $landed = $this->container->get(StockLandedCostRepository::class);
        $landed->insert($supplierId, [
            'document_id' => $draftId,
            'description' => 'Doprava z faktury',
            'amount' => '30.00',
            'allocation' => 'by_qty',
        ]);
        $allocated = $this->documents->updateDraft($supplierId, $draftId, $body, $this->userId);
        self::assertSame(['15.00', '15.00'], array_column($allocated['lines'], 'extra_cost'));

        return [$supplierId, $draftId, $body];
    }
}
