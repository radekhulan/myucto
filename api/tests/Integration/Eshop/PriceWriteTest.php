<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Service\Eshop\Pricing\PriceWriteService;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Tests\Integration\Stock\StockTestCase;

final class PriceWriteTest extends StockTestCase
{
    public function testForeignCurrencyWriteAndDeleteInvalidateEditorVersion(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'PRICE-VERSION');
        $repository = $this->container->get(\MyInvoice\Repository\StockItemRepository::class);
        $before = $repository->find($sid, $item)['row_version'];
        $writer = $this->container->get(PriceWriteService::class);
        $writer->save($sid, $item, [$this->row('EUR', '12')]);
        $after = $repository->find($sid, $item)['row_version'];
        self::assertGreaterThan($before, $after);
        $writer->delete($sid, $item, 'EUR');
        self::assertGreaterThan($after, $repository->find($sid, $item)['row_version']);
    }

    public function testVersionedWriteReturnsLockedVersionAndRejectsStaleEditor(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'PRICE-CAS');
        $writer = $this->container->get(PriceWriteService::class);
        $repository = $this->container->get(\MyInvoice\Repository\StockItemRepository::class);

        $saved = $writer->saveVersioned($sid, $item, 1, [$this->row('EUR', '12')], true);
        self::assertSame(['EUR'], array_column($saved['prices'], 'currency_code'));
        self::assertSame($saved['row_version'], $repository->find($sid, $item)['row_version']);

        $this->db->pdo()->prepare(
            'UPDATE stock_items SET note = ?, row_version = row_version + 1 WHERE supplier_id = ? AND id = ?'
        )->execute(['Souběžná změna', $sid, $item]);

        try {
            $writer->saveVersioned($sid, $item, $saved['row_version'], [$this->row('EUR', '99')], true);
            self::fail('Zastaralá verze ceny musí skončit konfliktem.');
        } catch (EshopException $e) {
            self::assertSame('version_conflict', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }

        self::assertSame('Souběžná změna', $repository->find($sid, $item)['note']);
        self::assertSame(
            '12.00',
            $this->container->get(StockItemPriceRepository::class)->findByCurrency($sid, $item, 'EUR')['fixed_price'],
        );
    }

    public function testPriceWriteRoundsHalfUp(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'PRICE-ROUND');
        $rows = $this->container->get(PriceWriteService::class)->save($sid, $item, [$this->row('CZK', '19.999')]);
        self::assertSame('20.00', $rows[0]['computed_price']);
    }

    public function testPartialCurrencyWritePreservesOtherCurrencies(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'PRICE-PARTIAL');
        $writer = $this->container->get(PriceWriteService::class);
        $writer->save($sid, $item, [$this->row('CZK', '100'), $this->row('USD', '20')]);
        $result = $writer->save($sid, $item, [$this->row('EUR', '49.90')]);
        self::assertSame(['CZK', 'EUR', 'USD'], array_column($result, 'currency_code'));
        self::assertSame(['100.00', '49.90', '20.00'], array_column($result, 'computed_price'));
        $result = $writer->delete($sid, $item, 'EUR');
        self::assertSame(['CZK', 'USD'], array_column($result, 'currency_code'));
    }

    public function testInvalidBatchLeavesPricesUntouched(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'PRICE-INVALID');
        $writer = $this->container->get(PriceWriteService::class);
        $writer->save($sid, $item, [$this->row('CZK', '100')]);
        try {
            $writer->save($sid, $item, [$this->row('EUR', '10'), $this->row('USD', '10000000000')], true);
            self::fail('Invalid range accepted');
        } catch (\InvalidArgumentException) {
            $rows = $this->container->get(StockItemPriceRepository::class)->listForItem($sid, $item);
            self::assertSame(['CZK'], array_column($rows, 'currency_code'));
            self::assertSame('100.00', $rows[0]['computed_price']);
        }
    }

    public function testPriceWriteParticipatesInAggregateRollback(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'PRICE-ROLLBACK');
        $this->db->pdo()->beginTransaction();
        $this->container->get(PriceWriteService::class)->save($sid, $item, [$this->row('CZK', '100')]);
        $this->db->pdo()->rollBack();
        self::assertSame([], $this->container->get(StockItemPriceRepository::class)->listForItem($sid, $item));
    }

    public function testTenantCannotWriteForeignCard(): void
    {
        $sid = $this->createSupplier();
        $other = $this->createSupplier();
        $item = $this->item($sid, 'PRICE-FOREIGN');
        $this->expectException(\InvalidArgumentException::class);
        $this->container->get(PriceWriteService::class)->save($other, $item, [$this->row('CZK', '100')]);
    }

    private function row(string $currency, string $price): array
    {
        return ['currency_code' => $currency, 'price_mode' => 'fixed', 'fixed_price' => $price];
    }
}
