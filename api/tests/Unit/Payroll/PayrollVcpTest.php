<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollVcp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollVcpTest extends TestCase
{
    public function testNormalizesTheOfficialNineDigitFormatStartingWithSix(): void
    {
        self::assertSame('612345678', PayrollVcp::normalize(' 612 345 678 '));
    }

    #[DataProvider('invalidValues')]
    public function testRejectsValuesOutsideTheOfficialFormat(string $value): void
    {
        self::assertFalse(PayrollVcp::isValid($value));
        $this->expectException(\InvalidArgumentException::class);
        PayrollVcp::normalize($value);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidValues(): iterable
    {
        yield 'jiná první číslice' => ['123456789'];
        yield 'počáteční nula' => ['012345678'];
        yield 'příliš krátké' => ['61234567'];
        yield 'příliš dlouhé' => ['6123456789'];
        yield 'písmeno' => ['61234567A'];
    }
}
