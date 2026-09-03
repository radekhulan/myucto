<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\PohodaXmlParser;
use PHPUnit\Framework\TestCase;

/**
 * SMĚR dokladu a role stran v Pohoda XML.
 *
 * Pohoda vyváží přijaté i vydané doklady do TÉHOŽ tvaru souboru: `root@ico` je u obou
 * IČO exportující firmy a `<inv:partnerIdentity>` je u obou protistrana. Jediné, co je
 * rozlišuje, je `<inv:invoiceType>`. Dokud se nečetl, mířila přijatá faktura do importu
 * jako vydaná — buď spadla na cross-tenant guardu („patří jinému plátci"), nebo se
 * TICHE založila jako vydaná, což obrátí stranu evidence DPH i přiznání.
 */
final class PohodaXmlParserDirectionTest extends TestCase
{
    private const DAT = 'http://www.stormware.cz/schema/version_2/data.xsd';
    private const INV = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
    private const TYP = 'http://www.stormware.cz/schema/version_2/type.xsd';

    /** Doklad s oběma stranami; `$type` je hodnota `<inv:invoiceType>`. */
    private function invoice(string $type, string $summary = ''): array
    {
        $dat = self::DAT;
        $inv = self::INV;
        $typ = self::TYP;
        $summary = $summary !== '' ? $summary : <<<XML
              <inv:invoiceSummary>
                <inv:homeCurrency>
                  <typ:priceHigh>1000.00</typ:priceHigh>
                  <typ:priceHighVAT rate="21">210.00</typ:priceHighVAT>
                </inv:homeCurrency>
              </inv:invoiceSummary>
        XML;

        $xml = <<<XML
        <dat:dataPack xmlns:dat="$dat" xmlns:inv="$inv" xmlns:typ="$typ" ico="12345678">
          <dat:dataPackItem>
            <inv:invoice version="2.0">
              <inv:invoiceHeader>
                <inv:invoiceType>$type</inv:invoiceType>
                <inv:symVar>2600001</inv:symVar>
                <inv:number><typ:numberRequested>26PF00001</typ:numberRequested></inv:number>
                <inv:date>2026-01-15</inv:date>
                <inv:text>Umístění sídel 1-12/2026</inv:text>
                <inv:partnerIdentity>
                  <typ:address>
                    <typ:company>Dodavatelská firma s.r.o.</typ:company>
                    <typ:ico>25596641</typ:ico>
                    <typ:dic>CZ25596641</typ:dic>
                  </typ:address>
                </inv:partnerIdentity>
                <inv:myIdentity>
                  <typ:address>
                    <typ:company>Testovací odběratel a.s.</typ:company>
                    <typ:ico>12345678</typ:ico>
                    <typ:dic>CZ12345678</typ:dic>
                  </typ:address>
                </inv:myIdentity>
              </inv:invoiceHeader>
        $summary
            </inv:invoice>
          </dat:dataPackItem>
        </dat:dataPack>
        XML;

        $res = (new PohodaXmlParser())->parse($xml);
        self::assertNotEmpty($res['invoices']);
        self::assertArrayNotHasKey('__error', $res['invoices'][0]);

        return $res['invoices'][0];
    }

    public function testReceivedInvoicePutsPartnerIntoSupplierRole(): void
    {
        $inv = $this->invoice('receivedInvoice');

        self::assertSame('purchase', $inv['direction']);
        // Protistrana je DODAVATEL — bez toho nemá purchase mapper koho dohledat.
        self::assertSame('25596641', $inv['supplier']['ic']);
        // Odběratelem je exportující firma, tedy tenant — na tom stojí cross-tenant guard.
        self::assertSame('12345678', $inv['client']['ic']);
    }

    public function testIssuedInvoiceKeepsPartnerAsCustomer(): void
    {
        $inv = $this->invoice('issuedInvoice');

        self::assertSame('issued', $inv['direction']);
        self::assertSame('25596641', $inv['client']['ic']);
        self::assertSame('12345678', $inv['supplier']['ic']);
    }

    public function testReceivedCorrectiveTaxIsPurchaseCreditNote(): void
    {
        $inv = $this->invoice('receivedCorrectiveTax');

        self::assertSame('purchase', $inv['direction']);
        self::assertSame('credit_note', $inv['invoice_type']);
    }

    public function testReceivedAdvanceInvoiceIsPurchaseProforma(): void
    {
        $inv = $this->invoice('receivedAdvanceInvoice');

        self::assertSame('purchase', $inv['direction']);
        self::assertSame('proforma', $inv['invoice_type']);
    }

    /**
     * Neznámý typ NESMÍ spadnout na „vydaná" — rozhodnout pak musí importní vrstva
     * podle IČO stran, jak to dělala dosud.
     */
    public function testUnknownTypeLeavesDirectionUndecided(): void
    {
        $inv = $this->invoice('somethingElse');

        self::assertNull($inv['direction']);
        // Bez určeného směru zůstává protistrana v roli odběratele (dosavadní chování).
        self::assertSame('25596641', $inv['client']['ic']);
    }
}
