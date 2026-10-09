<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;

/**
 * Převzaté měsíce jednoho roku a odpověď na otázku „odkud je který měsíc".
 *
 * ── K čemu to je ────────────────────────────────────────────────────────────
 * Rok přechodu z jiného mzdového programu má dvě poloviny: měsíce, které vedl
 * původní program, a měsíce, které už počítá MyÚčto. Zákonné povinnosti se ale
 * podávají za CELÝ rok — evidenční list důchodového pojištění, roční zúčtování,
 * platby. Každá taková sestava proto nejdřív potřebuje vědět, kde je hranice,
 * které měsíce má z které strany, a hlavně které nemá odnikud.
 *
 * ── Hranice je jedna, a není tady ───────────────────────────────────────────
 * Rozdělení na „historické" a „naše" je `payroll_module_state.start_period`
 * a jediné místo, které ho vykládá, je {@see PayrollHistoricalPeriodService}.
 * Tahle třída ho dostane hotové a vlastní porovnání nedělá — dvě implementace
 * téhož pravidla se rozejdou, a rozejdou se tiše.
 *
 * ── Co NENÍ zaručeno ────────────────────────────────────────────────────────
 *  - Že převzatý měsíc je úplný. Původní systém mohl vydat jen část veličin;
 *    nevyplněné sloupce jsou nuly, ne `null`, protože tak je drží tabulka.
 *  - Že `employment_id` je vyplněné. Vztah, který se do MyÚčta nepřevedl, má
 *    soft link `null` a pozná se jen podle identity z původního systému.
 *  - Že spočítaná strana je SCHVÁLENÁ. `calculatedPeriods` vychází z aktuální
 *    revize běhu, ne jen ze schválené: smysl je vidět stav, ne jen hotovo.
 *  - Že měsíc má právě jeden zdroj. Firma mohla převádět po částech; zdroje
 *    vrací {@see self::sources()} jako seznam.
 */
final readonly class PayrollTakeoverYear
{
    /** Měsíc je jen v převzatých datech. */
    public const PRESENCE_TAKEOVER_ONLY = 'takeover_only';
    /** Měsíc počítalo MyÚčto a převzatá data k němu nejsou. */
    public const PRESENCE_CALCULATED_ONLY = 'calculated_only';
    /** Měsíc je z obou stran — buď je to kontrola přepočtu, nebo dvojí evidence. */
    public const PRESENCE_BOTH = 'both';
    /** Měsíc není nikde. */
    public const PRESENCE_NONE = 'none';
    /** Měsíc ještě neskončil a podklad k němu zatím není; díra v roce to není. */
    public const PRESENCE_NOT_YET = 'not_yet';

    /** @var list<string> */
    public const PRESENCES = [
        self::PRESENCE_TAKEOVER_ONLY,
        self::PRESENCE_CALCULATED_ONLY,
        self::PRESENCE_BOTH,
        self::PRESENCE_NONE,
        self::PRESENCE_NOT_YET,
    ];

    /**
     * @param list<PayrollTakeoverMonth> $months seřazené podle období, pak vztahu
     * @param list<string> $calculatedPeriods `YYYY-MM`, která MyÚčto samo počítalo
     */
    public function __construct(
        public int $supplierId,
        public int $year,
        /** První měsíc, který počítá MyÚčto (`YYYY-MM`); `null` = firma hranici nemá. */
        public ?string $payrollStartPeriod,
        public array $months,
        public array $calculatedPeriods,
        /** `null` = celá firma; jinak osoba, na kterou je pohled omezený. */
        public ?int $employeeId = null,
        /** `null` = všechny vztahy; jinak vztah, na který je pohled omezený. */
        public ?int $employmentId = null,
        /**
         * Měsíc, který právě běží (`YYYY-MM`). Od něj dál se mzda ještě nepočítala,
         * protože měsíc neskončil, takže prázdný měsíc není díra. `null` = bez hranice.
         */
        public ?string $currentPeriod = null,
        /**
         * Schválené nepřítomnosti s daty od–do podle pracovního vztahu
         * (`employment_id` => seznam ve tvaru zmrazeného vstupu mzdového běhu),
         * které zasahují do roku. Plní je jen {@see PayrollTakeoverReader::forEmployment()};
         * evidenční list z nich odvozuje rozpad vyloučených dob převzatého měsíce.
         *
         * @var array<int,list<array<string,mixed>>>
         */
        public array $absences = [],
        /**
         * Doklad, že pracovní vztah po konci roku trvá (`employment_id` =>
         * první takový měsíc následujícího období a jeho zdroj). Plní ho jen
         * {@see PayrollTakeoverReader::forEmployment()}; evidenční list z něj
         * dovozuje trvání vztahu do 31. 12., když převzaté měsíce skončení neuvádějí.
         *
         * @var array<int,array{period:string,source:string,relationship_start_date:?string,relationship_end_date:?string}>
         */
        public array $continuations = [],
    ) {}

    /**
     * Doložené trvání vztahu po 31. 12. roku, nebo `null`.
     *
     * @return array{period:string,source:string,relationship_start_date:?string,relationship_end_date:?string}|null
     */
    public function continuationAfterYearEnd(int $employmentId): ?array
    {
        $evidence = $this->continuations[$employmentId] ?? null;

        return $evidence !== null && $evidence['period'] > sprintf('%04d-12', $this->year)
            ? $evidence
            : null;
    }

    /**
     * Nepřítomnosti vztahu, které zasahují do intervalu `[$from, $to]`.
     *
     * @return list<array<string,mixed>>
     */
    public function absencesFor(int $employmentId, string $from, string $to): array
    {
        return array_values(array_filter(
            $this->absences[$employmentId] ?? [],
            static fn (array $absence): bool => (string) ($absence['date_from'] ?? '') <= $to
                && (string) ($absence['date_to'] ?? '') >= $from,
        ));
    }

    /** @return list<string> období s převzatým měsícem, vzestupně a bez opakování */
    public function takeoverPeriods(): array
    {
        $periods = [];
        foreach ($this->months as $month) {
            $periods[$month->period] = true;
        }
        ksort($periods, SORT_STRING);

        return array_keys($periods);
    }

    /** @return list<PayrollTakeoverMonth> řádky (vztahy) daného měsíce */
    public function forPeriod(string $period): array
    {
        return array_values(array_filter(
            $this->months,
            static fn (PayrollTakeoverMonth $month): bool => $month->period === $period,
        ));
    }

    /** @return list<PayrollTakeoverMonth> řádky jednoho pracovního vztahu, vzestupně */
    public function forEmployment(int $employmentId): array
    {
        return array_values(array_filter(
            $this->months,
            static fn (PayrollTakeoverMonth $month): bool => $month->employmentId === $employmentId,
        ));
    }

    /** Předchází měsíc aktivaci mzdového modulu? Vyhodnocuje se jediným pravidlem. */
    public function isHistorical(string $period): bool
    {
        return PayrollHistoricalPeriodService::precedesStart($this->payrollStartPeriod, $period);
    }

    public function hasTakeover(string $period): bool
    {
        foreach ($this->months as $month) {
            if ($month->period === $period) {
                return true;
            }
        }

        return false;
    }

    public function hasCalculated(string $period): bool
    {
        return in_array($period, $this->calculatedPeriods, true);
    }

    /** Jedna z {@see self::PRESENCES}. */
    public function presence(string $period): string
    {
        return match (true) {
            $this->hasTakeover($period) && $this->hasCalculated($period) => self::PRESENCE_BOTH,
            $this->hasTakeover($period) => self::PRESENCE_TAKEOVER_ONLY,
            $this->hasCalculated($period) => self::PRESENCE_CALCULATED_ONLY,
            $this->currentPeriod !== null && $period >= $this->currentPeriod => self::PRESENCE_NOT_YET,
            default => self::PRESENCE_NONE,
        };
    }

    /**
     * Měsíce, které jsou z obou stran.
     *
     * Není to samo o sobě chyba — přesně nad tím stojí kontrolní sestava
     * převodu. Je to ale stav, který navazující sestava musí vědomě rozhodnout:
     * dvakrát započtený měsíc znamená dvojí vyměřovací základ i dvojí platbu.
     *
     * @return list<string>
     */
    public function overlappingPeriods(): array
    {
        return array_values(array_filter(
            $this->takeoverPeriods(),
            fn (string $period): bool => $this->hasCalculated($period),
        ));
    }

    /**
     * Měsíce zadaného intervalu, ke kterým není podklad ANI z jedné strany.
     *
     * Bez argumentů je intervalem celý rok. `ELDP` sem posílá trvání vztahu
     * v roce, protože měsíc mimo vztah chybějící podklad není. Stejně tak jím
     * není měsíc, který ještě neskončil ({@see self::PRESENCE_NOT_YET}).
     *
     * Hranice se zadávají jako `YYYY-MM` i `YYYY-MM-DD`; bere se měsíc.
     *
     * @return list<string>
     */
    public function missingPeriods(?string $from = null, ?string $to = null): array
    {
        $first = self::month($from) ?? sprintf('%04d-01', $this->year);
        $last = self::month($to) ?? sprintf('%04d-12', $this->year);

        $missing = [];
        foreach ($this->periods() as $period) {
            if ($period < $first || $period > $last) {
                continue;
            }
            if ($this->presence($period) === self::PRESENCE_NONE) {
                $missing[] = $period;
            }
        }

        return $missing;
    }

    /** @return list<string> všech dvanáct měsíců roku, vzestupně */
    public function periods(): array
    {
        $periods = [];
        for ($month = 1; $month <= 12; $month++) {
            $periods[] = sprintf('%04d-%02d', $this->year, $month);
        }

        return $periods;
    }

    /** @return list<string> zdroje, ze kterých převzaté měsíce pocházejí */
    public function sources(): array
    {
        $sources = [];
        foreach ($this->months as $month) {
            $sources[$month->source] = true;
        }
        ksort($sources, SORT_STRING);

        return array_keys($sources);
    }

    /**
     * Součet převzatých částek za osobu a měsíc.
     *
     * Sčítá se přes pracovní vztahy, protože pojistné i daň jsou ze zákona
     * veličiny osoby. Dny se NESČÍTAJÍ: souběžné vztahy sdílejí tytéž kalendářní
     * dny a jejich součet by dal nesmysl, takže se vrací maximum.
     *
     * @return array<string,int>
     */
    public function personMonthTotals(string $period, int $employeeId): array
    {
        $totals = array_fill_keys([
            'gross_minor', 'net_minor', 'deductions_minor', 'net_payable_minor',
            'social_base_minor', 'health_base_minor',
            'employee_social_minor', 'employee_health_minor',
            'employer_social_minor', 'employer_health_minor',
            'advance_tax_minor', 'withholding_tax_minor', 'tax_bonus_minor',
            'worked_days_hundredths', 'worked_minutes',
        ], 0);
        $totals['insurance_days'] = 0;
        $totals['excluded_days'] = 0;

        foreach ($this->months as $month) {
            if ($month->period !== $period || $month->employeeId !== $employeeId) {
                continue;
            }
            $row = $month->toArray();
            foreach (array_keys($totals) as $key) {
                if ($key === 'insurance_days' || $key === 'excluded_days') {
                    $totals[$key] = max($totals[$key], (int) $row[$key]);
                    continue;
                }
                $totals[$key] += (int) $row[$key];
            }
        }

        return $totals;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $periods = [];
        foreach ($this->periods() as $period) {
            $rows = $this->forPeriod($period);
            $periods[] = [
                'period' => $period,
                'presence' => $this->presence($period),
                'historical' => $this->isHistorical($period),
                'takeover_row_count' => count($rows),
                'takeover_employee_count' => count(array_unique(array_map(
                    static fn (PayrollTakeoverMonth $month): string => $month->employeeId !== null
                        ? 'e' . $month->employeeId
                        : 'x' . $month->externalPersonRef,
                    $rows,
                ))),
                'sources' => array_values(array_unique(array_map(
                    static fn (PayrollTakeoverMonth $month): string => $month->source,
                    $rows,
                ))),
            ];
        }

        return [
            'supplier_id' => $this->supplierId,
            'year' => $this->year,
            'employee_id' => $this->employeeId,
            'employment_id' => $this->employmentId,
            'payroll_start_period' => $this->payrollStartPeriod,
            'sources' => $this->sources(),
            'periods' => $periods,
            'takeover_periods' => $this->takeoverPeriods(),
            'calculated_periods' => $this->calculatedPeriods,
            'overlapping_periods' => $this->overlappingPeriods(),
            'missing_periods' => $this->missingPeriods(),
            'months' => array_map(
                static fn (PayrollTakeoverMonth $month): array => $month->toArray(),
                $this->months,
            ),
        ];
    }

    private static function month(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return preg_match('/^\d{4}-\d{2}/', $trimmed) === 1 ? substr($trimmed, 0, 7) : null;
    }
}
