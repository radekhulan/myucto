<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

/**
 * Zaměstnanci a zpracované mzdy ze zálohy PREMIER, přečtené do podoby, se kterou pracuje
 * krok převodu mezd ({@see PayrollImporter}). Nic nezapisuje.
 *
 * Tabulky:
 *  - `PERSONAL` - pracovní vztah (klíč `INTER`, osobní číslo `CISLO`, nástup `VSTUP`,
 *    skončení `VYSTUP`, výplatní účet `BANKA_UCET` / `BANKA_KOD`, příznak `JEDNATEL`,
 *    kategorie `UVA_KATE` a kód činnosti pro ČSSZ `KODPP_SO`). Na osobu ukazuje `SUP_ID`.
 *  - `PER_MAIN` - osoba (`ID`): jméno, rodné číslo `RC_1` + `RC_2`, narození, adresa,
 *    kontakt, daňový nerezident `NREZIDEN`. Starší verze PREMIER ji nemají; osobu pak nese
 *    `PERSON2`, historické snímky osoby po osobním čísle (poslední snímek platí).
 *  - `MZDY` - zpracovaná mzda vztahu za měsíc (`INTER`, `ROK`, `MESIC`).
 *  - `PERS_HYS` - historie sjednané mzdy od `PLATNY_OD` (`SAZBA_MZ` podle `TYP_MZDY`,
 *    u starších verzí `MZDA_MES`, viz {@see self::agreedWage()}).
 *  - `MZ_PRIZP` - oznámení zdravotní pojišťovně (`ZKRATKA_P` = kód pojišťovny).
 *
 * Význam polí `MZDY` ověřený proti zaúčtování téhož měsíce v deníku (`PUB_UCTO`):
 * hrubý příjem je `MZ_HRUBA` (mzda) + `MZ_ODSTAT` (odměna člena statutárního orgánu),
 * pojistné zaměstnance `MZ_SOC` + `MZ_ZDR` (zdravotní včetně doplatku do minimálního
 * základu), pojistné zaměstnavatele `MZ_SOCF` + `MZ_ZDRF`, záloha na daň `MZ_DAN`
 * ze základu `MZ_ZDANI`, srážková daň `MZ_SDAN` ze základu `MZ_SDANI`, daňový bonus
 * `MZ_BONUS`, čistá `MZ_CISTA`, k výplatě `MZ_VYPLATA`.
 */
final class PremierPayroll
{
    /** Účty zúčtování se zaměstnanci (a společníky) - protistrana nákladu na mzdy. */
    public const EMPLOYEE_ACCOUNTS = ['331', '333', '366'];

    /** Kódy druhu činnosti ČSSZ pro dohodu o provedení práce (viz `PayrollEmploymentJmhzActivityFamily`). */
    private const DPP_CODES = ['T', 'D'];

    /**
     * Druh vztahu učně (kategorie `UCN`). MyÚčto pro žáka nebo učně druh vztahu nemá
     * a pracovní poměr to není: odměna za produktivní činnost nezakládá účast na
     * pojištění jako mzda zaměstnance. Převod takový vztah nezakládá a ohlásí ho.
     */
    public const APPRENTICE = 'apprentice';

    /** Složky mezd (`DNY.KOD`), které snižují čistou mzdu ({@see self::monthItems()}). */
    public const DEDUCTION_CODES = ['700', '701', '702', '703', '704', '705', '706', '707', '708', '709', '710', '711', '714', '720', '750', '751', '770'];

    /**
     * @param list<array<string,mixed>> $relations
     * @param list<string> $missingTables
     */
    private function __construct(
        public readonly array $relations,
        public readonly array $missingTables,
        public readonly int $monthRows,
    ) {}

    public static function fromBackup(PremierBackup $backup): self
    {
        $missing = [];
        foreach (['PERSONAL', 'MZDY'] as $table) {
            if (!$backup->hasTable($table)) {
                $missing[] = $table;
            }
        }
        $people = [];
        foreach ($backup->rows('PER_MAIN') as $row) {
            $id = self::text($row['ID'] ?? '');
            if ($id !== '') {
                $people[$id] = $row;
            }
        }
        $mailing = self::mailingAddresses($backup);
        // Karta nerezidenta (`PER_NERZ`, vazba `N_SUPINT` = `PER_MAIN.SUP_INTER`): stát daňové
        // rezidence `N_STAT`. Karta je i u rezidentů (stát CZ); převod ji bere jen u nerezidenta.
        $residenceCountries = [];
        foreach ($backup->rows('PER_NERZ') as $row) {
            $country = self::country(self::text($row['N_STAT'] ?? ''));
            if ($country !== null && (int) ($row['N_SUPINT'] ?? 0) > 0) {
                $residenceCountries[(int) $row['N_SUPINT']] = $country;
            }
        }
        $recurringIncomes = PremierPayrollDeductions::recurringIncomes($backup);
        $snapshots = [];
        foreach ($backup->rows('PERSON2') as $row) {
            $number = (int) ($row['CISLO'] ?? 0);
            $current = $snapshots[$number] ?? null;
            if ($current === null || self::text($row['TS'] ?? '') >= self::text($current['TS'] ?? '')) {
                $snapshots[$number] = $row;
            }
        }
        $wages = [];
        $hourly = [];
        $weeklyHours = [];
        foreach ($backup->rows('PERS_HYS') as $row) {
            $from = self::date($row['PLATNY_OD'] ?? null)
                ?? (((int) ($row['ROK'] ?? 0)) > 0 ? sprintf('%04d-%02d-01', (int) $row['ROK'], max(1, (int) ($row['MESIC'] ?? 1))) : null);
            if ($from === null) {
                continue;
            }
            [$kind, $amount] = self::agreedWage($row);
            if ($kind === 'monthly') {
                $wages[(int) ($row['INTER'] ?? 0)][$from] = $amount;
            } elseif ($kind === 'hourly') {
                $hourly[(int) ($row['INTER'] ?? 0)][$from] = $amount;
            }
            // Týdenní pracovní doba verze (`UVA_HOD`, hodin týdně; `UVA_DOBA` je hodin denně).
            $weekly = round((float) ($row['UVA_HOD'] ?? 0), 2);
            if ($weekly > 0 && $weekly <= 168) {
                $weeklyHours[(int) ($row['INTER'] ?? 0)][$from] = ['weekly' => $weekly, 'daily' => round((float) ($row['UVA_DOBA'] ?? 0), 4)];
            }
        }
        $registry = PremierPayrollRegistry::read($backup);
        $card = PremierPayrollPersonCard::read($backup);
        $time = PremierPayrollTime::read($backup);
        $insurers = [];
        /** @var array<int,list<array{date:string,code:string,kind:string}>> $insurerEvents */
        $insurerEvents = [];
        foreach ($backup->rows('MZ_PRIZP') as $row) {
            $code = self::text($row['ZKRATKA_P'] ?? '');
            if (preg_match('/^[0-9]{3}$/D', $code) === 1) {
                $insurers[(int) ($row['INTER'] ?? 0)][self::date($row['HLAS_OD'] ?? null) ?? ''] = [
                    'code' => $code,
                    'registered' => self::text($row['KOD'] ?? '') === 'P' && ($row['PRIJATO'] ?? false) === true,
                ];
                $date = self::date($row['HLAS_OD'] ?? null);
                if ($date !== null) {
                    $insurerEvents[(int) ($row['INTER'] ?? 0)][] = ['date' => $date, 'code' => $code, 'kind' => strtoupper(self::text($row['KOD'] ?? ''))];
                }
            }
        }
        $items = self::monthItems($backup);
        $months = [];
        $monthRows = 0;
        foreach ($backup->rows('MZDY') as $row) {
            $year = (int) ($row['ROK'] ?? 0);
            $month = (int) ($row['MESIC'] ?? 0);
            if ($year < 1990 || $month < 1 || $month > 12) {
                continue;
            }
            $monthRows++;
            $inter = (int) ($row['INTER'] ?? 0);
            $period = sprintf('%04d-%02d', $year, $month);
            $months[$inter][$period] = self::month($row);
            // Srážky nese `DNY` po složkách; sloupce `SR_*` v `MZDY` nemají zálohu na mzdu
            // ani stravenky a u některých záloh ani exekuce.
            if (isset($items[$inter][$period])) {
                $months[$inter][$period]['deductions'] = round($items[$inter][$period], 2);
            }
        }

        $relations = [];
        foreach ($backup->rows('PERSONAL') as $row) {
            $inter = (int) ($row['INTER'] ?? 0);
            if ($inter <= 0) {
                continue;
            }
            $number = (int) ($row['CISLO'] ?? 0);
            $relationMonths = $months[$inter] ?? [];
            ksort($relationMonths);
            $person = $people[self::text($row['SUP_ID'] ?? '')] ?? null;
            $snapshot = $snapshots[$number] ?? null;
            $source = $person ?? $snapshot ?? [];
            $first = self::limited($source['JMENO'] ?? '', 96);
            $last = self::limited($source['PRIJMENI'] ?? '', 96);
            $start = self::date($row['VSTUP'] ?? null)
                ?? ($relationMonths === [] ? null : array_key_first($relationMonths) . '-01');
            $personInsurers = $insurers[$inter] ?? [];
            ksort($personInsurers);
            $insurer = $personInsurers === [] ? null : end($personInsurers);
            $insurerCode = $insurer['code'] ?? null;
            if ($insurerCode === null) {
                foreach (array_reverse($relationMonths) as $m) {
                    if ($m['insurer_code'] !== null) {
                        $insurerCode = $m['insurer_code'];
                        break;
                    }
                }
            }
            $relationWages = $wages[$inter] ?? [];
            ksort($relationWages);
            $relationHourly = $hourly[$inter] ?? [];
            ksort($relationHourly);
            $account = self::digits(str_replace('-', '', self::text($row['BANKA_UCET'] ?? '')), 22) !== null
                ? self::text($row['BANKA_UCET'] ?? '') : null;
            $bankCode = self::digits(self::text($row['BANKA_KOD'] ?? ''), 4);
            $evidence = $registry[$inter] ?? [];
            [$relationType, $typeDerived] = self::relationType($row, $evidence['jmhz']['activity'] ?? null);
            $relationWeekly = $weeklyHours[$inter] ?? [];
            ksort($relationWeekly);
            $relations[] = [
                'key' => (string) $inter,
                'person_key' => $person !== null ? 'PER_MAIN|' . self::text($person['ID'] ?? '') : 'CISLO|' . ($number > 0 ? $number : 'I' . $inter),
                'personal_number' => $number > 0 ? (string) $number : 'P' . $inter,
                'first_name' => $first,
                'last_name' => $last,
                'full_name' => trim(($first ?? '') . ' ' . ($last ?? '')),
                'birth_number' => self::birthNumber($source),
                'birth_date' => self::date($person['NAROZENI'] ?? null),
                'identity' => [
                    'title_prefix' => self::limited($person['TITUL_PR'] ?? '', 64),
                    'title_suffix' => self::limited($person['TITUL_ZA'] ?? '', 64),
                    'birth_place' => self::limited($person['MISTO_N'] ?? '', 128),
                    // `STAT_N` je stát narození (u osoby narozené na Slovensku `SK`, i když je občanem ČR),
                    // státní občanství nese `STOBC`. Ověřeno proti přijatým větám REGZEC (`birth/@stat`,
                    // `stat/@cnt`) i proti oznámením `MZ_PRISO` ("Ohlášení změny údajů: STAT_N").
                    'birth_country_code' => self::country(self::text($person['STAT_N'] ?? '')),
                    'citizenship_country_code' => self::country(self::text($person['STOBC'] ?? '')),
                ],
                'birth_surname' => self::limited($person['RODNE_P'] ?? '', 128),
                'residence' => self::address($source),
                'mailing' => $person !== null ? ($mailing[(int) ($person['SUP_INTER'] ?? 0)] ?? null) : null,
                'email' => self::email(self::text($person['E_MAIL'] ?? '')),
                'phone' => self::phone(self::text($person['MOBIL'] ?? '') ?: self::text($person['TEL'] ?? '')),
                'non_resident' => ($person['NREZIDEN'] ?? false) === true,
                'tax_residence_country' => $person !== null ? ($residenceCountries[(int) ($person['SUP_INTER'] ?? 0)] ?? null) : null,
                'recurring_incomes' => $recurringIncomes[$inter] ?? [],
                'foreign_legislation' => ($row['VYSLANY'] ?? false) === true
                    || (self::country($row['OSS_ZEME'] ?? '') ?? 'CZ') !== 'CZ',
                'relation_type' => $relationType,
                'relation_type_derived' => $typeDerived,
                // Příznak jednatele na vztahu; druh činnosti z hlášení JMHZ má před ním přednost.
                'statutory_flag' => ($row['JEDNATEL'] ?? false) === true,
                'category' => self::text($row['UVA_KATE'] ?? ''),
                'profession' => self::limited($row['UVA_PROF'] ?? '', 80),
                'start' => $start,
                'end' => self::date($row['VYSTUP'] ?? null),
                'insurer_code' => $insurerCode,
                'insurer_registered' => (bool) ($insurer['registered'] ?? false),
                'account' => $account !== null && $bankCode !== null ? ['account' => $account, 'bank_code' => $bankCode] : null,
                'wages' => $relationWages,
                'hourly_wages' => $relationHourly,
                // Úvazek po verzích: `od` => týdně a denně (hodiny).
                'working_time' => $relationWeekly,
                'months' => $relationMonths,
                'registry' => $evidence,
                'oic' => self::oic(self::text($person['IK_MPSV'] ?? '')),
                'children' => $person !== null ? ($card['children'][self::text($person['ID'] ?? '')] ?? []) : [],
                'pension' => $card['pensions'][$inter] ?? null,
                // Mzda na účet (`KONTO_L`); F = výplata v hotovosti.
                'paid_to_account' => ($row['KONTO_L'] ?? true) !== false,
                'account_history' => $card['accounts'][$inter] ?? [],
                'absences' => $time['absences'][$inter] ?? [],
                'leave_months' => $time['leave'][$inter] ?? [],
                'average_months' => $time['averages'][$inter] ?? [],
                'sickness' => $time['sickness'][$inter] ?? [],
            ];
        }
        usort($relations, static fn (array $a, array $b): int => ((int) $a['key']) <=> ((int) $b['key']));

        // Zdravotní pojištění je údaj osoby: historie se skládá z oznámení všech jejích vztahů.
        $personEvents = [];
        $personStart = [];
        foreach ($relations as $relation) {
            $personKey = (string) $relation['person_key'];
            $personEvents[$personKey] = [...($personEvents[$personKey] ?? []), ...($insurerEvents[(int) $relation['key']] ?? [])];
            if (is_string($relation['start']) && (!isset($personStart[$personKey]) || $relation['start'] < $personStart[$personKey])) {
                $personStart[$personKey] = $relation['start'];
            }
        }
        $personLast = [];
        /** @var array<string,list<string>> $personSigned měsíce s podepsaným prohlášením osoby (přes všechny vztahy) */
        $personSigned = [];
        foreach ($relations as $relation) {
            $last = array_key_last($relation['months']);
            $personKey = (string) $relation['person_key'];
            if ($last !== null && (!isset($personLast[$personKey]) || (string) $last > $personLast[$personKey])) {
                $personLast[$personKey] = (string) $last;
            }
            foreach ($relation['months'] as $period => $m) {
                if ($m['signed'] === true) {
                    $personSigned[$personKey][] = (string) $period;
                }
            }
        }
        // Pojišťovna zpracované mzdy (`MZDY.ZKR_POJ`) je ta, které se pojistné skutečně platilo, takže pro měsíc
        // se mzdou má přednost před oznámeními pojišťovnám (evidenční zápis kódu bez odeslání ji nemění).
        /** @var array<string,array<string,string>> $personMonthly klíč osoby => `YYYY-MM` => kód pojišťovny */
        $personMonthly = [];
        foreach ($relations as $relation) {
            foreach ($relation['months'] as $period => $m) {
                if ($m['insurer_code'] !== null) {
                    $personMonthly[(string) $relation['person_key']][(string) $period] = (string) $m['insurer_code'];
                }
            }
        }
        $accounts = self::payoutAccounts($relations);
        foreach ($relations as $i => $relation) {
            $personKey = (string) $relation['person_key'];
            $relations[$i]['insurer_history'] = self::insurerHistory($personEvents[$personKey] ?? [], $personStart[$personKey] ?? null, $personMonthly[$personKey] ?? []);
            if ($relations[$i]['insurer_history'] !== []) {
                $relations[$i]['insurer_code'] = end($relations[$i]['insurer_history'])['code'];
            }
            $relations[$i]['person_last_period'] = $personLast[$personKey] ?? null;
            $signed = array_values(array_unique($personSigned[$personKey] ?? []));
            sort($signed);
            $relations[$i]['person_signed_periods'] = $signed;
            $relations[$i]['payout_accounts'] = $accounts[$personKey] ?? [];
        }
        return new self($relations, $missing, $monthRows);
    }

    public function hasData(): bool
    {
        return $this->monthRows > 0 || $this->relations !== [];
    }

    /**
     * Měsíční úhrny mezd všech vztahů za rok: to, co má deník nést.
     *
     * @return array<string,array{gross:float,employee_insurance:float,employer_insurance:float,tax:float}> `YYYY-MM` => úhrny
     */
    public function monthTotals(int $year): array
    {
        $out = [];
        foreach ($this->relations as $relation) {
            foreach ($relation['months'] as $period => $m) {
                if ((int) substr($period, 0, 4) !== $year) {
                    continue;
                }
                $out[$period] ??= ['gross' => 0.0, 'employee_insurance' => 0.0, 'employer_insurance' => 0.0, 'tax' => 0.0];
                $out[$period]['gross'] += $m['gross'];
                $out[$period]['employee_insurance'] += $m['employee_social'] + $m['employee_health'];
                $out[$period]['employer_insurance'] += $m['employer_social'] + $m['employer_health'];
                $out[$period]['tax'] += $m['advance_tax'] + $m['withholding_tax'] - $m['tax_bonus'];
            }
        }
        ksort($out);
        return array_map(static fn (array $v): array => array_map(static fn (float $x): float => round($x, 2), $v), $out);
    }

    /**
     * Zaúčtování mezd v deníku PREMIER po měsících roku (podle data zápisu):
     *  - hrubé příjmy: náklad 52x proti účtům zaměstnanců (331, 333, 366),
     *  - pojistné zaměstnance: účty zaměstnanců proti 336,
     *  - pojistné zaměstnavatele: náklad 52x proti 336,
     *  - daň: účty zaměstnanců proti 342, snížená o daňový bonus zpět na účty zaměstnanců.
     *
     * @return array<string,array{gross:float,employee_insurance:float,employer_insurance:float,tax:float}>
     */
    public static function ledgerTotals(PremierJournal $journal, int $year): array
    {
        $out = [];
        foreach ($journal->year($year) as $r) {
            // Strany a znaménko tak, jak jsou v deníku: storno zápornou částkou na stejné
            // strany i opravný zápis obrácenými stranami úhrn snižují.
            $debit = (string) $r['md'];
            $credit = (string) $r['dal'];
            if ($debit === '' || $credit === '' || $debit === $credit || abs((float) $r['amount']) < 0.005) {
                continue;
            }
            $key = self::ledgerKey($debit, $credit) ?? self::ledgerKey($credit, $debit);
            if ($key === null) {
                continue;
            }
            $sign = self::ledgerKey($debit, $credit) !== null ? 1 : -1;
            $period = substr((string) $r['date'], 0, 7);
            $out[$period] ??= ['gross' => 0.0, 'employee_insurance' => 0.0, 'employer_insurance' => 0.0, 'tax' => 0.0];
            $out[$period][$key[0]] += $sign * $key[1] * (float) $r['amount'];
        }
        ksort($out);
        return array_map(static fn (array $v): array => array_map(static fn (float $x): float => round($x, 2), $v), $out);
    }

    /**
     * Mzdy proti deníku po měsících. Měsíc, ve kterém se úhrn liší o víc než haléř, je rozdíl.
     *
     * @param array<string,array<string,float>> $payroll {@see monthTotals()}
     * @param array<string,array<string,float>> $ledger {@see ledgerTotals()}
     * @return list<array{period:string,ok:bool,payroll:array<string,float>,ledger:array<string,float>,diffs:array<string,float>}>
     */
    public static function reconcile(array $payroll, array $ledger): array
    {
        $zero = ['gross' => 0.0, 'employee_insurance' => 0.0, 'employer_insurance' => 0.0, 'tax' => 0.0];
        $periods = array_unique(array_merge(array_keys($payroll), array_keys($ledger)));
        sort($periods);
        $out = [];
        foreach ($periods as $period) {
            $p = ($payroll[$period] ?? []) + $zero;
            $l = ($ledger[$period] ?? []) + $zero;
            $diffs = [];
            foreach (array_keys($zero) as $k) {
                $d = round($p[$k] - $l[$k], 2);
                if (abs($d) >= 0.01) {
                    $diffs[$k] = $d;
                }
            }
            $out[] = ['period' => $period, 'ok' => $diffs === [], 'payroll' => $p, 'ledger' => $l, 'diffs' => $diffs];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function month(array $row): array
    {
        $num = static fn (string $f): float => round((float) ($row[$f] ?? 0), 2);
        $participates = ($row['POJIS_SO'] ?? false) === true;
        $calendarDays = (int) round((float) (($row['KAL_DNYPP'] ?? 0) ?: ($row['KAL_DNY'] ?? 0)));
        $workedDays = $num('DNY_ODPR');
        $dailyHours = $num('UVA_DOBA');
        $insurer = self::text($row['ZKR_POJ'] ?? '');
        return [
            'gross' => round($num('MZ_HRUBA') + $num('MZ_ODSTAT'), 2),
            'net' => $num('MZ_CISTA'),
            'net_payable' => $num('MZ_VYPLATA'),
            'deductions' => round(max(0.0, $num('SR_SPOR') + $num('SR_POJI') + $num('SR_VYZI') + $num('SR_OST1') + $num('SR_OST2')), 2),
            'social_base' => $num('VYM_SOC'),
            'health_base' => $num('VYM_ZDR'),
            'employee_social' => $num('MZ_SOC'),
            'employee_health' => $num('MZ_ZDR'),
            'employer_social' => $num('MZ_SOCF'),
            'employer_health' => $num('MZ_ZDRF'),
            'health_top_up' => $num('MZ_ZDR2'),
            'advance_base' => $num('MZ_ZDANI'),
            'advance_tax' => $num('MZ_DAN'),
            'withholding_base' => $num('MZ_SDANI'),
            'withholding_tax' => $num('MZ_SDAN'),
            'tax_bonus' => $num('MZ_BONUS'),
            'non_refundable' => round($num('NEZD_VLAS') + $num('NEZD_INVA') + $num('NEZD_ZTP') + $num('NEZD_ZACI'), 2),
            'child' => $num('NEZD_DETI'),
            // Podepsané prohlášení je jen `POD_DAN`. `NEZD_A` nese PREMIER i u řádků
            // zdaněných srážkou (`SRAZ_DAN`), kde prohlášení podepsané není.
            'signed' => ($row['POD_DAN'] ?? false) === true,
            // Uplatněná sleva na pojistném pracujícího důchodce (Kč za měsíc).
            'pensioner_discount' => $num('SLEVA_SOC') > 0,
            // Příspěvek zaměstnavatele na penzijní připojištění (karta MZ_SRAZ 422, na záloze
            // shodný s položkou 422 v DNY): čerpání koše § 6 odst. 9 písm. p) ZDP v převzatém měsíci.
            'old_age_savings' => max(0.0, $num('MZ_PENZ')),
            'pension_participation' => $participates,
            'insurance_days' => $participates ? max(0, min($calendarDays, 31)) : 0,
            // `VYL_DND` je v reálných zálohách vždy prázdný; kalendářní dny nemoci
            // a neplaceného volna nese `DNY_NEKA`.
            'excluded_days' => max(0, min((int) round($num('DNY_NEKA')), 31)),
            'worked_days' => max(0.0, $workedDays),
            'worked_minutes' => max(0, (int) round($workedDays * $dailyHours * 60)),
            'insurer_code' => preg_match('/^[0-9]{3}$/D', $insurer) === 1 ? $insurer : null,
        ];
    }

    /**
     * Druh vztahu: jednatel (příznak `JEDNATEL`, kód činnosti `S` nebo kategorie `SJK`
     * - společník, jednatel, komanditista), dohody podle kódu činnosti ČSSZ nebo textu
     * kategorie, jinak pracovní poměr. Druhá hodnota říká, že druh vyšel z výchozí volby.
     *
     * `KODPP_SO` je v zálohách prázdný; druh činnosti pak nese poslední formulář JMHZ
     * vztahu (`X10239`, číselník JMHZ: 1-9 pracovní poměr, A-J DPČ, T-Z a ZA-ZC DPP,
     * S člen orgánu). Na reálné záloze sedí na kategorii ve všech formulářích.
     *
     * @param array<string,mixed> $row
     * @return array{0:string,1:bool}
     */
    private static function relationType(array $row, ?string $jmhzActivity = null): array
    {
        $activity = strtoupper(self::text($row['KODPP_SO'] ?? ''));
        if ($activity === '' && is_string($jmhzActivity)) {
            $jmhz = strtoupper($jmhzActivity);
            $type = match (true) {
                $jmhz === 'S' => 'statutory_body',
                preg_match('/^[1-9]$/D', $jmhz) === 1 => 'employment',
                preg_match('/^[A-J]$/D', $jmhz) === 1 => 'dpc',
                preg_match('/^(?:[T-Z]|Z[A-C])$/D', $jmhz) === 1 => 'dpp',
                default => null,
            };
            if ($type !== null) {
                return [$type, false];
            }
        }
        $category = mb_strtoupper(self::text($row['UVA_KATE'] ?? '') . ' ' . self::text($row['KATEGO'] ?? ''));
        if (($row['JEDNATEL'] ?? false) === true || $activity === 'S' || preg_match('/\bSJK\b|JEDNATEL|STATUT/u', $category) === 1) {
            return ['statutory_body', false];
        }
        // `KODPP_SO` je v zálohách prázdný, učeň se pozná jen podle kategorie. Bez tohohle
        // pravidla padal do výchozího pracovního poměru.
        if (preg_match('/\bUCN\b|UČE[NŇ]/u', $category) === 1) {
            return [self::APPRENTICE, false];
        }
        if (in_array($activity, self::DPP_CODES, true) || preg_match('/\bDPP\b|PROVEDEN/u', $category) === 1) {
            return ['dpp', false];
        }
        if (preg_match('/^[A-J]$/D', $activity) === 1 || preg_match('/\bDP[CČ]\b|PRACOVNÍ ČINNOST/u', $category) === 1) {
            return ['dpc', false];
        }
        if (preg_match('/^[1-9]$/D', $activity) === 1 || preg_match('/\bHPP\b|PRACOVNÍ POMĚR/u', $category) === 1) {
            return ['employment', false];
        }
        return ['employment', true];
    }

    /**
     * Srážky z čisté mzdy po měsících z položek mezd (`DNY`): složky 7xx, které čistou mzdu
     * snižují (číselník `MZDY_POL` je vede s příznakem `IS_NETTO`): spoření a půjčky,
     * exekuce a insolvence včetně nákladů, stravenky, odbory, záloha na mzdu, provozní
     * srážky. Stravenkový paušál (712) je příjem, zúčtování cestovního příkazu (721)
     * náhrada, ne srážka.
     *
     * Na reálné záloze (agregovaně) se součet `SR_*` v `MZDY` rovná součtu těchto složek
     * bez stravenek (710) a zálohy na mzdu (750) ve 145 ze 157 měsíců se srážkou; ve
     * zbylých chybí právě záloha na mzdu. Obojí přitom čistou mzdu k výplatě snižuje.
     *
     * Vrací jen vztahy a měsíce, pro které `DNY` nějaké položky má; ostatní zůstávají
     * na `SR_*` z `MZDY` (starší zálohy bez položek).
     *
     * @return array<int,array<string,float>> INTER => `YYYY-MM` => sraženo (Kč)
     */
    private static function monthItems(PremierBackup $backup): array
    {
        $out = [];
        foreach ($backup->rows('DNY') as $row) {
            $inter = (int) ($row['INTER'] ?? 0);
            $period = self::itemPeriod($row);
            if ($inter <= 0 || $period === null) {
                continue;
            }
            $code = self::text($row['KOD'] ?? '');
            $out[$inter][$period] = ($out[$inter][$period] ?? 0.0)
                + (in_array($code, self::DEDUCTION_CODES, true) ? (float) ($row['CASTKA'] ?? 0) : 0.0);
        }
        return $out;
    }

    /**
     * Měsíc mzdy, do kterého položka `DNY` patří (`DNY_ROK`/`DNY_MES`, jinak `DATUM_OD`).
     *
     * @param array<string,mixed> $row
     */
    private static function itemPeriod(array $row): ?string
    {
        $year = (int) ($row['DNY_ROK'] ?? 0);
        $month = (int) ($row['DNY_MES'] ?? 0);
        if ($year >= 1990 && $month >= 1 && $month <= 12) {
            return sprintf('%04d-%02d', $year, $month);
        }
        $from = self::date($row['DATUM_OD'] ?? null);
        return $from === null ? null : substr($from, 0, 7);
    }

    /**
     * Sjednaná mzda jedné verze `PERS_HYS`: `SAZBA_MZ` podle `TYP_MZDY` (1 = měsíční mzda
     * v Kč za měsíc, 2 = hodinová sazba, 0 = bez mzdy). `MZDA_MES` je jen u starších
     * verzí bez typu mzdy.
     *
     * Ověřeno na reálné záloze (agregovaně): u verzí s typem 1 se `SAZBA_MZ` rovná částce
     * měsíční mzdy (složka 101) za celý měsíc v `DNY` v 47 z 56 porovnatelných verzí,
     * `MZDA_MES` ani jednou tam, kde se od sazby liší (a vyplněné je jen u 29 % verzí).
     * U typu 2 je `SAZBA_MZ` vždy pod 1 000 Kč, tedy hodinová sazba.
     *
     * @param array<string,mixed> $row
     * @return array{0:?string,1:float} [`monthly` | `hourly` | null, částka]
     */
    private static function agreedWage(array $row): array
    {
        $rate = round((float) ($row['SAZBA_MZ'] ?? 0), 2);
        $monthly = round((float) ($row['MZDA_MES'] ?? 0), 2);
        return match ((int) ($row['TYP_MZDY'] ?? 0)) {
            1 => $rate > 0 ? ['monthly', $rate] : ($monthly > 0 ? ['monthly', $monthly] : [null, 0.0]),
            2 => $rate > 0 ? ['hourly', $rate] : [null, 0.0],
            default => $monthly > 0 ? ['monthly', $monthly] : [null, 0.0],
        };
    }

    /**
     * Výplatní účty osoby: aktivní je účet posledního vztahu (podle nástupu), na který
     * PREMIER mzdu vyplácí (`KONTO_L`); dřívější účty z historie změn (`MZ_PERH`) a účty
     * jiných vztahů osoby se zapíšou jako účty bez výplat, aby historie nezmizela. Osoba,
     * které PREMIER vyplácí v hotovosti, účet nedostane.
     *
     * @param list<array<string,mixed>> $relations
     * @return array<string,list<array{account:string,bank_code:string,active:bool}>> klíč osoby => účty
     */
    private static function payoutAccounts(array $relations): array
    {
        $byPerson = [];
        foreach ($relations as $relation) {
            $byPerson[(string) $relation['person_key']][] = $relation;
        }
        $out = [];
        foreach ($byPerson as $personKey => $list) {
            usort($list, static fn (array $a, array $b): int => [(string) $b['start'], (int) $b['key']] <=> [(string) $a['start'], (int) $a['key']]);
            $latest = $list[0];
            if (!is_array($latest['account']) || $latest['paid_to_account'] !== true) {
                continue;
            }
            $accounts = [['account' => $latest['account']['account'], 'bank_code' => $latest['account']['bank_code'], 'active' => true]];
            $seen = [self::accountKey($latest['account']['account'], $latest['account']['bank_code']) => true];
            foreach ($list as $relation) {
                $candidates = array_reverse((array) $relation['account_history']);
                if (is_array($relation['account'])) {
                    array_unshift($candidates, $relation['account']);
                }
                foreach ($candidates as $candidate) {
                    $key = self::accountKey((string) $candidate['account'], (string) $candidate['bank_code']);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $accounts[] = ['account' => (string) $candidate['account'], 'bank_code' => (string) $candidate['bank_code'], 'active' => false];
                }
            }
            $out[$personKey] = $accounts;
        }
        return $out;
    }

    private static function accountKey(string $account, string $bankCode): string
    {
        [$prefix, $number] = str_contains($account, '-') ? explode('-', $account, 2) : ['', $account];
        return ltrim(trim($prefix), '0') . '-' . ltrim(trim($number), '0') . '/' . $bankCode;
    }

    /**
     * Historie zdravotní pojišťovny osoby z oznámení pojišťovnám (`MZ_PRIZP`): přihláška
     * (`KOD` P) a změna pojišťovny (Q, M) začínají úsek s kódem `ZKRATKA_P`. Odhláška (O)
     * úsek neukončuje: zákonná evidence musí navazovat bez děr a o pojištění mimo vztahy
     * osoby převod nic neví. Úseky jsou po celých měsících, jak je evidence vyžaduje;
     * dvě oznámení v jednom měsíci rozhoduje to pozdější. První úsek začíná nejpozději
     * měsícem prvního nástupu osoby.
     *
     * Měsíc, za který PREMIER zpracoval mzdu, má pojišťovnu té mzdy (`$monthly`, `ZKR_POJ`):
     * ta, které se pojistné skutečně platilo. Oznámení bez odeslání pojišťovně (evidenční
     * zápis kódu, `PRIJATO` prázdné) jinak vyrobilo úsek, který platnou pojišťovnu měsíců
     * se mzdou přebil. Měsíce bez mzdy platí z oznámení.
     *
     * @param list<array{date:string,code:string,kind:string}> $events
     * @param array<string,string> $monthly `YYYY-MM` => kód pojišťovny zpracované mzdy
     * @return list<array{code:string,from:string,to:?string,reference:string}>
     */
    public static function insurerHistory(array $events, ?string $start, array $monthly = []): array
    {
        $events = array_values(array_filter($events, static fn (array $e): bool => in_array($e['kind'], ['P', 'Q', 'M'], true)));
        if ($events === [] && $monthly === []) {
            return [];
        }
        usort($events, static fn (array $a, array $b): int => [$a['date'], $a['kind'] === 'P' ? 0 : 1] <=> [$b['date'], $b['kind'] === 'P' ? 0 : 1]);
        $runs = [];
        foreach ($events as $event) {
            $month = substr($event['date'], 0, 7) . '-01';
            $last = array_key_last($runs);
            if ($last !== null && $runs[$last]['from'] === $month) {
                $runs[$last]['code'] = $event['code'];
                $runs[$last]['reference'] = 'premier:mz_prizp:' . $event['kind'] . ':' . $event['date'];
                if ($last > 0 && $runs[$last - 1]['code'] === $event['code']) {
                    array_pop($runs);
                }
                continue;
            }
            if ($last !== null && $runs[$last]['code'] === $event['code']) {
                continue;
            }
            $runs[] = ['code' => $event['code'], 'from' => $month, 'to' => null, 'reference' => 'premier:mz_prizp:' . $event['kind'] . ':' . $event['date']];
        }
        if ($monthly !== []) {
            $runs = self::overrideWithPayrollMonths($runs, $monthly);
        }
        if ($runs === []) {
            return [];
        }
        if (is_string($start) && substr($start, 0, 7) . '-01' < $runs[0]['from']) {
            $runs[0]['from'] = substr($start, 0, 7) . '-01';
        }
        foreach ($runs as $i => $run) {
            if (isset($runs[$i + 1])) {
                $runs[$i]['to'] = (new \DateTimeImmutable($runs[$i + 1]['from']))->modify('-1 day')->format('Y-m-d');
            }
        }
        return $runs;
    }

    /**
     * Úseky z oznámení pojišťovnám s měsíci se zpracovanou mzdou: pro měsíc se mzdou platí
     * její pojišťovna, ostatní měsíce zůstávají na oznámeních. Úsek se láme na každém měsíci,
     * ve kterém se zdroj mění, a na měsíci po posledním měsíci se mzdou (tam se vrací oznámení).
     *
     * @param list<array{code:string,from:string,to:?string,reference:string}> $runs
     * @param array<string,string> $monthly
     * @return list<array{code:string,from:string,to:?string,reference:string}>
     */
    private static function overrideWithPayrollMonths(array $runs, array $monthly): array
    {
        $points = [];
        foreach ($runs as $run) {
            $points[$run['from']] = true;
        }
        foreach (array_keys($monthly) as $period) {
            $month = $period . '-01';
            $points[$month] = true;
            $points[(new \DateTimeImmutable($month))->modify('+1 month')->format('Y-m-d')] = true;
        }
        ksort($points);
        $out = [];
        foreach (array_keys($points) as $month) {
            $period = substr($month, 0, 7);
            if (isset($monthly[$period])) {
                $code = $monthly[$period];
                $reference = 'premier:mzdy:zkr_poj:' . $period;
            } else {
                $base = null;
                foreach ($runs as $run) {
                    if ($run['from'] <= $month) {
                        $base = $run;
                    }
                }
                if ($base === null) {
                    continue;
                }
                $code = $base['code'];
                $reference = $base['reference'];
            }
            $last = array_key_last($out);
            if ($last !== null && $out[$last]['code'] === $code) {
                continue;
            }
            $out[] = ['code' => $code, 'from' => $month, 'to' => null, 'reference' => $reference];
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private static function birthNumber(array $row): ?string
    {
        $first = self::text($row['RC_1'] ?? '');
        $second = self::text($row['RC_2'] ?? '');
        return preg_match('/^[0-9]{6}$/D', $first) === 1 && preg_match('/^[0-9]{3,4}$/D', $second) === 1 ? $first . '/' . $second : null;
    }

    /**
     * Adresa; stát je v PREMIER volný text (`STAT` C(28), v `PERSON2` jen tři znaky).
     * Kód státu vyplní rovnou jen u dvoupísmenného kódu a českých variant; jinak nese
     * text (`country_text`) a na kód ho převede až {@see PremierPayrollTakeover::address()}
     * číselníkem zemí.
     *
     * @param array<string,mixed> $row
     * @param array{0:string,1:string,2:string,3:string,4:string,5:string} $columns ulice, číslo, město, obec, PSČ, stát
     * @return array{street_line:string,city:string,postal_code:string,country_code:?string,country_text:string}|null
     */
    private static function address(array $row, array $columns = ['ULICE', 'CISLOP', 'MESTO', 'OBEC', 'PSC', 'STAT']): ?array
    {
        [$streetColumn, $numberColumn, $cityColumn, $municipalityColumn, $postalColumn, $countryColumn] = $columns;
        $city = self::text($row[$cityColumn] ?? '') ?: self::text($row[$municipalityColumn] ?? '');
        $postal = self::text($row[$postalColumn] ?? '');
        if ($city === '' || $postal === '') {
            return null;
        }
        $countryText = self::text($row[$countryColumn] ?? '');
        $street = trim(self::text($row[$streetColumn] ?? '') . ' ' . self::text($row[$numberColumn] ?? ''));
        return [
            'street_line' => mb_substr($street !== '' ? $street : $city, 0, 191),
            'city' => mb_substr($city, 0, 128),
            'postal_code' => mb_substr($postal, 0, 24),
            'country_code' => self::country($countryText) ?? ($countryText === '' ? 'CZ' : null),
            'country_text' => $countryText,
        ];
    }

    /**
     * Kontaktní adresy osob z `PER_ADR` podle `INTER` = `PER_MAIN.SUP_INTER` (ověřeno na
     * reálné záloze: všechny řádky `PER_ADR` se tak spárují s osobou, s `PERSONAL.INTER`
     * jen polovina). `DRUH_ADR` 1 je kopie trvalé adresy, 2 další adresa; z nich má
     * přednost ta s příznakem korespondenční (`XKORES`), pak poslední podle `TS`.
     *
     * @return array<int,array{street_line:string,city:string,postal_code:string,country_code:?string,country_text:string}>
     */
    private static function mailingAddresses(PremierBackup $backup): array
    {
        $best = [];
        foreach ($backup->rows('PER_ADR') as $row) {
            if ((int) ($row['DRUH_ADR'] ?? 0) !== 2) {
                continue;
            }
            $inter = (int) ($row['INTER'] ?? 0);
            $rank = [($row['XKORES'] ?? false) === true ? 1 : 0, self::text($row['TS'] ?? '')];
            if (!isset($best[$inter]) || $rank >= $best[$inter][0]) {
                $best[$inter] = [$rank, $row];
            }
        }
        $out = [];
        foreach ($best as $inter => [, $row]) {
            $row['XSTAT_KOD'] = self::text($row['XZEME'] ?? '') !== '' ? self::text($row['XZEME'] ?? '') : self::text($row['XSTAT'] ?? '');
            $address = self::address($row, ['XULICE', 'XCISLO', 'XMESTO', 'XOBEC', 'XPSC', 'XSTAT_KOD']);
            if ($address !== null) {
                $out[$inter] = $address;
            }
        }
        return $out;
    }

    private static function country(mixed $value): ?string
    {
        $value = mb_strtoupper(self::text($value));
        if (preg_match('/^[A-Z]{2}$/D', $value) === 1) {
            return $value;
        }
        // „ČES" je název státu uříznutý na šířku pole `PERSON2.STAT` C(3).
        return in_array($value, ['CZE', 'ČR', 'ČES', 'ČESKÁ REPUBLIKA', 'ČESKO'], true) ? 'CZ' : null;
    }

    /**
     * Úhrn, do kterého zápis MD `$debit` / D `$credit` patří, a jeho znaménko. Daňový
     * bonus (MD 342 / D 331) daň snižuje; obrácený zápis ostatních úhrnů řeší volající.
     *
     * @return array{0:string,1:int}|null
     */
    private static function ledgerKey(string $debit, string $credit): ?array
    {
        $employeeDebit = self::startsWithAny($debit, self::EMPLOYEE_ACCOUNTS);
        $employeeCredit = self::startsWithAny($credit, self::EMPLOYEE_ACCOUNTS);
        return match (true) {
            str_starts_with($debit, '52') && $employeeCredit => ['gross', 1],
            $employeeDebit && str_starts_with($credit, '336') => ['employee_insurance', 1],
            str_starts_with($debit, '52') && str_starts_with($credit, '336') => ['employer_insurance', 1],
            $employeeDebit && str_starts_with($credit, '342') => ['tax', 1],
            default => null,
        };
    }

    /** @param list<string> $prefixes */
    private static function startsWithAny(string $code, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** OIČ osoby z `PER_MAIN.IK_MPSV` (text), jen deset číslic; kontrolní číslici ověří zápis. */
    private static function oic(string $value): ?string
    {
        $digits = (string) preg_replace('/\s+/', '', $value);
        return preg_match('/^[0-9]{10}$/D', $digits) === 1 ? $digits : null;
    }

    private static function email(string $value): ?string
    {
        return $value !== '' && strlen($value) <= 191 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }

    private static function phone(string $value): ?string
    {
        return preg_match('/^\+?[0-9][0-9 ()\/.-]{4,39}$/', $value) === 1 ? $value : null;
    }

    private static function digits(string $value, int $max): ?string
    {
        $value = (string) preg_replace('/\s+/u', '', $value);
        return preg_match('/^[0-9]{1,' . $max . '}$/D', $value) === 1 ? $value : null;
    }

    private static function limited(mixed $value, int $max): ?string
    {
        $value = self::text($value);
        return $value === '' ? null : mb_substr($value, 0, $max);
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
