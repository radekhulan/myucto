<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Pair;

use MyInvoice\Service\Accounting\EntryForm\EntryForm;
use MyInvoice\Service\Accounting\Pair\JournalPairException;
use MyInvoice\Service\Accounting\Pair\JournalPairInvariant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JournalPairInvariantTest extends TestCase
{
    /** @param array<string,mixed> $extra */
    private static function line(int $account, string $side, string $amount, ?int $pairNo, array $extra = []): array
    {
        return array_replace(['account_id' => $account, 'side' => $side, 'amount' => $amount,
            'is_red_storno' => false, 'pair_no' => $pairNo], $extra);
    }

    /** @return list<array<string,mixed>> */
    private static function validEntry(): array
    {
        return [
            self::line(311, 'debit', '1000.00', 1),
            self::line(602, 'credit', '1000.00', 1),
            self::line(311, 'debit', '210.00', 2),
            self::line(343, 'credit', '210.00', 2),
        ];
    }

    /** @param list<array<string,mixed>> $lines @param array<string,mixed> $header */
    private static function assertFails(string $code, array $lines, EntryForm $form, array $header = []): void
    {
        try {
            JournalPairInvariant::assert($header, $lines, $form);
        } catch (JournalPairException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame(422, $e->httpStatus);
            return;
        }
        self::fail('Očekávané porušení invariantu ' . $code . ' nenastalo.');
    }

    public function testValidPairsPassInSingleForm(): void
    {
        JournalPairInvariant::assert(['entry_date' => '2026-03-01'], self::validEntry(), EntryForm::Single);
        $this->addToAssertionCount(1);
    }

    public function testCompositeEntryWithoutPairsPassesInCompositeForm(): void
    {
        $lines = array_map(static fn (array $l): array => array_replace($l, ['pair_no' => null]), self::validEntry());
        JournalPairInvariant::assert([], $lines, EntryForm::Composite);
        $this->addToAssertionCount(1);
    }

    public function testI1SingleFormEntryWithoutPairsIsRejected(): void
    {
        $lines = [self::line(311, 'debit', '10.00', null), self::line(602, 'credit', '10.00', null)];

        self::assertFails(JournalPairInvariant::SINGLE_UNPAIRED, $lines, EntryForm::Single);
    }

    public function testI1MarkedCompositeEntryIsAllowedInSingleForm(): void
    {
        $lines = [self::line(311, 'debit', '10.00', null), self::line(602, 'credit', '10.00', null)];

        foreach (JournalPairInvariant::UNPAIRED_STATUSES as $status) {
            JournalPairInvariant::assert(['pair_status' => $status], $lines, EntryForm::Single);
        }
        self::assertFails(JournalPairInvariant::SINGLE_UNPAIRED, $lines, EntryForm::Single, ['pair_status' => 'derived']);
    }

    public function testI1PartiallyPairedEntryIsRejected(): void
    {
        $lines = self::validEntry();
        $lines[] = self::line(518, 'debit', '5.00', null);
        $lines[] = self::line(221, 'credit', '5.00', null);

        self::assertFails(JournalPairInvariant::INCOMPLETE, $lines, EntryForm::Single);
    }

    public function testI2PairWithThreeLinesIsRejected(): void
    {
        $lines = self::validEntry();
        $lines[] = self::line(221, 'credit', '1000.00', 2);

        self::assertFails(JournalPairInvariant::INCOMPLETE, $lines, EntryForm::Single);
    }

    public function testI2PairWithBothLinesOnSameEffectiveSideIsRejected(): void
    {
        $lines = self::validEntry();
        $lines[1]['side'] = 'debit';

        self::assertFails(JournalPairInvariant::UNBALANCED, $lines, EntryForm::Single);
    }

    public function testI2RedStornoFlipsEffectiveSide(): void
    {
        // Červené storno obou řádků: MD i Dal fyzicky zůstávají, účinné strany se prohodí.
        $red = [
            self::line(311, 'debit', '50.00', 1, ['is_red_storno' => true]),
            self::line(602, 'credit', '50.00', 1, ['is_red_storno' => true]),
        ];
        JournalPairInvariant::assert([], $red, EntryForm::Single);
        self::assertSame('credit', JournalPairInvariant::effectiveSide($red[0]));

        // Červený řádek na MD je účinně Dal: s dalším Dal řádkem dvojici netvoří.
        $wrong = [
            self::line(311, 'debit', '50.00', 1, ['is_red_storno' => true]),
            self::line(602, 'debit', '50.00', 1, ['is_red_storno' => true]),
        ];
        self::assertFails(JournalPairInvariant::UNBALANCED, $wrong, EntryForm::Single);
    }

    public function testI3DifferentAmountsAreRejected(): void
    {
        $lines = self::validEntry();
        $lines[1]['amount'] = '999.99';

        self::assertFails(JournalPairInvariant::UNBALANCED, $lines, EntryForm::Single);
    }

    public function testI3DifferentRedStornoFlagsAreRejected(): void
    {
        // Řádek MD fyzicky Dal s červeným stornem je účinně MD, strany sedí, ale storno ne.
        $lines = [
            self::line(311, 'credit', '10.00', 1, ['is_red_storno' => true]),
            self::line(602, 'credit', '10.00', 1),
        ];

        self::assertFails(JournalPairInvariant::UNBALANCED, $lines, EntryForm::Single);
    }

    /** @return iterable<string,array{string}> */
    public static function nonPositiveAmounts(): iterable
    {
        yield 'nula' => ['0.00'];
        yield 'záporná' => ['-10.00'];
        yield 'text' => ['abc'];
    }

    #[DataProvider('nonPositiveAmounts')]
    public function testI3NonPositiveAmountIsRejected(string $amount): void
    {
        $lines = [
            self::line(311, 'debit', $amount, 1),
            self::line(602, 'credit', $amount, 1),
        ];

        self::assertFails(JournalPairInvariant::AMOUNT_NOT_POSITIVE, $lines, EntryForm::Single);
    }

    public function testI4SameAccountIsRejected(): void
    {
        $lines = self::validEntry();
        $lines[1]['account_id'] = 311;

        self::assertFails(JournalPairInvariant::SAME_ACCOUNT, $lines, EntryForm::Single);
    }

    public function testMissingAccountsAreReportedPerSide(): void
    {
        $missingDebit = [
            self::line(0, 'debit', '10.00', 1),
            self::line(602, 'credit', '10.00', 1),
        ];
        self::assertFails(JournalPairInvariant::MISSING_DEBIT_ACCOUNT, $missingDebit, EntryForm::Single);

        $missingCredit = [
            ['side' => 'debit', 'amount' => '10.00', 'account_code' => '311', 'pair_no' => 1],
            ['side' => 'credit', 'amount' => '10.00', 'account_code' => ' ', 'pair_no' => 1],
        ];
        self::assertFails(JournalPairInvariant::MISSING_CREDIT_ACCOUNT, $missingCredit, EntryForm::Single);

        $sameCode = [
            ['side' => 'debit', 'amount' => '10.00', 'account_code' => '311', 'pair_no' => 1],
            ['side' => 'credit', 'amount' => '10.00', 'account_code' => '311', 'pair_no' => 1],
        ];
        self::assertFails(JournalPairInvariant::SAME_ACCOUNT, $sameCode, EntryForm::Single);
    }

    public function testI5PairNumbersMustStartAtOneWithoutGaps(): void
    {
        $gap = self::validEntry();
        $gap[2]['pair_no'] = 3;
        $gap[3]['pair_no'] = 3;
        self::assertFails(JournalPairInvariant::NUMBERING, $gap, EntryForm::Single);

        $zero = self::validEntry();
        $zero[0]['pair_no'] = 0;
        $zero[1]['pair_no'] = 0;
        self::assertFails(JournalPairInvariant::NUMBERING, $zero, EntryForm::Single);

        $garbage = self::validEntry();
        $garbage[0]['pair_no'] = 'x';
        self::assertFails(JournalPairInvariant::NUMBERING, $garbage, EntryForm::Single);
    }

    public function testI5PairNumbersNeedNotFollowLineOrder(): void
    {
        // Přeúčtování dvojice na místě ponechá číslo dvojice, nové řádky ale jdou na konec.
        $swapped = self::validEntry();
        foreach ($swapped as &$line) {
            $line['pair_no'] = $line['pair_no'] === 1 ? 2 : 1;
        }
        unset($line);
        JournalPairInvariant::assert([], $swapped, EntryForm::Single);
        $this->addToAssertionCount(1);
    }

    public function testI6CompositeFormMustNotReceivePairs(): void
    {
        self::assertFails(JournalPairInvariant::COMPOSITE_FORM, self::validEntry(), EntryForm::Composite);

        // Storno / přeúčtování dvojice z jednoduchého období si dvojice nese i do složeného.
        JournalPairInvariant::assert(['pairs_inherited' => true], self::validEntry(), EntryForm::Composite);
        $this->addToAssertionCount(1);
    }

    public function testPairNumbersFromDatabaseStringsAreAccepted(): void
    {
        $lines = self::validEntry();
        foreach ($lines as &$line) {
            $line['pair_no'] = (string) $line['pair_no'];
            $line['account_id'] = (string) $line['account_id'];
            $line['is_red_storno'] = '0';
        }
        unset($line);

        JournalPairInvariant::assert([], $lines, EntryForm::Single);
        $this->addToAssertionCount(1);
    }
}
