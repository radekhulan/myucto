<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Pohoda;

/**
 * Syntetické mzdy vedené přímo v POHODĚ (agenda Mzdy, `91_mzdy.xml` z datového souboru
 * POHODY): bez pracovních poměrů (`ZAMpomer`) a bez položek mezd, částky a hodiny jsou
 * sloupce mzdy (`MZ`), doby nepřítomnosti karta zaměstnance (`ZAMpDov`). Fiktivní osoby,
 * žádná reálná data.
 *
 * Leden a únor 2026:
 *  - Alena (P001): pracovní poměr 40 h, měsíční mzda 40 000 Kč, průměr 250 Kč/h, podepsané
 *    prohlášení se slevou na poplatníka a na první dítě, stravenkový paušál. V lednu svátek
 *    1. 1. (náhrada z průměru), v únoru dovolená 9. a 10. 2.,
 *  - Bohumil (P002): pracovní poměr 20 h (4 h denně), měsíční mzda 30 000 Kč, průměr 200 Kč/h,
 *    sleva na poplatníka. V lednu svátek, v únoru pracovní neschopnost 16. až 27. 2.
 *    s náhradou mzdy (`MZnahr`).
 *
 * Mzdy jsou spočítané tak, jak je počítá POHODA: měsíční mzda krácená podílem pracovních
 * dnů bez svátků, svátek a dovolená náhradou z průměru, čistá mzda a výplata ze sloupců.
 */
final class SyntheticPohodaMzdy
{
    public const ICO = '12345678';
    public const YEAR = 2026;

    /** Hrubá mzda po měsících (součet obou zaměstnanců). */
    public const GROSS = ['2026-01' => 40182.0 + 29436.0, '2026-02' => 40000.0 + 15000.0];

    /** Výplata po měsících (čistá mzda, náhrada mzdy při nemoci a stravenkový paušál). */
    public const PAYOUT = ['2026-01' => 36046.5 + 24166.0, '2026-02' => 35528.0 + 15276.0];

    /** Zapíše složku `<IČO>_<rok>` s `91_mzdy.xml` do `$root` a vrátí cestu k souboru. */
    public static function write(string $root): string
    {
        $dir = rtrim($root, '/\\') . '/' . self::ICO . '_' . self::YEAR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $x = '';
        $row = static function (string $table, array $cols) use (&$x): void {
            $x .= "\n  <{$table}>";
            foreach ($cols as $k => $v) {
                $x .= "<{$k}>" . htmlspecialchars((string) $v, ENT_XML1) . "</{$k}>";
            }
            $x .= "</{$table}>";
        };
        $row('sMzPoj', ['ID' => 1, 'IDS' => 'VZP', 'Kod' => '111']);
        $row('sSTR', ['ID' => 1, 'IDS' => 'ADM', 'SText' => 'Administrativa']);
        $row('ZAM', ['ID' => 11, 'OsCislo' => 'P001', 'Jmeno' => 'Alena', 'Prijmeni' => 'Testovací', 'DatNar' => '1990-05-04',
            'RefPoj' => 1, 'RefStr' => 1, 'Ulice' => 'Zkušební', 'CP' => '12', 'Obec' => 'Brno', 'PSC' => '60200', 'Stat' => 'CZ',
            'DatNast' => '2024-03-01', 'DatVstup' => '2024-03-01', 'RelDruhZ' => 0, 'RelDruhM' => 1, 'TUvazek' => 40, 'DUvazek' => 8,
            'KcSzM' => 40000, 'ProfeseISCO' => '43111', 'DovNarHod' => 160, 'DovStaraHod' => 24, 'DovKracHod' => 0, 'DovCerpHod' => 16]);
        $row('ZAM', ['ID' => 12, 'OsCislo' => 'P002', 'Jmeno' => 'Bohumil', 'Prijmeni' => 'Zkušební', 'DatNar' => '1985-11-20',
            'RefPoj' => 1, 'Ulice' => 'Pokusná', 'CP' => '3', 'Obec' => 'Ostrava', 'PSC' => '70200', 'Stat' => 'CZ',
            'DatNast' => '2025-09-01', 'DatVstup' => '2025-09-01', 'RelDruhZ' => 0, 'RelDruhM' => 1, 'TUvazek' => 20, 'DUvazek' => 4,
            'KcSzM' => 30000, 'DovNarHod' => 80, 'DovStaraHod' => 0, 'DovKracHod' => 0, 'DovCerpHod' => 0]);
        // Slevy a zvýhodnění na kartě: 36 sleva na poplatníka, 34 první dítě.
        $row('ZAMpDet', ['ID' => 1, 'RefAg' => 11, 'RelOdpoc' => 36, 'KcOdec' => 30840, 'DatOd' => '2024-03-01']);
        $row('ZAMpDet', ['ID' => 2, 'RefAg' => 11, 'RelOdpoc' => 34, 'KcOdec' => 15204, 'DatOd' => '2024-03-01',
            'Jmeno' => 'Tereza', 'Prijmeni' => 'Testovací', 'RodCisl' => '1501010005']);
        $row('ZAMpDet', ['ID' => 3, 'RefAg' => 12, 'RelOdpoc' => 36, 'KcOdec' => 30840, 'DatOd' => '2025-09-01']);
        // Doby na kartě: 35 dovolená, 4 pracovní neschopnost.
        $row('ZAMpDov', ['ID' => 501, 'RefAg' => 11, 'DatZac' => '2026-02-09', 'DatKon' => '2026-02-10', 'Dnu' => 2, 'RelDrDov' => 35]);
        $row('ZAMpDov', ['ID' => 502, 'RefAg' => 12, 'DatZac' => '2026-02-16', 'DatKon' => '2026-02-27', 'Dnu' => 10, 'RelDrDov' => 4]);
        $row('ZAMzp', ['ID' => 1, 'RefAg' => 11, 'RelKod' => 1, 'RefPoj' => 1, 'RefStav' => 1, 'DatStav' => '2024-03-04', 'Datum' => '2024-03-01']);

        $payslip = static function (array $m) use ($row): void {
            $gross = $m['KcZaklM'] + $m['KcDov'] + $m['KcSvatN'];
            // Záloha 15 % ze základu zaokrouhleného na stovky nahoru (celé haléře bez plovoucí čárky).
            $tax = (int) ceil($gross / 100) * 15;
            $afterTaxpayer = max(0, $tax - $m['KcNzdZak']);
            $advance = max(0, $afterTaxpayer - $m['KcNzdDet']);
            $net = $gross - $m['KcSoc'] - $m['KcZdr'] - $advance;
            $row('MZ', $m + [
                'Rok' => self::YEAR, 'RefPoj' => 1, 'RelDruhM' => 1, 'RelDruhZ' => 0, 'JeSocZAM' => 1, 'JeZdrZAM' => 1, 'BezSoc' => 0,
                'BezZdr' => 0, 'ZvlSazD' => 0, 'StanovenyFond' => ($m['DnyPrac'] + $m['DnyStSv']) * 8,
                'KcHrubaM' => $gross, 'KcSocZak' => $gross, 'KcZdrZak' => $gross, 'KcZdaM' => $gross,
                'KcDanPrS' => $tax, 'KcDanZal' => $afterTaxpayer, 'KcDanBon' => 0, 'KcZalDan' => $advance, 'KcCistaM' => $net,
                'KcVyuct' => round($net + $m['KcNahrNem'] + $m['KcStrav'], 2),
                'Datum' => sprintf('%04d-%02d-10', self::YEAR, (int) $m['RelMes'] + 1),
            ]);
        };
        // Leden: 21 pracovních dnů a svátek 1. 1.
        $payslip(['ID' => 101, 'RefZAM' => 11, 'RelMes' => 1, 'TUvazek' => 40, 'DUvazek' => 8, 'Prohlas' => 1, 'KcSzM' => 40000, 'KcPrum' => 250,
            'DnyKal' => 31, 'DnyPrac' => 21, 'DnyStSv' => 1, 'DnySvat' => 1, 'DnyOdpra' => 21, 'HodOdpra' => 168,
            'KcZaklM' => 38182, 'KcSvatN' => 2000, 'KcDov' => 0, 'HodDov' => 0, 'Nemoc' => 0, 'HodNem' => 0, 'KcNahrNem' => 0,
            'DnyStrav' => 21, 'KcStrav' => 2719.5, 'KcSoc' => 2853, 'KcSocL' => 9965.14, 'KcZdr' => 1809, 'KcZdrL' => 3617,
            'KcNzdZak' => 2570, 'KcNzdDet' => 1267]);
        $payslip(['ID' => 102, 'RefZAM' => 12, 'RelMes' => 1, 'TUvazek' => 20, 'DUvazek' => 4, 'Prohlas' => 1, 'KcSzM' => 30000, 'KcPrum' => 200,
            'DnyKal' => 31, 'DnyPrac' => 21, 'DnyStSv' => 1, 'DnySvat' => 1, 'DnyOdpra' => 21, 'HodOdpra' => 84,
            'KcZaklM' => 28636, 'KcSvatN' => 800, 'KcDov' => 0, 'HodDov' => 0, 'Nemoc' => 0, 'HodNem' => 0, 'KcNahrNem' => 0,
            'DnyStrav' => 0, 'KcStrav' => 0, 'KcSoc' => 2090, 'KcSocL' => 7300.13, 'KcZdr' => 1325, 'KcZdrL' => 2650,
            'KcNzdZak' => 2570, 'KcNzdDet' => 0]);
        // Únor: 20 pracovních dnů; Alena 2 dny dovolené, Bohumil 10 dnů nemoci.
        $payslip(['ID' => 103, 'RefZAM' => 11, 'RelMes' => 2, 'TUvazek' => 40, 'DUvazek' => 8, 'Prohlas' => 1, 'KcSzM' => 40000, 'KcPrum' => 250,
            'DnyKal' => 28, 'DnyPrac' => 20, 'DnyStSv' => 0, 'DnySvat' => 0, 'DnyOdpra' => 18, 'HodOdpra' => 144,
            'KcZaklM' => 36000, 'KcSvatN' => 0, 'KcDov' => 4000, 'HodDov' => 16, 'Nemoc' => 0, 'HodNem' => 0, 'KcNahrNem' => 0,
            'DnyStrav' => 18, 'KcStrav' => 2331, 'KcSoc' => 2840, 'KcSocL' => 9920, 'KcZdr' => 1800, 'KcZdrL' => 3600,
            'KcNzdZak' => 2570, 'KcNzdDet' => 1267]);
        $payslip(['ID' => 104, 'RefZAM' => 12, 'RelMes' => 2, 'TUvazek' => 20, 'DUvazek' => 4, 'Prohlas' => 1, 'KcSzM' => 30000, 'KcPrum' => 200,
            'DnyKal' => 28, 'DnyPrac' => 20, 'DnyStSv' => 0, 'DnySvat' => 0, 'DnyOdpra' => 10, 'HodOdpra' => 40,
            'KcZaklM' => 15000, 'KcSvatN' => 0, 'KcDov' => 0, 'HodDov' => 0, 'Nemoc' => 10, 'HodNem' => 40, 'NemKal' => 12, 'KcNahrNem' => 2016,
            'DnyStrav' => 0, 'KcStrav' => 0, 'KcSoc' => 1065, 'KcSocL' => 3720, 'KcZdr' => 675, 'KcZdrL' => 1350,
            'KcNzdZak' => 2570, 'KcNzdDet' => 0]);
        $row('MZnahr', ['ID' => 1, 'RefAg' => 104, 'RefZAMpDov' => 502, 'DatZac' => '2026-02-16', 'DatKon' => '2026-02-27', 'Kc' => 2016]);
        // Zaúčtování mezd: POHODA tu nevede účty, jen den zaúčtování.
        $row('MZzauct', ['ID' => 1, 'Rok' => self::YEAR, 'RelMes' => 1, 'KcDanUpS' => 0, 'KcDanUpZ' => 0]);

        $file = $dir . '/91_mzdy.xml';
        file_put_contents($file, '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="' . self::ICO . '" year="' . self::YEAR . '" source="POHODA" state="ok" created="2026-03-05T10:00:00">'
            . $x . "\n</mdbExport>\n");

        return $file;
    }
}
