<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\SaldoAccountMatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SaldoAccountMatchTest extends TestCase
{
    /** @return iterable<string,array{string,string,bool,bool}> [účet, kód z pravidla, analytika, patří] */
    public static function cases(): iterable
    {
        yield 'syntetika sama' => ['321', '321', false, true];
        yield 'tečkovaná analytika' => ['321.001', '321', true, true];
        yield 'analytika bez tečky' => ['321001', '321', true, true];
        yield 'cizí syntetika' => ['325001', '321', false, false];
        yield 'kratší kód' => ['32', '321', false, false];
        yield 'analytika z pravidla sama' => ['321.001', '321.001', false, true];
        yield 'podúčet analytiky z pravidla' => ['321.001.1', '321.001', true, true];
        yield 'jiná analytika téže syntetiky' => ['321.002', '321.001', false, false];
        yield 'analytika bez tečky proti tečkované z pravidla' => ['3210011', '321.001', false, false];
    }

    #[DataProvider('cases')]
    public function testMatchesAnalyticsWithAndWithoutDot(string $account, string $code, bool $analytic, bool $belongs): void
    {
        self::assertSame($analytic, SaldoAccountMatch::isAnalyticOf($account, $code));
        self::assertSame($belongs, SaldoAccountMatch::belongsTo($account, $code));
    }
}
