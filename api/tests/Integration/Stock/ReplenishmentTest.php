<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Stock\PurchaseOrderService;
use MyInvoice\Service\Stock\ReplenishmentService;
use PHPUnit\Framework\Attributes\Group;

/**
 * „Co objednat" (Epic SKLAD „na cestě", §5.6).
 *
 *     suggested_qty = max(0, min_qty × koef − on_hand + reserved − in_transit)
 *
 * Nejdůležitější je {@see testInTransitIsSubtractedSoNothingIsOrderedTwice()}:
 * odečtení „na cestě" je celý smysl epicu. Bez něj obrazovka navrhne doobjednat
 * zboží, které už je na cestě, a firma ho koupí dvakrát.
 */
#[Group('integration')]
final class ReplenishmentTest extends StockTestCase
{
    private ReplenishmentService $replenishment;
    private PurchaseOrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->replenishment = $this->container->get(ReplenishmentService::class);
        $this->orders        = $this->container->get(PurchaseOrderService::class);
    }

    public function testItemBelowMinimumIsSuggested(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->itemWithMin($sid, 'RP-1', '10.000');
        $this->receiveStock($sid, $whId, $item, '3.000', 20.0);

        $row = $this->onlySuggestion($sid, $item);
        self::assertSame('3.000', $row['on_hand']);
        self::assertSame('10.000', $row['min_qty']);
        self::assertSame('7.000', $row['suggested_qty']);
    }

    public function testItemAtOrAboveMinimumIsNotSuggested(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->itemWithMin($sid, 'RP-2', '5.000');
        $this->receiveStock($sid, $whId, $item, '5.000', 20.0);

        self::assertSame([], $this->suggestionsFor($sid, $item));
    }

    /**
     * JÁDRO EPICU. Karta pod minimem, ale zbytek už je objednaný → nesmí se
     * navrhnout znovu.
     */
    public function testInTransitIsSubtractedSoNothingIsOrderedTwice(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->itemWithMin($sid, 'RP-3', '10.000');
        $this->receiveStock($sid, $whId, $item, '3.000', 20.0);

        // Bez objednávky chybí 7 kusů.
        self::assertSame('7.000', $this->onlySuggestion($sid, $item)['suggested_qty']);

        // Objednáme 4 → chybí už jen 3.
        $orderId = $this->createOrder($sid, $whId, $item, '4');
        $this->orders->send($sid, $orderId, $this->userId);

        $row = $this->onlySuggestion($sid, $item);
        self::assertSame('4.000', $row['in_transit']);
        self::assertSame('3.000', $row['suggested_qty'], 'Na cestě se MUSÍ odečíst — jinak se objedná dvakrát.');

        // Objednáme zbytek → nenavrhuje se nic.
        $second = $this->createOrder($sid, $whId, $item, '3');
        $this->orders->send($sid, $second, $this->userId);
        self::assertSame([], $this->suggestionsFor($sid, $item));
    }

    /**
     * Rezervace se naopak PŘIČÍTÁ: ty kusy sice leží ve skladu, ale drží je
     * vystavená faktura, takže na pokrytí minima nejsou.
     */
    public function testReservationIncreasesTheSuggestion(): void
    {
        $sid  = $this->createSupplier(autoIssue: false);
        $whId = $this->warehouse($sid);
        $item = $this->itemWithMin($sid, 'RP-4', '10.000');
        $this->receiveStock($sid, $whId, $item, '10.000', 20.0);

        self::assertSame([], $this->suggestionsFor($sid, $item), 'Přesně na minimu se nic nenavrhuje.');

        $clientId  = $this->client($sid, 'Odběratel');
        $invoiceId = $this->invoiceDraft($sid, $clientId);
        $this->invoiceItem($invoiceId, $item, $whId, '4.000', 50.0);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$invoiceId]);

        $row = $this->onlySuggestion($sid, $item);
        self::assertSame('4.000', $row['reserved']);
        self::assertSame('4.000', $row['suggested_qty'], 'Rezervované kusy jsou fakticky pryč.');
    }

    public function testSuggestionRoundsUpToPackageAndRespectsMinimumOrder(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->itemWithMin($sid, 'RP-5', '10.000');
        $this->receiveStock($sid, $whId, $item, '3.000', 20.0); // chybí 7
        $vendorId = $this->client($sid, 'Dodavatel s balením');
        $this->vendorOffer($sid, $item, $vendorId, ['package_qty' => '6.000']);

        $row = $this->onlySuggestion($sid, $item);
        self::assertSame('7.000', $row['shortfall']);
        self::assertSame('12.000', $row['suggested_qty'], '7 se zaokrouhlí nahoru na dvě balení po 6.');
        self::assertSame($vendorId, $row['preferred_vendor']['client_id']);
    }

    public function testMinimumOrderQuantityFloorsTheSuggestion(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->itemWithMin($sid, 'RP-6', '10.000');
        $this->receiveStock($sid, $whId, $item, '9.000', 20.0); // chybí 1
        $vendorId = $this->client($sid, 'Dodavatel s minimem');
        $this->vendorOffer($sid, $item, $vendorId, ['min_order_qty' => '25.000']);

        $row = $this->onlySuggestion($sid, $item);
        self::assertSame('1.000', $row['shortfall']);
        self::assertSame('25.000', $row['suggested_qty']);
    }

    public function testMinimumOrderQuantityIsRoundedUpToWholePackages(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->itemWithMin($sid, 'RP-MOQ-PACK', '10.000');
        $this->receiveStock($sid, $whId, $item, '7.000', 20.0); // chybí 3
        $vendorId = $this->client($sid, 'Dodavatel s minimem i balením');
        $this->vendorOffer($sid, $item, $vendorId, [
            'min_order_qty' => '15.000',
            'package_qty'   => '10.000',
        ]);

        $row = $this->onlySuggestion($sid, $item);
        self::assertSame('3.000', $row['shortfall']);
        self::assertSame('20.000', $row['suggested_qty']);
    }

    public function testSuggestionIncludesItemBeyondFormerTwoThousandItemCutoff(): void
    {
        $sid = $this->createSupplier();
        $this->warehouse($sid);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO stock_items (supplier_id, sku, name, item_type, unit, min_qty, is_active)
             VALUES (?, ?, ?, "goods", "ks", ?, 1)'
        );
        $lastId = 0;
        for ($i = 1; $i <= 2001; $i++) {
            $stmt->execute([
                $sid,
                'RP-LIMIT-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'Karta ' . $i,
                $i === 2001 ? '5.000' : null,
            ]);
            $lastId = (int) $this->db->pdo()->lastInsertId();
        }

        $result = $this->replenishment->suggest($sid, ['limit' => 10, 'offset' => 0]);
        self::assertSame(1, $result['total']);
        self::assertSame($lastId, $result['items'][0]['stock_item_id']);
        self::assertSame('5.000', $result['items'][0]['suggested_qty']);
    }

    public function testPreferredVendorWinsOverCheaperOne(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->itemWithMin($sid, 'RP-7', '10.000');
        $this->receiveStock($sid, $whId, $item, '1.000', 20.0);

        $cheap     = $this->client($sid, 'Levný');
        $preferred = $this->client($sid, 'Preferovaný');
        $this->vendorOffer($sid, $item, $cheap, ['purchase_price' => '10.00']);
        $this->vendorOffer($sid, $item, $preferred, ['purchase_price' => '99.00', 'is_preferred' => 1]);

        $row = $this->onlySuggestion($sid, $item);
        self::assertSame($preferred, $row['preferred_vendor']['client_id']);
    }

    public function testItemWithoutMinimumIsNeverSuggested(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'RP-8'); // min_qty NULL
        $this->receiveStock($sid, $whId, $item, '0.001', 20.0);

        self::assertSame([], $this->suggestionsFor($sid, $item), 'Bez minima není co hlídat.');
    }

    public function testInactiveItemIsNeverSuggested(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->itemWithMin($sid, 'RP-9', '10.000');
        $this->receiveStock($sid, $whId, $item, '1.000', 20.0);
        $this->db->pdo()->prepare('UPDATE stock_items SET is_active = 0 WHERE id = ? AND supplier_id = ?')
            ->execute([$item, $sid]);

        self::assertSame([], $this->suggestionsFor($sid, $item));
    }

    /** Rozhodnutí #12: karta bez jediného pohybu se navrhuje na celé minimum. */
    public function testBrandNewItemWithoutAnyMovementIsSuggestedForTheWholeMinimum(): void
    {
        $sid = $this->createSupplier();
        $this->warehouse($sid);
        $item = $this->itemWithMin($sid, 'RP-10', '15.000');

        $row = $this->onlySuggestion($sid, $item);
        self::assertSame('0.000', $row['on_hand'], 'Karta bez řádku ve stock_levels musí vracet 0, ne padat.');
        self::assertSame('15.000', $row['suggested_qty']);
        self::assertNull($row['preferred_vendor']);
    }

    public function testCoefficientRaisesTheTargetLevel(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->itemWithMin($sid, 'RP-11', '10.000');
        $this->receiveStock($sid, $whId, $item, '10.000', 20.0);

        $result = $this->replenishment->suggest($sid, ['item_ids' => [$item], 'coefficient' => 1.5]);
        self::assertCount(1, $result['items']);
        self::assertSame('5.000', $result['items'][0]['suggested_qty']);
    }

    public function testTenantIsolation(): void
    {
        $sid   = $this->createSupplier();
        $other = $this->createSupplier();
        $whId  = $this->warehouse($sid);
        $item  = $this->itemWithMin($sid, 'RP-12', '10.000');
        $this->receiveStock($sid, $whId, $item, '1.000', 20.0);

        self::assertCount(1, $this->suggestionsFor($sid, $item));
        self::assertSame([], $this->suggestionsFor($other, $item));
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    private function itemWithMin(int $supplierId, string $sku, string $minQty): int
    {
        $id = $this->item($supplierId, $sku);
        $this->db->pdo()->prepare('UPDATE stock_items SET min_qty = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$minQty, $id, $supplierId]);

        return $id;
    }

    /** @param array<string,mixed> $over */
    private function vendorOffer(int $supplierId, int $itemId, int $clientId, array $over = []): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO stock_item_vendors
                (supplier_id, stock_item_id, client_id, vendor_sku, purchase_price, currency_code,
                 min_order_qty, package_qty, is_preferred, is_active)
             VALUES (?, ?, ?, ?, ?, "CZK", ?, ?, ?, 1)'
        )->execute([
            $supplierId,
            $itemId,
            $clientId,
            $over['vendor_sku'] ?? ('V-' . $itemId),
            $over['purchase_price'] ?? '50.00',
            $over['min_order_qty'] ?? null,
            $over['package_qty'] ?? null,
            (int) ($over['is_preferred'] ?? 0),
        ]);
    }

    private function createOrder(int $sid, int $whId, int $itemId, string $qty): int
    {
        return (int) $this->orders->create($sid, [
            'vendor_id'    => $this->vendorCache[$sid] ??= $this->client($sid, 'Dodavatel objednávek'),
            'order_date'   => '2099-08-01',
            'warehouse_id' => $whId,
            'currency_id'  => $this->currencyIdFor($sid),
            'lines'        => [[
                'stock_item_id' => $itemId,
                'qty_ordered'   => $qty,
                'unit_price'    => '20',
                'description'   => 'Doobjednávka',
            ]],
        ], $this->userId)['id'];
    }

    /** @var array<int,int> */
    private array $vendorCache = [];

    /** @return list<array<string,mixed>> */
    private function suggestionsFor(int $supplierId, int $itemId): array
    {
        return $this->replenishment->suggest($supplierId, ['item_ids' => [$itemId]])['items'];
    }

    /** @return array<string,mixed> */
    private function onlySuggestion(int $supplierId, int $itemId): array
    {
        $rows = $this->suggestionsFor($supplierId, $itemId);
        self::assertCount(1, $rows, 'Očekáván právě jeden návrh na doobjednání.');

        return $rows[0];
    }
}
