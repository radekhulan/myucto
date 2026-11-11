<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Nonprofit\Membership;

use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipListFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MembershipListFilterTest extends TestCase
{
    public function testTitleCanSelectAllItsPeriodsWithoutAssumingCalendarMembership(): void
    {
        self::assertSame(
            ['search' => 'Synthetic', 'title_key' => 'SYNTHETIC', 'year' => null, 'payment_status' => 'all'],
            MembershipListFilter::parse(['search' => ' Synthetic ', 'title_key' => 'SYNTHETIC', 'year' => '', 'payment_status' => 'all'])
        );
    }

    public function testExplicitNullYearFromJsonBodyMeansNoYearLikeEmptyQueryValue(): void
    {
        $query = ['title_key' => 'SYNTHETIC', 'payment_status' => 'all'];
        self::assertNull(MembershipListFilter::parse($query + ['year' => null])['year']);
        self::assertSame(MembershipListFilter::parse($query + ['year' => '']), MembershipListFilter::parse($query + ['year' => null]));
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidFiltersAreValidationErrors(array $query): void
    {
        $this->expectException(MembershipException::class);
        MembershipListFilter::parse($query);
    }

    public static function invalidQueries(): iterable
    {
        yield 'prázdné období i titul' => [['year' => '']];
        yield 'příliš krátký rok' => [['year' => '26']];
        yield 'mezera roku' => [['year' => ' 2026']];
        yield 'přesah roku' => [['year' => '9999']];
        yield 'pole roku' => [['year' => []]];
        yield 'pole hledání' => [['search' => []]];
        yield 'dlouhé hledání' => [['search' => str_repeat('x', 161)]];
        yield 'pole titulu' => [['title_key' => []]];
        yield 'neplatný titul' => [['title_key' => 'not a key']];
        yield 'cizí stav' => [['payment_status' => 'settled']];
        yield 'stav bez časového omezení' => [['year' => '', 'title_key' => 'SYNTHETIC', 'payment_status' => 'unpaid']];
        yield 'stav s null rokem' => [['year' => null, 'title_key' => 'SYNTHETIC', 'payment_status' => 'unpaid']];
    }

    public function testDefaultYearUsesApplicationCalendarAtTheUtcYearBoundary(): void
    {
        $zone = date_default_timezone_get();
        try {
            date_default_timezone_set('Europe/Prague');
            self::assertSame(2027, MembershipListFilter::parse([], new \DateTimeImmutable('2026-12-31T23:30:00Z'))['year']);
        } finally {
            date_default_timezone_set($zone);
        }
    }
}
