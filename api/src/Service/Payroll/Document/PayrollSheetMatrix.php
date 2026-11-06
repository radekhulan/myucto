<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Service\Payroll\IncomeTax\SeparateTaxBaseRegime;

/**
 * Mzdový list po měsících 1–12 a „Celkem", jak ho znají účetní z PAMICA.
 *
 * Každý řádek bere hodnotu z vlastního měsíce (výsledek MyÚčta), a není-li,
 * z převzatého měsíce předchozího programu. Údaj, který zdroj nenese, zůstane
 * prázdný — nikdy se nedoplní nulou. Údaj, který starší revize neevidovala,
 * nese značku a roční součet je pak „neúplné".
 *
 * Řádky § 38j odst. 2 ZDP se tisknou vždy (i s nulami); doplňkové řádky
 * (časové údaje, složky mzdy) jen tehdy, když v roce něco nesou.
 */
final class PayrollSheetMatrix
{
    private const NOT_RECORDED = 'neevidováno';

    /**
     * @return list<array{title:string,rows:list<array{label:string,cells:list<string>,total:string,strong:bool}>}>
     */
    public static function build(PayrollSheetDocumentData $data): array
    {
        $own = [];
        foreach ($data->months as $month) {
            $own[$month->month] = $month;
        }
        $taken = [];
        foreach ($data->takenOverMonths as $row) {
            $taken[(int) $row['month']] = $row;
        }
        $details = $data->monthDetails;

        $time = static fn (string $key): \Closure => static fn (PayrollSheetMonth $m) => $details[$m->month]->time[$key] ?? null;
        $group = static fn (string $key): \Closure => static fn (PayrollSheetMonth $m) => isset($details[$m->month])
            ? $details[$m->month]->groupTotals()[$key]
            : null;
        $takenField = static fn (string $field): \Closure => static fn (array $row) => $row[$field] ?? null;

        $sections = [];

        $sections[] = self::section('Časové údaje', [
            ['Odpracovaná doba (dny)', 'days', $time('worked_days'), static fn (array $r) => isset($r['worked_days_hundredths']) && $r['worked_days_hundredths'] !== null && $r['worked_days_hundredths'] > 0 ? $r['worked_days_hundredths'] : null, false, 'days100'],
            ['Odpracovaná doba (hod)', 'hours', $time('worked_millihours'), static fn (array $r) => isset($r['worked_minutes']) && $r['worked_minutes'] !== null && $r['worked_minutes'] > 0 ? (int) round($r['worked_minutes'] * 1000 / 60) : null],
            ['Čerpání dovolené (hod)', 'hours', $time('vacation_millihours'), null],
            ['Svátky (hod)', 'hours', $time('holiday_millihours'), null],
            ['Placené překážky (hod)', 'hours', $time('paid_obstacle_millihours'), null],
            ['Nemoc (hod)', 'hours', $time('sickness_millihours'), null],
            ['Neodpracovaná doba (hod)', 'hours', $time('unworked_millihours'), null],
            ['Přesčasy (hod)', 'hours', $time('overtime_millihours'), null],
            ['Dny poměru (k.d.)', 'days', $time('calendar_days'), $takenField('insurance_days')],
        ], $own, $taken, true);

        $componentRows = [];
        $labels = [];
        foreach ($details as $facts) {
            foreach ($facts->componentsByLabel() as $component) {
                $labels[$component['label']] = true;
            }
        }
        foreach (array_keys($labels) as $label) {
            $componentRows[] = [
                '  ' . $label,
                'money',
                static function (PayrollSheetMonth $m) use ($details, $label): ?int {
                    if (!isset($details[$m->month])) {
                        return null;
                    }
                    foreach ($details[$m->month]->componentsByLabel() as $component) {
                        if ($component['label'] === $label) {
                            return $component['amount_minor_units'];
                        }
                    }
                    return 0;
                },
                null,
            ];
        }
        $sections[] = self::section('Mzdové údaje – složky mezd', [
            ['Průměrný výdělek (Kč/h)', 'rate', static fn (PayrollSheetMonth $m) => $details[$m->month]->averageHourlyMinorUnits ?? null, null],
            ['Základní mzda', 'money', $group('base_wage'), null],
            ['Prémie a odměny', 'money', $group('bonuses'), null],
            ['Přesčasy', 'money', $group('overtime_pay'), null],
            ['Náhrada za dovolenou', 'money', $group('vacation_pay'), null],
            ['Ostatní náhrady mzdy', 'money', $group('compensations'), null],
            ['Stravenkový paušál', 'money', $group('meal_allowance'), null],
            ['Nezdanitelné náhrady', 'money', $group('reimbursements'), null],
            ['Ostatní složky', 'money', $group('other'), null],
            ...$componentRows,
        ], $own, $taken, true);

        $sections[] = self::section('Příjmy', [
            ['Hrubý příjem (úhrn zúčtovaných mezd)', 'money', static fn (PayrollSheetMonth $m) => $m->grossMinorUnits, $takenField('gross_minor'), true],
            ['Peněžní příjem', 'money', static fn (PayrollSheetMonth $m) => $m->cashIncomeMinorUnits, null],
            ['Nepeněžní příjem', 'money', static fn (PayrollSheetMonth $m) => $m->nonCashIncomeMinorUnits, null],
            ['Osvobozeno od daně', 'money', static fn (PayrollSheetMonth $m) => $m->taxDetailRecorded() ? $m->taxExemptIncomeMinorUnits : false, null],
        ], $own, $taken, false);

        $sections[] = self::section('Pojistné', [
            ['Vyměřovací základ sociální', 'money', static fn (PayrollSheetMonth $m) => $m->socialAssessmentBaseMinorUnits, $takenField('social_base_minor')],
            ['Sociální pojištění zaměstnanec', 'money', static fn (PayrollSheetMonth $m) => $m->employeeSocialMinorUnits, $takenField('employee_social_minor')],
            ['Sociální pojištění zaměstnavatel', 'money', static fn (PayrollSheetMonth $m) => $m->employerSocialMinorUnits, $takenField('employer_social_minor')],
            ['Vyměřovací základ zdravotní', 'money', static fn (PayrollSheetMonth $m) => $m->healthAssessmentBaseMinorUnits, $takenField('health_base_minor')],
            ['Zdravotní pojištění zaměstnanec', 'money', static fn (PayrollSheetMonth $m) => $m->employeeHealthMinorUnits, $takenField('employee_health_minor')],
            ['Doplatek ZP do minima', 'money', static fn (PayrollSheetMonth $m) => $m->healthMinimumTopUpMinorUnits, null],
            ['Zdravotní pojištění zaměstnavatel', 'money', static fn (PayrollSheetMonth $m) => $m->employerHealthMinorUnits, $takenField('employer_health_minor')],
        ], $own, $taken, false);

        // Od roku 2027 je samostatný základ podle § 6 odst. 4 základem zálohy,
        // ne srážkové daně — popisek se řídí zdaňovacím obdobím listu.
        $separateBase = SeparateTaxBaseRegime::forTaxYear($data->taxYear);
        $sections[] = self::section('Údaje o dani z příjmů', [
            ['Základ daně (zálohový)', 'money', static fn (PayrollSheetMonth $m) => $m->advanceTaxBaseMinorUnits, null],
            ['Daň před slevami', 'money', static fn (PayrollSheetMonth $m) => $m->advanceTaxBeforeCreditsMinorUnits, null],
            // Revize před mapováním v4 tu nesou nárok, ne poskytnutou slevu —
            // hodnota se ukáže s hvězdičkou, jako dosud.
            ['Sleva na dani § 35ba (uplatněná)', 'money', static fn (PayrollSheetMonth $m) => $m->creditDetailApplied() ? $m->nonRefundableCreditsMinorUnits : [$m->nonRefundableCreditsMinorUnits, '*'], null],
            ['Daňové zvýhodnění na děti (nárok)', 'money', static fn (PayrollSheetMonth $m) => $m->childDetailRecorded() ? $m->childEntitlementMinorUnits : false, null],
            ['Sleva na dani § 35c (uplatněná)', 'money', static fn (PayrollSheetMonth $m) => $m->childCreditMinorUnits, null],
            ['Záloha na daň (sražená)', 'money', static fn (PayrollSheetMonth $m) => $m->advanceTaxMinorUnits, $takenField('advance_tax_minor'), true],
            ['Daňový bonus', 'money', static fn (PayrollSheetMonth $m) => $m->taxBonusMinorUnits, $takenField('tax_bonus_minor')],
            [$separateBase->sheetBaseLabel(), 'money', static fn (PayrollSheetMonth $m) => $m->taxDetailRecorded() ? $m->withholdingTaxBaseMinorUnits : false, null],
            [$separateBase->sheetTaxLabel(), 'money', static fn (PayrollSheetMonth $m) => $m->withholdingTaxMinorUnits, $takenField('withholding_tax_minor')],
            ['Doplatek z ročního zúčtování', 'money', static fn (PayrollSheetMonth $m) => $m->annualSettlementMinorUnits, null],
        ], $own, $taken, false);

        $sections[] = self::section('Vyplacené částky', [
            ['Pojistné – zaměstnanec', 'money', static fn (PayrollSheetMonth $m) => $m->employeeSocialMinorUnits + $m->employeeHealthMinorUnits + $m->healthMinimumTopUpMinorUnits, static fn (array $r) => ($r['employee_social_minor'] ?? null) === null && ($r['employee_health_minor'] ?? null) === null ? null : (int) ($r['employee_social_minor'] ?? 0) + (int) ($r['employee_health_minor'] ?? 0)],
            ['Čistá mzda', 'money', static fn (PayrollSheetMonth $m) => $m->netPayableMinorUnits + $m->otherDeductionsMinorUnits - $m->annualSettlementMinorUnits, $takenField('net_minor'), true],
            ['Srážky ze mzdy', 'money', static fn (PayrollSheetMonth $m) => $m->otherDeductionsMinorUnits, $takenField('deductions_minor')],
            ['Částka k výplatě', 'money', static fn (PayrollSheetMonth $m) => $m->netPayableMinorUnits, static fn (array $r) => ($r['net_payable_minor'] ?? 0) !== 0 || ($r['net_minor'] ?? 0) === 0 ? ($r['net_payable_minor'] ?? null) : null, true],
        ], $own, $taken, false);

        return array_values(array_filter($sections, static fn (array $s): bool => $s['rows'] !== []));
    }

    /**
     * @param list<array{0:string,1:string,2:\Closure,3:?\Closure,4?:bool,5?:string}> $specs
     * @param array<int,PayrollSheetMonth> $own
     * @param array<int,array<string,int|string|null>> $taken
     * @return array{title:string,rows:list<array{label:string,cells:list<string>,total:string,strong:bool}>}
     */
    private static function section(string $title, array $specs, array $own, array $taken, bool $optional): array
    {
        $rows = [];
        foreach ($specs as $spec) {
            [$label, $kind, $ownFn, $takenFn] = $spec;
            $strong = $spec[4] ?? false;
            $takenKind = $spec[5] ?? $kind;
            $cells = [];
            $total = 0;
            $anyValue = false;
            $anyNonZero = false;
            $incomplete = false;
            $marker = '';
            for ($month = 1; $month <= 12; $month++) {
                $value = null;
                $cellKind = $kind;
                $suffix = '';
                if (isset($own[$month])) {
                    $value = $ownFn($own[$month]);
                } elseif (isset($taken[$month]) && $takenFn !== null) {
                    $value = $takenFn($taken[$month]);
                    $cellKind = $takenKind;
                }
                if (is_array($value)) {
                    [$value, $suffix] = $value;
                    $marker = $suffix;
                }
                if ($value === false) {
                    $cells[] = self::NOT_RECORDED;
                    $incomplete = true;
                    continue;
                }
                if ($value === null) {
                    $cells[] = '';
                    continue;
                }
                $anyValue = true;
                $anyNonZero = $anyNonZero || $value !== 0;
                if ($cellKind === 'days100') {
                    $total += $value;
                    $cells[] = self::format($value, 'days100');
                    continue;
                }
                $total += $kind === 'days' && $takenKind === 'days100' ? $value * 100 : $value;
                $cells[] = self::format($value, $cellKind) . $suffix;
            }
            if ($optional && (!$anyValue || !$anyNonZero)) {
                continue;
            }
            $totalText = match (true) {
                $incomplete => 'neúplné',
                !$anyValue => '',
                $kind === 'rate' => '',
                $takenKind === 'days100' => self::format($total, 'days100'),
                default => self::format($total, $kind) . $marker,
            };
            $rows[] = ['label' => $label, 'cells' => $cells, 'total' => $totalText, 'strong' => $strong];
        }

        return ['title' => $title, 'rows' => $rows];
    }

    public static function format(int $value, string $kind): string
    {
        return match ($kind) {
            'hours' => PayslipPdfRenderer::formatMillihours($value),
            'days' => number_format($value, 0, ',', ' '),
            'days100' => rtrim(rtrim(number_format($value / 100, 2, ',', ' '), '0'), ','),
            'rate' => number_format($value / 100, 2, ',', ' '),
            default => self::money($value),
        };
    }

    private static function money(int $minorUnits): string
    {
        $sign = $minorUnits < 0 ? '-' : '';
        $absolute = abs($minorUnits);
        $whole = number_format(intdiv($absolute, 100), 0, ',', ' ');
        $minor = $absolute % 100;

        return $sign . $whole . ($minor === 0 ? '' : ',' . str_pad((string) $minor, 2, '0', STR_PAD_LEFT));
    }
}
