<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\PohodaXmlParser;
use PHPUnit\Framework\TestCase;

/**
 * Cizoměnový doklad bez rozpisu položek a klasifikace DPH z Pohody.
 *
 * Dva nálezy z ostrého importu, oba na přijatých službách z EU (licence, reklama):
 *
 * 1. **Doklad vyšel NULOVÝ.** Pohoda píše rozpad podle sazeb jen do `homeCurrency`;
 *    `foreignCurrency` nese pouhý součet. Dopočet položek z rekapitulace hledal přihrádky
 *    v bloku podle měny dokladu, takže u cizoměnového dokladu nenašel nic — zmizel náklad
 *    i podklad pro samovyměření.
 *
 * 2. **Režim přenesené daňové povinnosti se nepoznal a zároveň se poznával špatně.**
 *    Kód se četl jako textContent celého `<inv:classificationVAT>`, tedy i s vnořeným
 *    `<typ:id>` („216PDslRegEU"), takže porovnání prefixu neuspělo nikdy. A prefix byl
 *    `PN`, což v Pohodě znamená „Přijaté Nezdanitelné plnění" — běžný nákup od neplátce.
 *    Kdyby detekce fungovala, vyrobila by samovyměření u dvou set běžných nákupů.
 */
final class PohodaXmlParserForeignSummaryTest extends TestCase
{
    /** Doklad v EUR bez položek musí nést svou hodnotu, ne nulu. */
    public function testForeignDocumentWithoutItemsIsDerivedFromHomeCurrency(): void
    {
        $parsed = (new PohodaXmlParser())->parse($this->xml('PDslRegEU', '318.79', '24.335', '13.1'));
        $inv = $parsed['invoices'][0];

        self::assertCount(1, $inv['items'], 'Doklad nesmí vzniknout bez jediné položky.');
        // 318,79 Kč / 24,335 = 13,10 EUR — a hlavně přesně to, co je v `priceSum`.
        self::assertEqualsWithDelta(13.10, (float) $inv['items'][0]['unit_price_without_vat'], 0.001);
        self::assertSame(0.0, (float) $inv['items'][0]['vat_rate'], 'Plnění bez české daně má nulovou sazbu.');
        self::assertSame('EUR', $inv['currency']);
    }

    /** Dorovnání na součet dokladu: dělení kurzem po řádcích se od `priceSum` liší o haléře. */
    public function testDerivedTotalMatchesDocumentSumToTheCent(): void
    {
        // 1 000 Kč / 24,7 = 40,4858… → zaokrouhleno 40,49, ale doklad zní na 40,50.
        $parsed = (new PohodaXmlParser())->parse($this->xml('PDslRegEU', '1000', '24.7', '40.5'));
        $inv = $parsed['invoices'][0];

        $sum = 0.0;
        foreach ($inv['items'] as $item) {
            $sum += (float) $item['unit_price_without_vat'];
        }
        self::assertEqualsWithDelta(40.50, $sum, 0.001, 'Součet položek musí sednout na částku dokladu.');
    }

    /** Služba z EU je samovyměření. */
    public function testEuServiceIsReverseCharge(): void
    {
        $parsed = (new PohodaXmlParser())->parse($this->xml('PDslRegEU', '318.79', '24.335', '13.1'));

        self::assertTrue($parsed['invoices'][0]['reverse_charge']);
    }

    /**
     * JÁDRO DRUHÉHO NÁLEZU: `PN` je přijaté NEZDANITELNÉ plnění (nákup od neplátce),
     * ne přenesená daňová povinnost. Označit ho za samovyměření by vyrobilo daň
     * k plnění, u kterého žádná není.
     */
    public function testNonTaxablePurchaseIsNotReverseCharge(): void
    {
        $parsed = (new PohodaXmlParser())->parse($this->xml('PN', '318.79', '24.335', '13.1'));

        self::assertFalse($parsed['invoices'][0]['reverse_charge']);
    }

    /** Tuzemské přijaté zdanitelné plnění taky ne. */
    public function testDomesticTaxablePurchaseIsNotReverseCharge(): void
    {
        $parsed = (new PohodaXmlParser())->parse($this->xml('PD', '318.79', '24.335', '13.1'));

        self::assertFalse($parsed['invoices'][0]['reverse_charge']);
    }

    /**
     * Kód se čte z `typ:ids`, ne z textContent celého elementu — ten nese i `typ:id`
     * a udělá z „PDslRegEU" řetězec „216PDslRegEU".
     */
    private function xml(string $vatClassCode, string $homeNone, string $rate, string $priceSum): string
    {
        $rsp = 'http://www.stormware.cz/schema/version_2/response.xsd';
        $lst = 'http://www.stormware.cz/schema/version_2/list.xsd';
        $inv = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
        $typ = 'http://www.stormware.cz/schema/version_2/type.xsd';

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rsp:responsePack xmlns:rsp="{$rsp}" xmlns:lst="{$lst}" xmlns:inv="{$inv}" xmlns:typ="{$typ}"
                              version="2.0" ico="12345678">
              <rsp:responsePackItem version="2.0">
                <lst:listInvoice version="2.0">
                  <lst:invoice version="2.0">
                    <inv:invoiceHeader>
                      <inv:invoiceType>receivedInvoice</inv:invoiceType>
                      <inv:number><typ:numberRequested>26PF00016</typ:numberRequested></inv:number>
                      <inv:symVar>70092</inv:symVar>
                      <inv:date>2093-01-21</inv:date>
                      <inv:dateTax>2093-01-21</inv:dateTax>
                      <inv:dateDue>2093-02-04</inv:dateDue>
                      <inv:classificationVAT><typ:id>216</typ:id><typ:ids>{$vatClassCode}</typ:ids></inv:classificationVAT>
                      <inv:text>Licence software</inv:text>
                      <inv:partnerIdentity>
                        <typ:address><typ:company>Zahraniční dodavatel</typ:company><typ:dic>IE9999999X</typ:dic></typ:address>
                      </inv:partnerIdentity>
                      <inv:myIdentity>
                        <typ:address><typ:company>Testovací odběratel a.s.</typ:company><typ:ico>12345678</typ:ico></typ:address>
                      </inv:myIdentity>
                    </inv:invoiceHeader>
                    <inv:invoiceSummary>
                      <inv:homeCurrency>
                        <typ:priceNone>{$homeNone}</typ:priceNone>
                        <typ:priceLow>0</typ:priceLow>
                        <typ:priceLowVAT rate="12">0</typ:priceLowVAT>
                        <typ:priceHigh>0</typ:priceHigh>
                        <typ:priceHighVAT rate="21">0</typ:priceHighVAT>
                      </inv:homeCurrency>
                      <inv:foreignCurrency>
                        <typ:currency><typ:ids>EUR</typ:ids></typ:currency>
                        <typ:rate>{$rate}</typ:rate>
                        <typ:amount>1</typ:amount>
                        <typ:priceSum>{$priceSum}</typ:priceSum>
                      </inv:foreignCurrency>
                    </inv:invoiceSummary>
                  </lst:invoice>
                </lst:listInvoice>
              </rsp:responsePackItem>
            </rsp:responsePack>
            XML;
    }
}
