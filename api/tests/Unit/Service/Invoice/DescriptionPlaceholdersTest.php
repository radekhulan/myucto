<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\DescriptionPlaceholders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Placeholdery v pravidelné fakturaci (#108). Referenční datum 15. 5. 2026
 * (DUZP; u proformy issue_date) — viz RecurringInvoiceGenerator.
 */
final class DescriptionPlaceholdersTest extends TestCase
{
    private static function ref(string $date = '2026-05-15'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    #[DataProvider('placeholderCases')]
    public function testApply(string $template, string $expected): void
    {
        self::assertSame($expected, DescriptionPlaceholders::apply($template, self::ref(), 'cs'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function placeholderCases(): iterable
    {
        // Rok
        yield 'YYYY'        => ['{YYYY}', '2026'];
        yield 'YY'          => ['{YY}', '26'];
        yield 'YYYY+1'      => ['{YYYY+1}', '2027'];
        yield 'YYYY-1'      => ['{YYYY-1}', '2025'];
        yield 'YY+1'        => ['{YY+1}', '27'];
        yield 'sezona'      => ['sezóna {YY}/{YY+1}', 'sezóna 26/27'];

        // Měsíc (offset po měsících, vč. přetečení roku)
        yield 'M'           => ['{M}', '5'];
        yield 'MM'          => ['{MM}', '05'];
        yield 'M+1'         => ['{M+1}', '6'];
        yield 'MM+8 rollover' => ['{MM+8}', '01'];
        yield 'MM-5 rollover' => ['{MM-5}', '12'];
        yield 'mesic/rok'   => ['{MM}/{YYYY}', '05/2026'];

        // Název měsíce (cs)
        yield 'MMMM'        => ['{MMMM}', 'květen'];
        yield 'MMMM+1'      => ['{MMMM+1}', 'červen'];
        yield 'MMMM+8'      => ['{MMMM+8}', 'leden'];

        // Čtvrtletí (offset po čtvrtletích)
        yield 'Q'           => ['{Q}', '2'];
        yield 'Q+1'         => ['{Q+1}', '3'];
        yield 'Q+3 rollover' => ['{Q+3}', '1'];
        yield 'Q-2'         => ['{Q-2}', '4'];

        // Den (offset po dnech)
        yield 'D'           => ['{D}', '15'];
        yield 'DD'          => ['{DD}', '15'];
        yield 'D+20'        => ['{D+20}', '4'];

        // Celé datum + aritmetika
        yield 'DATE'        => ['{DATE}', '15. 5. 2026'];
        yield 'DATE+1Y'     => ['{DATE+1Y}', '15. 5. 2027'];
        yield 'DATE+1Y-1D'  => ['{DATE+1Y-1D}', '14. 5. 2027'];
        yield 'DATE+14D'    => ['{DATE+14D}', '29. 5. 2026'];
        yield 'DATE+2M'     => ['{DATE+2M}', '15. 7. 2026'];

        // Začátek/konec měsíce
        yield 'BOM'         => ['{BOM}', '1. 5. 2026'];
        yield 'BOM+1'       => ['{BOM+1}', '1. 6. 2026'];
        yield 'EOM'         => ['{EOM}', '31. 5. 2026'];
        yield 'EOM+1'       => ['{EOM+1}', '30. 6. 2026'];
        yield 'EOM-1'       => ['{EOM-1}', '30. 4. 2026'];
        yield 'EOM-3'       => ['{EOM-3}', '28. 2. 2026'];
        yield 'mesicni obdobi' => ['období {BOM} - {EOM}', 'období 1. 5. 2026 - 31. 5. 2026'];
        yield 'domena use case' => [
            'Prodloužení domény example.cz na období {DATE} - {DATE+1Y-1D}',
            'Prodloužení domény example.cz na období 15. 5. 2026 - 14. 5. 2027',
        ];

        // Neznámé tokeny a běžný text zůstávají netknuté (zpětná kompatibilita)
        yield 'unknown token'   => ['{FOO} {PERIOD_START}', '{FOO} {PERIOD_START}'];
        yield 'lowercase'       => ['{yyyy} {date}', '{yyyy} {date}'];
        yield 'plain text'      => ['Hosting 05/2026', 'Hosting 05/2026'];
        yield 'empty braces'    => ['{}', '{}'];
        yield 'offset bez čísla' => ['{YYYY+}', '{YYYY+}'];
    }

    public function testEnglishLocaleFormats(): void
    {
        self::assertSame('May', DescriptionPlaceholders::apply('{MMMM}', self::ref(), 'en'));
        self::assertSame('May 15, 2026', DescriptionPlaceholders::apply('{DATE}', self::ref(), 'en'));
        self::assertSame('Jun', substr(DescriptionPlaceholders::apply('{DATE+1M}', self::ref(), 'en'), 0, 3));
    }

    public function testMonthOverflowAnchoredToFirstDay(): void
    {
        // 31. 5. +1 měsíc by v PHP dalo 1. 7. — pro tokeny měsíce kotvíme na 1. den,
        // takže {MM+1} z 31. 5. je červen, ne červenec.
        self::assertSame('06', DescriptionPlaceholders::apply('{MM+1}', self::ref('2026-05-31'), 'cs'));
        self::assertSame('únor', DescriptionPlaceholders::apply('{MMMM+1}', self::ref('2026-01-31'), 'cs'));
    }

    public function testDecemberYearRollover(): void
    {
        self::assertSame('1/2027', DescriptionPlaceholders::apply('{M+1}/{YYYY+1}', self::ref('2026-12-10'), 'cs'));
    }

    public function testYearShiftedByMonthsFollowsMonthToken(): void
    {
        // Nájem „na příští měsíc": rok musí jít s měsícem, ne s referenčním datem.
        $text = 'Nájem na měsíc {MMMM+1} {YYYY+1M}';
        self::assertSame('Nájem na měsíc listopad 2026', DescriptionPlaceholders::apply($text, self::ref('2026-10-02'), 'cs'));
        self::assertSame('Nájem na měsíc leden 2027', DescriptionPlaceholders::apply($text, self::ref('2026-12-02'), 'cs'));
        self::assertSame('3/2026 - 2/2027', DescriptionPlaceholders::apply('{M}/{YYYY+0M} - {M+11}/{YYYY+11M}', self::ref('2026-03-17'), 'cs'));
        self::assertSame('25', DescriptionPlaceholders::apply('{YY-1M}', self::ref('2026-01-31'), 'cs'));
        // Bez jednotky zůstává offset v letech.
        self::assertSame('2027', DescriptionPlaceholders::apply('{YYYY+1}', self::ref('2026-03-17'), 'cs'));
    }

    public function testDateArithmeticClampsMonthOverflow(): void
    {
        // PHP modify() by dal 31. 1. +1M = 3. 3. — clampujeme na konec února (jako MySQL DATE_ADD).
        self::assertSame('28. 2. 2026', DescriptionPlaceholders::apply('{DATE+1M}', self::ref('2026-01-31'), 'cs'));
        self::assertSame('27. 2. 2026', DescriptionPlaceholders::apply('{DATE+1M-1D}', self::ref('2026-01-31'), 'cs'));
        // Přestupný rok: únor 2028 má 29 dní.
        self::assertSame('29. 2. 2028', DescriptionPlaceholders::apply('{DATE+1M}', self::ref('2028-01-31'), 'cs'));
        // Roky: 29. 2. přestupného roku +1Y → 28. 2.
        self::assertSame('28. 2. 2029', DescriptionPlaceholders::apply('{DATE+1Y}', self::ref('2028-02-29'), 'cs'));
        // Posun po dnech zůstává exaktní (žádný clamp).
        self::assertSame('2. 3. 2026', DescriptionPlaceholders::apply('{DATE+30D}', self::ref('2026-01-31'), 'cs'));
        // Clamp je per-krok: 31. 1. +1M (→28. 2.) +1M → 28. 3. (den už zůstává 28).
        self::assertSame('28. 3. 2026', DescriptionPlaceholders::apply('{DATE+1M+1M}', self::ref('2026-01-31'), 'cs'));
    }

    public function testEomClampsFebruaryAndLeapYear(): void
    {
        self::assertSame('28. 2. 2026', DescriptionPlaceholders::apply('{EOM+1}', self::ref('2026-01-31'), 'cs'));
        self::assertSame('29. 2. 2028', DescriptionPlaceholders::apply('{EOM+1}', self::ref('2028-01-15'), 'cs'));
        self::assertSame('31. 12. 2026', DescriptionPlaceholders::apply('{EOM+1}', self::ref('2026-11-30'), 'cs'));
        self::assertSame('Feb 28, 2026', DescriptionPlaceholders::apply('{EOM}', self::ref('2026-02-10'), 'en'));
    }

    public function testIdempotentWithMonthSynchronizerOutput(): void
    {
        // Výstup placeholderů ("05/2026") následně projde MonthSynchronizer::syncTo
        // ke stejnému datu — nesmí se změnit.
        $evaluated = DescriptionPlaceholders::apply('Hosting {MM}/{YYYY}', self::ref(), 'cs');
        self::assertSame('Hosting 05/2026', $evaluated);
        self::assertSame(
            $evaluated,
            \MyInvoice\Service\Invoice\MonthSynchronizer::syncTo($evaluated, self::ref()),
        );
    }

    public function testUnknownTokensListsOnlyWhatApplyLeavesUntouched(): void
    {
        $known = '{YYYY}{YY}{M}{MM}{MMMM}{Q}{D}{DD}{DATE}{DATE+1Y-1D}{BOM}{EOM-1}{MM+8}{Q-1}{YYYY+8M}';
        self::assertSame([], DescriptionPlaceholders::unknownTokens('Nájem ' . $known));
        self::assertSame([], DescriptionPlaceholders::unknownTokens('Bez znaků 03/2026'));
        self::assertSame(['{FOO}', '{mm}'], DescriptionPlaceholders::unknownTokens('A {FOO} {MM} {mm} {FOO}'));
        self::assertSame(['{MM+1000}'], DescriptionPlaceholders::unknownTokens('{MM+1000}'));
        self::assertSame(['{'], DescriptionPlaceholders::unknownTokens('Nájem {MM'));
        self::assertSame(['}'], DescriptionPlaceholders::unknownTokens('Nájem MM}'));
        // Faktury dál nechávají neznámé tokeny v textu beze změny.
        self::assertSame('A {FOO} 05', DescriptionPlaceholders::apply('A {FOO} {MM}', self::ref('2026-05-15'), 'cs'));
    }
}
