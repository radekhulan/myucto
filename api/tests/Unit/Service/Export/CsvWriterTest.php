<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Service\Export\CsvWriter;
use PHPUnit\Framework\TestCase;

final class CsvWriterTest extends TestCase
{
    public function testBuildStartsWithBomAndHeaderRow(): void
    {
        $csv = CsvWriter::build(['VS', 'Klient', 'Celkem'], [
            ['2026001', 'ACME', '1000.00'],
        ]);

        // UTF-8 BOM na začátku (Excel)
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        // Hlavičkový řádek s `;` oddělovačem
        $this->assertStringContainsString('VS;Klient;Celkem', $csv);
        $this->assertStringContainsString('2026001;ACME;1000.00', $csv);
    }

    public function testSafeGuardsAgainstCsvInjection(): void
    {
        // Buňky začínající =,+,-,@,TAB,CR se prefixují apostrofem (OWASP CSV injection)
        $this->assertSame("'=cmd|'/c calc'!A1", CsvWriter::safe("=cmd|'/c calc'!A1"));
        $this->assertSame("'+1", CsvWriter::safe('+1'));
        $this->assertSame("'-2", CsvWriter::safe('-2'));
        $this->assertSame("'@x", CsvWriter::safe('@x'));
        // Běžný text zůstává beze změny
        $this->assertSame('ACME s.r.o.', CsvWriter::safe('ACME s.r.o.'));
        $this->assertSame('', CsvWriter::safe(null));
    }

    public function testBackslashBeforeQuoteCannotCreateAnExtraCell(): void
    {
        $value = 'text\\";=1+1';
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, CsvWriter::build(['Label'], [[$value]]));
        rewind($stream);
        fgetcsv($stream, separator: ';', escape: '');
        self::assertSame([$value], fgetcsv($stream, separator: ';', escape: ''));
        fclose($stream);
    }
}
