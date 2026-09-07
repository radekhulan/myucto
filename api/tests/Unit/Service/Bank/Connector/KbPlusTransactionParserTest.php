<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Service\Bank\Connector\KbPlusTransactionParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KbPlusTransactionParserTest extends TestCase
{
    public function testMapsBookedAdaaTransactionToImporterContract(): void
    {
        $result = new KbPlusTransactionParser()->parse(
            [$this->transaction()],
            '1000000005',
            'CZK',
            '2026-09-01',
            '2026-09-07',
        );

        self::assertSame([
            'account_number' => '1000000005',
            'statement_date' => '2026-09-07',
            'statement_number' => null,
            'prev_balance' => null,
            'curr_balance' => null,
            'debit_total' => null,
            'credit_total' => null,
        ], $result['header']);
        self::assertSame([
            'posted_at' => '2026-09-04',
            'amount' => -1234.56,
            'currency' => 'CZK',
            'variable_symbol' => '260100010',
            'constant_symbol' => '558',
            'specific_symbol' => '42',
            'counterparty_account' => 'CZ6108000000191000000005',
            'counterparty_bank' => '0800',
            'counterparty_name' => 'Synthetic Supplier s.r.o.',
            'description' => 'Syntetická úhrada | Interní syntetický popis | Syntetická doplňující informace',
            'bank_ref' => 'kbplus:SYNTHETIC-SERVICER-001',
        ], $result['transactions'][0]);
    }

    public function testCreditDirectionCreatesPositiveExactCentAmount(): void
    {
        $transaction = $this->transaction();
        $transaction['creditDebitIndicator'] = 'CREDIT';
        $transaction['amount']['value'] = 0.01;

        $parsed = new KbPlusTransactionParser()->parse(
            [$transaction],
            '1000000005',
            'CZK',
            '2026-09-04',
            '2026-09-04',
        )['transactions'][0];

        self::assertSame(0.01, $parsed['amount']);
    }

    public function testValidatesButDoesNotImportPendingCardHold(): void
    {
        $pending = $this->transaction();
        $pending['status'] = 'PDNG';
        unset($pending['bookingDate']);

        $result = new KbPlusTransactionParser()->parse(
            [$pending],
            '1000000005',
            'CZK',
            '2026-09-01',
            '2026-09-07',
        );

        self::assertSame([], $result['transactions']);
    }

    #[DataProvider('invalidTransactions')]
    public function testRejectsInvalidRequiredBankData(callable $change, string $messagePart): void
    {
        $transaction = $this->transaction();
        $change($transaction);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($messagePart);
        new KbPlusTransactionParser()->parse(
            [$transaction],
            '1000000005',
            'CZK',
            '2026-09-01',
            '2026-09-07',
        );
    }

    public static function invalidTransactions(): iterable
    {
        yield 'missing stable reference' => [
            static function (array &$transaction): void {
                unset($transaction['references']['accountServicer']);
            },
            'chybí accountServicer',
        ];
        yield 'entry reference is not accepted as fallback' => [
            static function (array &$transaction): void {
                unset($transaction['references']['accountServicer']);
                $transaction['entryReference'] = 'SYNTHETIC-UNSTABLE-ENTRY';
            },
            'chybí accountServicer',
        ];
        yield 'negative source amount' => [
            static function (array &$transaction): void { $transaction['amount']['value'] = -1.00; },
            'přesnost na celé centy',
        ];
        yield 'sub-cent amount' => [
            static function (array &$transaction): void { $transaction['amount']['value'] = 1.001; },
            'přesnost na celé centy',
        ];
        yield 'currency mismatch' => [
            static function (array &$transaction): void { $transaction['amount']['currency'] = 'EUR'; },
            'měna pohybu neodpovídá účtu',
        ];
        yield 'booking date outside period' => [
            static function (array &$transaction): void { $transaction['bookingDate'] = '2026-08-31'; },
            'bookingDate je mimo požadované období',
        ];
        yield 'unknown status' => [
            static function (array &$transaction): void { $transaction['status'] = 'CANCELLED'; },
            'neznámý status',
        ];
        yield 'malformed own iban' => [
            static function (array &$transaction): void { $transaction['iban'] = 'CZ0001000000191000000005'; },
            'neplatný IBAN vlastního účtu',
        ];
        yield 'missing mandatory update timestamp' => [
            static function (array &$transaction): void { unset($transaction['lastUpdated']); },
            'chybí lastUpdated',
        ];
    }

    public function testRejectsDuplicateStableReferencesInsteadOfFallingBackToWeakFingerprint(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duplicitní references.accountServicer');
        new KbPlusTransactionParser()->parse(
            [$this->transaction(), $this->transaction()],
            '1000000005',
            'CZK',
            '2026-09-01',
            '2026-09-07',
        );
    }

    /** @return array<string,mixed> */
    private function transaction(): array
    {
        return [
            'lastUpdated' => '2026-09-07T12:00:00.123Z',
            'accountType' => 'KB',
            'entryReference' => 'SYNTHETIC-ENTRY-001',
            'iban' => 'CZ0401000000191000000005',
            'creditDebitIndicator' => 'DEBIT',
            'transactionType' => 'DOMESTIC',
            'amount' => ['value' => 1234.56, 'currency' => 'CZK'],
            'bookingDate' => '2026-09-04',
            'valueDate' => '2026-09-04',
            'status' => 'BOOK',
            'counterParty' => [
                'iban' => 'CZ6108000000191000000005',
                'name' => 'Synthetic Supplier s.r.o.',
                'accountNo' => '19-1000000005',
                'bankCode' => '0800',
            ],
            'references' => [
                'accountServicer' => 'SYNTHETIC-SERVICER-001',
                'variable' => '0260100010',
                'constant' => '0558',
                'specific' => '00042',
                'receiver' => 'Syntetická úhrada',
                'myDescription' => 'Interní syntetický popis',
            ],
            'additionalTransactionInformation' => 'Syntetická doplňující informace',
        ];
    }
}
