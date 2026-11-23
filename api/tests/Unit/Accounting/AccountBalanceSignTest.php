<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\AccountBalanceSign;
use MyInvoice\Service\Accounting\Reports\AccountStatementService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountBalanceSignTest extends TestCase
{
    /** @return iterable<string,array{string,float,float}> typ, MD − D, očekávaný zůstatek */
    public static function signs(): iterable
    {
        yield 'A kladně na MD' => ['asset', 100.0, 100.0];
        yield 'A na Dal záporně' => ['asset', -100.0, -100.0];
        yield 'P kladně na Dal' => ['liability', -100.0, 100.0];
        yield 'P na MD záporně' => ['liability', 100.0, -100.0];
        yield 'vlastní kapitál jako P' => ['equity', -100.0, 100.0];
        yield 'N kladně na MD' => ['expense', 100.0, 100.0];
        yield 'V kladně na Dal' => ['revenue', -100.0, 100.0];
        yield 'V na MD záporně' => ['revenue', 100.0, -100.0];
        yield 'uzávěrkový účet zůstává MD − D' => ['closing', -100.0, -100.0];
    }

    #[DataProvider('signs')]
    public function testSignFollowsAccountType(string $type, float $debitMinusCredit, float $expected): void
    {
        self::assertSame($expected, AccountBalanceSign::signed($debitMinusCredit, $type));
    }

    public function testZeroHasNoNegativeSign(): void
    {
        self::assertSame(INF, fdiv(1, AccountBalanceSign::signed(0.0, 'liability')), '−0 by se vypsalo jako „−0,00“.');
        self::assertSame(INF, fdiv(1, AccountBalanceSign::signed(0.0, 'revenue')));
    }

    /** @return iterable<string,array{float,?string,bool}> */
    public static function unusual(): iterable
    {
        yield 'pohledávka v kreditu' => [-1.0, 'debit', true];
        yield 'pohledávka v debetu' => [1.0, 'debit', false];
        yield 'závazek v debetu' => [1.0, 'credit', true];
        yield 'oprávky v kreditu nejsou neobvyklé' => [-1.0, 'credit', false];
        yield 'saldní účet nemá neobvyklou stranu' => [1.0, null, false];
        yield 'saldní účet ani na druhé straně' => [-1.0, null, false];
        yield 'haléřová tolerance' => [-0.004, 'debit', false];
    }

    #[DataProvider('unusual')]
    public function testUnusualSideFollowsNormalSide(float $debitMinusCredit, ?string $normalSide, bool $expected): void
    {
        self::assertSame($expected, AccountBalanceSign::isUnusual($debitMinusCredit, $normalSide));
    }

    public function testInventoryOrientationFollowsNormalSide(): void
    {
        self::assertSame(-1, AccountBalanceSign::inventoryFactor('credit'));
        self::assertSame(1, AccountBalanceSign::inventoryFactor('debit'));
        self::assertSame(1, AccountBalanceSign::inventoryFactor(null));
    }

    public function testCounterTotalsAreReconciledToTheTurnoverInCents(): void
    {
        $row = static fn (string $code, float $md): array => ['account_id' => (int) $code, 'account_code' => $code, 'name' => $code, 'parent_id' => null, 'md' => $md, 'd' => 0.0];

        $out = AccountStatementService::reconcileCounterTotals(
            [$row('1', 33.333333), $row('2', 33.333333), $row('3', 33.333334)],
            ['md' => 100.0, 'd' => 0.0],
        );

        self::assertSame(10000, array_sum(array_map(static fn (array $r): int => (int) round($r['md'] * 100), $out)));
    }
}
