<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

/**
 * Rozpis ročně uplatňovaných položek (§ 38h odst. 6) pro tisk výsledku
 * ročního zúčtování.
 *
 * Nic nepočítá znovu: skládá jen hotová čísla z výsledku výpočtu. Jediná
 * úprava je rozložení společného limitu § 15 odst. 6 na jednotlivé druhy
 * (tiskopis MFin 5460/1 má řádky po druzích, výpočet zná jen úhrn).
 */
final class AnnualClaimsPrintBreakdown
{
    /**
     * Pořadí, ve kterém se společný limit 48 000 Kč rozděluje na druhy.
     *
     * Pokyny k tiskopisu pořadí nestanoví a zákon taky ne: limit omezuje
     * úhrn, ne jednotlivé druhy. Kráceno je proto v pořadí řádků tiskopisu
     * (penzijní produkty, životní pojištění, DIP, dlouhodobá péče), aby se
     * součet řádků vždy rovnal skutečně odečtenému úhrnu z výpočtu.
     *
     * @var array<string,string>
     */
    private const RETIREMENT_KINDS = [
        'pension_savings' => 'Penzijní produkty - penzijní připojištění, doplňkové penzijní spoření, penzijní pojištění (§ 15 odst. 6)',
        'life_insurance' => 'Soukromé životní pojištění (§ 15 odst. 6)',
        'long_term_investment' => 'Dlouhodobý investiční produkt (§ 15 odst. 6)',
        'long_term_care_insurance' => 'Pojištění dlouhodobé péče (§ 15 odst. 6)',
    ];

    /**
     * @param array<string,mixed> $result serializovaný `AnnualSettlementResult`
     * @return array{
     *   present:bool,
     *   rows:list<array{label:string,claimed_minor_units:int,amount_minor_units:int}>,
     *   tax_base_before_deductions_minor_units:int,
     *   non_taxable_deductions_minor_units:int,
     *   enforcement_credit_minor_units:int
     * }
     */
    public static function fromResult(array $result): array
    {
        $trace = is_array($result['trace'] ?? null) ? $result['trace'] : [];
        if (!isset($trace['annual_claims'])) {
            return [
                'present' => false,
                'rows' => [],
                'tax_base_before_deductions_minor_units' => 0,
                'non_taxable_deductions_minor_units' => 0,
                'enforcement_credit_minor_units' => 0,
            ];
        }
        $nonTaxable = is_array($trace['non_taxable'] ?? null) ? $trace['non_taxable'] : [];
        $donation = self::section($nonTaxable, 'donation');
        $interest = self::section($nonTaxable, 'housing_interest');
        $retirement = self::section($nonTaxable, 'retirement_and_care');
        $byKind = is_array($nonTaxable['claimed_by_kind'] ?? null) ? $nonTaxable['claimed_by_kind'] : [];

        $rows = [];
        self::add(
            $rows,
            'Hodnota bezúplatného plnění - dary (§ 15 odst. 1)',
            self::int($donation, 'claimed_minor_units'),
            self::int($donation, 'deducted_minor_units'),
        );
        self::add(
            $rows,
            'Úroky z úvěru na bytovou potřebu (§ 15 odst. 3 a 4)',
            self::int($interest, 'claimed_minor_units'),
            self::int($interest, 'deducted_minor_units'),
        );
        $remaining = self::int($retirement, 'deducted_minor_units');
        foreach (self::RETIREMENT_KINDS as $kind => $label) {
            $claimed = self::int($byKind, $kind);
            $deducted = min($claimed, $remaining);
            $remaining -= $deducted;
            self::add($rows, $label, $claimed, $deducted);
        }
        if ($remaining !== 0) {
            throw new \DomainException(
                'Rozpis spoření na stáří neodpovídá odečtenému úhrnu z výpočtu.',
            );
        }
        // § 35bd: sleva, ne odpočet — uplatněná je v úhrnu slev § 35ba; tady
        // jen nárok podle dětí, jak ho výpočet spočítal.
        $credits = is_array($trace['credits'] ?? null) ? $trace['credits'] : [];
        $preschool = self::section($credits, 'preschool');
        foreach (is_array($preschool['children'] ?? null) ? $preschool['children'] : [] as $child) {
            if (!is_array($child)) {
                continue;
            }
            self::add(
                $rows,
                'Sleva za umístění dítěte (§ 35bd)',
                self::int($child, 'expenses_minor_units'),
                self::int($child, 'amount_minor_units'),
            );
        }

        return [
            'present' => true,
            'rows' => $rows,
            'tax_base_before_deductions_minor_units' =>
                (int) ($result['tax_base_before_deductions_minor_units'] ?? 0),
            'non_taxable_deductions_minor_units' =>
                (int) ($result['non_taxable_deductions_minor_units'] ?? 0),
            'enforcement_credit_minor_units' =>
                (int) ($result['enforcement_credit_minor_units'] ?? 0),
        ];
    }

    /** @param list<array{label:string,claimed_minor_units:int,amount_minor_units:int}> $rows */
    private static function add(array &$rows, string $label, int $claimed, int $deducted): void
    {
        if ($claimed === 0 && $deducted === 0) {
            return;
        }
        $rows[] = [
            'label' => $label,
            'claimed_minor_units' => $claimed,
            'amount_minor_units' => $deducted,
        ];
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private static function section(array $source, string $key): array
    {
        return is_array($source[$key] ?? null) ? $source[$key] : [];
    }

    /** @param array<string,mixed> $source */
    private static function int(array $source, string $key): int
    {
        return (int) ($source[$key] ?? 0);
    }
}
