<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Anonymization;

use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonProfile;
use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonSpool;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CanonicalJsonSpoolTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function jsonDocuments(): iterable
    {
        yield 'nested-and-order' => ['{"z":{"b":2,"a":1},"a":[{"y":2,"x":1},null,true,false]}'];
        yield 'numeric-object-keys' => ['{"1":"one","0":"zero","01":"leading","-1":"negative"}'];
        yield 'object-becomes-list-after-sort' => ['{"1":"one","0":"zero"}'];
        yield 'duplicate-last-wins' => ['{"b":1,"a":2,"b":3}'];
        yield 'duplicate-child-last-wins' => ['{"x":{"b":1},"x":{"a":2}}'];
        yield 'root-list' => ['[{"b":2,"a":1},3,2,1]'];
        yield 'unicode-and-slashes' => ['{"text":"Případ Á / řádek","escaped":"a\\\\b\\nc"}'];
        yield 'numbers' => ['{"int":9223372036854775807,"large":99999999999999999999,"float":1.25,"exp":1e25,"zero":-0.0}'];
        yield 'empty-containers' => ['{"object":{},"list":[]}'];
    }

    #[DataProvider('jsonDocuments')]
    public function testNonprofitProfileMatchesCallableLegacySsot(string $json): void
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $expected = NonprofitTaxRules::canonicalJson($decoded);
        $output = fopen('php://temp', 'w+b');
        self::assertIsResource($output);

        $result = (new CanonicalJsonSpool())->canonicalize(
            $this->chunks($json, 7),
            CanonicalJsonProfile::NONPROFIT,
            $output,
        );

        rewind($output);
        $actual = stream_get_contents($output);
        fclose($output);
        self::assertSame($expected, $actual);
        self::assertSame(NonprofitTaxRules::hash($decoded), $result->sha256);
        self::assertSame(strlen($expected), $result->bytes);
    }

    #[DataProvider('jsonDocuments')]
    public function testPropertyProfileMatchesCallableLegacySsot(string $json): void
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $output = fopen('php://temp', 'w+b');
        self::assertIsResource($output);

        $result = (new CanonicalJsonSpool())->canonicalize(
            $this->chunks($json, 11),
            CanonicalJsonProfile::PROPERTY,
            $output,
        );

        fclose($output);
        self::assertSame(PropertyInput::hash($decoded), $result->sha256);
        $listKeys = [];
        foreach ($decoded as $key => $value) if (is_array($value) && array_is_list($value)) $listKeys[] = $key;
        self::assertSame(PropertyInput::hashLists($decoded, $listKeys), $result->sha256);
    }

    /** @return iterable<string> */
    private function chunks(string $json, int $size): iterable
    {
        for ($offset = 0, $length = strlen($json); $offset < $length; $offset += $size) {
            yield substr($json, $offset, $size);
        }
    }
}
