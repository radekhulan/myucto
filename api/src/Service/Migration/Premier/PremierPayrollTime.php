<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

/**
 * Časové evidence mezd ze zálohy PREMIER po pracovních vztazích (`INTER`): nepřítomnosti
 * s daty, stav dovolené, průměrné výdělky a případy pracovní neschopnosti. Nic nezapisuje;
 * převáděné období z nich vybere {@see PremierPayrollTakeover}.
 *
 * ── Nepřítomnosti (`DNY`) ─────────────────────────────────────────────────────
 * Řádek `DNY` je složka mzdy za interval `DATUM_OD` až `DATUM_DO` v rámci jednoho měsíce
 * mzdy (`DNY_ROK`/`DNY_MES`). Druh nepřítomnosti podle složky (`KOD`, číselník
 * `MZDY_POL`): {@see self::ABSENCE_CODES}. Náhrada za svátek (510), proplacená dovolená
 * (501) a čerpání či proplacení náhradního volna za přesčas (584) nepřítomnost nejsou.
 * PPM a otcovská mají v PREMIER jednu složku (603); rozliší je pohlaví osoby a den porodu
 * je narození dítěte z případu mateřské (`NEMOC.ID_DITETE` → `PER_DETI.BDNAR`).
 * Po měsících rozsekané intervaly spojí zápis ({@see \MyInvoice\Service\Payroll\Migration\PayrollTakeoverAbsenceWriter}).
 *
 * ── Dovolená (`DOV_DNY`) ───────────────────────────────────────────────────────
 * Měsíční stav dovolené vztahu. Sloupce jsou v HODINÁCH, ne ve dnech: ověřeno agregovaně
 * na reálné záloze - čerpání měsíce (`CERPANI`) se shoduje s hodinami složky dovolené
 * (500) v `DNY` ve 313 z 316 měsíců, s dny v žádném; roční nárok 194 h odpovídá pěti
 * týdnům při úvazku 7,75 h denně. Zůstatek `ZUST_MES` = zůstatek z minulého roku
 * (`ZUS_MINR`) + nárok (`NAROK`) − čerpáno v roce (`CERPANO_R`) ve všech měsících.
 * Jistota vysoká.
 *
 * ── Průměry (`PER_PRU`) ────────────────────────────────────────────────────────
 * Průměrný hodinový výdělek pro náhrady `XN_PRDO` po měsících (v rámci čtvrtletí stejný
 * až na výjimky), rozhodné období `XROZOBDOD`-`XROZOBDDO`, hrubý příjem v něm `XVYM_DOV`,
 * odpracované hodiny `XHOD_SPL`; `XN_DRUH` P = pravděpodobný, R = skutečný.
 *
 * ── Pracovní neschopnost (`MZ_HDPN`) ───────────────────────────────────────────
 * Případy eNeschopenky vztahu: `HDPN_OD`, konec `HDPN_DO` (prázdný u trvající),
 * `TYP_NP` DPN / OSE (ošetřování).
 */
final class PremierPayrollTime
{
    /** Složka mzdy (`DNY.KOD`) => druh nepřítomnosti MyÚčta; 603 viz hlavička. */
    public const ABSENCE_CODES = [
        '500' => 'vacation',
        '511' => 'employee_obstacle',
        '525' => 'employee_obstacle',
        '513' => 'employer_obstacle',
        '523' => 'employer_obstacle',
        '524' => 'employer_obstacle',
        '516' => 'other',
        '517' => 'other',
        '551' => 'unexcused',
        '552' => 'unpaid_leave',
        '554' => 'unpaid_leave',
        '556' => 'other',
        '582' => 'compensatory_time_off',
        '600' => 'dpn',
        '610' => 'dpn',
        '601' => 'ocr',
        '602' => 'ocr',
        '606' => 'long_term_care',
        '603' => 'ppm',
        '604' => 'parental',
        '605' => 'unpaid_leave',
    ];

    /**
     * Složky z řad nepřítomností (5xx, 6xx), které nepřítomnost vědomě nejsou: náhrada za
     * svátek, proplacená dovolená, čerpání či proplacení náhradního volna (viz hlavička).
     */
    public const NOT_ABSENCE_CODES = ['501', '510', '584'];

    /**
     * Složky z řad nepřítomností (5xx, 6xx) v `DNY`, které převod nezná: ani je nepřevádí
     * jako nepřítomnost ({@see self::ABSENCE_CODES}), ani o nich neví, že nepřítomnost nejsou
     * ({@see self::NOT_ABSENCE_CODES}). Takový řádek by čtení nepřítomností jinak tiše vynechalo.
     *
     * @return array<string,int> kód => počet řádků
     */
    public static function unknownAbsenceCodes(PremierBackup $backup): array
    {
        $out = [];
        foreach ($backup->rows('DNY') as $row) {
            $code = self::text($row['KOD'] ?? '');
            if (preg_match('/^[56]\d\d$/D', $code) === 1
                && !isset(self::ABSENCE_CODES[$code])
                && !in_array($code, self::NOT_ABSENCE_CODES, true)
            ) {
                $out[$code] = ($out[$code] ?? 0) + 1;
            }
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * @return array{
     *     absences: array<int,list<array{type:string,from:string,to:string,childbirth:?string,period:string}>>,
     *     leave: array<int,array<string,array{balance:float,taken:float}>>,
     *     averages: array<int,array<string,array<string,mixed>>>,
     *     sickness: array<int,list<array{from:string,to:?string,kind:string}>>
     * }
     */
    public static function read(PremierBackup $backup): array
    {
        return [
            'absences' => self::absences($backup),
            'leave' => self::leave($backup),
            'averages' => self::averages($backup),
            'sickness' => self::sickness($backup),
        ];
    }

    /** @return array<int,list<array{type:string,from:string,to:string,childbirth:?string,period:string}>> */
    private static function absences(PremierBackup $backup): array
    {
        $male = [];
        foreach ($backup->rows('PER_MAIN') as $row) {
            $male[self::text($row['ID'] ?? '')] = (int) ($row['POHLAVI'] ?? 0) === 1;
        }
        $maleRelation = [];
        foreach ($backup->rows('PERSONAL') as $row) {
            $maleRelation[(int) ($row['INTER'] ?? 0)] = $male[self::text($row['SUP_ID'] ?? '')] ?? false;
        }
        $births = [];
        foreach ($backup->rows('PER_DETI') as $row) {
            $births[self::text($row['ID'] ?? '')] = self::date($row['BDNAR'] ?? null);
        }
        $maternity = [];
        foreach ($backup->rows('NEMOC') as $row) {
            $from = self::date($row['OD'] ?? null);
            $birth = $births[self::text($row['ID_DITETE'] ?? '')] ?? null;
            if ($from !== null && $birth !== null) {
                $maternity[(int) ($row['INTER'] ?? 0)][] = ['from' => $from, 'to' => self::date($row['DO'] ?? null), 'birth' => $birth];
            }
        }
        $out = [];
        foreach ($backup->rows('DNY') as $row) {
            $code = self::text($row['KOD'] ?? '');
            $type = self::ABSENCE_CODES[$code] ?? null;
            $inter = (int) ($row['INTER'] ?? 0);
            $from = self::date($row['DATUM_OD'] ?? null);
            $to = self::date($row['DATUM_DO'] ?? null);
            if ($type === null || $inter <= 0 || $from === null || $to === null || $to < $from) {
                continue;
            }
            $year = (int) ($row['DNY_ROK'] ?? 0);
            $month = (int) ($row['DNY_MES'] ?? 0);
            $period = $year >= 1990 && $month >= 1 && $month <= 12 ? sprintf('%04d-%02d', $year, $month) : substr($from, 0, 7);
            $childbirth = null;
            if ($code === '603') {
                if ($maleRelation[$inter] ?? false) {
                    $type = 'paternity';
                } else {
                    foreach ($maternity[$inter] ?? [] as $case) {
                        if ($case['from'] <= $to && ($case['to'] === null || $case['to'] >= $from)) {
                            $childbirth = $case['birth'];
                        }
                    }
                }
            }
            $out[$inter][] = ['type' => $type, 'from' => $from, 'to' => $to, 'childbirth' => $childbirth, 'period' => $period];
        }
        return $out;
    }

    /** @return array<int,array<string,array{balance:float,taken:float}>> INTER => `YYYY-MM` => stav (hodiny) */
    private static function leave(PremierBackup $backup): array
    {
        $out = [];
        foreach ($backup->rows('DOV_DNY') as $row) {
            $year = (int) ($row['ROK'] ?? 0);
            $month = (int) ($row['MESIC'] ?? 0);
            if ($year < 1990 || $month < 1 || $month > 12) {
                continue;
            }
            $out[(int) ($row['INTER'] ?? 0)][sprintf('%04d-%02d', $year, $month)] = [
                'balance' => round((float) ($row['ZUST_MES'] ?? 0), 4),
                'taken' => round((float) ($row['CERPANO_R'] ?? 0), 4),
            ];
        }
        return $out;
    }

    /** @return array<int,array<string,array<string,mixed>>> INTER => `YYYY-MM` => průměr měsíce */
    private static function averages(PremierBackup $backup): array
    {
        $out = [];
        foreach ($backup->rows('PER_PRU') as $row) {
            $year = (int) ($row['XN_ROK'] ?? 0);
            $month = (int) ($row['XN_MES'] ?? 0);
            $hourly = round((float) ($row['XN_PRDO'] ?? 0), 4);
            if ($year < 1990 || $month < 1 || $month > 12 || $hourly <= 0) {
                continue;
            }
            $out[(int) ($row['XNINTER'] ?? 0)][sprintf('%04d-%02d', $year, $month)] = [
                'hourly' => $hourly,
                'from' => self::date($row['XROZOBDOD'] ?? null),
                'to' => self::date($row['XROZOBDDO'] ?? null),
                'gross' => round((float) ($row['XVYM_DOV'] ?? 0), 2),
                'worked' => round((float) ($row['XHOD_SPL'] ?? 0), 2),
                'kind' => strtoupper(self::text($row['XN_DRUH'] ?? '')),
            ];
        }
        return $out;
    }

    /** @return array<int,list<array{from:string,to:?string,kind:string}>> */
    private static function sickness(PremierBackup $backup): array
    {
        $out = [];
        foreach ($backup->rows('MZ_HDPN') as $row) {
            $from = self::date($row['HDPN_OD'] ?? null);
            if ($from === null) {
                continue;
            }
            $out[(int) ($row['INTER'] ?? 0)][] = [
                'from' => $from,
                'to' => self::date($row['HDPN_DO'] ?? null) ?? self::date($row['UKO_DPN'] ?? null),
                'kind' => strtoupper(self::text($row['TYP_NP'] ?? '')),
            ];
        }
        return $out;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : (is_int($value) || is_float($value) ? (string) $value : '');
    }

    private static function date(mixed $value): ?string
    {
        $v = self::text($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && (int) substr($v, 0, 4) >= 1901 ? $v : null;
    }
}
