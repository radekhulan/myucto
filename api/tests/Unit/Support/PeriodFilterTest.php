<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Support;

use MyInvoice\Support\PeriodFilter;
use PHPUnit\Framework\TestCase;

/**
 * Rychlý filtr „měsíc a rok" nad dlouhými seznamy.
 *
 * Podstatné je, že nesmyslná hodnota SKONČÍ CHYBOU, ne tichým ignorováním:
 * filtr, který se sám vypne, ukáže víc řádků, než uživatel čeká — a on si toho
 * nemusí všimnout, protože seznam vypadá normálně.
 */
final class PeriodFilterTest extends TestCase
{
    public function testEmptyQueryFiltersNothing(): void
    {
        $filter = PeriodFilter::fromQuery([]);

        self::assertTrue($filter->isEmpty());
        self::assertSame(['sql' => '', 'params' => []], $filter->sqlFor('sent_at'));
    }

    /** Prázdný řetězec je „bez omezení", ne nula. Přesně to posílá select „Vše". */
    public function testBlankValuesFilterNothing(): void
    {
        self::assertTrue(PeriodFilter::fromQuery(['year' => '', 'month' => ''])->isEmpty());
    }

    public function testYearAloneSelectsWholeYear(): void
    {
        $sql = PeriodFilter::fromQuery(['year' => '2026'])->sqlFor('sent_at');

        self::assertSame(' AND YEAR(sent_at) = ?', $sql['sql']);
        self::assertSame([2026], $sql['params']);
    }

    /** Samotný měsíc dává ten měsíc napříč roky — na to se lidé u sezónních věcí ptají. */
    public function testMonthAloneSelectsThatMonthAcrossYears(): void
    {
        $sql = PeriodFilter::fromQuery(['month' => '8'])->sqlFor('delivered_at');

        self::assertSame(' AND MONTH(delivered_at) = ?', $sql['sql']);
        self::assertSame([8], $sql['params']);
    }

    public function testBothNarrowToOneMonth(): void
    {
        $sql = PeriodFilter::fromQuery(['year' => '2026', 'month' => '12'])
            ->sqlFor('COALESCE(sent_at, created_at)');

        self::assertSame(
            ' AND YEAR(COALESCE(sent_at, created_at)) = ? AND MONTH(COALESCE(sent_at, created_at)) = ?',
            $sql['sql'],
        );
        self::assertSame([2026, 12], $sql['params']);
    }

    /**
     * @param array<string,mixed> $query
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidQueries')]
    public function testNonsenseIsRefusedInsteadOfIgnored(array $query): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PeriodFilter::fromQuery($query);
    }

    /**
     * @return iterable<string,array{array<string,mixed>}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'nulový měsíc' => [['month' => '0']];
        yield 'třináctý měsíc' => [['month' => '13']];
        yield 'rok mimo rozsah' => [['year' => '1899']];
        yield 'nečíselný rok' => [['year' => 'letos']];
        yield 'pole místo čísla' => [['month' => ['8']]];
        yield 'SQL v parametru' => [['year' => '2026 OR 1=1']];
    }
}
