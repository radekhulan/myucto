<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

/**
 * Trvalé srážky, exekuce a insolvence ze zálohy PREMIER v podobě záznamů společného
 * zápisu srážek ({@see \MyInvoice\Service\Payroll\Migration\PayrollTakeoverDeductionsWriter}).
 * Nic nezapisuje.
 *
 * Zdroj je `MZ_SRAZ` (trvalá složka vztahu `S_INTER` s pořadovým číslem `S_SRAINT`),
 * provedení po měsících nesou položky mezd `DNY` se stejným vztahem a `SRA_INT` (na
 * reálné záloze se tak spárují všechny položky s `SRA_INT` a v 1 993 z 1 996 i se stejným
 * kódem; samotné `S_SRAINT` unikátní není, je to pořadí v rámci vztahu).
 *
 * `MZ_SRAZ` vede všechny trvalé složky vztahu, nejen srážky: osobní ohodnocení (303),
 * příspěvek na praní (862), penzijní připojištění (422) nebo stravenkový paušál (712) jsou
 * příjmy, ne srážky, a do zápisu nejdou. Druh srážky je podle kódu složky (číselník
 * `MZDY_POL`), ne podle příznaků karty:
 *
 *  - 702, 704 exekuce nepřednostní, 751 poplatek za exekuci => exekuce, nepřednostní,
 *  - 706 exekuce přednostní => exekuce, jiná přednostní pohledávka,
 *  - 707 výživné => exekuce, běžné výživné,
 *  - 703, 705, 708, 709 (insolvence, náklady insolvence) => insolvence (přednostní rozsah
 *    podle § 398 odst. 3 insolvenčního zákona),
 *  - 700 spoření, 701 půjčka, 770 provozní srážky => dohoda o srážkách (ostatní),
 *    710, 711, 714 stravenky => dohoda (stravování), 720 odbory => dohoda (příspěvky),
 *    750 záloha => dohoda (záloha).
 *
 * Exekuce a insolvence: celková pohledávka `S_CASTKA`, měsíční splátka `S_CAST_SR`,
 * doručení plátci mzdy `S_DOCDAT`, pořadí `S_PORADI`, období `S_MES_OD`/`S_ROK_OD` až
 * `S_MES_DO`/`S_ROK_DO`, příjemce `S_DORUCIT`, účet `S_UCET` / `S_BANKOD`, symboly
 * `S_VAR`, `S_SPEC`, `S_KONS`. Zbývá celková pohledávka snížená o sražené v `DNY`.
 * Dobrovolná srážka: měsíční částka `S_CASTKA` (u stravenek je `S_CASTKA2` sazba za den,
 * měsíční částka není - dohoda vznikne jako návrh).
 */
final class PremierPayrollDeductions
{
    /** Kód složky => [cíl, kategorie pohledávky nebo druh dohody]. */
    private const CODES = [
        '702' => ['enforcement', 'non_priority'],
        '704' => ['enforcement', 'non_priority'],
        '751' => ['enforcement', 'non_priority'],
        '706' => ['enforcement', 'other_priority'],
        '707' => ['enforcement', 'current_maintenance'],
        '703' => ['insolvency', 'other_priority'],
        '705' => ['insolvency', 'other_priority'],
        '708' => ['insolvency', 'other_priority'],
        '709' => ['insolvency', 'other_priority'],
        '700' => ['voluntary', 'other'],
        '701' => ['voluntary', 'other'],
        '770' => ['voluntary', 'other'],
        '710' => ['voluntary', 'meal'],
        '711' => ['voluntary', 'meal'],
        '714' => ['voluntary', 'meal'],
        '720' => ['voluntary', 'contribution'],
        '750' => ['voluntary', 'advance'],
    ];

    /** Trvalé složky příjmu na kartě `MZ_SRAZ`, které srážkou nejsou (viz hlavička). */
    public const INCOME_CODES = ['303', '422', '712', '862'];

    /**
     * Trvalé příjmy pevnou měsíční částkou, ze kterých převod zakládá opakovanou složku
     * ({@see self::recurringIncomes()}): kód => druh složky. Osobní ohodnocení (303) je
     * v číselníku `MZDY_POL` hrubá mzda se sociálním i zdravotním pojištěním a do průměru.
     * Ostatní příjmy z {@see self::INCOME_CODES} se nezakládají: příspěvek na penzijní
     * připojištění (422) nese koš osvobození podle smlouvy, stravenkový paušál (712) se
     * počítá ze směn a příspěvek na praní (862) je náhrada vyplácená mimo hrubou mzdu.
     */
    public const RECURRING_INCOME = ['303' => 'bonus'];

    /**
     * Rozpočítání opakované složky podle kódu (výchozí `calendar_days`).
     *
     * Osobní ohodnocení (303) PREMIER krátí poměrem odpracovaných hodin k fondu pracovní
     * doby měsíce: na záloze 2025-2026 vyšlo u 126 ze 128 automaticky spočítaných měsíců
     * přesně `round(částka karty × odpracované hodiny / fond)` (dovolená, nemoc i překážky
     * krátí, svátek je mimo fond). MyÚčto to vyjadřuje rozpočítáním „odpracované hodiny"
     * (`hours`), které krátí stejně jako základní mzdu. Částky zadané v PREMIER ručně
     * (příznak `DNY.RUCNE`) pravidlo nemají a převod je nenapodobuje.
     */
    private const RECURRING_ALLOCATION = ['303' => 'hours'];

    /**
     * Karty `MZ_SRAZ` s trvalým příjmem ({@see self::RECURRING_INCOME}) po vztazích.
     *
     * @return array<int,list<array{code:string,name:string,kind:string,amount:float,from:?string,to:?string,allocation:string}>>
     *         `S_INTER` => karty; `from`/`to` jsou měsíce `YYYY-MM` (`null` = neomezeno)
     */
    public static function recurringIncomes(PremierBackup $backup): array
    {
        $names = [];
        foreach ($backup->rows('MZDY_POL') as $row) {
            $names[self::text($row['KOD'] ?? '')] = self::text($row['POPIS'] ?? '');
        }
        $out = [];
        foreach ($backup->rows('MZ_SRAZ') as $row) {
            $code = self::text($row['S_KOD'] ?? '');
            $kind = self::RECURRING_INCOME[$code] ?? null;
            $inter = (int) ($row['S_INTER'] ?? 0);
            if ($kind === null || $inter <= 0) {
                continue;
            }
            $name = self::text($row['S_POPIS'] ?? '') ?: ($names[$code] ?? '') ?: "Složka {$code}";
            $out[$inter][] = [
                'code' => 'PREMIER_' . $code,
                'name' => $name,
                'kind' => $kind,
                'amount' => round((float) ($row['S_CASTKA'] ?? 0), 2),
                'from' => self::month($row['S_ROK_OD'] ?? null, $row['S_MES_OD'] ?? null),
                'to' => self::month($row['S_ROK_DO'] ?? null, $row['S_MES_DO'] ?? null),
                'allocation' => self::RECURRING_ALLOCATION[$code] ?? 'calendar_days',
            ];
        }

        return $out;
    }

    /**
     * Karty `MZ_SRAZ` se složkou, kterou převod nezná: není mezi srážkami ({@see self::CODES})
     * ani mezi vědomě vynechanými příjmy ({@see self::INCOME_CODES}). Čtení srážek by je jinak
     * tiše přeskočilo.
     *
     * @return array<string,int> kód => počet karet
     */
    public static function unknownCodes(PremierBackup $backup): array
    {
        $out = [];
        foreach ($backup->rows('MZ_SRAZ') as $row) {
            $code = self::text($row['S_KOD'] ?? '');
            if ($code !== '' && !isset(self::CODES[$code]) && !in_array($code, self::INCOME_CODES, true)) {
                $out[$code] = ($out[$code] ?? 0) + 1;
            }
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * Srážky vztahů `$relations`, které trvají na konci posledního převáděného měsíce
     * (`$lastPeriod`). Skončené se jen spočítají.
     *
     * @param list<array<string,mixed>> $relations vztahy z {@see PremierPayroll::$relations}, které převod zakládá
     * @param int $year převáděný rok (měsíce insolvence)
     * @return array{deductions:list<array<string,mixed>>,protected_amount_inputs:int,ended:int}
     */
    public static function read(PremierBackup $backup, array $relations, string $lastPeriod, int $year): array
    {
        $byInter = [];
        foreach ($relations as $relation) {
            $byInter[(int) $relation['key']] = $relation;
        }
        $names = [];
        foreach ($backup->rows('MZDY_POL') as $row) {
            $names[self::text($row['KOD'] ?? '')] = self::text($row['POPIS'] ?? '');
        }
        /** @var array<string,array{withheld:float,periods:array<string,bool>,items:int}> $done */
        $done = [];
        foreach ($backup->rows('DNY') as $row) {
            $sra = (int) ($row['SRA_INT'] ?? 0);
            if ($sra <= 0) {
                continue;
            }
            $key = (int) ($row['INTER'] ?? 0) . '|' . $sra;
            $done[$key] ??= ['withheld' => 0.0, 'periods' => [], 'items' => 0];
            $done[$key]['withheld'] += (float) ($row['CASTKA'] ?? 0);
            $done[$key]['items']++;
            $yearOf = (int) ($row['DNY_ROK'] ?? 0);
            $month = (int) ($row['DNY_MES'] ?? 0);
            if ($yearOf === $year && $month >= 1 && $month <= 12 && sprintf('%04d-%02d', $yearOf, $month) <= $lastPeriod) {
                $done[$key]['periods'][sprintf('%04d-%02d', $yearOf, $month)] = true;
            }
        }
        $records = [];
        $ended = 0;
        foreach ($backup->rows('MZ_SRAZ') as $row) {
            $code = self::text($row['S_KOD'] ?? '');
            $class = self::CODES[$code] ?? null;
            $inter = (int) ($row['S_INTER'] ?? 0);
            $relation = $byInter[$inter] ?? null;
            if ($class === null || $relation === null) {
                continue;
            }
            $from = self::month($row['S_ROK_OD'] ?? null, $row['S_MES_OD'] ?? null);
            $to = self::month($row['S_ROK_DO'] ?? null, $row['S_MES_DO'] ?? null);
            // Srážka skončená dřív, nebo vztah skončený dřív (u skončeného vztahu se už nesráží,
            // i když karta srážky konec nemá).
            $relationEnd = is_string($relation['end'] ?? null) ? substr($relation['end'], 0, 7) : null;
            if (($from !== null && $from > $lastPeriod) || ($to !== null && $to < $lastPeriod) || ($relationEnd !== null && $relationEnd < $lastPeriod)) {
                $ended++;
                continue;
            }
            [$target, $kind] = $class;
            $sra = (int) ($row['S_SRAINT'] ?? 0);
            $evidence = $done[$inter . '|' . $sra] ?? ['withheld' => 0.0, 'periods' => [], 'items' => 0];
            $statutory = $target !== 'voluntary';
            $total = $statutory ? (float) ($row['S_CASTKA'] ?? 0) : 0.0;
            $monthly = $statutory ? (float) ($row['S_CAST_SR'] ?? 0) : (float) ($row['S_CASTKA'] ?? 0);
            $periods = array_keys($evidence['periods']);
            sort($periods);
            $title = trim($code . ' ' . (self::text($row['S_POPIS'] ?? '') ?: ($names[$code] ?? '')));
            $records[] = [
                'person_key' => (string) $relation['person_key'],
                'personal_numbers' => [(string) $relation['personal_number']],
                'reference' => 'premier:mz_sraz:' . $inter . ':' . $sra,
                'target' => $target,
                'target_reason' => 'code_' . $code,
                'code' => $code,
                'name' => $names[$code] ?? '',
                'title' => mb_substr($title !== '' ? $title : 'Srážka z PREMIER', 0, 190),
                'valid_from' => $from === null ? null : $from . '-01',
                'valid_to' => $to === null ? null : (new \DateTimeImmutable($to . '-01'))->format('Y-m-t'),
                'priority_date' => self::date($row['S_DOCDAT'] ?? null),
                'priority_no' => (int) ($row['S_PORADI'] ?? 0),
                'category' => $statutory ? $kind : null,
                'maintenance_weight_minor' => null,
                'total_minor' => self::minor($total),
                'withheld_minor' => self::minor($evidence['withheld']),
                'outstanding_minor' => max(0, self::minor($total) - self::minor($evidence['withheld'])),
                'monthly_minor' => self::minor($monthly),
                'basis_points' => null,
                'dependants' => max(0, (int) ($row['S_DETI_VYZ'] ?? 0)),
                'joint_discharge' => false,
                'deferred' => false,
                'deduction_kind' => $statutory ? null : $kind,
                'carried_by_attendance' => false,
                'recipient' => self::recipient($row),
                'periods' => $target === 'insolvency' ? $periods : [],
                'evidence' => ['MZ_SRAZ:' . $inter . '/' . $sra, 'DNY: ' . $evidence['items']],
            ];
        }
        usort($records, static fn (array $a, array $b): int => [$a['personal_numbers'][0], $a['reference']] <=> [$b['personal_numbers'][0], $b['reference']]);
        return ['deductions' => $records, 'protected_amount_inputs' => 0, 'ended' => $ended];
    }

    /**
     * Příjemce srážky; bez jména příjemce nemá záznam co zapsat.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private static function recipient(array $row): ?array
    {
        $name = self::text($row['S_DORUCIT'] ?? '');
        if ($name === '') {
            return null;
        }
        $account = (string) preg_replace('/\s+/', '', self::text($row['S_UCET'] ?? ''));
        $bankCode = self::text($row['S_BANKOD'] ?? '');
        $valid = $account !== '' && preg_match('/^[0-9]{4}$/D', $bankCode) === 1;
        $constant = (string) preg_replace('/\D/', '', self::text($row['S_KONS'] ?? ''));
        return [
            'name' => mb_substr($name, 0, 190),
            'reference' => null,
            'ico' => null,
            'account' => $valid ? $account : null,
            'bank_code' => $valid ? $bankCode : null,
            'variable_symbol' => self::digits(self::text($row['S_VAR'] ?? ''), 10),
            'specific_symbol' => self::digits(self::text($row['S_SPEC'] ?? ''), 10),
            'constant_symbol' => $constant === '' ? null : substr(str_pad($constant, 4, '0', STR_PAD_LEFT), -4),
        ];
    }

    /** Měsíc `YYYY-MM` z roku a měsíce; nula nebo prázdné = žádný. */
    private static function month(mixed $year, mixed $month): ?string
    {
        $y = (int) $year;
        $m = (int) $month;
        return $y >= 1990 && $m >= 1 && $m <= 12 ? sprintf('%04d-%02d', $y, $m) : null;
    }

    private static function digits(string $value, int $max): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $value);
        return $digits === '' ? null : substr($digits, 0, $max);
    }

    private static function minor(float $value): int
    {
        return (int) round($value * 100);
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
