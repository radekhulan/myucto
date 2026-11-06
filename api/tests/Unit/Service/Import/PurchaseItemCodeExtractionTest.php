<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\InvoiceExtractionPrompt;
use MyInvoice\Service\Import\IsdocParser;
use MyInvoice\Service\Import\PohodaXmlParser;
use PHPUnit\Framework\TestCase;

/** Kód zboží a EAN z řádku: prompt všech providerů, schema, ISDOC a Pohoda XML. */
final class PurchaseItemCodeExtractionTest extends TestCase
{
    public function testEveryProviderAsksForItemCodes(): void
    {
        $anthropic = (string) file_get_contents(dirname(__DIR__, 4) . '/src/Service/Import/AnthropicClient.php');
        self::assertStringContainsString('"seller_item_code": string|null', $anthropic);
        self::assertStringContainsString('InvoiceExtractionPrompt::itemCodeFieldRules()', $anthropic);

        $shared = InvoiceExtractionPrompt::invoiceSystem();
        self::assertStringContainsString('"seller_item_code": string|null', $shared);
        self::assertStringContainsString(InvoiceExtractionPrompt::itemCodeFieldRules(), $shared);

        $item = InvoiceExtractionPrompt::invoiceJsonSchema()['properties']['items']['items'];
        self::assertSame(['string', 'null'], $item['properties']['seller_item_code']['type']);
        self::assertSame(['string', 'null'], $item['properties']['item_ean']['type']);
        self::assertContains('seller_item_code', $item['required'], 'Striktní schema chce všechna pole v required.');
        self::assertContains('intrastat_cn8_code', $item['required']);
        self::assertContains('intrastat_country_of_origin', $item['required']);
        self::assertStringContainsString('"intrastat_cn8_code": string|null', $anthropic);
    }

    public function testIsdocReadsSellerCodeAndEan(): void
    {
        $isdoc = (string) file_get_contents(dirname(__DIR__, 4) . '/tests/Fixtures/Shoptet/isdoc-faktura.isdoc');
        $xml = str_replace(
            '<Item><Description>Tričko – M</Description></Item>',
            '<Item><Description>Tričko – M</Description><CatalogueItemIdentification><ID>8590000000011</ID></CatalogueItemIdentification>'
            . '<SellersItemIdentification><ID>000079673</ID></SellersItemIdentification></Item>',
            $isdoc,
        );
        self::assertNotSame($isdoc, $xml);

        $item = (new IsdocParser())->parse((string) $xml)['invoices'][0]['items'][0];
        self::assertSame('000079673', $item['seller_item_code'], 'Úvodní nuly se nesmí ztratit.');
        self::assertSame('8590000000011', $item['item_ean']);
    }

    public function testPohodaReadsCodeFromLineOrStockLink(): void
    {
        $withCode = $this->pohodaItem('<inv:code>ABC-1</inv:code>');
        self::assertSame('ABC-1', $withCode['seller_item_code']);
        self::assertNull($withCode['item_ean']);

        $withStock = $this->pohodaItem('<inv:stockItem><typ:stockItem><typ:ids>ZAS-7</typ:ids><typ:EAN>8590000000028</typ:EAN></typ:stockItem></inv:stockItem>');
        self::assertSame('ZAS-7', $withStock['seller_item_code']);
        self::assertSame('8590000000028', $withStock['item_ean']);

        self::assertNull($this->pohodaItem('')['seller_item_code']);
    }

    /** @return array<string,mixed> */
    private function pohodaItem(string $extra): array
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rsp:responsePack version="2.0" xmlns:rsp="http://www.stormware.cz/schema/version_2/response.xsd"
    xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
    xmlns:lst="http://www.stormware.cz/schema/version_2/list.xsd"
    xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd">
  <rsp:responsePackItem version="2.0" id="U1" state="ok"><lst:listInvoice version="2.0" state="ok"><lst:invoice version="2.0">
    <inv:invoiceHeader>
      <inv:invoiceType>receivedInvoice</inv:invoiceType>
      <inv:number><typ:numberRequested>26PF001</typ:numberRequested></inv:number>
      <inv:symVar>26001</inv:symVar>
      <inv:date>2026-01-04</inv:date><inv:dateTax>2026-01-04</inv:dateTax><inv:dateDue>2026-01-18</inv:dateDue>
      <inv:partnerIdentity><typ:address><typ:company>Dodavatel s.r.o.</typ:company><typ:ico>42408393</typ:ico></typ:address></inv:partnerIdentity>
    </inv:invoiceHeader>
    <inv:invoiceDetail><inv:invoiceItem>
      <inv:text>Zboží</inv:text>
      <inv:quantity>1</inv:quantity><inv:unit>ks</inv:unit>
      <inv:rateVAT>high</inv:rateVAT>
      <inv:homeCurrency><typ:unitPrice>100</typ:unitPrice><typ:price>100</typ:price><typ:priceVAT>21</typ:priceVAT></inv:homeCurrency>
      {$extra}
    </inv:invoiceItem></inv:invoiceDetail>
  </lst:invoice></lst:listInvoice></rsp:responsePackItem>
</rsp:responsePack>
XML;
        $invoice = (new PohodaXmlParser())->parse($xml)['invoices'][0];
        self::assertArrayNotHasKey('__error', $invoice);
        return $invoice['items'][0];
    }
}
