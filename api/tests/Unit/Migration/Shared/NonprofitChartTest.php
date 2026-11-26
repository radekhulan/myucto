<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Shared;

use MyInvoice\Service\Migration\Shared\NonprofitChart;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NonprofitChartTest extends TestCase
{
    #[DataProvider('charts')]
    public function testMatchesNonprofitChart(iterable $codes, bool $expected): void
    {
        self::assertSame($expected, NonprofitChart::matches($codes));
    }

    public static function charts(): iterable
    {
        yield 'vlastní jmění' => [['211', '901000'], true];
        yield 'výsledek v analytice' => [(static function (): \Generator { yield '931.419'; })(), true];
        yield 'základní kapitál v jiném roce' => [['931000', '901', '411230'], false];
        yield 'bez rozhodných účtů' => [['211', '602000', '911000', '932000'], false];
        yield 'prázdná osnova' => [[], false];
    }
}
