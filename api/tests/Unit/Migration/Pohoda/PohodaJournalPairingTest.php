<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PohodaJournal;
use MyInvoice\Service\Migration\Pohoda\PohodaSaldoPairing;
use PHPUnit\Framework\TestCase;

final class PohodaJournalPairingTest extends TestCase
{
    public function testAdvanceAccountTakesSecondSymbolDocumentAccountTheFirst(): void
    {
        $item = ['pairing' => ['symbol' => '2600111', 'advance' => '2500222']];
        self::assertSame('2600111', PohodaJournal::pairingSymbol($item, '321000'));
        self::assertSame('2500222', PohodaJournal::pairingSymbol($item, '314000'));
        self::assertSame('2500222', PohodaJournal::pairingSymbol($item, '324001'));
        self::assertSame('2600111', PohodaJournal::pairingSymbol($item, '311000'));
    }

    public function testAdvanceAccountWithoutSecondSymbolKeepsTheFirst(): void
    {
        $item = ['pairing' => ['symbol' => '2600111', 'advance' => '']];
        self::assertSame('2600111', PohodaJournal::pairingSymbol($item, '314000'));
    }

    public function testNonSaldoAccountOrMissingSymbolHasNone(): void
    {
        self::assertNull(PohodaJournal::pairingSymbol(['pairing' => ['symbol' => '1', 'advance' => '']], '221000'));
        self::assertNull(PohodaJournal::pairingSymbol(['pairing' => ['symbol' => '', 'advance' => '']], '311000'));
        self::assertNull(PohodaJournal::pairingSymbol([], '311000'));
    }

    public function testDescribeIsEmptyWithoutSymbols(): void
    {
        self::assertSame('', PohodaSaldoPairing::describe(null));
    }

    public function testDescribeNamesLargestGroups(): void
    {
        $text = PohodaSaldoPairing::describe(['closed' => 2, 'open' => 1, 'without_symbol' => -1000.5, 'top' => [['symbol' => '123', 'balance' => 50.0]]]);
        self::assertStringContainsString('vyrovnaných skupin 2, otevřených 1', $text);
        self::assertStringContainsString('123 50,00', $text);
        self::assertStringContainsString('-1 000,50', $text);
    }
}
