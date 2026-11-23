<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\JournalDocumentListService;
use PHPUnit\Framework\TestCase;

/** Důvody k ověření v soupisce dokladů (#356). */
final class JournalDocumentListChecksTest extends TestCase
{
    public function testCleanDocumentHasNoChecks(): void
    {
        self::assertSame([], JournalDocumentListService::checks(self::row()));
        self::assertSame([], JournalDocumentListService::checks(self::row(['amount' => 1000.00])), 'Celá částka dokladu není výhrada.');
    }

    public function testAmountAboveDocumentTotalIsFlagged(): void
    {
        self::assertSame(['K doložení převyšuje celkovou částku dokladu'],
            JournalDocumentListService::checks(self::row(['amount' => 1000.01])));
        self::assertSame([], JournalDocumentListService::checks(self::row(['amount' => 300.00, 'document_total' => -1000.00])),
            'Dobropis se záporným celkem porovná absolutní hodnoty.');
    }

    public function testForeignCurrencyIsNotComparedWithCrowns(): void
    {
        self::assertSame(['Doklad v cizí měně, celkem v EUR'],
            JournalDocumentListService::checks(self::row(['amount' => 25000.00, 'document_total' => 1000.00, 'document_currency' => 'EUR'])));
    }

    public function testMissingDocumentAndNegativeAmount(): void
    {
        self::assertSame(['Zápis bez dokladu v evidenci', 'Záporná částka (vratka nebo storno)'],
            JournalDocumentListService::checks(self::row([
                'doc_kind' => 'entry', 'document_total' => null, 'document_currency' => null, 'amount' => -5.00,
            ])));
        self::assertSame(['Celková částka dokladu není v evidenci'],
            JournalDocumentListService::checks(self::row(['doc_kind' => 'payroll', 'document_total' => null, 'document_currency' => null])));
    }

    public function testCorrectionOutsideTheFilterIsFlagged(): void
    {
        self::assertSame(['Storno nebo oprava dokladu leží mimo filtr (jiné období, koncept nebo jiný filtr Deníku)'],
            JournalDocumentListService::checks(self::row(['corrected_outside' => true])));
    }

    public function testMixedSidesAreFlagged(): void
    {
        self::assertSame(['V rozsahu jsou účty různých druhů (náklady, výnosy, rozvaha), částka sčítá obě strany'],
            JournalDocumentListService::checks(self::row(['mixed_sides' => true])));
    }

    /** @param array<string,mixed> $override */
    private static function row(array $override = []): array
    {
        return $override + [
            'doc_kind' => 'purchase_invoice', 'amount' => 600.00, 'document_total' => 1000.00,
            'document_currency' => 'CZK', 'corrected_outside' => false,
        ];
    }
}
