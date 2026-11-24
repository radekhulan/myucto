<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\InvoiceImporter;
use PHPUnit\Framework\TestCase;

final class InvoiceItemDescriptionTest extends TestCase
{
    public function testMatchesRatesAndJoinsDistinctNonemptyDescriptionsInOrder(): void
    {
        $descriptions = InvoiceImporter::lineDescriptions([
            ['SazbaDPH' => 21, 'Popis' => 'Úklid', 'Poradi' => 20, 'CisloPoloz' => 1],
            ['SazbaDPH' => 21.0, 'Popis' => 'Servis', 'Poradi' => 10, 'CisloPoloz' => 3],
            ['SazbaDPH' => 21.001, 'Popis' => ' Dohled ', 'Poradi' => 10, 'CisloPoloz' => 2],
            ['SazbaDPH' => 21, 'Popis' => 'Dohled', 'Poradi' => 30],
            ['SazbaDPH' => 21, 'Popis' => '  ', 'Poradi' => 1],
            ['Popis' => 'Nájem'],
            ['SazbaDPH' => 0, 'Popis' => 'Nájem'],
            ['SazbaDPH' => 12, 'Popis' => 'Spotřeba', 'CisloPoloz' => 2],
            ['SazbaDPH' => 12, 'Popis' => 'Odečet', 'CisloPoloz' => 1],
        ]);

        self::assertSame('Nájem', $descriptions[0]);
        self::assertSame('Dohled; Servis; Úklid', $descriptions[21]);
        self::assertSame('Odečet; Spotřeba', $descriptions[12]);
        self::assertArrayNotHasKey(15, $descriptions);
    }

    public function testMissingOrBlankDescriptionsProvideNoReplacement(): void
    {
        self::assertSame([], InvoiceImporter::lineDescriptions([]));
        self::assertSame([], InvoiceImporter::lineDescriptions([['SazbaDPH' => 21], ['Popis' => ' ']]));
        self::assertSame([0 => '0'], InvoiceImporter::lineDescriptions([['Popis' => '0']]));
    }
}
