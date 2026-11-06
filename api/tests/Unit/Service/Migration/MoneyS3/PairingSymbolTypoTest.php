<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\DocumentLinker;
use MyInvoice\Service\Migration\MoneyS3\PairingSymbolMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pravidla párování úhrad Money S3 bez databáze: co je překlep v párovacím symbolu
 * a který pohyb smí převod spárovat.
 */
final class PairingSymbolTypoTest extends TestCase
{
    /** @return iterable<string,array{string,string,bool}> */
    public static function symbols(): iterable
    {
        yield 'vypuštěná číslice' => ['210100041', '21100041', true];
        yield 'přidaná číslice' => ['2025200', '20252000', true];
        yield 'prohozené sousední číslice' => ['2025000123', '2025000132', true];
        // Sousední faktura téže řady (měsíční fakturace) - záměna číslice překlepem není.
        yield 'záměna číslice' => ['2025000123', '2025000124', false];
        yield 'prohozené nesousední číslice' => ['2025000123', '2025003120', false];
        yield 'dvě vypuštěné číslice' => ['210100041', '2110041', false];
        yield 'stejný symbol' => ['2025000123', '2025000123', false];
        yield 'vypuštěné písmeno' => ['FV2025A1', 'FV20251', false];
    }

    #[DataProvider('symbols')]
    public function testTypoInPairingSymbol(string $a, string $b, bool $typo): void
    {
        self::assertSame($typo, PairingSymbolMatcher::typo($a, $b));
        self::assertSame($typo, PairingSymbolMatcher::typo($b, $a));
    }

    public function testOnlyTakenOverFreeMovementsMayBePaired(): void
    {
        $m = ['taken' => true, 'status' => 'unmatched', 'reason' => '', 'matched' => 0.0];
        self::assertTrue(DocumentLinker::movementEditable($m, false));
        self::assertTrue(DocumentLinker::movementEditable($m, true));
        // Platba zaúčtovaná v MyÚčtu (bez převzatého bankovního zápisu).
        self::assertFalse(DocumentLinker::movementEditable(['taken' => false] + $m, false));
        // Převodem označený jako zaúčtovaný bez dokladu smí, ignorovaný uživatelem ne.
        self::assertTrue(DocumentLinker::movementEditable(['status' => 'ignored', 'reason' => 'money_s3_booked'] + $m, true));
        self::assertFalse(DocumentLinker::movementEditable(['status' => 'ignored', 'reason' => 'user'] + $m, false));
        // Spárovaný pohyb (hradí víc faktur): řádný převod ano, opravný jen když všechna jeho
        // párování vytvořil převod (párování uživatele je jeho rozhodnutí).
        $matched = ['status' => 'manual', 'matched' => 100.0] + $m;
        self::assertTrue(DocumentLinker::movementEditable($matched, false));
        self::assertFalse(DocumentLinker::movementEditable($matched, true));
        self::assertTrue(DocumentLinker::movementEditable(['user_matches' => 0] + $matched, true));
        self::assertFalse(DocumentLinker::movementEditable(['user_matches' => 1] + $matched, true));
        self::assertFalse(DocumentLinker::movementEditable(null, false));
    }
}
