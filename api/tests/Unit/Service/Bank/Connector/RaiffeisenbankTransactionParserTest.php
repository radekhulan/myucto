<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Service\Bank\Connector\RaiffeisenbankTransactionParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RaiffeisenbankTransactionParserTest extends TestCase
{
    public function testMapsOfficialTransactionShapeToImporterContract(): void
    {
        $result = new RaiffeisenbankTransactionParser()->parse(
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
            'counterparty_account' => '19-1000000005',
            'counterparty_bank' => '0100',
            'counterparty_name' => 'Synthetic Supplier s.r.o.',
            'description' => 'Syntetická úhrada 260100010 | Interní syntetický popis',
            'bank_ref' => 'SYNTHETIC-REF-001',
        ], $result['transactions'][0]);
    }

    public function testUsesIbanAndOrganisationNameFallback(): void
    {
        $transaction = $this->transaction();
        unset($transaction['entryDetails']['transactionDetails']['relatedParties']['counterParty']['name']);
        $transaction['entryDetails']['transactionDetails']['relatedParties']['counterParty']['account'] = [
            'iban' => 'CZ6108000000191000000005',
        ];
        $transaction['entryDetails']['transactionDetails']['relatedParties']['counterParty']
            ['organisationIdentification']['name'] = 'Synthetic Organisation a.s.';

        $parsed = new RaiffeisenbankTransactionParser()->parse(
            [$transaction],
            '1000000005',
            'CZK',
            '2026-09-01',
            '2026-09-07',
        )['transactions'][0];

        self::assertSame('CZ6108000000191000000005', $parsed['counterparty_account']);
        self::assertSame('Synthetic Organisation a.s.', $parsed['counterparty_name']);
    }

    public function testCreditKeepsExactCentAndOptionalFieldsAreNull(): void
    {
        $transaction = $this->transaction();
        $transaction['amount']['value'] = 0.01;
        $transaction['amount']['currency'] = 'EUR';
        $transaction['creditDebitIndication'] = 'CRDT';
        $transaction['entryDetails']['transactionDetails'] = [];

        $parsed = new RaiffeisenbankTransactionParser()->parse(
            [$transaction],
            '1000000005',
            'EUR',
            '2026-09-04',
            '2026-09-04',
        )['transactions'][0];

        self::assertSame(0.01, $parsed['amount']);
        self::assertSame('EUR', $parsed['currency']);
        self::assertNull($parsed['variable_symbol']);
        self::assertNull($parsed['counterparty_account']);
        self::assertNull($parsed['description']);
    }

    #[DataProvider('invalidTransactions')]
    public function testRejectsInvalidRequiredBankData(callable $change, string $messagePart): void
    {
        $transaction = $this->transaction();
        $change($transaction);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($messagePart);
        new RaiffeisenbankTransactionParser()->parse(
            [$transaction],
            '1000000005',
            'CZK',
            '2026-09-01',
            '2026-09-07',
        );
    }

    public static function invalidTransactions(): iterable
    {
        yield 'missing bank id' => [
            static function (array &$transaction): void { unset($transaction['entryReference']); },
            'chybí entryReference',
        ];
        yield 'unsafe bank id' => [
            static function (array &$transaction): void { $transaction['entryReference'] = 'id with spaces'; },
            'neplatné entryReference',
        ];
        yield 'currency mismatch' => [
            static function (array &$transaction): void { $transaction['amount']['currency'] = 'EUR'; },
            'měna pohybu neodpovídá účtu',
        ];
        yield 'fraction below one cent' => [
            static function (array &$transaction): void { $transaction['amount']['value'] = -1.001; },
            'přesnost na celé centy',
        ];
        yield 'debit with positive amount' => [
            static function (array &$transaction): void { $transaction['amount']['value'] = 10.00; },
            'částka neodpovídá DBIT/CRDT',
        ];
        yield 'invalid direction' => [
            static function (array &$transaction): void { $transaction['creditDebitIndication'] = 'DEBIT'; },
            'částka neodpovídá DBIT/CRDT',
        ];
        yield 'date outside requested period' => [
            static function (array &$transaction): void { $transaction['bookingDate'] = '2026-08-31T23:59:59+02:00'; },
            'bookingDate je mimo požadované období',
        ];
        yield 'invalid calendar date' => [
            static function (array &$transaction): void { $transaction['bookingDate'] = '2026-02-30'; },
            'neplatné bookingDate',
        ];
        yield 'amount encoded as string' => [
            static function (array &$transaction): void { $transaction['amount']['value'] = '-10.00'; },
            'není konečné číslo',
        ];
        yield 'malformed nested details' => [
            static function (array &$transaction): void { $transaction['entryDetails'] = 'invalid'; },
            'neplatné entryDetails',
        ];
    }

    public function testOneMalformedTransactionRejectsWholePageInsteadOfSkippingIt(): void
    {
        $invalid = $this->transaction();
        unset($invalid['amount']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pozici 1: chybí amount.value');
        new RaiffeisenbankTransactionParser()->parse(
            [$this->transaction(), $invalid],
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
            'entryReference' => 'SYNTHETIC-REF-001',
            'amount' => ['value' => -1234.56, 'currency' => 'CZK'],
            'creditDebitIndication' => 'DBIT',
            'bookingDate' => '2026-09-04T10:11:12.345+02:00',
            'entryDetails' => [
                'transactionDetails' => [
                    'relatedParties' => [
                        'counterParty' => [
                            'name' => 'Synthetic Supplier s.r.o.',
                            'organisationIdentification' => ['bankCode' => '0100'],
                            'account' => [
                                'accountNumberPrefix' => '000019',
                                'accountNumber' => '1000000005',
                            ],
                        ],
                    ],
                    'remittanceInformation' => [
                        'unstructured' => 'Syntetická úhrada 260100010',
                        'creditorReferenceInformation' => [
                            'variable' => '0260100010',
                            'constant' => '0558',
                            'specific' => '00042',
                        ],
                    ],
                    'originatorMessage' => 'Interní syntetický popis',
                ],
            ],
        ];
    }
}
