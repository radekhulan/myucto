<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\StereoNx;

/** Jen syntetické řádky pro převod mezd a ZIP integrační testy. */
final class SyntheticStereoNxPayrollTables
{
    public static function identity(): array
    {
        return ['ico' => '12345679'];
    }

    public static function tables(): array
    {
        return [
            'MZAMEST' => [[
                'Prac' => 'SYN-1', 'PracPravVztah' => 'P', 'Odvod' => 'HPP',
                'Zamestnanec' => true, 'StatOrg' => false, 'DatumNastupu' => '2020-01-01',
                'DatumUkonceni' => null, 'ZakPoj' => true, 'ZamMR' => false,
                'ZPS' => 'N', 'MinZamestnavatel' => '', 'PracRezim' => '',
                'DruhD' => '', 'OZP' => false, 'Duchod' => 0.0,
                'DuchodDruh' => '0', 'DuchodPredcasny' => false,
                'DuchodSnizVek' => false, 'HlavniPPV' => true,
                'DruhCinnosti' => '', 'ELDPKod' => '', 'StatVD' => false,
            ]],
            'MMzdy' => [array_replace(array_fill_keys([
                'PPSKlicMzdy', 'PPSHrubaMzda', 'PPSZaklZP', 'PPSZPZa', 'PPSZaklSP', 'PPSSPZa',
                'PPZa', 'PPPod', 'ZiPZa', 'ZiPPod', 'PPZiPZvys', 'NVDny', 'NVZa', 'DSZa',
                'Zaloha', 'Davky', 'RocniVyuct', 'RocniVyuctPrepl', 'RocniVyuctDanBon',
                'Neodpr', 'ZdanitPrijem', 'Nahr4Nahr', 'Nahr4Neodpr', 'DovolenaD',
                'DovolenaH', 'OmluvAbsence', 'OmluvAbsencePr', 'VylouceneDobyPr',
                'NeodprPracH', 'VlivNaOdprH', 'StravPNaD', 'StravPNeD',
            ], 0), [
                'Klic' => 7, 'Prac' => 'SYN-1', 'Rok' => 2026, 'Mesic' => 1,
                'MzOb' => '2026-01-01',
                'Odvod' => 'HPP', 'TypDan' => 'Z', 'Prohlaseni' => true,
                'JenDP' => false, 'DS' => false, 'NVOdvZa' => false,
                'HrubaMzda' => 10000, 'CistaMzda' => 8440, 'ZaklSP' => 10000,
                'ZaklZP' => 10000, 'SPZa' => 710, 'ZPZa' => 450, 'Dan' => 400,
                'Srazky' => 100, 'StravPO' => 50, 'Dobirka' => 8390,
                'OdpracovaneD' => 20.5, 'OdpracovaneH' => 164,
                'NeprKalDny' => 0, 'VylouceneDoby' => 0, 'NemocD' => 0,
                'NeodprPracDny' => 0, 'PPPoj' => '', 'ZiPPoj' => '',
                'ZdanitPrijemT' => '',
            ])],
            'Gparrok' => [[
                'DatumOd' => '2025-01-01', 'SOCPodnikatel' => 24.8,
                'SOCPojistneP' => 7.1, 'ZDRPojistneP' => 4.5,
                'ZDRPodnikatel' => 9,
            ]],
            'MOdvPar' => [[
                'DatumOd' => '1997-01-01', 'Odvod' => 'HPP',
                'SocPoj' => 'P', 'ZdrPoj' => 'M', 'ZamMR' => false,
                'SPTyp' => '',
            ]],
        ];
    }

}
