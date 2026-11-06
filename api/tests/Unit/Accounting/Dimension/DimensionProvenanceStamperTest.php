<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Dimension;

use MyInvoice\Service\Accounting\Dimension\DimensionDefaults;
use MyInvoice\Service\Accounting\Dimension\DimensionStamper;
use PHPUnit\Framework\TestCase;

final class DimensionProvenanceStamperTest extends TestCase
{
    public function testActualWinningLayerCarriesOriginEvenWhenValuesEqual(): void
    {
        $unknown = ['origin' => 'unknown', 'ref' => 'legacy'];
        $entity = ['origin' => 'entity_default', 'ref' => 'project:17'];
        $result = DimensionDefaults::layer([
            ['header' => [1 => 10], 'splits' => [], 'origins' => [1 => $unknown]],
            ['header' => [1 => 10, 2 => 20], 'splits' => [], 'origins' => [1 => $entity, 2 => $entity]],
        ]);
        self::assertSame([1 => $unknown, 2 => $entity], $result['origins']);
    }

    public function testItemHeaderAndExplicitLineOriginsFollowActualPrecedence(): void
    {
        $header = ['origin' => 'company_default', 'ref' => 'supplier:4'];
        $product = ['origin' => 'entity_default', 'ref' => 'product:23'];
        $unknown = ['origin' => 'unknown', 'ref' => 'legacy'];
        $lines = [['account_id' => 1, 'amount' => 10, 'dimensions' => [3 => 30], 'dimension_origins' => [3 => $unknown]]];
        $items = [['dims' => [1 => 10], 'origins' => [1 => $product], 'weight' => 10, 'account_id' => 1]];
        $result = DimensionStamper::assign($lines, [1 => 10, 2 => 20, 3 => 30], $items, [1 => 'expense'], true, [1 => $header, 2 => $header, 3 => $header]);
        self::assertSame([1 => $product, 2 => $header, 3 => $unknown], $result['lines'][0]['dimension_origins']);
    }

    public function testAllocationCarriesSingleDocumentOriginAndMarksMixedSplitAsModule(): void
    {
        $rule = ['origin' => 'rule_default', 'ref' => 'rule:2'];
        $unknown = ['origin' => 'unknown', 'ref' => 'legacy'];
        $documents = [
            ['amount' => 40.0, 'header' => [1 => 10], 'origins' => [1 => $rule]],
            ['amount' => 60.0, 'header' => [1 => 20], 'origins' => [1 => $unknown]],
        ];
        $lines = [
            ['side' => 'credit', 'amount' => 40.0],
            ['side' => 'credit', 'amount' => 60.0],
            ['side' => 'debit', 'amount' => 100.0],
        ];
        $result = DimensionStamper::allocateDocuments($lines, $documents, 'credit', [], 'bank:31');
        self::assertSame([1 => $rule], $result[0]['dimension_origins']);
        self::assertSame([1 => $unknown], $result[1]['dimension_origins']);
        $explicit = DimensionStamper::allocateDocuments([['side' => 'credit', 'amount' => 40.0, 'dimensions' => [1 => 10]]], $documents, 'credit', [], 'bank:31');
        self::assertSame([1 => ['origin' => 'unknown', 'ref' => null]], $explicit[0]['dimension_origins']);
        self::assertSame([1 => [10 => ['origin' => 'module', 'ref' => 'bank:31'], 20 => ['origin' => 'module', 'ref' => 'bank:31']]], $result[2]['dimension_split_origins']);
    }

    public function testSplitOriginsStayPerValueAndDoNotLeaveScalarOrigins(): void
    {
        $rule = ['origin' => 'rule_default', 'ref' => 'rule:9'];
        $unknown = ['origin' => 'unknown', 'ref' => null];
        $lines = [['dimensions' => [1 => -1], 'dimension_origins' => [1 => $rule]]];
        $result = DimensionStamper::expandSplits($lines, [[10 => 0.4, 11 => 0.6]], [[10 => $rule, 11 => $unknown]]);
        self::assertSame([1 => [10 => $rule, 11 => $unknown]], $result[0]['dimension_split_origins']);
        self::assertSame([], $result[0]['dimension_origins']);
        self::assertArrayNotHasKey('dimensions', $result[0]);
    }
}