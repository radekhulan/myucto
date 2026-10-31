<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Pohoda;

/**
 * Syntetický XML export agendy z POHODY (výstup nástroje `tools/pohoda-export`) pro testy
 * převodu. Fiktivní firma, fiktivní partneři, žádná reálná data.
 *
 * Agenda roku 2026: počáteční stavy (221 100 000 proti 411), vydaná faktura 1 000 + 21 %,
 * přijatá faktura 500 + 21 %, úhrada obou bankou. Deník zapisuje strany tak, jak je
 * zapisuje POHODA: `act:credit` = MD, `act:debit` = Dal.
 */
final class SyntheticPohodaExport
{
    public const ICO = '12345678';
    public const YEAR = 2026;
    public const NAME = 'Syntetická účetní s.r.o.';
    public const CUSTOMER_ICO = '87654321';
    public const VENDOR_ICO = '11223344';
    /** Vlastní účet firmy: platné číslo (modulo 11), které jiná firma v testovací DB nedrží. */
    public const BANK_ACCOUNT = '2604000000';
    public const BANK_CODE = '0800';

    private const NS = 'xmlns:rsp="http://www.stormware.cz/schema/version_2/response.xsd" xmlns:lst="http://www.stormware.cz/schema/version_2/list.xsd" '
        . 'xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd" xmlns:act="http://www.stormware.cz/schema/version_2/accountancy.xsd" '
        . 'xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd" xmlns:bnk="http://www.stormware.cz/schema/version_2/bank.xsd" '
        . 'xmlns:vat="http://www.stormware.cz/schema/version_2/classificationVAT.xsd" xmlns:bka="http://www.stormware.cz/schema/version_2/bankAccount.xsd" '
        . 'xmlns:adb="http://www.stormware.cz/schema/version_2/addressbook.xsd" xmlns:lAdb="http://www.stormware.cz/schema/version_2/list_addBook.xsd" '
        . 'xmlns:acu="http://www.stormware.cz/schema/version_2/accountingunit.xsd" xmlns:int="http://www.stormware.cz/schema/version_2/intDoc.xsd" xmlns:vch="http://www.stormware.cz/schema/version_2/voucher.xsd"';

    /** Karta majetku v tabulkách POHODY (`90_majetek.xml`) a její odpisy v deníku. */
    public const ASSET_NUMBER = '25IM0001';

    /** Doklad v režimu OSS (`$withOss`): 1 000 Kč + SK 23 %, odběratel bez DIČ na Slovensku. */
    public const OSS_DOCUMENT = '26FV0002';
    public const OSS_COUNTRY = 'SK';
    public const OSS_RATE = 23.0;

    /**
     * Tvar sazby na položce OSS dokladu (`$ossRateForm`). `value`: procento v exportním
     * atributu `rateVAT/@value`. `percent`: tvar, jakým POHODA píše sazbu, která se do
     * české úrovně nevejde - `historyHigh` bez atributu a skutečné procento v `percentVAT`,
     * k tomu částky položky i v EUR (issue #75). `history`: totéž bez `percentVAT` - sazba
     * řádku s daní se z exportu určit nedá. Stát `MOSS`, typ plnění a zemi odběratele
     * určuje {@see OssVariant}.
     */
    public const OSS_RATE_VALUE = 'value';
    public const OSS_RATE_PERCENT = 'percent';
    public const OSS_RATE_HISTORY = 'history';

    /** Konečná faktura s odpočtem nedaňové zálohy (`$withAdvanceDeduction`). */
    public const ADVANCE_DOCUMENT = '26FV0003';

    /** Přijatá faktura, kterou POHODA zlikviduje až v pozdějším exportu. */
    public const LATER_PAID_DOCUMENT = '26PF0003';
    public const LATER_PAID_VS = '2026009';
    public const LATER_PAID_BANK = 'BAN0010004';
    public const LATER_OPEN = 'open';
    public const LATER_SETTLED = 'settled';

    /** Účet protistrany (dodavatele i odběratele) - ověřený placeholder, projde mod 11. */
    public const PARTNER_ACCOUNT = '1000000005';
    public const PARTNER_BANK = '0100';
    /** Jiný účet protistrany (mod 11), na přijaté faktuře neuvedený. */
    public const OTHER_ACCOUNT = '2000000050';

    /**
     * Rok po roce agendy (`$unbooked`): agenda vede i doklady následujícího roku.
     */
    public const NEXT_YEAR = self::YEAR + 1;
    /** Vydaná faktura, kterou uhradí nezaúčtovaný příjem v následujícím roce (shoda VS + částka). */
    public const UNBOOKED_ISSUED = '26FV0004';
    public const UNBOOKED_ISSUED_VS = '260004';
    /** Dvě přijaté faktury se stejnou částkou i účtem dodavatele - platba bez VS je nejednoznačná. */
    public const AMBIGUOUS_PURCHASES = ['26PF0005', '26PF0006'];
    /** Přijatá faktura, kterou jednoznačně určí účet dodavatele, částka a datum. */
    public const ACCOUNT_PURCHASE = '26PF0007';
    /** Přijatá faktura, kterou určí párovací symbol pohybu (číslo dokladu). */
    public const PARSYM_PURCHASE = '26PF0008';
    /** Přijatá faktura, jejíž VS i částku má platba kartou - karta se automaticky nepáruje. */
    public const CARD_PURCHASE = '26PF0009';
    public const CARD_VS = '2026019';
    /**
     * Vydaná faktura s datem v následujícím roce, zaúčtovaná i uhrazená v POHODĚ. Úhrada nese
     * id bankovního dokladu a opis čísla jiného pohybu - rozhodovat musí id.
     */
    public const NEXT_YEAR_ISSUED = '26FV0005';

    /**
     * Zapíše export do `$root` (kořen jako rozbalený ZIP) a vrátí složku agendy.
     *
     * `$withAssets`: navíc majetek z datového souboru POHODY - stroj za 120 000 Kč
     * zařazený 15. 1. 2025, odpisová skupina 1, účetně 24 měsíců po 5 000 Kč od ledna
     * 2025; počáteční stavy 022/082 a odpisy leden až březen 2026 v deníku.
     *
     * `$withOss`: navíc doklad v režimu OSS tak, jak ho vede POHODA - členění `UN` mimo
     * přiznání, sazba státu spotřeby (SK 23 %), odběratel bez IČ a DIČ se slovenskou
     * adresou, měna EUR. V deníku 311/604 a 311/343.090.
     *
     * `$withAdvanceDeduction`: navíc konečná faktura 1 000 + 21 % s odpočtem nedaňové
     * zálohy 1 210 Kč (k úhradě 0). Deník ji má tak, jak ji zaúčtovala POHODA u reálné
     * agendy: odpočet zálohy KLADNĚ 311/602 1 210, takže 311 z dokladu drží 2 420 Kč
     * a rozdíl dokladů proti deníku je už v POHODĚ.
     *
     * `$laterPayment`: navíc přijatá faktura 26PF0003 na 726 Kč a odchozí platba s jejím VS.
     * `open` = stav před likvidací: faktura neuhrazená, platba v bance bez zaúčtování
     * (předkontace „Nevím"), v deníku tedy chybí. `settled` = účetní platbu mezitím zaúčtovala
     * jako úhradu faktury: faktura zlikvidovaná tímto pohybem, v deníku 321/221. Minulá
     * faktura 25FV0099 je v tomtéž exportu doplacená bez pohybu v bance.
     */
    public static function write(string $root, bool $withAssets = false, bool $withOss = false, bool $withAdvanceDeduction = false, ?string $laterPayment = null, bool $unbooked = false, string $ossRateForm = self::OSS_RATE_VALUE, ?OssVariant $oss = null): string
    {
        $dir = rtrim($root, '/\\') . '/' . self::ICO . '_' . self::YEAR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        self::file($root . '/00_ucetni_jednotky.xml', '<acu:listAccountingUnit version="1.1"><acu:itemAccountingUnit><acu:unitType>doubleEntry</acu:unitType>'
            . '<acu:year>' . self::YEAR . '</acu:year><acu:unitIdentity><typ:address><typ:company>' . self::NAME . '</typ:company><typ:ico>' . self::ICO . '</typ:ico></typ:address></acu:unitIdentity>'
            . '<acu:dataFile>StwPh_' . self::ICO . '_' . self::YEAR . '.mdb</acu:dataFile></acu:itemAccountingUnit></acu:listAccountingUnit>');
        foreach (self::files($withAssets, $withOss, $withAdvanceDeduction, $laterPayment, $unbooked, $ossRateForm, $oss) as $name => $body) {
            self::file($dir . '/' . $name, $body);
        }
        if ($withAssets) {
            $months = '';
            for ($i = 0; $i < 24; $i++) {
                $months .= sprintf('<IModpisM><ID>%d</ID><RefAg>1</RefAg><Mesic>%04d-%02d-01</Mesic><KcOdpis>5000</KcOdpis></IModpisM>', 100 + $i, 2025 + intdiv($i, 12), $i % 12 + 1);
            }
            file_put_contents($dir . '/90_majetek.xml', '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<mdbExport version="1" ico="' . self::ICO . '" year="' . self::YEAR . '" source="POHODA" state="ok">'
                . '<IM><ID>1</ID><Cislo>' . self::ASSET_NUMBER . '</Cislo><SText>Testovací stroj</SText><RelTpIM>1</RelTpIM><RelSkOdp>1</RelSkOdp><RelTpOdp>1</RelTpOdp>'
                . '<Datum>2025-01-15</Datum><DatZar>2025-01-15</DatZar><Kc>120000</Kc><KcDanova>120000</KcDanova></IM>'
                . '<IModpis><ID>1</ID><RefAg>1</RefAg><Rok>2025</Rok><RelUzavreno>1</RelUzavreno><KcOdpis>24000</KcOdpis></IModpis>'
                . '<IModpis><ID>2</ID><RefAg>1</RefAg><Rok>2026</Rok><KcOdpis>48000</KcOdpis></IModpis>'
                . '<IModpis><ID>3</ID><RefAg>1</RefAg><Rok>2027</Rok><KcOdpis>48000</KcOdpis></IModpis>'
                . $months
                // Drobný majetek: vrtačka z přijaté faktury (zdroj dohledaný nástrojem) a vyřazené židle bez dokladu.
                . '<IMmist><ID>1</ID><SText>Dílna</SText></IMmist>'
                . '<DM><ID>1</ID><Cislo>DM0001</Cislo><SText>Vrtačka</SText><RelTpDM>1</RelTpDM><RelAgID>2</RelAgID><RefPol>77</RefPol><Datum>2026-01-12</Datum>'
                . '<Pocet>1</Pocet><Kc>500</Kc><KcJedn>500</KcJedn><RefIMmist>1</RefIMmist>'
                . '<SrcAgenda>FA</SrcAgenda><SrcCislo>26PF0001</SrcCislo><SrcDatum>2026-01-12</SrcDatum><SrcText>Materiál</SrcText><SrcKc>500</SrcKc></DM>'
                . '<DM><ID>2</ID><Cislo>DM0002</Cislo><SText>Židle</SText><Datum>2025-06-01</Datum><Pocet>2</Pocet><Kc>3000</Kc><KcJedn>1500</KcJedn>'
                . '<DatLikv>2026-02-01</DatLikv></DM>'
                // Bez odkazu na doklad: obě karty se naváží podle data a ceny za kus na položku 2 ks faktury 26PF0002.
                . '<DM><ID>3</ID><Cislo>DM0003</Cislo><SText>Monitor</SText><Datum>2026-02-05</Datum><Pocet>1</Pocet><Kc>600</Kc><KcJedn>600</KcJedn></DM>'
                . '<DM><ID>4</ID><Cislo>DM0004</Cislo><SText>Monitor</SText><Datum>2026-02-05</Datum><Pocet>1</Pocet><Kc>600</Kc><KcJedn>600</KcJedn></DM>'
                . '</mdbExport>');
        }
        return $dir;
    }

    /**
     * Sklad z datového souboru POHODY (`92_sklad.xml`) do složky agendy `$dir`. Hlavní sklad
     * `HL`, materiálový `MAT` a konsignační `KON`; kabel `K-001` je v obou prvních skladech
     * (v hlavním 120,5 m za 1 210,37 Kč, v materiálovém 30 m za 303,33 Kč) a v hlavním
     * patří do větve členění Elektro / Kabely. Šroub `M-001` nese druh materiál a pohyb nemá,
     * jeho hodnota je stav × průměrná cena (1 000 × 0,3333). Dál služba, výrobek s kusovníkem,
     * karta se záporným a s nulovým stavem a textová položka. Ceník B2B je s DPH (36,30 =
     * 30,00 + 21 %), ceník EUR v eurech. Export proběhl 30. 6., poslední pohyb 1. 3.
     */
    public static function withStock(string $dir): void
    {
        $card = static fn (int $id, string $code, string $name, int $warehouse, int $type, string $qty, string $avg, string $extra = ''): string =>
            "<SKz><ID>{$id}</ID><IDS>{$code}</IDS><Nazev>{$name}</Nazev><RefSklad>{$warehouse}</RefSklad><RelSkTyp>{$type}</RelSkTyp>"
            . "<StavZ>{$qty}</StavZ><VNakup>{$avg}</VNakup><RelDPHp>2</RelDPHp><RelSKzVC>0</RelSKzVC>{$extra}</SKz>";
        file_put_contents($dir . '/92_sklad.xml', '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="sklad" source="POHODA" state="ok" created="2026-06-30T10:00:00">'
            . '<sSklad><ID>1</ID><IDS>HL</IDS><SText>Hlavní sklad</SText></sSklad>'
            . '<sSklad><ID>2</ID><IDS>MAT</IDS><SText>Materiálový sklad</SText></sSklad>'
            . '<sSklad><ID>3</ID><IDS>KON</IDS><SText>Konsignační sklad Test</SText></sSklad>'
            . '<SkSt><ID>10</ID><RefSklad>1</RefSklad></SkSt>'
            . '<SkSt><ID>11</ID><RefSklad>1</RefSklad><Vetev1>Elektro</Vetev1><Vetev2>Kabely</Vetev2></SkSt>'
            . '<SkSt><ID>20</ID><RefSklad>2</RefSklad></SkSt>'
            . '<SkCeny><ID>1</ID><IDS>Prodej</IDS><RelTpCeny>0</RelTpCeny><SDph>0</SDph></SkCeny>'
            . '<SkCeny><ID>2</ID><IDS>B2B</IDS><SText>Velkoobchod</SText><RelTpCeny>1</RelTpCeny><SDph>1</SDph></SkCeny>'
            . '<SkCeny><ID>3</ID><IDS>EUR</IDS><RelTpCeny>2</RelTpCeny><SDph>0</SDph><RefCM>9</RefCM><Sleva>0</Sleva></SkCeny>'
            . '<sCMeny><ID>9</ID><Kod>EUR</Kod><IDS>€</IDS></sCMeny>'
            . $card(101, 'K-001', 'Kabel', 1, 1, '120.5', '10.0444', '<RefStruct>11</RefStruct><MJ>m</MJ><MJ2>cívka</MJ2><MJ2Koef>100</MJ2Koef>'
                . '<EAN>4006381333931</EAN><ProdejKc>25</ProdejKc><MinLim>10</MinLim><Hmotnost>0.05</Hmotnost><NakupC>9.5</NakupC><RefAD>2</RefAD>')
            . $card(102, 'K-001', 'Kabel', 2, 1, '30', '10.111', '<RefStruct>20</RefStruct><MJ>m</MJ>')
            . $card(103, 'M-001', 'Šroub', 2, 1, '1000', '0.3333', '<RefStruct>20</RefStruct><RelSkDruh>3</RelSkDruh><MJ>ks</MJ>')
            . $card(104, 'SL-01', 'Montáž', 1, 3, '5', '0', '<MJ>hod</MJ>')
            . $card(105, 'V-01', 'Výrobek', 1, 5, '2', '500', '<MJ>ks</MJ>')
            . $card(106, 'N-01', 'Záporná karta', 1, 1, '-3', '10', '<MJ>ks</MJ>')
            . $card(107, 'Z-01', 'Nulová karta', 1, 1, '0', '10', '<MJ>ks</MJ>')
            . $card(108, 'KON-01', 'Cizí zboží', 3, 1, '7', '10', '<MJ>ks</MJ>')
            . $card(109, 'T-01', 'Text', 1, 2, '0', '0', '')
            . '<SKzCn><ID>1</ID><RefAg>101</RefAg><RefSkCeny>1</RefSkCeny><ProdejC>25</ProdejC></SKzCn>'
            . '<SKzCn><ID>2</ID><RefAg>101</RefAg><RefSkCeny>2</RefSkCeny><ProdejC>36.3</ProdejC></SKzCn>'
            . '<SKzCn><ID>3</ID><RefAg>101</RefAg><RefSkCeny>3</RefSkCeny><ProdejC>1.2</ProdejC></SKzCn>'
            . '<SKzCn><ID>4</ID><RefAg>102</RefAg><RefSkCeny>2</RefSkCeny><ProdejC>99</ProdejC></SKzCn>'
            . '<SKzCn><ID>5</ID><RefAg>103</RefAg><RefSkCeny>2</RefSkCeny><ProdejC>0</ProdejC></SKzCn>'
            . '<SKzPol><ID>1</ID><RefAg>105</RefAg><RefSKz>101</RefSKz><Mnozstvi>2</Mnozstvi></SKzPol>'
            . '<SKzPol><ID>2</ID><RefAg>105</RefAg><RefSKz>103</RefSKz><Mnozstvi>8</Mnozstvi></SKzPol>'
            . '<SKzStav><RefSKz>101</RefSKz><Datum>2026-03-01</Datum><KcOceneni>1210.37</KcOceneni><Pohybu>4</Pohybu></SKzStav>'
            . '<SKzStav><RefSKz>102</RefSKz><Datum>2026-02-10</Datum><KcOceneni>303.33</KcOceneni><Pohybu>2</Pohybu></SKzStav>'
            . '<SKzStav><RefSKz>105</RefSKz><Datum>2026-01-20</Datum><KcOceneni>1000</KcOceneni><Pohybu>1</Pohybu></SKzStav>'
            . '</mdbExport>');
    }

    /**
     * Ostatní pohledávka a závazek mimo DPH, neuhrazené. S `$settled` navíc:
     *   - závazek uhrazený bankou ve dvou splátkách,
     *   - předpis odvodu na účtu 336, který vlastní mzdový modul,
     *   - evidenční závazky bez zaúčtování (předkontace „bez"): podíl na zisku na 364
     *     částečně uhrazený bankou, záloha na daň z příjmů uhrazená bankou a doplatek
     *     daně na stejném účtu 341.
     */
    public static function withOtherItems(string $dir, bool $settled = false): void
    {
        $append = static function (string $file, string $needle, string $xml): void {
            $source = (string) file_get_contents($file);
            $needle = (string) iconv('UTF-8', 'Windows-1250', $needle);
            $xml = (string) iconv('UTF-8', 'Windows-1250', $xml);
            if (substr_count($source, $needle) !== 1) {
                throw new \RuntimeException('Nečekaný formát syntetického exportu.');
            }
            file_put_contents($file, str_replace($needle, $xml . $needle, $source));
        };
        $append($dir . '/02_uctova_osnova.xml', '</lst:listAccount>',
            '<lst:itemAccount id="315000" code="315000" name="Ostatní pohledávky"/>'
            . '<lst:itemAccount id="325000" code="325000" name="Ostatní závazky"/>'
            . ($settled ? '<lst:itemAccount id="336000" code="336000" name="Zúčtování s institucemi SZ a ZP"/>'
                . '<lst:itemAccount id="341000" code="341000" name="Daň z příjmů"/>'
                . '<lst:itemAccount id="364000" code="364000" name="Závazky ke společníkům při rozdělování zisku"/>'
                . '<lst:itemAccount id="428000" code="428000" name="Nerozdělený zisk minulých let"/>'
                . '<lst:itemAccount id="513000" code="513000" name="Náklady na reprezentaci"/>'
                . '<lst:itemAccount id="524000" code="524000" name="Zákonné sociální pojištění"/>' : ''));
        if ($settled) {
            $append($dir . '/03_predkontace_pu.xml', '</lst:listAccounting>',
                '<lst:itemAccounting id="2" code="BEZ364000" accounting="Podíl na zisku společníka" debit="364000" agenda="commitment"/>'
                . '<lst:itemAccounting id="3" code="BEZ341000" accounting="DPPO" debit="341000" agenda="commitment"/>');
        }
        $append($dir . '/01_ucetni_denik.xml', '</lst:accountancy>',
            self::entry('Ostatní pohledávky', '26OP0001', 'Nájem', 1200, '315000', '602000', '2026-02-01')
            . self::entry('Ostatní závazky', '26OZ0001', 'Nájem', 800, '518000', '325000', '2026-02-02')
            . ($settled ? self::entry('Ostatní závazky', '26OZ0002', 'Reprezentace', 300, '513000', '325000', '2026-02-03')
                . self::entry('Ostatní závazky', '26OZM0001', 'Sociální pojištění', 500, '524000', '336000', '2026-02-04')
                . self::entry('Interní doklady', '26IN0001', 'Podíl na zisku', 900, '428000', '364000', '2026-02-05')
                . self::entry('Banka', 'BAN0010020', 'Úhrada 26OZ0002', 100, '325000', '221001', '2026-02-10')
                . self::entry('Banka', 'BAN0010021', 'Úhrada 26OZ0002', 200, '325000', '221001', '2026-02-12')
                . self::entry('Banka', 'BAN0010022', 'Úhrada podílu', 400, '364000', '221001', '2026-02-20')
                . self::entry('Banka', 'BAN0010023', 'Záloha daně', 1000, '341000', '221001', '2026-03-10') : ''));
        $document = static function (string $number, string $vat, float $amount, string $date, float $remaining, string $liquidations = '', string $text = 'Nájem', string $rule = '', string $due = '2026-03-01'): string {
            return '<lst:invoice version="2.0"><inv:invoiceHeader>'
                . '<inv:number><typ:numberRequested>' . $number . '</typ:numberRequested></inv:number>'
                . '<inv:date>' . $date . '</inv:date><inv:dateAccounting>' . $date . '</inv:dateAccounting>'
                . '<inv:dateDue>' . $due . '</inv:dateDue><inv:text>' . $text . '</inv:text>'
                . ($rule !== '' ? '<inv:accounting><typ:ids>' . $rule . '</typ:ids></inv:accounting>' : '')
                . '<inv:classificationVAT><typ:ids>' . $vat . '</typ:ids></inv:classificationVAT>'
                . '<inv:partnerIdentity><typ:address><typ:company>Pronajímatel Test s.r.o.</typ:company></typ:address></inv:partnerIdentity>'
                . '<inv:liquidation><typ:amountHome>' . $remaining . '</typ:amountHome></inv:liquidation>'
                . '</inv:invoiceHeader><inv:invoiceSummary><inv:homeCurrency>'
                . '<typ:priceNone>' . $amount . '</typ:priceNone><typ:priceLow>0</typ:priceLow>'
                . '<typ:priceLowVAT>0</typ:priceLowVAT><typ:priceHigh>0</typ:priceHigh>'
                . '<typ:priceHighVAT>0</typ:priceHighVAT></inv:homeCurrency></inv:invoiceSummary>'
                . $liquidations . '</lst:invoice>';
        };
        $liquidation = static fn (string $bank, string $date, float $amount): string =>
            '<typ:liquidation><typ:id>' . abs(crc32($bank . 'liq')) . '</typ:id><typ:date>' . $date . '</typ:date><typ:sourceAgenda>bank</typ:sourceAgenda>'
            . '<typ:sourceDocument><typ:id>' . abs(crc32($bank)) . '</typ:id><typ:number>' . $bank . '</typ:number></typ:sourceDocument>'
            . '<typ:amount>' . $amount . '</typ:amount></typ:liquidation>';
        self::file($dir . '/18_faktury_receivable.xml', '<lst:listInvoice version="2.0" state="ok">'
            . $document('26OP0001', 'UN', 1200, '2026-02-01', 1200) . '</lst:listInvoice>');
        self::file($dir . '/26_faktury_commitment.xml', '<lst:listInvoice version="2.0" state="ok">'
            . $document('26OZ0001', 'PN', 800, '2026-02-02', 800)
            . ($settled ? $document('26OZ0002', 'PN', 300, '2026-02-03', 0, '<inv:liquidations>'
                    . $liquidation('BAN0010020', '2026-02-10', 100) . $liquidation('BAN0010021', '2026-02-12', 200) . '</inv:liquidations>')
                . $document('26OZM0001', 'PN', 500, '2026-02-04', 500)
                . $document('26OZB0001', 'PN', 900, '2026-02-05', 500, '<inv:liquidations>'
                    . $liquidation('BAN0010022', '2026-02-20', 400) . '</inv:liquidations>', 'Podíl na zisku', 'BEZ364000')
                . $document('26OZD0001', 'PN', 1000, '2026-02-06', 0, '<inv:liquidations>'
                    . $liquidation('BAN0010023', '2026-03-10', 1000) . '</inv:liquidations>', 'Daň z příjmů (záloha) 1/4', 'BEZ341000', '2026-03-16')
                . $document('26OZD0002', 'PN', 2000, '2026-02-06', 2000, '', 'Odvod daně z příjmů - doplatek', 'BEZ341000', '2026-04-01') : '')
            . '</lst:listInvoice>');
        if ($settled) {
            $append($dir . '/29_banka.xml', '</lst:listBank>',
                self::bank('BAN0010020', 'expense', '2026-02-10', 100, '', 'Pronajímatel Test s.r.o.')
                . self::bank('BAN0010021', 'expense', '2026-02-12', 200, '', 'Pronajímatel Test s.r.o.')
                . self::bank('BAN0010022', 'expense', '2026-02-20', 400, '', 'Pronajímatel Test s.r.o.')
                . self::bank('BAN0010023', 'expense', '2026-03-10', 1000, '', 'Finanční úřad'));
        }
    }

    /** Ostatní závazek uhrazený v minulém roce ({@see withOtherItemSettlements()}). */
    public const OTHER_ITEM_PAID_EARLIER = '26OZP0001';
    /** Ostatní závazek uhrazený pokladnou ({@see withOtherItemSettlements()}). */
    public const OTHER_ITEM_PAID_BY_CASH = '26OZC0001';

    /**
     * Doplní agendu z {@see withOtherItems()} (`$settled = true`) o závazek, který POHODA vede
     * jako uhrazený bez pohybu v roce agendy, a o závazek uhrazený výdejovým pokladním dokladem.
     */
    public static function withOtherItemSettlements(string $dir): void
    {
        $item = static fn (string $number, float $amount, string $rule, string $liquidations): string =>
            '<lst:invoice version="2.0"><inv:invoiceHeader>'
            . '<inv:number><typ:numberRequested>' . $number . '</typ:numberRequested></inv:number>'
            . '<inv:date>2026-02-07</inv:date><inv:dateAccounting>2026-02-07</inv:dateAccounting><inv:dateDue>2026-03-01</inv:dateDue>'
            . '<inv:text>Pojistné</inv:text>' . ($rule !== '' ? '<inv:accounting><typ:ids>' . $rule . '</typ:ids></inv:accounting>' : '')
            . '<inv:classificationVAT><typ:ids>PN</typ:ids></inv:classificationVAT>'
            . '<inv:partnerIdentity><typ:address><typ:company>Pojišťovna Test a.s.</typ:company></typ:address></inv:partnerIdentity>'
            . '<inv:liquidation><typ:amountHome>0</typ:amountHome></inv:liquidation>'
            . '</inv:invoiceHeader><inv:invoiceSummary><inv:homeCurrency>'
            . '<typ:priceNone>' . $amount . '</typ:priceNone><typ:priceLow>0</typ:priceLow>'
            . '<typ:priceLowVAT>0</typ:priceLowVAT><typ:priceHigh>0</typ:priceHigh>'
            . '<typ:priceHighVAT>0</typ:priceHighVAT></inv:homeCurrency></inv:invoiceSummary>' . $liquidations . '</lst:invoice>';
        self::append($dir . '/26_faktury_commitment.xml', '</lst:listInvoice>',
            $item(self::OTHER_ITEM_PAID_EARLIER, 700, 'BEZ364000', '')
            . $item(self::OTHER_ITEM_PAID_BY_CASH, 250, '', '<inv:liquidations><typ:liquidation><typ:id>' . abs(crc32('26VP0001liq')) . '</typ:id>'
                . '<typ:date>2026-02-15</typ:date><typ:sourceAgenda>voucher</typ:sourceAgenda>'
                . '<typ:sourceDocument><typ:number>26VP0001</typ:number></typ:sourceDocument><typ:amount>250</typ:amount></typ:liquidation></inv:liquidations>'));
        self::append($dir . '/02_uctova_osnova.xml', '</lst:listAccount>', '<lst:itemAccount id="211000" code="211000" name="Pokladna"/>');
        self::append($dir . '/01_ucetni_denik.xml', '</lst:accountancy>',
            self::entry('Ostatní závazky', self::OTHER_ITEM_PAID_BY_CASH, 'Pojistné', 250, '518000', '325000', '2026-02-07')
            . self::entry('Pokladna', '26VP0001', 'Úhrada pojistného', 250, '325000', '211000', '2026-02-15'));
        self::file($dir . '/28_pokladna.xml', '<lst:listVoucher version="2.0" state="ok"><lst:voucher version="2.0"><vch:voucherHeader>'
            . '<vch:voucherType>expense</vch:voucherType><vch:cashAccount><typ:ids>HP</typ:ids></vch:cashAccount>'
            . '<vch:number><typ:numberRequested>26VP0001</typ:numberRequested></vch:number>'
            . '<vch:date>2026-02-15</vch:date><vch:datePayment>2026-02-15</vch:datePayment><vch:text>Úhrada pojistného</vch:text>'
            . '<vch:classificationVAT><typ:ids>PN</typ:ids></vch:classificationVAT>'
            . '</vch:voucherHeader><vch:voucherSummary><vch:homeCurrency><typ:priceNone>250</typ:priceNone>'
            . '<typ:priceLow>0</typ:priceLow><typ:priceLowVAT>0</typ:priceLowVAT><typ:priceHigh>0</typ:priceHigh><typ:priceHighVAT>0</typ:priceHighVAT>'
            . '</vch:homeCurrency></vch:voucherSummary></lst:voucher></lst:listVoucher>');
    }

    /** Přeplatek odběratele s protiúčtem a symbolem na 315.999 ({@see withOverpayments()}). */
    public const OVERPAYMENT_BANK = 'BAN0020001';

    /**
     * Pohyby zaúčtované přímo na analytiky přeplatků 315.999 a 325.999, bez dokladu v agendě
     * ostatních pohledávek a závazků:
     *   - 315.999: přeplatek 1 500 s protiúčtem a symbolem; platba a vrácení 700 téhož protiúčtu;
     *     přeplatek 100 zaúčtovaný vedle úhrady faktury na 311 (`BAN0010001`),
     *   - 325.999: naše chybná platba 900 jen s názvem a symbolem; příjem 400 a vrácení 100
     *     téhož názvu; pokladní příjem 350.
     */
    public static function withOverpayments(string $dir): void
    {
        self::overpaymentAccounts($dir);
        self::append($dir . '/01_ucetni_denik.xml', '</lst:accountancy>',
            self::entry('Banka', self::OVERPAYMENT_BANK, 'Přeplatek', 1500, '221001', '315999', '2026-03-02')
            . self::entry('Banka', 'BAN0020002', 'Chybná platba', 700, '221001', '315999', '2026-03-05')
            . self::entry('Banka', 'BAN0020003', 'Vrácení chybné platby', 700, '315999', '221001', '2026-03-09')
            . self::entry('Banka', 'BAN0010001', 'Přeplatek k faktuře', 100, '221001', '315999', '2026-01-15')
            . self::entry('Banka', 'BAN0020004', 'Chybná platba dodavateli', 900, '325999', '221001', '2026-03-12')
            . self::entry('Banka', 'BAN0020005', 'Příjem bez dokladu', 400, '221001', '325999', '2026-03-15')
            . self::entry('Banka', 'BAN0020006', 'Částečné vrácení', 100, '325999', '221001', '2026-03-20')
            . self::entry('Pokladna', '26PP0001', 'Přeplatek v hotovosti', 350, '211000', '325999', '2026-03-22'));
        self::append($dir . '/29_banka.xml', '</lst:listBank>',
            self::bank(self::OVERPAYMENT_BANK, 'receipt', '2026-03-02', 1500, '2026101', 'Odběratel Přeplatek s.r.o.', self::PARTNER_ACCOUNT)
            . self::bank('BAN0020002', 'receipt', '2026-03-05', 700, '', 'Omyl Test s.r.o.', self::OTHER_ACCOUNT)
            . self::bank('BAN0020003', 'expense', '2026-03-09', 700, '', 'Omyl Test s.r.o.', self::OTHER_ACCOUNT)
            . self::bank('BAN0020004', 'expense', '2026-03-12', 900, '2026102', 'Dodavatel Přeplatek s.r.o.')
            . self::bank('BAN0020005', 'receipt', '2026-03-15', 400, '', 'Vratka Test s.r.o.')
            . self::bank('BAN0020006', 'expense', '2026-03-20', 100, '', 'Vratka Test s.r.o.'));
        $bank = (string) file_get_contents($dir . '/29_banka.xml');
        file_put_contents($dir . '/29_banka.xml', preg_replace('~(<bnk:number>BAN0010001</bnk:number>.*?<typ:priceNone>)1210~s', '${1}1310', $bank, 1));
        self::file($dir . '/28_pokladna.xml', '<lst:listVoucher version="2.0" state="ok"><lst:voucher version="2.0"><vch:voucherHeader>'
            . '<vch:voucherType>receipt</vch:voucherType><vch:cashAccount><typ:ids>HP</typ:ids></vch:cashAccount>'
            . '<vch:number><typ:numberRequested>26PP0001</typ:numberRequested></vch:number>'
            . '<vch:date>2026-03-22</vch:date><vch:datePayment>2026-03-22</vch:datePayment><vch:text>Přeplatek v hotovosti</vch:text>'
            . '<vch:classificationVAT><typ:ids>UN</typ:ids></vch:classificationVAT>'
            . '<vch:partnerIdentity><typ:address><typ:company>Hotovost Test s.r.o.</typ:company></typ:address></vch:partnerIdentity>'
            . '</vch:voucherHeader><vch:voucherSummary><vch:homeCurrency><typ:priceNone>350</typ:priceNone>'
            . '<typ:priceLow>0</typ:priceLow><typ:priceLowVAT>0</typ:priceLowVAT><typ:priceHigh>0</typ:priceHigh><typ:priceHighVAT>0</typ:priceHighVAT>'
            . '</vch:homeCurrency></vch:voucherSummary></lst:voucher></lst:listVoucher>');
    }

    /** Pozdější vrácení přeplatku {@see OVERPAYMENT_BANK} (doplní se do novějšího exportu). */
    public static function withOverpaymentRefund(string $dir): void
    {
        self::append($dir . '/01_ucetni_denik.xml', '</lst:accountancy>',
            self::entry('Banka', 'BAN0020009', 'Vrácení přeplatku', 1500, '315999', '221001', '2026-06-01'));
        self::append($dir . '/29_banka.xml', '</lst:listBank>',
            self::bank('BAN0020009', 'expense', '2026-06-01', 1500, '', 'Odběratel Přeplatek s.r.o.', self::PARTNER_ACCOUNT));
    }

    /** Příjem 250 na 315.999 bez partnera, symbolu i protiúčtu. */
    public static function withUnclearOverpayment(string $dir): void
    {
        self::overpaymentAccounts($dir);
        self::append($dir . '/01_ucetni_denik.xml', '</lst:accountancy>',
            self::entry('Banka', 'BAN0020007', 'Neznámá platba', 250, '221001', '315999', '2026-03-25'));
        self::append($dir . '/29_banka.xml', '</lst:listBank>',
            self::bank('BAN0020007', 'receipt', '2026-03-25', 250, '', ''));
    }

    /**
     * Vrácení přeplatku z počátečního stavu: 1 000 na straně Dal 315.999 přinesl počáteční
     * stav, v roce je jen pohyb, který ho vrací. Účet má nulový zůstatek.
     */
    public static function withOpeningOverpaymentRefund(string $dir): void
    {
        self::overpaymentAccounts($dir);
        self::append($dir . '/01_ucetni_denik.xml', '</lst:accountancy>',
            self::entry('Počáteční stavy účtů', 'ZAV', 'Počáteční stav účtu', 1000, '701000', '315999', '2026-01-01')
            . self::entry('Počáteční stavy účtů', 'ZAV', 'Počáteční stav účtu', 1000, '411000', '701000', '2026-01-01')
            . self::entry('Banka', 'BAN0020008', 'Vrácení přeplatku z minulého roku', 1000, '315999', '221001', '2026-02-20'));
        self::append($dir . '/29_banka.xml', '</lst:listBank>',
            self::bank('BAN0020008', 'expense', '2026-02-20', 1000, '', 'Starý Přeplatek s.r.o.', self::PARTNER_ACCOUNT));
    }

    private static function overpaymentAccounts(string $dir): void
    {
        self::append($dir . '/02_uctova_osnova.xml', '</lst:listAccount>',
            '<lst:itemAccount id="211000" code="211000" name="Pokladna"/>'
            . '<lst:itemAccount id="315999" code="315999" name="Přeplatky odběratelů"/>'
            . '<lst:itemAccount id="325999" code="325999" name="Přeplatky dodavatelům"/>');
    }

    /**
     * Nezaúčtovaný pohyb v prosinci se stejným číslem jako zaúčtovaná úhrada z února: číselná
     * řada banky se opakuje, agenda vede oba. Vyžaduje {@see withOtherItems()} (`$settled = true`).
     */
    public static function withRepeatedBankNumber(string $dir): void
    {
        $bank = self::bank('BAN0010020', 'expense', '2026-12-20', 77, '', 'Dodavatel Test s.r.o.');
        self::append($dir . '/29_banka.xml', '</lst:listBank>',
            str_replace('<bnk:id>' . abs(crc32('BAN0010020')) . '</bnk:id>', '<bnk:id>999001</bnk:id>', $bank));
    }

    /**
     * Agenda z {@see write()} v osnově pro neziskové organizace: vlastní zdroje na 901
     * místo 411, jako má POHODA pro nevýdělečné organizace.
     */
    public static function withNonprofitChart(string $dir): void
    {
        foreach (['/02_uctova_osnova.xml', '/01_ucetni_denik.xml'] as $file) {
            $source = (string) file_get_contents($dir . $file);
            file_put_contents($dir . $file, str_replace(['411000', '701000', '568000'], ['901000', '961000', '549000'], $source));
        }
        $chart = (string) file_get_contents($dir . '/02_uctova_osnova.xml');
        if (!str_contains($chart, '901000')) {
            self::append($dir . '/02_uctova_osnova.xml', '</lst:listAccount>', '<lst:itemAccount id="901000" code="901000" name="Vlastní jmění"/>');
        }
    }

    /** Přijatá faktura částečně uhrazená v minulém roce ({@see withPurchasePaidEarlier()}). */
    public const PURCHASE_PAID_EARLIER = '25PF0099';

    /**
     * Přijatá faktura minulého roku z 1 000 Kč, z toho 600 Kč uhrazeno v minulém roce, zůstává 400 Kč.
     * Pohyb minulého roku v agendě není a v deníku roku agendy zápis nemá.
     */
    public static function withPurchasePaidEarlier(string $dir): void
    {
        self::append($dir . '/20_faktury_receivedInvoice.xml', '</lst:listInvoice>',
            '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>receivedInvoice</inv:invoiceType>'
            . '<inv:number><typ:numberRequested>' . self::PURCHASE_PAID_EARLIER . '</typ:numberRequested></inv:number>'
            . '<inv:symVar>2599</inv:symVar><inv:originalDocument>RE-2025-99</inv:originalDocument><inv:date>2025-11-15</inv:date><inv:dateTax>2025-11-15</inv:dateTax>'
            . '<inv:dateAccounting>2025-11-15</inv:dateAccounting><inv:dateDue>2025-12-01</inv:dateDue>'
            . '<inv:classificationVAT><typ:ids>PN</typ:ids></inv:classificationVAT><inv:text>Leasing</inv:text>'
            . '<inv:partnerIdentity><typ:address><typ:company>Leasing Test a.s.</typ:company></typ:address></inv:partnerIdentity>'
            . '<inv:liquidation><typ:amountHome>400</typ:amountHome></inv:liquidation></inv:invoiceHeader>'
            . '<inv:invoiceSummary><inv:homeCurrency><typ:priceNone>1000</typ:priceNone><typ:priceLow>0</typ:priceLow><typ:priceLowVAT>0</typ:priceLowVAT>'
            . '<typ:priceHigh>0</typ:priceHigh><typ:priceHighVAT>0</typ:priceHighVAT></inv:homeCurrency></inv:invoiceSummary>'
            . '<inv:liquidations><typ:liquidation><typ:id>' . abs(crc32('25PF0099liq')) . '</typ:id><typ:date>2025-12-10</typ:date><typ:sourceAgenda>bank</typ:sourceAgenda>'
            . '<typ:sourceDocument><typ:number>BAN9990001</typ:number></typ:sourceDocument><typ:amount>600</typ:amount></typ:liquidation></inv:liquidations></lst:invoice>');
    }

    /** Doklady, jejichž skupinu párovacího symbolu má POHODA vyrovnanou ({@see withClosedPairingGroups()}). */
    public const PAIRED_ISSUED = '26FV0090';
    public const PAIRED_PURCHASE = '26PF0090';

    /**
     * Vydaná a přijatá faktura z 1 210 Kč, které POHODA vede jako neuhrazené, ale v deníku je
     * vyrovnává interní doklad se stejným párovacím symbolem.
     */
    public static function withClosedPairingGroups(string $dir): void
    {
        $paired = static fn (string $symbol, string $entry): string => str_replace(
            '</act:accountingItem>',
            '<act:pairing><act:symbol>' . $symbol . '</act:symbol></act:pairing></act:accountingItem>',
            $entry,
        );
        self::append($dir . '/12_faktury_issuedInvoice.xml', '</lst:listInvoice>',
            self::invoice('issuedInvoice', self::PAIRED_ISSUED, '260090', '2026-04-01', 1000, 210));
        self::append($dir . '/20_faktury_receivedInvoice.xml', '</lst:listInvoice>',
            self::invoice('receivedInvoice', self::PAIRED_PURCHASE, '260091', '2026-04-01', 1000, 210));
        self::append($dir . '/01_ucetni_denik.xml', '</lst:accountancy>',
            $paired('260090', self::entry('Vydané faktury', self::PAIRED_ISSUED, 'Služby', 1210, '311001', '602000', '2026-04-01'))
            . $paired('260090', self::entry('Interní doklady', '26IN0090', 'Vyrovnání faktury', 1210, '518000', '311001', '2026-04-20'))
            . $paired('260091', self::entry('Přijaté faktury', self::PAIRED_PURCHASE, 'Materiál', 1210, '518000', '321001', '2026-04-01'))
            . $paired('260091', self::entry('Interní doklady', '26IN0091', 'Vyrovnání faktury', 1210, '321001', '602000', '2026-04-21')));
    }

    /** Zápis deníku se střediskem VYUKA, 500 Kč na 518000 / 321001. */
    public const CENTRE_DOCUMENT = '26IN0099';

    public static function withCostCentre(string $dir): void
    {
        self::withCentreEntry($dir, self::CENTRE_DOCUMENT, 'VYUKA', 'Výuka hry na nástroj', 'Učebny', 500, '518000', '321001');
    }

    /** Interní doklad deníku se střediskem na obou řádcích. */
    public static function withCentreEntry(string $dir, string $number, string $centre, string $centreName, string $text, float $amount, string $debit, string $credit): void
    {
        self::append($dir . '/01_ucetni_denik.xml', '</lst:accountancy>', str_replace(
            '</act:accountingItem>',
            '<act:centre><typ:ids>' . $centre . '</typ:ids><typ:name>' . $centreName . '</typ:name></act:centre></act:accountingItem>',
            self::entry('Interní doklady', $number, $text, $amount, $debit, $credit, '2026-03-05'),
        ));
    }

    /** Přeplatek odběratele bez dokladu: zápis 221/311 v deníku, ke kterému saldokonto nemá položku. */
    public const OVERPAYMENT_DOCUMENT = '26IN0098';

    public static function withOverpaymentWithoutDocument(string $dir, float $amount): void
    {
        self::append($dir . '/01_ucetni_denik.xml', '</lst:accountancy>',
            self::entry('Interní doklady', self::OVERPAYMENT_DOCUMENT, 'Přeplatek', $amount, '221001', '311001', '2026-03-06'));
    }

    /** Interní doklady s partnerem a druhem ({@see withInternalDocumentKinds()}). */
    public const INT_KNOWN_PARTNER = '26IN0201';
    public const INT_TWO_PARTNERS = '26IN0202';
    public const INT_UNKNOWN_PARTNER = '26IN0203';
    public const INT_FX = '26IN0204';
    public const INT_ACCRUAL = '26IN0205';
    public const INT_OFFSET = '26IN0206';
    public const INT_PAYROLL = '26IN0207';
    public const INT_OWN_PARTNER = '26IN0208';
    public const INT_UNKNOWN_NAME = 'Neznámý Partner Test s.r.o.';

    public static function withInternalDocumentKinds(string $dir): void
    {
        $entry = static function (string $number, string $text, float $amount, string $md, string $d, string $company = '', string $ico = ''): string {
            $address = $company === '' && $ico === '' ? '' : '<act:address>'
                . ($company !== '' ? '<typ:company>' . $company . '</typ:company>' : '')
                . ($ico !== '' ? '<typ:ico>' . $ico . '</typ:ico>' : '')
                . '</act:address>';
            return str_replace('</act:accountingItem>', $address . '</act:accountingItem>', self::entry('Interní doklady', $number, $text, $amount, $md, $d, '2026-03-10'));
        };
        self::append($dir . '/01_ucetni_denik.xml', '</lst:accountancy>',
            $entry(self::INT_KNOWN_PARTNER, 'Přeúčtování nákladů', 300, '518000', '321001', 'Dodavatel Test', self::VENDOR_ICO)
            . $entry(self::INT_KNOWN_PARTNER, 'Přeúčtování nákladů', 200, '518000', '321001')
            . $entry(self::INT_KNOWN_PARTNER, 'Přeúčtování nákladů', 100, '518000', '321001', 'Dodavatel Test', self::VENDOR_ICO)
            . $entry(self::INT_TWO_PARTNERS, 'Přeúčtování mezi partnery', 400, '311001', '321001', 'Dodavatel Test', self::VENDOR_ICO)
            . $entry(self::INT_TWO_PARTNERS, 'Přeúčtování mezi partnery', 100, '311001', '321001', 'Odběratel Test', self::CUSTOMER_ICO)
            . $entry(self::INT_UNKNOWN_PARTNER, 'Vratka', 150, '518000', '321001', self::INT_UNKNOWN_NAME)
            . $entry(self::INT_FX, 'Kurzový rozdíl', 12, '563000', '321001')
            . $entry(self::INT_ACCRUAL, 'Dohad energií', 800, '518000', '389000')
            . $entry(self::INT_OFFSET, 'Zápočet pohledávek', 250, '321001', '311001')
            . $entry(self::INT_PAYROLL, 'Mzdy 2026/03', 900, '521000', '389000')
            . $entry(self::INT_OWN_PARTNER, 'Vlastní doklad', 50, '518000', '321001', self::NAME, self::ICO));
    }

    /** Zápočet {@see OFFSET_DOCUMENT} s textem, který zápočet neprozradí: pozná se jen z úhrad dokladů. */
    public static function withOffsetTextHidden(string $agendaDir): void
    {
        $file = $agendaDir . '/01_ucetni_denik.xml';
        $from = array_map(static fn (string $text): string => (string) iconv('UTF-8', 'Windows-1250', $text), ['Zápočet faktury', 'Zápočet dobropisu']);
        file_put_contents($file, str_replace($from, 'Vyrovnani', (string) file_get_contents($file)));
    }

    /** Oprava odpočtu § 74b a její pozdější vrácení ({@see withDebtorDeductionCorrection()}). */
    public const S74B_REDUCTION = '26DD0074';
    public const S74B_RESTORATION = '26DD0075';

    /**
     * Agenda z {@see write()} s opravou odpočtu dlužníka podle § 74b: POHODA ji vede interním
     * dokladem s členěním `PNedpohl` (ř. 34, 40, 41) a číslem opravovaného dokladu v textu.
     * V květnu odpočet vrací kladnou daní, v červnu po úhradě znovu uplatňuje zápornou.
     *
     * @param array{0:float,1:float} $amounts základ a daň opravy
     */
    public static function withDebtorDeductionCorrection(string $dir, string $target, array $amounts): void
    {
        self::append($dir . '/06_cleneni_dph.xml', '</lst:listClassificationVAT>',
            '<lst:classificationVAT version="2.0"><vat:classificationVATHeader><vat:code>PNedpohl</vat:code>'
            . '<vat:name>Oprava základu daně u nedobytné pohledávky - dlužník</vat:name>'
            . '<vat:lineInVATReturn>34, 40, 41</vat:lineInVATReturn><vat:sectionInVATLedgerStatement>B.2.</vat:sectionInVATLedgerStatement>'
            . '</vat:classificationVATHeader></lst:classificationVAT>');
        $doc = static fn (string $number, string $date, float $sign, string $text): string =>
            '<lst:intDoc version="2.0"><int:intDocHeader><int:number><typ:numberRequested>' . $number . '</typ:numberRequested></int:number>'
            . '<int:date>' . $date . '</int:date><int:dateTax>' . $date . '</int:dateTax><int:dateAccounting>' . $date . '</int:dateAccounting>'
            . '<int:dateKHDPH>2026-01-10</int:dateKHDPH><int:classificationVAT><typ:ids>PNedpohl</typ:ids></int:classificationVAT>'
            . '<int:text>' . $text . ' ' . $target . ', datum splatnosti 01.02.2026</int:text></int:intDocHeader>'
            . '<int:intDocSummary><int:homeCurrency><typ:priceNone>0</typ:priceNone><typ:priceLow>0</typ:priceLow><typ:priceLowVAT>0</typ:priceLowVAT>'
            . '<typ:priceHigh>' . ($sign * $amounts[0]) . '</typ:priceHigh><typ:priceHighVAT rate="21">' . ($sign * $amounts[1]) . '</typ:priceHighVAT>'
            . '</int:homeCurrency></int:intDocSummary></lst:intDoc>';
        self::file($dir . '/27_interni_doklady.xml', '<lst:listIntDoc version="2.0" state="ok">'
            . $doc(self::S74B_REDUCTION, '2026-05-14', 1.0, 'Vrácení uplatněného odpočtu DPH u neuhrazeného závazku do 6 měsíců od splatnosti, ev. č. původně vystaveného dokladu')
            . $doc(self::S74B_RESTORATION, '2026-06-20', -1.0, 'Uplatnění odpočtu DPH z úhrady závazku po 6 měsících od splatnosti, ev. č. původního vystaveného dokladu')
            . '</lst:listIntDoc>');
    }

    /**
     * Agenda následujícího roku po agendě `write(..., unbooked: true)`: POHODA do ní převzala
     * doklady, které vedla už agenda minulého roku (vydaná faktura s úhradou a nezaúčtovaný
     * příjem), a přibyl nezaúčtovaný pohyb se stejným číslem jako zaúčtovaný poplatek minulého
     * roku (číselná řada banky se po letech opakuje).
     */
    public static function writeNextYear(string $root): string
    {
        $year = self::NEXT_YEAR;
        $dir = rtrim($root, '/\\') . '/' . self::ICO . '_' . $year;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        self::file($root . '/00_ucetni_jednotky.xml', '<acu:listAccountingUnit version="1.1"><acu:itemAccountingUnit><acu:unitType>doubleEntry</acu:unitType>'
            . '<acu:year>' . $year . '</acu:year><acu:unitIdentity><typ:address><typ:company>' . self::NAME . '</typ:company><typ:ico>' . self::ICO . '</typ:ico></typ:address></acu:unitIdentity>'
            . '<acu:dataFile>StwPh_' . self::ICO . '_' . $year . '.mdb</acu:dataFile></acu:itemAccountingUnit></acu:listAccountingUnit>');
        $all = self::files(unbooked: true);
        $journal = self::entry('Vydané faktury', self::NEXT_YEAR_ISSUED, 'Služby', 1000, '311001', '602000', $year . '-01-10')
            . self::entry('Vydané faktury', self::NEXT_YEAR_ISSUED, 'DPH', 210, '311001', '343021', $year . '-01-10')
            . self::entry('Banka', 'BAN0010016', 'Úhrada ' . self::NEXT_YEAR_ISSUED, 1210, '221001', '311001', $year . '-01-20');
        $files = [
            '01_ucetni_denik.xml' => '<lst:listAccountancy version="2.0" dateTimeStamp="' . $year . '-03-01T10:00:00" state="ok"><lst:accountancy version="2.0">' . $journal . '</lst:accountancy></lst:listAccountancy>',
            '12_faktury_issuedInvoice.xml' => '<lst:listInvoice version="2.0" state="ok">'
                . self::invoice('issuedInvoice', self::NEXT_YEAR_ISSUED, '260005', $year . '-01-10', 1000, 210, '', 'BAN0010016', 'BAN0010003') . '</lst:listInvoice>',
            '29_banka.xml' => '<lst:listBank version="2.0" state="ok">'
                . self::bank('BAN0010011', 'receipt', $year . '-01-15', 1815, self::UNBOOKED_ISSUED_VS, 'Odběratel Test s.r.o.', self::PARTNER_ACCOUNT)
                . self::bank('BAN0010016', 'receipt', $year . '-01-20', 1210, '260005', 'Odběratel Test s.r.o.', self::PARTNER_ACCOUNT)
                . self::bank('BAN0010003', 'expense', $year . '-02-01', 80, '', 'Testovací banka', self::OTHER_ACCOUNT) . '</lst:listBank>',
        ];
        foreach (['02_uctova_osnova.xml', '03_predkontace_pu.xml', '06_cleneni_dph.xml', '30_adresar.xml', '31_bankovni_ucty.xml'] as $name) {
            $files[$name] = $all[$name];
        }
        foreach ($files as $name => $body) {
            self::file($dir . '/' . $name, $body);
        }
        return $dir;
    }

    /**
     * Agenda z {@see write()} s kráceným odpočtem (§ 76): členění `PK` (ř. 40, 41) a přijatá
     * faktura 26PF0001 zařazená do něj místo `PD`.
     */
    public static function withReducedDeduction(string $agendaDir): void
    {
        $class = '<lst:classificationVAT version="2.0"><vat:classificationVATHeader><vat:code>PK</vat:code><vat:name>Tuzemsky odpocet kraceny</vat:name>'
            . '<vat:lineInVATReturn>40, 41</vat:lineInVATReturn><vat:sectionInVATLedgerStatement>B.2., B.3.</vat:sectionInVATLedgerStatement></vat:classificationVATHeader></lst:classificationVAT>';
        $classes = $agendaDir . '/06_cleneni_dph.xml';
        file_put_contents($classes, str_replace('</lst:listClassificationVAT>', $class . '</lst:listClassificationVAT>', (string) file_get_contents($classes)));
        $received = $agendaDir . '/20_faktury_receivedInvoice.xml';
        file_put_contents($received, str_replace('<typ:ids>PD</typ:ids>', '<typ:ids>PK</typ:ids>', (string) file_get_contents($received)));
    }

    /**
     * Agenda z {@see write()} s pořízením majetku: členění `PDM` (ř. 40, 41, 47) a přijatá
     * faktura 26PF0001 zařazená do něj místo `PD`.
     */
    public static function withFixedAssetPurchase(string $agendaDir): void
    {
        $class = '<lst:classificationVAT version="2.0"><vat:classificationVATHeader><vat:code>PDM</vat:code><vat:name>Tuzemsky odpocet porizeni majetku</vat:name>'
            . '<vat:lineInVATReturn>40, 41, 47</vat:lineInVATReturn><vat:sectionInVATLedgerStatement>B.2., B.3.</vat:sectionInVATLedgerStatement></vat:classificationVATHeader></lst:classificationVAT>';
        $classes = $agendaDir . '/06_cleneni_dph.xml';
        file_put_contents($classes, str_replace('</lst:listClassificationVAT>', $class . '</lst:listClassificationVAT>', (string) file_get_contents($classes)));
        $received = $agendaDir . '/20_faktury_receivedInvoice.xml';
        file_put_contents($received, str_replace('<typ:ids>PD</typ:ids>', '<typ:ids>PDM</typ:ids>', (string) file_get_contents($received)));
    }

    /** Číslo vydané faktury v tuzemském přenesení daňové povinnosti ({@see withDomesticReverseSale()}). */
    public const REVERSE_SALE = '26FV0009';

    /**
     * Agenda z {@see write()} s vydanou fakturou 26FV0009 na 1 000 Kč bez daně v členění
     * `UDpdp` (ř. 25, tuzemské přenesení daňové povinnosti) a jejím zápisem v deníku.
     */
    public static function withDomesticReverseSale(string $agendaDir): void
    {
        $append = static function (string $file, string $closing, string $xml): void {
            $content = (string) file_get_contents($file);
            file_put_contents($file, str_replace($closing, (string) iconv('UTF-8', 'Windows-1250', $xml) . $closing, $content));
        };
        $append($agendaDir . '/06_cleneni_dph.xml', '</lst:listClassificationVAT>',
            '<lst:classificationVAT version="2.0"><vat:classificationVATHeader><vat:code>UDpdp</vat:code><vat:name>Přenesení daňové povinnosti</vat:name>'
            . '<vat:lineInVATReturn>25</vat:lineInVATReturn><vat:sectionInVATLedgerStatement>A.1.</vat:sectionInVATLedgerStatement></vat:classificationVATHeader></lst:classificationVAT>');
        $append($agendaDir . '/12_faktury_issuedInvoice.xml', '</lst:listInvoice>',
            str_replace('<typ:ids>UD</typ:ids>', '<typ:ids>UDpdp</typ:ids>', self::invoice('issuedInvoice', self::REVERSE_SALE, '260009', '2026-01-18', 1000, 0)));
        $append($agendaDir . '/01_ucetni_denik.xml', '</lst:accountancy>',
            self::entry('Vydané faktury', self::REVERSE_SALE, 'Stavební práce', 1000, '311001', '602000', '2026-01-18'));
    }

    /** Přijatá faktura za službu z EU se samovyměřením ({@see withSelfAssessedForeignPurchase()}). */
    public const SELF_ASSESSED_PURCHASE = '26PF0010';
    public const SELF_ASSESSMENT_DOCUMENT = '26DD0001';

    /**
     * Agenda z {@see write()} s přijatou fakturou 26PF0010 od německého dodavatele za službu
     * (100 EUR, v Kč kurzem faktury 25,30 = 2 530 Kč, členění `PDslRegEU` ř. 43, 44) a interním
     * dokladem 26DD0001 s vyměřením daně (`DDslRegEU` ř. 5, 6) ze základu 2 500 Kč - kurz ke
     * dni plnění 25,00. Rozdíl 30 Kč je jen kurzový, daň z něj nevzniká.
     */
    public static function withSelfAssessedForeignPurchase(string $agendaDir): void
    {
        $append = static function (string $file, string $closing, string $xml): void {
            $content = (string) file_get_contents($file);
            file_put_contents($file, str_replace($closing, (string) iconv('UTF-8', 'Windows-1250', $xml) . $closing, $content));
        };
        $append($agendaDir . '/20_faktury_receivedInvoice.xml', '</lst:listInvoice>',
            '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>receivedInvoice</inv:invoiceType><inv:number><typ:numberRequested>' . self::SELF_ASSESSED_PURCHASE . '</typ:numberRequested></inv:number>'
            . '<inv:symVar>2026010</inv:symVar><inv:originalDocument>RE-2026-10</inv:originalDocument><inv:date>2026-02-15</inv:date><inv:dateTax>2026-02-15</inv:dateTax>'
            . '<inv:dateAccounting>2026-02-15</inv:dateAccounting><inv:dateDue>2026-03-01</inv:dateDue>'
            . '<inv:classificationVAT><typ:ids>PDslRegEU</typ:ids></inv:classificationVAT><inv:text>Licence software</inv:text>'
            . '<inv:partnerIdentity><typ:address><typ:company>Lieferant Test GmbH</typ:company><typ:city>Berlin</typ:city><typ:street>Teststraße 1</typ:street>'
            . '<typ:zip>10115</typ:zip><typ:country><typ:ids>DE</typ:ids></typ:country><typ:dic>DE999999999</typ:dic></typ:address></inv:partnerIdentity>'
            . '<inv:liquidation><typ:amountHome>2530</typ:amountHome></inv:liquidation></inv:invoiceHeader>'
            . '<inv:invoiceSummary><inv:homeCurrency><typ:priceNone>2530</typ:priceNone><typ:priceLow>0</typ:priceLow><typ:priceLowVAT rate="12">0</typ:priceLowVAT>'
            . '<typ:priceLowSum>0</typ:priceLowSum><typ:priceHigh>0</typ:priceHigh><typ:priceHighVAT rate="21">0</typ:priceHighVAT><typ:priceHighSum>0</typ:priceHighSum>'
            . '<typ:round><typ:priceRound>0</typ:priceRound></typ:round></inv:homeCurrency>'
            . '<inv:foreignCurrency><typ:currency><typ:ids>EUR</typ:ids></typ:currency><typ:rate>25.3</typ:rate><typ:priceSum>100</typ:priceSum></inv:foreignCurrency></inv:invoiceSummary></lst:invoice>');
        self::file($agendaDir . '/27_interni_doklady.xml', '<lst:listIntDoc version="2.0" state="ok"><lst:intDoc version="2.0"><int:intDocHeader>'
            . '<int:number><typ:numberRequested>' . self::SELF_ASSESSMENT_DOCUMENT . '</typ:numberRequested></int:number>'
            . '<int:date>2026-02-15</int:date><int:dateTax>2026-02-15</int:dateTax><int:dateAccounting>2026-02-15</int:dateAccounting>'
            . '<int:classificationVAT><typ:ids>DDslRegEU</typ:ids></int:classificationVAT><int:text>Vyměření DPH - služba z EU</int:text></int:intDocHeader>'
            . '<int:intDocDetail><int:intDocItem><int:text>Licence software</int:text><int:rateVAT value="21">high</int:rateVAT>'
            . '<int:homeCurrency><typ:price>2500</typ:price><typ:priceVAT>525</typ:priceVAT></int:homeCurrency></int:intDocItem></int:intDocDetail>'
            . '<int:linkedDocuments><typ:link><typ:sourceAgenda>receivedInvoice</typ:sourceAgenda><typ:sourceDocument><typ:number>' . self::SELF_ASSESSED_PURCHASE . '</typ:number></typ:sourceDocument></typ:link></int:linkedDocuments>'
            . '</lst:intDoc></lst:listIntDoc>');
        $append($agendaDir . '/01_ucetni_denik.xml', '</lst:accountancy>',
            self::entry('Přijaté faktury', self::SELF_ASSESSED_PURCHASE, 'Licence software', 2530, '518000', '321001', '2026-02-15')
            . self::entry('Interní doklady', self::SELF_ASSESSMENT_DOCUMENT, 'DPH samovyměření', 525, '343011', '343021', '2026-02-15'));
    }

    /** Faktury se samovyměřením jen na interních dokladech ({@see withSelfAssessmentByInternalDocuments()}). */
    public const PAIR_SERVICE = '26PF0030';
    public const PAIR_MIXED = '26PF0031';
    public const PAIR_NO_DEDUCTION = '26PF0032';

    /**
     * Agenda z {@see write()} s fakturami, které samy členění samovyměření nemají. Výstup
     * i odpočet vede POHODA dvojicí interních dokladů navázaných na fakturu:
     *
     *  - 26PF0030: služba z EU 2 530 Kč v členění `PN`, vyměření 26DD0002 (`DDslRegEU`
     *    ř. 5, 6) a odpočet 26DD0003 (`PDslRegEU` ř. 43, 44) ze základu 2 500 Kč;
     *  - 26PF0031: tuzemská smíšená faktura v členění `PD` - zboží v přenesení daňové
     *    povinnosti 10 000 Kč bez daně (řádek `PN`) a služba 1 000 + 210 Kč, vyměření
     *    26DD0004 (`DDpdp` ř. 10, 11) a odpočet 26DD0005 (`PDpdp` ř. 43, 44);
     *  - 26PF0032: služba z EU 1 000 Kč v `PN` jen s vyměřením 26DD0006, bez odpočtu.
     */
    public static function withSelfAssessmentByInternalDocuments(string $agendaDir): void
    {
        self::append($agendaDir . '/06_cleneni_dph.xml', '</lst:listClassificationVAT>',
            '<lst:classificationVAT version="2.0"><vat:classificationVATHeader><vat:code>DDpdp</vat:code><vat:name>Přenesení daňové povinnosti, odběratel</vat:name>'
            . '<vat:lineInVATReturn>10, 11</vat:lineInVATReturn><vat:sectionInVATLedgerStatement>B.1.</vat:sectionInVATLedgerStatement></vat:classificationVATHeader></lst:classificationVAT>'
            . '<lst:classificationVAT version="2.0"><vat:classificationVATHeader><vat:code>PDpdp</vat:code><vat:name>Přenesení daňové povinnosti, odpočet</vat:name>'
            . '<vat:lineInVATReturn>43, 44</vat:lineInVATReturn><vat:sectionInVATLedgerStatement>Nezahrnovat</vat:sectionInVATLedgerStatement></vat:classificationVATHeader></lst:classificationVAT>');
        $foreign = '<inv:partnerIdentity><typ:address><typ:company>Lieferant Test GmbH</typ:company><typ:city>Berlin</typ:city><typ:street>Teststraße 1</typ:street>'
            . '<typ:zip>10115</typ:zip><typ:country><typ:ids>DE</typ:ids></typ:country><typ:dic>DE999999999</typ:dic></typ:address></inv:partnerIdentity>';
        $invoice = static fn (string $number, string $class, string $partner, float $none, float $high, float $vat, string $items = ''): string =>
            '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>receivedInvoice</inv:invoiceType><inv:number><typ:numberRequested>' . $number . '</typ:numberRequested></inv:number>'
            . '<inv:symVar>' . substr($number, -4) . '</inv:symVar><inv:originalDocument>D-' . $number . '</inv:originalDocument><inv:date>2026-02-16</inv:date><inv:dateTax>2026-02-16</inv:dateTax>'
            . '<inv:dateAccounting>2026-02-16</inv:dateAccounting><inv:dateDue>2026-03-02</inv:dateDue>'
            . '<inv:classificationVAT><typ:ids>' . $class . '</typ:ids></inv:classificationVAT><inv:text>Nákup ' . $number . '</inv:text>' . $partner
            . '<inv:liquidation><typ:amountHome>' . ($none + $high + $vat) . '</typ:amountHome></inv:liquidation></inv:invoiceHeader>' . $items
            . '<inv:invoiceSummary><inv:homeCurrency><typ:priceNone>' . $none . '</typ:priceNone><typ:priceLow>0</typ:priceLow><typ:priceLowVAT rate="12">0</typ:priceLowVAT>'
            . '<typ:priceLowSum>0</typ:priceLowSum><typ:priceHigh>' . $high . '</typ:priceHigh><typ:priceHighVAT rate="21">' . $vat . '</typ:priceHighVAT><typ:priceHighSum>' . ($high + $vat) . '</typ:priceHighSum>'
            . '<typ:round><typ:priceRound>0</typ:priceRound></typ:round></inv:homeCurrency></inv:invoiceSummary></lst:invoice>';
        $item = static fn (string $text, float $base, float $vat, string $rate, string $class): string =>
            '<inv:invoiceItem><inv:text>' . $text . '</inv:text><inv:quantity>1</inv:quantity><inv:rateVAT>' . $rate . '</inv:rateVAT>'
            . '<inv:homeCurrency><typ:unitPrice>' . $base . '</typ:unitPrice><typ:price>' . $base . '</typ:price><typ:priceVAT>' . $vat . '</typ:priceVAT><typ:priceSum>' . ($base + $vat) . '</typ:priceSum></inv:homeCurrency>'
            . ($class !== '' ? '<inv:classificationVAT><typ:ids>' . $class . '</typ:ids></inv:classificationVAT>' : '') . '</inv:invoiceItem>';
        self::append($agendaDir . '/20_faktury_receivedInvoice.xml', '</lst:listInvoice>',
            $invoice(self::PAIR_SERVICE, 'PN', $foreign, 2530, 0, 0)
            . $invoice(self::PAIR_MIXED, 'PD', self::partner('inv', 'Dodavatel Test s.r.o.', self::VENDOR_ICO, 'CZ' . self::VENDOR_ICO), 10000, 1000, 210,
                '<inv:invoiceDetail>' . $item('Zboží v přenesení daňové povinnosti', 10000, 0, 'none', 'PN') . $item('Doprava', 1000, 210, 'high', '') . '</inv:invoiceDetail>')
            . $invoice(self::PAIR_NO_DEDUCTION, 'PN', $foreign, 1000, 0, 0));
        $internal = static fn (string $number, string $class, string $invoice, float $base, float $vat): string =>
            '<lst:intDoc version="2.0"><int:intDocHeader><int:number><typ:numberRequested>' . $number . '</typ:numberRequested></int:number>'
            . '<int:date>2026-02-16</int:date><int:dateTax>2026-02-16</int:dateTax><int:dateAccounting>2026-02-16</int:dateAccounting>'
            . '<int:classificationVAT><typ:ids>' . $class . '</typ:ids></int:classificationVAT><int:text>DPH k ' . $invoice . '</int:text></int:intDocHeader>'
            . '<int:intDocDetail><int:intDocItem><int:text>DPH k ' . $invoice . '</int:text><int:rateVAT value="21">high</int:rateVAT>'
            . '<int:homeCurrency><typ:price>' . $base . '</typ:price><typ:priceVAT>' . $vat . '</typ:priceVAT></int:homeCurrency></int:intDocItem></int:intDocDetail>'
            . '<int:linkedDocuments><typ:link><typ:sourceAgenda>receivedInvoice</typ:sourceAgenda><typ:sourceDocument><typ:number>' . $invoice . '</typ:number></typ:sourceDocument></typ:link></int:linkedDocuments></lst:intDoc>';
        self::file($agendaDir . '/27_interni_doklady.xml', '<lst:listIntDoc version="2.0" state="ok">'
            . $internal('26DD0002', 'DDslRegEU', self::PAIR_SERVICE, 2500, 525) . $internal('26DD0003', 'PDslRegEU', self::PAIR_SERVICE, 2500, 525)
            . $internal('26DD0004', 'DDpdp', self::PAIR_MIXED, 10000, 2100) . $internal('26DD0005', 'PDpdp', self::PAIR_MIXED, 10000, 2100)
            . $internal('26DD0006', 'DDslRegEU', self::PAIR_NO_DEDUCTION, 1000, 210) . '</lst:listIntDoc>');
        self::append($agendaDir . '/01_ucetni_denik.xml', '</lst:accountancy>',
            self::entry('Přijaté faktury', self::PAIR_SERVICE, 'Služba z EU', 2530, '518000', '321001', '2026-02-16')
            . self::entry('Přijaté faktury', self::PAIR_MIXED, 'Zboží a doprava', 11000, '518000', '321001', '2026-02-16')
            . self::entry('Přijaté faktury', self::PAIR_MIXED, 'DPH', 210, '343011', '321001', '2026-02-16')
            . self::entry('Přijaté faktury', self::PAIR_NO_DEDUCTION, 'Služba z EU', 1000, '518000', '321001', '2026-02-16')
            . self::entry('Interní doklady', '26DD0002', 'DPH samovyměření', 525, '343011', '343021', '2026-02-16')
            . self::entry('Interní doklady', '26DD0004', 'DPH samovyměření', 2100, '343011', '343021', '2026-02-16')
            . self::entry('Interní doklady', '26DD0006', 'DPH samovyměření', 210, '518000', '343021', '2026-02-16'));
    }

    /** Doklady v EUR ({@see withForeignCurrencyDocuments()}), kurz faktur 25,12 Kč/EUR. */
    public const FOREIGN_ISSUED = '26FV0020';
    public const FOREIGN_ISSUED_VS = '260020';
    public const FOREIGN_MISMATCH = '26FV0021';
    public const FOREIGN_PURCHASE = '26PF0020';
    public const FOREIGN_PURCHASE_BANK = 'BAN0010021';
    public const FOREIGN_RECEIPT = 'BAN0010020';
    public const FOREIGN_RATE = 25.12;

    /**
     * Agenda z {@see write()} s doklady v EUR tak, jak je vede POHODA: částky položek v EUR
     * (`foreignCurrency`) i v Kč (`homeCurrency`), rekapitulace v Kč, v deníku Kč.
     *
     *  - 26FV0020: 2 položky, 100 + 21 EUR a 40 + 8,40 EUR, v Kč 2 512 + 527,52 a 1 004,80 + 211,01
     *    (přesně kurzem 25,12), celkem 169,40 EUR = 4 255,33 Kč, neuhrazená;
     *  - 26FV0021: 10 + 2,10 EUR, daň v Kč 52,76 místo 52,75 (POHODA ji spočítala jinak než
     *    přepočtem daně v EUR) - kurzem nevyjde, převod ji musí nechat v Kč;
     *  - 26PF0020: přijatá 200 + 42 EUR = 5 024 + 1 055,04 Kč, uhrazená bankou 6 079,04 Kč.
     */
    public static function withForeignCurrencyDocuments(string $agendaDir): void
    {
        $item = static fn (string $text, float $qty, float $base, float $vat, float $foreignBase, float $foreignVat): string =>
            '<inv:invoiceItem><inv:text>' . $text . '</inv:text><inv:quantity>' . $qty . '</inv:quantity><inv:rateVAT value="21">high</inv:rateVAT>'
            . '<inv:homeCurrency><typ:unitPrice>' . round($base / $qty, 2) . '</typ:unitPrice><typ:price>' . $base . '</typ:price><typ:priceVAT>' . $vat . '</typ:priceVAT><typ:priceSum>' . round($base + $vat, 2) . '</typ:priceSum></inv:homeCurrency>'
            . '<inv:foreignCurrency><typ:unitPrice>' . round($foreignBase / $qty, 2) . '</typ:unitPrice><typ:price>' . $foreignBase . '</typ:price><typ:priceVAT>' . $foreignVat . '</typ:priceVAT><typ:priceSum>' . round($foreignBase + $foreignVat, 2) . '</typ:priceSum></inv:foreignCurrency></inv:invoiceItem>';
        $document = static fn (string $type, string $number, string $vs, string $date, string $items, float $base, float $vat, float $foreignTotal, string $liquidation, string $extra = ''): string =>
            '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>' . $type . '</inv:invoiceType><inv:number><typ:numberRequested>' . $number . '</typ:numberRequested></inv:number>'
            . '<inv:symVar>' . $vs . '</inv:symVar>' . ($type === 'receivedInvoice' ? '<inv:originalDocument>D-' . $number . '</inv:originalDocument>' : '')
            . '<inv:date>' . $date . '</inv:date><inv:dateTax>' . $date . '</inv:dateTax><inv:dateAccounting>' . $date . '</inv:dateAccounting><inv:dateDue>' . $date . '</inv:dateDue>'
            . '<inv:classificationVAT><typ:ids>' . ($type === 'issuedInvoice' ? 'UD' : 'PD') . '</typ:ids></inv:classificationVAT><inv:text>Služby v EUR</inv:text>'
            . ($type === 'issuedInvoice' ? self::partner('inv', 'Odběratel Test s.r.o.', self::CUSTOMER_ICO, 'CZ' . self::CUSTOMER_ICO) : self::partner('inv', 'Dodavatel Test s.r.o.', self::VENDOR_ICO, 'CZ' . self::VENDOR_ICO))
            . '<inv:paymentType><typ:paymentType>draft</typ:paymentType></inv:paymentType>' . $liquidation . '</inv:invoiceHeader>'
            . '<inv:invoiceDetail>' . $items . '</inv:invoiceDetail>'
            . '<inv:invoiceSummary><inv:homeCurrency><typ:priceNone>0</typ:priceNone><typ:priceLow>0</typ:priceLow><typ:priceLowVAT rate="12">0</typ:priceLowVAT>'
            . '<typ:priceLowSum>0</typ:priceLowSum><typ:priceHigh>' . $base . '</typ:priceHigh><typ:priceHighVAT rate="21">' . $vat . '</typ:priceHighVAT>'
            . '<typ:priceHighSum>' . round($base + $vat, 2) . '</typ:priceHighSum><typ:round><typ:priceRound>0</typ:priceRound></typ:round></inv:homeCurrency>'
            . '<inv:foreignCurrency><typ:currency><typ:ids>EUR</typ:ids></typ:currency><typ:rate>' . self::FOREIGN_RATE . '</typ:rate><typ:amount>1</typ:amount>'
            . '<typ:priceSum>' . $foreignTotal . '</typ:priceSum></inv:foreignCurrency></inv:invoiceSummary>' . $extra . '</lst:invoice>';

        self::append($agendaDir . '/12_faktury_issuedInvoice.xml', '</lst:listInvoice>',
            $document('issuedInvoice', self::FOREIGN_ISSUED, self::FOREIGN_ISSUED_VS, '2026-02-20',
                $item('Konzultace', 2.0, 2512.0, 527.52, 100.0, 21.0) . $item('Licence', 1.0, 1004.8, 211.01, 40.0, 8.4),
                3516.8, 738.53, 169.4, '<inv:liquidation><typ:amountHome>4255.33</typ:amountHome></inv:liquidation>')
            . $document('issuedInvoice', self::FOREIGN_MISMATCH, '260021', '2026-02-21',
                $item('Podpora', 1.0, 251.2, 52.76, 10.0, 2.1), 251.2, 52.76, 12.1, '<inv:liquidation><typ:amountHome>303.96</typ:amountHome></inv:liquidation>'));
        self::append($agendaDir . '/20_faktury_receivedInvoice.xml', '</lst:listInvoice>',
            $document('receivedInvoice', self::FOREIGN_PURCHASE, '2026020', '2026-02-22',
                $item('Vývoj', 1.0, 5024.0, 1055.04, 200.0, 42.0), 5024.0, 1055.04, 242.0,
                '<inv:liquidation><typ:amountHome>0</typ:amountHome><typ:date>2026-02-25</typ:date></inv:liquidation>',
                '<inv:liquidations><typ:liquidation><typ:id>' . abs(crc32(self::FOREIGN_PURCHASE)) . '</typ:id><typ:date>2026-02-25</typ:date><typ:sourceAgenda>bank</typ:sourceAgenda>'
                    . '<typ:sourceDocument><typ:id>' . abs(crc32(self::FOREIGN_PURCHASE_BANK)) . '</typ:id><typ:number>' . self::FOREIGN_PURCHASE_BANK . '</typ:number></typ:sourceDocument>'
                    . '<typ:amount>6079.04</typ:amount></typ:liquidation></inv:liquidations>'));
        self::append($agendaDir . '/29_banka.xml', '</lst:listBank>',
            self::bank(self::FOREIGN_PURCHASE_BANK, 'expense', '2026-02-25', 6079.04, '2026020', 'Dodavatel Test s.r.o.', self::PARTNER_ACCOUNT));
        self::append($agendaDir . '/01_ucetni_denik.xml', '</lst:accountancy>',
            self::entry('Vydané faktury', self::FOREIGN_ISSUED, 'Služby v EUR', 3516.8, '311001', '602000', '2026-02-20')
            . self::entry('Vydané faktury', self::FOREIGN_ISSUED, 'DPH', 738.53, '311001', '343021', '2026-02-20')
            . self::entry('Vydané faktury', self::FOREIGN_MISMATCH, 'Služby v EUR', 251.2, '311001', '602000', '2026-02-21')
            . self::entry('Vydané faktury', self::FOREIGN_MISMATCH, 'DPH', 52.76, '311001', '343021', '2026-02-21')
            . self::entry('Přijaté faktury', self::FOREIGN_PURCHASE, 'Vývoj', 5024, '518000', '321001', '2026-02-22')
            . self::entry('Přijaté faktury', self::FOREIGN_PURCHASE, 'DPH', 1055.04, '343011', '321001', '2026-02-22')
            . self::entry('Banka', self::FOREIGN_PURCHASE_BANK, 'Úhrada ' . self::FOREIGN_PURCHASE, 6079.04, '321001', '221001', '2026-02-25'));
    }

    /**
     * Příjem 4 255,33 Kč s VS faktury 26FV0020 bez zaúčtování v POHODĚ (předkontace „Nevím")
     * - převod ho spáruje s fakturou v EUR podle VS a částky v Kč.
     */
    public static function withForeignCurrencyReceipt(string $agendaDir): void
    {
        self::append($agendaDir . '/29_banka.xml', '</lst:listBank>',
            self::bank(self::FOREIGN_RECEIPT, 'receipt', '2026-03-10', 4255.33, self::FOREIGN_ISSUED_VS, 'Odběratel Test s.r.o.', self::PARTNER_ACCOUNT));
    }

    /** Odpočet uplatněný před datem plnění ({@see withEarlyClaims()}). */
    public const EARLY_CLAIM_PREVIOUS = '25PF0900';
    public const EARLY_CLAIM_CURRENT = '26PF0050';

    /**
     * Přijaté faktury, u kterých POHODA uplatnila odpočet (datum pro KH) dřív než k datu
     * plnění, obě 1 000 + 210 Kč:
     *  - 25PF0900: doklad minulého období (vystaven a uplatněn 10. 12. 2025, plnění
     *    1. 1. 2026), DPH je podané v přiznání agendy 2025;
     *  - 26PF0050: vystaven a uplatněn 20. 1. 2026, plnění až 1. 3. 2026.
     */
    public static function withEarlyClaims(string $agendaDir): void
    {
        $early = static fn (string $number, string $date, string $supply): string =>
            str_replace('<inv:dateTax>' . $date . '</inv:dateTax>', '<inv:dateTax>' . $supply . '</inv:dateTax>',
                self::invoice('receivedInvoice', $number, substr($number, -4), $date, 1000, 210));
        self::append($agendaDir . '/20_faktury_receivedInvoice.xml', '</lst:listInvoice>',
            $early(self::EARLY_CLAIM_PREVIOUS, '2025-12-10', '2026-01-01') . $early(self::EARLY_CLAIM_CURRENT, '2026-01-20', '2026-03-01'));
        self::append($agendaDir . '/01_ucetni_denik.xml', '</lst:accountancy>',
            self::entry('Přijaté faktury', self::EARLY_CLAIM_CURRENT, 'Služby', 1000, '518000', '321001', '2026-01-20')
            . self::entry('Přijaté faktury', self::EARLY_CLAIM_CURRENT, 'DPH', 210, '343011', '321001', '2026-01-20'));
    }

    /** Úhrada přes platební bránu ({@see withPaymentGateway()}). */
    public const GATEWAY_INVOICE = '26FV0040';
    public const GATEWAY_BANK = 'BAN0010040';

    /**
     * Faktura 26FV0040 (1 000 + 210 Kč) uhrazená přes platební bránu tak, jak ji vede POHODA:
     * dva bankovní doklady se stejným číslem BAN0010040 a datem - nejdřív poplatek brány
     * 20 Kč, pak příjem 1 210 Kč, kterým je faktura zlikvidovaná. V deníku mají oba doklady
     * zápis pod týmž číslem.
     */
    public static function withPaymentGateway(string $agendaDir): void
    {
        $doc = static fn (string $id, string $type, float $amount, string $vs): string =>
            str_replace('<bnk:id>' . abs(crc32(self::GATEWAY_BANK)) . '</bnk:id>', '<bnk:id>' . abs(crc32($id)) . '</bnk:id>',
                self::bank(self::GATEWAY_BANK, $type, '2026-03-05', $amount, $vs, 'Platební brána Test'));
        self::append($agendaDir . '/12_faktury_issuedInvoice.xml', '</lst:listInvoice>',
            self::invoice('issuedInvoice', self::GATEWAY_INVOICE, '260040', '2026-03-01', 1000, 210, '', 'GW-PAY', self::GATEWAY_BANK));
        self::append($agendaDir . '/29_banka.xml', '</lst:listBank>',
            $doc('GW-FEE', 'expense', 20, '') . $doc('GW-PAY', 'receipt', 1210, '260040'));
        self::append($agendaDir . '/01_ucetni_denik.xml', '</lst:accountancy>',
            self::entry('Vydané faktury', self::GATEWAY_INVOICE, 'Služby', 1000, '311001', '602000', '2026-03-01')
            . self::entry('Vydané faktury', self::GATEWAY_INVOICE, 'DPH', 210, '311001', '343021', '2026-03-01')
            . self::entry('Banka', self::GATEWAY_BANK, 'Poplatek brány', 20, '568000', '221001', '2026-03-05')
            . self::entry('Banka', self::GATEWAY_BANK, 'Úhrada ' . self::GATEWAY_INVOICE, 1210, '221001', '311001', '2026-03-05'));
    }

    /** Totéž bez částek v cizí měně - doklady, jak by je převod převzal v Kč. */
    public static function withoutForeignAmounts(string $agendaDir): void
    {
        foreach (['12_faktury_issuedInvoice.xml', '20_faktury_receivedInvoice.xml'] as $name) {
            $file = $agendaDir . '/' . $name;
            file_put_contents($file, (string) preg_replace('~<inv:foreignCurrency>.*?</inv:foreignCurrency>~s', '', (string) file_get_contents($file)));
        }
    }

    /** Doklady pro vazby záloh, zápočtů a kurzových rozdílů ({@see withAdvancesAndSettlements()}). */
    public const LINK_ADVANCE = '26ZP0001';
    public const LINK_ADVANCE_FALLBACK = '26ZP0002';
    public const LINK_ADVANCE_PAIRED = '26ZP0003';
    public const LINK_ADVANCE_SPLIT = '26ZP0004';
    public const REFUND_ADVANCE = '26ZP0005';
    public const FIFO_ADVANCES = ['26ZP0006', '26ZP0007'];
    public const LINK_FINAL = '26PF0101';
    public const LINK_FINAL_MULTI = '26PF0102';
    public const LINK_FINAL_SPLIT = ['26PF0103', '26PF0104'];
    public const FIFO_FINAL = '26PF0105';
    public const FX_PURCHASE = '26PF0106';
    public const FX_ISSUED = '26FV0101';
    public const OFFSET_INVOICE = '26FV0102';
    public const OFFSET_CREDIT_NOTE = '26DV0001';
    public const OFFSET_DOCUMENT = 'ZAP0001';
    public const TAX_DOCUMENT_LINKED = '26ZD0001';
    public const TAX_DOCUMENT_BY_TEXT = '26ZD0002';
    public const TAX_DOCUMENT_WRONG_AMOUNT = '26ZD0003';
    public const REFUND_VENDOR = 'Vratka Test s.r.o.';
    public const REFUND_ADVANCE_BY_VS = '26ZP0008';
    public const REFUND_VENDOR_BY_VS = 'Vratka VS Test s.r.o.';
    public const FIFO_VENDOR = 'Pronajímatel Test s.r.o.';

    /**
     * Agenda z {@see write()} s čerpáním záloh, zápočty interním dokladem a kurzovými rozdíly
     * v zápisu úhrady (zdroje vazeb {@see \MyInvoice\Service\Migration\Pohoda\DocumentLinker}):
     *
     *  - zálohy 26ZP0001 až 26ZP0004 (neuhrazené, Dodavatel Test): 26PF0101 odečítá položkou
     *    odpočtu jedinou zálohu 26ZP0001 (vazba na dokladu), 26PF0102 dvě zálohy 26ZP0002
     *    a 26ZP0003, záloha 26ZP0004 se čerpá postupně fakturami 26PF0103 a 26PF0104 (400 + 400);
     *  - DDKP (interní doklady v členění PD): 26ZD0001 s vazbou na 26ZP0001, 26ZD0002 jen
     *    s textem „…zálohové faktuře č. 26ZP0002" (stejný partner i částka jako záloha),
     *    26ZD0003 s textem o 26ZP0003, ale jinou částkou;
     *  - záloha 26ZP0005 (1 000, Vratka Test, doklad dodavatele = VS 2026105) zaplacená na 314
     *    a příchozí platba 300 s jejím VS zaúčtovaná na 314 bez vazby (vrácení zálohy);
     *    záloha 26ZP0008 (400, Vratka VS Test) totéž, jen doklad dodavatele `ZF-2026-8` nese
     *    jiné číslo než VS 2026108, se kterým přišlo vrácení celé zálohy;
     *  - zálohy 26ZP0006 a 26ZP0007 (po 500, Pronajímatel Test) zaplacené na 314 a faktura
     *    26PF0105, která je odečítá obyčejnou položkou „Odečet zálohy" 700 (kredit 314);
     *  - 26PF0106 (1 210) uhrazená pohybem 1 200, kurzový zisk 10 v zápisu úhrady (321/663);
     *    26FV0101 (1 210) uhrazená příjmem 1 200, kurzová ztráta 10 v zápisu úhrady (563/311);
     *  - 26FV0102 (1 210) a dobropis 26DV0001 (-1 210) vyrovnané interním dokladem ZAP0001.
     */
    public static function withAdvancesAndSettlements(string $agendaDir): void
    {
        $vendor = self::partner('inv', 'Dodavatel Test s.r.o.', self::VENDOR_ICO, 'CZ' . self::VENDOR_ICO);
        $customer = self::partner('inv', 'Odběratel Test s.r.o.', self::CUSTOMER_ICO, 'CZ' . self::CUSTOMER_ICO);
        $named = static fn (string $ns, string $name): string =>
            '<' . $ns . ':partnerIdentity><typ:address><typ:company>' . $name . '</typ:company><typ:city>Brno</typ:city></typ:address></' . $ns . ':partnerIdentity>';
        $liquidation = static fn (string $agenda, string $source, string $date, float $amount): string =>
            '<typ:liquidation><typ:id>' . abs(crc32($source . $date . $amount . $agenda)) . '</typ:id><typ:date>' . $date . '</typ:date><typ:sourceAgenda>' . $agenda . '</typ:sourceAgenda>'
            . '<typ:sourceDocument>' . ($agenda === 'bank' ? '<typ:id>' . abs(crc32($source)) . '</typ:id>' : '') . '<typ:number>' . $source . '</typ:number></typ:sourceDocument>'
            . '<typ:amount>' . $amount . '</typ:amount></typ:liquidation>';
        $header = static fn (string $type, string $number, string $vs, string $date, string $class, string $text, string $partner, float $remaining, ?string $paidOn = null, ?string $original = null): string =>
            '<inv:invoiceHeader><inv:invoiceType>' . $type . '</inv:invoiceType><inv:number><typ:numberRequested>' . $number . '</typ:numberRequested></inv:number>'
            . '<inv:symVar>' . $vs . '</inv:symVar>' . (str_starts_with($type, 'received') ? '<inv:originalDocument>' . ($original ?? 'D-' . $number) . '</inv:originalDocument>' : '')
            . '<inv:date>' . $date . '</inv:date><inv:dateTax>' . $date . '</inv:dateTax><inv:dateAccounting>' . $date . '</inv:dateAccounting>'
            . '<inv:dateDue>' . date('Y-m-d', (int) strtotime($date . ' +14 days')) . '</inv:dateDue>'
            . '<inv:classificationVAT><typ:ids>' . $class . '</typ:ids></inv:classificationVAT><inv:text>' . $text . '</inv:text>' . $partner
            . '<inv:paymentType><typ:paymentType>draft</typ:paymentType></inv:paymentType>'
            . '<inv:liquidation><typ:amountHome>' . $remaining . '</typ:amountHome>' . ($paidOn !== null ? '<typ:date>' . $paidOn . '</typ:date>' : '') . '</inv:liquidation></inv:invoiceHeader>';
        $item = static fn (string $text, float $base, float $vat): string =>
            '<inv:invoiceItem><inv:text>' . $text . '</inv:text><inv:quantity>1.0</inv:quantity><inv:rateVAT value="' . ($vat !== 0.0 ? '21' : '0') . '">' . ($vat !== 0.0 ? 'high' : 'none') . '</inv:rateVAT>'
            . '<inv:homeCurrency><typ:unitPrice>' . $base . '</typ:unitPrice><typ:price>' . $base . '</typ:price><typ:priceVAT>' . $vat . '</typ:priceVAT><typ:priceSum>' . ($base + $vat) . '</typ:priceSum></inv:homeCurrency></inv:invoiceItem>';
        $deduction = static fn (string $advance, float $amount): string =>
            '<inv:invoiceAdvancePaymentItem><inv:text>Odpočet zálohy</inv:text><inv:quantity>1.0</inv:quantity><inv:rateVAT value="0">none</inv:rateVAT>'
            . '<inv:homeCurrency><typ:unitPrice>' . -$amount . '</typ:unitPrice><typ:price>' . -$amount . '</typ:price><typ:priceVAT>0</typ:priceVAT><typ:priceSum>' . -$amount . '</typ:priceSum></inv:homeCurrency>'
            . '<inv:sourceDocument><typ:number>' . $advance . '</typ:number></inv:sourceDocument></inv:invoiceAdvancePaymentItem>';
        $summary = static fn (float $none, float $base, float $vat): string =>
            '<inv:invoiceSummary><inv:homeCurrency><typ:priceNone>' . $none . '</typ:priceNone><typ:priceLow>0</typ:priceLow><typ:priceLowVAT rate="12">0</typ:priceLowVAT>'
            . '<typ:priceLowSum>0</typ:priceLowSum><typ:priceHigh>' . $base . '</typ:priceHigh><typ:priceHighVAT rate="21">' . $vat . '</typ:priceHighVAT>'
            . '<typ:priceHighSum>' . ($base + $vat) . '</typ:priceHighSum><typ:round><typ:priceRound>0</typ:priceRound></typ:round></inv:homeCurrency></inv:invoiceSummary>';
        $advance = static fn (string $number, string $vs, string $date, float $amount, string $partner, string $liquidations = '', ?string $original = null): string =>
            '<lst:invoice version="2.0">' . $header('receivedAdvanceInvoice', $number, $vs, $date, 'PN', 'Záloha ' . $number, $partner, $liquidations === '' ? $amount : 0, $liquidations === '' ? null : $date, $original)
            . $summary($amount, 0, 0) . ($liquidations !== '' ? '<inv:liquidations>' . $liquidations . '</inv:liquidations>' : '') . '</lst:invoice>';

        self::append($agendaDir . '/02_uctova_osnova.xml', '</lst:listAccount>',
            '<lst:itemAccount id="314001" code="314001" name="Poskytnuté zálohy"/>'
            . '<lst:itemAccount id="395000" code="395000" name="Vnitřní zúčtování"/>'
            . '<lst:itemAccount id="563000" code="563000" name="Kurzové ztráty"/>'
            . '<lst:itemAccount id="663000" code="663000" name="Kurzové zisky"/>');

        self::file($agendaDir . '/23_faktury_receivedAdvanceInvoice.xml', '<lst:listInvoice version="2.0" state="ok">'
            . $advance(self::LINK_ADVANCE, '2026101', '2026-01-05', 1210, $vendor)
            . $advance(self::LINK_ADVANCE_FALLBACK, '2026102', '2026-01-05', 1210, $vendor)
            . $advance(self::LINK_ADVANCE_PAIRED, '2026103', '2026-01-05', 500, $vendor)
            . $advance(self::LINK_ADVANCE_SPLIT, '2026104', '2026-01-05', 800, $vendor)
            . $advance(self::REFUND_ADVANCE, '2026105', '2026-01-08', 1000, $named('inv', self::REFUND_VENDOR), $liquidation('bank', 'BAN0010105', '2026-01-10', 1000), '2026105')
            . $advance(self::REFUND_ADVANCE_BY_VS, '2026108', '2026-01-08', 400, $named('inv', self::REFUND_VENDOR_BY_VS), $liquidation('bank', 'BAN0010112', '2026-01-10', 400), 'ZF-2026-8')
            . $advance(self::FIFO_ADVANCES[0], '2026106', '2026-01-08', 500, $named('inv', self::FIFO_VENDOR), $liquidation('bank', 'BAN0010106', '2026-01-10', 500))
            . $advance(self::FIFO_ADVANCES[1], '2026107', '2026-01-18', 500, $named('inv', self::FIFO_VENDOR), $liquidation('bank', 'BAN0010107', '2026-01-20', 500))
            . '</lst:listInvoice>');

        self::append($agendaDir . '/20_faktury_receivedInvoice.xml', '</lst:listInvoice>',
            '<lst:invoice version="2.0">' . $header('receivedInvoice', self::LINK_FINAL, '2026111', '2026-02-02', 'PD', 'Vyúčtování', $vendor, 1210)
                . '<inv:invoiceDetail>' . $item('Služby', 2000, 420) . $deduction(self::LINK_ADVANCE, 1210) . '</inv:invoiceDetail>' . $summary(0, 2000, 420) . '</lst:invoice>'
            . '<lst:invoice version="2.0">' . $header('receivedInvoice', self::LINK_FINAL_MULTI, '2026112', '2026-02-03', 'PD', 'Vyúčtování energií', $vendor, 1920)
                . '<inv:invoiceDetail>' . $item('Energie', 3000, 630) . $deduction(self::LINK_ADVANCE_FALLBACK, 1210) . $deduction(self::LINK_ADVANCE_PAIRED, 500) . '</inv:invoiceDetail>'
                . $summary(0, 3000, 630) . '</lst:invoice>'
            . '<lst:invoice version="2.0">' . $header('receivedInvoice', self::LINK_FINAL_SPLIT[0], '2026113', '2026-02-04', 'PD', 'Nájem únor', $vendor, 810)
                . '<inv:invoiceDetail>' . $item('Nájem', 1000, 210) . $deduction(self::LINK_ADVANCE_SPLIT, 400) . '</inv:invoiceDetail>' . $summary(0, 1000, 210) . '</lst:invoice>'
            . '<lst:invoice version="2.0">' . $header('receivedInvoice', self::LINK_FINAL_SPLIT[1], '2026114', '2026-03-04', 'PD', 'Nájem březen', $vendor, 810)
                . '<inv:invoiceDetail>' . $item('Nájem', 1000, 210) . $deduction(self::LINK_ADVANCE_SPLIT, 400) . '</inv:invoiceDetail>' . $summary(0, 1000, 210) . '</lst:invoice>'
            . '<lst:invoice version="2.0">' . $header('receivedInvoice', self::FIFO_FINAL, '2026115', '2026-02-10', 'PD', 'Nájem', $named('inv', self::FIFO_VENDOR), 1720)
                . '<inv:invoiceDetail>' . $item('Nájem', 2000, 420) . $item('Odečet zálohy', -700, 0) . '</inv:invoiceDetail>' . $summary(-700, 2000, 420) . '</lst:invoice>'
            . '<lst:invoice version="2.0">' . $header('receivedInvoice', self::FX_PURCHASE, '2026116', '2026-02-12', 'PD', 'Licence', $vendor, 0, '2026-02-20')
                . $summary(0, 1000, 210) . '<inv:liquidations>' . $liquidation('bank', 'BAN0010110', '2026-02-20', 1200) . '</inv:liquidations></lst:invoice>');

        self::append($agendaDir . '/12_faktury_issuedInvoice.xml', '</lst:listInvoice>',
            '<lst:invoice version="2.0">' . $header('issuedInvoice', self::FX_ISSUED, '260101', '2026-02-12', 'UD', 'Služby', $customer, 0, '2026-02-21')
                . $summary(0, 1000, 210) . '<inv:liquidations>' . $liquidation('bank', 'BAN0010111', '2026-02-21', 1200) . '</inv:liquidations></lst:invoice>'
            . '<lst:invoice version="2.0">' . $header('issuedInvoice', self::OFFSET_INVOICE, '260102', '2026-02-14', 'UD', 'Služby', $customer, 0, '2026-02-25')
                . $summary(0, 1000, 210) . '<inv:liquidations>' . $liquidation('internalDocuments', self::OFFSET_DOCUMENT, '2026-02-25', 1210) . '</inv:liquidations></lst:invoice>');
        $credit = $agendaDir . '/13_faktury_issuedCreditNotice.xml';
        $creditNote = '<lst:invoice version="2.0">' . $header('issuedCreditNotice', self::OFFSET_CREDIT_NOTE, '260201', '2026-02-20', 'UD', 'Dobropis', $customer, 0, '2026-02-25')
            . $summary(0, -1000, -210) . '<inv:liquidations>' . $liquidation('internalDocuments', self::OFFSET_DOCUMENT, '2026-02-25', -1210) . '</inv:liquidations></lst:invoice>';
        is_file($credit)
            ? self::append($credit, '</lst:listInvoice>', $creditNote)
            : self::file($credit, '<lst:listInvoice version="2.0" state="ok">' . $creditNote . '</lst:listInvoice>');

        $taxDocument = static fn (string $number, string $text, string $links): string =>
            '<lst:intDoc version="2.0"><int:intDocHeader><int:number><typ:numberRequested>' . $number . '</typ:numberRequested></int:number>'
            . '<int:date>2026-01-15</int:date><int:dateTax>2026-01-15</int:dateTax><int:dateAccounting>2026-01-15</int:dateAccounting>'
            . '<int:classificationVAT><typ:ids>PD</typ:ids></int:classificationVAT><int:text>' . $text . '</int:text>'
            . self::partner('int', 'Dodavatel Test s.r.o.', self::VENDOR_ICO, 'CZ' . self::VENDOR_ICO) . '</int:intDocHeader>'
            . '<int:intDocDetail><int:intDocItem><int:text>' . $text . '</int:text><int:quantity>1.0</int:quantity><int:rateVAT value="21">high</int:rateVAT>'
            . '<int:homeCurrency><typ:unitPrice>1000</typ:unitPrice><typ:price>1000</typ:price><typ:priceVAT>210</typ:priceVAT><typ:priceSum>1210</typ:priceSum></int:homeCurrency></int:intDocItem></int:intDocDetail>'
            . str_replace(['int:invoiceSummary'], ['int:intDocSummary'], self::summary('int', 1000, 210))
            . ($links !== '' ? '<int:linkedDocuments><typ:link><typ:sourceAgenda>receivedAdvanceInvoice</typ:sourceAgenda><typ:sourceDocument><typ:number>' . $links . '</typ:number></typ:sourceDocument></typ:link></int:linkedDocuments>' : '')
            . '</lst:intDoc>';
        $internal = $agendaDir . '/27_interni_doklady.xml';
        $taxDocuments = $taxDocument(self::TAX_DOCUMENT_LINKED, 'Daňový doklad k přijaté platbě', self::LINK_ADVANCE)
            . $taxDocument(self::TAX_DOCUMENT_BY_TEXT, 'Daňový doklad k uhrazené zálohové faktuře č. ' . self::LINK_ADVANCE_FALLBACK, '')
            . $taxDocument(self::TAX_DOCUMENT_WRONG_AMOUNT, 'Daňový doklad k uhrazené zálohové faktuře č. ' . self::LINK_ADVANCE_PAIRED, '');
        is_file($internal)
            ? self::append($internal, '</lst:listIntDoc>', $taxDocuments)
            : self::file($internal, '<lst:listIntDoc version="2.0" state="ok">' . $taxDocuments . '</lst:listIntDoc>');

        self::append($agendaDir . '/29_banka.xml', '</lst:listBank>',
            self::bank('BAN0010105', 'expense', '2026-01-10', 1000, '2026105', self::REFUND_VENDOR)
            . self::bank('BAN0010106', 'expense', '2026-01-10', 500, '2026106', self::FIFO_VENDOR)
            . self::bank('BAN0010107', 'expense', '2026-01-20', 500, '2026107', self::FIFO_VENDOR)
            . self::bank('BAN0010108', 'receipt', '2026-03-15', 300, '2026105', self::REFUND_VENDOR)
            . self::bank('BAN0010112', 'expense', '2026-01-10', 400, '2026108', self::REFUND_VENDOR_BY_VS)
            . self::bank('BAN0010113', 'receipt', '2026-03-16', 400, '2026108', self::REFUND_VENDOR_BY_VS)
            . self::bank('BAN0010110', 'expense', '2026-02-20', 1200, '2026116', 'Dodavatel Test s.r.o.')
            . self::bank('BAN0010111', 'receipt', '2026-02-21', 1200, '260101', 'Odběratel Test s.r.o.'));

        self::append($agendaDir . '/01_ucetni_denik.xml', '</lst:accountancy>',
            self::entry('Přijaté faktury', self::LINK_FINAL, 'Služby', 2000, '518000', '321001', '2026-02-02')
            . self::entry('Přijaté faktury', self::LINK_FINAL, 'DPH', 420, '343011', '321001', '2026-02-02')
            . self::entry('Přijaté faktury', self::LINK_FINAL, 'Odpočet zálohy', 1210, '321001', '314001', '2026-02-02')
            . self::entry('Přijaté faktury', self::LINK_FINAL_MULTI, 'Energie', 3000, '518000', '321001', '2026-02-03')
            . self::entry('Přijaté faktury', self::LINK_FINAL_MULTI, 'DPH', 630, '343011', '321001', '2026-02-03')
            . self::entry('Přijaté faktury', self::LINK_FINAL_MULTI, 'Odpočet záloh', 1710, '321001', '314001', '2026-02-03')
            . self::entry('Přijaté faktury', self::LINK_FINAL_SPLIT[0], 'Nájem', 1000, '518000', '321001', '2026-02-04')
            . self::entry('Přijaté faktury', self::LINK_FINAL_SPLIT[0], 'DPH', 210, '343011', '321001', '2026-02-04')
            . self::entry('Přijaté faktury', self::LINK_FINAL_SPLIT[0], 'Odpočet zálohy', 400, '321001', '314001', '2026-02-04')
            . self::entry('Přijaté faktury', self::LINK_FINAL_SPLIT[1], 'Nájem', 1000, '518000', '321001', '2026-03-04')
            . self::entry('Přijaté faktury', self::LINK_FINAL_SPLIT[1], 'DPH', 210, '343011', '321001', '2026-03-04')
            . self::entry('Přijaté faktury', self::LINK_FINAL_SPLIT[1], 'Odpočet zálohy', 400, '321001', '314001', '2026-03-04')
            . self::entry('Přijaté faktury', self::FIFO_FINAL, 'Nájem', 2000, '518000', '321001', '2026-02-10')
            . self::entry('Přijaté faktury', self::FIFO_FINAL, 'DPH', 420, '343011', '321001', '2026-02-10')
            . self::entry('Přijaté faktury', self::FIFO_FINAL, 'Odečet zálohy', 700, '321001', '314001', '2026-02-10')
            . self::entry('Přijaté faktury', self::FX_PURCHASE, 'Licence', 1000, '518000', '321001', '2026-02-12')
            . self::entry('Přijaté faktury', self::FX_PURCHASE, 'DPH', 210, '343011', '321001', '2026-02-12')
            . self::entry('Vydané faktury', self::FX_ISSUED, 'Služby', 1000, '311001', '602000', '2026-02-12')
            . self::entry('Vydané faktury', self::FX_ISSUED, 'DPH', 210, '311001', '343021', '2026-02-12')
            . self::entry('Vydané faktury', self::OFFSET_INVOICE, 'Služby', 1000, '311001', '602000', '2026-02-14')
            . self::entry('Vydané faktury', self::OFFSET_INVOICE, 'DPH', 210, '311001', '343021', '2026-02-14')
            . self::entry('Vydané faktury', self::OFFSET_CREDIT_NOTE, 'Dobropis', -1000, '311001', '602000', '2026-02-20')
            . self::entry('Vydané faktury', self::OFFSET_CREDIT_NOTE, 'DPH', -210, '311001', '343021', '2026-02-20')
            . self::entry('Interní doklady', self::OFFSET_DOCUMENT, 'Zápočet faktury', 1210, '395000', '311001', '2026-02-25')
            . self::entry('Interní doklady', self::OFFSET_DOCUMENT, 'Zápočet dobropisu', 1210, '311001', '395000', '2026-02-25')
            . self::entry('Interní doklady', self::TAX_DOCUMENT_LINKED, 'DPH zálohy', 210, '343011', '314001', '2026-01-15')
            . self::entry('Interní doklady', self::TAX_DOCUMENT_BY_TEXT, 'DPH zálohy', 210, '343011', '314001', '2026-01-15')
            . self::entry('Interní doklady', self::TAX_DOCUMENT_WRONG_AMOUNT, 'DPH zálohy', 210, '343011', '314001', '2026-01-15')
            . self::entry('Banka', 'BAN0010105', 'Záloha', 1000, '314001', '221001', '2026-01-10')
            . self::entry('Banka', 'BAN0010106', 'Záloha', 500, '314001', '221001', '2026-01-10')
            . self::entry('Banka', 'BAN0010107', 'Záloha', 500, '314001', '221001', '2026-01-20')
            . self::entry('Banka', 'BAN0010108', 'Vrácení zálohy', 300, '221001', '314001', '2026-03-15')
            . self::entry('Banka', 'BAN0010112', 'Záloha', 400, '314001', '221001', '2026-01-10')
            . self::entry('Banka', 'BAN0010113', 'Vrácení zálohy', 400, '221001', '314001', '2026-03-16')
            . self::entry('Banka', 'BAN0010110', 'Úhrada ' . self::FX_PURCHASE, 1200, '321001', '221001', '2026-02-20')
            . self::entry('Banka', 'BAN0010110', 'Kurzový rozdíl', 10, '321001', '663000', '2026-02-20')
            . self::entry('Banka', 'BAN0010111', 'Úhrada ' . self::FX_ISSUED, 1200, '221001', '311001', '2026-02-21')
            . self::entry('Banka', 'BAN0010111', 'Kurzový rozdíl', 10, '563000', '311001', '2026-02-21'));
    }

    /** Poskytnuté zálohy minulého roku ({@see withPreviousYearAdvances()}), předpis bez DPH 1 000 + 500. */
    public const PREVIOUS_ADVANCES = ['25ZP0001', '25ZP0002'];
    public const PREVIOUS_ADVANCES_BASE = 1500.0;

    /**
     * Agenda z {@see write()} se dvěma poskytnutými zálohami minulého roku, které POHODA
     * převedla do agendy (uhrazené v prosinci 2025, bez DDKP v exportu): 25ZP0001 1 000 + 210
     * a 25ZP0002 500 + 105 (Dodavatel Test). Počáteční stav 314 je `$opening314` (proti 701,
     * otevírací zápis zůstává vyrovnaný přes 411).
     */
    public static function withPreviousYearAdvances(string $agendaDir, float $opening314): void
    {
        $advance = static fn (string $number, string $vs, string $date, float $base, float $vat): string =>
            '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>receivedAdvanceInvoice</inv:invoiceType><inv:number><typ:numberRequested>' . $number . '</typ:numberRequested></inv:number>'
            . '<inv:symVar>' . $vs . '</inv:symVar><inv:originalDocument>' . $vs . '</inv:originalDocument><inv:date>' . $date . '</inv:date><inv:dateTax>' . $date . '</inv:dateTax>'
            . '<inv:dateAccounting>' . $date . '</inv:dateAccounting><inv:dateDue>' . $date . '</inv:dateDue>'
            . '<inv:classificationVAT><typ:ids>PN</typ:ids></inv:classificationVAT><inv:text>Záloha ' . $number . '</inv:text>'
            . self::partner('inv', 'Dodavatel Test s.r.o.', self::VENDOR_ICO, 'CZ' . self::VENDOR_ICO)
            . '<inv:paymentType><typ:paymentType>draft</typ:paymentType></inv:paymentType>'
            . '<inv:liquidation><typ:amountHome>0</typ:amountHome><typ:date>' . date('Y-m-d', (int) strtotime($date . ' +5 days')) . '</typ:date></inv:liquidation></inv:invoiceHeader>'
            . self::summary('inv', $base, $vat) . '</lst:invoice>';
        self::append($agendaDir . '/02_uctova_osnova.xml', '</lst:listAccount>', '<lst:itemAccount id="314001" code="314001" name="Poskytnuté zálohy"/>');
        self::file($agendaDir . '/23_faktury_receivedAdvanceInvoice.xml', '<lst:listInvoice version="2.0" state="ok">'
            . $advance(self::PREVIOUS_ADVANCES[0], '2025901', '2025-11-10', 1000, 210)
            . $advance(self::PREVIOUS_ADVANCES[1], '2025902', '2025-12-01', 500, 105)
            . '</lst:listInvoice>');
        self::append($agendaDir . '/01_ucetni_denik.xml', '</lst:accountancy>',
            self::entry('Počáteční stavy účtů', 'ZAV', 'Počáteční stav účtu', $opening314, '314001', '701000', '2026-01-01')
            . self::entry('Počáteční stavy účtů', 'ZAV', 'Počáteční stav účtu', $opening314, '701000', '411000', '2026-01-01'));
    }

    /** Pohyby bez zápisu v deníku z {@see withBookedBankHistory()}, které návrh dostanou nebo nedostanou. */
    public const PROPOSAL_BY_ACCOUNT = 'BAN0020001';
    public const PROPOSAL_BY_NAME = 'BAN0020002';
    public const PROPOSAL_AT_THRESHOLD = 'BAN0020003';
    public const NO_PROPOSAL_INCONSISTENT = 'BAN0020004';
    public const NO_PROPOSAL_TOO_FEW = 'BAN0020005';
    public const NO_PROPOSAL_SALDO = 'BAN0020006';
    public const NO_PROPOSAL_OTHER_DIRECTION = 'BAN0020007';
    public const PROPOSAL_ACCOUNT = '3000000020';

    /**
     * Zaúčtované pohyby bez dokladu a k nim pohyb bez zápisu (předkontace „Nevím"): shoda podle
     * účtu, podle jména u platby bez účtu, přesně 9 z 10 shod, 3 ze 4 shod, jen dvě shody,
     * úhrady přes saldokonto a platba opačným směrem.
     */
    public static function withBookedBankHistory(string $dir): void
    {
        $bank = '';
        $journal = '';
        $number = 100;
        $booked = static function (int $times, string $md, string $d, string $partner, string $account, string $type = 'expense') use (&$bank, &$journal, &$number): void {
            for ($i = 0; $i < $times; $i++) {
                $doc = 'BAN0020' . ++$number;
                $date = sprintf('2026-%02d-%02d', 2 + $i % 9, 5 + $number % 20);
                $bank .= self::bank($doc, $type, $date, 100.0 + $i, '', $partner, $account);
                $journal .= self::entry('Banka', $doc, 'Platba', 100.0 + $i, $md, $d, $date);
            }
        };
        $booked(3, '518000', '221001', 'Nájem Test s.r.o.', self::PROPOSAL_ACCOUNT);
        $booked(3, '568000', '221001', 'Poštovné Test', '');
        $booked(9, '518000', '221001', 'Hranice Test s.r.o.', '7000000010');
        $booked(1, '568000', '221001', 'Hranice Test s.r.o.', '7000000010');
        $booked(3, '518000', '221001', 'Rozpor Test s.r.o.', '4000000009');
        $booked(1, '568000', '221001', 'Rozpor Test s.r.o.', '4000000009');
        $booked(2, '518000', '221001', 'Málo Test s.r.o.', '5000000011');
        $booked(3, '321001', '221001', 'Saldo Test s.r.o.', '6000000040');

        $bank .= self::bank(self::PROPOSAL_BY_ACCOUNT, 'expense', '2026-11-02', 1500, '', 'Nájem Test s.r.o.', self::PROPOSAL_ACCOUNT)
            . self::bank(self::PROPOSAL_BY_NAME, 'expense', '2026-11-03', 90, '', 'Poštovné Test')
            . self::bank(self::PROPOSAL_AT_THRESHOLD, 'expense', '2026-11-04', 110, '', 'Hranice Test s.r.o.', '7000000010')
            . self::bank(self::NO_PROPOSAL_INCONSISTENT, 'expense', '2026-11-05', 120, '', 'Rozpor Test s.r.o.', '4000000009')
            . self::bank(self::NO_PROPOSAL_TOO_FEW, 'expense', '2026-11-06', 130, '', 'Málo Test s.r.o.', '5000000011')
            . self::bank(self::NO_PROPOSAL_SALDO, 'expense', '2026-11-07', 140, '', 'Saldo Test s.r.o.', '6000000040')
            . self::bank(self::NO_PROPOSAL_OTHER_DIRECTION, 'receipt', '2026-11-08', 150, '', 'Nájem Test s.r.o.', self::PROPOSAL_ACCOUNT);
        self::append($dir . '/29_banka.xml', '</lst:listBank>', $bank);
        self::append($dir . '/01_ucetni_denik.xml', '</lst:accountancy>', $journal);
    }

    private static function append(string $file, string $closing, string $xml): void
    {
        $content = (string) file_get_contents($file);
        file_put_contents($file, str_replace($closing, (string) iconv('UTF-8', 'Windows-1250', $xml) . $closing, $content));
    }

    /** ZIP exportu tak, jak ho zabalí nástroj (kořen s přehledem jednotek a složka agendy). */
    public static function writeZip(string $zipPath, string $workDir): void
    {
        self::write($workDir);
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFile($workDir . '/00_ucetni_jednotky.xml', '00_ucetni_jednotky.xml');
        foreach (glob($workDir . '/' . self::ICO . '_' . self::YEAR . '/*.xml') ?: [] as $file) {
            $zip->addFile($file, self::ICO . '_' . self::YEAR . '/' . basename($file));
        }
        $zip->addFromString('souhrn.txt', 'Export z POHODY');
        $zip->close();
    }

    /** @return array<string,string> soubor => obsah listu */
    private static function files(bool $withAssets = false, bool $withOss = false, bool $withAdvanceDeduction = false, ?string $laterPayment = null, bool $unbooked = false, string $ossRateForm = self::OSS_RATE_VALUE, ?OssVariant $oss = null): array
    {
        $oss ??= new OssVariant();
        $journal = self::entry('Počáteční stavy účtů', 'ZAV', 'Počáteční stav účtu', 100000, '221001', '701000', '2026-01-01')
            . self::entry('Počáteční stavy účtů', 'ZAV', 'Počáteční stav účtu', 100000, '701000', '411000', '2026-01-01')
            . self::entry('Vydané faktury', '26FV0001', 'Služby', 1000, '311001', '602000', '2026-01-10')
            . self::entry('Vydané faktury', '26FV0001', 'DPH', 210, '311001', '343021', '2026-01-10')
            . self::entry('Přijaté faktury', '26PF0001', 'Materiál', 500, '518000', '321001', '2026-01-12')
            . self::entry('Přijaté faktury', '26PF0001', 'DPH', 105, '343011', '321001', '2026-01-12')
            . self::entry('Banka', 'BAN0010001', 'Úhrada 26FV0001', 1210, '221001', '311001', '2026-01-15')
            . self::entry('Banka', 'BAN0010002', 'Úhrada 26PF0001', 605, '321001', '221001', '2026-01-20')
            . self::entry('Banka', 'BAN0010003', 'Poplatek za vedení účtu', 50, '568000', '221001', '2026-01-31');
        if ($withAssets) {
            $journal .= self::entry('Počáteční stavy účtů', 'ZAV', 'Počáteční stav účtu', 120000, '022001', '701000', '2026-01-01')
                . self::entry('Počáteční stavy účtů', 'ZAV', 'Počáteční stav účtu', 60000, '701000', '082001', '2026-01-01')
                . self::entry('Počáteční stavy účtů', 'ZAV', 'Počáteční stav účtu', 60000, '701000', '411000', '2026-01-01');
            foreach (['2026-01-31', '2026-02-28', '2026-03-31'] as $date) {
                $journal .= self::entry('Dlouhodobý majetek', self::ASSET_NUMBER, 'Odpis - Testovací stroj', 5000, '551000', '082001', $date);
            }
            $journal .= self::entry('Přijaté faktury', '26PF0002', 'Nářadí', 1200, '518000', '321001', '2026-02-05')
                . self::entry('Přijaté faktury', '26PF0002', 'DPH', 252, '343011', '321001', '2026-02-05');
        }
        if ($withOss) {
            $journal .= self::entry('Vydané faktury', '26FV0002', 'Zboží na dálku SK', 1000, '311001', '604000', '2026-02-10')
                . self::entry('Vydané faktury', '26FV0002', 'DPH OSS', 230, '311001', '343090', '2026-02-10');
        }
        $settled = $laterPayment === self::LATER_SETTLED;
        if ($laterPayment !== null) {
            $journal .= self::entry('Přijaté faktury', self::LATER_PAID_DOCUMENT, 'Servis', 600, '518000', '321001', '2026-01-25')
                . self::entry('Přijaté faktury', self::LATER_PAID_DOCUMENT, 'DPH', 126, '343011', '321001', '2026-01-25');
            if ($settled) {
                $journal .= self::entry('Banka', self::LATER_PAID_BANK, 'Úhrada ' . self::LATER_PAID_DOCUMENT, 726, '321001', '221001', '2026-01-28');
            }
        }
        if ($unbooked) {
            $next = self::NEXT_YEAR;
            $journal .= self::entry('Vydané faktury', self::UNBOOKED_ISSUED, 'Služby', 1500, '311001', '602000', '2026-11-20')
                . self::entry('Vydané faktury', self::UNBOOKED_ISSUED, 'DPH', 315, '311001', '343021', '2026-11-20');
            foreach ([[self::AMBIGUOUS_PURCHASES[0], 300, '2026-03-01'], [self::AMBIGUOUS_PURCHASES[1], 300, '2026-03-02'],
                [self::ACCOUNT_PURCHASE, 200, '2026-04-01'], [self::PARSYM_PURCHASE, 400, '2026-05-01'], [self::CARD_PURCHASE, 100, '2026-05-20']] as [$number, $base, $date]) {
                $journal .= self::entry('Přijaté faktury', $number, 'Služby', $base, '518000', '321001', $date)
                    . self::entry('Přijaté faktury', $number, 'DPH', $base * 0.21, '343011', '321001', $date);
            }
            // Doklady následujícího roku, které agenda vede a POHODA je i zaúčtovala.
            $journal .= self::entry('Vydané faktury', self::NEXT_YEAR_ISSUED, 'Služby', 1000, '311001', '602000', $next . '-01-10')
                . self::entry('Vydané faktury', self::NEXT_YEAR_ISSUED, 'DPH', 210, '311001', '343021', $next . '-01-10')
                . self::entry('Banka', 'BAN0010016', 'Úhrada ' . self::NEXT_YEAR_ISSUED, 1210, '221001', '311001', $next . '-01-20');
        }
        if ($withAdvanceDeduction) {
            $journal .= self::entry('Vydané faktury', self::ADVANCE_DOCUMENT, 'Služby', 1000, '311001', '602000', '2026-03-02')
                . self::entry('Vydané faktury', self::ADVANCE_DOCUMENT, 'DPH', 210, '311001', '343021', '2026-03-02')
                . self::entry('Vydané faktury', self::ADVANCE_DOCUMENT, 'Tržby z prodeje služeb', 1210, '311001', '602000', '2026-03-02');
        }

        $accounts = '';
        if ($withOss) {
            foreach (['604000' => 'Tržby za zboží', '343090' => 'DPH OSS'] as $code => $name) {
                $accounts .= '<lst:itemAccount id="' . $code . '" code="' . $code . '" name="' . $name . '"/>';
            }
        }
        if ($withAssets) {
            foreach (['022001' => 'Stroje', '042000' => 'Pořízení majetku', '082001' => 'Oprávky ke strojům', '551000' => 'Odpisy'] as $code => $name) {
                $accounts .= '<lst:itemAccount id="' . $code . '" code="' . $code . '" name="' . $name . '"/>';
            }
        }
        foreach (['221001' => 'Bankovní účet', '311001' => 'Odběratelé', '321001' => 'Dodavatelé', '343011' => 'DPH vstup', '343021' => 'DPH výstup',
            '411000' => 'Základní kapitál', '518000' => 'Ostatní služby', '568000' => 'Ostatní finanční náklady', '602000' => 'Tržby z prodeje služeb',
            '701000' => 'Počáteční účet rozvažný'] as $code => $name) {
            $accounts .= '<lst:itemAccount id="' . $code . '" code="' . $code . '" name="' . $name . '"/>';
        }

        $classes = '';
        foreach ([['UD', 'Tuzemské plnění', '1, 2', 'A.4., A.5.'], ['UDA5', 'Tuzemské plnění pod limit', '1, 2', 'A.5.'], ['PD', 'Tuzemské plnění', '40, 41', 'B.2., B.3.'],
            ['UN', 'Nezahrnovat do přiznání DPH', '---', 'Nezahrnovat'], ['PN', 'Nezahrnovat do přiznání DPH', '---', 'Nezahrnovat'],
            ['PDslRegEU', 'Služba z EU', '43, 44', 'Nezahrnovat'], ['DDslRegEU', 'Přijetí služby z EU', '5, 6', 'A.2.']] as [$code, $name, $lines, $section]) {
            $classes .= '<lst:classificationVAT version="2.0"><vat:classificationVATHeader><vat:code>' . $code . '</vat:code><vat:name>' . $name . '</vat:name>'
                . '<vat:lineInVATReturn>' . $lines . '</vat:lineInVATReturn><vat:sectionInVATLedgerStatement>' . $section . '</vat:sectionInVATLedgerStatement></vat:classificationVATHeader></lst:classificationVAT>';
        }

        $issued = '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>issuedInvoice</inv:invoiceType><inv:number><typ:numberRequested>26FV0001</typ:numberRequested></inv:number>'
            . '<inv:symVar>260001</inv:symVar><inv:date>2026-01-10</inv:date><inv:dateTax>2026-01-10</inv:dateTax><inv:dateAccounting>2026-01-10</inv:dateAccounting><inv:dateDue>2026-01-24</inv:dateDue>'
            . '<inv:classificationVAT><typ:ids>UD</typ:ids></inv:classificationVAT><inv:text>Fakturujeme Vám služby</inv:text>'
            . self::partner('inv', 'Odběratel Test s.r.o.', self::CUSTOMER_ICO, 'CZ' . self::CUSTOMER_ICO)
            . '<inv:paymentType><typ:paymentType>draft</typ:paymentType></inv:paymentType><inv:liquidation><typ:date>2026-01-15</typ:date></inv:liquidation></inv:invoiceHeader>'
            . '<inv:invoiceDetail><inv:invoiceItem><inv:text>Služby</inv:text><inv:quantity>2.0</inv:quantity><inv:rateVAT value="21">high</inv:rateVAT>'
            . '<inv:homeCurrency><typ:unitPrice>500</typ:unitPrice><typ:price>1000</typ:price><typ:priceVAT>210</typ:priceVAT><typ:priceSum>1210</typ:priceSum></inv:homeCurrency></inv:invoiceItem></inv:invoiceDetail>'
            . self::summary('inv', 1000, 210)
            . '<inv:liquidations><typ:liquidation><typ:id>1</typ:id><typ:date>2026-01-15</typ:date><typ:sourceAgenda>bank</typ:sourceAgenda><typ:sourceDocument><typ:number>BAN0010001</typ:number></typ:sourceDocument><typ:amount>1210</typ:amount></typ:liquidation></inv:liquidations></lst:invoice>';

        // Neuhrazená faktura z minulého roku, kterou POHODA přenesla do agendy: v deníku roku
        // zápis nemá, zůstatek je v počátečních stavech.
        $issued .= '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>issuedInvoice</inv:invoiceType><inv:number><typ:numberRequested>25FV0099</typ:numberRequested></inv:number>'
            . '<inv:symVar>250099</inv:symVar><inv:date>2025-12-20</inv:date><inv:dateTax>2025-12-20</inv:dateTax><inv:dateAccounting>2025-12-20</inv:dateAccounting><inv:dateDue>2026-01-03</inv:dateDue>'
            . '<inv:classificationVAT><typ:ids>UN</typ:ids></inv:classificationVAT><inv:text>Služby z minulého roku</inv:text>'
            . self::partner('inv', 'Odběratel Test s.r.o.', self::CUSTOMER_ICO, 'CZ' . self::CUSTOMER_ICO)
            . ($settled
                ? '<inv:liquidation><typ:amountHome>0</typ:amountHome><typ:date>2026-01-05</typ:date></inv:liquidation></inv:invoiceHeader>'
                : '<inv:liquidation><typ:amountHome>500</typ:amountHome></inv:liquidation></inv:invoiceHeader>')
            . '<inv:invoiceSummary><inv:homeCurrency><typ:priceNone>500</typ:priceNone><typ:priceHigh>0</typ:priceHigh><typ:priceHighVAT rate="21">0</typ:priceHighVAT>'
            . '<typ:round><typ:priceRound>0</typ:priceRound></typ:round></inv:homeCurrency></inv:invoiceSummary></lst:invoice>';

        // Prodej zboží na dálku koncovému zákazníkovi na Slovensko: vlastní členění mimo
        // české přiznání, sazba státu spotřeby, odběratel bez IČ a DIČ, doklad v EUR.
        // Přesně takhle se OSS v POHODĚ vede.
        if ($withOss) {
            $percent = $ossRateForm !== self::OSS_RATE_VALUE;
            $issued .= '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>issuedInvoice</inv:invoiceType><inv:number><typ:numberRequested>' . self::OSS_DOCUMENT . '</typ:numberRequested></inv:number>'
                . '<inv:symVar>260002</inv:symVar><inv:date>2026-02-10</inv:date><inv:dateTax>2026-02-10</inv:dateTax><inv:dateAccounting>2026-02-10</inv:dateAccounting><inv:dateDue>2026-02-24</inv:dateDue>'
                . '<inv:classificationVAT><typ:ids>UN</typ:ids></inv:classificationVAT><inv:text>Prodej zboží na dálku - SK</inv:text>'
                . ($oss->moss !== null ? '<inv:MOSS><typ:ids>' . $oss->moss . '</typ:ids></inv:MOSS><inv:evidentiaryResourcesMOSS><typ:ids>G</typ:ids></inv:evidentiaryResourcesMOSS>' : '')
                . '<inv:partnerIdentity><typ:address><typ:name>Jana</typ:name><typ:surname>Testovacia</typ:surname><typ:city>Bratislava</typ:city>'
                . '<typ:street>Testovacia 1</typ:street><typ:zip>81101</typ:zip>'
                . ($oss->partnerCountry !== null ? '<typ:country><typ:ids>' . $oss->partnerCountry . '</typ:ids></typ:country>' : '')
                . '</typ:address></inv:partnerIdentity>'
                . '<inv:liquidation><typ:amountHome>1230</typ:amountHome></inv:liquidation></inv:invoiceHeader>'
                . '<inv:invoiceDetail><inv:invoiceItem><inv:text>Zboží</inv:text><inv:quantity>1.0</inv:quantity><inv:unit>ks</inv:unit>'
                . ($oss->supplyType !== '' ? '<inv:typeServiceMOSS><typ:ids>' . $oss->supplyType . '</typ:ids></inv:typeServiceMOSS>' : '')
                . ($percent
                    ? '<inv:rateVAT>historyHigh</inv:rateVAT>' . ($ossRateForm === self::OSS_RATE_PERCENT ? '<inv:percentVAT>' . self::OSS_RATE . '</inv:percentVAT>' : '')
                    : '<inv:rateVAT value="23">high</inv:rateVAT>')
                . '<inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice><typ:price>1000</typ:price><typ:priceVAT>230</typ:priceVAT><typ:priceSum>1230</typ:priceSum></inv:homeCurrency>'
                . ($percent ? '<inv:foreignCurrency><typ:unitPrice>40</typ:unitPrice><typ:price>40</typ:price><typ:priceVAT>9.20</typ:priceVAT><typ:priceSum>49.20</typ:priceSum></inv:foreignCurrency>' : '')
                . '</inv:invoiceItem></inv:invoiceDetail>'
                . '<inv:invoiceSummary><inv:homeCurrency><typ:priceNone>0</typ:priceNone><typ:priceLow>0</typ:priceLow><typ:priceLowVAT rate="12">0</typ:priceLowVAT>'
                . '<typ:priceLowSum>0</typ:priceLowSum><typ:priceHigh>1000</typ:priceHigh><typ:priceHighVAT rate="23">230</typ:priceHighVAT>'
                . '<typ:priceHighSum>1230</typ:priceHighSum><typ:round><typ:priceRound>0</typ:priceRound></typ:round></inv:homeCurrency>'
                . '<inv:foreignCurrency><typ:currency><typ:ids>EUR</typ:ids></typ:currency><typ:rate>25</typ:rate><typ:priceSum>49.20</typ:priceSum></inv:foreignCurrency></inv:invoiceSummary></lst:invoice>';
        }

        if ($withAdvanceDeduction) {
            $issued .= '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>issuedInvoice</inv:invoiceType><inv:number><typ:numberRequested>' . self::ADVANCE_DOCUMENT . '</typ:numberRequested></inv:number>'
                . '<inv:symVar>260003</inv:symVar><inv:date>2026-03-02</inv:date><inv:dateTax>2026-03-02</inv:dateTax><inv:dateAccounting>2026-03-02</inv:dateAccounting><inv:dateDue>2026-03-16</inv:dateDue>'
                . '<inv:classificationVAT><typ:ids>UD</typ:ids></inv:classificationVAT><inv:text>Vyúčtování služeb po záloze</inv:text>'
                . self::partner('inv', 'Odběratel Test s.r.o.', self::CUSTOMER_ICO, 'CZ' . self::CUSTOMER_ICO)
                . '<inv:paymentType><typ:paymentType>draft</typ:paymentType></inv:paymentType><inv:liquidation><typ:amountHome>0</typ:amountHome><typ:date>2026-03-02</typ:date></inv:liquidation></inv:invoiceHeader>'
                . '<inv:invoiceDetail><inv:invoiceItem><inv:text>Služby</inv:text><inv:quantity>1.0</inv:quantity><inv:rateVAT value="21">high</inv:rateVAT>'
                . '<inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice><typ:price>1000</typ:price><typ:priceVAT>210</typ:priceVAT><typ:priceSum>1210</typ:priceSum></inv:homeCurrency></inv:invoiceItem>'
                . '<inv:invoiceAdvancePaymentItem><inv:text>Uhrazená záloha</inv:text><inv:quantity>1.0</inv:quantity><inv:rateVAT value="0">none</inv:rateVAT>'
                . '<inv:homeCurrency><typ:unitPrice>-1210</typ:unitPrice><typ:price>-1210</typ:price><typ:priceVAT>0</typ:priceVAT><typ:priceSum>-1210</typ:priceSum></inv:homeCurrency>'
                . '<inv:sourceDocument><typ:number>26ZF0001</typ:number></inv:sourceDocument></inv:invoiceAdvancePaymentItem></inv:invoiceDetail>'
                . self::summary('inv', 1000, 210) . '</lst:invoice>';
        }

        $received = '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>receivedInvoice</inv:invoiceType><inv:number><typ:numberRequested>26PF0001</typ:numberRequested></inv:number>'
            . '<inv:symVar>2026007</inv:symVar><inv:originalDocument>D-2026-7</inv:originalDocument><inv:date>2026-01-12</inv:date><inv:dateTax>2026-01-12</inv:dateTax>'
            . '<inv:dateAccounting>2026-01-12</inv:dateAccounting><inv:dateDue>2026-01-26</inv:dateDue><inv:dateKHDPH>2026-01-12</inv:dateKHDPH>'
            . '<inv:classificationVAT><typ:ids>PD</typ:ids></inv:classificationVAT><inv:text>Materiál</inv:text>'
            . self::partner('inv', 'Dodavatel Test s.r.o.', self::VENDOR_ICO, 'CZ' . self::VENDOR_ICO)
            . '<inv:paymentType><typ:paymentType>draft</typ:paymentType></inv:paymentType><inv:paymentAccount><typ:accountNo>1000000005</typ:accountNo><typ:bankCode>0100</typ:bankCode></inv:paymentAccount>'
            . '<inv:liquidation><typ:date>2026-01-20</typ:date></inv:liquidation></inv:invoiceHeader>'
            . self::summary('inv', 500, 105)
            . '<inv:liquidations><typ:liquidation><typ:id>2</typ:id><typ:date>2026-01-20</typ:date><typ:sourceAgenda>bank</typ:sourceAgenda><typ:sourceDocument><typ:number>BAN0010002</typ:number></typ:sourceDocument><typ:amount>605</typ:amount></typ:liquidation></inv:liquidations></lst:invoice>';
        if ($withAssets) {
            // Neuhrazená faktura za 2 monitory - na její položku se naváží karty drobného majetku DM0003 a DM0004.
            $received .= '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>receivedInvoice</inv:invoiceType><inv:number><typ:numberRequested>26PF0002</typ:numberRequested></inv:number>'
                . '<inv:symVar>2026008</inv:symVar><inv:originalDocument>D-2026-8</inv:originalDocument><inv:date>2026-02-05</inv:date><inv:dateTax>2026-02-05</inv:dateTax>'
                . '<inv:dateAccounting>2026-02-05</inv:dateAccounting><inv:dateDue>2026-02-19</inv:dateDue><inv:dateKHDPH>2026-02-05</inv:dateKHDPH>'
                . '<inv:classificationVAT><typ:ids>PD</typ:ids></inv:classificationVAT><inv:text>Nářadí</inv:text>'
                . self::partner('inv', 'Dodavatel Test s.r.o.', self::VENDOR_ICO, 'CZ' . self::VENDOR_ICO)
                . '<inv:liquidation><typ:amountHome>1452</typ:amountHome></inv:liquidation></inv:invoiceHeader>'
                . '<inv:invoiceDetail><inv:invoiceItem><inv:text>Monitor</inv:text><inv:quantity>2.0</inv:quantity><inv:rateVAT value="21">high</inv:rateVAT>'
                . '<inv:homeCurrency><typ:unitPrice>600</typ:unitPrice><typ:price>1200</typ:price><typ:priceVAT>252</typ:priceVAT><typ:priceSum>1452</typ:priceSum></inv:homeCurrency></inv:invoiceItem></inv:invoiceDetail>'
                . self::summary('inv', 1200, 252) . '</lst:invoice>';
        }

        if ($unbooked) {
            $issued .= self::invoice('issuedInvoice', self::UNBOOKED_ISSUED, self::UNBOOKED_ISSUED_VS, '2026-11-20', 1500, 315)
                . self::invoice('issuedInvoice', self::NEXT_YEAR_ISSUED, '260005', self::NEXT_YEAR . '-01-10', 1000, 210, '', 'BAN0010016', 'BAN0010003');
            $received .= self::invoice('receivedInvoice', self::AMBIGUOUS_PURCHASES[0], '2026015', '2026-03-01', 300, 63, self::PARTNER_ACCOUNT)
                . self::invoice('receivedInvoice', self::AMBIGUOUS_PURCHASES[1], '2026016', '2026-03-02', 300, 63, self::PARTNER_ACCOUNT)
                . self::invoice('receivedInvoice', self::ACCOUNT_PURCHASE, '2026017', '2026-04-01', 200, 42, self::PARTNER_ACCOUNT)
                . self::invoice('receivedInvoice', self::PARSYM_PURCHASE, '2026018', '2026-05-01', 400, 84)
                . self::invoice('receivedInvoice', self::CARD_PURCHASE, self::CARD_VS, '2026-05-20', 100, 21);
        }
        if ($laterPayment !== null) {
            $received .= '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>receivedInvoice</inv:invoiceType><inv:number><typ:numberRequested>' . self::LATER_PAID_DOCUMENT . '</typ:numberRequested></inv:number>'
                . '<inv:symVar>' . self::LATER_PAID_VS . '</inv:symVar><inv:originalDocument>D-2026-9</inv:originalDocument><inv:date>2026-01-25</inv:date><inv:dateTax>2026-01-25</inv:dateTax>'
                . '<inv:dateAccounting>2026-01-25</inv:dateAccounting><inv:dateDue>2026-02-08</inv:dateDue><inv:dateKHDPH>2026-01-25</inv:dateKHDPH>'
                . '<inv:classificationVAT><typ:ids>PD</typ:ids></inv:classificationVAT><inv:text>Servis</inv:text>'
                . self::partner('inv', 'Dodavatel Test s.r.o.', self::VENDOR_ICO, 'CZ' . self::VENDOR_ICO)
                . '<inv:paymentType><typ:paymentType>draft</typ:paymentType></inv:paymentType>'
                . ($settled
                    ? '<inv:liquidation><typ:amountHome>0</typ:amountHome><typ:date>2026-01-28</typ:date></inv:liquidation></inv:invoiceHeader>'
                    : '<inv:liquidation><typ:amountHome>726</typ:amountHome></inv:liquidation></inv:invoiceHeader>')
                . self::summary('inv', 600, 126)
                . ($settled
                    ? '<inv:liquidations><typ:liquidation><typ:id>3</typ:id><typ:date>2026-01-28</typ:date><typ:sourceAgenda>bank</typ:sourceAgenda><typ:sourceDocument><typ:number>'
                        . self::LATER_PAID_BANK . '</typ:number></typ:sourceDocument><typ:amount>726</typ:amount></typ:liquidation></inv:liquidations>'
                    : '')
                . '</lst:invoice>';
        }

        // POHODA zakládá na začátku roku doklad počátečního stavu účtu: bez čísla, bez výpisu
        // a bez zaúčtování. Není to pohyb, stav už nese deník.
        $bank = '<lst:bank version="2.0"><bnk:bankHeader><bnk:bankType>receipt</bnk:bankType><bnk:account><typ:ids>BAN</typ:ids></bnk:account>'
            . '<bnk:dateStatement>2026-01-01</bnk:dateStatement><bnk:datePayment>2026-01-01</bnk:datePayment>'
            . '<bnk:accounting><typ:ids>Bez</typ:ids><typ:accountingType>withoutAccounting</typ:accountingType></bnk:accounting>'
            . '<bnk:text>Počáteční stav bankovního účtu BAN</bnk:text></bnk:bankHeader>'
            . '<bnk:bankSummary><bnk:homeCurrency><typ:priceNone>100000</typ:priceNone><typ:priceLowSum>0</typ:priceLowSum><typ:priceHighSum>0</typ:priceHighSum>'
            . '<typ:round><typ:priceRound>0</typ:priceRound></typ:round></bnk:homeCurrency></bnk:bankSummary></lst:bank>'
            . self::bank('BAN0010001', 'receipt', '2026-01-15', 1210, '260001', 'Odběratel Test s.r.o.')
            . self::bank('BAN0010002', 'expense', '2026-01-20', 605, '2026007', 'Dodavatel Test s.r.o.')
            // Poplatek bez dokladu: v Pohodě zaúčtovaný, fakturu nemá.
            . self::bank('BAN0010003', 'expense', '2026-01-31', 50, '', 'Testovací banka');
        if ($laterPayment !== null) {
            $bank .= self::bank(self::LATER_PAID_BANK, 'expense', '2026-01-28', 726, self::LATER_PAID_VS, 'Dodavatel Test s.r.o.', self::PARTNER_ACCOUNT);
        }
        if ($unbooked) {
            // Pohyby bez zaúčtování (předkontace „Nevím"): úhrada vydané faktury v následujícím
            // roce podle VS, platba bez VS dvou stejných faktur, platba podle účtu dodavatele,
            // platba s párovacím symbolem a platba kartou bez účtu protistrany.
            $bank .= self::bank('BAN0010011', 'receipt', self::NEXT_YEAR . '-01-15', 1815, self::UNBOOKED_ISSUED_VS, 'Odběratel Test s.r.o.', self::PARTNER_ACCOUNT)
                . self::bank('BAN0010012', 'expense', '2026-03-10', 363, '', 'Dodavatel Test s.r.o.', self::PARTNER_ACCOUNT)
                . self::bank('BAN0010013', 'expense', '2026-04-10', 242, '', 'Dodavatel Test s.r.o.', self::PARTNER_ACCOUNT)
                . self::bank('BAN0010014', 'expense', '2026-05-05', 484, '', 'Dodavatel Test s.r.o.', self::OTHER_ACCOUNT, self::PARSYM_PURCHASE)
                . self::bank('BAN0010015', 'expense', '2026-06-01', 121, self::CARD_VS, 'Obchod kartou')
                . self::bank('BAN0010016', 'receipt', self::NEXT_YEAR . '-01-20', 1210, '260005', 'Odběratel Test s.r.o.', self::PARTNER_ACCOUNT);
        }

        $addressbook = '';
        foreach ([[1, 'Odběratel Test s.r.o.', self::CUSTOMER_ICO], [2, 'Dodavatel Test s.r.o.', self::VENDOR_ICO]] as [$id, $name, $ico]) {
            $addressbook .= '<lAdb:addressbook version="2.0"><adb:addressbookHeader><adb:id>' . $id . '</adb:id><adb:identity><typ:address><typ:company>' . $name . '</typ:company>'
                . '<typ:city>Brno</typ:city><typ:street>Testovací 1</typ:street><typ:zip>60200</typ:zip><typ:ico>' . $ico . '</typ:ico><typ:dic>CZ' . $ico . '</typ:dic></typ:address></adb:identity>'
                . '<adb:email>test@example.invalid</adb:email></adb:addressbookHeader></lAdb:addressbook>';
        }

        return [
            '01_ucetni_denik.xml' => '<lst:listAccountancy version="2.0" dateTimeStamp="2026-02-01T10:00:00" state="ok"><lst:accountancy version="2.0">' . $journal . '</lst:accountancy></lst:listAccountancy>',
            '02_uctova_osnova.xml' => '<lst:listAccount version="1.1" state="ok">' . $accounts . '</lst:listAccount>',
            '03_predkontace_pu.xml' => '<lst:listAccounting version="1.1" state="ok"><lst:itemAccounting id="1" code="1Fv" accounting="Tržby za služby" debit="311001" credit="602000" agenda="issuedInvoice"/></lst:listAccounting>',
            '06_cleneni_dph.xml' => '<lst:listClassificationVAT version="2.0" state="ok">' . $classes . '</lst:listClassificationVAT>',
            '12_faktury_issuedInvoice.xml' => '<lst:listInvoice version="2.0" state="ok">' . $issued . '</lst:listInvoice>',
            '20_faktury_receivedInvoice.xml' => '<lst:listInvoice version="2.0" state="ok">' . $received . '</lst:listInvoice>',
            '29_banka.xml' => '<lst:listBank version="2.0" state="ok">' . $bank . '</lst:listBank>',
            '30_adresar.xml' => '<lAdb:listAddressBook version="2.0" state="ok">' . $addressbook . '</lAdb:listAddressBook>',
            '31_bankovni_ucty.xml' => '<lst:listBankAccount version="2.0" state="ok"><lst:bankAccount version="2.0"><bka:bankAccountHeader><bka:ids>BAN</bka:ids>'
                . '<bka:numberAccount>' . self::BANK_ACCOUNT . '</bka:numberAccount><bka:codeBank>' . self::BANK_CODE . '</bka:codeBank><bka:nameBank>Testovací banka</bka:nameBank>'
                . '<bka:analyticAccount><typ:ids>221001</typ:ids></bka:analyticAccount></bka:bankAccountHeader></lst:bankAccount></lst:listBankAccount>',
        ];
    }

    private static function entry(string $source, string $number, string $text, float $amount, string $md, string $d, string $date): string
    {
        return '<act:accountingItem><act:source>' . $source . '</act:source><act:number><typ:numberRequested>' . $number . '</typ:numberRequested></act:number>'
            . '<act:text>' . $text . '</act:text><act:homeCurrency><typ:priceSum>' . $amount . '</typ:priceSum></act:homeCurrency>'
            . '<act:accounting><act:credit>' . $md . '</act:credit><act:debit>' . $d . '</act:debit></act:accounting><act:date>' . $date . '</act:date><act:dateTax>' . $date . '</act:dateTax></act:accountingItem>';
    }

    private static function partner(string $ns, string $name, string $ico, string $dic): string
    {
        return '<' . $ns . ':partnerIdentity><typ:address><typ:company>' . $name . '</typ:company><typ:city>Brno</typ:city><typ:street>Testovací 1</typ:street>'
            . '<typ:zip>60200</typ:zip><typ:ico>' . $ico . '</typ:ico><typ:dic>' . $dic . '</typ:dic></typ:address></' . $ns . ':partnerIdentity>';
    }

    private static function summary(string $ns, float $base, float $vat): string
    {
        return '<' . $ns . ':invoiceSummary><' . $ns . ':homeCurrency><typ:priceNone>0</typ:priceNone><typ:priceLow>0</typ:priceLow><typ:priceLowVAT rate="12">0</typ:priceLowVAT>'
            . '<typ:priceLowSum>0</typ:priceLowSum><typ:priceHigh>' . $base . '</typ:priceHigh><typ:priceHighVAT rate="21">' . $vat . '</typ:priceHighVAT>'
            . '<typ:priceHighSum>' . ($base + $vat) . '</typ:priceHighSum><typ:round><typ:priceRound>0</typ:priceRound></typ:round></' . $ns . ':homeCurrency></' . $ns . ':invoiceSummary>';
    }

    /**
     * Faktura bez úhrady, nebo zlikvidovaná bankovním dokladem `$paidBy` (úhrada nese jeho id;
     * `$paidByNumber` = opis čísla v úhradě, který se může lišit).
     */
    private static function invoice(string $type, string $number, string $vs, string $date, float $base, float $vat, string $account = '', string $paidBy = '', ?string $paidByNumber = null): string
    {
        $issued = $type === 'issuedInvoice';
        $due = date('Y-m-d', (int) strtotime($date . ' +14 days'));
        $total = $base + $vat;
        return '<lst:invoice version="2.0"><inv:invoiceHeader><inv:invoiceType>' . $type . '</inv:invoiceType><inv:number><typ:numberRequested>' . $number . '</typ:numberRequested></inv:number>'
            . '<inv:symVar>' . $vs . '</inv:symVar>' . ($issued ? '' : '<inv:originalDocument>D-' . $number . '</inv:originalDocument>')
            . '<inv:date>' . $date . '</inv:date><inv:dateTax>' . $date . '</inv:dateTax><inv:dateAccounting>' . $date . '</inv:dateAccounting><inv:dateDue>' . $due . '</inv:dateDue>'
            . ($issued ? '' : '<inv:dateKHDPH>' . $date . '</inv:dateKHDPH>')
            . '<inv:classificationVAT><typ:ids>' . ($issued ? 'UD' : 'PD') . '</typ:ids></inv:classificationVAT><inv:text>Služby</inv:text>'
            . ($issued ? self::partner('inv', 'Odběratel Test s.r.o.', self::CUSTOMER_ICO, 'CZ' . self::CUSTOMER_ICO) : self::partner('inv', 'Dodavatel Test s.r.o.', self::VENDOR_ICO, 'CZ' . self::VENDOR_ICO))
            . '<inv:paymentType><typ:paymentType>draft</typ:paymentType></inv:paymentType>'
            . ($account !== '' ? '<inv:paymentAccount><typ:accountNo>' . $account . '</typ:accountNo><typ:bankCode>' . self::PARTNER_BANK . '</typ:bankCode></inv:paymentAccount>' : '')
            . ($paidBy !== '' ? '<inv:liquidation><typ:amountHome>0</typ:amountHome></inv:liquidation>' : '<inv:liquidation><typ:amountHome>' . $total . '</typ:amountHome></inv:liquidation>')
            . '</inv:invoiceHeader>' . self::summary('inv', $base, $vat)
            . ($paidBy !== '' ? '<inv:liquidations><typ:liquidation><typ:id>' . abs(crc32($number)) . '</typ:id><typ:sourceAgenda>bank</typ:sourceAgenda><typ:sourceDocument>'
                . '<typ:id>' . abs(crc32($paidBy)) . '</typ:id><typ:number>' . ($paidByNumber ?? $paidBy) . '</typ:number></typ:sourceDocument><typ:amount>' . $total . '</typ:amount></typ:liquidation></inv:liquidations>' : '')
            . '</lst:invoice>';
    }

    /** `$account` prázdný = platba kartou (POHODA ji vede bez účtu protistrany). */
    private static function bank(string $number, string $type, string $date, float $amount, string $vs, string $partner, string $account = '', string $symPar = ''): string
    {
        return '<lst:bank version="2.0"><bnk:bankHeader><bnk:id>' . abs(crc32($number)) . '</bnk:id><bnk:bankType>' . $type . '</bnk:bankType><bnk:account><typ:ids>BAN</typ:ids></bnk:account>'
            . '<bnk:number>' . $number . '</bnk:number><bnk:statementNumber><bnk:statementNumber>001</bnk:statementNumber></bnk:statementNumber>'
            . '<bnk:symVar>' . $vs . '</bnk:symVar>' . ($symPar !== '' ? '<bnk:symPar>' . $symPar . '</bnk:symPar>' : '')
            . '<bnk:dateStatement>2026-01-31</bnk:dateStatement><bnk:datePayment>' . $date . '</bnk:datePayment>'
            . '<bnk:text>Platba</bnk:text><bnk:partnerIdentity><typ:address><typ:company>' . $partner . '</typ:company></typ:address></bnk:partnerIdentity>'
            . ($account !== '' ? '<bnk:paymentAccount><typ:accountNo>' . $account . '</typ:accountNo><typ:bankCode>' . self::PARTNER_BANK . '</typ:bankCode></bnk:paymentAccount>' : '')
            . '</bnk:bankHeader>'
            . '<bnk:bankSummary><bnk:homeCurrency><typ:priceNone>' . $amount . '</typ:priceNone><typ:priceLowSum>0</typ:priceLowSum><typ:priceHighSum>0</typ:priceHighSum>'
            . '<typ:round><typ:priceRound>0</typ:priceRound></typ:round></bnk:homeCurrency></bnk:bankSummary></lst:bank>';
    }

    /** Odpověď POHODY v kódování Windows-1250, jak ji export zapisuje. */
    private static function file(string $path, string $list): void
    {
        $xml = '<?xml version="1.0" encoding="Windows-1250"?>' . "\n"
            . '<rsp:responsePack version="2.0" id="001" state="ok" programVersion="SYNTETICKY 1.0" ico="' . self::ICO . '" ' . self::NS . '>'
            . '<rsp:responsePackItem version="2.0" id="001-1" state="ok">' . $list . '</rsp:responsePackItem></rsp:responsePack>';
        file_put_contents($path, (string) iconv('UTF-8', 'Windows-1250', $xml));
    }
}
