<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Pohoda;

/**
 * Syntetický export PAMICA (`91_mzdy.xml`) se mzdami za leden a únor 2025 i 2026, na kterém
 * jde převést starší rok až po novějším (doplnění rozhodného období) i obráceně. Fiktivní
 * osoby, žádná reálná data.
 *
 *  - Alena (2001): pracovní poměr od roku 2024, který trvá, a souběžná DPP jen v roce 2025
 *    (osobní číslo `2001-2`). V roce 2026 má mzdu jen z pracovního poměru, takže převod
 *    roku 2026 o DPP neví a převod roku 2025 ji musí založit jako DALŠÍ vztah téže osoby.
 *  - Bohumil (2002): v roce 2026 už není, v roce 2025 má pracovní poměr i DPP (`2002-2`),
 *    obojí skončené 30. 6. 2025. Osoba se při převodu 2025 zakládá celá znovu a druhý vztah
 *    nesmí narazit na „stejné rodné číslo už evidujete". V únoru 2026, po skončení, mu PAMICA
 *    zúčtovala doplatek: ani ten nesmí založit druhou osobu.
 *  - Alena má navíc DPP od února 2026 bez výplaty, která trvá (`2001-3`): patří do roku 2026.
 *    Bohumilova dohoda z roku 2019 bez mzdy v převáděných letech se nezakládá.
 *
 * Osobní ohodnocení (`O01`) a časová mzda (`C01`) jsou složky, které ve firmě nejsou a převod
 * je zakládá. Osobní ohodnocení má Alena v obou letech, takže při převodu staršího roku už
 * složka má verzi od 1. 1. novějšího roku.
 */
final class SyntheticPohodaPayrollTwoYears
{
    public const ICO = '12345678';
    public const ALENA_HPP = '2001';
    public const ALENA_DPP = '2001-2';
    public const BOHUMIL_HPP = '2002';
    public const BOHUMIL_DPP = '2002-2';
    public const BOHUMIL_END = '2025-06-30';
    public const ALENA_DPP_START = '2025-01-01';
    public const ALENA_DPP_END = '2025-12-31';
    /** Třetí vztah Aleny: DPP od února 2026, která trvá a výplatu ještě nemá. */
    public const ALENA_OPEN = '2001-3';
    public const ALENA_OPEN_START = '2026-02-01';
    /** Kódy složek, které převod zakládá ({@see \MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollCatalog::component()}). */
    public const BONUS_CODE = 'PAM_O01';
    public const HOURLY_CODE = 'PAM_C01';
    /** Důvod slevy zaměstnavatele Aleny (§ 7a odst. 1 písm. b). */
    public const ALENA_DISCOUNT_REASON = 'child_care_under_10';

    public static function write(string $root): string
    {
        $dir = rtrim($root, '/\\') . '/' . self::ICO . '_2025_2026';
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
        foreach ([[1, 'M01', 'Základní mzda měsíční'], [2, 'O01', 'Osobní ohodnocení'], [3, 'C01', 'Časová mzda']] as [$id, $cislo, $nazev]) {
            $row('sMZslozky', ['ID' => $id, 'Cislo' => $cislo, 'Nazev' => $nazev]);
        }
        $row('sMzPoj', ['ID' => 1, 'IDS' => 'VZP', 'Kod' => '111']);
        $row('ZAM', ['ID' => 1, 'OsCislo' => self::ALENA_HPP, 'Jmeno' => 'Alena', 'Prijmeni' => 'Vzorová', 'DatNar' => '1990-05-04',
            'RodCisl' => '9055040104', 'StatPris' => 'CZ', 'Nerezident' => 0, 'RefPoj' => 1, 'Ulice' => 'Zkušební', 'CP' => '1',
            'Obec' => 'Brno', 'PSC' => '60200', 'Stat' => 'CZ']);
        $row('ZAM', ['ID' => 2, 'OsCislo' => self::BOHUMIL_HPP, 'Jmeno' => 'Bohumil', 'Prijmeni' => 'Ukázkový', 'DatNar' => '1985-03-15',
            'RodCisl' => '8503150106', 'StatPris' => 'CZ', 'Nerezident' => 0, 'RefPoj' => 1, 'Ulice' => 'Pokusná', 'CP' => '2',
            'Obec' => 'Brno', 'PSC' => '60200', 'Stat' => 'CZ']);
        $row('ZAMpomer', ['ID' => 1, 'RefZAM' => 1, 'Poradi' => 1, 'JeDPP' => 0, 'DatNast' => '2024-01-01', 'TUvazek' => 40]);
        $row('ZAMpomer', ['ID' => 2, 'RefZAM' => 1, 'Poradi' => 2, 'JeDPP' => 1, 'DatNast' => self::ALENA_DPP_START, 'DatOdch' => self::ALENA_DPP_END]);
        $row('ZAMpomer', ['ID' => 3, 'RefZAM' => 2, 'Poradi' => 1, 'JeDPP' => 0, 'DatNast' => '2025-01-01', 'DatOdch' => self::BOHUMIL_END, 'TUvazek' => 40]);
        $row('ZAMpomer', ['ID' => 4, 'RefZAM' => 2, 'Poradi' => 2, 'JeDPP' => 1, 'DatNast' => '2025-01-01', 'DatOdch' => self::BOHUMIL_END]);
        // Trvající DPP Aleny bez výplaty (nástup 2026) a dávno skončená dohoda Bohumila bez mzdy.
        $row('ZAMpomer', ['ID' => 5, 'RefZAM' => 1, 'Poradi' => 3, 'JeDPP' => 1, 'DatNast' => self::ALENA_OPEN_START]);
        $row('ZAMpomer', ['ID' => 6, 'RefZAM' => 2, 'Poradi' => 3, 'JeDPP' => 1, 'DatNast' => '2019-01-01', 'DatOdch' => '2019-06-30']);
        // Sleva zaměstnavatele na pojistném (§ 7a): Alena z důvodu b) péče o dítě, Bohumil s důvodem a) věk nad 55 let,
        // který jeho věk vylučuje (chyba dat předchozího programu). Obojí PAMICA přiznala (SocPojSlevaNarok).
        $row('SocPojSleva', ['ID' => 1, 'RefPomer' => 1, 'RelDuvod' => 2, 'DatumOd' => '2024-01-01']);
        $row('SocPojSleva', ['ID' => 2, 'RefPomer' => 3, 'RelDuvod' => 1, 'DatumOd' => '2025-01-01']);

        $id = 0;
        $item = 0;
        // [osoba, vztah, roky]: pracovní poměr Aleny oba roky, ostatní vztahy jen 2025.
        foreach ([[1, 1, [2025, 2026]], [1, 2, [2025]], [2, 3, [2025]], [2, 4, [2025]]] as [$person, $relation, $years]) {
            $dpp = in_array($relation, [2, 4], true);
            foreach ($years as $year) {
                foreach ([1, 2] as $month) {
                    $id++;
                    $gross = $dpp ? 4000 : 32000;
                    $row('MZ', ['ID' => $id, 'RefZAM' => $person, 'RefPomer' => $relation, 'Rok' => $year, 'RelMes' => $month,
                        'HodFond' => $dpp ? 0 : 160, 'DnyFond2' => $dpp ? 0 : 20, 'TUvazek' => $dpp ? 0 : 40, 'HodOdpra' => $dpp ? 20 : 160,
                        'RefPoj' => 1, 'KcHrubaM' => $gross, 'KcCistaM' => $dpp ? 3400 : 25000, 'Prohlas' => $dpp ? 0 : 1,
                        'JeSocPP' => $dpp ? 0 : 1, 'KcZaklM' => $dpp ? 0 : 30000, 'DnyPrac' => 20, 'DnyOdpra' => 20, 'KcPrum' => 190,
                        'Datum' => sprintf('%04d-%02d-10', $year, $month + 1), 'KcVyplat' => $dpp ? 3400 : 25000,
                        'SocPojSlevaZadost' => $dpp ? 0 : 1, 'SocPojSlevaNarok' => $dpp ? 0 : 1]);
                    if ($dpp) {
                        $row('MZslozky', ['ID' => ++$item, 'RefAg' => $id, 'RefSlozka' => 3, 'KcMzda' => 4000, 'PocHodin' => 20]);
                        continue;
                    }
                    $row('MZslozky', ['ID' => ++$item, 'RefAg' => $id, 'RefSlozka' => 1, 'KcMzda' => 30000, 'Hodnota1' => 30000]);
                    $row('MZslozky', ['ID' => ++$item, 'RefAg' => $id, 'RefSlozka' => 2, 'KcMzda' => 2000]);
                }
            }
        }

        // Doplatek Bohumilovi v únoru 2026, po skončení vztahu (PAMICA ho zúčtovala k vztahu).
        $row('MZ', ['ID' => ++$id, 'RefZAM' => 2, 'RefPomer' => 3, 'Rok' => 2026, 'RelMes' => 2, 'HodFond' => 0, 'HodOdpra' => 0,
            'RefPoj' => 1, 'KcHrubaM' => 2000, 'KcCistaM' => 1600, 'Prohlas' => 1, 'JeSocPP' => 1, 'Datum' => '2026-03-10', 'KcVyplat' => 1600]);
        $row('MZslozky', ['ID' => ++$item, 'RefAg' => $id, 'RefSlozka' => 2, 'KcMzda' => 2000]);

        $file = $dir . '/91_mzdy.xml';
        file_put_contents($file, '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="' . self::ICO . '" year="2026" source="POHODA" state="ok">' . $x . '</mdbExport>');
        return $file;
    }
}
