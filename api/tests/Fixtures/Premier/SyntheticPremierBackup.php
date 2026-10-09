<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Premier;

/**
 * Syntetická záloha dat PREMIER (databáze Visual FoxPro) pro testy převodu. Fiktivní
 * firma, fiktivní partneři, žádná reálná data.
 *
 * Dva účetní roky - počáteční stavy roku 2026 se dopočtou z deníku roku 2025:
 *
 *   2025  vklad do pokladny 5 000 a splacení ZK 200 000 na banku (211/221 proti 411);
 *         vydaná faktura VF 250001 10 000 + 21 % uhrazená bankou (vazba VAZBY);
 *         přijatá tuzemská PF 250001 1 000 + 21 % uhrazená bankou (vazba opačným směrem);
 *         přijatá služba z EU PF 250002 5 000 (kód 45 = ř. 5 + 43, přenesení) zaúčtovaná
 *         JEN řádkem základu, bez samovyměření na 343 - uhrazená až v roce 2026;
 *         táž služba PF 250003 3 000, kde účetní samovyměření zaúčtovala MD 343 / D 343
 *         (řádek je v deníku PŘED řádkem základu);
 *         dobropis VF 250002 -1 000 + 21 % se zápornými částkami na stejných stranách, vrácený bankou;
 *         přijatá faktura v EUR PF 250004 (položky v EUR, deník v Kč s haléřovým rozdílem);
 *         pokladní výdej PV 1 500 + 21 % (kód 15 → řádek DPH 40);
 *         bankovní poplatek bez dokladu.
 *   2026  úhrada PF 250002, vydaná VF 260001 20 000 + 21 % uhrazená bankou, přijatá
 *         PF 260001 2 000 + 21 % neuhrazená.
 *
 * `oss`: navíc vydaná faktura VF 250003 s kódem 60 bez řádků přiznání, který nese daň
 * 23 % - prodej koncovému zákazníkovi na Slovensko (kandidát OSS).
 */
final class SyntheticPremierBackup
{
    public const ICO = '12345679';
    public const DIC = 'CZ12345679';
    public const NAME = 'Fiktivní účetní s.r.o.';
    public const YEAR1 = 2025;
    public const YEAR2 = 2026;
    public const CUSTOMER_ICO = '87654326';
    public const VENDOR_ICO = '11223341';
    public const EU_VENDOR_DIC = 'DE123456789';
    public const BANK_ACCOUNT = '1000000005';
    public const BANK_CODE = '0100';

    public const RC_MONTH = 4;
    public const OSS_DOCUMENT = '250003';
    /** Vydaná faktura v EUR, jejíž koruny v deníku vycházejí přesně kurzem (flag `eur_exact`). */
    public const EUR_DOCUMENT = '250008';
    public const OSS_COUNTRY = 'SK';
    public const OSS_RATE = 23.0;

    /** Kódy DPH (`KODY_DPH`): sloupce R15* jsou starý tiskopis a platit nesmí. */
    public const CODE_SALE = '36';
    public const CODE_PURCHASE = '15';
    public const CODE_EU_SERVICE = '45';
    public const CODE_OUTSIDE = '60';

    /** Zápis tabulek do `$dir` (`.DBF` + `.FPT`). */
    /** @param array<string,bool> $flags viz {@see tables()} */
    public static function writeDir(string $dir, bool $oss = false, array $flags = []): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        foreach (self::tables($oss, $flags) as $name => [$fields, $rows]) {
            DbfWriter::write($dir . DIRECTORY_SEPARATOR . $name . '.DBF', $fields, $rows);
        }
        return $dir;
    }

    /**
     * Soubory zálohy jako jméno => obsah (pro sestavení vlastního archivu v testu).
     *
     * @param array<string,bool> $flags
     * @return array<string,string>
     */
    public static function files(string $tmp, bool $oss = false, array $flags = []): array
    {
        $dir = $tmp . DIRECTORY_SEPARATOR . 'premier_src_' . bin2hex(random_bytes(4));
        self::writeDir($dir, $oss, $flags);
        $out = [];
        foreach (scandir($dir) ?: [] as $f) {
            if (is_file($dir . DIRECTORY_SEPARATOR . $f)) {
                $out[$f] = (string) file_get_contents($dir . DIRECTORY_SEPARATOR . $f);
                unlink($dir . DIRECTORY_SEPARATOR . $f);
            }
        }
        rmdir($dir);
        ksort($out);
        return $out;
    }

    /** Záloha iZIP: tabulky malými písmeny ve složce, jak je PREMIER balí. */
    /** @param array<string,bool> $flags */
    public static function writeZip(string $path, string $tmp, bool $oss = false, array $flags = []): string
    {
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach (self::files($tmp, $oss, $flags) as $name => $content) {
            $zip->addFromString('DATA/' . strtolower($name), $content);
        }
        $zip->addFromString('DATA/zaloha.txt', 'Záloha dat PREMIER');
        $zip->close();
        return $path;
    }

    /** Záloha iCAB (jedna složka, MSZIP). */
    /** @param array<string,bool> $flags */
    public static function writeCab(string $path, string $tmp, bool $oss = false, array $flags = []): string
    {
        $files = [];
        foreach (self::files($tmp, $oss, $flags) as $name => $content) {
            $files['DATA\\' . strtolower($name)] = $content;
        }
        $files['DATA\\zaloha.txt'] = 'Záloha dat PREMIER';
        CabWriter::write($path, $files);
        return $path;
    }

    /**
     * Volitelné části zálohy (`$flags`), výchozí záloha je beze změny:
     *
     *   `small_assets`          účet 501200 „Spotřeba materiálu - dr. majetek" a 314000; přijaté
     *                           PF 250005 (Notebook 15 000 + Myš 500 na 501200), dobropis PF 250006
     *                           vracející Notebook a konečná PF 250007 (Monitor 20 000 na 501200,
     *                           odpočet zálohy -5 000 na 314000)
     *   `unmatched_return`      s `small_assets`: dobropis vrací „Tiskárnu", kterou nikdo nekoupil
     *   `small_asset_evidence` vlastní evidence drobného majetku: `MAJ_OST` (3 řádky) a karta
     *                           `MAJETEK` v řadě DH
     *   `other_register`        karta `MAJETEK` v řadě LEA (leasing)
     *   `maj_h`                 dlouhodobá karta `MAJ_H` (řada HM) s daňovými odpisy `MAJ_H_OD`,
     *                           plánem `MAJ_H_OU` a pohyby `MAJ_H_PO`
     *   `maj_h_unbooked`        s `maj_h`: pohyby bez „zaúčtován odpis" - převod neví, že odpisy
     *                           2025 jsou v deníku, a uzávěrka by je účtovala znovu
     *   `maj_h_disposed`        s `maj_h`: server vyřazený 31. 12. 2025, deník nese zůstatkovou cenu
     *                           100 000 (541/082) i vyřazení z evidence (082/022)
     *   `dppo`                  podané přiznání k DPPO za 2025 (`D_PO1` + `D_PO2` bez úprav)
     *   `periody` / `periody_11` zamčení období 2025 (`PERIODY`): všech 12 / jen 11 měsíců
     *   `payroll`               zaměstnanci a mzdy (`PERSONAL`, `PER_MAIN`, `PERSON2`, `MZDY`, `MZDY_POL`…)
     *                           se zaúčtováním v deníku, viz {@see payroll()}
     *   `payroll_mismatch`      s `payroll`: jeden měsíc deníku nesedí na mzdy
     *   `payroll_detail`        s `payroll`: další vztahy a evidence mzdového modulu, viz {@see payrollDetail()}
     *   `payroll_nonresident`   s `payroll_detail`: osoba DPP (INTER 6) je daňový nerezident s kartou
     *                           `PER_NERZ` (stát rezidence SK); karta rezidenta (CZ) u INTER 5
     *   `payroll_benefits`      s `payroll_detail`: trvalé příjmy INTER 5 na kartě `MZ_SRAZ` (penzijní
     *                           připojištění 422, stravenkový paušál 712 za směnu, praní 862) a příspěvek
     *                           na penzijní připojištění v `MZDY.MZ_PENZ` všech měsíců, viz {@see benefits()}
     *   `bank_split`           výpis BV 8 z 15. 10. 2025 se čtyřmi pohyby a dvěma řádky bez pohybu: výběr hotovosti,
     *                           úhrada VF 250005 (1 209,60, VS jen ve `VAR_DAL`, údaje homebankingu
     *                           `H*`/`PARTRAN`) s haléřovým vyrovnáním 548/311 0,40 (vazba na tutéž
     *                           fakturu), úhrada VF 250006, poplatek a kurzový zisk 311/663 bez vazby;
     *                           VF 250007 uhrazená zápočtem 321/311 (ID 5); EUR účet (řada BE, 221002)
     *                           s vkladem 1 000 EUR a kurzovým přeceněním s částkou v měně 0
     *   `reduced_deduction`     tuzemský kód odpočtu je krácený (§ 76, `IS_KRACENY`)
     *   `oss_eur`               s `$oss`: VF 250003 v EUR (40 EUR + 9,20 EUR daň, kurz 25 = deník 1 000 + 230 Kč)
     *   `eur_exact`             VF 250008 v EUR (100 + 21 a 40 + 8,40 EUR, kurz 25,12 = deník 3 516,80 + 738,53 Kč)
     *   `rc_uncoded_line`       služba z EU PF 250002 má navíc položku 200 Kč bez kódu DPH (poplatek mimo přiznání)
     *   `cash_duplicate`        další dva pokladní doklady PP 1 z 2. 1. 2025 (jiný sborník), tedy tři
     *                           doklady se stejnou řadou i číslem a dva i se stejným datem
     *
     * @param array<string,bool> $flags
     * @return array<string,array{0:list<array{0:string,1:string,2?:int,3?:int}>,1:list<array<string,mixed>>}>
     */
    public static function tables(bool $oss = false, array $flags = []): array
    {
        $tables = self::baseTables($oss);
        if ($flags !== []) {
            self::applyFlags($tables, $flags);
        }
        return $tables;
    }

    /** @return array<string,array{0:list<array{0:string,1:string,2?:int,3?:int}>,1:list<array<string,mixed>>}> */
    private static function baseTables(bool $oss): array
    {
        return [
            'SET_GLOB' => [
                [['PROMEN', 'C', 20], ['C_SET', 'C', 100], ['N_SET', 'N', 15, 2], ['D_SET', 'D'], ['L_SET', 'L']],
                [
                    ['PROMEN' => 'aico', 'C_SET' => self::ICO],
                    ['PROMEN' => 'adic', 'C_SET' => 'CZ 12345679'],
                    ['PROMEN' => 'adress(1)', 'C_SET' => self::NAME],
                    ['PROMEN' => 'a_mena', 'C_SET' => 'CZK'],
                    ['PROMEN' => 'a_zacatek', 'D_SET' => '2025-01-01'],
                    ['PROMEN' => 'a_koef', 'N_SET' => 100],
                ],
            ],
            'OSNOVA' => [
                [['UCET', 'C', 3], ['ANALYT', 'C', 3], ['TEXT', 'C', 40], ['ROK', 'N', 4], ['NEDANOVY', 'L']],
                self::chart(),
            ],
            'KODY_DPH' => [
                [['KOD_DPH', 'C', 3], ['TEXT', 'C', 40], ['SAZBA', 'N', 1], ['JINA_SAZBA', 'N', 5, 2], ['FA_IN', 'L'], ['FA_OUT', 'L'],
                    ['IS_REVERS', 'L'], ['IS_KRACENY', 'L'], ['R15', 'N', 3], ['R15B', 'N', 3], ['R17', 'N', 3], ['R17B', 'N', 3], ['R17C', 'N', 3],
                    ['TAB_FA', 'C', 5], ['TAB_FANE', 'C', 5]],
                [
                    ['KOD_DPH' => self::CODE_SALE, 'TEXT' => 'Tuzemské plnění 21 %', 'SAZBA' => 2, 'FA_OUT' => true, 'R15' => 1, 'R17' => 1, 'TAB_FA' => 'A.4.', 'TAB_FANE' => 'A.5.'],
                    ['KOD_DPH' => self::CODE_PURCHASE, 'TEXT' => 'Tuzemský odpočet 21 %', 'SAZBA' => 2, 'FA_IN' => true, 'R15' => 40, 'R17' => 40, 'TAB_FA' => 'B.2.', 'TAB_FANE' => 'B.3.'],
                    ['KOD_DPH' => self::CODE_EU_SERVICE, 'TEXT' => 'Přijetí služby z EU 21 %', 'SAZBA' => 2, 'FA_IN' => true, 'IS_REVERS' => true,
                        'R15' => 99, 'R15B' => 98, 'R17' => 5, 'R17B' => 43, 'TAB_FA' => 'A.2.'],
                    ['KOD_DPH' => self::CODE_OUTSIDE, 'TEXT' => 'Nezahrnovat do přiznání', 'SAZBA' => 0, 'FA_OUT' => true],
                ],
            ],
            'PUB_UCTO' => [
                [['INTER', 'N', 10], ['DATUM', 'D'], ['DATUM_DPH', 'D'], ['DOKLAD', 'C', 5], ['CISLO', 'C', 10], ['POPIS', 'C', 50], ['POZNAMKA', 'M'],
                    ['CASTKA', 'N', 15, 2], ['MD', 'C', 6], ['DAL', 'C', 6], ['KOD_DPH', 'C', 3], ['SAZBA_DPH', 'N', 5, 2], ['CASTKA_DPH', 'N', 15, 2],
                    ['IKOD', 'C', 1], ['SB_KOD', 'C', 5], ['SBORNIK', 'N', 10], ['MENA', 'C', 3], ['ZCASTKA', 'N', 15, 2], ['KURS', 'N', 10, 4], ['M_KURS', 'N', 5],
                    ['VARIABL', 'C', 12], ['CISLO_ODB', 'C', 10], ['NAZEV_ODB', 'C', 50], ['ICO_ODB', 'C', 12], ['DIC_ODB', 'C', 14], ['STAT_ODB', 'C', 30],
                    ['ID_PAR', 'C', 10], ['STKOD', 'N', 5], ['VAR_DAL', 'C', 12], ['HUCET', 'C', 40], ['HKS', 'C', 4], ['HSPEC', 'C', 10],
                    ['HVAR', 'C', 10], ['HZPR_PRIJ', 'C', 140], ['PARTRAN', 'C', 20]],
                self::journal($oss),
            ],
            'PARTNERY' => [
                [['ID', 'C', 10], ['CISLO', 'C', 10], ['NAZEV', 'C', 50], ['ICO', 'C', 12], ['DIC', 'C', 14], ['ULICE', 'C', 40], ['MESTO', 'C', 30],
                    ['PSC', 'C', 6], ['STAT', 'C', 30], ['KOD_ZEME', 'C', 2], ['E_MAIL', 'C', 50], ['TEL', 'C', 20], ['MOBIL', 'C', 20]],
                [
                    ['ID' => 'P0', 'CISLO' => '0', 'NAZEV' => self::NAME, 'ICO' => self::ICO, 'DIC' => self::DIC, 'ULICE' => 'Účetní 1', 'MESTO' => 'Brno', 'PSC' => '60200', 'KOD_ZEME' => 'CZ'],
                    ['ID' => 'P1', 'CISLO' => '1', 'NAZEV' => 'Odběratel Fiktivní s.r.o.', 'ICO' => self::CUSTOMER_ICO, 'DIC' => 'CZ' . self::CUSTOMER_ICO,
                        'ULICE' => 'Zkušební 10', 'MESTO' => 'Praha', 'PSC' => '11000', 'KOD_ZEME' => 'CZ', 'E_MAIL' => 'odberatel@example.invalid'],
                    ['ID' => 'P2', 'CISLO' => '2', 'NAZEV' => 'Dodavatel Fiktivní s.r.o.', 'ICO' => self::VENDOR_ICO, 'DIC' => 'CZ' . self::VENDOR_ICO,
                        'ULICE' => 'Vzorová 5', 'MESTO' => 'Ostrava', 'PSC' => '70200', 'STAT' => 'Česká republika'],
                    ['ID' => 'P3', 'CISLO' => '3', 'NAZEV' => 'Fiktiv Software GmbH', 'DIC' => self::EU_VENDOR_DIC,
                        'ULICE' => 'Musterstraße 1', 'MESTO' => 'Berlin', 'PSC' => '10115', 'STAT' => 'Německo', 'KOD_ZEME' => 'DE'],
                ],
            ],
            'FA_OUT' => [self::headerFields('VS'), self::issued($oss)],
            'POLOZKY' => [self::itemFields(), self::issuedItems($oss)],
            'FA_IN' => [self::headerFields('VARIABL'), self::purchases()],
            'POLOZ_IN' => [self::itemFields(), self::purchaseItems()],
            'DOKLAD' => [
                [['DOKLAD', 'C', 5], ['TEXT', 'C', 40], ['TOK', 'N', 3]],
                [['DOKLAD' => 'VF', 'TEXT' => 'Vydané faktury', 'TOK' => 3], ['DOKLAD' => 'PF', 'TEXT' => 'Přijaté faktury', 'TOK' => 4],
                    ['DOKLAD' => 'ZVF', 'TEXT' => 'Vydané zálohové listy', 'TOK' => 11]],
            ],
            'DOKL_PU' => [
                [['DOKLAD', 'C', 5], ['TOK', 'N', 3], ['MD', 'C', 3], ['MDA', 'C', 3], ['DAL', 'C', 3], ['DALA', 'C', 3], ['CISLO_U', 'C', 20],
                    ['KOD_U', 'C', 4], ['IBAN', 'C', 34], ['MENA', 'C', 3], ['TEXT', 'C', 40], ['NAZEV_B', 'C', 40]],
                [
                    ['DOKLAD' => 'BV', 'TOK' => 2, 'MD' => '221', 'MDA' => '001', 'CISLO_U' => self::BANK_ACCOUNT, 'KOD_U' => self::BANK_CODE,
                        'MENA' => 'CZK', 'TEXT' => 'Bankovní výpisy', 'NAZEV_B' => 'Fiktivní banka'],
                    ['DOKLAD' => 'PP', 'TOK' => 1, 'MD' => '211', 'MDA' => '001', 'TEXT' => 'Pokladna příjem'],
                    ['DOKLAD' => 'PV', 'TOK' => 1, 'DAL' => '211', 'DALA' => '001', 'TEXT' => 'Pokladna výdej'],
                ],
            ],
            'VAZBY' => [
                [['KOD_ZDR', 'C', 3], ['INT_ZDR', 'N', 10], ['KOD_TER', 'C', 3], ['INT_TER', 'N', 10]],
                [
                    ['KOD_ZDR' => 'UD', 'INT_ZDR' => 14, 'KOD_TER' => 'VF', 'INT_TER' => 1],
                    ['KOD_ZDR' => 'PF', 'INT_ZDR' => 101, 'KOD_TER' => 'UD', 'INT_TER' => 17],
                    ['KOD_ZDR' => 'UD', 'INT_ZDR' => 21, 'KOD_TER' => 'PF', 'INT_TER' => 103],
                    ['KOD_ZDR' => 'UD', 'INT_ZDR' => 24, 'KOD_TER' => 'VF', 'INT_TER' => 2],
                    ['KOD_ZDR' => 'UD', 'INT_ZDR' => 27, 'KOD_TER' => 'PF', 'INT_TER' => 104],
                    ['KOD_ZDR' => 'UD', 'INT_ZDR' => 40, 'KOD_TER' => 'PF', 'INT_TER' => 102],
                    ['KOD_ZDR' => 'UD', 'INT_ZDR' => 43, 'KOD_TER' => 'VF', 'INT_TER' => 4],
                ],
            ],
            'D_KHDPH1' => [
                [['ID_CISLO', 'C', 10], ['ROK', 'N', 4], ['MESIC', 'N', 2], ['DNE', 'C', 10], ['FORMA', 'C', 1]],
                [
                    // Řádné KH za únor, pak následné - platí poslední podání.
                    ['ID_CISLO' => 'KH1', 'ROK' => 2025, 'MESIC' => 2, 'DNE' => '20.03.2025', 'FORMA' => 'B'],
                    ['ID_CISLO' => 'KH2', 'ROK' => 2025, 'MESIC' => 2, 'DNE' => '02.04.2025', 'FORMA' => 'N'],
                ],
            ],
            'D_KHDPHP1' => [
                [['ID_CISLO', 'C', 10], ['ODDIL', 'C', 3], ['ZAKL_DANE1', 'N', 15, 2], ['DAN1', 'N', 15, 2], ['ZAKL_DANE2', 'N', 15, 2],
                    ['DAN2', 'N', 15, 2], ['ZAKL_DANE3', 'N', 15, 2], ['DAN3', 'N', 15, 2]],
                [
                    ['ID_CISLO' => 'KH1', 'ODDIL' => 'A4', 'ZAKL_DANE1' => 9000, 'DAN1' => 1890],
                    ['ID_CISLO' => 'KH2', 'ODDIL' => 'A4', 'ZAKL_DANE1' => 10000, 'DAN1' => 2100],
                ],
            ],
        ];
    }

    /**
     * @param array<string,array{0:list<array{0:string,1:string,2?:int,3?:int}>,1:list<array<string,mixed>>}> $tables MĚNÍ SE
     * @param array<string,bool> $flags
     */
    private static function applyFlags(array &$tables, array $flags): void
    {
        $chart = [];
        $cards = [];
        if (!empty($flags['small_assets'])) {
            $chart[] = ['501', '200', 'Spotřeba materiálu - dr. majetek'];
            $chart[] = ['314', '000', 'Poskytnuté zálohy'];
            $vendor = ['CISLO_ODB' => '2', 'NAZEV_ODB' => 'Dodavatel Fiktivní s.r.o.', 'ICO_ODB' => self::VENDOR_ICO, 'DIC_ODB' => 'CZ' . self::VENDOR_ICO, 'ID_PAR' => 'P2'];
            $vendorHeader = $vendor + ['ULICE_ODB' => 'Vzorová 5', 'MESTO_ODB' => 'Ostrava', 'PSC_ODB' => '70200', 'UCET_ODB' => '1000000005/0100', 'FORMA' => 'příkazem'];
            $small = ['UC_S' => '501', 'UC_SA' => '200', 'UC_D' => '321', 'UC_DA' => '000'];
            $vat = static fn (string $kind, int $inter): array => self::vat(self::CODE_PURCHASE, $kind, 'PF', $inter) + $vendor;
            array_push($tables['PUB_UCTO'][1],
                self::row(60, '2025-09-10', 'PF', '250005', 'Notebook a myš', 15500, '501200', '321000', $vat('P', 106)),
                self::row(61, '2025-09-10', 'PF', '250005', 'DPH', 3255, '343021', '321000', $vat('D', 106)),
                // Dobropis vracející notebook: záporné částky na stejné strany.
                self::row(62, '2025-10-01', 'PF', '250006', 'Vrácení notebooku', -15000, '501200', '321000', $vat('P', 107)),
                self::row(63, '2025-10-01', 'PF', '250006', 'DPH', -3150, '343021', '321000', $vat('D', 107)),
                // Konečná faktura s odpočtem zálohy (314).
                self::row(64, '2025-11-05', 'PF', '250007', 'Monitor', 20000, '501200', '321000', $vat('P', 108)),
                self::row(65, '2025-11-05', 'PF', '250007', 'Odpočet zálohy', -5000, '314000', '321000', $vat('P', 108)),
                self::row(66, '2025-11-05', 'PF', '250007', 'DPH', 4200, '343021', '321000', $vat('D', 108)),
                self::row(67, '2025-11-05', 'PF', '250007', 'DPH odpočet zálohy', -1050, '343021', '321000', $vat('D', 108)),
            );
            array_push($tables['FA_IN'][1],
                self::header(106, 'PF', '250005', '2025-09-10', 'Notebook a myš', ['VARIABL' => '9005', 'CISLO_PF' => 'D-2025-9005'] + $vendorHeader),
                self::header(107, 'PF', '250006', '2025-10-01', 'Dobropis - vrácení notebooku', ['VARIABL' => '9006', 'CISLO_PF' => 'D-2025-9006'] + $vendorHeader),
                self::header(108, 'PF', '250007', '2025-11-05', 'Monitor', ['VARIABL' => '9007', 'CISLO_PF' => 'D-2025-9007'] + $vendorHeader),
            );
            array_push($tables['POLOZ_IN'][1],
                $small + self::item(106, 1, 'Notebook', 1, 'ks', 15000, 3150, 21, self::CODE_PURCHASE),
                $small + self::item(106, 2, 'Myš', 1, 'ks', 500, 105, 21, self::CODE_PURCHASE),
                $small + self::item(107, 1, empty($flags['unmatched_return']) ? 'Notebook' : 'Tiskárna', 1, 'ks', -15000, -3150, 21, self::CODE_PURCHASE),
                $small + self::item(108, 1, 'Monitor', 1, 'ks', 20000, 4200, 21, self::CODE_PURCHASE),
                ['UC_S' => '314', 'UC_SA' => '000', 'UC_D' => '321', 'UC_DA' => '000'] + self::item(108, 2, 'Odpočet zálohy', 1, 'ks', -5000, -1050, 21, self::CODE_PURCHASE),
            );
        }
        if (!empty($flags['small_asset_evidence'])) {
            $tables['MAJ_OST'] = [
                [['ID', 'C', 10], ['INTER', 'N', 10], ['DOKLAD', 'C', 5], ['POPIS', 'C', 50], ['TYP', 'C', 30], ['EVI_CIS', 'C', 20], ['CISLO', 'C', 20],
                    ['ZARAZENO', 'D'], ['VYRAZENO', 'D'], ['MNOZSTVI', 'N', 12, 3], ['CENA', 'N', 15, 2], ['CENA_KS', 'N', 15, 2],
                    ['UMISTENI', 'C', 40], ['JMENO_ODP', 'C', 40], ['POZNAMKA', 'C', 60]],
                [
                    // Cena jen za kus - celková se dopočte.
                    ['ID' => 'O1', 'INTER' => 1, 'POPIS' => 'Kancelářská židle', 'EVI_CIS' => 'DM-001', 'ZARAZENO' => '2025-03-10', 'MNOZSTVI' => 2,
                        'CENA' => 0, 'CENA_KS' => 4500, 'UMISTENI' => 'Kancelář 1', 'JMENO_ODP' => 'Jan Zkušební'],
                    ['ID' => 'O2', 'INTER' => 2, 'POPIS' => 'Skartovačka', 'EVI_CIS' => 'DM-002', 'ZARAZENO' => '2024-05-01', 'VYRAZENO' => '2025-06-30',
                        'MNOZSTVI' => 1, 'CENA' => 3000],
                    ['ID' => 'O3', 'INTER' => 3, 'POPIS' => 'Tablet', 'EVI_CIS' => 'DM-003', 'ZARAZENO' => '2026-02-01', 'MNOZSTVI' => 1, 'CENA' => 8000],
                ],
            ];
            $cards[] = ['INTER' => 501, 'ID' => 'M501', 'DOKLAD' => 'DH', 'CISLO' => 'DH-001', 'POPIS' => 'Notebook do terénu', 'DATUM' => '2025-02-03',
                'DATUM_P' => '2025-02-01', 'DATUM_UO' => '2025-02-03', 'KUSY' => 1, 'CENA' => 25000, 'UMISTENI' => 'Sklad', 'JMENO_ODP' => 'Eva Vzorová'];
        }
        if (!empty($flags['other_register'])) {
            $cards[] = ['INTER' => 502, 'ID' => 'M502', 'DOKLAD' => 'LEA', 'CISLO' => 'LEA-001', 'POPIS' => 'Osobní automobil na leasing', 'DATUM' => '2025-01-15',
                'DATUM_P' => '2025-01-15', 'DATUM_UO' => '2025-01-15', 'KUSY' => 1, 'CENA' => 600000];
        }
        if ($cards !== []) {
            $tables['MAJETEK'] = [self::cardFields(), $cards];
        }
        if (!empty($flags['maj_h'])) {
            array_push($chart, ['022', '100', 'Samostatné movité věci'], ['042', '100', 'Pořízení DHM'], ['082', '100', 'Oprávky k SMV'], ['551', '000', 'Odpisy DHM']);
            // ID karty v PREMIER je znakové pole doplněné nulami; řádky odpisů a pohybů ho nesou stejně.
            // Server zařazený 1. 3. 2025 (nepeněžitý vklad), účetní odpisy 2025 v deníku jedním zápisem.
            $id = '0000000007';
            array_push($tables['PUB_UCTO'][1],
                self::row(70, '2025-03-01', 'ID', '1', 'Zařazení serveru', 120000, '022100', '042100'),
                self::row(71, '2025-03-01', 'ID', '1', 'Nepeněžitý vklad serveru', 120000, '042100', '411000'),
                self::row(72, '2025-12-31', 'ID', '2', 'Účetní odpisy 2025', 20000, '551000', '082100'),
            );
            if (!empty($flags['maj_h_disposed'])) {
                // Server vyřazený 31. 12. 2025: PREMIER zaúčtoval ZC 100 000 na 541 a vyřazení z evidence.
                $chart[] = ['541', '000', 'Zůstatková cena prodaného majetku'];
                array_push($tables['PUB_UCTO'][1],
                    self::row(73, '2025-12-31', 'ID', '3', 'Vyřazení serveru - zůstatková cena', 100000, '541000', '082100'),
                    self::row(74, '2025-12-31', 'ID', '3', 'Vyřazení serveru z evidence', 120000, '082100', '022100'),
                );
            }
            $tables['MAJ_H'] = [self::cardFields(), [[
                'INTER' => 7, 'ID' => $id, 'DOKLAD' => 'HM', 'CISLO' => 'HM-001', 'POPIS' => 'Server', 'DATUM' => '2025-03-01', 'DATUM_P' => '2025-03-01',
                'DATUM_UO' => '2025-03-01', 'KUSY' => 1, 'CENA' => 120000, 'D_CENA' => 120000, 'ZPUSOB' => 1, 'SKUPINA' => 2,
                'PMD' => '022100', 'PDAL' => '042100', 'UMD' => '551000', 'UDAL' => '082100',
            ] + (!empty($flags['maj_h_disposed']) ? ['DATUM_V' => '2025-12-31'] : [])]];
            $tables['MAJ_H_OD'] = [
                [['ID_MAJ_H', 'C', 10], ['O_DATUM', 'D'], ['O_ODPIS', 'N', 15, 2], ['O_ZUST2', 'N', 15, 2]],
                [
                    ['ID_MAJ_H' => $id, 'O_DATUM' => '2025-12-31', 'O_ODPIS' => 13200, 'O_ZUST2' => 106800],
                    ['ID_MAJ_H' => $id, 'O_DATUM' => '2026-12-31', 'O_ODPIS' => 26700, 'O_ZUST2' => 80100],
                ],
            ];
            $plan = [];
            $movements = [['ID_MAJ_H' => $id, 'KOD' => 10, 'DATUM' => '2025-03-01', 'CASTKA' => 120000, 'POPIS' => 'Zařazení']];
            for ($m = 0; $m < 60; $m++) {
                $date = date('Y-m-t', strtotime("2025-03-01 +{$m} months"));
                $plan[] = ['ID_MAJ_H' => $id, 'O_DATUM' => $date, 'O_ODPIS' => 2000];
                if ($date <= '2025-12-31' && empty($flags['maj_h_unbooked'])) {
                    $movements[] = ['ID_MAJ_H' => $id, 'KOD' => 6, 'DATUM' => $date, 'CASTKA' => 2000, 'POPIS' => 'Účetní odpis'];
                }
            }
            $tables['MAJ_H_OU'] = [[['ID_MAJ_H', 'C', 10], ['O_DATUM', 'D'], ['O_ODPIS', 'N', 15, 2]], $plan];
            $tables['MAJ_H_PO'] = [[['ID_MAJ_H', 'C', 10], ['KOD', 'N', 3], ['DATUM', 'D'], ['CASTKA', 'N', 15, 2], ['POPIS', 'C', 40]], $movements];
        }
        if (!empty($flags['small_asset_evidence']) || !empty($flags['other_register']) || !empty($flags['maj_h'])) {
            array_push($tables['DOKL_PU'][1],
                ['DOKLAD' => 'HM', 'TOK' => 41, 'TEXT' => 'Dlouhodobý hmotný majetek'],
                ['DOKLAD' => 'DH', 'TOK' => 43, 'TEXT' => 'Drobný hmotný majetek'],
                ['DOKLAD' => 'LEA', 'TOK' => 46, 'TEXT' => 'Leasing'],
            );
        }
        if (!empty($flags['dppo'])) {
            $tables['D_PO1'] = [[['ID_CISLO', 'C', 10], ['ROK', 'N', 4], ['DNE', 'C', 10], ['FORMA', 'C', 1]],
                [['ID_CISLO' => 'PO1', 'ROK' => self::YEAR1, 'DNE' => '30.06.2026', 'FORMA' => 'B']]];
            $tables['D_PO2'] = [[['ID_CISLO', 'C', 10], ['II_40_VYDA', 'N', 15, 2]], [['ID_CISLO' => 'PO1', 'II_40_VYDA' => 0]]];
        }
        if (!empty($flags['payroll'])) {
            array_push($chart, ['331', '100', 'Zaměstnanci'], ['336', '100', 'Zúčtování sociálního pojištění'], ['336', '200', 'Zúčtování zdravotního pojištění'],
                ['342', '200', 'Srážková daň'], ['342', '100', 'Záloha na daň ze závislé činnosti'], ['521', '100', 'Mzdové náklady'], ['524', '100', 'Zákonné pojištění']);
            self::payroll($tables, !empty($flags['payroll_mismatch']), !empty($flags['payroll_detail']), !empty($flags['payroll_unknown_codes']));
            if (!empty($flags['payroll_detail']) && !empty($flags['payroll_nonresident'])) {
                self::nonResident($tables);
            }
            if (!empty($flags['payroll_detail']) && !empty($flags['payroll_benefits'])) {
                self::benefits($tables);
            }
        }
        if (!empty($flags['bank_split'])) {
            self::bankSplit($tables, $chart);
        }
        if (!empty($flags['reduced_deduction'])) {
            foreach ($tables['KODY_DPH'][1] as &$code) {
                if ($code['KOD_DPH'] === self::CODE_PURCHASE) {
                    $code['IS_KRACENY'] = true;
                }
            }
            unset($code);
        }
        if (!empty($flags['oss_eur'])) {
            foreach ($tables['FA_OUT'][1] as &$header) {
                if ($header['INTER'] === 3) {
                    $header = ['MENA' => 'EUR', 'KURS' => 25, 'M_KURS' => 1] + $header;
                }
            }
            unset($header);
            foreach ($tables['POLOZKY'][1] as &$item) {
                if ($item['FAKTURA'] === 3) {
                    $item = ['CENA' => 40.0, 'CENA_DPH' => 9.2] + $item;
                }
            }
            unset($item);
        }
        if (!empty($flags['eur_exact'])) {
            // VF 250008 v EUR, kurz 25,12: 100 + 21 EUR a 40 + 8,40 EUR = v Kč přesně 2 512 + 527,52
            // a 1 004,80 + 211,01; deník 3 516,80 + 738,53 Kč, neuhrazená.
            $customer = ['CISLO_ODB' => '1', 'NAZEV_ODB' => 'Odběratel Fiktivní s.r.o.', 'ICO_ODB' => self::CUSTOMER_ICO, 'DIC_ODB' => 'CZ' . self::CUSTOMER_ICO, 'ID_PAR' => 'P1'];
            array_push($tables['PUB_UCTO'][1],
                self::row(92, '2025-10-15', 'VF', self::EUR_DOCUMENT, 'Služby v EUR', 3516.80, '311000', '602100', self::vat(self::CODE_SALE, 'P', 'VF', 9) + $customer),
                self::row(93, '2025-10-15', 'VF', self::EUR_DOCUMENT, 'DPH', 738.53, '311000', '343021', self::vat(self::CODE_SALE, 'D', 'VF', 9) + $customer),
            );
            $tables['FA_OUT'][1][] = self::header(9, 'VF', self::EUR_DOCUMENT, '2025-10-15', 'Služby v EUR',
                ['VS' => self::EUR_DOCUMENT, 'FORMA' => 'převodem', 'MENA' => 'EUR', 'KURS' => 25.12, 'M_KURS' => 1] + $customer);
            array_push($tables['POLOZKY'][1],
                self::item(9, 1, 'Konzultace', 2, 'hod', 100.00, 21.00, 21, self::CODE_SALE),
                self::item(9, 2, 'Licence', 1, 'ks', 40.00, 8.40, 21, self::CODE_SALE),
            );
        }
        if (!empty($flags['rc_uncoded_line'])) {
            $euVendor = ['CISLO_ODB' => '3', 'NAZEV_ODB' => 'Fiktiv Software GmbH', 'DIC_ODB' => self::EU_VENDOR_DIC, 'STAT_ODB' => 'Německo', 'ID_PAR' => 'P3'];
            $tables['PUB_UCTO'][1][] = self::row(70, '2025-04-10', 'PF', '250002', 'Poplatek mimo DPH', 200, '518100', '321000', ['SB_KOD' => 'PF', 'SBORNIK' => 102] + $euVendor);
            $tables['POLOZ_IN'][1][] = self::item(102, 2, 'Poplatek mimo DPH', 1, 'ks', 200, 0, 0, '');
        }
        if (!empty($flags['cash_duplicate'])) {
            array_push($tables['PUB_UCTO'][1],
                self::row(80, '2025-01-02', 'PP', '1', 'Další vklad', 100, '211001', '411000', ['SB_KOD' => 'PP', 'SBORNIK' => 901]),
                self::row(81, '2025-01-02', 'PP', '1', 'Třetí vklad', 200, '211001', '411000', ['SB_KOD' => 'PP', 'SBORNIK' => 902]),
            );
        }
        if (!empty($flags['periody']) || !empty($flags['periody_11'])) {
            $rows = [];
            foreach (range(1, !empty($flags['periody']) ? 12 : 11) as $month) {
                // Zamčení účetnictví (PU) i celý měsíc (KOMPLET) se počítají stejně.
                $rows[] = ['ROK' => self::YEAR1, 'MESIC' => $month, 'KOMPLET' => $month % 2 === 0, 'PU' => $month % 2 === 1];
            }
            // Zamčený měsíc jiného roku a nezamčený řádek se nepočítají.
            $rows[] = ['ROK' => self::YEAR2, 'MESIC' => 12, 'KOMPLET' => true, 'PU' => true];
            if (empty($flags['periody'])) {
                $rows[] = ['ROK' => self::YEAR1, 'MESIC' => 12, 'KOMPLET' => false, 'PU' => false];
            }
            $tables['PERIODY'] = [[['ROK', 'N', 4], ['MESIC', 'N', 2], ['KOMPLET', 'L'], ['PU', 'L']], $rows];
        }
        foreach ([self::YEAR1, self::YEAR2] as $year) {
            foreach ($chart as [$synthetic, $analytic, $name]) {
                $tables['OSNOVA'][1][] = ['UCET' => $synthetic, 'ANALYT' => $analytic, 'TEXT' => $name, 'ROK' => $year];
            }
        }
    }

    /**
     * Mzdy (`payroll`): tři fiktivní vztahy a jejich zaúčtování v deníku (MD 521/524,
     * D 331/336/342 posledním dnem měsíce, jako to dělá PREMIER):
     *
     *   INTER 1  jednatelka s odměnou (`MZ_ODSTAT`) 6 000 Kč, od 1. 1. 2025, srážková daň;
     *            od 2026 odměna 6 500 (`PERS_HYS`), osoba v `PER_MAIN`, výplatní účet
     *   INTER 2  DPP 3 000 Kč 3-6/2025, skončená, osoba jen ve snímcích `PERSON2`
     *   INTER 3  pracovní poměr od 1. 2. 2026, 40 000 Kč, podepsané prohlášení, přihláška ZP
     *
     * `$mismatch`: zdravotní pojištění zaměstnavatele za 5/2025 je v deníku o 40 Kč nižší.
     *
     * @param array<string,array{0:list<array{0:string,1:string,2?:int,3?:int}>,1:list<array<string,mixed>>}> $tables MĚNÍ SE
     */
    private static function payroll(array &$tables, bool $mismatch, bool $detail = false, bool $unknownCodes = false): void
    {
        $tables['PERSONAL'] = [
            [['INTER', 'N', 8], ['CISLO', 'N', 10], ['VSTUP', 'D'], ['VYSTUP', 'D'], ['BANKA_UCET', 'C', 30], ['BANKA_KOD', 'C', 20], ['UVA_KATE', 'C', 3],
                ['UVA_PROF', 'C', 80], ['KATEGO', 'C', 48], ['JEDNATEL', 'L'], ['KODPP_SO', 'C', 1], ['OSS_ZEME', 'C', 2], ['VYSLANY', 'L'], ['SUP_ID', 'C', 36], ['ID', 'C', 36]],
            [
                ['INTER' => 1, 'CISLO' => 1, 'VSTUP' => '2025-01-01', 'BANKA_UCET' => self::BANK_ACCOUNT, 'BANKA_KOD' => self::BANK_CODE, 'UVA_KATE' => 'SJK',
                    'UVA_PROF' => 'jednatelka', 'JEDNATEL' => true, 'OSS_ZEME' => 'CZ', 'SUP_ID' => 'OS-A', 'ID' => 'PP-1'],
                ['INTER' => 2, 'CISLO' => 2, 'VSTUP' => '2025-03-01', 'VYSTUP' => '2025-06-30', 'UVA_KATE' => 'DPP', 'KODPP_SO' => 'T', 'ID' => 'PP-2'],
                ['INTER' => 3, 'CISLO' => 3, 'VSTUP' => '2026-02-01', 'UVA_KATE' => 'HPP', 'KODPP_SO' => '1', 'OSS_ZEME' => 'CZ', 'SUP_ID' => 'OS-C', 'ID' => 'PP-3'],
            ],
        ];
        $tables['PER_MAIN'] = [
            [['ID', 'C', 36], ['RC_1', 'C', 6], ['RC_2', 'C', 4], ['PRIJMENI', 'C', 40], ['JMENO', 'C', 40], ['TITUL_PR', 'C', 10], ['TITUL_ZA', 'C', 10],
                ['NAROZENI', 'D'], ['MISTO_N', 'C', 70], ['ULICE', 'C', 28], ['CISLOP', 'C', 12], ['PSC', 'C', 6], ['MESTO', 'C', 40], ['STAT', 'C', 28],
                ['E_MAIL', 'C', 64], ['MOBIL', 'C', 20], ['NREZIDEN', 'L'], ['STAT_N', 'C', 3], ['STOBC', 'C', 3], ['RODNE_P', 'C', 40]],
            [
                ['ID' => 'OS-A', 'RC_1' => '855101', 'RC_2' => '0105', 'PRIJMENI' => 'Fiktivní', 'JMENO' => 'Jana', 'TITUL_PR' => 'Ing.', 'NAROZENI' => '1985-01-01',
                    'MISTO_N' => 'Brno', 'ULICE' => 'Zkušební', 'CISLOP' => '1', 'PSC' => '602 00', 'MESTO' => 'Brno', 'STAT' => 'CZ',
                    'E_MAIL' => 'jednatelka@example.invalid', 'STAT_N' => 'CZ', 'STOBC' => 'CZ', 'RODNE_P' => 'Vzorová'],
                ['ID' => 'OS-C', 'RC_1' => '920620', 'RC_2' => '0102', 'PRIJMENI' => 'Vzorový', 'JMENO' => 'Karel', 'NAROZENI' => '1992-06-20',
                    'ULICE' => 'Pokusná', 'CISLOP' => '7', 'PSC' => '70200', 'MESTO' => 'Ostrava', 'STAT' => 'CZ', 'STAT_N' => 'CZ', 'STOBC' => 'CZ',
                    'RODNE_P' => 'Vzorový'],
            ],
        ];
        // Historické snímky osoby: platí poslední podle TS.
        $tables['PERSON2'] = [
            [['INTER', 'N', 8], ['CISLO', 'N', 10], ['RC_1', 'C', 6], ['RC_2', 'C', 4], ['PRIJMENI', 'C', 20], ['JMENO', 'C', 12], ['VSTUP', 'D'], ['VYSTUP', 'D'],
                ['TS', 'C', 40], ['ULICE', 'C', 28], ['CISLOP', 'C', 10], ['PSC', 'C', 6], ['MESTO', 'C', 20], ['OBEC', 'C', 20], ['STAT', 'C', 3], ['ID', 'C', 36]],
            [
                ['CISLO' => 2, 'RC_1' => '900315', 'RC_2' => '0101', 'PRIJMENI' => 'Zkušebni', 'JMENO' => 'Petr', 'VSTUP' => '2025-03-01', 'TS' => '2025030110:00:00#INSE#',
                    'ULICE' => 'Stará', 'CISLOP' => '2', 'PSC' => '11000', 'MESTO' => 'Praha', 'STAT' => 'CZ', 'ID' => 'S2-1'],
                ['CISLO' => 2, 'RC_1' => '900315', 'RC_2' => '0101', 'PRIJMENI' => 'Zkušební', 'JMENO' => 'Petr', 'VSTUP' => '2025-03-01', 'VYSTUP' => '2025-06-30',
                    'TS' => '2025070110:00:00#INSE#', 'ULICE' => 'Nová', 'CISLOP' => '5', 'PSC' => '11000', 'MESTO' => 'Praha', 'STAT' => 'CZ', 'ID' => 'S2-2'],
            ],
        ];
        $tables['PERS_HYS'] = [
            [['INTER', 'N', 8], ['ROK', 'N', 4], ['MESIC', 'N', 2], ['MZDA_MES', 'N', 9, 2], ['PLATNY_OD', 'D'], ['ID', 'C', 36]],
            [
                ['INTER' => 1, 'ROK' => 2025, 'MESIC' => 1, 'MZDA_MES' => 6000, 'PLATNY_OD' => '2025-01-01', 'ID' => 'H1'],
                ['INTER' => 1, 'ROK' => 2026, 'MESIC' => 1, 'MZDA_MES' => 6500, 'PLATNY_OD' => '2026-01-01', 'ID' => 'H2'],
                ['INTER' => 3, 'ROK' => 2026, 'MESIC' => 2, 'MZDA_MES' => 40000, 'PLATNY_OD' => '2026-02-01', 'ID' => 'H3'],
            ],
        ];
        $tables['MZ_PRIZP'] = [
            [['INTER', 'N', 8], ['HLAS_OD', 'D'], ['ZKRATKA_P', 'C', 3], ['KOD', 'C', 1], ['PRIJATO', 'L'], ['ID', 'C', 36]],
            [['INTER' => 3, 'HLAS_OD' => '2026-02-01', 'ZKRATKA_P' => '201', 'KOD' => 'P', 'PRIJATO' => true, 'ID' => 'ZP3']],
        ];
        $tables['MZDY_POL'] = [
            [['KOD', 'C', 3], ['POPIS', 'C', 200], ['UCET', 'C', 7], ['TYP', 'N', 2]],
            [['KOD' => '101', 'POPIS' => 'Mzda měsíční', 'UCET' => '521100', 'TYP' => 1], ['KOD' => '510', 'POPIS' => 'Odměna člena statutárního orgánu', 'UCET' => '521100', 'TYP' => 1]],
        ];

        $statutory = static fn (int $amount, int $soc, int $zdr, int $socF, int $zdrF, int $tax): array => [
            'MZ_ODSTAT' => $amount, 'VYM_SOC' => $amount, 'VYM_ZDR' => $amount, 'MZ_SOC' => $soc, 'MZ_ZDR' => $zdr, 'MZ_SOCF' => $socF, 'MZ_ZDRF' => $zdrF,
            // PREMIER nese `NEZD_A` i u zdanění srážkou; prohlášení podepsané není (`POD_DAN`).
            'MZ_SDANI' => $amount, 'MZ_SDAN' => $tax, 'SRAZ_DAN' => true, 'NEZD_A' => true, 'MZ_CISTA' => $amount - $soc - $zdr - $tax, 'MZ_VYPLATA' => $amount - $soc - $zdr - $tax,
            'POJIS_SO' => true, 'ZKR_POJ' => '111', 'UVA_DOBA' => 8,
        ];
        $months = [];
        foreach (range(1, 12) as $m) {
            $months[] = [1, 2025, $m, $statutory(6000, 426, 270, 1488, 540, 900)];
        }
        foreach ([1, 2] as $m) {
            $months[] = [1, 2026, $m, $statutory(6500, 462, 293, 1612, 585, 975)];
        }
        foreach (range(3, 6) as $m) {
            $months[] = [2, 2025, $m, ['MZ_HRUBA' => 3000, 'MZ_SDANI' => 3000, 'MZ_SDAN' => 450, 'SRAZ_DAN' => true, 'MZ_CISTA' => 2550, 'MZ_VYPLATA' => 2550,
                'DNY_ODPR' => 5, 'UVA_DOBA' => 8]];
        }
        $months[] = [3, 2026, 2, ['MZ_HRUBA' => 40000, 'VYM_SOC' => 40000, 'VYM_ZDR' => 40000, 'MZ_SOC' => 2840, 'MZ_ZDR' => 1800, 'MZ_SOCF' => 9920, 'MZ_ZDRF' => 3600,
            'MZ_ZDANI' => 40000, 'MZ_DAN' => 3430, 'NEZD_VLAS' => 2570, 'POD_DAN' => true, 'NEZD_A' => true, 'MZ_CISTA' => 31930, 'MZ_VYPLATA' => 31930,
            'POJIS_SO' => true, 'ZKR_POJ' => '201', 'DNY_ODPR' => 19, 'UVA_DOBA' => 8]];
        $mzdyFields = [];
        if ($detail) {
            $mzdyFields = self::payrollDetail($tables, $months, $unknownCodes);
        }

        $rows = [];
        $inter = 300;
        foreach ($months as [$relation, $year, $month, $values]) {
            $days = (int) date('t', (int) mktime(0, 0, 0, $month, 1, $year));
            $rows[] = $values + ['INTER' => $relation, 'ROK' => $year, 'MESIC' => $month, 'KAL_DNY' => $days, 'KAL_DNYPP' => $days, 'ID' => "M{$relation}-{$year}-{$month}"];
            $date = sprintf('%04d-%02d-%02d', $year, $month, $days);
            $number = sprintf('%02d%02d%d', $year % 100, $month, $relation);
            $gross = ($values['MZ_HRUBA'] ?? 0) + ($values['MZ_ODSTAT'] ?? 0);
            $employerHealth = (float) ($values['MZ_ZDRF'] ?? 0);
            if ($mismatch && $relation === 1 && $year === 2025 && $month === 5) {
                $employerHealth -= 40;
            }
            foreach ([
                ['Hrubá mzda', $gross, '521100', '331100'],
                ['Sociální pojištění zaměstnanec', $values['MZ_SOC'] ?? 0, '331100', '336100'],
                ['Zdravotní pojištění zaměstnanec', $values['MZ_ZDR'] ?? 0, '331100', '336200'],
                ['Sociální pojištění zaměstnavatel', $values['MZ_SOCF'] ?? 0, '524100', '336100'],
                ['Zdravotní pojištění zaměstnavatel', $employerHealth, '524100', '336200'],
                ['Srážková daň', $values['MZ_SDAN'] ?? 0, '331100', '342200'],
                ['Záloha na daň', $values['MZ_DAN'] ?? 0, '331100', '342100'],
            ] as [$text, $amount, $md, $dal]) {
                if ((float) $amount !== 0.0) {
                    $tables['PUB_UCTO'][1][] = self::row($inter++, $date, 'MZ', $number, $text, (float) $amount, $md, $dal);
                }
            }
        }
        $tables['MZDY'] = [
            [['INTER', 'N', 8], ['MESIC', 'N', 2], ['ROK', 'N', 4], ['MZ_HRUBA', 'N', 12, 2], ['MZ_ODSTAT', 'N', 15, 2], ['VYM_SOC', 'N', 15, 2], ['VYM_ZDR', 'N', 15, 2],
                ['MZ_SOC', 'N', 12, 2], ['MZ_ZDR', 'N', 12, 2], ['MZ_SOCF', 'N', 15, 2], ['MZ_ZDRF', 'N', 15, 2], ['MZ_ZDANI', 'N', 15, 2], ['MZ_DAN', 'N', 15, 2],
                ['MZ_SDANI', 'N', 15, 2], ['MZ_SDAN', 'N', 15, 2], ['MZ_BONUS', 'N', 15, 2], ['NEZD_VLAS', 'N', 12, 2], ['NEZD_DETI', 'N', 12, 2],
                ['MZ_CISTA', 'N', 15, 2], ['MZ_VYPLATA', 'N', 15, 2], ['SRAZ_DAN', 'L'], ['POD_DAN', 'L'], ['NEZD_A', 'L'], ['POJIS_SO', 'L'],
                ['KAL_DNY', 'N', 2], ['KAL_DNYPP', 'N', 5, 1], ['DNY_ODPR', 'N', 5, 2], ['UVA_DOBA', 'N', 7, 4], ['VYL_DND', 'N', 6, 2], ['DNY_NEKA', 'N', 5, 1], ['ZKR_POJ', 'C', 3], ['ID', 'C', 36],
                ...$mzdyFields],
            $rows,
        ];
    }

    /**
     * Další vztahy a evidence mzdového modulu (`payroll_detail`), syntetické osoby:
     *
     *   INTER 4  učeň (kategorie `UCN`, `KODPP_SO` prázdné jako v reálných zálohách), bez mezd
     *   INTER 5  pracovní poměr od 15. 1. 2025 (kategorie `HPP`, `KODPP_SO` prázdné); sjednaná mzda
     *            v `SAZBA_MZ` podle `TYP_MZDY` (30 000, od 7/2025 32 000; `MZDA_MES` prázdné
     *            nebo jiné), stát adresy názvem „Česká republika"; mzdy 1/2025-1/2026; přihláška
     *            k ZP 111, od 7/2025 změna na 201; korespondenční adresa v `PER_ADR`; exekuce
     *            1 000 Kč měsíčně od 10/2025, v 11/2025 záloha na mzdu 2 000 Kč (jen v `DNY`);
     *            úvazek 38,75 h týdně; formulář JMHZ za 1/2026 přijatý ČSSZ (OIČ, ID PPV,
     *            pracoviště Brno, druh činnosti 1), CZ-ISCO v `MZ_ISPV`, přijaté oznámení
     *            o nástupu ČSSZ (`MZ_PRISO`); výplatní účet se změnou 9/2025 (`MZ_PERH`), dítě
     *            se zvýhodněním (`PER_DETI`, `MZ_DETI`) a dítě bez něj; dovolená 8/2025 a pracovní
     *            neschopnost od 10. 11. 2025 do 20. 1. 2026 (`DNY`, `MZ_HDPN`), stav dovolené
     *            v hodinách (`DOV_DNY`), průměry čtvrtletí (`PER_PRU`); trvalé složky `MZ_SRAZ`
     *            (osobní ohodnocení, exekuce, skončené spoření, odbory)
     *   INTER 6  DPP 4-6/2025 (kategorie `DPP`), osoba s bydlištěm na Slovensku (stát názvem
     *            „Slovenská republika"); OIČ jen na kartě osoby (bez formuláře JMHZ), přijatá
     *            oznámení ČSSZ o nástupu i skončení; pobírá důchod (`MZ_DUCHOD`), výplata v hotovosti
     *
     * Registr pojišťoven `POJIST` a účty odvodů v nastavení mezd (`SET_GLOB` `amz_ucet1..3`).
     *
     * @param array<string,array{0:list<array{0:string,1:string,2?:int,3?:int}>,1:list<array<string,mixed>>}> $tables MĚNÍ SE
     * @param list<array{0:int,1:int,2:int,3:array<string,mixed>}> $months MĚNÍ SE
     * `$unknownCodes` (příznak `payroll_unknown_codes`): navíc položka z řady nepřítomností,
     * kterou převod nezná (557), a karta `MZ_SRAZ` s neznámou složkou (721).
     *
     * @return list<array{0:string,1:string,2?:int,3?:int}> další sloupce `MZDY`
     */
    private static function payrollDetail(array &$tables, array &$months, bool $unknownCodes = false): array
    {
        array_push($tables['PERSONAL'][1],
            ['INTER' => 4, 'CISLO' => 4, 'VSTUP' => '2025-09-01', 'UVA_KATE' => 'UCN', 'UVA_PROF' => 'učeň', 'KODPP_SO' => '',
                'OSS_ZEME' => 'CZ', 'SUP_ID' => 'OS-D', 'ID' => 'PP-4'],
            ['INTER' => 5, 'CISLO' => 5, 'VSTUP' => '2025-01-15', 'UVA_KATE' => 'HPP', 'UVA_PROF' => 'programátor', 'KODPP_SO' => '',
                'OSS_ZEME' => 'CZ', 'SUP_ID' => 'OS-E', 'ID' => 'PP-5'],
            ['INTER' => 6, 'CISLO' => 6, 'VSTUP' => '2025-04-01', 'VYSTUP' => '2025-06-30', 'UVA_KATE' => 'DPP', 'UVA_PROF' => 'lektor', 'KODPP_SO' => '',
                'OSS_ZEME' => 'CZ', 'SUP_ID' => 'OS-F', 'ID' => 'PP-6'],
        );
        array_push($tables['PER_MAIN'][1],
            ['ID' => 'OS-D', 'RC_1' => '080312', 'RC_2' => '0000', 'PRIJMENI' => 'Učňovský', 'JMENO' => 'Adam', 'NAROZENI' => '2008-03-12',
                'ULICE' => 'Školní', 'CISLOP' => '3', 'PSC' => '60200', 'MESTO' => 'Brno', 'STAT' => 'CZ', 'STAT_N' => 'CZ'],
            ['ID' => 'OS-E', 'SUP_INTER' => 105, 'RC_1' => '880312', 'RC_2' => '0106', 'PRIJMENI' => 'Syntetický', 'JMENO' => 'Tomáš', 'NAROZENI' => '1988-03-12',
                'ULICE' => 'Vymyšlená', 'CISLOP' => '12', 'PSC' => '60200', 'MESTO' => 'Brno', 'STAT' => 'Česká republika', 'STAT_N' => 'CZ'],
            ['ID' => 'OS-F', 'IK_MPSV' => '9876543204', 'RC_1' => '870202', 'RC_2' => '0107', 'PRIJMENI' => 'Pokusný', 'JMENO' => 'Marek', 'NAROZENI' => '1987-02-02',
                'ULICE' => 'Hlavná', 'CISLOP' => '5', 'PSC' => '81101', 'MESTO' => 'Bratislava', 'STAT' => 'Slovenská republika', 'STAT_N' => 'SK', 'STOBC' => 'CZ'],
        );
        $tables['PER_MAIN'][0][] = ['SUP_INTER', 'N', 10];
        $tables['PER_MAIN'][0][] = ['IK_MPSV', 'C', 36];
        // Výplata na účet (KONTO_L): INTER 5 na účet s historií změn, INTER 6 v hotovosti.
        $tables['PERSONAL'][0][] = ['KONTO_L', 'L'];
        foreach ($tables['PERSONAL'][1] as $i => $row) {
            if ($row['INTER'] === 5) {
                $tables['PERSONAL'][1][$i] += ['BANKA_UCET' => self::BANK_ACCOUNT, 'BANKA_KOD' => self::BANK_CODE, 'KONTO_L' => true];
            } else {
                $tables['PERSONAL'][1][$i]['KONTO_L'] = $row['INTER'] !== 6;
            }
        }
        $history = static fn (string $key, string $value, int $month): array => ['UDAJ' => $key, 'TYP' => 'C', 'HODNOTAC' => $value, 'PLATN_OD_M' => $month,
            'PLATN_OD_R' => 2025, 'MZ_HINTER' => 5, 'ID' => "PH5-{$key}-{$month}"];
        $tables['MZ_PERH'] = [
            [['UDAJ', 'C', 20], ['TYP', 'C', 1], ['HODNOTAC', 'C', 80], ['PLATN_OD_M', 'N', 2], ['PLATN_OD_R', 'N', 4], ['MZ_HINTER', 'N', 12], ['ID', 'C', 36]],
            [$history('banka_ucet', '19-2000145399', 1), $history('banka_kod', '0800', 1),
                $history('banka_ucet', self::BANK_ACCOUNT, 9), $history('banka_kod', self::BANK_CODE, 9)],
        ];
        // Děti osoby OS-E (vazba přes SUP_INTER): se zvýhodněním 1. pořadí (uplatněné 1/2025-1/2026,
        // příznak BVYZI) a bez zvýhodnění.
        $tables['PER_DETI'] = [
            [['ID', 'C', 36], ['INTER', 'N', 8], ['BJMENO', 'C', 30], ['BPRIJMENI', 'C', 30], ['BRC_1', 'C', 6], ['BRC_2', 'C', 4], ['BDNAR', 'D'],
                ['BABY_POR', 'C', 1], ['BABY_SLE', 'L'], ['BVYZI', 'L'], ['BABYV', 'L']],
            [
                ['ID' => 'D1', 'INTER' => 105, 'BJMENO' => 'Eliška', 'BPRIJMENI' => 'Syntetická', 'BRC_1' => '180420', 'BRC_2' => '0101', 'BDNAR' => '2018-04-20',
                    'BABY_POR' => '1', 'BABY_SLE' => true, 'BVYZI' => true, 'BABYV' => true],
                ['ID' => 'D2', 'INTER' => 105, 'BJMENO' => 'Ondřej', 'BPRIJMENI' => 'Syntetický', 'BRC_1' => '150505', 'BRC_2' => '0107', 'BDNAR' => '2015-05-05',
                    'BABY_POR' => 'N', 'BABY_SLE' => false, 'BVYZI' => false, 'BABYV' => true],
            ],
        ];
        $applied = [];
        foreach ([[2025, range(1, 12)], [2026, [1]]] as [$year, $monthList]) {
            foreach ($monthList as $m) {
                $applied[] = ['DITE_ID' => 'D1', 'DITE_POR' => '1', 'DI_SLE' => 1267, 'ZAM_SUPID' => 'OS-E', 'DITE_MES' => $m, 'DITE_ROK' => $year, 'ID' => "MD-{$year}-{$m}"];
            }
        }
        $tables['MZ_DETI'] = [
            [['DITE_ID', 'C', 36], ['DITE_POR', 'C', 1], ['DI_SLE', 'N', 12, 2], ['ZAM_SUPID', 'C', 36], ['DITE_MES', 'N', 2], ['DITE_ROK', 'N', 4], ['ID', 'C', 36]],
            $applied,
        ];
        // DPP vztahu INTER 6 pobírá starobní důchod, sleva na pojistném se neuplatňuje.
        $tables['MZ_DUCHOD'] = [[['INTER', 'N', 12], ['DAT_PR_OD', 'D'], ['D_KATE2', 'N', 2], ['ID', 'C', 36]],
            [['INTER' => 6, 'DAT_PR_OD' => '2020-01-01', 'D_KATE2' => 8, 'ID' => 'DU6']]];
        // Registr pojišťoven a účty odvodů v nastavení mezd (pozice 1 záloha na daň, 2 srážková daň, 3 ČSSZ).
        $tables['POJIST'] = [
            [['ZKRATKA_PO', 'C', 3], ['NAZEV_PO', 'C', 40], ['UCET_PO', 'C', 36], ['KOD_PO', 'C', 12], ['VAR1', 'C', 10], ['CO_POJIS', 'N', 2], ['ADR_DS', 'C', 10]],
            [
                ['ZKRATKA_PO' => '111', 'NAZEV_PO' => 'Fiktivní zdravotní pojišťovna', 'UCET_PO' => self::BANK_ACCOUNT, 'KOD_PO' => '0710', 'VAR1' => self::ICO, 'CO_POJIS' => 1, 'ADR_DS' => 'abc1234'],
                ['ZKRATKA_PO' => '201', 'NAZEV_PO' => 'Vzorová pojišťovna', 'UCET_PO' => '2000145399', 'KOD_PO' => '0100', 'VAR1' => self::ICO, 'CO_POJIS' => 1],
                ['ZKRATKA_PO' => 'PF', 'NAZEV_PO' => 'Penzijní fond', 'UCET_PO' => self::BANK_ACCOUNT, 'KOD_PO' => '2700', 'CO_POJIS' => 2],
            ],
        ];
        array_push($tables['SET_GLOB'][1],
            ['PROMEN' => 'amz_ucet1', 'C_SET' => '713-' . self::BANK_ACCOUNT], ['PROMEN' => 'amz_kod1', 'C_SET' => '0710'], ['PROMEN' => 'amz_var1', 'C_SET' => self::ICO],
            ['PROMEN' => 'amz_ucet2', 'C_SET' => '7720-' . self::BANK_ACCOUNT], ['PROMEN' => 'amz_kod2', 'C_SET' => '0710'], ['PROMEN' => 'amz_var2', 'C_SET' => self::ICO],
            ['PROMEN' => 'amz_ucet3', 'C_SET' => '21012-' . self::BANK_ACCOUNT], ['PROMEN' => 'amz_kod3', 'C_SET' => '0710'], ['PROMEN' => 'amz_var3', 'C_SET' => '1234567890'],
        );
        // Další adresy osoby: vazba přes PER_MAIN.SUP_INTER; druh 1 je kopie trvalé, druh 2 korespondenční.
        $tables['PER_ADR'] = [
            [['INTER', 'N', 10], ['XULICE', 'C', 28], ['XCISLO', 'C', 12], ['XPSC', 'C', 10], ['XMESTO', 'C', 40], ['XOBEC', 'C', 50], ['XSTAT', 'C', 10],
                ['XZEME', 'C', 2], ['DRUH_ADR', 'N', 1], ['XKORES', 'L'], ['XIS_IMP', 'L'], ['ID', 'C', 36], ['TS', 'C', 40]],
            [
                ['INTER' => 105, 'XULICE' => 'Vymyšlená', 'XCISLO' => '12', 'XPSC' => '60200', 'XMESTO' => 'Brno', 'XSTAT' => 'CZ', 'XZEME' => 'CZ',
                    'DRUH_ADR' => 1, 'XKORES' => false, 'XIS_IMP' => true, 'ID' => 'A5-1', 'TS' => '2025011510:00:00#INSE#'],
                ['INTER' => 105, 'XULICE' => 'Poštovní', 'XCISLO' => '1', 'XPSC' => '77900', 'XMESTO' => 'Olomouc', 'XSTAT' => 'CZ', 'XZEME' => 'CZ',
                    'DRUH_ADR' => 2, 'XKORES' => true, 'XIS_IMP' => false, 'ID' => 'A5-2', 'TS' => '2025011510:00:00#INSE#'],
            ],
        ];
        foreach (range(4, 6) as $m) {
            $months[] = [6, 2025, $m, ['MZ_HRUBA' => 5000, 'MZ_SDANI' => 5000, 'MZ_SDAN' => 750, 'SRAZ_DAN' => true, 'MZ_CISTA' => 4250, 'MZ_VYPLATA' => 4250,
                'DNY_ODPR' => 5, 'UVA_DOBA' => 8]];
        }
        $tables['PERS_HYS'][0] = [...$tables['PERS_HYS'][0], ['TYP_MZDY', 'N', 1], ['SAZBA_MZ', 'N', 15, 4], ['UVA_HOD', 'N', 8, 4], ['UVA_DOBA', 'N', 7, 4]];
        $time = ['UVA_HOD' => 38.75, 'UVA_DOBA' => 7.75];
        array_push($tables['PERS_HYS'][1],
            ['INTER' => 5, 'ROK' => 2025, 'MESIC' => 1, 'TYP_MZDY' => 1, 'SAZBA_MZ' => 30000, 'PLATNY_OD' => '2025-01-01', 'ID' => 'H5-1'] + $time,
            // `MZDA_MES` se od sazby liší a sjednanou mzdou není.
            ['INTER' => 5, 'ROK' => 2025, 'MESIC' => 7, 'TYP_MZDY' => 1, 'SAZBA_MZ' => 32000, 'MZDA_MES' => 30400, 'PLATNY_OD' => '2025-07-01', 'ID' => 'H5-2'] + $time,
        );
        // Formulář JMHZ za 1/2026 přijatý ČSSZ; OIČ má platnou kontrolní číslici.
        $tables['MZ_JMHZ'] = [
            [['ID', 'C', 36], ['X10010', 'N', 12], ['X10011', 'N', 12], ['X10007', 'C', 10], ['TS', 'C', 40]],
            [['ID' => 'J2601', 'X10010' => 1, 'X10011' => 2026, 'X10007' => 'R', 'TS' => '2026021010:00:00#INSE#']],
        ];
        $tables['MZ_JMHZ2'] = [
            [['ID', 'C', 36], ['ID_JMHZ', 'C', 36], ['INT_ZAM', 'N', 10], ['XPRIJATO_Z', 'N', 2], ['X10051', 'N', 12], ['X10228', 'C', 100],
                ['X10229', 'C', 100], ['X10230', 'C', 10], ['X10231', 'C', 10], ['X10239', 'C', 10], ['X10261', 'N', 12, 2], ['TS', 'C', 40]],
            [['ID' => 'F5', 'ID_JMHZ' => 'J2601', 'INT_ZAM' => 5, 'XPRIJATO_Z' => 3, 'X10051' => 1234567895, 'X10228' => '1234567890123',
                'X10229' => 'Brno', 'X10230' => '582786', 'X10231' => 'CZ', 'X10239' => '1', 'X10261' => 38.75, 'TS' => '2026021010:00:00#INSE#']],
        ];
        $tables['MZ_ISPV'] = [[['INTER', 'N', 10], ['KZAM', 'C', 8], ['CZICSE', 'C', 4], ['ID', 'C', 36]], [['INTER' => 5, 'KZAM' => '25120', 'CZICSE' => '1111', 'ID' => 'I5']]];
        $tables['MZ_PRISO'] = [
            [['INTER', 'N', 8], ['KOD', 'C', 2], ['PRIJATO', 'L'], ['PRIJ_DAT', 'D'], ['ID', 'C', 36]],
            [
                ['INTER' => 5, 'KOD' => '1', 'PRIJATO' => true, 'PRIJ_DAT' => '2025-01-20', 'ID' => 'S5-1'],
                ['INTER' => 6, 'KOD' => '1', 'PRIJATO' => true, 'PRIJ_DAT' => '2025-04-05', 'ID' => 'S6-1'],
                ['INTER' => 6, 'KOD' => '2', 'PRIJATO' => true, 'PRIJ_DAT' => '2025-07-03', 'ID' => 'S6-2'],
            ],
        ];
        // Přihláška k VZP s nástupem, od 7/2025 změna pojišťovny (oznámení Q).
        array_push($tables['MZ_PRIZP'][1],
            ['INTER' => 5, 'HLAS_OD' => '2025-01-15', 'ZKRATKA_P' => '111', 'KOD' => 'P', 'PRIJATO' => true, 'ID' => 'ZP5-1'],
            ['INTER' => 5, 'HLAS_OD' => '2025-07-01', 'ZKRATKA_P' => '201', 'KOD' => 'Q', 'PRIJATO' => true, 'ID' => 'ZP5-2'],
        );
        $employee = static fn (int $gross, int $soc, int $zdr, int $socF, int $zdrF, int $tax, string $insurer): array => [
            'MZ_HRUBA' => $gross, 'VYM_SOC' => $gross, 'VYM_ZDR' => $gross, 'MZ_SOC' => $soc, 'MZ_ZDR' => $zdr, 'MZ_SOCF' => $socF, 'MZ_ZDRF' => $zdrF,
            'MZ_ZDANI' => $gross, 'MZ_DAN' => $tax, 'NEZD_VLAS' => 2570, 'POD_DAN' => true, 'NEZD_A' => true, 'MZ_CISTA' => $gross - $soc - $zdr - $tax,
            'MZ_VYPLATA' => $gross - $soc - $zdr - $tax, 'POJIS_SO' => true, 'ZKR_POJ' => $insurer, 'DNY_ODPR' => 20, 'UVA_DOBA' => 8,
        ];
        // Exekuce 1 000 Kč měsíčně od 10/2025 (v `SR_OST1` i v `DNY`), v 11/2025 navíc záloha
        // na mzdu 2 000 Kč, kterou `SR_*` nenese.
        $items = [];
        foreach (range(1, 12) as $m) {
            $values = $m < 7 ? $employee(30000, 2130, 1350, 7440, 2700, 1930, '111') : $employee(32000, 2272, 1440, 7936, 2880, 2230, '201');
            if ($m >= 10) {
                $values['SR_OST1'] = 1000;
                $values['MZ_VYPLATA'] -= 1000;
                $items[] = self::item5(2025, $m, '702', 1000, ['SRA_INT' => 2]);
            }
            if ($m === 11) {
                $values['MZ_VYPLATA'] -= 2000;
                $items[] = self::item5(2025, $m, '750', 2000);
            }
            $months[] = [5, 2025, $m, $values];
        }
        $months[] = [5, 2026, 1, $employee(32000, 2272, 1440, 7936, 2880, 2230, '201')];
        // Dovolená 4.-8. 8. 2025 (38,75 h) a pracovní neschopnost od 10. 11. 2025: náhrada (610),
        // pak nemocenská (600) do konce roku; případ eNeschopenky končí 20. 1. 2026.
        array_push($items,
            self::item5(2025, 8, '500', 5400, ['DATUM_OD' => '2025-08-04', 'DATUM_DO' => '2025-08-08', 'HODINY' => 38.75, 'N_DNY' => 5, 'TYP' => 2]),
            self::item5(2025, 11, '610', 4200, ['DATUM_OD' => '2025-11-10', 'DATUM_DO' => '2025-11-23', 'HODINY' => 69.75, 'N_DNY' => 14, 'TYP' => 3]),
            self::item5(2025, 11, '600', 0, ['DATUM_OD' => '2025-11-24', 'DATUM_DO' => '2025-11-30', 'N_DNY' => 7, 'TYP' => 3]),
            self::item5(2025, 12, '600', 0, ['DATUM_OD' => '2025-12-01', 'DATUM_DO' => '2025-12-31', 'N_DNY' => 31, 'TYP' => 3]),
        );
        if ($unknownCodes) {
            $items[] = self::item5(2025, 9, '557', 0, ['DATUM_OD' => '2025-09-15', 'DATUM_DO' => '2025-09-16', 'N_DNY' => 2, 'TYP' => 2]);
            $items[] = self::item5(2025, 9, '510', 800, ['TYP' => 2]);
        }
        // Trvalé složky vztahu: osobní ohodnocení (příjem, ne srážka), exekuce od 10/2025,
        // spoření skončené v 6/2025 a odborové příspěvky.
        $card = static fn (int $sra, string $code, string $text, array $values): array => $values + ['S_INTER' => 5, 'S_SRAINT' => $sra, 'S_KOD' => $code,
            'S_POPIS' => $text, 'ID' => "SR5-{$sra}"];
        $tables['MZ_SRAZ'] = [
            [['S_INTER', 'N', 10], ['S_KOD', 'C', 3], ['S_SRAINT', 'N', 10], ['S_POPIS', 'C', 64], ['S_CASTKA', 'N', 12, 3], ['S_CAST_SR', 'N', 12, 2],
                ['S_EXEKUCE', 'L'], ['S_DOCDAT', 'D'], ['S_PORADI', 'N', 2], ['S_MES_OD', 'N', 2], ['S_ROK_OD', 'N', 4], ['S_MES_DO', 'N', 2], ['S_ROK_DO', 'N', 4],
                ['S_DORUCIT', 'C', 64], ['S_UCET', 'C', 35], ['S_BANKOD', 'C', 11], ['S_VAR', 'C', 10], ['S_KONS', 'C', 4], ['ID', 'C', 36]],
            [
                $card(1, '303', 'Osobní ohodnocení', ['S_CASTKA' => 2000, 'S_MES_OD' => 1, 'S_ROK_OD' => 2025]),
                $card(2, '702', 'Exekuce - syntetická', ['S_CASTKA' => 50000, 'S_CAST_SR' => 1000, 'S_EXEKUCE' => true, 'S_DOCDAT' => '2025-09-15', 'S_PORADI' => 1,
                    'S_MES_OD' => 10, 'S_ROK_OD' => 2025, 'S_DORUCIT' => 'Exekutorský úřad Fiktivní', 'S_UCET' => '2000145399', 'S_BANKOD' => '0100',
                    'S_VAR' => '1234', 'S_KONS' => '558']),
                $card(3, '700', 'Spoření', ['S_CASTKA' => 500, 'S_MES_OD' => 1, 'S_ROK_OD' => 2025, 'S_MES_DO' => 6, 'S_ROK_DO' => 2025]),
                $card(4, '720', 'Odbory', ['S_CASTKA' => 150, 'S_MES_OD' => 1, 'S_ROK_OD' => 2025]),
                ...($unknownCodes ? [$card(5, '721', 'Neznámá složka', ['S_CASTKA' => 300, 'S_MES_OD' => 1, 'S_ROK_OD' => 2025])] : []),
            ],
        ];
        $tables['MZ_HDPN'] = [[['INTER', 'N', 10], ['HDPN_OD', 'D'], ['HDPN_DO', 'D'], ['TYP_NP', 'C', 3], ['C_LISTKU', 'C', 20], ['ID', 'C', 36]],
            [['INTER' => 5, 'HDPN_OD' => '2025-11-10', 'HDPN_DO' => '2026-01-20', 'TYP_NP' => 'DPN', 'C_LISTKU' => 'SYN-1', 'ID' => 'HD5']]];
        // Stav dovolené v hodinách: nárok 193,75 h, do 12/2025 vyčerpáno 38,75 h.
        $tables['DOV_DNY'] = [
            [['INTER', 'N', 8], ['MESIC', 'N', 2], ['ROK', 'N', 4], ['ZUS_MINR', 'N', 9, 4], ['CERPANI', 'N', 9, 4], ['NAROK', 'N', 9, 4], ['CERPANO_R', 'N', 9, 4],
                ['ZUST_MES', 'N', 11, 4], ['ID', 'C', 36]],
            [
                ['INTER' => 5, 'MESIC' => 8, 'ROK' => 2025, 'ZUS_MINR' => 0, 'CERPANI' => 38.75, 'NAROK' => 193.75, 'CERPANO_R' => 38.75, 'ZUST_MES' => 155, 'ID' => 'DD5-8'],
                ['INTER' => 5, 'MESIC' => 12, 'ROK' => 2025, 'ZUS_MINR' => 0, 'CERPANI' => 0, 'NAROK' => 193.75, 'CERPANO_R' => 38.75, 'ZUST_MES' => 155, 'ID' => 'DD5-12'],
            ],
        ];
        // Průměrný výdělek pro náhrady po měsících; ve čtvrtletí stejný.
        $averages = [];
        foreach (range(1, 12) as $m) {
            $quarter = (int) ceil($m / 3);
            // Pravděpodobný výdělek nástupce (Q1) PREMIER vede bez rozhodného období: od > do.
            $from = $quarter === 1 ? '2025-01-01' : sprintf('2025-%02d-01', ($quarter - 2) * 3 + 1);
            $to = $quarter === 1 ? '2024-12-31' : date('Y-m-t', (int) strtotime(sprintf('2025-%02d-01', ($quarter - 1) * 3)));
            $averages[] = ['XNINTER' => 5, 'XN_MES' => $m, 'XN_ROK' => 2025, 'XN_PRDO' => $quarter === 1 ? 180.5 : 190.25, 'XN_DRUH' => $quarter === 1 ? 'P' : 'R',
                'XROZOBDOD' => $from, 'XROZOBDDO' => $to, 'XVYM_DOV' => $quarter === 1 ? 0 : 90000, 'XHOD_SPL' => $quarter === 1 ? 0 : 480, 'ID' => "PR5-{$m}"];
        }
        $tables['PER_PRU'] = [
            [['XNINTER', 'N', 8], ['XN_MES', 'N', 2], ['XN_ROK', 'N', 4], ['XN_PRDO', 'N', 10, 4], ['XN_DRUH', 'C', 1], ['XROZOBDOD', 'D'], ['XROZOBDDO', 'D'],
                ['XVYM_DOV', 'N', 12, 2], ['XHOD_SPL', 'N', 7, 2], ['ID', 'C', 36]],
            $averages,
        ];
        $tables['DNY'] = [
            [['INTER', 'N', 8], ['DATUM_OD', 'D'], ['DATUM_DO', 'D'], ['KOD', 'C', 3], ['HODINY', 'N', 8, 4], ['N_DNY', 'N', 5, 2], ['CASTKA', 'N', 14, 2],
                ['SAZBA', 'N', 15, 4], ['SRA_INT', 'N', 10], ['DNY_ROK', 'N', 4], ['DNY_MES', 'N', 2], ['TYP', 'N', 2], ['KOD_BAZE', 'C', 3],
                ['C_LISTKU', 'C', 20], ['ZAC_NEM', 'D'], ['UKONCENO', 'L'], ['ID', 'C', 36]],
            $items,
        ];
        return [['SR_OST1', 'N', 12, 2]];
    }

    /**
     * Trvalé příjmy vztahu INTER 5 (`payroll_benefits`): příspěvek na penzijní připojištění
     * 1 000 Kč měsíčně (422, v mzdách `MZ_PENZ`), stravenkový paušál 129,50 Kč za směnu (712)
     * a příspěvek na praní 500 Kč (862), vše od 1/2025 bez konce.
     *
     * @param array<string,array{0:list<array{0:string,1:string,2?:int,3?:int}>,1:list<array<string,mixed>>}> $tables MĚNÍ SE
     */
    private static function benefits(array &$tables): void
    {
        $card = static fn (int $sra, string $code, string $text, float $amount): array => ['S_INTER' => 5, 'S_SRAINT' => $sra, 'S_KOD' => $code,
            'S_POPIS' => $text, 'S_CASTKA' => $amount, 'S_MES_OD' => 1, 'S_ROK_OD' => 2025, 'ID' => "SR5-{$sra}"];
        array_push(
            $tables['MZ_SRAZ'][1],
            $card(11, '422', 'Příspěvek na penzijní připojištění', 1000),
            $card(12, '712', 'Stravenkový paušál', 129.5),
            $card(13, '862', 'Příspěvek na praní', 500),
        );
        $tables['MZDY'][0][] = ['MZ_PENZ', 'N', 12, 2];
        // Soukromé životní pojištění 250 Kč v březnu 2025: patří do téhož koše.
        $tables['MZDY'][0][] = ['MZ_ZIV', 'N', 12, 2];
        foreach ($tables['MZDY'][1] as $i => $row) {
            $tables['MZDY'][1][$i]['MZ_PENZ'] = (int) $row['INTER'] === 5 ? 1000 : 0;
            $tables['MZDY'][1][$i]['MZ_ZIV'] = (int) $row['INTER'] === 5 && (int) $row['ROK'] === 2025 && (int) $row['MESIC'] === 3 ? 250 : 0;
        }
    }

    /**
     * Daňový nerezident (`payroll_nonresident`): osoba OS-F (INTER 6) s příznakem `NREZIDEN`
     * a kartou `PER_NERZ` (vazba `N_SUPINT` = `PER_MAIN.SUP_INTER`), stát rezidence SK.
     * Karta osoby OS-E je rezidentská (CZ) a převod ji nepoužije.
     *
     * @param array<string,array{0:list<array{0:string,1:string,2?:int,3?:int}>,1:list<array<string,mixed>>}> $tables MĚNÍ SE
     */
    private static function nonResident(array &$tables): void
    {
        foreach ($tables['PER_MAIN'][1] as $i => $row) {
            if ($row['ID'] === 'OS-F') {
                $tables['PER_MAIN'][1][$i] += ['NREZIDEN' => true, 'SUP_INTER' => 106];
            }
        }
        $tables['PER_NERZ'] = [
            [['N_SUPINT', 'N', 10], ['N_STAT', 'C', 2], ['N_STATDO', 'C', 2], ['N_TCISDO', 'C', 1], ['N_CISDO', 'C', 20], ['N_DIC', 'C', 20],
                ['N_ORGAN_D', 'C', 64], ['F_STAT', 'C', 2], ['ID', 'C', 36]],
            [
                ['N_SUPINT' => 105, 'N_STAT' => 'CZ', 'N_STATDO' => 'CZ', 'N_TCISDO' => 'P', 'N_CISDO' => 'XX0000005', 'F_STAT' => 'CZ', 'ID' => 'NZ5'],
                ['N_SUPINT' => 106, 'N_STAT' => 'SK', 'N_STATDO' => 'SK', 'N_TCISDO' => 'P', 'N_CISDO' => 'XX0000001', 'N_DIC' => 'SK 1000000001',
                    'N_ORGAN_D' => 'Fiktivní úřad', 'F_STAT' => 'SK', 'ID' => 'NZ6'],
            ],
        ];
    }

    /**
     * Položka mzdy vztahu INTER 5 (`DNY`) za měsíc; interval je celý měsíc, pokud ho
     * `$extra` neurčí jinak.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function item5(int $year, int $month, string $code, float $amount, array $extra = []): array
    {
        $from = sprintf('%04d-%02d-01', $year, $month);
        return $extra + ['INTER' => 5, 'DATUM_OD' => $from, 'DATUM_DO' => date('Y-m-t', (int) strtotime($from)), 'KOD' => $code, 'CASTKA' => $amount,
            'DNY_ROK' => $year, 'DNY_MES' => $month, 'TYP' => 6, 'ID' => "D5-{$year}-{$month}-{$code}"];
    }

    /**
     * Výpis s více pohyby v jednom dni, řádky bez pohybu a EUR účet (`bank_split`).
     *
     * @param array<string,array{0:list<array{0:string,1:string,2?:int,3?:int}>,1:list<array<string,mixed>>}> $tables MĚNÍ SE
     * @param list<array{0:string,1:string,2:string}> $chart MĚNÍ SE
     */
    private static function bankSplit(array &$tables, array &$chart): void
    {
        array_push($chart, ['221', '002', 'Běžný účet EUR'], ['548', '000', 'Ostatní provozní náklady'], ['663', '000', 'Kurzové zisky']);
        $customer = ['CISLO_ODB' => '1', 'NAZEV_ODB' => 'Odběratel Fiktivní s.r.o.', 'ICO_ODB' => self::CUSTOMER_ICO, 'DIC_ODB' => 'CZ' . self::CUSTOMER_ICO, 'ID_PAR' => 'P1'];
        $date = '2025-10-15';
        array_push($tables['PUB_UCTO'][1],
            self::row(55, '2025-10-01', 'VF', '250005', 'Správa serveru', 1000, '311000', '602100', self::vat(self::CODE_SALE, 'P', 'VF', 6) + $customer),
            self::row(56, '2025-10-01', 'VF', '250005', 'DPH', 210, '311000', '343021', self::vat(self::CODE_SALE, 'D', 'VF', 6) + $customer),
            self::row(57, '2025-10-01', 'VF', '250006', 'Školení', 2000, '311000', '602100', self::vat(self::CODE_SALE, 'P', 'VF', 7) + $customer),
            self::row(58, '2025-10-01', 'VF', '250006', 'DPH', 420, '311000', '343021', self::vat(self::CODE_SALE, 'D', 'VF', 7) + $customer),
            self::row(90, '2025-10-01', 'VF', '250007', 'Konzultace', 500, '311000', '602100', self::vat(self::CODE_SALE, 'P', 'VF', 8) + $customer),
            self::row(91, '2025-10-01', 'VF', '250007', 'DPH', 105, '311000', '343021', self::vat(self::CODE_SALE, 'D', 'VF', 8) + $customer),
            // Výpis BV 8: tři pohyby a dva řádky bez pohybu v jednom dni. Řádek haléřového
            // vyrovnání je v deníku před úhradou, kterou dorovnává.
            self::row(47, $date, 'BV', '8', 'Výběr hotovosti', 1000, '211001', '221001'),
            self::row(49, $date, 'BV', '8', 'Haléřové vyrovnání', 0.40, '548000', '311000', $customer),
            self::row(50, $date, 'BV', '8', 'Úhrada VF 250005', 1209.60, '221001', '311000', [
                'VAR_DAL' => '250005', 'HUCET' => '000000-1000000005/0100', 'HKS' => '0308', 'HSPEC' => '0000000000', 'HVAR' => '999',
                'HZPR_PRIJ' => 'Platba faktury 250005', 'PARTRAN' => 'SYN-TX-0001',
            ] + $customer),
            self::row(52, $date, 'BV', '8', 'Úhrada VF 250006', 2420, '221001', '311000', ['VARIABL' => '250006', 'VAR_DAL' => '111', 'HUCET' => '000000-0000000000/0000'] + $customer),
            self::row(53, $date, 'BV', '8', 'Poplatek za platbu', 25, '568000', '221001', ['HVAR' => '777', 'HSPEC' => '42']),
            self::row(54, $date, 'BV', '8', 'Kurzový rozdíl', 5, '311000', '663000'),
            // Úhrada VF 250007 zápočtem proti závazku - bez pohybu peněz.
            self::row(59, '2025-11-01', 'ID', '5', 'Zápočet VF 250007', 605, '321000', '311000', $customer),
            // EUR účet: vklad 1 000 EUR a kurzové přecenění (částka v měně 0) na konci roku.
            self::row(80, '2025-09-01', 'BE', '1', 'Vklad EUR', 25000, '221002', '411000', ['MENA' => 'EUR', 'ZCASTKA' => 1000, 'KURS' => 25, 'M_KURS' => 1]),
            self::row(81, '2025-12-31', 'BE', '2', 'Kurzové přecenění', 500, '221002', '663000', ['MENA' => 'EUR', 'ZCASTKA' => 0, 'KURS' => 25.5, 'M_KURS' => 1]),
        );
        array_push($tables['VAZBY'][1],
            ['KOD_ZDR' => 'UD', 'INT_ZDR' => 50, 'KOD_TER' => 'VF', 'INT_TER' => 6],
            ['KOD_ZDR' => 'UD', 'INT_ZDR' => 49, 'KOD_TER' => 'VF', 'INT_TER' => 6],
            ['KOD_ZDR' => 'UD', 'INT_ZDR' => 52, 'KOD_TER' => 'VF', 'INT_TER' => 7],
            ['KOD_ZDR' => 'UD', 'INT_ZDR' => 59, 'KOD_TER' => 'VF', 'INT_TER' => 8],
        );
        $header = ['FORMA' => 'převodem', 'ULICE_ODB' => 'Zkušební 10', 'MESTO_ODB' => 'Praha', 'PSC_ODB' => '110 00', 'STAT_ODB' => 'Česká republika'] + $customer;
        array_push($tables['FA_OUT'][1],
            self::header(6, 'VF', '250005', '2025-10-01', 'Správa serveru', ['VS' => '250005'] + $header),
            self::header(7, 'VF', '250006', '2025-10-01', 'Školení', ['VS' => '250006'] + $header),
            self::header(8, 'VF', '250007', '2025-10-01', 'Konzultace', ['VS' => '250007'] + $header),
        );
        array_push($tables['POLOZKY'][1],
            self::item(6, 1, 'Správa serveru', 1, 'měs', 1000, 210, 21, self::CODE_SALE),
            self::item(7, 1, 'Školení', 1, 'ks', 2000, 420, 21, self::CODE_SALE),
            self::item(8, 1, 'Konzultace', 1, 'hod', 500, 105, 21, self::CODE_SALE),
        );
        $tables['DOKL_PU'][1][] = ['DOKLAD' => 'BE', 'TOK' => 2, 'MD' => '221', 'MDA' => '002', 'MENA' => 'EUR', 'TEXT' => 'Bankovní výpisy EUR', 'NAZEV_B' => 'Fiktivní banka EUR'];
    }

    /** @return list<array{0:string,1:string,2?:int,3?:int}> */
    private static function cardFields(): array
    {
        return [['INTER', 'N', 10], ['ID', 'C', 10], ['DOKLAD', 'C', 5], ['CISLO', 'C', 20], ['POPIS', 'C', 50], ['DATUM', 'D'], ['DATUM_P', 'D'],
            ['DATUM_UO', 'D'], ['DATUM_V', 'D'], ['KUSY', 'N', 10, 3], ['CENA', 'N', 15, 2], ['D_CENA', 'N', 15, 2], ['ZPUSOB', 'N', 2], ['SKUPINA', 'N', 2],
            ['PMD', 'C', 6], ['PDAL', 'C', 6], ['UMD', 'C', 6], ['UDAL', 'C', 6], ['UMISTENI', 'C', 40], ['JMENO_ODP', 'C', 40], ['POZNAMKA', 'C', 60]];
    }

    /** @return list<array<string,mixed>> */
    private static function chart(): array
    {
        $out = [];
        $accounts = [
            ['211', '001', 'Pokladna'], ['221', '001', 'Běžný účet'], ['311', '000', 'Odběratelé'], ['321', '000', 'Dodavatelé'],
            ['343', '021', 'DPH 21 %'], ['343', '090', 'DPH OSS'], ['343', '100', 'DPH samovyměření - odpočet'], ['343', '200', 'DPH samovyměření - daň'],
            ['411', '000', 'Základní kapitál'], ['431', '000', 'Výsledek hospodaření ve schvalovacím řízení'],
            ['501', '100', 'Spotřeba materiálu'], ['518', '100', 'Ostatní služby'], ['568', '000', 'Bankovní poplatky'],
            ['513', '100', 'Reprezentace'], ['602', '100', 'Tržby za služby'], ['604', '100', 'Tržby za zboží'],
        ];
        foreach ([self::YEAR1, self::YEAR2] as $year) {
            foreach ($accounts as [$synthetic, $analytic, $name]) {
                $out[] = ['UCET' => $synthetic, 'ANALYT' => $analytic, 'TEXT' => $name, 'ROK' => $year, 'NEDANOVY' => $synthetic === '513'];
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function journal(bool $oss): array
    {
        $customer = ['CISLO_ODB' => '1', 'NAZEV_ODB' => 'Odběratel Fiktivní s.r.o.', 'ICO_ODB' => self::CUSTOMER_ICO, 'DIC_ODB' => 'CZ' . self::CUSTOMER_ICO, 'ID_PAR' => 'P1'];
        $vendor = ['CISLO_ODB' => '2', 'NAZEV_ODB' => 'Dodavatel Fiktivní s.r.o.', 'ICO_ODB' => self::VENDOR_ICO, 'DIC_ODB' => 'CZ' . self::VENDOR_ICO, 'ID_PAR' => 'P2'];
        $euVendor = ['CISLO_ODB' => '3', 'NAZEV_ODB' => 'Fiktiv Software GmbH', 'DIC_ODB' => self::EU_VENDOR_DIC, 'STAT_ODB' => 'Německo', 'ID_PAR' => 'P3'];
        $rows = [
            self::row(10, '2025-01-02', 'PP', '1', 'Vklad do pokladny', 5000, '211001', '411000'),
            self::row(11, '2025-01-02', 'BV', '1', 'Splacení základního kapitálu', 200000, '221001', '411000', ['POZNAMKA' => 'Vklad společníka - převod z účtu č. 1000000005/0100']),
            self::row(12, '2025-02-10', 'VF', '250001', 'Programátorské služby', 10000, '311000', '602100', self::vat(self::CODE_SALE, 'P', 'VF', 1) + $customer),
            self::row(13, '2025-02-10', 'VF', '250001', 'DPH', 2100, '311000', '343021', self::vat(self::CODE_SALE, 'D', 'VF', 1) + $customer),
            self::row(14, '2025-02-20', 'BV', '2', 'Úhrada VF 250001', 12100, '221001', '311000', ['VARIABL' => '250001'] + $customer),
            self::row(15, '2025-03-05', 'PF', '250001', 'Kancelářské potřeby', 1000, '518100', '321000', self::vat(self::CODE_PURCHASE, 'P', 'PF', 101) + $vendor),
            self::row(16, '2025-03-05', 'PF', '250001', 'DPH', 210, '343021', '321000', self::vat(self::CODE_PURCHASE, 'D', 'PF', 101) + $vendor),
            self::row(17, '2025-03-15', 'BV', '3', 'Úhrada PF 250001', 1210, '321000', '221001', ['VARIABL' => '7001'] + $vendor),
            // Služba z EU: jen základ, samovyměření na 343 účetní nezaúčtovala.
            self::row(18, '2025-04-10', 'PF', '250002', 'Licence software', 5000, '518100', '321000', self::vat(self::CODE_EU_SERVICE, 'P', 'PF', 102) + $euVendor),
            // Táž služba se samovyměřením MD 343 / D 343 - řádek daně je v deníku před základem.
            self::row(19, '2025-05-12', 'PF', '250003', 'Samovyměření DPH', 630, '343100', '343200', self::vat(self::CODE_EU_SERVICE, 'D', 'PF', 103) + $euVendor),
            self::row(20, '2025-05-12', 'PF', '250003', 'Hosting', 3000, '518100', '321000', self::vat(self::CODE_EU_SERVICE, 'P', 'PF', 103) + $euVendor),
            self::row(21, '2025-05-20', 'BV', '4', 'Úhrada PF 250003', 3000, '321000', '221001', $euVendor),
            // Dobropis zápornými částkami na stejné strany.
            self::row(22, '2025-06-01', 'VF', '250002', 'Sleva za služby', -1000, '311000', '602100', self::vat(self::CODE_SALE, 'P', 'VF', 2) + $customer),
            self::row(23, '2025-06-01', 'VF', '250002', 'DPH', -210, '311000', '343021', self::vat(self::CODE_SALE, 'D', 'VF', 2) + $customer),
            self::row(24, '2025-06-10', 'BV', '5', 'Vrácení dobropisu VF 250002', 1210, '311000', '221001', ['VARIABL' => '250002'] + $customer),
            // Faktura v EUR: deník v Kč, cizí měna na řádcích závazku.
            self::row(25, '2025-07-01', 'PF', '250004', 'Konzultace', 10051.72, '518100', '321000',
                self::vat(self::CODE_PURCHASE, 'P', 'PF', 104) + ['MENA' => 'EUR', 'ZCASTKA' => 400.10, 'KURS' => 25.123, 'M_KURS' => 1] + $vendor),
            self::row(26, '2025-07-01', 'PF', '250004', 'DPH', 2110.86, '343021', '321000',
                self::vat(self::CODE_PURCHASE, 'D', 'PF', 104) + ['MENA' => 'EUR', 'ZCASTKA' => 84.02, 'KURS' => 25.123, 'M_KURS' => 1] + $vendor),
            self::row(27, '2025-07-20', 'BV', '6', 'Úhrada PF 250004', 12162.58, '321000', '221001', $vendor),
            // Pokladní výdej s DPH.
            self::row(28, '2025-08-05', 'PV', '1', 'Nákup materiálu', 500, '501100', '211001', ['KOD_DPH' => self::CODE_PURCHASE, 'SAZBA_DPH' => 21, 'CASTKA_DPH' => 105, 'IKOD' => 'P']),
            self::row(29, '2025-08-05', 'PV', '1', 'DPH', 105, '343021', '211001', ['KOD_DPH' => self::CODE_PURCHASE, 'SAZBA_DPH' => 21, 'IKOD' => 'D']),
            self::row(30, '2025-12-31', 'BV', '7', 'Poplatek za vedení účtu', 50, '568000', '221001'),

            self::row(40, '2026-01-15', 'BV', '1', 'Úhrada PF 250002', 5000, '321000', '221001', $euVendor),
            self::row(41, '2026-02-10', 'VF', '260001', 'Programátorské služby', 20000, '311000', '602100', self::vat(self::CODE_SALE, 'P', 'VF', 4) + $customer),
            self::row(42, '2026-02-10', 'VF', '260001', 'DPH', 4200, '311000', '343021', self::vat(self::CODE_SALE, 'D', 'VF', 4) + $customer),
            self::row(43, '2026-02-25', 'BV', '2', 'Úhrada VF 260001', 24200, '221001', '311000', ['VARIABL' => '260001'] + $customer),
            self::row(44, '2026-03-03', 'PF', '260001', 'Kancelářské potřeby', 2000, '518100', '321000', self::vat(self::CODE_PURCHASE, 'P', 'PF', 105) + $vendor),
            self::row(45, '2026-03-03', 'PF', '260001', 'DPH', 420, '343021', '321000', self::vat(self::CODE_PURCHASE, 'D', 'PF', 105) + $vendor),
        ];
        if ($oss) {
            $sk = ['NAZEV_ODB' => 'Zákazník Bratislava', 'STAT_ODB' => 'Slovensko'];
            $rows[] = self::row(31, '2025-09-01', 'VF', self::OSS_DOCUMENT, 'Zboží na dálku SK', 1000, '311000', '604100', ['SAZBA_DPH' => 23] + self::vat(self::CODE_OUTSIDE, 'P', 'VF', 3) + $sk);
            $rows[] = self::row(32, '2025-09-01', 'VF', self::OSS_DOCUMENT, 'DPH OSS', 230, '311000', '343090', ['SAZBA_DPH' => 23] + self::vat(self::CODE_OUTSIDE, 'D', 'VF', 3) + $sk);
        }
        // Záznam smazaný v PREMIER (hvězdička) - převod ho nesmí vidět.
        $rows[] = self::row(99, '2025-03-01', 'ID', '9', 'Smazaný zápis', 99999, '518100', '221001') + ['_deleted' => true];
        return $rows;
    }

    /** @return array<string,mixed> */
    private static function vat(string $code, string $kind, string $sbKod, int $sbornik): array
    {
        return ['KOD_DPH' => $code, 'SAZBA_DPH' => $code === self::CODE_OUTSIDE ? 0 : 21, 'IKOD' => $kind, 'SB_KOD' => $sbKod, 'SBORNIK' => $sbornik];
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function row(int $inter, string $date, string $series, string $number, string $text, float $amount, string $md, string $dal, array $extra = []): array
    {
        return $extra + [
            'INTER' => $inter, 'DATUM' => $date, 'DATUM_DPH' => $date, 'DOKLAD' => $series, 'CISLO' => $number, 'POPIS' => $text,
            'CASTKA' => $amount, 'MD' => $md, 'DAL' => $dal, 'M_KURS' => 1,
        ];
    }

    /** @return list<array{0:string,1:string,2?:int,3?:int}> */
    private static function headerFields(string $symbol): array
    {
        return [['INTER', 'N', 10], ['DOKLAD', 'C', 5], ['CISLO', 'C', 10], ['DATUM_VYS', 'D'], ['DATUM_USK', 'D'], ['DATUM_SPL', 'D'], ['DATUM_DPH', 'D'],
            ['DATUM_KVY', 'D'], ['MENA', 'C', 3], ['KURS', 'N', 10, 4], ['M_KURS', 'N', 5], ['POPIS', 'C', 50], [$symbol, 'C', 12], ['CISLO_PF', 'C', 20],
            ['K_SYMBOL', 'C', 4], ['UCET_ODB', 'C', 30], ['FORMA', 'C', 15], ['STORNO_FA', 'L'], ['CISLO_ODB', 'C', 10], ['NAZEV_ODB', 'C', 50],
            ['ICO_ODB', 'C', 12], ['DIC_ODB', 'C', 14], ['ULICE_ODB', 'C', 40], ['MESTO_ODB', 'C', 30], ['PSC_ODB', 'C', 6], ['STAT_ODB', 'C', 30], ['ID_PAR', 'C', 10]];
    }

    /** @return list<array{0:string,1:string,2?:int,3?:int}> */
    private static function itemFields(): array
    {
        return [['FAKTURA', 'N', 10], ['POL_SORT', 'N', 5], ['PORDER', 'N', 5], ['TEXT', 'C', 50], ['TEXT_2', 'C', 50], ['MNOZSTVI', 'N', 12, 3],
            ['MJ', 'C', 5], ['CENA', 'N', 15, 2], ['CENA_DPH', 'N', 15, 2], ['SAZBA_DPH', 'N', 5, 2], ['KOD_DPH', 'C', 3],
            ['UC_S', 'C', 3], ['UC_SA', 'C', 3], ['UC_D', 'C', 3], ['UC_DA', 'C', 3]];
    }

    /** @return list<array<string,mixed>> */
    private static function issued(bool $oss): array
    {
        $customer = ['CISLO_ODB' => '1', 'NAZEV_ODB' => 'Odběratel Fiktivní s.r.o.', 'ICO_ODB' => self::CUSTOMER_ICO, 'DIC_ODB' => 'CZ' . self::CUSTOMER_ICO,
            'ULICE_ODB' => 'Zkušební 10', 'MESTO_ODB' => 'Praha', 'PSC_ODB' => '110 00', 'STAT_ODB' => 'Česká republika', 'ID_PAR' => 'P1'];
        $rows = [
            self::header(1, 'VF', '250001', '2025-02-10', 'Programátorské služby', ['VS' => '250001', 'FORMA' => 'převodem'] + $customer),
            self::header(2, 'VF', '250002', '2025-06-01', 'Dobropis - sleva za služby', ['VS' => '250002', 'FORMA' => 'převodem'] + $customer),
            self::header(4, 'VF', '260001', '2026-02-10', 'Programátorské služby', ['VS' => '260001', 'FORMA' => 'převodem'] + $customer),
            // Rozpracovaný prázdný doklad bez položek i zápisu se nepřevádí.
            self::header(5, 'VF', '260099', '2026-05-01', 'Rozpracováno', $customer),
        ];
        if ($oss) {
            $rows[] = self::header(3, 'VF', self::OSS_DOCUMENT, '2025-09-01', 'Zboží na dálku', ['VS' => self::OSS_DOCUMENT, 'NAZEV_ODB' => 'Zákazník Bratislava',
                'ULICE_ODB' => 'Hlavná 1', 'MESTO_ODB' => 'Bratislava', 'PSC_ODB' => '81101', 'STAT_ODB' => 'Slovensko']);
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private static function issuedItems(bool $oss): array
    {
        $rows = [
            self::item(1, 1, 'Vývoj aplikace', 8, 'hod', 8000, 1680, 21, self::CODE_SALE),
            self::item(1, 2, 'Konzultace', 2, 'hod', 2000, 420, 21, self::CODE_SALE),
            self::item(2, 1, 'Sleva za služby', 1, 'ks', -1000, -210, 21, self::CODE_SALE),
            self::item(4, 1, 'Vývoj aplikace', 20, 'hod', 20000, 4200, 21, self::CODE_SALE),
        ];
        if ($oss) {
            $rows[] = self::item(3, 1, 'Zboží', 1, 'ks', 1000, 230, 23, self::CODE_OUTSIDE);
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private static function purchases(): array
    {
        $vendor = ['CISLO_ODB' => '2', 'NAZEV_ODB' => 'Dodavatel Fiktivní s.r.o.', 'ICO_ODB' => self::VENDOR_ICO, 'DIC_ODB' => 'CZ' . self::VENDOR_ICO,
            'ULICE_ODB' => 'Vzorová 5', 'MESTO_ODB' => 'Ostrava', 'PSC_ODB' => '70200', 'ID_PAR' => 'P2', 'UCET_ODB' => '1000000005/0100', 'FORMA' => 'příkazem'];
        $euVendor = ['CISLO_ODB' => '3', 'NAZEV_ODB' => 'Fiktiv Software GmbH', 'DIC_ODB' => self::EU_VENDOR_DIC, 'ULICE_ODB' => 'Musterstraße 1',
            'MESTO_ODB' => 'Berlin', 'PSC_ODB' => '10115', 'STAT_ODB' => 'Německo', 'ID_PAR' => 'P3'];
        return [
            self::header(101, 'PF', '250001', '2025-03-05', 'Kancelářské potřeby', ['VARIABL' => '7001', 'CISLO_PF' => 'D-2025-7001'] + $vendor),
            self::header(102, 'PF', '250002', '2025-04-10', 'Licence software', ['VARIABL' => '', 'CISLO_PF' => 'INV-2025-0410'] + $euVendor),
            self::header(103, 'PF', '250003', '2025-05-12', 'Hosting', ['CISLO_PF' => 'INV-2025-0512'] + $euVendor),
            self::header(104, 'PF', '250004', '2025-07-01', 'Konzultace v EUR', ['VARIABL' => '7004', 'CISLO_PF' => 'D-2025-7004', 'MENA' => 'EUR', 'KURS' => 25.123, 'M_KURS' => 1] + $vendor),
            self::header(105, 'PF', '260001', '2026-03-03', 'Kancelářské potřeby', ['VARIABL' => '8001', 'CISLO_PF' => 'D-2026-8001'] + $vendor),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function purchaseItems(): array
    {
        return [
            self::item(101, 1, 'Papír A4', 10, 'bal', 1000, 210, 21, self::CODE_PURCHASE),
            self::item(102, 1, 'Licence software', 1, 'ks', 5000, 0, 21, self::CODE_EU_SERVICE),
            self::item(103, 1, 'Hosting', 1, 'ks', 3000, 0, 21, self::CODE_EU_SERVICE),
            // EUR: 100,00 + 300,10 EUR × 25,123 = 2 512,30 + 7 539,41; deník 10 051,72 (rozdíl 0,01 na větší položku).
            self::item(104, 1, 'Konzultace - přípravná', 1, 'ks', 100.00, 21.00, 21, self::CODE_PURCHASE),
            self::item(104, 2, 'Konzultace - hlavní', 1, 'ks', 300.10, 63.02, 21, self::CODE_PURCHASE),
            self::item(105, 1, 'Toner', 2, 'ks', 2000, 420, 21, self::CODE_PURCHASE),
        ];
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function header(int $inter, string $series, string $number, string $date, string $text, array $extra): array
    {
        return $extra + [
            'INTER' => $inter, 'DOKLAD' => $series, 'CISLO' => $number, 'DATUM_VYS' => $date, 'DATUM_USK' => $date,
            'DATUM_SPL' => date('Y-m-d', strtotime($date . ' +14 days')), 'DATUM_DPH' => $date, 'MENA' => 'CZK', 'KURS' => 1, 'M_KURS' => 1, 'POPIS' => $text,
        ];
    }

    /** @return array<string,mixed> */
    private static function item(int $invoice, int $order, string $text, float $qty, string $unit, float $price, float $vat, float $rate, string $code): array
    {
        return ['FAKTURA' => $invoice, 'POL_SORT' => $order, 'PORDER' => $order, 'TEXT' => $text, 'MNOZSTVI' => $qty, 'MJ' => $unit,
            'CENA' => $price, 'CENA_DPH' => $vat, 'SAZBA_DPH' => $rate, 'KOD_DPH' => $code];
    }
}
