<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\PohodaXmlParser;
use PHPUnit\Framework\TestCase;

/**
 * Doklad BEZ rozpisu položek — řádky dopočtené z jeho vlastní rekapitulace.
 *
 * Pohoda takový doklad běžně vyváží: faktura zadaná jen textem a částkou nemá
 * `<inv:invoiceDetail>`, ale rekapitulaci má úplnou. Import ji přitom odmítal, protože
 * součty se počítají výhradně z řádků — v reálné migraci 3 144 faktur takhle propadlo
 * 458 dokladů se základem 2 034 236 Kč a daní 427 190 Kč.
 */
final class PohodaXmlParserSummaryItemsTest extends TestCase
{
    private const DAT = 'http://www.stormware.cz/schema/version_2/data.xsd';
    private const INV = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
    private const TYP = 'http://www.stormware.cz/schema/version_2/type.xsd';

    /** Doklad bez `<inv:invoiceDetail>`; `$summary` je obsah `<inv:homeCurrency>`. */
    private function parseWithoutItems(string $summary, string $headerText = 'Daňové poradenství'): array
    {
        $dat = self::DAT;
        $inv = self::INV;
        $typ = self::TYP;
        $xml = <<<XML
        <dat:dataPack xmlns:dat="$dat" xmlns:inv="$inv" xmlns:typ="$typ" ico="12345678">
          <dat:dataPackItem>
            <inv:invoice version="2.0">
              <inv:invoiceHeader>
                <inv:invoiceType>issuedInvoice</inv:invoiceType>
                <inv:symVar>2600001</inv:symVar>
                <inv:number><typ:numberRequested>26VF00001</typ:numberRequested></inv:number>
                <inv:date>2026-01-14</inv:date>
                <inv:text>$headerText</inv:text>
              </inv:invoiceHeader>
              <inv:invoiceSummary>
                <inv:homeCurrency>
        $summary
                </inv:homeCurrency>
              </inv:invoiceSummary>
            </inv:invoice>
          </dat:dataPackItem>
        </dat:dataPack>
        XML;

        $res = (new PohodaXmlParser())->parse($xml);
        self::assertNotEmpty($res['invoices']);
        self::assertArrayNotHasKey('__error', $res['invoices'][0]);

        return $res['invoices'][0];
    }

    /** Součet základů a daně z položek — proti čemu se porovnává rekapitulace. */
    private static function totals(array $items): array
    {
        $base = 0.0;
        $vat = 0.0;
        foreach ($items as $item) {
            $line = $item['unit_price_without_vat'] * $item['quantity'];
            $base += $line;
            $vat += $line * ($item['vat_rate'] ?? 0.0) / 100.0;
        }

        return [round($base, 2), round($vat, 2)];
    }

    public function testTaxedDocumentWithoutItemsGetsOneLinePerRate(): void
    {
        $inv = $this->parseWithoutItems(<<<XML
                  <typ:priceHigh>1000.00</typ:priceHigh>
                  <typ:priceHighVAT rate="21">210.00</typ:priceHighVAT>
                  <typ:priceLow>500.00</typ:priceLow>
                  <typ:priceLowVAT rate="12">60.00</typ:priceLowVAT>
        XML);

        self::assertSame('summary_recap', $inv['items_source']);
        self::assertCount(2, $inv['items']);
        self::assertSame([1500.00, 270.00], self::totals($inv['items']));
        // Popis se přebírá z textu hlavičky — jediné, co se u dopočtu „vymýšlí".
        self::assertSame('Daňové poradenství', $inv['items'][0]['description']);
        self::assertSame('summary_recap', $inv['items'][0]['vat_rate_source']);
    }

    /**
     * Plnění bez daně nesmí zmizet: přijaté faktury od neplátců mají v rekapitulaci
     * jedině `priceNone` a bez něj by z nich nezbylo vůbec nic (v testovaném souboru
     * 172 dokladů ze 409).
     */
    public function testExemptOnlyDocumentYieldsZeroRatedLine(): void
    {
        $inv = $this->parseWithoutItems('          <typ:priceNone>3000.00</typ:priceNone>');

        self::assertSame('summary_recap', $inv['items_source']);
        self::assertCount(1, $inv['items']);
        self::assertSame(0.0, $inv['items'][0]['vat_rate']);
        self::assertSame([3000.00, 0.00], self::totals($inv['items']));
    }

    /**
     * HALÉŘOVÉ VYROVNÁNÍ. Pohoda ho při zaokrouhlení dokladu (`roundingDocument`
     * = `math2one`) parkuje do `priceNone` jako ZÁPORNOU částku vedle kladného základu,
     * kdežto `<typ:round>` nechá nulové. Absolutní hodnota by z těch dvaceti haléřů
     * udělala plus a doklad by proti originálu přebil o čtyřicet — přesně tak se to
     * chovalo na 19 dokladech reálného souboru.
     */
    public function testNegativeRoundingResidualKeepsItsSign(): void
    {
        $inv = $this->parseWithoutItems(<<<XML
                  <typ:priceNone>-0.20</typ:priceNone>
                  <typ:priceHigh>35520.00</typ:priceHigh>
                  <typ:priceHighVAT rate="21">7459.20</typ:priceHighVAT>
                  <typ:round><typ:priceRound>0</typ:priceRound></typ:round>
        XML);

        self::assertSame([35519.80, 7459.20], self::totals($inv['items']));
        // Zbytek vedle zdaněného plnění není osvobozené plnění — popis hlavičky by mátl.
        $rounding = array_values(array_filter(
            $inv['items'],
            static fn (array $i): bool => $i['unit_price_without_vat'] < 0.0,
        ));
        self::assertCount(1, $rounding);
        self::assertSame('Zaokrouhlení', $rounding[0]['description']);
    }

    /**
     * Dobropis Pohoda vyváží zápornými částkami, kdežto přihrádky se čtou v absolutní
     * hodnotě. Celý dopočtený doklad proto musí vyjít v ORIENTACI PŘIHRÁDEK — tedy kladný
     * — aby ho otočení dobropisu v importní vrstvě otočilo celý najednou. Zbytek zapsaný
     * opačným znaménkem (`priceNone` +0,30 vedle základu −1 000) doklad SNIŽUJE, takže
     * relativně je záporný; syrové znaménko by mu naopak přičetlo.
     */
    public function testNegativelyWrittenDocumentKeepsBucketOrientation(): void
    {
        $inv = $this->parseWithoutItems(<<<XML
                  <typ:priceNone>0.30</typ:priceNone>
                  <typ:priceHigh>-1000.00</typ:priceHigh>
                  <typ:priceHighVAT rate="21">-210.00</typ:priceHighVAT>
        XML);

        // Originál je −1 000 + 0,30 = −999,70; dopočet ho vede kladně o téže velikosti.
        self::assertSame([999.70, 210.00], self::totals($inv['items']));
    }

    /**
     * Přihrádka s částkou, ale bez určitelného procenta (cizoměnový doklad bez daně
     * v přihrádce) ruší dopočet CELÉHO dokladu. Dosadit tam české procento by z něj
     * udělalo pozitivní tvrzení „tohle je tuzemská sazba"; uložit ho bez té přihrádky
     * by znamenalo uložit ho neúplný a tvářil by se přitom kompletně.
     */
    public function testBucketWithoutDeterminableRateCancelsSynthesis(): void
    {
        $dat = self::DAT;
        $inv = self::INV;
        $typ = self::TYP;
        $xml = <<<XML
        <dat:dataPack xmlns:dat="$dat" xmlns:inv="$inv" xmlns:typ="$typ" ico="12345678">
          <dat:dataPackItem>
            <inv:invoice version="2.0">
              <inv:invoiceHeader>
                <inv:invoiceType>issuedInvoice</inv:invoiceType>
                <inv:symVar>2600002</inv:symVar>
                <inv:date>2026-01-14</inv:date>
              </inv:invoiceHeader>
              <inv:invoiceSummary>
                <inv:foreignCurrency>
                  <typ:currency><typ:ids>EUR</typ:ids></typ:currency>
                  <typ:rate>25.00</typ:rate>
                  <typ:amount>1</typ:amount>
                  <typ:priceHigh>100.00</typ:priceHigh>
                  <typ:priceHighVAT>0</typ:priceHighVAT>
                </inv:foreignCurrency>
              </inv:invoiceSummary>
            </inv:invoice>
          </dat:dataPackItem>
        </dat:dataPack>
        XML;

        $parsed = (new PohodaXmlParser())->parse($xml)['invoices'][0];

        self::assertSame([], $parsed['items']);
        self::assertSame('detail', $parsed['items_source']);
    }

    /** Doklad S položkami se nesmí dopočtem dotknout. */
    public function testDocumentWithRealItemsIsUntouched(): void
    {
        $dat = self::DAT;
        $inv = self::INV;
        $typ = self::TYP;
        $xml = <<<XML
        <dat:dataPack xmlns:dat="$dat" xmlns:inv="$inv" xmlns:typ="$typ" ico="12345678">
          <dat:dataPackItem>
            <inv:invoice version="2.0">
              <inv:invoiceHeader>
                <inv:invoiceType>issuedInvoice</inv:invoiceType>
                <inv:symVar>2600003</inv:symVar>
                <inv:date>2026-01-14</inv:date>
                <inv:text>Hlavička</inv:text>
              </inv:invoiceHeader>
              <inv:invoiceDetail>
                <inv:invoiceItem>
                  <inv:text>Konzultace</inv:text>
                  <inv:quantity>2</inv:quantity>
                  <inv:rateVAT>high</inv:rateVAT>
                  <inv:homeCurrency><typ:unitPrice>500.00</typ:unitPrice></inv:homeCurrency>
                </inv:invoiceItem>
              </inv:invoiceDetail>
              <inv:invoiceSummary>
                <inv:homeCurrency>
                  <typ:priceHigh>1000.00</typ:priceHigh>
                  <typ:priceHighVAT rate="21">210.00</typ:priceHighVAT>
                </inv:homeCurrency>
              </inv:invoiceSummary>
            </inv:invoice>
          </dat:dataPackItem>
        </dat:dataPack>
        XML;

        $parsed = (new PohodaXmlParser())->parse($xml)['invoices'][0];

        self::assertSame('detail', $parsed['items_source']);
        self::assertCount(1, $parsed['items']);
        self::assertSame('Konzultace', $parsed['items'][0]['description']);
    }
}
