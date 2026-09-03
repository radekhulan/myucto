<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\ImportDateSpan;
use PHPUnit\Framework\TestCase;

/**
 * Rozsah dat importované dávky — vstup pro kontrolu, jestli ho pokrývají účetní období.
 */
final class ImportDateSpanTest extends TestCase
{
    /** @param list<array<string,mixed>> $invoices */
    private static function file(array $invoices): array
    {
        return ['file' => 'export.xml', 'invoices' => $invoices];
    }

    public function testSpansOldestAndNewestAcrossFiles(): void
    {
        $span = ImportDateSpan::of([
            self::file([
                ['issue_date' => '2026-03-01', 'tax_date' => null],
                ['issue_date' => '2024-11-30', 'tax_date' => null],
            ]),
            self::file([
                ['issue_date' => '2025-06-15', 'tax_date' => null],
            ]),
        ]);

        self::assertSame(['2024-11-30', '2026-03-01'], $span);
    }

    /** DUZP má přednost před datem vystavení — na něm stojí zařazení do období. */
    public function testTaxDateWinsOverIssueDate(): void
    {
        $span = ImportDateSpan::of([
            self::file([['issue_date' => '2026-01-05', 'tax_date' => '2025-12-31']]),
        ]);

        self::assertSame(['2025-12-31', '2025-12-31'], $span);
    }

    /**
     * Doklad, který se při zápisu odmítne, se po opravě zdroje doimportuje do TÉHOŽ
     * období — rozsah ho proto musí pokrýt taky. Vyřazují se jen položky, ze kterých
     * datum vůbec nejde přečíst.
     */
    public function testSkipsOnlyUnreadableEntries(): void
    {
        $span = ImportDateSpan::of([
            ['file' => 'rozbity.xml', 'error' => 'Nelze parsovat Pohoda XML.'],
            self::file([
                ['__error' => 'Chybí varsymbol (symVar / number).'],
                ['issue_date' => '', 'tax_date' => null],
                ['issue_date' => 'nesmysl', 'tax_date' => null],
                ['issue_date' => '2022-04-01', 'tax_date' => null],
            ]),
        ]);

        self::assertSame(['2022-04-01', '2022-04-01'], $span);
    }

    public function testReturnsNullWhenNothingHasAReadableDate(): void
    {
        self::assertNull(ImportDateSpan::of([]));
        self::assertNull(ImportDateSpan::of([
            ['file' => 'rozbity.xml', 'error' => 'Nelze parsovat Pohoda XML.'],
        ]));
        self::assertNull(ImportDateSpan::of([
            self::file([['issue_date' => null, 'tax_date' => null]]),
        ]));
    }

    /** Přijaté doklady se nevyřazují — účtovat se budou stejně jako vydané. */
    public function testCountsBothDirections(): void
    {
        $span = ImportDateSpan::of([
            self::file([
                ['issue_date' => '2026-05-01', 'tax_date' => null, 'direction' => 'issued'],
                ['issue_date' => '2023-02-11', 'tax_date' => null, 'direction' => 'purchase'],
            ]),
        ]);

        self::assertSame(['2023-02-11', '2026-05-01'], $span);
    }
}
