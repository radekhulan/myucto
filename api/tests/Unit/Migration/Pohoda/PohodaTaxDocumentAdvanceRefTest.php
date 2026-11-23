<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\DocumentLinker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Číslo zálohy v textu daňového dokladu k přijaté platbě, který POHODA generuje. Podle něj
 * převod naváže DDKP na zálohu, když export vazbu nenese.
 */
final class PohodaTaxDocumentAdvanceRefTest extends TestCase
{
    /** @return iterable<string,array{string,?string}> */
    public static function cases(): iterable
    {
        yield 'vydaný DDKP, číslo v závorce' => ['Daňový doklad k přijaté platbě (zálohová faktura č. 260800020)', '260800020'];
        yield 'číslo s písmeny v závorce' => ['Daňový doklad k přijaté platbě (zálohová faktura č. 26ZFV0007)', '26ZFV0007'];
        yield 'za závorkou další text' => ['Daňový doklad k přijaté platbě (zálohová faktura č. 1260001) STORNO', '1260001'];
        yield 'přijatý DDKP bez závorky' => ['Daňový doklad k uhrazené zálohové faktuře č. 26ZP0002', '26ZP0002'];
        yield 'tečka za číslem' => ['Daňový doklad k uhrazené zálohové faktuře č. 26ZP0002.', '26ZP0002'];
        yield 'bez čísla zálohy' => ['Daňový doklad k přijaté platbě', null];
    }

    #[DataProvider('cases')]
    public function testReadsAdvanceNumber(string $text, ?string $expected): void
    {
        self::assertSame($expected, DocumentLinker::advanceNumberFromText($text));
    }
}
