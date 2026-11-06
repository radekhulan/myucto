<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\InvoiceImporter;
use PHPUnit\Framework\TestCase;

/**
 * Evidenční číslo dokladu dodavatele pro KH (`c_evid_dd`) z dokladu POHODY. Interní daňový
 * doklad k platbě má číslo i variabilní symbol vlastní, dodavatelovo číslo nese jen „číslo
 * dokladu pro KH" (`numberKHDPH`, v datovém souboru `CisloKHDPH`). Syntetické hodnoty.
 */
final class InvoiceImporterVendorNumberTest extends TestCase
{
    public function testKhNumberWinsThenOriginalThenSymbolThenNumber(): void
    {
        $doc = ['kh_number' => 'PDZ262604342', 'original' => 'PZ26204858', 'symvar' => '2600008', 'number' => '26ZZ00008'];
        self::assertSame('PDZ262604342', InvoiceImporter::vendorDocumentNumber($doc));
        self::assertSame('PZ26204858', InvoiceImporter::vendorDocumentNumber(['kh_number' => ' '] + $doc));
        self::assertSame('2600008', InvoiceImporter::vendorDocumentNumber(['kh_number' => '', 'original' => ''] + $doc));
        self::assertSame('26ZZ00008', InvoiceImporter::vendorDocumentNumber(['number' => '26ZZ00008']));
    }
}
