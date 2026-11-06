<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository;

use MyInvoice\Repository\VatCoefficientRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VatCoefficientDeductionPercentTest extends TestCase
{
    public static function cases(): iterable
    {
        yield 'vypořádací před zálohovým' => [['final_percent' => 70, 'provisional_percent' => 60], null, 70];
        yield 'nulový vypořádací' => [['final_percent' => 0, 'provisional_percent' => 60], null, 0];
        yield 'zálohový před minulým rokem' => [['provisional_percent' => 60], ['final_percent' => 50, 'settled_at' => '2098-12-31'], 60];
        yield 'minulý vypořádaný rok' => [null, ['final_percent' => 50, 'settled_at' => '2098-12-31'], 50];
        yield 'minulý nevypořádaný rok' => [null, ['final_percent' => 50, 'settled_at' => null], null];
        yield 'bez podkladů' => [null, null, null];
        yield 'ořez na sto' => [['provisional_percent' => 150], null, 100];
        yield 'ořez na nulu' => [['provisional_percent' => -10], null, 0];
    }

    #[DataProvider('cases')]
    public function testResolvesTheSameDeductionCoefficientForBatchedAndSingleInputs(?array $current, ?array $previous, ?int $expected): void
    {
        self::assertSame($expected, VatCoefficientRepository::deductionPercent($current, $previous));
    }
}
