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
        yield 'currency mismatch' => [
            static function (array &$transaction): void { $transaction['amount']['currency'] = 'EUR'; },
            'měna pohybu neodpovídá účtu',
        ];
        yield 'invalid direction' => [
            static function (array &$transaction): void { $transaction['creditDebitIndication'] = 'DEBIT'; },
            'neznámý směr pohybu',
        ];
        yield 'missing date' => [
            static function (array &$transaction): void { unset($transaction['bookingDate']); },
            'chybí bookingDate',
        ];
        yield 'invalid calendar date' => [
            static function (array &$transaction): void { $transaction['bookingDate'] = '2026-02-30'; },
            'neplatné bookingDate',
        ];
        yield 'amount not a number' => [
            static function (array &$transaction): void { $transaction['amount']['value'] = 'deset'; },
            'není konečné číslo',
        ];
    }

    /**
     * Dokumentace RB nezaručuje znaménko částky, délku reference, pásmo data ani tvar
     * doplňkových údajů. Odchylka v nich nesmí shodit celý výpis (a s ním ověření napojení).
     */
    #[DataProvider('toleratedTransactions')]
    public function testToleratesBankDataOutsideDocumentedExamples(callable $change, string $field, mixed $expected): void
    {
        $transaction = $this->transaction();
        $change($transaction);

        $parsed = new RaiffeisenbankTransactionParser()->parse([$transaction], '1000000005', 'CZK', '2026-09-01', '2026-09-07')['transactions'][0];

        self::assertSame($expected, $parsed[$field]);
    }

    public static function toleratedTransactions(): iterable
    {
        yield 'debit with unsigned amount' => [
            static function (array &$transaction): void { $transaction['amount']['value'] = 1234.56; }, 'amount', -1234.56];
        yield 'credit with signed amount' => [
            static function (array &$transaction): void { $transaction['creditDebitIndication'] = 'CRDT'; }, 'amount', 1234.56];
        yield 'amount as numeric string' => [
            static function (array &$transaction): void { $transaction['amount']['value'] = '-10.50'; }, 'amount', -10.5];
        yield 'sub-cent amount is rounded' => [
            static function (array &$transaction): void { $transaction['amount']['value'] = -1.004; }, 'amount', -1.0];
        yield 'utc midnight shift keeps prague date' => [
            static function (array &$transaction): void { $transaction['bookingDate'] = '2026-09-03T22:30:00Z'; }, 'posted_at', '2026-09-04'];
        yield 'date outside requested period is kept' => [
            static function (array &$transaction): void { $transaction['bookingDate'] = '2026-08-31T23:59:59+02:00'; }, 'posted_at', '2026-08-31'];
        yield 'value date fallback' => [
            static function (array &$transaction): void { unset($transaction['bookingDate']); $transaction['valueDate'] = '2026-09-05'; }, 'posted_at', '2026-09-05'];
        yield 'long bank id is hashed to column width' => [
            static function (array &$transaction): void { $transaction['entryReference'] = str_repeat('7', 64); },
            'bank_ref', 'RB-' . substr(hash('sha256', str_repeat('7', 64)), 0, 37)];
        yield 'bank id with spaces is hashed' => [
            static function (array &$transaction): void { $transaction['entryReference'] = 'id with spaces'; },
            'bank_ref', 'RB-' . substr(hash('sha256', 'id with spaces'), 0, 37)];
        yield 'account with dash prefix' => [
            static function (array &$transaction): void {
                $transaction['entryDetails']['transactionDetails']['relatedParties']['counterParty']['account'] = ['accountNumber' => '000019-1000000005'];
            }, 'counterparty_account', '19-1000000005'];
        yield 'invalid iban falls back to number' => [
            static function (array &$transaction): void {
                $transaction['entryDetails']['transactionDetails']['relatedParties']['counterParty']['account']['iban'] = 'neplatný';
            }, 'counterparty_account', '19-1000000005'];
        yield 'non numeric symbol is dropped' => [
            static function (array &$transaction): void {
                $transaction['entryDetails']['transactionDetails']['remittanceInformation']['creditorReferenceInformation']['variable'] = 'FA-2026/1';
            }, 'variable_symbol', null];
        yield 'numeric symbol from json number' => [
            static function (array &$transaction): void {
                $transaction['entryDetails']['transactionDetails']['remittanceInformation']['creditorReferenceInformation']['constant'] = 308;
            }, 'constant_symbol', '308'];
        yield 'invalid bank code is dropped' => [
            static function (array &$transaction): void {
                $transaction['entryDetails']['transactionDetails']['relatedParties']['counterParty']['organisationIdentification']['bankCode'] = 'RZBC';
            }, 'counterparty_bank', null];
        yield 'long description is truncated' => [
            static function (array &$transaction): void {
                $transaction['entryDetails']['transactionDetails']['remittanceInformation']['unstructured'] = str_repeat('á', 300);
                unset($transaction['entryDetails']['transactionDetails']['originatorMessage']);
            }, 'description', str_repeat('á', 255)];
        yield 'malformed nested details are ignored' => [
            static function (array &$transaction): void { $transaction['entryDetails'] = 'invalid'; }, 'counterparty_account', null];
    }

    public function testZeroAmountMovementIsSkippedNotRejected(): void
    {
        $zero = $this->transaction();
        $zero['entryReference'] = 'SYNTHETIC-REF-000';
        $zero['amount']['value'] = 0.0;

        $parsed = new RaiffeisenbankTransactionParser()->parse([$zero, $this->transaction()], '1000000005', 'CZK', '2026-09-01', '2026-09-07')['transactions'];

        self::assertCount(1, $parsed);
        self::assertSame('SYNTHETIC-REF-001', $parsed[0]['bank_ref']);
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
