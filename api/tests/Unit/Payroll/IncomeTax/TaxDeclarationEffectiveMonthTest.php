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

    /**
     * Formulář zákonné evidence odvozuje „Platí od" jen ze dne podpisu: rok
     * prohlášení určí lhůta po nástupu (§ 38k odst. 1 a 4), jinak den podpisu.
     *
     * @param list<string> $starts
     */
    #[DataProvider('signatureCases')]
    public function testFirstMonthFromSignatureAlone(
        string $signedOn,
        array $starts,
        ?string $frozenThrough,
        string $legalFrom,
        string $basis,
        string $openFrom,
        ?string $entry = null,
    ): void {
        $start = TaxDeclarationEffectiveMonth::forSignature($signedOn, $starts, $frozenThrough);

        self::assertSame($legalFrom, $start['legal_from']);
        self::assertSame($basis, $start['basis']);
        self::assertSame($openFrom, $start['open_from']);
        self::assertSame($openFrom !== $legalFrom, $start['frozen']);
        self::assertSame((int) substr($legalFrom, 0, 4), $start['tax_year']);
        self::assertSame($entry, $start['entry']);
    }

    /** @return iterable<string,array{0:string,1:list<string>,2:?string,3:string,4:string,5:string,6?:?string}> */
    public static function signatureCases(): iterable
    {
        // Nález z průchodu: nástup 15. 12. 2026, podpis 10. 1. 2027 (26. den).
        yield 'podpis v lednu do 30 dnů po prosincovém nástupu = od prosince' => ['2027-01-10', ['2026-12-15'], null, '2026-12-01', 'employment_start', '2026-12-01', '2026-12-15'];
        yield '30. den po nástupu ještě od měsíce nástupu' => ['2027-01-14', ['2026-12-15'], null, '2026-12-01', 'employment_start', '2026-12-01', '2026-12-15'];
        yield '31. den po nástupu už roční lhůta: do 15. 2. od ledna' => ['2027-01-15', ['2026-12-15'], null, '2027-01-01', 'year_start', '2027-01-01'];
        yield 'podpis 15. 2. stávajícího zaměstnance = od ledna' => ['2027-02-15', ['2020-01-01'], null, '2027-01-01', 'year_start', '2027-01-01'];
        yield 'podpis 16. 2. = od března' => ['2027-02-16', ['2020-01-01'], null, '2027-03-01', 'following_month', '2027-03-01'];
        yield 'podpis před nástupem = od měsíce nástupu' => ['2026-12-20', ['2027-01-04'], null, '2027-01-01', 'employment_start', '2027-01-01', '2027-01-04'];
        yield 'podpis v prosinci stávajícího zaměstnance = od ledna dalšího roku' => ['2026-12-10', ['2020-01-01'], null, '2027-01-01', 'year_start', '2027-01-01'];
        yield 'po lhůtě 30 dnů uprostřed roku = měsíc po podpisu' => ['2026-07-26', ['2026-06-25'], null, '2026-08-01', 'following_month', '2026-08-01'];
        yield 'bez známého nástupu' => ['2026-04-10', [], null, '2026-05-01', 'following_month', '2026-05-01'];
        // Zákonný měsíc zůstává, předvyplní se první neuzavřený.
        yield 'prosinec a leden uzavřené schválenou mzdou' => ['2027-01-10', ['2026-12-15'], '2027-01-31', '2026-12-01', 'employment_start', '2027-02-01', '2026-12-15'];
        yield 'uzavřený jen měsíc před zákonným začátkem nic neposouvá' => ['2027-01-10', ['2026-12-15'], '2026-11-30', '2026-12-01', 'employment_start', '2026-12-01', '2026-12-15'];
        yield 'uzavřený leden u roční lhůty' => ['2027-02-10', ['2020-01-01'], '2027-01-31', '2027-01-01', 'year_start', '2027-02-01'];
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
