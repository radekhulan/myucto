<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\InvoiceImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Číslo opravovaného dokladu v textu opravy odpočtu § 74b, který POHODA generuje. */
final class PohodaDebtorCorrectionTargetTest extends TestCase
{
    private const TEXT = 'Vrácení uplatněného odpočtu DPH u neuhrazeného závazku do 6 měsíců od splatnosti, ev. č. původně vystaveného dokladu %s, datum splatnosti 01.02.2026';

    /** @return iterable<string,array{string,list<string>,string}> */
    public static function cases(): iterable
    {
        yield 'číslo bez mezery' => ['26PF0001', ['26PF0001'], '26PF0001'];
        yield 'číslo s mezerou' => ['900 0001', ['900 0001'], '900 0001'];
        yield 's mezerou, kratší prefix je jiný doklad' => ['900 0001', ['900', '900 0001'], '900 0001'];
        yield 'neznámé číslo vrací první slovo' => ['900 0001', [], '900'];
    }

    /** @param list<string> $known */
    #[DataProvider('cases')]
    public function testResolvesNumberAgainstImportedDocuments(string $number, array $known, string $expected): void
    {
        $target = InvoiceImporter::debtorCorrectionTarget(sprintf(self::TEXT, $number), static fn (string $n): bool => in_array($n, $known, true));

        self::assertSame($expected, $target);
    }

    public function testTextWithoutDocumentNumber(): void
    {
        self::assertSame('', InvoiceImporter::debtorCorrectionTarget('Oprava odpočtu', static fn (): bool => true));
    }
}
