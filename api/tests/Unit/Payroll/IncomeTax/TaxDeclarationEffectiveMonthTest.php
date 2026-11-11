<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\IncomeTax;

use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationEffectiveMonth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * První měsíc prohlášení poplatníka podle § 38k odst. 1, 4 a 7 ZDP.
 */
final class TaxDeclarationEffectiveMonthTest extends TestCase
{
    /**
     * @param list<string> $starts
     */
    #[DataProvider('cases')]
    public function testFirstMonth(
        string $signedOn,
        array $starts,
        ?string $frozenThrough,
        ?string $from,
        string $basis,
        bool $settlement,
    ): void {
        $resolved = TaxDeclarationEffectiveMonth::resolve(2026, $signedOn, $starts, $frozenThrough);

        self::assertSame($from, $resolved['from']);
        self::assertSame($basis, $resolved['basis']);
        self::assertSame($settlement, $resolved['annual_settlement']);
    }

    /** @return iterable<string,array{string,list<string>,?string,?string,string,bool}> */
    public static function cases(): iterable
    {
        yield 'nástup 25. 6., podpis 2. 7. = od června' => ['2026-07-02', ['2026-06-25'], null, '2026-06-01', 'employment_start', false];
        yield 'podpis v den nástupu uprostřed měsíce' => ['2026-06-25', ['2026-06-25'], null, '2026-06-01', 'employment_start', false];
        yield 'podpis před nástupem' => ['2026-06-20', ['2026-06-25'], null, '2026-06-01', 'employment_start', false];
        yield 'poslední den lhůty' => ['2026-07-25', ['2026-06-25'], null, '2026-06-01', 'employment_start', false];
        yield 'po lhůtě 30 dnů = měsíc po podpisu' => ['2026-07-26', ['2026-06-25'], null, '2026-08-01', 'following_month', false];
        yield 'stávající zaměstnanec 10. 3. = duben' => ['2026-03-10', ['2020-01-01'], null, '2026-04-01', 'following_month', false];
        yield 'stávající zaměstnanec 10. 2. = leden' => ['2026-02-10', ['2020-01-01'], null, '2026-01-01', 'year_start', false];
        yield 'stávající zaměstnanec 15. 2. = leden' => ['2026-02-15', ['2020-01-01'], null, '2026-01-01', 'year_start', false];
        yield 'stávající zaměstnanec 16. 2. = březen' => ['2026-02-16', ['2020-01-01'], null, '2026-03-01', 'following_month', false];
        yield 'uzavřený leden = únor a roční zúčtování' => ['2026-02-10', ['2020-01-01'], '2026-01-31', '2026-02-01', 'after_frozen_period', true];
        yield 'podpis v prosinci na příští rok' => ['2025-12-15', ['2020-01-01'], null, '2026-01-01', 'year_start', false];
        yield 'nástup v prosinci, podpis v lednu' => ['2026-01-05', ['2025-12-20'], null, '2026-01-01', 'employment_start', false];
        yield 'podpis v prosinci platí až od dalšího roku' => ['2026-12-10', ['2020-01-01'], null, null, 'following_month', false];
        yield 'pozdější domluvený vztah stávajícího zaměstnance' => ['2026-03-10', ['2020-01-01', '2026-09-01'], null, '2026-04-01', 'following_month', false];
        yield 'nový vztah u stávajícího zaměstnance' => ['2026-03-10', ['2020-01-01', '2026-03-02'], null, '2026-03-01', 'employment_start', false];
        yield 'bez známého nástupu' => ['2026-04-10', [], null, '2026-05-01', 'following_month', false];
    }

    public function testCheckWarnsWhenTheRowStartsEarlierThanTheLawAllows(): void
    {
        self::assertSame(
            ['legal_from' => '2026-04-01', 'basis' => 'following_month', 'warning' => 'earlier_than_allowed'],
            TaxDeclarationEffectiveMonth::check('2026-03-01', '2026-03-10', ['2020-01-01']),
        );
        self::assertSame(
            'later_than_entitled',
            TaxDeclarationEffectiveMonth::check('2026-07-01', '2026-07-02', ['2026-06-25'])['warning'],
        );
        self::assertNull(TaxDeclarationEffectiveMonth::check('2026-06-01', '2026-07-02', ['2026-06-25'])['warning']);
    }
}
