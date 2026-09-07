<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Service\Bank\Connector\CsasTransactionParser;
use MyInvoice\Service\Bank\Connector\BankConnectorException;
use PHPUnit\Framework\TestCase;

final class CsasTransactionParserTest extends TestCase
{
    private function row(): array
    {
        return ['entryReference' => 'synthetic-stable-reference', 'status' => 'BOOK', 'amount' => ['value' => 100, 'currency' => 'CZK'],
            'creditDebitIndicator' => 'CRDT', 'bookingDate' => ['date' => '2026-01-02T00:00:00Z'],
            'entryDetails' => ['transactionDetails' => ['remittanceInformation' => ['unstructured' => 'SYNTHETIC',
                'structured' => ['creditorReferenceInformation' => ['reference' => ['VS:123', 'KS:0308']]]]]]];
    }

    private function parse(array $rows): array
    {
        return (new CsasTransactionParser())->parse($rows, 'CZ7508000000001000000005', 'CZK', '2026-01-01', '2026-01-31');
    }

    public function testBookedTransactionsAreAuthoritativeAndInfoIsExcluded(): void
    {
        $rows = $this->parse([$this->row(), ['status' => 'INFO']]);
        self::assertCount(1, $rows['transactions']);
        self::assertSame('2026-01-02', $rows['transactions'][0]['posted_at']);
        self::assertEquals(100, $rows['transactions'][0]['amount']);
        self::assertSame('123', $rows['transactions'][0]['variable_symbol']);
        self::assertNull($rows['header']['curr_balance']);
        self::assertSame($rows['transactions'][0]['bank_ref'], $this->parse([$this->row()])['transactions'][0]['bank_ref']);
    }

    public function testDebitAndEmptyAccount(): void
    {
        $row = $this->row();
        $row['creditDebitIndicator'] = 'DBIT';
        self::assertEquals(-100, $this->parse([$row])['transactions'][0]['amount']);
        self::assertSame([], $this->parse([])['transactions']);
    }

    public function testRejectsWrongCurrency(): void
    {
        $row = $this->row();
        $row['amount']['currency'] = 'EUR';
        $this->expectException(BankConnectorException::class);
        $this->parse([$row]);
    }

    public function testRejectsDuplicateStableReferences(): void
    {
        $this->expectException(BankConnectorException::class);
        $this->parse([$this->row(), $this->row()]);
    }
}
