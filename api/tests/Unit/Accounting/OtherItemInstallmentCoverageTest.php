<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\OtherItemInstallmentCoverage;
use MyInvoice\Service\Accounting\OtherItemPaymentCandidateRanker;
use PHPUnit\Framework\TestCase;

final class OtherItemInstallmentCoverageTest extends TestCase
{
    private const PLAN = [
        ['id' => 11, 'position' => 1, 'due_on' => '2099-01-10', 'amount' => '1000.00'],
        ['id' => 12, 'position' => 2, 'due_on' => '2099-02-10', 'amount' => '1000.00'],
        ['id' => 13, 'position' => 3, 'due_on' => '2099-03-10', 'amount' => '1000.00'],
    ];

    public function testUnassignedPaymentsCoverInstallmentsInOrderAfterSettledOutside(): void
    {
        $rows = OtherItemInstallmentCoverage::compute(self::PLAN,
            [['installment_id' => null, 'amount' => 900]], 300.0, '2099-01-15');
        self::assertSame([1000.0, 200.0, 0.0], array_column($rows, 'paid_amount'));
        self::assertSame(['paid', 'partial', 'unpaid'], array_column($rows, 'status'));
    }

    public function testAssignedPaymentFillsItsInstallmentAndOverflowGoesInOrder(): void
    {
        $rows = OtherItemInstallmentCoverage::compute(self::PLAN, [
            ['installment_id' => 13, 'amount' => 1000],
            ['installment_id' => 12, 'amount' => 1500],
        ], 0.0, '2099-02-15');
        self::assertSame([500.0, 1000.0, 1000.0], array_column($rows, 'paid_amount'));
        self::assertSame(['overdue', 'paid', 'paid'], array_column($rows, 'status'),
            'Neuhrazený zbytek po splatnosti je po splatnosti, i když je splátka částečně uhrazená.');
    }

    public function testFifoTargetsReproducePureOrderCoverage(): void
    {
        // Každá úhrada míří na první otevřenou splátku v době přiřazení (backfill).
        $payments = [700, 450, 1200, 0.01];
        $assigned = [];
        foreach ($payments as $amount) {
            $coverage = OtherItemInstallmentCoverage::compute(self::PLAN, $assigned, 120.0, '2099-01-01');
            $assigned[] = ['installment_id' => OtherItemInstallmentCoverage::target($coverage, $amount, false), 'amount' => $amount];
        }
        $unassigned = array_map(static fn (float|int $amount): array => ['installment_id' => null, 'amount' => $amount], $payments);
        self::assertSame(
            OtherItemInstallmentCoverage::compute(self::PLAN, $unassigned, 120.0, '2099-01-01'),
            OtherItemInstallmentCoverage::compute(self::PLAN, $assigned, 120.0, '2099-01-01'),
        );
        self::assertSame([11, 11, 12, 13], array_column($assigned, 'installment_id'));
    }

    public function testTargetPrefersExactOpenInstallment(): void
    {
        $plan = self::PLAN;
        $plan[1]['amount'] = '1500.00';
        $coverage = OtherItemInstallmentCoverage::compute($plan, [], 0.0, '2099-01-01');
        self::assertSame(12, OtherItemInstallmentCoverage::target($coverage, 1500.0));
        self::assertSame(12, OtherItemInstallmentCoverage::target($coverage, 1499.99), 'Tolerance jednoho haléře.');
        self::assertSame(11, OtherItemInstallmentCoverage::target($coverage, 700.0));
        self::assertSame(11, OtherItemInstallmentCoverage::target($coverage, 1500.0, false));
        self::assertNull(OtherItemInstallmentCoverage::target([], 100.0));
    }

    public function testSplitInstallmentsAreCoveredPerComponentAndUnsplitStayAsBefore(): void
    {
        $plan = [
            ['id' => 21, 'position' => 1, 'due_on' => '2099-01-10', 'amount' => '1120.00',
                'principal_amount' => '1000.00', 'interest_amount' => '100.00', 'fee_amount' => '20.00'],
            ['id' => 22, 'position' => 2, 'due_on' => '2099-02-10', 'amount' => '1070.00',
                'principal_amount' => '1000.00', 'interest_amount' => '50.00', 'fee_amount' => '20.00'],
            ['id' => 23, 'position' => 3, 'due_on' => '2099-03-10', 'amount' => '500.00',
                'principal_amount' => null, 'interest_amount' => null, 'fee_amount' => null],
        ];
        $rows = OtherItemInstallmentCoverage::compute($plan, [
            ['installment_id' => 21, 'amount' => 1000, 'interest_amount' => 100, 'fee_amount' => 20],
            ['installment_id' => 22, 'amount' => 0, 'interest_amount' => 50, 'fee_amount' => 5],
        ], 0.0, '2099-01-15');
        self::assertSame(['paid', 'partial', 'unpaid'], array_column($rows, 'status'));
        self::assertSame([0.0, 1015.0, 500.0], array_column($rows, 'remaining_amount'), 'Zbytek je peněžní, i s úrokem a poplatkem.');
        self::assertSame([0.0, 1000.0, 500.0], array_column($rows, 'remaining_principal'));
        self::assertSame([null, null, null], [$rows[2]['principal_amount'], $rows[2]['interest_amount'], $rows[2]['fee_amount']]);

        // Mimo evidenci vypořádaná částka je jistina: úrok splátky zůstane otevřený.
        $outside = OtherItemInstallmentCoverage::compute($plan, [], 1000.0, '2099-01-01');
        self::assertSame([120.0, 1070.0, 500.0], array_column($outside, 'remaining_amount'));
        self::assertSame(['partial', 'unpaid', 'unpaid'], array_column($outside, 'status'));
        self::assertTrue(OtherItemInstallmentCoverage::hasSplit($outside));
        self::assertFalse(OtherItemInstallmentCoverage::hasSplit(OtherItemInstallmentCoverage::compute(self::PLAN, [], 0.0, '2099-01-01')));
    }

    public function testSplitPaysInterestAndFeeFirstAndRoundTripsThroughCompute(): void
    {
        $plan = [
            ['id' => 31, 'position' => 1, 'due_on' => '2099-01-10', 'amount' => '1120.00',
                'principal_amount' => '1000.00', 'interest_amount' => '100.00', 'fee_amount' => '20.00'],
            ['id' => 32, 'position' => 2, 'due_on' => '2099-02-10', 'amount' => '1070.00',
                'principal_amount' => '1000.00', 'interest_amount' => '50.00', 'fee_amount' => '20.00'],
        ];
        $coverage = OtherItemInstallmentCoverage::compute($plan, [], 0.0, '2099-01-01');
        self::assertSame(['principal' => 0.0, 'interest' => 100.0, 'fee' => 10.0, 'unallocated' => 0.0],
            OtherItemInstallmentCoverage::split($coverage, 110.0, 31));
        self::assertSame(['principal' => 1000.0, 'interest' => 150.0, 'fee' => 30.0, 'unallocated' => 0.0],
            OtherItemInstallmentCoverage::split($coverage, 1180.0, 31), 'Přebytek pokryje úrok a poplatek další splátky.');
        self::assertSame(['principal' => 2000.0, 'interest' => 150.0, 'fee' => 40.0, 'unallocated' => 0.01],
            OtherItemInstallmentCoverage::split($coverage, 2190.01, 31));
        self::assertSame(['principal' => 1000.0, 'interest' => 150.0, 'fee' => 40.0, 'unallocated' => 0.0],
            OtherItemInstallmentCoverage::split($coverage, 1190.0, 32), 'Platba přesně na druhou splátku začíná jí.');

        // Stav splátek přečtený z uložených složek odpovídá rozdělení.
        $split = OtherItemInstallmentCoverage::split($coverage, 1180.0, 31);
        $rows = OtherItemInstallmentCoverage::compute($plan, [['installment_id' => 31, 'amount' => $split['principal'],
            'interest_amount' => $split['interest'], 'fee_amount' => $split['fee']]], 0.0, '2099-01-01');
        self::assertSame([0.0, 1010.0], array_column($rows, 'remaining_amount'));
        self::assertSame([0.0, 0.0], array_column($rows, 'remaining_interest'));
        self::assertSame(2190.0, OtherItemInstallmentCoverage::remainingTotal($coverage));
    }

    public function testRankerOrdersTiersAndExplainsReasons(): void
    {
        $installments = OtherItemInstallmentCoverage::compute(self::PLAN, [], 0.0, '2099-01-01');
        $payment = ['amount' => 1000.0, 'variable_symbol' => '0042', 'counterparty_account' => 'CZ1801000000001000000005',
            'counterparty_bank' => null, 'payment_on' => '2099-01-20'];
        $item = static fn (array $changes): array => $changes + ['variable_symbol' => null, 'counterparty_account' => null,
            'counterparty_bank_code' => null, 'remaining_amount' => 3000.0, 'installments' => []];

        $ranked = OtherItemPaymentCandidateRanker::rank($payment, [
            $item(['id' => 'none', 'remaining_amount' => 999.0]),
            $item(['id' => 'far', 'installments' => [$installments[2]]]),
            $item(['id' => 'near', 'installments' => $installments]),
            $item(['id' => 'all', 'variable_symbol' => '42', 'counterparty_account' => '1000000005',
                'counterparty_bank_code' => '0100', 'installments' => $installments]),
            $item(['id' => 'account', 'counterparty_account' => '1000000005', 'counterparty_bank_code' => '0100']),
            $item(['id' => 'other-bank', 'counterparty_account' => '1000000005', 'counterparty_bank_code' => '0300']),
        ]);
        self::assertSame(['all', 'near', 'far', 'account', 'none', 'other-bank'], array_column($ranked, 'id'));
        self::assertSame([100, 60, 40, 30, 0, 0], array_map(static fn (array $row): int => $row['match']['score'], $ranked));
        self::assertSame(11, $ranked[1]['match']['installment']['id'], 'V okně splatnosti je jen první splátka.');
        self::assertSame(['installment_amount'], $ranked[2]['match']['reasons']);
        self::assertSame(0.6, $ranked[1]['match']['confidence']);
    }
}
