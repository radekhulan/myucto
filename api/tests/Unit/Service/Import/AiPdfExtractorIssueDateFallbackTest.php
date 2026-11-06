<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\AiPdfExtractor;
use PHPUnit\Framework\TestCase;

/** Účtenka bez data nesmí shodit AI extrakci; datum se doplní a koncept nese příznak. */
final class AiPdfExtractorIssueDateFallbackTest extends TestCase
{
    public function testValidIssueDateStaysUntouched(): void
    {
        $data = AiPdfExtractor::withIssueDateFallback(['issue_date' => '2026-10-20'], '2026-10-27');

        self::assertSame('2026-10-20', $data['issue_date']);
        self::assertArrayNotHasKey(AiPdfExtractor::ISSUE_DATE_FALLBACK, $data);
    }

    public function testMissingIssueDateTakesTaxDate(): void
    {
        $data = AiPdfExtractor::withIssueDateFallback(['issue_date' => null, 'tax_date' => '2026-10-19'], '2026-10-27');

        self::assertSame('2026-10-19', $data['issue_date']);
        self::assertTrue($data[AiPdfExtractor::ISSUE_DATE_FALLBACK]);
    }

    public function testNoDateAtAllFallsBackToImportDay(): void
    {
        foreach ([[], ['issue_date' => ''], ['issue_date' => '27.10.2026'], ['issue_date' => null, 'tax_date' => 'neuvedeno']] as $input) {
            $data = AiPdfExtractor::withIssueDateFallback($input, '2026-10-27');
            self::assertSame('2026-10-27', $data['issue_date']);
            self::assertTrue($data[AiPdfExtractor::ISSUE_DATE_FALLBACK]);
        }
    }
}
