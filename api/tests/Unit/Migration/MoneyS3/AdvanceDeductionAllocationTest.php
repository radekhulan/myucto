<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\AdvanceDeductionMatcher;
use MyInvoice\Service\Migration\MoneyS3\AdvanceLinker;
use PHPUnit\Framework\TestCase;

/** Párování čerpání záloh po partnerovi ({@see AdvanceDeductionMatcher::allocate()}). */
final class AdvanceDeductionAllocationTest extends TestCase
{
    public function testOpeningIsConsumedFirstThenOldestAdvancePaidBeforeDeduction(): void
    {
        $result = AdvanceDeductionMatcher::allocate(
            ['ico:1' => 1000.0],
            [self::advance(10, '2025-01-10', 1000.0), self::advance(11, '2025-02-10', 1000.0)],
            [self::deduction(20, '2025-01-05', 1000.0), self::deduction(21, '2025-02-05', 1000.0), self::deduction(22, '2025-03-05', 1000.0)],
        );
        self::assertSame([21 => [10 => 1000.0], 22 => [11 => 1000.0]], $result['deductions']);
        self::assertSame(1000.0, $result['opening_consumed']);
        self::assertSame([], $result['unmatched']);
    }

    public function testDeductionNeverTakesAdvancePaidLaterNorMoreThanOpen(): void
    {
        $result = AdvanceDeductionMatcher::allocate(
            [],
            [self::advance(10, '2025-03-01', 500.0), self::advance(11, '2025-01-01', 300.0)],
            [self::deduction(20, '2025-02-01', 1000.0), self::deduction(21, '2025-04-01', 400.0)],
        );
        self::assertSame([20 => [11 => 300.0], 21 => [10 => 400.0]], $result['deductions']);
        self::assertSame([20 => 700.0], $result['unmatched']);
    }

    public function testReferenceWinsAndPartnersStaySeparate(): void
    {
        $result = AdvanceDeductionMatcher::allocate(
            ['ico:2' => 5000.0],
            [self::advance(10, '2025-01-01', 1000.0), self::advance(11, '2025-02-01', 1000.0), self::advance(12, '2025-01-01', 900.0, 'ico:2')],
            [self::deduction(20, '2025-03-01', 600.0, [11]), self::deduction(21, '2025-03-02', 1500.0)],
        );
        self::assertSame([20 => [11 => 600.0], 21 => [10 => 1000.0, 11 => 400.0]], $result['deductions']);
        self::assertSame(0.0, $result['opening_consumed'], 'Počáteční stav jiného partnera se nečerpá.');
    }

    public function testRoundingCentsAreNotChainedToNextAdvance(): void
    {
        $result = AdvanceDeductionMatcher::allocate(
            [],
            [self::advance(10, '2025-01-10', 33950.41), self::advance(11, '2025-02-10', 33950.42)],
            [self::deduction(20, '2025-02-05', 33950.42), self::deduction(21, '2025-03-05', 33950.41)],
        );
        self::assertSame([20 => [10 => 33950.41], 21 => [11 => 33950.41]], $result['deductions']);
        self::assertSame([20 => 0.01], $result['unmatched']);
    }

    public function testReferenceOfLaterInvoiceWinsOverEarlierFifo(): void
    {
        $result = AdvanceDeductionMatcher::allocate(
            [],
            [self::advance(10, '2025-01-01', 1000.0), self::advance(11, '2025-02-01', 1000.0)],
            [self::deduction(20, '2025-03-01', 1000.0), self::deduction(21, '2025-04-01', 1000.0, [10])],
        );
        self::assertSame([20 => [11 => 1000.0], 21 => [10 => 1000.0]], $result['deductions']);
    }

    public function testCreditNoteReturnsDeductionOfItsInvoiceBeforeFifo(): void
    {
        $result = AdvanceDeductionMatcher::allocate(
            [],
            [self::advance(10, '2025-01-01', 1000.0)],
            [self::deduction(20, '2025-02-01', 1000.0)],
            [['id' => 30, 'final' => 20, 'amount' => 1000.0]],
        );
        self::assertSame([20 => [30 => 1000.0]], $result['deductions'], 'Záloha 10 zůstane otevřená.');
    }

    public function testFifoPairsOnlyCrownDocuments(): void
    {
        $foreign = ['fifo' => false] + self::advance(10, '2025-01-01', 1000.0);
        $result = AdvanceDeductionMatcher::allocate(
            [],
            [$foreign, self::advance(11, '2025-01-02', 500.0)],
            [self::deduction(20, '2025-02-01', 800.0), ['fifo' => false] + self::deduction(21, '2025-02-02', 300.0)],
        );
        self::assertSame([20 => [11 => 500.0]], $result['deductions']);
        self::assertSame([20 => 300.0, 21 => 300.0], $result['unmatched']);
    }

    public function testForeignAdvanceIsPairedByReference(): void
    {
        $foreign = ['fifo' => false] + self::advance(10, '2025-01-01', 900.0, 'vendor:7');
        $result = AdvanceDeductionMatcher::allocate(
            [],
            [$foreign],
            [['fifo' => false] + self::deduction(20, '2025-02-01', 1200.0, [10], 'vendor:7')],
        );
        self::assertSame([20 => [10 => 900.0]], $result['deductions']);
        self::assertSame([20 => 300.0], $result['unmatched']);
    }

    public function testFinalInvoiceDoesNotDeductAdvanceItIsLinkedTo(): void
    {
        // Proformu 10 čerpá faktura vazbou; zbytek strany čerpání jde na další zálohu i přes odkaz na 10.
        $result = AdvanceDeductionMatcher::allocate(
            [],
            [self::advance(10, '2025-01-01', 1000.0), self::advance(11, '2025-01-02', 2000.0)],
            [['skip' => [10]] + self::deduction(20, '2025-02-01', 2000.0, [10])],
        );
        self::assertSame([20 => [11 => 2000.0]], $result['deductions']);
        self::assertSame([], $result['unmatched']);
    }

    public function testCarriedAdvancesFromYearBeforeConversion(): void
    {
        self::assertSame(['ico:1' => 1000.0], AdvanceDeductionMatcher::carriedAdvances(['ico:1' => [
            ['date' => '2024-11-10', 'amount' => 1210.0],
            ['date' => '2024-11-10', 'amount' => -210.0],
            ['date' => '2024-12-05', 'amount' => -1500.0], // čerpá i zálohy ze starších let - přebytek se zahodí
            ['date' => '2024-12-10', 'amount' => 1210.0],
            ['date' => '2024-12-10', 'amount' => -210.0],
        ], 'ico:2' => [['date' => '2024-06-01', 'amount' => -100.0]]]));
    }

    public function testDeductionItemWithPriceIncludingVatDeductsBase(): void
    {
        self::assertSame(57980.17, AdvanceLinker::deductedBase(70156.0, 4, 21.0));
        self::assertSame(1000.0, AdvanceLinker::deductedBase(1000.0, 0, 21.0), 'Cena bez DPH zůstává.');
        self::assertSame(500.0, AdvanceLinker::deductedBase(500.0, 4, 0.0), 'Záloha bez daně zůstává.');
    }

    /** @return array{id:int,partner:string,date:string,open:float} */
    private static function advance(int $id, string $date, float $open, string $partner = 'ico:1'): array
    {
        return ['id' => $id, 'partner' => $partner, 'date' => $date, 'open' => $open];
    }

    /**
     * @param list<int> $refs
     * @return array{id:int,partner:string,date:string,amount:float,refs:list<int>}
     */
    private static function deduction(int $id, string $date, float $amount, array $refs = [], string $partner = 'ico:1'): array
    {
        return ['id' => $id, 'partner' => $partner, 'date' => $date, 'amount' => $amount, 'refs' => $refs];
    }
}
