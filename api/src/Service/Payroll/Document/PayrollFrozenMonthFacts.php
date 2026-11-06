<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

/**
 * Časové údaje a rozpad složek mzdy jedné osoby za jeden měsíc, čtené ze
 * ZMRAZENÉ vstupní revize a jejího výsledku.
 *
 * Výplatní páska i roční mzdový list potřebují tytéž údaje (odpracované dny
 * a hodiny, dovolenou, svátky, nemoc, průměr, základní mzdu, prémie, náhrady,
 * stravenkový paušál). Kdyby si je každý doklad skládal sám, rozešly by se
 * právě na zařazení složek. Proto je pravidlo jen tady.
 *
 * Časové údaje se berou ze zmrazeného měsíčního souhrnu docházky
 * (`time_month.jmhz_work_summary.values`). Kde souhrn není (vztah bez
 * schválené docházky), jsou `null` — doklad je pak vynechá, nevypíše nulu.
 */
final readonly class PayrollFrozenMonthFacts
{
    /** Složky, které jsou náhradou mzdy za dovolenou (§ 222 ZP). */
    private const VACATION_CODE_PREFIX = 'NAHRADA_MZDY_DOVOLENA';

    private const OVERTIME_CODES = ['PRIPLATEK_PRESCAS', 'MZDA_DOSAZENA_PRESCAS'];

    /** Nezdanitelné náhrady výdajů, které PAMICA vede mimo mzdu. */
    private const REIMBURSEMENT_CODES = ['NAHRADA_VYDAJU_PREVZATA'];

    private const BASE_WAGE_KINDS = ['base_wage', 'hourly_wage', 'task_wage'];

    private const BONUS_KINDS = ['bonus', 'premium', 'commission', 'allowance'];

    /**
     * @param array<string,?int> $time
     * @param list<array{label:string,amount_minor_units:int,group:string}> $components
     * @param list<string> $employmentCodes
     */
    public function __construct(
        public array $time,
        public ?int $averageHourlyMinorUnits,
        public array $components,
        public array $employmentCodes,
        public ?string $payoutAccountMasked,
    ) {}

    /** Klíče časových údajů v pořadí, v jakém je doklady tisknou. */
    public const TIME_KEYS = [
        'calendar_days',
        'fund_millihours',
        'worked_days',
        'worked_millihours',
        'holiday_millihours',
        'vacation_millihours',
        'paid_obstacle_millihours',
        'sickness_millihours',
        'care_millihours',
        'unpaid_leave_millihours',
        'overtime_millihours',
        'unworked_millihours',
    ];

    /** Skupiny složek mzdy v pořadí tisku. */
    public const GROUPS = [
        'base_wage',
        'bonuses',
        'overtime_pay',
        'vacation_pay',
        'compensations',
        'meal_allowance',
        'reimbursements',
        'other',
    ];

    /**
     * @param array<string,mixed> $inputPerson osoba ze vstupního snapshotu revize
     * @param array<string,mixed> $resultPerson výsledek téže osoby v revizi
     */
    public static function fromFrozen(array $inputPerson, array $resultPerson): self
    {
        $time = array_fill_keys(self::TIME_KEYS, null);
        $averageHourly = null;
        $codes = [];
        $inputs = [];
        foreach (self::rows($inputPerson['employments'] ?? null) as $row) {
            $employment = is_array($row['employment'] ?? null) ? $row['employment'] : [];
            if (is_string($employment['code'] ?? null) && $employment['code'] !== '') {
                $codes[$employment['code']] = true;
            }
            $average = is_array($row['average_earning'] ?? null) ? $row['average_earning'] : [];
            if ($averageHourly === null && is_int($average['average_hourly_minor'] ?? null)) {
                $averageHourly = $average['average_hourly_minor'];
            }
            foreach (self::rows($row['inputs'] ?? null) as $input) {
                if (is_int($input['id'] ?? null)) {
                    $inputs[$input['id']] = $input;
                }
            }
            $timeMonth = $row['time_month'] ?? null;
            $values = is_array($timeMonth)
                && is_array($timeMonth['jmhz_work_summary'] ?? null)
                && is_array($timeMonth['jmhz_work_summary']['values'] ?? null)
                    ? $timeMonth['jmhz_work_summary']['values']
                    : null;
            if ($values === null) {
                continue;
            }
            $add = static function (?int $current, int ...$parts): ?int {
                return ($current ?? 0) + array_sum($parts);
            };
            $int = static fn (string $key): ?int => is_int($values[$key] ?? null) ? $values[$key] : null;
            $sum = static function (string ...$keys) use ($int): ?int {
                $total = null;
                foreach ($keys as $key) {
                    $value = $int($key);
                    if ($value !== null) {
                        $total = ($total ?? 0) + $value;
                    }
                }
                return $total;
            };
            $days = $int('evidence_days');
            if ($days !== null) {
                $time['calendar_days'] = max($time['calendar_days'] ?? 0, $days);
            }
            foreach ([
                'fund_millihours' => $int('agreed_fund_millihours') ?? $int('standard_fund_millihours'),
                'worked_days' => $int('worked_days'),
                'worked_millihours' => $int('worked_millihours'),
                'holiday_millihours' => $int('holiday_millihours'),
                'vacation_millihours' => $int('vacation_millihours'),
                'paid_obstacle_millihours' => $sum(
                    'employee_obstacle_paid_millihours',
                    'employer_obstacle_millihours',
                ),
                'sickness_millihours' => $sum(
                    'dpn_with_employer_compensation_millihours',
                    'dpn_without_employer_compensation_millihours',
                ),
                'care_millihours' => $int('care_millihours'),
                'unpaid_leave_millihours' => $int('unpaid_leave_millihours'),
                'overtime_millihours' => $int('overtime_millihours'),
                'unworked_millihours' => $int('unworked_total_millihours'),
            ] as $key => $value) {
                // Docházka je u vztahu schválená, takže neuvedená hodnota je
                // nula, ne neznámý údaj.
                $time[$key] = $add($time[$key], $value ?? 0);
            }
        }

        $components = [];
        foreach (self::rows($resultPerson['employments'] ?? null) as $employmentResult) {
            foreach (self::rows($employmentResult['inputs'] ?? null) as $inputResult) {
                $inputId = $inputResult['input_id'] ?? null;
                $totals = is_array($inputResult['totals'] ?? null) ? $inputResult['totals'] : [];
                $amount = $totals['source_amount_minor'] ?? null;
                if (!is_int($inputId) || !is_int($amount)) {
                    continue;
                }
                $component = is_array($inputs[$inputId]['component'] ?? null)
                    ? $inputs[$inputId]['component']
                    : [];
                $code = is_string($component['code'] ?? null)
                    ? $component['code']
                    : (is_string($inputResult['component_code'] ?? null) ? $inputResult['component_code'] : '');
                $kind = is_string($component['component_kind'] ?? null) ? $component['component_kind'] : '';
                $label = is_string($component['name'] ?? null) && trim($component['name']) !== ''
                    ? $component['name']
                    : ($code !== '' ? $code : 'Složka mzdy');
                $components[] = [
                    'label' => $label,
                    'amount_minor_units' => $amount,
                    'group' => self::group($code, $kind),
                ];
            }
        }

        $accounts = self::rows($inputPerson['payout_accounts'] ?? null);
        $masked = null;
        foreach ($accounts as $account) {
            if (is_string($account['bank_account_masked'] ?? null) && $account['bank_account_masked'] !== '') {
                $masked = $account['bank_account_masked'];
                break;
            }
        }

        return new self(
            $time,
            $averageHourly,
            $components,
            array_keys($codes),
            $masked,
        );
    }

    /**
     * Zařazení složky do skupiny dokladu. Veřejné, aby šlo pravidlo zavolat
     * i z testu a nekopírovalo se.
     */
    public static function group(string $code, string $kind): string
    {
        if (str_starts_with($code, self::VACATION_CODE_PREFIX)) {
            return 'vacation_pay';
        }
        if (in_array($code, self::OVERTIME_CODES, true)) {
            return 'overtime_pay';
        }
        if (in_array($code, self::REIMBURSEMENT_CODES, true) || $kind === 'travel_reimbursement') {
            return 'reimbursements';
        }
        if (in_array($kind, self::BASE_WAGE_KINDS, true)) {
            return 'base_wage';
        }
        if (in_array($kind, self::BONUS_KINDS, true)) {
            return 'bonuses';
        }
        if ($kind === 'compensation') {
            return 'compensations';
        }
        if ($kind === 'benefit_meal') {
            return 'meal_allowance';
        }

        return 'other';
    }

    /**
     * Sloučí údaje více revizí téhož měsíce (více účtáren, doplatek po
     * skončení). Hodiny a částky se sčítají, kalendářní dny se berou nejvyšší.
     */
    public static function merge(self $left, self $right): self
    {
        $time = [];
        foreach (self::TIME_KEYS as $key) {
            $a = $left->time[$key];
            $b = $right->time[$key];
            $time[$key] = match (true) {
                $a === null => $b,
                $b === null => $a,
                $key === 'calendar_days' => max($a, $b),
                default => $a + $b,
            };
        }

        return new self(
            $time,
            $left->averageHourlyMinorUnits ?? $right->averageHourlyMinorUnits,
            [...$left->components, ...$right->components],
            array_values(array_unique([...$left->employmentCodes, ...$right->employmentCodes])),
            $left->payoutAccountMasked ?? $right->payoutAccountMasked,
        );
    }

    public function hasTime(): bool
    {
        return $this->time['worked_millihours'] !== null || $this->time['calendar_days'] !== null;
    }

    /** @return array<string,int> skupina => součet v haléřích */
    public function groupTotals(): array
    {
        $totals = array_fill_keys(self::GROUPS, 0);
        foreach ($this->components as $component) {
            $totals[$component['group']] += $component['amount_minor_units'];
        }

        return $totals;
    }

    /**
     * Složky sečtené podle názvu, v pořadí prvního výskytu.
     *
     * @return list<array{label:string,amount_minor_units:int,group:string}>
     */
    public function componentsByLabel(): array
    {
        $byLabel = [];
        foreach ($this->components as $component) {
            $key = $component['label'];
            if (!isset($byLabel[$key])) {
                $byLabel[$key] = $component;
                continue;
            }
            $byLabel[$key]['amount_minor_units'] += $component['amount_minor_units'];
        }

        return array_values($byLabel);
    }

    /** @return array<string,mixed> */
    public function toSnapshot(): array
    {
        return [
            'time' => $this->time,
            'average_hourly_minor_units' => $this->averageHourlyMinorUnits,
            'components' => $this->components,
            'employment_codes' => $this->employmentCodes,
        ];
    }

    /** Ze snapshotu dokladu; neznámý tvar je chyba, ne prázdný údaj. */
    public static function fromSnapshot(mixed $value): self
    {
        if (!is_array($value) || !is_array($value['time'] ?? null) || !is_array($value['components'] ?? null)) {
            throw new \DomainException('Měsíční údaje dokladu nemají platný tvar.');
        }
        $time = array_fill_keys(self::TIME_KEYS, null);
        foreach (self::TIME_KEYS as $key) {
            $item = $value['time'][$key] ?? null;
            if ($item !== null && !is_int($item)) {
                throw new \DomainException("Časový údaj {$key} není celé číslo.");
            }
            $time[$key] = $item;
        }
        $components = [];
        foreach ($value['components'] as $row) {
            if (!is_array($row)
                || !is_string($row['label'] ?? null)
                || !is_int($row['amount_minor_units'] ?? null)
                || !in_array($row['group'] ?? null, self::GROUPS, true)
            ) {
                throw new \DomainException('Složka mzdy v měsíčních údajích není platná.');
            }
            $components[] = [
                'label' => $row['label'],
                'amount_minor_units' => $row['amount_minor_units'],
                'group' => $row['group'],
            ];
        }
        $average = $value['average_hourly_minor_units'] ?? null;
        $codes = is_array($value['employment_codes'] ?? null)
            ? array_values(array_filter($value['employment_codes'], 'is_string'))
            : [];

        return new self($time, is_int($average) ? $average : null, $components, $codes, null);
    }

    /** @return list<array<string,mixed>> */
    private static function rows(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
