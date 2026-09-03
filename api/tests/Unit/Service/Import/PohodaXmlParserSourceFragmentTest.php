<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\PohodaXmlParser;
use PHPUnit\Framework\TestCase;

/**
 * Zdrojový artefakt archivovaný k dokladu musí být úsek TOHO dokladu, ne celý soubor.
 *
 * Nález z ostrého importu: export z Pohody nese celou agendu v jednom souboru, takže
 * ke každé ze 409 přijatých faktur se archivoval týž několikamegabajtový soubor se
 * všemi ostatními doklady. Kromě nafouklého úložiště to znehodnocuje i důkazní stopu —
 * „zdroj dokladu" má být doklad, ne agenda, ve které ho čtenář musí teprve najít.
 */
final class PohodaXmlParserSourceFragmentTest extends TestCase
{
    private function parseBatch(): array
    {
        $parser = new PohodaXmlParser();

        return $parser->parse($this->batchXml());
    }

    public function testEachInvoiceCarriesOnlyItsOwnFragment(): void
    {
        $parsed = $this->parseBatch();
        self::assertCount(2, $parsed['invoices']);

        foreach ($parsed['invoices'] as $inv) {
            self::assertArrayHasKey('__source_xml', $inv, 'Doklad musí nést vlastní zdrojový úsek.');
            $xml = (string) $inv['__source_xml'];
            $vs = (string) $inv['varsymbol'];

            self::assertStringContainsString($vs, $xml, 'Úsek musí obsahovat vlastní doklad.');
            self::assertSame(1, substr_count($xml, '<lst:invoice'), 'Úsek smí nést právě jeden doklad.');
        }

        // Křížově: úsek prvního dokladu nesmí obsahovat symbol druhého.
        $first  = (string) $parsed['invoices'][0]['__source_xml'];
        $second = (string) $parsed['invoices'][1]['varsymbol'];
        self::assertStringNotContainsString($second, $first, 'Úsek nesmí nést cizí doklady.');
    }

    /** Kořen s `ico` musí v úseku zůstat — bez něj nejde poznat směr dokladu ani ho znovu naimportovat. */
    public function testFragmentKeepsRootWithExportingCompanyId(): void
    {
        $parsed = $this->parseBatch();
        $xml = (string) $parsed['invoices'][0]['__source_xml'];

        self::assertStringContainsString('ico="12345678"', $xml);

        $reparsed = (new PohodaXmlParser())->parse($xml);
        self::assertCount(1, $reparsed['invoices'], 'Úsek musí být znovu naimportovatelný sám o sobě.');
        self::assertSame('12345678', $reparsed['supplier_ic']);
    }

    private function batchXml(): string
    {
        $rsp = 'http://www.stormware.cz/schema/version_2/response.xsd';
        $lst = 'http://www.stormware.cz/schema/version_2/list.xsd';
        $inv = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
        $typ = 'http://www.stormware.cz/schema/version_2/type.xsd';

        $items = '';
        foreach ([['93100001', '2093-01-14'], ['93100002', '2093-01-15']] as [$vs, $date]) {
            $items .= <<<XML
                  <lst:invoice version="2.0">
                    <inv:invoiceHeader>
                      <inv:invoiceType>issuedInvoice</inv:invoiceType>
                      <inv:number><typ:numberRequested>{$vs}</typ:numberRequested></inv:number>
                      <inv:symVar>{$vs}</inv:symVar>
                      <inv:date>{$date}</inv:date>
                      <inv:text>Plnění</inv:text>
                    </inv:invoiceHeader>
                    <inv:invoiceSummary>
                      <inv:homeCurrency>
                        <typ:priceHigh>1000</typ:priceHigh>
                        <typ:priceHighVAT rate="21">210</typ:priceHighVAT>
                      </inv:homeCurrency>
                    </inv:invoiceSummary>
                  </lst:invoice>
                XML;
        }

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rsp:responsePack xmlns:rsp="{$rsp}" xmlns:lst="{$lst}" xmlns:inv="{$inv}" xmlns:typ="{$typ}"
                              version="2.0" ico="12345678">
              <rsp:responsePackItem version="2.0">
                <lst:listInvoice version="2.0">
            {$items}
                </lst:listInvoice>
              </rsp:responsePackItem>
            </rsp:responsePack>
            XML;
    }
}
