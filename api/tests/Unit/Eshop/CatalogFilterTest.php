<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eshop;

use MyInvoice\Service\Eshop\CatalogFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogFilterTest extends TestCase
{
    public function testNormalizesHttpAndStructuredFormats(): void
    {
        self::assertSame([
            'active' => true,
            'tag_ids' => [2, 7],
            'missing' => ['ean', 'price'],
            'attribute_filters' => [['attribute_id' => 9, 'value_num_min' => '10']],
            'direction' => 'desc',
        ], CatalogFilter::normalize([
            'active' => '1', 'tag_ids' => '2,7,2', 'missing' => 'ean, price',
            'attribute_filters' => '[{"attribute_id":9,"value_num_min":"10"}]',
            'direction' => 'DESC', 'page' => '2', 'supplier_id' => '19',
        ]));

        self::assertSame([
            'tag_ids' => [4, 8], 'missing' => ['image'],
            'attribute_filters' => [['attribute_id' => 5, 'value_bool' => true]],
        ], CatalogFilter::normalize([
            'tag_ids' => [4, '8'], 'missing' => ['image'],
            'attribute_filters' => [['attribute_id' => 5, 'value_bool' => true]],
        ]));
    }

    #[DataProvider('invalidFilters')]
    public function testRejectsMalformedAndUnknownFilters(array $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CatalogFilter::normalize($input);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidFilters(): iterable
    {
        yield 'unknown nonempty key' => [['typo' => 'goods']];
        yield 'malformed tags' => [['tag_ids' => ['1', 'bad']]];
        yield 'nested missing' => [['missing' => [['ean']]]];
        yield 'malformed attribute array' => [['attribute_filters' => [['attribute_id' => 1, 'value_bool' => '1']]]];
        yield 'nested attribute text' => [['attribute_filters' => [['attribute_id' => 1, 'value_text' => ['bad']]]]];
        yield 'boolean two' => [['active' => 2]];
        yield 'overflow identifier' => [['warehouse_id' => '999999999999999999999999']];
        yield 'unknown item type' => [['type' => 'invalid']];
        yield 'too many tag ids' => [['tag_ids' => range(1, 101)]];
        yield 'too many attribute filters' => [['attribute_filters' => array_fill(0, 51, ['attribute_id' => 1])]];
        yield 'array scalar filter' => [['q' => ['product']]];
    }
}
