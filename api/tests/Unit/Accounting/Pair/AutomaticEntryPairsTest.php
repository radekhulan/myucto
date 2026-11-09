<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Pair;

use MyInvoice\Service\Accounting\Closing\ClosingEntryBuilder;
use MyInvoice\Service\Accounting\EntryForm\EntryForm;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Accounting\Pair\JournalPairer;
use MyInvoice\Service\Accounting\Pair\JournalPairInvariant;
use MyInvoice\Service\Accounting\Pair\PairGroups;
use MyInvoice\Service\Accounting\Vat\VatClearingService;
use PHPUnit\Framework\TestCase;

/**
 * Výstup builderů automatických zápisů (řádky před zápisem) jde v jednoduché formě
 * rozdělit na dvojice beze zbytku a součet dvojic = částka zápisu. Uzávěrkový
 * a otevírací zápis mají 702, 710, 701 na obou stranách; skládají se po dvojicích
 * a volající je tak i označí ({@see PairGroups::consecutive()}), aby rozdělení
 * nezáviselo na shodě částek.
 */
final class AutomaticEntryPairsTest extends TestCase
{
    public function testClosingEntryIsPairedByConsecutiveGroups(): void
    {
        $result = (new ClosingEntryBuilder())->closingLines(
            [self::bal(1, '602', -10000.00), self::bal(2, '518', 4000.00), self::bal(8, '604', -1000.00)],
            [
                self::bal(3, '311', 13220.00), self::bal(4, '343', -2220.00), self::bal(5, '221', 5000.00),
                self::bal(6, '321', -4840.00), self::bal(7, '411', -4160.00),
            ],
        );
        $this->assertPairedTotals(PairGroups::consecutive($result['lines'], 'closing'), $result['lines']);
    }

    public function testOpeningEntryIsPairedByConsecutiveGroups(): void
    {
        $lines = (new ClosingEntryBuilder())->openingLines(
            [
                self::bal(3, '311', 13220.00), self::bal(4, '343', -2220.00), self::bal(5, '221', 5000.00),
                self::bal(6, '321', -4840.00), self::bal(7, '411', -4160.00),
            ],
            7000.00,
        );

        $this->assertPairedTotals(PairGroups::consecutive($lines, 'opening'), $lines);
    }

    public function testVatClearingIsTwoPairs(): void
    {
        foreach ([[2100.00, 2100.00], [5000.00, 1200.00], [-300.00, 800.00]] as [$input, $output]) {
            $lines = VatClearingService::buildLines($input, $output, '343.100', '343.200', '343.900');
            $paired = $this->assertPairedTotals(PairGroups::consecutive($lines, 'vat_clearing'), $lines);
            self::assertCount(4, $paired);
        }
    }

    /** Hromadná platba ze Zadání: 321/221 24 200, 321/221 6 050, 568/221 15 = 30 265. */
    public function testBulkPaymentFromTheSpecification(): void
    {
        $lines = [
            ['account_code' => '221', 'side' => 'credit', 'amount' => 30265.00],
            ['account_code' => '321', 'side' => 'debit', 'amount' => 24200.00],
            ['account_code' => '321', 'side' => 'debit', 'amount' => 6050.00],
            ['account_code' => '568', 'side' => 'debit', 'amount' => 15.00],
        ];
        $paired = $this->assertPairedTotals($lines, $lines);

        self::assertSame(
            [['321', '221', '24200.00'], ['321', '221', '6050.00'], ['568', '221', '15.00']],
            self::pairs($paired),
        );
    }

    /**
     * Úhrada dvou cizoměnových faktur jedním pohybem s kurzovým ziskem: 221 a 663 jsou
     * na straně Dal. Kurzový rozdíl nese roli doplňkového řádku, takže se spáruje se
     * závazkem postupně a zápis zůstane rozdělitelný.
     */
    public function testMultiInvoiceFxGainIsPairedThroughTheSupplementaryRole(): void
    {
        $lines = [
            ['account_code' => '321', 'side' => 'debit', 'amount' => 2600.00],
            ['account_code' => '321', 'side' => 'debit', 'amount' => 1300.00],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 3850.00],
            ['account_code' => '663', 'side' => 'credit', 'amount' => 50.00, 'pair_role' => JournalPairer::ROLE_ROUNDING],
        ];
        $withoutRole = $lines;
        unset($withoutRole[3]['pair_role']);
        self::assertSame('unpairable', JournalPairer::pair($withoutRole)['status']);

        $paired = $this->assertPairedTotals($lines, $lines);
        self::assertSame(
            [['321', '221', '2600.00'], ['321', '221', '1250.00'], ['321', '663', '50.00']],
            self::pairs($paired),
        );
    }

    public function testConsecutiveLeavesNonPairInputUntouched(): void
    {
        $hub = [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1210.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 1000.00],
            ['account_code' => '343', 'side' => 'credit', 'amount' => 210.00],
        ];
        self::assertSame($hub, PairGroups::consecutive($hub, 'x'));

        $sameAccount = [
            ['account_code' => '379', 'side' => 'debit', 'amount' => 10.00],
            ['account_code' => '379', 'side' => 'credit', 'amount' => 10.00],
        ];
        self::assertSame($sameAccount, PairGroups::consecutive($sameAccount, 'x'));
    }

    /**
     * @param list<array<string,mixed>> $input řádky pro párovač
     * @param list<array<string,mixed>> $original řádky složeného zápisu
     * @return list<array<string,mixed>>
     */
    private function assertPairedTotals(array $input, array $original): array
    {
        $result = JournalPairer::pair($input);
        self::assertSame('paired', $result['status'], $result['reason'] ?? '');
        JournalPairInvariant::assert(['entry_date' => '2026-12-31'], $result['lines'], EntryForm::Single);
        self::assertSame(self::totals($original), self::totals($result['lines']), 'Součty po účtech a stranách se nemění.');

        $pairSum = 0;
        for ($i = 0; $i < count($result['lines']); $i += 2) {
            $pairSum += JournalLineAmount::cents($result['lines'][$i]['amount']);
        }
        self::assertSame(self::totals($original)['debit'], $pairSum, 'Součet dvojic = částka zápisu.');
        foreach ($result['lines'] as $line) {
            self::assertArrayNotHasKey('pair_group', $line);
            self::assertArrayNotHasKey('pair_role', $line);
        }
        return $result['lines'];
    }

    /** @param list<array<string,mixed>> $lines @return array<string,int> */
    private static function totals(array $lines): array
    {
        $out = ['debit' => 0];
        foreach ($lines as $line) {
            $key = $line['account_code'] . '|' . $line['side'];
            $out[$key] = ($out[$key] ?? 0) + JournalLineAmount::cents($line['amount']);
            if ($line['side'] === 'debit') {
                $out['debit'] += JournalLineAmount::cents($line['amount']);
            }
        }
        ksort($out);
        return $out;
    }

    /** @param list<array<string,mixed>> $paired @return list<array{string,string,string}> */
    private static function pairs(array $paired): array
    {
        $out = [];
        for ($i = 0; $i < count($paired); $i += 2) {
            $out[] = [
                (string) $paired[$i]['account_code'],
                (string) $paired[$i + 1]['account_code'],
                number_format(JournalLineAmount::cents($paired[$i]['amount']) / 100, 2, '.', ''),
            ];
        }
        return $out;
    }

    /** @return array{account_id:int, account_code:string, name:string, bal:float} */
    private static function bal(int $id, string $code, float $bal): array
    {
        return ['account_id' => $id, 'account_code' => $code, 'name' => $code, 'bal' => $bal];
    }
}
