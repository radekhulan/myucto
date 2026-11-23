<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemLoanSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OtherItemLoanScheduleTest extends TestCase
{
    public function testAnnuityHasEqualPaymentsAndPrincipalAddsUpExactly(): void
    {
        $rows = OtherItemLoanSchedule::plan([
            'principal' => 100000, 'annual_rate' => 6.9, 'count' => 12, 'first_due_on' => '2099-01-31',
            'frequency' => 'monthly', 'method' => 'annuity',
        ]);
        self::assertCount(12, $rows);
        self::assertSame(100000.0, round(array_sum(array_column($rows, 'principal_amount')), 2));
        // Anuita 100 000 Kč, 6,9 % p. a., 12 měsíců: 8 648,07 Kč; první úrok 575,00 Kč.
        self::assertSame(8648.07, $rows[0]['amount']);
        self::assertSame(575.0, $rows[0]['interest_amount']);
        foreach (array_slice($rows, 0, 11) as $row) {
            self::assertSame(8648.07, $row['amount']);
        }
        self::assertEqualsWithDelta(8648.07, $rows[11]['amount'], 0.10, 'Poslední splátka vyrovná haléře po zaokrouhlení.');
        foreach ($rows as $row) {
            self::assertSame(round($row['principal_amount'] + $row['interest_amount'] + $row['fee_amount'], 2), $row['amount']);
        }
        self::assertSame(['2099-01-31', '2099-02-28', '2099-03-31'], array_column(array_slice($rows, 0, 3), 'due_on'),
            'Den splatnosti se drží, nejvýš poslední den měsíce.');
    }

    public function testLinearHasEqualPrincipalAndDecreasingInterestWithFee(): void
    {
        $rows = OtherItemLoanSchedule::plan([
            'principal' => 1000, 'annual_rate' => 12, 'count' => 3, 'first_due_on' => '2099-01-15',
            'frequency' => 'quarterly', 'method' => 'linear', 'fee' => 25,
        ]);
        self::assertSame([333.33, 333.33, 333.34], array_column($rows, 'principal_amount'));
        self::assertSame([30.0, 20.0, 10.0], array_column($rows, 'interest_amount'), 'Úrok čtvrtletí = 3 % ze zůstatku.');
        self::assertSame([25.0, 25.0, 25.0], array_column($rows, 'fee_amount'));
        self::assertSame([388.33, 378.33, 368.34], array_column($rows, 'amount'));
        self::assertSame(['2099-01-15', '2099-04-15', '2099-07-15'], array_column($rows, 'due_on'));
    }

    public function testZeroRateSplitsPrincipalOnly(): void
    {
        $rows = OtherItemLoanSchedule::plan([
            'principal' => 100, 'annual_rate' => 0, 'count' => 3, 'first_due_on' => '2099-01-15', 'method' => 'annuity',
        ]);
        self::assertSame([33.34, 33.34, 33.32], array_column($rows, 'principal_amount'));
        self::assertSame([0.0, 0.0, 0.0], array_column($rows, 'interest_amount'));
        self::assertSame(100.0, round(array_sum(array_column($rows, 'amount')), 2));
    }

    /** @return iterable<string,array{0:array<string,mixed>,1:string}> */
    public static function invalidInputs(): iterable
    {
        $base = ['principal' => 1000, 'annual_rate' => 5, 'count' => 12, 'first_due_on' => '2099-01-15'];
        yield 'nulová jistina' => [['principal' => 0] + $base, 'invalid_loan_principal'];
        yield 'sazba nad 100 %' => [['annual_rate' => 101] + $base, 'invalid_loan_rate'];
        yield 'jedna splátka' => [['count' => 1] + $base, 'invalid_loan_count'];
        yield 'roční frekvence' => [['frequency' => 'yearly'] + $base, 'invalid_frequency'];
        yield 'neznámá metoda' => [['method' => 'balloon'] + $base, 'invalid_loan_method'];
        yield 'neplatné datum' => [['first_due_on' => '2099-02-30'] + $base, 'invalid_date'];
        yield 'záporný poplatek' => [['fee' => -1] + $base, 'invalid_loan_fee'];
    }

    /** @param array<string,mixed> $input */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsRejected(array $input, string $code): void
    {
        try {
            OtherItemLoanSchedule::plan($input);
            self::fail('Vstup musí být odmítnut.');
        } catch (OtherItemException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }
}
