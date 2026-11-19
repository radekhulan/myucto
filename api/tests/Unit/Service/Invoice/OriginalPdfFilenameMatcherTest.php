<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\OriginalPdfFilenameMatcher;
use PHPUnit\Framework\TestCase;

final class OriginalPdfFilenameMatcherTest extends TestCase
{
    /** @return list<array{id:int, varsymbol:?string, payment_variable_symbol:?string}> */
    private static function invoices(): array
    {
        return [
            ['id' => 1, 'varsymbol' => 'FV-2026-001', 'payment_variable_symbol' => '2026001'],
            ['id' => 2, 'varsymbol' => '20260002', 'payment_variable_symbol' => null],
            ['id' => 3, 'varsymbol' => '20260003', 'payment_variable_symbol' => null],
            ['id' => 4, 'varsymbol' => 'FV-2026-0012', 'payment_variable_symbol' => null],
        ];
    }

    /** @return array{file:string, status:string, reason:?string, invoice_id:?int, candidate_ids:list<int>} */
    private static function one(string $file, ?array $invoices = null): array
    {
        return OriginalPdfFilenameMatcher::match([$file], $invoices ?? self::invoices())[0];
    }

    public function testNumberWithSeparatorsMatchesRegardlessOfCaseAndSeparators(): void
    {
        foreach (['FV-2026-001.pdf', 'faktura fv_2026_001.PDF', 'Faktura č. FV 2026 001 (kopie).pdf'] as $file) {
            $r = self::one($file);
            self::assertSame('matched', $r['status'], $file);
            self::assertSame(1, $r['invoice_id'], $file);
        }
    }

    public function testDiacriticsAroundNumberAreIgnored(): void
    {
        $r = self::one('Účetní_doklad-20260002-Šťastný.pdf');

        self::assertSame('matched', $r['status']);
        self::assertSame(2, $r['invoice_id']);
    }

    public function testNumberMustBeWholeToken(): void
    {
        self::assertSame('unmatched', self::one('202600021.pdf')['status']);
        self::assertSame('unmatched', self::one('x20260002.pdf')['status']);
        // FV-2026-0012 není FV-2026-001 s ocáskem: token 0012 ≠ 001.
        self::assertSame(4, self::one('FV-2026-0012.pdf')['invoice_id']);
    }

    public function testPaymentVariableSymbolMatches(): void
    {
        $r = self::one('platba 2026001.pdf');

        self::assertSame('matched', $r['status']);
        self::assertSame(1, $r['invoice_id']);
    }

    public function testTwoInvoicesInOneNameAreAmbiguous(): void
    {
        $r = self::one('20260002_20260003.pdf');

        self::assertSame('ambiguous', $r['status']);
        self::assertSame('multiple_invoices', $r['reason']);
        self::assertNull($r['invoice_id']);
        self::assertSame([2, 3], $r['candidate_ids']);
    }

    public function testLongestNumberWinsOverShorterContainedNumber(): void
    {
        $invoices = [
            ['id' => 10, 'varsymbol' => '001', 'payment_variable_symbol' => null],
            ['id' => 11, 'varsymbol' => 'FV-2026-001', 'payment_variable_symbol' => null],
        ];

        $r = self::one('FV-2026-001.pdf', $invoices);

        self::assertSame('matched', $r['status']);
        self::assertSame(11, $r['invoice_id']);
    }

    public function testTwoFilesForSameInvoiceAreBothAmbiguous(): void
    {
        $rows = OriginalPdfFilenameMatcher::match(['20260002.pdf', 'kopie 20260002.pdf', '20260003.pdf'], self::invoices());

        self::assertSame(['ambiguous', 'ambiguous', 'matched'], array_column($rows, 'status'));
        self::assertSame('duplicate_file', $rows[0]['reason']);
        self::assertSame(3, $rows[2]['invoice_id']);
    }

    public function testUnknownNameIsUnmatched(): void
    {
        $r = self::one('scan.pdf');

        self::assertSame('unmatched', $r['status']);
        self::assertSame([], $r['candidate_ids']);
    }

    public function testCandidateKeysCoverNumbersSplitBySeparators(): void
    {
        $keys = OriginalPdfFilenameMatcher::candidateKeys('Faktura FV-2026-001.pdf');

        // varsymbol_match_key čísla FV-2026-001 = číslice bez vodicích nul.
        self::assertContains('2026001', $keys);
        self::assertContains('2026', $keys);
        self::assertNotContains('', $keys);
    }
}
