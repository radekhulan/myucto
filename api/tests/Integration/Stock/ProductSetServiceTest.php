<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Sets\ProductSetService;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class ProductSetServiceTest extends StockTestCase
{
    private function setCard(int $supplierId, string $sku): int
    {
        $id = $this->item($supplierId, $sku);
        $this->db->pdo()->prepare('UPDATE stock_items SET is_stocked = 0 WHERE id = ?')->execute([$id]);
        return $id;
    }

    public function testQuoteUsesNestedDefinitionsAndCurrentComponentPrices(): void
    {
        $sid = $this->createSupplier();
        $a = $this->setCard($sid, 'SET-A');
        $b = $this->setCard($sid, 'SET-B');
        $leaf = $this->item($sid, 'LEAF');
        $this->db->pdo()->prepare('UPDATE stock_items SET sale_price_without_vat = 10, vat_rate_id = ? WHERE id = ?')->execute([$this->vatRateId, $leaf]);
        $service = $this->container->get(ProductSetService::class);
        $service->save($sid, $b, 0, ['components' => [['item_id' => $leaf, 'quantity' => '2']]]);
        $service->save($sid, $a, 0, ['components' => [['item_id' => $b, 'quantity' => '3']], 'prices' => ['CZK' => ['mode' => 'discount', 'discount_pct' => '10']]]);
        $quote = $service->quote($sid, $a, 'CZK', '2');
        self::assertSame('108.00', $quote['quote']['amount']);
        self::assertSame('12.000', $quote['quote']['components'][0]['quantity']);
        self::assertFalse($quote['prices_include_vat']);
        self::assertSame(1, $quote['row_version']);
    }

    public function testCycleIsRejectedWithoutChangingSavedRevision(): void
    {
        $sid = $this->createSupplier();
        $a = $this->setCard($sid, 'SET-A');
        $b = $this->setCard($sid, 'SET-B');
        $leaf = $this->item($sid, 'LEAF');
        $service = $this->container->get(ProductSetService::class);
        $service->save($sid, $a, 0, ['components' => [['item_id' => $leaf, 'quantity' => '1']]]);
        $service->save($sid, $b, 0, ['components' => [['item_id' => $a, 'quantity' => '1']]]);
        try {
            $service->save($sid, $a, 1, ['components' => [['item_id' => $b, 'quantity' => '1']]]);
            self::fail('Cycle accepted');
        } catch (EshopException $error) {
            self::assertSame('set_cycle', $error->errorCode);
        }
        self::assertSame(1, $service->get($sid, $a)['row_version']);
        self::assertSame($leaf, $service->get($sid, $a)['definition']['components'][0]['item_id']);
    }

    public function testForeignComponentCannotBeAttached(): void
    {
        $sid = $this->createSupplier();
        $foreign = $this->createSupplier();
        $a = $this->setCard($sid, 'SET-A');
        $leaf = $this->item($foreign, 'FOREIGN');
        $service = $this->container->get(ProductSetService::class);
        try {
            $service->save($sid, $a, 0, ['components' => [['item_id' => $leaf, 'quantity' => '1']]]);
            self::fail('Foreign component accepted');
        } catch (EshopException $error) {
            self::assertSame('set_component_not_found', $error->errorCode);
        }
        self::assertNull($service->get($sid, $a));
    }

    public function testStaleVersionCannotReplaceDefinition(): void
    {
        $sid = $this->createSupplier();
        $a = $this->setCard($sid, 'SET-A');
        $leaf = $this->item($sid, 'LEAF');
        $service = $this->container->get(ProductSetService::class);
        $definition = ['components' => [['item_id' => $leaf, 'quantity' => '1']]];
        $service->save($sid, $a, 0, $definition);
        $this->expectException(EshopException::class);
        $service->save($sid, $a, 0, $definition);
    }

    public function testVirtualSetCannotBeConvertedToPhysicalStock(): void
    {
        $sid = $this->createSupplier();
        $set = $this->setCard($sid, 'SET-FLAG');
        $leaf = $this->item($sid, 'SET-COMPONENT');
        $this->container->get(ProductSetService::class)->save($sid, $set, 0, ['components' => [['item_id' => $leaf, 'quantity' => '1']]]);
        $this->expectException(EshopException::class);
        $this->expectExceptionMessage('Virtuální set');
        $this->container->get(\MyInvoice\Service\Eshop\ProductCardService::class)->update($sid, $set, [
            'row_version' => $this->itemsRepo->find($sid, $set)['row_version'], 'is_stocked' => true,
        ]);
    }
}
