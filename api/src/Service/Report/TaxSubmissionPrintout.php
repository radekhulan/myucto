<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * Tisková podoba ODESLANÉHO podání DPH, KH nebo SH: vychází výhradně z uloženého XML
 * snapshotu, ne z aktuálních dat, aby PDF odpovídalo tomu, co finanční správa dostala.
 *
 * Řádky přiznání k DPH se popisují podle {@see DphPriznaniBuilder::LINE_MAP}, tedy
 * stejné mapy, podle které se XML skládá. Atribut, který tisk nezná, se nevynechá:
 * vytiskne se pod svým názvem z XML.
 */
final class TaxSubmissionPrintout
{
    /** Výkazy, jejichž XML umí tisk zobrazit. */
    public const FORM_CODES = ['dphdp3', 'dphkh1', 'dphshv'];

    public const FORM_TITLES = [
        'dphdp3' => 'Přiznání k dani z přidané hodnoty',
        'dphkh1' => 'Kontrolní hlášení DPH',
        'dphshv' => 'Souhrnné hlášení',
    ];

    private const VARIANTS = [
        'dphdp3' => ['B' => 'řádné', 'O' => 'opravné', 'D' => 'dodatečné', 'E' => 'dodatečné/opravné'],
        'dphkh1' => ['B' => 'řádné', 'O' => 'řádné opravné', 'N' => 'následné', 'E' => 'následné opravné'],
        'dphshv' => ['R' => 'řádné', 'N' => 'následné'],
    ];

    private const STATUS_LABELS = [
        'draft' => 'koncept', 'generated' => 'vygenerováno', 'downloaded' => 'staženo',
        'submitted' => 'podáno', 'accepted' => 'přijato', 'rejected' => 'zamítnuto',
    ];

    /** Krátké texty řádků tiskopisu DPHDP3. */
    private const DP3_LINES = [
        '1' => 'Dodání zboží a poskytnutí služeb s místem plnění v tuzemsku, základní sazba',
        '2' => 'Dodání zboží a poskytnutí služeb s místem plnění v tuzemsku, snížená sazba',
        '3' => 'Pořízení zboží z jiného členského státu, základní sazba',
        '4' => 'Pořízení zboží z jiného členského státu, snížená sazba',
        '5' => 'Přijetí služby z jiného členského státu (§ 9 odst. 1), základní sazba',
        '6' => 'Přijetí služby z jiného členského státu (§ 9 odst. 1), snížená sazba',
        '7' => 'Dovoz zboží (§ 23), základní sazba',
        '8' => 'Dovoz zboží (§ 23), snížená sazba',
        '9' => 'Pořízení nového dopravního prostředku',
        '10' => 'Režim přenesení daňové povinnosti (§ 92a), příjemce, základní sazba',
        '11' => 'Režim přenesení daňové povinnosti (§ 92a), příjemce, snížená sazba',
        '12' => 'Ostatní plnění s povinností přiznat daň příjemcem, základní sazba',
        '13' => 'Ostatní plnění s povinností přiznat daň příjemcem, snížená sazba',
        '20' => 'Dodání zboží do jiného členského státu',
        '21' => 'Poskytnutí služeb s místem plnění v jiném členském státě',
        '22' => 'Vývoz zboží',
        '23' => 'Dodání nového dopravního prostředku osobě neregistrované k dani',
        '24' => 'Vybraná plnění (§ 110b odst. 2)',
        '25' => 'Režim přenesení daňové povinnosti (§ 92a), dodavatel',
        '26' => 'Ostatní uskutečněná plnění s nárokem na odpočet',
        '30' => 'Třístranný obchod, pořízení zboží prostřední osobou',
        '31' => 'Třístranný obchod, dodání zboží prostřední osobou',
        '32' => 'Dovoz zboží osvobozený (§ 71g)',
        '33' => 'Oprava výše daně u pohledávek za dlužníky (věřitel)',
        '34' => 'Oprava výše daně u pohledávek za dlužníky (dlužník)',
        '40' => 'Přijatá plnění od plátců v tuzemsku, základní sazba',
        '41' => 'Přijatá plnění od plátců v tuzemsku, snížená sazba',
        '42' => 'Dovoz zboží (§ 23)',
        '43' => 'Plnění z řádků 3 až 13, základní sazba',
        '44' => 'Plnění z řádků 3 až 13, snížená sazba',
        '45' => 'Korekce odpočtů daně',
        '46' => 'Odpočet daně celkem',
        '47' => 'Hodnota pořízeného majetku',
        '50' => 'Plnění osvobozená od daně bez nároku na odpočet',
        '51' => 'Plnění nezapočítávaná do koeficientu (§ 76 odst. 4)',
        '52' => 'Koeficient a odpočet v krácené výši',
        '53' => 'Vypořádací koeficient a změna odpočtu',
        '60' => 'Úprava odpočtu daně (§ 78 a násl.)',
        '61' => 'Vrácení daně (§ 84)',
        '62' => 'Daň na výstupu celkem',
        '63' => 'Odpočet daně celkem',
        '64' => 'Vlastní daň',
        '65' => 'Nadměrný odpočet',
        '66' => 'Rozdíl oproti poslední známé dani',
    ];

    /**
     * Atributy DPHDP3 mimo {@see DphPriznaniBuilder::LINE_MAP}: součtové a rekapitulační
     * řádky. Atribut → [řádek, sloupec].
     */
    private const DP3_EXTRA = [
        'odp_rez_nar'    => ['45', 'full'],
        'odp_rezim'      => ['45', 'reduced'],
        'odp_sum_nar'    => ['46', 'full'],
        'odp_sum_kr'     => ['46', 'reduced'],
        'od_maj'         => ['47', 'full'],
        'odkr_maj'       => ['47', 'reduced'],
        'koef_p20_nov'   => ['52', 'base'],
        'odp_uprav_kf'   => ['52', 'full'],
        'koef_p20_vypor' => ['53', 'base'],
        'vypor_odp'      => ['53', 'full'],
        'uprav_odp'      => ['60', 'full'],
        'vrac_dani'      => ['61', 'full'],
        'dan_zocelk'     => ['62', 'full'],
        'odp_zocelk'     => ['63', 'full'],
        'dano_da'        => ['64', 'full'],
        'dano_no'        => ['65', 'full'],
        'dano'           => ['66', 'full'],
    ];

    private const DP3_SECTIONS = [
        'Veta1' => 'I. Zdanitelná plnění',
        'Veta2' => 'II. Ostatní plnění a plnění s místem plnění mimo tuzemsko s nárokem na odpočet',
        'Veta3' => 'III. Doplňující údaje',
        'Veta4' => 'IV. Nárok na odpočet daně',
        'Veta5' => 'V. Krácení nároku na odpočet daně',
        'Veta6' => 'VI. Výpočet daně',
    ];

    private const RECORD_TITLES = [
        'VetaA1' => 'A.1 Uskutečněná plnění v režimu přenesení daňové povinnosti',
        'VetaA2' => 'A.2 Přijatá plnění, u kterých je povinen přiznat daň příjemce',
        'VetaA3' => 'A.3 Uskutečněná plnění v režimu zvláštního postupu (investiční zlato)',
        'VetaA4' => 'A.4 Uskutečněná plnění nad 10 000 Kč',
        'VetaA5' => 'A.5 Uskutečněná plnění do 10 000 Kč (souhrnně)',
        'VetaB1' => 'B.1 Přijatá plnění v režimu přenesení daňové povinnosti',
        'VetaB2' => 'B.2 Přijatá plnění nad 10 000 Kč',
        'VetaB3' => 'B.3 Přijatá plnění do 10 000 Kč (souhrnně)',
        'VetaC'  => 'C. Kontrolní řádky',
        'VetaR'  => 'Řádky hlášení',
    ];

    private const IDENTITY_LABELS = [
        'dokument' => 'Tiskopis', 'k_uladis' => 'Daň', 'rok' => 'Rok', 'mesic' => 'Měsíc',
        'ctvrt' => 'Čtvrtletí', 'dapdph_forma' => 'Typ přiznání', 'khdph_forma' => 'Typ hlášení',
        'shvies_forma' => 'Typ hlášení', 'd_poddp' => 'Datum v podání', 'd_zjist' => 'Datum zjištění důvodů',
        'typ_platce' => 'Typ plátce', 'c_okec' => 'CZ-NACE', 'vyzva_odp' => 'Odpověď na výzvu',
        'c_jed_vyzvy' => 'Č. j. výzvy', 'c_ufo' => 'Kód finančního úřadu', 'c_pracufo' => 'Kód územního pracoviště',
        'dic' => 'DIČ', 'typ_ds' => 'Typ subjektu', 'zkrobchjm' => 'Název', 'jmeno' => 'Jméno',
        'prijmeni' => 'Příjmení', 'titul' => 'Titul', 'ulice' => 'Ulice', 'c_pop' => 'Číslo popisné',
        'c_orient' => 'Číslo orientační', 'naz_obce' => 'Obec', 'psc' => 'PSČ', 'stat' => 'Stát',
        'email' => 'E-mail', 'c_telef' => 'Telefon', 'opr_jmeno' => 'Oprávněná osoba, jméno',
        'opr_prijmeni' => 'Oprávněná osoba, příjmení', 'opr_postaveni' => 'Postavení oprávněné osoby',
        'sest_jmeno' => 'Sestavil, jméno', 'sest_prijmeni' => 'Sestavil, příjmení', 'sest_telef' => 'Sestavil, telefon',
        'zast_typ' => 'Typ zástupce', 'zast_kod' => 'Kód zástupce', 'zast_nazev' => 'Zástupce, název',
        'zast_jmeno' => 'Zástupce, jméno', 'zast_prijmeni' => 'Zástupce, příjmení', 'zast_ic' => 'Zástupce, IČ',
        'zast_ev_cislo' => 'Zástupce, ev. číslo', 'zast_dat_nar' => 'Zástupce, datum narození',
    ];

    private const COLUMN_LABELS = [
        'c_radku' => 'Ř.', 'dic_odb' => 'DIČ odběratele', 'dic_dod' => 'DIČ dodavatele',
        'k_stat' => 'Stát', 'vatid_dod' => 'DIČ dodavatele', 'c_evid_dd' => 'Ev. číslo dokladu',
        'duzp' => 'DUZP', 'dppd' => 'DPPD', 'kod_pred_pl' => 'Kód plnění', 'kod_rezim_pl' => 'Režim',
        'zdph_44' => '§ 44', 'pomer' => 'Poměr', 'zakl_dane1' => 'Základ 21 %', 'dan1' => 'Daň 21 %',
        'zakl_dane2' => 'Základ 12 %', 'dan2' => 'Daň 12 %', 'zakl_dane3' => 'Základ 0 %/3. sazba',
        'dan3' => 'Daň 3. sazba', 'rozsah' => 'Rozsah', 'obrat23' => 'Obrat 21 % (ř. 1)',
        'obrat5' => 'Obrat 12 % (ř. 2)', 'pln23' => 'Plnění 21 % (ř. 40)', 'pln5' => 'Plnění 12 % (ř. 41)',
        'pln_rez_pren' => 'Přenesení, dodavatel (ř. 25)', 'rez_pren23' => 'Přenesení 21 % (ř. 10)',
        'rez_pren5' => 'Přenesení 12 % (ř. 11)', 'celk_zd_a2' => 'Základ A.2 celkem',
        'zakl_dane' => 'Základ daně', 'c_vat' => 'DIČ pořizovatele', 'k_pln_eu' => 'Kód plnění',
        'pln_pocet' => 'Počet plnění', 'pln_hodnota' => 'Hodnota plnění', 'por_c_stran' => 'Pořadí',
    ];

    /**
     * @param array<string,mixed> $submission řádek `tax_submissions` obohacený o artefakty
     * @return array<string,mixed>
     */
    public static function build(array $submission): array
    {
        $formCode = strtolower((string) ($submission['form_code'] ?? ''));
        $parsed = SubmittedEpoXmlReader::read((string) ($submission['xml_content'] ?? ''));

        $identity = [];
        $records = [];
        foreach ($parsed['records'] as $record) {
            if (in_array($record['name'], ['VetaD', 'VetaP'], true)) {
                foreach ($record['attrs'] as $name => $value) {
                    $identity[] = ['label' => self::IDENTITY_LABELS[$name] ?? $name, 'value' => $value];
                }
                continue;
            }
            $records[$record['name']][] = $record['attrs'];
        }

        $variant = strtoupper(trim((string) ($submission['form_variant'] ?? '')));

        return [
            'form_code'     => strtoupper($formCode),
            'title'         => self::FORM_TITLES[$formCode] ?? strtoupper($formCode),
            'version'       => $parsed['version'],
            'period_label'  => self::periodLabel($submission),
            'variant_label' => self::VARIANTS[$formCode][$variant] ?? ($variant !== '' ? $variant : null),
            'status_label'  => self::STATUS_LABELS[(string) ($submission['status'] ?? '')] ?? (string) ($submission['status'] ?? ''),
            'submission_id' => (int) ($submission['id'] ?? 0),
            'generated_at'  => $submission['generated_at'] ?? null,
            'xml_sha256'    => (string) ($submission['xml_sha256'] ?? ''),
            'identity'      => $identity,
            'line_groups'   => $formCode === 'dphdp3' ? self::dp3Groups($records) : [],
            'tables'        => $formCode === 'dphdp3' ? self::tables(array_diff_key($records, self::DP3_SECTIONS)) : self::tables($records),
            'receipt'       => TaxSubmissionReceiptEvidence::summarize($submission),
        ];
    }

    /** @param array<string,mixed> $submission */
    public static function periodLabel(array $submission): string
    {
        $year = (int) ($submission['period_year'] ?? 0);
        if (($submission['period_month'] ?? null) !== null) {
            return sprintf('%02d/%d', (int) $submission['period_month'], $year);
        }
        if (($submission['period_quarter'] ?? null) !== null) {
            return (int) $submission['period_quarter'] . '. čtvrtletí ' . $year;
        }
        return (string) $year;
    }

    /**
     * Věty 1 až 6 přiznání k DPH jako řádky tiskopisu: číslo řádku, text, základ,
     * daň (v plné výši) a krácený odpočet.
     *
     * @param array<string, list<array<string,string>>> $records
     * @return list<array{title:string, rows:list<array{line:string, label:string, base:?string, full:?string, reduced:?string}>}>
     */
    private static function dp3Groups(array $records): array
    {
        $groups = [];
        foreach (self::DP3_SECTIONS as $veta => $title) {
            $attrs = $records[$veta][0] ?? null;
            if ($attrs === null || $attrs === []) {
                continue;
            }
            $vetaNo = (int) substr($veta, 4);
            $rows = [];
            $put = static function (string $line, string $column, string $attr) use (&$rows, &$attrs): void {
                if (!array_key_exists($attr, $attrs)) {
                    return;
                }
                $rows[$line] ??= ['line' => $line, 'base' => null, 'full' => null, 'reduced' => null];
                $rows[$line][$column] = $attrs[$attr];
                unset($attrs[$attr]);
            };
            foreach (DphPriznaniBuilder::LINE_MAP as $key => $map) {
                if ($map['veta'] !== $vetaNo) {
                    continue;
                }
                $reduced = str_ends_with((string) $key, 'k');
                $line = rtrim((string) $key, 'kb');
                // 51b je druhý sloupec téhož řádku (plnění bez nároku na odpočet).
                $put($line, str_ends_with((string) $key, 'b') ? 'full' : 'base', $map['base']);
                if ($map['vat'] !== null) {
                    $put($line, $reduced ? 'reduced' : 'full', $map['vat']);
                }
                if (isset($map['info_vat'])) {
                    $put($line, 'full', $map['info_vat']);
                }
            }
            foreach (self::DP3_EXTRA as $attr => [$line, $column]) {
                $put($line, $column, $attr);
            }
            uksort($rows, static fn (string $a, string $b): int => (int) $a <=> (int) $b);
            $out = [];
            foreach ($rows as $line => $row) {
                $out[] = $row + ['label' => self::DP3_LINES[$line] ?? ''];
            }
            // Atribut, který tisk nezná, se vytiskne pod svým názvem, aby PDF nic nezatajilo.
            foreach ($attrs as $name => $value) {
                $out[] = ['line' => '', 'label' => $name, 'base' => null, 'full' => $value, 'reduced' => null];
            }
            $groups[] = ['title' => $title, 'rows' => $out];
        }
        return $groups;
    }

    /**
     * Ostatní věty (oddíly KH, řádky SH, přílohy) jako tabulky se sloupci podle
     * atributů, které se ve větách skutečně vyskytují.
     *
     * @param array<string, list<array<string,string>>> $records
     * @return list<array{title:string, columns:list<array{key:string,label:string,num:bool}>, rows:list<array<string,string>>}>
     */
    private static function tables(array $records): array
    {
        $tables = [];
        foreach ($records as $name => $rows) {
            $keys = [];
            foreach ($rows as $row) {
                foreach (array_keys($row) as $key) {
                    $keys[$key] = true;
                }
            }
            if ($keys === []) {
                continue;
            }
            $tables[] = [
                'title'   => self::RECORD_TITLES[$name] ?? $name,
                'columns' => array_map(
                    static fn (string $key): array => [
                        'key'   => $key,
                        'label' => self::COLUMN_LABELS[$key] ?? $key,
                        'num'   => preg_match('/^(zakl_dane|dan[0-9]|obrat|pln[0-9_]|rez_pren|celk_|pln_hodnota)/', $key) === 1,
                    ],
                    array_keys($keys),
                ),
                'rows'    => $rows,
            ];
        }
        return $tables;
    }
}
