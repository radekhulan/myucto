<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeInputMaterializer;

/**
 * Kontrola běhu a kontroly před během: dosažená mzda za přesčas z docházky.
 *
 * § 114 odst. 1 zákoníku práce přiznává za přesčas „mzdu, na kterou vzniklo
 * za tuto dobu právo“ A příplatek. Schválení docházky u měsíční mzdy zakládalo
 * jen příplatek; dosaženou mzdu zakládá až nově
 * ({@see PayrollSurchargeInputMaterializer}). Firma, která ji do té doby
 * doplňovala ručním vstupem, by ji vyplatila dvakrát, a naopak: chybí-li
 * v podmínkách vztahu sjednaná měsíční mzda, dosažená mzda se nezaloží
 * a zpráva schválení docházky se k účetní nedostane. Proto čtyři nálezy:
 *
 *  - `overtime_wage_from_attendance` (upozornění): mzda se založila
 *    automaticky; jmenuje osobu, hodiny a částku a říká, že ruční vstup téže
 *    mzdy je třeba zrušit. Jde ho skrýt po vztazích.
 *  - `overtime_wage_manual_duplicate` (varování s povinným potvrzením): vedle
 *    automatického vstupu je v měsíci i ruční vstup na složce dosažené mzdy
 *    nebo přesčasu.
 *  - `overtime_achieved_wage_basis_missing` (varování s povinným potvrzením):
 *    docházka nese přesčas, dosažená mzda se nezaložila a v podmínkách vztahu
 *    chybí sjednaná měsíční mzda.
 *  - `overtime_achieved_wage_missing` (varování s povinným potvrzením):
 *    docházka založila příplatek za přesčas, ale dosaženou mzdu ne (docházka
 *    schválená před zavedením, chybějící kalendář); stačí ji schválit znovu.
 *
 * Nic se nedopočítává a do kanonického snímku se nepíše, otisk vstupu zůstává.
 */
final class PayrollRunAttendanceOvertimeWage
{
    public const CODE_NOTICE = 'overtime_wage_from_attendance';
    public const CODE_DUPLICATE = 'overtime_wage_manual_duplicate';
    public const CODE_BASIS_MISSING = PayrollSurchargeInputMaterializer::OVERTIME_WAGE_BASIS_MISSING;
    public const CODE_MISSING = 'overtime_achieved_wage_missing';

    /** Složky, na které účetní dosaženou mzdu za přesčas ručně doplňovala. */
    private const MANUAL_WAGE_CODES = ['MZDA_HODINOVA', 'MZDA_DOSAZENA_PRESCAS', 'PREMIE_PRIPLATKY'];

    /** Příplatek za přesčas: ruční vedle docházky je duplicita téhož nároku. */
    private const PREMIUM_CODE = 'PRIPLATEK_PRESCAS';

    /**
     * @param list<array<string,mixed>> $employments řádky {@see PayrollRunSnapshotBuilder::employmentRows()}
     * @param array<int,list<array<string,mixed>>> $inputRows schválené vstupy období po vztazích
     * @param array<int,array<string,mixed>> $timeMonthRows pracovní měsíce po vztazích
     * @return list<PayrollRunValidation>
     */
    public static function validations(
        array $employments,
        array $inputRows,
        array $timeMonthRows,
        string $periodStart,
    ): array {
        $validations = [];
        $period = substr($periodStart, 0, 7);
        foreach ($employments as $row) {
            $employmentId = (int) $row['employment_id'];
            $employeeId = (int) ($row['employee_id'] ?? 0);
            $name = (string) ($row['full_name'] ?? '');
            $autoAmount = 0;
            $autoHours = 0;
            $attendancePremium = false;
            $manualWage = false;
            $manual = [];
            foreach ($inputRows[$employmentId] ?? [] as $input) {
                $amount = is_numeric($input['amount_minor'] ?? null) ? (int) $input['amount_minor'] : 0;
                $component = is_string($input['component_snapshot_json'] ?? null)
                    ? json_decode($input['component_snapshot_json'], true)
                    : null;
                $code = is_array($component) ? (string) ($component['code'] ?? '') : '';
                $source = $input['source_kind'] ?? null;
                if ($source === 'time') {
                    if (str_starts_with(
                        (string) ($input['external_id'] ?? ''),
                        PayrollSurchargeInputMaterializer::OVERTIME_WAGE_EXTERNAL_PREFIX,
                    )) {
                        $autoAmount += $amount;
                        $autoHours += is_numeric($input['quantity_milliunits'] ?? null)
                            ? (int) $input['quantity_milliunits']
                            : 0;
                    } elseif ($code === self::PREMIUM_CODE && $amount !== 0) {
                        $attendancePremium = true;
                    }
                    continue;
                }
                if ($source !== 'manual' || $amount === 0) {
                    continue;
                }
                if (in_array($code, self::MANUAL_WAGE_CODES, true)) {
                    $manualWage = true;
                }
                if ($code === self::PREMIUM_CODE || in_array($code, self::MANUAL_WAGE_CODES, true)) {
                    $manual[] = sprintf(
                        '%s %s Kč',
                        is_array($component) ? (string) ($component['name'] ?? $code) : $code,
                        self::amount($amount),
                    );
                }
            }
            if ($autoAmount > 0) {
                $validations[] = $manual === []
                    ? new PayrollRunValidation(
                        'warning',
                        self::CODE_NOTICE,
                        'employment',
                        $employmentId,
                        sprintf(
                            '%s: schválení docházky založilo dosaženou mzdu za %s h přesčasu %s Kč '
                            . '(§ 114 odst. 1 zákoníku práce); dřív se z docházky zakládal jen příplatek. '
                            . 'Pokud jste tuto mzdu dosud doplňovali ručním vstupem, ruční vstup zrušte, '
                            . 'jinak se vyplatí dvakrát.',
                            $name,
                            self::hours($autoHours),
                            self::amount($autoAmount),
                        ),
                        '/payroll/components?tab=inputs&period=' . $period . '&employment=' . $employmentId,
                    )
                    : new PayrollRunValidation(
                        'warning',
                        self::CODE_DUPLICATE,
                        'employment',
                        $employmentId,
                        sprintf(
                            '%s: dosaženou mzdu za %s h přesčasu %s Kč založilo schválení docházky '
                            . '(§ 114 odst. 1 zákoníku práce) a v témže měsíci je i ruční vstup (%s). '
                            . 'Jde-li o tentýž přesčas, ruční vstup zrušte, jinak se vyplatí dvakrát. '
                            . 'Potvrďte jen tehdy, když ruční vstup platí něco jiného.',
                            $name,
                            self::hours($autoHours),
                            self::amount($autoAmount),
                            self::shorten(implode('; ', $manual)),
                        ),
                        '/payroll/components?tab=inputs&period=' . $period . '&employment=' . $employmentId,
                        true,
                    );
                continue;
            }

            // Dosažená mzda se nezaložila: má docházka přesčas, za který náleží?
            $timeMonth = $timeMonthRows[$employmentId] ?? null;
            $overtime = is_array($timeMonth)
                && ($timeMonth['status'] ?? null) === 'approved'
                && is_numeric($timeMonth['overtime_millihours'] ?? null)
                    ? (int) $timeMonth['overtime_millihours']
                    : 0;
            if ($overtime <= 0 || $manualWage || ($row['remuneration_type'] ?? null) === 'hourly') {
                continue;
            }
            $monthlyGross = is_numeric($row['monthly_gross_minor'] ?? null) ? (int) $row['monthly_gross_minor'] : 0;
            if ($monthlyGross <= 0) {
                $validations[] = new PayrollRunValidation(
                    'warning',
                    self::CODE_BASIS_MISSING,
                    'employment',
                    $employmentId,
                    sprintf(
                        '%s: docházka eviduje %s h přesčasu, dosažená mzda za ně (§ 114 odst. 1 '
                        . 'zákoníku práce) se ale nezaložila, protože v podmínkách vztahu chybí sjednaná '
                        . 'měsíční mzda. Doplňte ji a docházku měsíce schvalte znovu, nebo mzdu za '
                        . 'přesčas doplňte ručním vstupem; samotný příplatek ji nenahrazuje.',
                        $name,
                        self::hours($overtime),
                    ),
                    "/payroll/people?person={$employeeId}&employment={$employmentId}&panel=employment_terms&field=monthly_gross_minor",
                    true,
                );
            } elseif ($attendancePremium) {
                $validations[] = new PayrollRunValidation(
                    'warning',
                    self::CODE_MISSING,
                    'employment',
                    $employmentId,
                    sprintf(
                        '%s: docházka eviduje %s h přesčasu a příplatek za něj, dosažená mzda za tyto '
                        . 'hodiny (§ 114 odst. 1 zákoníku práce) ale založená není. Otevřete docházku '
                        . 'měsíce a schvalte ji znovu (mzdu založí schválení), nebo ji doplňte ručním '
                        . 'vstupem.',
                        $name,
                        self::hours($overtime),
                    ),
                    '/payroll/time?employment=' . $employmentId . '&period=' . $period,
                    true,
                );
            }
        }

        return $validations;
    }

    private static function hours(int $millihours): string
    {
        return rtrim(rtrim(number_format($millihours / 1000, 3, ',', ' '), '0'), ',');
    }

    private static function amount(int $minor): string
    {
        return rtrim(rtrim(number_format($minor / 100, 2, ',', ' '), '0'), ',');
    }

    /** Sloupec zprávy validace má 500 znaků; výčet vstupů se proto krátí. */
    private static function shorten(string $list): string
    {
        return mb_strlen($list) <= 120 ? $list : mb_substr($list, 0, 117) . '…';
    }
}
