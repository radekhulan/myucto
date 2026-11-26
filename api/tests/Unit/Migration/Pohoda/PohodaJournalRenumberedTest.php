<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PohodaJournal;
use PHPUnit\Framework\TestCase;

/** Zápis dokladu přečíslovaného po zaúčtování ({@see PohodaJournal::renumberedEntries()}). */
final class PohodaJournalRenumberedTest extends TestCase
{
    public function testNumberKeyIgnoresLeadingZerosOfNumericPart(): void
    {
        self::assertSame(PohodaJournal::numberKey('26OZ033'), PohodaJournal::numberKey('26oz0033 '));
        self::assertNotSame(PohodaJournal::numberKey('26OZ033'), PohodaJournal::numberKey('26OZ0330'));
        self::assertSame('26OZ0', PohodaJournal::numberKey('26OZ000'));
    }

    public function testOnlySingleEntryOfSameDateAndFreeNumberMatches(): void
    {
        $journal = [
            '26OZ033' => [['date' => '2026-05-28', 'id' => 1]],
            '26OZ034' => [['date' => '2026-05-28', 'id' => 2]],
            '26OZ0035' => [['date' => '2026-05-28', 'id' => 3]],
            '26OZ035' => [['date' => '2026-05-28', 'id' => 4]],
        ];
        self::assertSame([['date' => '2026-05-28', 'id' => 1]], PohodaJournal::renumberedEntries($journal, '26OZ0033', ['2026-05-28'], []));
        self::assertSame([], PohodaJournal::renumberedEntries($journal, '26OZ0033', ['2026-05-27'], []), 'Jiné datum.');
        // Datum zaúčtování dokladu se liší od vystavení: zápis nese datum zaúčtování.
        self::assertSame([['date' => '2026-05-28', 'id' => 1]], PohodaJournal::renumberedEntries($journal, '26OZ0033', ['2026-05-26', '2026-05-28 00:00:00'], []));
        self::assertSame([], PohodaJournal::renumberedEntries($journal, '26OZ0033', [null], []));
        // Číslo zápisu má vlastní doklad agendy: patří jemu.
        self::assertSame([], PohodaJournal::renumberedEntries($journal, '26OZ0034', ['2026-05-28'], ['26OZ034' => true]));
        // Doklad 26OZ00035 má dva možné zápisy (26OZ0035 i 26OZ035): žádný.
        self::assertSame([], PohodaJournal::renumberedEntries($journal, '26OZ00035', ['2026-05-28'], []));
    }
}
