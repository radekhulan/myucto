<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\MoneyS3;

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
 *     začátku) a úhrada kartou (`Uhrada`).
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
    public const VERSION = '26.600';

    private const JOURNAL_FIELDS = [
        ['Cislo', 'L', 4], ['Zdroj', 'C', 2], ['Doklad', 'C', 10], ['Datum', 'D', 2], ['DatPlnDPH', 'D', 2],
        ['Popis', 'C', 50], ['UcMD', 'C', 6], ['UcD', 'C', 6], ['Castka', 'E', 10], ['Zakazka', 'C', 10], ['Del', 'B', 1],
    ];
    private const CHART_FIELDS = [['Ucet', 'C', 6], ['Nazev', 'C', 50]];
    private const PURCHASE_FIELDS = [
        ['Doklad', 'C', 10], ['Storno', 'B', 1], ['PrijatDokl', 'C', 20], ['VarSymbol', 'C', 10], ['D_ICO', 'C', 12], ['D_DIC', 'C', 14],
        ['D_Nazev', 'C', 60], ['D_Ulice', 'C', 40], ['D_Mesto', 'C', 40], ['D_Psc', 'C', 10],
        ['Vystaveno', 'D', 2], ['DatUcPr', 'D', 2], ['PlnenoDPH', 'D', 2], ['Splatno', 'D', 2], ['Doruceno', 'D', 2],
        ['KodDPH', 'C', 12], ['Druh', 'C', 1], ['Dobropis', 'B', 1], ['Uhrada', 'C', 20],
        ['Zaklad_0', 'E', 10], ['Zaklad_1', 'E', 10], ['Zaklad_2', 'E', 10], ['SazbaDPH1', 'E', 10], ['SazbaDPH2', 'E', 10],
        ['DPH_1', 'E', 10], ['DPH_2', 'E', 10],
        ['CelkemSDPH', 'E', 10], ['Uhrazeno', 'D', 2], ['UDoklad', 'C', 10], ['Popis', 'C', 50], ['BarCode', 'C', 20],
        ['Mena', 'C', 3], ['PocetJedn', 'L', 4], ['Kurs', 'E', 10], ['Neuctovat', 'B', 1],
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
    ];
    private const CASH_FIELDS = [
        ['Doklad', 'C', 10], ['Pokl', 'C', 6], ['Vydej', 'B', 1], ['PrKont', 'C', 6], ['DatVyst', 'D', 2], ['DatUplDPH', 'D', 2],
        ['DatUcPr', 'D', 2], ['AdNazev', 'C', 60], ['AdICO', 'C', 12], ['AdDIC', 'C', 14], ['Popis', 'C', 50], ['Celkem', 'E', 10],
        ['BarCode', 'C', 20],
    ];
    private const BANK_FIELDS = [
        ['Doklad', 'C', 10], ['Ucet', 'C', 6], ['Vydej', 'B', 1], ['DatUcPr', 'D', 2], ['DatPlat', 'D', 2], ['Celkem', 'E', 10],
        ['VarSym', 'C', 10], ['AdNazev', 'C', 60], ['Popis', 'C', 50],
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
            ], [
                ['Cislo' => 1, 'Nazev' => self::NAME, 'ICO' => self::ICO, 'DIC' => 'CZ' . self::ICO, 'Ulice' => 'Účetní 12', 'Misto' => 'Brno', 'PSC' => '602 00'],
                ['Cislo' => 2, 'Nazev' => 'Dodavatel Alfa s.r.o.', 'ICO' => self::VENDOR_ICO, 'DIC' => 'CZ' . self::VENDOR_ICO,
                    'Ulice' => 'Vzorová 1', 'Misto' => 'Praha', 'PSC' => '110 00', 'EMail' => 'fakturace@example.invalid'],
                ['Cislo' => 3, 'Nazev' => 'Odběratel Beta a.s.', 'ICO' => self::CUSTOMER_ICO, 'DIC' => 'CZ' . self::CUSTOMER_ICO,
                    'Ulice' => 'Ukázková 7', 'Misto' => 'Ostrava', 'PSC' => '702 00'],
                ['Cislo' => 4, 'Nazev' => 'Smazaný partner', 'ICO' => '', 'Del' => 1],
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
                ['Cislo' => 1, 'Zdroj' => 'FP', 'Doklad' => 'FP24001', 'Datum' => '2024-02-10', 'DatPlnDPH' => '2024-02-10', 'Popis' => 'Účetní služby', 'UcMD' => '518000', 'UcD' => '321000', 'Castka' => 10000.0, 'Zakazka' => 'ZAK01'],
                ['Cislo' => 2, 'Zdroj' => 'FP', 'Doklad' => 'FP24001', 'Datum' => '2024-02-10', 'DatPlnDPH' => '2024-02-10', 'Popis' => 'Účetní služby', 'UcMD' => '343100', 'UcD' => '321000', 'Castka' => 2100.0],
                ['Cislo' => 3, 'Zdroj' => 'FP', 'Doklad' => 'FP24001', 'Datum' => '2024-02-10', 'Popis' => 'Nulový řádek', 'UcMD' => '518000', 'UcD' => '321000', 'Castka' => 0.0],
                ['Cislo' => 4, 'Zdroj' => 'FP', 'Doklad' => 'FP24099', 'Datum' => '2024-02-11', 'Popis' => 'Smazaný doklad', 'UcMD' => '518000', 'UcD' => '321000', 'Castka' => 999.0, 'Del' => 1],
                ['Cislo' => 5, 'Zdroj' => 'BK', 'Doklad' => 'BV24001', 'Datum' => '2024-02-20', 'Popis' => 'Úhrada FP24001', 'UcMD' => '321000', 'UcD' => '221001', 'Castka' => 12100.0],
                ['Cislo' => 6, 'Zdroj' => 'FV', 'Doklad' => 'FV24001', 'Datum' => '2024-03-05', 'DatPlnDPH' => '2024-03-05', 'Popis' => 'Poradenství', 'UcMD' => '311000', 'UcD' => '602000', 'Castka' => 20000.0],
                ['Cislo' => 7, 'Zdroj' => 'FV', 'Doklad' => 'FV24001', 'Datum' => '2024-03-05', 'DatPlnDPH' => '2024-03-05', 'Popis' => 'Poradenství', 'UcMD' => '311000', 'UcD' => '343200', 'Castka' => 4200.0],
                ['Cislo' => 8, 'Zdroj' => 'BK', 'Doklad' => 'BP24002', 'Datum' => '2024-03-20', 'Popis' => 'Úhrada FV24001', 'UcMD' => '221001', 'UcD' => '311000', 'Castka' => 24200.0],
                ['Cislo' => 9, 'Zdroj' => 'PK', 'Doklad' => 'PV24001', 'Datum' => '2024-04-01', 'Popis' => 'Nákup materiálu', 'UcMD' => '501100', 'UcD' => '211000', 'Castka' => 1500.0],
                ['Cislo' => 10, 'Zdroj' => 'BK', 'Doklad' => 'BP24003', 'Datum' => '2024-06-30', 'Popis' => 'Vratka poplatku', 'UcMD' => '568000', 'UcD' => '221001', 'Castka' => -50.0],
                ['Cislo' => 11, 'Zdroj' => 'ID', 'Doklad' => 'ID24001', 'Datum' => '2024-12-31', 'Popis' => 'Dohadná položka', 'UcMD' => '518000', 'UcD' => '325000', 'Castka' => 300.0],
            ], 13),
            'ROK.001/PFaktury.DAT' => Ms3FixtureWriter::table(self::PURCHASE_FIELDS, [
                $vendor + [
                    'Doklad' => 'FP24001', 'PrijatDokl' => 'DF-2024-017', 'VarSymbol' => '2024017',
                    'Vystaveno' => '2024-02-08', 'DatUcPr' => '2024-02-10', 'PlnenoDPH' => '2024-02-10', 'Splatno' => '2024-02-22', 'Doruceno' => '2024-02-10',
                    'Zaklad_2' => 10000.0, 'DPH_2' => 2100.0, 'CelkemSDPH' => 12100.0,
                    'Uhrazeno' => '2024-02-20', 'UDoklad' => 'BV24001', 'Popis' => 'Účetní služby', 'BarCode' => '90000101',
                ],
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
                ['Doklad' => 'BV24001', 'Ucet' => 'BU', 'Vydej' => 1, 'DatUcPr' => '2024-02-20', 'DatPlat' => '2024-02-20', 'Celkem' => 12100.0, 'VarSym' => '2024017', 'AdNazev' => 'Dodavatel Alfa s.r.o.', 'Popis' => 'Úhrada FP24001'],
                ['Doklad' => 'BP24002', 'Ucet' => 'BU', 'Vydej' => 0, 'DatUcPr' => '2024-03-20', 'DatPlat' => '2024-03-20', 'Celkem' => 24200.0, 'VarSym' => '2024001', 'AdNazev' => 'Odběratel Beta a.s.', 'Popis' => 'Úhrada FV24001'],
                ['Doklad' => 'BP24003', 'Ucet' => 'BU', 'Vydej' => 0, 'DatUcPr' => '2024-06-30', 'DatPlat' => '2024-06-30', 'Celkem' => 50.0, 'Popis' => 'Vratka poplatku'],
            ]),

            'ROK.002/UcOsnova.DAT' => Ms3FixtureWriter::table(self::CHART_FIELDS, $chart),
            'ROK.002/UcPrKont.DAT' => Ms3FixtureWriter::table(self::RULE_FIELDS, $rules),
            'ROK.002/SzUcPokl.DAT' => Ms3FixtureWriter::table(self::REGISTER_FIELDS, $registers),
            'ROK.002/UcDenik.DAT' => Ms3FixtureWriter::table(self::JOURNAL_FIELDS, [
                ['Cislo' => -1, 'Zdroj' => 'XP', 'Datum' => '2025-01-01', 'Popis' => 'Počáteční stav roku 2025', 'UcMD' => '211000', 'UcD' => '701000', 'Castka' => 8500.0],
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
                ['Uhrada' => 'kartou', 'Doklad' => 'FP25002', 'PrijatDokl' => 'DF-2025-010', 'VarSymbol' => '2025010',
                    'Zaklad_2' => 1000.0, 'DPH_2' => 210.0, 'CelkemSDPH' => 1210.0, 'Popis' => 'Vyúčtování služeb'] + $purchaseDates('2025-03-10') + $vendor,
                ['Dobropis' => 1, 'Doklad' => 'DP25001', 'PrijatDokl' => 'DB-2025-001', 'VarSymbol' => '2025011',
                    'Zaklad_2' => -500.0, 'DPH_2' => -105.0, 'CelkemSDPH' => -605.0, 'Popis' => 'Dobropis služeb'] + $purchaseDates('2025-03-20') + $vendor,
                ['KodDPH' => self::KOD_DPH_REVERSE_CHARGE, 'Doklad' => 'FP25003', 'PrijatDokl' => 'RC-2025-001', 'VarSymbol' => '2025012',
                    'Zaklad_2' => 2000.0, 'DPH_2' => 0.0, 'CelkemSDPH' => 2000.0, 'Popis' => 'Stavební práce'] + $purchaseDates('2025-04-05') + $vendor,
                ['Mena' => 'EUR', 'PocetJedn' => 1, 'Kurs' => 25.0, 'Doklad' => 'FP25004', 'PrijatDokl' => 'EU-2025-001', 'VarSymbol' => '2025013',
                    'Zaklad_2' => 2500.0, 'DPH_2' => 525.0, 'CelkemSDPH' => 3025.0, 'Popis' => 'Licence v cizí měně'] + $purchaseDates('2025-04-15') + $vendor,
                // Money čísluje řadu každý rok od začátku: FP24001 je i v roce 2024.
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
