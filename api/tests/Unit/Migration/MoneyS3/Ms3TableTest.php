<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\Ms3Journal;
use MyInvoice\Service\Migration\MoneyS3\Ms3Table;
use MyInvoice\Tests\Fixtures\MoneyS3\Ms3FixtureWriter;
use PHPUnit\Framework\TestCase;

final class Ms3TableTest extends TestCase
{
    /**
     * Epocha kalendáře Money: 1 = 1. 1. 1900, tedy dny od 31. 12. 1899. Kotvy jsou
     * napevno (sériové číslo data v Excelu minus jedna), ne spočtené stejným kódem —
     * s epochou Delphi (30. 12. 1899) by 1. 1. 2025 vyšel jako 31. 12. 2024 a doklad
     * by spadl do jiného účetního roku.
     */
    public function testDateEpochIsDecemberThirtyFirst1899(): void
    {
        self::assertSame('1900-01-01', Ms3Table::dateFromDays(1));
        self::assertSame('2024-01-01', Ms3Table::dateFromDays(45291));
        self::assertSame('2025-01-01', Ms3Table::dateFromDays(45657));
        self::assertSame('2026-01-01', Ms3Table::dateFromDays(46022));
        self::assertSame('2025-12-31', Ms3Table::dateFromDays(46021));
        self::assertNull(Ms3Table::dateFromDays(0));
    }

    public function testNewYearsDayEntryStaysInNewYear(): void
    {
        $raw = Ms3FixtureWriter::table([['Zdroj', 'C', 2], ['Datum', 'D', 2]], [
            ['Zdroj' => 'ID', 'Datum' => '2025-01-01'],
        ]);
        $rows = iterator_to_array(Ms3Table::fromString($raw, 'UCDENIK')->rows(), false);
        self::assertSame('2025-01-01', $rows[0]['Datum']);
        self::assertSame(2025, Ms3Journal::fiscalYear($rows));
    }

    public function testDecodesExtendedAmountsAndCp1250Texts(): void
    {
        $amounts = [0.0, 0.01, 12100.0, -50.0, 1234567.89, -0.5, 3749375.21, 99999999.99];
        $rows = [];
        foreach ($amounts as $i => $a) {
            $rows[] = ['Cislo' => $i, 'Popis' => 'Účetní služby, žluťoučký kůň', 'Castka' => $a];
        }
        $raw = Ms3FixtureWriter::table([['Cislo', 'L', 4], ['Popis', 'C', 40], ['Castka', 'E', 10]], $rows);
        $decoded = iterator_to_array(Ms3Table::fromString($raw, 'T')->rows(), false);

        self::assertCount(count($amounts), $decoded);
        foreach ($amounts as $i => $a) {
            self::assertSame($i, $decoded[$i]['Cislo']);
            self::assertSame(round($a, 2), round((float) $decoded[$i]['Castka'], 2));
        }
        self::assertSame('Účetní služby, žluťoučký kůň', $decoded[0]['Popis']);
    }

    public function testSkipsDeletedRecordsAndFindsDataBehindIndexBlock(): void
    {
        $raw = Ms3FixtureWriter::table([['Doklad', 'C', 10], ['Del', 'B', 1]], [
            ['Doklad' => 'A1'],
            ['Doklad' => 'SMAZANO', 'Del' => 1],
            ['Doklad' => 'A2'],
        ], 37);
        $table = Ms3Table::fromString($raw, 'T');
        $docs = array_column(iterator_to_array($table->rows(), false), 'Doklad');

        self::assertSame(['A1', 'A2'], $docs);
        self::assertSame(1, $table->skippedDeleted());
    }

    public function testEmptyTableHasNoData(): void
    {
        $table = Ms3Table::fromString(Ms3FixtureWriter::table([['Doklad', 'C', 10]], []), 'T');
        self::assertFalse($table->hasData());
        self::assertSame([], iterator_to_array($table->rows(), false));
    }

    public function testRejectsForeignFile(): void
    {
        $this->expectException(\RuntimeException::class);
        Ms3Table::fromString(str_repeat('x', 200), 'T');
    }

    public function testJournalEffectSwapsNegativeAmountAndSkipsDegenerateRows(): void
    {
        self::assertSame(['debit' => '221001', 'credit' => '568000', 'amount' => 50.0],
            Ms3Journal::effect(['UcMD' => '568000', 'UcD' => '221001', 'Castka' => -50.0]));
        self::assertNull(Ms3Journal::effect(['UcMD' => '211000', 'UcD' => '211000', 'Castka' => 10.0]));
        self::assertNull(Ms3Journal::effect(['UcMD' => '518000', 'UcD' => '321000', 'Castka' => 0.0]));
    }
}
