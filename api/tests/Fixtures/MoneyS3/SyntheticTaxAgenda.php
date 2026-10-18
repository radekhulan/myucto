<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\Ms3Table;

/**
 * Syntetická agenda Money S3 vedená jako DAŇOVÁ EVIDENCE — dva roky vymyšlené firmy
 * (tatáž firma jako {@see SyntheticAgenda}). Všechno je smyšlené.
 *
 * Tvar tabulek odpovídá Money: místo účetního deníku peněžní deník (`UcPenDen`, prázdná
 * tabulka `UcDenik`), typy pohybů se sloupcem peněžního deníku (`UcPohyby`), předkontace
 * daňové evidence (`UcPrKntJ`), nastavení roku (`Rok.DAT`, „Podvojné účetnictví" = N),
 * účty s počátečními stavy (`SzUcPokl.PocStav`) a podaná přiznání DPH (`SzDPH`).
 *
 * Adresář ROK.001 je rok 2025 a ROK.002 rok 2024 — pořadí adresářů v Money neurčuje
 * pořadí let. Firma je plátcem DPH od 1. 7. 2024 (2024 měsíčně, 2025 čtvrtletně).
 *
 * 2024: vklad do pokladny před registrací k DPH, vydaná faktura uhrazená bankou, přijatá
 * faktura placená v hotovosti a vratka k jejímu dobropisu v hotovosti (záporný výdaj),
 * pojistné OSVČ přes závazek (KZ), osobní spotřeba a nedaňový příjem bez dokladu.
 * 2025: záloha na přijatou fakturu (Druh L) a vyúčtování odečtenou zálohou, dobropis
 * vyrovnaný zápočtem (interní doklady bez řádku deníku), daňový příjem bez dokladu, nákup
 * v hotovosti bez faktury s DPH, odvod DPH přes závazek, otevřená vydaná faktura
 * a otevřený závazek (daň z příjmů).
 *
 * Počáteční stavy 2025 = počáteční stavy 2024 + pohyby 2024 (účet 51 115, pokladna 5 911).
 */
final class SyntheticTaxAgenda
{
    public const ICO = SyntheticAgenda::ICO;
    public const NAME = SyntheticAgenda::NAME;
    public const BANK_ACCOUNT = '3000000004';
    public const BANK_CODE = '0100';

    public const CASHBOOK_FIELDS = [
        ['Cislo', 'L', 4], ['Druh', 'V', 1], ['Datum', 'D', 2], ['Zdroj', 'C', 2], ['ZRadek', 'L', 4], ['URok', 'C', 3],
        ['UZdroj', 'C', 2], ['URadek', 'L', 4], ['Doklad', 'C', 10], ['Popis', 'C', 50], ['Typ', 'C', 1], ['Pohyb', 'C', 10],
        ['Castka', 'E', 10], ['VarSym', 'C', 20], ['ICO', 'C', 10], ['Del', 'B', 1], ['Free', 'B', 1], ['Mena', 'C', 4],
        ['Kurz', 'E', 10], ['AdCislo', 'L', 4],
    ];
    public const MOVEMENT_FIELDS = [['Zkrat', 'C', 10], ['Popis', 'C', 50], ['Typ', 'C', 1], ['Sloupec', 'C', 4]];
    private const RULE_FIELDS = [['Zkrat', 'C', 10], ['Popis', 'C', 50], ['Typ', 'C', 1], ['PohZak', 'C', 10], ['ZauctDPH', 'C', 10]];
    private const YEAR_FIELDS = [['Section1', 'C', 30], ['Variable', 'C', 40], ['TypeVar', 'C', 1], ['Value', 'C', 60]];
    public const ACCOUNT_FIELDS = [
        ['Zkrat', 'C', 10], ['Popis', 'C', 50], ['UcPokl', 'C', 1], ['PocStav', 'E', 10], ['Mena', 'C', 4], ['PrimUcet', 'C', 9],
        ['Ucet', 'C', 20], ['BKod', 'C', 4], ['IBAN', 'C', 34],
    ];
    public const BANK_FIELDS = [
        ['Cislo', 'L', 4], ['Doklad', 'C', 10], ['Ucet', 'C', 6], ['Vydej', 'B', 1], ['DatUcPr', 'D', 2], ['DatPlat', 'D', 2],
        ['Celkem', 'E', 10], ['VarSym', 'C', 20], ['AdNazev', 'C', 60], ['Popis', 'C', 50], ['Vypis', 'L', 4], ['PrKont', 'C', 10],
        ['Cleneni', 'C', 10], ['Mena', 'C', 3], ['Kurs', 'E', 10],
    ];
    private const CASH_FIELDS = [
        ['Cislo', 'L', 4], ['Doklad', 'C', 10], ['Pokl', 'C', 6], ['Vydej', 'B', 1], ['PrKont', 'C', 10], ['DatVyst', 'D', 2],
        ['DatUplDPH', 'D', 2], ['DatUcPr', 'D', 2], ['AdNazev', 'C', 60], ['AdICO', 'C', 12], ['AdDIC', 'C', 14], ['Popis', 'C', 50],
        ['Celkem', 'E', 10], ['Cleneni', 'C', 12], ['ZSazba', 'E', 10], ['ZaklZS', 'E', 10], ['DPHZS', 'E', 10],
    ];
    private const INTERNAL_FIELDS = [
        ['Cislo', 'L', 4], ['Doklad', 'C', 10], ['Popis', 'C', 50], ['DatUcPr', 'D', 2], ['Celkem', 'E', 10], ['CisloZapoc', 'L', 4],
        ['PrKont', 'C', 10], ['Cleneni', 'C', 12],
    ];
    private const LIABILITY_FIELDS = [
        ['Cislo', 'L', 4], ['Doklad', 'C', 10], ['Popis', 'C', 50], ['DatVyst', 'D', 2], ['DatSpl', 'D', 2], ['PrKont', 'C', 10],
        ['Celkem', 'E', 10], ['UhZbyva', 'E', 10], ['UcPokl', 'C', 10], ['AdCislo', 'L', 4],
    ];
    public const PURCHASE_FIELDS = [
        ['Cislo', 'L', 4], ['Doklad', 'C', 10], ['Storno', 'B', 1], ['PrijatDokl', 'C', 20], ['VarSymbol', 'C', 10], ['D_ICO', 'C', 12],
        ['D_DIC', 'C', 14], ['D_Nazev', 'C', 60], ['D_Ulice', 'C', 40], ['D_Mesto', 'C', 40], ['D_Psc', 'C', 10],
        ['Vystaveno', 'D', 2], ['DatUcPr', 'D', 2], ['PlnenoDPH', 'D', 2], ['Splatno', 'D', 2], ['Doruceno', 'D', 2],
        ['KodDPH', 'C', 12], ['Druh', 'C', 1], ['Dobropis', 'B', 1], ['Uhrada', 'C', 20],
        ['Zaklad_0', 'E', 10], ['Zaklad_1', 'E', 10], ['Zaklad_2', 'E', 10], ['SazbaDPH1', 'E', 10], ['SazbaDPH2', 'E', 10],
        ['DPH_1', 'E', 10], ['DPH_2', 'E', 10], ['CelkemSDPH', 'E', 10], ['Uhrazeno', 'D', 2], ['UDoklad', 'C', 10],
        ['Popis', 'C', 50], ['Mena', 'C', 3], ['PocetJedn', 'L', 4], ['Kurs', 'E', 10], ['Neuctovat', 'B', 1], ['FlagDel', 'B', 1],
        ['PredKontac', 'C', 10], ['SumZaloha', 'E', 10], ['Proplatit', 'E', 10],
    ];
    public const ISSUED_FIELDS = [
        ['Cislo', 'L', 4], ['Doklad', 'C', 10], ['Storno', 'B', 1], ['VarSymbol', 'C', 10], ['O_ICO', 'C', 12], ['O_DIC', 'C', 14],
        ['O_Nazev', 'C', 60], ['O_Ulice', 'C', 40], ['O_Mesto', 'C', 40], ['O_Psc', 'C', 10],
        ['Vystaveno', 'D', 2], ['DatUcPr', 'D', 2], ['PlnenoDPH', 'D', 2], ['Splatno', 'D', 2],
        ['KodDPH', 'C', 12], ['Druh', 'C', 1], ['Dobropis', 'B', 1], ['Uhrada', 'C', 20],
        ['Zaklad_0', 'E', 10], ['Zaklad_1', 'E', 10], ['Zaklad_2', 'E', 10], ['SazbaDPH1', 'E', 10], ['SazbaDPH2', 'E', 10],
        ['DPH_1', 'E', 10], ['DPH_2', 'E', 10], ['CelkemSDPH', 'E', 10], ['Uhrazeno', 'D', 2], ['UDoklad', 'C', 10],
        ['Popis', 'C', 50], ['Mena', 'C', 3], ['PocetJedn', 'L', 4], ['Kurs', 'E', 10], ['Neuctovat', 'B', 1],
        ['PredKontac', 'C', 10], ['Proplatit', 'E', 10],
    ];
    private const FILING_FIELDS = [
        ['ID', 'L', 4], ['DruhPodani', 'C', 5], ['ObdobiTyp', 'C', 1], ['Obdobi', 'V', 1], ['ObdobiRok', 'W', 2],
        ['DatumOd', 'D', 2], ['DatumDo', 'D', 2], ['Forma', 'V', 1], ['Poradi', 'V', 1], ['PodDate', 'D', 2],
    ];

    /** Typy pohybů peněžního deníku (výběr standardního číselníku Money) se sloupci. */
    public const MOVEMENTS = [
        ['Zkrat' => 'PP_504', 'Popis' => 'prodej služeb', 'Typ' => 'P', 'Sloupec' => 'PSlu'],
        ['Zkrat' => 'PP_DPH', 'Popis' => 'příjem DPH', 'Typ' => 'P', 'Sloupec' => 'PDPH'],
        ['Zkrat' => 'PP_510', 'Popis' => 'vklad podnikatele', 'Typ' => 'P', 'Sloupec' => 'PVkl'],
        ['Zkrat' => 'PP_999', 'Popis' => 'ostatní nedaňové příjmy', 'Typ' => 'P', 'Sloupec' => 'POsN'],
        ['Zkrat' => 'VP_105', 'Popis' => 'nákup zboží', 'Typ' => 'V', 'Sloupec' => 'VZbo'],
        ['Zkrat' => 'VP_106', 'Popis' => 'nákup služeb', 'Typ' => 'V', 'Sloupec' => 'VRez'],
        ['Zkrat' => 'VP_DPH', 'Popis' => 'výdaj DPH', 'Typ' => 'V', 'Sloupec' => 'VDPH'],
        ['Zkrat' => 'VP_142', 'Popis' => 'výdaje za sociální pojištění (OSVČ)', 'Typ' => 'V', 'Sloupec' => 'VOzc'],
        ['Zkrat' => 'VP_150', 'Popis' => 'daň z příjmů', 'Typ' => 'V', 'Sloupec' => 'VDzP'],
        ['Zkrat' => 'VP_160', 'Popis' => 'osobní spotřeba', 'Typ' => 'V', 'Sloupec' => 'VOso'],
    ];
    private const RULES = [
        ['Zkrat' => 'P504', 'Popis' => 'prodej služeb', 'Typ' => 'P', 'PohZak' => 'PP_504', 'ZauctDPH' => 'PP_DPH'],
        ['Zkrat' => 'P510', 'Popis' => 'vklad podnikatele', 'Typ' => 'P', 'PohZak' => 'PP_510', 'ZauctDPH' => 'PP_DPH'],
        ['Zkrat' => 'P999', 'Popis' => 'ostatní nedaňové příjmy', 'Typ' => 'P', 'PohZak' => 'PP_999', 'ZauctDPH' => 'PP_DPH'],
        ['Zkrat' => 'V105', 'Popis' => 'nákup zboží', 'Typ' => 'V', 'PohZak' => 'VP_105', 'ZauctDPH' => 'VP_DPH'],
        ['Zkrat' => 'V106', 'Popis' => 'nákup služeb', 'Typ' => 'V', 'PohZak' => 'VP_106', 'ZauctDPH' => 'VP_DPH'],
        ['Zkrat' => 'V142', 'Popis' => 'sociální pojištění OSVČ', 'Typ' => 'V', 'PohZak' => 'VP_142', 'ZauctDPH' => 'VP_DPH'],
        ['Zkrat' => 'V150', 'Popis' => 'daň z příjmů', 'Typ' => 'V', 'PohZak' => 'VP_150', 'ZauctDPH' => 'VP_DPH'],
        ['Zkrat' => 'V160', 'Popis' => 'osobní spotřeba', 'Typ' => 'V', 'PohZak' => 'VP_160', 'ZauctDPH' => 'VP_DPH'],
        ['Zkrat' => 'VDPH', 'Popis' => 'platba DPH', 'Typ' => 'V', 'PohZak' => 'VP_DPH', 'ZauctDPH' => 'VP_DPH'],
    ];

    /**
     * Celá agenda jako soubory: relativní cesta => obsah.
     *
     * @return array<string,string>
     */
    public static function files(): array
    {
        $base = SyntheticAgenda::files();
        $vendor = [
            'D_ICO' => SyntheticAgenda::VENDOR_ICO, 'D_DIC' => 'CZ' . SyntheticAgenda::VENDOR_ICO, 'D_Nazev' => 'Dodavatel Alfa s.r.o.',
            'D_Ulice' => 'Vzorová 1', 'D_Mesto' => 'Praha', 'D_Psc' => '110 00', 'SazbaDPH1' => 12.0, 'SazbaDPH2' => 21.0,
            'Druh' => 'N', 'KodDPH' => SyntheticAgenda::KOD_DPH_PURCHASE, 'Uhrada' => 'převodem',
        ];
        $customer = [
            'O_ICO' => SyntheticAgenda::CUSTOMER_ICO, 'O_DIC' => 'CZ' . SyntheticAgenda::CUSTOMER_ICO, 'O_Nazev' => 'Odběratel Beta a.s.',
            'O_Ulice' => 'Ukázková 7', 'O_Mesto' => 'Ostrava', 'O_Psc' => '702 00', 'SazbaDPH1' => 12.0, 'SazbaDPH2' => 21.0,
            'Druh' => 'N', 'KodDPH' => SyntheticAgenda::KOD_DPH_SALE, 'Uhrada' => 'převodem', 'PredKontac' => 'P504',
        ];
        $purchaseDates = static fn (string $date): array => ['Vystaveno' => $date, 'DatUcPr' => $date, 'PlnenoDPH' => $date, 'Splatno' => $date, 'Doruceno' => $date];
        $issuedDates = static fn (string $date): array => ['Vystaveno' => $date, 'DatUcPr' => $date, 'PlnenoDPH' => $date, 'Splatno' => $date];
        // Řádek peněžního deníku: [číslo, datum, zdroj, číslo dokladu, doklad, typ, pohyb, částka, vazba [zdroj, číslo, rok]].
        $cashbook = static function (array $rows): string {
            $out = [];
            foreach ($rows as $i => [$date, $source, $number, $doc, $type, $movement, $amount, $link]) {
                $out[] = [
                    'Cislo' => $i + 1, 'Datum' => $date, 'Zdroj' => $source, 'ZRadek' => $number, 'Doklad' => $doc, 'Popis' => $doc,
                    'Typ' => $type, 'Pohyb' => $movement, 'Castka' => $amount, 'Mena' => 'Kč', 'Kurz' => 1.0,
                    'UZdroj' => $link[0] ?? '', 'URadek' => $link[1] ?? 0, 'URok' => $link[2] ?? '',
                ];
            }
            // Smazaný řádek: v deníku zůstává, do součtů nepatří.
            $out[] = ['Cislo' => 99, 'Datum' => $rows[0][0], 'Zdroj' => 'BK', 'ZRadek' => 999, 'Doklad' => 'SMAZANO', 'Typ' => 'V',
                'Pohyb' => 'VP_160', 'Castka' => 999.0, 'Del' => 1];
            return Ms3FixtureWriter::table(self::CASHBOOK_FIELDS, $out);
        };
        $year = static fn (int $year): string => Ms3FixtureWriter::table(self::YEAR_FIELDS, [
            ['Section1' => 'Global', 'Variable' => 'Účetní rok', 'Value' => (string) $year],
            ['Section1' => 'Global', 'Variable' => 'Plátce DPH', 'Value' => '1'],
            ['Section1' => 'Global', 'Variable' => 'Podvojné účetnictví', 'Value' => 'N'],
            ['Section1' => 'Global', 'Variable' => 'Hospodářský rok od', 'Value' => (string) Ms3FixtureWriter::days("{$year}-01-01")],
            ['Section1' => 'Global', 'Variable' => 'Hospodářský rok do', 'Value' => (string) Ms3FixtureWriter::days("{$year}-12-31")],
            // Stejně pojmenovaná proměnná jiné sekce s rokem nesouvisí.
            ['Section1' => 'Účetnictví', 'Variable' => 'Účetní rok', 'Value' => 'jiná sekce'],
        ]);
        $accounts = static fn (float $bank, float $cash): string => Ms3FixtureWriter::table(self::ACCOUNT_FIELDS, [
            ['Zkrat' => 'BAN', 'Popis' => 'Běžný účet', 'UcPokl' => 'U', 'PocStav' => $bank, 'Ucet' => self::BANK_ACCOUNT, 'BKod' => self::BANK_CODE],
            ['Zkrat' => 'HOT', 'Popis' => 'Pokladna', 'UcPokl' => 'P', 'PocStav' => $cash],
        ]);
        $common = static fn (): array => [
            'UcPohyby.DAT' => Ms3FixtureWriter::table(self::MOVEMENT_FIELDS, self::MOVEMENTS),
            'UcPrKntJ.DAT' => Ms3FixtureWriter::table(self::RULE_FIELDS, self::RULES),
            'UcDenik.DAT' => Ms3FixtureWriter::table([['Cislo', 'L', 4], ['Zdroj', 'C', 2], ['Datum', 'D', 2], ['Castka', 'E', 10]], []),
        ];

        $files = [
            'AgendaInfo.ini' => $base['AgendaInfo.ini'],
            'Agenda.DAT' => $base['Agenda.DAT'],
            'AdresarF.DAT' => $base['AdresarF.DAT'],
            'AdUcBan.DAT' => $base['AdUcBan.DAT'],
            'SzDPH.DAT' => Ms3FixtureWriter::table(self::FILING_FIELDS, self::filings()),
        ];

        // ── 2024 (adresář ROK.002) ──────────────────────────────────────────────
        foreach ($common() as $name => $content) {
            $files['ROK.002/' . $name] = $content;
        }
        $files['ROK.002/Rok.DAT'] = $year(2024);
        $files['ROK.002/SzUcPokl.DAT'] = $accounts(50000.0, 5000.0);
        $files['ROK.002/VFaktury.DAT'] = Ms3FixtureWriter::table(self::ISSUED_FIELDS, [
            $customer + ['Cislo' => 1, 'Doklad' => 'FV24001', 'VarSymbol' => '2024001', 'Zaklad_2' => 10000.0, 'DPH_2' => 2100.0,
                'CelkemSDPH' => 12100.0, 'Uhrazeno' => '2024-08-20', 'UDoklad' => 'BP24001', 'Popis' => 'Poradenství'] + $issuedDates('2024-08-05'),
        ]);
        $files['ROK.002/PFaktury.DAT'] = Ms3FixtureWriter::table(self::PURCHASE_FIELDS, [
            $vendor + ['Cislo' => 1, 'Doklad' => 'FP24001', 'PrijatDokl' => 'DF-2024-031', 'VarSymbol' => '2024031', 'Zaklad_2' => 1000.0,
                'DPH_2' => 210.0, 'CelkemSDPH' => 1210.0, 'Uhrazeno' => '2024-09-12', 'UDoklad' => 'PV24001', 'Uhrada' => 'hotově',
                'Popis' => 'Servis tiskárny', 'PredKontac' => 'V106'] + $purchaseDates('2024-09-10'),
            $vendor + ['Cislo' => 2, 'Doklad' => 'FP24002', 'PrijatDokl' => 'DB-2024-031', 'VarSymbol' => '2024031', 'Dobropis' => 1, 'Zaklad_2' => -100.0,
                'DPH_2' => -21.0, 'CelkemSDPH' => -121.0, 'Uhrazeno' => '2024-09-30', 'UDoklad' => 'PP24002', 'Uhrada' => 'hotově',
                'Popis' => 'Dobropis servisu', 'PredKontac' => 'V106'] + $purchaseDates('2024-09-25'),
        ]);
        $files['ROK.002/KnihZav.DAT'] = Ms3FixtureWriter::table(self::LIABILITY_FIELDS, [
            ['Cislo' => 1, 'Doklad' => 'ZAV24001', 'Popis' => 'Sociální pojištění OSVČ', 'DatVyst' => '2024-10-01', 'DatSpl' => '2024-10-20',
                'PrKont' => 'V142', 'Celkem' => 3000.0, 'UhZbyva' => 0.0, 'UcPokl' => 'BAN'],
        ]);
        $files['ROK.002/BankKnih.DAT'] = Ms3FixtureWriter::table(self::BANK_FIELDS, [
            ['Cislo' => 1, 'Doklad' => 'BP24001', 'Ucet' => 'BAN', 'Vydej' => 0, 'DatUcPr' => '2024-08-20', 'DatPlat' => '2024-08-20', 'Celkem' => 12100.0,
                'VarSym' => '2024001', 'AdNazev' => 'Odběratel Beta a.s.', 'Popis' => 'Úhrada FV24001', 'Vypis' => 8, 'PrKont' => 'P504'],
            ['Cislo' => 2, 'Doklad' => 'BV24001', 'Ucet' => 'BAN', 'Vydej' => 1, 'DatUcPr' => '2024-10-18', 'DatPlat' => '2024-10-18', 'Celkem' => 3000.0,
                'VarSym' => '9000000001', 'Popis' => 'Sociální pojištění', 'Vypis' => 10, 'PrKont' => 'V142'],
            ['Cislo' => 3, 'Doklad' => 'BV24002', 'Ucet' => 'BAN', 'Vydej' => 1, 'DatUcPr' => '2024-11-29', 'DatPlat' => '2024-11-29', 'Celkem' => 8000.0,
                'Popis' => 'Výběr podnikatele', 'Vypis' => 11, 'PrKont' => 'V160'],
            ['Cislo' => 4, 'Doklad' => 'BP24002', 'Ucet' => 'BAN', 'Vydej' => 0, 'DatUcPr' => '2024-12-31', 'DatPlat' => '2024-12-31', 'Celkem' => 15.0,
                'Popis' => 'Zdaněné úroky', 'Vypis' => 12, 'PrKont' => 'P999'],
        ]);
        $files['ROK.002/PoklKnih.DAT'] = Ms3FixtureWriter::table(self::CASH_FIELDS, [
            ['Cislo' => 1, 'Doklad' => 'PV24001', 'Pokl' => 'HOT', 'Vydej' => 1, 'PrKont' => 'V106', 'DatVyst' => '2024-09-12', 'DatUcPr' => '2024-09-12',
                'AdNazev' => 'Dodavatel Alfa s.r.o.', 'Popis' => 'Úhrada FP24001', 'Celkem' => 1210.0],
            ['Cislo' => 2, 'Doklad' => 'PP24001', 'Pokl' => 'HOT', 'Vydej' => 0, 'PrKont' => 'P510', 'DatVyst' => '2024-03-01', 'DatUcPr' => '2024-03-01',
                'Popis' => 'Vklad do pokladny', 'Celkem' => 2000.0],
            // Vratka k dobropisu: příjem v pokladně, v peněžním deníku záporný výdaj.
            ['Cislo' => 3, 'Doklad' => 'PP24002', 'Pokl' => 'HOT', 'Vydej' => 0, 'PrKont' => 'V106', 'DatVyst' => '2024-09-30', 'DatUcPr' => '2024-09-30',
                'AdNazev' => 'Dodavatel Alfa s.r.o.', 'Popis' => 'Vratka k dobropisu FP24002', 'Celkem' => 121.0],
        ]);
        $files['ROK.002/UcPenDen.DAT'] = $cashbook([
            ['2024-03-01', 'PK', 2, 'PP24001', 'P', 'PP_510', 2000.0, null],
            ['2024-08-20', 'BK', 1, 'BP24001', 'P', 'PP_504', 10000.0, ['FV', 1]],
            ['2024-08-20', 'BK', 1, 'BP24001', 'P', 'PP_DPH', 2100.0, ['FV', 1]],
            ['2024-09-12', 'PK', 1, 'PV24001', 'V', 'VP_106', 1000.0, ['FP', 1]],
            ['2024-09-12', 'PK', 1, 'PV24001', 'V', 'VP_DPH', 210.0, ['FP', 1]],
            ['2024-09-30', 'PK', 3, 'PP24002', 'V', 'VP_106', -100.0, ['FP', 2]],
            ['2024-09-30', 'PK', 3, 'PP24002', 'V', 'VP_DPH', -21.0, ['FP', 2]],
            ['2024-10-18', 'BK', 2, 'BV24001', 'V', 'VP_142', 3000.0, ['KZ', 1]],
            ['2024-11-29', 'BK', 3, 'BV24002', 'V', 'VP_160', 8000.0, null],
            ['2024-12-31', 'BK', 4, 'BP24002', 'P', 'PP_999', 15.0, null],
        ]);

        // ── 2025 (adresář ROK.001) ──────────────────────────────────────────────
        foreach ($common() as $name => $content) {
            $files['ROK.001/' . $name] = $content;
        }
        $files['ROK.001/Rok.DAT'] = $year(2025);
        $files['ROK.001/SzUcPokl.DAT'] = $accounts(51115.0, 5911.0);
        $files['ROK.001/VFaktury.DAT'] = Ms3FixtureWriter::table(self::ISSUED_FIELDS, [
            // Otevřená k datu zálohy - doplatí se až v MyÚčtu.
            $customer + ['Cislo' => 1, 'Doklad' => 'FV25001', 'VarSymbol' => '2025001', 'Zaklad_2' => 5000.0, 'DPH_2' => 1050.0,
                'CelkemSDPH' => 6050.0, 'Proplatit' => 6050.0, 'Popis' => 'Konzultace'] + $issuedDates('2025-06-02'),
        ]);
        $files['ROK.001/PFaktury.DAT'] = Ms3FixtureWriter::table(self::PURCHASE_FIELDS, [
            // Zálohová faktura, Money vede úhradu zálohy jako výdaj ke dni úhrady.
            ['Cislo' => 1, 'Doklad' => 'ZF25001', 'PrijatDokl' => 'ZF-2025-001', 'VarSymbol' => '2025101', 'Druh' => 'L', 'KodDPH' => '',
                'Zaklad_0' => 2420.0, 'CelkemSDPH' => 2420.0, 'Uhrazeno' => '2025-02-03', 'UDoklad' => 'BV25001', 'Popis' => 'Záloha na školení',
                'PredKontac' => ''] + $purchaseDates('2025-01-28') + $vendor,
            // Vyúčtování: celé uhrazené zálohou.
            $vendor + ['Cislo' => 2, 'Doklad' => 'FP25002', 'PrijatDokl' => 'DF-2025-012', 'VarSymbol' => '2025012', 'Zaklad_2' => 2000.0, 'DPH_2' => 420.0,
                'CelkemSDPH' => 2420.0, 'SumZaloha' => -2420.0, 'Uhrazeno' => '2025-02-20', 'UDoklad' => 'ZF25001', 'Popis' => 'Školení',
                'PredKontac' => 'V106'] + $purchaseDates('2025-02-20'),
            // Faktura a dobropis k ní, vyrovnané zápočtem (interní doklady bez peněz).
            $vendor + ['Cislo' => 3, 'Doklad' => 'FP25003', 'PrijatDokl' => 'DF-2025-020', 'VarSymbol' => '2025020', 'Zaklad_2' => 500.0, 'DPH_2' => 105.0,
                'CelkemSDPH' => 605.0, 'Uhrazeno' => '2025-03-15', 'UDoklad' => 'ID25001', 'Popis' => 'Spotřební materiál',
                'PredKontac' => 'V105'] + $purchaseDates('2025-03-03'),
            $vendor + ['Cislo' => 4, 'Doklad' => 'FP25004', 'PrijatDokl' => 'DB-2025-020', 'VarSymbol' => '2025020', 'Dobropis' => 1, 'Zaklad_2' => -500.0,
                'DPH_2' => -105.0, 'CelkemSDPH' => -605.0, 'Uhrazeno' => '2025-03-15', 'UDoklad' => 'ID25002', 'Popis' => 'Dobropis materiálu',
                'PredKontac' => 'V105'] + $purchaseDates('2025-03-10'),
        ]);
        $files['ROK.001/IntDokl.DAT'] = Ms3FixtureWriter::table(self::INTERNAL_FIELDS, [
            ['Cislo' => 1, 'Doklad' => 'ID25001', 'Popis' => 'Zápočet FP25003', 'DatUcPr' => '2025-03-15', 'Celkem' => 605.0, 'CisloZapoc' => 1],
            ['Cislo' => 2, 'Doklad' => 'ID25002', 'Popis' => 'Zápočet FP25004', 'DatUcPr' => '2025-03-15', 'Celkem' => 605.0, 'CisloZapoc' => 1],
        ]);
        $files['ROK.001/KnihZav.DAT'] = Ms3FixtureWriter::table(self::LIABILITY_FIELDS, [
            ['Cislo' => 1, 'Doklad' => 'ZAV25001', 'Popis' => 'DPH za 12/2024', 'DatVyst' => '2025-01-25', 'DatSpl' => '2025-01-25',
                'PrKont' => 'VDPH', 'Celkem' => 1890.0, 'UhZbyva' => 0.0, 'UcPokl' => 'BAN'],
            // Otevřený k datu zálohy.
            ['Cislo' => 2, 'Doklad' => 'ZAV25002', 'Popis' => 'Doplatek daně z příjmů', 'DatVyst' => '2025-11-01', 'DatSpl' => '2025-12-15',
                'PrKont' => 'V150', 'Celkem' => 1500.0, 'UhZbyva' => 1500.0, 'UcPokl' => 'BAN'],
        ]);
        $files['ROK.001/BankKnih.DAT'] = Ms3FixtureWriter::table(self::BANK_FIELDS, [
            ['Cislo' => 1, 'Doklad' => 'BV25001', 'Ucet' => 'BAN', 'Vydej' => 1, 'DatUcPr' => '2025-02-03', 'DatPlat' => '2025-02-03', 'Celkem' => 2420.0,
                'VarSym' => '2025101', 'AdNazev' => 'Dodavatel Alfa s.r.o.', 'Popis' => 'Záloha ZF25001', 'Vypis' => 2, 'PrKont' => 'V106'],
            ['Cislo' => 2, 'Doklad' => 'BV25002', 'Ucet' => 'BAN', 'Vydej' => 1, 'DatUcPr' => '2025-01-24', 'DatPlat' => '2025-01-24', 'Celkem' => 1890.0,
                'VarSym' => '9000000002', 'Popis' => 'Odvod DPH', 'Vypis' => 1, 'PrKont' => 'VDPH'],
            ['Cislo' => 3, 'Doklad' => 'BP25001', 'Ucet' => 'BAN', 'Vydej' => 0, 'DatUcPr' => '2025-03-10', 'DatPlat' => '2025-03-10', 'Celkem' => 500.0,
                'Popis' => 'Tržba bez faktury', 'Vypis' => 3, 'PrKont' => 'P504'],
        ]);
        $files['ROK.001/PoklKnih.DAT'] = Ms3FixtureWriter::table(self::CASH_FIELDS, [
            ['Cislo' => 1, 'Doklad' => 'PV25001', 'Pokl' => 'HOT', 'Vydej' => 1, 'PrKont' => 'V105', 'DatVyst' => '2025-04-02', 'DatUplDPH' => '2025-04-02',
                'DatUcPr' => '2025-04-02', 'Popis' => 'Nákup bez faktury', 'Celkem' => 1210.0, 'Cleneni' => SyntheticAgenda::KOD_DPH_PURCHASE,
                'ZSazba' => 21.0, 'ZaklZS' => 1000.0, 'DPHZS' => 210.0],
            // Nulový doklad: v pokladně nemá účinek ani řádek deníku.
            ['Cislo' => 2, 'Doklad' => 'PV25002', 'Pokl' => 'HOT', 'Vydej' => 1, 'PrKont' => 'V105', 'DatVyst' => '2025-04-03', 'DatUcPr' => '2025-04-03',
                'Popis' => 'Nulový doklad', 'Celkem' => 0.0],
        ]);
        $files['ROK.001/UcPenDen.DAT'] = $cashbook([
            ['2025-01-24', 'BK', 2, 'BV25002', 'V', 'VP_DPH', 1890.0, ['KZ', 1]],
            ['2025-02-03', 'BK', 1, 'BV25001', 'V', 'VP_106', 2420.0, ['FP', 1]],
            ['2025-03-10', 'BK', 3, 'BP25001', 'P', 'PP_504', 500.0, null],
            ['2025-04-02', 'PK', 1, 'PV25001', 'V', 'VP_105', 1000.0, null],
            ['2025-04-02', 'PK', 1, 'PV25001', 'V', 'VP_DPH', 210.0, null],
        ]);
        return $files;
    }

    /**
     * Agenda, která v Money vedla rok 2024 podvojně a rok 2025 znovu jako daňovou evidenci
     * (syntetická agenda {@see SyntheticAgenda} s rokem 2025 předělaným na peněžní deník).
     *
     * @return array<string,string>
     */
    public static function filesReturnedToTaxRecords(): array
    {
        $files = SyntheticAgenda::files();
        $setting = static fn (int $year, string $doubleEntry): string => Ms3FixtureWriter::table(self::YEAR_FIELDS, [
            ['Section1' => 'Global', 'Variable' => 'Účetní rok', 'Value' => (string) $year],
            ['Section1' => 'Global', 'Variable' => 'Podvojné účetnictví', 'Value' => $doubleEntry],
        ]);
        $files['ROK.001/Rok.DAT'] = $setting(2024, 'Y');
        $files['ROK.002/Rok.DAT'] = $setting(2025, 'N');
        $files['ROK.002/UcDenik.DAT'] = Ms3FixtureWriter::table([['Cislo', 'L', 4], ['Zdroj', 'C', 2], ['Datum', 'D', 2], ['Castka', 'E', 10]], []);
        $files['ROK.002/UcPohyby.DAT'] = Ms3FixtureWriter::table(self::MOVEMENT_FIELDS, self::MOVEMENTS);
        $files['ROK.002/UcPenDen.DAT'] = Ms3FixtureWriter::table(self::CASHBOOK_FIELDS, [
            ['Cislo' => 1, 'Datum' => '2025-03-01', 'Zdroj' => 'BK', 'ZRadek' => 1, 'Doklad' => 'BV25001', 'Typ' => 'V', 'Pohyb' => 'VP_160', 'Castka' => 500.0],
        ]);
        return $files;
    }

    /**
     * Agenda, ve které Money při vyúčtování zálohy přeúčtovalo DPH zálohy nulovým pokladním
     * dokladem (výdaj služby −420 Kč, DPH +420 Kč k vyúčtování FP25002). MyÚčto nulové
     * doklady nepřebírá, rekonciliace to musí ukázat jako rozdíl k přijetí u dokladu.
     *
     * @return array<string,string>
     */
    public static function filesWithAdvanceVatReclass(): array
    {
        $files = self::with(self::files(), 'ROK.001/PoklKnih.DAT', self::CASH_FIELDS, static fn (array $rows): array => [...$rows,
            ['Cislo' => 3, 'Doklad' => 'PV25003', 'Pokl' => 'HOT', 'Vydej' => 1, 'PrKont' => 'V106', 'DatVyst' => '2025-02-20', 'DatUcPr' => '2025-02-20',
                'Popis' => 'Zúčtování zálohy ZF25001', 'Celkem' => 0.0]]);
        return self::with($files, 'ROK.001/UcPenDen.DAT', self::CASHBOOK_FIELDS, static fn (array $rows): array => [...$rows,
            ['Cislo' => 80, 'Datum' => '2025-02-20', 'Zdroj' => 'PK', 'ZRadek' => 3, 'Doklad' => 'PV25003', 'Typ' => 'V', 'Pohyb' => 'VP_106',
                'Castka' => -420.0, 'UZdroj' => 'FP', 'URadek' => 2, 'Mena' => 'Kč', 'Kurz' => 1.0],
            ['Cislo' => 81, 'Datum' => '2025-02-20', 'Zdroj' => 'PK', 'ZRadek' => 3, 'Doklad' => 'PV25003', 'Typ' => 'V', 'Pohyb' => 'VP_DPH',
                'Castka' => 420.0, 'UZdroj' => 'FP', 'URadek' => 2, 'Mena' => 'Kč', 'Kurz' => 1.0],
        ]);
    }

    /**
     * Řádky tabulky ze souborů agendy (všechna pole, jak je přečte převod).
     *
     * @param array<string,string> $files
     * @return list<array<string,mixed>>
     */
    public static function rows(array $files, string $path): array
    {
        return iterator_to_array(Ms3Table::fromString($files[$path], strtoupper(pathinfo($path, PATHINFO_FILENAME)))->rows(), false);
    }

    /**
     * Soubory agendy s tabulkou přepsanou: `$map` dostane řádky tabulky a vrátí nové.
     *
     * @param array<string,string> $files
     * @param list<array{0:string,1:string,2:int}> $fields rozložení tabulky
     * @param callable(list<array<string,mixed>>):list<array<string,mixed>> $map
     * @return array<string,string>
     */
    public static function with(array $files, string $path, array $fields, callable $map): array
    {
        $files[$path] = Ms3FixtureWriter::table($fields, $map(isset($files[$path]) ? self::rows($files, $path) : []));
        return $files;
    }

    /**
     * Podaná přiznání DPH a kontrolní hlášení, jak je eviduje Money (`SzDPH`): 2024 měsíčně
     * od července, 2025 čtvrtletně.
     *
     * @return list<array<string,mixed>>
     */
    private static function filings(): array
    {
        $rows = [];
        $id = 0;
        $period = static function (int $year, string $type, int $period) use (&$rows, &$id): void {
            $months = $type === 'M' ? 1 : 3;
            $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, ($period - 1) * $months + 1));
            foreach (['DPH', 'DPHKH'] as $kind) {
                $rows[] = ['ID' => ++$id, 'DruhPodani' => $kind, 'ObdobiTyp' => $type, 'Obdobi' => $period, 'ObdobiRok' => $year,
                    'DatumOd' => $from->format('Y-m-d'), 'DatumDo' => $from->modify('+' . ($months - 1) . ' months')->format('Y-m-t'), 'Forma' => 0, 'Poradi' => 1];
            }
        };
        foreach (range(7, 12) as $month) {
            $period(2024, 'M', $month);
        }
        foreach (range(1, 4) as $quarter) {
            $period(2025, 'K', $quarter);
        }
        return $rows;
    }
}
