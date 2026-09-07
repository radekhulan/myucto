<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\GpcExporter;
use MyInvoice\Service\Bank\GpcParser;
use PHPUnit\Framework\TestCase;

final class GpcExporterTest extends TestCase
{
    private function snapshot(): array
    {
        return ['status' => 'calculated', 'account_number' => '0000001000000005', 'bank_code' => '0100', 'currency' => 'CZK',
            'from' => '2026-01-01', 'to' => '2026-01-31', 'opening' => 100, 'closing' => 110.12, 'credit' => 10.12, 'debit' => 0,
            'transactions' => [['id' => 1, 'posted_at' => '2026-01-02', 'amount' => '10.12', 'bank_ref' => '123',
                'counterparty_account' => 'CZ7508000000001000000005', 'counterparty_bank' => '0800',
                'counterparty_name' => 'Žluťoučký test', 'description' => "Test\r\n075INJECTION", 'variable_symbol' => '10',
                'constant_symbol' => '0308', 'specific_symbol' => null]]];
    }

    public function testFixedWidthEncodingAndFinancialRoundTrip(): void
    {
        $content = (new GpcExporter())->export($this->snapshot());
        foreach (explode("\r\n", rtrim($content, "\r\n")) as $line) self::assertSame(128, strlen($line));
        self::assertStringContainsString(iconv('UTF-8', 'Windows-1250', 'Žluťoučký test'), $content);
        $parsed = (new GpcParser())->parse($content);
        self::assertTrue($parsed['header']['reconstructed']);
        self::assertSame(100.0, $parsed['header']['prev_balance']);
        self::assertSame(110.12, $parsed['header']['curr_balance']);
        self::assertCount(1, $parsed['transactions']);
        self::assertSame(10.12, $parsed['transactions'][0]['amount']);
        self::assertSame('10', $parsed['transactions'][0]['variable_symbol']);
        self::assertSame('308', $parsed['transactions'][0]['constant_symbol']);
        self::assertSame('123', $parsed['transactions'][0]['bank_ref']);
        self::assertSame('0800', $parsed['transactions'][0]['counterparty_bank']);
    }

    public function testDebitAndNegativeClosingBalance(): void
    {
        $s = $this->snapshot();
        $s['opening'] = 0; $s['closing'] = -10.12; $s['credit'] = 0; $s['debit'] = 10.12;
        $s['transactions'][0]['amount'] = '-10.12';
        $p = (new GpcParser())->parse((new GpcExporter())->export($s));
        self::assertSame(-10.12, $p['header']['curr_balance']);
        self::assertSame(-10.12, $p['transactions'][0]['amount']);
    }

    public function testMissingOrConflictingBalanceCannotBeExported(): void
    {
        $s = $this->snapshot(); $s['status'] = 'missing_anchor'; $s['opening'] = null;
        $this->expectException(\InvalidArgumentException::class);
        (new GpcExporter())->export($s);
    }

    public function testOverflowIsRejectedInsteadOfTruncatingFinancialFields(): void
    {
        $s = $this->snapshot(); $s['transactions'][0]['constant_symbol'] = '12345';
        $this->expectException(\InvalidArgumentException::class);
        (new GpcExporter())->export($s);
    }

    public function testLongReferenceIsRetainedInAdditionalText(): void
    {
        $s = $this->snapshot(); $s['transactions'][0]['bank_ref'] = 'synthetic-long-bank-reference';
        $content = (new GpcExporter())->export($s);
        self::assertStringContainsString('REF: synthetic-long-bank-reference', $content);
        self::assertSame('9000000000001', (new GpcParser())->parse($content)['transactions'][0]['bank_ref']);
    }
}
