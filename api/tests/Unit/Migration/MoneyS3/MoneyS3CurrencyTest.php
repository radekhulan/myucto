<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\InvoiceImporter;
use MyInvoice\Service\Migration\MoneyS3\JournalImporter;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyS3CurrencyTest extends TestCase
{
    /** @return iterable<string,array{mixed,string}> */
    public static function currencies(): iterable
    {
        yield 'prázdné pole' => ['', 'CZK'];
        yield 'null' => [null, 'CZK'];
        yield 'Kč z peněžního deníku' => ['Kč', 'CZK'];
        yield 'kč malými' => ['kč', 'CZK'];
        yield 'KČ' => ['KČ', 'CZK'];
        yield 'Kc bez diakritiky' => ['Kc', 'CZK'];
        yield 'CZK s mezerou' => [' czk ', 'CZK'];
        yield 'euro' => ['eur', 'EUR'];
    }

    #[DataProvider('currencies')]
    public function testCodeRecognizesCrownInEveryNotation(mixed $mena, string $expected): void
    {
        self::assertSame($expected, MoneyS3Currency::code($mena));
        self::assertSame($expected === 'CZK', MoneyS3Currency::isCzk($mena));
    }

    public function testJournalRowInKcIsNotForeign(): void
    {
        self::assertNull(JournalImporter::rowForeign(['Mena' => 'Kč', 'Castka' => 1210.0, 'CastkaCM' => 1210.0, 'Kurz' => 1]));
        self::assertSame('EUR', JournalImporter::rowForeign(['Mena' => 'EUR', 'Castka' => 2500.0, 'CastkaCM' => 100.0, 'Kurz' => 25])['currency'] ?? null);
    }

    public function testInvoiceInKcIsDomestic(): void
    {
        $currency = new \ReflectionMethod(InvoiceImporter::class, 'currency');
        self::assertSame('', $currency->invoke(null, ['Mena' => 'Kč']));
        self::assertSame('EUR', $currency->invoke(null, ['Mena' => 'eur']));
    }
}
