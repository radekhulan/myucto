<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PohodaBankJournal;
use PHPUnit\Framework\TestCase;

/**
 * Které řádky deníku POHODY patří kterému bankovnímu pohybu se stejným číslem dokladu.
 */
final class PohodaBankJournalTest extends TestCase
{
    private const DAY = '2026-04-10';

    public function testRowCarriesSourceIdAndEffectOnBankAccount(): void
    {
        self::assertSame(['date' => self::DAY, 'source_id' => '11', 'net' => 500.0, 'candidates' => [500.0]], PohodaBankJournal::row(self::item(500, '221001', '261000', '11')));
        self::assertSame(['date' => self::DAY, 'source_id' => '', 'net' => -20.0, 'candidates' => [-20.0]], PohodaBankJournal::row(self::item(20, '568000', '221001')));
        // Záporná částka prohodí strany, převod mezi vlastními účty hýbe 221 na obou stranách.
        self::assertSame(-30.0, PohodaBankJournal::row(self::item(-30, '221001', '311001'))['net']);
        self::assertSame([100.0, -100.0], PohodaBankJournal::row(self::item(100, '221001', '221002'))['candidates']);
        self::assertNull(PohodaBankJournal::row(self::item(0, '221001', '311001')));
    }

    /** Datový soubor: rozhoduje id dokladu, i když částky stejného směru zaměnit jdou. */
    public function testDocumentIdDecidesWhichMovementIsBooked(): void
    {
        $rows = ['B1' => [self::row(-20, '13'), self::row(500, '11')]];
        $split = PohodaBankJournal::split(['B1' => [self::tx(1, 500), self::tx(2, -20), self::tx(3, -20)]], $rows, ['11' => 1, '12' => 2, '13' => 3]);
        self::assertSame([1, 0, 1], [count($split[1]), count($split[2]), count($split[3])]);
    }

    /** Řádek s id, které žádnému pohybu čísla nepatří: id nerozhoduje, páruje se podle částky. */
    public function testUnknownDocumentIdFallsBackToAmounts(): void
    {
        $split = PohodaBankJournal::split(['B1' => [self::tx(1, 500), self::tx(2, -150)]], ['B1' => [self::row(500, '99')]], ['11' => 1, '12' => 2]);
        self::assertSame([1, 0], [count($split[1]), count($split[2])]);
    }

    /** XML: pohyb dostane nepoužitý řádek své částky, pohyb bez řádku zůstává bez zápisu. */
    public function testAmountsPickTheBookedMovements(): void
    {
        $txs = ['B1' => [self::tx(1, 500), self::tx(2, -20), self::tx(3, -150)]];
        $split = PohodaBankJournal::split($txs, ['B1' => [self::row(500), self::row(-20)]], []);
        self::assertSame([1, 1, 0], [count($split[1]), count($split[2]), count($split[3])]);
    }

    /** Pohyb rozúčtovaný do víc řádků dostane zbylé řádky, když dají právě jeho částku. */
    public function testSplitMovementTakesRemainingRows(): void
    {
        $txs = ['B1' => [self::tx(1, -1000), self::tx(2, -20)]];
        $split = PohodaBankJournal::split($txs, ['B1' => [self::row(-800), self::row(-20), self::row(-200)]], []);
        self::assertSame([2, 1], [count($split[1]), count($split[2])]);
    }

    /**
     * Co nejde rozhodnout, zůstává jako dřív (zápis patří všem pohybům čísla): žádný řádek
     * nesedí na částku, nebo zbydou řádky, které nejde přiřadit.
     */
    public function testUndecidedNumberKeepsEarlierBehaviour(): void
    {
        $txs = ['B1' => [self::tx(1, 1210), self::tx(2, -20)]];
        self::assertSame([], PohodaBankJournal::split($txs, ['B1' => [self::row(1190)]], []));
        $txs = ['B1' => [self::tx(1, -100), self::tx(2, -50), self::tx(3, -70)]];
        self::assertSame([], PohodaBankJournal::split($txs, ['B1' => [self::row(-100), self::row(-30), self::row(-30)]], []));
    }

    /**
     * Jediný pohyb čísla a dne dostane všechny řádky dne. Pohyb dne bez řádků (XML) zůstává
     * nerozhodnutý, rozhoduje o něm datum zápisu jako dřív.
     */
    public function testSingleMovementOfTheDayKeepsAllRows(): void
    {
        $txs = ['B1' => [self::tx(1, -300), self::tx(2, -50, '2026-04-11')]];
        $split = PohodaBankJournal::split($txs, ['B1' => [self::row(300), self::row(-5)]], []);
        self::assertCount(2, $split[1]);
        self::assertArrayNotHasKey(2, $split);
    }

    /** Datový soubor: stejné číslo o rok později bez řádku s jeho id nemá zápis, ať je datum jakékoli. */
    public function testDocumentIdLeavesRepeatedNumberOfNextYearWithoutRows(): void
    {
        $txs = ['B1' => [self::tx(1, 400, '2026-03-03'), self::tx(2, -250, '2027-03-02')]];
        $split = PohodaBankJournal::split($txs, ['B1' => [['date' => '2026-03-03', 'source_id' => '11', 'net' => 400.0, 'candidates' => [400.0]]]], ['11' => 1, '12' => 2]);
        self::assertSame([1, 0], [count($split[1]), count($split[2])]);
    }

    /** Pohyb hradící doklad dostane při shodné částce řádek přednostně. */
    public function testPayingMovementWinsEqualAmount(): void
    {
        $txs = ['B1' => [self::tx(1, 100), self::tx(2, 100)]];
        $split = PohodaBankJournal::split($txs, ['B1' => [self::row(100)]], [], [2 => true]);
        self::assertSame([0, 1], [count($split[1]), count($split[2])]);
    }

    /** @return array<string,mixed> řádek deníku tak, jak ho čte převod (XML i MDB) */
    private static function item(float $amount, string $md, string $d, string $sourceId = ''): array
    {
        return ['source' => 'Banka', 'number' => ['numberRequested' => 'B1'], 'date' => self::DAY, 'homeCurrency' => ['priceSum' => (string) $amount],
            'accounting' => ['credit' => $md, 'debit' => $d], 'sourceId' => $sourceId];
    }

    /** @return array{date:string,source_id:string,net:float,candidates:list<float>} */
    private static function row(float $net, string $sourceId = ''): array
    {
        return ['date' => self::DAY, 'source_id' => $sourceId, 'net' => $net, 'candidates' => [$net]];
    }

    /** @return array{id:int,date:string,amount:float} */
    private static function tx(int $id, float $amount, string $date = self::DAY): array
    {
        return ['id' => $id, 'date' => $date, 'amount' => $amount];
    }
}
