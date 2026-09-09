<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eshop;

use MyInvoice\Service\Eshop\CatalogReadRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogReadRequestTest extends TestCase
{
    #[DataProvider('invalidRequests')]
    public function testMalformedRequestsAreRejected(string $method, array $body): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CatalogReadRequest::$method($body);
    }

    public static function invalidRequests(): iterable
    {
        yield ['products', ['ids' => []]];
        yield ['products', ['ids' => range(1, 501)]];
        yield ['products', ['ids' => [1, 1]]];
        yield ['products', ['ids' => ['1']]];
        yield ['products', ['ids' => [1], 'fields' => ['note']]];
        yield ['products', ['ids' => [1], 'fields' => []]];
        yield ['products', ['ids' => [1], 'warehouse_ids' => []]];
        yield ['products', ['ids' => [1], 'currencies' => ['CZK;DROP']]];
        yield ['products', ['ids' => [1], 'locales' => [['cs']]]];
        yield ['products', ['ids' => [1], 'filters' => []]];
        yield ['prices', ['items' => [['id' => 1, 'qty' => '0']]]];
        yield ['prices', ['items' => [['id' => 1, 'qty' => '-1']]]];
        yield ['prices', ['items' => [['id' => 1, 'qty' => '1.0001']]]];
        yield ['prices', ['items' => [['id' => 1, 'qty' => 1.5]]]];
        yield ['prices', ['items' => [['id' => 1], ['id' => 1]]]];
        yield ['prices', ['items' => [['id' => 1]], 'on_date' => '2026-02-30']];
    }

    public function testDefaultsAndDecimalQuantitiesAreExplicit(): void
    {
        $product = CatalogReadRequest::products(['ids' => [7, 3]]);
        self::assertSame([7, 3], $product['ids']);
        self::assertSame(['sku', 'name', 'ean', 'is_active'], $product['fields']);
        self::assertSame(['CZK'], $product['currencies']);
        self::assertSame(['cs'], $product['locales']);
        $prices = CatalogReadRequest::prices(['items' => [['id' => 7, 'qty' => '2.5'], ['id' => 3]], 'currency' => 'eur']);
        self::assertSame([7 => '2.500', 3 => '1.000'], $prices['quantities']);
        self::assertSame('EUR', $prices['currency']);
    }
}
