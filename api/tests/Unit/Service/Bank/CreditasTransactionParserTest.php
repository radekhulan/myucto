<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\CreditasTransactionParser;
use PHPUnit\Framework\TestCase;

final class CreditasTransactionParserTest extends TestCase
{
    public function testStatementNumberFitsBankStatementStorage(): void
    {
        $parsed = (new CreditasTransactionParser())->parse([], '1000000005', '2026-09-07');
        self::assertLessThanOrEqual(20, strlen($parsed['header']['statement_number']));
    }

    public function testMapsTransactionsToStatementImporterShapeAndPreservesRawMetadata(): void
    {
        $longId = str_repeat('x', 41);
        $raw = [
            'transactionId' => $longId,
            'category' => 'DOMESTIC',
            'type' => 'DEBIT',
            'code' => '76',
            'amount' => ['value' => '123.45', 'currency' => 'CZK'],
            'effectiveDate' => '2026-09-06',
            'variableSymbol' => '202600001',
            'constantSymbol' => '308',
            'specificSymbol' => '7',
            'remittanceInfo' => 'Synthetic invoice',
            'partnerAccount' => ['number' => '2000000018', 'bankCode' => '0100', 'partnerName' => 'Synthetic Partner'],
            'rawExtension' => ['preserved' => true],
        ];

        $parsed = (new CreditasTransactionParser())->parse([$raw], '1000000005', '2026-09-07');

        self::assertSame('1000000005', $parsed['header']['account_number']);
        self::assertNull($parsed['header']['prev_balance']);
        self::assertNull($parsed['header']['curr_balance']);
        self::assertSame(123.45, $parsed['header']['debit_total']);
        self::assertSame(-123.45, $parsed['transactions'][0]['amount']);
        self::assertSame('creditas:' . substr(hash('sha256', $longId), 0, 32), $parsed['transactions'][0]['bank_ref']);
        self::assertSame($raw, $parsed['transactions'][0]['metadata']);
        self::assertSame('Synthetic invoice', $parsed['transactions'][0]['description']);
    }

    public function testKeepsShortBankReferenceAndAppliesCreditSign(): void
    {
        $raw = [
            'transactionId' => 'TX-1', 'type' => 'CREDIT',
            'amount' => ['value' => '-10.00', 'currency' => 'EUR'], 'effectiveDate' => '2026-09-06',
        ];
        $transaction = (new CreditasTransactionParser())->parse([$raw], 'CZ3022500000001000000005', '2026-09-07')['transactions'][0];
        self::assertSame('TX-1', $transaction['bank_ref']);
        self::assertSame(10.0, $transaction['amount']);
        self::assertSame('EUR', $transaction['currency']);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidAmountProvider(): iterable
    {
        yield 'non-zero third decimal' => ['1.001'];
        yield 'DB decimal overflow' => ['1000000000000.00'];
        yield 'zero integer' => ['0'];
        yield 'zero decimal' => ['0.00000000'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAmountProvider')]
    public function testRejectsAmountsThatCannotBePersistedExactly(string $amount): void
    {
        $raw = [
            'transactionId' => 'TX-invalid', 'type' => 'CREDIT',
            'amount' => ['value' => $amount, 'currency' => 'CZK'], 'effectiveDate' => '2026-09-06',
        ];
        $this->expectException(\InvalidArgumentException::class);
        (new CreditasTransactionParser())->parse([$raw], '1000000005', '2026-09-07');
    }

    public function testAcceptsTrailingZeroPrecisionWithoutRounding(): void
    {
        $raw = [
            'transactionId' => 'TX-trailing', 'type' => 'DEBIT',
            'amount' => ['value' => '123.45000000', 'currency' => 'CZK'], 'effectiveDate' => '2026-09-06',
        ];
        $transaction = (new CreditasTransactionParser())->parse([$raw], '1000000005', '2026-09-07')['transactions'][0];
        self::assertSame(-123.45, $transaction['amount']);
    }
}
