<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Částky jedné osoby ve výsledku běhu, které se ukládají do sloupců
 * `payroll_run_persons` vedle jejího JSON výsledku.
 *
 * Přehled osob ve spočítaném běhu se stránkuje a řadí podle částek na serveru;
 * číst kvůli tomu výsledky všech osob revize z JSON by rostlo s počtem lidí.
 * Definice částek je táž jako v rozpadu čisté mzdy
 * ({@see \MyInvoice\Service\Payroll\Net\PayrollNetResultQueryService}): hrubá
 * je peněžní a nepeněžní příjem, čistá je před srážkami, k výplatě po srážkách
 * a exekucích. Stejné výrazy doplňuje starým řádkům migrace
 * `2285_payroll_run_summaries.sql`; shodu hlídá `PayrollRunPeopleApiTest`.
 */
final class PayrollRunPersonSummary
{
    /** Sloupce v pořadí, v jakém je vrací {@see self::fromResult()}. */
    public const COLUMNS = [
        'result_status',
        'gross_minor',
        'employee_social_minor',
        'employee_health_minor',
        'advance_tax_minor',
        'withholding_tax_minor',
        'tax_bonus_minor',
        'net_minor',
        'payable_minor',
    ];

    private function __construct() {}

    /**
     * @param array<string,mixed> $person výsledek osoby ze snímku běhu
     * @return array{
     *   result_status:?string,
     *   gross_minor:int,
     *   employee_social_minor:?int,
     *   employee_health_minor:?int,
     *   advance_tax_minor:?int,
     *   withholding_tax_minor:?int,
     *   tax_bonus_minor:?int,
     *   net_minor:?int,
     *   payable_minor:?int
     * }
     */
    public static function fromResult(array $person): array
    {
        $statutory = is_array($person['statutory'] ?? null) ? $person['statutory'] : [];
        $net = is_array($statutory['net_pay'] ?? null) ? $statutory['net_pay'] : [];
        $totals = is_array($person['totals'] ?? null) ? $person['totals'] : [];
        $cash = self::int($net, 'cash_income_minor_units');
        $nonCash = self::int($net, 'non_cash_income_minor_units');
        $status = $statutory['status'] ?? null;

        return [
            'result_status' => is_string($status) ? $status : null,
            'gross_minor' => $cash !== null && $nonCash !== null
                ? $cash + $nonCash
                : (self::int($totals, 'cash_payable_minor') ?? 0),
            'employee_social_minor' => self::int($net, 'employee_social_minor_units'),
            'employee_health_minor' => self::int($net, 'employee_health_minor_units'),
            'advance_tax_minor' => self::int($net, 'advance_tax_minor_units'),
            'withholding_tax_minor' => self::int($net, 'withholding_tax_minor_units'),
            'tax_bonus_minor' => self::int($net, 'tax_bonus_minor_units'),
            'net_minor' => self::int($net, 'net_before_deductions_minor_units'),
            'payable_minor' => self::int($person, 'payable_after_enforcement_minor'),
        ];
    }

    /** @param array<string,mixed> $source */
    private static function int(array $source, string $key): ?int
    {
        $value = $source[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
