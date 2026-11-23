<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PohodaMdbAccounting;

/**
 * Syntetický převod datového souboru POHODY (`89_ucetnictvi_mdb.xml`): tabulky MDB
 * tak, jak je zapíše převodník. Jen vymyšlená data, IČO a rok jako {@see SyntheticPohodaExport}.
 */
final class SyntheticPohodaMdbExport
{
    /** Popis nezaúčtovaného pohybu ve výpisu {@see writeSharedBankNumber()}. */
    public const UNBOOKED_TEXT = 'Výdaj bez zaúčtování';

    /** Tabulky, které převod z MDB vyžaduje (mohou být prázdné). */
    private const TABLES = ['pUD', 'pOS', 'sDPH', 'sDPHTp', 'pPK', 'FA', 'FApol', 'AD', 'sUcet', 'sZeme', 'sCMeny', 'sFormUh', 'BV', 'BVpol', 'HO', 'HOpol', 'pINT', 'pINTpol', 'Uhrady'];

    /**
     * Agenda s výpisem pod jediným číslem dokladu, jako {@see SyntheticPohodaExport::withSharedBankNumber()}:
     * tři pohyby BAN0010050 téhož dne, příjem 500 Kč a poplatek 20 Kč mají v deníku řádek
     * s id svého bankovního dokladu (`pUD.RelAgID`), výdaj 20 Kč s popisem {@see UNBOOKED_TEXT}
     * řádek nemá. Je ve výpisu před poplatkem a zní na stejnou částku: podle částky by se
     * zaúčtovaný pohyb poznat nedal, rozhoduje id dokladu.
     *
     * @return string složka agendy
     */
    public static function writeSharedBankNumber(string $root): string
    {
        $date = '2026-04-10T00:00:00';
        $bank = static fn (string $id, string $type, string $amount, string $text): array =>
            ['ID' => $id, 'RelTpBV' => $type, 'Cislo' => SyntheticPohodaExport::SHARED_BANK, 'Datum' => $date, 'DatPlat' => $date,
                'Kc0' => $amount, 'RefUcet' => '1', 'Vypis' => '001/1', 'SText' => $text, 'Firma' => 'Banka Test'];
        $journal = static fn (string $id, string $agenda, string $source, string $day, string $amount, string $md, string $d): array =>
            ['ID' => $id, 'RelUdAg' => $agenda, 'RelAgID' => $source, 'Cislo' => $agenda === '28' ? SyntheticPohodaExport::SHARED_BANK : '',
                'Datum' => $day, 'Kc' => $amount, 'UMD' => $md, 'UD' => $d, 'SText' => 'Řádek'];
        return self::write($root, [
            'pOS' => array_map(static fn (string $code, string $name): array => ['Ucet' => $code, 'Nazev' => $name],
                ['221001', '261000', '411000', '568000', '701000'],
                ['Bankovní účet', 'Peníze na cestě', 'Základní kapitál', 'Ostatní finanční náklady', 'Počáteční účet rozvažný']),
            'sUcet' => [['ID' => '1', 'RelJeUcet' => '2', 'IDS' => 'BAN', 'SText' => SyntheticPohodaExport::BANK_ACCOUNT,
                'KodBanky' => SyntheticPohodaExport::BANK_CODE, 'Banka' => 'Testovací banka', 'AUcet' => '221001']],
            'pUD' => [
                $journal('1', '63', '0', '2026-01-01T00:00:00', '100000', '221001', '701000'),
                $journal('2', '63', '0', '2026-01-01T00:00:00', '100000', '701000', '411000'),
                $journal('3', '28', '11', $date, '500', '221001', '261000'),
                $journal('4', '28', '13', $date, '20', '568000', '221001'),
            ],
            'BV' => [$bank('11', '1', '500', 'Převod'), $bank('12', '2', '20', self::UNBOOKED_TEXT), $bank('13', '2', '20', 'Poplatek')],
        ]);
    }

    /**
     * Datový soubor s korunovým účtem BAN a účtem BANE v EUR (`sUcet.RefCM` do číselníku měn
     * `sCMeny`), stejné částky jako {@see SyntheticPohodaExport::withEurBankAccount()}:
     * počáteční stav 1 000 EUR (24 245 Kč) v otevíracím zápisu, příjem 500 EUR kurzem 24,20
     * (12 100 Kč, 221002/261000) a poplatek 2 EUR kurzem 24,25 (48,50 Kč). Poplatek částku
     * v měně nenese (`RefCM` prázdné): měnu určí účet, částka se přepočte uloženým kurzem ČNB.
     *
     * @return string složka agendy
     */
    public static function writeEurBankAccount(string $root): string
    {
        $journal = static fn (string $id, string $agenda, string $source, string $number, string $day, string $amount, string $md, string $d): array =>
            ['ID' => $id, 'RelUdAg' => $agenda, 'RelAgID' => $source, 'Cislo' => $number, 'Datum' => $day, 'Kc' => $amount, 'UMD' => $md, 'UD' => $d, 'SText' => 'Řádek'];
        $bank = static fn (string $id, string $type, string $number, string $day, string $amount, string $currency, string $rate, string $foreign): array =>
            ['ID' => $id, 'RelTpBV' => $type, 'Cislo' => $number, 'Datum' => $day, 'DatPlat' => $day, 'Kc0' => $amount, 'RefUcet' => '2', 'Vypis' => '001/1',
                'SText' => 'Platba EUR', 'Firma' => 'Banka Test', 'RefCM' => $currency, 'CmKurs' => $rate, 'CmMnoz' => '1', 'CmCelkem' => $foreign];
        return self::write($root, [
            'pOS' => array_map(static fn (string $code, string $name): array => ['Ucet' => $code, 'Nazev' => $name],
                ['221001', '221002', '261000', '411000', '568000', '701000'],
                ['Bankovní účet', 'Bankovní účet EUR', 'Peníze na cestě', 'Základní kapitál', 'Ostatní finanční náklady', 'Počáteční účet rozvažný']),
            'sCMeny' => [['ID' => '7', 'Kod' => 'EUR']],
            'sUcet' => [
                ['ID' => '1', 'RelJeUcet' => '2', 'IDS' => 'BAN', 'SText' => SyntheticPohodaExport::BANK_ACCOUNT, 'KodBanky' => SyntheticPohodaExport::BANK_CODE, 'Banka' => 'Testovací banka', 'AUcet' => '221001'],
                ['ID' => '2', 'RelJeUcet' => '2', 'IDS' => SyntheticPohodaExport::EUR_ACCOUNT, 'SText' => SyntheticPohodaExport::EUR_ACCOUNT_NUMBER,
                    'KodBanky' => SyntheticPohodaExport::BANK_CODE, 'Banka' => 'Testovací banka', 'AUcet' => '221002', 'RefCM' => '7'],
            ],
            'pUD' => [
                $journal('1', '63', '0', '', '2026-01-01T00:00:00', '100000', '221001', '701000'),
                $journal('2', '63', '0', '', '2026-01-01T00:00:00', '100000', '701000', '411000'),
                $journal('3', '63', '0', '', '2026-01-01T00:00:00', '24245', '221002', '701000'),
                $journal('4', '63', '0', '', '2026-01-01T00:00:00', '24245', '701000', '411000'),
                $journal('5', '28', '21', 'BANE0001', '2026-02-10T00:00:00', '12100', '221002', '261000'),
                $journal('6', '28', '22', 'BANE0002', '2026-02-27T00:00:00', '48.5', '568000', '221002'),
            ],
            'BV' => [
                $bank('21', '1', 'BANE0001', '2026-02-10T00:00:00', '12100', '7', '24.2', '500'),
                $bank('22', '2', 'BANE0002', '2026-02-27T00:00:00', '48.5', '0', '0', '0'),
            ],
        ]);
    }

    /** Přijatá faktura s různým DUZP a datem odpočtu ({@see writeSplitVatDates()}). */
    public const SPLIT_PURCHASE = '26PF0071';

    /**
     * Agenda s přijatou fakturou 26PF0071 (10 000 + 2 100 Kč, členění `PD`): „Datum KH DPH"
     * (`DatKHDPH`, DUZP z dokladu dodavatele) 27. 2. 2026, „Datum odp./doruč." (`DatZdPln`)
     * 1. 3. 2026. Stejný doklad jako {@see SyntheticPohodaExport::withSplitVatDates()} v XML.
     *
     * @return string složka agendy
     */
    public static function writeSplitVatDates(string $root): string
    {
        $journal = static fn (string $id, string $agenda, string $source, string $number, string $day, string $amount, string $md, string $d): array =>
            ['ID' => $id, 'RelUdAg' => $agenda, 'RelAgID' => $source, 'Cislo' => $number, 'Datum' => $day, 'Kc' => $amount, 'UMD' => $md, 'UD' => $d, 'SText' => 'Řádek'];
        return self::write($root, [
            'pOS' => array_map(static fn (string $code, string $name): array => ['Ucet' => $code, 'Nazev' => $name],
                ['221001', '321001', '343011', '411000', '518000', '701000'],
                ['Bankovní účet', 'Dodavatelé', 'DPH na vstupu', 'Základní kapitál', 'Ostatní služby', 'Počáteční účet rozvažný']),
            'sDPHTp' => [['ID' => '1', 'Radky' => '40, 41']],
            'sDPH' => [['ID' => '283', 'IDS' => 'PD', 'SText' => 'Tuzemské plnění', 'RefTpDph' => '1', 'RelVlivKHDPH' => '10']],
            'pUD' => [
                $journal('1', '63', '0', '', '2026-01-01T00:00:00', '100000', '221001', '701000'),
                $journal('2', '63', '0', '', '2026-01-01T00:00:00', '100000', '701000', '411000'),
                $journal('3', '3', '1', self::SPLIT_PURCHASE, '2026-03-01T00:00:00', '10000', '518000', '321001'),
                $journal('4', '3', '1', self::SPLIT_PURCHASE, '2026-03-01T00:00:00', '2100', '343011', '321001'),
            ],
            'FA' => [[
                'ID' => '1', 'Cislo' => self::SPLIT_PURCHASE, 'RelTpFak' => '11', 'Datum' => '2026-03-01T00:00:00', 'DatZdPln' => '2026-03-01T00:00:00',
                'DatUcP' => '2026-03-01T00:00:00', 'DatSplat' => '2026-03-15T00:00:00', 'DatKHDPH' => '2026-02-27T00:00:00',
                'PDoklad' => 'D-' . self::SPLIT_PURCHASE, 'VarSym' => '20260071', 'RelTpDPH' => '283',
                'Firma' => 'Dodavatel Test s.r.o.', 'ICO' => SyntheticPohodaExport::VENDOR_ICO, 'DIC' => 'CZ' . SyntheticPohodaExport::VENDOR_ICO,
                'Kc0' => '0', 'Kc1' => '0', 'KcDPH1' => '0', 'Kc2' => '10000', 'KcDPH2' => '2100', 'Kc3' => '0', 'KcDPH3' => '0', 'KcZaokr' => '0', 'KcLikv' => '12100',
            ]],
        ]);
    }

    /** Otevřená vydaná objednávka s částečně dodanou položkou ({@see writeOrders()}). */
    public const OPEN_ISSUED_ORDER = '26OV0001';
    /** Vydaná objednávka s příznakem vyřízeno. */
    public const FULFILLED_ISSUED_ORDER = '26OV0002';
    /** Vydaná objednávka bez příznaku, ale se všemi položkami dodanými. */
    public const DELIVERED_ISSUED_ORDER = '26OV0003';
    /** Otevřená přijatá objednávka od odběratele. */
    public const OPEN_RECEIVED_ORDER = '26OP0001';

    /**
     * Agenda s objednávkami nad kartami skladu {@see SyntheticPohodaExport::withStock()}
     * (kabel `K-001` je karta 101 v hlavním skladu, šroub `M-001` karta 103 v materiálovém):
     *   - {@see OPEN_ISSUED_ORDER} dodavateli: kabel 10 m za 950 Kč bez DPH, dodáno 4 m;
     *     doprava bez karty 150 Kč; položka s kartou 999, která ve skladu není (2 ks za 40 Kč);
     *     textový řádek bez množství; termín dodání 20. 3. 2026,
     *   - {@see FULFILLED_ISSUED_ORDER} s příznakem vyřízeno a {@see DELIVERED_ISSUED_ORDER}
     *     s celou položkou dodanou (bez příznaku) se nepřevedou,
     *   - {@see OPEN_RECEIVED_ORDER} od odběratele: 100 šroubů za 50 Kč bez DPH.
     * Partneři jsou v adresáři (AD 5 dodavatel, AD 6 odběratel).
     *
     * @return string složka agendy
     */
    public static function writeOrders(string $root): string
    {
        $journal = static fn (string $id, string $amount, string $md, string $d): array =>
            ['ID' => $id, 'RelUdAg' => '63', 'RelAgID' => '0', 'Cislo' => '', 'Datum' => '2026-01-01T00:00:00', 'Kc' => $amount, 'UMD' => $md, 'UD' => $d, 'SText' => 'Počáteční stav'];
        $order = static fn (string $id, string $number, string $type, string $partner, string $fulfilled): array =>
            ['ID' => $id, 'Cislo' => $number, 'RelTpObj' => $type, 'Datum' => '2026-03-02T00:00:00', 'DatDod' => '2026-03-20T00:00:00',
                'Vyrizeno' => $fulfilled, 'SText' => 'Objednávka ' . $number, 'RefAD' => $partner, 'RefCM' => '0', 'CmKurs' => '0', 'CmMnoz' => '0'];
        $item = static fn (string $id, string $order, string $card, string $text, string $qty, string $delivered, string $price, string $unit = 'ks'): array =>
            ['ID' => $id, 'RefAg' => $order, 'RefSKz' => $card, 'SText' => $text, 'Mnozstvi' => $qty, 'Dodano' => $delivered, 'MJ' => $unit, 'MJKoef' => '1',
                'Kc' => $price, 'KcDPH' => (string) round((float) $price * 0.21, 2), 'RelSzDPH' => '2', 'ProcentoDPH' => '21', 'SDph' => 'false', 'OrderFld' => $id];
        return self::write($root, [
            'pOS' => array_map(static fn (string $code, string $name): array => ['Ucet' => $code, 'Nazev' => $name],
                ['221001', '411000', '701000'], ['Bankovní účet', 'Základní kapitál', 'Počáteční účet rozvažný']),
            'pUD' => [$journal('1', '100000', '221001', '701000'), $journal('2', '100000', '701000', '411000')],
            'AD' => [
                ['ID' => '5', 'Firma' => 'Dodavatel Test s.r.o.', 'ICO' => SyntheticPohodaExport::VENDOR_ICO, 'Obec' => 'Brno'],
                ['ID' => '6', 'Firma' => 'Odběratel Test s.r.o.', 'ICO' => SyntheticPohodaExport::CUSTOMER_ICO, 'Obec' => 'Praha'],
            ],
            'OBJ' => [
                $order('1', self::OPEN_ISSUED_ORDER, '2', '5', 'false'),
                $order('2', self::FULFILLED_ISSUED_ORDER, '2', '5', 'true'),
                $order('3', self::DELIVERED_ISSUED_ORDER, '2', '5', 'false'),
                $order('4', self::OPEN_RECEIVED_ORDER, '1', '6', 'false'),
            ],
            'OBJpol' => [
                $item('1', '1', '101', 'Kabel', '10', '4', '950', 'm'),
                $item('2', '1', '0', 'Doprava', '1', '0', '150'),
                $item('3', '1', '999', 'Neznámá karta', '2', '0', '40'),
                $item('4', '1', '0', 'Dodejte prosím do skladu HL', '0', '0', '0'),
                $item('5', '2', '101', 'Kabel', '5', '0', '475', 'm'),
                $item('6', '3', '103', 'Šroub', '50', '50', '25'),
                $item('7', '4', '103', 'Šroub', '100', '0', '50'),
            ],
        ]);
    }

    /**
     * Zapíše tabulky do složky agendy `<kořen>/<IČO>_<rok>`.
     *
     * @param array<string,list<array<string,string>>> $data řádky tabulek
     * @return string složka agendy
     */
    public static function write(string $root, array $data): string
    {
        $dir = rtrim($root, '/\\') . '/' . SyntheticPohodaExport::ICO . '_' . SyntheticPohodaExport::YEAR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $tables = array_fill_keys(self::TABLES, []);
        $tables['sKonfig'] = [['ICO' => SyntheticPohodaExport::ICO, 'Rok' => (string) SyntheticPohodaExport::YEAR]];
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'utf-8');
        $xml->startElement('pohodaMdbAccounting');
        foreach (['formatVersion' => '1', 'ico' => SyntheticPohodaExport::ICO, 'year' => (string) SyntheticPohodaExport::YEAR, 'programVersion' => 'test'] as $key => $value) {
            $xml->writeAttribute($key, $value);
        }
        foreach (array_replace($tables, $data) as $name => $rows) {
            $xml->startElement('table');
            $xml->writeAttribute('name', $name);
            foreach ($rows as $row) {
                $xml->startElement('row');
                foreach ($row as $key => $value) {
                    $xml->writeElement($key, $value);
                }
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->endElement();
        file_put_contents($dir . '/' . PohodaMdbAccounting::FILE, $xml->outputMemory());
        return $dir;
    }
}
