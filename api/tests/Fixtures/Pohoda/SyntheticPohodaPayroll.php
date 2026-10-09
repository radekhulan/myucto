<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Pohoda;

/**
 * Syntetické mzdy z datového souboru POHODA Mzdy / PAMICA (`91_mzdy.xml`, výstup
 * `tools/pohoda-export/Export-PohodaMdb.ps1`). Fiktivní osoby, žádná reálná data.
 *
 * Leden a únor 2026: Jana (pracovní poměr 40 h, měsíční mzda 40 000 Kč, odměna,
 * přesčas, obědy, v lednu srážka 300 Kč, v únoru 8 h dovolené) a Petr (DPP, časová
 * mzda za hodiny).
 *
 * Údaje osob a vztahů pro JMHZ: Jana má adresu, e-mail, podepsané prohlášení, pracoviště
 * s kódem obce, CZ-ISCO, platné OIČ a ID PPV, oznámení pojišťovně o nástupu a odeslanou
 * registraci ČSSZ. Petr má telefon, nepodepsané prohlášení, OIČ s chybnou kontrolní
 * číslicí, vztah skončený 28. 2. 2026 s odeslanou odhláškou ČSSZ a oznámením pojišťovně
 * o skončení; jeho registrace ČSSZ odeslaná není a oznámení o nástupu je v jiném stavu.
 */
final class SyntheticPohodaPayroll
{
    public const ICO = '12345678';
    public const YEAR = 2026;
    /** Hrubá mzda Jany a Petra za měsíc (kontrolní součet). */
    public const GROSS = [43000.0, 5000.0];
    /** OIČ Jany (platná kontrolní číslice) a ID PPV obou vztahů. */
    public const JANA_OIC = '1234567895';
    public const JANA_ID_PPV = '1234567890123';
    public const PETR_ID_PPV = '9876543210';
    public const PETR_END = '2026-02-28';

    /** GUID podání a formulářů syntetických hlášení JMHZ ({@see self::writeWithReports()}). */
    public const REPORT_GUIDS = [
        'jan' => '11111111-1111-4111-8111-111111111111',
        'feb' => '22222222-2222-4222-8222-222222222222',
        'feb_o' => '33333333-3333-4333-8333-333333333333',
        'mar' => '44444444-4444-4444-8444-444444444444',
        'jana' => 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA',
        'petr' => 'BBBBBBBB-BBBB-4BBB-8BBB-BBBBBBBBBBBB',
    ];
    /** Průměrný hodinový výdělek Petra, který nese jen hlášení (PAMICA ho ve mzdě nemá). */
    public const PETR_REPORT_AVERAGE = '180.50';

    /**
     * Totéž jako {@see self::write()} a navíc obsah podání tak, jak ho rozepíše export PAMICA
     * (`<Data><a id t f i>…</a></Data>`): hlášení za leden a únor (řádné) odeslaná, za únor
     * i opravné, za březen neodeslané; Janina registrace s obsahem věty. Petrův vztah v kartě
     * ID PPV nemá - nese ho jen hlášení. Hlášení se schválně v několika údajích liší od karet
     * (pracoviště, týdenní doba, CZ-ISCO, druhé dítě), aby šlo ověřit, že převod karty nepřepíše.
     */
    public static function writeWithReports(string $root): string
    {
        return self::write($root, true);
    }

    /**
     * Totéž jako {@see self::writeWithReports()}, ale věty registrace Jany jsou úplné, jak je PAMICA
     * odeslala: první (duben) hlásí jiné CZ-ISCO než karta, druhá (květen) s kartou souhlasí a nese
     * navíc stát a místo narození, daňovou rezidenci a pojišťovnu.
     */
    public static function writeWithRegistrations(string $root): string
    {
        return self::write($root, true, true);
    }

    /** Zapíše složku mezd `<IČO>_<rok>` s `91_mzdy.xml` do `$root` a vrátí cestu k souboru. */
    public static function write(string $root, bool $reports = false, bool $registrations = false): string
    {
        $dir = rtrim($root, '/\\') . '/' . self::ICO . '_' . self::YEAR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $x = '';
        $row = static function (string $table, array $cols) use (&$x): void {
            $x .= "<{$table}>";
            foreach ($cols as $k => $v) {
                $x .= "<{$k}>" . htmlspecialchars((string) $v, ENT_XML1) . "</{$k}>";
            }
            $x .= "</{$table}>";
        };
        foreach ([[1, 'M01', 'Základní mzda měsíční'], [2, 'O01', 'Odměna'], [3, 'J03', 'Obědy'], [4, 'P01', 'Příplatek za přesčas'], [5, 'C01', 'Časová mzda']] as [$id, $cislo, $nazev]) {
            $row('sMZslozky', ['ID' => $id, 'Cislo' => $cislo, 'Nazev' => $nazev]);
        }
        $row('sMZneprit', ['ID' => 1, 'Cislo' => 'V01', 'Nazev' => 'Dovolená']);
        $row('sMZneprit', ['ID' => 2, 'Cislo' => 'H08', 'Nazev' => 'Rodičovská dovolená']);
        $row('sMZsrazky', ['ID' => 1, 'Cislo' => 'S07', 'Nazev' => 'Srážka zadaná částkou']);
        $row('sMzPoj', ['ID' => 1, 'IDS' => 'VZP', 'Kod' => '111', 'Ucet' => '2050001', 'KodBanky' => '0710', 'VarSym' => '12345678', 'DataBox' => 'i48ae3q']);
        $row('sSTR', ['ID' => 1, 'IDS' => 'ADM', 'SText' => 'Administrativa']);
        $row('PracMista', ['ID' => 1, 'IDS' => 'UCT', 'SText' => 'Účetní']);
        $row('sMzMist', ['ID' => 1, 'Cislo' => '582786', 'Misto' => 'Brno sídlo', 'Obec' => 'Brno', 'Stat' => 'CZ']);
        $row('ZAM', ['ID' => 1, 'OsCislo' => '1001', 'Jmeno' => 'Jana', 'Prijmeni' => 'Testovací', 'Rozena' => 'Pokusná', 'Titul' => 'Ing.',
            'DatNar' => '1990-05-04', 'MistoNar' => 'Brno', 'StatPris' => 'CZ', 'Nerezident' => 0, 'RefPoj' => 1, 'RefMist' => 1,
            'Ulice' => 'Zkušební', 'CP' => '12', 'Obec' => 'Brno', 'PSC' => '60200', 'Stat' => 'CZ', 'Email' => 'jana@example.invalid', 'OIC' => self::JANA_OIC]);
        // Petr má v datech rodné číslo bez platného data narození a pojišťovnu 999 (cizinec
        // bez českého pojištění) - převod je vynechá, osobu ale založí.
        $row('sMzPoj', ['ID' => 2, 'IDS' => 'CIZI', 'Kod' => '999']);
        $row('ZAM', ['ID' => 2, 'OsCislo' => '1002', 'Jmeno' => 'Petr', 'Prijmeni' => 'Zkušební', 'DatNar' => '1985-11-20', 'RodCisl' => '8513990000', 'RefPoj' => 2,
            'StatPris' => 'SK', 'Nerezident' => 0, 'Ulice' => 'Pokusná', 'CP' => '3', 'Obec' => 'Ostrava', 'PSC' => '70200', 'Stat' => 'CZ',
            'Tel' => '+420 600 000 000', 'OIC' => '1234567890']);
        $row('ZAMpomer', ['ID' => 1, 'RefZAM' => 1, 'Poradi' => 1, 'Cislo' => '1', 'JeDPP' => 0, 'DatNast' => '2025-03-01', 'TUvazek' => 40, 'ResStr' => 1, 'RelPracMist' => 1,
            'IDPPV' => self::JANA_ID_PPV, 'ResCisCZISCO' => '43111']);
        $row('ZAMpomer', ['ID' => 2, 'RefZAM' => 2, 'Poradi' => 1, 'Cislo' => '1', 'JeDPP' => 1, 'DatNast' => '2026-01-01', 'DatOdch' => self::PETR_END, 'RelUkonc' => 1]
            + ($reports ? [] : ['IDPPV' => self::PETR_ID_PPV]));
        foreach ([1, 2] as $m) {
            $jana = 10 + $m;
            $petr = 20 + $m;
            $row('MZ', ['ID' => $jana, 'RefZAM' => 1, 'RefPomer' => 1, 'Rok' => self::YEAR, 'RelMes' => $m, 'HodFond' => 160, 'HodOdpra' => $m === 2 ? 152 : 160,
                'TUvazek' => 40, 'RefPoj' => 1, 'KcHrubaM' => self::GROSS[0], 'KcCistaM' => 33000, 'Prohlas' => 1, 'JeSocPP' => 1, 'KcSoc' => 3053, 'KcZdr' => 1935,
                // Den výplaty a vyplacená částka: doklad, že mzda na účet opravdu odešla.
                'Datum' => sprintf('%04d-%02d-10', self::YEAR, $m + 1), 'KcVyplat' => 33000,
                // Sjednaná měsíční mzda a čtvrtletní průměr, se kterým PAMICA počítala náhrady.
                'KcZaklM' => $m === 2 ? 42000 : 40000, 'DnyPrac' => 20, 'DnyOdpra' => 20, 'KcPrum' => 250,
                'KcSocZak' => 43000, 'KcZdaM' => 43000, 'KcDanPrS' => 6450, 'KcNzdZak' => 2570, 'KcDanZal' => 3880, 'KcZalDan' => 3880]);
            // Petr (DPP pod limitem) bez účasti na pojištění; v únoru žádá o slevu pracujícího důchodce.
            $row('MZ', ['ID' => $petr, 'RefZAM' => 2, 'RefPomer' => 2, 'Rok' => self::YEAR, 'RelMes' => $m, 'HodFond' => 0, 'HodOdpra' => 25,
                'RefPoj' => 2, 'KcHrubaM' => self::GROSS[1], 'KcCistaM' => 5000, 'Prohlas' => 0, 'JeSocPP' => 0, 'KcSraDanZak' => 5000, 'KcSraDan' => 750,
                'SocPojSlevaZadost' => $m === 2 ? 1 : 0]);
            // Od února má Jana vyšší měsíční mzdu (změnu zpracovala PAMICA).
            $row('MZslozky', ['ID' => 100 + $m, 'RefAg' => $jana, 'RefSlozka' => 1, 'KcMzda' => 40000, 'Hodnota1' => $m === 2 ? 42000 : 40000]);
            $row('MZslozky', ['ID' => 110 + $m, 'RefAg' => $jana, 'RefSlozka' => 2, 'KcMzda' => 2000]);
            $row('MZslozky', ['ID' => 120 + $m, 'RefAg' => $jana, 'RefSlozka' => 4, 'KcMzda' => 1000, 'PocHodin' => 4]);
            $row('MZslozky', ['ID' => 130 + $m, 'RefAg' => $jana, 'RefSlozka' => 3, 'KcMzda' => -600]);
            $row('MZslozky', ['ID' => 140 + $m, 'RefAg' => $petr, 'RefSlozka' => 5, 'KcMzda' => 5000, 'PocHodin' => 25]);
        }
        $row('MZneprit', ['ID' => 1, 'RefAg' => 12, 'RefSlozka' => 1, 'HodPrac' => 8, 'KcNahr' => 2000, 'DatZac' => '2026-02-10', 'DatKon' => '2026-02-10']);
        // Rodičovská dovolená (H08) v hodinách sešitu není, do evidence nepřítomností ale patří.
        $row('MZneprit', ['ID' => 2, 'RefAg' => 22, 'RefSlozka' => 2, 'DatZac' => '2026-02-01', 'DatKon' => '2026-02-28']);
        $row('ZAMucet', ['ID' => 1, 'RefAg' => 1, 'Ucet' => '1000002', 'KodBanky' => '0800', 'Active' => 1]);
        $row('MZsrazky', ['ID' => 1, 'RefAg' => 11, 'RefSlozka' => 1, 'KcSrazeno' => 300]);

        // Janino dítě s daňovým zvýhodněním na 1. dítě (kód 34) a její sleva na poplatníka (36).
        $row('ZAMpDet', ['ID' => 1, 'RefAg' => 1, 'DatOd' => '2025-03-01', 'RelOdpoc' => 34, 'KcOdec' => 15204, 'Poradi' => 1,
            'Jmeno' => 'Tereza', 'Prijmeni' => 'Testovací', 'RodCisl' => '1501010005']);
        $row('ZAMpDet', ['ID' => 2, 'RefAg' => 1, 'DatOd' => '2025-03-01', 'RelOdpoc' => 36, 'KcOdec' => 30840, 'Poradi' => 2]);

        // Oznámení pojišťovně (RelKod 1 nástup, 2 skončení; stav 2 = zpracované).
        // Janino oznámení o nástupu PAMICA nevede jako zpracované; vztah ale vznikl před převodem.
        $row('ZAMzp', ['ID' => 1, 'RefAg' => 1, 'RefPomer' => 1, 'RelKod' => 1, 'RefPoj' => 1, 'RefStav' => 1, 'DatStav' => '2025-03-03', 'Datum' => '2025-03-01']);
        $row('ZAMzp', ['ID' => 2, 'RefAg' => 2, 'RefPomer' => 2, 'RelKod' => 1, 'RefPoj' => 2, 'RefStav' => 1, 'DatStav' => '2026-01-02', 'Datum' => '2026-01-01']);
        $row('ZAMzp', ['ID' => 3, 'RefAg' => 2, 'RefPomer' => 2, 'RelKod' => 2, 'RefPoj' => 2, 'RefStav' => 2, 'DatStav' => '2026-03-02', 'Datum' => self::PETR_END]);
        // Registrace JMHZ: Janin trvající vztah odeslaný, Petrova přihláška neodeslaná.
        $row('RegZAM', ['ID' => 1, 'RelStavDP' => 7, 'DatPod' => '2026-04-10', 'DatPrij' => '2026-04-10', 'ElOdeslano' => 1]);
        if ($reports) {
            // Věta registrace s obsahem: CZ-ISCO jiné než na kartě (karta vyhrává), upřesnění
            // vztahu a sjednané místo výkonu práce, které karta nemá.
            $x .= '<RegZAMitems><ID>1</ID><RefAg>1</RefAg><RefZAM>1</RefZAM><RefPomer>1</RefPomer><RelTyp>3</RelTyp>'
                . '<OIC>' . self::JANA_OIC . '</OIC><IDPPV>' . self::JANA_ID_PPV . '</IDPPV>'
                . self::attributes([[10228, self::JANA_ID_PPV], [10234, '41101'], [10239, '1'], [10502, '1'], [10527, 'Brno pobočka'],
                    [10528, 'Praha'], [10529, '554782'], [10386, '1.1.2026', 0, 1], ...($registrations ? self::janaSentence() : [])])
                . '<DatCreate>2026-04-09T10:00:00</DatCreate></RegZAMitems>';
            if ($registrations) {
                $row('RegZAM', ['ID' => 3, 'RelStavDP' => 7, 'DatPod' => '2026-05-04', 'DatPrij' => '2026-05-04', 'ElOdeslano' => 1]);
                $x .= '<RegZAMitems><ID>3</ID><RefAg>3</RefAg><RefZAM>1</RefZAM><RefPomer>1</RefPomer><Sqnr>1</Sqnr><RelTyp>3</RelTyp>'
                    . self::attributes([...self::janaSentence(), [10234, '43111'], [10235, 'účetní'], [10249, '1111'], [10255, '1'], [10407, 'N'], [10065, 'CZ'], [10066, 'Brno'], [10068, 'CZ'], [10102, '111']])
                    . '<DatCreate>2026-05-04T10:00:00</DatCreate></RegZAMitems>';
            }
        } else {
            $row('RegZAMitems', ['ID' => 1, 'RefAg' => 1, 'RefZAM' => 1, 'RefPomer' => 1, 'RelTyp' => 3, 'OIC' => self::JANA_OIC, 'IDPPV' => self::JANA_ID_PPV]);
        }
        $row('RegZAM', ['ID' => 2, 'RelStavDP' => 1, 'DatPod' => '2026-01-02', 'ElOdeslano' => 0]);
        $row('RegZAMitems', ['ID' => 2, 'RefAg' => 2, 'RefZAM' => 2, 'RefPomer' => 2, 'RelTyp' => 1]);
        // Starší odhláška ČSSZ (ONZ) Petrova vztahu, odeslaná.
        $row('ONZ', ['ID' => 1, 'RelStavDP' => 7, 'DatPod' => '2026-03-05', 'ElOdeslano' => 1]);
        $row('ONZpol', ['ID' => 1, 'RefAg' => 1, 'RefPomer' => 2, 'RelTyp' => 2, 'DatVstup' => '2026-01-01', 'DatOdch' => self::PETR_END]);
        if ($reports) {
            $x .= self::reports();
        }

        $file = $dir . '/91_mzdy.xml';
        file_put_contents($file, '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="' . self::ICO . '" year="' . self::YEAR . '" source="POHODA" state="ok">' . $x . '</mdbExport>');
        return $file;
    }

    /**
     * Hlášení JMHZ (`MH`, `MHitems`) v podobě exportu PAMICA. Hodnoty jako v PAMICA: datum
     * `d.m.rrrr`, příznak `A`/`N`, desetinná tečka.
     */
    private static function reports(): string
    {
        $g = self::REPORT_GUIDS;
        $header = static fn (int $id, int $month, int $type, int $ref, bool $sent, string $submitted, string $guid): string => '<MH>'
            . "<ID>{$id}</ID><RefID>{$ref}</RefID><RelTyp>{$type}</RelTyp><RelStavDP>" . ($sent ? 7 : 1) . '</RelStavDP>'
            . "<RelMesic>{$month}</RelMesic><Rok>" . self::YEAR . '</Rok><DatPod>' . $submitted . '</DatPod>'
            . ($sent ? '<DatPrij>' . $submitted . '</DatPrij>' : '') . '<ElOdeslano>' . ($sent ? 1 : 0) . '</ElOdeslano>'
            . self::attributes([[10001, $guid], [10029, '15000']], 'DataAll') . '</MH>';
        $jana = static fn (string $type, string $weekly, string $average): array => [
            [1, 'bezPriznaku'], [10012, $g['jana']], [10016, $type], [10495, 'A'],
            [10051, self::JANA_OIC], [10228, self::JANA_ID_PPV], [10053, 'Testovací'], [10054, 'Jana'],
            [10056, '4.5.1990'], [10223, '1.3.2025'], [10239, '1'],
            // Pracoviště jiné než na kartě: karta vyhrává, hlášení ho nepřepíše.
            [10229, 'Praha'], [10230, '554782'], [10231, 'CZ'], [10232, 'N'], [10247, 'N', 0, 0], [10251, 'N'],
            [10259, '160.000', 0, 0], [10260, '160.000', 0, 0], [10261, $weekly],
            [10265, '31'], [10268, '160.000'], [10275, '0.000'], [10279, '8.000'],
            [10286, '43000'], [10297, '43000'], [10298, '6450'], [10299, '2570'], [10305, '3880'], [10306, '0'], [10419, 'A'],
            [10344, '33000'], [10116, 'N'], [10371, '1935'], [10482, '3870'],
            // Dvě děti; převod dítě z karty nechá a druhé z hlášení nepřidá.
            [10435, 'Tereza'], [10436, 'Testovací'], [10438, '1501010005'], [10439, 'N'], [10440, '1'],
            [10435, 'Tomáš', 0, 1], [10436, 'Testovací', 0, 1], [10438, '1702020009', 0, 1], [10439, 'N', 0, 1], [10440, '2', 0, 1],
            [10303, '1267'], [10304, '1267'], [10453, 'N'],
            [10328, '43000'], [10329, '40000'], [10345, $average],
            [10354, '1.3.2025'], [10477, '43000'], [10370, '3053'], [10481, '10664'], [10490, 'N'], [10546, 'N'],
            [10240, '1'], [10241, '1.1.2026'], [10242, '31.1.2026'], [10356, '31'], [10245, '43000'], [10357, '0'],
            [10535, '43000'],
        ];
        $petr = static fn (string $type): array => [
            [1, 'bezPriznaku'], [10012, $g['petr']], [10016, $type], [10495, 'A'],
            [10051, '1234567890'], [10228, self::PETR_ID_PPV], [10053, 'Zkušební'], [10054, 'Petr'],
            [10056, '20.11.1985'], [10223, '1.1.2026'], [10239, 'P'],
            [10229, 'Ostrava'], [10230, '554821'], [10231, 'CZ'], [10232, 'N'], [10247, 'N'], [10251, 'N'],
            [10286, '5000'], [10307, '5000'], [10309, '750'], [10419, 'N'], [10344, '5000'],
            [10328, '5000'], [10345, self::PETR_REPORT_AVERAGE], [10535, '5000'],
        ];
        $item = static fn (int $id, int $mh, int $relation, int $person, array $attributes): string => '<MHitems>'
            . "<ID>{$id}</ID><RefAg>{$mh}</RefAg><RefZAM>{$person}</RefZAM><RefPomer>{$relation}</RefPomer><RelTyp>1</RelTyp>"
            . self::attributes($attributes) . '</MHitems>';

        return $header(1, 1, 1, 0, true, '2026-02-15T09:00:00', $g['jan'])
            . $item(1, 1, 1, 1, $jana('R', '40.00', '250.00')) . $item(2, 1, 2, 2, $petr('R'))
            . $header(2, 2, 1, 0, true, '2026-03-16T09:00:00', $g['feb'])
            . $item(3, 2, 1, 1, $jana('R', '40.00', '250.00')) . $item(4, 2, 2, 2, $petr('R'))
            // Opravné podání za únor (RefID = řádné): platí místo řádného.
            . $header(3, 2, 2, 2, true, '2026-03-20T10:30:00', $g['feb_o'])
            . $item(5, 3, 1, 1, $jana('O', '37.50', '255.00'))
            // Březen PAMICA připravila, ale neodeslala.
            . $header(4, 3, 1, 0, false, '2026-04-14T08:00:00', $g['mar'])
            . $item(6, 4, 1, 1, $jana('R', '40.00', '255.00'));
    }

    /**
     * Společné atributy Janiny věty registrace (bez CZ-ISCO): akce a okres, osoba, zaměstnavatel
     * a vztah. Osobu najde import podle OIČ a ID PPV, rodné číslo Jana nemá.
     *
     * @return list<array{0:int,1:string}>
     */
    private static function janaSentence(): array
    {
        return [[10004, '110'], [10051, self::JANA_OIC], [10053, 'Testovací'], [10054, 'Jana'], [10056, '4.5.1990'], [10059, 'Ž'],
            [10067, 'CZ'], [10120, 'Syntetická firma'], [10221, '1234567890'], [10227, '1.3.2025']];
    }

    /**
     * Atributový sloupec tak, jak ho zapíše export PAMICA.
     *
     * @param list<array{0:int,1:string,2?:int,3?:int}> $attributes [ID, hodnota, příznak, pořadí]
     */
    private static function attributes(array $attributes, string $column = 'Data'): string
    {
        $out = "<{$column} v=\"1\">";
        foreach ($attributes as $attribute) {
            $flag = $attribute[2] ?? 1;
            $order = $attribute[3] ?? 0;
            $out .= '<a id="' . $attribute[0] . '" t="0" f="' . $flag . '"' . ($order !== 0 ? ' i="' . $order . '"' : '') . '>'
                . htmlspecialchars($attribute[1], ENT_XML1) . '</a>';
        }

        return $out . "</{$column}>";
    }
}
