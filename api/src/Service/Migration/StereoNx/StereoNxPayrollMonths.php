<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Service\Payroll\Migration\PayrollMigrationTakeoverFacts;

/** Ověřené historické mzdy Stereo NX, bez přepočtu podle aktuálních předpisů. */
final class StereoNxPayrollMonths
{
    private const MONEY_FIELDS = [
        'HrubaMzda', 'CistaMzda', 'ZaklSP', 'ZaklZP', 'SPZa', 'ZPZa',
        'Dan', 'Srazky', 'Dobirka', 'StravPO',
    ];
    private const ZERO_FIELDS = [
        'PPSKlicMzdy', 'PPSHrubaMzda', 'PPSZaklZP', 'PPSZPZa', 'PPSZaklSP', 'PPSSPZa',
        'PPZa', 'PPPod', 'ZiPZa', 'ZiPPod', 'PPZiPZvys',
        'NVDny', 'NVZa', 'DSZa', 'Zaloha', 'Davky', 'RocniVyuct',
        'RocniVyuctPrepl', 'RocniVyuctDanBon', 'Neodpr', 'ZdanitPrijem',
        'Nahr4Nahr', 'Nahr4Neodpr', 'DovolenaD', 'DovolenaH',
        'OmluvAbsence', 'OmluvAbsencePr', 'VylouceneDobyPr',
        'NeodprPracH', 'VlivNaOdprH', 'StravPNaD', 'StravPNeD',
    ];
    private const RATE_FIELDS = [
        'SOCPodnikatel', 'SOCPojistneP', 'ZDRPojistneP', 'ZDRPodnikatel',
    ];

    /** @param array<string,list<array<string,mixed>>> $tables */
    public static function fromTables(array $tables, array $identity, int $companyIndex): array
    {
        foreach (['MZAMEST', 'MMzdy', 'Gparrok', 'MOdvPar'] as $name) {
            if (!array_key_exists($name, $tables)) {
                throw new StereoNxException('payroll_table_missing', 'Chybí zdrojová mzdová tabulka.');
            }
        }
        $ico = preg_replace('/\D/', '', (string) ($identity['ico'] ?? '')) ?? '';
        if (preg_match('/^[0-9]{8}$/D', $ico) !== 1 || $companyIndex < 0) {
            throw new StereoNxException('payroll_source_identity_invalid', 'Zdrojová firma nemá platnou identitu.');
        }

        $people = [];
        foreach ($tables['MZAMEST'] as $employee) {
            $key = self::key($employee['Prac'] ?? null);
            if ($key === null) {
                throw new StereoNxException('employee_key_invalid', 'Karta zaměstnance nemá platný zdrojový klíč.');
            }
            if (isset($people[$key])) {
                throw new StereoNxException('employee_key_duplicate', 'Zdroj obsahuje duplicitní kartu zaměstnance.');
            }
            $people[$key] = $employee;
        }

        $keys = [];
        $periods = [];
        foreach ($tables['MMzdy'] as $month) {
            $key = self::key($month['Klic'] ?? null);
            $employeeKey = self::key($month['Prac'] ?? null);
            if ($key === null || $employeeKey === null) {
                throw new StereoNxException('monthly_key_invalid', 'Mzda nemá platný zdrojový klíč.');
            }
            if (isset($keys[$key])) {
                throw new StereoNxException('monthly_key_duplicate', 'Zdroj obsahuje duplicitní klíč mzdy.');
            }
            $keys[$key] = true;
            $period = self::period($month);
            if ($period !== null) {
                $pair = $employeeKey . "\0" . $period;
                if (isset($periods[$pair])) {
                    throw new StereoNxException('monthly_period_duplicate', 'Zdroj obsahuje duplicitní mzdu osoby za měsíc.');
                }
                $periods[$pair] = true;
            }
        }

        $warnings = [];
        $records = [];
        foreach ($tables['MMzdy'] as $month) {
            $employeeKey = (string) self::key($month['Prac']);
            $period = self::period($month);
            $reason = self::unsupportedReason(
                $month,
                $people[$employeeKey] ?? null,
                $period,
                $tables['Gparrok'],
                $tables['MOdvPar'],
            );
            if ($reason !== null) {
                self::warning($warnings, $reason);
                continue;
            }
            $employee = $people[$employeeKey];
            $rates = self::effective($tables['Gparrok'], $period . '-01', null);
            $gross = self::minor($month['HrubaMzda']);
            $net = self::minor($month['CistaMzda']);
            $social = self::minor($month['SPZa']);
            $health = self::minor($month['ZPZa']);
            $tax = self::minor($month['Dan']);
            $deductions = self::minor($month['Srazky']);
            $meal = self::minor($month['StravPO']);
            $payable = self::minor($month['Dobirka']);
            $socialBase = self::minor($month['ZaklSP']);
            $healthBase = self::minor($month['ZaklZP']);

            if ($net !== $gross - $social - $health - $tax) {
                self::warning($warnings, 'monthly_net_control_failed');
                continue;
            }
            if ($payable !== $net - $deductions + $meal) {
                self::warning($warnings, 'monthly_payout_control_failed');
                continue;
            }
            if ($social !== self::ceilRate($socialBase, (float) $rates['SOCPojistneP'])
                || $health !== self::ceilRate($healthBase, (float) $rates['ZDRPojistneP'])) {
                self::warning($warnings, 'monthly_employee_insurance_control_failed');
                continue;
            }

            $employerSocial = self::ceilRate($socialBase, (float) $rates['SOCPodnikatel']);
            // Stereo počítá zdravotní pojistné za osobu celkem a odečte sraženou část.
            $employerHealth = self::ceilRate(
                $healthBase,
                (float) $rates['ZDRPojistneP'] + (float) $rates['ZDRPodnikatel'],
            ) - $health;
            if ($employerHealth < 0) {
                self::warning($warnings, 'monthly_employer_insurance_invalid');
                continue;
            }
            $days = (int) (new \DateTimeImmutable($period . '-01'))->format('t');
            $facts = new PayrollMigrationTakeoverFacts(
                relationshipStartDate: self::date($employee['DatumNastupu']),
                relationshipEndDate: self::date($employee['DatumUkonceni'] ?? null),
                relationType: 'employment',
                pensionParticipation: true,
                insuranceDays: $days,
                excludedDays: 0,
                workedDaysHundredths: (int) round((float) $month['OdpracovaneD'] * 100),
                workedMinutes: (int) round((float) $month['OdpracovaneH'] * 60),
                deductionsMinor: $deductions,
                netPayableMinor: $payable,
                payoutDate: null,
            );
            $amounts = [
                'gross' => $gross / 100, 'net' => $net / 100,
                'social_base' => $socialBase / 100, 'health_base' => $healthBase / 100,
                'employee_social' => $social / 100, 'employee_health' => $health / 100,
                'employer_social' => $employerSocial / 100,
                'employer_health' => $employerHealth / 100,
                'advance_tax' => $tax / 100, 'withholding_tax' => 0, 'tax_bonus' => 0,
            ];
            $record = [
                'source_key' => (string) $month['Klic'],
                'employee_key' => $employeeKey,
                'period' => $period,
                'amounts' => $amounts,
                'facts' => get_object_vars($facts),
                'derivation_rates' => array_intersect_key($rates, array_flip(['DatumOd', ...self::RATE_FIELDS])),
            ];
            $record['source_hash'] = StereoNxImportMap::fingerprint($record);
            $records[] = $record;
        }
        if ($records !== []) {
            self::warning($warnings, 'employer_amounts_reconstructed');
            self::warning($warnings, 'historical_deductions_not_openings');
            self::warning($warnings, 'payroll_institution_accounts_unverified');
        }
        $lastSourcePeriod = $periods === [] ? null : max(array_map(
            static fn (string $key): string => substr($key, strpos($key, "\0") + 1),
            array_keys($periods),
        ));
        return [
            'source_ico' => $ico,
            'source_company_index' => $companyIndex,
            'last_source_period' => $lastSourcePeriod,
            'counts' => [
                'historical_payroll_source' => count($tables['MMzdy']),
                'historical_payroll_ready' => count($records),
                'historical_payroll_skipped' => count($tables['MMzdy']) - count($records),
            ],
            'warnings' => array_values($warnings),
            'records' => $records,
        ];
    }

    private static function unsupportedReason(
        array $month,
        ?array $employee,
        ?string $period,
        array $rates,
        array $parameters,
    ): ?string {
        if ($employee === null) return 'monthly_employee_orphan';
        if ($period === null) return 'monthly_period_invalid';
        $wagePeriod = self::date($month['MzOb'] ?? null);
        if ($wagePeriod === null || substr($wagePeriod, 0, 7) !== $period) {
            return 'monthly_period_conflict';
        }
        if (($employee['PracPravVztah'] ?? null) !== 'P'
            || ($employee['Odvod'] ?? null) !== 'HPP'
            || ($employee['Zamestnanec'] ?? null) !== true
            || ($employee['StatOrg'] ?? null) !== false) return 'employment_type_unsupported';
        // Účast a zaměstnavatelská sazba nejsou z těchto příznaků dopočitatelné.
        // Zejména ZakPoj=false nesmí vytvořit celý měsíc insuranceDays.
        foreach ([
            'ZakPoj' => true, 'ZamMR' => false, 'ZPS' => 'N',
            'MinZamestnavatel' => '', 'PracRezim' => '', 'DruhD' => '',
            'OZP' => false, 'Duchod' => 0.0, 'DuchodDruh' => '0',
            'DuchodPredcasny' => false, 'DuchodSnizVek' => false,
            'HlavniPPV' => true, 'DruhCinnosti' => '', 'ELDPKod' => '',
            'StatVD' => false,
        ] as $field => $standard) {
            if (($employee[$field] ?? null) !== $standard) {
                return 'employment_insurance_regime_unsupported';
            }
        }
        if (($month['Odvod'] ?? null) !== 'HPP'
            || ($month['TypDan'] ?? null) !== 'Z'
            || ($month['Prohlaseni'] ?? null) !== true
            || ($month['JenDP'] ?? null) !== false
            || ($month['DS'] ?? null) !== false
            || ($month['NVOdvZa'] ?? null) !== false) return 'monthly_type_unsupported';
        if (($month['PPPoj'] ?? null) !== '' || ($month['ZiPPoj'] ?? null) !== ''
            || ($month['ZdanitPrijemT'] ?? null) !== '') return 'monthly_parallel_employer_values';
        foreach (self::ZERO_FIELDS as $field) {
            if (self::nonnegativeMinor($month[$field] ?? null) !== 0) {
                return 'monthly_adjustment_unsupported';
            }
        }
        foreach (self::MONEY_FIELDS as $field) {
            if (self::nonnegativeMinor($month[$field] ?? null) === null) {
                return 'monthly_amount_missing_or_invalid';
            }
        }
        $start = self::date($employee['DatumNastupu'] ?? null);
        $end = self::date($employee['DatumUkonceni'] ?? null);
        $lastDay = (new \DateTimeImmutable($period . '-01'))->format('Y-m-t');
        if ($start === null || $start > $period . '-01'
            || (($employee['DatumUkonceni'] ?? null) !== null && $end === null)
            || ($end !== null && $end < $lastDay)) return 'monthly_insurance_days_unsupported';
        foreach (['NeprKalDny', 'VylouceneDoby', 'NemocD', 'NeodprPracDny'] as $field) {
            if (self::boundedNumber($month[$field] ?? null, 31) !== 0.0) {
                return 'monthly_insurance_days_unsupported';
            }
        }
        $days = self::boundedNumber($month['OdpracovaneD'] ?? null, 31);
        $hours = self::boundedNumber($month['OdpracovaneH'] ?? null, 744);
        if ($days === null || $hours === null
            || abs($days * 100 - round($days * 100)) > 0.000001
            || abs($hours * 60 - round($hours * 60)) > 0.000001) {
            return 'monthly_worked_time_invalid';
        }
        $rate = self::effective($rates, $period . '-01', null);
        if ($rate === null || self::hasMidmonthChange($rates, $period, null)) {
            return 'payroll_rate_missing_or_ambiguous';
        }
        foreach (self::RATE_FIELDS as $field) {
            $value = self::boundedNumber($rate[$field] ?? null, 100);
            if ($value === null || $value <= 0) {
                return 'payroll_rate_missing_or_ambiguous';
            }
        }
        $parameter = self::effective($parameters, $period . '-01', 'HPP');
        if ($parameter === null || self::hasMidmonthChange($parameters, $period, 'HPP')
            || ($parameter['SocPoj'] ?? null) !== 'P'
            || ($parameter['ZdrPoj'] ?? null) !== 'M'
            || ($parameter['ZamMR'] ?? null) !== false
            || ($parameter['SPTyp'] ?? null) !== '') {
            return 'payroll_parameters_unverified';
        }
        return null;
    }

    private static function effective(array $rows, string $date, ?string $odvod): ?array
    {
        $best = null;
        $bestDate = null;
        $bestCount = 0;
        foreach ($rows as $row) {
            if ($odvod !== null && ($row['Odvod'] ?? null) !== $odvod) continue;
            $start = self::date($row['DatumOd'] ?? null);
            if ($start === null || $start > $date) continue;
            if ($bestDate !== null && $start === $bestDate) {
                $bestCount++;
            } elseif ($bestDate === null || $start > $bestDate) {
                $best = $row;
                $bestDate = $start;
                $bestCount = 1;
            }
        }
        return $bestCount === 1 ? $best : null;
    }

    private static function hasMidmonthChange(array $rows, string $period, ?string $odvod): bool
    {
        foreach ($rows as $row) {
            if ($odvod !== null && ($row['Odvod'] ?? null) !== $odvod) continue;
            $date = self::date($row['DatumOd'] ?? null);
            if ($date !== null && substr($date, 0, 7) === $period && substr($date, 8) !== '01') {
                return true;
            }
        }
        return false;
    }

    private static function period(array $row): ?string
    {
        $year = $row['Rok'] ?? null;
        $month = $row['Mesic'] ?? null;
        return is_int($year) && $year >= 1900 && $year <= 9999
            && is_int($month) && $month >= 1 && $month <= 12
            ? sprintf('%04d-%02d', $year, $month) : null;
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || str_contains($value, "\0")) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private static function key(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) return null;
        $key = trim((string) $value);
        return $key !== '' && strlen($key) <= 190 && !str_contains($key, "\0") ? $key : null;
    }

    private static function boundedNumber(mixed $value, float $maximum): ?float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) && $number >= 0 && $number <= $maximum ? $number : null;
    }

    private static function nonnegativeMinor(mixed $value): ?int
    {
        $number = self::boundedNumber($value, 10_000_000);
        return $number !== null && abs($number * 100 - round($number * 100)) < 0.000001
            ? (int) round($number * 100) : null;
    }

    private static function minor(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    private static function ceilRate(int $baseMinor, float $rate): int
    {
        return (int) ceil(($baseMinor / 100) * $rate / 100 - 0.000000001) * 100;
    }

    private static function warning(array &$warnings, string $code): void
    {
        $warnings[$code] = [
            'level' => 'warning',
            'code' => $code,
            'message' => match ($code) {
                'employer_amounts_reconstructed' => 'Pojistné zaměstnavatele bylo rekonstruováno z historických sazeb Stereo NX; nejde o nový výpočet mzdy.',
                'historical_deductions_not_openings' => 'Historické srážky nejsou exekuční karty ani počáteční kumulace.',
                'payroll_institution_accounts_unverified' => 'Účty příjemců pojistného nejsou v převáděných tabulkách jednoznačně doložené a nepřevedou se.',
                'monthly_employee_orphan' => 'Mzda nemá odpovídající kartu zaměstnance.',
                'monthly_period_invalid', 'monthly_period_conflict' => 'Mzdové období v záznamu chybí nebo si časová pole odporují.',
                'employment_type_unsupported' => 'Pracovní vztah není ověřený standardní HPP.',
                'employment_insurance_regime_unsupported' => 'Karta zaměstnance uvádí neověřený režim pojištění, důchodu nebo pracovního vztahu.',
                'monthly_type_unsupported' => 'Měsíc používá nepodporovaný druh odvodu, daně nebo zvláštní režim.',
                'monthly_parallel_employer_values' => 'Měsíc obsahuje souběžné nebo nestandardní pojistné.',
                'monthly_adjustment_unsupported' => 'Měsíc obsahuje nepodporovanou zálohu, dávku, absenci nebo jinou úpravu.',
                'monthly_amount_missing_or_invalid' => 'Povinná peněžní částka chybí nebo není platná.',
                'monthly_insurance_days_unsupported' => 'Dobu pojištění nelze bezpečně určit jako celý kalendářní měsíc.',
                'monthly_worked_time_invalid' => 'Odpracované dny nebo hodiny chybí či jsou mimo platný rozsah.',
                'payroll_rate_missing_or_ambiguous' => 'Historická sazba pojistného chybí nebo je pro měsíc nejednoznačná.',
                'payroll_parameters_unverified' => 'Historické parametry odvodu HPP chybí nebo jsou nejednoznačné.',
                'monthly_net_control_failed' => 'Čistá mzda nesedí na hrubou mzdu, pojistné a zálohu na daň.',
                'monthly_payout_control_failed' => 'Dobírka nesedí na čistou mzdu, srážky a osvobozený příspěvek na stravování.',
                'monthly_employee_insurance_control_failed' => 'Sražené pojistné nesedí na základ a historické sazby.',
                'monthly_employer_insurance_invalid' => 'Rekonstruované pojistné zaměstnavatele není platné.',
                default => 'Mzdový měsíc nebyl převzat.',
            },
        ];
    }
}
