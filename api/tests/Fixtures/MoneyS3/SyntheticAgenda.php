<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\Ms3Table;

/**
 * Syntetická agenda Money S3 — dva účetní roky vymyšlené firmy.
 *
 * Všechno je smyšlené: firma, partneři, čísla dokladů. IČO jsou zjevně vzorová
 * (s platnou kontrolní číslicí, protože import IČO neověřuje, ale partnerské účty
 * a výkazy s ním pracují), bankovní účty projdou kontrolou mod 11.
 *
 * Obsah je poskládaný tak, aby prošel všemi pastmi, které převod z Money má:
 *   - 2024 začíná počátečními stavy (zdroj XP) včetně nulového a degenerovaného řádku,
 *   - smazaný řádek deníku (`Del`), nulový řádek a řádek se zápornou částkou,
 *   - zápis k 1. 1. 2025 (epocha dat — o den dřív by spadl do roku 2024),
 *   - přijatá i vydaná faktura uhrazená bankou (`UDoklad`), pokladní doklad, bankovní
 *     pohyby včetně vratky poplatku,
 *   - počáteční stavy 2025 = konečné stavy 2024 + VH na 431 (uzávěrka bez zdvojení),
 *   - doklady 2025, které se nesmí převzít naslepo jako tuzemská faktura: zálohová
 *     přijatá i vydaná (jiný `Druh` než `N`) vedle konečné faktury, dobropis, doklad
 *     s členěním DPH přenesené daňové povinnosti a faktura v cizí měně,
 *   - číslo dokladu z roku 2024 znovu v roce 2025 (Money čísluje řadu každý rok od
 *     začátku) a úhrada kartou (`Uhrada`),
 *   - smazaná faktura (`FlagDel`) se stejným číslem jako živá, doklad knihy 2025 s datem
 *     31. 12. 2024 a účet ze skupiny 61, kterou osnova od roku 2016 nemá,
 *   - uzávěrka roku 2024 z Money (zdroj XZ), pokladní příjem se zápornou částkou (vratka)
 *     a nulový pokladní doklad,
 *   - faktura 2024, kterou Money nezaúčtovalo, a daňový doklad k záloze účtovaný mimo 321.
 *
 * Hodnoty v {@see trialBalanceCsv()} jsou spočtené ručně, ne z definice níže —
 * rekonciliace proti nim proto kontroluje celý řetěz nezávisle.
 */
final class SyntheticAgenda
{
    public const ICO = '12345679';
    public const NAME = 'Vzorová účetní s.r.o.';
    public const VENDOR_ICO = '87654326';
    public const CUSTOMER_ICO = '11223341';
    public const SECOND_VENDOR_ICO = '22334459';
    public const ADVANCE_VENDOR_ICO = '33445567';
    public const SECOND_ADVANCE_VENDOR_ICO = '44556675';
    public const ADVANCE_CUSTOMER_ICO = '55667783';
    public const VERSION = '26.600';

    private const JOURNAL_FIELDS = [
        ['Cislo', 'L', 4], ['Zdroj', 'C', 2], ['Doklad', 'C', 10], ['Datum', 'D', 2], ['DatPlnDPH', 'D', 2],
        ['Popis', 'C', 50], ['UcMD', 'C', 6], ['UcD', 'C', 6], ['Castka', 'E', 10], ['Stred', 'C', 10], ['Zakazka', 'C', 10], ['ParICO', 'C', 12], ['Del', 'B', 1],
    ];
    private const CHART_FIELDS = [['Ucet', 'C', 6], ['Nazev', 'C', 50]];
    private const ASSET_FIELDS = [
        ['Cislo', 'L', 4], ['TypMajetku', 'L', 4], ['Nazev', 'C', 60], ['InventCisl', 'C', 20], ['Druh', 'C', 1],
        ['ZpusobOdpi', 'C', 1], ['OdpisSkupi', 'C', 2], ['DatZarazen', 'D', 2], ['DatVyrazen', 'D', 2],
        ['PrUcMaj', 'C', 6], ['PrUcOpr', 'C', 6], ['UcOdpPorC', 'E', 10], ['Umisteno', 'C', 40],
        ['SDodavatel', 'C', 60], ['CDodavatel', 'L', 4], ['ZpVyrazeni', 'C', 20],
        ['KodSKP', 'C', 10], ['FL_LGMajSk', 'L', 4], ['UcRovnyDan', 'L', 4], ['DatZDanOdp', 'D', 2],
    ];
    private const ASSET_MOVE_FIELDS = [
        ['CisloMajet', 'L', 4], ['Cislo', 'L', 4], ['Datum', 'D', 2], ['Typ', 'C', 1], ['Castka', 'E', 10],
        ['ZustCena', 'E', 10], ['Doklad', 'C', 20], ['Popis', 'C', 40], ['PrUcMaj', 'C', 6], ['PrUcOpr', 'C', 6],
        ['OdpZustCen', 'L', 4],
    ];
    private const PURCHASE_FIELDS = [
        ['Doklad', 'C', 10], ['Storno', 'B', 1], ['PrijatDokl', 'C', 20], ['VarSymbol', 'C', 10], ['D_ICO', 'C', 12], ['D_DIC', 'C', 14],
        ['D_Nazev', 'C', 60], ['D_Ulice', 'C', 40], ['D_Mesto', 'C', 40], ['D_Psc', 'C', 10],
        ['Vystaveno', 'D', 2], ['DatUcPr', 'D', 2], ['PlnenoDPH', 'D', 2], ['Splatno', 'D', 2], ['Doruceno', 'D', 2],
        ['KodDPH', 'C', 12], ['Druh', 'C', 1], ['Dobropis', 'B', 1], ['Uhrada', 'C', 20],
        ['Zaklad_0', 'E', 10], ['Zaklad_1', 'E', 10], ['Zaklad_2', 'E', 10], ['SazbaDPH1', 'E', 10], ['SazbaDPH2', 'E', 10],
        ['DPH_1', 'E', 10], ['DPH_2', 'E', 10],
        ['CelkemSDPH', 'E', 10], ['Uhrazeno', 'D', 2], ['UDoklad', 'C', 10], ['Popis', 'C', 50], ['BarCode', 'C', 20],
        ['Mena', 'C', 3], ['PocetJedn', 'L', 4], ['Kurs', 'E', 10], ['Neuctovat', 'B', 1], ['FlagDel', 'B', 1],
        ['ValutyZak0', 'E', 10], ['ValutyZak1', 'E', 10], ['ValutyZak2', 'E', 10], ['ValutyDPH1', 'E', 10], ['ValutyDPH2', 'E', 10],
    ];
    private const ISSUED_FIELDS = [
        ['Doklad', 'C', 10], ['Storno', 'B', 1], ['VarSymbol', 'C', 10], ['O_ICO', 'C', 12], ['O_DIC', 'C', 14], ['O_Nazev', 'C', 60],
        ['O_Ulice', 'C', 40], ['O_Mesto', 'C', 40], ['O_Psc', 'C', 10],
        ['Vystaveno', 'D', 2], ['DatUcPr', 'D', 2], ['PlnenoDPH', 'D', 2], ['Splatno', 'D', 2],
        ['KodDPH', 'C', 12], ['Druh', 'C', 1], ['Dobropis', 'B', 1], ['Uhrada', 'C', 20],
        ['Zaklad_0', 'E', 10], ['Zaklad_1', 'E', 10], ['Zaklad_2', 'E', 10], ['SazbaDPH1', 'E', 10], ['SazbaDPH2', 'E', 10],
        ['DPH_1', 'E', 10], ['DPH_2', 'E', 10],
        ['CelkemSDPH', 'E', 10], ['Uhrazeno', 'D', 2], ['UDoklad', 'C', 10], ['Popis', 'C', 50],
        ['Mena', 'C', 3], ['PocetJedn', 'L', 4], ['Kurs', 'E', 10], ['Neuctovat', 'B', 1],
    ];
    private const REGISTER_FIELDS = [
        ['Zkrat', 'C', 6], ['Popis', 'C', 40], ['UcPokl', 'C', 1], ['PrimUcet', 'C', 6], ['Ucet', 'C', 20], ['BKod', 'C', 4], ['IBAN', 'C', 34],
        ['Mena', 'C', 3], ['PSKurz', 'E', 10],
    ];
    private const CASH_FIELDS = [
        ['Doklad', 'C', 10], ['Pokl', 'C', 6], ['Vydej', 'B', 1], ['PrKont', 'C', 6], ['DatVyst', 'D', 2], ['DatUplDPH', 'D', 2],
        ['DatUcPr', 'D', 2], ['AdNazev', 'C', 60], ['AdICO', 'C', 12], ['AdDIC', 'C', 14], ['Popis', 'C', 50], ['Celkem', 'E', 10],
        ['BarCode', 'C', 20], ['Cleneni', 'C', 12], ['ZSazba', 'E', 10], ['ZaklZS', 'E', 10], ['DPHZS', 'E', 10],
    ];
    private const BANK_FIELDS = [
        ['Doklad', 'C', 10], ['Ucet', 'C', 6], ['Vydej', 'B', 1], ['DatUcPr', 'D', 2], ['DatPlat', 'D', 2], ['Celkem', 'E', 10],
        ['VarSym', 'C', 20], ['AdNazev', 'C', 60], ['Popis', 'C', 50], ['Vypis', 'L', 4],
        ['Mena', 'C', 3], ['Kurs', 'E', 10], ['ValutyKUhr', 'E', 10],
    ];
    private const RULE_FIELDS = [['Zkrat', 'C', 6], ['Popis', 'C', 40], ['UcMD', 'C', 6], ['UcD', 'C', 6]];

    /** Členění DPH tuzemského přijatého plnění s nárokem na odpočet (řádky 40 a 41 přiznání). */
    public const KOD_DPH_PURCHASE = '19Ř40,41';
    /** Členění DPH tuzemského uskutečněného plnění (řádky 1 a 2 přiznání). */
    public const KOD_DPH_SALE = '19Ř01,02';
    /** Členění DPH přenesené daňové povinnosti na straně příjemce (řádky 10 a 43). */
    public const KOD_DPH_REVERSE_CHARGE = '19Ř10,43';

    /**
     * Celá agenda jako soubory: relativní cesta => obsah.
     *
     * @return array<string,string>
     */
    public static function files(): array
    {
        $vendor = [
            'D_ICO' => self::VENDOR_ICO, 'D_DIC' => 'CZ' . self::VENDOR_ICO, 'D_Nazev' => 'Dodavatel Alfa s.r.o.',
            'D_Ulice' => 'Vzorová 1', 'D_Mesto' => 'Praha', 'D_Psc' => '110 00',
            'SazbaDPH1' => 12.0, 'SazbaDPH2' => 21.0, 'Druh' => 'N', 'KodDPH' => self::KOD_DPH_PURCHASE, 'Uhrada' => 'převodem',
        ];
        $customer = [
            'O_ICO' => self::CUSTOMER_ICO, 'O_DIC' => 'CZ' . self::CUSTOMER_ICO,
            'O_Nazev' => 'Odběratel Beta a.s.', 'O_Ulice' => 'Ukázková 7', 'O_Mesto' => 'Ostrava', 'O_Psc' => '702 00',
            'SazbaDPH1' => 12.0, 'SazbaDPH2' => 21.0, 'Druh' => 'N', 'KodDPH' => self::KOD_DPH_SALE, 'Uhrada' => 'převodem',
        ];
        $purchaseDates = static fn (string $date): array => [
            'Vystaveno' => $date, 'DatUcPr' => $date, 'PlnenoDPH' => $date, 'Splatno' => $date, 'Doruceno' => $date,
        ];
        $issuedDates = static fn (string $date): array => [
            'Vystaveno' => $date, 'DatUcPr' => $date, 'PlnenoDPH' => $date, 'Splatno' => $date,
        ];
        $chart = [
            ['Ucet' => '211000', 'Nazev' => 'Pokladna'],
            ['Ucet' => '221001', 'Nazev' => 'Běžný účet'],
            ['Ucet' => '221002', 'Nazev' => 'Druhý běžný účet'],
            ['Ucet' => '311000', 'Nazev' => 'Odběratelé'],
            ['Ucet' => '314000', 'Nazev' => 'Poskytnuté zálohy'],
            ['Ucet' => '315000', 'Nazev' => 'Ostatní pohledávky'],
            ['Ucet' => '321000', 'Nazev' => 'Dodavatelé'],
            ['Ucet' => '325000', 'Nazev' => 'Ostatní závazky'],
            ['Ucet' => '343100', 'Nazev' => 'DPH na vstupu'],
            ['Ucet' => '343200', 'Nazev' => 'DPH na výstupu'],
            ['Ucet' => '411000', 'Nazev' => 'Základní kapitál'],
            ['Ucet' => '431000', 'Nazev' => 'Výsledek hospodaření ve schvalovacím řízení'],
            ['Ucet' => '501100', 'Nazev' => 'Spotřeba materiálu'],
            ['Ucet' => '518000', 'Nazev' => 'Ostatní služby'],
            ['Ucet' => '568000', 'Nazev' => 'Ostatní finanční náklady'],
            ['Ucet' => '602000', 'Nazev' => 'Tržby z prodeje služeb'],
            ['Ucet' => '613000', 'Nazev' => 'Změna stavu výrobků'],
            ['Ucet' => '701000', 'Nazev' => 'Počáteční účet rozvažný'],
        ];
        $registers = [
            ['Zkrat' => 'PO', 'Popis' => 'Hlavní pokladna', 'UcPokl' => 'P', 'PrimUcet' => '211000'],
            ['Zkrat' => 'BU', 'Popis' => 'Běžný účet', 'UcPokl' => 'U', 'PrimUcet' => '221001', 'Ucet' => '3000000004', 'BKod' => '0100'],
            ['Zkrat' => 'BU2', 'Popis' => 'Druhý běžný účet', 'UcPokl' => 'U', 'PrimUcet' => '221002', 'Ucet' => '1000000005', 'BKod' => '0100'],
        ];
        $rules = [
            ['Zkrat' => 'PF001', 'Popis' => 'Nákup služeb', 'UcMD' => '518000', 'UcD' => '321000'],
            ['Zkrat' => 'PV001', 'Popis' => 'Výdej na materiál', 'UcMD' => '501100', 'UcD' => '211000'],
            ['Zkrat' => 'XX001', 'Popis' => 'Nedosazená předkontace', 'UcMD' => 'xxxxxx', 'UcD' => '211000'],
        ];

        $files = [
            'AgendaInfo.ini' => (string) iconv('UTF-8', 'CP1250', "[Agenda]\r\nNázev=" . self::NAME . "\r\nIČO=" . self::ICO
                . "\r\nVersion=" . self::VERSION . "\r\nDatum=10.01.2026 08:15\r\n"),
            'Agenda.DAT' => Ms3FixtureWriter::table([['Section1', 'C', 30], ['Variable', 'C', 20], ['Value', 'C', 60]], [
                ['Section1' => 'Údaje o firmě', 'Variable' => 'Název', 'Value' => self::NAME],
                ['Section1' => 'Údaje o firmě', 'Variable' => 'Ulice', 'Value' => 'Účetní 12'],
                ['Section1' => 'Údaje o firmě', 'Variable' => 'Místo', 'Value' => 'Brno'],
                ['Section1' => 'Údaje o firmě', 'Variable' => 'PSČ', 'Value' => '602 00'],
                ['Section1' => 'Údaje o firmě', 'Variable' => 'IČO', 'Value' => self::ICO],
                ['Section1' => 'Údaje o firmě', 'Variable' => 'DIČ', 'Value' => 'CZ' . self::ICO],
                ['Section1' => 'Tisk', 'Variable' => 'Název', 'Value' => 'nesouvisí s firmou'],
            ], 7),
            'AdresarF.DAT' => Ms3FixtureWriter::table([
                ['Cislo', 'L', 4], ['Nazev', 'C', 60], ['ICO', 'C', 12], ['DIC', 'C', 14], ['Ulice', 'C', 40],
                ['Misto', 'C', 40], ['PSC', 'C', 10], ['EMail', 'C', 60], ['TelCislo', 'C', 20], ['Del', 'B', 1],
                ['Stat', 'C', 40],
            ], [
                ['Cislo' => 1, 'Nazev' => self::NAME, 'ICO' => self::ICO, 'DIC' => 'CZ' . self::ICO, 'Ulice' => 'Účetní 12', 'Misto' => 'Brno', 'PSC' => '602 00'],
                ['Cislo' => 2, 'Nazev' => 'Dodavatel Alfa s.r.o.', 'ICO' => self::VENDOR_ICO, 'DIC' => 'CZ' . self::VENDOR_ICO,
                    'Ulice' => 'Vzorová 1', 'Misto' => 'Praha', 'PSC' => '110 00', 'EMail' => 'fakturace@example.invalid'],
                ['Cislo' => 3, 'Nazev' => 'Odběratel Beta a.s.', 'ICO' => self::CUSTOMER_ICO, 'DIC' => 'CZ' . self::CUSTOMER_ICO,
                    'Ulice' => 'Ukázková 7', 'Misto' => 'Ostrava', 'PSC' => '702 00'],
                ['Cislo' => 4, 'Nazev' => 'Smazaný partner', 'ICO' => '', 'Del' => 1],
                ['Cislo' => 5, 'Nazev' => 'Lieferant Gamma GmbH', 'ICO' => '', 'DIC' => 'DE123456789',
                    'Ulice' => 'Musterstraße 1', 'Misto' => 'Berlin', 'PSC' => '10115', 'Stat' => 'Německo'],
                // Money pustí do pole DIČ i rejstříkové číslo; zemi pak nese jen adresa.
                ['Cislo' => 6, 'Nazev' => 'Lieferant Delta GmbH', 'ICO' => '', 'DIC' => 'FN123456a',
                    'Ulice' => 'Beispielgasse 2', 'Misto' => 'Wien', 'PSC' => '1010', 'Stat' => 'Rakousko'],
                // Pole státu použité na jiný údaj (u fyzické osoby třeba rodné číslo) zemí není.
                ['Cislo' => 7, 'Nazev' => 'Řemeslník Epsilon', 'ICO' => '', 'DIC' => '',
                    'Ulice' => 'Dílenská 3', 'Misto' => 'Kolín', 'PSC' => '280 02', 'Stat' => 'r.č. 000000/0000'],
                // Tentýž subjekt dvakrát: IČO jednou s vodicími nulami, jednou bez nich; e-mail jen ve druhém.
                ['Cislo' => 8, 'Nazev' => 'Obec Vzorová', 'ICO' => '00012346', 'Ulice' => 'Náměstí 1', 'Misto' => 'Vzorová', 'PSC' => '123 45'],
                ['Cislo' => 9, 'Nazev' => 'Obec Vzorová', 'ICO' => '12346', 'EMail' => 'obec@example.invalid'],
                // Člen skupiny DPH: Money místo DIČ uvádí značku skupiny.
                ['Cislo' => 10, 'Nazev' => 'Pojišťovna Theta a.s.', 'ICO' => '00087650', 'DIC' => 'SKUPINOVE_DPH',
                    'Ulice' => 'Pojistná 5', 'Misto' => 'Praha', 'PSC' => '110 00'],
            ]),
            'AdUcBan.DAT' => Ms3FixtureWriter::table([['CisPartn', 'L', 4], ['Ucet', 'C', 20], ['KodBanky', 'C', 4]], [
                ['CisPartn' => 2, 'Ucet' => '1000000005', 'KodBanky' => '0100'],
            ]),

            'ROK.001/UcOsnova.DAT' => Ms3FixtureWriter::table(self::CHART_FIELDS, $chart),
            'ROK.001/UcPrKont.DAT' => Ms3FixtureWriter::table(self::RULE_FIELDS, $rules),
            'ROK.001/SzUcPokl.DAT' => Ms3FixtureWriter::table(self::REGISTER_FIELDS, $registers),
            'ROK.001/UcDenik.DAT' => Ms3FixtureWriter::table(self::JOURNAL_FIELDS, [
                ['Cislo' => -1, 'Zdroj' => 'XP', 'Datum' => '2024-01-01', 'Popis' => 'Počáteční stav roku 2024', 'UcMD' => '211000', 'UcD' => '701000', 'Castka' => 10000.0],
                ['Cislo' => -2, 'Zdroj' => 'XP', 'Datum' => '2024-01-01', 'Popis' => 'Počáteční stav roku 2024', 'UcMD' => '221001', 'UcD' => '701000', 'Castka' => 50000.0],
                ['Cislo' => -3, 'Zdroj' => 'XP', 'Datum' => '2024-01-01', 'Popis' => 'Počáteční stav roku 2024', 'UcMD' => '701000', 'UcD' => '411000', 'Castka' => 60000.0],
                ['Cislo' => -4, 'Zdroj' => 'XP', 'Datum' => '2024-01-01', 'Popis' => 'Počáteční stav roku 2024', 'UcMD' => '211000', 'UcD' => '211000', 'Castka' => 0.0],
                ['Cislo' => 1, 'Zdroj' => 'FP', 'Doklad' => 'FP24001', 'Datum' => '2024-02-10', 'DatPlnDPH' => '2024-02-10', 'Popis' => 'Účetní služby', 'UcMD' => '518000', 'UcD' => '321000', 'Castka' => 10000.0, 'Stred' => 'REZIE', 'Zakazka' => 'ZAK01'],
                ['Cislo' => 2, 'Zdroj' => 'FP', 'Doklad' => 'FP24001', 'Datum' => '2024-02-10', 'DatPlnDPH' => '2024-02-10', 'Popis' => 'Účetní služby', 'UcMD' => '343100', 'UcD' => '321000', 'Castka' => 2100.0],
                ['Cislo' => 3, 'Zdroj' => 'FP', 'Doklad' => 'FP24001', 'Datum' => '2024-02-10', 'Popis' => 'Nulový řádek', 'UcMD' => '518000', 'UcD' => '321000', 'Castka' => 0.0],
                ['Cislo' => 4, 'Zdroj' => 'FP', 'Doklad' => 'FP24099', 'Datum' => '2024-02-11', 'Popis' => 'Smazaný doklad', 'UcMD' => '518000', 'UcD' => '321000', 'Castka' => 999.0, 'Del' => 1],
                ['Cislo' => 5, 'Zdroj' => 'BK', 'Doklad' => 'BV24001', 'Datum' => '2024-02-20', 'Popis' => 'Úhrada FP24001', 'UcMD' => '321000', 'UcD' => '221001', 'Castka' => 12100.0],
                ['Cislo' => 6, 'Zdroj' => 'FV', 'Doklad' => 'FV24001', 'Datum' => '2024-03-05', 'DatPlnDPH' => '2024-03-05', 'Popis' => 'Poradenství', 'UcMD' => '311000', 'UcD' => '602000', 'Castka' => 20000.0, 'Zakazka' => 'ZAK02', 'ParICO' => self::CUSTOMER_ICO],
                ['Cislo' => 7, 'Zdroj' => 'FV', 'Doklad' => 'FV24001', 'Datum' => '2024-03-05', 'DatPlnDPH' => '2024-03-05', 'Popis' => 'Poradenství', 'UcMD' => '311000', 'UcD' => '343200', 'Castka' => 4200.0],
                ['Cislo' => 8, 'Zdroj' => 'BK', 'Doklad' => 'BP24002', 'Datum' => '2024-03-20', 'Popis' => 'Úhrada FV24001', 'UcMD' => '221001', 'UcD' => '311000', 'Castka' => 24200.0],
                ['Cislo' => 9, 'Zdroj' => 'PK', 'Doklad' => 'PV24001', 'Datum' => '2024-04-01', 'Popis' => 'Nákup materiálu', 'UcMD' => '501100', 'UcD' => '211000', 'Castka' => 1500.0, 'Stred' => 'REZIE', 'Zakazka' => '1AB2345'],
                ['Cislo' => 10, 'Zdroj' => 'BK', 'Doklad' => 'BP24003', 'Datum' => '2024-06-30', 'Popis' => 'Vratka poplatku', 'UcMD' => '568000', 'UcD' => '221001', 'Castka' => -50.0],
                ['Cislo' => 11, 'Zdroj' => 'ID', 'Doklad' => 'ID24001', 'Datum' => '2024-12-31', 'Popis' => 'Dohadná položka', 'UcMD' => '518000', 'UcD' => '325000', 'Castka' => 300.0],
                // Uzávěrka roku v Money (zdroj XZ) — převod ji nepřebírá, rok uzavře MyÚčto.
                ['Cislo' => 12, 'Zdroj' => 'XZ', 'Datum' => '2024-12-31', 'Popis' => 'Účetní závěrka roku 2024', 'UcMD' => '702000', 'UcD' => '211000', 'Castka' => 8500.0],
                ['Cislo' => 13, 'Zdroj' => 'XZ', 'Datum' => '2024-12-31', 'Popis' => 'Účetní závěrka roku 2024', 'UcMD' => '710000', 'UcD' => '518000', 'Castka' => 10300.0],
            ], 13),
            'ROK.001/PFaktury.DAT' => Ms3FixtureWriter::table(self::PURCHASE_FIELDS, [
                $vendor + [
                    'Doklad' => 'FP24001', 'PrijatDokl' => 'DF-2024-017', 'VarSymbol' => '2024017',
                    'Vystaveno' => '2024-02-08', 'DatUcPr' => '2024-02-10', 'PlnenoDPH' => '2024-02-10', 'Splatno' => '2024-02-22', 'Doruceno' => '2024-02-10',
                    'Zaklad_2' => 10000.0, 'DPH_2' => 2100.0, 'CelkemSDPH' => 12100.0,
                    'Uhrazeno' => '2024-02-20', 'UDoklad' => 'BV24001', 'Popis' => 'Účetní služby', 'BarCode' => '90000101',
                ],
                // Zálohová faktura 2024, kterou Money nikdy nezaúčtovalo — do uzavřeného roku nepatří.
                ['Druh' => 'Z', 'Doklad' => 'ZF24001', 'PrijatDokl' => 'ZF-2024-001', 'VarSymbol' => '2024201',
                    'Zaklad_2' => 500.0, 'DPH_2' => 105.0, 'CelkemSDPH' => 605.0, 'Popis' => 'Záloha 2024'] + $purchaseDates('2024-10-01') + $vendor,
                // Doklad, který Money nezaúčtovalo (v deníku není) a přesto rok uzavřelo.
                $vendor + [
                    'Doklad' => 'FP24002', 'PrijatDokl' => 'DF-2024-099', 'VarSymbol' => '2024099',
                    'Zaklad_2' => 400.0, 'DPH_2' => 84.0, 'CelkemSDPH' => 484.0, 'Popis' => 'Nezaúčtovaná faktura',
                ] + $purchaseDates('2024-11-15'),
                // Doklad k ruční kontrole (přenesená povinnost), který Money v uzavřeném roce nezaúčtovalo.
                ['KodDPH' => self::KOD_DPH_REVERSE_CHARGE, 'Doklad' => 'FP24003', 'PrijatDokl' => 'RC-2024-001', 'VarSymbol' => '2024301',
                    'Zaklad_2' => 800.0, 'DPH_2' => 0.0, 'CelkemSDPH' => 800.0, 'Popis' => 'Nezaúčtované stavební práce'] + $purchaseDates('2024-12-02') + $vendor,
            ]),
            'ROK.001/VFaktury.DAT' => Ms3FixtureWriter::table(self::ISSUED_FIELDS, [
                $customer + [
                    'Doklad' => 'FV24001', 'VarSymbol' => '2024001',
                    'Vystaveno' => '2024-03-05', 'DatUcPr' => '2024-03-05', 'PlnenoDPH' => '2024-03-05', 'Splatno' => '2024-03-19',
                    'Zaklad_2' => 20000.0, 'DPH_2' => 4200.0, 'CelkemSDPH' => 24200.0,
                    'Uhrazeno' => '2024-03-20', 'UDoklad' => 'BP24002', 'Popis' => 'Poradenství',
                ],
            ]),
            'ROK.001/PoklKnih.DAT' => Ms3FixtureWriter::table(self::CASH_FIELDS, [
                ['Doklad' => 'PV24001', 'Pokl' => 'PO', 'Vydej' => 1, 'PrKont' => 'PV001', 'DatVyst' => '2024-04-01', 'DatUcPr' => '2024-04-01', 'Popis' => 'Nákup materiálu', 'Celkem' => 1500.0, 'BarCode' => '90000201'],
            ]),
            'ROK.001/BankKnih.DAT' => Ms3FixtureWriter::table(self::BANK_FIELDS, [
                ['Doklad' => 'BV24001', 'Ucet' => 'BU', 'Vydej' => 1, 'DatUcPr' => '2024-02-20', 'DatPlat' => '2024-02-20', 'Celkem' => 12100.0, 'VarSym' => '2024017', 'AdNazev' => 'Dodavatel Alfa s.r.o.', 'Popis' => 'Úhrada FP24001', 'Vypis' => 1],
                ['Doklad' => 'BP24002', 'Ucet' => 'BU', 'Vydej' => 0, 'DatUcPr' => '2024-03-20', 'DatPlat' => '2024-03-20', 'Celkem' => 24200.0, 'VarSym' => '2024001', 'AdNazev' => 'Odběratel Beta a.s.', 'Popis' => 'Úhrada FV24001', 'Vypis' => 2],
                // Reference platby kartou v poli VS (15 číslic) — variabilní symbol to není.
                ['Doklad' => 'BP24003', 'Ucet' => 'BU', 'Vydej' => 0, 'DatUcPr' => '2024-06-30', 'DatPlat' => '2024-06-30', 'Celkem' => 50.0, 'VarSym' => '955000000000017', 'Popis' => 'Vratka poplatku', 'Vypis' => 3],
            ]),

            'ROK.002/UcOsnova.DAT' => Ms3FixtureWriter::table(self::CHART_FIELDS, $chart),
            'ROK.002/UcPrKont.DAT' => Ms3FixtureWriter::table(self::RULE_FIELDS, $rules),
            'ROK.002/SzUcPokl.DAT' => Ms3FixtureWriter::table(self::REGISTER_FIELDS, $registers),
            'ROK.002/UcDenik.DAT' => Ms3FixtureWriter::table(self::JOURNAL_FIELDS, [
                ['Cislo' => -1, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav roku 2025', 'UcMD' => '211000', 'UcD' => '701000', 'Castka' => 10000.0],
                ['Cislo' => -101, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav střediska a vozidla', 'UcMD' => '701000', 'UcD' => '211000', 'Castka' => 1500.0, 'Stred' => 'REZIE', 'Zakazka' => '1AB2345'],
                ['Cislo' => -102, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav zakázky', 'UcMD' => '311000', 'UcD' => '701000', 'Castka' => 20000.0, 'Zakazka' => 'ZAK02'],
                ['Cislo' => -103, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav bez zakázky', 'UcMD' => '701000', 'UcD' => '311000', 'Castka' => 20000.0],
                ['Cislo' => -104, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav střediska a zakázky', 'UcMD' => '701000', 'UcD' => '321000', 'Castka' => 10000.0, 'Stred' => 'REZIE', 'Zakazka' => 'ZAK01'],
                ['Cislo' => -105, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav bez střediska a zakázky', 'UcMD' => '321000', 'UcD' => '701000', 'Castka' => 10000.0],
                ['Cislo' => -2, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav roku 2025', 'UcMD' => '221001', 'UcD' => '701000', 'Castka' => 62150.0],
                ['Cislo' => -3, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav roku 2025', 'UcMD' => '343100', 'UcD' => '701000', 'Castka' => 2100.0],
                ['Cislo' => -4, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav roku 2025', 'UcMD' => '701000', 'UcD' => '343200', 'Castka' => 4200.0],
                ['Cislo' => -5, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav roku 2025', 'UcMD' => '701000', 'UcD' => '325000', 'Castka' => 300.0],
                ['Cislo' => -6, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav roku 2025', 'UcMD' => '701000', 'UcD' => '411000', 'Castka' => 60000.0],
                ['Cislo' => -7, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav roku 2025', 'UcMD' => '701000', 'UcD' => '431000', 'Castka' => 8250.0],
                ['Cislo' => 1, 'Zdroj' => 'ID', 'Doklad' => 'ID25001', 'Datum' => '2025-01-01', 'Popis' => 'Rozpuštění dohadné položky', 'UcMD' => '325000', 'UcD' => '518000', 'Castka' => 300.0],
                ['Cislo' => 2, 'Zdroj' => 'FP', 'Doklad' => 'FP25001', 'Datum' => '2025-01-15', 'DatPlnDPH' => '2025-01-15', 'Popis' => 'Účetní služby', 'UcMD' => '518000', 'UcD' => '321000', 'Castka' => 5000.0],
                ['Cislo' => 3, 'Zdroj' => 'FP', 'Doklad' => 'FP25001', 'Datum' => '2025-01-15', 'DatPlnDPH' => '2025-01-15', 'Popis' => 'Účetní služby', 'UcMD' => '343100', 'UcD' => '321000', 'Castka' => 1050.0],
                ['Cislo' => 4, 'Zdroj' => 'PK', 'Doklad' => 'PV25001', 'Datum' => '2025-02-01', 'Popis' => 'Kancelářské potřeby', 'UcMD' => '501100', 'UcD' => '211000', 'Castka' => 800.0],
                ['Cislo' => 5, 'Zdroj' => 'BK', 'Doklad' => 'BV25001', 'Datum' => '2025-12-31', 'Popis' => 'Poplatek za vedení účtu', 'UcMD' => '568000', 'UcD' => '221001', 'Castka' => 100.0],
                // Zálohové faktury ZF25001 / ZV25001 Money nezaúčtovává — v deníku nejsou.
                ['Cislo' => 6, 'Zdroj' => 'FP', 'Doklad' => 'FP25002', 'Datum' => '2025-03-10', 'DatPlnDPH' => '2025-03-10', 'Popis' => 'Vyúčtování služeb', 'UcMD' => '518000', 'UcD' => '321000', 'Castka' => 1000.0],
                ['Cislo' => 7, 'Zdroj' => 'FP', 'Doklad' => 'FP25002', 'Datum' => '2025-03-10', 'DatPlnDPH' => '2025-03-10', 'Popis' => 'Vyúčtování služeb', 'UcMD' => '343100', 'UcD' => '321000', 'Castka' => 210.0],
                // Placeno kartou z pokladny: Money účtuje úhradu v tomtéž dokladu, 321 vyjde nulový.
                ['Cislo' => 23, 'Zdroj' => 'FP', 'Doklad' => 'FP25002', 'Datum' => '2025-03-10', 'Popis' => 'Úhrada kartou', 'UcMD' => '321000', 'UcD' => '211000', 'Castka' => 1210.0],
                ['Cislo' => 8, 'Zdroj' => 'FP', 'Doklad' => 'DP25001', 'Datum' => '2025-03-20', 'DatPlnDPH' => '2025-03-20', 'Popis' => 'Dobropis služeb', 'UcMD' => '518000', 'UcD' => '321000', 'Castka' => -500.0],
                ['Cislo' => 9, 'Zdroj' => 'FP', 'Doklad' => 'DP25001', 'Datum' => '2025-03-20', 'DatPlnDPH' => '2025-03-20', 'Popis' => 'Dobropis služeb', 'UcMD' => '343100', 'UcD' => '321000', 'Castka' => -105.0],
                ['Cislo' => 10, 'Zdroj' => 'FP', 'Doklad' => 'FP25003', 'Datum' => '2025-04-05', 'DatPlnDPH' => '2025-04-05', 'Popis' => 'Stavební práce', 'UcMD' => '518000', 'UcD' => '321000', 'Castka' => 2000.0],
                ['Cislo' => 11, 'Zdroj' => 'FP', 'Doklad' => 'FP25003', 'Datum' => '2025-04-05', 'DatPlnDPH' => '2025-04-05', 'Popis' => 'Stavební práce', 'UcMD' => '343100', 'UcD' => '343200', 'Castka' => 420.0],
                ['Cislo' => 12, 'Zdroj' => 'FP', 'Doklad' => 'FP25004', 'Datum' => '2025-04-15', 'DatPlnDPH' => '2025-04-15', 'Popis' => 'Licence v cizí měně', 'UcMD' => '518000', 'UcD' => '321000', 'Castka' => 2500.0],
                ['Cislo' => 13, 'Zdroj' => 'FP', 'Doklad' => 'FP25004', 'Datum' => '2025-04-15', 'DatPlnDPH' => '2025-04-15', 'Popis' => 'Licence v cizí měně', 'UcMD' => '343100', 'UcD' => '321000', 'Castka' => 525.0],
                ['Cislo' => 14, 'Zdroj' => 'FP', 'Doklad' => 'FP24001', 'Datum' => '2025-05-06', 'DatPlnDPH' => '2025-05-06', 'Popis' => 'Drobné služby', 'UcMD' => '518000', 'UcD' => '321000', 'Castka' => 300.0],
                ['Cislo' => 15, 'Zdroj' => 'FP', 'Doklad' => 'FP24001', 'Datum' => '2025-05-06', 'DatPlnDPH' => '2025-05-06', 'Popis' => 'Drobné služby', 'UcMD' => '343100', 'UcD' => '321000', 'Castka' => 63.0],
                ['Cislo' => 16, 'Zdroj' => 'FV', 'Doklad' => 'FV25001', 'Datum' => '2025-05-20', 'DatPlnDPH' => '2025-05-20', 'Popis' => 'Poradenství', 'UcMD' => '311000', 'UcD' => '602000', 'Castka' => 1000.0],
                ['Cislo' => 17, 'Zdroj' => 'FV', 'Doklad' => 'FV25001', 'Datum' => '2025-05-20', 'DatPlnDPH' => '2025-05-20', 'Popis' => 'Poradenství', 'UcMD' => '311000', 'UcD' => '343200', 'Castka' => 210.0],
                // Číselná řada druhého účtu má stejné číslo dokladu jako řada prvního účtu.
                ['Cislo' => 18, 'Zdroj' => 'BK', 'Doklad' => 'BV25001', 'Datum' => '2025-11-30', 'Popis' => 'Poplatek druhého účtu', 'UcMD' => '568000', 'UcD' => '221002', 'Castka' => 40.0],
                // Doklad s datem z předchozího roku, který Money vede v knize roku 2025.
                ['Cislo' => 19, 'Zdroj' => 'ID', 'Doklad' => 'ID25002', 'Datum' => '2024-12-31', 'Popis' => 'Náklad zaúčtovaný po uzávěrce', 'UcMD' => '518000', 'UcD' => '325000', 'Castka' => 200.0],
                // Skupina 61 ze staré osnovy (před rokem 2016), kterou šablona MyÚčta nemá.
                ['Cislo' => 20, 'Zdroj' => 'ID', 'Doklad' => 'ID25003', 'Datum' => '2025-06-30', 'Popis' => 'Změna stavu výrobků', 'UcMD' => '501100', 'UcD' => '613000', 'Castka' => 150.0],
                // Příjmový doklad se zápornou částkou = vratka, peníze z pokladny odešly.
                ['Cislo' => 21, 'Zdroj' => 'PK', 'Doklad' => 'PP25001', 'Datum' => '2025-03-01', 'Popis' => 'Vratka tržby', 'UcMD' => '211000', 'UcD' => '602000', 'Castka' => -200.0],
                ['Cislo' => 24, 'Zdroj' => 'PK', 'Doklad' => 'PV25002', 'Datum' => '2025-06-02', 'DatPlnDPH' => '2025-06-02', 'Popis' => 'Tankování', 'UcMD' => '501100', 'UcD' => '211000', 'Castka' => 100.0],
                ['Cislo' => 25, 'Zdroj' => 'PK', 'Doklad' => 'PV25002', 'Datum' => '2025-06-02', 'DatPlnDPH' => '2025-06-02', 'Popis' => 'DPH tankování', 'UcMD' => '343100', 'UcD' => '211000', 'Castka' => 21.0],
                // Ostatní pohledávka s DPH (věcné břemeno) — kniha pohledávek Money, zdroj KP.
                ['Cislo' => 28, 'Zdroj' => 'KP', 'Doklad' => 'PH25001', 'Datum' => '2025-08-05', 'DatPlnDPH' => '2025-08-05', 'Popis' => 'Věcné břemeno', 'UcMD' => '315000', 'UcD' => '602000', 'Castka' => 1000.0],
                ['Cislo' => 29, 'Zdroj' => 'KP', 'Doklad' => 'PH25001', 'Datum' => '2025-08-05', 'DatPlnDPH' => '2025-08-05', 'Popis' => 'DPH 21% - věcné břemeno', 'UcMD' => '315000', 'UcD' => '343200', 'Castka' => 210.0],
                ['Cislo' => 26, 'Zdroj' => 'FP', 'Doklad' => 'FP25005', 'Datum' => '2025-07-10', 'DatPlnDPH' => '2025-07-10', 'Popis' => 'Licence ze zahraničí', 'UcMD' => '518000', 'UcD' => '321000', 'Castka' => 1000.0],
                // Samovyměření: odpočet i povinnost na 343 stejnou částkou.
                ['Cislo' => 27, 'Zdroj' => 'ID', 'Doklad' => 'ICH25001', 'Datum' => '2025-07-10', 'DatPlnDPH' => '2025-07-10', 'Popis' => 'RCH k FP25005', 'UcMD' => '343100', 'UcD' => '343200', 'Castka' => 210.0],
                ['Cislo' => 22, 'Zdroj' => 'FP', 'Doklad' => 'DZ25001', 'Datum' => '2025-03-02', 'DatPlnDPH' => '2025-03-02', 'Popis' => 'DPH ze zálohy', 'UcMD' => '343100', 'UcD' => '314000', 'Castka' => 210.0],
            ]),
            'ROK.002/PFaktury.DAT' => Ms3FixtureWriter::table(self::PURCHASE_FIELDS, [
                $vendor + [
                    'Doklad' => 'FP25001', 'PrijatDokl' => 'DF-2025-003', 'VarSymbol' => '2025003',
                    'Vystaveno' => '2025-01-14', 'DatUcPr' => '2025-01-15', 'PlnenoDPH' => '2025-01-15', 'Splatno' => '2025-01-28', 'Doruceno' => '2025-01-15',
                    'Zaklad_2' => 5000.0, 'DPH_2' => 1050.0, 'CelkemSDPH' => 6050.0, 'Popis' => 'Účetní služby',
                ],
                // Zálohová faktura (jiný druh než běžná `N`) — daňový doklad je až konečná FP25002.
                ['Druh' => 'Z', 'Doklad' => 'ZF25001', 'PrijatDokl' => 'ZF-2025-001', 'VarSymbol' => '2025101',
                    'Zaklad_2' => 1000.0, 'DPH_2' => 210.0, 'CelkemSDPH' => 1210.0, 'Popis' => 'Záloha na služby'] + $purchaseDates('2025-03-01') + $vendor,
                // Daňový doklad k poskytnuté záloze (druh D): Money ho účtuje jen 343/314, na 321 nejde.
            ['Druh' => 'D', 'Doklad' => 'DZ25001', 'PrijatDokl' => 'DZ-2025-001', 'VarSymbol' => '2025102',
                'Zaklad_2' => 1000.0, 'DPH_2' => 210.0, 'CelkemSDPH' => 1210.0, 'Popis' => 'Daňový doklad k záloze'] + $purchaseDates('2025-03-02') + $vendor,
            // Smazaná faktura (`FlagDel`): řada její číslo přidělila znovu živé FP25001.
            ['FlagDel' => 1, 'Doklad' => 'FP25001', 'PrijatDokl' => 'SMAZ-2025-001', 'VarSymbol' => '2025901',
                'Zaklad_2' => 700.0, 'DPH_2' => 147.0, 'CelkemSDPH' => 847.0, 'Popis' => 'Smazaný doklad'] + $purchaseDates('2025-01-10') + $vendor,
            ['Uhrada' => 'kartou', 'Doklad' => 'FP25002', 'PrijatDokl' => 'DF-2025-010', 'VarSymbol' => '2025010',
                    'Zaklad_2' => 1000.0, 'DPH_2' => 210.0, 'CelkemSDPH' => 1210.0, 'Popis' => 'Vyúčtování služeb'] + $purchaseDates('2025-03-10') + $vendor,
                ['Dobropis' => 1, 'Doklad' => 'DP25001', 'PrijatDokl' => 'DB-2025-001', 'VarSymbol' => '2025011',
                    'Zaklad_2' => -500.0, 'DPH_2' => -105.0, 'CelkemSDPH' => -605.0, 'Popis' => 'Dobropis služeb'] + $purchaseDates('2025-03-20') + $vendor,
                ['KodDPH' => self::KOD_DPH_REVERSE_CHARGE, 'Doklad' => 'FP25003', 'PrijatDokl' => 'RC-2025-001', 'VarSymbol' => '2025012',
                    'Zaklad_2' => 2000.0, 'DPH_2' => 0.0, 'CelkemSDPH' => 2000.0, 'Popis' => 'Stavební práce'] + $purchaseDates('2025-04-05') + $vendor,
                // V měně dokladu 100 + 21 EUR (`ValutyZak2`, `ValutyDPH2`) - kurzem 25 přesně Kč z Money.
                ['Mena' => 'EUR', 'PocetJedn' => 1, 'Kurs' => 25.0, 'Doklad' => 'FP25004', 'PrijatDokl' => 'EU-2025-001', 'VarSymbol' => '2025013',
                    'Zaklad_2' => 2500.0, 'DPH_2' => 525.0, 'CelkemSDPH' => 3025.0, 'ValutyZak2' => 100.0, 'ValutyDPH2' => 21.0,
                    'Popis' => 'Licence v cizí měně'] + $purchaseDates('2025-04-15') + $vendor,
                // Money čísluje řadu každý rok od začátku: FP24001 je i v roce 2024.
                // Licence z EU: faktura mimo přiznání, DPH samovyměřuje interní doklad ICH25001.
                ['KodDPH' => '19Ř00P', 'Doklad' => 'FP25005', 'PrijatDokl' => 'DE-2025-001', 'VarSymbol' => '2025014',
                    'D_ICO' => '', 'D_DIC' => 'DE123456789', 'D_Nazev' => 'Lieferant Gamma GmbH', 'D_Ulice' => 'Musterstraße 1',
                    'D_Mesto' => 'Berlin', 'D_Psc' => '10115', 'Druh' => 'N', 'Uhrada' => 'převodem',
                    'Zaklad_0' => 1000.0, 'CelkemSDPH' => 1000.0, 'Popis' => 'Licence ze zahraničí'] + $purchaseDates('2025-07-10'),
                ['Doklad' => 'FP24001', 'PrijatDokl' => 'DF-2025-020', 'VarSymbol' => '2025020',
                    'Zaklad_2' => 300.0, 'DPH_2' => 63.0, 'CelkemSDPH' => 363.0, 'Popis' => 'Drobné služby'] + $purchaseDates('2025-05-06') + $vendor,
            ]),
            'ROK.002/VFaktury.DAT' => Ms3FixtureWriter::table(self::ISSUED_FIELDS, [
                ['Druh' => 'Z', 'Doklad' => 'ZV25001', 'VarSymbol' => '2025101',
                    'Zaklad_2' => 1000.0, 'DPH_2' => 210.0, 'CelkemSDPH' => 1210.0, 'Popis' => 'Záloha na poradenství'] + $issuedDates('2025-05-02') + $customer,
                ['Doklad' => 'FV25001', 'VarSymbol' => '2025001',
                    'Zaklad_2' => 1000.0, 'DPH_2' => 210.0, 'CelkemSDPH' => 1210.0, 'Popis' => 'Poradenství'] + $issuedDates('2025-05-20') + $customer,
            ]),
            'ROK.002/PoklKnih.DAT' => Ms3FixtureWriter::table(self::CASH_FIELDS, [
                ['Doklad' => 'PV25001', 'Pokl' => 'PO', 'Vydej' => 1, 'PrKont' => 'PV001', 'DatVyst' => '2025-02-01', 'DatUcPr' => '2025-02-01', 'Popis' => 'Kancelářské potřeby', 'Celkem' => 800.0],
                ['Doklad' => 'PP25001', 'Pokl' => 'PO', 'Vydej' => 0, 'DatVyst' => '2025-03-01', 'DatUcPr' => '2025-03-01', 'Popis' => 'Vratka tržby', 'Celkem' => -200.0],
                // Nákup s DPH za hotové (tankování) — DPH z pokladny patří do přiznání,
                // s kráceným odpočtem podle § 76 (členění s příponou K).
                ['Doklad' => 'PV25002', 'Pokl' => 'PO', 'Vydej' => 1, 'DatVyst' => '2025-06-02', 'DatUplDPH' => '2025-06-02', 'DatUcPr' => '2025-06-02',
                    'AdNazev' => 'Čerpací stanice Gama s.r.o.', 'Popis' => 'Tankování', 'Celkem' => 121.0,
                    'Cleneni' => self::KOD_DPH_PURCHASE . ' K', 'ZSazba' => 21.0, 'ZaklZS' => 100.0, 'DPHZS' => 21.0],
                // Nulový doklad nemá v pokladně účinek ani zápis v deníku.
                ['Doklad' => 'PP25002', 'Pokl' => 'PO', 'Vydej' => 0, 'DatVyst' => '2025-03-02', 'DatUcPr' => '2025-03-02', 'Popis' => 'Stornovaná tržba', 'Celkem' => 0.0],
            ]),
            // Odpočet FP25001 Money přesunulo do února (doklad došel po podání přiznání za leden).
            'ROK.002/UcPrvDPH.DAT' => Ms3FixtureWriter::table([['Doklad', 'C', 10], ['DatumD', 'D', 2], ['DatPln', 'D', 2], ['Cleneni', 'C', 12]], [
                ['Doklad' => 'FP25001', 'DatumD' => '2025-01-15', 'DatPln' => '2025-02-03', 'Cleneni' => self::KOD_DPH_PURCHASE],
            ]),
            // Samovyměření k licenci z EU (FP25005) vede Money interním dokladem: výstup
            // ř. 5 (přijetí služby z EU) a zrcadlový odpočet ř. 43 s kráceným nárokem.
            'ROK.002/IntDokl.DAT' => Ms3FixtureWriter::table([
                ['Cislo', 'L', 4], ['Doklad', 'C', 10], ['Popis', 'C', 50], ['DatUcPr', 'D', 2], ['DatUplDPH', 'D', 2],
                ['Cleneni', 'C', 12], ['ZaklZS', 'E', 10], ['DPHZS', 'E', 10],
            ], [
                ['Cislo' => 1, 'Doklad' => 'ICH25001', 'Popis' => 'RCH k FP25005', 'DatUcPr' => '2025-07-10', 'DatUplDPH' => '2025-07-10',
                    'Cleneni' => '19Ř00P', 'ZaklZS' => 2000.0, 'DPHZS' => 420.0],
            ]),
            'ROK.002/PolUcDID.DAT' => Ms3FixtureWriter::table([
                ['CISLO', 'L', 4], ['Cena', 'E', 10], ['SazbaDPH', 'E', 10], ['PocetMJ', 'E', 10], ['Cleneni', 'C', 12], ['PredmPln', 'C', 2],
            ], [
                ['CISLO' => 1, 'Cena' => 1000.0, 'SazbaDPH' => 21.0, 'PocetMJ' => 1.0, 'Cleneni' => '19Ř05,06'],
                ['CISLO' => 1, 'Cena' => 1000.0, 'SazbaDPH' => 21.0, 'PocetMJ' => 1.0, 'Cleneni' => '19Ř43,44 K'],
            ]),
            // Kniha ostatních pohledávek: věcné břemeno s DPH (tuzemské plnění ř. 1).
            'ROK.002/KnihPohl.DAT' => Ms3FixtureWriter::table([
                ['Cislo', 'L', 4], ['Doklad', 'C', 10], ['DatUcPr', 'D', 2], ['DatVyst', 'D', 2], ['DatPln', 'D', 2], ['DatSpl', 'D', 2],
                ['Popis', 'C', 50], ['VarSym', 'C', 10], ['AdNazev', 'C', 60], ['AdICO', 'C', 12], ['AdDIC', 'C', 14],
                ['AdUlice', 'C', 40], ['AdMesto', 'C', 40], ['AdPSC', 'C', 10], ['Cleneni', 'C', 12],
                ['ZSazba', 'E', 10], ['ZaklZS', 'E', 10], ['DPHZS', 'E', 10], ['Zakl0', 'E', 10], ['Celkem', 'E', 10],
            ], [
                ['Cislo' => 1, 'Doklad' => 'PH25001', 'DatUcPr' => '2025-08-05', 'DatVyst' => '2025-08-05', 'DatPln' => '2025-08-05', 'DatSpl' => '2025-08-19',
                    'Popis' => 'Věcné břemeno', 'VarSym' => '2025801', 'AdNazev' => 'Odběratel Beta a.s.', 'AdICO' => self::CUSTOMER_ICO,
                    'AdDIC' => 'CZ' . self::CUSTOMER_ICO, 'AdUlice' => 'Ukázková 7', 'AdMesto' => 'Ostrava', 'AdPSC' => '702 00',
                    'Cleneni' => self::KOD_DPH_SALE, 'ZSazba' => 21.0, 'ZaklZS' => 1000.0, 'DPHZS' => 210.0, 'Celkem' => 1210.0],
            ]),
            'ROK.002/BankKnih.DAT' => Ms3FixtureWriter::table(self::BANK_FIELDS, [
                ['Doklad' => 'BV25001', 'Ucet' => 'BU', 'Vydej' => 1, 'DatUcPr' => '2025-12-31', 'DatPlat' => '2025-12-31', 'Celkem' => 100.0, 'Popis' => 'Poplatek za vedení účtu'],
                ['Doklad' => 'BV25001', 'Ucet' => 'BU2', 'Vydej' => 1, 'DatUcPr' => '2025-11-30', 'DatPlat' => '2025-11-30', 'Celkem' => 40.0, 'Popis' => 'Poplatek druhého účtu'],
            ]),
        ];
        // Indexy a šifrovaný archiv jsou v každé záloze; převod je nesmí potřebovat.
        $files['ROK.001/UcDenik.MDT'] = str_repeat("\x5A", 64);
        $files['Dokumenty.s3db'] = str_repeat("\xA5", 128);
        return $files;
    }

    /**
     * Tatáž agenda jako agenda jiné firmy (dávkový převod více firem): jiné IČO, název
     * a sídlo v `AgendaInfo.ini` i v „Údajích o firmě".
     *
     * @param array<string,string> $files
     * @return array<string,string>
     */
    public static function forCompany(array $files, string $ico, string $name, string $street = 'Účetní 12', string $city = 'Brno', string $zip = '602 00'): array
    {
        $files['AgendaInfo.ini'] = (string) iconv('UTF-8', 'CP1250', "[Agenda]\r\nNázev=" . $name . "\r\nIČO=" . $ico
            . "\r\nVersion=" . self::VERSION . "\r\nDatum=10.01.2026 08:15\r\n");
        $files['Agenda.DAT'] = Ms3FixtureWriter::table([['Section1', 'C', 30], ['Variable', 'C', 20], ['Value', 'C', 60]], [
            ['Section1' => 'Údaje o firmě', 'Variable' => 'Název', 'Value' => $name],
            ['Section1' => 'Údaje o firmě', 'Variable' => 'Ulice', 'Value' => $street],
            ['Section1' => 'Údaje o firmě', 'Variable' => 'Místo', 'Value' => $city],
            ['Section1' => 'Údaje o firmě', 'Variable' => 'PSČ', 'Value' => $zip],
            ['Section1' => 'Údaje o firmě', 'Variable' => 'IČO', 'Value' => $ico],
            ['Section1' => 'Údaje o firmě', 'Variable' => 'DIČ', 'Value' => 'CZ' . $ico],
        ], 7);
        return $files;
    }

    /**
     * Účetnictví agendy jako hospodářský rok od srpna: deník obou let posunutý o sedm měsíců
     * (ROK.001 = srpen 2024 – červenec 2025, ROK.002 = srpen 2025 – červenec 2026), počáteční
     * stavy k 1. 8. Doklady, banka a pokladna v roce nejsou - jde o tvar účetních období.
     *
     * @param bool $withOpening2024 false = první rok bez počátečních stavů (firma vzniklá v roce)
     * @return array<string,string>
     */
    public static function filesFiscalYearFromAugust(bool $withOpening2024 = true): array
    {
        $files = self::files();
        $keep = ['UcOsnova.DAT', 'UcPrKont.DAT', 'SzUcPokl.DAT', 'UcDenik.DAT'];
        foreach (array_keys($files) as $path) {
            if (str_starts_with($path, 'ROK.') && !in_array(basename($path), $keep, true)) {
                unset($files[$path]);
            }
        }
        $shift = static function (?string $date): ?string {
            if ($date === null || $date === '') {
                return $date;
            }
            $d = new \DateTimeImmutable($date);
            $month = (new \DateTimeImmutable($d->format('Y-m-01')))->modify('+7 months');
            return $month->format('Y-m-') . sprintf('%02d', min((int) $d->format('d'), (int) $month->format('t')));
        };
        foreach (['ROK.001/UcDenik.DAT', 'ROK.002/UcDenik.DAT'] as $path) {
            $rows = iterator_to_array(Ms3Table::fromString($files[$path], 'UCDENIK')->rows(), false);
            $out = [];
            foreach ($rows as $r) {
                if (!$withOpening2024 && $path === 'ROK.001/UcDenik.DAT' && $r['Zdroj'] === 'XP') {
                    continue;
                }
                $r['Datum'] = $shift($r['Datum'] ?? null);
                $r['DatPlnDPH'] = $shift($r['DatPlnDPH'] ?? null);
                $out[] = $r;
            }
            $files[$path] = Ms3FixtureWriter::table(self::JOURNAL_FIELDS, $out);
        }
        return $files;
    }

    /**
     * Agenda, ve které Money otevřelo rok 2025 bez uzávěrky roku 2024 (deník 2024 bez XZ).
     *
     * @return array<string,string>
     */
    public static function filesWithoutYearEndClosing(): array
    {
        $files = self::files();
        $rows = iterator_to_array(Ms3Table::fromString($files['ROK.001/UcDenik.DAT'], 'UCDENIK')->rows(), false);
        $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['Zdroj'] !== 'XZ'));
        $files['ROK.001/UcDenik.DAT'] = Ms3FixtureWriter::table(self::JOURNAL_FIELDS, $rows);
        return $files;
    }

    public static function filesWithoutOpeningDimensions(): array
    {
        $files = self::files();
        $path = 'ROK.002/UcDenik.DAT';
        $rows = iterator_to_array(Ms3Table::fromString($files[$path], 'UCDENIK')->rows(), false);
        foreach ($rows as &$row) {
            if ($row['Zdroj'] === 'XP') {
                $row['Stred'] = '';
                $row['Zakazka'] = '';
            }
        }
        unset($row);
        $files[$path] = Ms3FixtureWriter::table(self::JOURNAL_FIELDS, $rows);
        return $files;
    }

    public static function filesWithChangedDimensions(int $year = 2024): array
    {
        $files = self::files();
        $path = $year === 2024 ? 'ROK.001/UcDenik.DAT' : 'ROK.002/UcDenik.DAT';
        $rows = iterator_to_array(Ms3Table::fromString($files[$path], 'UCDENIK')->rows(), false);
        foreach ($rows as &$row) {
            if ($row['Stred'] === 'REZIE') {
                $row['Stred'] = 'NOVE1';
            }
            if ($row['Zakazka'] === 'ZAK01') {
                $row['Zakazka'] = 'ZAK99';
            }
        }
        unset($row);
        $files[$path] = Ms3FixtureWriter::table(self::JOURNAL_FIELDS, $rows);
        return $files;
    }

    /**
     * Agenda, ve které roky v Money nenavazují: počáteční stavy 2025 přesouvají 100 Kč
     * z pokladny na účet (ruční přepis PS v Money), konečné stavy 2024 zůstávají.
     *
     * @return array<string,string>
     */
    public static function filesWithOpeningReclass(): array
    {
        $files = self::files();
        $rows = iterator_to_array(Ms3Table::fromString($files['ROK.002/UcDenik.DAT'], 'UCDENIK')->rows(), false);
        foreach ($rows as &$r) {
            if ($r['Zdroj'] === 'XP' && $r['UcMD'] === '211000') {
                $r['Castka'] = 9900.0;
            } elseif ($r['Zdroj'] === 'XP' && $r['UcMD'] === '221001') {
                $r['Castka'] = 62250.0;
            }
        }
        unset($r);
        $files['ROK.002/UcDenik.DAT'] = Ms3FixtureWriter::table(self::JOURNAL_FIELDS, $rows);
        return $files;
    }

    /**
     * Agenda s účtem v eurech (BE, 221003). Money má na účtu 100 EUR z doby před první
     * knihou zálohy — pohybem nejsou, jen korunovým počátečním stavem v deníku (kurz 25).
     * Pohyby: 2024 +40 EUR, 2025 −20 EUR, protiúčet 325000; řetěz let navazuje.
     *
     * @return array<string,string>
     */
    public static function filesWithForeignAccount(): array
    {
        $files = self::files();
        $append = static function (string $path, array $fields, array $rows) use (&$files): void {
            $table = strtoupper(pathinfo($path, PATHINFO_FILENAME));
            $existing = iterator_to_array(Ms3Table::fromString($files[$path], $table)->rows(), false);
            $files[$path] = Ms3FixtureWriter::table($fields, array_merge($existing, $rows));
        };
        foreach (['ROK.001' => 25.0, 'ROK.002' => 25.0] as $dir => $openingRate) {
            $append($dir . '/UcOsnova.DAT', self::CHART_FIELDS, [['Ucet' => '221003', 'Nazev' => 'Devizový účet EUR']]);
            $append($dir . '/SzUcPokl.DAT', self::REGISTER_FIELDS, [[
                'Zkrat' => 'BE', 'Popis' => 'Devizový účet', 'UcPokl' => 'U', 'PrimUcet' => '221003',
                'Ucet' => '5000000003', 'BKod' => '0100', 'Mena' => 'EUR', 'PSKurz' => $openingRate,
            ]]);
        }
        $append('ROK.001/UcDenik.DAT', self::JOURNAL_FIELDS, [
            ['Cislo' => -5, 'Zdroj' => 'XP', 'Datum' => '2024-01-01', 'Popis' => 'Počáteční stav roku 2024', 'UcMD' => '221003', 'UcD' => '701000', 'Castka' => 2500.0],
            ['Cislo' => -6, 'Zdroj' => 'XP', 'Datum' => '2024-01-01', 'Popis' => 'Počáteční stav roku 2024', 'UcMD' => '701000', 'UcD' => '325000', 'Castka' => 2500.0],
            ['Cislo' => 30, 'Zdroj' => 'BK', 'Doklad' => 'BE24001', 'Datum' => '2024-05-10', 'Popis' => 'Příjem na devizový účet', 'UcMD' => '221003', 'UcD' => '325000', 'Castka' => 1000.0],
        ]);
        $append('ROK.001/BankKnih.DAT', self::BANK_FIELDS, [
            ['Doklad' => 'BE24001', 'Ucet' => 'BE', 'Vydej' => 0, 'DatUcPr' => '2024-05-10', 'DatPlat' => '2024-05-10', 'Celkem' => 1000.0,
                'Popis' => 'Příjem na devizový účet', 'Vypis' => 1, 'Mena' => 'EUR', 'Kurs' => 25.0, 'ValutyKUhr' => 40.0],
        ]);
        $append('ROK.002/UcDenik.DAT', self::JOURNAL_FIELDS, [
            ['Cislo' => -8, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav roku 2025', 'UcMD' => '221003', 'UcD' => '701000', 'Castka' => 3500.0],
            ['Cislo' => -9, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav roku 2025', 'UcMD' => '701000', 'UcD' => '325000', 'Castka' => 3500.0],
            ['Cislo' => 31, 'Zdroj' => 'BK', 'Doklad' => 'BE25001', 'Datum' => '2025-03-10', 'Popis' => 'Výdej z devizového účtu', 'UcMD' => '325000', 'UcD' => '221003', 'Castka' => 500.0],
        ]);
        $append('ROK.002/BankKnih.DAT', self::BANK_FIELDS, [
            ['Doklad' => 'BE25001', 'Ucet' => 'BE', 'Vydej' => 1, 'DatUcPr' => '2025-03-10', 'DatPlat' => '2025-03-10', 'Celkem' => 500.0,
                'Popis' => 'Výdej z devizového účtu', 'Vypis' => 1, 'Mena' => 'EUR', 'Kurs' => 25.0, 'ValutyKUhr' => 20.0],
        ]);
        return $files;
    }

    /**
     * Agenda s evidencí majetku (`MajInv`, `MjInvPoh`):
     *   - traktor DM-001 zařazený 1. 7. 2023 za 120 000 Kč, rovnoměrně ve 2. skupině, účetně
     *     2 000 Kč měsíčně od srpna 2023 (před převodem 5 měsíců = 10 000 Kč), v deníku
     *     odpisy 24 000 Kč za 2024 i 2025,
     *   - drobný majetek: notebook v používání a tiskárna vyřazená 1. 2. 2025,
     *   - pomocná karta bez majetkového účtu (`000000`), která se nepřevádí.
     * Odpisy vyrovnává výnos na 648/378, takže výsledek hospodaření ani počáteční stavy
     * ostatních účtů se proti základní agendě nemění.
     *
     * @return array<string,string>
     */
    public static function filesWithAssets(): array
    {
        $files = self::files();
        $append = static function (string $path, array $fields, array $rows) use (&$files): void {
            $table = strtoupper(pathinfo($path, PATHINFO_FILENAME));
            $existing = iterator_to_array(Ms3Table::fromString($files[$path], $table)->rows(), false);
            $files[$path] = Ms3FixtureWriter::table($fields, array_merge($existing, $rows));
        };
        foreach (['ROK.001', 'ROK.002'] as $dir) {
            $append($dir . '/UcOsnova.DAT', self::CHART_FIELDS, [
                ['Ucet' => '022100', 'Nazev' => 'Stroje'],
                ['Ucet' => '082100', 'Nazev' => 'Oprávky ke strojům'],
                ['Ucet' => '551000', 'Nazev' => 'Odpisy'],
                ['Ucet' => '378000', 'Nazev' => 'Jiné pohledávky'],
                ['Ucet' => '648000', 'Nazev' => 'Ostatní provozní výnosy'],
            ]);
        }
        $append('ROK.001/UcDenik.DAT', self::JOURNAL_FIELDS, [
            ['Cislo' => -20, 'Zdroj' => 'XP', 'Datum' => '2024-01-01', 'Popis' => 'Počáteční stav roku 2024', 'UcMD' => '022100', 'UcD' => '701000', 'Castka' => 120000.0],
            ['Cislo' => -21, 'Zdroj' => 'XP', 'Datum' => '2024-01-01', 'Popis' => 'Počáteční stav roku 2024', 'UcMD' => '701000', 'UcD' => '082100', 'Castka' => 10000.0],
            ['Cislo' => -22, 'Zdroj' => 'XP', 'Datum' => '2024-01-01', 'Popis' => 'Počáteční stav roku 2024', 'UcMD' => '701000', 'UcD' => '411000', 'Castka' => 110000.0],
            ['Cislo' => 40, 'Zdroj' => 'ID', 'Doklad' => 'IDH24012', 'Datum' => '2024-12-31', 'Popis' => 'Odpisy majetku 2024', 'UcMD' => '551000', 'UcD' => '082100', 'Castka' => 24000.0],
            ['Cislo' => 41, 'Zdroj' => 'ID', 'Doklad' => 'ID24090', 'Datum' => '2024-12-31', 'Popis' => 'Ostatní výnos', 'UcMD' => '378000', 'UcD' => '648000', 'Castka' => 24000.0],
        ]);
        $append('ROK.002/UcDenik.DAT', self::JOURNAL_FIELDS, [
            ['Cislo' => -20, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav roku 2025', 'UcMD' => '022100', 'UcD' => '701000', 'Castka' => 120000.0],
            ['Cislo' => -21, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav roku 2025', 'UcMD' => '701000', 'UcD' => '082100', 'Castka' => 34000.0],
            ['Cislo' => -22, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav roku 2025', 'UcMD' => '378000', 'UcD' => '701000', 'Castka' => 24000.0],
            ['Cislo' => -23, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav roku 2025', 'UcMD' => '701000', 'UcD' => '411000', 'Castka' => 110000.0],
            ['Cislo' => 40, 'Zdroj' => 'ID', 'Doklad' => 'IDH25012', 'Datum' => '2025-12-31', 'Popis' => 'Odpisy majetku 2025', 'UcMD' => '551000', 'UcD' => '082100', 'Castka' => 24000.0],
            ['Cislo' => 41, 'Zdroj' => 'ID', 'Doklad' => 'ID25090', 'Datum' => '2025-12-31', 'Popis' => 'Ostatní výnos', 'UcMD' => '378000', 'UcD' => '648000', 'Castka' => 24000.0],
        ]);
        $files['MajInv.DAT'] = Ms3FixtureWriter::table(self::ASSET_FIELDS, [
            ['Cislo' => 1, 'TypMajetku' => 1, 'Nazev' => 'Traktor', 'InventCisl' => 'DM-001', 'Druh' => 'H', 'ZpusobOdpi' => 'N', 'OdpisSkupi' => '2',
                'DatZarazen' => '2023-07-01', 'PrUcMaj' => '022100', 'PrUcOpr' => '082100', 'UcOdpPorC' => 120000.0],
            ['Cislo' => 2, 'TypMajetku' => 0, 'Nazev' => 'Notebook', 'InventCisl' => 'DR-001', 'Druh' => 'H', 'DatZarazen' => '2024-03-01',
                'Umisteno' => 'Kancelář Brno', 'SDodavatel' => 'Dodavatel techniky s.r.o.', 'UcOdpPorC' => 15000.0],
            ['Cislo' => 3, 'TypMajetku' => 0, 'Nazev' => 'Tiskárna', 'InventCisl' => 'DR-002', 'Druh' => 'H', 'DatZarazen' => '2024-02-01',
                'DatVyrazen' => '2025-02-01', 'ZpVyrazeni' => 'LIKVIDACE', 'UcOdpPorC' => 8000.0],
            ['Cislo' => 4, 'TypMajetku' => 1, 'Nazev' => 'pomocná karta - výpočet daňových odpisů', 'InventCisl' => 'POM-1', 'Druh' => 'H',
                'ZpusobOdpi' => 'Z', 'OdpisSkupi' => '2', 'DatZarazen' => '2023-07-01', 'PrUcMaj' => '000000', 'PrUcOpr' => '000000', 'UcOdpPorC' => 50000.0],
        ]);
        $moves = [
            ['CisloMajet' => 1, 'Cislo' => 1, 'Datum' => '2023-07-01', 'Typ' => 'Z', 'Castka' => 120000.0, 'ZustCena' => 120000.0, 'Doklad' => 'IDH23001', 'PrUcMaj' => '022100', 'PrUcOpr' => '082100'],
            ['CisloMajet' => 2, 'Cislo' => 1, 'Datum' => '2024-03-01', 'Typ' => 'Z', 'Castka' => 15000.0, 'ZustCena' => 15000.0],
            ['CisloMajet' => 3, 'Cislo' => 1, 'Datum' => '2024-02-01', 'Typ' => 'Z', 'Castka' => 8000.0, 'ZustCena' => 8000.0],
            ['CisloMajet' => 3, 'Cislo' => 2, 'Datum' => '2025-02-01', 'Typ' => 'Y', 'Castka' => 8000.0, 'ZustCena' => 0.0],
            ['CisloMajet' => 4, 'Cislo' => 1, 'Datum' => '2023-07-01', 'Typ' => 'Z', 'Castka' => 50000.0, 'ZustCena' => 50000.0],
        ];
        $residual = 120000.0;
        $no = 1;
        for ($month = new \DateTimeImmutable('2023-08-31'); $month <= new \DateTimeImmutable('2025-12-31'); $month = $month->modify('last day of next month')) {
            $residual -= 2000.0;
            $moves[] = ['CisloMajet' => 1, 'Cislo' => ++$no, 'Datum' => $month->format('Y-m-d'), 'Typ' => 'U', 'Castka' => 2000.0, 'ZustCena' => $residual,
                'Popis' => 'účetní odpis', 'PrUcMaj' => '022100', 'PrUcOpr' => '082100'];
            if ($month->format('m') === '12') {
                $moves[] = ['CisloMajet' => 1, 'Cislo' => ++$no, 'Datum' => $month->format('Y-m-d'), 'Typ' => 'X', 'Castka' => 0.0, 'ZustCena' => $residual];
            }
        }
        $files['MjInvPoh.DAT'] = Ms3FixtureWriter::table(self::ASSET_MOVE_FIELDS, $moves);
        return $files;
    }

    /**
     * {@see filesWithAssets()} a karty s daňovými zvláštnostmi Money (bez zápisů v deníku):
     *   - 5 auto 200 000 Kč (2. sk. rovnoměrně) zařazené 1. 3. 2023, účetně 3 000 Kč měsíčně,
     *     vyřazené 15. 10. 2024 s odpisem zůstatkové ceny 146 000 Kč (`OdpZustCen`),
     *   - 6 hala „jen ÚČETNÍ odpis" 1,3 mil. Kč a 7 její pomocná karta (`000000`) s daňovou
     *     vstupní cenou 1,1 mil. Kč (zrychleně, skupina jen v číselníku `FL_LGMajSk` 8 = 5. sk.)
     *     a daňovým odpisem 2023 40 000 Kč (pohyb D),
     *   - 8 stroj 300 000 Kč bez `OdpisSkupi`, skupina z číselníku (`FL_LGMajSk` 6 = 3. sk.),
     *   - 9 elektromobil 1 mil. Kč zařazený 10. 3. 2024 (`FL_LGMajSk` 5, mimořádně §30a),
     *   - 10 FVE 120 000 Kč s daňovým odpisem rovným účetnímu (`UcRovnyDan`), první měsíc
     *     účetně poloviční.
     * `$residualWriteOffInUse`: stroji 8 Money 30. 6. 2025 odepíše zůstatek 120 Kč
     * (`OdpZustCen`), karta přitom zůstane v užívání.
     *
     * @return array<string,string>
     */
    public static function filesWithAssetTaxCases(bool $residualWriteOffInUse = false): array
    {
        $files = self::filesWithAssets();
        $append = static function (string $path, array $fields, array $rows) use (&$files): void {
            $table = strtoupper(pathinfo($path, PATHINFO_FILENAME));
            $existing = iterator_to_array(Ms3Table::fromString($files[$path], $table)->rows(), false);
            $files[$path] = Ms3FixtureWriter::table($fields, array_merge($existing, $rows));
        };
        foreach (['ROK.001', 'ROK.002'] as $dir) {
            $append($dir . '/UcOsnova.DAT', self::CHART_FIELDS, [
                ['Ucet' => '021100', 'Nazev' => 'Stavby'],
                ['Ucet' => '081100', 'Nazev' => 'Oprávky ke stavbám'],
            ]);
        }
        $append('MajInv.DAT', self::ASSET_FIELDS, [
            ['Cislo' => 5, 'TypMajetku' => 1, 'Nazev' => 'Auto', 'InventCisl' => 'DM-005', 'Druh' => 'H', 'ZpusobOdpi' => 'N', 'OdpisSkupi' => '2', 'FL_LGMajSk' => 4,
                'DatZarazen' => '2023-03-01', 'DatVyrazen' => '2024-10-15', 'ZpVyrazeni' => 'PRODEJ', 'PrUcMaj' => '022100', 'PrUcOpr' => '082100'],
            ['Cislo' => 6, 'TypMajetku' => 1, 'Nazev' => 'Hala, jen ÚČETNÍ odpis', 'InventCisl' => 'DM-006', 'Druh' => 'H', 'ZpusobOdpi' => 'Z', 'OdpisSkupi' => '5', 'FL_LGMajSk' => 8,
                'KodSKP' => '5-1', 'DatZarazen' => '2023-06-01', 'PrUcMaj' => '021100', 'PrUcOpr' => '081100'],
            ['Cislo' => 7, 'TypMajetku' => 1, 'Nazev' => 'pomocná karta-výpočet daň.odpisů', 'InventCisl' => 'DM-006 DAŇ.', 'Druh' => 'H', 'ZpusobOdpi' => 'Z', 'FL_LGMajSk' => 8,
                'KodSKP' => '5-1', 'DatZarazen' => '2023-06-01', 'PrUcMaj' => '000000', 'PrUcOpr' => '000000'],
            ['Cislo' => 8, 'TypMajetku' => 1, 'Nazev' => 'Stroj', 'InventCisl' => 'DM-008', 'Druh' => 'H', 'ZpusobOdpi' => 'R', 'FL_LGMajSk' => 6,
                'DatZarazen' => '2023-01-10', 'PrUcMaj' => '022100', 'PrUcOpr' => '082100'],
            ['Cislo' => 9, 'TypMajetku' => 1, 'Nazev' => 'Elektromobil', 'InventCisl' => 'DM-009', 'Druh' => 'H', 'FL_LGMajSk' => 5,
                'DatZarazen' => '2024-03-10', 'DatZDanOdp' => '2024-12-31', 'PrUcMaj' => '022100', 'PrUcOpr' => '082100'],
            ['Cislo' => 10, 'TypMajetku' => 1, 'Nazev' => 'FVE', 'InventCisl' => 'DM-010', 'Druh' => 'H', 'FL_LGMajSk' => 20, 'UcRovnyDan' => 1,
                'DatZarazen' => '2024-05-31', 'PrUcMaj' => '022100', 'PrUcOpr' => '082100'],
        ]);
        $moves = [];
        $monthly = static function (int $card, string $from, string $to, float $amount, float $price, array $first = []) use (&$moves): void {
            $residual = $price;
            $no = 100;
            for ($month = new \DateTimeImmutable($from); $month <= new \DateTimeImmutable($to); $month = $month->modify('last day of next month')) {
                $castka = $first[$month->format('Y-m')] ?? $amount;
                $residual -= $castka;
                $moves[] = ['CisloMajet' => $card, 'Cislo' => ++$no, 'Datum' => $month->format('Y-m-d'), 'Typ' => 'U', 'Castka' => $castka, 'ZustCena' => $residual,
                    'PrUcOpr' => $card === 6 ? '081100' : '082100'];
            }
        };
        $moves[] = ['CisloMajet' => 5, 'Cislo' => 1, 'Datum' => '2023-03-01', 'Typ' => 'Z', 'Castka' => 200000.0, 'ZustCena' => 200000.0];
        $monthly(5, '2023-04-30', '2024-09-30', 3000.0, 200000.0);
        $moves[] = ['CisloMajet' => 5, 'Cislo' => 90, 'Datum' => '2024-10-15', 'Typ' => 'U', 'Castka' => 146000.0, 'ZustCena' => 0.0, 'OdpZustCen' => 1, 'PrUcOpr' => '082100'];
        $moves[] = ['CisloMajet' => 5, 'Cislo' => 91, 'Datum' => '2024-10-15', 'Typ' => 'Y', 'Castka' => 200000.0, 'ZustCena' => 0.0];
        foreach ([6 => 1000000.0, 7 => 800000.0] as $card => $input) {
            $moves[] = ['CisloMajet' => $card, 'Cislo' => 1, 'Datum' => '2023-06-01', 'Typ' => 'Z', 'Castka' => $input, 'ZustCena' => $input];
            $moves[] = ['CisloMajet' => $card, 'Cislo' => 2, 'Datum' => '2023-06-01', 'Typ' => 'V', 'Castka' => 500000.0, 'ZustCena' => $input + 500000.0];
            $moves[] = ['CisloMajet' => $card, 'Cislo' => 3, 'Datum' => '2023-06-01', 'Typ' => 'S', 'Castka' => 200000.0, 'ZustCena' => $input + 300000.0, 'Popis' => 'dotace'];
        }
        $monthly(6, '2023-07-31', '2025-12-31', 5000.0, 1300000.0);
        $moves[] = ['CisloMajet' => 7, 'Cislo' => 4, 'Datum' => '2023-12-31', 'Typ' => 'D', 'Castka' => 40000.0, 'ZustCena' => 1060000.0];
        $moves[] = ['CisloMajet' => 8, 'Cislo' => 1, 'Datum' => '2023-01-10', 'Typ' => 'Z', 'Castka' => 300000.0, 'ZustCena' => 300000.0];
        $monthly(8, '2023-02-28', '2025-12-31', 2500.0, 300000.0);
        $moves[] = ['CisloMajet' => 9, 'Cislo' => 1, 'Datum' => '2024-03-10', 'Typ' => 'Z', 'Castka' => 1000000.0, 'ZustCena' => 1000000.0];
        $monthly(9, '2024-04-30', '2025-12-31', 10000.0, 1000000.0);
        $moves[] = ['CisloMajet' => 10, 'Cislo' => 1, 'Datum' => '2024-05-31', 'Typ' => 'Z', 'Castka' => 120000.0, 'ZustCena' => 120000.0];
        $monthly(10, '2024-06-30', '2025-12-31', 1000.0, 120000.0, ['2024-06' => 500.0]);
        if ($residualWriteOffInUse) {
            $moves[] = ['CisloMajet' => 8, 'Cislo' => 300, 'Datum' => '2025-06-15', 'Typ' => 'U', 'Castka' => 120.0, 'ZustCena' => 214880.0,
                'OdpZustCen' => 1, 'PrUcOpr' => '082100'];
        }
        $append('MjInvPoh.DAT', self::ASSET_MOVE_FIELDS, $moves);
        return $files;
    }

    /**
     * Agenda s pastmi z reálných záloh v roce 2025:
     *   - storno výdeje v bance (vrácený poplatek): `Vydej` = 1 a záporná částka, peníze na
     *     účet přišly (BV25002, v deníku 568/221 zápornou částkou),
     *   - úhrada, kterou Money zaúčtovalo na 221 jinou částkou, než nese bankovní doklad
     *     (kurzový rozdíl 0,72 Kč zaúčtovaný mimo účet banky, BV25003),
     *   - dobropis, jehož celkem se v Money liší od zápisu v deníku o 10 Kč (DV25001).
     * Poslední dva rozdíly jsou už v Money — rekonciliace je má vysvětlit, ne shodit.
     *
     * @return array<string,string>
     */
    public static function filesWithMoneyDifferences(): array
    {
        $files = self::files();
        $append = static function (string $path, array $fields, array $rows) use (&$files): void {
            $table = strtoupper(pathinfo($path, PATHINFO_FILENAME));
            $existing = iterator_to_array(Ms3Table::fromString($files[$path], $table)->rows(), false);
            $files[$path] = Ms3FixtureWriter::table($fields, array_merge($existing, $rows));
        };
        $append('ROK.002/UcOsnova.DAT', self::CHART_FIELDS, [['Ucet' => '663000', 'Nazev' => 'Kurzové zisky']]);
        $append('ROK.002/UcDenik.DAT', self::JOURNAL_FIELDS, [
            ['Cislo' => 40, 'Zdroj' => 'BK', 'Doklad' => 'BV25002', 'Datum' => '2025-09-15', 'Popis' => 'Storno poplatku', 'UcMD' => '568000', 'UcD' => '221001', 'Castka' => -30.0],
            ['Cislo' => 41, 'Zdroj' => 'BK', 'Doklad' => 'BV25003', 'Datum' => '2025-09-20', 'Popis' => 'Úhrada licence', 'UcMD' => '321000', 'UcD' => '221001', 'Castka' => 24.26],
            ['Cislo' => 42, 'Zdroj' => 'BK', 'Doklad' => 'BV25003', 'Datum' => '2025-09-20', 'Popis' => 'Kurzový rozdíl při úhradě', 'UcMD' => '321000', 'UcD' => '663000', 'Castka' => 0.72],
            ['Cislo' => 43, 'Zdroj' => 'FV', 'Doklad' => 'DV25001', 'Datum' => '2025-10-01', 'DatPlnDPH' => '2025-10-01', 'Popis' => 'Dobropis poradenství', 'UcMD' => '311000', 'UcD' => '602000', 'Castka' => -1010.0],
            ['Cislo' => 44, 'Zdroj' => 'FV', 'Doklad' => 'DV25001', 'Datum' => '2025-10-01', 'DatPlnDPH' => '2025-10-01', 'Popis' => 'Dobropis poradenství', 'UcMD' => '311000', 'UcD' => '343200', 'Castka' => -210.0],
            ['Cislo' => 45, 'Zdroj' => 'FP', 'Doklad' => 'FP25006', 'Datum' => '2025-10-10', 'DatPlnDPH' => '2025-10-10', 'Popis' => 'Doplatek po záloze', 'UcMD' => '518000', 'UcD' => '321000', 'Castka' => 529.0],
            ['Cislo' => 46, 'Zdroj' => 'FP', 'Doklad' => 'FP25006', 'Datum' => '2025-10-10', 'DatPlnDPH' => '2025-10-10', 'Popis' => 'Doplatek po záloze', 'UcMD' => '343100', 'UcD' => '321000', 'Castka' => 111.0],
        ]);
        // Konečná faktura po odpočtu zálohy: `CelkemSDPH` nese celou cenu, sazby jen doplatek
        // (`SumZaloha`) — převedený doklad má doplatek, opakovaný převod ho nesmí hlásit jako změněný.
        $append('ROK.002/PFaktury.DAT', self::PURCHASE_FIELDS, [[
            'D_ICO' => self::VENDOR_ICO, 'D_DIC' => 'CZ' . self::VENDOR_ICO, 'D_Nazev' => 'Dodavatel Alfa s.r.o.',
            'D_Ulice' => 'Vzorová 1', 'D_Mesto' => 'Praha', 'D_Psc' => '110 00',
            'SazbaDPH1' => 12.0, 'SazbaDPH2' => 21.0, 'Druh' => 'N', 'KodDPH' => self::KOD_DPH_PURCHASE, 'Uhrada' => 'převodem',
            'Doklad' => 'FP25006', 'PrijatDokl' => 'DF-2025-060', 'VarSymbol' => '2025060',
            'Vystaveno' => '2025-10-10', 'DatUcPr' => '2025-10-10', 'PlnenoDPH' => '2025-10-10', 'Splatno' => '2025-10-24', 'Doruceno' => '2025-10-10',
            'Zaklad_2' => 529.0, 'DPH_2' => 111.0, 'CelkemSDPH' => 14192.0, 'Popis' => 'Doplatek po záloze',
        ]]);
        $append('ROK.002/BankKnih.DAT', self::BANK_FIELDS, [
            ['Doklad' => 'BV25002', 'Ucet' => 'BU', 'Vydej' => 1, 'DatUcPr' => '2025-09-15', 'DatPlat' => '2025-09-15', 'Celkem' => -30.0, 'Popis' => 'Storno poplatku', 'Vypis' => 9],
            ['Doklad' => 'BV25003', 'Ucet' => 'BU', 'Vydej' => 1, 'DatUcPr' => '2025-09-20', 'DatPlat' => '2025-09-20', 'Celkem' => 24.98, 'Popis' => 'Úhrada licence', 'Vypis' => 10],
        ]);
        $append('ROK.002/VFaktury.DAT', self::ISSUED_FIELDS, [[
            'O_ICO' => self::CUSTOMER_ICO, 'O_DIC' => 'CZ' . self::CUSTOMER_ICO, 'O_Nazev' => 'Odběratel Beta a.s.',
            'O_Ulice' => 'Ukázková 7', 'O_Mesto' => 'Ostrava', 'O_Psc' => '702 00',
            'SazbaDPH1' => 12.0, 'SazbaDPH2' => 21.0, 'Druh' => 'N', 'KodDPH' => self::KOD_DPH_SALE, 'Uhrada' => 'převodem',
            'Dobropis' => 1, 'Doklad' => 'DV25001', 'VarSymbol' => '2025002',
            'Vystaveno' => '2025-10-01', 'DatUcPr' => '2025-10-01', 'PlnenoDPH' => '2025-10-01', 'Splatno' => '2025-10-15',
            'Zaklad_2' => -1000.0, 'DPH_2' => -210.0, 'CelkemSDPH' => -1210.0, 'Popis' => 'Dobropis poradenství',
        ]]);
        return $files;
    }

    /**
     * Agenda s úhradami po částech, které Money páruje párovacím symbolem deníku (`ParSym`);
     * faktura v `UDoklad` drží jen poslední doklad, který ji vyrovnal:
     *   - vydaná FV25002 (12 100 Kč) uhrazená BP25004 (5 000) a BP25005 (7 100, `UDoklad`),
     *   - přijatá FP25007 (6 050 Kč) uhrazená BV25004 (2 000) a BV25005 (4 050, `UDoklad`),
     *   - vydaná FV25003 (1 210 Kč) uhrazená BP25006 (1 200) a dorovnaná interním dokladem
     *     ID25005 (odpis 10 Kč, `UDoklad`) - bankovní úhradu najde jen párovací symbol,
     *   - vydaná FV25004 (2 420 Kč) snížená dobropisem DV25002 (−484) se stejným symbolem
     *     a uhrazená BP25007 (1 936),
     *   - přijatá záloha ZF25002 (1 210 Kč, BV25006), její DDKP DZ25002 a konečná FP25008
     *     s položkou odpočtu zálohy (`PFaktPol.Zaloha`, `CisloZaFak`),
     *   - vzájemný zápočet přes 395 interním dokladem ID25010: vydané FV25005 (605)
     *     a FV25006 (363) proti přijaté FP25009 (968),
     *   - přijatá FP25010 (1 210, symbol 2025200) uhrazená BV25008 se symbolem s překlepem (202520),
     *   - záloha ZF25003 (1 000) zaplacená BV25009 a vrácená BP25010, DDKP DZ25003 odkazující
     *     na bankovní doklad úhrady („záloha BV25009"),
     *   - vydaná FV25007 (605) stornovaná zápornou fakturou FV25008 (ne dobropisem) se stejným symbolem.
     *
     * @return array<string,string>
     */
    public static function filesWithPartialPayments(): array
    {
        $files = self::files();
        $journalFields = array_merge(self::JOURNAL_FIELDS, [['ParSym', 'C', 20]]);
        $append = static function (string $path, array $fields, array $rows) use (&$files): void {
            $table = strtoupper(pathinfo($path, PATHINFO_FILENAME));
            $existing = iterator_to_array(Ms3Table::fromString($files[$path], $table)->rows(), false);
            $files[$path] = Ms3FixtureWriter::table($fields, array_merge($existing, $rows));
        };
        $append('ROK.002/UcDenik.DAT', $journalFields, [
            ['Cislo' => 60, 'Zdroj' => 'FV', 'Doklad' => 'FV25002', 'Datum' => '2025-06-01', 'DatPlnDPH' => '2025-06-01', 'Popis' => 'Školení', 'UcMD' => '311000', 'UcD' => '602000', 'Castka' => 10000.0, 'ParSym' => '2025003'],
            ['Cislo' => 61, 'Zdroj' => 'FV', 'Doklad' => 'FV25002', 'Datum' => '2025-06-01', 'DatPlnDPH' => '2025-06-01', 'Popis' => 'Školení', 'UcMD' => '311000', 'UcD' => '343200', 'Castka' => 2100.0, 'ParSym' => '2025003'],
            ['Cislo' => 62, 'Zdroj' => 'BK', 'Doklad' => 'BP25004', 'Datum' => '2025-06-10', 'Popis' => 'Záloha na FV25002', 'UcMD' => '221001', 'UcD' => '311000', 'Castka' => 5000.0, 'ParSym' => '2025003'],
            ['Cislo' => 63, 'Zdroj' => 'BK', 'Doklad' => 'BP25005', 'Datum' => '2025-06-30', 'Popis' => 'Doplatek FV25002', 'UcMD' => '221001', 'UcD' => '311000', 'Castka' => 7100.0, 'ParSym' => '2025003'],
            ['Cislo' => 64, 'Zdroj' => 'FP', 'Doklad' => 'FP25007', 'Datum' => '2025-06-05', 'DatPlnDPH' => '2025-06-05', 'Popis' => 'Servis', 'UcMD' => '518000', 'UcD' => '321000', 'Castka' => 5000.0, 'ParSym' => '2025070', 'ParICO' => self::VENDOR_ICO],
            ['Cislo' => 65, 'Zdroj' => 'FP', 'Doklad' => 'FP25007', 'Datum' => '2025-06-05', 'DatPlnDPH' => '2025-06-05', 'Popis' => 'Servis', 'UcMD' => '343100', 'UcD' => '321000', 'Castka' => 1050.0, 'ParSym' => '2025070', 'ParICO' => self::VENDOR_ICO],
            ['Cislo' => 66, 'Zdroj' => 'BK', 'Doklad' => 'BV25004', 'Datum' => '2025-06-12', 'Popis' => 'Splátka FP25007', 'UcMD' => '321000', 'UcD' => '221001', 'Castka' => 2000.0, 'ParSym' => '2025070', 'ParICO' => self::VENDOR_ICO],
            ['Cislo' => 67, 'Zdroj' => 'BK', 'Doklad' => 'BV25005', 'Datum' => '2025-07-02', 'Popis' => 'Doplatek FP25007', 'UcMD' => '321000', 'UcD' => '221001', 'Castka' => 4050.0, 'ParSym' => '2025070', 'ParICO' => self::VENDOR_ICO],
            ['Cislo' => 68, 'Zdroj' => 'FV', 'Doklad' => 'FV25003', 'Datum' => '2025-06-15', 'DatPlnDPH' => '2025-06-15', 'Popis' => 'Konzultace', 'UcMD' => '311000', 'UcD' => '602000', 'Castka' => 1000.0, 'ParSym' => '2025004'],
            ['Cislo' => 69, 'Zdroj' => 'FV', 'Doklad' => 'FV25003', 'Datum' => '2025-06-15', 'DatPlnDPH' => '2025-06-15', 'Popis' => 'Konzultace', 'UcMD' => '311000', 'UcD' => '343200', 'Castka' => 210.0, 'ParSym' => '2025004'],
            ['Cislo' => 70, 'Zdroj' => 'BK', 'Doklad' => 'BP25006', 'Datum' => '2025-06-25', 'Popis' => 'Úhrada FV25003', 'UcMD' => '221001', 'UcD' => '311000', 'Castka' => 1200.0, 'ParSym' => '2025004'],
            ['Cislo' => 71, 'Zdroj' => 'ID', 'Doklad' => 'ID25005', 'Datum' => '2025-06-30', 'Popis' => 'Odpis nedoplatku FV25003', 'UcMD' => '568000', 'UcD' => '311000', 'Castka' => 10.0, 'ParSym' => '2025004'],
            ['Cislo' => 72, 'Zdroj' => 'FV', 'Doklad' => 'FV25004', 'Datum' => '2025-07-01', 'DatPlnDPH' => '2025-07-01', 'Popis' => 'Audit', 'UcMD' => '311000', 'UcD' => '602000', 'Castka' => 2000.0, 'ParSym' => '2025005'],
            ['Cislo' => 73, 'Zdroj' => 'FV', 'Doklad' => 'FV25004', 'Datum' => '2025-07-01', 'DatPlnDPH' => '2025-07-01', 'Popis' => 'Audit', 'UcMD' => '311000', 'UcD' => '343200', 'Castka' => 420.0, 'ParSym' => '2025005'],
            ['Cislo' => 74, 'Zdroj' => 'FV', 'Doklad' => 'DV25002', 'Datum' => '2025-07-10', 'DatPlnDPH' => '2025-07-10', 'Popis' => 'Dobropis auditu', 'UcMD' => '311000', 'UcD' => '602000', 'Castka' => -400.0, 'ParSym' => '2025005'],
            ['Cislo' => 75, 'Zdroj' => 'FV', 'Doklad' => 'DV25002', 'Datum' => '2025-07-10', 'DatPlnDPH' => '2025-07-10', 'Popis' => 'Dobropis auditu', 'UcMD' => '311000', 'UcD' => '343200', 'Castka' => -84.0, 'ParSym' => '2025005'],
            ['Cislo' => 76, 'Zdroj' => 'BK', 'Doklad' => 'BP25007', 'Datum' => '2025-07-20', 'Popis' => 'Úhrada FV25004 po dobropisu', 'UcMD' => '221001', 'UcD' => '311000', 'Castka' => 1936.0, 'ParSym' => '2025005'],
            ['Cislo' => 77, 'Zdroj' => 'BK', 'Doklad' => 'BV25006', 'Datum' => '2025-08-01', 'Popis' => 'Záloha ZF25002', 'UcMD' => '314000', 'UcD' => '221001', 'Castka' => 1210.0],
            ['Cislo' => 78, 'Zdroj' => 'FP', 'Doklad' => 'DZ25002', 'Datum' => '2025-08-01', 'DatPlnDPH' => '2025-08-01', 'Popis' => 'DPH ze zálohy ZF25002', 'UcMD' => '343100', 'UcD' => '314000', 'Castka' => 210.0],
            ['Cislo' => 79, 'Zdroj' => 'FP', 'Doklad' => 'FP25008', 'Datum' => '2025-08-20', 'DatPlnDPH' => '2025-08-20', 'Popis' => 'Vyúčtování zálohy', 'UcMD' => '518000', 'UcD' => '321000', 'Castka' => 1000.0],
            ['Cislo' => 80, 'Zdroj' => 'FP', 'Doklad' => 'FP25008', 'Datum' => '2025-08-20', 'DatPlnDPH' => '2025-08-20', 'Popis' => 'Vyúčtování zálohy', 'UcMD' => '343100', 'UcD' => '321000', 'Castka' => 210.0],
            ['Cislo' => 81, 'Zdroj' => 'FP', 'Doklad' => 'FP25008', 'Datum' => '2025-08-20', 'Popis' => 'Odpočet zálohy ZF25002', 'UcMD' => '321000', 'UcD' => '314000', 'Castka' => 1000.0],
            ['Cislo' => 82, 'Zdroj' => 'FP', 'Doklad' => 'FP25008', 'Datum' => '2025-08-20', 'Popis' => 'Odpočet zálohy ZF25002 - DPH', 'UcMD' => '321000', 'UcD' => '343100', 'Castka' => 210.0],
            // Vzájemný zápočet přes 395 jedním interním dokladem: dvě vydané a jedna přijatá faktura.
            ['Cislo' => 83, 'Zdroj' => 'FV', 'Doklad' => 'FV25005', 'Datum' => '2025-09-01', 'DatPlnDPH' => '2025-09-01', 'Popis' => 'Pronájem', 'UcMD' => '311000', 'UcD' => '602000', 'Castka' => 500.0, 'ParSym' => '2025010'],
            ['Cislo' => 84, 'Zdroj' => 'FV', 'Doklad' => 'FV25005', 'Datum' => '2025-09-01', 'DatPlnDPH' => '2025-09-01', 'Popis' => 'Pronájem', 'UcMD' => '311000', 'UcD' => '343200', 'Castka' => 105.0, 'ParSym' => '2025010'],
            ['Cislo' => 85, 'Zdroj' => 'FV', 'Doklad' => 'FV25006', 'Datum' => '2025-09-02', 'DatPlnDPH' => '2025-09-02', 'Popis' => 'Služby', 'UcMD' => '311000', 'UcD' => '602000', 'Castka' => 300.0, 'ParSym' => '2025011'],
            ['Cislo' => 86, 'Zdroj' => 'FV', 'Doklad' => 'FV25006', 'Datum' => '2025-09-02', 'DatPlnDPH' => '2025-09-02', 'Popis' => 'Služby', 'UcMD' => '311000', 'UcD' => '343200', 'Castka' => 63.0, 'ParSym' => '2025011'],
            ['Cislo' => 87, 'Zdroj' => 'FP', 'Doklad' => 'FP25009', 'Datum' => '2025-09-03', 'DatPlnDPH' => '2025-09-03', 'Popis' => 'Materiál', 'UcMD' => '518000', 'UcD' => '321000', 'Castka' => 800.0, 'ParSym' => '2025012', 'ParICO' => self::VENDOR_ICO],
            ['Cislo' => 88, 'Zdroj' => 'FP', 'Doklad' => 'FP25009', 'Datum' => '2025-09-03', 'DatPlnDPH' => '2025-09-03', 'Popis' => 'Materiál', 'UcMD' => '343100', 'UcD' => '321000', 'Castka' => 168.0, 'ParSym' => '2025012', 'ParICO' => self::VENDOR_ICO],
            ['Cislo' => 89, 'Zdroj' => 'ID', 'Doklad' => 'ID25010', 'Datum' => '2025-09-30', 'Popis' => 'Zápočet FV25005', 'UcMD' => '395000', 'UcD' => '311000', 'Castka' => 605.0, 'ParSym' => '2025010'],
            ['Cislo' => 90, 'Zdroj' => 'ID', 'Doklad' => 'ID25010', 'Datum' => '2025-09-30', 'Popis' => 'Zápočet FV25006', 'UcMD' => '395000', 'UcD' => '311000', 'Castka' => 363.0, 'ParSym' => '2025011'],
            ['Cislo' => 91, 'Zdroj' => 'ID', 'Doklad' => 'ID25010', 'Datum' => '2025-09-30', 'Popis' => 'Zápočet FP25009', 'UcMD' => '321000', 'UcD' => '395000', 'Castka' => 968.0, 'ParSym' => '2025012', 'ParICO' => self::VENDOR_ICO],
            // Úhrada s překlepem v párovacím symbolu (chybí číslice).
            ['Cislo' => 92, 'Zdroj' => 'FP', 'Doklad' => 'FP25010', 'Datum' => '2025-09-20', 'DatPlnDPH' => '2025-09-20', 'Popis' => 'Oprava', 'UcMD' => '518000', 'UcD' => '321000', 'Castka' => 1000.0, 'ParSym' => '2025200', 'ParICO' => self::VENDOR_ICO],
            ['Cislo' => 93, 'Zdroj' => 'FP', 'Doklad' => 'FP25010', 'Datum' => '2025-09-20', 'DatPlnDPH' => '2025-09-20', 'Popis' => 'Oprava', 'UcMD' => '343100', 'UcD' => '321000', 'Castka' => 210.0, 'ParSym' => '2025200', 'ParICO' => self::VENDOR_ICO],
            ['Cislo' => 94, 'Zdroj' => 'BK', 'Doklad' => 'BV25008', 'Datum' => '2025-10-05', 'Popis' => 'Úhrada FP25010', 'UcMD' => '321000', 'UcD' => '221001', 'Castka' => 1210.0, 'ParSym' => '202520', 'ParICO' => self::VENDOR_ICO],
            // Záloha ZF25003 zaplacená BV25009 a vrácená BP25010.
            ['Cislo' => 95, 'Zdroj' => 'BK', 'Doklad' => 'BV25009', 'Datum' => '2025-10-01', 'Popis' => 'Záloha ZF25003', 'UcMD' => '314000', 'UcD' => '221001', 'Castka' => 1000.0],
            ['Cislo' => 96, 'Zdroj' => 'BK', 'Doklad' => 'BP25010', 'Datum' => '2025-10-20', 'Popis' => 'Vrácení zálohy ZF25003', 'UcMD' => '221001', 'UcD' => '314000', 'Castka' => 1000.0],
            // Storno faktury: Money ho vede jako fakturu se zápornou částkou (ne dobropis) se symbolem faktury.
            ['Cislo' => 97, 'Zdroj' => 'FV', 'Doklad' => 'FV25007', 'Datum' => '2025-10-01', 'DatPlnDPH' => '2025-10-01', 'Popis' => 'Prodej', 'UcMD' => '311000', 'UcD' => '602000', 'Castka' => 500.0, 'ParSym' => '2025020'],
            ['Cislo' => 98, 'Zdroj' => 'FV', 'Doklad' => 'FV25007', 'Datum' => '2025-10-01', 'DatPlnDPH' => '2025-10-01', 'Popis' => 'Prodej', 'UcMD' => '311000', 'UcD' => '343200', 'Castka' => 105.0, 'ParSym' => '2025020'],
            ['Cislo' => 99, 'Zdroj' => 'FV', 'Doklad' => 'FV25008', 'Datum' => '2025-10-02', 'DatPlnDPH' => '2025-10-02', 'Popis' => 'Storno faktury FV25007', 'UcMD' => '311000', 'UcD' => '602000', 'Castka' => -500.0, 'ParSym' => '2025020'],
            ['Cislo' => 100, 'Zdroj' => 'FV', 'Doklad' => 'FV25008', 'Datum' => '2025-10-02', 'DatPlnDPH' => '2025-10-02', 'Popis' => 'Storno faktury FV25007', 'UcMD' => '311000', 'UcD' => '343200', 'Castka' => -105.0, 'ParSym' => '2025020'],
        ]);
        $append('ROK.002/UcOsnova.DAT', self::CHART_FIELDS, [['Ucet' => '395000', 'Nazev' => 'Vnitřní zúčtování']]);
        $vendor = [
            'D_ICO' => self::VENDOR_ICO, 'D_DIC' => 'CZ' . self::VENDOR_ICO, 'D_Nazev' => 'Dodavatel Alfa s.r.o.',
            'D_Ulice' => 'Vzorová 1', 'D_Mesto' => 'Praha', 'D_Psc' => '110 00',
            'SazbaDPH1' => 12.0, 'SazbaDPH2' => 21.0, 'KodDPH' => self::KOD_DPH_PURCHASE, 'Uhrada' => 'převodem',
        ];
        $append('ROK.002/PFaktury.DAT', array_merge(self::PURCHASE_FIELDS, [['Cislo', 'L', 4]]), [
            $vendor + ['Cislo' => 50, 'Druh' => 'Z', 'Doklad' => 'ZF25002', 'PrijatDokl' => 'ZF-2025-002', 'VarSymbol' => '2025090',
                'Vystaveno' => '2025-07-25', 'DatUcPr' => '2025-07-25', 'PlnenoDPH' => '2025-07-25', 'Splatno' => '2025-08-01', 'Doruceno' => '2025-07-25',
                'Zaklad_2' => 1000.0, 'DPH_2' => 210.0, 'CelkemSDPH' => 1210.0, 'Uhrazeno' => '2025-08-01', 'UDoklad' => 'BV25006', 'Popis' => 'Záloha na servis'],
            $vendor + ['Cislo' => 51, 'Druh' => 'D', 'Doklad' => 'DZ25002', 'PrijatDokl' => 'DZ-2025-002', 'VarSymbol' => '2025092',
                'Vystaveno' => '2025-08-01', 'DatUcPr' => '2025-08-01', 'PlnenoDPH' => '2025-08-01', 'Splatno' => '2025-08-01', 'Doruceno' => '2025-08-01',
                'Zaklad_2' => 1000.0, 'DPH_2' => 210.0, 'CelkemSDPH' => 1210.0, 'Popis' => 'Doklad k přijaté platbě (záloha ZF25002)'],
            $vendor + ['Cislo' => 52, 'Druh' => 'N', 'Doklad' => 'FP25008', 'PrijatDokl' => 'DF-2025-091', 'VarSymbol' => '2025091',
                'Vystaveno' => '2025-08-20', 'DatUcPr' => '2025-08-20', 'PlnenoDPH' => '2025-08-20', 'Splatno' => '2025-09-03', 'Doruceno' => '2025-08-20',
                'Zaklad_2' => 1000.0, 'DPH_2' => 210.0, 'CelkemSDPH' => 1210.0, 'Uhrazeno' => '2025-08-20', 'Popis' => 'Vyúčtování zálohy'],
            $vendor + ['Cislo' => 53, 'Druh' => 'Z', 'Doklad' => 'ZF25003', 'PrijatDokl' => 'ZF-2025-003', 'VarSymbol' => '2025093',
                'Vystaveno' => '2025-09-25', 'DatUcPr' => '2025-09-25', 'PlnenoDPH' => '2025-09-25', 'Splatno' => '2025-10-01', 'Doruceno' => '2025-09-25',
                'Zaklad_2' => 1000.0, 'DPH_2' => 0.0, 'CelkemSDPH' => 1000.0, 'Uhrazeno' => '2025-10-01', 'UDoklad' => 'BV25009', 'Popis' => 'Záloha na opravu'],
            $vendor + ['Cislo' => 54, 'Druh' => 'D', 'Doklad' => 'DZ25003', 'PrijatDokl' => 'DZ-2025-003', 'VarSymbol' => '2025094',
                'Vystaveno' => '2025-10-01', 'DatUcPr' => '2025-10-01', 'PlnenoDPH' => '2025-10-01', 'Splatno' => '2025-10-01', 'Doruceno' => '2025-10-01',
                'Zaklad_2' => 1000.0, 'DPH_2' => 0.0, 'CelkemSDPH' => 1000.0, 'Popis' => 'Doklad k přijaté platbě (záloha BV25009)'],
            $vendor + ['Cislo' => 55, 'Druh' => 'N', 'Doklad' => 'FP25009', 'PrijatDokl' => 'DF-2025-112', 'VarSymbol' => '2025012',
                'Vystaveno' => '2025-09-03', 'DatUcPr' => '2025-09-03', 'PlnenoDPH' => '2025-09-03', 'Splatno' => '2025-09-17', 'Doruceno' => '2025-09-03',
                'Zaklad_2' => 800.0, 'DPH_2' => 168.0, 'CelkemSDPH' => 968.0, 'Uhrazeno' => '2025-09-30', 'UDoklad' => 'ID25010', 'Popis' => 'Materiál'],
            $vendor + ['Cislo' => 56, 'Druh' => 'N', 'Doklad' => 'FP25010', 'PrijatDokl' => 'DF-2025-200', 'VarSymbol' => '2025200',
                'Vystaveno' => '2025-09-20', 'DatUcPr' => '2025-09-20', 'PlnenoDPH' => '2025-09-20', 'Splatno' => '2025-10-04', 'Doruceno' => '2025-09-20',
                'Zaklad_2' => 1000.0, 'DPH_2' => 210.0, 'CelkemSDPH' => 1210.0, 'Uhrazeno' => '2025-10-05', 'Popis' => 'Oprava'],
        ]);
        $files['ROK.002/PFaktPol.DAT'] = Ms3FixtureWriter::table([
            ['Cislo', 'L', 4], ['Popis', 'C', 60], ['Cena', 'E', 10], ['PocetMJ', 'E', 10], ['Zaloha', 'B', 1], ['CisloZaFak', 'L', 4], ['RokZaFak', 'C', 3],
        ], [
            ['Cislo' => 52, 'Popis' => 'Servis', 'Cena' => 1000.0, 'PocetMJ' => 1.0],
            ['Cislo' => 52, 'Popis' => 'Odpočet zálohy ZF25002 (daň. dokl. DZ25002)', 'Cena' => -1000.0, 'PocetMJ' => 1.0, 'Zaloha' => 1, 'CisloZaFak' => 50],
        ]);
        $customer = [
            'O_ICO' => self::CUSTOMER_ICO, 'O_DIC' => 'CZ' . self::CUSTOMER_ICO, 'O_Nazev' => 'Odběratel Beta a.s.',
            'O_Ulice' => 'Ukázková 7', 'O_Mesto' => 'Ostrava', 'O_Psc' => '702 00',
            'SazbaDPH1' => 12.0, 'SazbaDPH2' => 21.0, 'Druh' => 'N', 'KodDPH' => self::KOD_DPH_SALE, 'Uhrada' => 'převodem',
        ];
        $append('ROK.002/VFaktury.DAT', self::ISSUED_FIELDS, [
            $customer + ['Doklad' => 'FV25002', 'VarSymbol' => '2025003', 'Vystaveno' => '2025-06-01', 'DatUcPr' => '2025-06-01', 'PlnenoDPH' => '2025-06-01', 'Splatno' => '2025-06-30',
                'Zaklad_2' => 10000.0, 'DPH_2' => 2100.0, 'CelkemSDPH' => 12100.0, 'Uhrazeno' => '2025-06-30', 'UDoklad' => 'BP25005', 'Popis' => 'Školení'],
            $customer + ['Doklad' => 'FV25003', 'VarSymbol' => '2025004', 'Vystaveno' => '2025-06-15', 'DatUcPr' => '2025-06-15', 'PlnenoDPH' => '2025-06-15', 'Splatno' => '2025-06-29',
                'Zaklad_2' => 1000.0, 'DPH_2' => 210.0, 'CelkemSDPH' => 1210.0, 'Uhrazeno' => '2025-06-30', 'UDoklad' => 'ID25005', 'Popis' => 'Konzultace'],
            $customer + ['Doklad' => 'FV25004', 'VarSymbol' => '2025005', 'Vystaveno' => '2025-07-01', 'DatUcPr' => '2025-07-01', 'PlnenoDPH' => '2025-07-01', 'Splatno' => '2025-07-15',
                'Zaklad_2' => 2000.0, 'DPH_2' => 420.0, 'CelkemSDPH' => 2420.0, 'Uhrazeno' => '2025-07-20', 'UDoklad' => 'BP25007', 'Popis' => 'Audit'],
            $customer + ['Dobropis' => 1, 'Doklad' => 'DV25002', 'VarSymbol' => '2025006', 'Vystaveno' => '2025-07-10', 'DatUcPr' => '2025-07-10', 'PlnenoDPH' => '2025-07-10', 'Splatno' => '2025-07-24',
                'Zaklad_2' => -400.0, 'DPH_2' => -84.0, 'CelkemSDPH' => -484.0, 'Uhrazeno' => '2025-07-20', 'Popis' => 'Dobropis auditu'],
            $customer + ['Doklad' => 'FV25005', 'VarSymbol' => '2025010', 'Vystaveno' => '2025-09-01', 'DatUcPr' => '2025-09-01', 'PlnenoDPH' => '2025-09-01', 'Splatno' => '2025-09-15',
                'Zaklad_2' => 500.0, 'DPH_2' => 105.0, 'CelkemSDPH' => 605.0, 'Uhrazeno' => '2025-09-30', 'UDoklad' => 'ID25010', 'Popis' => 'Pronájem'],
            $customer + ['Doklad' => 'FV25006', 'VarSymbol' => '2025011', 'Vystaveno' => '2025-09-02', 'DatUcPr' => '2025-09-02', 'PlnenoDPH' => '2025-09-02', 'Splatno' => '2025-09-16',
                'Zaklad_2' => 300.0, 'DPH_2' => 63.0, 'CelkemSDPH' => 363.0, 'Uhrazeno' => '2025-09-30', 'UDoklad' => 'ID25010', 'Popis' => 'Služby'],
            $customer + ['Doklad' => 'FV25007', 'VarSymbol' => '2025020', 'Vystaveno' => '2025-10-01', 'DatUcPr' => '2025-10-01', 'PlnenoDPH' => '2025-10-01', 'Splatno' => '2025-10-15',
                'Zaklad_2' => 500.0, 'DPH_2' => 105.0, 'CelkemSDPH' => 605.0, 'Uhrazeno' => '2025-10-02', 'Popis' => 'Prodej'],
            $customer + ['Doklad' => 'FV25008', 'VarSymbol' => '2025021', 'Vystaveno' => '2025-10-02', 'DatUcPr' => '2025-10-02', 'PlnenoDPH' => '2025-10-02', 'Splatno' => '2025-10-02',
                'Zaklad_2' => -500.0, 'DPH_2' => -105.0, 'CelkemSDPH' => -605.0, 'Uhrazeno' => '2025-10-02', 'Popis' => 'Storno faktury FV25007'],
        ]);
        $append('ROK.002/PFaktury.DAT', array_merge(self::PURCHASE_FIELDS, [['Cislo', 'L', 4]]), [[
            'D_ICO' => self::VENDOR_ICO, 'D_DIC' => 'CZ' . self::VENDOR_ICO, 'D_Nazev' => 'Dodavatel Alfa s.r.o.',
            'D_Ulice' => 'Vzorová 1', 'D_Mesto' => 'Praha', 'D_Psc' => '110 00',
            'SazbaDPH1' => 12.0, 'SazbaDPH2' => 21.0, 'Druh' => 'N', 'KodDPH' => self::KOD_DPH_PURCHASE, 'Uhrada' => 'převodem',
            'Doklad' => 'FP25007', 'PrijatDokl' => 'DF-2025-070', 'VarSymbol' => '2025070',
            'Vystaveno' => '2025-06-05', 'DatUcPr' => '2025-06-05', 'PlnenoDPH' => '2025-06-05', 'Splatno' => '2025-06-19', 'Doruceno' => '2025-06-05',
            'Zaklad_2' => 5000.0, 'DPH_2' => 1050.0, 'CelkemSDPH' => 6050.0, 'Uhrazeno' => '2025-07-02', 'UDoklad' => 'BV25005', 'Popis' => 'Servis',
        ]]);
        $append('ROK.002/BankKnih.DAT', self::BANK_FIELDS, [
            ['Doklad' => 'BP25004', 'Ucet' => 'BU', 'Vydej' => 0, 'DatUcPr' => '2025-06-10', 'DatPlat' => '2025-06-10', 'Celkem' => 5000.0, 'VarSym' => '2025003', 'AdNazev' => 'Odběratel Beta a.s.', 'Popis' => 'Záloha na FV25002', 'Vypis' => 11],
            ['Doklad' => 'BP25005', 'Ucet' => 'BU', 'Vydej' => 0, 'DatUcPr' => '2025-06-30', 'DatPlat' => '2025-06-30', 'Celkem' => 7100.0, 'VarSym' => '2025003', 'AdNazev' => 'Odběratel Beta a.s.', 'Popis' => 'Doplatek FV25002', 'Vypis' => 12],
            ['Doklad' => 'BV25004', 'Ucet' => 'BU', 'Vydej' => 1, 'DatUcPr' => '2025-06-12', 'DatPlat' => '2025-06-12', 'Celkem' => 2000.0, 'VarSym' => '2025070', 'AdNazev' => 'Dodavatel Alfa s.r.o.', 'Popis' => 'Splátka FP25007', 'Vypis' => 11],
            ['Doklad' => 'BV25005', 'Ucet' => 'BU', 'Vydej' => 1, 'DatUcPr' => '2025-07-02', 'DatPlat' => '2025-07-02', 'Celkem' => 4050.0, 'VarSym' => '2025070', 'AdNazev' => 'Dodavatel Alfa s.r.o.', 'Popis' => 'Doplatek FP25007', 'Vypis' => 13],
            ['Doklad' => 'BP25006', 'Ucet' => 'BU', 'Vydej' => 0, 'DatUcPr' => '2025-06-25', 'DatPlat' => '2025-06-25', 'Celkem' => 1200.0, 'VarSym' => '2025004', 'AdNazev' => 'Odběratel Beta a.s.', 'Popis' => 'Úhrada FV25003', 'Vypis' => 12],
            ['Doklad' => 'BP25007', 'Ucet' => 'BU', 'Vydej' => 0, 'DatUcPr' => '2025-07-20', 'DatPlat' => '2025-07-20', 'Celkem' => 1936.0, 'VarSym' => '2025005', 'AdNazev' => 'Odběratel Beta a.s.', 'Popis' => 'Úhrada FV25004 po dobropisu', 'Vypis' => 13],
            ['Doklad' => 'BV25006', 'Ucet' => 'BU', 'Vydej' => 1, 'DatUcPr' => '2025-08-01', 'DatPlat' => '2025-08-01', 'Celkem' => 1210.0, 'VarSym' => '2025090', 'AdNazev' => 'Dodavatel Alfa s.r.o.', 'Popis' => 'Záloha ZF25002', 'Vypis' => 14],
            ['Doklad' => 'BV25008', 'Ucet' => 'BU', 'Vydej' => 1, 'DatUcPr' => '2025-10-05', 'DatPlat' => '2025-10-05', 'Celkem' => 1210.0, 'VarSym' => '202520', 'AdNazev' => 'Dodavatel Alfa s.r.o.', 'Popis' => 'Úhrada FP25010', 'Vypis' => 15],
            ['Doklad' => 'BV25009', 'Ucet' => 'BU', 'Vydej' => 1, 'DatUcPr' => '2025-10-01', 'DatPlat' => '2025-10-01', 'Celkem' => 1000.0, 'VarSym' => '2025093', 'AdNazev' => 'Dodavatel Alfa s.r.o.', 'Popis' => 'Záloha ZF25003', 'Vypis' => 15],
            ['Doklad' => 'BP25010', 'Ucet' => 'BU', 'Vydej' => 0, 'DatUcPr' => '2025-10-20', 'DatPlat' => '2025-10-20', 'Celkem' => 1000.0, 'VarSym' => '2025093', 'AdNazev' => 'Dodavatel Alfa s.r.o.', 'Popis' => 'Vrácení zálohy ZF25003', 'Vypis' => 16],
        ]);
        return $files;
    }

    /**
     * Agenda s úhradami po částech ({@see filesWithPartialPayments()}) a vyrovnáními, která
     * párovací symbol sám nepřiřadí:
     *   - interní doklad ID25020 „zápočet FP25020 x FV25020" (MD 321 / D 311, symbol bez
     *     dokladu) vyrovnává přijatou FP25020 i vydanou FV25020 (po 1 210 Kč),
     *   - vydané FV25021 (605) a FV25022 (363) se sdíleným symbolem 0000000000, BP25021 (605)
     *     s číslem faktury v popisu hradí jen FV25021; FV25021 Money neoznačilo jako uhrazenou,
     *   - interní doklad ID25021 „zápočet FV25023,FV25024 X FP25021" (800) vyrovnává dvě
     *     vydané faktury (500 a 300) a přijatou FP25021 (800),
     *   - vydaná FV25025 (1 210) bez data úhrady zaplacená po částech BP25025 (500) a BP25026 (200),
     *   - vydaná FV25026 (2 420) uhrazená BP25027 (1 620) a BP25028 (800); týž BP25028 (1 405)
     *     hradí i FV25027 (605) - obě faktury mají v `UDoklad` BP25028,
     *   - přijaté FP25022 (Dodavatel Alfa) a FP25023 (jiný dodavatel) se stejným symbolem,
     *     BV25022 s IČO druhého dodavatele hradí FP25023; vydané FV25028 (předpis bez IČO)
     *     a FV25029 (s IČO) se stejným symbolem, BP25029 bez IČO hradí FV25028,
     *   - konečná FV25030 (1 210) s odpočtem zálohy (800, MD 324 / D 311) větším než zbytek
     *     předpisu na 311, který uhradil BP25030 (410).
     *
     * @return array<string,string>
     */
    public static function filesWithSettlementGaps(): array
    {
        $files = self::filesWithPartialPayments();
        $journalFields = array_merge(self::JOURNAL_FIELDS, [['ParSym', 'C', 20]]);
        $append = static function (string $path, array $fields, array $rows) use (&$files): void {
            $table = strtoupper(pathinfo($path, PATHINFO_FILENAME));
            $existing = iterator_to_array(Ms3Table::fromString($files[$path], $table)->rows(), false);
            $files[$path] = Ms3FixtureWriter::table($fields, array_merge($existing, $rows));
        };
        $n = 200;
        $line = static function (string $zdroj, string $doklad, string $datum, string $popis, string $md, string $d, float $castka, string $sym, string $ico = '') use (&$n): array {
            $row = ['Cislo' => $n++, 'Zdroj' => $zdroj, 'Doklad' => $doklad, 'Datum' => $datum, 'Popis' => $popis, 'UcMD' => $md, 'UcD' => $d, 'Castka' => $castka, 'ParSym' => $sym];
            if (in_array($zdroj, ['FV', 'FP'], true)) {
                $row['DatPlnDPH'] = $datum;
            }
            return $ico === '' ? $row : $row + ['ParICO' => $ico];
        };
        $sale = static fn (string $no, string $date, float $base, string $sym, string $ico = ''): array => [
            $line('FV', $no, $date, 'Prodej', '311000', '602000', $base, $sym, $ico),
            $line('FV', $no, $date, 'Prodej', '311000', '343200', round($base * 0.21, 2), $sym, $ico),
        ];
        $purchase = static fn (string $no, string $date, float $base, string $sym, string $ico): array => [
            $line('FP', $no, $date, 'Nákup', '518000', '321000', $base, $sym, $ico),
            $line('FP', $no, $date, 'Nákup', '343100', '321000', round($base * 0.21, 2), $sym, $ico),
        ];
        $append('ROK.002/UcDenik.DAT', $journalFields, array_merge(
            $sale('FV25020', '2025-11-02', 1000.0, '2025500'),
            $purchase('FP25020', '2025-11-03', 1000.0, '2025501', self::VENDOR_ICO),
            [$line('ID', 'ID25020', '2025-11-30', 'zápočet FP25020 x FV25020', '321000', '311000', 1210.0, '9999001')],
            $sale('FV25022', '2025-10-25', 300.0, '0000000000'),
            $sale('FV25021', '2025-11-05', 500.0, '0000000000'),
            [$line('BK', 'BP25021', '2025-11-10', 'FV25021 - Úhrada faktury vydané', '221001', '311000', 605.0, '0000000000')],
            $sale('FV25023', '2025-11-06', 413.22, '2025523'),
            $sale('FV25024', '2025-11-07', 247.93, '2025524'),
            $purchase('FP25021', '2025-11-08', 661.16, '2025530', self::VENDOR_ICO),
            [$line('ID', 'ID25021', '2025-11-28', 'zápočet FV25023,FV25024 X FP25021', '321000', '311000', 800.0, '9999002')],
            $sale('FV25025', '2025-11-09', 1000.0, '2025525'),
            [
                $line('BK', 'BP25025', '2025-11-12', 'Splátka', '221001', '311000', 500.0, '2025525'),
                $line('BK', 'BP25026', '2025-11-20', 'Splátka', '221001', '311000', 200.0, '2025525'),
            ],
            $sale('FV25026', '2025-11-10', 2000.0, '2025526'),
            $sale('FV25027', '2025-11-11', 500.0, '2025527'),
            [
                $line('BK', 'BP25027', '2025-11-25', 'Úhrada', '221001', '311000', 1620.0, '2025526'),
                $line('BK', 'BP25028', '2025-12-05', 'Úhrada', '221001', '311000', 800.0, '2025526'),
                $line('BK', 'BP25028', '2025-12-05', 'Úhrada', '221001', '311000', 605.0, '2025527'),
            ],
            $purchase('FP25022', '2025-11-12', 1000.0, '2025600', self::VENDOR_ICO),
            $purchase('FP25023', '2025-11-13', 500.0, '2025600', self::SECOND_VENDOR_ICO),
            [$line('BK', 'BV25022', '2025-11-27', 'Úhrada', '321000', '221001', 605.0, '2025600', self::SECOND_VENDOR_ICO)],
            $sale('FV25028', '2025-11-14', 500.0, '2025610'),
            $sale('FV25029', '2025-11-15', 300.0, '2025610', self::CUSTOMER_ICO),
            [$line('BK', 'BP25029', '2025-11-29', 'Úhrada', '221001', '311000', 605.0, '2025610')],
            $sale('FV25030', '2025-12-01', 1000.0, '2025620'),
            [
                $line('FV', 'FV25030', '2025-12-01', 'Odpočet zálohy', '324000', '311000', 800.0, '2025620'),
                $line('BK', 'BP25030', '2025-12-10', 'Úhrada', '221001', '311000', 410.0, '2025620'),
            ],
        ));
        $append('ROK.002/UcOsnova.DAT', self::CHART_FIELDS, [['Ucet' => '324000', 'Nazev' => 'Přijaté zálohy']]);
        $customer = [
            'O_ICO' => self::CUSTOMER_ICO, 'O_DIC' => 'CZ' . self::CUSTOMER_ICO, 'O_Nazev' => 'Odběratel Beta a.s.',
            'O_Ulice' => 'Ukázková 7', 'O_Mesto' => 'Ostrava', 'O_Psc' => '702 00',
            'SazbaDPH1' => 12.0, 'SazbaDPH2' => 21.0, 'Druh' => 'N', 'KodDPH' => self::KOD_DPH_SALE, 'Uhrada' => 'převodem',
        ];
        $issued = static fn (string $no, string $sym, string $date, float $base, array $extra = []): array => $customer + [
            'Doklad' => $no, 'VarSymbol' => $sym, 'Vystaveno' => $date, 'DatUcPr' => $date, 'PlnenoDPH' => $date, 'Splatno' => $date,
            'Zaklad_2' => $base, 'DPH_2' => round($base * 0.21, 2), 'CelkemSDPH' => round($base * 1.21, 2), 'Popis' => 'Prodej',
        ] + $extra;
        $append('ROK.002/VFaktury.DAT', self::ISSUED_FIELDS, [
            $issued('FV25020', '2025500', '2025-11-02', 1000.0, ['Uhrazeno' => '2025-11-30', 'UDoklad' => 'ID25020']),
            $issued('FV25021', '2025021', '2025-11-05', 500.0),
            $issued('FV25022', '2025022', '2025-10-25', 300.0),
            $issued('FV25023', '2025523', '2025-11-06', 413.22, ['Uhrazeno' => '2025-11-28', 'UDoklad' => 'ID25021']),
            $issued('FV25024', '2025524', '2025-11-07', 247.93, ['Uhrazeno' => '2025-11-28', 'UDoklad' => 'ID25021']),
            $issued('FV25025', '2025525', '2025-11-09', 1000.0),
            $issued('FV25026', '2025526', '2025-11-10', 2000.0, ['Uhrazeno' => '2025-12-05', 'UDoklad' => 'BP25028']),
            $issued('FV25027', '2025527', '2025-11-11', 500.0, ['Uhrazeno' => '2025-12-05', 'UDoklad' => 'BP25028']),
            $issued('FV25028', '2025610', '2025-11-14', 500.0, ['Uhrazeno' => '2025-11-29']),
            $issued('FV25029', '2025611', '2025-11-15', 300.0),
            $issued('FV25030', '2025620', '2025-12-01', 1000.0, ['Uhrazeno' => '2025-12-10']),
        ]);
        $vendor = static fn (string $ico, string $name): array => [
            'D_ICO' => $ico, 'D_DIC' => 'CZ' . $ico, 'D_Nazev' => $name, 'D_Ulice' => 'Vzorová 1', 'D_Mesto' => 'Praha', 'D_Psc' => '110 00',
            'SazbaDPH1' => 12.0, 'SazbaDPH2' => 21.0, 'KodDPH' => self::KOD_DPH_PURCHASE, 'Uhrada' => 'převodem', 'Druh' => 'N',
        ];
        $received = static fn (array $partner, int $cislo, string $no, string $vendorNo, string $sym, string $date, float $base, array $extra = []): array => $partner + [
            'Cislo' => $cislo, 'Doklad' => $no, 'PrijatDokl' => $vendorNo, 'VarSymbol' => $sym,
            'Vystaveno' => $date, 'DatUcPr' => $date, 'PlnenoDPH' => $date, 'Splatno' => $date, 'Doruceno' => $date,
            'Zaklad_2' => $base, 'DPH_2' => round($base * 0.21, 2), 'CelkemSDPH' => round($base * 1.21, 2), 'Popis' => 'Nákup',
        ] + $extra;
        $alfa = $vendor(self::VENDOR_ICO, 'Dodavatel Alfa s.r.o.');
        $gama = $vendor(self::SECOND_VENDOR_ICO, 'Dodavatel Gama s.r.o.');
        $append('ROK.002/PFaktury.DAT', array_merge(self::PURCHASE_FIELDS, [['Cislo', 'L', 4]]), [
            $received($alfa, 60, 'FP25020', 'DF-2025-300', '2025501', '2025-11-03', 1000.0, ['Uhrazeno' => '2025-11-30', 'UDoklad' => 'ID25020']),
            $received($alfa, 61, 'FP25021', 'DF-2025-301', '2025530', '2025-11-08', 661.16, ['Uhrazeno' => '2025-11-28', 'UDoklad' => 'ID25021']),
            $received($alfa, 62, 'FP25022', 'DF-2025-302', '2025600', '2025-11-12', 1000.0),
            $received($gama, 63, 'FP25023', 'GF-2025-001', '2025600', '2025-11-13', 500.0, ['Uhrazeno' => '2025-11-27']),
        ]);
        $bank = static fn (string $no, int $vydej, string $date, float $amount, string $vs, string $popis, int $vypis): array => [
            'Doklad' => $no, 'Ucet' => 'BU', 'Vydej' => $vydej, 'DatUcPr' => $date, 'DatPlat' => $date, 'Celkem' => $amount,
            'VarSym' => $vs, 'AdNazev' => 'Partner', 'Popis' => $popis, 'Vypis' => $vypis,
        ];
        $append('ROK.002/BankKnih.DAT', self::BANK_FIELDS, [
            $bank('BP25021', 0, '2025-11-10', 605.0, '0000000000', 'FV25021 - Úhrada faktury vydané', 20),
            $bank('BP25025', 0, '2025-11-12', 500.0, '2025525', 'Splátka', 20),
            $bank('BP25026', 0, '2025-11-20', 200.0, '2025525', 'Splátka', 21),
            $bank('BP25027', 0, '2025-11-25', 1620.0, '2025526', 'Úhrada', 21),
            $bank('BP25028', 0, '2025-12-05', 1405.0, '2025526', 'Úhrada', 22),
            $bank('BV25022', 1, '2025-11-27', 605.0, '2025600', 'Úhrada', 21),
            $bank('BP25029', 0, '2025-11-29', 605.0, '2025610', 'Úhrada', 21),
            $bank('BP25030', 0, '2025-12-10', 410.0, '2025620', 'Úhrada', 22),
        ]);
        return $files;
    }

    /**
     * Agenda s čerpáním poskytnutých záloh, které Money vede bez odkazu na zálohu (řádek
     * konečné faktury na 314 se symbolem a IČO faktury). Převádí se od roku 2025, počáteční
     * stav 314 Money vede bez IČO:
     *   - Dodavatel Delta platí měsíční zálohy 1 210 Kč, ke každé daňový doklad k přijaté
     *     platbě (základ 314.200 / 314.100, daň 343 / 314.100): v prosinci 2024 (BV24101,
     *     DD24101 - jen v deníku roku před převodem), BV25101/DD25101 a BV25102/DD25102.
     *     Měsíční faktury „vyúčtované zálohy" čerpají zálohu předchozího měsíce zápornou
     *     částkou MD 314.200 / D 321: FP25101 zálohu z prosince 2024, FP25102 DD25101
     *     a FP25103 DD25102, kterou ale dobropis DP25103 stornuje - DD25102 zůstane otevřená,
     *   - Dodavatel Epsilon platí tři zálohy (DD25111 až DD25113). FP25111 (1 000) čerpá bez
     *     odkazu, pozdější FP25113 (1 000) s odkazem na nejstarší DD25111 v popisu, FP25112
     *     vrací 300 Kč na zálohu (MD 314.200 / D 321) a roční vyúčtování FP25114 (1 300)
     *     čerpá zbytek.
     *
     * @return array<string,string>
     */
    public static function filesWithAdvanceDeductions(): array
    {
        $files = self::files();
        $journalFields = array_merge(self::JOURNAL_FIELDS, [['ParSym', 'C', 20]]);
        $append = static function (string $path, array $fields, array $rows) use (&$files): void {
            $table = strtoupper(pathinfo($path, PATHINFO_FILENAME));
            $existing = iterator_to_array(Ms3Table::fromString($files[$path], $table)->rows(), false);
            $files[$path] = Ms3FixtureWriter::table($fields, array_merge($existing, $rows));
        };
        $n = 300;
        $line = static function (string $zdroj, string $doklad, string $datum, string $popis, string $md, string $d, float $castka, string $sym, string $ico) use (&$n): array {
            $row = ['Cislo' => $n++, 'Zdroj' => $zdroj, 'Doklad' => $doklad, 'Datum' => $datum, 'Popis' => $popis, 'UcMD' => $md, 'UcD' => $d,
                'Castka' => $castka, 'ParSym' => $sym, 'ParICO' => $ico];
            return $zdroj === 'FP' ? $row + ['DatPlnDPH' => $datum] : $row;
        };
        // Záloha zaplacená bankou a daňový doklad k ní (základ přeúčtovaný na 314.200).
        $advance = static fn (string $bank, string $ddkp, string $date, string $sym, string $ico): array => [
            $line('BK', $bank, $date, 'Záloha', '314100', '221001', 1210.0, $sym, $ico),
            $line('FP', $ddkp, $date, 'Doklad k přijaté platbě (záloha ' . $bank . ')', '314200', '314100', 1000.0, $sym, $ico),
            $line('FP', $ddkp, $date, 'DPH 21% - Doklad k přijaté platbě', '343100', '314100', 210.0, $sym, $ico),
        ];
        $final = static fn (string $no, string $date, float $base, string $sym, string $ico, array $advanceLines): array => array_merge([
            $line('FP', $no, $date, 'Spotřeba', '518000', '321000', $base, $sym, $ico),
            $line('FP', $no, $date, 'DPH 21% - spotřeba', '343100', '321000', round($base * 0.21, 2), $sym, $ico),
        ], $advanceLines);
        $delta = self::ADVANCE_VENDOR_ICO;
        $epsilon = self::SECOND_ADVANCE_VENDOR_ICO;
        $chart = [
            ['Ucet' => '314100', 'Nazev' => 'Poskytnuté zálohy - platby'],
            ['Ucet' => '314200', 'Nazev' => 'Poskytnuté zálohy - základ'],
        ];
        $append('ROK.001/UcDenik.DAT', $journalFields, $advance('BV24101', 'DD24101', '2024-12-10', '7700001', $delta));
        $append('ROK.001/UcOsnova.DAT', self::CHART_FIELDS, $chart);
        $append('ROK.002/UcDenik.DAT', $journalFields, array_merge(
            [
                // Počáteční stav Money vede bez IČO.
                ['Cislo' => -201, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav záloh', 'UcMD' => '314200', 'UcD' => '701000', 'Castka' => 1000.0],
                ['Cislo' => -202, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav závazků', 'UcMD' => '701000', 'UcD' => '325000', 'Castka' => 1000.0],
            ],
            $advance('BV25101', 'DD25101', '2025-01-10', '7700001', $delta),
            $final('FP25101', '2025-01-15', 1500.0, '8800001', $delta, [$line('FP', 'FP25101', '2025-01-15', 'vyúčtované zálohy', '314200', '321000', -1000.0, '8800001', $delta)]),
            $advance('BV25102', 'DD25102', '2025-02-10', '7700001', $delta),
            $final('FP25102', '2025-02-15', 1500.0, '8800002', $delta, [$line('FP', 'FP25102', '2025-02-15', 'vyúčtované zálohy', '314200', '321000', -1000.0, '8800002', $delta)]),
            $final('FP25103', '2025-03-15', 1500.0, '8800003', $delta, [$line('FP', 'FP25103', '2025-03-15', 'vyúčtované zálohy', '314200', '321000', -1000.0, '8800003', $delta)]),
            // Dobropis FP25103: Money ho vede zápornými částkami, odpočet zálohy tedy jako debet 314.
            $final('DP25103', '2025-03-20', -1500.0, '8800003', $delta, [$line('FP', 'DP25103', '2025-03-20', 'storno vyúčtovaných záloh', '314200', '321000', 1000.0, '8800003', $delta)]),
            $advance('BV25111', 'DD25111', '2025-04-10', '7700011', $epsilon),
            $advance('BV25112', 'DD25112', '2025-05-10', '7700011', $epsilon),
            $advance('BV25113', 'DD25113', '2025-06-10', '7700011', $epsilon),
            $final('FP25111', '2025-07-01', 3000.0, '8800011', $epsilon, [$line('FP', 'FP25111', '2025-07-01', 'odpočet záloh', '321000', '314200', 1000.0, '8800011', $epsilon)]),
            $final('FP25112', '2025-07-15', 100.0, '8800012', $epsilon, [$line('FP', 'FP25112', '2025-07-15', 'vráceno na zálohu', '314200', '321000', 300.0, '8800012', $epsilon)]),
            $final('FP25113', '2025-08-01', 2000.0, '8800013', $epsilon, [$line('FP', 'FP25113', '2025-08-01', 'Odpočet zálohy DD25111', '321000', '314200', 1000.0, '8800013', $epsilon)]),
            $final('FP25114', '2025-09-01', 2000.0, '8800014', $epsilon, [$line('FP', 'FP25114', '2025-09-01', 'roční vyúčtování', '321000', '314200', 1300.0, '8800014', $epsilon)]),
        ));
        $append('ROK.002/UcOsnova.DAT', self::CHART_FIELDS, $chart);
        $vendor = static fn (string $ico, string $name): array => [
            'D_ICO' => $ico, 'D_DIC' => 'CZ' . $ico, 'D_Nazev' => $name, 'D_Ulice' => 'Vzorová 1', 'D_Mesto' => 'Praha', 'D_Psc' => '110 00',
            'SazbaDPH1' => 12.0, 'SazbaDPH2' => 21.0, 'KodDPH' => self::KOD_DPH_PURCHASE, 'Uhrada' => 'převodem', 'Druh' => 'N',
        ];
        $received = static fn (array $partner, int $cislo, string $no, string $sym, string $date, float $base, float $total, array $extra = []): array => $extra + $partner + [
            'Cislo' => $cislo, 'Doklad' => $no, 'PrijatDokl' => $sym, 'VarSymbol' => $sym,
            'Vystaveno' => $date, 'DatUcPr' => $date, 'PlnenoDPH' => $date, 'Splatno' => $date, 'Doruceno' => $date,
            'Zaklad_2' => $base, 'DPH_2' => round($base * 0.21, 2), 'CelkemSDPH' => $total, 'Popis' => 'Spotřeba',
        ];
        $ddkp = static fn (array $partner, int $cislo, string $no, string $sym, string $date, string $bank): array => ['Druh' => 'D'] + $partner + [
            'Cislo' => $cislo, 'Doklad' => $no, 'PrijatDokl' => $sym . '-' . $no, 'VarSymbol' => $sym,
            'Vystaveno' => $date, 'DatUcPr' => $date, 'PlnenoDPH' => $date, 'Splatno' => $date, 'Doruceno' => $date,
            'Zaklad_2' => 1000.0, 'DPH_2' => 210.0, 'CelkemSDPH' => 1210.0, 'Popis' => 'Doklad k přijaté platbě (záloha ' . $bank . ')',
            'Uhrazeno' => $date, 'UDoklad' => $bank,
        ];
        $d = $vendor($delta, 'Dodavatel Delta a.s.');
        $e = $vendor($epsilon, 'Dodavatel Epsilon a.s.');
        $append('ROK.002/PFaktury.DAT', array_merge(self::PURCHASE_FIELDS, [['Cislo', 'L', 4]]), [
            $ddkp($d, 102, 'DD25101', '7700001', '2025-01-10', 'BV25101'),
            $received($d, 101, 'FP25101', '8800001', '2025-01-15', 1500.0, 815.0),
            $ddkp($d, 104, 'DD25102', '7700001', '2025-02-10', 'BV25102'),
            $received($d, 103, 'FP25102', '8800002', '2025-02-15', 1500.0, 815.0),
            $received($d, 105, 'FP25103', '8800003', '2025-03-15', 1500.0, 815.0),
            $received($d, 106, 'DP25103', '8800003', '2025-03-20', -1500.0, -815.0, ['Dobropis' => 1, 'PrijatDokl' => 'DP-8800003']),
            $ddkp($e, 111, 'DD25111', '7700011', '2025-04-10', 'BV25111'),
            $ddkp($e, 112, 'DD25112', '7700011', '2025-05-10', 'BV25112'),
            $ddkp($e, 113, 'DD25113', '7700011', '2025-06-10', 'BV25113'),
            $received($e, 114, 'FP25111', '8800011', '2025-07-01', 3000.0, 2630.0),
            $received($e, 115, 'FP25112', '8800012', '2025-07-15', 100.0, 421.0, ['Zaklad_0' => 300.0]),
            $received($e, 116, 'FP25113', '8800013', '2025-08-01', 2000.0, 1420.0),
            $received($e, 117, 'FP25114', '8800014', '2025-09-01', 2000.0, 1120.0),
        ]);
        $bank = static fn (string $no, string $date, string $vs, string $name, int $vypis): array => [
            'Doklad' => $no, 'Ucet' => 'BU', 'Vydej' => 1, 'DatUcPr' => $date, 'DatPlat' => $date, 'Celkem' => 1210.0,
            'VarSym' => $vs, 'AdNazev' => $name, 'Popis' => 'Záloha', 'Vypis' => $vypis,
        ];
        $append('ROK.002/BankKnih.DAT', self::BANK_FIELDS, [
            $bank('BV25101', '2025-01-10', '7700001', 'Dodavatel Delta a.s.', 31),
            $bank('BV25102', '2025-02-10', '7700001', 'Dodavatel Delta a.s.', 32),
            $bank('BV25111', '2025-04-10', '7700011', 'Dodavatel Epsilon a.s.', 33),
            $bank('BV25112', '2025-05-10', '7700011', 'Dodavatel Epsilon a.s.', 34),
            $bank('BV25113', '2025-06-10', '7700011', 'Dodavatel Epsilon a.s.', 35),
        ]);
        return $files;
    }

    /**
     * Agenda s čerpáním přijatých záloh (proformy na 324), které Money vede bez vazby na
     * všechny zálohy. Převádí se od roku 2025:
     *   - Odběratel Zeta zaplatil proformy ZV25201 (1 000 Kč, BP25201) a ZV25202 (2 000 Kč,
     *     BP25202). Konečná FV25201 odečítá obě jedním řádkem „MD 311 / D 324" se zápornou
     *     částkou -3 000 pod symbolem první proformy; položka odpočtu odkazuje jen na ZV25201,
     *     takže faktura dostane vazbu na ZV25201 a ZV25202 zůstane bez čerpání,
     *   - Odběratel Éta (bez IČO) zaplatil ZV25211 (500 Kč) a ZV25212 (800 Kč). Konečná
     *     FV25211 odečítá řádkem „MD 324 / D 311" 800 Kč s párovacím symbolem 25212 (číslice
     *     čísla proformy ZV25212), ne nejstarší zálohu.
     *
     * @return array<string,string>
     */
    public static function filesWithIssuedAdvanceDeductions(): array
    {
        $files = self::files();
        $journalFields = array_merge(self::JOURNAL_FIELDS, [['ParSym', 'C', 20]]);
        $append = static function (string $path, array $fields, array $rows) use (&$files): void {
            $table = strtoupper(pathinfo($path, PATHINFO_FILENAME));
            $existing = iterator_to_array(Ms3Table::fromString($files[$path], $table)->rows(), false);
            $files[$path] = Ms3FixtureWriter::table($fields, array_merge($existing, $rows));
        };
        $n = 400;
        $line = static function (string $zdroj, string $doklad, string $datum, string $popis, string $md, string $d, float $castka, string $sym, string $ico) use (&$n): array {
            $row = ['Cislo' => $n++, 'Zdroj' => $zdroj, 'Doklad' => $doklad, 'Datum' => $datum, 'Popis' => $popis, 'UcMD' => $md, 'UcD' => $d,
                'Castka' => $castka, 'ParSym' => $sym, 'ParICO' => $ico];
            return $zdroj === 'FV' ? $row + ['DatPlnDPH' => $datum] : $row;
        };
        $zeta = self::ADVANCE_CUSTOMER_ICO;
        $append('ROK.002/UcDenik.DAT', $journalFields, [
            $line('BK', 'BP25201', '2025-03-05', 'Úhrada zálohové faktury ZV25201', '221001', '324000', 1000.0, '25201', $zeta),
            $line('BK', 'BP25202', '2025-03-06', 'Úhrada zálohové faktury ZV25202', '221001', '324000', 2000.0, '25202', $zeta),
            $line('FV', 'FV25201', '2025-04-10', 'Montáž', '311000', '602000', 4000.0, '2025210', $zeta),
            $line('FV', 'FV25201', '2025-04-10', 'Montáž - DPH', '311000', '343200', 840.0, '2025210', $zeta),
            $line('FV', 'FV25201', '2025-04-10', 'Odpočet záloh', '311000', '324000', -3000.0, '25201', $zeta),
            $line('BK', 'BP25211', '2025-05-02', 'Úhrada zálohové faktury', '221001', '324000', 500.0, '25211', ''),
            $line('BK', 'BP25212', '2025-05-03', 'Úhrada zálohové faktury', '221001', '324000', 800.0, '25212', ''),
            $line('FV', 'FV25211', '2025-06-01', 'Servis', '311000', '602000', 1000.0, '2025221', ''),
            $line('FV', 'FV25211', '2025-06-01', 'Servis - DPH', '311000', '343200', 210.0, '2025221', ''),
            $line('FV', 'FV25211', '2025-06-01', 'Odpočet zálohy', '324000', '311000', 800.0, '25212', ''),
        ]);
        $append('ROK.002/UcOsnova.DAT', self::CHART_FIELDS, [['Ucet' => '324000', 'Nazev' => 'Přijaté zálohy']]);
        $customer = static fn (string $ico, string $name): array => [
            'O_ICO' => $ico, 'O_DIC' => $ico !== '' ? 'CZ' . $ico : '', 'O_Nazev' => $name,
            'O_Ulice' => 'Ukázková 9', 'O_Mesto' => 'Olomouc', 'O_Psc' => '779 00',
            'SazbaDPH1' => 12.0, 'SazbaDPH2' => 21.0, 'KodDPH' => self::KOD_DPH_SALE, 'Uhrada' => 'převodem',
        ];
        $proforma = static fn (array $partner, int $cislo, string $no, string $date, float $amount, string $bank): array => $partner + [
            'Cislo' => $cislo, 'Druh' => 'Z', 'Doklad' => $no, 'VarSymbol' => substr($no, 2),
            'Vystaveno' => $date, 'DatUcPr' => $date, 'PlnenoDPH' => $date, 'Splatno' => $date,
            'Zaklad_0' => $amount, 'CelkemSDPH' => $amount, 'Uhrazeno' => $date, 'UDoklad' => $bank, 'Popis' => 'Záloha',
        ];
        $final = static fn (array $partner, int $cislo, string $no, string $sym, string $date, float $base, string $popis): array => $partner + [
            'Cislo' => $cislo, 'Druh' => 'N', 'Doklad' => $no, 'VarSymbol' => $sym,
            'Vystaveno' => $date, 'DatUcPr' => $date, 'PlnenoDPH' => $date, 'Splatno' => $date,
            'Zaklad_2' => $base, 'DPH_2' => round($base * 0.21, 2), 'CelkemSDPH' => round($base * 1.21, 2), 'Popis' => $popis,
        ];
        $z = $customer($zeta, 'Odběratel Zeta s.r.o.');
        $e = $customer('', 'Odběratelka Éta');
        $append('ROK.002/VFaktury.DAT', array_merge(self::ISSUED_FIELDS, [['Cislo', 'L', 4]]), [
            $proforma($z, 201, 'ZV25201', '2025-03-01', 1000.0, 'BP25201'),
            $proforma($z, 202, 'ZV25202', '2025-03-02', 2000.0, 'BP25202'),
            $final($z, 203, 'FV25201', '2025210', '2025-04-10', 4000.0, 'Montáž'),
            $proforma($e, 211, 'ZV25211', '2025-05-01', 500.0, 'BP25211'),
            $proforma($e, 212, 'ZV25212', '2025-05-02', 800.0, 'BP25212'),
            $final($e, 213, 'FV25211', '2025221', '2025-06-01', 1000.0, 'Servis'),
        ]);
        $files['ROK.002/VFaktPol.DAT'] = Ms3FixtureWriter::table([
            ['Cislo', 'L', 4], ['Popis', 'C', 60], ['Cena', 'E', 10], ['PocetMJ', 'E', 10], ['Zaloha', 'B', 1], ['CisloZaFak', 'L', 4], ['RokZaFak', 'C', 3],
        ], [
            ['Cislo' => 203, 'Popis' => 'Montáž', 'Cena' => 4000.0, 'PocetMJ' => 1.0],
            ['Cislo' => 203, 'Popis' => 'Odpočet zálohy ZV25201', 'Cena' => -3000.0, 'PocetMJ' => 1.0, 'Zaloha' => 1, 'CisloZaFak' => 201],
        ]);
        $bank = static fn (string $no, string $date, float $amount, string $vs, string $name, int $vypis): array => [
            'Doklad' => $no, 'Ucet' => 'BU', 'Vydej' => 0, 'DatUcPr' => $date, 'DatPlat' => $date, 'Celkem' => $amount,
            'VarSym' => $vs, 'AdNazev' => $name, 'Popis' => 'Úhrada zálohy', 'Vypis' => $vypis,
        ];
        $append('ROK.002/BankKnih.DAT', self::BANK_FIELDS, [
            $bank('BP25201', '2025-03-05', 1000.0, '25201', 'Odběratel Zeta s.r.o.', 41),
            $bank('BP25202', '2025-03-06', 2000.0, '25202', 'Odběratel Zeta s.r.o.', 42),
            $bank('BP25211', '2025-05-02', 500.0, '25211', 'Odběratelka Éta', 43),
            $bank('BP25212', '2025-05-03', 800.0, '25212', 'Odběratelka Éta', 44),
        ]);
        return $files;
    }

    /**
     * Agenda, ve které jeden pokladní doklad (PV25001, 800 Kč) hradí dvě přijaté faktury
     * (FP25010 a FP25011 po 400 Kč). Faktury Money nezaúčtovalo, jde jen o vazbu úhrady.
     *
     * @return array<string,string>
     */
    public static function filesWithCashPayingTwoInvoices(): array
    {
        $files = self::files();
        $table = strtoupper(pathinfo('ROK.002/PFaktury.DAT', PATHINFO_FILENAME));
        $existing = iterator_to_array(Ms3Table::fromString($files['ROK.002/PFaktury.DAT'], $table)->rows(), false);
        $rows = [];
        foreach (['FP25010' => 'DF-2025-110', 'FP25011' => 'DF-2025-111'] as $doc => $vendorDoc) {
            $rows[] = [
                'D_ICO' => self::VENDOR_ICO, 'D_DIC' => 'CZ' . self::VENDOR_ICO, 'D_Nazev' => 'Dodavatel Alfa s.r.o.',
                'D_Ulice' => 'Vzorová 1', 'D_Mesto' => 'Praha', 'D_Psc' => '110 00',
                'SazbaDPH1' => 12.0, 'SazbaDPH2' => 21.0, 'Druh' => 'N', 'KodDPH' => self::KOD_DPH_PURCHASE, 'Uhrada' => 'hotově',
                'Doklad' => $doc, 'PrijatDokl' => $vendorDoc, 'VarSymbol' => substr($vendorDoc, -3) . '2025',
                'Vystaveno' => '2025-01-30', 'DatUcPr' => '2025-01-30', 'PlnenoDPH' => '2025-01-30', 'Splatno' => '2025-02-10', 'Doruceno' => '2025-01-30',
                'Zaklad_2' => 330.58, 'DPH_2' => 69.42, 'CelkemSDPH' => 400.0,
                'Uhrazeno' => '2025-02-01', 'UDoklad' => 'PV25001', 'Popis' => 'Hotovostní nákup',
            ];
        }
        $files['ROK.002/PFaktury.DAT'] = Ms3FixtureWriter::table(self::PURCHASE_FIELDS, array_merge($existing, $rows));
        return $files;
    }

    /**
     * Agenda, ve které je FP25002 pořízením majetku (`19Ř40,41M`) a pokladní PV25002
     * pořízením majetku s kráceným odpočtem (`19Ř40,41 MK`): v přiznání patří i do ř. 47.
     *
     * @return array<string,string>
     */
    public static function filesWithFixedAssetCodes(): array
    {
        $files = self::files();
        foreach (['ROK.002/PFaktury.DAT' => [self::PURCHASE_FIELDS, 'FP25002', 'KodDPH', self::KOD_DPH_PURCHASE . 'M'],
            'ROK.002/PoklKnih.DAT' => [self::CASH_FIELDS, 'PV25002', 'Cleneni', self::KOD_DPH_PURCHASE . ' MK']] as $path => [$fields, $doc, $column, $code]) {
            $rows = iterator_to_array(Ms3Table::fromString($files[$path], strtoupper(pathinfo($path, PATHINFO_FILENAME)))->rows(), false);
            foreach ($rows as &$row) {
                if (trim((string) ($row['Doklad'] ?? '')) === $doc) {
                    $row[$column] = $code;
                }
            }
            unset($row);
            $files[$path] = Ms3FixtureWriter::table($fields, $rows);
        }
        return $files;
    }

    /**
     * Agenda s vydanou FV25002 v tuzemském přenesení daňové povinnosti u stavebních prací
     * (`19Ř25_S`, 1 000 Kč bez daně) a jejím zápisem v deníku.
     *
     * @return array<string,string>
     */
    public static function filesWithDomesticReverseSale(): array
    {
        $files = self::files();
        $append = static function (string $path, array $fields, array $rows) use (&$files): void {
            $table = strtoupper(pathinfo($path, PATHINFO_FILENAME));
            $existing = iterator_to_array(Ms3Table::fromString($files[$path], $table)->rows(), false);
            $files[$path] = Ms3FixtureWriter::table($fields, array_merge($existing, $rows));
        };
        $append('ROK.002/UcDenik.DAT', self::JOURNAL_FIELDS, [
            ['Cislo' => 50, 'Zdroj' => 'FV', 'Doklad' => 'FV25002', 'Datum' => '2025-08-04', 'DatPlnDPH' => '2025-08-04', 'Popis' => 'Stavební práce', 'UcMD' => '311000', 'UcD' => '602000', 'Castka' => 1000.0],
        ]);
        $append('ROK.002/VFaktury.DAT', self::ISSUED_FIELDS, [[
            'O_ICO' => self::CUSTOMER_ICO, 'O_DIC' => 'CZ' . self::CUSTOMER_ICO, 'O_Nazev' => 'Odběratel Beta a.s.',
            'O_Ulice' => 'Ukázková 7', 'O_Mesto' => 'Ostrava', 'O_Psc' => '702 00',
            'SazbaDPH1' => 12.0, 'SazbaDPH2' => 21.0, 'Druh' => 'N', 'KodDPH' => '19Ř25_S', 'Uhrada' => 'převodem',
            'Doklad' => 'FV25002', 'VarSymbol' => '2025003',
            'Vystaveno' => '2025-08-04', 'DatUcPr' => '2025-08-04', 'PlnenoDPH' => '2025-08-04', 'Splatno' => '2025-08-18',
            'Zaklad_2' => 1000.0, 'DPH_2' => 0.0, 'CelkemSDPH' => 1000.0, 'Popis' => 'Stavební práce',
        ]]);
        return $files;
    }

    /**
     * Agenda, ve které licence z EU FP25005 zní na 1 030 Kč (kurz faktury), ale samovyměření
     * ICH25001 má základ 1 000 Kč (kurz ke dni plnění). Rozdíl 30 Kč je jen kurzový.
     *
     * @return array<string,string>
     */
    public static function filesWithSelfAssessmentRateDifference(): array
    {
        $files = self::files();
        $patch = static function (string $path, array $fields, callable $change) use (&$files): void {
            $table = strtoupper(pathinfo($path, PATHINFO_FILENAME));
            $rows = array_map($change, iterator_to_array(Ms3Table::fromString($files[$path], $table)->rows(), false));
            $files[$path] = Ms3FixtureWriter::table($fields, $rows);
        };
        $patch('ROK.002/PFaktury.DAT', self::PURCHASE_FIELDS, static fn (array $r): array => trim((string) $r['Doklad']) === 'FP25005'
            ? ['Zaklad_0' => 1030.0, 'CelkemSDPH' => 1030.0] + $r : $r);
        $patch('ROK.002/UcDenik.DAT', self::JOURNAL_FIELDS, static fn (array $r): array => trim((string) $r['Doklad']) === 'FP25005'
            ? ['Castka' => 1030.0] + $r : $r);
        return $files;
    }

    /**
     * Agenda, ve které interní doklad se samovyměřením k FP25005 odkazuje na fakturu
     * jinak než „RCH k FP25005": účetní píše malá písmena s mezerou, jen číslice, nebo
     * číslo v popisu vůbec nemá a doklad nese jen číslo dokladu dodavatele (`PrijatDokl`).
     *
     * @return array<string,string>
     */
    public static function filesWithSelfAssessmentReference(string $description, string $vendorDocument = ''): array
    {
        $files = self::files();
        $files['ROK.002/IntDokl.DAT'] = Ms3FixtureWriter::table([
            ['Cislo', 'L', 4], ['Doklad', 'C', 10], ['Popis', 'C', 50], ['PrijatDokl', 'C', 20], ['DatUcPr', 'D', 2], ['DatUplDPH', 'D', 2],
            ['Cleneni', 'C', 12], ['ZaklZS', 'E', 10], ['DPHZS', 'E', 10],
        ], [
            ['Cislo' => 1, 'Doklad' => 'ICH25001', 'Popis' => $description, 'PrijatDokl' => $vendorDocument, 'DatUcPr' => '2025-07-10', 'DatUplDPH' => '2025-07-10',
                'Cleneni' => '19Ř00P', 'ZaklZS' => 2000.0, 'DPHZS' => 420.0],
        ]);
        return $files;
    }

    /**
     * Agenda, ve které FP25005 nese příznak přenesené povinnosti (`RevCH`), ale interní
     * doklad se samovyměřením k ní v záloze není.
     *
     * @return array<string,string>
     */
    public static function filesWithReverseChargeWithoutSelfAssessment(): array
    {
        $files = self::files();
        $rows = iterator_to_array(Ms3Table::fromString($files['ROK.002/PFaktury.DAT'], 'PFAKTURY')->rows(), false);
        foreach ($rows as &$r) {
            $r['RevCH'] = trim((string) $r['Doklad']) === 'FP25005' ? 1 : 0;
        }
        unset($r);
        $files['ROK.002/PFaktury.DAT'] = Ms3FixtureWriter::table(array_merge(self::PURCHASE_FIELDS, [['RevCH', 'B', 1]]), $rows);
        unset($files['ROK.002/IntDokl.DAT'], $files['ROK.002/PolUcDID.DAT']);
        return $files;
    }

    /**
     * Agenda s osnovou, která nese daňovou uznatelnost (`Typ` 1 = daňový, 0 = nedaňový):
     * bankovní poplatky 568000 jsou v Money vedené jako nedaňové, ostatní náklady daňové.
     *
     * @return array<string,string>
     */
    public static function filesWithChartDeductibility(): array
    {
        $files = self::files();
        foreach (['ROK.001', 'ROK.002'] as $dir) {
            $rows = iterator_to_array(Ms3Table::fromString($files[$dir . '/UcOsnova.DAT'], 'UCOSNOVA')->rows(), false);
            foreach ($rows as &$r) {
                $r['Typ'] = trim((string) $r['Ucet']) === '568000' ? 0 : 1;
            }
            unset($r);
            $files[$dir . '/UcOsnova.DAT'] = Ms3FixtureWriter::table(array_merge(self::CHART_FIELDS, [['Typ', 'L', 4]]), $rows);
        }
        return $files;
    }

    /**
     * Agenda, ve které přijatá FP25002 nese sazbu DPH 17 %, kterou číselník sazeb nezná.
     *
     * @return array<string,string>
     */
    public static function filesWithUnknownVatRate(): array
    {
        $files = self::files();
        $rows = array_map(
            static fn (array $r): array => trim((string) $r['Doklad']) === 'FP25002' ? ['SazbaDPH2' => 17.0] + $r : $r,
            iterator_to_array(Ms3Table::fromString($files['ROK.002/PFaktury.DAT'], 'PFAKTURY')->rows(), false),
        );
        $files['ROK.002/PFaktury.DAT'] = Ms3FixtureWriter::table(self::PURCHASE_FIELDS, $rows);
        return $files;
    }

    /**
     * Agenda vedená v Money jako daňová evidence: rok má místo účetního deníku peněžní deník
     * (`UcPenDen`, dva živé a jeden smazaný řádek) a `UcDenik` je prázdná tabulka.
     * `$doubleEntryFrom2025` = firma v roce 2025 přešla na podvojné účetnictví, deník 2025 zůstává.
     *
     * @return array<string,string>
     */
    public static function filesWithTaxRecords(bool $doubleEntryFrom2025 = false): array
    {
        $files = self::files();
        $fields = [['Cislo', 'L', 4], ['Datum', 'D', 2], ['Doklad', 'C', 10], ['Popis', 'C', 50], ['Castka', 'E', 10], ['Del', 'B', 1]];
        foreach (['ROK.001' => 2024, 'ROK.002' => 2025] as $dir => $year) {
            if ($doubleEntryFrom2025 && $year === 2025) {
                continue;
            }
            $files[$dir . '/UcDenik.DAT'] = Ms3FixtureWriter::table(self::JOURNAL_FIELDS, []);
            $files[$dir . '/UcPenDen.DAT'] = Ms3FixtureWriter::table($fields, [
                ['Cislo' => 1, 'Datum' => "{$year}-02-10", 'Doklad' => 'BP001', 'Popis' => 'Tržba za služby', 'Castka' => 12100.0],
                ['Cislo' => 2, 'Datum' => "{$year}-04-01", 'Doklad' => 'PV001', 'Popis' => 'Nákup materiálu', 'Castka' => 1500.0],
                ['Cislo' => 3, 'Datum' => "{$year}-04-02", 'Doklad' => 'PV002', 'Popis' => 'Smazaný pohyb', 'Castka' => 999.0, 'Del' => 1],
            ]);
        }
        return $files;
    }

    /**
     * Agenda se mzdami v roce 2025, jak je nese mzdový modul Money: mzdové doklady
     * (`KnihZav`, `IntDokl` s `MZTyp`, `MZRok`, `MZMesic`, `MZDI_Zauct`) za leden až březen
     * a jejich řádky v deníku, měsíční úhrn daně (`VYUCDPFO`) a zbytek čitelných mezd
     * osob (`MZDY`) končící 10/2020.
     *
     * Měsíc: hrubé mzdy 30 000 Kč na dvě střediska (druh 3), sociální pojištění 2 130 +
     * 7 440 (druh 7, analytika 336200), zdravotní 1 350 + 2 700 (druh 10, 336100), záloha
     * na daň 3 810 (druh 5), závazek čisté mzdy 331/331 na témž účtu (druh 1). V únoru navíc
     * exekuce 1 000 Kč (druh 14), v lednu zákonné pojištění odpovědnosti (druh 15, jen do
     * přehledu). Doklady bez druhu (starší způsob): odměna 5 000 Kč 521/331 a doplatek
     * pojistného 331/336100 - analytika 336 se pozná podle dokladů s druhem, ne podle
     * názvu (názvy analytik 336 jsou záměrně neutrální). Odvod zálohové daně za březen
     * ve vyúčtování Money nesedí na doklady (3 000 Kč).
     *
     * @return array<string,string>
     */
    public static function filesWithPayroll(): array
    {
        $files = self::files();
        $append = static function (string $path, array $fields, array $rows) use (&$files): void {
            $table = strtoupper(pathinfo($path, PATHINFO_FILENAME));
            $existing = iterator_to_array(Ms3Table::fromString($files[$path], $table)->rows(), false);
            $files[$path] = Ms3FixtureWriter::table($fields, array_merge($existing, $rows));
        };
        $append('ROK.002/UcOsnova.DAT', self::CHART_FIELDS, [
            ['Ucet' => '331000', 'Nazev' => 'Zaměstnanci'],
            ['Ucet' => '336100', 'Nazev' => 'Zúčtování s institucemi 1'],
            ['Ucet' => '336200', 'Nazev' => 'Zúčtování s institucemi 2'],
            ['Ucet' => '342100', 'Nazev' => 'Záloha na daň ze závislé činnosti'],
            ['Ucet' => '379000', 'Nazev' => 'Jiné závazky'],
            ['Ucet' => '379100', 'Nazev' => 'Pojištění odpovědnosti'],
            ['Ucet' => '521000', 'Nazev' => 'Mzdové náklady'],
            ['Ucet' => '524100', 'Nazev' => 'Zákonné sociální pojištění'],
            ['Ucet' => '524200', 'Nazev' => 'Zákonné zdravotní pojištění'],
            ['Ucet' => '548000', 'Nazev' => 'Ostatní provozní náklady'],
        ]);
        $journal = [];
        $liabilities = [];
        $internal = [];
        $no = 100;
        foreach ([1, 2, 3] as $month) {
            $date = (new \DateTimeImmutable(sprintf('2025-%02d-01', $month)))->format('Y-m-t');
            $doc = static fn (string $prefix, int $n): string => sprintf('%s25%02d%d', $prefix, $month, $n);
            $mz = ['MZTyp' => 1, 'MZRok' => 2025, 'MZMesic' => $month];
            $internal[] = ['Cislo' => 10 + $month, 'Doklad' => $doc('IDM', 1), 'Popis' => 'Předpis mezd', 'DatUcPr' => $date, 'MZDI_Zauct' => 3] + $mz;
            $journal[] = ['Cislo' => ++$no, 'Zdroj' => 'ID', 'Doklad' => $doc('IDM', 1), 'Datum' => $date, 'Popis' => 'Hrubé mzdy', 'UcMD' => '521000', 'UcD' => '331000', 'Castka' => 20000.0, 'Stred' => 'REZIE'];
            $journal[] = ['Cislo' => ++$no, 'Zdroj' => 'ID', 'Doklad' => $doc('IDM', 1), 'Datum' => $date, 'Popis' => 'Hrubé mzdy', 'UcMD' => '521000', 'UcD' => '331000', 'Castka' => 10000.0, 'Stred' => 'VYROBA'];
            $net = 22710.0;
            $entries = [
                [7, 'Odvod ČSSZ', [['331000', '336200', 2130.0], ['524100', '336200', 7440.0]]],
                [10, 'Odvod ZP', [['331000', '336100', 1350.0], ['524200', '336100', 2700.0]]],
                [5, 'Odvod daně', [['331000', '342100', 3810.0]]],
            ];
            if ($month === 2) {
                $entries[] = [14, 'Exekuce', [['331000', '379000', 1000.0]]];
                $net -= 1000.0;
            }
            if ($month === 1) {
                $entries[] = [15, 'Pojištění odpovědnosti', [['548000', '379100', 150.0]]];
            }
            // Závazek čisté mzdy vede Money na témž účtu (zaměstnance rozliší párový symbol).
            $entries[] = [1, 'Čistá mzda', [['331000', '331000', $net]]];
            foreach ($entries as $i => [$kind, $text, $lines]) {
                $number = $doc('ZA', $i + 1);
                $liabilities[] = ['Cislo' => 100 * $month + $i, 'Doklad' => $number, 'Popis' => $text, 'MZDI_Zauct' => $kind, 'AdCislo' => 0] + $mz;
                foreach ($lines as [$md, $d, $amount]) {
                    $journal[] = ['Cislo' => ++$no, 'Zdroj' => 'KZ', 'Doklad' => $number, 'Datum' => $date, 'Popis' => $text, 'UcMD' => $md, 'UcD' => $d, 'Castka' => $amount];
                }
            }
        }
        // Doklady bez druhu i bez příznaku mzdového dokladu (starší způsob účtování mezd).
        $internal[] = ['Cislo' => 90, 'Doklad' => 'IDM25090', 'Popis' => 'Odměna', 'DatUcPr' => '2025-01-20'];
        $journal[] = ['Cislo' => ++$no, 'Zdroj' => 'ID', 'Doklad' => 'IDM25090', 'Datum' => '2025-01-20', 'Popis' => 'Odměna', 'UcMD' => '521000', 'UcD' => '331000', 'Castka' => 5000.0];
        $liabilities[] = ['Cislo' => 90, 'Doklad' => 'ZA25090', 'Popis' => 'Doplatek pojistného', 'MZTyp' => 0];
        $journal[] = ['Cislo' => ++$no, 'Zdroj' => 'KZ', 'Doklad' => 'ZA25090', 'Datum' => '2025-01-20', 'Popis' => 'Doplatek pojistného', 'UcMD' => '331000', 'UcD' => '336100', 'Castka' => 100.0];
        $append('ROK.002/UcDenik.DAT', self::JOURNAL_FIELDS, $journal);

        $mzFields = [['MZTyp', 'V', 1], ['MZRok', 'W', 2], ['MZMesic', 'V', 1], ['MZDI_Zauct', 'V', 1]];
        $files['ROK.002/KnihZav.DAT'] = Ms3FixtureWriter::table(
            array_merge([['Cislo', 'L', 4], ['Doklad', 'C', 10], ['Popis', 'C', 50], ['AdCislo', 'L', 4]], $mzFields),
            $liabilities,
        );
        $append('ROK.002/IntDokl.DAT', array_merge([
            ['Cislo', 'L', 4], ['Doklad', 'C', 10], ['Popis', 'C', 50], ['DatUcPr', 'D', 2], ['DatUplDPH', 'D', 2],
            ['Cleneni', 'C', 12], ['ZaklZS', 'E', 10], ['DPHZS', 'E', 10],
        ], $mzFields), $internal);
        // Únor: sražené zálohy 4 810 Kč, z toho 1 000 Kč přeplatek z ročního zúčtování, odvod 3 810 Kč.
        $files['VYUCDPFO.DAT'] = Ms3FixtureWriter::table([['Mesic', 'V', 1], ['Rok', 'W', 2], ['Preplatek', 'E', 10], ['DPFO', 'E', 10], ['Odvod', 'E', 10]], [
            ['Mesic' => 255, 'Rok' => 65535],
            ['Mesic' => 1, 'Rok' => 2025, 'DPFO' => 3810.0, 'Odvod' => 3810.0],
            ['Mesic' => 2, 'Rok' => 2025, 'Preplatek' => 1000.0, 'DPFO' => 4810.0, 'Odvod' => 3810.0],
            ['Mesic' => 3, 'Rok' => 2025, 'DPFO' => 3000.0, 'Odvod' => 3000.0],
        ]);
        $files['MZDY.DAT'] = Ms3FixtureWriter::table([['OsCislo', 'C', 10], ['Rok', 'W', 2], ['Mesic', 'V', 1], ['HRUBA', 'E', 10]], [
            ['OsCislo' => '1', 'Rok' => 2020, 'Mesic' => 9, 'HRUBA' => 25000.0],
            ['OsCislo' => '1', 'Rok' => 2020, 'Mesic' => 10, 'HRUBA' => 25000.0],
        ]);
        return $files;
    }

    /**
     * Záloha agendy z daných souborů (varianty agendy pro jednotlivé testy).
     *
     * @param array<string,string> $files
     */
    public static function writeLzFiles(string $path, array $files): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Nelze vytvořit {$path}");
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
    }

    /** Agenda rozbalená do adresáře (jak ji vidí převod po rozbalení zálohy). */
    public static function writeDir(string $dir): void
    {
        foreach (self::files() as $path => $content) {
            $full = $dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0755, true);
            }
            file_put_contents($full, $content);
        }
    }

    /**
     * Záloha agendy (`.lz` = ZIP), jak ji vyrobí Money.
     *
     * @param array<string,string> $extra další položky ZIPu (testy přibalují podvržené cesty)
     */
    public static function writeLz(string $path, array $extra = []): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Nelze vytvořit {$path}");
        }
        foreach (self::files() + $extra as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
    }

    /**
     * Obratová předvaha 2024 tak, jak ji Money vyexportuje do CSV — spočtená ručně.
     * Sloupce: účet, název, PS MD, PS D, obrat MD, obrat D, KS MD, KS D.
     */
    public static function trialBalanceCsv2024(): string
    {
        $rows = [
            ['211', 'Pokladna', '10 000,00', '0,00', '0,00', '1 500,00', '8 500,00', '0,00'],
            ['221', 'Bankovní účty', '50 000,00', '0,00', '24 250,00', '12 100,00', '62 150,00', '0,00'],
            ['311', 'Odběratelé', '0,00', '0,00', '24 200,00', '24 200,00', '0,00', '0,00'],
            ['321', 'Dodavatelé', '0,00', '0,00', '12 100,00', '12 100,00', '0,00', '0,00'],
            ['325', 'Ostatní závazky', '0,00', '0,00', '0,00', '300,00', '0,00', '300,00'],
            ['343', 'Daň z přidané hodnoty', '0,00', '0,00', '2 100,00', '4 200,00', '0,00', '2 100,00'],
            ['411', 'Základní kapitál', '0,00', '60 000,00', '0,00', '0,00', '0,00', '60 000,00'],
            ['501', 'Spotřeba materiálu', '0,00', '0,00', '1 500,00', '0,00', '1 500,00', '0,00'],
            ['518', 'Ostatní služby', '0,00', '0,00', '10 300,00', '0,00', '10 300,00', '0,00'],
            ['568', 'Ostatní finanční náklady', '0,00', '0,00', '0,00', '50,00', '0,00', '50,00'],
            ['602', 'Tržby z prodeje služeb', '0,00', '0,00', '0,00', '20 000,00', '0,00', '20 000,00'],
            ['701', 'Počáteční účet rozvažný', '60 000,00', '60 000,00', '0,00', '0,00', '0,00', '0,00'],
        ];
        $out = "Účet;Název;PS MD;PS D;Obrat MD;Obrat D;KS MD;KS D\r\n";
        foreach ($rows as $r) {
            $out .= implode(';', $r) . "\r\n";
        }
        $out .= ";Celkem;120 000,00;120 000,00;74 450,00;74 450,00;82 450,00;82 450,00\r\n";
        return (string) iconv('UTF-8', 'CP1250', $out);
    }
}
