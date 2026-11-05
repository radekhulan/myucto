<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Jak úhrady ostatní položky pokrývají její splátky. Jediné místo s tímto pravidlem:
 * čte ho detail splátek, přiřazení úhrady ({@see OtherItemService::allocate}), výběr
 * kandidátů ({@see OtherItemPaymentCandidateRanker}), předpověď
 * ({@see Obligations\OtherItemForecastService}) i backfill vazby na splátku.
 *
 * Pravidlo:
 *  1. Úhrada s `installment_id` pokryje nejdřív svou splátku, nejvýš její otevřenou část.
 *  2. Zbytek (přebytek takové úhrady, úhrady bez splátky a `settled_outside_amount`)
 *     pokrývá otevřené splátky podle pořadí (`position`).
 *
 * Jedna platba má k položce nejvýš jeden řádek (unikátní klíč), takže platba za víc
 * splátek nese splátku, kterou začíná, a zbytek jde krokem 2. Když každá úhrada míří
 * na první otevřenou splátku v době přiřazení (backfill, přiřazení bez přesné shody),
 * výsledek je totožný s čistým pořadím podle součtu úhrad, které dřív počítala
 * předpověď. Odchylka vzniká jen u úhrady přesně na pozdější splátku a je záměrná.
 */
final class OtherItemInstallmentCoverage
{
    public const STATUS_PAID = 'paid';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_UNPAID = 'unpaid';

    /**
     * @param list<array{id:int|string,position:int|string,due_on:string,amount:float|int|string}> $installments
     * @param list<array{installment_id:int|string|null,amount:float|int|string}> $allocations aktivní úhrady
     *        v pořadí payment_on, id
     * @return list<array{id:int,position:int,due_on:string,amount:float,paid_amount:float,
     *     remaining_amount:float,status:string}>
     */
    public static function compute(array $installments, array $allocations, float $settledOutside, string $today): array
    {
        usort($installments, static fn (array $a, array $b): int => (int) $a['position'] <=> (int) $b['position']);
        $open = [];
        $paid = [];
        $byId = [];
        foreach ($installments as $index => $row) {
            $open[$index] = self::cents($row['amount']);
            $paid[$index] = 0;
            $byId[(int) $row['id']] = $index;
        }
        $pool = self::cents($settledOutside);
        foreach ($allocations as $allocation) {
            $amount = self::cents($allocation['amount']);
            $target = $allocation['installment_id'] !== null ? ($byId[(int) $allocation['installment_id']] ?? null) : null;
            if ($target !== null) {
                $take = min($amount, $open[$target]);
                $open[$target] -= $take;
                $paid[$target] += $take;
                $amount -= $take;
            }
            $pool += $amount;
        }
        foreach (array_keys($installments) as $index) {
            if ($pool <= 0) break;
            $take = min($pool, $open[$index]);
            $open[$index] -= $take;
            $paid[$index] += $take;
            $pool -= $take;
        }
        $result = [];
        foreach ($installments as $index => $row) {
            $dueOn = (string) $row['due_on'];
            $status = match (true) {
                $open[$index] <= 0 => self::STATUS_PAID,
                $dueOn < $today => self::STATUS_OVERDUE,
                $paid[$index] > 0 => self::STATUS_PARTIAL,
                default => self::STATUS_UNPAID,
            };
            $result[] = [
                'id' => (int) $row['id'],
                'position' => (int) $row['position'],
                'due_on' => $dueOn,
                'amount' => self::cents($row['amount']) / 100.0,
                'paid_amount' => $paid[$index] / 100.0,
                'remaining_amount' => $open[$index] / 100.0,
                'status' => $status,
            ];
        }
        return $result;
    }

    /**
     * Splátka, kterou nová úhrada hradí: otevřená splátka se zbytkem přesně ve výši
     * platby (±1 haléř), jinak první otevřená splátka. Null, když položka splátky
     * nemá nebo jsou všechny uhrazené. Bez `$preferExact` vždy první otevřená splátka
     * (backfill dřívějších úhrad, které se rozpočítávaly jen podle pořadí).
     *
     * @param list<array{id:int,remaining_amount:float}> $coverage výstup {@see compute}
     */
    public static function target(array $coverage, float $amount, bool $preferExact = true): ?int
    {
        $cents = self::cents($amount);
        $first = null;
        foreach ($coverage as $row) {
            $open = self::cents($row['remaining_amount']);
            if ($open <= 0) continue;
            if (!$preferExact) return (int) $row['id'];
            if (abs($open - $cents) <= 1) return (int) $row['id'];
            $first ??= (int) $row['id'];
        }
        return $first;
    }

    /**
     * Otevřené splátky (se zbytkem k úhradě) v pořadí.
     *
     * @param list<array<string,mixed>> $coverage
     * @return list<array<string,mixed>>
     */
    public static function open(array $coverage): array
    {
        return array_values(array_filter($coverage, static fn (array $row): bool => (float) $row['remaining_amount'] > 0.005));
    }

    private static function cents(float|int|string $value): int
    {
        return (int) round((float) $value * 100);
    }
}
